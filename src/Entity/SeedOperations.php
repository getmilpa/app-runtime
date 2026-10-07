<?php

/**
 * This file is part of Milpa App Runtime — the application runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Entity;

use Milpa\AppRuntime\Support\AppRoot;
use Milpa\Command\CommandProvider;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Interfaces\Di\DIContainerInterface;

/**
 * `entity:seed`: the governed way to leave the rows a work is born with (greenhouse decisions/0574, slice BV-4).
 *
 * It DECLARES rows ({@see SeedDeclarations}) and seeds them where it runs. For a seat that is a trial: the copy's
 * store, so the rehearsal proves the rows save — and the house's own store is written when the declaration is
 * promoted. The authority is the plugin's: the declaration is a file of its tree, so `plugins.<Plugin>:write`
 * is asked where it crosses, and nothing here grants a way around it.
 */
final class SeedOperations implements CommandProvider
{
    public function __construct(private readonly DIContainerInterface $container, private readonly ?string $root = null)
    {
    }

    /**
     * The one operation this contributes: `entity:seed`.
     *
     * @return list<Operation>
     */
    public function operations(): array
    {
        return [new Operation(
            name: 'entity:seed',
            description: 'Leave the rows a work is born with: declare rows of an entity (e.g. one row that is public and one that is not) and the house seeds them once. Use this instead of writing rows in a plugin\'s boot(), a seeder class or a sequence. Each row is an object of the entity\'s fields, without id. The declaration is a file of the plugin (src/Plugins/<Plugin>/Seeds/<Entity>.json): in a trial the copy is seeded, and the house when it is promoted.',
            handler: fn (array $input): array => $this->seed($input),
            inputSchema: [
                'type' => 'object',
                'required' => ['entity', 'rows'],
                'properties' => [
                    'entity' => ['type' => 'string', 'description' => 'The entity by its short name, e.g. <Entity> — or <Plugin>/<Entity> when two plugins have one'],
                    'rows' => [
                        'type' => 'array',
                        'items' => ['type' => 'object'],
                        'description' => 'The rows to seed, each an object of the entity\'s fields without id, e.g. [{"<field>": <value>, …}]. A row already declared is not added twice',
                    ],
                ],
            ],
            mutating: true,
            scopes: ['plugins:write'],
            effects: new EffectProfile(
                Mutation::Persistent,
                Externality::None,
                // Undoing the promotion returns the declaration; the rows it seeded stay. Data has no pre-image.
                Reversibility::ManualRecovery,
                Authority::WriteAsUser,
                subject: Subject::Data,
            ),
            observableEvidence: 'the `seeded` receipt — declared, added, already — and the entity\'s rows on the page that lists them',
        )];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function seed(array $input): array
    {
        $rows = $input['rows'] ?? null;
        if (\is_string($rows)) {
            $rows = json_decode($rows, true);
        }
        $seeds = new SeedDeclarations($this->root ?? AppRoot::of($this->container, 'entity:seed'));
        $declared = $seeds->declare(\is_string($input['entity'] ?? null) ? trim($input['entity']) : '', $rows);
        if ($declared['ok'] !== true) {
            return $declared;
        }
        $seeded = $seeds->apply([$declared['file']], fn (string $id): ?object => $this->container->has($id) && \is_object($service = $this->container->get($id)) ? $service : null);

        return $declared + ['seeded' => $seeded[0] ?? null];
    }
}
