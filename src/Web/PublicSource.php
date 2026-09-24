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

use Milpa\Data\EntityInterface;
use Milpa\Data\PagesResults;
use Milpa\Data\RepositoryInterface;

/**
 * A declared screen BOUND to an entity: the runtime fills its rows on every request, and only with what
 * that entity already declared public (greenhouse decisions/0462).
 *
 * The binding is `{entity: <FQCN>, columns: [<field>, …], limit?: <1..200>}`. Three things make it safe
 * to hand to an agent, and none of them is the agent's to write:
 *
 * - **Only a public entity.** The entity must declare `PUBLIC_WHEN` — the same constant its generated
 *   controller reads. An entity with no declared visibility cannot be bound at all: `screen:declare`
 *   sits at the anonymous-read ceiling, and a binding that could read private rows would raise it.
 * - **Only through that visibility.** The criteria is `[PUBLIC_WHEN => true]`, fixed here. There is no
 *   filter input, so there is no filter to get wrong.
 * - **Only the named columns.** Each row is projected to the declared columns, each checked against the
 *   entity's constructor when declared — a column that is not a field is refused, never served empty.
 *
 * Everything that cannot be satisfied throws {@see InvalidScreenTree} with the failing path, so a
 * declaration is refused by name and a served page answers 422 — never a table of invented or leaked rows.
 */
final class PublicSource
{
    /** The rows a bound screen serves when the declaration names no limit. */
    public const DEFAULT_LIMIT = 50;

    /** The most rows a bound screen may serve — the page is a window, not an export. */
    public const MAX_LIMIT = 200;

    /**
     * The binding, checked and normalised — or {@see InvalidScreenTree} naming what is wrong.
     *
     * @return array{entity: class-string<EntityInterface>, columns: list<string>, limit: int}
     */
    public static function validate(mixed $source): array
    {
        if (! \is_array($source) || array_is_list($source)) {
            throw new InvalidScreenTree('source', 'source must be an object: {entity, columns}');
        }
        $entity = $source['entity'] ?? null;
        if (! \is_string($entity) || trim($entity) === '') {
            throw new InvalidScreenTree('source.entity', 'source.entity must name the entity class');
        }
        $entity = ltrim(trim($entity), '\\');
        if (! class_exists($entity) || ! is_subclass_of($entity, EntityInterface::class)) {
            throw new InvalidScreenTree('source.entity', "{$entity} is not an entity this app can load");
        }
        $publicWhen = self::publicWhen($entity);
        if ($publicWhen === null) {
            throw new InvalidScreenTree(
                'source.entity',
                "{$entity} declares no PUBLIC_WHEN, so nothing about it is public; declare it with make:crud --public-when=<bool field>",
            );
        }

        $fields = self::fields($entity);
        $columns = $source['columns'] ?? null;
        if (! \is_array($columns) || $columns === [] || ! array_is_list($columns)) {
            throw new InvalidScreenTree('source.columns', 'source.columns must list the fields to show, e.g. ["title"]');
        }
        $named = [];
        foreach ($columns as $i => $column) {
            if (! \is_string($column) || ! \in_array($column, $fields, true)) {
                throw new InvalidScreenTree(
                    "source.columns.{$i}",
                    'not a field of ' . $entity . '; its fields are: ' . implode(', ', $fields),
                );
            }
            $named[] = $column;
        }

        $limit = $source['limit'] ?? self::DEFAULT_LIMIT;
        if (! \is_int($limit) || $limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidScreenTree('source.limit', 'source.limit is a whole number from 1 to ' . self::MAX_LIMIT);
        }

        return ['entity' => $entity, 'columns' => array_values(array_unique($named)), 'limit' => $limit];
    }

    /**
     * The public rows of the bound entity, projected to the declared columns.
     *
     * @param \Closure(string): ?object $service resolves a container service by id, or null when absent
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(mixed $source, \Closure $service): array
    {
        $binding = self::validate($source);
        $id = $binding['entity'] . 'Repository';
        $repository = $service($id);
        if (! $repository instanceof RepositoryInterface) {
            throw new InvalidScreenTree('source.entity', "no repository is registered as {$id}; is the plugin that owns it enabled?");
        }

        $criteria = [(string) self::publicWhen($binding['entity']) => true];
        $entities = $repository instanceof PagesResults
            ? $repository->page($criteria, $binding['limit'])
            : \array_slice($repository->query($criteria), 0, $binding['limit']);

        $rows = [];
        foreach ($entities as $entity) {
            $row = $entity->toArray();
            $rows[] = array_combine(
                $binding['columns'],
                array_map(static fn (string $column): mixed => $row[$column] ?? null, $binding['columns']),
            );
        }

        return $rows;
    }

    /** The declared visibility field, or null when the entity declares none (or declares it badly). */
    private static function publicWhen(string $entity): ?string
    {
        $constant = $entity . '::PUBLIC_WHEN';
        if (! \defined($constant)) {
            return null;
        }
        $field = \constant($constant);

        return \is_string($field) && $field !== '' ? $field : null;
    }

    /**
     * The entity's fields, read from its constructor — the shape `make` writes and `fromArray()` fills.
     *
     * @return list<string>
     */
    private static function fields(string $entity): array
    {
        $constructor = (new \ReflectionClass($entity))->getConstructor();

        return $constructor === null ? [] : array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            $constructor->getParameters(),
        );
    }
}
