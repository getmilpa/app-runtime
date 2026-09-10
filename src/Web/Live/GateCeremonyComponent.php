<?php

/**
 * This file is part of milpa/app-runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Web\Live;

use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * THE CEREMONY, AS A COMPONENT — the screen where a human gives this house an identity, or proves one.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────────────────────────────
 *
 * Both ceremony pages were hand-written HTML inside PHP heredocs: two ~300-line templates whose
 * ninety-four-line `<style>` blocks were BYTE-IDENTICAL and whose scripts each carried their own copy
 * of `mark()`, the reachability check and the base64url helpers. The house's own doctrine forbids
 * exactly that — the UI is a composition of components, never hand-written HTML
 * (greenhouse decisions/0189) — and the heredoc is what ate a `\n` and shipped a ceremony whose button
 * had no listener (decisions/0261).
 *
 * ── WHY IT IS NOT A SECTION OF THE PANEL ─────────────────────────────────────────────────────────
 *
 * Because it cannot be, and the reason is load-bearing rather than incidental. The panel carries ONE
 * middleware stack across every route it owns — `/s/{id}` and `/assets/{file}` included — and a
 * section is not a route: they are all painted by one handler behind that one door. So a ceremony
 * hosted there would sit behind the gate whose key it exists to mint, and even hosted OUTSIDE it, a
 * page wearing the panel's shell would fetch its stylesheet and its whole runtime from the panel's
 * gated asset route — a 401 on a `<link>`, which breaks a page in silence (decisions/0263).
 *
 * `DesignTokens` already said it, one layer down: *enrolling is the PRECONDITION of having a panel
 * session, so a house with no panel still has to be able to let somebody in* (decisions/0243).
 *
 * The ceremony looks like the house because it is BUILT from the house — its tokens, its brand mark,
 * its components — not because it borrows the panel's chrome.
 *
 * ── WHAT THE PAGE STILL OWNS ─────────────────────────────────────────────────────────────────────
 *
 * The document: `<html>`, the head, the token and font links. This component owns everything inside
 * it and declares the two client files it needs ({@see GateCeremonyHtmlRenderer::clientAssets()}).
 *
 * They travel as a `<link>` and a `<script src>` rather than through `ComponentPresentation`, and
 * that is measured, not stylistic: the orchestrator SCOPES a declared stylesheet, so
 * `html`, `body`, `*`, `h1` and `button` come back as `gate-ceremony html`, `gate-ceremony body` …
 * — selectors that match nothing. A full-page ceremony needs document-level rules, so its sheet is
 * served the same way the tokens beside it already are (decisions/0263).
 *
 * ── WHAT IS STILL OWED ───────────────────────────────────────────────────────────────────────────
 *
 * The copy is hardcoded English, against the house's rule that a user-facing surface reads from a
 * message catalog. `decisions/0260` declared that residue and this slice does not pay it — but the
 * copy now lives in ONE place instead of two templates, which turns internationalising it into a
 * change of this method rather than a rewrite of two pages.
 */
final class GateCeremonyComponent implements ComponentDefinitionInterface
{
    public const NAME = 'gate-ceremony';

    public const ENROLL = 'enroll';

    public const SIGNIN = 'signin';

    /** Dispatched with a mutable {@see GateCeremonyRender} before the markup is painted. */
    public const BEFORE_RENDER = 'gate.ceremony.before_render';

