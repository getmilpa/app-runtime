<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Web\Controllers;

use Milpa\AppRuntime\Identity\EnrollmentStore;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\AppRuntime\Web\Live\GateCeremonyAssets;
use Milpa\AppRuntime\Web\Live\GateCeremonyComponent;
use Milpa\AppRuntime\Web\Live\GateCeremonyHtmlRenderer;
use Milpa\AppRuntime\Web\LocalPath;
use Milpa\AppRuntime\Web\RegisteredCredentialIds;
use Milpa\AppRuntime\Web\SessionCookie;
use Milpa\Auth\WebAuthn\ChallengeStore;
use Milpa\Auth\WebAuthn\PasskeyAuthenticator;
use Milpa\Auth\WebAuthn\PasskeyCredentialStore;
use Milpa\Auth\WebAuthn\PasskeyLogin;
use Milpa\Auth\WebAuthn\WebAuthnRegistrationVerifier;
use Milpa\Live\Assets\ComponentAssetOrchestrator;
use Milpa\Live\Components\BrandMarkComponent;
use Milpa\Live\Rendering\BrandMarkHtmlRenderer;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\RenderRequest;
use Nyholm\Psr7\Response;
use Milpa\AppRuntime\Web\PasskeyPlugin;
use Milpa\Live\Support\DesignTokens;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The HTTP door of a passkey login: hand the browser a fresh challenge, then turn its assertion into a
 * session cookie — or refuse it (greenhouse decisions/0126).
 *
 * The controller is thin on purpose: the ceremony's safety lives in milpa/auth (single-use challenge,
 * signature, clone counter, recognition), and this only marshals bytes across HTTP. The credential's
 * three fields arrive base64url-encoded the way a browser sends them; the credential id stays the
 * base64url STRING it was registered as. On success the minted session id travels in a cookie whose
 * attributes {@see SessionCookie} decides (HttpOnly, SameSite=Strict, `Secure` off loopback or over
 * https) and that the framework's StartSession middleware — and
 * {@see \Milpa\AppRuntime\Web\PasskeyGateMiddleware} — reads back.
 *
 * The authentication options carry `allowCredentials` (greenhouse decisions/0206): the enrollment
 * ceremony registers NON-DISCOVERABLE credentials (evidence/0486), and a browser only finds one of
 * those when the request names it. Only ids that are registered AND enrolled are named — the login
 * refuses anything else, and `POST /webauthn/register` is open, so a registered-but-unenrolled key must
 * not swell the list ({@see RegisteredCredentialIds::allowEnrolledCredentials()}). `GET /webauthn/signin`
 * is the page that runs the ceremony and returns the human to `next` — a local path, validated server side.
 */
final class PasskeyController
{
    public function __construct(
        private readonly PasskeyAuthenticator $authenticator,
        private readonly PasskeyLogin $login,
        private readonly ChallengeStore $challenges,
        private readonly WebAuthnRegistrationVerifier $registration,
        private readonly PasskeyCredentialStore $credentials,
        private readonly RegisteredCredentialIds $registered,
        private readonly EnrollmentStore $enrollments,
        private readonly string $rpId,
        private readonly string $cookieName,
        private readonly string $gateScope = 'milpa.admin',
        /**
         * Which authenticators this house admits, or `null` to admit whatever the person has.
         *
         * This was written into the code as `'cross-platform'`, which **excludes** the platform
         * authenticator — Touch ID, Windows Hello, a phone's fingerprint — and the password managers
         * that hold passkeys too. That is, both of the places a passkey lives on the machine anyone
         * already owns (greenhouse decisions/0244).
         *
         * `evidence/0486` wired it on request, under the name "the enroll PREFERS the YubiKey". The
         * crack is in the verb: WebAuthn cannot prefer an attachment — either you name one and
         * discard the rest, or you omit it. The name was written meaning the preference.
         *
         * Absent by default, deliberately. A house that wants hardware only declares it and gets
         * exactly the previous behaviour back. What was law becomes each house's decision.
         */
        private readonly ?string $authenticatorAttachment = null,
        /**
         * The house's dispatcher, so the ceremony component's lifecycle pair can be observed.
         *
         * Optional and last: a controller built without one still paints, it simply announces
         * nothing. Milpa is event-driven and a component nobody can observe is a component nobody
         * can extend — so the seam exists even where no app has used it yet.
         */
        private readonly ?MilpaEventDispatcherInterface $events = null,
    ) {
    }

