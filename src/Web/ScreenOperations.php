<?php

/**
 * This file is part of Milpa App Runtime — the application runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Web;

use Milpa\Command\CommandProvider;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;

/**
 * The primitive that lets the AGENT author a live screen MATERIALLY (greenhouse decisions/0158):
 * `screen:declare` persists a screen DECLARATION (a data-table — name + columns + rows) to the
 * {@see ScreenStore}. {@see LivePlugin} reads that store to register the screen as a live component
 * and {@see DeclaredScreensPageProvider} serves its data, so a declared screen answers at
 * `GET /live/page?component=<name>` with NO code deploy.
 *
 * The operation MUTATES and declares its effect profile, so it flows through the governed path the
 * runtime already owns. The default store lives under var/ and is not a publishable trial diff;
 * a host that needs promotion must explicitly choose a tracked path (Greenhouse 0325/0641).
 * The served screen is bound to the component-type scope. The agent declares a datum; the framework projects; the human
 * governs. Contributed by LivePlugin (a booted `CommandProvider`), so enabling the live door enables
 * the author-material loop — no per-app wiring.
 */
final class ScreenOperations implements CommandProvider
{
    /**
     * @param list<string> $types the component types a screen may be declared as (data-table, state-machine,
     *                            …); an unknown type is refused so a screen never registers against a class
     *                            that does not exist. Empty means «any» (validation deferred to registration).
     */
    public function __construct(
        private readonly ScreenStore $store,
        private readonly array $types = [],
        private readonly ?LayoutStateStore $layout = null,
        /** @var \Closure(): ?ScreenComponents|null live registry resolver; null preserves standalone callers */
        private readonly ?\Closure $registry = null,
        /**
         * WHY the registry is absent, when the host knows — so the refusal names the fix instead of the
         * symptom. «The registry is not mounted» is true and useless: the reader cannot mount a registry,
         * but can set a secret (greenhouse evidence/0995).
         *
         * @var \Closure(): ?string|null
         */
        private readonly ?\Closure $unmountedBecause = null,
        /**
         * Serves a declared screen through the REAL page path and answers its HTTP status — or null when
         * it cannot be asked. When the host wires it, the «served» receipt is EARNED rather than assumed.
         * Standalone callers leave it null and keep the previous behaviour.
         *
         * @var \Closure(string): ?int|null
         */
        private readonly ?\Closure $serve = null,
        /** The words this house added to its visual language (decisions/0465); null: no words. */
        private readonly ?ComponentWords $words = null,
    ) {
    }

