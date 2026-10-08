<?php

/**
 * This file is part of Milpa App Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\SessionStore;
use Milpa\Console\McpProjector;
use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;

/**
 * The operations whose verified trial the house applies on its own (greenhouse decisions/0586).
 *
 * A producer runs in a trial, and applying what the trial left is a second call: `sandbox:promote`. Asking the
 * resident to say it wants that — in the producer call — did not work: it said so in 2 of 27 trials (decisions/0578,
 * evidence/1121). So it is written down once, per operation. Of four operations the verified trial IS the work: in
 * 71 recorded runs the resident applied 84 of their 84 verified trials, never looked at the copy first, never
 * discarded one. Of the others it is not — `screen:declare`, `implement`, `edit`, `make what=test` have discards
 * and retries on record — and they are left as they are.
 *
 * Scaffolding an operation and scaffolding an entity were added to the list by Rod on 2026-10-07 (the amendment of
 * decisions/0586): «the house knows how to do it; whether it does it "automatically" depends completely on the
 * context». THE LIST SAYS WHAT THE HOUSE KNOWS HOW TO APPLY, not what it applies: their record is not the four's —
 * of 41 verified trials of `make what=operation` the resident applied 34, and discarded or replaced the rest — so
 * being on the list decides nothing by itself. What decides is the context a leg reads, below.
 *
 * Two things are kept apart, each with its owner:
 *
 *  - WHAT CAN BE ADMITTED is {@see ADMISSIBLE}: decided operation by operation, with its record beside. A fifth is a
 *    change to this list, with its own decision.
 *  - WHAT IS ADMITTED IN A HOUSE is a person's act in that house, kept beside its seats' frontier ({@see PATH}):
 *    who admitted it, and when. A house is born with none, and one where nobody admitted anything works exactly as
 *    it did.
 *
 * Nothing here is an authority. What follows from an admitted operation's verified trial is exactly one call, the
 * promotion of that very trial, played by the leg's loop as its next step through the governed door: the gate, the
 * mode's question, the scopes held now, the boot probe. The house only stops asking the model to type it, and
 * records that it did.
 */
final class AppliedTrials
{
    /** Where a house keeps what a person of it admitted. A trial never carries it: only `sandbox:admit` writes it. */
    public const PATH = 'storage/identity/applied-trials.json';

    /** The fact the house leaves when it continues a call, so the ledger never reads the promotion as the model's. */
    public const CONTINUED = 'session.call_continued';

    /**
     * What can be admitted (greenhouse decisions/0586): the operation, and for one that makes many things the values
     * of `what` whose trial is the work — null for the whole operation.
     *
     * @var array<string, list<string>|null>
     */
    private const ADMISSIBLE = [
        'plugins.register' => null,
        'entity:seed' => null,
        'make' => ['page', 'plugin', 'operation', 'entity'],
    ];

    /**
     * What each admissible operation does, as a person reads it before admitting it. Whoever admits everything at
     * once is shown exactly this, and signs its digest ({@see digestOfEverything()}): an entry with no sentence here
     * is an entry nobody was told about.
     *
     * @var array<string, string>
     */
    private const DOES = [
        'plugins.register' => 'registers a plugin of this house, so that it boots with it',
        'entity:seed' => 'writes the rows an entity declares',
        'make what=page' => 'scaffolds a page',
        'make what=plugin' => 'scaffolds a plugin',
        'make what=operation' => 'scaffolds an operation, its body still to be written',
        'make what=entity' => 'scaffolds an entity',
    ];

    public function __construct(private readonly string $path)
    {
    }

    /** The list of the house at this root. */
    public static function forRoot(string $root): self
    {
        return new self(rtrim($root, '/') . '/' . self::PATH);
    }

    /**
     * Every operation that can be admitted, as a person names it: `plugins.register`, `make what=page`.
     *
     * @return list<string>
     */
    public static function admissible(): array
    {
        $out = [];
        foreach (self::ADMISSIBLE as $operation => $whats) {
            foreach ($whats ?? [null] as $what) {
                $out[] = $what === null ? $operation : "{$operation} what={$what}";
            }
        }

        return $out;
    }

    /**
     * The admissible operation this call is — or null: an operation not on the list, or one called for something
     * its entry does not name.
     *
     * @param array<string, mixed> $arguments
     */
    public static function key(string $operation, array $arguments): ?string
    {
        if (!\array_key_exists($operation, self::ADMISSIBLE)) {
            return null;
        }
        $whats = self::ADMISSIBLE[$operation];
        if ($whats === null) {
            return $operation;
        }
        $what = $arguments['what'] ?? null;

        return \is_string($what) && \in_array($what, $whats, true) ? "{$operation} what={$what}" : null;
    }

    /**
     * What the catalogue says of an operation in a house that admitted these — or null when it admitted none of it.
     * One sentence, on the tool whose verified trial the house applies.
     *
     * @param list<string> $admitted
     */
    public static function says(string $operation, array $admitted): ?string
    {
        if (!\array_key_exists($operation, self::ADMISSIBLE)) {
            return null;
        }
        $whats = self::ADMISSIBLE[$operation];
        if ($whats === null) {
            return \in_array($operation, $admitted, true) ? 'The house applies its verified trial.' : null;
        }
        $named = array_values(array_filter($whats, static fn (string $what): bool => \in_array("{$operation} what={$what}", $admitted, true)));

        if ($named === []) {
            return null;
        }
        $last = array_pop($named);

        return 'The house applies its verified trial when what is ' . ($named === [] ? '' : implode(', ', $named) . ' or ') . $last . '.';
    }

