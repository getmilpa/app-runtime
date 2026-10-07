<?php
/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace App\ReadingSession;

use Milpa\Live\Assets\ComponentMessages;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\{ActionContract, ComponentContext, ComponentContract, ComponentPresentation, InteractionRequest, InteractionResult, StateSnapshot};

final readonly class ReadingSession implements ComponentDefinitionInterface
{
    public static function contract(): ComponentContract
    {
        return new ComponentContract('reading-session', '1', summary: 'Pages read toward a session goal.',
            propsSchema: ['title' => ['type' => 'string'], 'locale' => ['type' => 'string', 'enum' => ['en', 'es']]],
            actions: [
                'advance' => new ActionContract('Record one more page.', mutating: true),
                'reset' => new ActionContract('Start a new reading session.', mutating: true),
                'goal' => new ActionContract('Set a goal of 1 to 500 pages.', mutating: true, payload: ['goal' => 'string']),
                'locale' => new ActionContract('Change display language.', payload: ['locale' => 'string']),
            ], presentation: new ComponentPresentation(styles: __DIR__ . '/session.css', messages: __DIR__ . '/messages.php'));
    }

    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        $locale = ($props['locale'] ?? $context->locale) === 'es' ? 'es' : 'en';
        return new StateSnapshot($context->componentId, 'reading-session', '1', [
            'title' => is_string($props['title'] ?? null) ? $props['title'] : '',
            'pages' => 0, 'goal' => 20, 'locale' => $locale, 'notice' => '',
        ], ['principal' => $context->principal, 'route' => $context->route]);
    }

    public function handle(InteractionRequest $request): InteractionResult
    {
        $state = $request->state;
        $data = $state->data;
        $data['notice'] = '';
        if ($request->action === 'advance') {
            $data['pages'] = min(100000, $data['pages'] + 1);
        } elseif ($request->action === 'reset') {
            $data['pages'] = 0;
        } elseif ($request->action === 'goal') {
            $goal = filter_var($request->payload['goal'] ?? null, FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 500]]);
            if ($goal === false) {
                $data['notice'] = 'invalid_goal';
            } else {
                $data['goal'] = $goal;
            }
        } elseif ($request->action === 'locale' && in_array($request->payload['locale'] ?? null, ['en', 'es'], true)) {
            $data['locale'] = $request->payload['locale'];
        } else {
            $data['notice'] = 'invalid_action';
        }
        $next = new StateSnapshot($state->componentId, $state->componentName, $state->version, $data, $state->meta);
        $messages = (new ComponentMessages())->for(self::contract(), $data['locale']);
        return new InteractionResult($next, errors: $data['notice'] === '' ? [] : ['session' => $messages[$data['notice']]]);
    }
}
