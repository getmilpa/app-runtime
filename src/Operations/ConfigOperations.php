<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app installs, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Operations;

use Milpa\AppRuntime\Config\AgentKeys;
use Milpa\AppRuntime\Config\JudgeCeiling;
use Milpa\AppRuntime\Support\Capabilities;
use Milpa\AppRuntime\Support\CatalogueBorrower;
use Milpa\AppRuntime\Config\MachineOverlay;
use Milpa\AppRuntime\Config\SecretOverlay;
use Milpa\Command\CommandProvider;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;

/**
 * Changing this app's agent configuration as a governed act, instead of by editing a PHP file.
 *
 * greenhouse evidence/0144 measured the shape of the problem: `config/app.php` documents four of the
 * seventeen keys the code reads, and two of them are not preferences at all — they are parameters of
 * the gate itself, one of which turns a PASS into a REFUSAL. So whoever edits by hand does not know
 * what they are touching, and the file does not tell them.
 *
 * TWO OPERATIONS AND NOT ONE, because decisions/0027 measured that they carry different ceilings:
 * reading is a read, and writing a criterion of the judge borrows the ceiling of the heaviest thing
 * that criterion can permit. Shipping them as one would have meant a single declared ceiling for two
 * different acts — the defect decisions/0018 named, a field answering how much when the question was
 * of what.
 */
final class ConfigOperations implements CommandProvider, CatalogueBorrower
{
    /**
     * NO CONSTRUCTOR, and that is the point rather than an omission.
     *
     * `config/operations.php` is a list of class-strings, and the dispatcher builds each provider by
     * handing it the container. A class with no constructor takes that call and ignores it, which is
     * exactly how `CapabilityOperations` and `FoundationOperations` are registrable. A class that
     * declares `__construct(?string $root)` does NOT: it raises a TypeError, and the failure is not a
     * bad ceiling — the whole catalogue stops building, so the app loses every command it has. That
     * is what this class cost the skeleton before it was measured there.
     *
     * The seams live in `para()` instead, where they are named at the call site.
     */
    private ?string $root = null;

    /** @var list<Operation> the catalogue whose ceiling `config:set` borrows */
    private array $operations = [];

    /**
     * The seams, for a caller that has a root and a catalogue to hand — tests, and whoever
     * eventually gives this operation the real one.
     *
     * The defaults are the safe end. With NO catalogue the borrowed ceiling folds over nothing,
     * which GOV-05 makes the maximum of every dimension, so an instance that was told nothing asks
     * for consent rather than skipping it. Failing upwards, again.
     *
     * @param list<Operation> $operations
     */
    public static function para(?string $root = null, array $operations = []): self
    {
        $proveedor = new self();
        $proveedor->root = $root;
        $proveedor->operations = $operations;

        return $proveedor;
    }

    /**
     * The same provider, now holding the catalogue whose ceiling `config:set` borrows.
     *
     * Built from `config/operations.php` this provider receives nothing, because it is built in
     * order to PRODUCE that catalogue. `Operations::withBorrowedCeilings()` asks again once the
     * catalogue is complete, and what it hands over excludes this provider's own operations.
     *
     * @param list<Operation> $catalogue
     */
    public function withCatalogue(array $catalogue): self
    {
        return self::para($this->root, $catalogue);
    }

    /**
     * What `config:set` does on its own, before borrowing anything.
     *
     * It writes one key into a file this app reads at boot: a persistent change to CONFIGURATION,
     * under the authority of whoever runs the app, and undone by writing the previous value back.
     */
    private static function loQueEscribeHace(): EffectProfile
    {
        return new EffectProfile(
            mutation: Mutation::Persistent,
            externality: Externality::None,
            reversibility: Reversibility::Compensatable,
            authority: Authority::WriteAsUser,
            subject: Subject::Configuration,
            rollbackContract: 'write the previous value back through the same operation',
        );
    }

    private function raiz(): string
    {
        return $this->root ?? Capabilities::raizDeLaApp();
    }

    /**
     * The two operations: read the configuration, and write one key through the governed path.
     *
     * Reading is free and writing is not. `config:set` carries a BORROWED ceiling — the heaviest
     * thing the criterion it edits can permit — because whoever edits the judge does not weigh less
     * than what the judge governs. That number is derived from the catalogue rather than written
     * here, so it moves when the catalogue moves instead of going stale in a constant.
     *
     * @return list<Operation>
     */
    public function operations(): array
    {
        return $this->build(self::loQueEscribeHace()->join(JudgeCeiling::prestado($this->operations)));
    }

