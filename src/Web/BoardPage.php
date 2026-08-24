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
 * ── THE ONE WRITE, AND WHAT GUARDS IT ────────────────────────────────────────────────────────────
 *
 * Answering is a consent to an effect, so the identity of whoever answers is PART OF THE FACT
 * (board spec §4). The page ships the two answer buttons born disabled: they wake only when a
 * token is present, the token travels in the `Authorization` header — never in a URL, which gets
 * logged and shared, and never in browser storage, which outlives the consent — and the write goes
 * to `agent:answer`, whose server side refuses any non-terminal caller that brings no verified
 * actor (criteria 3–4). The page-level gating is a courtesy; THE gate is the operation's. When the
 * server refuses, the refusal is shown verbatim: a surface that rephrases a refusal teaches its
 * own lie.
 *
 * The PAINTER still renders no writable control (its test holds): the buttons belong to the page
 * shell, next to the identity that arms them, so the artifact that translates model-written data
 * into markup never grows a way to write.
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
     * @param string      $session        the session identifier, exactly as `agent:board` knows it
     * @param string      $boardEndpoint  where `agent:board` is served over HTTP — the app names it
     *                                    in `config/http.php`; the default is the derived route
     * @param string|null $hubUrl         the PUBLIC Mercure hub URL a browser can reach, or `null`
     *                                    when no live push is wired — the page then says so honestly
     * @param string      $answerEndpoint where `agent:answer` accepts the POST — the session and the
     *                                    answer go in the body, the token in the header, nothing in
     *                                    the URL
     */
    public function render(
        string $session,
        string $boardEndpoint = '/agent/board',
        ?string $hubUrl = null,
        string $answerEndpoint = '/agent/answer',
    ): string {
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
        // The answer endpoint carries NO query string: the session travels in the JSON body and the
        // token in the Authorization header. A URL ends up in history, logs and referrers.
        $answerUrl = json_encode($answerEndpoint, $flags);
        $sessionJs = json_encode($session, $flags);
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
/* flex-direction goes explicit: the design's .mui-card is itself a column flexbox, and without
   the row here the token and the two buttons stack centered under each other (seen in a real
   browser at 1440px, 2026-08-06). */
.milpa-answer { margin-top: var(--space-4, 1rem); padding: var(--space-4, 1rem); display: flex; flex-direction: row; flex-wrap: wrap; align-items: center; gap: var(--space-3, .75rem); }
/* display:flex beats the user-agent's [hidden] rule, so hidden must win explicitly — found in a
   real browser: the bar showed up with no question open, wearing hidden="true" (2026-08-06). */
.milpa-answer[hidden] { display: none; }
.milpa-answer-why { flex: 1 1 100%; margin: 0; font-size: .85rem; color: var(--text-secondary, #555); }
.milpa-answer-token { font-family: var(--font-mono, monospace); }
.milpa-answer-status { flex: 1 1 100%; margin: 0; font-size: .85rem; color: var(--text-muted, #666); min-height: 1.2em; }
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
<!-- The one write on this surface. Hidden until the fold says a question is open; the buttons are
     born disabled and wake only when a token is present. The REAL gate is server-side: agent:answer
     refuses any non-terminal caller without a verified actor (board spec §4, criteria 3-4). -->
<section id="milpa-answer" class="mui-card mui-card--compact milpa-answer" hidden>
<p class="milpa-answer-why">Answering writes to the session stream, signed by whoever answers.
Paste a token with the <code>agent:answer</code> scope to arm the buttons — it stays in this page's
memory and travels only in the request header.</p>
<label>token <input type="password" id="milpa-answer-token" class="milpa-answer-token" autocomplete="off"></label>
<button type="button" id="milpa-answer-yes" class="mui-btn mui-btn--primary" disabled>sí</button>
<button type="button" id="milpa-answer-no" class="mui-btn mui-btn--secondary" disabled>no</button>
<p id="milpa-answer-status" class="milpa-answer-status" aria-live="polite"></p>
</section>
<script>{$painter}</script>
<script>
(function () {
    'use strict';

    const boardUrl = {$boardUrl};
    const subscribeUrl = {$subscribeUrl};
    const answerUrl = {$answerUrl};
    const session = {$sessionJs};
    const board = document.getElementById('milpa-board');
    const status = document.getElementById('milpa-board-status');
    const answerBar = document.getElementById('milpa-answer');
    const tokenField = document.getElementById('milpa-answer-token');
    const yesButton = document.getElementById('milpa-answer-yes');
    const noButton = document.getElementById('milpa-answer-no');
    const answerStatus = document.getElementById('milpa-answer-status');

    function repaint() {
        return fetch(boardUrl)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                board.innerHTML = MilpaBoard.paintBoard(data);
                // The bar follows the FOLD, not this page's memory of what it sent: the question is
                // open until the stream says otherwise, and closed the moment it does — even when
                // someone else answered from the terminal.
                answerBar.hidden = !(data && data.ok === true
                    && typeof data.pending_question === 'string' && data.pending_question !== '');
            })
            .catch(function () { status.textContent = 'the board endpoint is unreachable'; });
    }

    // The token lives in this field and in the request header — NEVER in the URL, which ends up in
    // history and logs, and NEVER in browser storage, which outlives the consent it was pasted for.
    function armButtons() {
        const armed = tokenField.value.trim() !== '';
        yesButton.disabled = !armed;
        noButton.disabled = !armed;
    }
    tokenField.addEventListener('input', armButtons);

    function answer(value) {
        yesButton.disabled = true;
        noButton.disabled = true;
        answerStatus.textContent = 'answering…';
        fetch(answerUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + tokenField.value.trim(),
            },
            body: JSON.stringify({ session: session, answer: value }),
        })
            .then(function (r) {
                return r.json().catch(function () { return null; }).then(function (data) {
                    return { status: r.status, data: data };
                });
            })
            .then(function (r) {
                if (r.status < 300 && r.data && r.data.ok !== false) {
                    answerStatus.textContent = 'answered: ' + value;
                } else {
                    // The server's refusal, VERBATIM. Rephrasing «no verified actor» into something
                    // friendlier would hide exactly the fact the human needs in order to fix it.
                    answerStatus.textContent = r.data && typeof r.data.error === 'string'
                        ? r.data.error
                        : 'refused (HTTP ' + r.status + ')';
                }
                armButtons();
                scheduleRepaint();
            })
            .catch(function () {
                answerStatus.textContent = 'the answer endpoint is unreachable';
                armButtons();
            });
    }
    yesButton.addEventListener('click', function () { answer('sí'); });
    noButton.addEventListener('click', function () { answer('no'); });

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

    // The board is transport-agnostic on the SERVER (greenhouse evidence/0291); the browser must still
    // speak the transport's own subscribe protocol. SSE (http hub) and WebSocket (ws) are two client
    // shapes for one stream, chosen here by the URL scheme the config declared. Both feed one handler.
    function onFact(dataString) {
        let event = null;
        try { event = JSON.parse(dataString); } catch (e) { return; }
        const line = MilpaBoard.paintActivity(event);
        if (line !== null) {
            // textContent, never innerHTML: the activity line carries model-written text.
            status.textContent = line;
        }
        scheduleRepaint();
    }

    if (subscribeUrl.slice(0, 3) === 'ws:' || subscribeUrl.slice(0, 4) === 'wss:') {
        const socket = new WebSocket(subscribeUrl);
        // Whatever happened while disconnected is already in the fold — refetching IS catching up.
        socket.onopen = function () { status.textContent = 'live'; scheduleRepaint(); };
        socket.onclose = function () { status.textContent = 'reconnecting — the board may be behind'; };
        socket.onmessage = function (message) { onFact(message.data); };
    } else {
        const source = new EventSource(subscribeUrl);
        source.onopen = function () { status.textContent = 'live'; scheduleRepaint(); };
        // EventSource reconnects by itself; the surface's duty is to stop wearing a live face.
        source.onerror = function () { status.textContent = 'reconnecting — the board may be behind'; };
        source.onmessage = function (message) { onFact(message.data); };
    }
})();
</script>
</body>
</html>
HTML;
    }
}