    /**
     * What a person admitted in this house and has not withdrawn, each with who admitted it and when. A list that
     * cannot be read admits nothing.
     *
     * @return array<string, array{admitted_by: string, admitted_at: string}>
     */
    public function admitted(): array
    {
        $out = [];
        foreach ($this->read() as $key => $entry) {
            if (\in_array($key, self::admissible(), true) && \is_string($entry['admitted_by'] ?? null) && \is_string($entry['admitted_at'] ?? null) && !isset($entry['withdrawn_by'])) {
                $out[$key] = ['admitted_by' => $entry['admitted_by'], 'admitted_at' => $entry['admitted_at']];
            }
        }

        return $out;
    }

    /**
     * The list as it was written, withdrawn entries included — for whoever reads who decided what.
     *
     * @return array<string, array<string, mixed>>
     */
    public function record(): array
    {
        return $this->read();
    }

    /** Whether this operation is admitted in this house now. */
    public function admits(string $key): bool
    {
        return isset($this->admitted()[$key]);
    }

    /**
     * The admissible operations with what each does, in the list's order: what a person is shown before admitting
     * them all.
     *
     * @return list<array{operation: string, does: string}>
     */
    public static function whatEachDoes(): array
    {
        return array_map(static fn (string $key): array => ['operation' => $key, 'does' => self::DOES[$key] ?? ''], self::admissible());
    }

    /**
     * The digest of exactly what {@see whatEachDoes()} shows. One signed act admits everything only over this digest
     * (greenhouse decisions/0586, amended on 2026-10-08): if the list, or what an entry of it does, is no longer what
     * the person saw, the digest is another and the act admits nothing.
     */
    public static function digestOfEverything(): string
    {
        return 'sha256:' . hash('sha256', (string) json_encode(self::whatEachDoes()));
    }

    /**
     * Admit, in one write, every admissible operation that is not admitted yet — each with its own entry, as if
     * admitted by itself: who admitted one before keeps being who admitted it, and each is withdrawn by itself.
     *
     * @return list<string> the operations admitted now
     *
     * @throws \RuntimeException when the list cannot be written
     */
    public function admitEverything(string $by, string $at): array
    {
        $now = [];
        foreach (self::admissible() as $key) {
            if (!$this->admits($key)) {
                $now[$key] = ['admitted_by' => $by, 'admitted_at' => $at];
            }
        }
        if ($now !== []) {
            $this->write($now + $this->read());
        }

        return array_keys($now);
    }

    /**
     * Admit an operation in this house, by this person. False when it already was, or cannot be: nothing is written.
     *
     * @throws \RuntimeException when the list cannot be written
     */
    public function admit(string $key, string $by, string $at): bool
    {
        if (!\in_array($key, self::admissible(), true) || $this->admits($key)) {
            return false;
        }
        $this->write([$key => ['admitted_by' => $by, 'admitted_at' => $at]] + $this->read());

        return true;
    }

    /**
     * Withdraw an admission. The entry stays, with who withdrew it laid over. False when it was not admitted.
     *
     * @throws \RuntimeException when the list cannot be written
     */
    public function withdraw(string $key, string $by, string $at): bool
    {
        if (!$this->admits($key)) {
            return false;
        }
        $all = $this->read();
        $all[$key] = ['withdrawn_by' => $by, 'withdrawn_at' => $at] + $all[$key];
        $this->write($all);

        return true;
    }

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
     * applies — or null. One call, one workspace, the one this very result is about. Whether the house DID say so
     * of this trial is not read here: a result is data, and the leg asks its own trial layer.
     *
     * @param mixed $result what the governed door answered
     *
     * @return list<array{name: string, arguments: array{workspace: string}}>|null
     */
    public static function follows(string $tool, mixed $result): ?array
    {
        $promote = McpProjector::toolName('sandbox:promote');
        if ($tool === $promote || !\is_array($result)) {
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
     * Record that the house continues with these calls, after which recorded call, as whom, and by which admitted
     * operation's contract.
     *
     * @param list<Event>                                                $stream the session's stream, the producer's call just recorded in it
     * @param list<array{name: string, arguments: array<string, mixed>}> $calls
     */
    public static function continued(EventStoreInterface $events, array $stream, string $session, array $calls, string $as, string $key): void
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
                payload: ['tool' => $call['name'], 'arguments' => $call['arguments'], 'after' => $after, 'because' => 'contract: ' . $key, 'as' => $as],
                seq: $events->nextSeq(),
            ));
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function read(): array
    {
        $raw = @file_get_contents($this->path);
        $decoded = \is_string($raw) ? json_decode($raw, true) : null;
        if (!\is_array($decoded)) {
            return [];
        }

        return array_filter($decoded, static fn (mixed $entry, mixed $key): bool => \is_string($key) && \is_array($entry), \ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param array<string, array<string, mixed>> $all
     *
     * @throws \RuntimeException when the list cannot be written
     */
    private function write(array $all): void
    {
        $dir = \dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException('The house has nowhere to keep what it applies on its own: ' . $dir);
        }
        ksort($all);
        if (@file_put_contents($this->path, json_encode($all, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n", \LOCK_EX) === false) {
            throw new \RuntimeException('The list of what the house applies on its own could not be written: ' . $this->path);
        }
    }
}
