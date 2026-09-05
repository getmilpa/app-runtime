<p align="center">
  <a href="https://github.com/getmilpa">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-dark.svg">
      <img src="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-light.svg" alt="Milpa" width="300">
    </picture>
  </a>
</p>

# milpa/app-runtime

**The agent runtime a Milpa app *installs* instead of copying.**

What an agent is allowed to do inside your app, what your app knows how to do, and the two surfaces
you drive it from — the CLI and the agent screen. All of it arrives by version.

## Why this package exists

Because it used to live inside the template, and that meant **it never reached anyone**.

`milpa/framework` is `type: project`. When you run `composer create-project`, its `src/` is *copied*
into your app and from that moment it is yours. That is exactly right for the example plugin you are
going to delete. It is exactly wrong for the agent runtime, which improves every week and which
nobody ever edits.

The symptom that exposed it, measured: an app created one day earlier **did not receive** the
permission-question buttons, or the indicator that pulses on every real event, or `agent:board` —
even after updating everything. And the worst case was the quiet one: it *did* receive the new
`milpa/live-tui`, which knows how to paint what the system said in a different colour from what the
model said, **and saw no change at all** — because its copied screen never emitted the markers that
trigger that painting. Half the improvement landed, half didn't, and nothing said so.

> The rule that came out of it, and that this package applies: **you copy what you are going to
> edit; you install what you are going to use.** A template that copies files nobody will touch is a
> package in disguise — all of a package's cost, none of its benefit.

## What's in it

**The gates — what an agent may do**

| piece | what it decides |
|---|---|
| `SessionToolGate` | whether a call proceeds: permission, intent contract, sterile loop, ordering |
| `SubAgentSpawner` | delegating to a child session and resuming it — with fresh context, not re-delegating |
| `TreeBudget` | how many steps the *tree* spends, not each child: bounding the child does not bound the tree |
| `SterileLoopGuard` | not repeating a call that already failed the same way twice — **on by default**: at its home tolerance it would have refused 81 of a sick run's 89 calls and none of the healthy runs'. Opt out with `agent.sterileLoopGuard: false`; an integer sets the tolerance |
| `PrerequisiteGate` | an ordering obligation, executed: until the required thing runs, the rest does not. The system **renews** a session's standing obligation with a cheap read of its own state (`agent_show`) — orientation, not curation: a turn opened by bookkeeping becomes a bookkeeping turn (measured, twice). `agent.renewalTool` names another tool; `false` disables renewal, declared — never silent |
| `SessionOptionTable` | withdrawing a tool from a session's catalogue — forbidding, not asking |
| `BroadcastingEventStore` · `SurfaceBroadcaster` · `MercureBroadcaster` | getting what happens to the live surfaces while it happens |
| `SessionBookkeeping` · `SessionPlanBoard` | the session's plan and to-dos, bound to *its* id |

**The operations — what your app knows how to do**

`AgentOperations`, `SessionOperations`, `CapabilityOperations` and `TokenOperations` are the operation
groups a Milpa app registers. They are *returned*, never self-registered: whoever assembles the
registry decides which groups get in and with what authority, and a group that registered itself
would take that decision away.

**Containing what an agent may reach**

An agent runs contained from the CLI, not only when a parent delegates to it. The withdrawal is a
fact of the session, recorded in its stream — not a sentence in the prompt asking nicely:

```bash
# by name, when you know exactly which tools to take away
php coa agent "review this app and report" --session=review --deny=plugins:enable,make

# by effect class, which covers what a list of names forgets
php coa agent "review this app and report" --session=review --denyEffects=mutating
```

Classes are `mutating`, `external`, `irreversible` and `authority`, resolved against the **live**
catalogue — an operation added tomorrow is covered the day it exists. An operation that never declared
its effects is **denied**, not waved through: unknown ranks above known-bad, so a catalogue nobody
classified withdraws entirely, and when that happens the command refuses and says so rather than
handing back a mute agent.

`--deny` needs `--session`: the option table lives in the session, and a prohibition that cannot be
recorded would not survive the first step.

Why a class and not a list: a measurement (`settlement-q-p20p.md`) put an agent under a task it could
not finish without mutating, took five tools away by name, and watched it reach for a sixth that
mutates — three times out of three. The list is worth exactly what whoever wrote it remembered.

**The surfaces — where you drive it from**

`Console\Application` is the single door of the CLI: `coa` on its own, a named command, the TUI, a
one-shot chat. `Tui\AgentScreen` renders the agent screen as text — the actor markers travel *inside*
the text, so a painter can colour by origin and the same screen still works where there is no colour.

