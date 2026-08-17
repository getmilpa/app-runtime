# Changelog


## [0.43.0](https://github.com/getmilpa/app-runtime/compare/v0.42.1...v0.43.0) (2026-08-17)


### Features

* expose catalogue effect declarations ([#96](https://github.com/getmilpa/app-runtime/issues/96)) ([78ba831](https://github.com/getmilpa/app-runtime/commit/78ba83184881ca660b9ccf162251729f1b055eda))


### Bug Fixes

* an effect's authority comes from the grant, not from the path it took ([#95](https://github.com/getmilpa/app-runtime/issues/95)) ([37ad92c](https://github.com/getmilpa/app-runtime/commit/37ad92ce2a8cdf42db73def5fae318316b8b4f4b))

## [0.42.1](https://github.com/getmilpa/app-runtime/compare/v0.42.0...v0.42.1) (2026-08-17)


### Bug Fixes

* compaction reads the key the app declares, not the library's parameter name ([#93](https://github.com/getmilpa/app-runtime/issues/93)) ([fc7d8ea](https://github.com/getmilpa/app-runtime/commit/fc7d8eaa38e2ca381cc05c93ff78cda4d298ee83))

## [0.42.0](https://github.com/getmilpa/app-runtime/compare/v0.41.1...v0.42.0) (2026-08-17)


### Features

* declare who ran the effect, and stop letting the reader own the authority ([#91](https://github.com/getmilpa/app-runtime/issues/91)) ([0c8684f](https://github.com/getmilpa/app-runtime/commit/0c8684f505bdf5e477d1d80930b01b98f81ff1b4))

## [0.20.0](https://github.com/getmilpa/app-runtime/releases/tag/v0.20.0) (2026-08-12)

**`AgentKeyScan`: what an agent configuration key looks like, defined once.**

The rule was written twice and the two copies disagreed — the only way this defect ever shows up. Greenhouse `evidence/0158` planted a key beginning with a digit and the narrower copy did not see it: a rail meant to remove a blind spot had one.

The pattern now lives in one constant and the sweep in one method. This package's staleness rail calls it, and so does the greenhouse's family-wide rail, which reads the class out of a cattle app's vendor tree.

## [0.19.0](https://github.com/getmilpa/app-runtime/releases/tag/v0.19.0) (2026-08-12)

**`config` says which keys exist.**

The code reads seventeen agent keys, the template's comment documents four, and a newborn app ships two — neither of them the agent's. `config` now names every key with its type and what it decides.

A hand-written catalogue with nothing confronting it is the same debt better dressed, so the declaration is checked against the `Config::get('agent.*')` call sites derived from this package's own source, in **both** directions: a key read and undeclared teaches nobody, and a key declared and never read is a knob that does not exist.

**An unknown key is reported and still written.** This runtime speaks only for its own keys — a plugin declares its own — so refusing would break a legitimate app to punish a typo. The write is already governed by consent; what was missing is not another lock but the caller knowing what exists.

## [0.18.0](https://github.com/getmilpa/app-runtime/releases/tag/v0.18.0) (2026-08-12)

**The borrowed ceiling is derived from the real catalogue.**

A provider is built in order to *produce* the catalogue, so when it declares its operations the catalogue does not exist yet. `ConfigOperations` received nothing, borrowed from an empty catalogue, and GOV-05 made that the maximum of every axis — safe, and derived from nothing.

`Operations::all()` now runs a second pass once the catalogue is complete, handing it to whoever declares the new `CatalogueBorrower`. What a borrower is handed **excludes its own operations**: folding a borrower into its own loan is a fixed point that returns the maximum.

The loan is **joined** with what the act intrinsically does rather than substituted for it. A mild app lends a mild ceiling, and a ceiling below the act itself is a contradiction `Operation` refuses — `config:set` writes a file and cannot carry `Mutation::None`. Joining also keeps the loan monotone: it can only raise a ceiling, never excuse it.

This does not change whether `config:set` asks for consent. What changes is the honesty of the number — a derived ceiling drops when the app is milder, and a conservative one never drops.

## [0.17.1](https://github.com/getmilpa/app-runtime/releases/tag/v0.17.1) (2026-08-12)

`ConfigOperations` is registrable in `config/operations.php`.

The dispatcher builds each provider by handing it the container, and v0.17.0 declared `__construct(?string $root)` — so the registered path raised a TypeError. The failure is not a bad ceiling: **the whole catalogue stops building**, and the app loses every command it has.

It now declares no constructor, like `CapabilityOperations` and `FoundationOperations`; the seams move to `ConfigOperations::para()`. The regression test passes a container on purpose, because building the provider bare would pass while the registered path still raised.

## [0.17.0](https://github.com/getmilpa/app-runtime/releases/tag/v0.17.0) (2026-08-12)

**Agent configuration as a governed operation.**

`config` reads what the app runs on and names the keys two files declare at once. `config:set` writes one key through the governed path instead of a hand edit of `config/app.php`.

Writing carries a **borrowed ceiling**: the heaviest thing the criterion it edits can permit, because whoever edits the judge does not weigh less than what the judge governs (greenhouse `decisions/0027`). The number is derived from the catalogue rather than written by hand, so it moves when the catalogue moves instead of going stale in a constant.

Told nothing, it borrows from an empty catalogue — which GOV-05 makes the maximum of every dimension, so an instance that knows nothing asks for consent rather than skipping it.

## [0.16.1](https://github.com/getmilpa/app-runtime/releases/tag/v0.16.1) (2026-08-12)

Accepts `milpa/console ^0.9`, which resolves an operation's ceiling for the **call** rather than for the operation in the abstract — the change that makes the descent declared here on `capabilities:enable` actually take effect.

## [0.16.0](https://github.com/getmilpa/app-runtime/releases/tag/v0.16.0) (2026-08-12)

**`capabilities:enable` declares that its rehearsal does not weigh like the install.**

Wiring S2 (`console v0.8.0`) made `capabilities:enable --dry-run --json` ask for consent, and the prompt landed where JSON was expected. S2 judges the *operation*, not the *invocation* — a rehearsal carried the same ceiling as the real thing.

`command v0.8.0` adds the descent field for exactly this, and this is the first declaration:

```
dry_run=true → Mutation::None · Externality::None · Reversibility::Guaranteed · Authority::Read · Subject::None
```

Both halves of the reason are measurements, not adjectives: it leaves the disk untouched, and it succeeds inside an empty network namespace on a cold cache where the real install fails. That second one is why `Externality` comes down too — without it only `Mutation` could have, and the rehearsal would still ask permission over an axis nobody had checked.

Also accepts `milpa/command ^0.8`.

## [0.15.2](https://github.com/getmilpa/app-runtime/compare/v0.15.1...v0.15.2) (2026-08-12)


### Bug Fixes

* accept milpa/console ^0.8 so the wired S2 reaches an app ([#33](https://github.com/getmilpa/app-runtime/issues/33)) ([4b9ff7c](https://github.com/getmilpa/app-runtime/commit/4b9ff7cfd9e3ec1fdbc737cd7bf876a9da6bfc1c))

## [0.15.1](https://github.com/getmilpa/app-runtime/compare/v0.15.0...v0.15.1) (2026-08-12)


### Bug Fixes

* the catalogue declares its own ceiling ([#31](https://github.com/getmilpa/app-runtime/issues/31)) ([a838a2e](https://github.com/getmilpa/app-runtime/commit/a838a2ef074578fc53f34ccedad50e42a8d6994a))

## [0.15.0](https://github.com/getmilpa/app-runtime/compare/v0.14.0...v0.15.0) (2026-08-12)


### Features

* derive the borrowed ceiling of whoever edits the judge's criterion ([#29](https://github.com/getmilpa/app-runtime/issues/29)) ([ad7f51c](https://github.com/getmilpa/app-runtime/commit/ad7f51ca9ea97235f60aa9277bb7a029ab6e86ed))

## [0.14.0](https://github.com/getmilpa/app-runtime/compare/v0.13.0...v0.14.0) (2026-08-12)


### Features

* doctor names the keys both files declare ([#27](https://github.com/getmilpa/app-runtime/issues/27)) ([e658dbb](https://github.com/getmilpa/app-runtime/commit/e658dbb3d287af1bdbbf8e23e1df313a40995c39))

## [0.13.0](https://github.com/getmilpa/app-runtime/compare/v0.12.4...v0.13.0) (2026-08-12)


### Features

* what the machine wrote lays over what the human wrote ([#25](https://github.com/getmilpa/app-runtime/issues/25)) ([668202c](https://github.com/getmilpa/app-runtime/commit/668202c7911d1136f89e9ca87338165f698a0e76))

## [0.12.4](https://github.com/getmilpa/app-runtime/compare/v0.12.3...v0.12.4) (2026-08-12)


### Bug Fixes

* accept milpa/plugin ^0.11 so framework can reach it ([#23](https://github.com/getmilpa/app-runtime/issues/23)) ([94f0f99](https://github.com/getmilpa/app-runtime/commit/94f0f99fd001c9fc04955884c5b556f41fddf939))

## [0.12.3](https://github.com/getmilpa/app-runtime/compare/v0.12.2...v0.12.3) (2026-08-12)


### Bug Fixes

* a name the catalogue does not carry leaves a failing status ([#21](https://github.com/getmilpa/app-runtime/issues/21)) ([27f90ee](https://github.com/getmilpa/app-runtime/commit/27f90eebb0928d209617604dcf655d7643e9a6c5))

## [0.12.2](https://github.com/getmilpa/app-runtime/compare/v0.12.1...v0.12.2) (2026-08-11)


### Bug Fixes

* require milpa/command ^0.7, the only range this package can run in ([#19](https://github.com/getmilpa/app-runtime/issues/19)) ([0cc4756](https://github.com/getmilpa/app-runtime/commit/0cc47565cd1562c0165ac0a6942ae3081ba9d8e1))

## [0.12.1](https://github.com/getmilpa/app-runtime/compare/v0.12.0...v0.12.1) (2026-08-11)


### Bug Fixes

* the withdrawal list asks the projector for names instead of copying its rule ([#17](https://github.com/getmilpa/app-runtime/issues/17)) ([7780b6c](https://github.com/getmilpa/app-runtime/commit/7780b6c2b9d42082c6a0fc703f431e5f1d180013))

## [0.12.0](https://github.com/getmilpa/app-runtime/compare/v0.11.1...v0.12.0) (2026-08-09)


### Features

* **effect:** every operation here declares what its change is made of ([616e224](https://github.com/getmilpa/app-runtime/commit/616e2247321d261e45da9e2cd577914b7a9db9a5))

## [0.11.1](https://github.com/getmilpa/app-runtime/compare/v0.11.0...v0.11.1) (2026-08-09)


### Bug Fixes

* **deps:** reach milpa/command ^0.7, where the effect ceiling grew its fifth dimension ([6b5969a](https://github.com/getmilpa/app-runtime/commit/6b5969a307e11d6d7238abf4fe53c2e139061e96))

## [0.11.0](https://github.com/getmilpa/app-runtime/compare/v0.10.0...v0.11.0) (2026-08-09)


### Features

* capabilities and sessions publish what they return, so a caller can chain without guessing ([18aa9ee](https://github.com/getmilpa/app-runtime/commit/18aa9ee98c0a9a4bf6862906d86b7c7853427144))
* founding is an operation, so the rite can be run and therefore measured ([ebe3db9](https://github.com/getmilpa/app-runtime/commit/ebe3db990f8cbbff7909940b6bddc7b6a8e9c5ca))

## [0.10.0](https://github.com/getmilpa/app-runtime/compare/v0.9.0...v0.10.0) (2026-08-07)


### Features

* the guard wakes armed, the renewal orients, and the board carries its one write ([0d8ea85](https://github.com/getmilpa/app-runtime/commit/0d8ea852142bfcbc14f5563f0247c655bc3c1e53))

## [0.9.0](https://github.com/getmilpa/app-runtime/compare/v0.8.0...v0.9.0) (2026-08-06)


### Features

* the live Kanban board, the derived capability index, and code that lands through gates ([3520631](https://github.com/getmilpa/app-runtime/commit/3520631f0078951b4bff5c5ce6e69f907a3b67ba))

## [0.8.0](https://github.com/getmilpa/app-runtime/compare/v0.7.0...v0.8.0) (2026-08-05)


### Features

* **agent:** la contención es alcanzable por quien corre el agente, y se declara por clase ([1f8afa4](https://github.com/getmilpa/app-runtime/commit/1f8afa422173dafa2df4b39337cd314c5e08b9fb))

## [0.7.0](https://github.com/getmilpa/app-runtime/compare/v0.6.0...v0.7.0) (2026-08-05)


### Features

* **relay:** el SDK — especialistas en orden fijo, y el artefacto es el bastón ([23ca7a8](https://github.com/getmilpa/app-runtime/commit/23ca7a8f0aaad0f3fb4524883f9ec6bee16206a0))

## [0.6.0](https://github.com/getmilpa/app-runtime/compare/v0.5.0...v0.6.0) (2026-08-05)


### ⚠ BREAKING CHANGES

* un rol declarativo — el prompt sugiere, el resto gobierna

### Features

* un rol declarativo — el prompt sugiere, el resto gobierna ([84a76b0](https://github.com/getmilpa/app-runtime/commit/84a76b089d8b3f27bfd380587bc95f8ef5289dbb))

## [0.5.0](https://github.com/getmilpa/app-runtime/compare/v0.4.1...v0.5.0) (2026-08-05)


### Features

* **channel:** agent_message — el padre corrige a media tarea, y el hijo avisa sin terminar ([932907b](https://github.com/getmilpa/app-runtime/commit/932907b9ffd51c3e49d89bbe793beb38b9116ab6))

## [0.4.1](https://github.com/getmilpa/app-runtime/compare/v0.4.0...v0.4.1) (2026-08-05)


### Bug Fixes

* el reintento del artefacto llegaba sin el trabajo del hijo ([861f860](https://github.com/getmilpa/app-runtime/commit/861f8609b3d0be1faa4a25ac4afef9c04a0ac7a8))

## [0.4.0](https://github.com/getmilpa/app-runtime/compare/v0.3.0...v0.4.0) (2026-08-04)


### ⚠ BREAKING CHANGES

* milpa/agent vuelve a ser opcional — una app tiny volvió a ser tiny

### Bug Fixes

* milpa/agent vuelve a ser opcional — una app tiny volvió a ser tiny ([eb3c0c8](https://github.com/getmilpa/app-runtime/commit/eb3c0c8ebb717715fb24f5455ecb86b2bddd9f48))

## [0.3.0](https://github.com/getmilpa/app-runtime/compare/v0.2.0...v0.3.0) (2026-08-04)


### Features

* las operaciones, el catálogo de capacidades, los tokens y las dos superficies ([9142cc2](https://github.com/getmilpa/app-runtime/commit/9142cc25086e4b0200f8998f954de3f8813a9d31))

## [0.2.0](https://github.com/getmilpa/app-runtime/compare/v0.1.0...v0.2.0) (2026-08-04)


### Features

* el runtime del agente que una app instala, en vez de copiar ([7dc02b1](https://github.com/getmilpa/app-runtime/commit/7dc02b13276f2b99efa3d3ae7d2f63db7216d64b))
