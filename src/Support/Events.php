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
use Milpa\Interfaces\Event\DeclaresEvents;
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
 * ── THE APP, NOT THE PROCESS ────────────────────────────────────────────────────────────────────
 *
 * An emitter declares WHEN IT IS CONSTRUCTED, so a process that never builds it never hears about its
 * events: measured on cattle, a CLI process knew seven of the family's twenty-four names — the catalogue
 * was answering for the process, and whoever asks «what events exist?» is asking about the APP. The
 * package itself can speak for the emitter nobody constructed: it names a
 * {@see DeclaresEvents} holder in its own manifest (`extra.milpa.events`), and this fold reads those
 * manifests from `vendor/composer/installed.json` — what Composer really resolved — and declares the
 * holder's events to the dispatcher on the emitter's behalf. The dispatcher stays the authority; the
 * manifest is only how the declaration of an unbuilt emitter arrives, and since the first declaration of
 * a name wins, an emitter that already declared is never overridden and the pass is idempotent.
 *
 * A manifest that names a class which does not exist, or one that is not a holder, is NAMED in
 * `warnings` — never silently dropped, and never turned into an invented row.
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
     * Before the dispatcher is read, every holder named in an installed package's manifest declares on behalf
     * of the emitter this process did not construct; what could not be resolved comes back in `warnings`.
     *
     * @param null|string $appRoot the app whose manifests are read; deduced from the running autoloader when absent
     *
     * @return array<string, mixed>
     */
    public static function catalogue(DIContainerInterface $container, ?string $appRoot = null): array
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

        // The manifest pass FIRST, and only then the dispatcher: whoever declared at construction got there
        // before this, and the first declaration of a name wins — so speaking for an unbuilt emitter never
        // overrides the one that was really built.
        $warnings = self::declareWhatTheManifestsName($dispatcher, $appRoot);

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
            'warnings' => $warnings,
        ];
    }

    /**
     * Declares, on behalf of every emitter this process will not construct, what its package's manifest names —
     * and returns what could NOT be resolved, named.
     *
     * The dispatcher keeps the first declaration of a name, so this never overrides an emitter that already
     * declared for itself and calling it twice changes nothing. A manifest entry that names a class which is
     * not autoloadable here, or one that is not a {@see DeclaresEvents} holder, is a warning carrying the
     * package and the class: the disagreement between a manifest and its package is exactly what must be said
     * out loud, and an invented row would say the opposite.
     *
     * @return list<array{package: string, class: string, why: string}>
     */
    private static function declareWhatTheManifestsName(DeclaredEvents $dispatcher, ?string $appRoot): array
    {
        $warnings = [];
        foreach (self::holdersNamedInManifests($appRoot) as $named) {
            $package = $named['package'];
            $entry = $named['class'];
            if (!\is_string($entry) || trim($entry) === '') {
                $warnings[] = [
                    'package' => $package,
                    'class' => get_debug_type($entry),
                    'why' => 'extra.milpa.events must be a list of class names, and this entry is not one',
                ];

                continue;
            }
            $class = ltrim(trim($entry), '\\');
            if (!class_exists($class)) {
                $warnings[] = [
                    'package' => $package,
                    'class' => $class,
                    'why' => 'named in extra.milpa.events but no such class is autoloadable in this app, so the events it would declare are missing from this catalogue',
                ];

                continue;
            }
            if (!is_a($class, DeclaresEvents::class, true)) {
                $warnings[] = [
                    'package' => $package,
                    'class' => $class,
                    'why' => \sprintf(
                        'named in extra.milpa.events but it does not implement %s, so it cannot be asked what the package dispatches — the events it would declare are missing from this catalogue',
                        DeclaresEvents::class,
                    ),
                ];

                continue;
            }
            try {
                $declarations = $class::declarations();
                if ($declarations !== []) {
                    $dispatcher->declare(...$declarations);
                }
            } catch (\Throwable $e) {
                // NOT swallowed: a holder that throws is reported with what it threw, in the same list a
                // missing class lands in — the catalogue keeps answering, minus that package, and says so.
                $warnings[] = [
                    'package' => $package,
                    'class' => $class,
                    'why' => \sprintf('%s::declarations() failed with %s: %s', $class, $e::class, $e->getMessage()),
                ];
            }
        }

        return $warnings;
    }

    /**
     * Every holder class a manifest names: each installed package's `extra.milpa.events`, then the app's own.
     *
     * Read exactly the way {@see Capabilities::declaredBy()} reads capabilities — from
     * `vendor/composer/installed.json`, which is what Composer really put there, written by Composer and not
     * by a person, and never a probe for a class somebody named from memory (evidence/0565). An app without
     * that file yields nothing from its vendor: «I could not know», not «there is nothing».
     *
     * The app's own `composer.json` is read LAST so a package stays the authority on its own events: an app
     * adds what it dispatches itself, it does not restate somebody else's.
     *
     * @return list<array{package: string, class: mixed}>
     */
    private static function holdersNamedInManifests(?string $appRoot): array
    {
        $root = rtrim($appRoot ?? Capabilities::raizDeLaApp(), '/');

        $named = [];
        $installed = self::readJson($root . '/vendor/composer/installed.json');
        $packages = \is_array($installed['packages'] ?? null) ? $installed['packages'] : [];
        foreach ($packages as $package) {
            if (!\is_array($package) || !\is_string($package['name'] ?? null)) {
                continue;
            }
            foreach (self::entriesOf($package) as $entry) {
                $named[] = ['package' => $package['name'], 'class' => $entry];
            }
        }

        $own = self::readJson($root . '/composer.json');
        $ownName = \is_string($own['name'] ?? null) ? $own['name'] : 'this app';
        foreach (self::entriesOf($own) as $entry) {
            $named[] = ['package' => $ownName, 'class' => $entry];
        }

        return $named;
    }

    /**
     * The `extra.milpa.events` entries of one manifest — a scalar is read as a single entry so a manifest
     * that named one holder without a list still speaks, and anything else yields nothing to resolve.
     *
     * @param array<string, mixed> $manifest
     *
     * @return list<mixed>
     */
    private static function entriesOf(array $manifest): array
    {
        $extra = $manifest['extra'] ?? null;
        $events = \is_array($extra) ? ($extra['milpa']['events'] ?? null) : null;
        if ($events === null) {
            return [];
        }

        return \is_array($events) ? array_values($events) : [$events];
    }

    /**
     * One manifest, decoded — an absent or unreadable file is an empty array, never a guess.
     *
     * @return array<string, mixed>
     */
    private static function readJson(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($file), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * The same fold, compact — counts and names, the shape `house:context`'s sections keep.
     *
     * A dispatcher that cannot be read yields the same `ok:false` and reason the catalogue gives: a section
     * that answered zeros there would be a lie with the right shape. It carries the catalogue's `warnings`
     * verbatim for the same reason: a manifest this app could not resolve is missing events, and a section
     * that hid that would be counting less than the app has without saying so.
     *
     * @param null|string $appRoot the app whose manifests are read; deduced from the running autoloader when absent
     *
     * @return array<string, mixed>
     */
    public static function summary(DIContainerInterface $container, ?string $appRoot = null): array
    {
        $catalogue = self::catalogue($container, $appRoot);
        if ($catalogue['ok'] !== true) {
            return $catalogue;
        }

        return [
            'ok' => true,
            'dispatcher' => $catalogue['dispatcher'],
            'counts' => $catalogue['counts'],
            'names' => array_column($catalogue['events'], 'name'),
            'warnings' => $catalogue['warnings'],
        ];
    }
}
