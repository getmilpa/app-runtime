<?php

/**
 * A producer call that asks the house to apply its trial once it verifies.
 * (c) Rodrigo Vicente - TeamX Agency
 *
 * @license Apache-2.0
 */
declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\SessionStore;
use Milpa\Console\McpProjector;
use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;

/**
 * `apply: "when_verified"` — the intention to apply, said in the call (greenhouse decisions/0578).
 *
 * A producer runs in a trial, and applying what the trial left is a second call: `sandbox:promote`. When nothing is
 * decided between the two, the model spent an inference copying an identifier out of the result it had just been
 * handed (evidence/1109: four such calls on one run). But residents do NOT always apply a trial that verified — 7
 * of 93 on record were discarded or left — so the house never infers the intention: the call carries it, and without
 * it nothing changes.
 *
 * What follows is exactly one call, the promotion of the workspace that same result names, played by the leg's loop
 * as its next step through the governed door: the gate, the mode's question, the scopes held now, the boot probe.
 * It is the seat's call; the house only saves the model retyping it, and records that it did.
 */
final class AppliedWhenVerified
{
    /** The one value the parameter takes. */
    public const KEYWORD = 'when_verified';

    /** The fact the house leaves when it continues a call, so the ledger never reads the promotion as the model's. */
    public const CONTINUED = 'session.call_continued';

    /**
     * Whether a trial's own result says it verified: the producer did not say it failed, the house boots with what
     * it left, and what the producer checked of its own work — its verification, its postconditions — holds.
     * Asked of the result the model is about to read; a producer that checks nothing says none of this, and its
     * trial is applied as the model would have applied it, under the promotion's own boot probe.
     *
     * @param array<string, mixed> $data
     */
    public static function verified(array $data): bool
    {
        $output = \is_array($data['output'] ?? null) ? $data['output'] : [];
        $said = [
            $output['ok'] ?? null,
            $output['house_boots'] ?? null,
            \is_array($output['verify'] ?? null) ? ($output['verify']['ok'] ?? null) : null,
            \is_array($output['postconditions'] ?? null) ? ($output['postconditions']['ok'] ?? null) : null,
        ];

        return !\in_array(false, $said, true);
    }

    /**
     * The calls that follow from a tool call the loop just ran: the promotion of the trial the house said it
     * applies — or null. One call, one workspace, the one this very result is about.
     *
     * @param array<string, mixed> $arguments the arguments the model sent, the parameter among them
     * @param mixed                $result    what the governed door answered
     *
     * @return list<array{name: string, arguments: array{workspace: string}}>|null
     */
    public static function follows(string $tool, array $arguments, mixed $result): ?array
    {
        $promote = McpProjector::toolName('sandbox:promote');
        if ($tool === $promote || ($arguments['apply'] ?? null) !== self::KEYWORD || !\is_array($result)) {
            return null;
        }
        $workspace = $result['workspace'] ?? null;
        $applies = $result['applies'] ?? null;
        if (($result['ran_in_trial'] ?? null) !== true || ($result['applied'] ?? null) !== false || !\is_string($workspace) || $workspace === ''
            || $applies !== ['operation' => 'sandbox:promote', 'arguments' => ['workspace' => $workspace]]) {
            return null;
        }

        return [['name' => $promote, 'arguments' => ['workspace' => $workspace]]];
    }

    /**
     * Record that the house continues with these calls, after which recorded call, and as whom.
     *
     * @param list<Event>                                                $stream the session's stream, the producer's call just recorded in it
     * @param list<array{name: string, arguments: array<string, mixed>}> $calls
     */
    public static function continued(EventStoreInterface $events, array $stream, string $session, array $calls, string $as): void
    {
        $after = 0;
        foreach ($stream as $event) {
            if ($event->type === 'session.tool_called') {
                $after = $event->seq;
            }
        }
        foreach ($calls as $call) {
            $events->append(new Event(
                streamId: SessionStore::PREFIX . $session,
                type: self::CONTINUED,
                payload: ['tool' => $call['name'], 'arguments' => $call['arguments'], 'after' => $after, 'because' => 'apply: ' . self::KEYWORD, 'as' => $as],
                seq: $events->nextSeq(),
            ));
        }
    }
}