    /** Dispatched with the same subject after, its `html` filled — so a plugin can extend without touching this. */
    public const AFTER_RENDER = 'gate.ceremony.after_render';

    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: self::NAME,
            contractVersion: '1',
            summary: 'The passkey ceremony: register an identity, or prove one this house recognises.',
            propsSchema: [
                'kind' => ['type' => 'string', 'required' => true, 'enum' => [self::ENROLL, self::SIGNIN]],
                'rpId' => ['type' => 'string', 'required' => true],
                'scope' => ['type' => 'string', 'required' => true],
                'next' => ['type' => 'string', 'default' => '/'],
                'attachment' => ['type' => 'string|null', 'default' => null],
                'markHtml' => ['type' => 'string', 'default' => ''],
            ],
            stateSchema: [
                'kind' => ['type' => 'string'],
                'rpId' => ['type' => 'string'],
                'scope' => ['type' => 'string'],
                'next' => ['type' => 'string'],
                'attachment' => ['type' => 'string|null'],
                'markHtml' => ['type' => 'string'],
            ],
            // NO ACTIONS, AND THAT IS THE POINT. The ceremony talks to `/webauthn/*` — challenge,
            // registration, assertion — never to a live endpoint. A component with an action would
            // need the wire, and the wire is exactly what an unauthenticated page cannot have.
            actions: [],
        );
    }

    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        $kind = \is_string($props['kind'] ?? null) && \in_array($props['kind'], [self::ENROLL, self::SIGNIN], true)
            ? (string) $props['kind']
            : self::SIGNIN;

        $attachment = $props['attachment'] ?? null;

        return new StateSnapshot(
            $context->componentId,
            self::NAME,
            '1',
            [
                'kind' => $kind,
                'rpId' => \is_string($props['rpId'] ?? null) ? (string) $props['rpId'] : '',
                'scope' => \is_string($props['scope'] ?? null) ? (string) $props['scope'] : '',
                'next' => \is_string($props['next'] ?? null) && $props['next'] !== '' ? (string) $props['next'] : '/',
                'attachment' => \is_string($attachment) && $attachment !== '' ? $attachment : null,
                'markHtml' => \is_string($props['markHtml'] ?? null) ? (string) $props['markHtml'] : '',
            ],
            ['locale' => $context->locale],
        );
    }

    public function handle(InteractionRequest $request): InteractionResult
    {
        // A component with no actions still implements this, and says so rather than throwing: the
        // page is unauthenticated and has no wire, so an action reaching here is a misconfiguration
        // to report, not an exception to raise inside somebody's request.
        return new InteractionResult(
            state: $request->state,
            errors: ['action' => 'the ceremony takes no live actions; it answers to /webauthn/* directly'],
        );
    }

    /**
     * The words of one act, in one place.
     *
     * @return array{kicker: string, heading: string, lede: list<string>, doctrine: string, next: array{kicker: string, text: string}|null, button: string, away: array{href: string, text: string}}
     */
    public static function copy(string $kind): array
    {
        if ($kind === self::ENROLL) {
            return [
                'kicker' => 'Step 1 · Who are you?',
                'heading' => 'Register a passkey',
                'lede' => [
                    'Give this house a verifiable identity for you.',
                    'Use your device, password manager, or security key.',
                ],
                // THE ONE LINE THAT HAS TO SURVIVE THIS SCREEN (greenhouse decisions/0260).
                'doctrine' => 'Registering identifies you. It grants no permissions.',
                // AND STEP 2 FINISHES THE SENTENCE STEP 1 STARTED — half a thought until the other
                // half says where permission DOES come from. Together they teach identity ≠ authority
                // with no architecture lecture at all.
                'next' => ['kicker' => 'Step 2 · What may you do?', 'text' => 'Choose what this identity may do.'],
                'button' => 'Register with passkey',
                'away' => ['href' => '/webauthn/signin', 'text' => 'Already enrolled? Sign in →'],
            ];
        }

        return [
            'kicker' => 'House identity · the gate',
            'heading' => 'Sign in',
            'lede' => ['Confirm the key this house already recognizes.'],
            // Not the same sentence as enrolling, because it is not the same act.
            'doctrine' => 'The house checks the signature before it mints a session.',
            'next' => null,
            'button' => 'Continue with a passkey',
            'away' => ['href' => '/webauthn/enroll', 'text' => 'No key on this house yet? Register one →'],
        ];
    }
}