    /** Issue a fresh one-time challenge for a registration ceremony. */
    public function registerOptions(ServerRequestInterface $request): ResponseInterface
    {
        $challenge = $this->challenges->issue();

        return $this->json(200, [
            'rpId' => $this->rpId,
            'challenge' => self::base64UrlEncode($challenge),
        ]);
    }

    /**
     * Verify a registration attestation and, if it holds, remember the credential.
     *
     * The credential is now REGISTERED (the house holds its public key), not yet RECOGNIZED — granting it
     * scopes is identity:enroll's job, exactly as a fresh gpg key must be enrolled (greenhouse decisions/0125).
     */
    public function register(ServerRequestInterface $request): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        if (!\is_array($body)) {
            return $this->json(400, ['error' => 'passkey_bad_request', 'message' => 'The body is not a JSON object.']);
        }
        $clientData = self::base64UrlDecode(\is_string($body['clientDataJSON'] ?? null) ? $body['clientDataJSON'] : '');
        $attestation = self::base64UrlDecode(\is_string($body['attestationObject'] ?? null) ? $body['attestationObject'] : '');
        if ($clientData === null || $attestation === null) {
            return $this->json(400, ['error' => 'passkey_bad_request', 'message' => 'clientDataJSON and attestationObject are required.']);
        }

        // The challenge the client echoes must be one we issued and have not spent — consume it first.
        $decoded = json_decode($clientData, true);
        $challenge = self::base64UrlDecode(\is_array($decoded) && \is_string($decoded['challenge'] ?? null) ? $decoded['challenge'] : '');
        if ($challenge === null || !$this->challenges->consume($challenge)) {
            return $this->json(401, ['ok' => false, 'error' => 'passkey_rejected']);
        }

        $credential = $this->registration->verify($challenge, $this->rpId, $clientData, $attestation);
        if ($credential === null) {
            return $this->json(401, ['ok' => false, 'error' => 'passkey_rejected']);
        }
        $this->credentials->register($credential);

