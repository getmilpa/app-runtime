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

use Milpa\AppRuntime\Config\JudgeCeiling;
use Milpa\AppRuntime\Support\Capabilities;
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
final class ConfigOperations implements CommandProvider
{
    /**
     * Both arguments are seams, and their defaults are the safe end.
     *
     * The root resolves the way this family already resolves it, so a bare `new ConfigOperations()`
     * works where every other provider does. And with NO catalogue the borrowed ceiling folds over
     * nothing, which GOV-05 makes the maximum of every dimension — so an instance that was told
     * nothing asks for consent rather than skipping it. Failing upwards, again.
     */
    public function __construct(private readonly ?string $root = null, private readonly array $operations = [])
    {
    }

    private function raiz(): string
    {
        return $this->root ?? Capabilities::raizDeLaApp();
    }

    /** @return list<Operation> */
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
                effects: JudgeCeiling::prestado($this->operations),
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

        return [
            'ok' => true,
            'key' => $llave,
            'written_to' => MachineOverlay::RUTA,
            'governs_the_judge' => JudgeCeiling::esCriterioDelJuez($llave),
            'hint' => 'run `coa config` to see it, and `coa doctor` if config/app.php declares it too',
        ];
    }
}
