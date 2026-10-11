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

namespace Milpa\AppRuntime\Tests\Standing\Plugins\Shelf;

use Milpa\AppRuntime\Agent\DeclaresWorkState;
use Milpa\AppRuntime\Standing\StandingLedger;
use Milpa\Command\CommandProvider;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;

/**
 * A plugin of the domain for the tests of a leg — under `Plugins\<Name>`, where the house looks for whoever
 * provides work — with two acts a grant may cover that a leg does NOT run through the
 * handler its registry holds.
 *
 *   shelf:lend   work in the domain by what it declares — data, nothing leaves the house, a state it names — so a
 *                leg runs it in the house, confined, in another process;
 *   shelf:label  a mutation that is not work — configuration — so a leg rehearses it in a copy of the house.
 *
 * Each says, in what it leaves behind, how many `absorbed` lines the book of the tree it ran in held WHEN IT RAN:
 * that is how a test reads whether the house wrote the act down before it ran. Three arguments make an act misbehave
 * on purpose: `quiet` (it does not say what it consumed), `seal` (it leaves the book unwritable) and `crash` (the
 * process that runs it dies without an answer).
 */
final class LabShelf implements CommandProvider, DeclaresWorkState
{
    /** Called when the house asks where the work keeps its state — after the gate judged the call, before it runs. */
    public ?\Closure $between = null;

    public function __construct(private readonly string $root)
    {
    }

    public function workState(): array
    {
        if ($this->between !== null) {
            ($this->between)();
        }

        return ['shelf:lend' => ['storage/shelf']];
    }

    public function operations(): array
    {
        $root = $this->root;
        $actsOn = static fn (array $input): ?string => is_file($root . '/' . ($input['path'] ?? '')) ? 'sha256:' . hash_file('sha256', $root . '/' . $input['path']) : null;
        $schema = ['type' => 'object', 'properties' => ['path' => ['type' => 'string'], 'quiet' => ['type' => 'boolean'], 'seal' => ['type' => 'boolean'], 'crash' => ['type' => 'boolean']], 'required' => ['path']];
        $act = static function (string $into, array $input) use ($root): array {
            $bytes = (string) file_get_contents($root . '/' . $input['path']);
            $absorbed = 0;
            foreach (@file($root . '/' . StandingLedger::PATH) ?: [] as $line) {
                $absorbed += (json_decode($line, true)['type'] ?? null) === StandingLedger::ABSORBED ? 1 : 0;
            }
            @mkdir(\dirname($root . '/' . $into), 0o755, true);
            file_put_contents($root . '/' . $into, hash('sha256', $bytes) . ' absorbed-when-it-ran=' . $absorbed . "\n", \FILE_APPEND);

            if (($input['seal'] ?? false) === true) {
                // `seal`: after the act, the book can no longer be written — what the house then finds, it cannot note.
                chmod($root . '/' . StandingLedger::PATH, 0o400);
            }
            if (($input['crash'] ?? false) === true) {
                // `crash`: the process that runs the act dies after acting, with no answer at all.
                exit(3);
            }

            // `quiet`: an act that does not say what it consumed — which, at the house's boundary, suspends the grant.
            return ($input['quiet'] ?? false) === true ? ['ok' => true] : ['ok' => true, 'acted_on' => 'sha256:' . hash('sha256', $bytes)];
        };

        return [
            new Operation(
                name: 'shelf:lend',
                description: 'Lab: lend an item from the shelf — work in the domain',
                handler: static fn (array $input): array => $act('storage/shelf/log.txt', $input),
                inputSchema: $schema,
                mutating: true,
                scopes: ['shelf:write'],
                effects: new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::Compensatable, Authority::WriteAsUser, subject: Subject::Data),
                actsOn: $actsOn,
            ),
            new Operation(
                name: 'shelf:label',
                description: 'Lab: label the shelf — a mutation a leg rehearses in a copy',
                handler: static fn (array $input): array => $act('labels/log.txt', $input),
                inputSchema: $schema,
                mutating: true,
                scopes: ['shelf:write'],
                effects: new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::Compensatable, Authority::WriteAsUser, subject: Subject::Configuration),
                actsOn: $actsOn,
            ),
        ];
    }
}
