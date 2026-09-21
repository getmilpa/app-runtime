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

## Declared screen pages

With `LivePlugin` enabled and `live.secret` configured, `GET /live/page?component=<name>` returns a
complete HTML document. It loads the local runtime, remote runtime and Alpine once, plus the shipped
Milpa design styles, local fonts, and every rendered descendant's declared styles, scripts and messages.
The document works on its own or inside the panel's preview iframe. `live.route` changes the page,
endpoint and design-asset mount; runtime URLs retain their existing root mounts.

`screen:declare` validates the entire `props.children` tree before writing. An unknown or malformed
child returns `ok: false` with its path and preserves the previous screen; no served-evidence receipt
is issued. Invalid trees already in the store return HTTP 422 instead of rendering a partial screen.
`screen:types` reads the current component and renderer registries. It reports canonical contract
names with an HTML renderer and explains unavailable registrations. The declaration's `type` field
references that operation with `x-milpa-source`; validation reads the registry when called, including
plugins that boot after `LivePlugin`.

A plugin can extend the live door with an object that already has its collaborators:

```php
// Run after LivePlugin boots. These are the same registries used by GET and action POST.
$container->get(\Milpa\Live\Contracts\Component\ComponentRegistryInterface::class)
    ->register('task-item', new TaskItem($repository));
$container->get(\Milpa\Live\Rendering\ComponentRendererRegistry::class)
    ->registerFor('task-item', new TaskItemRenderer($container->get(
        \Milpa\Live\Contracts\Transport\StateTransferCodecInterface::class,
    )));
```

The registration name must match `TaskItem::contract()->name`. Its renderer supplies the component
HTML and signed state envelope. With live-web 0.29+, `x-data="milpaComponent({componentId: 'task'})"`
and `@click="act('toggle', {})"` use the existing signed transport and HTML reconciliation. No app
transport module is required. A declared screen can use this type at its root or inside a supported
container's `props.children`. Renderer `DeclaresClientAssets` and component presentation resources
are collected from descendants. Merely advertising a class through `DeclaresComponents` does not
construct it or register a renderer. An opaque registry must implement `ListsComponents` to offer
types through discovery. Existing configured components and built-in types remain supported.

This page shows the current declaration. It is not an isolated draft or a deployment boundary, and
rendering a group of controls does not yet provide shared application state between them.

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

The resident resolves an optional `TrialInputObserver` registered in the app's DI container:

```php
$container->registerService(TrialInputObserver::class, $hostObserver);
```

Register it during trusted host composition, before invoking the agent. `AgentOperations` passes
that same object to its shared trial runner; the existing trial event reports whether an attempt
has a known, partial or unknown witness. A wrong service type or a resolution error is a visible
configuration failure, never silently replaced by an unobserved runner. Disabled trials do not
resolve the observer. A hook or capture failure preserves the trial verdict and records unknown.
No observer binding keeps the previous behavior. The host still supplies and operates its capture
mechanism; registering a hook does not install a tracer or attest complete inputs. Measured in
Greenhouse decisions/0351 and evidence/0668.

Hosts that compose a `TrialRunner` directly can also pass `inputObserver`.
Its `before(TrialInputAttempt)` and `after(TrialInputAttempt, int $exit)` hooks surround the native
test execution. The observer runs outside the tested process; tool output is never this channel.
The returned record binds `id`, `copy`, `operation`, and `arguments` to that attempt and declares
`scope: copied-app-file-content-presence-and-directory-members/v1`, `complete_execution_inputs:
false`, `status: known|partial|unknown`, and `inputs`. Each relative input carries `facets`
(`content`, `presence`, or `members`) and its `before` state (`kind`, plus `sha256` for file content
or `members` for enumerated directories). The observer must report writes during execution,
incomplete resolution, and missing captures conservatively; a post-execution hash alone cannot
attest an input. The runner rejects records belonging to another attempt.

When the gate and executor share their `TrialRouter` and session, the runner's witness reaches
`SterileLoopGuard` through a one-use host channel. Known changed inputs permit new work while
unrelated edits retain the old failures. Returning to old inputs restores their failure history;
success clears only the exact known input identity. Missing or partial observations cannot prove
a repair, and an unreadable current input retains its failures. The native trial event records
the attempt, scope, status and identity. Without an observer, the existing argument-based behavior
is unchanged. No tracer, platform dependency, or observer is enabled by default. This bounded
file scope excludes vendor, var, cache, `.env`, the trial runner, external paths, environment,
clock, randomness and services; it does not claim complete or semantic dependencies. Measured in
Greenhouse decisions/0350 and evidence/0667.

During progress recovery, `ConsentBridge` removes declared reads from the offered catalogue using
the session gate's current state. Successful material work, recorded evidence or a completed todo
restores them. A new validated diagnostic can also restore reads; an unobserved failure or pending confirmation cannot. Durable option removals remain in
force. Full and lazy discovery use this current offer, including previously discovered schemas.
Offering a mutation does not authorize it: scopes and argument-dependent effects are still judged
when it is called. Catalogue inspection does not execute that judgment or open consent questions.

`SessionProgressProbe` opens recovery after four model calls without recorded growth. Successful
`source_read`, `source_page`, and `skill_load` results may defer that first stall when the latest
round returned previously unseen, nonempty content. This initial exploration allowance ends at
the twelfth model call of the session, including calls before a continuation. Repeated content,
changed paths or cursors, failures and confirmation requests do not extend it. Exploration is
recorded separately as `session.exploration_observed`; it never counts as material progress or
clears an existing recovery. This bounded exception follows Greenhouse 0439/0826.

Once recovery opens, the probe allows
one further window of the same size for preparation, then reports exhaustion if growth is still
absent. A successful artifact-producing operation, recorded evidence or a completed todo resets
the window, including on its last call. New validated diagnostics are tracked separately from positive
evidence. Plan edits, repeated todos, repeated diagnostics and confirmation requests do not reset it. Observations explicitly distinguish pending, recovered and exhausted recovery;
an unavailable store cannot claim any of them. The orchestrator enforces this contract without
changing tool permissions or the total step budget. Windows belong to the current invocation.

Syntax receipts require `milpa/devtools` 0.33 or later; DevTools remains optional.
Older producers retain their existing rejection behavior without syntax diagnostic credit.

