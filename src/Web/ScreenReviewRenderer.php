<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Web;

use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\Contracts\Transport\StateTransferCodecInterface;
use Milpa\Live\ValueObjects\{RenderTarget,RenderRequest,RenderResult};
use Milpa\Live\Support\Html;

/** A review is an immutable before/after plus the isolated, operable screen, not a mutable name. */
final readonly class ScreenReviewRenderer implements ComponentRendererInterface
{
    public function __construct(private StateTransferCodecInterface $codec)
    {
    }
    /** Review uses the normal HTML target. */
    public function supportsTarget(RenderTarget $target): bool
    {
        return $target === RenderTarget::HTML;
    }
    /** Render the component with its own presentation, translated controls and signed selection. */
    public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
    {
        $s = $request->state ?? $component->mount($request->props, $request->context);
        $d = $s->data;
        $catalog = require __DIR__ . '/../../resources/screen-review/messages.php';
        $t = $catalog[$d['locale']];
        $h = Html::escape(...);
        $root = Html::attrs(['data-milpa-component' => 'screen-review','data-milpa-component-id' => $s->componentId,'lang' => $d['locale'],'x-data' => 'milpaComponent(' . json_encode(['componentId' => $s->componentId]) . ')']);
        $next = $d['locale'] === 'en' ? 'es' : 'en';
        $html = '<main ' . $root . '><header><div><h1>' . $t['title'] . '</h1><p>' . $t['intro'] . '</p></div><button class="mui-btn mui-btn--subtle" ' . Html::attrs(['@click' => "act('read',{locale:'$next'})",':disabled' => 'busy']) . '>' . $t['language'] . '</button></header>';
        $html .= '<p class="notice" role="status" data-notice="' . $h($d['notice']) . '">' . ($d['notice'] === '' ? '' : $h($t[$d['notice']] ?? $t['error'])) . '</p><p role="alert" x-text="error"></p><div class="review-grid"><aside><h2>' . $t['drafts'] . '</h2>';
        foreach ($d['drafts'] as $draft) {
            $html .= '<button class="revision" ' . Html::attrs(['data-revision' => $draft['id'],'aria-pressed' => $d['selected'] === $draft['id'] ? 'true' : 'false','@click' => "act('read',{revision:'" . $draft['id'] . "'})",':disabled' => 'busy']) . '>' . $h($draft['name']) . ' · ' . $h(substr($draft['id'], 0, 12)) . '</button>';
        }
        $selected = $d['review'];
        $first = $d['screens'][0] ?? [];
        $html .= '<details open><summary>' . $t['new'] . '</summary><form data-create-revision ' . Html::attrs(['@submit.prevent' => "act('draft',Object.fromEntries(new FormData(\$event.target)))"]) . '>';
        foreach (['name' => $selected['name'] ?? $first['name'] ?? '','type' => $selected['definition']['type'] ?? $first['type'] ?? ''] as $key => $value) {
            $html .= '<label for="review-' . $key . '">' . $t[$key] . '</label><input class="mui-input" id="review-' . $key . '" name="' . $key . '" value="' . $h($value) . '" required>';
        }
        $props = $selected['definition']['props'] ?? $first['definition']['props'] ?? [];
        $html .= '<label for="review-props">' . $t['props'] . '</label><textarea class="mui-textarea" id="review-props" name="props">' . $h(json_encode($props === [] ? (object)[] : $props, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</textarea><div class="actions"><button class="mui-btn mui-btn--primary" type="submit" :disabled="busy">' . $t['save'] . '</button></div></form></details></aside><article>';
        if ($selected === null) {
            $html .= '<p>' . $t['empty'] . '</p>';
        } else {
            $html .= '<h2>' . $h($selected['name']) . '</h2><code data-selected-revision>' . $h($selected['id']) . '</code><p>' . $t[$selected['fresh'] ? 'fresh' : ($selected['restorable'] ? 'restorable' : 'stale')] . '</p><div class="comparison">';
            foreach (['before' => 'before','definition' => 'after'] as $key => $label) {
                $html .= '<section><h3>' . $t[$label] . '</h3><pre data-diff="' . $key . '">' . $h(json_encode($selected[$key], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre></section>';
            }
            $html .= '</div><div class="actions">';
            foreach (['promote' => 'fresh','rollback' => 'restorable'] as $action => $enabled) {
                $html .= '<button class="mui-btn mui-btn--' . ($action === 'promote' ? 'primary' : 'secondary') . '" ' . Html::attrs(['data-review-action' => $action,'@click' => "act('$action',{revision:'" . $selected['id'] . "'})",'disabled' => !$selected[$enabled],':disabled' => 'busy || ' . ($selected[$enabled] ? 'false' : 'true')]) . '>' . $t[$action] . '</button>';
            }
            $html .= '<a target="_blank" rel="noopener" href="' . $h($d['route'] . '/page?component=' . $selected['name']) . '">' . $t['active'] . '</a></div><p>' . $t['isolation'] . '</p><iframe data-draft-preview title="' . $t['preview'] . '" src="' . $h($d['route'] . '/preview?revision=' . $selected['id']) . '"></iframe>';
        }
        $html .= '</article></div></main><script type="application/milpa+xhtml" data-milpa-state="' . $h($s->componentId) . '">' . $this->codec->encodeState($s) . '</script>';
        return new RenderResult($html, state:$s);
    }
}
