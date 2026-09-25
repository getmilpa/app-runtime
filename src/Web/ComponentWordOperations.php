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

namespace Milpa\AppRuntime\Web;

use Milpa\Command\CommandProvider;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;

/**
 * How a house learns a word (greenhouse decisions/0465): `component:define` adds a named, typed
 * composition of the components it already registers to THIS house's visual language, and
 * `component:forget` retires it.
 *
 * Defining is authorship: it writes the versioned `config/components.json`, so in a governed session
 * it is rehearsed in a trial and promoted like any other work, with its own authority. What it can
 * never do is carry markup — see {@see ComponentWords}.
 */
final class ComponentWordOperations implements CommandProvider
{
    /** The authority that may add to, or take from, a house's visual language. */
    public const SCOPE = 'milpa:component:define';

    public function __construct(
        private readonly ComponentWords $words,
        /** @var \Closure(): ?ScreenComponents live registry resolver */
        private readonly \Closure $registry,
    ) {
    }

    /**
     * `component:define` and `component:forget`.
     *
     * @return list<Operation>
     */
    public function operations(): array
    {
        return [
            new Operation(
                name: 'component:define',
                description: 'Add a word to THIS house\'s visual language when no component expresses what you need: a name, what it is for, typed inputs, and a composition of components the house already has (discover them with screen:types) with "$input" where an input goes. It never carries markup. Afterwards any session uses it with screen:declare type: <name>, props: {<inputs>}.',
                handler: fn (array $input): array => $this->define($input),
                inputSchema: [
                    'type' => 'object',
                    'required' => ['name', 'summary', 'inputs', 'composition'],
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'the new word: a-z, 0-9, dashes; not a component the house already has'],
                        'summary' => ['type' => 'string', 'description' => 'what it expresses — every later session reads this to decide whether to use it'],
                        'inputs' => ['type' => 'object', 'description' => '{<input>: {type: string|integer|number|boolean, required?: bool, description?: string}}'],
                        'composition' => ['type' => 'object', 'description' => 'a screen tree of existing components: {type, props}; containers take props.children: [{type, props}]; put "$<input>" as a whole prop value where an input goes'],
                    ],
                ],
                mutating: true,
                scopes: [self::SCOPE],
                effects: new EffectProfile(
                    Mutation::Persistent,
                    Externality::None,
                    Reversibility::Guaranteed,
                    subject: Subject::Configuration,
                    rollbackContract: 'component:forget',
                ),
            ),
            new Operation(
                name: 'component:forget',
                description: 'Retire a word from this house\'s visual language. Screens already declared with it keep what they compiled to.',
                handler: fn (array $input): array => $this->words->forget((string) ($input['name'] ?? '')),
                inputSchema: [
                    'type' => 'object',
                    'required' => ['name'],
                    'properties' => ['name' => ['type' => 'string', 'description' => 'the word to retire']],
                ],
                mutating: true,
                scopes: [self::SCOPE],
                namedTarget: 'name',
                effects: new EffectProfile(
                    Mutation::Persistent,
                    Externality::None,
                    Reversibility::Compensatable,
                    subject: Subject::Configuration,
                    rollbackContract: 'define the word again with component:define',
                ),
            ),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function define(array $input): array
    {
        $registry = ($this->registry)();
        if ($registry === null) {
            return ['ok' => false, 'error' => 'the live screen registry is not mounted, so no component can be composed'];
        }

        // Each primitive's contract travels with the definition, so a word is judged against what its
        // components actually receive (greenhouse decisions/0470).
        $schemas = [];
        foreach ($registry->primitives() as $type) {
            if ($registry->has($type)) {
                $schemas[$type] = $registry->get($type)::contract()->propsSchema;
            }
        }

        return $this->words->define($input, $registry->primitives(), $schemas);
    }
}