A rejected `implement` can report a new diagnostic when `milpa/devtools` supplies a
`milpa.authoring-diagnostic/v1` receipt. All phases bind the admitted call, submitted and
normalized body and observed copied files. Behavior and static analysis require completed rollback. The `behavior` phase additionally
requires the class's unique test selector. Runtime errors describe that scoped execution; they
do not establish that the proposal caused the error. The `static-analysis` phase requires a
complete attributed PHPStan rule report, exit 1, consistent counts and a recomputed finding
fingerprint. It needs no behavioral test. The `syntax` phase instead requires a stable proposal,
an unchanged destination with its prior hash and an explicit claim that the candidate was never
installed. The consumer independently reproduces the native parser finding from the admitted
body; invented rollback fields are rejected. Size refusals, unstructured syntax errors, incomplete or foreign reports,
infrastructure failures, timeouts, unobserved mutations and invalid receipts earn no diagnostic
identity. The existing explicit `test` diagnostic contract is unchanged.

Behavioral novelty uses the judged body, selector and observed copied tree. Static novelty uses
the subject, canonical message/identifier set and observed copied tree: a changed body hash
still needs attribution but cannot renew unchanged findings. Lines, finding order and duplicate
occurrences do not renew static information. Syntax novelty uses the subject, parser/message
fingerprint and observed tree, ignoring body hash and location changes that preserve the finding.
All phases deduplicate across workspaces and
inline/finish transport. These identities do not establish semantic equivalence. The copied file scope excludes vendor,
var and other trial machinery; it is not a complete dependency trace. A failed authoring result
retains its structured receipt in the `milpa.trial-authoring-failure/v1` error envelope so it can
reach the next model request. It remains failed and unapplied, with no promotion instruction.
Older devtools producers without this receipt continue to earn no authoring diagnostic credit.
Static receipts require `milpa/devtools` 0.32 or later; older producers retain their existing
behavioral receipt support. Devtools remains an optional runtime capability.

**The operations — what your app knows how to do**

`AgentOperations`, `SessionOperations`, `CapabilityOperations` and `TokenOperations` are the operation
groups a Milpa app registers. They are *returned*, never self-registered: whoever assembles the
registry decides which groups get in and with what authority, and a group that registered itself
would take that decision away.

At a natural end, `closure.verified` covers `scope: recorded_work`: it requires positive
recorded evidence, no open or unevidenced done items, and current verification for artifacts
with mutation attempts. An empty ledger, a scaffold without verification, or a later write
that invalidated a passing check cannot verify closure. Read-only discovery does not require
artifact verification. This verdict does not certify that the ledger covers every requirement
of the human's goal; callers still need task-specific acceptance criteria.

A caller can bind a known candidate to one immutable delivery when continuing a session:

```php
$input = [
    'prompt' => 'Finish the focus screen',
    'session' => $sessionId,
    'delivery' => json_encode([
        'workspace' => $candidateWorkspace,
        'artifactPath' => 'src/Plugins/Owned/Services/FocusCounterView.php',
        'test' => ['path' => 'tests/Plugins/Owned', 'filter' => ''],
        'screen' => ['name' => 'focus', 'type' => 'focus-counter'],
    ], JSON_THROW_ON_ERROR),
];
```

CLI and HTTP accept `delivery` as a JSON string (`--delivery` on the CLI).
The `DeliveryScope::parse()` SDK also accepts a PHP array for direct use. The invocation records
`session.delivery_declared` with its observed caller provenance. Omitting `delivery` on later
turns retains it; an identical declaration is idempotent, and a different or malformed one is
refused before the turn runs. Use a new session for a different delivery. This input belongs to
the caller of `agent`, which is outside the resident's tool catalogue.

At each proven current `final_answer` with no pending question, the runtime reads `AcceptanceEvidence` again from the native stream,
current files and configured draft store. The result covers
`scope: declared_delivery_and_recorded_work`: current positive evidence can satisfy only the
identified producer's artifact, while open todos, unevidenced dones, explicit red judges and
other artifacts still count. A write after the delivery's test receipt blocks its closure,
even if a separate ledger verifier subsequently says green. No test is run during closure.

The returned closure and its `session.closure_derived` event contain the same sampled
`observation` and delivery declaration reference. These are historical observations, not locks,
approval, permissions or browser verification; a later turn must observe again. Sessions without
a declaration retain the original recorded-work verdict. All other termination causes, including `unknown`, produce no closure.
Evidence: greenhouse decisions/0390 and evidence/0708.

A caller can instead declare a finite read-only diagnostic before executing a new session:

```php
$input = [
    'prompt' => 'Compare the required and configured engine in the pinned document.',
    'session' => $newSessionId,
    'diagnostic' => json_encode([
        'path' => 'tests/engine.json',
        'sha256' => $documentSha256,
        'fields' => ['required' => 'required_engine', 'configured' => 'configured_engine'],
        'equals' => ['matches' => ['required_engine', 'configured_engine']],
    ], JSON_THROW_ON_ERROR),
];
```

The CLI accepts the same JSON through `--diagnostic`. `DiagnosticContract::parse()` also accepts
an array. `fields` maps output names to top-level scalar document keys; `equals` compares two such
keys with strict equality. The response must contain exactly those names and types in one JSON
object, optionally fenced as JSON. This criterion pins a document snapshot, not current filesystem
freshness or the truth of arbitrary prose. It cannot be combined with a work-delivery declaration.

Add `'output' => 'json_schema'` inside the diagnostic to request structured JSON from an
OpenAI-compatible provider. The runtime derives a required scalar-object schema from `fields`
and boolean `equals` outputs without inserting expected values. This finite option supports up to
64 output names of at most 64 characters; it does not accept arbitrary JSON Schema. It requires
gateway structured-output support and an agent intake that records the wire format. Unsupported
installations or providers refuse; a provider HTTP error never falls back to an ordinary answer.

With this option, every observed model call must carry the declared `response_format`, and the
answer must be a JSON object without fences or surrounding prose. Missing or changed transport
evidence yields `answer_indeterminate`; the existing diagnostic judge still rejects false values.
The option is part of the immutable declaration and cannot be added to an existing session.
Omitting it preserves the original diagnostic behavior, including an optional JSON fence.

The declaration is durable, immutable and owned by the invoker. Omitting it on later turns retains
it; an identical redeclaration is idempotent. Changed or late criteria are refused before execution.
The runtime requires an answer-judge capable gateway and a durable event store. Its native judge
reconstructs a complete, contiguous `source_page` chain with the declared path and SHA-256 from
successful read-only results that actually reached subsequent model input. Missing evidence,
wrong values, extra prose, duplicate output keys and changed types cannot establish acceptance.

