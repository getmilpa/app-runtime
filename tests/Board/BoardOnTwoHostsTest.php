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

namespace Milpa\AppRuntime\Tests\Board;

use Milpa\AppRuntime\Board\BoardComponent;
use Milpa\AppRuntime\Board\BoardHtmlRenderer;
use Milpa\AppRuntime\Board\BoardTuiRenderer;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderTarget;
use Milpa\Live\Rendering\ComponentRendererRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The refuting slice of "web/desktop as hosts" (greenhouse evidence/0295): the agent board is ONE
 * Live component definition, rendered to TWO hosts through Milpa Live's render-target seam. If the
 * same unadapted BoardComponent reaches both HTML and TUI with its fold data, the board fits the
 * "one component, many hosts" model — and a desktop host is a later render target, not a third
 * bespoke rendering. If it cannot, the vision's substrate is wrong and no host work should start.
 */
final class BoardOnTwoHostsTest extends TestCase
{
    /** @return array<string, mixed> the agent:board fold shape, as props */
    private function fold(): array
    {
        return ['columns' => [
            'done' => [['id' => 'turn:15', 'text' => 'plugins_list']],
            'in_progress' => [],
            'pending' => [],
            'blocked' => [],
        ]];
    }

    private function requestFor(RenderTarget $target): RenderRequest
    {
        return new RenderRequest(
            context: new ComponentContext(componentId: 'board-1'),
            props: $this->fold(),
            state: null,
            target: $target,
        );
    }

    public function testOneDefinitionReachesBothHtmlAndTui(): void
    {
        $definition = new BoardComponent();   // ONE definition, nobody adapts it per host

        $html = (new BoardHtmlRenderer())->render($definition, $this->requestFor(RenderTarget::HTML));
        self::assertSame(RenderTarget::HTML, $html->format);
        self::assertNotNull($html->state, 'a mounted render hands back the snapshot it drew');
        self::assertStringContainsString('plugins_list', $html->output, 'the card text reaches HTML');

        $tui = (new BoardTuiRenderer())->render($definition, $this->requestFor(RenderTarget::TUI));
        self::assertSame(RenderTarget::TUI, $tui->format);
        self::assertStringContainsString('plugins_list', $tui->output, 'the SAME card text reaches the terminal');
    }

    public function testEachRendererDeclaresItsTargetAndRefusesTheOthers(): void
    {
        self::assertTrue((new BoardHtmlRenderer())->supportsTarget(RenderTarget::HTML));
        self::assertFalse((new BoardHtmlRenderer())->supportsTarget(RenderTarget::TUI));
        self::assertTrue((new BoardTuiRenderer())->supportsTarget(RenderTarget::TUI));
        self::assertFalse((new BoardTuiRenderer())->supportsTarget(RenderTarget::HTML));
    }
    public function testTheRegistryDispatchesByTargetToTheSameDefinition(): void
    {
        // A host resolves a renderer by the target it declares — not by knowing the component.
        $registry = new ComponentRendererRegistry();
        $registry->register(new BoardHtmlRenderer());
        $registry->register(new BoardTuiRenderer());

        $definition = new BoardComponent();

        $htmlRenderer = $registry->resolve(RenderTarget::HTML);
        $tuiRenderer = $registry->resolve(RenderTarget::TUI);
        self::assertNotNull($htmlRenderer);
        self::assertNotNull($tuiRenderer);

        $html = $htmlRenderer->render($definition, $this->requestFor(RenderTarget::HTML));
        $tui = $tuiRenderer->render($definition, $this->requestFor(RenderTarget::TUI));

        self::assertStringContainsString('plugins_list', $html->output);
        self::assertStringContainsString('plugins_list', $tui->output);
        self::assertSame(RenderTarget::HTML, $html->format);
        self::assertSame(RenderTarget::TUI, $tui->format);
    }
}
