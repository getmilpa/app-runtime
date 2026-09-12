<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Web;

use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\{ComponentContract,ComponentContext,StateSnapshot,InteractionRequest,InteractionResult,ActionContract,ComponentPresentation};

/** The review UI calls the same revision service as the operation surface. */
final readonly class ScreenReviewComponent implements ComponentDefinitionInterface
{
    public function __construct(private ScreenDrafts $drafts, private string $route)
    {
    }
    /** Review and activation are separate scoped actions on a signed, owned component. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract('screen-review', '1', presentation:new ComponentPresentation(styles:__DIR__ . '/../../resources/screen-review/style.css'), summary:'Review an immutable screen proposal against its active baseline.', actions:[
            'draft' => new ActionContract('Save a new proposal.', mutating:true, payload:['name' => 'string','type' => 'string','props' => 'string']),
            'read' => new ActionContract('Inspect a revision.', payload:['revision' => 'string']),
            'promote' => new ActionContract('Activate the selected revision.', mutating:true, payload:['revision' => 'string']),
            'rollback' => new ActionContract('Restore the selected revision’s baseline.', mutating:true, payload:['revision' => 'string']),
        ]);
    }
    /** The server owns revision selection, status, and the current catalogue. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        $id = is_string($props['revision'] ?? null) ? $props['revision'] : '';
        return new StateSnapshot($context->componentId, 'screen-review', '1', $this->data($id, 'en', ''), ['principal' => $context->principal,'route' => $context->route]);
    }
    /** Interactions re-read durable records; clients cannot supply a replacement declaration to promotion. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        $old = $request->state;
        $id = $old->data['selected'];
        $locale = $old->data['locale'];
        $notice = '';
        $error = null;
        try {
            if ($request->action === 'read') {
                $id = is_string($request->payload['revision'] ?? null) ? $request->payload['revision'] : $id;
                if (in_array($request->payload['locale'] ?? '', ['en','es'], true)) {
                    $locale = $request->payload['locale'];
                }
            } elseif ($request->action === 'draft') {
                $props = json_decode(is_string($request->payload['props'] ?? null) ? $request->payload['props'] : '', true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($props) || ($props !== [] && array_is_list($props))) {
                    throw new \DomainException('invalid_props');
                }
                $d = $this->drafts->draft((string)($request->payload['name'] ?? ''), (string)($request->payload['type'] ?? ''), $props);
                $id = $d['id'];
                $notice = 'draft_saved';
            } elseif (in_array($request->action, ['promote','rollback'], true)) {
                // The UI's selected revision must equal the payload; a stale button cannot choose another.
                if ($id === '' || ($request->payload['revision'] ?? '') !== $id) {
                    throw new \DomainException('revision_changed');
                }
                $request->action === 'promote' ? $this->drafts->promote($id) : $this->drafts->rollback($id);
                $notice = $request->action === 'promote' ? 'promoted' : 'restored';
            } else {
                throw new \DomainException('unknown_action');
            }
            $data = $this->data($id, $locale, $notice);
        } catch (\DomainException|\JsonException|InvalidScreenTree $e) {
            $error = $e instanceof \JsonException ? 'invalid_props' : $e->getMessage();
            $data = $this->data('', $locale, $error);
        }
        return new InteractionResult(new StateSnapshot($old->componentId, $old->componentName, $old->version, $data, $old->meta), errors:$error === null ? [] : ['review' => $error]);
    }
    /** @return array<string,mixed> */
    private function data(string $id, string $locale, string $notice): array
    {
        return $this->drafts->catalogue() + ['selected' => $id,'review' => $id === '' ? null : $this->drafts->review($id),'locale' => $locale,'notice' => $notice,'route' => $this->route];
    }
}
