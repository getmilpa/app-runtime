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
 * The markup keeps the three bare hooks the module binds to — `#go`, `#out`, and the brand mark's
 * `[data-milpa-component]` — because they are the contract between this file and its module, and
 * renaming one silently disables the ceremony.
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
     * is the only way a subscriber changes the copy or the markup.
     */
    public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
    {
        $state = $request->state->data ?? [];
        $kind = \is_string($state['kind'] ?? null) ? (string) $state['kind'] : GateCeremonyComponent::SIGNIN;
        $rpId = \is_string($state['rpId'] ?? null) ? (string) $state['rpId'] : '';
        $scope = \is_string($state['scope'] ?? null) ? (string) $state['scope'] : '';

        $subject = new GateCeremonyRender(
            copy: GateCeremonyComponent::copy($kind),
            facts: [
                'kind' => $kind,
                'rpId' => $rpId,
                'scope' => $scope,
                'next' => \is_string($state['next'] ?? null) ? (string) $state['next'] : '/',
                'attachment' => \is_string($state['attachment'] ?? null) ? (string) $state['attachment'] : null,
            ],
            markHtml: \is_string($state['markHtml'] ?? null) ? (string) $state['markHtml'] : '',
        );
        $this->events?->dispatch(GateCeremonyComponent::BEFORE_RENDER, ['ceremony' => $subject]);

        $subject->html = self::markup($subject);
        $this->events?->dispatch(GateCeremonyComponent::AFTER_RENDER, ['ceremony' => $subject]);

        return new RenderResult(
            output: $subject->html,
            state: $request->state,
            clientAssets: $this->clientAssets(),
        );
    }

    private static function markup(GateCeremonyRender $subject): string
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
            . '      <button id="go">' . $e($copy['button']) . "</button>\n"
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
