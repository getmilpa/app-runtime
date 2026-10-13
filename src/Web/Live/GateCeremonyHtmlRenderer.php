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

use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\Contracts\Rendering\DeclaresClientAssets;
use Milpa\AppRuntime\Web\PasskeyPlugin;
use Milpa\Live\Support\DesignTokens;
use Milpa\Live\ValueObjects\ClientAssets;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderResult;
use Milpa\Live\ValueObjects\RenderTarget;

/**
 * Paints the ceremony, and declares the two client files it needs.
 *
 * WHAT THE SERVER CHOSE TRAVELS AS JSON, NEVER AS JAVASCRIPT. The relying party, the required scope,
 * the return path and the authenticator preference go into a `type="application/json"` tag, encoded so
 * no value can close it. The shape this replaces interpolated them into source: `next` as a JS string
 * literal, and the authenticator preference as a RAW FRAGMENT spliced into an object literal — safe
 * only while something upstream validated it, and it was the controller's caller that did, not the
 * controller. Data cannot become code here (greenhouse decisions/0263).
 *
 * The markup keeps the bare hooks the module binds to — `#go`, `#out`, and the brand mark's
 * `[data-milpa-component]` — because they are the contract between this file and its module, and
 * renaming one silently disables the ceremony. `#go` is there only when the page has an act to offer: a
 * page whose invitation the door refused has none, and the module binds to it only when it finds it.
 */
final class GateCeremonyHtmlRenderer implements ComponentRendererInterface, DeclaresClientAssets
{
    public function __construct(private readonly ?MilpaEventDispatcherInterface $events = null)
    {
    }

    /** HTML only: a ceremony is a page in a browser, never a terminal frame. */
    public function supportsTarget(RenderTarget $target): bool
    {
        return $target === RenderTarget::HTML;
    }

    /**
     * The sheet and the module, by URL.
     *
     * Not through `ComponentPresentation`: the asset orchestrator SCOPES a declared stylesheet, and
     * this one carries document-level rules (`html`, `body`, `*`, bare `h1` and `button`) that come
     * back as `gate-ceremony html`, `gate-ceremony body` … — selectors that match nothing. Measured
     * against `CssScoper` before choosing this (greenhouse decisions/0263).
     */
    public function clientAssets(): ClientAssets
    {
        return new ClientAssets(
            scripts: [GateCeremonyAssets::url(GateCeremonyAssets::MODULE)],
            styles: [GateCeremonyAssets::url(GateCeremonyAssets::STYLESHEET)],
        );
    }

    /**
     * Paints the act the state names, announcing before and after so a plugin can extend it.
     *
     * The subject the pair carries is MUTABLE on purpose: `dispatch` returns void, so writing to it
     * is the only way a subscriber changes the copy or the markup. What no word in the copy changes is
     * whether the page offers an act: an invitation the door refused paints no button, whoever wrote one.
     */
    public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
    {
        $state = $request->state->data ?? [];
        $kind = \is_string($state['kind'] ?? null) ? (string) $state['kind'] : GateCeremonyComponent::SIGNIN;
        $rpId = \is_string($state['rpId'] ?? null) ? (string) $state['rpId'] : '';
        $scope = \is_string($state['scope'] ?? null) ? (string) $state['scope'] : '';

        $invitation = $kind === GateCeremonyComponent::ENROLL && \is_array($state['invitation'] ?? null) ? $state['invitation'] : null;
        $copy = GateCeremonyComponent::copy($kind);
        $subject = new GateCeremonyRender(
            copy: $invitation === null ? $copy : GateCeremonyComponent::invitationCopy($copy, $invitation),
            facts: [
                'kind' => $kind,
                'rpId' => $rpId,
                'scope' => $scope,
                'next' => \is_string($state['next'] ?? null) ? (string) $state['next'] : '/',
                'attachment' => \is_string($state['attachment'] ?? null) ? (string) $state['attachment'] : null,
                // The secret travels back only to the page it came in on, as data (greenhouse decisions/0498).
                'invite' => $invitation !== null && ($invitation['ok'] ?? false) === true && \is_string($state['invite'] ?? null) ? (string) $state['invite'] : '',
            ],
            markHtml: \is_string($state['markHtml'] ?? null) ? (string) $state['markHtml'] : '',
        );
        $this->events?->dispatch(GateCeremonyComponent::BEFORE_RENDER, ['ceremony' => $subject]);

        // WHETHER THERE IS AN ACT TO OFFER IS THE DOOR'S VERDICT, NOT A WORD IN THE COPY. The copy was just
        // handed to every subscriber, and writing the button is how a house translates it: read from there,
        // «no button» lasts until the first translator. So it is read from the state the door filled, which
        // no subscriber holds.
        $subject->html = self::markup($subject, $invitation === null || ($invitation['ok'] ?? false) === true);
        $this->events?->dispatch(GateCeremonyComponent::AFTER_RENDER, ['ceremony' => $subject]);

        return new RenderResult(
            output: $subject->html,
            state: $request->state,
            clientAssets: $this->clientAssets(),
        );
    }

