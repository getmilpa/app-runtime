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

use Milpa\Command\Operation;
use Milpa\AppRuntime\Auth\HostPermissionPolicy;
use Milpa\Console\McpProjector;
use Milpa\Console\OperationBoundary;
use Milpa\Console\OperationPermissionPolicy;
use Milpa\Console\PermissionCallPolicy;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\ToolRuntime\Policy\AuthorizationResult;
use Milpa\ToolRuntime\Contracts\CallPolicy;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolDefinition;

/** The house's authoring policy: current permission, concrete write set, and full export. */
final class PluginAuthoringPolicy implements CallPolicy, OperationBoundary
{
    /** The scope `screen:declare` requires — the only authority that may promote the declared screens. */
    private const SCREEN_SCOPE = 'milpa:component:data-table:*';

    public const BUILD = ['make', 'implement', 'edit', 'test'];

    /** The trial's own doors: each judges the export or the workspace it touches, whatever admitted the run. */
    private const SANDBOX = ['sandbox_promote', 'sandbox_undo', 'sandbox_discard'];

    /** The plugin state `plugins:write` exports: the registry the boot reads and the lock, by what each one is. */
    private const PLUGIN_STATE = ['storage/plugins.json' => 'registry', 'milpa.lock' => 'lock'];

    /** The seat's session this policy answers in, when a leg set one ({@see withSeatSession()}); otherwise none. */
    private ?\Milpa\Agent\SessionStore $seatStore = null;

    private ?string $seatSession = null;

    /**
     * The catalogue's operations typed by `permission`, by tool name — shared with every copy of this policy.
     *
     * @var \ArrayObject<string, Operation>
     */
    private \ArrayObject $permissioned;

    /**
     * @param (\Closure(): ?\Milpa\Agent\SessionStore)|null $sessions
     * @param JudgedPermission|null                         $judged       the HTTP policy's verdict on the permissioned
     *                                                                    operation now running; without it a mutation
     *                                                                    typed by `permission` is judged by its scopes
     * @param (\Closure(): mixed)|null                      $permissions  the host's OperationPermissionPolicy, asked
     *                                                                    for a finite caller of an operation typed by
     *                                                                    `permission`; without it that call is refused
     * @param (\Closure(): ?BuiltCapabilities)|null         $capabilities the capabilities built in this house; without
     *                                                                    them no verb is judged as a built one
     */
    public function __construct(
        private readonly string $root,
        private readonly TrialRunner $runner = new TrialRunner(),
        private readonly ?\Closure $sessions = null,
        private readonly ?JudgedPermission $judged = null,
        private readonly ?\Closure $permissions = null,
        private readonly ?\Closure $capabilities = null,
    ) {
        $this->permissioned = new \ArrayObject();
    }

    /**
     * The same policy, answering inside one seat's session: a refusal the seat's frontier would offer says who
     * grants it (greenhouse decisions/0543). The judgement does not change — only the sentence of a refusal.
     *
     * A copy of THIS class, never a wrapper: the trial executor reads `instanceof PluginAuthoringPolicy` to confine
     * a call's write set, and a decorator would silently widen it.
     */
    public function withSeatSession(\Milpa\Agent\SessionStore $store, string $session): self
    {
        $copy = clone $this;
        $copy->seatStore = $store;
        $copy->seatSession = $session;

        return $copy;
    }

    /** Install the host boundary for both the CLI catalogue and HTTP/agent projections. */
    public static function install(\Milpa\Interfaces\Di\DIContainerInterface $container, string $root): void
    {
        // The verdict follows the runs of the dispatcher the HTTP surface announces them on — only for the policy
        // that becomes the boundary, so a second install never subscribes a mark nobody takes.
        $events = !$container->has(OperationBoundary::class) && $container->has(MilpaEventDispatcherInterface::class)
            ? $container->get(MilpaEventDispatcherInterface::class) : null;
        $policy = new self(
            $root,
            sessions: static fn () => (new \Milpa\AppRuntime\Operations\AgentOperations($container))->sessionStore(),
            judged: $events instanceof MilpaEventDispatcherInterface ? JudgedPermission::listen($events) : null,
            permissions: static fn (): mixed => $container->has(OperationPermissionPolicy::class) ? $container->get(OperationPermissionPolicy::class) : null,
            capabilities: static fn (): ?BuiltCapabilities => BuiltCapabilities::ofContainer($container),
        );
        // THE JUDGE MCP AND A FINITE TERMINAL CALLER ANSWER TO (GHSA-xj7j-99jx-52hh, greenhouse decisions/0545): the
        // host's own resolver, the one its HTTP policy asks. A host that brought its own judge keeps it.
        if (!$container->has(OperationPermissionPolicy::class)) {
            $container->registerService(OperationPermissionPolicy::class, new HostPermissionPolicy($container));
        }
        if (!$container->has(CallPolicy::class)) {
            $container->registerService(CallPolicy::class, $policy);
        }
        if (!$container->has(OperationBoundary::class)) {
            $container->registerService(OperationBoundary::class, $policy);
        }
    }

    /**
     * Tells the installed boundary which of the catalogue's operations are typed by `permission`, and hands the
     * catalogue back unchanged.
     *
     * A tool definition carries `scopes` and never `permission`, so without this a permission-typed mutation reads,
     * at authorization, as one that declares no authority — and the terminal asks this policy before it asks the
     * permission judge.
     *
     * @param list<Operation> $operations
     *
     * @return list<Operation>
     */
    public static function catalogue(\Milpa\Interfaces\Di\DIContainerInterface $container, array $operations): array
    {
        $boundary = $container->has(OperationBoundary::class) ? $container->get(OperationBoundary::class) : null;
        if ($boundary instanceof self) {
            foreach ($operations as $operation) {
                if ($operation->permission !== null) {
                    $boundary->permissioned[McpProjector::toolName($operation->name)] = $operation;
                }
            }
        }

        return $operations;
    }

    /**
     * Judge the requested resource and export against this call's current authority.
     *
     * @param array<string, mixed> $arguments
     */
    public function authorize(ToolContext $context, ToolDefinition $tool, array $arguments): AuthorizationResult
    {
        return $this->verdict($context, $tool, $arguments, $this->permissioned[$tool->name] ?? null);
    }

    /**
     * The verdict for one call; `$permissioned` is the operation behind the tool when it is typed by `permission`.
     *
     * @param array<string, mixed> $arguments
     */
    private function verdict(ToolContext $context, ToolDefinition $tool, array $arguments, ?Operation $permissioned): AuthorizationResult
    {
        try {
            $name = $tool->name;
            if ($name === 'edit' && array_key_exists('source', $arguments)
                && !$context->hasScope('agent:read') && !$context->hasScope('agent:answer')) {
                throw new \RuntimeException('Recorded repair requires agent:read or agent:answer.');
            }
            if ($name === 'sandbox_promote' || $name === 'sandbox_undo') {
                $this->checkExport($context, $name, $arguments);
                return AuthorizationResult::allowed();
            }
            if ($context->hasScope('*')) {
                return AuthorizationResult::allowed();
            }
            // A VERB OF A CAPABILITY BUILT IN THIS HOUSE RUNS FOR A SEAT ONLY IF A PERSON ADMITTED IT (greenhouse
            // decisions/0590). The word it declares as its scope is its author's, and the author may be the seat:
            // measured on the published train, a capability naming its scope after one the seat already held was
            // read and written by it with no human act. So for an enrolled key the admission is the whole verdict —
            // its declared scopes are not asked here, and holding them is not asked either.
            $admissions = $this->admissions();
            $built = $admissions?->seatOf($context->principal) === null ? null : $admissions->verb($name);
            if ($admissions !== null && $built !== null) {
                $missing = $admissions->missing($context->principal, $name);

                return $missing === null
                    ? AuthorizationResult::allowed()
                    : AuthorizationResult::denied($missing->sentence() . $this->whoGrantsIt($name, $arguments));
            }
            if (in_array($name, self::BUILD, true)) {
                $this->writePaths($context, $name, $arguments);
                if (!$this->runner->available()) {
                    throw new \RuntimeException('Plugin authoring requires an available write-confined trial; the host cannot execute this call directly.');
                }
            } elseif ($name === 'sandbox_discard') {
                $workspace = $this->workspace($arguments);
                $record = json_decode((string) @file_get_contents($workspace->baseDirectory() . '/authoring.json'), true);
                $plugin = is_array($record) ? ($record['plugin'] ?? null) : null;
                $this->requirePlugin($context, $plugin);
            } elseif ($permissioned !== null) {
                // `Operation` holds `scopes` XOR `permission`: empty scopes here are a permission to judge, by the same
                // judge MCP and the terminal ask — never a pass, and never "declares no authority" (decisions/0545).
                return $this->judgePermission($permissioned, $context, $arguments);
            } elseif ($tool->mutating && $tool->scopes === []) {
                throw new \RuntimeException("Mutation '{$name}' declares no authority for a finite principal.");
            }
            return AuthorizationResult::allowed();
        } catch (MissingPermission $missing) {
            return AuthorizationResult::denied($missing->getMessage() . $this->whoGrantsIt($tool->name, $arguments));
        } catch (\Throwable $error) {
            return AuthorizationResult::denied($error->getMessage());
        }
    }

    /**
     * The caller as the tool runtime's gate is to see it for ONE call to this tool (greenhouse decisions/0590).
     *
     * That gate asks for the word a tool declares before it asks this policy, and for a seat calling a built verb
     * the word is not the question: the admission is, and this policy answers it right after. So that one call
     * carries the verb's own declared words — admitted or not; the verdict is this policy's. Any other caller, and
     * any other tool, gets the context back as it came: nothing is written to the seat, and no other operation
     * that asks for the same word ever sees it.
     */
    public function contextFor(ToolContext $context, string $tool): ToolContext
    {
        $admissions = $context->hasScope('*') ? null : $this->admissions();
        $verb = $admissions?->seatOf($context->principal) === null ? null : $admissions->verb($tool);
        $words = $verb === null ? [] : array_values(array_diff($verb->operation->scopes, $context->scopes));
        if ($words === []) {
            return $context;
        }

        return new ToolContext(
            principal: $context->principal,
            channel: $context->channel,
            scopes: [...$context->scopes, ...$words],
            request_id: $context->request_id,
            ip: $context->ip,
            userAgent: $context->userAgent,
            extra: $context->extra,
            mode: $context->mode,
            resultBudget: $context->resultBudget,
        );
    }

    /** The judge of built verbs over this house's ledger, or null when nobody told this policy what was built. */
    public function admissions(): ?CapabilityAdmissions
    {
        $built = $this->built();

        return $built === null ? null : CapabilityAdmissions::forRoot($this->root, $built);
    }

    /** What this house built, as this policy was told — or null: nobody told it, or the house built nothing. */
    private function built(): ?BuiltCapabilities
    {
        try {
            $built = $this->capabilities === null ? null : ($this->capabilities)();
        } catch (\Throwable) {
            return null;
        }

        return $built instanceof BuiltCapabilities && !$built->isEmpty() ? $built : null;
    }

    /**
     * A finite caller of an operation typed by `permission`: the host's judge decides, and without one nothing runs.
     *
     * @param array<string, mixed> $arguments
     */
    private function judgePermission(Operation $operation, ToolContext $context, array $arguments): AuthorizationResult
    {
        $judge = $this->permissions === null ? null : ($this->permissions)();
        if (!$judge instanceof OperationPermissionPolicy) {
            return AuthorizationResult::denied(\sprintf(
                "Operation '%s' requires the permission '%s' and this host wired no %s to judge it. Nothing ran.",
                $operation->name,
                $operation->permission,
                OperationPermissionPolicy::class,
            ));
        }

        return PermissionCallPolicy::judge($judge, $operation, $context, $arguments);
    }

    /**
     * For a refusal the seat's frontier would offer, the sentence that says who grants it — otherwise nothing.
     *
     * A missing scope stays a refusal (decisions/0317); what it gains is the one fact the model could not know:
     * a person can grant it in the panel, so it is not a gap in the house. Unsaid, a resident in evidence/1071
     * declared `plugins.Blog:write` the scaffolder's chicken-and-egg and ended its leg as a false `HOUSE_DEBT`.
     * The frontier decides (decisions/0496): an invented name, or a session no one enrolled, gets nothing added.
     *
     * @param array<string, mixed> $arguments
     */
    private function whoGrantsIt(string $tool, array $arguments): string
    {
        if ($this->seatStore === null || $this->seatSession === null) {
            return '';
        }
        try {
            $offered = SeatFrontier::forRoot($this->root, $this->seatStore, $this->built())->wouldOffer($this->seatSession, $tool, $arguments);
        } catch (\Throwable) {
            return '';
        }
        if (($offered['kind'] ?? null) === 'capability') {
            // Admitting is another act than granting a scope (greenhouse decisions/0590): a person sees the verb's
            // contract and approves its digest. And the house does not replay a call of the domain after it
            // (decisions/0577 resumes only what a trial confines), so the sentence must not promise that it will.
            return ' Whoever enrolled this seat admits it, seeing its contract — Agent → Decisions in the panel, or'
                . ' `identity:grant` with the digest the house shows. This is a person\'s decision, not a gap in the'
                . ' house: do not declare HOUSE_DEBT for it. The leg ends here and waits for that admission; after'
                . ' it, `continue` and make this same call again.';
        }

        return $offered === null ? '' : sprintf(
            ' Whoever enrolled this seat can grant «%s» in the panel (Agent → Decisions). This is a person\'s decision,'
            . ' not a gap in the house: do not declare HOUSE_DEBT for it. The leg ends here and waits for that grant;'
            . ' after it, `continue` runs this same call again.',
            $offered['permission'],
        );
    }

    /**
     * The permission a recorded call still lacks under this authority, or null (greenhouse decisions/0493).
     *
     * The refusal is judged again, not read back: the same resource checks {@see authorize()} runs before
     * a call executes, asked with the scopes the principal holds NOW. A refusal already granted, or a call
     * that failed for any other reason, names no permission.
     *
     * @param array<string, mixed> $arguments the arguments the call was recorded with
     */
    public function missingPermission(ToolContext $context, string $tool, array $arguments): ?string
    {
        return $this->missing($context, $tool, $arguments)?->permission;
    }

    /**
     * The refusal a recorded call still earns under this authority, with the plugin it targets — or null.
     *
     * The same judgement as {@see missingPermission()}, kept whole: a reader that must know WHICH plugin
     * a missing write scope is for reads it here, never from the scope's spelling (decisions/0496).
     *
     * @param array<string, mixed> $arguments the arguments the call was recorded with
     */
    public function missing(ToolContext $context, string $tool, array $arguments): ?MissingPermission
    {
        if ($context->hasScope('*')) {
            return null;
        }
        try {
            if (in_array($tool, self::BUILD, true)) {
                $this->writePaths($context, $tool, $arguments);
            } elseif ($tool === 'sandbox_promote' || $tool === 'sandbox_undo') {
                $this->checkExport($context, $tool, $arguments);
            } elseif ($tool === 'sandbox_discard') {
                $record = json_decode((string) @file_get_contents($this->workspace($arguments)->baseDirectory() . '/authoring.json'), true);
                $this->requirePlugin($context, is_array($record) ? ($record['plugin'] ?? null) : null);
            }
        } catch (MissingPermission $missing) {
            return $missing;
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Resolve the exact write set; null preserves the explicitly unrestricted local mode.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<string>|null
     */
    public function writePaths(ToolContext $context, string $name, array $arguments): ?array
    {
        if ($context->hasScope('*') && !($name === 'edit' && array_key_exists('source', $arguments))) {
            return null;
        }
        $plugin = $arguments['plugin'] ?? null;
        if ($name === 'test') {
            $path = $arguments['path'] ?? null;
            if (!is_string($path) || !preg_match('~^tests/Plugins/([A-Za-z_][A-Za-z0-9_]*)(?:/|$)~D', $path, $match)) {
                throw new \RuntimeException('Scoped test requires a path under tests/Plugins/<Plugin>.');
            }
            $this->regularPath($this->root, $path);
            $plugin = $match[1];
        }
        if ($name === 'edit' && isset($arguments['mode'])) {
            throw new \RuntimeException('Scoped authoring currently requires a complete implementation in one call.');
        }
        // implement's parts live beside the scaffold inside this same write set. Each part still
        // runs in a confined trial and requires an authorized promotion; only finish publishes PHP
        // after the existing verifier accepts the assembled source (greenhouse decisions/0332).
        $plugin = $this->requirePlugin($context, $plugin);
        $paths = ['src/Plugins/' . $plugin, 'tests/Plugins/' . $plugin];
        foreach ($paths as $path) {
            $this->regularPath($this->root, $path);
        }
        return $paths;
    }

    /**
     * The operation boundary covers direct CLI/HTTP/MCP execution as well as the agent's registry.
     *
     * @param array<string, mixed> $input
     * @param \Closure(): mixed    $next
     */
    public function execute(Operation $operation, array $input, ?ToolContext $authority, \Closure $next): mixed
    {
        $context = $authority ?? ToolContext::cli();
        $name = McpProjector::toolName($operation->name);
        // A PERMISSION IS JUDGED FOR THE OPERATION THAT RUNS (greenhouse decisions/0544). `Operation` holds `scopes`
        // XOR `permission`, so empty scopes are not "declares no authority" when the host's HTTP policy admitted this
        // very run. Asked on every run, so a verdict never waits for a later one; authoring and sandbox calls keep
        // their own checks.
        if ($this->judged?->take($operation) === true && !in_array($name, [...self::BUILD, ...self::SANDBOX], true)) {
            return $next();
        }
        $tool = new ToolDefinition(
            $name,
            $operation->description,
            $operation->inputSchema ?? [],
            $operation->handler,
            scopes: $operation->scopes,
            mutating: $operation->mutating
        );
        $verdict = $this->verdict($context, $tool, $input, $operation->permission !== null ? $operation : null);
        if (!$verdict->allowed) {
            throw new \RuntimeException((string) $verdict->reason);
        }
        $recorded = RecordedEdit::usesSource($operation, $input);
        if (($context->hasScope('*') && !$recorded) || !in_array($name, self::BUILD, true)) {
            return $next();
        }
        $prepared = null;
        if ($recorded) {
            $sessions = $this->sessions === null ? null : ($this->sessions)();
            if ($sessions === null) {
                throw new \RuntimeException('Recorded repair requires the host session store.');
            }
            $prepared = (new RecordedEdit($this->root, $sessions))->prepare($input, $context);
        }
        $router = new TrialRouter($this->root, $this->runner, dirname(__DIR__, 2) . '/resources/trial-run.php', confinedTesting: true);
        $plan = $router->planFor($operation, $input);
        if ($plan === null) {
            throw new \RuntimeException('This authoring call cannot run in a confined trial.');
        }
        if ($prepared !== null) {
            RecordedEdit::assertBaseline($plan->workspace->copy, $prepared['provenance']['subject'], $prepared['provenance']['baseline_sha256']);
        }
        $run = $this->runner->run(
            $plan->workspace,
            $prepared === null ? $operation->name : 'implement',
            $prepared['input'] ?? $input,
            $this->writePaths($context, $name, $input)
        );
        if (!$run->ok()) {
            return ['ok' => false, 'error' => $run->output['error'] ?? ($run->stderr !== '' ? $run->stderr : 'the trial did not succeed'),
                'workspace' => $plan->workspace->id, 'output' => $run->output,
                ...($prepared === null ? [] : ['repair' => $prepared['provenance']])];
        }
        $data = ['ok' => true, 'ran_in_trial' => true, 'applied' => false, 'workspace' => $plan->workspace->id,
            'changed' => array_map(static fn (array $entry): string => $entry['status'], $run->report), 'output' => $run->output];
        if ($prepared !== null) {
            $data['repair'] = $prepared['provenance'];
        }
        if ($run->report !== []) {
            $data['to_apply'] = ['operation' => 'sandbox:promote', 'arguments' => ['workspace' => $plan->workspace->id]];
        }
        return $data;
    }

    /**
     * Inspect every path before a handler may write the first one.
     *
     * @param array<string, mixed> $arguments
     */
    private function checkExport(ToolContext $context, string $name, array $arguments): void
    {
        if ($name === 'sandbox_promote') {
            $workspace = $this->workspace($arguments);
            $this->authorizePaths($context, array_keys($workspace->diff()), $workspace->copy);
            return;
        }
        $id = $arguments['workspace'] ?? null;
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]+$/D', $id)) {
            throw new \RuntimeException('A workspace must name one trial directory.');
        }
        $base = $this->root . '/var/trials/' . $id;
        $record = json_decode((string) @file_get_contents($base . '/promoted.json'), true);
        if (!is_array($record) || $record === []) {
            throw new \RuntimeException('No recorded promotion to undo.');
        }
        $this->authorizePaths($context, array_keys($record), $base . '/pre');
    }

    /**
     * Reject the entire export when any source or destination is outside the current write set.
     *
     * @param list<string> $paths
     */
    public function authorizePaths(ToolContext $context, array $paths, string $source): void
    {
        foreach ($paths as $path) {
            $this->regularPath($this->root, $path);
            $this->regularPath($source, $path);
            if ($context->hasScope('*')) {
                continue;
            }
            // `plugins:register` is rehearsed like every other reversible local mutation, but its
            // artifact is the house's explicit plugin list rather than a file below one plugin's
            // source tree. Promotion must preserve that operation's own narrow authority: the
            // principal that may write plugin configuration may export this one file, and nothing
            // else under config/. Without this branch the trial truthfully returned a
            // sandbox:promote next step that could never cross the same boundary.
            if ($path === 'config/plugins.php') {
                if (!$context->hasScope('plugins.config:write')) {
                    throw new MissingPermission('plugins.config:write', "Missing required permission 'plugins.config:write' for plugin configuration.");
                }

                continue;
            }
            // The plugin switch crosses the same way (greenhouse decisions/0532). `plugins.enable` / `plugins.disable`
            // write the registry the boot reads, and `plugins.lock` writes the lock — all three under
            // `plugins:write`. Since 0530 they run inside a leg's trial, and without this branch a seat could
            // switch a plugin in the rehearsal and never in the house. The same authority, and nothing else.
            if (isset(self::PLUGIN_STATE[$path])) {
                if (!$context->hasScope('plugins:write')) {
                    throw new MissingPermission('plugins:write', "Missing required permission 'plugins:write' for the plugin " . self::PLUGIN_STATE[$path] . '.');
                }

                continue;
            }
            // The same shape for the house's declared screens (greenhouse decisions/0463): a screen is
            // rehearsed in a trial like any work and crosses on promotion — with the authority of the
            // operation that writes it, and nothing else under config/.
            if ($path === \Milpa\AppRuntime\Web\ScreenStore::DEFAULT_PATH) {
                if (!$context->hasScope(self::SCREEN_SCOPE)) {
                    throw new MissingPermission(self::SCREEN_SCOPE, "Missing required permission '" . self::SCREEN_SCOPE . "' for declared screens.");
                }

                continue;
            }
            // The house's visual language crosses the same way (decisions/0465), with the authority of
            // the operation that writes it.
            if ($path === \Milpa\AppRuntime\Web\ComponentWords::PATH) {
                if (!$context->hasScope(\Milpa\AppRuntime\Web\ComponentWordOperations::SCOPE)) {
                    throw new MissingPermission(\Milpa\AppRuntime\Web\ComponentWordOperations::SCOPE, "Missing required permission '" . \Milpa\AppRuntime\Web\ComponentWordOperations::SCOPE . "' for the house's words.");
                }

                continue;
            }
            if (!preg_match('~^(?:src|tests)/Plugins/([A-Za-z_][A-Za-z0-9_]*)/~D', $path, $match)) {
                throw new \RuntimeException("Export '{$path}' is outside a plugin write set.");
            }
            $this->requirePlugin($context, $match[1]);
        }
    }

