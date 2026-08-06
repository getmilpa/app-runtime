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

namespace Milpa\AppRuntime\Web;

use Milpa\AppRuntime\Agent\BroadcastingEventStore;

/**
 * The agent board in a browser: four columns, read-only, painted from `agent:board`.
 *
 * ── WHERE THE FOLD LIVES, AND WHERE IT DOES NOT ──────────────────────────────────────────────────
 *
 * This page never folds the stream. The fold — which cards exist, which duplicate steps aside,
 * which generation is in force — is `agent:board`, server-side, shared with the CLI and tested
 * there. The browser fetches that fold and paints it; when the live bridge pushes a fact, the page
 * repaints the pushed activity line and fetches the fold again. One translation, two moments.
 *
 * A client-side fold would be the second translation the board spec forbids (§2.1): two readings of
 * the same stream diverge exactly on the event nobody tested. It would also need a cursor, and a
 * client that maintains its own cursor is a client that can maintain it wrong and skip facts in
 * silence. Refetching the fold makes catching up idempotent: reconnecting IS catching up.
 *
 * ── WHY THERE ARE NO BUTTONS ─────────────────────────────────────────────────────────────────────
 *
 * Approving is a consent to an effect. It has its own shape (`PolicyDecision`, with the verified
 * principal of whoever consents), its own acceptance criteria, and its own construction step that
 * is gated on identity working end to end. A surface that writes before identity is proven is the
 * order this repository already learned not to invert — so this step paints and refuses to touch.
 *
 * ── WHAT THIS PAGE IS HONEST ABOUT ───────────────────────────────────────────────────────────────
 *
 * With no hub configured it says so, instead of pretending to be live. While reconnecting it says
 * so, instead of freezing a stale board with a live face. And with JavaScript off it names the
 * terminal command that answers the same question. A surface that offers what it cannot do teaches
 * that the listing lies (ADR-0040).
 *
 * The look — colors, typography, density — is `@milpa/design`'s decision, in its own repository, by
 * version. The markup here is semantic and class-stable so that skinning it later touches nothing
 * structural.
 */
