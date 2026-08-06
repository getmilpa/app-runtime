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

use Milpa\AppRuntime\Web\BoardPage;
use PHPUnit\Framework\TestCase;

/**
 * The read-only shell: what the page promises before any data arrives.
 *
 * The painting itself is verified in {@see BoardPainterTest} against the same JavaScript artifact
 * the browser runs. Here the claims are the shell's: nothing writable, nothing pretended, nothing
 * unescaped.
 */
final class BoardPageTest extends TestCase
{
    /**
     * Step 2 of the board spec is read-only BY CONSTRUCTION, not by discipline: the page carries no
     * control that could write. Approving has its own shape and its own step, gated on identity.
     */
    public function testThePageCarriesNoWritableControl(): void
    {
        $html = (new BoardPage())->render('org-1', '/agent/board', 'https://hub.example/.well-known/mercure');

        self::assertStringNotContainsStringIgnoringCase('<button', $html);
        self::assertStringNotContainsStringIgnoringCase('<form', $html);
        self::assertStringNotContainsStringIgnoringCase('<input', $html);
        self::assertStringNotContainsStringIgnoringCase('<textarea', $html);
    }

    /** The one painter ships inside the page: the browser runs exactly what the test suite ran. */
    public function testThePageEmbedsThePainterAndAsksForTheRightFold(): void
    {
        $html = (new BoardPage())->render('org-1', '/agent/board', null);

        self::assertStringContainsString('MilpaBoard', $html);
        self::assertStringContainsString('/agent/board?session=org-1', $html);
    }

    /**
     * The look is `@milpa/design`'s, consumed from the CDN BY A PINNED VERSION — a floating tag
     * would let the surface change skin without anyone deciding it.
     */
    public function testTheDesignSystemComesFromTheCdnPinnedByVersion(): void
    {
        $html = (new BoardPage())->render('org-1', '/agent/board', null);

        self::assertStringContainsString('https://unpkg.com/@milpa/design@0.9.0/dist/milpa-tokens.css', $html);
        self::assertStringNotContainsString('@milpa/design@latest', $html);
    }

    /**
     * A session id is data, and data that becomes markup or syntax is an injection. The id is
     * chosen by whoever starts a session — this surface must survive a hostile one.
     */
    public function testAHostileSessionIdLandsAsTextNeverAsMarkup(): void
    {
        $hostile = '"><script>alert(1)</script>';
        $html = (new BoardPage())->render($hostile, '/agent/board', 'https://hub.example/.well-known/mercure');

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('"><script>', $html);
    }

    /**
     * No hub is not a broken hub: the page says the view only repaints on reload instead of
     * wearing a live face. A surface that offers what it cannot do teaches that the listing lies
     * (ADR-0040).
     */
    public function testWithoutAHubThePageSaysSoInsteadOfPretending(): void
    {
        $html = (new BoardPage())->render('org-1', '/agent/board', null);

        self::assertStringContainsString('no live push wired', $html);
        self::assertStringNotContainsString('new EventSource(null)', $html);
    }

    /** With a hub, the subscription names the session's exact topic — the one the bridge publishes. */
    public function testWithAHubThePageSubscribesToTheSessionTopic(): void
    {
        $html = (new BoardPage())->render('org-1', '/agent/board', 'https://hub.example/.well-known/mercure');

        self::assertStringContainsString(
            'https://hub.example/.well-known/mercure?topic=' . rawurlencode('milpa/sessions/org-1'),
            $html,
        );
    }

    /** With JavaScript off, the page names the terminal command that answers the same question. */
    public function testWithoutJavascriptThePageNamesTheHonestAlternative(): void
    {
        $html = (new BoardPage())->render('org-1', '/agent/board', null);

        self::assertStringContainsString('<noscript>', $html);
        self::assertStringContainsString('agent:board --session=org-1', $html);
    }
}
