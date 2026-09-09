<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app installs, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Web;

use Milpa\AppRuntime\Web\Controllers\PasskeyController;
use Milpa\AppRuntime\Web\PasskeyPlugin;
use Milpa\Container\DIContainer;
use Milpa\Runtime\Config;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * The passkey ceremony looks like the house, and does not need the panel to do it (greenhouse
 * decisions/0243).
 *
 * The two pages carried `system-ui` and hand-picked hex — not out of neglect, but because the design
 * system only travelled by being copied into a package's own assets, and this package vendors
 * nothing. Enrolling is the PRECONDITION of having a panel session, so the pages cannot depend on
 * the panel: a house with no panel still has to be able to let somebody in.
 */
final class TheCeremonyLooksLikeTheHouseTest extends TestCase
{
    /** The route exists, beside the ceremony and not behind the gate. */
    public function testTheTokensAreServedBesideTheCeremony(): void
    {
        // `routes()` answers nothing until `boot()` reads the relying party — a plugin with no rpId
        // declares no door, which is the correct default and the reason this has to boot first.
        $plugin = new PasskeyPlugin($this->container());
        $plugin->boot();

        $paths = [];
        foreach ($plugin->routes() as $route) {
            $paths[] = $route->path;
        }

        self::assertContains('/webauthn/milpa-tokens.css', $paths);
        self::assertContains('/webauthn/enroll', $paths, 'the page that needs it is on the same prefix');
    }

    /**
     * IT SERVES THE HOUSE'S TOKENS, and carries the one the vendored copies lost.
     *
     * `--space-32` is what proved the drift: three packages with identical copies, all three missing
     * it. If this served a fourth copy, this assertion is what fails.
     */
    public function testItServesTheHousesTokensAndNotAFourthCopy(): void
    {
        $response = $this->tokensResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/css; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $css = (string) $response->getBody();
        self::assertStringContainsString('--tierra-950', $css);
        self::assertStringContainsString('--space-32', $css, 'the token the vendored copies lost');
    }

    /**
     * THE ONE THAT CAN SAY NO: neither page carries a colour of its own any more.
     *
     * A single hand-written hex in these pages is a fourth copy of the design system with extra
     * steps, and it is exactly how the drift started.
     */
    public function testNeitherPageCarriesAColourOfItsOwn(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Web/Controllers/PasskeyController.php');

        self::assertSame(0, preg_match_all('/#[0-9a-fA-F]{3,6}\b/', $source), 'a hex literal is a token that was not asked for');
        self::assertStringNotContainsString('system-ui', $source, 'the house has its own faces');
    }

    /** Both pages link the stylesheet — one styled and one bare would be worse than neither. */
    public function testBothPagesLinkTheStylesheet(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Web/Controllers/PasskeyController.php');

        self::assertSame(2, substr_count($source, '/webauthn/milpa-tokens.css'), 'enroll and signin — one styled and one bare would be worse than neither');
    }

    private function tokensResponse(): \Psr\Http\Message\ResponseInterface
    {
        // The stylesheet needs nothing but the package: no store, no ceremony, no session — which is
        // the point, because it is served to somebody who does not have one yet.
        $controller = new \ReflectionClass(PasskeyController::class);

        return $controller->newInstanceWithoutConstructor()->tokens(new ServerRequest('GET', '/webauthn/milpa-tokens.css'));
    }

    /** A container that declares an rpId, because a plugin without one declares no routes at all. */
    private function container(): DIContainer
    {
        $container = new DIContainer();
        $container->registerService(Config::class, new Config(['passkey' => ['rpId' => 'localhost']]));

        return $container;
    }
}