The response exposes `answerAccepted` and `diagnostic`; the termination carries the same verdict.
An accepted diagnostic returns the raw candidate as `final_answer`, even after a pending progress
notice. Rejected or indeterminate answers have separate terminal causes and do not derive work
closure. A customized orchestrator factory that omits the current native judgment cannot return a
successful diagnostic. `session.diagnostic_judged` retains the candidate, criterion identity and
evidence coordinates; `DiagnosticJudge::derive()` replays it without filesystem access or a model.

A diagnostic never manufactures progress, grants permissions or verifies recorded work. Its
accepted read-only answer can coexist with `closure.verified: false` for `scope: recorded_work`.
Sessions without this optional contract retain their existing behavior. Measured in Greenhouse
decision 0418 and evidence 0736, using fixed provider responses, not a real model.

If the provider reports a truncated response, `agent` returns `ok: false`, `truncated: true`,
`provider`, `outputLimit`, `stopReason`, and the session id when one exists. It records no final answer or
closure for that incomplete response. Earlier effects and recorded usage remain in the session.

The app may declare `agent.outputTokens` as a positive integer in `config/app.php` or through
`config:set`. Absent keeps the native 4096-token default; invalid values, including explicit null,
are refused. Explicit output requires gateway 0.29+ and agent 0.47+ so both the native loop and
its intake support the contract; older installations refuse the option before generation.
Every loop call carries the same limit, including existing context and degeneration recovery.
A known context must be larger than the output limit. Input projection reserves that output
space and refuses an estimated input that still cannot fit; the estimate is not a provider
token count, and an unknown context makes no capacity guarantee. A truncated response never
raises the limit or executes its partial tools. This configuration grants no additional scopes.

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

**Recovering a recorded argument — `agent:argument`**

A rejected implementation remains in its `session.tool_called` event even when the trial restores
its original files. Read one top-level string argument by session and exact event sequence:

```bash
php bin/coa agent:argument --session=my-session --seq=87 --argument=content --max_chars=3000
```

The result includes `content`, its full `sha256` and `total_bytes`, byte offsets, the recorded tool
and `call_ok`, and `next_cursor`. Keep the same session, sequence and argument, pass `next_cursor`
unchanged, and concatenate page contents until it is null. Each page is complete JSON within the
transport's result budget; an explicit `max_chars` can only tighten that budget and is required
when no transport budget exists. Offsets respect UTF-8 boundaries, while the bound measures the
encoded JSON, including metadata and escapes.

The cursor binds the selected call and content rather than the growing journal, so recording a
read does not invalidate the next page. It carries no authority: the operation requires the same
`agent:read` or `agent:answer` scope as other protected session reads and is available on CLI, TUI
and MCP. Unknown calls, nontext arguments and mismatched cursors return an error. Reading a recorded
proposal neither accepts its code nor applies it to a workspace. Recovery was measured through the
native loop in greenhouse evidence/0781; that fixture does not demonstrate autonomous discovery or
repair of the proposal.

During progress recovery, `agent:argument` can read a call already recorded in the current
session through its declared `SessionArgumentOperation` contract. It remains subject to scopes,
explicit withdrawal, prerequisites, argument identity, cursor checks and the transport budget.
An aliased operation keeps this contract; a matching tool name alone does not acquire it.
Reading does not clear recovery, invoke the original producer or prove accepted code. A `finish`
call without a `content` argument cannot supply the earlier `start` or `append` arguments.
The initial run context lists `recorded_argument_readers` separately from
`recorded_result_readers`; both describe only readers visible at that snapshot. Always use the
current outgoing offer for continued availability. General source exploration stays restricted.

**Repairing a recorded proposal — `edit` with `source`**

With DevTools 0.34 or later providing `EditPairs`, the runtime adds an optional `source` to
its existing `edit` contract. Supply the rejected call's `session`, exact tool-call `seq`,
and the complete proposal's `submitted_sha256` as `source.sha256`, alongside the usual
`plugin`, `class`, and exact `edits` pairs. Without `source`, editing keeps its current-file
behavior. The same `agent:read` or `agent:answer` permission used by session readers and
`plugins.<Plugin>:write` are both required before the source is read.

A source must be a complete recorded inline `implement` rejection, or a recorded rejection
of an earlier source-based `edit`. The runtime validates the native trial and effect
receipts, destination, submitted/judged hashes, and preserved or restored baseline.
The current destination must still match that baseline; this operation does not silently
rebase an old proposal onto changed code. Missing, ambiguous, malformed, or oversized
repairs refuse before judgment. Derived source chains are limited to 16 producer calls.

The host reconstructs the exact repaired PHP and sends only that implementation to the
normal `implement` judges inside a confined trial. It does not copy the session ledger
into the trial. The public edit arguments stay in the session record; the trial receipt
also identifies the effective implementation input and repair provenance. Success still
requires explicit promotion, and a recorded failed repair can be referenced by its new
call sequence and submitted hash. This does not grant activation or human approval.

A compatible runtime must resolve `source` before invoking DevTools: the standalone
editor explicitly refuses that argument so an older host cannot silently edit the current
file instead. Unsupported or unrecorded proposals can still be read with the existing
readers and resubmitted as a complete `implement` input under the ordinary gates.

**Recovering a recorded result — `agent:result`**

When a resumed conversation no longer contains a full diagnostic, read the stored result of that
exact call without invoking its producer again:

```bash
php bin/coa agent:result --session=my-session --seq=87 --max_chars=3000
```

Concatenate `content` pages using each `next_cursor` unchanged with the same session and sequence.
The result includes the stored bytes' `sha256`, a `call_sha256` binding the recorded event and its
metadata, byte offsets and `total_bytes`. Cursors survive journal growth, reject a different call
or changed record, and respect UTF-8 boundaries. Complete JSON pages obey the transport's encoded
result budget; `max_chars` can only tighten it and is required without a transport budget.

`ok` reports whether the read succeeded; `call_ok` preserves the original call's outcome.
`stored_chars` counts the text available in the record, while `declared_chars` preserves the
producer's recorded length. `storage_complete` is true for a known full result, false for a known
cut, and null when completeness is unknown. A null `next_cursor` ends the stored bytes without
upgrading unknown or partial storage to complete. Reading a diagnostic neither repairs the code
nor proves that an implementation was accepted.

