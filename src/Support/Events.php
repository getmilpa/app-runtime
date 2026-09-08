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

namespace Milpa\AppRuntime\Support;

use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Event\DeclaredEvents;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;

/**
 * The event table of a booted app: what its dispatcher was TOLD exists, against what it really dispatched —
 * the one fold `events:catalogue` and `house:context`'s events section share (greenhouse decisions/0228).
 *
 * The authority on «what events exist» is the emitter, and the one place every dispatch passes through is
 * the dispatcher: emitters declare their events to it where they hold it, and it remembers every name it
 * dispatched in this process. So this fold asks the dispatcher and nobody else — never a scan of source,
 * never a list kept here that would drift from the code that fires them. A name dispatched without a
 * declaration is debt with a name, and it is printed as such rather than hidden.
 *
 * A dispatcher that does not implement {@see DeclaredEvents} is asked nothing, and the answer SAYS SO —
 * `ok:false` naming the class and the interface it lacks — instead of answering «no events» (H-GATE-1: a
 * gap named in words, never a plausible empty list).
 */
final class Events
{
    /**
     * The catalogue: one row per event name, sorted, plus the counts.
     *
     * A declared row carries the declaration verbatim ({@see \Milpa\Interfaces\Event\EventDeclaration::toArray()})
     * with `declared: true` and whether this process dispatched it; a name dispatched without a declaration
     * carries `null` for everything only a declaration can say, `declared: false`, `dispatched: true`. The first
     * declaration of a name wins, the dispatcher's own rule.
     *
     * @return array<string, mixed>
     */
    public static function catalogue(DIContainerInterface $container): array
    {
        $dispatcher = $container->has(MilpaEventDispatcherInterface::class)
            ? $container->get(MilpaEventDispatcherInterface::class)
            : null;
        if (!$dispatcher instanceof MilpaEventDispatcherInterface) {
            return [
                'ok' => false,
                'error' => 'this app has no event dispatcher in its container, so there is nothing to ask — the kernel registers one when it boots',
            ];
        }
        if (!$dispatcher instanceof DeclaredEvents) {
            return [
                'ok' => false,
                'dispatcher' => $dispatcher::class,
                'error' => \sprintf(
                    '%s does not implement %s (milpa/core >= 0.11): it keeps no record of what was declared to it nor of what it dispatched, so the catalogue cannot be read from it — a dispatcher that does is milpa/events >= 0.4 (Milpa\Eventing\EventDispatcher)',
                    $dispatcher::class,
                    DeclaredEvents::class,
                ),
            ];
        }

        /** @var array<string, array<string, mixed>> $rows keyed by event name */
        $rows = [];
        foreach ($dispatcher->declared() as $declaration) {
            if (isset($rows[$declaration->name])) {
                // The dispatcher's own rule, applied again here so a dispatcher that did not apply it
                // still prints one row per name: the first declaration wins.
                continue;
            }
            $rows[$declaration->name] = $declaration->toArray() + ['declared' => true, 'dispatched' => false];
        }
        $declared = \count($rows);

        $dispatched = array_values(array_unique($dispatcher->dispatched()));
        $undeclared = 0;
        foreach ($dispatched as $name) {
            if (isset($rows[$name])) {
                $rows[$name]['dispatched'] = true;
                continue;
            }
            ++$undeclared;
            $rows[$name] = [
                'name' => $name,
                'dispatchedBy' => null,
                'when' => null,
                'subject' => null,
                'declared' => false,
                'dispatched' => true,
            ];
        }
        ksort($rows, \SORT_STRING);

        return [
            'ok' => true,
            'dispatcher' => $dispatcher::class,
            'counts' => [
                'declared' => $declared,
                'dispatched' => \count($dispatched),
                'undeclared' => $undeclared,
            ],
            'events' => array_values($rows),
        ];
    }

    /**
     * The same fold, compact — counts and names, the shape `house:context`'s sections keep.
     *
     * A dispatcher that cannot be read yields the same `ok:false` and reason the catalogue gives: a section
     * that answered zeros there would be a lie with the right shape.
     *
     * @return array<string, mixed>
     */
    public static function summary(DIContainerInterface $container): array
    {
        $catalogue = self::catalogue($container);
        if ($catalogue['ok'] !== true) {
            return $catalogue;
        }

        return [
            'ok' => true,
            'dispatcher' => $catalogue['dispatcher'],
            'counts' => $catalogue['counts'],
            'names' => array_column($catalogue['events'], 'name'),
        ];
    }
}