        return $this->json(201, [
            'ok' => true,
            'credentialId' => $credential->credentialId,
            'note' => 'registered — enroll this credential id to grant it scopes',
        ]);
    }

    /**
     * Issue a fresh one-time challenge for an authentication ceremony, naming every credential that
     * could actually sign in.
     *
     * `allowCredentials` lists each id as `{type: 'public-key', id: <base64url>}` — the only way a
     * non-discoverable key (a YubiKey enrolled with `residentKey: discouraged`) answers. Only ids that
     * are registered AND enrolled are offered: the login admits by enrollment, and registering is open
     * to anyone, so the list names what the door would accept and nothing more. An id is not a secret,
     * the private key is.
     */
    public function options(ServerRequestInterface $request): ResponseInterface
    {
        $challenge = $this->authenticator->challenge();

        return $this->json(200, [
            'rpId' => $this->rpId,
            'challenge' => self::base64UrlEncode($challenge),
            'allowCredentials' => $this->registered->allowEnrolledCredentials($this->enrollments),
        ]);
    }

    /** Verify an assertion and, if the passkey is recognized, mint a session cookie. */
    public function authenticate(ServerRequestInterface $request): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        if (!\is_array($body)) {
            return $this->json(400, ['error' => 'passkey_bad_request', 'message' => 'The body is not a JSON object.']);
        }

        $credentialId = \is_string($body['credentialId'] ?? null) ? $body['credentialId'] : '';
        $clientData = self::base64UrlDecode(\is_string($body['clientDataJSON'] ?? null) ? $body['clientDataJSON'] : '');
        $authData = self::base64UrlDecode(\is_string($body['authenticatorData'] ?? null) ? $body['authenticatorData'] : '');
        $signature = self::base64UrlDecode(\is_string($body['signature'] ?? null) ? $body['signature'] : '');
        if ($credentialId === '' || $clientData === null || $authData === null || $signature === null) {
            return $this->json(400, ['error' => 'passkey_bad_request', 'message' => 'credentialId, clientDataJSON, authenticatorData and signature are required.']);
        }

        $session = $this->login->login($this->rpId, $credentialId, $clientData, $authData, $signature);
        if ($session === null) {
            // Refused for any reason — replay, unknown or unrecognized credential, bad signature, clone.
            // The single message keeps the door from telling an attacker which check failed.
            return $this->json(401, ['ok' => false, 'error' => 'passkey_rejected']);
        }

        return $this->json(200, ['ok' => true, 'actor' => $session->actorId, 'scopes' => $session->scopes])
            ->withHeader('Set-Cookie', SessionCookie::set($this->cookieName, $session->id, $request));
    }

    /**
     * The house's mark and the stylesheet it declares, both from `milpa/live-web`.
     *
     * The ceremony writes NO rules for it. That is the whole point of greenhouse `decisions/0246`:
     * a component carries its own look, so the page embeds what the component asked for and never
     * needs to know what the mark is made of. Before this, the mark's sixty-odd lines of CSS lived
     * in this file — twice, once per page — which is how a design ends up with two versions of
     * itself in one class.
     *
     * @return array{0: string, 1: string} The mark's markup, then the `<style>` its contract asked for.
     */
    private function houseMark(): array
    {
        $component = new BrandMarkComponent();
        $markup = (new BrandMarkHtmlRenderer())->render($component, new RenderRequest(
            context: new ComponentContext('gate-mark', route: '/webauthn'),
            props: ['state' => 'sown', 'label' => 'Milpa'],
        ))->output;

        return [$markup, (new ComponentAssetOrchestrator())->collect([BrandMarkComponent::contract()])->styleTag()];
    }

    /** The self-contained enrollment page: registers a passkey with `navigator.credentials.create`. */
    public function enrollPage(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(
            200,
            ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'],
            $this->ceremonyHtml(GateCeremonyComponent::ENROLL),
        );
    }

    /**
     * The house's design tokens, served beside the ceremony that needs them.
     *
     * Read from `milpa/live-web`, which ships them so a surface can look like the house WITHOUT
     * copying them — three packages already carry drifting copies (greenhouse decisions/0243). This
     * route is not behind the gate on purpose: these pages are how somebody GETS a session, so a
     * stylesheet they cannot fetch would leave the way in looking like nothing else in the house.
     */
    public function tokens(ServerRequestInterface $request): ResponseInterface
    {
        // The name comes from the ROUTE, and `DesignTokens::path()` decides whether it owns it:
        // this class does not validate the path, because validating in two places is having two
        // rules that can disagree. A name the package does not ship comes back `null` and leaves as
        // a 404 — never as a read from disk.
        $name = basename(parse_url((string) $request->getUri()->getPath(), \PHP_URL_PATH) ?: '');
        $file = DesignTokens::path($name === '' ? DesignTokens::TOKENS : $name);
        if ($file === null && $name !== '' && $name !== DesignTokens::TOKENS) {
            return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'this package does not ship ' . $name);
        }
        if ($file === null) {
            // Said, not guessed: a surface that silently serves an empty stylesheet looks styled and
            // is not, and the next person debugs CSS instead of an install.
            return new Response(500, ['Content-Type' => 'text/plain; charset=utf-8'], 'the design tokens are not installed: milpa/live-web ships them');
        }

        return new Response(
            200,
            ['Content-Type' => DesignTokens::contentType($name), 'Cache-Control' => 'public, max-age=300'],
            (string) file_get_contents($file),
        );
    }

    /**
     * The sign-in page (greenhouse decisions/0206, wireframe 2j): runs the authentication ceremony and,
     * on success, returns the browser to `next`.
     *
     * `next` is whatever the gate put in the query — which means anyone can put anything there — so it
     * is validated here, server side, as a LOCAL absolute path ({@see LocalPath}): `//evil`, `https://x`
     * and `\x` all become `/`. The page never trusts the value it embeds.
     */
    public function signinPage(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        if (!\array_key_exists('next', $params)) {
            // The server did not parse the query (a bare PSR-7 request): read the URI's.
            parse_str($request->getUri()->getQuery(), $params);
        }
        $next = LocalPath::orRoot($params['next'] ?? null);

        return new Response(
            200,
            ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'],
            $this->ceremonyHtml(GateCeremonyComponent::SIGNIN, $next),
        );
    }

    /**
     * ONE CEREMONY PAGE, COMPOSED — the document, and the component that fills it.
     *
     * This replaced two ~265-line hand-written templates whose `<style>` blocks were byte-identical
     * and whose scripts each carried their own `mark()`, reachability check and base64url helpers.
     * The house's doctrine is that the UI is a COMPOSITION of components, never hand-written HTML
     * (greenhouse decisions/0189) — and the heredoc those templates were written in is what ate a
     * `\n` meant for JavaScript and shipped a ceremony whose button had no listener
     * (decisions/0261). There is no heredoc here, so that class of defect has nowhere to happen.
     *
     * What this method still owns is the DOCUMENT: the tokens and fonts every surface of the house
     * reads, the component's own two files, and the mark's declared styles. It owns no markup of the
     * ceremony and no line of its CSS.
     */
    private function ceremonyHtml(string $kind, string $next = '/'): string
    {
        // The canon, under this plugin's own prefix — the same call its routes are declared from,
        // so a page can never link a URL this plugin does not serve (greenhouse decisions/0308).
        $design = DesignTokens::urls(PasskeyPlugin::designPrefix());

        [$mark, $markStyles] = $this->houseMark();

        $component = new GateCeremonyComponent();
        $renderer = new GateCeremonyHtmlRenderer($this->events);
        $context = new ComponentContext('gate-ceremony', route: '/webauthn');
        $state = $component->mount([
            'kind' => $kind,
            'rpId' => $this->rpId,
            'scope' => $this->gateScope,
            'next' => $next,
            'attachment' => $this->authenticatorAttachment,
            'markHtml' => $mark,
        ], $context);
        $painted = $renderer->render($component, new RenderRequest(context: $context, state: $state));

        $head = '';
        foreach ($painted->clientAssets()->toArray()['styles'] as $href) {
            $head .= '<link rel="stylesheet" href="' . self::attr($href) . '">' . "\n";
        }
        $tail = '';
        foreach ($painted->clientAssets()->toArray()['scripts'] as $src) {
            $tail .= '<script src="' . self::attr($src) . '" defer></script>' . "\n";
        }

        $title = $kind === GateCeremonyComponent::ENROLL ? 'Register a passkey · Milpa' : 'Sign in · Milpa';

        return '<!doctype html>' . "\n"
            . '<html lang="en" data-theme="dark">' . "\n"
            . '<head>' . "\n"
            . '<meta charset="utf-8">' . "\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '<title>' . self::attr($title) . '</title>' . "\n"
            // The same canon the routes are declared from, so a page can never link a URL this
            // plugin does not serve (greenhouse decisions/0308).
            . '<link rel="stylesheet" href="' . self::attr($design[DesignTokens::FONTS]) . '">' . "\n"
            . '<link rel="stylesheet" href="' . self::attr($design[DesignTokens::TOKENS]) . '">' . "\n"
            . $markStyles . "\n"
            . $head
            . '</head>' . "\n"
            . '<body>' . "\n"
            . $painted->output
            . $tail
            . '</body>' . "\n"
            . '</html>' . "\n";
    }

    /** One escaping rule for everything this document puts in an attribute. */
    private static function attr(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * One of the two client files the ceremony component declares, or a 404.
     *
     * The name arrives from the route, and {@see GateCeremonyAssets::path()} answers from a NAMED
     * LIST — so traversal is unrepresentable rather than filtered, exactly as `tokens()` above reads
     * `DesignTokens`. And like every asset route of this plugin it carries no gate: these pages are
     * how somebody GETS a session, and a 401 answering a `<link>` breaks a page in silence.
     */
    public function ceremonyAsset(ServerRequestInterface $request): ResponseInterface
    {
        $name = basename(parse_url((string) $request->getUri()->getPath(), \PHP_URL_PATH) ?: '');
        $file = GateCeremonyAssets::path($name);
        if ($file === null) {
            return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'this package does not ship ' . $name);
        }

        return new Response(
            200,
            ['Content-Type' => GateCeremonyAssets::contentType($name), 'Cache-Control' => 'no-cache'],
            (string) file_get_contents($file),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(int $status, array $body): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'],
            (string) json_encode($body, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
        );
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

}
