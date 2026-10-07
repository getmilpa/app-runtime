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

namespace Milpa\AppRuntime\Entity;

use Milpa\Data\EntityInterface;
use Milpa\Data\RepositoryInterface;

/**
 * The rows a work is born with, declared — and the house seeds them (greenhouse decisions/0574, slice BV-4).
 *
 * Measured with real residents (greenhouse evidence/1104, 1107): the house had no governed way to leave two rows.
 * One resident edited the plugin's `boot()` — code that runs on every boot to leave data once — one invented a
 * sequence, and one scaffolded a tool the house refused to run: 21 model calls without a row.
 *
 * A ROW A WORK IS BORN WITH IS PART OF WHAT WAS BUILT, NOT DATA SOMEBODY TYPED. So it is DECLARED in a file of the
 * plugin's own tree — `src/Plugins/<Plugin>/Seeds/<Entity>.json` — which the plugin's write scope already covers,
 * a trial diffs and a promotion crosses (decisions/0463). The store lives in `var/` or a database, which a trial
 * neither copies nor promotes: it is the HOUSE that writes it, when the declaration lands ({@see apply()}).
 *
 * A row is seeded once: what was seeded is remembered by content in `var/seeds/`, so promoting twice does not
 * duplicate and a row somebody deleted later does not come back.
 */
final class SeedDeclarations
{
    /** A seed declaration, relative to the app root: plugin and entity are its two names. */
    private const FILE = '~^src/Plugins/([A-Za-z_][A-Za-z0-9_]*)/Seeds/([A-Za-z_][A-Za-z0-9_]*)\.json$~D';

    /** An entity of a plugin of this house, by its class. */
    private const ENTITY = '~\\\\Plugins\\\\([A-Za-z_][A-Za-z0-9_]*)\\\\Entities\\\\([A-Za-z_][A-Za-z0-9_]*)$~D';

    public function __construct(private readonly string $root)
    {
    }

    /**
     * The seed declarations among what landed.
     *
     * @param list<string> $paths relative to the app root
     *
     * @return list<string>
     */
    public static function landed(array $paths): array
    {
        return array_values(array_filter($paths, static fn (string $path): bool => preg_match(self::FILE, $path) === 1));
    }

    /**
     * Declare rows of an entity: validated by BUILDING each one with the entity, then added to its declaration.
     * A row already declared — the same content, in any key order — is not declared twice.
     *
     * @return array<string, mixed> `{ok, entity, file, declared, added}`, or `{ok: false, error}` and nothing written
     */
    public function declare(string $entity, mixed $rows): array
    {
        try {
            $class = EntityName::resolve($entity);
        } catch (EntityNameIsAmbiguous $ambiguous) {
            return self::refused($ambiguous->getMessage());
        }
        if (! class_exists($class)) {
            return self::refused("this house has no entity «{$entity}»: name one a plugin declares, e.g. <Entity> or <Plugin>/<Entity>");
        }
        if (! is_subclass_of($class, EntityInterface::class) || preg_match(self::ENTITY, $class, $names) !== 1) {
            return self::refused("«{$entity}» is not an entity of a plugin of this house: rows are seeded for src/Plugins/<Plugin>/Entities/<Entity>");
        }
        [, $plugin, $short] = $names;
        if (! \is_array($rows) || ! array_is_list($rows)) {
            return self::refused('«rows» is a list of rows, each an object of the entity\'s fields: [{"<field>": <value>, …}]');
        }
        if ($rows === []) {
            return self::refused('a seed needs at least one row');
        }
        foreach ($rows as $at => $row) {
            if (! \is_array($row) || ($row !== [] && array_is_list($row))) {
                return self::refused("rows.{$at} is not an object of {$short}'s fields");
            }
            if (\array_key_exists('id', $row)) {
                return self::refused("rows.{$at} carries «id»: the store assigns it, a declared row does not");
            }
            try {
                $fields = array_values(array_diff(array_keys(self::build($class, $row)->toArray()), ['id']));
            } catch (\Throwable $cannot) {
                return self::refused("rows.{$at} cannot be a {$short}: " . $cannot->getMessage());
            }
            foreach (array_keys($row) as $named) {
                if (! \in_array($named, $fields, true)) {
                    return self::refused("rows.{$at} names «{$named}», which {$short} does not have; its fields are: " . implode(', ', $fields));
                }
            }
        }

        $file = "src/Plugins/{$plugin}/Seeds/{$short}.json";
        $declared = $this->read($file) ?? [];
        $known = array_map(self::digest(...), $declared);
        $added = 0;
        foreach ($rows as $row) {
            if (! \in_array(self::digest($row), $known, true)) {
                $declared[] = $row;
                $known[] = self::digest($row);
                ++$added;
            }
        }
        @mkdir(\dirname($this->root . '/' . $file), 0o755, true);
        file_put_contents($this->root . '/' . $file, json_encode(
            ['entity' => "{$plugin}/{$short}", 'rows' => $declared],
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        ) . "\n");

        return ['ok' => true, 'entity' => "{$plugin}/{$short}", 'file' => $file, 'declared' => \count($declared), 'added' => $added];
    }

