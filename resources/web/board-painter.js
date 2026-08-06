/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app installs, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

/**
 * The one painter of the agent board.
 *
 * ── WHY THE CLIENT PAINTS AND NEVER FOLDS ────────────────────────────────────────────────────────
 *
 * The fold — which cards exist, which generation is in force, which duplicate steps aside — lives in
 * `agent:board`, server-side, shared with the CLI and tested there. A second fold written here in
 * JavaScript would be the two-translations trap the board spec forbids: two readings of the same
 * stream diverge exactly on the fact nobody tested. So the browser asks for the fold and paints it,
 * and when the live bridge signals that something happened, it asks for the fold again.
 *
 * That also makes catching up impossible to get wrong: there is no client cursor to maintain and no
 * event list to deduplicate — reconnecting and repainting IS catching up.
 *
 * ── WHY THESE FUNCTIONS RETURN STRINGS ───────────────────────────────────────────────────────────
 *
 * So the same artifact runs in the browser and under `node` in a test. A painter that can only be
 * verified inside a browser is a painter that in practice nobody verifies.
 *
 * ── WHAT IT REFUSES TO PAINT ─────────────────────────────────────────────────────────────────────
 *
 * Buttons. Approving is a consent to an effect, it has its own shape (`PolicyDecision`, with the
 * verified principal of whoever consents) and its own construction step, gated on identity working
 * end to end. A read-only surface that grew a button early would invert exactly the order this
 * repository already learned not to invert.
 */

(function (root, factory) {
    'use strict';

    const api = factory();
    if (typeof module === 'object' && module !== null && typeof module.exports === 'object') {
        module.exports = api;
    } else {
        root.MilpaBoard = api;
    }
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    /**
     * Everything painted goes through here. Card texts are written by a model: a model that writes
     * `<script>` must land as text in the human's browser, never as markup.
     */
    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /**
     * One card. `origin: retrospective` is a card that was born already done — it never crossed the
     * board, and painting it like the ones that did would be the board lying (measured: 12 of 16
     * cards are born in `done`). It gets a mark and a plain label instead of a crossing.
     */
    function paintCard(card) {
        const origin = typeof card.origin === 'string' ? card.origin : 'unknown';
        const appearedDone = origin === 'retrospective';
        const unexplained = typeof card.unexplained === 'number' ? card.unexplained : 0;

        let inner = '<span class="milpa-card-text">' + escapeHtml(card.text) + '</span>';
        if (appearedDone) {
            // A label, not an animation: «appeared already done» is a fact; a crossing would be a story.
            inner += '<span class="mui-badge mui-badge--warning milpa-card-flag">appeared already done</span>';
        }
        if (unexplained > 0) {
            // How many mutations happened after this card was last touched. It does not say the card
            // is wrong — it says that much work went unexplained, which is the invariant Q-P19-C left.
            inner += '<span class="mui-badge mui-badge--secondary milpa-card-unexplained" title="mutations since this card was touched">'
                + String(unexplained) + '</span>';
        }

        return '<li class="milpa-card" data-id="' + escapeHtml(card.id) + '"'
            + ' data-origin="' + escapeHtml(origin) + '"'
            + ' data-crossed="' + (appearedDone ? 'false' : 'true') + '"'
            + ' data-version="' + escapeHtml(String(card.version ?? 1)) + '">'
            + inner + '</li>';
    }

    /**
     * The whole board, from one `agent:board` response.
     *
     * The columns are the keys the data brings, in the order it brings them — the enum decides
     * server-side. A list written here would be the second place deciding how many columns exist,
     * and a new status would be born invisible on this surface.
     */
    function paintBoard(board) {
        if (!board || board.ok !== true || typeof board.columns !== 'object' || board.columns === null) {
            return '<p class="mui-alert mui-alert--danger milpa-board-error">'
                + escapeHtml(board && board.error ? board.error : 'the board could not be read')
                + '</p>';
        }

        let html = '';

        if (typeof board.pending_question === 'string' && board.pending_question !== '') {
            // Stopped work waiting for a human is the one thing this surface must not hide — but it
            // shows the question WITHOUT an answer control: answering writes to the stream and that
            // is the next construction step, gated on verified identity.
            html += '<p class="mui-alert mui-alert--warning milpa-board-waiting">' + escapeHtml(board.pending_question)
                + ' <span class="milpa-board-waiting-hint">answer from the terminal: '
                + '<code>php bin/coa agent:answer --session=' + escapeHtml(board.session ?? '')
                + ' --answer=…</code></span></p>';
        }

        html += '<div class="milpa-board-columns">';
        for (const status of Object.keys(board.columns)) {
            const cards = Array.isArray(board.columns[status]) ? board.columns[status] : [];
            html += '<section class="mui-card mui-card--compact milpa-column" data-status="' + escapeHtml(status) + '">'
                + '<h2 class="milpa-column-title">' + escapeHtml(status)
                + ' <span class="milpa-column-count">' + String(cards.length) + '</span></h2>'
                + '<ul class="milpa-column-cards">'
                + cards.map(paintCard).join('')
                + '</ul></section>';
        }
        html += '</div>';

        return html;
    }

    /**
     * One line for what the session is doing RIGHT NOW, from a projected event pushed by the bridge.
     *
     * Returns `null` for the kinds whose durable meaning lives in the fold (`card`, `plan`): the
     * caller refetches the board for those instead of folding here — see the header. Unknown kinds
     * also return `null`: a stream is read years after it was written, and a surface that invents
     * what to do with the unknown paints anything with the face of data.
     */
    function paintActivity(event) {
        if (!event || typeof event.kind !== 'string') {
            return null;
        }

        switch (event.kind) {
            case 'activity': {
                const a = event.activity ?? {};
                if (a.state === 'tool') {
                    // The tool NAME is what makes progress observable: a fixed text for sixteen
                    // seconds does not distinguish work from a hang; a changing name does.
                    return 'running ' + (typeof a.detail === 'string' ? a.detail : 'a tool');
                }
                return a.state === 'ready' ? 'ready' : 'thinking…';
            }
            case 'waiting':
                return 'waiting for a human: ' + ((event.ended && event.ended.question) || '');
            case 'answered': {
                // Said out loud so the waiting face clears — the banner itself goes away with the
                // fold refetch. The executor travels in the fact; a surface must not re-derive who.
                const a = event.answered ?? {};
                const who = typeof a.executor === 'string' && a.executor !== '' ? ' (' + a.executor + ')' : '';
                return 'question answered: ' + (typeof a.answer === 'string' ? a.answer : '') + who;
            }
            case 'ended':
                return 'session ended' + ((event.ended && event.ended.because) ? ' — ' + event.ended.because : '');
            case 'open-work':
                return 'session ended leaving open work';
            case 'transferred':
                return 'open cards transferred to ' + ((event.ended && event.ended.to) || 'another session');
            case 'message':
                return ((event.message && event.message.from) || '(unknown)') + ' says: '
                    + ((event.message && event.message.content) || '');
            default:
                return null;
        }
    }

    return {
        escapeHtml: escapeHtml,
        paintCard: paintCard,
        paintBoard: paintBoard,
        paintActivity: paintActivity,
    };
});