`Web\BoardPage` renders the session's work as a **live Kanban board** in a browser: four columns,
and exactly **one write** — answering the question that paused the session, through two buttons
born disabled. They arm only when a token with the `agent:answer` scope is pasted; the token
travels in the `Authorization` header — never in a URL, never in browser storage — and the server
refuses any caller without a verified actor, showing the refusal verbatim. The page never folds
the stream client-side — the fold is `agent:board`, shared with the CLI — and when the live bridge
pushes a fact the page repaints the activity line and fetches the fold again, so reconnecting *is*
catching up. A card born already done is set apart, never animated as if it had crossed; a card
held by an open question sits in `blocked` saying why. Serve `agent:board` and `agent:answer` over
HTTP (`config/http.php`), point the page at your Mercure hub, and with no hub it says so instead
of pretending to be live.

**Steering a session from any of them — `agent:goal`, `agent:mode`, `skill:invoke`**

A session carries a **standing goal** — the human's intent, seeded from the first prompt and
changeable mid-session: `agent:goal` sets it, clears it, or reads it, over `cli`, `tui`, `mcp` and
`http`. The gate judges targets against it, and in `auto` mode it bounds what runs without asking;
the system prompt of every run speaks for the goal and the mode as they stand when that run starts.
`agent:mode` reaches the session over `http` too, so a Desktop's mode chip changes the real session,
not a label. A human runs a **user-invocable** skill with `skill:invoke`, which returns the skill's
body to put in front of the agent — including a skill marked `disable-model-invocation`, which the
model's own door, `skill:load`, refuses. All three are deliberately **off the model's tool table**
(`AgentTable`): a session must not widen its own standing ask, raise its own autonomy, or hand itself
a skill the human kept. And none of them pre-consents anything: a call that requires a signature, or
reaches a third party, still stops in every mode, whatever the goal names.

**Growing the app — `capabilities`, `capabilities:refresh`, `capabilities:enable`**

The capability→package index is **derived from what the registry publishes**, never written by
hand: every announcing package declares `"type": "milpa-capability"` on Packagist with its full
contract (`extra.milpa.capability`), and `capabilities:refresh` turns that into a dated artifact
under `var/`. Three authorities answer «what exists» and the rank is executed, not implied:
`installed.json` (what IS) over the derived index (what EXISTS, dated) over a small offline floor —
and every answer names which one it used. After `capabilities:enable` installs, what the registry
**promised** is compared with what **arrived**, and any difference is recorded: a package's
declaration about itself is a claim, not a classification.

Most of these exist because a measurement said they were needed, not because they seemed like a good
idea. The settlements live in the monorepo (`docs/library/settlement-q-*.md`) and the docblocks cite
which one.

## Install

```bash
composer require milpa/app-runtime
```

A host composes it: this package boots nothing on its own and knows nothing about your app. It
receives the session store, the operation catalogue and the model credential from whoever builds it —
which is whoever holds the kernel.

Optional packages widen what it offers, and their absence is handled rather than assumed:
`milpa/auth` for token verification, `milpa/data` for persisting them, `milpa/devtools` for `coa
doctor`, `coa repair` and `coa update`. Without them those surfaces are simply not offered — the app
never promises what it cannot do.

## Passkey gate

**One session, one scope, one middleware the panel names.** `PasskeyPlugin` owns the whole passkey
ceremony — registration, sign-in, the session it mints — and registers `PasskeyGateMiddleware` in the
container under its own class name. A panel (`milpa/admin`, or any route of yours) puts identity in
front of itself by *naming* that class in its middleware list; it learns nothing about `milpa/auth`.
Identity lives where the ceremony lives (greenhouse decisions/0206).

The gate reads the session cookie the sign-in ceremony set and looks it up in the session store — the
cookie value is never trusted on its own. Then:

| the request carries | a browser (`GET` accepting `text/html`) gets | anything else gets |
|---|---|---|
| no live session (no cookie, unknown, expired, revoked) | `302` to `/webauthn/signin?next=<where it was going>` | `401 {ok:false, error:"unauthenticated", signin:"/webauthn/signin"}` |
| a session **without** the scope | `403`, a page: *Authenticated, but the scope `milpa.admin` is not granted*, naming the principal, with a *Use another passkey* link | `403 {ok:false, error:"scope_denied", scope}` |
| a session **with** the scope | the route, with the `AuthContext` attached under `milpa.auth` (`AuthenticateMiddleware::ATTRIBUTE`) — `signed in as passkey:<credential id>` | the same |

