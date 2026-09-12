<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Web;

use Milpa\Live\Contracts\Transport\StateTransferCodecInterface;
use Milpa\Live\Runtime\InMemoryComponentRegistry;
use Milpa\Live\Rendering\ComponentRendererRegistry;

/** Explicit factories for isolated preview definitions AND renderers; never fall back to live collaborators. */
final class ScreenPreviewRegistry
{
    /** @var array<string,\Closure(PreviewEnvironment):void> */
    private array $factories = [];
    /** The app opts in by constructing its preview graph with separate persistence/effects. */
    public function register(string $type, \Closure $factory): void
    {
        $this->factories[$type] = $factory;
    }
    /**
     * Types with explicitly isolated preview factories.
     *
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys($this->factories);
    }
    /**
     * Build a fresh isolated graph for one immutable revision.
     *
     * @param array<string,mixed> $props
     */
    public function build(string $id, StateTransferCodecInterface $codec, string $type, array $props): PreviewEnvironment
    {
        $env = new PreviewEnvironment($id, $codec, new InMemoryComponentRegistry(), new ComponentRendererRegistry());
        $visit = function (string $type, array $props) use (&$visit, $env): void {
            if (!isset($this->factories[$type])) {
                throw new \DomainException('preview_not_configured');
            }
            if (!$env->components->has($type)) {
                ($this->factories[$type])($env);
            }
            if (!$env->components->has($type) || $env->components->get($type)::contract()->name !== $type || $env->renderers->resolveFor($type, \Milpa\Live\ValueObjects\RenderTarget::HTML) === null) {
                throw new \DomainException('preview_not_configured');
            }
            foreach (ScreenTree::children($type, $props) as $child) {
                $visit($child['type'], $child['props'] ?? []);
            }
        };
        $visit($type, $props);
        return $env;
    }
}
