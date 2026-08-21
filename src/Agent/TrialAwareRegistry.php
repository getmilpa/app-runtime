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

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\SessionStore;
use Milpa\Command\Operation;
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
 * The executor half of the trial contract: it asks the SAME {@see TrialRouter} the gate asked, and
 * for a call with a plan it runs the operation in the sandbox instead of on the host (greenhouse
 * decisions/0069 §11). Everything else it forwards to the registry it wraps — so a call without a
 * plan reaches its registered tool exactly as before, and the host tree never changes for a trial.
 *
 * ── WHY A DECORATOR AND NOT A SUBCLASS OF THE REAL REGISTRY ──────────────────────────────────────
 *
 * `ToolRegistry` is not `final`, so this can wrap the app's registry without touching ai-gateway
 * (0069). It extends `ToolRegistry` only to satisfy the type the gateway expects; its own tool
 * store stays empty, and every lookup is answered from the wrapped one. The forwarding is total on
 * purpose — a decorator that forgot a method would answer that one from an empty registry, which is
 * a silent lie the test `testTheDecoratorForwardsEveryPublicMethodOfTheRegistry` refuses.
 */
final class TrialAwareRegistry extends ToolRegistry
{
    /** @param list<Operation> $operations */
    public function __construct(
        private readonly ToolRegistry $inner,
        private readonly TrialRouter $router,
        private readonly array $operations,
        private readonly ?SessionStore $sessions = null,
        private readonly ?string $sessionId = null,
    ) {
        parent::__construct(new NullLogger());
    }

    /**
     * Route a planned call into the trial; forward everything else to the wrapped registry.
     *
     * A call with a plan runs in the sandbox and never reaches the registered handler; the result
     * carries the trial's output and, in `meta.trial`, the workspace, exit and the host-computed diff.
     */
    public function call(string $name, array $args, ?ToolContext $ctx = null): ToolResult
    {
        $operation = $this->operationFor($name);
        $plan = $operation === null ? null : $this->router->planFor($operation, $args);
        if ($operation === null || $plan === null) {
            return $this->inner->call($name, $args, $ctx);
        }

        // THE CALL RUNS IN THE COPY, NOT ON THE HOST. The registered handler is never reached; what
        // the human gets back is the trial's output and, in the meta, the confinement and the diff.
        $run = $this->router->runner()->run($plan->workspace, $operation->name, $args);
        $this->record($plan, $operation->name, $args, $run);

        $meta = [
            'trial' => [
                'workspace' => $plan->workspace->id,
                'exit' => $run->exit,
                'bounds' => $run->bounds,
                'report' => $run->report,
            ],
        ];

        if (! $run->ok()) {
            return ToolResult::error($run->stderr !== '' ? $run->stderr : 'the trial did not succeed', $run->output, $meta);
        }

        // THE RESULT IS SELF-DESCRIBING, and that is not decoration — it is the difference between a
        // trial that gets promoted and one silently dropped. Measured on llama.local (greenhouse
        // evidence/0274): given the bare operation output, the agent reported «done» and never
        // promoted, so nothing reached the host. The result now TELLS the agent it ran in a trial,
        // WHAT it changed, and the exact call that applies it — the house guides the flow, the model
        // does not have to infer it.
        $changed = array_map(static fn (array $info): string => $info['status'], $run->report);
        $ws = $plan->workspace->id;

        $data = [
            'ran_in_trial' => true,
            'applied' => false,
            'workspace' => $ws,
            'changed' => $changed,
            'output' => $run->output,
        ];
        if ($changed === []) {
            $data['note'] = 'This ran in a disposable trial and changed nothing on disk; there is nothing to apply.';

            return ToolResult::success($data, 'ran in a trial; it changed nothing', $meta);
        }

        $data['to_apply'] = ['operation' => 'sandbox:promote', 'arguments' => ['workspace' => $ws]];
        $data['to_discard'] = ['operation' => 'sandbox:discard', 'arguments' => ['workspace' => $ws]];
        $data['note'] = sprintf(
            'This ran in a disposable TRIAL and is NOT applied to the app yet. To apply the change, '
            . 'call sandbox:promote with {"workspace":"%s"}. To throw it away, call sandbox:discard.',
            $ws,
        );

        return ToolResult::success($data, 'ran in a trial — call sandbox:promote to apply it, or sandbox:discard to throw it away', $meta);
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
    public function getPolicyGate(): PolicyGate
    {
        return $this->inner->getPolicyGate();
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

    /** @param array<string, mixed> $args */
    private function record(TrialPlan $plan, string $operation, array $args, TrialRun $run): void
    {
        if ($this->sessions === null || $this->sessionId === null) {
            return;
        }

        $this->sessions->recordTrialRun($this->sessionId, [
            'workspace' => $plan->workspace->id,
            'operation' => $operation,
            'arguments_digest' => $plan->confinement->argumentsDigest,
            'bounds' => $run->bounds,
            'exit' => $run->exit,
            'report' => $run->report,
            'output_digest' => hash('sha256', $run->stdout),
        ]);
    }

    private function operationFor(string $tool): ?Operation
    {
        foreach ($this->operations as $operation) {
            if (\Milpa\Console\McpProjector::toolName($operation->name) === $tool) {
                return $operation;
            }
        }

        return null;
    }
}