final readonly class BoardPage
{
    /**
     * The complete HTML document for one session's board.
     *
     * @param string      $session       the session identifier, exactly as `agent:board` knows it
     * @param string      $boardEndpoint where `agent:board` is served over HTTP — the app names it
     *                                   in `config/http.php`; the default is the derived route
     * @param string|null $hubUrl        the PUBLIC Mercure hub URL a browser can reach, or `null`
     *                                   when no live push is wired — the page then says so honestly
     */
    public function render(string $session, string $boardEndpoint = '/agent/board', ?string $hubUrl = null): string
    {
        $painter = file_get_contents(\dirname(__DIR__, 2) . '/resources/web/board-painter.js');
        if ($painter === false) {
            throw new \RuntimeException('the board painter asset is missing from this installation');
        }

        $sessionHtml = htmlspecialchars($session, \ENT_QUOTES);
        // Values that cross into JavaScript go through json_encode, never through string
        // interpolation: a session id is data, and data that becomes syntax is an injection.
        $flags = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES
            | \JSON_HEX_TAG | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_HEX_AMP;
        $boardUrl = json_encode($boardEndpoint . '?session=' . rawurlencode($session), $flags);
        $subscribeUrl = $hubUrl === null ? 'null' : json_encode(
            $hubUrl . '?topic=' . rawurlencode(BroadcastingEventStore::TOPIC_PREFIX . $session),
            $flags,
        );

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>agent board — {$sessionHtml}</title>
<!-- The look is @milpa/design's, consumed BY VERSION from the CDN — never copied into this repo.
     Bumping the pin is how this surface changes skin. -->
<link rel="stylesheet" href="https://unpkg.com/@milpa/design@0.9.0/dist/milpa-tokens.css">
<link rel="stylesheet" href="https://unpkg.com/@milpa/design@0.9.0/primitives/milpa-primitives.css">
<link rel="stylesheet" href="https://unpkg.com/@milpa/design@0.9.0/components/milpa-components.css">
<style>
/* Structure only. Colors, borders and type come from the design tokens; every var() carries a
   fallback so the board stays readable when the CDN is out of reach — degraded, not broken. */
body { font-family: var(--font-body, system-ui, sans-serif); margin: 1.5rem; color: var(--text, #1a1a1a); background: var(--bg, #fafafa); }
header { display: flex; align-items: baseline; gap: 1rem; margin-bottom: 1rem; }
h1 { font-family: var(--font-heading, inherit); font-size: 1.1rem; margin: 0; }
.milpa-board-status { font-size: .85rem; color: var(--text-muted, #666); }
.milpa-board-columns { display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: var(--space-4, 1rem); }
.milpa-column { padding: var(--space-4, 1rem); }
.milpa-column-title { display: flex; align-items: baseline; justify-content: space-between; font-size: .8rem; text-transform: uppercase; letter-spacing: .05em; color: var(--text-secondary, #555); margin: 0 0 var(--space-3, .75rem); padding-bottom: var(--space-2, .5rem); border-bottom: 1px solid var(--border-subtle, #e8e8e8); }
.milpa-column-count { color: var(--text-muted, #999); font-weight: normal; }
.milpa-column-cards { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: var(--space-2, .5rem); }
.milpa-card { display: flex; flex-wrap: wrap; align-items: baseline; gap: .25rem .5rem; border: 1px solid var(--border-strong, #c9c9c9); border-radius: var(--radius-md, .375rem); padding: .625rem .75rem; font-size: .9rem; background: var(--surface-raised, #fff); }
.milpa-card-text { flex: 1 1 auto; min-width: 0; }
/* A card that appeared already done never crossed the board — it is set apart, not celebrated. */
.milpa-card[data-crossed="false"] { border-style: dashed; color: var(--text-secondary, #555); }
.milpa-card-unexplained { margin-left: auto; }
.milpa-board-waiting { margin-bottom: var(--space-4, 1rem); }
.milpa-board-waiting-hint { display: block; font-size: .8rem; color: var(--text-muted, #666); margin-top: .25rem; }
</style>
</head>
<body>
<header>
<h1>agent board · <code>{$sessionHtml}</code></h1>
<p class="milpa-board-status" id="milpa-board-status">loading…</p>
</header>
<noscript><p>This live view needs JavaScript. The same board, from the terminal:
<code>php bin/coa agent:board --session={$sessionHtml}</code></p></noscript>
<main id="milpa-board" aria-live="polite"></main>
<script>{$painter}</script>
<script>
(function () {
    'use strict';

    const boardUrl = {$boardUrl};
    const subscribeUrl = {$subscribeUrl};
    const board = document.getElementById('milpa-board');
    const status = document.getElementById('milpa-board-status');

    function repaint() {
        return fetch(boardUrl)
            .then(function (r) { return r.json(); })
            .then(function (data) { board.innerHTML = MilpaBoard.paintBoard(data); })
            .catch(function () { status.textContent = 'the board endpoint is unreachable'; });
    }

    // Every pushed fact schedules a refetch of the fold. On purpose there is NO list here of which
    // kinds change the board: such a list would be a second copy of what the projector already
    // decides, and it would diverge on the event nobody tested. Coalescing keeps it cheap.
    let pending = null;
    function scheduleRepaint() {
        if (pending !== null) { return; }
        pending = setTimeout(function () { pending = null; repaint(); }, 300);
    }

    repaint();

    if (subscribeUrl === null) {
        // No hub, no pretending: the board is a photograph until the app wires one.
        status.textContent = 'no live push wired — this view repaints only on reload';
        return;
    }

    const source = new EventSource(subscribeUrl);
    source.onopen = function () {
        status.textContent = 'live';
        // Whatever happened while disconnected is already in the fold — refetching IS catching up.
        scheduleRepaint();
    };
    source.onerror = function () {
        // EventSource reconnects by itself; the surface's duty is to stop wearing a live face.
        status.textContent = 'reconnecting — the board may be behind';
    };
    source.onmessage = function (message) {
        let event = null;
        try { event = JSON.parse(message.data); } catch (e) { return; }
        const line = MilpaBoard.paintActivity(event);
        if (line !== null) {
            // textContent, never innerHTML: the activity line carries model-written text.
            status.textContent = line;
        }
        scheduleRepaint();
    };
})();
</script>
</body>
</html>
HTML;
    }
}
