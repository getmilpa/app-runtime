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
        paintActivity: paintActivity,
    };
});
