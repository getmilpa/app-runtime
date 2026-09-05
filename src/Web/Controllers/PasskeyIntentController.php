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

use Milpa\AppRuntime\Agent\PasskeyIntentAdmission;
use Milpa\AppRuntime\Web\RegisteredCredentialIds;
use Milpa\Command\Consent\OperationId;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The browser ceremony for a PROOF-BACKED INTENT (greenhouse decisions/0187, evidence/0459/0464).
 *
 * Where {@see PasskeyController} turns a passkey into a login session, this turns one into AUTHORITY
 * for a concrete operation. It is the WEB channel's equivalent of the CLI's gpg signature ceremony
 * (greenhouse decisions/0056): the ceremony PRODUCES a proof-backed authorisation for an exact call —
 * the human, shown the operation, approves it with their passkey — and the run consumes it. The two
 * doors:
 *
 *   POST /webauthn/intent/options  {operation, arguments, session}  → a challenge bound to THAT call
 *   POST /webauthn/intent/admit    {the assertion}                  → the call is authorised, or not
 *
 * `admit` re-verifies the assertion live through {@see PasskeyIntentAdmission} — a registered
 * credential, a live signature, the challenge spent once — and mints a `ConsentGrant` for the bound
 * call. The grade is produced by that verification, never read from storage (evidence/0254): an
 * unregistered credential, a bad signature, or a replayed or unbound challenge authorise nothing.
 *
 * The options carry `allowCredentials` with every registered id, as the login options do (greenhouse
 * decisions/0206): a non-discoverable key answers only a request that names it.
 */
final class PasskeyIntentController
{
    public function __construct(
        private readonly PasskeyIntentAdmission $admission,
        private readonly RegisteredCredentialIds $registered,
        private readonly string $rpId,
    ) {
    }

    /**
     * Issue a challenge bound to a concrete call, naming every registered credential. The client shows
     * the operation and runs the ceremony.
     */
    public function intentOptions(ServerRequestInterface $request): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        if (!\is_array($body) || !\is_string($body['operation'] ?? null) || ($body['operation'] === '')) {
            return $this->json(400, ['error' => 'passkey_bad_request', 'message' => '`operation` is required.']);
        }

        $arguments = \is_array($body['arguments'] ?? null) ? $body['arguments'] : [];
        $session = \is_string($body['session'] ?? null) ? $body['session'] : null;

        $challenge = $this->admission->challengeFor(new OperationId($body['operation']), $arguments, $session);

        return $this->json(200, [
            'rpId' => $this->rpId,
            'challenge' => self::base64UrlEncode($challenge),
            'allowCredentials' => $this->registered->allowCredentials(),
            'operation' => $body['operation'],
            'arguments' => $arguments,
        ]);
    }

    /** Turn an assertion into a proof-backed authorisation for the call its challenge was bound to. */
    public function intentAdmit(ServerRequestInterface $request): ResponseInterface
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

        $grant = $this->admission->admit($this->rpId, $credentialId, $clientData, $authData, $signature);
        if ($grant === null) {
            // One message, so the door does not tell an attacker which check failed.
            return $this->json(401, ['ok' => false, 'error' => 'passkey_rejected']);
        }

        return $this->json(200, [
            'ok' => true,
            'authorized' => true,
            'operation' => $grant->operation->canonical,
            'principal' => $grant->principal,
            'evidence' => $grant->evidence(),
        ]);
    }

    /** The self-contained ceremony page: shows the operation and runs `navigator.credentials.get`. */
    public function page(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(
            200,
            ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'],
            $this->html(),
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

    private function html(): string
    {
        // A single self-contained page. It reads the operation from the query (?operation=&session=),
        // asks the server for a call-bound challenge, runs the passkey ceremony, and posts the
        // assertion back. The base64url helpers below are the standard WebAuthn marshalling.
        return <<<'HTML'
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Approve operation</title>
<style>
  body { font: 15px/1.5 system-ui, sans-serif; max-width: 34rem; margin: 4rem auto; padding: 0 1rem; color: #1a1a1a; }
  .op { background: #f3f4f6; border-radius: 8px; padding: 1rem; font-family: ui-monospace, monospace; word-break: break-all; }
  button { font: inherit; padding: .7rem 1.2rem; border: 0; border-radius: 8px; background: #111; color: #fff; cursor: pointer; }
  button:disabled { opacity: .5; cursor: default; }
  .r { margin-top: 1rem; padding: .8rem 1rem; border-radius: 8px; }
  .ok { background: #dcfce7; } .no { background: #fee2e2; }
</style>
<h1>Approve this operation</h1>
<p>Your passkey confirms this exact action. Nothing else is authorized by it.</p>
<div class="op" id="op">…</div>
<p><button id="go">Approve with passkey</button></p>
<div id="out"></div>
<script>
const q = new URLSearchParams(location.search);
const operation = q.get('operation') || '';
const session = q.get('session') || null;
let args = {};
try { args = JSON.parse(q.get('arguments') || '{}'); } catch (e) { args = {}; }
document.getElementById('op').textContent = operation + ' ' + JSON.stringify(args);

const b64uToBuf = s => Uint8Array.from(atob(s.replace(/-/g,'+').replace(/_/g,'/')), c => c.charCodeAt(0));
const bufToB64u = b => btoa(String.fromCharCode(...new Uint8Array(b))).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');

async function approve() {
  const btn = document.getElementById('go'); const out = document.getElementById('out');
  btn.disabled = true; out.textContent = '';
  try {
    // Same lesson as the sign-in page (greenhouse evidence/0519): an extension that replaced
    // navigator.credentials.get can swallow the ceremony without a dialog or an error.
    if (!/\[native code\]/.test(String(navigator.credentials.get))) {
      out.className = 'r no';
      out.textContent = 'A browser extension has replaced navigator.credentials.get on this page. If no passkey dialog opens, retry in a browser profile without that extension.';
    }
    const opt = await (await fetch('/webauthn/intent/options', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ operation, arguments: args, session })
    })).json();

    // Non-discoverable credentials (residentKey: discouraged at enrollment) are only found when the
    // request names them — that is what allowCredentials is for. User verification is required, as
    // at enrollment: the human's touch/PIN, not mere presence.
    const allow = (opt.allowCredentials || []).map(c => ({ type: c.type, id: b64uToBuf(c.id) }));
    const assertion = await navigator.credentials.get({ publicKey: {
      challenge: b64uToBuf(opt.challenge),
      rpId: opt.rpId,
      allowCredentials: allow,
      userVerification: 'required',
      timeout: 60000,
    }});

    const r = assertion.response;
    const res = await (await fetch('/webauthn/intent/admit', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({
        credentialId: bufToB64u(assertion.rawId),
        clientDataJSON: bufToB64u(r.clientDataJSON),
        authenticatorData: bufToB64u(r.authenticatorData),
        signature: bufToB64u(r.signature),
      })
    })).json();

    out.className = 'r ' + (res.authorized ? 'ok' : 'no');
    out.textContent = res.authorized ? ('Authorized: ' + res.operation) : ('Refused: ' + (res.error || 'unknown'));
  } catch (e) {
    out.className = 'r no'; out.textContent = 'Ceremony failed: ' + e.message;
  } finally { btn.disabled = false; }
}
document.getElementById('go').addEventListener('click', approve);
</script>
HTML;
    }
}
