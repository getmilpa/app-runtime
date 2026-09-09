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
        $file = DesignTokens::path(DesignTokens::TOKENS);
        if ($file === null) {
            // Said, not guessed: a surface that silently serves an empty stylesheet looks styled and
            // is not, and the next person debugs CSS instead of an install.
            return new Response(500, ['Content-Type' => 'text/plain; charset=utf-8'], 'the design tokens are not installed: milpa/live-web ships them');
        }

        return new Response(
            200,
            ['Content-Type' => DesignTokens::contentType(), 'Cache-Control' => 'public, max-age=300'],
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
        // El relying party se PINTA porque es el hecho que decide si esta credencial servirá: una
        // llave enrolada contra otro rpId no abre esta casa, y descubrirlo al firmar es tarde.
        $rp = htmlspecialchars($this->rpId, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<!doctype html>
<html lang="en" data-theme="dark">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Register a passkey · Milpa</title>
<link rel="stylesheet" href="/webauthn/milpa-tokens.css">
<style>
  /* LA PANTALLA DE ANTES DEL PANEL (greenhouse decisions/0243).
     Todo sale de los tokens: un valor escrito a mano aquí es una cuarta copia
     del sistema de diseño, que es exactamente como empezó el drift. */
  *, *::before, *::after { box-sizing: border-box; }
  html, body { height: 100%; }
  body { margin: 0; font-family: var(--font-body); font-size: var(--text-base, 1rem);
         line-height: var(--leading-normal, 1.5); color: var(--text); background: var(--bg); }

  .gate { min-height: 100%; display: grid; grid-template-columns: 1fr; }
  @media (min-width: 56rem) { .gate { grid-template-columns: 1fr minmax(24rem, 27rem); } }

  /* ── la mitad de la marca ─────────────────────────────────────────────── */
  /* La marca y el texto son UN grupo, anclado abajo — no dos cosas pegadas a esquinas
     opuestas. El vacío va arriba, que es donde no estorba. */
  .gate__brand { background: var(--bg); padding: var(--space-8, 2rem);
                 display: flex; flex-direction: column; justify-content: flex-end;
                 gap: var(--space-8, 2rem); min-width: 0; }
  .gate__mark { display: flex; align-items: flex-end; }
  /* El símbolo es MARCA, no UI: mono-oro constante en ambos temas, nunca var(--accent)
     (logo/README.txt). Por eso el fill va literal y no por token. */
  .grano { width: clamp(5rem, 13vw, 9rem); height: auto; display: block; overflow: visible; }
  .grano rect { fill: #E8B14C; }
  .gate__lede { max-width: 34ch; }
  .kicker { font-family: var(--font-mono); font-size: var(--text-xs, .75rem);
            letter-spacing: var(--tracking-wide, .08em); text-transform: uppercase;
            color: var(--olivo-300, var(--text-muted)); margin: 0 0 var(--space-3, .75rem); }
  h1 { font-family: var(--font-heading); font-size: var(--text-4xl, 2.25rem);
       line-height: var(--leading-tight, 1.1); letter-spacing: var(--tracking-tight, -.02em);
       font-weight: var(--weight-bold, 700); color: var(--text); margin: 0 0 var(--space-3, .75rem);
       text-wrap: balance; }
  .gate__lede p { color: var(--text-secondary); margin: 0; text-wrap: pretty; }

  /* ── la mitad del acto ────────────────────────────────────────────────── */
  /* El acto se centra: pegado arriba dejaba la columna entera vacía debajo del botón. */
  .gate__act { background: var(--surface); border-left: 1px solid var(--border-subtle);
               padding: var(--space-8, 2rem); display: grid;
               grid-template-rows: 1fr auto; gap: var(--space-6, 1.5rem); min-width: 0; }
  .gate__body { align-self: center; }
  @media (max-width: 55.99rem) { .gate__act { border-left: 0; border-top: 1px solid var(--border-subtle); } }
  .gate__body { display: flex; flex-direction: column; gap: var(--space-4, 1rem); }
  .gate__foot { align-self: end; }
  .gate__foot { font-family: var(--font-mono); font-size: var(--text-2xs, .6875rem);
                color: var(--text-muted); margin: 0; display: flex; flex-wrap: wrap;
                gap: var(--space-1, .25rem) var(--space-3, .75rem); }

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
       word-break: break-all; font-family: var(--font-mono); font-size: var(--text-sm, .875rem);
       border: 1px solid var(--border); background: var(--bg); color: var(--text); }
  .ok { border-color: var(--success); } .no { border-color: var(--danger); }

  /* ── LA SIEMBRA ───────────────────────────────────────────────────────────
     Una milpa se siembra: los granos caen en el orden en que se plantan —la
     columna izquierda, la diagonal, la derecha— y se asientan. No es un
     fade-in genérico; es lo que hace la marca. */
  @keyframes sembrar {
    from { opacity: 0; transform: translateY(-.6rem) scale(.85); }
    to   { opacity: 1; transform: none; }
  }
  .grano rect { opacity: 0; transform-box: fill-box; transform-origin: center;
                animation: sembrar var(--dur-slow, 420ms) var(--ease-standard, cubic-bezier(.4,0,.2,1)) forwards;
                animation-delay: calc(var(--i) * var(--stagger-tight, 40ms)); }
  @media (prefers-reduced-motion: reduce) {
    .grano rect { animation: none; opacity: 1; transform: none; }
  }
</style>
<div class="gate">
  <aside class="gate__brand">
    <div class="gate__mark"><svg class="grano" viewBox="0 0 60 60" role="img" aria-label="Milpa"><rect x="0.0" y="0.0" width="10" height="10" rx="2.5" style="--i:0"/><rect x="0.0" y="12.5" width="10" height="10" rx="2.5" style="--i:1"/><rect x="0.0" y="25.0" width="10" height="10" rx="2.5" style="--i:2"/><rect x="0.0" y="37.5" width="10" height="10" rx="2.5" style="--i:3"/><rect x="0.0" y="50.0" width="10" height="10" rx="2.5" style="--i:4"/><rect x="12.5" y="12.5" width="10" height="10" rx="2.5" style="--i:5"/><rect x="25.0" y="25.0" width="10" height="10" rx="2.5" style="--i:6"/><rect x="37.5" y="12.5" width="10" height="10" rx="2.5" style="--i:7"/><rect x="50.0" y="0.0" width="10" height="10" rx="2.5" style="--i:8"/><rect x="50.0" y="12.5" width="10" height="10" rx="2.5" style="--i:9"/><rect x="50.0" y="25.0" width="10" height="10" rx="2.5" style="--i:10"/><rect x="50.0" y="37.5" width="10" height="10" rx="2.5" style="--i:11"/><rect x="50.0" y="50.0" width="10" height="10" rx="2.5" style="--i:12"/></svg></div>
    <div class="gate__lede">
      <p class="kicker">House identity</p>
      <h1>Register a passkey</h1>
      <p>Enroll this device's authenticator so it can approve operations. You will be asked to touch it.</p>
    </div>
  </aside>
  <main class="gate__act">
    <div class="gate__body">
      <button id="go">Register with passkey</button>
      <div id="out"></div>
    </div>
    <p class="gate__foot"><span>relying party: <code>{$rp}</code></span></p>
  </main>
</div>
<script>
const b64uToBuf = s => Uint8Array.from(atob(s.replace(/-/g,'+').replace(/_/g,'/')), c => c.charCodeAt(0));
const bufToB64u = b => btoa(String.fromCharCode(...new Uint8Array(b))).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');

async function register() {
  const btn = document.getElementById('go'); const out = document.getElementById('out');
  btn.disabled = true; out.textContent = '';
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
<link rel="stylesheet" href="/webauthn/milpa-tokens.css">
<style>
  /* LA PANTALLA DE ANTES DEL PANEL (greenhouse decisions/0243).
     Todo sale de los tokens: un valor escrito a mano aquí es una cuarta copia
     del sistema de diseño, que es exactamente como empezó el drift. */
  *, *::before, *::after { box-sizing: border-box; }
  html, body { height: 100%; }
  body { margin: 0; font-family: var(--font-body); font-size: var(--text-base, 1rem);
         line-height: var(--leading-normal, 1.5); color: var(--text); background: var(--bg); }

  .gate { min-height: 100%; display: grid; grid-template-columns: 1fr; }
  @media (min-width: 56rem) { .gate { grid-template-columns: 1fr minmax(24rem, 27rem); } }

  /* ── la mitad de la marca ─────────────────────────────────────────────── */
  /* La marca y el texto son UN grupo, anclado abajo — no dos cosas pegadas a esquinas
     opuestas. El vacío va arriba, que es donde no estorba. */
  .gate__brand { background: var(--bg); padding: var(--space-8, 2rem);
                 display: flex; flex-direction: column; justify-content: flex-end;
                 gap: var(--space-8, 2rem); min-width: 0; }
  .gate__mark { display: flex; align-items: flex-end; }
  /* El símbolo es MARCA, no UI: mono-oro constante en ambos temas, nunca var(--accent)
     (logo/README.txt). Por eso el fill va literal y no por token. */
  .grano { width: clamp(5rem, 13vw, 9rem); height: auto; display: block; overflow: visible; }
  .grano rect { fill: #E8B14C; }
  .gate__lede { max-width: 34ch; }
  .kicker { font-family: var(--font-mono); font-size: var(--text-xs, .75rem);
            letter-spacing: var(--tracking-wide, .08em); text-transform: uppercase;
            color: var(--olivo-300, var(--text-muted)); margin: 0 0 var(--space-3, .75rem); }
  h1 { font-family: var(--font-heading); font-size: var(--text-4xl, 2.25rem);
       line-height: var(--leading-tight, 1.1); letter-spacing: var(--tracking-tight, -.02em);
       font-weight: var(--weight-bold, 700); color: var(--text); margin: 0 0 var(--space-3, .75rem);
       text-wrap: balance; }
  .gate__lede p { color: var(--text-secondary); margin: 0; text-wrap: pretty; }

  /* ── la mitad del acto ────────────────────────────────────────────────── */
  /* El acto se centra: pegado arriba dejaba la columna entera vacía debajo del botón. */
  .gate__act { background: var(--surface); border-left: 1px solid var(--border-subtle);
               padding: var(--space-8, 2rem); display: grid;
               grid-template-rows: 1fr auto; gap: var(--space-6, 1.5rem); min-width: 0; }
  .gate__body { align-self: center; }
  @media (max-width: 55.99rem) { .gate__act { border-left: 0; border-top: 1px solid var(--border-subtle); } }
  .gate__body { display: flex; flex-direction: column; gap: var(--space-4, 1rem); }
  .gate__foot { align-self: end; }
  .gate__foot { font-family: var(--font-mono); font-size: var(--text-2xs, .6875rem);
                color: var(--text-muted); margin: 0; display: flex; flex-wrap: wrap;
                gap: var(--space-1, .25rem) var(--space-3, .75rem); }

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
       word-break: break-all; font-family: var(--font-mono); font-size: var(--text-sm, .875rem);
       border: 1px solid var(--border); background: var(--bg); color: var(--text); }
  .ok { border-color: var(--success); } .no { border-color: var(--danger); }

  /* ── LA SIEMBRA ───────────────────────────────────────────────────────────
     Una milpa se siembra: los granos caen en el orden en que se plantan —la
     columna izquierda, la diagonal, la derecha— y se asientan. No es un
     fade-in genérico; es lo que hace la marca. */
  @keyframes sembrar {
    from { opacity: 0; transform: translateY(-.6rem) scale(.85); }
    to   { opacity: 1; transform: none; }
  }
  .grano rect { opacity: 0; transform-box: fill-box; transform-origin: center;
                animation: sembrar var(--dur-slow, 420ms) var(--ease-standard, cubic-bezier(.4,0,.2,1)) forwards;
                animation-delay: calc(var(--i) * var(--stagger-tight, 40ms)); }
  @media (prefers-reduced-motion: reduce) {
    .grano rect { animation: none; opacity: 1; transform: none; }
  }
</style>
<div class="gate">
  <aside class="gate__brand">
    <div class="gate__mark"><svg class="grano" viewBox="0 0 60 60" role="img" aria-label="Milpa"><rect x="0.0" y="0.0" width="10" height="10" rx="2.5" style="--i:0"/><rect x="0.0" y="12.5" width="10" height="10" rx="2.5" style="--i:1"/><rect x="0.0" y="25.0" width="10" height="10" rx="2.5" style="--i:2"/><rect x="0.0" y="37.5" width="10" height="10" rx="2.5" style="--i:3"/><rect x="0.0" y="50.0" width="10" height="10" rx="2.5" style="--i:4"/><rect x="12.5" y="12.5" width="10" height="10" rx="2.5" style="--i:5"/><rect x="25.0" y="25.0" width="10" height="10" rx="2.5" style="--i:6"/><rect x="37.5" y="12.5" width="10" height="10" rx="2.5" style="--i:7"/><rect x="50.0" y="0.0" width="10" height="10" rx="2.5" style="--i:8"/><rect x="50.0" y="12.5" width="10" height="10" rx="2.5" style="--i:9"/><rect x="50.0" y="25.0" width="10" height="10" rx="2.5" style="--i:10"/><rect x="50.0" y="37.5" width="10" height="10" rx="2.5" style="--i:11"/><rect x="50.0" y="50.0" width="10" height="10" rx="2.5" style="--i:12"/></svg></div>
    <div class="gate__lede">
      <p class="kicker">House identity</p>
      <h1>Sign in</h1>
      <p>The gate is configured to accept a passkey. One scope covers the whole panel.</p>
    </div>
  </aside>
  <main class="gate__act">
HTML;

        $script = <<<'HTML'
<script>
const b64uToBuf = s => Uint8Array.from(atob(s.replace(/-/g,'+').replace(/_/g,'/')), c => c.charCodeAt(0));
const bufToB64u = b => btoa(String.fromCharCode(...new Uint8Array(b))).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');

async function signin() {
  const btn = document.getElementById('go'); const out = document.getElementById('out');
  btn.disabled = true; out.className = ''; out.textContent = '';
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
            . '<div class="gate__body">' . "\n"
            . '<button id="go">Continue with a passkey</button>' . "\n"
            . '<div id="out"></div>' . "\n"
            . '</div>' . "\n"
            . '<p class="gate__foot"><span>scope: <code>' . $scope . '</code></span>'
            . '<span>relying party: <code>' . htmlspecialchars($this->rpId, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '</code></span></p>' . "\n"
            . '</main></div>' . "\n"
            . '<script>const NEXT = ' . $nextLiteral . ';</script>' . "\n"
            . $script;
    }
}