During progress recovery, the native agent still offers this reader for results recorded in its
own session. A concrete call must name that session and an existing `session.tool_called` sequence;
other reads remain restricted. Recovering pages does not clear recovery, count as new exploration
or reset the progress window. Prerequisites, authorization and cursor validation still apply.
The producer declares this behavior through `SessionResultOperation`, not a tool-name exception.

This read-only operation uses `agent:read` or `agent:answer` on CLI, TUI and MCP. Its cursor grants
no authority. An unknown or ambiguous sequence, a non-call event, invalid text or metadata, and
a mismatched cursor return an error. `agent:argument` remains the reader for submitted arguments.

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

The default agent's skill-loading instruction follows the executable tool offer on each
request when the installed gateway supports `setSystemPromptProjection`. Withdrawing
`skill_load` removes that section; offering it again restores it in the same position.
The skill list is captured when the invocation starts and still excludes human-only skills.
The projection preserves the named `invocation_start` snapshot, conversation, scopes and
recovery notices. Older gateways retain the initial-offer behavior. An application override
that replaces the generated system prompt keeps responsibility for its own instructions.

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
   On the CLI a currently recognized signer's scopes are checked after signature verification;
   `identity:enroll` requires that scope. A key never recognized retains the local bootstrap behavior.
   To make your gpg key the house's *recognized* root
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
   decisions/0207). Active passkey sessions use the enrollment's current scopes on every request:
   reducing permissions takes effect immediately without requiring a new sign-in. An empty scope
   list preserves authentication and grants no scoped access; revocation ends the session.
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

`POST /webauthn/register` stays open: registering grants nothing, enrolling is the act, and the root gate
is the file only you write.

### The session on the operations surface

The same session is a **principal of the operations surface** (greenhouse decisions/0208). Once the door
is wired, `PasskeyPlugin` also registers `Milpa\AppRuntime\Web\PasskeySessionMiddleware` — under its own
class name, and as the container's `Milpa\Auth\Contracts\AuthContextFactory` (the same instance; a host
that registered its own factory keeps it). It reads the cookie and puts the resulting `AuthContext` under
`milpa.auth`, exactly where `AuthOperationHttpPolicy` judges every operation that declares `scopes` — so
a browser signed in with a key holding `agent:run` can `POST /agent`, and one without it gets `403`.

- **Precedence — the Bearer decides.** If `AuthenticateMiddleware` already left a context that is
  *authenticated* or *invalid*, the request passes through untouched: a rejected Bearer is never
  laundered by a cookie. Only an absent or anonymous context lets the cookie speak.
- **Revocation parity.** The cookie is worth what it is worth at the panel's door: resolved through
  `milpa/auth`'s `StartSession` and re-checked against the enrollment ledger on **every** request. A
  revoked passkey's session is destroyed, the response carries an expiring `Set-Cookie`, and the request
  goes on **anonymous** — no error from the middleware; the operation's policy answers `401` if it needed
  an actor. The ledger judges only `passkey:*` principals: a session the host minted itself through
  `milpa/auth` under the same cookie (`token:…`, `user:…`) is attached as it is and never destroyed here.
- **CSRF posture.** A mutating request (`POST`, `PUT`, `PATCH`, `DELETE`) authenticates from the cookie
  **only** when its `Content-Type` is `application/json` (parameters allowed) **and**, if `Sec-Fetch-Site`
  is present, it says `same-origin` or `none`. Otherwise the cookie is ignored — not even read — and the
  request continues anonymous. `GET`/`HEAD`/`OPTIONS` authenticate from the cookie unconditionally.

Compose it in `public/index.php` after the Bearer middleware and before the handler (`milpa/framework`
ships this composition as `App\Http\IdentityChain`, executed by its own test):

```php
use Milpa\AppRuntime\Web\PasskeySessionMiddleware;
use Milpa\Auth\Contracts\CredentialVerifier;
use Milpa\Auth\Http\AuthenticateMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

$container = $kernel->container();
$chain = [];
if ($container->has(CredentialVerifier::class)) {
    $chain[] = new AuthenticateMiddleware($container->get(CredentialVerifier::class));   // the Bearer decides first
}
if ($container->has(PasskeySessionMiddleware::class)) {
    $chain[] = $container->get(PasskeySessionMiddleware::class);                       // the cookie speaks when it said nothing
}
foreach (array_reverse($chain) as $middleware) {                                       // nest: first declared runs first
    $handler = new class ($middleware, $handler) implements RequestHandlerInterface {
        public function __construct(private readonly MiddlewareInterface $m, private readonly RequestHandlerInterface $next) {}
        public function handle(ServerRequestInterface $r): ResponseInterface { return $this->m->process($r, $this->next); }
    };
}
$response = $handler->handle($request);
```

## Upgrading

### `events:catalogue` answers for the APP, not for the process

An emitter declares its events to the dispatcher **when it is constructed**, so a CLI process that builds
almost none of them heard about almost none of them: measured on fresh cattle, the catalogue listed **7 of
the family's 24** framework events (greenhouse decisions/0228, second slice). The package now speaks for the
emitter nobody built — it names a `Milpa\Interfaces\Event\DeclaresEvents` holder in its own manifest, and
this fold reads those manifests and declares on the emitter's behalf, so `events:catalogue` and
`house:context`'s `events` section both answer for what the app HAS installed.

```json
{ "extra": { "milpa": { "events": ["Acme\\Shop\\Event\\ShopEvents"] } } }
```

- **The floor moved**: `milpa/core >= 0.12` (where `DeclaresEvents` lives), and with it
  `milpa/runtime >= 0.14`, `milpa/plugin >= 0.17` and `milpa/live >= 0.23` — the versions whose manifests
  name their holders. `milpa/mcp-server >= 0.7` and `milpa/admin >= 0.16` do the same for the apps that
  install them.
- **The manifests read are `vendor/composer/installed.json`** — what Composer really resolved — plus the
  app's own `composer.json`, so an app declares the events IT dispatches the same way. Nothing is probed
  and no path is invented; an app without an `installed.json` answers from its dispatcher alone.
- **The dispatcher stays the authority.** It keeps the first declaration of a name, so an emitter that was
  really constructed is never overridden by its manifest, and asking twice changes nothing.
