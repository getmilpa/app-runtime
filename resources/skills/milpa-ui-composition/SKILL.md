---
name: milpa-ui-composition
description: Compose usable application screens with Milpa Components, including native fields, server actions and reviewable previews. Use when building or changing an app interface or its HTML renderer.
requires: screen:declare
---

# Milpa UI composition

Keep the requested UI. For failed proposals, read diagnostics and complete proposed
bytes before general discovery. For new screens, read scaffolds, tests and SDK map.
Use installed identifiers/signatures; preserve tests.

## Implement and deliver

Use bare plugin/class names with implement/edit. Send a complete inline file as content
without mode. mode=start requires content and stages bytes; it does not finish a file.
For existing staging, use offered implement(mode=amend, expected_sha256, edits) with
exact find/replace pairs. Promote the accepted trial, then finish to judge the file.
For staging read [multipart](references/multipart.md); resolve paths from skill_load's
base. Other references cover missing contracts; [workflow](references/workflow.md)
covers new screens.

After inline rejection, if edit offers source, use the call's session,
tool-call seq and submitted_sha256 as source.sha256. Without source, edit targets
the current file, possibly a scaffold. Otherwise repair and resubmit the full body.
Promote accepted trials before their consumers.

Start agent_result(session,seq) without cursor; cursor:"" is invalid. Copy returned
next_cursor unchanged for later pages until null. After an argument
rejection, correct the named argument and use an offered tool. A rejected argument
alone does not prove a missing framework capability.

For long source, start offered source_page without a cursor:
```json
{"path":"src/Plugins/Owned/Services/Example.php.milpa-part"}
```
Use your path and copy returned cursors; never invent them. source_read can be cut
in transit despite truncated:false. On continuation, reload an absent skill with
skill_load when offered. History excerpts are incomplete: recover visible source_page
records with agent_result, page their JSON, then join content by source offset and
matching hash. Batch independent records; wait for their cursors. Alternatively,
agent_argument reads content from visible own implement start/append seqs; finish
has none. Provider tool_call_id is not a seq. Reading does not clear recovery.

Check actions, errors, locales, keyboard, narrow layout and child resources. Obtain
the exact review link. Rendering, loading and activation are separate governed steps;
authoring never grants human approval.

Match the executable consumer's event attribute names exactly. The native pattern is
`@click="act(...)"` (or `x-on:click`) for buttons and `@submit.prevent` for forms; do
not add button modifiers unless the installed consumer or acceptance contract names
them. Use the domain's concrete action names rather than inventing a generic toggle.
When a failed trial returns `summary.complete=true`, act from that bounded summary
first. Follow its `next` transition and do not page the complete recorded result unless
the error excerpt is insufficient; never repeat an unchanged `finish` call.

## Executable composition

This complete renderer tracks a reading session. Adapt its pattern, not its product: supplied
state wins; native fields receive distinct IDs and the parent's principal, route and locale;
the parent owns actions and reads current FormData; both resource channels return with the state.
ComponentMessages is an instance; resolveFor takes a RenderTarget enum. RenderResult carries
contracts under assets['componentContracts']. Keep the signed envelope and client identity aligned.

The supporting component, catalog, CSS and preview registration are in
[the reading-session recipe](references/reading-session.md). This block is the renderer's
canonical source. It stores session state only; an app needing durable records needs a repository.

```php
<?php
/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace App\ReadingSession;

use Milpa\Live\Assets\ComponentMessages;
use Milpa\Live\Contracts\Component\{ComponentDefinitionInterface, ComponentRegistryInterface};
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\Contracts\Transport\StateTransferCodecInterface;
use Milpa\Live\Rendering\ComponentRendererRegistry;
use Milpa\Live\Support\Html;
use Milpa\Live\ValueObjects\{ComponentContext, RenderRequest, RenderResult, RenderTarget};

final readonly class ReadingSessionRenderer implements ComponentRendererInterface
{
    public function __construct(private StateTransferCodecInterface $codec,
        private ComponentRegistryInterface $components, private ComponentRendererRegistry $renderers) {}

    public function supportsTarget(RenderTarget $target): bool { return $target === RenderTarget::HTML; }

    public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
    {
        $state = $request->state ?? $component->mount($request->props, $request->context);
        $data = $state->data;
        $messages = (new ComponentMessages())->for($component::contract(), $data['locale']);
        $text = array_map(Html::escape(...), $messages);
        $field = $this->components->get('input');
        $child = $this->renderers->resolveFor('input', RenderTarget::HTML)->render($field, new RenderRequest(
            new ComponentContext($state->componentId . '-goal', $state->meta['principal'], $data['locale'], $state->meta['route']),
            ['name' => 'goal', 'type' => 'number', 'value' => (string) $data['goal'], 'storage' => 'none',
                'label' => $messages['goal'], 'required' => true]));
        $root = Html::attrs(['class' => 'reading-session', 'lang' => $data['locale'],
            'data-milpa-component' => $state->componentName, 'data-milpa-component-id' => $state->componentId,
            'x-data' => 'milpaComponent(' . json_encode(['componentId' => $state->componentId], JSON_THROW_ON_ERROR) . ')',
            ':aria-busy' => 'busy']);
        $nextLocale = $data['locale'] === 'en' ? 'es' : 'en';
        $language = Html::attrs(['type' => 'button', '@click' => "act('locale', {locale: '$nextLocale'})", ':disabled' => 'busy']);
        $form = Html::attrs(['@submit.prevent' => "act('goal', Object.fromEntries(new FormData(\$event.target)))"]);
        $progress = Html::attrs(['value' => min($data['pages'], $data['goal']), 'max' => $data['goal'], 'aria-label' => $messages['progress']]);
        $html = '<main ' . $root . '><header><h1>' . $text['heading'] . '</h1><button ' . $language . '>'
            . $text['language_' . $nextLocale] . '</button></header><h2>'
            . ($data['title'] === '' ? $text['untitled'] : Html::escape($data['title'])) . '</h2>'
            . '<p class="reading-total" role="status" aria-live="polite">' . $data['pages'] . ' / ' . $data['goal'] . ' ' . $text['pages'] . '</p>'
            . '<progress ' . $progress . '></progress><p>' . $text[$data['pages'] === 0 ? 'empty' : ($data['pages'] >= $data['goal'] ? 'complete' : 'continue')] . '</p>'
            . '<div class="reading-actions"><button type="button" @click="act(\'advance\', {})" :disabled="busy">' . $text['advance'] . '</button>'
            . '<button type="button" @click="act(\'reset\', {})" :disabled="busy">' . $text['reset'] . '</button></div>'
            . '<form ' . $form . '>' . $child->output . '<button type="submit" :disabled="busy">' . $text['save'] . '</button></form>'
            . '<p role="alert">' . ($data['notice'] === '' ? '' : $text[$data['notice']]) . '</p><p role="alert" x-text="error"></p>'
            . '<p class="reading-note">' . $text['session_only'] . '</p></main>'
            . '<script type="application/milpa+xhtml" ' . Html::attrs(['data-milpa-state' => $state->componentId]) . '>'
            . $this->codec->encodeState($state) . '</script>';
        return new RenderResult($html, state: $state,
            assets: ['componentContracts' => [$component::contract(), $field::contract(), ...($child->assets['componentContracts'] ?? [])]],
            clientAssets: $child->clientAssets());
    }
}
```

Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency
