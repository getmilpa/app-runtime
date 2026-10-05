<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\EffectObservation;
use Milpa\Agent\SessionStore;
use Milpa\Command\Operation;
use Milpa\DevTools\Operations\EditHandler;
use Milpa\DevTools\Operations\EditPairs;
use Milpa\DevTools\Operations\ImplementationBody;
use Milpa\DevTools\Operations\ImplementHandler;
use Milpa\EventStore\Event;
use Milpa\ToolRuntime\Contracts\ToolContext;

/** Resolve immutable rejected proposals on the host; the trial receives only repaired PHP. */
final readonly class RecordedEdit
{
    public const MAX_DEPTH = 16;

    public function __construct(private string $root, private SessionStore $sessions)
    {
    }

    /** Add the optional source only where this runtime can compose the installed native editor. */
    public static function operation(Operation $operation): Operation
    {
        if ($operation->name !== 'edit' || $operation->handler !== [EditHandler::class, 'handle']
            || !class_exists(EditPairs::class)) {
            return $operation;
        }
        $schema = $operation->inputSchema ?? [];
        $schema['properties']['source'] = [
            'type' => 'object',
            'description' => 'Optional complete rejected proposal: a recorded inline implement or source-based edit. '
                . 'Use its session, tool-call sequence and submitted_sha256. The destination must still match '
                . 'the rejection baseline. Requires session read and plugin write permission; runs the same judges in a trial.',
            'properties' => [
                'session' => ['type' => 'string'],
                'seq' => ['type' => 'integer', 'minimum' => 1],
                'sha256' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
            ],
            'required' => ['session', 'seq', 'sha256'],
            'additionalProperties' => false,
        ];
        $schema['properties']['edits']['description'] = 'Exact find-replace pairs against the current file, or the complete recorded proposal when source is supplied.';
        return new Operation(...array_replace(get_object_vars($operation), ['inputSchema' => $schema]));
    }

    /** Identify an explicit recorded source on the installed native editor.
     * @param array<string, mixed> $input
     */
    public static function usesSource(Operation $operation, array $input): bool
    {
        return $operation->name === 'edit' && $operation->handler === [EditHandler::class, 'handle']
            && array_key_exists('source', $input);
    }

    /**
     * Bind a rejected source and exact repair to the current authorized destination.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{input: array{plugin: string, class: string, content: string}, provenance: array<string, mixed>}
     */
    public function prepare(array $arguments, ToolContext $authority): array
    {
        [$plugin, $class] = self::identifiers($arguments);
        // Check both permissions before reading a session or disclosing whether a source exists.
        if (!$authority->hasScope('agent:read') && !$authority->hasScope('agent:answer')) {
            throw new \RuntimeException('Recorded repair requires agent:read or agent:answer.');
        }
        if (!$authority->hasScope('plugins.' . $plugin . ':write')) {
            throw new \RuntimeException("Recorded repair requires plugins.{$plugin}:write.");
        }
        return $this->bind($plugin, $class, $arguments);
    }

    /**
     * What this door answers to a session's call about ITS OWN record, permissions aside — or null when the
     * source binds. The session gate asks before it asks a person: a yes is not spent on a call this door
     * already refuses (greenhouse decisions/0569). Another session's record is not read here; whether the
     * caller may read it is a permission, and {@see self::prepare()} judges that first.
     *
     * @param array<string, mixed> $arguments
     */
    public function refusal(array $arguments, string $session): ?string
    {
        if (!is_array($arguments['source'] ?? null) || ($arguments['source']['session'] ?? null) !== $session) {
            return null;
        }
        try {
            [$plugin, $class] = self::identifiers($arguments);
            $this->bind($plugin, $class, $arguments);
        } catch (\RuntimeException $refused) {
            return $refused->getMessage();
        }
        return null;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{string, string} plugin and class
     */
    private static function identifiers(array $arguments): array
    {
        $plugin = $arguments['plugin'] ?? null;
        $class = $arguments['class'] ?? null;
        if (!is_string($plugin) || !is_string($class)
            || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $plugin)
            || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $class)) {
            throw new \RuntimeException('Recorded repair requires bare plugin and class identifiers.');
        }
        return [$plugin, $class];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{input: array{plugin: string, class: string, content: string}, provenance: array<string, mixed>}
     */
    private function bind(string $plugin, string $class, array $arguments): array
    {
        if (!class_exists(EditPairs::class)) {
            throw new \RuntimeException('Installed DevTools does not support recorded proposal repair.');
        }
        $seen = [];
        $source = $this->source($arguments['source'] ?? null, $plugin, $class, $seen);
        self::assertBaseline($this->root, $source['subject'], $source['baseline_sha256']);
        $content = self::patch($source['content'], $arguments['edits'] ?? null);
        return ['input' => ['plugin' => $plugin, 'class' => $class, 'content' => $content],
            'provenance' => ['source' => $arguments['source'], 'source_sha256' => hash('sha256', $source['content']),
                'subject' => $source['subject'], 'baseline_sha256' => $source['baseline_sha256'],
                'submitted_sha256' => hash('sha256', $content)]];
    }

    /** Recheck the copied destination immediately before judging; promotion retains its own check. */
    public static function assertBaseline(string $root, string $subject, string $digest): void
    {
        $path = rtrim($root, '/');
        foreach (explode('/', $subject) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new \RuntimeException('Recorded proposal has an invalid destination.');
            }
            $path .= '/' . $part;
            if (is_link($path)) {
                throw new \RuntimeException('Recorded repair cannot follow destination symlinks.');
            }
        }
        clearstatcache(true, $path);
        if (!is_file($path) || hash_file('sha256', $path) !== $digest) {
            throw new \RuntimeException('Recorded proposal baseline is stale; the destination changed. Read the current class before repairing.');
        }
    }

    /**
     * @param array<string, true> $seen
     *
     * @return array{content: string, subject: string, baseline_sha256: string}
     */
    private function source(mixed $reference, string $plugin, string $class, array &$seen): array
    {
        if (!is_array($reference) || count($reference) !== 3 || !is_string($reference['session'] ?? null)
            || $reference['session'] === '' || !is_int($reference['seq'] ?? null) || $reference['seq'] < 1
            || !is_string($reference['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $reference['sha256'])) {
            throw new \RuntimeException('Recorded source requires session, positive seq and a SHA256 of the complete proposal.');
        }
        $identity = $reference['session'] . ':' . $reference['seq'];
        if (isset($seen[$identity]) || count($seen) >= self::MAX_DEPTH) {
            throw new \RuntimeException('Recorded repair source chain is cyclic or exceeds its depth limit.');
        }
        $seen[$identity] = true;
        $events = $this->sessions->stream($reference['session']);
        $indexed = [];
        $previous = 0;
        $previousCall = 0;
        foreach ($events as $event) {
            if ($event->seq <= $previous || $event->streamId !== SessionStore::PREFIX . $reference['session']) {
                throw new \RuntimeException('Recorded proposal stream is invalid.');
            }
            $previous = $event->seq;
            $indexed[$event->seq] = $event;
            if ($event->seq < $reference['seq'] && $event->type === 'session.tool_called') {
                $previousCall = $event->seq;
            }
        }
        $event = $indexed[$reference['seq']] ?? null;
        if (!$event instanceof Event || $event->type !== 'session.tool_called') {
            throw new \RuntimeException('Recorded source must identify a tool call in this app.');
        }
        $call = $event->payload;
        $input = $call['arguments'] ?? null;
        $raw = $call['result'] ?? null;
        if (($call['ok'] ?? null) !== false || ($call['awaitingConfirmation'] ?? null) !== false
            || !in_array($call['tool'] ?? null, ['implement', 'edit'], true) || !is_array($input)
            || ($input['plugin'] ?? null) !== $plugin || ($input['class'] ?? null) !== $class
            || !is_string($raw) || ($call['resultChars'] ?? null) !== mb_strlen($raw)) {
            throw new \RuntimeException('Recorded source is not a complete rejected producer for this plugin and class.');
        }
        $witness = is_int($call['effectObservationSeq'] ?? null) ? ($indexed[$call['effectObservationSeq']] ?? null) : null;
        if (!$witness instanceof Event || $witness->type !== 'session.effect_observed'
            || $witness->seq <= $previousCall || $witness->seq >= $event->seq
            || ($witness->payload['tool'] ?? null) !== $call['tool']
            || ($witness->payload['argumentsDigest'] ?? null) !== EffectObservation::argumentsDigest($input)) {
            throw new \RuntimeException('Recorded producer has no matching native effect witness.');
        }
        $observation = EffectObservation::fromArray($witness->payload['observation'] ?? null);
        if (!$observation->known || $observation->diagnostics === []) {
            throw new \RuntimeException('Recorded producer has no attributable rejection diagnostic.');
        }
        $result = json_decode($raw, true);
        $diagnostic = $result['output']['diagnostic'] ?? null;
        if (!is_array($result) || ($result['schema'] ?? null) !== 'milpa.trial-authoring-failure/v1'
            || ($result['ok'] ?? null) !== false || ($result['ran_in_trial'] ?? null) !== true
            || ($result['applied'] ?? null) !== false || ($result['trial_exit'] ?? null) !== 1
            || ($result['output']['ok'] ?? null) !== false || !self::native($diagnostic)) {
            throw new \RuntimeException('Recorded rejection lacks its native authoring receipt.');
        }
        $trials = array_values(array_filter($events, static fn (Event $trial): bool =>
            $trial->seq < $witness->seq && $trial->type === 'session.trial_run_recorded'
            && ($trial->payload['workspace'] ?? null) === ($result['workspace'] ?? null)));
        if (count($trials) !== 1 || ($trials[0]->payload['operation'] ?? null) !== $call['tool']
            || ($trials[0]->payload['arguments_digest'] ?? null) !== EffectObservation::argumentsDigest($input)
            || ($trials[0]->payload['exit'] ?? null) !== 1) {
            throw new \RuntimeException('Recorded rejection is not linked to one failed native trial.');
        }
        [$subject, $baseline] = self::destination($diagnostic, $plugin, $class);
        if ($call['tool'] === 'implement') {
            if (isset($input['mode']) || !is_string($input['content'] ?? null)) {
                throw new \RuntimeException('Recorded repair requires a complete inline proposal, not a staged section.');
            }
            $content = $input['content'];
        } else {
            $parent = $this->source($input['source'] ?? null, $plugin, $class, $seen);
            if ($parent['subject'] !== $subject || $parent['baseline_sha256'] !== $baseline) {
                throw new \RuntimeException('Recorded repair chain changed its destination baseline.');
            }
            $content = self::patch($parent['content'], $input['edits'] ?? null);
            $execution = ['operation' => 'implement',
                'arguments_digest' => EffectObservation::argumentsDigest(['plugin' => $plugin, 'class' => $class, 'content' => $content]),
                'repair' => ['source' => $input['source'], 'source_sha256' => hash('sha256', $parent['content']),
                    'subject' => $subject, 'baseline_sha256' => $baseline, 'submitted_sha256' => hash('sha256', $content)]];
            $recordedExecution = $trials[0]->payload['execution'] ?? null;
            if (!is_array($recordedExecution)
                || EffectObservation::argumentsDigest($recordedExecution) !== EffectObservation::argumentsDigest($execution)) {
                throw new \RuntimeException('Recorded repair does not bind the effective implementation input.');
            }
        }
        if (hash('sha256', $content) !== $reference['sha256'] || !self::judged($content, $subject, $diagnostic)) {
            throw new \RuntimeException('Recorded proposal bytes do not match the source hash and native judgment.');
        }
        return ['content' => $content, 'subject' => $subject, 'baseline_sha256' => $baseline];
    }

    /**
     * Whether a rejection the trial just produced is one {@see self::source()} takes as a producer — asked
     * where the repair hint is written, so the hint names this door only when it opens (greenhouse
     * decisions/0569). The same checks as the door, on the same receipt: never a second list of phases.
     *
     * @param array<string, mixed>      $input       what the landing gate ran: plugin, class and the complete body
     * @param array<string, mixed>|null $output      the gate's own answer
     * @param list<string>              $diagnostics the identities the rejection's witness will carry
     */
    public static function admits(array $input, ?array $output, int $exit, array $diagnostics): bool
    {
        $diagnostic = $output['diagnostic'] ?? null;
        if ($exit !== 1 || $diagnostics === [] || isset($input['mode']) || !is_string($input['content'] ?? null)
            || !is_string($input['plugin'] ?? null) || !is_string($input['class'] ?? null)
            || ($output['ok'] ?? null) !== false || !self::native($diagnostic)) {
            return false;
        }
        try {
            [$subject] = self::destination($diagnostic, $input['plugin'], $input['class']);
        } catch (\RuntimeException) {
            return false;
        }
        return self::judged($input['content'], $subject, $diagnostic);
    }

    /** @phpstan-assert-if-true array<string, mixed> $diagnostic */
    private static function native(mixed $diagnostic): bool
    {
        return is_array($diagnostic) && ($diagnostic['schema'] ?? null) === 'milpa.authoring-diagnostic/v1'
            && ($diagnostic['stable_subject'] ?? null) === true;
    }

    /**
     * The file a rejection left as it was, and the bytes it left there.
     *
     * A plugin's class or its test: the landing gate takes both (a judge lands through the same gate), so
     * the recorded door does too. Every phase that restores its destination is one whose proposal can be
     * repaired; `syntax` never installed it.
     *
     * @param array<string, mixed> $diagnostic
     *
     * @return array{string, string} subject and baseline SHA-256
     */
    private static function destination(array $diagnostic, string $plugin, string $class): array
    {
        $subject = $diagnostic['subject'] ?? null;
        if (!is_string($subject) || basename($subject) !== $class . '.php'
            || (!str_starts_with($subject, 'src/Plugins/' . $plugin . '/') && !str_starts_with($subject, 'tests/Plugins/' . $plugin . '/'))) {
            throw new \RuntimeException('Recorded proposal names a different destination.');
        }
        $syntax = ($diagnostic['phase'] ?? null) === 'syntax';
        if ($syntax) {
            if (($diagnostic['candidate_installed'] ?? null) !== false || ($diagnostic['destination_preserved'] ?? null) !== true
                || array_key_exists('rolled_back', $diagnostic) || array_key_exists('restored_sha256', $diagnostic)) {
                throw new \RuntimeException('Recorded syntax rejection did not preserve its destination.');
            }
        } elseif (!in_array($diagnostic['phase'] ?? null, ['static-analysis', 'container', 'behavior'], true)
            || ($diagnostic['rolled_back'] ?? null) !== true) {
            throw new \RuntimeException('Recorded rejection did not restore its destination.');
        }
        $baseline = $diagnostic[$syntax ? 'preserved_sha256' : 'restored_sha256'] ?? null;
        if (!is_string($baseline) || !preg_match('/^[a-f0-9]{64}$/D', $baseline)) {
            throw new \RuntimeException('Recorded rejection has no destination baseline.');
        }
        return [$subject, $baseline];
    }

    /** @param array<string, mixed> $diagnostic */
    private static function judged(string $content, string $subject, array $diagnostic): bool
    {
        return strlen($content) <= ImplementHandler::MAX_INLINE_BYTES && mb_check_encoding($content, 'UTF-8')
            && hash('sha256', $content) === ($diagnostic['submitted_sha256'] ?? null)
            && hash('sha256', ImplementationBody::normalize($content, $subject)) === ($diagnostic['judged_sha256'] ?? null);
    }

    private static function patch(string $content, mixed $edits): string
    {
        $result = EditPairs::apply($content, is_array($edits) ? $edits : [], 'RECORDED proposal');
        if (!$result['ok']) {
            throw new \RuntimeException($result['error']);
        }
        if (strlen($result['content']) > ImplementHandler::MAX_INLINE_BYTES) {
            throw new \RuntimeException('Repaired proposal exceeds the complete inline implementation limit.');
        }
        return $result['content'];
    }
}