- **A new `warnings` list**: a manifest entry naming a class that is not autoloadable here, or one that is
  not a `DeclaresEvents` holder, comes back as `{package, class, why}` with `ok` still `true`. Its events
  are MISSING from the catalogue, which is exactly why it is said out loud instead of dropped — and no row
  is invented for it.

### `events:catalogue` — the house counts its own events; `house:context` gains a section

`events:catalogue` answers what this app's dispatcher was **told** exists against what it really
**dispatched** in this process (greenhouse decisions/0228), and `house:context` carries the same fold
compact under a new `events` key. Both read the dispatcher and nothing else — the manifest pass described
above came after this and declares TO that same dispatcher: the authority on «what events exist» is the
emitter, and the one place every dispatch passes through is the dispatcher.

- **The floor moved**: `milpa/core >= 0.11`, which is where the contract lives
  (`Milpa\Interfaces\Event\DeclaredEvents`, `EventDeclaration`). `MilpaEventDispatcherInterface` was
  **not** widened — a dispatcher either implements the new interface or is asked nothing.
- **An app on an older dispatcher is not broken, it is named.** `milpa/events < 0.4` implements no
  memory of what was declared or dispatched, so both answer `ok:false` with the dispatcher's class and
  the interface it lacks — never an empty list, which would read as «this app dispatches no events».
  `composer update milpa/events` to `>= 0.4` and the rows appear.
- **A name dispatched without a declaration is listed as debt**, with `declared: false` and `null` for
  everything only a declaration could say. Nothing is invented to fill the row out, and nothing is
  hidden for lacking one.

### Scoped plugin authoring

The host registers one `PluginAuthoringPolicy` as a tool `CallPolicy` and an `OperationBoundary`.
CLI, MCP and HTTP executions carry their current `ToolContext` into the runner; agent, sequence and
recipe drivers preserve it when opening the next door. `InvocationContext` remains attribution,
not permission. These contracts require `milpa/tool-runtime >= 0.17` and `milpa/console >= 0.20`.

A finite caller needs the exact scope `plugins.<Plugin>:write` to author that plugin. For example,
`plugins.Owned:write` permits writing `src/Plugins/Owned/` and `tests/Plugins/Owned/`. This follows
Permission's namespace/resource/action spelling; it does not expand roles, accept globs or assign
meaning to the experimental `plugin:Owned` string. Activation still needs its own authorization.

- `make`, `implement` and `edit` require a canonical plugin name. Implementations must currently
  use either one complete body or `implement`'s `mode=start`, `append`, and `finish` protocol.
  Each section still runs in a confined trial and must be promoted before the next call can use
  it. Parts remain beside the scaffold as `.php.milpa-part`, inside the same plugin write set;
  they never replace executable PHP until `finish` passes the existing verification gate and its
  trial is promoted. Revocation also blocks promotion of pending parts.
- `test` requires a relative path under `tests/Plugins/<Plugin>/`. Tests and verifier subprocesses
  run in the same write boundary, with read-only root/vendor, private trial state and temporary
  storage, an ephemeral PHPUnit cache, and unshared network/PID namespaces. Missing confinement
  refuses execution; it never falls back to writing the host.
- Trial stdout and stderr are drained together, so a verbose warning cannot block the child
  behind an unread pipe. Both channels and the exit status are retained, including output
  produced before the existing trial deadline kills an unfinished process.
- A failed native `test` stays unsuccessful. Its error text is JSON with schema
  `milpa.trial-test-failure/v1`, `ok: false`, `ran_in_trial: true`, `applied: false`, the
  workspace, `trial_exit`, the original structured `output` (or `null`), and separate `stderr`.
  This survives the tool channel's exception and the durable session record. Missing output
  does not imply a PHPUnit verdict; unknown producer counts remain unknown. Invalid UTF-8 in
  diagnostics becomes the Unicode replacement character. Direct `ToolResult.data` consumers
  keep the original producer data. No promotion instruction is added to failed tests.
- A successful trial is a proposal. `sandbox:promote` and `sandbox:undo` judge every affected file
  against the authority of the current call before writing the first one. Mixed resource exports,
  traversal and symbolic links refuse as a whole. A saved trial never saves permission to export.
- The diff compares the trial copy with its original host manifest. Changes made only in the host
  are not trial edits; stale checks separately reject conflicts on files the trial actually changed.
  This lets a current regrant authorize a pending trial without overwriting the enrollment ledger.
- Other mutating operations with empty declared scopes refuse finite callers. The agent's session
  bookkeeping declares `agent:run`. The local `*` mode keeps its existing behavior.

This confines authoring writes, including code run by a verifier. It does not isolate a local shell
owner, hide readable files, make already activated host plugins untrusted, or provide transactional
isolation against external writers. Authoring a plugin and activating its code are separate steps.

### 0.120.0 — driving the agent requires `agent:run`; the passkey session is a principal

The four operations that drive the agent — `agent`, `skill:invoke`, `agent:goal`, `agent:mode` — now declare
`scopes: ['agent:run']` (greenhouse decisions/0208). Over HTTP the policy is consulted where before it was
not: an **anonymous `POST /agent` now answers `401`**, and an authenticated actor without the scope `403`.
The CLI now checks declared operation scopes when `MILPA_TOKEN` presents a verified identity with
nonempty scopes. It uses the same `PolicyGate::authorizeScopes` judgement as the agent door, before
asking for a signature or session consent. Consent cannot supply a missing scope; a sufficient scope
does not replace consent. This requires `milpa/tool-runtime >= 0.16`.

With `--sign`, the current verified GPG signer also carries its recognized scopes into the shared
gate and delegated tools. The enrollment ledger takes precedence over static policy: revoked entries
have empty authority, and an unreadable ledger refuses. A key never recognized retains the existing
local bootstrap behavior. An explicit signature authenticates read operations as well; a stored
session owner never supplies that authority. When a token and signature are both presented, their
scopes intersect, so signing cannot widen the token.