`next` is validated server side as a **local absolute path**: `//evil`, `https://x` and `\x` all fall
back to `/`. The sign-in page never redirects to a URL somebody else chose.

**The operator sequence** — from a fresh app to a panel that opens only for your key (run once with a
physical YubiKey, greenhouse evidence/0519):

0. **Install what the door is made of.** A fresh `composer create-project milpa/framework` app does
   **not** ship `milpa/auth` — the ceremony, the session store and the gate middleware live there:
   ```bash
   composer require milpa/auth
   ```
   Since 0.118 a `PasskeyPlugin` declared without it refuses to boot and names this command; before, it
   mounted nothing and a panel naming the gate answered a mute `500`. The `identity:*` operations you
   need below are offered by the runtime on their own; `milpa/agent` + `milpa/ai-gateway` are only for
   the agent operations.
1. **Declare the plugin and the relying party.** In `config/plugins.php` list
   `Milpa\AppRuntime\Web\PasskeyPlugin::class`; in `config/app.php` declare
   `'passkey' => ['rpId' => 'localhost']`. The `rpId` must be the host the browser is on, and WebAuthn
   needs a secure context (`https://`, or `localhost`). Without `rpId` the plugin mounts nothing — a
   relying party nobody chose is one nobody can trust. With it, and no session store registered by the
   host, the plugin provides one (`var/passkey/sessions.json`).
2. **Register the key.** Open `GET /webauthn/enroll`, press *Register with passkey*, touch the key. The
   page prints the **credential id** (base64url). The credential is now *registered* — the house holds
   its public key — but *recognized* by nobody: registering grants nothing.
3. **Root the credential id out of band.** `config/identity.php`:
   ```php
   <?php return ['rooted' => ['<credential id>']];
   ```
   The root is read, never written, by the running app: the only way in is this file.
4. **Enroll it with the panel's scope** — a governed, signed operation:
   ```bash
   php coa identity:enroll --fingerprint=<credential id> --scopes=milpa.admin --sign
   ```
   How this is authorised today: `identity:enroll` is declared `requiresConfirmation: true`, so the CLI
   refuses it without `--sign`. `--sign` signs *this exact call* — operation, arguments, host — with your
   gpg key (the YubiKey through gpg-agent); the runner verifies the signature and hands the handler a
   `GrantedAuthorization`. The handler then checks that the grant covers `identity:enroll` for **this**
   fingerprint, that the id is in `config/identity.php`'s `rooted`, and only then writes the recognition
   to `storage/identity/enrollments.json` with `authorized_by: key:<your fingerprint>`. `--scopes` is an
   array argument — repeat the flag for more than one (`--scopes=milpa.admin --scopes=agent:read`).
   Over `http`/`mcp` the operation additionally requires a caller holding the `identity:enroll` scope.
   On the CLI the signature alone is the authority. To make your gpg key the house's *recognized* root
   as well — so the ledger names it (`authorized_by: bootstrap`) and the same enrollment can run over
   `http`/`mcp` — bootstrap once, on an empty house, before rooting the credential:
   ```php
   <?php return ['bootstrap' => true, 'rooted' => []];   // config/identity.php, first run only
   ```
   ```bash
   php coa identity:bootstrap --scopes=identity:enroll --sign   # one touch: your key becomes the root
   ```
   `identity:bootstrap` refuses once `rooted` is non-empty or anything was ever recognized — it is a
   one-time act. Then write the credential id into `rooted` and enroll as above. Revoking
   (`php coa identity:revoke --fingerprint=<credential id> --sign`) lays `revoked_by` over the entry
   and the sign-in list stops offering the key; enrolling the same id again re-admits it and keeps the
   revocation in the entry's `history` — the ledger records facts, it erases none (greenhouse
   decisions/0207).
5. **Name the gate.** Where the panel's middleware is declared (`admin.middleware` for `milpa/admin`,
   the `middleware` of any `Route` of yours):
   ```php
   'admin' => ['middleware' => [Milpa\AppRuntime\Web\PasskeyGateMiddleware::class]],
   ```
6. **Sign in.** `GET /milpa/admin` → `302` to `/webauthn/signin?next=/milpa/admin` → *Continue with a
   passkey* → touch → the cookie is set and the browser returns to the panel, `200`.

