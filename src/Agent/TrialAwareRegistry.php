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
use Milpa\Agent\EffectObservation;
use Milpa\AppRuntime\Web\ScreenDrafts;
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
    /** A staged multipart part whose native trial result still needs its explicit promotion. */
    private ?string $pendingMultipartPromotion = null;

    /** What runs a work call confined in the house, when this house does ({@see runsWorkInTheHouse()}). */
    private ?ConfinedWork $confinedWork = null;

    /** @var array<string, array<string, mixed>> what the house saw of the work calls it just ran, until the receipt asks ({@see landedWork()}) */
    private array $landed = [];

    /**
     * Say that work in the domain runs in the house, and hand over what runs it (greenhouse decisions/0588).
     *
     * A work call — its operation declares `subject: data`, `externality: none`, and a state the house can name —
     * is no longer rehearsed in a copy that was born without that state. It runs once, against the house,
     * confined to its state, after the same gates as any call.
     */
    public function runsWorkInTheHouse(HouseWork $work, ConfinedWork $confined): void
    {
        $this->router->runsWorkInTheHouse($work);
        $this->confinedWork = $confined;
    }

    /**
     * What THIS registry saw of the work call it just ran — where it ran, and what its state was and is — or null.
     * Asked once: the answer is spent (greenhouse decisions/0588, rule 5).
     *
     * The receipt of an execution is written by another hand, after the call. What a tool answers is data, so that
     * hand does not read the receipt's facts out of a result: it asks here, where the call was run and its state
     * digested.
     *
     * @param array<string, mixed> $args
     *
     * @return array{environment: string, confined: bool, state: list<array{path: string, before: ?string, after: ?string}>, changed: bool, pre_image: ?string}|null
     */
    public function landedWork(string $tool, array $args): ?array
    {
        $key = $tool . '#' . EffectObservation::argumentsDigest($args);
        $landed = $this->landed[$key] ?? null;
        unset($this->landed[$key]);

        /** @var array{environment: string, confined: bool, state: list<array{path: string, before: ?string, after: ?string}>, changed: bool, pre_image: ?string}|null */
        return $landed;
    }

    /**
     * Run one work call in the house and answer with the handler's output and the house's account of its state.
     *
     * @param array<string, mixed> $args
     */
    private function workInTheHouse(string $name, WorkPlan $plan, array $args): ToolResult
    {
        if ($this->confinedWork === null) {
            return ToolResult::error("«{$plan->operation}» is work in the domain and this house has nothing to run it confined with; nothing ran");
        }
        try {
            $outcome = $this->confinedWork->run($plan, $args);
        } catch (\RuntimeException $refused) {
            return ToolResult::error($refused->getMessage() . ' Nothing ran.');
        }
        $this->landed[$name . '#' . EffectObservation::argumentsDigest($args)] = [
            'environment' => 'house',
            'confined' => true,
            'state' => $outcome->state,
            'changed' => $outcome->changed,
            'pre_image' => $outcome->preImage,
        ];
        $house = ['ran_in_house' => true, 'changed' => $outcome->changed, 'state' => $outcome->state, 'pre_image' => $outcome->preImage];
        $meta = ['work' => ['exit' => $outcome->run->exit, 'bounds' => $outcome->run->bounds, 'state' => $outcome->state, 'pre_image' => $outcome->preImage]];
        if (! $outcome->run->ok()) {
            // A REFUSAL OF THE DOMAIN KEEPS ITS REASON, and the house says beside it what it saw of its state.
            $output = \is_array($outcome->run->output) ? $outcome->run->output : ['error' => trim($outcome->run->stderr) !== '' ? trim($outcome->run->stderr) : 'the call did not succeed'];

            return ToolResult::error((string) json_encode(
                ['ok' => false, ...$house, ...array_diff_key($output, ['ok' => 1])],
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE,
            ), $outcome->run->output, $meta);
        }
        $data = [...$house, 'output' => $outcome->run->output, 'note' => $outcome->changed
            ? 'This ran in the house, confined to its state, and the state changed: it is done. There is nothing to promote.'
            : 'This ran in the house and its state did not change: whatever it answered, nothing of it is in the house.'];

        return ToolResult::success($data, $outcome->changed ? 'ran in the house; its state changed' : 'ran in the house; its state did not change', $meta);
    }

    /** The house's list, when this leg's loop plays the promotion of an admitted operation's trial ({@see houseApplies()}). */
    private ?AppliedTrials $applied = null;

    /** @var list<string> what the house had admitted when the leg began: what its catalogue says, for the whole leg */
    private array $admittedAtStart = [];

    /** @var array<string, string> the trials this registry said the house applies, each with its admitted operation, until the leg asks ({@see saidItApplies()}) */
    private array $applies = [];

    /**
     * Say that the leg this registry serves continues the verified trial of an admitted operation with its
     * promotion, and hand it the house's list (greenhouse decisions/0586).
     *
     * Only then does the catalogue say it and a trial's result promise it: a registry never promises what its loop
     * cannot do. What the catalogue says is the list as it stood when the leg began, so what a leg sends does not
     * change under the model; whether a trial is applied is asked of the list again when that trial verifies. An
     * admission holds from the next leg, a withdrawal from the next call.
     */
    public function houseApplies(AppliedTrials $admitted): void
    {
        $this->applied = $admitted;
        $this->admittedAtStart = array_keys($admitted->admitted());
    }

    /**
     * The admitted operation whose trial THIS registry just ran, saw verify and said the house applies — or null.
     * Asked once: the answer is spent (greenhouse decisions/0586).
     *
     * What a tool answers is data. A result shaped like a verified trial — from a tool no trial confines, naming a
     * trial of another session or of an operation nobody admitted — must not make the house promote anything, so
     * the leg asks here, where the trial was run, and not the result.
     */
    public function saidItApplies(string $workspace): ?string
    {
        $key = $this->applies[$workspace] ?? null;
        unset($this->applies[$workspace]);

        return $key;
    }

    /**
     * The admitted operation this call is — admitted when the leg began, and still — or null.
     *
     * @param array<string, mixed> $args
     */
    private function admittedCall(string $operation, array $args): ?string
    {
        if ($this->applied === null) {
            return null;
        }
        $key = AppliedTrials::key($operation, $args);

        return $key !== null && \in_array($key, $this->admittedAtStart, true) && $this->applied->admits($key) ? $key : null;
    }

    /**
     * @param list<Operation>                  $operations
     * @param (\Closure(): ?ScreenDrafts)|null $screenDrafts
     */
    public function __construct(
        private readonly ToolRegistry $inner,
        private readonly TrialRouter $router,
        private readonly array $operations,
        private readonly ?SessionStore $sessions = null,
        private readonly ?string $sessionId = null,
        private readonly ?\Closure $screenDrafts = null,
    ) {
        parent::__construct(new NullLogger());
        $this->pendingMultipartPromotion = $this->recordedMultipartPromotion();
    }

    /**
     * Route a planned call into the trial; forward everything else to the wrapped registry.
     *
     * A call with a plan runs in the sandbox and never reaches the registered handler; the result
     * carries the trial's output and, in `meta.trial`, the workspace, exit and the host-computed diff.
     */
    public function call(string $name, array $args, ?ToolContext $ctx = null): ToolResult
    {
        $definition = $this->inner->getDefinition($name);
        if ($definition !== null) {
            $admission = $this->inner->getPolicyGate()->authorizeCall($ctx ?? ToolContext::cli(), $definition, $args);
            if (!$admission->allowed) {
                return ToolResult::error((string) $admission->reason);
            }
        }
        $operation = $this->operationFor($name);
        $prepared = null;
        if ($operation !== null && RecordedEdit::usesSource($operation, $args)) {
            if ($this->sessions === null) {
                return ToolResult::error('Recorded repair requires the host session store.');
            }
            try {
                $prepared = (new RecordedEdit($this->router->root(), $this->sessions))->prepare($args, $ctx ?? ToolContext::cli());
            } catch (\RuntimeException $error) {
                return ToolResult::error($error->getMessage());
            }
        }
        // WORK IN THE DOMAIN RUNS IN THE HOUSE, NOT IN A COPY (greenhouse decisions/0588). The gates above and
        // before this door are the same; what changes is where the admitted call runs. A state that is not a
        // place for state never runs; a call the house cannot confine runs where it always did — a person was
        // asked first.
        $work = $operation === null || $prepared !== null ? null : $this->router->workFor($operation);
        if ($work !== null) {
            if ($work->refused !== null) {
                return ToolResult::error($work->refused);
            }

            return $work->confined ? $this->workInTheHouse($name, $work, $args) : $this->inner->call($name, $args, $ctx);
        }
        $plan = $operation === null ? null : $this->router->planFor($operation, $args);
        if ($prepared !== null && $plan === null) {
            return ToolResult::error('Recorded repair requires an available confined trial.');
        }
        if ($operation === null || $plan === null) {
            if ($operation?->name === 'screen:draft' && $this->sessions !== null && $this->sessionId !== null) {
                $observation = ScreenDraftObservation::prepare($this->screenDrafts, $args);
                $result = $this->inner->call($name, $args, $ctx);
                $this->recordEffect($name, $args, $observation->observe($result));
                return $result;
            }
            if ($operation?->name !== 'sandbox:promote' || $this->sessions === null || $this->sessionId === null) {
                return $this->inner->call($name, $args, $ctx);
            }
            $workspace = is_string($args['workspace'] ?? null) ? $this->router->workspace($args['workspace']) : null;
            $paths = $workspace === null ? [] : array_keys($workspace->diff());
            $before = $workspace === null ? null : FileEffectObserver::hostSnapshot($workspace->root, $paths);
            $result = $this->inner->call($name, $args, $ctx);
            $after = $workspace === null ? null : FileEffectObserver::hostSnapshot($workspace->root, $paths);
            $this->recordEffect($name, $args, FileEffectObserver::compare($before, $after, 'applied'));
            if ($result->success && ($result->data['ok'] ?? null) === true
                && ($args['workspace'] ?? null) === $this->pendingMultipartPromotion) {
                $this->pendingMultipartPromotion = null;
            }
            return $result;
        }

        // THE CALL RUNS IN THE COPY, NOT ON THE HOST. The registered handler is never reached; what
        // the human gets back is the trial's output and, in the meta, the confinement and the diff.
        $policy = $this->inner->getPolicyGate()->getCallPolicy();
        // A custom registry policy cannot turn a source repair's named write set into a whole-copy write.
        if ($prepared !== null && !$policy instanceof PluginAuthoringPolicy) {
            $policy = new PluginAuthoringPolicy($this->router->root());
        }
        $paths = $policy instanceof PluginAuthoringPolicy && in_array($name, PluginAuthoringPolicy::BUILD, true)
            ? $policy->writePaths($ctx ?? ToolContext::cli(), $name, $args) : null;
        $observe = $this->sessions !== null && $this->sessionId !== null;
        $before = $observe ? FileEffectObserver::trialSnapshot($plan->workspace) : null;
        $executionName = $prepared === null ? $operation->name : 'implement';
        $executionInput = $prepared['input'] ?? $args;
        if ($prepared !== null) {
            try {
                RecordedEdit::assertBaseline($plan->workspace->copy, $prepared['provenance']['subject'], $prepared['provenance']['baseline_sha256']);
            } catch (\RuntimeException $error) {
                return ToolResult::error($error->getMessage());
            }
        }
        $authoring = AuthoringDiagnostic::prepare($executionName, $executionInput, $plan->workspace, $before, $paths);
        $run = $this->router->runner()->run($plan->workspace, $executionName, $executionInput, $paths);
        if ($this->sessionId !== null) {
            $this->router->recordInputCall($this->sessionId, $name, $args, $run->inputWitness);
        }
        $execution = $prepared === null ? null : ['operation' => $executionName,
            'arguments_digest' => EffectObservation::argumentsDigest($executionInput), 'repair' => $prepared['provenance']];
        $this->record($plan, $operation->name, $args, $run, $execution);
        // Whether `edit` with `source` will take this rejection: asked of that door, with the witness it will
        // read, before the hint that names it is written (greenhouse decisions/0571).
        $recorded = false;
        if ($observe) {
            $after = FileEffectObserver::trialSnapshot($plan->workspace);
            $evidence = FileEffectObserver::testEvidence($name, $args, $after, $run->output);
            $diagnostics = $run->exit === 1 && $before !== null && $before === $after
                ? FileEffectObserver::testDiagnostics($name, $args, $after, $run->output) : [];
            $diagnostics = [...$diagnostics, ...($authoring?->identities($before, $after, $run->output, $run->exit) ?? [])];
            $this->recordEffect($name, $args, FileEffectObserver::compare($before, $after, 'proposal', $evidence, $diagnostics));
            $recorded = $executionName === 'implement' && RecordedEdit::admits($executionInput, $run->output, $run->exit, $diagnostics);
        }

        $meta = [
            'trial' => [
                'workspace' => $plan->workspace->id,
                'exit' => $run->exit,
                'bounds' => $run->bounds,
                'report' => $run->report,
            ],
        ];

        if (! $run->ok()) {
            if ($operation->name === 'test' || ($executionName === 'implement' && is_array($run->output['diagnostic'] ?? null))) {
                // The native channel persists and throws only error text on failure. Keep the
                // producer's result there, separately from runner diagnostics (greenhouse 0695).
                // Null output means no structured result was received; no cause is inferred.
                $error = json_encode([
                    'schema' => $operation->name === 'test' ? 'milpa.trial-test-failure/v1' : 'milpa.trial-authoring-failure/v1',
                    'ok' => false,
                    'ran_in_trial' => true,
                    'applied' => false,
                    'workspace' => $plan->workspace->id,
                    'trial_exit' => $run->exit,
                    'summary' => TrialFailureSummary::from($executionName, $run->output, $run->stderr, $executionInput, $recorded),
                    'output' => $run->output,
                    'stderr' => $run->stderr,
                    ...($prepared === null ? [] : ['repair' => $prepared['provenance']]),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

                return ToolResult::error($error, $run->output, $meta);
            }
            // A REFUSAL KEEPS ITS REASON. The native channel shows the agent only this error text, and
            // for every other producer it used to be `error` alone: «invalid word», with the path and the
            // reason the producer wrote dropped on the floor. Measured (greenhouse evidence/1002): the
            // resident retried one definition four times blind. When the producer said more than a
            // message, all of it travels, marked as a trial's.
            // What a SUCCESS would have told the agent to do next never rides a failure: a failed
            // producer's continuation note would read as guidance to go on (TrialPartialContinuationTest).
            $output = array_diff_key(\is_array($run->output) ? $run->output : [], array_flip(['partial', 'note', 'to_apply', 'to_discard']));
            if (array_diff(array_keys($output), ['ok', 'error']) !== []) {
                return ToolResult::error(json_encode(
                    ['ok' => false, 'ran_in_trial' => true, 'applied' => false, 'workspace' => $plan->workspace->id, ...$output],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
                ), $run->output, $meta);
            }

            return ToolResult::error(\is_string($run->output['error'] ?? null) ? $run->output['error'] : ($run->stderr !== '' ? $run->stderr : 'the trial did not succeed'), $run->output, $meta);
        }

        // THE RESULT IS SELF-DESCRIBING, and that is not decoration — it is the difference between a
        // trial that gets promoted and one silently dropped. Measured on llama.local (greenhouse
        // evidence/0274): given the bare operation output, the agent reported «done» and never
        // promoted, so nothing reached the host. The result now TELLS the agent it ran in a trial,
        // WHAT it changed, and the exact call that applies it — the house guides the flow, the model
        // does not have to infer it.
        $changed = array_map(static fn (array $info): string => $info['status'], $run->report);
        $ws = $plan->workspace->id;

        // A RECEIPT EARNED IN A TRIAL SAYS WHERE (greenhouse decisions/0463). A producer that
        // demonstrated something — a screen served, say — demonstrated it in the COPY: served@trial,
        // and served@house does NOT follow. Sealed here, for every producer, with the world it was
        // observed in and whether that world is durable yet, so the fact cannot be read later with more
        // authority than it earned (evidence/0997: «served» with a servedAt while the house answered
        // 404). Promotion does not upgrade it: sandbox:promote leaves its own receipt, and a fact about
        // the house is observed in the house.
        $output = $run->output;
        if (\is_array($output) && \is_array($output['evidence'] ?? null)) {
            $output['evidence']['environment'] = ['kind' => 'trial', 'workspace' => $ws];
            $output['evidence']['promoted'] = false;
        }

        $data = [
            'ran_in_trial' => true,
            'applied' => false,
            'workspace' => $ws,
            'changed' => $changed,
            'output' => $output,
            ...($prepared === null ? [] : ['repair' => $prepared['provenance']]),
        ];
        if ($changed === []) {
            $data['note'] = 'This ran in a disposable trial and changed nothing on disk; there is nothing to apply.';

            return ToolResult::success($data, 'ran in a trial; it changed nothing', $meta);
        }

        $data['to_apply'] = ['operation' => 'sandbox:promote', 'arguments' => ['workspace' => $ws]];
        $data['to_discard'] = ['operation' => 'sandbox:discard', 'arguments' => ['workspace' => $ws]];
        if ($operation->name === 'implement' && in_array($args['mode'] ?? null, ['start', 'append', 'amend', 'reset'], true)
            && is_string($run->output['file'] ?? null) && is_string($run->output['staging'] ?? null)
            && $run->output['staging'] === $run->output['file'] . '.milpa-part'
            && array_keys($changed) === [$run->output['staging']]) {
            $this->pendingMultipartPromotion = $ws;
        }
        $data['note'] = sprintf(
            'This ran in a disposable TRIAL and is NOT applied to the app yet. To apply the change, '
            . 'call sandbox:promote with {"workspace":"%s"}. To throw it away, call sandbox:discard.',
            $ws,
        );

        $partial = self::partialTrialNote($executionName, $executionInput, $run->output, $run->report);
        // THE HOUSE APPLIES THE VERIFIED TRIAL OF AN OPERATION A PERSON ADMITTED (greenhouse decisions/0586). It says
        // so here and the leg's loop plays the promotion next, through the governed door. The call is no longer the
        // model's to make, so it is not handed one to copy; the trial is still named. A trial that did not verify is
        // never applied — and neither is a part of a file being authored: authoring cannot be admitted.
        $contract = AppliedTrials::verified($data) ? $this->admittedCall($operation->name, $args) : null;
        if ($contract !== null) {
            $data['applies'] = $data['to_apply'];
            unset($data['to_apply'], $data['to_discard']);
            $this->applies[$ws] = $contract;
            $data['note'] = \sprintf(
                'This ran in a disposable TRIAL and verified. The house applies the verified trial of %s — a person of '
                . 'this house admitted it: it calls sandbox:promote for it next, under the same checks as if you had, '
                . 'and its result follows. Do not call it yourself.',
                $contract,
            );

            return ToolResult::success($data, 'ran in a trial and verified — the house applies it next', $meta);
        }
        if ($partial !== null) {
            // Preserve the producer output. Its directions describe the trial's filesystem;
            // the next agent invocation starts from the app and needs explicit promotion first.
            $data['note'] = $partial . ' ' . $data['note'];
        }

        return ToolResult::success($data, 'ran in a trial — call sandbox:promote to apply it, or sandbox:discard to throw it away', $meta);
    }

    /** Describe an unverified part only when one producer hash matches the whole diff.
     *
     * @param array<string, mixed>                $input
     * @param array<string, mixed>|null           $output
     * @param array<string, array<string, mixed>> $report
     */
    private static function partialTrialNote(string $operation, array $input, ?array $output, array $report): ?string
    {
        if ($operation !== 'implement' || !in_array($input['mode'] ?? null, ['start', 'append', 'amend', 'reset'], true)
            || ($output['ok'] ?? null) !== true || !is_string($output['partial'] ?? null)
            || trim($output['partial']) === '' || isset($output['verified'])
            || !is_string($output['file'] ?? null) || !str_ends_with($output['file'], '.php')
            || ($output['staging'] ?? null) !== $output['file'] . '.milpa-part'
            || !is_string($output['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $output['sha256'])
            || count($report) !== 1) {
            return null;
        }
        $entry = $report[$output['staging']] ?? null;
        if (!is_array($entry) || !in_array($entry['status'] ?? null, ['added', 'modified'], true)
            || $entry !== ['status' => $entry['status'], 'sha256' => $output['sha256']]) {
            return null;
        }

        return 'The accepted part exists only in this trial. Before another reset, append, amend or finish, '
            . 'call the returned to_apply operation and check that its domain result succeeded. '
            . 'That promotion transfers staging only: the PHP class remains unchanged and unverified. '
            . 'candidate:state describes producer-verified candidates, so it cannot verify this partial. '
            . 'The producer output describes work inside the trial, not a change already applied to the app.';
    }

    /** Forwards to the wrapped registry. */
    public function register(string $name, string $description, array $inputSchema, callable $callback, ?ToolOptions $options = null): void
    {
        $this->inner->register($name, $description, $inputSchema, $callback, $options);
    }

    /**
     * Give the next step one actionable transition while a multipart part remains in a trial.
     * This narrows the model's offer only; the existing gate still judges the exact workspace,
     * authority and domain result. Without an offered promotion tool, preserve the catalogue.
     */
    public function getToolSummaries(): array
    {
        $tools = $this->inner->getToolSummaries();
        // THE CATALOGUE SAYS IT WHERE THE HOUSE WILL HONOUR IT (greenhouse decisions/0586): one sentence on the
        // operations a person admitted, as the list stood when the leg began. No parameter, and nothing in a house
        // where nobody admitted anything.
        foreach ($this->admittedAtStart === [] ? [] : array_keys($tools) as $i) {
            $operation = $this->operationFor($tools[$i]['name']);
            $says = $operation === null ? null : AppliedTrials::says($operation->name, $this->admittedAtStart);
            if ($says !== null && $this->router->eligible($operation)) {
                $tools[$i]['description'] = rtrim((string) $tools[$i]['description']) . ' ' . $says;
            }
        }
        if ($this->pendingMultipartPromotion !== null) {
            $workspace = $this->router->workspace($this->pendingMultipartPromotion);
            if ($workspace === null || $workspace->stale() !== []) {
                $this->pendingMultipartPromotion = null;
            }
        }
        if ($this->pendingMultipartPromotion === null) {
            return $tools;
        }
        $promotion = array_values(array_filter($tools, static fn (array $tool): bool => $tool['name'] === 'sandbox_promote'));
        return count($promotion) === 1 ? $promotion : $tools;
    }

    /**
     * Recover the last unconsumed multipart promotion from the durable session ledger.
     *
     * A model may create an accepted part on its final step. The next `agent` invocation builds a
     * new registry, so the in-memory pointer above cannot be the source of truth across that
     * boundary. `session.tool_called` already records both the exact trial receipt and every later
     * promote/discard result; folding those facts restores only a still-actionable workspace.
     */
    private function recordedMultipartPromotion(): ?string
    {
        if ($this->sessions === null || $this->sessionId === null) {
            return null;
        }

        $pending = null;
        foreach ($this->sessions->stream($this->sessionId) as $event) {
            if ($event->type !== 'session.tool_called' || ($event->payload['ok'] ?? null) !== true
                || ($event->payload['awaitingConfirmation'] ?? false) === true
                || !is_string($event->payload['result'] ?? null)) {
                continue;
            }
            $result = json_decode($event->payload['result'], true);
            if (!is_array($result)) {
                continue;
            }
            $tool = $event->payload['tool'] ?? null;
            $arguments = is_array($event->payload['arguments'] ?? null) ? $event->payload['arguments'] : [];
            if ($tool === 'implement' && in_array($arguments['mode'] ?? null, ['start', 'append', 'amend', 'reset'], true)
                && ($result['ran_in_trial'] ?? null) === true && ($result['applied'] ?? null) === false
                && ($result['to_apply']['operation'] ?? null) === 'sandbox:promote'
                && is_string($result['to_apply']['arguments']['workspace'] ?? null)
                && is_string($result['output']['file'] ?? null)
                && ($result['output']['staging'] ?? null) === $result['output']['file'] . '.milpa-part'
                && is_string($result['output']['partial'] ?? null) && trim($result['output']['partial']) !== '') {
                $pending = $result['to_apply']['arguments']['workspace'];
                continue;
            }
            if (in_array($tool, ['sandbox_promote', 'sandbox_discard'], true)
                && ($arguments['workspace'] ?? null) === $pending && ($result['ok'] ?? null) === true) {
                $pending = null;
            }
        }

        $workspace = $pending === null ? null : $this->router->workspace($pending);

        return $workspace !== null && $workspace->stale() === [] ? $pending : null;
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

    /** The observer writes facts; tool output never supplies this trusted channel.
     * @param array<string, mixed> $arguments
     */
    private function recordEffect(string $tool, array $arguments, EffectObservation $observation): void
    {
        if ($this->sessions !== null && $this->sessionId !== null) {
            $this->sessions->recordEffectObservation($this->sessionId, $tool, $arguments, $observation);
        }
    }

    /** @param array<string, mixed> $args
     * @param array<string, mixed>|null $execution
     */
    private function record(TrialPlan $plan, string $operation, array $args, TrialRun $run, ?array $execution = null): void
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
            ...($execution === null ? [] : ['execution' => $execution]),
            ...($run->inputWitness === null ? [] : ['input_witness' => [
                'attempt' => $run->inputWitness->attempt->id,
                'scope' => TrialInputWitness::SCOPE,
                'status' => $run->inputWitness->status,
                'identity' => $run->inputWitness->status === 'known' ? $run->inputWitness->identity() : null,
                'complete_execution_inputs' => false,
            ]]),
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
