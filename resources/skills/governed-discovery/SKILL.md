---
name: governed-discovery
description: Use at the very start of any "build X / implement X / add X" request, BEFORE proposing or writing an implementation. Reframes the feature request into the architectural question it is really answering, the minimal slice that could refute it, the observable evidence that would count, and what is explicitly out of scope — then runs the reorder check so the person feels whether the framing changed their first move. Executable D³ (Doctrine-Driven Development).
---

# governed-discovery

> The artifact is not the code. It is the question the developer didn't know to ask.

Executable front of the D³ pipeline. It does not write the feature. It makes the request
*answerable* — and it earns its minute only if it **reorders** what you build first.

**Announce:** "Running governed-discovery before we build — one minute to frame this."

## When this fires

Any request to build, implement, add, or "just quickly" ship a feature. The stronger the urge to
jump straight to code, the more this earns its minute.

## The one rule

**Do not propose or write an implementation until the frame below exists.** D³ never writes your
code from an unframed request — it frames the question and judges the evidence.

## Step 1 — Capture the "before" (one line, don't skip)

Write down, in one sentence, the implementation the person was about to start:

> **Before:** "The first thing I was going to build is ______."

You will test this line at the end. Skip it and you cannot know whether the Discovery did anything.

## Step 2 — The five questions

Ask them in order. Answer each in one or two lines — prose, not a plan.

1. **What architectural question are we really answering?**
   A question is *architectural* only when its answer **changes the order of construction**. If the
   answer would not reorder anything, it is interesting, not load-bearing — keep digging until you
   reach the one that reorders.
2. **What claim would be too big for the evidence we have?**
   Name the overstatement to avoid, up front. Never claim more than a slice will prove.
3. **What is the minimal slice that could *refute* the hypothesis?**
   Not the slice that ships the feature — the smallest thing that, if it fails, tells us the framing
   is wrong. Slices exist to kill hypotheses, not to demonstrate cleverness.
4. **What observable result would count as evidence?**
   A red/green, a number, a diff someone else can reproduce. "It works" is not observable.
5. **What is explicitly out of scope — and what decision are we deferring (no evidence yet)?**
   At least one thing should move to "deferred, on purpose." Intelligently postponing decisions that
   do not yet deserve to become architecture is the point, not a side effect.

## Step 3 — The reorder check (this is the whole test)

> **After:** "Is your first move still the first?"

- **No** → the framing moved the build order. The Discovery earned its minute. Proceed to the
  reordered first slice.
- **Yes, always** → the Discovery produced curiosity, not architecture. **Say so plainly** — do not
  dress a non-result as insight. The fix is a sharper Discovery next time, never a pretend result.

## What this refuses

- It never writes the feature — it frames the question and hands you the reordered first slice.
- It never lets a question that would not reorder construction pose as "architectural."
- It never claims more than the slice will prove.
- It never lets the conversation become law — capture the frame as an artifact, not a memory.

## Pairs with

`governed-close` — at the end of the slice: what was proved, what was not, and the next open question.

---

> **Grounded in** `constitution.md` + `README.md` (the three inversions; a question is architectural
> only when its answer reorders construction). This skill *executes* that doctrine — it does not
> re-legislate it; if the wording here diverges from the canonical docs, the docs win.
>
> **Status: hypothesis, not settled doctrine.** The canonical D³ docs defer tooling and agents until
> the method earns them (`D3/README.md`, `D3/lab-log.md`). This skill is a *new* artifact on the
> adoption axis: it claims an executable Discovery reproduces the H-001 effect (reorders construction)
> for its user. Its own success test — Step 3 — is D³'s refutation protocol. If running it never
> reorders anyone's first move, the skill is refuted, not the doctrine.
>
> Apache-2.0 · © Rodrigo Vicente — TeamX Agency
