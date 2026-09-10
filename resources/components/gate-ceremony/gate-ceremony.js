/*
 * THE CEREMONY'S OWN MODULE — ONE FILE, BOTH ACTS.
 *
 * This is where greenhouse decisions/0261 is actually fixed, not patched. That defect was not a typo:
 * the whole page was a PHP heredoc, so a lone `\n` written for a JavaScript string was eaten by PHP
 * and emitted as a REAL newline, breaking the literal and throwing a SyntaxError — the enroll button
 * had no listener from the day it was written. The comment that first explained it reproduced it. The
 * test written to catch it checked only the FIRST inline script and so never read the sign-in ceremony
 * at all (decisions/0263).
 *
 * A real `.js` file has no interpolating host. `\n` below is `\n`. The class of defect is gone rather
 * than avoided, and both ceremonies stopped carrying their own copy of `mark()`,
 * `houseIsReachable()` and the base64url helpers.
 *
 * WHAT THE SERVER CHOOSES TRAVELS AS JSON, NEVER AS JAVASCRIPT. The relying party, the scope, the
 * return path and the authenticator preference arrive in a `type="application/json"` tag, encoded so
 * a value cannot close the tag. The old shape interpolated them into source — `next` as a JS string
 * literal, and `authenticatorAttachment` as a RAW FRAGMENT spliced into an object literal, which is
 * only safe as long as something upstream validates it. Data cannot be code here.
 *
 * Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency
 */