HTTP agent turns additionally require `milpa/console >= 0.19`: the authenticated request's tool
authority travels separately from `InvocationContext`, through the runner to the agent's governed
door. A passkey or Bearer caller keeps its own scopes, including an empty list; the server's
`MILPA_TOKEN` cannot replace them. A web turn missing that authority refuses before calling a model.
Absent, invalid and empty-scope tokens retain the local process’s `*` default (greenhouse decision
0311); finite callers are additionally subject to the plugin authoring policy below. MCP over stdio
also retains its `*` context. An MCP client that authenticates as a principal of its own needs `agent:run`
for `skill:invoke`, `agent:goal` and `agent:mode`, as it already did for `agent:sessions` and `agent:show`.
The `*` wildcard an `identity:bootstrap` root holds keeps admitting. An app that exposes any of the four
in `config/http.php` — by name, or through `expose: ['*']`, which includes them — without an
`OperationHttpPolicy` now refuses to boot, as it does for every scoped operation.

- Tokens: mint them with the scope — `php coa token:new desktop --scopes=agent:run` (add `agent:read`
  / `agent:answer` for the session reads and the gate answers, as before).
- Passkeys: enroll them with it — `php coa identity:enroll --fingerprint=<credential id> --scopes=agent:run --sign`
  (repeat `--scopes` for `milpa.admin` if the same key opens the panel).
