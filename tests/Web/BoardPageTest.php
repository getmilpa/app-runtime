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
 * The shell: what the page promises before any data arrives.
 *
 * The painting itself is verified in {@see BoardPainterTest} against the same JavaScript artifact
 * the browser runs — and the painter still renders no writable control. Here the claims are the
 * shell's: the one write is gated on identity by construction, nothing pretended, nothing
 * unescaped.
 */
final class BoardPageTest extends TestCase
{
    /**
     * Step 3 of the board spec: the page carries ONE write — answering — and it is disarmed by
     * construction. The bar is born hidden, the buttons born disabled, and the token field is a
     * password input no browser autofills: until an identity is in hand, nothing on this surface
     * can touch the stream. The page gating is a courtesy — THE gate is `agent:answer` refusing
     * a non-terminal caller without a verified actor (criteria 3–4, SessionOperationsTest).
     */
    public function testTheAnswerControlsAreBornHiddenAndDisarmed(): void
    {
        $html = (new BoardPage())->render('org-1', '/agent/board', 'https://hub.example/.well-known/mercure');

        self::assertMatchesRegularExpression('/<section[^>]*id="milpa-answer"[^>]*hidden/', $html);
        self::assertMatchesRegularExpression('/<button[^>]*id="milpa-answer-yes"[^>]*disabled/', $html);
        self::assertMatchesRegularExpression('/<button[^>]*id="milpa-answer-no"[^>]*disabled/', $html);
        self::assertMatchesRegularExpression('/<input[^>]*type="password"[^>]*autocomplete="off"/', $html);
        // No <form>: a form that degraded to GET would put its fields — the token — in a URL.
        self::assertStringNotContainsStringIgnoringCase('<form', $html);
        // The bar's own display:flex beats the user-agent's [hidden] rule, so the page must carry
        // the override — without it the bar paints with hidden="true" on (found in a real browser).
        self::assertStringContainsString('.milpa-answer[hidden]', $html);
    }

    /**
     * The token reaches the server in the `Authorization` header and NOWHERE else: not in a URL,
     * which ends up in history, logs and referrers, and not in browser storage, which outlives
     * the consent it was pasted for.
     */
    public function testTheTokenTravelsInTheHeaderAndNeverOutlivesThePage(): void
    {
        $html = (new BoardPage())->render('org-1', '/agent/board', null);

        self::assertStringContainsString("'Authorization': 'Bearer ' + tokenField.value", $html);
        // The needle is concatenated so the SOURCE never carries the pattern the public-safety
        // gate forbids — this assertion hunts that exact pattern in the page's OUTPUT.
        self::assertStringNotContainsString('token' . '=', $html);
        self::assertStringNotContainsString('localStorage', $html);
        self::assertStringNotContainsString('sessionStorage', $html);
    }

    /** The write is a POST to the declared endpoint with the session in the BODY — never a link. */
    public function testTheAnswerGoesToTheDeclaredEndpointWithTheSessionInTheBody(): void
    {
        $html = (new BoardPage())->render('org-1', '/agent/board', null, '/agent/answer');

        self::assertStringContainsString('"/agent/answer"', $html);
        self::assertStringContainsString('JSON.stringify({ session: session, answer: value })', $html);
        self::assertStringContainsString("method: 'POST'", $html);
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

    /**
     * Reconnecting IS catching up (criterion 5): the open handler refetches the fold, and there is
     * no client cursor that could resync wrong. This asserts the WIRING — the behaviour itself was
     * exercised in a real browser (hub stopped, a fact written in the dark, hub restarted: the
     * board caught up without a reload; 2026-08-06, kanban-criteria.tsv). It also asserts the page
     * stops wearing a live face while behind: the reconnect message is part of the contract.
     */
    public function testReconnectionRefetchesTheFoldSoCatchingUpIsIdempotent(): void
    {
        $html = (new BoardPage())->render('org-1', '/agent/board', 'https://hub.example/.well-known/mercure');

        self::assertMatchesRegularExpression('/source\.onopen[\s\S]{0,400}scheduleRepaint\(\)/', $html);
        self::assertStringContainsString('reconnecting — the board may be behind', $html);
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