    /**
     * The operations the live screen store contributes: screen:declare, screen:list and screen:forget.
     *
     * @return list<Operation>
     */
    public function operations(): array
    {
        return [
            ...($this->registry === null ? [] : [new Operation(
                name: 'screen:types',
                description: 'List registered components that can render as HTML screens and round-trip actions. Names come from the live registry, including plugins registered after boot.',
                handler: fn (array $input): array => ($this->registry)()?->catalogue() ?? ['types' => [], 'unavailable' => []],
                effects: EffectProfile::readOnly(),
                outputSchema: ['type' => 'object', 'properties' => ['types' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]]]],
            )]),
            new Operation(
                name: 'screen:declare',
                description: 'Declare a live screen by name and component type (default data-table) with its props. It is served at /live/page?component=<name> with no code deploy. A data-table may pass columns/rows at the top level. A type whose contract has rows (data-table, content) may bind to a public entity with source instead of rows. Any type passes its props under "props".',
                handler: fn (array $input): array => $this->declare($input),
                inputSchema: [
                    'type' => 'object',
                    'required' => ['name'],
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'a-z, 0-9, dash; starts with a letter'],
                        'type' => $this->registry !== null
                            ? ['type' => 'string', 'description' => 'a currently registered HTML component; discover with screen:types', 'x-milpa-source' => ['tool' => 'screen:types', 'path' => 'types', 'key' => 'name']]
                            : ($this->types === []
                            ? ['type' => 'string', 'description' => 'the SDK component type; default data-table']
                            : ['type' => 'string', 'enum' => $this->types, 'description' => 'the SDK component type; default data-table']),
                        'props' => ['type' => 'object', 'description' => 'the component-type props (e.g. state-machine: { machine: { initial, transitions } })'],
                        'columns' => ['type' => 'array', 'description' => 'data-table convenience: list of { key, label }'],
                        'rows' => ['type' => 'array', 'description' => 'data-table convenience: list of row objects keyed by column key'],
                        'source' => [
                            'type' => 'object',
                            'description' => 'instead of rows, for a type whose contract has rows (data-table, content): bind to an entity that declares PUBLIC_WHEN; the runtime serves its public rows per request, projected to the named fields',
                            'required' => ['entity', 'columns'],
                            'properties' => [
                                'entity' => ['type' => 'string', 'description' => 'the entity class: App\\Plugins\\<Plugin>\\Entities\\<Entity>'],
                                'columns' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'the entity fields to show'],
                                'limit' => ['type' => 'integer', 'description' => 'rows served, 1 to 200; default 50'],
                            ],
                        ],
                    ],
                ],
                mutating: true,
                scopes: ['milpa:component:data-table:*'],
                effects: new EffectProfile(
                    Mutation::Persistent,
                    Externality::None,
                    Reversibility::Guaranteed,
                    subject: Subject::Data,
                    // The inverse was already IN the sentence, wrapped where no machine could use it.
                    rollbackContract: 'screen:forget',
                ),
            ),
            new Operation(
                name: 'screen:list',
                description: 'List the live screens declared at runtime — name, where each is served, and its shape. Read-only.',
                handler: fn (array $input): array => ['screens' => $this->store->catalogue()],
                effects: EffectProfile::readOnly(),
            ),
            new Operation(
                name: 'screen:forget',
                description: 'Forget a runtime-declared screen by name — the rollback of screen:declare. It stops being served at /live/page?component=<name>.',
                handler: fn (array $input): array => $this->forget($input),
                inputSchema: [
                    'type' => 'object',
                    'required' => ['name'],
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'the declared screen to forget'],
                    ],
                ],
                mutating: true,
                scopes: ['milpa:component:data-table:*'],
                // The screen to forget must be NAMED by the request (ADR-0044): «remove the old screen» does
                // not execute against a guessed target — it asks, with the operation and the name in the ask.
                namedTarget: 'name',
                effects: new EffectProfile(
                    Mutation::Persistent,
                    Externality::None,
                    // COMPENSATABLE, not Guaranteed: re-declaring the screen restores it, but the delete is not
                    // free — the prior rows/columns are gone unless the caller re-supplies them.
                    Reversibility::Compensatable,
                    subject: Subject::Data,
                    rollbackContract: 'declare the screen again with screen:declare',
                ),
            ),
            new Operation(
                name: 'screen:set-state',
                description: 'Set one shared value of a layout (its layout state) for a session — what a child WRITES so another child READS it. Server-authoritative and isolated per session; the layout recomputes from it.',
                handler: fn (array $input): array => $this->setState($input),
                inputSchema: [
                    'type' => 'object',
                    'required' => ['session', 'screen', 'key', 'value'],
                    'properties' => [
                        'session' => ['type' => 'string', 'description' => 'the session whose layout state to set (its owner)'],
                        'screen' => ['type' => 'string', 'description' => 'the layout screen'],
                        'key' => ['type' => 'string', 'description' => 'the shared key (e.g. a filter name)'],
                        'value' => ['type' => 'string', 'description' => 'the shared value'],
                    ],
                ],
                mutating: true,
                // The contract decides the cost, not the syntax (greenhouse decisions/0169): setting a layout's
                // shared value is EPHEMERAL, touches only the caller's own session (WriteAsUser), reaches nothing
                // outside it, and is fully reversible. So the composition can conclude it is cheap — no human
                // ceremony — while a heavier write that happened to use the same shape would NOT, because its
                // profile would say so.
                effects: new EffectProfile(
                    Mutation::Ephemeral,
                    Externality::None,
                    // COMPENSATABLE, NOT GUARANTEED — greenhouse decisions/0221.
                    //
                    // Two reasons, and either one is enough. This handler REFUSES when no
                    // `LayoutStateStore` is wired, so the undo depends on the host having wired
                    // something; and «call me again with the previous value» is a claim about
                    // ARGUMENTS, which nothing can check — `RollbackContracts` now reports an
                    // operation that names itself, and it is right to.
                    //
                    // A compensating action exists and it is this one. What does not exist is a
                    // guarantee, and `Guaranteed` is the only value in that enum that BUYS lower
                    // scrutiny.
                    Reversibility::Compensatable,
                    Authority::WriteAsUser,
                    subject: Subject::Data,
                    rollbackContract: 'call screen:set-state again with the previous value',
                ),
            ),
        ];
    }

    /**
     * Set one shared value of a layout's state for a session (greenhouse decisions/0169). Needs a wired
     * {@see LayoutStateStore}; without one it is a no-op that says so, never a silent success.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function setState(array $input): array
    {
        if (! $this->layout instanceof LayoutStateStore) {
            return ['ok' => false, 'error' => 'no layout state store is wired'];
        }

        return $this->layout->set(
            (string) ($input['session'] ?? ''),
            (string) ($input['screen'] ?? ''),
            (string) ($input['key'] ?? ''),
            (string) ($input['value'] ?? ''),
        );
    }

    /**
     * Refuse an unknown component type before it reaches the store, so a screen never registers against a
     * class that does not exist (the safety net at registration would just 404 it silently). An empty type
     * list means «any» — validation is deferred to registration.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function declare(array $input): array
    {
        $registry = $this->registry !== null ? ($this->registry)() : null;
        if ($this->registry !== null && $registry === null) {
            $because = $this->unmountedBecause !== null ? ($this->unmountedBecause)() : null;

            return ['ok' => false, 'error' => 'live screen registry is not mounted'
                . ($because !== null ? ': ' . $because : '')];
        }
        $types = $registry?->types() ?? $this->types;
        $type = trim((string) ($input['type'] ?? ScreenStore::DEFAULT_TYPE)) ?: ScreenStore::DEFAULT_TYPE;
        if (($registry !== null || $types !== []) && ! \in_array($type, $types, true)) {
            return ['ok' => false, 'error' => 'unknown component type', 'type' => $type, 'known' => $types];
        }

        if ($registry?->conflicts(trim((string) ($input['name'] ?? '')), $type)) {
            return ['ok' => false, 'error' => 'screen name conflicts with a registered component', 'path' => 'name'];
        }
        $props = $input['props'] ?? [];
        if (! \is_array($props) || ($props !== [] && array_is_list($props))) {
            return ['ok' => false, 'error' => 'invalid screen tree', 'path' => 'props', 'reason' => 'props must be an object'];
        }

        // A WORD OF THIS HOUSE (greenhouse decisions/0465) is compiled here to the tree it stands for,
        // and from this line on the screen is that tree — validated, stored and served by the path every
        // screen takes. The screen remembers which word and which version produced it.
        $word = $this->words?->word($type);
        if ($word !== null && ! \in_array($type, $registry?->primitives() ?? [], true)) {
            if (\array_key_exists('source', $input)) {
                return ['ok' => false, 'error' => 'invalid screen tree', 'path' => 'source', 'reason' => "«{$type}» takes its inputs as props, not a source"];
            }
            try {
                $compiled = $this->words->compile($type, $props);
            } catch (InvalidScreenTree $error) {
                return ['ok' => false, 'error' => 'invalid screen tree', 'path' => $error->path, 'reason' => $error->getMessage()];
            }
            $type = $compiled['type'];
            $props = $compiled['props'];
            $input = ['name' => $input['name'] ?? '', 'type' => $type, 'props' => $props, 'word' => $compiled['word']];
        }

        try {
            ScreenTree::validate($type, $props, $types);
        } catch (InvalidScreenTree $error) {
            return ['ok' => false, 'error' => 'invalid screen tree', 'path' => $error->path, 'reason' => $error->getMessage()];
        }

        // A BOUND screen (greenhouse decisions/0462) is refused here, by name, rather than stored and
        // answered 422 later: the rows come from the source and not from the caller, and the entity must
        // already declare what of it is public.
        //
        // 🚨 A BINDING IS AN OBJECT. `autocomplete` already names its data source with a string
        // `props.source`, and reading every `source` as a binding refused every autocomplete declaration
        // from 0.180.0 on (greenhouse decisions/0464). A string stays the component's own prop.
        $source = \array_key_exists('source', $input) ? $input['source'] : ($props['source'] ?? null);
        if (\is_array($source) || (\array_key_exists('source', $input) && $source !== null)) {
            // WHO MAY BIND IS THE CONTRACT'S, NOT THE NAME'S (decisions/0464): a type binds when its contract
            // declares the `rows` prop a binding fills. Without a registry the contract cannot be read, and
            // only the default table is assumed.
            $schema = $this->propsSchemaOf($registry, $type);
            if (! \array_key_exists('rows', $schema)) {
                return ['ok' => false, 'error' => 'invalid screen tree', 'path' => 'source', 'reason' => "«{$type}» declares no rows prop, so it cannot bind to an entity"];
            }
            if (\array_key_exists('rows', $input) || \array_key_exists('rows', $props)) {
                return ['ok' => false, 'error' => 'invalid screen tree', 'path' => 'rows', 'reason' => 'a bound screen reads its rows from its source; declare either rows or source'];
            }
            try {
                $binding = PublicSource::validate($source);
            } catch (InvalidScreenTree $error) {
                return ['ok' => false, 'error' => 'invalid screen tree', 'path' => $error->path, 'reason' => $error->getMessage()];
            }
            // A contract that shows columns shows the ones the binding reads, unless the caller labelled them.
            if (\array_key_exists('columns', $schema) && ! \array_key_exists('columns', $input) && ! \array_key_exists('columns', $props)) {
                $input['columns'] = array_map(
                    static fn (string $key): array => ['key' => $key, 'label' => ucfirst(str_replace('_', ' ', $key))],
                    $binding['columns'],
                );
            }
            $input['source'] = $binding;
            unset($input['props']['source']);
        }

        $result = $this->store->declare($input);

        // THE OPERATION DECLARES WHAT IT DEMONSTRATED (greenhouse decisions/0187). A served screen is
        // real, verifiable evidence — a reader opens it at `servedAt` — but it is none of the three
        // producer-shaped facts a work claim used to recognise. So the successful result carries a
        // served-evidence receipt: a predicate («served») and its subject (the screen), the shape the
        // judge reads by what it DEMONSTRATES rather than by who produced it. It rides the
        // `session.tool_called` fact this call already leaves; nothing here indexes it a second time.
        if (($result['ok'] ?? false) === true
            && \is_string($result['screen'] ?? null)
            && \is_string($result['servedAt'] ?? null)
        ) {
            // 🚨 THE RECEIPT IS EARNED, NOT ASSUMED (greenhouse evidence/0995). This emitted «served» for
            // having STORED the screen, and a judge closes a work claim on that predicate (decisions/0187).
            // Measured: it answered `predicate: served` for a screen whose page answered 500 — so an agent
            // could have closed «I delivered the screen» over a broken page. Where the host can serve it,
            // the page is requested through the same controller a browser reaches, and only a 200 earns
            // the receipt. Anything else leaves the screen declared, and says it was not served.
            $status = $this->serve !== null ? ($this->serve)($result['screen']) : null;
            if ($this->serve !== null && $status !== 200) {
                $result['served'] = false;
                $result['status'] = $status;
                $result['note'] = $status === null
                    ? 'declared, but the page could not be requested here — no served evidence was recorded'
                    : "declared, but its page answered HTTP {$status} — no served evidence was recorded; "
                        . 'open ' . $result['servedAt'] . ' to see why';

                return $result;
            }

            $result['evidence'] = [
                'predicate' => 'served',
                'subject' => $result['screen'],
                'servedAt' => $result['servedAt'],
            ];
        }

        return $result;
    }

    /**
     * The props a type's contract declares, read from the live registry — or, with no registry, the
     * default table's `rows`/`columns`, the one shape a standalone caller can rely on.
     *
     * @return array<string, mixed>
     */
    private function propsSchemaOf(?ScreenComponents $registry, string $type): array
    {
        if ($registry === null) {
            return $type === ScreenStore::DEFAULT_TYPE ? ['rows' => [], 'columns' => []] : [];
        }

        return $registry->has($type) ? $registry->get($type)::contract()->propsSchema : [];
    }

    /**
     * Forget a declared screen, and — the symmetric half of {@see declare()} — carry an INVALIDATION
     * receipt when it succeeds (greenhouse decisions/0187). Where declare declares the served
     * predicate for its subject, forget declares that same predicate no longer holds: the anti-receipt
     * that a later reader derives freshness from. It rides the `session.tool_called` fact this call
     * already leaves, so the standing served receipt of the screen goes stale with no field the
     * producer sets to say so. A failed forget revoked nothing and so declares nothing.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function forget(array $input): array
    {
        $result = $this->store->forget((string) ($input['name'] ?? ''));

        if (($result['ok'] ?? false) === true && \is_string($result['forgotten'] ?? null)) {
            $result['evidence'] = [
                'predicate' => 'served',
                'subject' => $result['forgotten'],
                'invalidates' => true,
            ];
        }

        return $result;
    }
}