    /**
     * Seed the declarations that landed: every declared row not seeded before is saved through the entity's
     * repository. Nothing is guessed — a declaration the house cannot read, an entity with no repository and a row
     * the store refused are each said, and none of them is remembered as seeded.
     *
     * @param list<string>              $paths   what landed, relative to the app root
     * @param \Closure(string): ?object $service the house's container, by service id
     *
     * @return list<array<string, mixed>> one `{entity, declared, added, already[, failed][, unseeded]}` per declaration
     */
    public function apply(array $paths, \Closure $service): array
    {
        $said = [];
        foreach (self::landed($paths) as $file) {
            preg_match(self::FILE, $file, $names);
            [, $plugin, $short] = $names;
            $entity = "{$plugin}/{$short}";
            $rows = $this->read($file);
            if ($rows === null) {
                $said[] = ['entity' => $entity, 'declared' => 0, 'added' => 0, 'already' => 0, 'unseeded' => "{$file} is not a seed declaration the house can read"];

                continue;
            }
            $class = EntityName::resolve($entity);
            $repository = $service($class . 'Repository');
            if (! $repository instanceof RepositoryInterface || ! is_subclass_of($class, EntityInterface::class)) {
                $said[] = ['entity' => $entity, 'declared' => \count($rows), 'added' => 0, 'already' => 0,
                    'unseeded' => "no repository is registered for {$entity}: is its plugin registered, and does the house boot with it?"];

                continue;
            }

            $ledger = $this->root . "/var/seeds/{$plugin}.{$short}.json";
            $seeded = is_file($ledger) ? (array) json_decode((string) file_get_contents($ledger), true) : [];
            $added = $already = 0;
            $failed = [];
            foreach ($rows as $at => $row) {
                $digest = self::digest($row);
                if (\in_array($digest, $seeded, true)) {
                    ++$already;

                    continue;
                }
                try {
                    $repository->save(self::build($class, $row));
                } catch (\Throwable $refused) {
                    $failed[] = ['row' => $at, 'reason' => (new \ReflectionClass($refused))->getShortName() . ': ' . $refused->getMessage()];

                    continue;
                }
                $seeded[] = $digest;
                ++$added;
            }
            if ($added > 0) {
                @mkdir(\dirname($ledger), 0o755, true);
                file_put_contents($ledger, json_encode(array_values($seeded), \JSON_PRETTY_PRINT) . "\n");
            }
            $said[] = ['entity' => $entity, 'declared' => \count($rows), 'added' => $added, 'already' => $already] + ($failed === [] ? [] : ['failed' => $failed]);
        }

        return $said;
    }

    /**
     * The declared rows of a declaration file, or null when it is absent or not one.
     *
     * @return list<array<string, mixed>>|null
     */
    private function read(string $file): ?array
    {
        if (! is_file($this->root . '/' . $file)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($this->root . '/' . $file), true);
        $rows = \is_array($decoded) ? ($decoded['rows'] ?? null) : null;

        return \is_array($rows) && array_is_list($rows) ? array_values(array_filter($rows, 'is_array')) : null;
    }

    /**
     * The entity one declared row stands for. What the row lacks is the entity's to refuse, by its own types; PHP's
     * warning about the missing key is kept out of the answer, where it would only be noise beside that refusal.
     *
     * @param class-string<EntityInterface> $class
     * @param array<string, mixed>          $row
     */
    private static function build(string $class, array $row): EntityInterface
    {
        set_error_handler(static fn (): bool => true, \E_WARNING);
        try {
            return $class::fromArray(['id' => null] + $row);
        } finally {
            restore_error_handler();
        }
    }

    /** One row by its content: the same fields and values in any order are the same row. */
    private static function digest(mixed $row): string
    {
        $sorted = \is_array($row) ? $row : [];
        ksort($sorted);

        return hash('sha256', (string) json_encode($sorted, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    }

    /** @return array{ok: false, error: string} */
    private static function refused(string $why): array
    {
        return ['ok' => false, 'error' => $why];
    }
}