If *Continue with a passkey* does nothing — no dialog, no error, the button stays disabled — a browser
extension has most likely replaced `navigator.credentials.get` (password managers that offer their own
passkeys do; the console shows the extension's content script). The pages now say so before waiting on
the call; retry in a browser profile without that extension (greenhouse evidence/0519).

Why the sign-in page works with a hardware key: enrollment registers a **non-discoverable** credential
(`residentKey: discouraged`, so a key with scarce slots is not consumed), and a browser only finds one of
those when the request names it. The authentication and intent options therefore return
`allowCredentials` with **every credential id that is registered AND enrolled** — `POST /webauthn/register`
stays open and registering grants nothing, so a key nobody enrolled is never offered; an id is not a
secret, the private key is. The intent page (the D-01 approve ceremony) now requests
`userVerification: 'required'`, the same bar the enrollment ceremony sets (greenhouse evidence/0486).

**Config keys** (`config/app.php`, under `passkey`):

| key | default | what it decides |
|---|---|---|
| `passkey.rpId` | *none — required* | the relying-party id every assertion binds to; without it, no routes |
| `passkey.cookie` | `milpa_session` | the cookie the session id travels in (HttpOnly, SameSite=Strict) |
| `passkey.ttl` | `3600` | session lifetime in seconds, from the moment the ceremony mints it |
| `passkey.sessions` | `<root>/var/passkey/sessions.json` | where the provided `FileSessionStore` writes — ignored when the host registered its own `SessionStore` |
| `passkey.gate.scope` | `milpa.admin` | the **one** scope `PasskeyGateMiddleware` requires (the `*` wildcard an `identity:bootstrap` root holds also opens it) |

Not covered by this gate, said plainly: operations over HTTP (`POST /agent`, `agent:goal`, …) are still
governed by the Bearer policy — the passkey cookie does not reach them yet. `POST /webauthn/register`
stays open: registering grants nothing, enrolling is the act, and the root gate is the file only you
write.

## Upgrading

### 0.119.0 — the identity ledger keeps history

`storage/identity/enrollments.json` is a ledger of **facts**, not of state (greenhouse decisions/0207).
Enrolling a key that already has an entry — revoked or live — no longer overwrites it: the state it
replaces is pushed onto the entry's `history` list (most recent last) and the new `{scopes, authorized_by}`
becomes the live state, so a revocation is never erased by the recognition that follows it. Re-enrolling a
revoked key is allowed, under the same signed, rooted authority as enrolling. `identity:enroll` now says
what it did: `history_entries` (prior states kept for the key; `0` on a first enrollment) and, when the
standing entry was revoked, `previously_revoked_by`. Reads are tolerant: a ledger written before reads
identically, `scopesFor` (live state; revoked → `null`) and `isEmpty` (sealed by any entry) keep their
contracts, and `history` appears the first time a key is re-written. No migration. And a write the store
cannot make — the file cannot be opened, the disk refused the bytes, or the ledger holds content the store
cannot read — is refused rather than reported on: `identity:enroll`, `identity:revoke` and
`identity:bootstrap` answer `ok: false` naming the cause, nothing is written over unreadable content, and
such content is not a greenfield for `identity:bootstrap`.

### 0.118.0 — `PasskeyPlugin` refuses to boot without `milpa/auth`

A `PasskeyPlugin` listed in `config/plugins.php` while `milpa/auth` is not installed used to boot
quietly and mount nothing; a panel naming `PasskeyGateMiddleware` then answered a `500` that blamed
nothing (greenhouse evidence/0519). Now `boot()` throws a `RuntimeException` that names the fix:

```bash
composer require milpa/auth      # or remove the plugin from config/plugins.php
```

No behaviour changes for a house that has the package. The sign-in, enrollment and intent pages also
say when a browser extension replaced the WebAuthn API on the page, before waiting on it.

### 0.45.0 — `capabilities:enable --dry-run` requires a signature

`--dry-run` used to run without consent. It no longer does: the rehearsal now carries the full
operation's ceiling, so it asks like any other governed effect.

```bash
# before
coa capabilities:enable milpa/devtools --dry-run

# now
coa capabilities:enable milpa/devtools --dry-run --sign
```

The exemption came from a **descent** — a declaration that the rehearsal reaches no further than the
disk — and it was switched off because nothing could check it. The claim rests on the network, and
the network here is observed by difference, which cannot tell *does not reach out* from *reaches out
and swallows the error*. A ceiling lowered by a promise nobody can verify is worse than the nuisance
of asking. The descent returns when it can be certified.

`--dry-run` still does exactly what it did; only the exemption is gone.

## License

Apache-2.0 · © Rodrigo Vicente — TeamX Agency

---

Milpa is designed, built, and maintained by **[Rodrigo Vicente - TeamX Agency](https://teamx.agency/?utm_source=github&utm_medium=readme&utm_campaign=milpa&utm_content=app-runtime)**.

