---
name: governed-close
description: Use at the END of a slice, before you call it done or write it up — closes the loop governed-discovery opened. Checks what the slice actually proved against the evidence criterion it set, states plainly what it did NOT prove, names which deferred decision (if any) now has evidence to become an ADR, the next open question, and what must still not be built. Stops "I built it, therefore it works." Executable D³ (Doctrine-Driven Development).
---

# governed-close

> Never claim more than the slice proved. The green check is not the evidence.

The back of the D³ loop. `governed-discovery` opened the question; this closes it — by settling what
the slice *proved*, refusing to claim a gram more, and handing the loop its next question.

**Announce:** "Running governed-close before we call this done — settling what it actually proved."

## When this fires

At the end of any slice, PR, or "it's working now" — especially the moment you are about to write the
summary, mark it done, or move on. The urge to declare victory is exactly when this earns its minute.

## The one rule

**Measure the result against the evidence criterion the slice set — not against your effort.** If
`governed-discovery` ran, its Step 4 named what would count as evidence. Close against *that*, not
against "I built it."

## The five questions

1. **What did the slice prove?**
   State it as "today we proved that …" — the exact claim the observable evidence supports, no wider.
   This is the crystallization an ADR is made of.
2. **What did it NOT prove?**
   The limits, said out loud and up front: sample size, the host it ran on, the case you did not test,
   the "works on my machine." A slice that proved nothing is a valid, honest outcome — say so.
3. **Which deferred decision now has evidence to activate — if any?**
   Look at what was deferred (in the Discovery or the decision log). Did this slice earn one of them
   into a decision? If yes, it is an ADR candidate — *today we proved that…*. If the evidence is thin,
   **leave it deferred.** Do not promote an intuition.
4. **What is the next open question?**
   The loop continues. Naming the next question is the deliverable, not an afterthought.
5. **What must still NOT be built yet?**
   Scope discipline outlives the slice. Name what stays deferred so momentum does not smuggle it in.

## The overstatement check (this is the whole test)

Read your answer to Q1 back against the raw evidence. If a skeptic reading only the artifact — not
your effort, not your intent — could not reach that claim, **shrink the claim until they could.**
Presence is not proof: a filled field, a passing build, a written test are structure, not evidence
that the thing is true.

If closing forces you to shrink a claim, declare a limit, or surface a next question you were about to
skip — it worked. If it changed nothing you were already going to write, say so plainly.

## What this refuses

- It never lets "I built it" stand in for "the slice proved it."
- It never promotes a deferred decision on thin evidence — the ADR waits for the slice that earns it.
- It never lets the lesson stay in the conversation — capture the frame as an artifact; only the
  written record is law.
- It never treats a green check, a filled field, or a passing test as proof that a claim is true.

## Pairs with

`governed-discovery` — the front of the loop this closes.

---

> **Grounded in** `constitution.md` (never claim more than a slice proved; one authority per decision;
> the law is the compiled contract) + `README.md` (an ADR is the crystallization of what a slice
> proved). This skill *executes* that doctrine; if the wording here diverges, the canonical docs win.
>
> **Status: hypothesis, not settled doctrine.** Same footing as `governed-discovery`: the canonical D³
> docs defer tooling until the method earns it. This skill claims a disciplined close prevents the
> overstatement that creeps in at the moment of declaring done. Its success test — the overstatement
> check — is D³'s own *never claim more than a slice proved*. If it never shrinks a claim or surfaces a
> limit, it is refuted, not the doctrine.
>
> Apache-2.0 · © Rodrigo Vicente — TeamX Agency
