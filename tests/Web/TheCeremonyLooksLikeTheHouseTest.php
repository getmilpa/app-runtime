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
use Milpa\AppRuntime\Web\Live\GateCeremonyAssets;
use Milpa\AppRuntime\Web\PasskeyPlugin;
use Milpa\Container\DIContainer;
use Milpa\Live\Support\DesignTokens;
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
     * THE ONE THAT CAN SAY NO: neither page carries a colour of its own — not even the mark's gold.
     *
     * A hand-written hex in these pages is a fourth copy of the design system with extra steps, and
     * it is exactly how the drift started. This used to allow exactly one — the mark's gold, which
     * the logo kit mandates as constant in both themes because a logo is brand and not UI. It no
     * longer allows even that: the mark became a component and took its gold with it, so the count
     * that was one is now zero.
     *
     * The pair matters. Source with no colour and a page that renders none would just be a page
     * without a mark, so the second half asks the RENDERED page whether the gold arrived — from the
     * component, not from here.
     */
    public function testNeitherPageCarriesAColourOfItsOwn(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Web/Controllers/PasskeyController.php');

        preg_match_all('/#[0-9a-fA-F]{3,6}\b/', $source, $hexes);

        self::assertSame([], array_values(array_unique($hexes[0])), 'the ceremony states no colour at all now');
        self::assertStringNotContainsString('system-ui', $source, 'the house has its own faces');
        self::assertStringContainsString(
            DesignTokens::MARK_GOLD,
            (string) $this->page('enrollPage')->getBody(),
            'the gold must still reach the page — carried by the component, not written here',
        );
    }

    /**
     * Both pages link the stylesheets — one styled and one bare would be worse than neither.
     *
     * This used to count occurrences in the CONTROLLER'S SOURCE and expect exactly two, one per
     * hand-written template. There is one composition now, so the source says it once and the count
     * measured the shape rather than the property. Asked of the SERVED pages instead, which is what
     * the sentence above actually claims — and it keeps holding whatever the pages are built from
     * (greenhouse decisions/0263).
     */
    public function testBothPagesLinkTheStylesheet(): void
    {
        foreach (['enrollPage', 'signinPage'] as $handler) {
            $body = (string) $this->page($handler)->getBody();

            self::assertStringContainsString('/webauthn/milpa-tokens.css', $body, "$handler links the house's tokens");
            self::assertStringContainsString('/webauthn/milpa-fonts.css', $body, "$handler links the house's faces");
            self::assertStringContainsString(GateCeremonyAssets::url(GateCeremonyAssets::STYLESHEET), $body, "$handler links the ceremony's own sheet");
        }
    }

    /**
     * Every asset route the door DECLARES actually SERVES — 200, its own content type, real bytes.
     *
     * The routes were asserted to exist and nothing asserted they answered, so a path pointing at a
     * file the package does not ship passed both: the wordmark route was a 404 for as long as
     * `milpa/live-web` was pinned below the version that carries it, and every test stayed green.
     * Driven from the plugin's own routes rather than a list written here, so a route added later
     * cannot quietly skip this.
     */
    public function testEveryAssetRouteTheDoorDeclaresActuallyServes(): void
    {
        $plugin = new PasskeyPlugin($this->container());
        $plugin->boot();

        $controller = (new \ReflectionClass(PasskeyController::class))->newInstanceWithoutConstructor();
        $faces = array_filter(array_keys(DesignTokens::defaultUrls()), static fn (string $n): bool => str_ends_with($n, '.woff2'));
        $served = 0;

        foreach ($plugin->routes() as $route) {
            if (!preg_match('#\.(css|svg)$#', $route->path) && !str_contains($route->path, '{face}')) {
                continue;
            }

            $path = str_replace('{face}', (string) reset($faces), $route->path);
            $response = $controller->tokens(new ServerRequest('GET', $path));

            self::assertSame(200, $response->getStatusCode(), $path . ' is declared and does not serve');
            self::assertNotSame('', (string) $response->getBody(), $path . ' serves nothing');
            self::assertSame(
                DesignTokens::contentType(basename($path)),
                $response->getHeaderLine('Content-Type'),
                $path . ' is served as somebody else\'s kind',
            );
            ++$served;
        }

        self::assertSame(4, $served, 'the four things the door wears: tokens, the faces stylesheet, a face, and the wordmark');
    }

    /**
     * F5 of greenhouse `decisions/0246` — the falsifier that can say no.
     *
     * The claim is that a component travels WHOLE: if the ceremony had to write rules of its own to
     * make the mark look right, the component would be a template with extra steps and the
     * architecture would be wrong. So this asks two things that must both hold at once — the
     * ceremony's source states nothing about the mark, and the rendered page has everything the
     * mark needs. Either half alone is satisfiable by a page with no mark on it.
     */
    public function testTheCeremonyWritesNoRuleForTheMarkAndTheMarkStillArrivesComplete(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Web/Controllers/PasskeyController.php');

        self::assertStringNotContainsString('@keyframes', $source, 'the mark took its animations with it');
        self::assertStringNotContainsString('.grano', $source, 'the mark took its selectors with it');
        // The CSS AT-RULE, not the bare phrase: the ceremony still consults reduced motion in JS to
        // decide how long to wait before it navigates, and that is its own concern, not the mark's.
        self::assertStringNotContainsString('@media (prefers-reduced-motion', $source, 'the mark took its reduced-motion rules with it');

        foreach (['enrollPage', 'signinPage'] as $handler) {
            $html = (string) $this->page($handler)->getBody();

            self::assertStringContainsString('data-milpa-component="brand-mark"', $html, $handler . ' renders the mark');
            self::assertSame(3, substr_count($html, '@keyframes milpa-mark-'), $handler . ' carries the mark\'s three states');
            self::assertStringContainsString('[data-milpa-component="brand-mark"][data-state="growing"]', $html, $handler . ' carries the scoped state rules');
            self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $html, $handler . ' still answers reduced motion, from the component');
            self::assertStringContainsString(DesignTokens::MARK_GOLD, $html, $handler . ' carries the brand gold');
        }
    }

    /**
     * Either ceremony page, rendered. It needs no store and no session — which is the point, since
     * it is served to somebody who does not have one yet.
     */
    private function page(string $handler): \Psr\Http\Message\ResponseInterface
    {
        $class = new \ReflectionClass(PasskeyController::class);
        $controller = $class->newInstanceWithoutConstructor();

        // Every property the page path reads, seeded — a promoted readonly left uninitialised is a
        // TypeError at first access, not a null (greenhouse decisions/0263).
        foreach (['rpId' => 'localhost', 'gateScope' => 'milpa.admin', 'authenticatorAttachment' => null, 'events' => null] as $property => $value) {
            if ($class->hasProperty($property)) {
                $reflected = $class->getProperty($property);
                $reflected->setAccessible(true);
                $reflected->setValue($controller, $value);
            }
        }

        return $controller->{$handler}(new ServerRequest('GET', '/webauthn/enroll'));
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
