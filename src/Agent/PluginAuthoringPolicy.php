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
    public const BUILD = ['make', 'implement', 'edit', 'test'];

    public function __construct(private readonly string $root, private readonly TrialRunner $runner = new TrialRunner())
    {
    }

    /** Install the host boundary for both the CLI catalogue and HTTP/agent projections. */
    public static function install(\Milpa\Interfaces\Di\DIContainerInterface $container, string $root): void
    {
        $policy = new self($root);
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
        if ($context->hasScope('*')) {
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
        if (($name === 'implement' || $name === 'edit') && isset($arguments['mode'])) {
            throw new \RuntimeException('Scoped authoring currently requires a complete implementation in one call.');
        }
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
        if ($context->hasScope('*') || !in_array($name, self::BUILD, true)) {
            return $next();
        }
        $router = new TrialRouter($this->root, $this->runner, dirname(__DIR__, 2) . '/resources/trial-run.php', confinedTesting: true);
        $plan = $router->planFor($operation, $input);
        if ($plan === null) {
            throw new \RuntimeException('This authoring call cannot run in a confined trial.');
        }
        $run = $this->runner->run($plan->workspace, $operation->name, $input, $this->writePaths($context, $name, $input));
        if (!$run->ok()) {
            return ['ok' => false, 'error' => $run->output['error'] ?? ($run->stderr !== '' ? $run->stderr : 'the trial did not succeed'),
                'workspace' => $plan->workspace->id, 'output' => $run->output];
        }
        $data = ['ok' => true, 'ran_in_trial' => true, 'applied' => false, 'workspace' => $plan->workspace->id,
            'changed' => array_map(static fn (array $entry): string => $entry['status'], $run->report), 'output' => $run->output];
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
            throw new \RuntimeException("Missing required permission '{$permission}' for plugin '{$plugin}'.");
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
