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

use Milpa\AppRuntime\Entity\EntityName;
use Milpa\Data\EntityInterface;
use Milpa\Data\RepositoryInterface;

/**
 * What a mounted screen served, against what it had to serve (greenhouse decisions/0576, slice BV-3b).
 *
 * Measured on cattle (greenhouse evidence/1108): the house closed `verified: true` over a mounted screen that read
 * «Nothing to read yet.» — it answered 200 and was nobody's scaffold. The house does not need the goal, a todo or a
 * model to know that page is empty: the screen's DECLARATION says which entity it lists and which fields it paints,
 * and the entity says which rows are public (`PUBLIC_WHEN`, decisions/0462). This compares the two.
 *
 * It reads; it decides nothing. Whether an observation closes the work is the closure's to say, from these counts.
 */
final class ListedContent
{
    /** Rows a bound screen serves when its declaration does not say (the same default the binding uses). */
    private const DEFAULT_LIMIT = 50;

    /**
     * The counts for the screen mounted at a route, or null when there is nothing the house can compare: no screen
     * is mounted there, it binds no entity's rows, or the entity cannot be read here.
     *
     * A field counts as shown when its value is in the text a visitor reads; an empty value is not asked for. A row
     * that is not public counts as leaked when one of its painted fields shows and is not also a public row's.
     *
     * @param \Closure(string): ?object $service the house's container, by service id
     * @param string                    $body    the page the house was served at that route
     *
     * @return array{entity: string, public: int, shown: int, withheld: int, leaked: int, withholding: string}|null
     */
    public static function of(ScreenStore $screens, string $route, \Closure $service, string $body): ?array
    {
        $name = null;
        foreach ($screens->mounts() as $mounted => $screen) {
            if (ScreenRoute::key($mounted) === ScreenRoute::key($route)) {
                $name = $screen;
            }
        }
        $source = $name === null ? null : ($screens->screen($name)['props']['source'] ?? null);
        $columns = \is_array($source) && \is_array($source['columns'] ?? null) ? array_values(array_filter($source['columns'], 'is_string')) : [];
        if (! \is_array($source) || ! \is_string($source['entity'] ?? null) || $columns === []) {
            return null;
        }
        $class = EntityName::resolve($source['entity']);
        $repository = $service($class . 'Repository');
        if (! is_subclass_of($class, EntityInterface::class) || ! \defined($class . '::PUBLIC_WHEN') || ! $repository instanceof RepositoryInterface) {
            return null;
        }

        $publicWhen = (string) \constant($class . '::PUBLIC_WHEN');
        $public = $withheld = [];
        foreach ($repository->all() as $entity) {
            $row = $entity->toArray();
            $painted = array_map(static fn (string $column): string => self::read($row[$column] ?? null), $columns);
            if (($row[$publicWhen] ?? null) === true) {
                $public[] = $painted;
            } else {
                $withheld[] = $painted;
            }
        }
        $public = \array_slice($public, 0, \is_int($source['limit'] ?? null) ? $source['limit'] : self::DEFAULT_LIMIT);

        $text = self::read(html_entity_decode(
            (string) preg_replace(['~<(script|style)\b.*?</\1>~is', '~<[^>]+>~'], ' ', $body),
            \ENT_QUOTES | \ENT_HTML5,
        ));
        $shows = static fn (string $value): bool => $value !== '' && str_contains($text, $value);
        $shown = \count(array_filter($public, static fn (array $row): bool => array_filter($row, static fn (string $value): bool => $value !== '' && ! $shows($value)) === []));
        $leaked = 0;
        foreach ($withheld as $row) {
            foreach ($row as $at => $value) {
                if ($shows($value) && ! \in_array($value, array_column($public, $at), true)) {
                    ++$leaked;

                    break;
                }
            }
        }

        return [
            'entity' => self::named($class),
            'public' => \count($public),
            'shown' => $shown,
            'withheld' => \count($withheld),
            'leaked' => $leaked,
            'withholding' => $withheld === [] ? 'unexercised' : 'exercised',
        ];
    }

    /** An entity as the house names it to a reader: `Plugin/Entity` for a plugin's entity, else its short class name. */
    public static function named(string $class): string
    {
        return preg_match('~\\\\Plugins\\\\([A-Za-z_][A-Za-z0-9_]*)\\\\Entities\\\\([A-Za-z_][A-Za-z0-9_]*)$~D', $class, $names) === 1
            ? $names[1] . '/' . $names[2]
            : substr((string) strrchr('\\' . $class, '\\'), 1);
    }

    /** A value as a visitor reads it: text, with its whitespace collapsed. */
    private static function read(mixed $value): string
    {
        return trim((string) preg_replace('~\s+~u', ' ', \is_scalar($value) ? (string) $value : ''));
    }
}