(function () {
  'use strict';

  const CONFIG_ID = 'milpa-gate-ceremony';

  /**
   * What this house admits, built from what it DECLARED.
   *
   * OMITTING THE KEY IS NOT THE SAME AS SENDING `null`: a browser reads `authenticatorAttachment:
   * null` as a constraint no authenticator satisfies, so a house that declared nothing must say
   * nothing (greenhouse decisions/0244). The server used to spell this object out as source and the
   * suite grepped the string it emitted; the object is built here now, so the property is asserted
   * by RUNNING this function rather than by reading a template (decisions/0263).
   */
  function selectionFor(attachment) {
    const selection = { userVerification: 'required', residentKey: 'discouraged' };
    if (typeof attachment === 'string' && attachment !== '') {
      selection.authenticatorAttachment = attachment;
    }

    return selection;
  }

  // The one seam the suite drives, named like every other surface of the house exposes its own
  // (`MilpaLive.desktop`). Set BEFORE the config check below, so a page with no ceremony on it —
  // and a test harness with no DOM at all — can still ask this house what it admits.
  globalThis.MilpaGateCeremony = { selectionFor };

  /** What the server chose for this page. A page without the tag is a page that cannot run a ceremony. */
  function config() {
    const tag = document.getElementById(CONFIG_ID);
    if (!tag) {
      return null;
    }
    try {
      const read = JSON.parse(tag.textContent || '{}');
      return read && typeof read === 'object' ? read : null;
    } catch (e) {
      return null;
    }
  }

  const cfg = config();
  if (cfg === null || (cfg.kind !== 'enroll' && cfg.kind !== 'signin')) {
    return;
  }

  const RP_ID = typeof cfg.rpId === 'string' ? cfg.rpId : '';
  const SCOPE = typeof cfg.scope === 'string' ? cfg.scope : '';
  const NEXT = typeof cfg.next === 'string' ? cfg.next : '/';

  const out = () => document.getElementById('out');
  const btn = () => document.getElementById('go');

  // The mark reports where the ceremony is: sown on arrival, growing while it waits — the touch,
  // the verification, whatever comes next loading — and opening once when it lands. One state,
  // not three animations stuck together (greenhouse decisions/0243).
  const markEl = document.querySelector('[data-milpa-component="brand-mark"]');
  const mark = (state) => markEl && markEl.setAttribute('data-state', state);

  const say = (className, text) => {
    const region = out();
    if (region) {
      region.className = className;
      region.textContent = text;
    }
  };

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
    const host = location.hostname;
    const suffix = host === RP_ID || host.endsWith('.' + RP_ID);
    if (!window.isSecureContext) {
      say('r no', 'A passkey needs a secure page. Open this over https, or on localhost. This page is ' + location.origin + '.');
      if (btn()) {
        btn().disabled = true;
      }
      return false;
    }
    if (!suffix) {
      say('r no', 'This house answers to “' + RP_ID + '” and you opened it as “' + host + '”, so no key can be registered here — a passkey is bound to the name in the address bar. Open ' + location.protocol + '//' + RP_ID + (location.port ? ':' + location.port : '') + location.pathname + ' instead, or declare passkey.rpId to match the name you use.');
      if (btn()) {
        btn().disabled = true;
      }
      return false;
    }
    return true;
  }

  const b64uToBuf = (s) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/')), (c) => c.charCodeAt(0));
  const bufToB64u = (b) => btoa(String.fromCharCode(...new Uint8Array(b))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

  /** An extension that replaced the call can swallow the ceremony with no dialog and no error (greenhouse evidence/0519). */
  function warnIfReplaced(call, name) {
    if (!/\[native code\]/.test(String(call))) {
      say('r no', 'A browser extension has replaced navigator.credentials.' + name + ' on this page. If no passkey dialog opens, retry in a browser profile without that extension.');
    }
  }

  async function register() {
    if (!houseIsReachable()) {
      return;
    }
    btn().disabled = true;
    say('', '');
    mark('growing');
    try {
      warnIfReplaced(navigator.credentials.create, 'create');
      const opt = await (await fetch('/webauthn/register/options', { method: 'POST' })).json();

      // WHAT THIS HOUSE ACCEPTS, declared and not assumed (greenhouse decisions/0244).
      //
      // `userVerification: 'required'` is the point: the human's touch or PIN, never mere presence —
      // a platform authenticator and a password manager both satisfy it. A hardcoded
      // cross-platform attachment used to EXCLUDE the built-in authenticator and every password
      // manager, i.e. the two places a passkey lives on a machine somebody already owns. A house
      // that wants hardware only declares `passkey.authenticator`, and it arrives as DATA below.
      //
      // residentKey is discouraged so a hardware key with scarce slots enrolls as a non-discoverable
      // credential (the server holds the credential id for the approve ceremony), and it works the
      // same for the other two. ES256 (alg -7) is what a FIDO2 key produces, so this stays within
      // the one algorithm milpa/auth verifies.
      const cred = await navigator.credentials.create({ publicKey: {
        rp: { id: opt.rpId, name: 'Milpa' },
        user: { id: crypto.getRandomValues(new Uint8Array(16)), name: 'operator', displayName: 'Operator' },
        challenge: b64uToBuf(opt.challenge),
        pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
        authenticatorSelection: selectionFor(cfg.attachment),
        timeout: 60000,
      }});

      const res = await (await fetch('/webauthn/register', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          clientDataJSON: bufToB64u(cred.response.clientDataJSON),
          attestationObject: bufToB64u(cred.response.attestationObject),
        }),
      })).json();

      // SUCCESS SAYS WHAT IS STILL MISSING. Registering is not permission: the server had been
      // saying so in `note` all along and this page threw it away, so somebody read "Registered
      // credential: T05…" and concluded, reasonably, that they were in. They were not
      // (greenhouse decisions/0244).
      //
      // AND IT NAMES THE STEP THAT WAS MISSING FROM THE INSTRUCTION. `identity:enroll` checks the
      // ENROLLED fingerprint against the out-of-band root, so on a house that declared no root the
      // command it used to print here could only ever fail with `IdentityNotRooted` — measured
      // (greenhouse decisions/0263). Naming the declaration first is what makes step 2 finish the
      // sentence step 1 started (decisions/0260), one step further out.
      say(
        'r ' + (res.ok ? 'ok' : 'no'),
        res.ok
          ? 'Registered. This house now holds the public key of credential ' + res.credentialId
            + '. It grants nothing yet.\n\n'
            + '1 · Declare this credential in config/identity.php so the house is willing to recognise it:\n\n'
            + "    return ['rooted' => ['" + res.credentialId + "']];\n\n"
            + '2 · Say what it may do, authorised by a principal this house already recognises:\n\n'
            + '    php bin/coa identity:enroll --fingerprint=' + res.credentialId + ' --scopes=' + SCOPE + ' --sign\n\n'
            + 'Then sign in at /webauthn/signin.'
          : 'Refused: the challenge was already spent, or the attestation did not verify. Nothing was stored — press again for a fresh challenge.',
      );
      mark(res.ok ? 'ready' : 'sown');
    } catch (e) {
      say('r no', 'The key did not answer: ' + e.message + '. Nothing was stored.');
      mark('sown');
    } finally {
      btn().disabled = false;
    }
  }

  async function signin() {
    if (!houseIsReachable()) {
      return;
    }
    btn().disabled = true;
    say('', '');
    mark('growing');
    try {
      warnIfReplaced(navigator.credentials.get, 'get');
      const opt = await (await fetch('/webauthn/authenticate/options', { method: 'POST' })).json();
      const allow = (opt.allowCredentials || []).map((c) => ({ type: c.type, id: b64uToBuf(c.id) }));
      if (allow.length === 0) {
        // Registered ∩ enrolled is empty. Registered is not enrolled, and revoked is not enrolled
        // either (greenhouse evidence/0519): say which act is missing, not "no passkey".
        say('r no', 'Not enrolled: no passkey is recognised by this house. A registered key nobody enrolled, or one that was revoked, is not offered. Register one at /webauthn/enroll if you have none, then enroll its credential id with identity:enroll.');
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
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          credentialId: bufToB64u(assertion.rawId),
          clientDataJSON: bufToB64u(r.clientDataJSON),
          authenticatorData: bufToB64u(r.authenticatorData),
          signature: bufToB64u(r.signature),
        }),
      });
      const body = await res.json().catch(() => ({}));
      if (res.ok && body.ok) {
        // Names WHERE it goes, not what it assumes is there: this gate guards whatever the app put
        // behind it, and a house with nothing installed still has to be able to get in.
        say('r ok', 'Signed in as ' + body.actor + '. Returning you to ' + NEXT);
        // The mark opens BEFORE the jump: without that pulse the page change reads as a cut and
        // nobody sees their key worked. The wait is the animation's, not an invented number — and
        // whoever asked for less motion does not sit through it.
        mark('ready');
        setTimeout(() => location.replace(NEXT), matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 520);
        return;
      }
      say('r no', 'Passkey rejected: the credential is not registered, not enrolled, or the assertion did not verify.');
    } catch (e) {
      say('r no', 'Sign-in failed: ' + e.message);
      mark('sown');
    } finally {
      btn().disabled = false;
    }
  }

  const act = cfg.kind === 'enroll' ? register : signin;

  document.addEventListener('DOMContentLoaded', houseIsReachable);
  if (btn()) {
    btn().addEventListener('click', act);
  } else {
    document.addEventListener('DOMContentLoaded', () => btn() && btn().addEventListener('click', act));
  }
})();