    /**
     * The same two operations with `config:set` at what writing a key does ALONE — the seed the loan
     * is solved from (greenhouse decisions/0224). `config` is a read either way.
     *
     * @return list<Operation>
     */
    public function operationsAtTheFloor(): array
    {
        return $this->build(self::loQueEscribeHace());
    }

    /** @return list<Operation> */
    private function build(EffectProfile $ceilingOfSet): array
    {
        return [
            new Operation(
                name: 'config',
                description: 'The agent configuration this app runs on, and which keys two files declare at once',
                handler: fn (array $input): array => $this->show(),
                inputSchema: ['type' => 'object', 'properties' => [], 'required' => []],
                effects: EffectProfile::readOnly(),
            ),
            new Operation(
                name: 'provider:declare',
                description: 'Declare a provider credential where it can be read and never committed — the value is written, never echoed',
                handler: fn (array $input): array => $this->declareSecret($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'key' => ['type' => 'string', 'description' => 'Dotted path the code already reads — e.g. agent.apiKey'],
                        'value' => ['type' => 'string', 'description' => 'The credential. It is written and never returned, printed or logged'],
                        'forget' => ['type' => 'boolean', 'description' => 'Remove the declaration instead of writing one'],
                    ],
                    'required' => ['key'],
                ],
                // 🚨 `mutating: true`, SAID TWICE BECAUSE TWO THINGS READ IT.
                //
                // The effects profile below says `mutation: persistent`, and this flag says the same
                // fact again — because the signature gate reads THE FLAG, not the profile. Declared
                // with the ceiling alone, this operation wrote a credential with no signature at all
                // while `config:set`, whose ceiling is lighter, demanded one. Measured on cattle:
                // `mutating: no` in its own contract, beside `mutation: persistent`
                // (greenhouse decisions/0267).
                //
                // A falsifier now holds the two in agreement for every operation this package
                // declares, because a fact with two sources is a fact that will disagree.
                mutating: true,
                // 🚨 THE SCOPE, AND ITS ABSENCE WAS A HOLE I SHIPPED. Measured on cattle: exposed over
                // HTTP, two same-origin POSTs with NO SESSION, no identity and no signature wrote a
                // provider credential — a `428` handed out a confirm token, the token came back, `201
                // Created`, the key on disk. The framework's boot guard refuses to expose an operation
                // that «demands identity» without a policy to judge it, and it reads exactly two
                // things: `scopes` and `permission`. This one declared neither, so the guard walked
                // past it and the HTTP surface downgraded «needs your signature» to «needs a token I
                // will hand you» (greenhouse decisions/0274).
                //
                // The reasoning was already written in this house, on `identity:enroll`: «the gate
                // enforces this on a permission-aware surface … WITHOUT IT THE DOOR WOULD BE OPEN ON
                // HTTP». Same shape, same fix — the scope is the operation's own name, as that one's
                // is.
                //
                // `requiresConfirmation` below is NOT a substitute and never was: it is what the CLI
                // reads to demand a signature. A gate that is right on one surface and absent on the
                // other is a gate at the height of the lower one.
                scopes: ['provider:declare'],
                // 🚨 `requiresConfirmation`, AND THE AXES ARE WHY — not in spite of them.
                //
                // Rule S2 demands consent when subject >= Executable AND authority >= Privileged.
                // Declaring a credential is honestly `Configuration`: the same classes keep loading,
                // they act differently — and the axis's own docblock says that level is «neither the
                // kind of act a signature is for», naming founding an app as its neighbour.
                // `capabilities:enable` is signed because it is `Executable`: it changes WHICH CODE
                // WILL RUN. A key is not an install, and pretending its subject is executable to
                // borrow the gate would put a lie in the ceiling to get the behaviour.
                //
                // THE EFFECT AXES MEASURE THE CHANGE TO THIS HOUSE, AND A CREDENTIAL'S DANGER IS ALL
                // OUTSIDE IT. `externality: none` is correct — writing the file calls nobody — and
                // that correctness is exactly why the axes cannot see what this act hands over: the
                // power to act as somebody at another service, and to spend whatever it charges.
                // Nothing measurable about the local change reflects that.
                //
                // So this is what the flag exists for. `Consent::demanded()` reads it FIRST, before
                // S2, which is the declared way to say «this one asks, wherever the axes land»
                // (greenhouse decisions/0267). Measured on cattle: with the ceiling alone it wrote a
                // credential with no signature at all.
                requiresConfirmation: true,
                // TWO AXES DIFFER FROM `config:set`, AND BOTH ON PURPOSE.
                //
                // `authority: privileged` — declaring a credential is not writing a preference. It
                // gives this app the ability to act as you at somebody else's service, and spend
                // whatever that service charges. A house that classified it as `write_as_user`
                // would let it through the same gate as a compaction setting.
                //
                // `reversibility: manual_recovery` — `config:set` is compensatable because the
                // previous value can be written back. Here it CANNOT: nothing in this framework can
                // read a secret out, by design, so overwriting one destroys the only copy. Getting
                // it back means getting a new key from the provider, which is recovery by hand.
                //
                // `externality: none` is honest and worth saying out loud: writing this file reaches
                // nobody. It ENABLES egress later — `agent:model` declares that egress when it goes
                // out — and conflating «I stored a key» with «I called somebody» would put every
                // provider's uptime inside this operation's ceiling (greenhouse decisions/0267).
                effects: new EffectProfile(
                    mutation: Mutation::Persistent,
                    externality: Externality::None,
                    reversibility: Reversibility::ManualRecovery,
                    authority: Authority::Privileged,
                    escalatesOn: ['id'],
                    subject: Subject::Configuration,
                    rollbackContract: 'declare a new credential from the provider; the previous one cannot be read back',
                ),
            ),
            new Operation(
                name: 'config:set',
                description: 'Write one agent configuration key through the governed path, instead of editing config/app.php',
                handler: fn (array $input): array => $this->set($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'key' => ['type' => 'string', 'description' => 'Dotted path, as Config::get asks for it — e.g. agent.instructions'],
                        'value' => ['description' => 'The value to write. Declared agent keys enforce the type shown by `coa config`.'],
                    ],
                    'required' => ['key', 'value'],
                ],
                mutating: true,
                // THE CEILING IS BORROWED, AND IT IS NOT WRITTEN HERE (decisions/0027).
                //
                // This operation can write a criterion of the judge, so it carries the ceiling of the
                // heaviest thing that criterion can permit — a child does not exceed its parent, and
                // whoever edits the judge does not weigh less than what the judge governs. Written by
                // hand it would be a number somebody guessed; derived, it moves when the catalogue
                // moves.
                //
                // THE LOAN IS JOINED WITH WHAT THIS ACT DOES, never substituted for it.
                //
                // A mild app lends a mild ceiling, and a ceiling below the act itself is a
                // contradiction `Operation` refuses outright — this operation writes a file, so it
                // cannot carry `Mutation::None` no matter how gentle the catalogue is. Joining also
                // keeps the loan monotone: it can only raise this ceiling, never excuse it, which is
                // what makes borrowing safe at all (GOV-14).
                // 🚨 THE SCOPE, AND ITS ABSENCE LET SOMEBODY REDIRECT WHERE THE AGENT TALKS. Measured
                // on cattle WITH an `OperationHttpPolicy` registered: two same-origin POSTs with no
                // session, no principal and no signature set `agent.baseUrl` — so every prompt and
                // every piece of context the agent sends would go to the caller's server
                // (greenhouse decisions/0278).
                //
                // A POLICY CAN ONLY JUDGE WHAT AN OPERATION DECLARES. This one declared nothing to
                // judge: its consent is not a flag but a DERIVATION — rule S2 over the ceiling it
                // borrows from the catalogue — so the CLI demanded `--sign` while the HTTP surface
                // handed out a confirm token and the policy had no scope to match. An operation that
                // demands consent and declares no scope is UNJUDGEABLE, and a policy being present
                // does not change that.
                //
                // `config:write` and not `config:set`: the scope names the ACT, and the same authority
                // should gate any operation that writes this app's configuration rather than one
                // command's spelling.
                scopes: ['config:write'],
                effects: $ceilingOfSet,
            ),
        ];
    }

    /**
     * DECLARE A PROVIDER CREDENTIAL — written where the code reads, never where git looks.
     *
     * A Milpa app had nowhere to put one. Measured on `milpa/framework`: it ships no `.env`, loads
     * no `.env`, and does not ignore one; its two config homes are both committed. So this operation
     * could not exist until {@see SecretOverlay} did, and building it first would have made the house
     * say «declared» about a value that either went to git or that no request could read — both
     * refutation conditions of the pin's own rung (greenhouse decisions/0267).
     *
     * IT REFUSES BEFORE IT LEAKS. If the app's `.gitignore` does not ignore the secrets file, this
     * writes NOTHING and says which line is missing. A credential written into a repository that
     * would commit it is not a mistake to warn about after the fact: the value is already in the
     * working tree by then, and the honest moment to stop is before.
     *
     * AND IT NEVER ECHOES THE VALUE. The result names the path and says it was declared. An
     * operation that returned what it wrote would put a key in a terminal's scrollback, an event
     * ledger and whatever surface projected the result — which is the whole reason
     * {@see SecretOverlay} has no reader that can print one.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function declareSecret(array $input): array
    {
        $key = \is_string($input['key'] ?? null) ? trim($input['key']) : '';
        if ($key === '' || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)*$/', $key) !== 1) {
            return ['ok' => false, 'error' => '`key` must be a dotted configuration path, as Config::get asks for it — e.g. agent.apiKey'];
        }

        $root = $this->raiz();
        $missing = self::gitignoreMissing($root);
        if ($missing !== null) {
            // Refused, and it says the exact line rather than «configure your gitignore».
            return [
                'ok' => false,
                'error' => 'nothing was written: this app would commit its secrets file',
                'add_to_gitignore' => $missing,
            ];
        }

        $forget = ($input['forget'] ?? false) === true;
        $value = $input['value'] ?? null;
        if (!$forget && (!\is_string($value) || $value === '')) {
            return ['ok' => false, 'error' => '`value` is required unless `forget` is true — a credential declared empty is a credential nobody can use'];
        }

        $file = $root . SecretOverlay::RUTA;
        $held = \is_array($read = json_decode((string) @file_get_contents($file), true)) ? $read : [];
        $held = $forget ? self::forget($held, explode('.', $key)) : self::put($held, explode('.', $key), (string) $value);

        if (!is_dir(\dirname($file)) && !@mkdir(\dirname($file), 0o700, true)) {
            return ['ok' => false, 'error' => 'nothing was written: ' . \dirname(SecretOverlay::RUTA) . ' could not be created'];
        }
        // 0600 BEFORE THE BYTES, not after. Writing world-readable and then narrowing leaves a window
        // in which the key is readable by anything on the machine, and that window is exactly when a
        // backup or a watcher would read it.
        $handle = @fopen($file, 'w');
        if ($handle === false) {
            return ['ok' => false, 'error' => 'nothing was written: ' . ltrim(SecretOverlay::RUTA, '/') . ' could not be opened'];
        }
        @chmod($file, 0o600);
        fwrite($handle, (string) json_encode($held, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n");
        fclose($handle);

        return [
            'ok' => true,
            'key' => $key,
            'declared' => !$forget,
            'written_to' => ltrim(SecretOverlay::RUTA, '/'),
            'holds' => SecretOverlay::declared($root),
            'note' => $forget
                ? 'the declaration is gone; the provider still knows the credential, so revoke it there too'
                : 'the value was written and is not returned by anything — no surface of this framework can read it back',
        ];
    }

    /**
     * The `.gitignore` line this app is missing, or `null` when the secrets file is already ignored.
     *
     * Asked of `git` itself rather than by reading the file, because `.gitignore` composes — a global
     * one, a parent directory's, `info/exclude` — and a reader that only parsed the local file would
     * refuse an app that was already safe. Without git available the answer is «missing», which
     * refuses: an app whose ignore rules cannot be verified is not an app to write a key into.
     */
    private static function gitignoreMissing(string $root): ?string
    {
        $probe = ltrim(SecretOverlay::RUTA, '/');
        if (!is_dir($root . '/.git')) {
            // Not a repository: nothing would commit it, so nothing is missing.
            return null;
        }
        $status = 1;
        @exec('git -C ' . escapeshellarg($root) . ' check-ignore -q ' . escapeshellarg($probe) . ' 2>/dev/null', $_, $status);

        return $status === 0 ? null : SecretOverlay::IGNORE_LINE;
    }

    /**
     * @param array<string, mixed> $tree
     * @param list<string>         $path
     *
     * @return array<string, mixed>
     */
    private static function put(array $tree, array $path, string $value): array
    {
        $key = array_shift($path);
        if ($path === []) {
            $tree[$key] = $value;

            return $tree;
        }
        $tree[$key] = self::put(\is_array($tree[$key] ?? null) ? $tree[$key] : [], $path, $value);

        return $tree;
    }

    /**
     * @param array<string, mixed> $tree
     * @param list<string>         $path
     *
     * @return array<string, mixed>
     */
    private static function forget(array $tree, array $path): array
    {
        $key = array_shift($path);
        if (!\array_key_exists($key, $tree)) {
            return $tree;
        }
        if ($path === []) {
            unset($tree[$key]);

            return $tree;
        }
        if (\is_array($tree[$key])) {
            $tree[$key] = self::forget($tree[$key], $path);
            if ($tree[$key] === []) {
                unset($tree[$key]);
            }
        }

        return $tree;
    }

    /** @return array<string, mixed> */
    private function show(): array
    {
        $delHumano = \is_array($c = @include $this->raiz() . '/config/app.php') ? $c : [];

        return [
            'ok' => true,
            'config' => MachineOverlay::sobre($delHumano, $this->raiz())['agent'] ?? [],
            'declared_twice' => MachineOverlay::divergencias($delHumano, $this->raiz()),
            'written_by_the_machine' => is_file($this->raiz() . MachineOverlay::RUTA),

            // WHICH PATHS HOLD A CREDENTIAL — never what they hold.
            //
            // The report was BLIND to this: measured on cattle with a key in place, «what is this app
            // configured with» answered without one sign that a credential existed. A screen deciding
            // whether to open a provider wizard needs exactly this fact, and it is the whole read
            // surface {@see SecretOverlay} offers — «there is a key» or «there is none». Nothing here
            // can reach the value, which is why the answer is safe to print, log and screenshot
            // (greenhouse decisions/0267).
            'holds_secrets' => SecretOverlay::declared($this->raiz()),

            // WHICH KEYS EXIST, because an agent that cannot see the knobs is being asked to know
            // the architecture — the same thing scaffolding exists to remove. greenhouse
            // evidence/0155: the code reads seventeen, the template documents four, and a newborn
            // ships two, none of them the agent's.
            'keys' => AgentKeys::todas(),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function set(array $input): array
    {
        $llave = (string) ($input['key'] ?? '');
        if ($llave === '') {
            return ['ok' => false, 'error' => 'a key is required — the dotted path Config::get asks for'];
        }

        try {
            // AgentKeys is the type authority for both the catalogue and this write. A second map
            // here would let the displayed contract and the persisted value drift independently.
            $value = AgentKeys::coerceDeclaredValue($llave, $input['value'] ?? null);
        } catch (\InvalidArgumentException $error) {
            return ['ok' => false, 'error' => $error->getMessage()];
        }

        $archivo = $this->raiz() . MachineOverlay::RUTA;
        $decoded = is_file($archivo) ? json_decode((string) file_get_contents($archivo)) : null;
        $actual = $decoded instanceof \stdClass ? $decoded : new \stdClass();

        // Decode the document as objects so an untouched `{}` cannot silently become `[]` when a
        // sibling is written. The dotted path changes only its leaf.
        $parts = explode('.', $llave);
        $leaf = (string) array_pop($parts);
        $cursor = $actual;
        foreach ($parts as $part) {
            if (!isset($cursor->{$part}) || !$cursor->{$part} instanceof \stdClass) {
                $cursor->{$part} = new \stdClass();
            }
            $cursor = $cursor->{$part};
        }
        $cursor->{$leaf} = $value;

        try {
            $encoded = json_encode(
                $actual,
                \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
            );
        } catch (\JsonException $error) {
            return [
                'ok' => false,
                'error' => "Configuration key '{$llave}' cannot be written as JSON: {$error->getMessage()}",
            ];
        }

        @mkdir(\dirname($archivo), 0o777, true);
        file_put_contents($archivo, $encoded . "\n");

        $respuesta = [
            'ok' => true,
            'key' => $llave,
            // RELATIVA A LA RAÍZ DE LA APP, y sin la diagonal de adelante.
            //
            // `MachineOverlay::RUTA` es un fragmento que se concatena a la raíz, y anunciarlo tal cual
            // dice `/.milpa/agent.json` — que se lee como absoluta y manda a buscar a la raíz del
            // SISTEMA. Medido en una sesión de chat real: la escritura estuvo bien y la frase mal, que
            // es la peor forma de estar mal porque parece ayuda y cuesta una búsqueda
            // (greenhouse evidence/0199).
            'written_to' => ltrim(MachineOverlay::RUTA, '/'),
            'governs_the_judge' => JudgeCeiling::esCriterioDelJuez($llave),
            'hint' => 'run `coa config` to see it, and `coa doctor` if config/app.php declares it too',
        ];

        // AN UNKNOWN KEY IS REPORTED AND STILL WRITTEN, and that is a decision rather than an
        // omission (greenhouse evidence/0155). This runtime speaks only for its own keys: a plugin
        // declares its own and this list cannot know them, so refusing would break a legitimate app
        // in order to punish a typo — and the write is already governed by consent, so what is
        // missing here is not another lock but the caller knowing what exists.
        if (! AgentKeys::conocida($llave)) {
            $respuesta['unknown_key'] = true;
            $respuesta['hint'] = 'this runtime does not declare that key — it was written anyway, '
                . 'since a plugin may. Run `coa config` for the ones it does declare.';
        }

        return $respuesta;
    }
}
