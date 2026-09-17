<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\EffectObservation;
use Milpa\AppRuntime\Web\ScreenDrafts;
use Milpa\ToolRuntime\ToolResult;

/** A newly persisted proposal is an artifact; another revision of known values is not novelty. */
final readonly class ScreenDraftObservation
{
    private const PRODUCER = 'app-runtime/screen-drafts/v1';

    /**
     * @param array<string, array<string, mixed>>|null $before
     * @param array<string, mixed>                     $arguments
     */
    private function __construct(private ?ScreenDrafts $drafts, private ?array $before, private array $arguments)
    {
    }

    /** Resolve observation lazily so a missing screen service cannot break another operation.
     * @param (\Closure(): ?ScreenDrafts)|null $resolve
     * @param array<string, mixed>             $arguments
     */
    public static function prepare(?\Closure $resolve, array $arguments): self
    {
        try {
            $drafts = $resolve === null ? null : $resolve();
        } catch (\Throwable) {
            $drafts = null;
        }
        return new self($drafts, self::snapshot($drafts), $arguments);
    }

    /** Bind the returned revision to native persisted values, without certifying its UI or props. */
    public function observe(ToolResult $result): EffectObservation
    {
        $unknown = new EffectObservation(self::PRODUCER, false);
        $after = self::snapshot($this->drafts);
        if ($this->before === null || $after === null) {
            return $unknown;
        }
        if (!$result->success || $result->requiresConfirmation() || !is_array($result->data) || ($result->data['ok'] ?? null) !== true) {
            return new EffectObservation(self::PRODUCER, $this->before === $after);
        }
        $claimed = $result->data['result'] ?? null;
        $id = is_array($claimed) ? ($claimed['id'] ?? null) : null;
        if (!is_string($id) || !isset($after[$id]) || !is_string($this->arguments['name'] ?? null)
            || !is_string($this->arguments['type'] ?? null) || !is_array($this->arguments['props'] ?? null)) {
            return $unknown;
        }
        try {
            $record = $after[$id];
            $props = $this->arguments['props'];
            $props['name'] ??= $this->arguments['name'];
            $definition = ['type' => $this->arguments['type'], 'props' => $props];
            if ($record['name'] !== $this->arguments['name'] || ScreenDrafts::hash($record['definition']) !== ScreenDrafts::hash($definition)) {
                return $unknown;
            }
            foreach ($record as $key => $value) {
                if (!array_key_exists($key, $claimed) || ScreenDrafts::hash($value) !== ScreenDrafts::hash($claimed[$key])) {
                    return $unknown;
                }
            }
            $identity = self::identity($record);
            foreach ($this->before as $previous) {
                if (self::identity($previous) === $identity) {
                    return new EffectObservation(self::PRODUCER, true);
                }
            }
            return new EffectObservation(self::PRODUCER, true, [$identity]);
        } catch (\Throwable) {
            return $unknown;
        }
    }

    /** Read verified immutable records through the same service that the operation uses.
     * @return array<string, array<string, mixed>>|null
     */
    private static function snapshot(?ScreenDrafts $drafts): ?array
    {
        if ($drafts === null) {
            return null;
        }
        try {
            $records = $drafts->catalogue()['drafts'];
            if (!is_array($records)) {
                return null;
            }
            $snapshot = [];
            foreach ($records as $record) {
                if (!is_array($record) || !is_string($record['id'] ?? null) || ($record['kind'] ?? null) !== 'draft'
                    || !is_string($record['name'] ?? null) || !is_array($record['definition'] ?? null)
                    || !is_string($record['build'] ?? null) || !array_key_exists('before', $record)
                    || ($record['before'] !== null && !is_array($record['before']))) {
                    return null;
                }
                $id = $record['id'];
                $unsigned = $record;
                unset($unsigned['id']);
                if (ScreenDrafts::hash($unsigned) !== $id) {
                    return null;
                }
                $snapshot[$id] = $record;
            }
            ksort($snapshot);
            return $snapshot;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Revision metadata stays in storage; proposal identity describes the values and build.
     * @param array<string, mixed> $record
     */
    private static function identity(array $record): string
    {
        return ScreenDrafts::hash(['native-screen-proposal/v1', array_intersect_key($record, array_flip(['name', 'before', 'definition', 'build']))]);
    }
}
