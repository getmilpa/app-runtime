<?php
/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace App\ReadingSession;

use Milpa\AppRuntime\Web\{PreviewEnvironment, ScreenPreviewRegistry};
use Milpa\Attributes\PluginMetadata;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Live\Adapters\Alpine\AlpineRuntimeAdapter;
use Milpa\Live\Components\Form\InputComponent;
use Milpa\Live\Contracts\Component\{ComponentRegistryInterface, DeclaresComponents};
use Milpa\Live\Contracts\Transport\StateTransferCodecInterface;
use Milpa\Live\Rendering\{ComponentRendererRegistry, FormPrimitiveHtmlRenderer};

#[PluginMetadata(name: 'ReadingSession', version: '0.1.0', author: 'Rodrigo Vicente - TeamX Agency', site: 'https://github.com/getmilpa/greenhouse', type: 'Web')]
final readonly class ReadingSessionPlugin implements PluginInterface, DeclaresComponents
{
    public function __construct(private DIContainerInterface $container) {}
    public function declaredComponents(): array { return [ReadingSession::class]; }

    public function boot(): void
    {
        $components = $this->container->get(ComponentRegistryInterface::class);
        $renderers = $this->container->get(ComponentRendererRegistry::class);
        $components->register('reading-session', new ReadingSession());
        $renderers->registerFor('reading-session', new ReadingSessionRenderer(
            $this->container->get(StateTransferCodecInterface::class), $components, $renderers));
        if ($this->container->has(ScreenPreviewRegistry::class)) {
            $this->container->get(ScreenPreviewRegistry::class)->register('reading-session', static function (PreviewEnvironment $env): void {
                $env->components->register('input', new InputComponent());
                $env->components->register('reading-session', new ReadingSession());
                $env->renderers->registerFor('input', new FormPrimitiveHtmlRenderer(new AlpineRuntimeAdapter(), $env->codec));
                $env->renderers->registerFor('reading-session', new ReadingSessionRenderer($env->codec, $env->components, $env->renderers));
            });
        }
    }

    public function install(): void {}
    public function uninstall(): void {}
    public function enable(): void {}
    public function disable(): void {}
}
