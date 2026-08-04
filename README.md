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
| `SterileLoopGuard` | not repeating a call that already failed the same way twice |
| `PrerequisiteGate` | an ordering obligation, executed: until the required thing runs, the rest does not |
| `SessionOptionTable` | withdrawing a tool from a session's catalogue — forbidding, not asking |
| `BroadcastingEventStore` · `SurfaceBroadcaster` · `MercureBroadcaster` | getting what happens to the live surfaces while it happens |
| `SessionBookkeeping` · `SessionPlanBoard` | the session's plan and to-dos, bound to *its* id |

**The operations — what your app knows how to do**

`AgentOperations`, `SessionOperations`, `CapabilityOperations` and `TokenOperations` are the operation
groups a Milpa app registers. They are *returned*, never self-registered: whoever assembles the
registry decides which groups get in and with what authority, and a group that registered itself
would take that decision away.

**The surfaces — where you drive it from**

`Console\Application` is the single door of the CLI: `coa` on its own, a named command, the TUI, a
one-shot chat. `Tui\AgentScreen` renders the agent screen as text — the actor markers travel *inside*
the text, so a painter can colour by origin and the same screen still works where there is no colour.

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

## License

Apache-2.0 · © Rodrigo Vicente — TeamX Agency

---

Milpa is designed, built, and maintained by **[Rodrigo Vicente - TeamX Agency](https://teamx.agency/?utm_source=github&utm_medium=readme&utm_campaign=milpa&utm_content=app-runtime)**.
