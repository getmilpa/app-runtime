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
            $this->enrollHtml(),
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
            $this->signinHtml($next),
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

    private function enrollHtml(): string
    {
        // A self-contained enrollment page: it asks the server for a registration challenge, runs
        // navigator.credentials.create against the real authenticator (the human's device), and posts
        // the attestation back to /webauthn/register. The base64url helpers are the standard WebAuthn
        // marshalling; the server verifies and stores the credential (registered, then enrolled).
        // The relying party is SHOWN because it is the fact that decides whether this credential
        // will work: a key enrolled against another rpId does not open this house, and finding that
        // out at signing time is too late.
        $rp = htmlspecialchars($this->rpId, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        // This house's REAL scope, because step 2's command carries it as an argument. Printing an
        // invented "milpa.admin" when the app declared something else would be a value placed for
        // convenience, which is precisely what the house refuses to do on every surface.
        $scope = htmlspecialchars($this->gateScope, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        // Built here rather than in the template: when the house declares nothing, the property is
        // not emitted at all — an `authenticatorAttachment: null` is NOT the same as omitting it,
        // and the browser reads the first as a constraint nothing can satisfy.
        $attachment = $this->authenticatorAttachment === null
            ? ''
            : \sprintf("authenticatorAttachment: '%s', ", $this->authenticatorAttachment);

        [$mark, $markStyles] = $this->houseMark();

        return <<<HTML
<!doctype html>
<html lang="en" data-theme="dark">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Register a passkey · Milpa</title>
<link rel="stylesheet" href="/webauthn/milpa-fonts.css">
<link rel="stylesheet" href="/webauthn/milpa-tokens.css">
{$markStyles}
<style>
  /* THE SCREEN BEFORE THE PANEL (greenhouse decisions/0243).
     Everything comes from the tokens: a value written by hand here is a fourth copy of the
     design system, which is exactly how the drift started. */
  *, *::before, *::after { box-sizing: border-box; }
  html, body { height: 100%; }
  body { margin: 0; font-family: var(--font-body); font-size: var(--text-base, 1rem);
         line-height: var(--leading-normal, 1.5); color: var(--text); background: var(--bg); }

  .gate { min-height: 100%; display: grid; grid-template-columns: 1fr; }
  @media (min-width: 56rem) { .gate { grid-template-columns: 1fr minmax(24rem, 27rem); } }

  /* ── the brand's half ─────────────────────────────────────────────────── */
  /* The mark and the text are ONE group, anchored to the bottom — not two things pinned to
     opposite corners. The empty space goes above, where it is not in the way. */
  .gate__brand { background: var(--bg); padding: var(--space-8, 2rem);
                 display: grid; grid-template-rows: auto 1fr auto; align-content: stretch;
                 gap: var(--space-8, 2rem); min-width: 0; }
  .gate__brand .gate__mark { align-self: end; }
  .gate__mark { display: flex; align-items: flex-end; }
  /* The wordmark IDENTIFIES — top, small, still; the symbol REPORTS state below. They stay
     apart rather than as a lockup because one of them moves and the kit's lockup does not. */
  .gate__wordmark { display: inline-flex; align-self: flex-start; border-radius: var(--radius-sm, 4px); }
  .gate__wordmark img { height: var(--space-8, 2rem); width: auto; display: block; }
  .gate__wordmark:focus-visible { outline: var(--focus-width, 2px) solid var(--accent); outline-offset: 4px; }
  /* The symbol is BRAND, not UI: constant mono-gold in both themes, never var(--accent)
     (logo/README.txt). That is why the fill is a literal and not a token. */
  .gate__lede { max-width: 34ch; }
  .kicker { font-family: var(--font-mono); font-size: var(--text-xs, .75rem);
            letter-spacing: var(--tracking-wide, .08em); text-transform: uppercase;
            color: var(--olivo-300, var(--text-muted)); margin: 0 0 var(--space-3, .75rem); }
  h1 { font-family: var(--font-heading); font-size: var(--text-4xl, 2.25rem);
       line-height: var(--leading-tight, 1.1); letter-spacing: var(--tracking-tight, -.02em);
       font-weight: var(--weight-bold, 700); color: var(--text); margin: 0 0 var(--space-3, .75rem);
       text-wrap: balance; }
  .gate__lede p { color: var(--text-secondary); margin: 0; text-wrap: pretty; }
  .gate__lede p + p { margin-top: var(--space-3, .75rem); }
  /* THE ONE LINE THAT HAS TO SURVIVE THIS SCREEN (greenhouse decisions/0260). The lede used to open by
     explaining what kind of framework this is, to somebody who came here to act — and buried the only
     sentence that changes what they should expect. It carries the page's weight, so it looks like it. */
  .gate__doctrine { color: var(--text); font-weight: var(--weight-medium, 500); }
  /* STEP 2 FINISHES THE SENTENCE STEP 1 STARTED (greenhouse decisions/0260). «Registering identifies
     you; it grants no permissions» is half a thought until the other half says where permission DOES
     come from. Together the two teach identity ≠ authority with no architecture lecture at all — which
     is why step 2 is on this page although it does not happen here. It reads as what comes next, not
     as an equal: its own kicker, quieter than the doctrine above it. */
  .gate__next { margin-top: var(--space-5, 1.5rem); color: var(--text-muted); }
  .gate__next .kicker { display: block; margin-bottom: var(--space-1, .25rem); }
  /* The house's own facts are true and SECONDARY: behind a disclosure, so the main hierarchy stays the
     ceremony. Whoever needs the relying party knows to look; nobody else pays for it. */
  .gate__tech > summary { cursor: pointer; color: var(--text-muted); }
  .gate__tech > summary:focus-visible { outline: var(--focus-width, 2px) solid var(--accent); outline-offset: 3px; }
  .gate__tech[open] > summary { margin-bottom: var(--space-2, .5rem); }

  /* ── the act's half ───────────────────────────────────────────────────── */
  /* The act is centred: pinned to the top it left the whole column empty below the button. */
  .gate__act { background: var(--surface); border-left: 1px solid var(--border-subtle);
               padding: var(--space-8, 2rem); display: grid;
               grid-template-rows: 1fr auto; gap: var(--space-6, 1.5rem); min-width: 0; }
  .gate__body { align-self: center; }
  @media (max-width: 55.99rem) { .gate__act { border-left: 0; border-top: 1px solid var(--border-subtle); } }
  .gate__body { display: flex; flex-direction: column; gap: var(--space-4, 1rem); }
  .gate__foot { align-self: end; }
  .gate__foot { font-family: var(--font-mono); font-size: var(--text-2xs, .6875rem);
                color: var(--text-muted); display: flex; flex-direction: column;
                gap: var(--space-2, .5rem); }
  .gate__foot p { margin: 0; display: flex; flex-wrap: wrap;
                  gap: var(--space-1, .25rem) var(--space-3, .75rem); color: inherit; }
  /* The sentence that teaches the term takes the text face, not the data face: it is prose. */
  .gate__foot .gate__teach { font-family: var(--font-body); font-size: var(--text-xs, .75rem);
                             max-width: 44ch; display: block; }
  .gate__foot a { color: var(--accent-text, var(--accent)); text-decoration: none; }
  .gate__foot a:hover { text-decoration: underline; }
  .gate__foot a:focus-visible { outline: var(--focus-width, 2px) solid var(--accent); outline-offset: 2px; }

  button { font: inherit; font-family: var(--font-heading); font-weight: var(--weight-medium, 500);
           width: 100%; padding: var(--space-3, .75rem) var(--space-5, 1.25rem);
           border: var(--border-width, 1px) var(--border-style, solid) transparent;
           border-radius: var(--radius-md, 6px); background: var(--accent);
           color: var(--text-on-accent); cursor: pointer; }
  button:hover:not(:disabled) { background: var(--accent-hover); }
  button:focus-visible { outline: var(--focus-width, 2px) solid var(--accent); outline-offset: 2px; }
  button:disabled { opacity: .5; cursor: default; }
  .scope { color: var(--text-muted); font-size: var(--text-sm, .875rem); margin: 0; }
  code { font-family: var(--font-mono); background: var(--surface-raised, var(--bg));
         border: 1px solid var(--border-subtle); border-radius: var(--radius-sm, 4px);
         padding: 0 var(--space-1, .25rem); }
  .r { padding: var(--space-3, .75rem) var(--space-4, 1rem); border-radius: var(--radius-md, 6px);
       overflow-wrap: anywhere; white-space: pre-wrap; font-family: var(--font-mono);
       font-size: var(--text-sm, .875rem); line-height: var(--leading-normal, 1.5);
       border: 1px solid var(--border); background: var(--bg); color: var(--text); }
  .ok { border-color: var(--success); } .no { border-color: var(--danger); }

</style>
<div class="gate">
  <aside class="gate__brand">
    <a class="gate__wordmark" href="https://getmilpa.com" target="_blank" rel="noopener noreferrer"><img src="/webauthn/milpa-wordmark.svg" alt="Milpa" width="2407" height="900"></a>
    <div class="gate__mark">{$mark}</div>
    <div class="gate__lede">
      <p class="kicker">Step 1 · Who are you?</p>
      <h1>Register a passkey</h1>
      <p>Give this house a verifiable identity for you.</p>
      <p>Use your device, password manager, or security key.</p>
      <p class="gate__doctrine">Registering identifies you. It grants no permissions.</p>
      <p class="gate__next"><span class="kicker">Step 2 · What may you do?</span>Choose what this identity may do.</p>
    </div>
  </aside>
  <main class="gate__act">
    <div class="gate__body">
      <button id="go">Register with passkey</button>
      <div id="out"></div>
    </div>
    <div class="gate__foot">
      <details class="gate__tech">
        <summary>Technical details</summary>
        <p><span>Relying party: <code>{$rp}</code></span><span>Required scope: <code>{$scope}</code></span></p>
        <p class="gate__teach">Credentials from another house are not accepted.</p>
      </details>
      <p><a href="/webauthn/signin">Already enrolled? Sign in →</a></p>
    </div>
  </main>
</div>
<script>
// The name this house answers to, placed by the server: the client cannot guess it, and asking
// for it over the network would be a round trip for a fact that is already here.
const RP_ID = "{$rp}";
const SCOPE = "{$scope}";
// The mark reports where the ceremony is: sown on arrival, growing while it waits — the touch,
// the verification, whatever comes next loading — and opening once when it lands. One state,
// not three animations stuck together (greenhouse decisions/0243).
const markEl = document.querySelector('[data-milpa-component="brand-mark"]');
const mark = state => markEl && markEl.setAttribute('data-state', state);

// THE PAGE CHECKS ITSELF BEFORE IT OFFERS THE BUTTON (greenhouse decisions/0244).
//
// WebAuthn requires the relying party id to be a registrable suffix of the page's own host, and it
// requires a secure context. Neither is knowable to the server — it cannot see the URL the human
// typed — but both are knowable HERE, at load, before anybody presses anything. Without this the
// ceremony fails with the browser's own words: "This is an invalid domain." True, and useless: it
// names neither what was expected nor how to get there.
//
// Measured: a page served on 127.0.0.1 with rpId `localhost` refuses every ceremony this way. Same
// bytes, same app — a different name in the address bar.
function houseIsReachable() {
  const out = document.getElementById('out');
  const btn = document.getElementById('go');
  const host = location.hostname;
  const suffix = host === RP_ID || host.endsWith('.' + RP_ID);
  if (!window.isSecureContext) {
    out.className = 'r no';
    out.textContent = 'A passkey needs a secure page. Open this over https, or on localhost. This page is ' + location.origin + '.';
    btn.disabled = true;
    return false;
  }
  if (!suffix) {
    out.className = 'r no';
    out.textContent = 'This house answers to \u201c' + RP_ID + '\u201d and you opened it as \u201c' + host + '\u201d, so no key can be registered here — a passkey is bound to the name in the address bar. Open ' + location.protocol + '//' + RP_ID + (location.port ? ':' + location.port : '') + location.pathname + ' instead, or declare passkey.rpId to match the name you use.';
    btn.disabled = true;
    return false;
  }
  return true;
}
document.addEventListener('DOMContentLoaded', houseIsReachable);

const b64uToBuf = s => Uint8Array.from(atob(s.replace(/-/g,'+').replace(/_/g,'/')), c => c.charCodeAt(0));
const bufToB64u = b => btoa(String.fromCharCode(...new Uint8Array(b))).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');

async function register() {
  const btn = document.getElementById('go'); const out = document.getElementById('out');
  if (!houseIsReachable()) { return; }
  btn.disabled = true; out.textContent = ''; mark('growing');
  try {
    // Same lesson as the sign-in page (greenhouse evidence/0519): an extension that replaced
    // navigator.credentials.create can swallow the ceremony without a dialog or an error.
    if (!/\[native code\]/.test(String(navigator.credentials.create))) {
      out.className = 'r no';
      out.textContent = 'A browser extension has replaced navigator.credentials.create on this page. If no passkey dialog opens, retry in a browser profile without that extension.';
    }
    const opt = await (await fetch('/webauthn/register/options', { method: 'POST' })).json();

    const userId = crypto.getRandomValues(new Uint8Array(16));
    const cred = await navigator.credentials.create({ publicKey: {
      rp: { id: opt.rpId, name: 'Milpa' },
      user: { id: userId, name: 'operator', displayName: 'Operator' },
      challenge: b64uToBuf(opt.challenge),
      pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
      // WHAT THIS HOUSE ACCEPTS, declared and not assumed (greenhouse decisions/0244).
      //
      // `userVerification: 'required'` stays and is the point: the human's touch or PIN, never mere
      // presence — a platform authenticator and a password manager both satisfy it. What left is the
      // hardcoded `authenticatorAttachment: 'cross-platform'`, which EXCLUDED the built-in
      // authenticator and every password manager, i.e. the two places a passkey lives on a machine
      // somebody already owns. A house that wants hardware only declares `passkey.authenticator`.
      //
      // residentKey is discouraged so a hardware key with scarce slots enrolls as a non-discoverable
      // credential (the server holds the credential id for the approve ceremony), and it works the
      // same for the other two. ES256 (alg -7) above is what a FIDO2 key produces, so this stays
      // within the one algorithm milpa/auth verifies.
      authenticatorSelection: { {$attachment}userVerification: 'required', residentKey: 'discouraged' },
      timeout: 60000,
    }});

    const res = await (await fetch('/webauthn/register', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({
        clientDataJSON: bufToB64u(cred.response.clientDataJSON),
        attestationObject: bufToB64u(cred.response.attestationObject),
      })
    })).json();

    // SUCCESS SAYS WHAT IS STILL MISSING. Registering is not permission: the server has been
    // saying so in `note` all along and this page threw it away, so somebody read "Registered
    // credential: T05…" and concluded, reasonably, that they were in. They were not
    // (greenhouse decisions/0244).
    out.className = 'r ' + (res.ok ? 'ok' : 'no');
    out.textContent = res.ok
      ? 'Registered. This house now holds the public key of credential ' + res.credentialId
        + '. It grants nothing yet — to say what this key may do, run:\n\n'
        + 'php bin/coa identity:enroll --fingerprint=' + res.credentialId + ' --scopes=' + SCOPE + ' --sign\n\n'
        + 'That command needs a principal this house already recognizes. Then sign in at /webauthn/signin.'
      : 'Refused: the challenge was already spent, or the attestation did not verify. Nothing was stored — press again for a fresh challenge.';
    mark(res.ok ? 'ready' : 'sown');
  } catch (e) {
    out.className = 'r no';
    out.textContent = 'The key did not answer: ' + e.message + '. Nothing was stored.';
    mark('sown');
  } finally { btn.disabled = false; }
}
document.getElementById('go').addEventListener('click', register);
</script>
HTML;
    }

    private function signinHtml(string $next): string
    {
        // The embedded values are the only server-chosen parts of the page: `next` was validated as a
        // local path and is emitted as a JSON string literal (with </script>-safe escaping); the scope
        // is text. The copy is wireframe 2j's (greenhouse decisions/0203), verbatim. Everything else is
        // the standard WebAuthn marshalling the other pages share.
        $nextLiteral = (string) json_encode($next, \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_UNESCAPED_SLASHES);
        $scope = htmlspecialchars($this->gateScope, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        $head = <<<'HTML'
<!doctype html>
<html lang="en" data-theme="dark">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · Milpa</title>
<link rel="stylesheet" href="/webauthn/milpa-fonts.css">
<link rel="stylesheet" href="/webauthn/milpa-tokens.css">
{$markStyles}
<style>
  /* THE SCREEN BEFORE THE PANEL (greenhouse decisions/0243).
     Everything comes from the tokens: a value written by hand here is a fourth copy of the
     design system, which is exactly how the drift started. */
  *, *::before, *::after { box-sizing: border-box; }
  html, body { height: 100%; }
  body { margin: 0; font-family: var(--font-body); font-size: var(--text-base, 1rem);
         line-height: var(--leading-normal, 1.5); color: var(--text); background: var(--bg); }

  .gate { min-height: 100%; display: grid; grid-template-columns: 1fr; }
  @media (min-width: 56rem) { .gate { grid-template-columns: 1fr minmax(24rem, 27rem); } }

  /* ── the brand's half ─────────────────────────────────────────────────── */
  /* The mark and the text are ONE group, anchored to the bottom — not two things pinned to
     opposite corners. The empty space goes above, where it is not in the way. */
  .gate__brand { background: var(--bg); padding: var(--space-8, 2rem);
                 display: grid; grid-template-rows: auto 1fr auto; align-content: stretch;
                 gap: var(--space-8, 2rem); min-width: 0; }
  .gate__brand .gate__mark { align-self: end; }
  .gate__mark { display: flex; align-items: flex-end; }
  /* The wordmark IDENTIFIES — top, small, still; the symbol REPORTS state below. They stay
     apart rather than as a lockup because one of them moves and the kit's lockup does not. */
  .gate__wordmark { display: inline-flex; align-self: flex-start; border-radius: var(--radius-sm, 4px); }
  .gate__wordmark img { height: var(--space-8, 2rem); width: auto; display: block; }
  .gate__wordmark:focus-visible { outline: var(--focus-width, 2px) solid var(--accent); outline-offset: 4px; }
  /* The symbol is BRAND, not UI: constant mono-gold in both themes, never var(--accent)
     (logo/README.txt). That is why the fill is a literal and not a token. */
  .gate__lede { max-width: 34ch; }
  .kicker { font-family: var(--font-mono); font-size: var(--text-xs, .75rem);
            letter-spacing: var(--tracking-wide, .08em); text-transform: uppercase;
            color: var(--olivo-300, var(--text-muted)); margin: 0 0 var(--space-3, .75rem); }
  h1 { font-family: var(--font-heading); font-size: var(--text-4xl, 2.25rem);
       line-height: var(--leading-tight, 1.1); letter-spacing: var(--tracking-tight, -.02em);
       font-weight: var(--weight-bold, 700); color: var(--text); margin: 0 0 var(--space-3, .75rem);
       text-wrap: balance; }
  .gate__lede p { color: var(--text-secondary); margin: 0; text-wrap: pretty; }
  .gate__lede p + p { margin-top: var(--space-3, .75rem); }
  /* THE ONE LINE THAT HAS TO SURVIVE THIS SCREEN (greenhouse decisions/0260). The lede used to open by
     explaining what kind of framework this is, to somebody who came here to act — and buried the only
     sentence that changes what they should expect. It carries the page's weight, so it looks like it. */
  .gate__doctrine { color: var(--text); font-weight: var(--weight-medium, 500); }
  /* STEP 2 FINISHES THE SENTENCE STEP 1 STARTED (greenhouse decisions/0260). «Registering identifies
     you; it grants no permissions» is half a thought until the other half says where permission DOES
     come from. Together the two teach identity ≠ authority with no architecture lecture at all — which
     is why step 2 is on this page although it does not happen here. It reads as what comes next, not
     as an equal: its own kicker, quieter than the doctrine above it. */
  .gate__next { margin-top: var(--space-5, 1.5rem); color: var(--text-muted); }
  .gate__next .kicker { display: block; margin-bottom: var(--space-1, .25rem); }
  /* The house's own facts are true and SECONDARY: behind a disclosure, so the main hierarchy stays the
     ceremony. Whoever needs the relying party knows to look; nobody else pays for it. */
  .gate__tech > summary { cursor: pointer; color: var(--text-muted); }
  .gate__tech > summary:focus-visible { outline: var(--focus-width, 2px) solid var(--accent); outline-offset: 3px; }
  .gate__tech[open] > summary { margin-bottom: var(--space-2, .5rem); }

  /* ── the act's half ───────────────────────────────────────────────────── */
  /* The act is centred: pinned to the top it left the whole column empty below the button. */
  .gate__act { background: var(--surface); border-left: 1px solid var(--border-subtle);
               padding: var(--space-8, 2rem); display: grid;
               grid-template-rows: 1fr auto; gap: var(--space-6, 1.5rem); min-width: 0; }
  .gate__body { align-self: center; }
  @media (max-width: 55.99rem) { .gate__act { border-left: 0; border-top: 1px solid var(--border-subtle); } }
  .gate__body { display: flex; flex-direction: column; gap: var(--space-4, 1rem); }
  .gate__foot { align-self: end; }
  .gate__foot { font-family: var(--font-mono); font-size: var(--text-2xs, .6875rem);
                color: var(--text-muted); display: flex; flex-direction: column;
                gap: var(--space-2, .5rem); }
  .gate__foot p { margin: 0; display: flex; flex-wrap: wrap;
                  gap: var(--space-1, .25rem) var(--space-3, .75rem); color: inherit; }
  /* The sentence that teaches the term takes the text face, not the data face: it is prose. */
  .gate__foot .gate__teach { font-family: var(--font-body); font-size: var(--text-xs, .75rem);
                             max-width: 44ch; display: block; }
  .gate__foot a { color: var(--accent-text, var(--accent)); text-decoration: none; }
  .gate__foot a:hover { text-decoration: underline; }
  .gate__foot a:focus-visible { outline: var(--focus-width, 2px) solid var(--accent); outline-offset: 2px; }

  button { font: inherit; font-family: var(--font-heading); font-weight: var(--weight-medium, 500);
           width: 100%; padding: var(--space-3, .75rem) var(--space-5, 1.25rem);
           border: var(--border-width, 1px) var(--border-style, solid) transparent;
           border-radius: var(--radius-md, 6px); background: var(--accent);
           color: var(--text-on-accent); cursor: pointer; }
  button:hover:not(:disabled) { background: var(--accent-hover); }
  button:focus-visible { outline: var(--focus-width, 2px) solid var(--accent); outline-offset: 2px; }
  button:disabled { opacity: .5; cursor: default; }
  .scope { color: var(--text-muted); font-size: var(--text-sm, .875rem); margin: 0; }
  code { font-family: var(--font-mono); background: var(--surface-raised, var(--bg));
         border: 1px solid var(--border-subtle); border-radius: var(--radius-sm, 4px);
         padding: 0 var(--space-1, .25rem); }
  .r { padding: var(--space-3, .75rem) var(--space-4, 1rem); border-radius: var(--radius-md, 6px);
       overflow-wrap: anywhere; white-space: pre-wrap; font-family: var(--font-mono);
       font-size: var(--text-sm, .875rem); line-height: var(--leading-normal, 1.5);
       border: 1px solid var(--border); background: var(--bg); color: var(--text); }
  .ok { border-color: var(--success); } .no { border-color: var(--danger); }

</style>
<div class="gate">
  <aside class="gate__brand">
    <a class="gate__wordmark" href="https://getmilpa.com" target="_blank" rel="noopener noreferrer"><img src="/webauthn/milpa-wordmark.svg" alt="Milpa" width="2407" height="900"></a>
    <div class="gate__mark">{$mark}</div>
    <div class="gate__lede">
      <p class="kicker">House identity · the gate</p>
      <h1>Sign in</h1>
      <p>Confirm the key this house already recognizes.</p>
      <p class="gate__doctrine">The house checks the signature before it mints a session.</p>
    </div>
  </aside>
  <main class="gate__act">
HTML;

        $script = <<<'HTML'
<script>
// The mark reports where the ceremony is: sown on arrival, growing while it waits — the touch,
// the verification, whatever comes next loading — and opening once when it lands. One state,
// not three animations stuck together (greenhouse decisions/0243).
const markEl = document.querySelector('[data-milpa-component="brand-mark"]');
const mark = state => markEl && markEl.setAttribute('data-state', state);

// THE PAGE CHECKS ITSELF BEFORE IT OFFERS THE BUTTON (greenhouse decisions/0244).
//
// WebAuthn requires the relying party id to be a registrable suffix of the page's own host, and it
// requires a secure context. Neither is knowable to the server — it cannot see the URL the human
// typed — but both are knowable HERE, at load, before anybody presses anything. Without this the
// ceremony fails with the browser's own words: "This is an invalid domain." True, and useless: it
// names neither what was expected nor how to get there.
//
// Measured: a page served on 127.0.0.1 with rpId `localhost` refuses every ceremony this way. Same
// bytes, same app — a different name in the address bar.
function houseIsReachable() {
  const out = document.getElementById('out');
  const btn = document.getElementById('go');
  const host = location.hostname;
  const suffix = host === RP_ID || host.endsWith('.' + RP_ID);
  if (!window.isSecureContext) {
    out.className = 'r no';
    out.textContent = 'A passkey needs a secure page. Open this over https, or on localhost. This page is ' + location.origin + '.';
    btn.disabled = true;
    return false;
  }
  if (!suffix) {
    out.className = 'r no';
    out.textContent = 'This house answers to \u201c' + RP_ID + '\u201d and you opened it as \u201c' + host + '\u201d, so no key can be registered here — a passkey is bound to the name in the address bar. Open ' + location.protocol + '//' + RP_ID + (location.port ? ':' + location.port : '') + location.pathname + ' instead, or declare passkey.rpId to match the name you use.';
    btn.disabled = true;
    return false;
  }
  return true;
}
document.addEventListener('DOMContentLoaded', houseIsReachable);

const b64uToBuf = s => Uint8Array.from(atob(s.replace(/-/g,'+').replace(/_/g,'/')), c => c.charCodeAt(0));
const bufToB64u = b => btoa(String.fromCharCode(...new Uint8Array(b))).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');

async function signin() {
  const btn = document.getElementById('go'); const out = document.getElementById('out');
  if (!houseIsReachable()) { return; }
  btn.disabled = true; out.className = ''; out.textContent = ''; mark('growing');
  try {
    // A browser extension (a password manager offering its own passkeys, usually) may have replaced
    // navigator.credentials.get; when it swallows the call, no dialog opens and no error ever comes
    // back (greenhouse evidence/0519). Say so before waiting on it.
    if (!/\[native code\]/.test(String(navigator.credentials.get))) {
      out.className = 'r no';
      out.textContent = 'A browser extension has replaced navigator.credentials.get on this page. If no passkey dialog opens, retry in a browser profile without that extension.';
    }
    const opt = await (await fetch('/webauthn/authenticate/options', { method: 'POST' })).json();
    const allow = (opt.allowCredentials || []).map(c => ({ type: c.type, id: b64uToBuf(c.id) }));
    if (allow.length === 0) {
      out.className = 'r no';
      // Registered ∩ enrolled is empty. Registered is not enrolled, and revoked is not enrolled either
      // (greenhouse evidence/0519): say which act is missing, not "no passkey".
      out.textContent = 'Not enrolled: no passkey is recognised by this house. A registered key nobody enrolled, or one that was revoked, is not offered. Register one at /webauthn/enroll if you have none, then enroll its credential id with identity:enroll.';
      return;
    }

    // Non-discoverable credentials (residentKey: discouraged at enrollment) are only found when the
    // request names them — that is what allowCredentials is for. User verification is required, as
    // at enrollment: the human's touch/PIN, not mere presence.
    const assertion = await navigator.credentials.get({ publicKey: {
      challenge: b64uToBuf(opt.challenge),
      rpId: opt.rpId,
      allowCredentials: allow,
      userVerification: 'required',
      timeout: 60000,
    }});

    const r = assertion.response;
    const res = await fetch('/webauthn/authenticate', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({
        credentialId: bufToB64u(assertion.rawId),
        clientDataJSON: bufToB64u(r.clientDataJSON),
        authenticatorData: bufToB64u(r.authenticatorData),
        signature: bufToB64u(r.signature),
      })
    });
    const body = await res.json().catch(() => ({}));
    if (res.ok && body.ok) {
      // Names WHERE it goes, not what it assumes is there: this gate guards whatever the app put
      // behind it, and a house with no panel installed still has to be able to get in.
      out.className = 'r ok'; out.textContent = 'Signed in as ' + body.actor + '. Returning you to ' + NEXT;
      // The mark opens BEFORE the jump: without that pulse the page change reads as a cut and
      // nobody sees their key worked. The wait is the animation's, not an invented number — and
      // whoever asked for less motion does not sit through it.
      mark('ready');
      const wait = matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 520;
      setTimeout(() => location.replace(NEXT), wait);
      return;
    }
    out.className = 'r no';
    out.textContent = 'Passkey rejected: the credential is not registered, not enrolled, or the assertion did not verify.';
  } catch (e) {
    out.className = 'r no'; out.textContent = 'Sign-in failed: ' + e.message; mark('sown');
  } finally { btn.disabled = false; }
}
document.getElementById('go').addEventListener('click', signin);
</script>
HTML;

        // The head stays a NOWDOC — nothing in it interpolates, which is why the page's only
        // server-chosen values are the ones placed deliberately. The mark and its stylesheet are
        // substituted here, by name, rather than by turning the whole block into a heredoc.
        [$mark, $markStyles] = $this->houseMark();
        $head = strtr($head, ['{$markStyles}' => $markStyles, '{$mark}' => $mark]);

        return $head
            . '<div class="gate__body">' . "\n"
            . '<button id="go">Continue with a passkey</button>' . "\n"
            . '<div id="out"></div>' . "\n"
            . '</div>' . "\n"
            . '<div class="gate__foot">'
            . '<details class="gate__tech"><summary>Technical details</summary>'
            . '<p><span>Relying party: <code>' . htmlspecialchars($this->rpId, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</code></span>'
            . '<span>Required scope: <code>' . $scope . '</code></span></p>'
            . '<p class="gate__teach">Credentials from another house are not accepted.</p></details>'
            . '<p><a href="/webauthn/enroll">No key on this house yet? Register one →</a></p>'
            . '</div>' . "\n"
            . '</main></div>' . "\n"
            . '<script>const NEXT = ' . $nextLiteral . '; const RP_ID = '
            . json_encode($this->rpId, \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_THROW_ON_ERROR)
            . ';</script>' . "\n"
            . $script;
    }
}
