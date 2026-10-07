# Carry multipart work between trials

In the agent's trial workflow, each `implement` call can run in a separate copy. Starting a part
does not put it in the app that the next call copies. For an existing class that needs multipart,
keep the same plugin/class and send the complete PHP as ordered chunks within the installed
operation's per-call limit:

1. `implement` with `mode=start` and the first chunk; promote its returned `workspace` with
   `sandbox_promote` before sending more content.
2. `implement` with `mode=append` and the next chunk; promote that call's returned workspace.
   Repeat for each remaining chunk, promoting each one before the next call.
3. `implement` with `mode=finish` and no content; inspect its own behavioral judgment. Only
   after success, promote that returned workspace and run the full relevant tests.

Partial promotions carry the `.php.milpa-part` staging file; they do not replace the class or
prove its behavior. Finish assembles and judges the class. Use each actual returned workspace,
not an earlier trial id. Read the operation's result: a completed tool call can still report
`ok:false`. A missing-part refusal calls for checking the preceding promotion; a moved-target
refusal calls for inspecting the current proposal and destination. Do not repeat unchanged calls
or bypass a refusal. After a failed finish, the promoted `.php.milpa-part` remains readable while
the PHP class stays unchanged. A failed finish is not delivery.

## Repair the current staging file

Read the installed `implement` contract. If it offers `mode=amend`, keep the same plugin/class:

1. Read the current `.php.milpa-part` with an offered `source_page`: omit cursor on the first
   call, then copy each returned next_cursor unchanged, keeping the same path, until null.
   A line-based read may exceed the transport window even when its stored result is complete.
   If only recorded readers remain offered, use this session's visible implement sequences:
   `agent_argument` with argument=content reads start/append bytes in order; finish is not
   their content. A recorded proposal describes current staging only if no later change
   superseded it. Build exact `find`/`replace` pairs against the current bytes. Each find must
   match once at its turn; pairs apply in order. Include replace even for an empty string.
2. Set `expected_sha256` to the whole current staging hash. `source_page` returns that hash even
   for a partial page; follow its cursor to read further. Alternatively use `sha256` from the
   latest promoted start/append/amend result, or `submitted_sha256` from its failed finish, only
   if staging has not changed since. Do not use the normalized PHP's judgment hash.
3. Call `implement` with `mode=amend`, `expected_sha256` and `edits`; omit content. The combined
   UTF-8 bytes of all find and replace strings must fit the installed limit (8192 bytes in
   devtools0.35.0). On a hash mismatch, reread current staging and rebuild the pairs; do not
   repeat an old hash or assume an unpromoted trial changed the host.
4. Inspect the result and promote that amendment's returned workspace. It changes only staging
   and remains unverified. Then call `mode=finish` without content, inspect its own judgment,
   and only after success promote that workspace and run the full relevant tests.

`edit` without source targets the PHP class, possibly still a scaffold. Its recorded `source`
repair supports complete inline proposals, not multipart finish. Append only adds bytes.
If the installed contract lacks amend, restart with corrected chunks using `mode=start`,
promote each chunk and finish as above.

## Continue from recorded pages

After a step limit, inspect the current history and offered tools. A previously loaded skill
may be absent from the new model window; reload it while skill_load is offered. An explicit
first=skill_load orders calls for that invocation; it does not select the skill or load it
without a call. Use the skill name requested by the task.

For source_page results visible in session history, take session and seq from their envelopes.
The result excerpt may stop inside content: its visible path, source hash and offsets identify
the page, but are not its full body. With agent_result offered, request those recorded results.
Independent first pages can share a response. Copy each result's own next_cursor unchanged;
do not reuse a source cursor as a recorded-result cursor. Assemble each result's complete JSON
before reading the source content inside it. Then join source pages in offset order, checking
one path/hash and contiguous offsets through total_bytes. Do not invent missing identities.

A recovered historical source describes current staging only when no later change supersedes
it. If source tools are withdrawn, keep to the offered recorded readers. Reading supplies bytes
for an exact repair; it does not clear recovery or count as repair, verification or delivery.

Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency
