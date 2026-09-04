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

use Milpa\AppRuntime\Web\LocalPath;
use Milpa\AppRuntime\Web\RegisteredCredentialIds;
use Milpa\Auth\WebAuthn\ChallengeStore;
use Milpa\Auth\WebAuthn\PasskeyAuthenticator;
use Milpa\Auth\WebAuthn\PasskeyCredentialStore;
use Milpa\Auth\WebAuthn\PasskeyLogin;
use Milpa\Auth\WebAuthn\WebAuthnRegistrationVerifier;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The HTTP door of a passkey login: hand the browser a fresh challenge, then turn its assertion into a
 * session cookie — or refuse it (greenhouse decisions/0126).
 *
 * The controller is thin on purpose: the ceremony's safety lives in milpa/auth (single-use challenge,
 * signature, clone counter, recognition), and this only marshals bytes across HTTP. The credential's
 * three fields arrive base64url-encoded the way a browser sends them; the credential id stays the
 * base64url STRING it was registered as. On success the minted session id travels in an HttpOnly cookie
 * the framework's StartSession middleware — and {@see \Milpa\AppRuntime\Web\PasskeyGateMiddleware} —
 * reads back.
 *
 * The authentication options carry `allowCredentials` with every registered id (greenhouse
 * decisions/0206): the enrollment ceremony registers NON-DISCOVERABLE credentials (evidence/0486), and
 * a browser only finds one of those when the request names it. `GET /webauthn/signin` is the page that
 * runs the ceremony and returns the human to `next` — a local path, validated server side.
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
        private readonly string $rpId,
        private readonly string $cookieName,
        private readonly string $gateScope = 'milpa.admin',
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
     * Issue a fresh one-time challenge for an authentication ceremony, naming every registered credential.
     *
     * `allowCredentials` lists each registered id as `{type: 'public-key', id: <base64url>}` — the only
     * way a non-discoverable key (a YubiKey enrolled with `residentKey: discouraged`) answers. This is a
     * single-operator panel, so all of them are offered; an id is not a secret, the private key is.
     */
    public function options(ServerRequestInterface $request): ResponseInterface
    {
        $challenge = $this->authenticator->challenge();

        return $this->json(200, [
            'rpId' => $this->rpId,
            'challenge' => self::base64UrlEncode($challenge),
            'allowCredentials' => $this->registered->allowCredentials(),
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
            ->withHeader('Set-Cookie', $this->cookieName . '=' . $session->id . '; HttpOnly; SameSite=Strict; Path=/');
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
        return <<<'HTML'
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Register a passkey</title>
<style>
  body { font: 15px/1.5 system-ui, sans-serif; max-width: 34rem; margin: 4rem auto; padding: 0 1rem; color: #1a1a1a; }
  button { font: inherit; padding: .7rem 1.2rem; border: 0; border-radius: 8px; background: #111; color: #fff; cursor: pointer; }
  button:disabled { opacity: .5; cursor: default; }
  .r { margin-top: 1rem; padding: .8rem 1rem; border-radius: 8px; word-break: break-all; }
  .ok { background: #dcfce7; } .no { background: #fee2e2; }
</style>
<h1>Register a passkey</h1>
<p>Enroll this device's authenticator so it can approve operations. You will be asked to touch it.</p>
<p><button id="go">Register with passkey</button></p>
<div id="out"></div>
<script>
const b64uToBuf = s => Uint8Array.from(atob(s.replace(/-/g,'+').replace(/_/g,'/')), c => c.charCodeAt(0));
const bufToB64u = b => btoa(String.fromCharCode(...new Uint8Array(b))).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');

async function register() {
  const btn = document.getElementById('go'); const out = document.getElementById('out');
  btn.disabled = true; out.textContent = '';
  try {
    const opt = await (await fetch('/webauthn/register/options', { method: 'POST' })).json();

    const userId = crypto.getRandomValues(new Uint8Array(16));
    const cred = await navigator.credentials.create({ publicKey: {
      rp: { id: opt.rpId, name: 'Milpa' },
      user: { id: userId, name: 'operator', displayName: 'Operator' },
      challenge: b64uToBuf(opt.challenge),
      pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
      // Prefer a roaming security key (a YubiKey): cross-platform excludes the built-in platform
      // authenticator, and required user verification means the human's touch/PIN, not mere presence.
      // residentKey is discouraged so a hardware key with scarce slots enrolls as a non-discoverable
      // credential (the server holds the credential id for the approve ceremony). ES256 (alg -7) above
      // is what a FIDO2 key produces, so this stays within the one algorithm milpa/auth verifies.
      authenticatorSelection: { authenticatorAttachment: 'cross-platform', userVerification: 'required', residentKey: 'discouraged' },
      timeout: 60000,
    }});

    const res = await (await fetch('/webauthn/register', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({
        clientDataJSON: bufToB64u(cred.response.clientDataJSON),
        attestationObject: bufToB64u(cred.response.attestationObject),
      })
    })).json();

    out.className = 'r ' + (res.ok ? 'ok' : 'no');
    out.textContent = res.ok ? ('Registered credential: ' + res.credentialId) : ('Refused: ' + (res.error || 'unknown'));
  } catch (e) {
    out.className = 'r no'; out.textContent = 'Registration failed: ' + e.message;
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
        // is text. Everything else is the standard WebAuthn marshalling the other pages share.
        $nextLiteral = (string) json_encode($next, \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_UNESCAPED_SLASHES);
        $scope = htmlspecialchars($this->gateScope, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        $head = <<<'HTML'
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in</title>
<style>
  body { font: 15px/1.5 system-ui, sans-serif; max-width: 34rem; margin: 4rem auto; padding: 0 1rem; color: #1a1a1a; }
  button { font: inherit; padding: .7rem 1.2rem; border: 0; border-radius: 8px; background: #111; color: #fff; cursor: pointer; }
  button:disabled { opacity: .5; cursor: default; }
  .scope { color: #4b5563; }
  code { background: #f3f4f6; border-radius: 4px; padding: .1rem .3rem; }
  .r { margin-top: 1rem; padding: .8rem 1rem; border-radius: 8px; }
  .ok { background: #dcfce7; } .no { background: #fee2e2; }
</style>
<h1>Sign in to open the panel</h1>
<p>Your passkey proves who you are. You will be asked to touch it.</p>
HTML;

        $script = <<<'HTML'
<script>
const b64uToBuf = s => Uint8Array.from(atob(s.replace(/-/g,'+').replace(/_/g,'/')), c => c.charCodeAt(0));
const bufToB64u = b => btoa(String.fromCharCode(...new Uint8Array(b))).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');

async function signin() {
  const btn = document.getElementById('go'); const out = document.getElementById('out');
  btn.disabled = true; out.className = ''; out.textContent = '';
  try {
    const opt = await (await fetch('/webauthn/authenticate/options', { method: 'POST' })).json();
    const allow = (opt.allowCredentials || []).map(c => ({ type: c.type, id: b64uToBuf(c.id) }));
    if (allow.length === 0) {
      out.className = 'r no';
      out.textContent = 'Not enrolled: this house has no registered passkey yet. Register one at /webauthn/enroll, then enroll its credential id.';
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
      out.className = 'r ok'; out.textContent = 'Signed in as ' + body.actor + '. Opening the panel…';
      location.replace(NEXT);
      return;
    }
    out.className = 'r no';
    out.textContent = 'Passkey rejected: the credential is not registered, not enrolled, or the assertion did not verify.';
  } catch (e) {
    out.className = 'r no'; out.textContent = 'Sign-in failed: ' + e.message;
  } finally { btn.disabled = false; }
}
document.getElementById('go').addEventListener('click', signin);
</script>
HTML;

        return $head
            . '<p class="scope">Scope requested: <code>' . $scope . '</code></p>' . "\n"
            . '<p><button id="go">Continue with a passkey</button></p>' . "\n"
            . '<div id="out"></div>' . "\n"
            . '<script>const NEXT = ' . $nextLiteral . ';</script>' . "\n"
            . $script;
    }
}
