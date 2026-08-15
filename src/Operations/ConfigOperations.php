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
        return [
            new Operation(
                name: 'config',
                description: 'The agent configuration this app runs on, and which keys two files declare at once',
                handler: fn (array $input): array => $this->show(),
                inputSchema: ['type' => 'object', 'properties' => [], 'required' => []],
                effects: new EffectProfile(
                    mutation: Mutation::None,
                    externality: Externality::None,
                    reversibility: Reversibility::Guaranteed,
                    authority: Authority::Read,
                    subject: Subject::None,
                    rollbackContract: 'reads only: there is nothing to roll back',
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
                        'value' => ['description' => 'The value to write. Anything JSON can carry.'],
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
                // `key` escalates: until it is known, this ceiling is provisional, and a caller can
                // ask `unresolvedEscalators()` whether the ceiling is still the ceiling.
                // THE LOAN IS JOINED WITH WHAT THIS ACT DOES, never substituted for it.
                //
                // A mild app lends a mild ceiling, and a ceiling below the act itself is a
                // contradiction `Operation` refuses outright — this operation writes a file, so it
                // cannot carry `Mutation::None` no matter how gentle the catalogue is. Joining also
                // keeps the loan monotone: it can only raise this ceiling, never excuse it, which is
                // what makes borrowing safe at all (GOV-14).
                effects: self::loQueEscribeHace()->join(JudgeCeiling::prestado($this->operations)),
            ),
        ];
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

        $archivo = $this->raiz() . MachineOverlay::RUTA;
        $actual = is_file($archivo) ? json_decode((string) file_get_contents($archivo), true) : [];
        $actual = \is_array($actual) ? $actual : [];

        // The dotted path becomes nesting, and nothing else in the file is touched: writing
        // agent.instructions must not evaporate the agent.compaction nobody mentioned.
        $ref = &$actual;
        foreach (explode('.', $llave) as $parte) {
            if (! isset($ref[$parte]) || ! \is_array($ref[$parte])) {
                $ref[$parte] = [];
            }
            $ref = &$ref[$parte];
        }
        $ref = $input['value'] ?? null;
        unset($ref);

        @mkdir(\dirname($archivo), 0o777, true);
        file_put_contents($archivo, json_encode($actual, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n");

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
