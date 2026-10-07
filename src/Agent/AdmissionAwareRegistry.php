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

use Milpa\ToolRuntime\ConfirmationTokenStore;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\PolicyGate;
use Milpa\ToolRuntime\RateLimiting\RateLimiterInterface;
use Milpa\ToolRuntime\TokenEstimator;
use Milpa\ToolRuntime\ToolDefinition;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ToolRuntime\ToolResult;
use Milpa\ValueObjects\Tooling\ToolOptions;
use Psr\Log\NullLogger;

/**
 * The registry a leg calls through, judging a seat's call to a built verb the house's way (greenhouse
 * decisions/0590).
 *
 * Everything is the wrapped registry's — its tools, its definitions, its gate's policy. Two things differ, and
 * both only for a seat calling a verb of a capability built in this house: the gate it hands out
 * ({@see AdmissionAwareGate}) and the call itself are asked with that verb's own declared words in hand, for that
 * one call. The tool runtime asks for the word first; this is what lets the house's policy be the one that answers
 * — did a person admit this verb — instead of a word its author chose. No other tool sees those words, and nothing
 * is written to the seat.
 */
final class AdmissionAwareRegistry extends ToolRegistry
{
    private readonly AdmissionAwareGate $gate;

    public function __construct(private readonly ToolRegistry $inner)
    {
        parent::__construct(new NullLogger());
        $this->gate = new AdmissionAwareGate($inner->getPolicyGate());
    }

    /** The registry this one wraps. */
    public function inner(): ToolRegistry
    {
        return $this->inner;
    }

    /**
     * Call through the wrapped registry, as the caller is to be seen for this one tool.
     *
     * @param array<string, mixed> $args
     */
    public function call(string $name, array $args, ?ToolContext $ctx = null): ToolResult
    {
        return $this->inner->call($name, $args, $ctx === null ? null : $this->gate->contextFor($ctx, $name));
    }

    /** The wrapped registry's gate, asked the house's way. */
    public function getPolicyGate(): PolicyGate
    {
        return $this->gate;
    }

    /** Forwards to the wrapped registry. */
    public function register(string $name, string $description, array $inputSchema, callable $callback, ?ToolOptions $options = null): void
    {
        $this->inner->register($name, $description, $inputSchema, $callback, $options);
    }

    /** Forwards to the wrapped registry. */
    public function getToolSummaries(): array
    {
        return $this->inner->getToolSummaries();
    }

    /** Forwards to the wrapped registry. */
    public function getToolDefinitions(): array
    {
        return $this->inner->getToolDefinitions();
    }

    /** Forwards to the wrapped registry. */
    public function getToolsByScopes(array $scopes): array
    {
        return $this->inner->getToolsByScopes($scopes);
    }

    /** Forwards to the wrapped registry. */
    public function getToolsByPrefix(string $prefix): array
    {
        return $this->inner->getToolsByPrefix($prefix);
    }

    /** Forwards to the wrapped registry. */
    public function getToolsWithinBudget(string $model, ?array $priorityTools = null): array
    {
        return $this->inner->getToolsWithinBudget($model, $priorityTools);
    }

    /** Forwards to the wrapped registry. */
    public function getTokenUsageReport(string $model = 'gpt-4'): string
    {
        return $this->inner->getTokenUsageReport($model);
    }

    /** Forwards to the wrapped registry. */
    public function estimateTokens(): array
    {
        return $this->inner->estimateTokens();
    }

    /** Forwards to the wrapped registry. */
    public function checkTokenBudget(string $model): array
    {
        return $this->inner->checkTokenBudget($model);
    }

    /** Forwards to the wrapped registry. */
    public function getTokenEstimator(): TokenEstimator
    {
        return $this->inner->getTokenEstimator();
    }

    /** Forwards to the wrapped registry. */
    public function has(string $name): bool
    {
        return $this->inner->has($name);
    }

    /** Forwards to the wrapped registry. */
    public function getDefinition(string $name): ?ToolDefinition
    {
        return $this->inner->getDefinition($name);
    }

    /** Forwards to the wrapped registry. */
    public function hasRateLimiter(): bool
    {
        return $this->inner->hasRateLimiter();
    }

    /** Forwards to the wrapped registry. */
    public function hasDispatcher(): bool
    {
        return $this->inner->hasDispatcher();
    }

    /** Forwards to the wrapped registry. */
    public function getConfirmationStore(): ConfirmationTokenStore
    {
        return $this->inner->getConfirmationStore();
    }

    /** Forwards to the wrapped registry. */
    public function setRateLimiter(RateLimiterInterface $limiter): void
    {
        $this->inner->setRateLimiter($limiter);
    }

    /** Forwards to the wrapped registry. */
    public function getRateLimiter(): ?RateLimiterInterface
    {
        return $this->inner->getRateLimiter();
    }
}