    /** @param bool $offers whether the door admits an act from this page: false for an invitation it refused */
    private static function markup(GateCeremonyRender $subject, bool $offers): string
    {
        $copy = $subject->copy;
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        $lede = '';
        foreach (\is_array($copy['lede'] ?? null) ? $copy['lede'] : [] as $paragraph) {
            $lede .= '      <p>' . $e($paragraph) . "</p>\n";
        }

        $next = '';
        if (\is_array($copy['next'] ?? null)) {
            $next = '      <p class="gate__next"><span class="kicker">' . $e($copy['next']['kicker'])
                . '</span>' . $e($copy['next']['text']) . "</p>\n";
        }

        $away = \is_array($copy['away'] ?? null) ? $copy['away'] : ['href' => '/', 'text' => ''];

        // The two words that may be absent are read as every other word of the copy is — cast, so a
        // subscriber may hand a lazy translation — and guarded only because, unlike the rest, no words here
        // means nothing is painted.
        $words = static fn (mixed $value): string => \is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
        $button = $words($copy['button'] ?? '');

        // An invitation that admits nobody says so where the button would be, not only in the margin.
        $said = $words($copy['refused'] ?? '');
        $refused = $said !== '' ? '      <p class="gate__refused" role="alert">' . $e($said) . "</p>\n" : '';

        return '<div class="gate">' . "\n"
            . '  <aside class="gate__brand">' . "\n"
            . '    <a class="gate__wordmark" href="https://getmilpa.com" target="_blank" rel="noopener noreferrer">'
            // The wordmark's URL comes from the canon under this plugin's prefix, like every other
            // design-system asset the ceremony links (greenhouse decisions/0308).
            . '<img src="' . DesignTokens::urls(PasskeyPlugin::designPrefix())[DesignTokens::WORDMARK] . '" alt="Milpa" width="2407" height="900"></a>' . "\n"
            . '    <div class="gate__mark">' . $subject->markHtml . "</div>\n"
            . '    <div class="gate__lede">' . "\n"
            . '      <p class="kicker">' . $e($copy['kicker']) . "</p>\n"
            . '      <h1>' . $e($copy['heading']) . "</h1>\n"
            . $lede
            . '      <p class="gate__doctrine">' . $e($copy['doctrine']) . "</p>\n"
            . $next
            . "    </div>\n"
            . "  </aside>\n"
            . '  <main class="gate__act">' . "\n"
            . '    <div class="gate__body">' . "\n"
            . $refused
            // A page the door refused has no button whatever the copy says, and no words for it is no button
            // anywhere: the client module binds to it only when it is there.
            . ($offers && $button !== '' ? '      <button id="go">' . $e($button) . "</button>\n" : '')
            . '      <div id="out"></div>' . "\n"
            . "    </div>\n"
            . '    <div class="gate__foot">' . "\n"
            . '      <details class="gate__tech">' . "\n"
            . '        <summary>Technical details</summary>' . "\n"
            . '        <p><span>Relying party: <code>' . $e($subject->facts['rpId']) . '</code></span>'
            . '<span>Required scope: <code>' . $e($subject->facts['scope']) . "</code></span></p>\n"
            . '        <p class="gate__teach">Credentials from another house are not accepted.</p>' . "\n"
            . "      </details>\n"
            . '      <p><a href="' . $e($away['href']) . '">' . $e($away['text']) . "</a></p>\n"
            . "    </div>\n"
            . "  </main>\n"
            . "</div>\n"
            . self::factsTag($subject->facts);
    }

    /**
     * The server's choices, as data.
     *
     * `JSON_HEX_TAG` is what makes the tag unclosable from a value, so a relying party or a return
     * path can never become markup. The `next` this carries was already narrowed to a local absolute
     * path by {@see \Milpa\AppRuntime\Web\LocalPath::orRoot()} — the encoding is the second wall, not
     * the first.
     *
     * @param array<string, mixed> $facts
     */
    private static function factsTag(array $facts): string
    {
        return '<script type="application/json" id="milpa-gate-ceremony">'
            . json_encode($facts, \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)
            . '</script>' . "\n";
    }
}
