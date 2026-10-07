# Milpa UI composition

Start from the human's workflow: what they see, change, submit and recover from. Choose a layout
that makes those actions direct. A useful task interface may be a list, form or card; a data table
is appropriate when the work actually benefits from tabular comparison. Keep the requested product.

## Choose the composition

- An existing component already performs the interaction: reuse it and configure its props.
- Several existing components cover the workflow: compose them, identifying which component owns
  each piece of state and which action updates it. Avoid separate copies of the same state.
- The domain behavior exists but its presentation is missing: author the renderer and reuse the
  behavior. Keep substantial markup in a small presentation helper when that makes each change
  independently implementable; let the renderer connect dependencies and native child components.
- New behavior is required: decide its state, actions and invalid-input outcomes before its UI.

When the request supplies a scaffold, start by reading that file, its acceptance tests and the
supplied SDK map through `source_read`. Use their exact names and installed signatures to implement
the next bounded unit. Consult additional contracts for concrete missing details. When the component
choice is still open, discover it through `components_catalogue` and `screen_types`;
`operation_contract` explains tool inputs. Resolve uncertain HTML/parser behavior by testing a
candidate. Keep changes within the source boundary the task permits.

A useful split, when the task permits additional classes, is a pure view helper accepting data, identity, messages,
already-rendered child HTML and the already-encoded envelope. Implement and test this small helper
before its consumer. The renderer then stays short: obtain state, render the native child, pass
those values to the helper, and return its HTML with the state, contracts and child assets. Choose
names and fields from the actual task; this pattern does not dictate the app's layout or domain.

## Compose the live boundary

For a complete worked example, read [the reading-session recipe](reading-session.md)
through `source_read`, then its renderer and the supporting files relevant to your change.
Paths in that recipe are relative to this skill's base directory, returned by `skill_load`.
It demonstrates a different domain; adapt the composition to the requested application and its
existing behavior. The PHP files are executable reference code, not an instruction to install
the sample or replace supplied scaffolds with it.

For a custom HTML renderer, use supplied request state when present; otherwise mount the component.
Preserve identity, principal, route and state locale. Resolve native fields through the component
and renderer registries. Give each child a distinct identity and forward its required context.
For transient forms, consult the field's `storage` contract so old browser values do not overwrite
fresh server values. Carry child client assets and component contracts into the parent result.

Use the installed signed-state codec and client action factory. Keep server domain logic on the
server. The root identity, client configuration and envelope must refer to the same component.
For the web client, read `vendor/milpa/live-web/resources/milpa-live-remote.js` through
`source_read` or `source_page` and locate the `milpaComponent` factory. Its returned object
exposes `act(action, payload)`, `busy` and `error`; internal transport functions are not methods
available to an HTML event expression. Choose action names and payload fields from the owning
component's contract. For a form, obtain its current values when it is submitted, rather than
embedding the values from the previous render. A row acting on its parent uses that parent's
client scope and signed envelope; rendering a native field does not move ownership of the
parent's actions into the field. Verify these details against the installed version before
inventing helpers or a second transport.
Encode JavaScript configuration as JSON, then HTML-escape the attribute once; trusted renderer and
codec output already have their own serialization contract. Escape user values and render messages
from the locale catalogue. Show busy, empty and error states where the workflow needs them.

## Deliver a reviewable unit

Use `make` for a missing service, then promote its scaffold before implementing it. Implement and
promote one bounded unit, test its behavior, then build its consumer. For a complete PHP file within
the installed inline size limit, call `implement` with `content` and omit `mode`; this immediately
verifies and judges the proposed class. Use multipart for larger files or intentional chunks.
`mode=start` only stages bytes, even when they contain an entire class. Do not alter supplied tests
to make your implementation pass.

For `implement` and `edit`, use the catalog's bare `plugin` and `class` identifiers; file paths
belong in `source_read`. `implement` writes PHP, so omitting `content` is not a way to read a class.
Correct the rejected argument first. Read the complete retained body and diagnostics with
`agent_argument` and `agent_result` when offered; follow their continuation cursor if present.

When the installed `edit` contract advertises `source`, a complete rejected inline `implement`
can be repaired without resending its PHP. Keep the same `plugin` and `class`, supply exact
`edits` find/replace pairs against the retained body, and set `source` to an object containing:

- `session`: the session holding the rejected call;
- `seq`: that producer's tool-call sequence, not a trial or diagnostic event sequence;
- `sha256`: its complete `submitted_sha256`, not the host baseline or one reader page's hash.

If this source-based edit is rejected by the judges, the next repair can reference that edit's
own tool-call sequence and new submitted hash. The current destination must still match the
rejection's baseline. A changed destination requires inspecting the current file and making a
new supported proposal; an old reference does not rebase itself or grant any authority.
Acceptance remains a trial requiring promotion before its consumer is built.

Without `source`, `edit` changes the current app file, which may still be its scaffold; inspect
it before choosing replacements. If source is unavailable or the producer is unsupported,
repair the complete body and resubmit `implement`. Source repair does not target a multipart
staging file or a failed `finish`; use the multipart recipe for those cases.

During progress recovery, use offered tools for their documented purpose. Repeating a test with
the same findings adds no new diagnosis; `ABANDON` does not restore readers. If you cannot make a supported
proposal, use the stated recovery exits and describe the actual gap without claiming completion.

For files exceeding the inline limit, read [the multipart trial recipe](multipart.md)
through `source_read` before staging chunks. It explains why each chunk's trial must be promoted
before the next call and how to repair a rejected assembled body.

Check real rendering and interaction as well as PHP signatures: native children present, resources
preserved, state carried through the next action, readable errors, keyboard controls and narrow
layout. Report failed checks accurately. Code loading and screen activation remain separate governed
steps; authoring a renderer does not authorize either. When draft tools and loaded components are
available, create and inspect the immutable draft and return the exact review link from the house.

Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency
