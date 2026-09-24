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
use Milpa\Console\McpProjector;
use Milpa\Console\OperationBoundary;
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

    /** @param (\Closure(): ?\Milpa\Agent\SessionStore)|null $sessions */
    public function __construct(
        private readonly string $root,
        private readonly TrialRunner $runner = new TrialRunner(),
        private readonly ?\Closure $sessions = null,
    ) {
    }

    /** Install the host boundary for both the CLI catalogue and HTTP/agent projections. */
    public static function install(\Milpa\Interfaces\Di\DIContainerInterface $container, string $root): void
    {
        $policy = new self($root, sessions: static fn () => (new \Milpa\AppRuntime\Operations\AgentOperations($container))->sessionStore());
        if (!$container->has(CallPolicy::class)) {
            $container->registerService(CallPolicy::class, $policy);
        }
        if (!$container->has(OperationBoundary::class)) {
            $container->registerService(OperationBoundary::class, $policy);
        }
    }

    /**
     * Judge the requested resource and export against this call's current authority.
     *
     * @param array<string, mixed> $arguments
     */
    public function authorize(ToolContext $context, ToolDefinition $tool, array $arguments): AuthorizationResult
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
            } elseif ($tool->mutating && $tool->scopes === []) {
                throw new \RuntimeException("Mutation '{$name}' declares no authority for a finite principal.");
            }
            return AuthorizationResult::allowed();
        } catch (\Throwable $error) {
            return AuthorizationResult::denied($error->getMessage());
        }
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
        $tool = new ToolDefinition(
            $name,
            $operation->description,
            $operation->inputSchema ?? [],
            $operation->handler,
            scopes: $operation->scopes,
            mutating: $operation->mutating
        );
        $verdict = $this->authorize($context, $tool, $input);
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
                    throw new \RuntimeException("Missing required permission 'plugins.config:write' for plugin configuration.");
                }

                continue;
            }
            // The same shape for the house's declared screens (greenhouse decisions/0463): a screen is
            // rehearsed in a trial like any work and crosses on promotion — with the authority of the
            // operation that writes it, and nothing else under config/.
            if ($path === \Milpa\AppRuntime\Web\ScreenStore::DEFAULT_PATH) {
                if (!$context->hasScope(self::SCREEN_SCOPE)) {
                    throw new \RuntimeException("Missing required permission '" . self::SCREEN_SCOPE . "' for declared screens.");
                }

                continue;
            }
            // The house's visual language crosses the same way (decisions/0465), with the authority of
            // the operation that writes it.
            if ($path === \Milpa\AppRuntime\Web\ComponentWords::PATH) {
                if (!$context->hasScope(\Milpa\AppRuntime\Web\ComponentWordOperations::SCOPE)) {
                    throw new \RuntimeException("Missing required permission '" . \Milpa\AppRuntime\Web\ComponentWordOperations::SCOPE . "' for the house's words.");
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
            throw new \RuntimeException($message);
        }
        return $plugin;
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