- The session itself: `PasskeyPlugin` now registers `PasskeySessionMiddleware` (also as the container's
  `AuthContextFactory`), and it only counts once `public/index.php` composes it after `AuthenticateMiddleware`
  — existing apps copy the new composition from `milpa/framework`'s `public/index.php` (see *The session
  on the operations surface* above). Without that line the passkey cookie keeps opening the panel only.
  A house without `milpa/data` also needs its `OperationHttpPolicy` registered with `milpa/auth` alone
  (the skeleton's `config/boot.php` now does, through `App\Http\IdentityWiring`): a policy gated on the
  token store leaves the cookie-only house with no policy to consult, and a scoped operation exposed
  in `config/http.php` refuses to boot.

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

### Review a screen before activation

With `LivePlugin` enabled and `live.secret` configured, `/live/review` lets an
identified reviewer create immutable screen revisions, compare their baseline and
proposal, try the proposed screen, activate the exact revision, and restore its
baseline. Reload an active page to load its new declaration. Existing open tabs
keep their signed view until reloaded; activation does not replace application code.

The operations `screen:draft` (`name`, `type`, `props`), `screen:review` (optional
`revision`), `screen:promote` and `screen:rollback` (required `revision`) use the same
revision service. They declare `milpa:component:screen-review:draft`, `:read`,
`:promote` and `:rollback` respectively. The review page requires `:read`; its
buttons enforce the corresponding action scopes. The component wildcard `:*`
grants all four in the component UI; assign the explicit scopes above to operation
callers. A shareable review URL is `/live/review?revision=<id>` under the
configured live route. Identity and scopes are still required.

The resident agent creates revisions in the host's review store. These three
revision mutations use their own lifecycle instead of the generic file trial;
scope and consent checks still apply. A scoped launch grant such as
`--grant=screen_draft:name=todos` can consent to proposals for that screen without
granting activation. Reading a generated revision does not require its hash to
have appeared in the original request. Plugin authoring and `screen:declare`
retain their existing trial routing (Greenhouse 0330).

Apps explicitly opt component types into `ScreenPreviewRegistry` during plugin
boot. A factory receives a `PreviewEnvironment` containing the immutable revision
ID, an isolated codec, and empty component and renderer registries. It must build
its complete component graph and HTML renderers with that codec and separate
persistence/effect collaborators. No active registry or renderer is used as a
fallback. See Greenhouse's complete ToDo example for an implementation with a
separate SQLite file per revision and private records per principal.

Preview is a trusted application factory boundary, not an operating-system sandbox
for arbitrary PHP or external services. Preview actions still require the component's
normal scopes. Test records are never copied to active storage. Revisions and their
test stores remain under the app's ownership; this release does not delete them
according to a retention policy.

Revisions live under `var/screen-drafts`; each file is addressed and verified by its
canonical content hash. Activation compares the reviewed baseline under a file lock,
then atomically replaces only that screen's declaration. The app's `src`, `config`,
`public` and `composer.lock` fingerprint must still match. Dependency selection is
bound through the lock; edits made directly inside `vendor` are outside this guard.
Changing app PHP, configuration, assets or dependencies requires creating a new
revision. Malformed active declaration stores are refused instead of overwritten
as empty stores. Promotion and restoration change screen declarations only, never
PHP, migrations, or application records. Greenhouse decision 0329 defines this cut.

### Observed progress

Native trial calls record a host observation of the file changes made by that execution, separate
from the operation's permission ceiling. The session links that observation to its tool call;
repeated content in another trial does not create new progress. Promotion has a separate application
identity. A successful native `test` contributes behavioral evidence for its selector and input tree,
Changing only the timeout does not create another proof.

A known empty observation does not reset recovery. An unavailable observation neither clears a
pending recovery nor proves its window exhausted. Older producers without observations retain the
legacy session interpretation. This protocol requires `milpa/agent >=0.46` when the optional agent
capability is installed (greenhouse decisions/0346, evidence/0663).

A completed native trial with failed assertions can separately produce a diagnostic identity when
its copied inputs remain unchanged. This is not positive test evidence. The identity binds the
normalized relative test selector and copied input bytes; another workspace, timeout, output text
or equivalent relative path spelling cannot make it new. Repeating it does not reset progress
again. Unexecuted tests, assertion-free runs, infrastructure errors, absolute selectors and
unobserved or changed inputs produce no diagnostic identity. Permissions, verification claims and
screen activation remain separate (greenhouse decision0429/evidence0751).

With trials enabled, native agent `screen:draft` calls also observe the screen revision store
before and after execution. A successful result must identify a verified stored record matching
the requested name, type and normalized props. Artifact identity describes the screen name,
baseline, definition and app build; revision IDs, timestamps and nonces remain storage metadata.
Saving those same values again, including values present before this session, contributes no new
artifact. Map order and the native default screen name do not make a proposal new.

Each save still creates its own immutable revision and review link. Draft observation supplies no
test evidence, diagnostic, UI validation or activation approval. Missing observations and
unverifiable results remain unknown; scopes and permission gates continue to govern the call.
Historical calls without observations retain their existing interpretation. This observation is
scoped to the agent's trial-aware registry, not manual CLI calls or agents with trials disabled
(greenhouse evidence0777).


### Trial input freshness

A repeated call reuses its trial plan only while the copied host inputs still match the original
manifest. Source additions, edits, deletions and undo renew the plan; top-level `var/`, live-mounted
`vendor/` and `.env` are outside that copied-input comparison. `TrialWorkspace::hasCurrentInputs()`
checks all copied inputs, while `stale()` continues to check only a proposal's promotion targets.

Renewal retains the old workspace and pending diff under the existing 24-trial retention bound;
it never rebases or promotes that proposal. Unreadable baselines cannot establish freshness.
This does not provide an atomic snapshot against concurrent host writers or change the independent
repeated-failure guard. Greenhouse decisions/0347 and evidence/0664 measure the native path.

## Read a candidate before continuing

`candidate:state` reads a single-file `edit` or `implement` candidate from the session's native
receipts and current files. It is offered by `AgentOperations` when the `agent` capability is
installed; no model connection or extra app provider is needed. The operation requires
`agent:read` and declares read-only effects.

A verified multipart `implement` finish remains one candidate when its complete report changes
the declared PHP file and deletes that file's `.milpa-part` sibling. The staged baseline must
contain exactly the final PHP bytes, and promotion must consume the sibling. Other deleted
files, unfinished parts, altered inputs or a reappearing staging file do not qualify. The
reader retains the complete promotion receipt; it does not grant activation or human approval.

```bash
php bin/coa candidate:state --session=repair-session --workspace=w1234567890abcdef --json
```

The same projection is available to PHP consumers:

```php
use Milpa\AppRuntime\Agent\CandidateState;

$state = CandidateState::read($appRoot, $sessionStore->stream($sessionId), $workspaceId);
```

Pass an absolute, resolved application root and the trusted session stream. The projection returns
`pending`, `promoted`, `contradicted` or `indeterminate`, with a reason, the candidate path/hash
when known, and references to the producing events and physical receipts. It reads again on each
call; it has no durable state or cache of its own.

A pending candidate has a matching trial copy and current copied-input baseline. A promoted
candidate has an exact promotion receipt, matching host bytes and a collapsed copy. A contradiction
or insufficient evidence offers no continuation. `verification.scope=producer_declaration`
preserves what the producer reported: syntax/conformance alone does not become behavior acceptance.

`authorization` is always `not_evaluated`. When present, `next` describes the existing
`sandbox:promote` operation and its workspace, with `requiresRecheck=true`. Re-read immediately
before proposing it through the normal governed runner. The read neither grants permission nor
reserves future bytes; the normal native gates still decide execution. Reading through the agent
continues to record ordinary tool-call events.

This contract covers one added or modified file. Pending reads use the native copied-input domain;
promoted reads cover the files recorded in the baseline, not later added inputs, vendor or the
process environment. It does not certify all execution dependencies, test/review acceptance or
readiness to deploy. Evidence: greenhouse decisions/0375–0376 and evidence/0692–0693.


## Join candidate, test and screen evidence

`acceptance:evidence` is an `agent:read` operation offered with the agent capability, without a
model connection. It joins the candidate's native receipts and current files, the latest test
attempt in that session, and its latest immutable screen review. The caller supplies the question:

```php
use Milpa\AppRuntime\Agent\AcceptanceEvidence;
use Milpa\AppRuntime\Web\ScreenDrafts;

$evidence = AcceptanceEvidence::read(
    $appRoot,
    $sessionStore->stream($sessionId),
    $candidateWorkspace,
    ['path' => 'tests/Plugins/Owned', 'filter' => ''],
    ['name' => 'focus', 'type' => 'focus-counter'],
    $container->get(ScreenDrafts::class),
);
```

Pass the host's configured `ScreenDrafts` service; pass `null` when unavailable. The native
operation uses that same service and SDK. Its arguments are `session`, `workspace`, `test`
(the exact path/filter object) and `screen` (name/type, optionally an exact `definition`).
The workspace is the **edit/implement candidate**, not the later test workspace.

Catalogue queries (`screen_review` with `{}` or `{"revision":""}`) do not replace the latest
directed review. The collector and receipt join select by request intent, never success: a
later failed or malformed directed attempt stays relevant and cannot recover an earlier green
result. A catalogue alone supplies no exact review. Current files and the selected revision
are still re-observed; catalogue queries do not freeze evidence freshness.

The versioned `milpa.acceptance-evidence/v1` result distinguishes `current_evidence`,
`historical_evidence`, `failed`, `incomplete` and `indeterminate`. Test outcome, `ran`, known
counts, actual scope and `coversRequestedScope` remain separate: a failed or filtered attempt
does not stand for the entire requested suite. Unknown counts stay `null`, and zero stays zero.
Changing copied inputs invalidates failed evidence as well as passing evidence. The reader
uses complete durable receipts, not a model-window preview, and re-observes current files.

Full output and stderr remain in the session stream. `test.receipt` identifies the original
`session.tool_called` result by `toolCallSeq`, character count and SHA-256 of its UTF-8 bytes.
This compact projection preserves the verdict without repeating the diagnostic; it is not a new
receipt store or a guarantee that arbitrary user-supplied screen definitions fit every model window.
A refusal without a trial remains indeterminate and does not acquire a PHPUnit verdict.

`authorization=not_evaluated` and `humanApproval=not_recorded` are unconditional. A screen review
is a read, not human acceptance. This does not activate a screen, deploy, certify browser behavior,
lock future bytes, or certify execution inputs outside the native copied files and screen build.
Concurrent changes can invalidate any later action. Output bytes that cannot be reconstructed
exactly from the native runner's JSON record plus LF remain indeterminate; extra stdout is not
silently normalized away. Evidence: greenhouse decisions/0381 and evidence/0698.

## Declare expectations before selecting a delivery candidate

An agent invocation can declare `expectation` with an exact test path/filter and screen target,
before the session records its first turn or model/tool activity:

```sh
php bin/coa agent --session=focus --prompt='Build the focus screen' \
  --expectation='{"test":{"path":"tests/Plugins/Owned","filter":""},"screen":{"name":"focus","type":"focus-counter"}}'
```

The native `session.delivery_expected` event records canonical target bytes, their digest and the
observed caller provenance. Omission retains it; an identical repetition is idempotent. A changed
or late expectation is refused before session mutation or provider access. SDK invocation accepts
the same object; HTTP carries its JSON string, as declared by the operation schema.

Once a native edit/implement candidate has been promoted, pass its workspace as
`deliveryCandidate` on a later `agent` invocation. The runtime derives its artifact and exact
producer from `CandidateState`, then records `session.delivery_declared` with the expectation
reference and candidate bytes. It never takes the expected coverage from the executed test.
An unbound expectation keeps closure unverified. Binding, repetition and omission grant no
permission or human approval, and a different candidate requires a new session.

Trusted SDK callers can inspect without writing:

```php
use Milpa\AppRuntime\Agent\{DeliveryExpectation, DeliveryScope};

$expected = DeliveryExpectation::read($sessionStore->stream($sessionId), $sessionId);
$proposed = DeliveryScope::forCandidate(
    $appRoot, $sessionStore->stream($sessionId), $sessionId, $candidateWorkspace,
);
```

`DeliveryExpectation::record()` and `DeliveryScope::recordCandidate()` write through a native
`EventStoreInterface` with an `ObservedExecutor`. They are trusted caller APIs, not model-callable
tools; a model's JSON answer is never an expectation. `recordCandidate()` re-reads native files
and events rather than accepting the proposed observation as evidence.

Each model leg receives the current session's recorded expectation and delivery declaration in
its system context, read through the same validated native folds. The first declaration is
available before the first provider call; continuing without those fields re-reads the durable
records. Switching sessions or entering a child reads that session's own stream. Sessions without
either declaration receive no delivery section.

For `screen:draft`, the caller's validated screen name resolves target selection only.
If the proposed name differs and no current, standing or confirmed human intent names it,
the gate records `delivery_target_mismatch` as a failed call and returns an argument error to
the tool loop. It neither creates a draft nor rewrites the arguments or opens a human question.
The tool stays available for a separately proposed correction, subject to the usual scope,
consent and repeated-failure checks. Missing or invalid expectations and other operations keep
their existing gate behavior; a declaration never authorizes activation.

This context is caller-supplied data, not instructions, permission, approval or current verification.
A null delivery means no candidate is bound. A recorded binding identifies its original producer
and artifact digest; it does not certify current file bytes or passing tests. Continue to use native
candidate and acceptance evidence operations to verify current state. Greenhouse0408/evidence0726
measures this transmission independently from model answers.

Complete legacy `delivery` declarations retain their existing behavior in sessions without an
expectation. Do not mix the two paths. Readers reject missing expectation links, changed criteria,
or a different producer behind a bound workspace. Current physical evidence is still sampled by
`AcceptanceEvidence`; the binding is not a transaction, filesystem lock or session-owner policy.
Evidence: greenhouse decisions/0403 and evidence/0721.

For a screen written across several artifacts, add `members` to the expectation **before**
execution. It is a unique list of at most 32 exact relative paths, normalized as a sorted set:

```json
{
  "members": ["src/Plugins/Owned/Services/TodoBoardRenderer.php", "src/Plugins/Owned/Services/TodoItemRenderer.php"],
  "test": {"path": "tests/Plugins/Owned", "filter": ""},
  "screen": {"name": "todos", "type": "todo-board"}
}
```

Select a current promoted member using the same `deliveryCandidate` input. The SDK also binds
each member's latest native producer, artifact digest, baseline digest and complete promotion
receipt digest. Every producer must follow the expectation. These bindings survive rehydration
and cannot be replaced. Legacy `delivery` cannot introduce composition members.

An earlier member may have historical execution inputs because another member was written later.
Its retained artifact and promotion receipt do not make that old candidate current: `CandidateState`
keeps its original freshness checks. Composition acceptance instead requires every retained member
to appear with identical bytes in a later native test's preserved inputs, along with the exact
requested test path/filter and current screen review. Closure relates each producer to exactly one
recorded artifact and retains all other work obligations, red judgments and later mutations.

This proves the declared test ran against those members; it does not prove that the tests cover
every UI behavior. It grants no activation authority, human approval or browser verification.
Omitting `members` preserves the single-artifact contract.

## Run termination and closure

The `agent` result includes `termination: {reason, receipt}` for attempts that reach the model
invocation. The same observation is appended as `session.run_terminated` when a session event
store is available. Reasons come from `milpa/ai-gateway` 0.25.0's base loop; `unknown` means the
current invocation has no proven producer observation. Answer text never supplies the cause.

Closure is derived only for a current `final_answer` with no pending session question. Refusal,
confirmation, blocking, exhaustion, stalled progress, declared house debt, invalid response and
exceptional exits cannot derive a closure. A genuine final answer may still have `paused: true`
if the host recorded a question; it has no closure. A final cause alone certifies no completed
work, permission or approval: the existing recorded-work verdict remains the judge.

A gateway with context-budget termination can return `context_budget_exhausted` after a completed
step. The SDK exposes `contextExhausted: true` and persists the same estimated-budget receipt in
`session.run_terminated`. This is not a pending human question and produces no closure. An invoker
may continue the same session with its existing delivery criteria and authority under a finite
total request budget. Nothing retries or resumes automatically, and a continuation does not reset
the durable progress history. The partial progress window and pending recovery are checkpointed
before the termination event, then restored in the next process. A missing or malformed checkpoint
refuses continuation instead of silently granting a new preparation quota. Other termination
causes keep the previous new-run accounting. An impossible initial input and provider failures retain their
original failure cause; older gateways remain compatible.

The protected `ask()` and `orchestrator()` signatures remain unchanged. Overriding the factory
while preserving the base `ask()`, orchestrator `run()` and `termination()` methods retains
provenance. Replacing any of those three methods yields `unknown`, even if a previous base run
left an observation. A reused producer must emit a new observation in the current invocation.
Older stale vendors without the API also yield `unknown`; Composer requires gateway >=0.25
when that optional capability is installed. Early refusals before `ask()` emit no run observation.

### Explicit MiniMax-M3 generation mode

Set `agent.minimaxThinking` to `disabled` or `adaptive` to select the corresponding
MiniMax-M3 mode. Omit the key to retain provider defaults. Invalid modes and unsupported
models/providers fail before generation. Explicit configuration also refuses an older
gateway or agent intake that cannot transport and record it. Both ordinary work and
structured diagnostic loops apply the same profile. This does not change authority,
tool availability, token limits or acceptance criteria. See Greenhouse evidence 0831.

Ordering prerequisites complete only after a successful dispatch and an affirmative `ok` when the operation declares one. Failed results, pending confirmation and known incomplete results remain pending across session reloads. Legacy complete results without an `ok` declaration retain their dispatch outcome; this does not judge the quality of a plan or guide (Greenhouse 0833–0834).