    /** @param array<string, mixed> $arguments */
    private function workspace(array $arguments): TrialWorkspace
    {
        $id = $arguments['workspace'] ?? null;
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]+$/D', $id)) {
            throw new \RuntimeException('A workspace must name one trial directory.');
        }
        $workspace = TrialWorkspace::open($this->root, $id);
        if ($workspace === null) {
            throw new \RuntimeException("No trial '{$id}' to inspect.");
        }
        return $workspace;
    }

    /** Require a canonical resource and the exact Permission-compatible scope for it. */
    private function requirePlugin(ToolContext $context, mixed $plugin): string
    {
        if (!is_string($plugin) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $plugin)) {
            throw new \RuntimeException('Scoped authoring requires one canonical plugin name.');
        }
        $permission = 'plugins.' . $plugin . ':write';
        if (!$context->hasScope($permission)) {
            $message = "Missing required permission '{$permission}' for plugin '{$plugin}'.";
            $matching = [];
            foreach (array_filter($context->scopes, 'is_string') as $scope) {
                if (preg_match('/^plugins\.([A-Za-z_][A-Za-z0-9_]*):write$/D', $scope, $parts) !== 1) {
                    continue;
                }
                if ($parts[1] !== $plugin && strcasecmp($parts[1], $plugin) === 0) {
                    $matching[$scope] = true;
                }
            }
            if (count($matching) === 1) {
                $scope = array_key_first($matching);
                $message .= " Plugin identifiers and grants are case-sensitive. The current grant is '{$scope}'."
                    . ' Verify the installed plugin identifier before requesting a different permission.';
            }
            // A fact of the disk, not a guess about the goal: this policy never reads the session. The
            // skeleton only shows `<Something>Plugin` directories, and a resident told to build «a plugin
            // named Blog» asked for `BlogPlugin` three times on the bare refusal (evidence/1028).
            if (!$this->pluginExists($plugin)) {
                $message .= " No plugin '{$plugin}' exists in this house yet. A new plugin takes exactly the name the task gives it.";
            }
            throw new MissingPermission($permission, $message, $plugin);
        }
        return $plugin;
    }

    /**
     * Whether the house already has this plugin: its source directory, the one its write scope would open.
     */
    public function pluginExists(string $plugin): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $plugin) === 1 && is_dir($this->root . '/src/Plugins/' . $plugin);
    }

    /** Refuse traversal, aliases and links in either the source or destination tree. */
    private function regularPath(string $base, string $relative): void
    {
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\') || str_contains($relative, "\0")) {
            throw new \RuntimeException('An authoring path must be relative to the house.');
        }
        $path = $base;
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \RuntimeException('An authoring path must not contain traversal or empty segments.');
            }
            $path .= '/' . $segment;
            if (is_link($path)) {
                throw new \RuntimeException("Authoring refuses a symbolic link at '{$relative}'.");
            }
        }
    }
}
