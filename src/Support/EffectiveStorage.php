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

/**
 * Where this house's entities ARE kept, as `house:context` answers it (greenhouse evidence/1109).
 *
 * ── THE DEBT THIS PAYS, MEASURED ────────────────────────────────────────────────────────────────
 *
 * `house:context` answered the `storage` block of `config/app.php` as written: `{driver: null, where: null}` on
 * a house that persists, with zero configuration, to a JSON file under `var/`. The resident of the BV-4 run read
 * it as «entities won't persist», opened `config/app.php` and found the truth in a comment; the recorded runs of
 * evidence/1088 and 1089 made the same read. An answer true of the file and false of the house is an incomplete
 * contract: the house knew, and made the model derive it (greenhouse decisions/0575).
 *
 * ── TWO AUTHORITIES, NEITHER KEPT HERE ──────────────────────────────────────────────────────────
 *
 * - With no block, what holds is the fallback `make` writes into a plugin's `boot()` — milpa/devtools' stubs:
 *   `$config->get('storage', ['driver' => 'file', 'path' => <root>/var/<table>.json])`. The two constants below
 *   repeat it, and a test reads the stubs so they cannot drift apart.
 * - With a block, whether it works is what `Milpa\Data\RepositoryFactory::fromConfig()` accepts: a known driver
 *   and that driver's own key. The same test asks the factory, case by case.
 *
 * It never says more than that: not whether the file is writable, the database reachable, or what a plugin that
 * builds its own repository by hand does.
 */
final class EffectiveStorage
{
    /** The driver generated code falls back to when `config/app.php` declares no `storage` block. */
    public const DEFAULT_DRIVER = 'file';

    /** Where that fallback keeps each entity, relative to the house root: one JSON file per table. */
    public const DEFAULT_WHERE = 'var/<table>.json';

    /** The key each driver needs beside `driver`, as the factory demands it; `null`: none. */
    private const LOCATION = ['file' => 'path', 'sqlite' => 'path', 'mysql' => 'dsn', 'memory' => null];

    /** Static-only: a fold of one config value. */
    private function __construct()
    {
    }

    /**
     * The storage this house runs on, from the `storage` value of its config bag (`null` when it declares none).
     *
     * `driver` and `where` are the effective ones; `source` says whether they come from the `config` or are the
     * `default`; `configuration_required` is true only when a declared block is one the factory would refuse.
     * `where` never carries a credential: `storage.user` and `storage.password` are not read, and a DSN travels
     * with its `user=` and `password=` values cut.
     *
     * @return array{driver: ?string, where: ?string, source: 'config'|'default', configuration_required: bool}
     */
    public static function of(mixed $declared): array
    {
        if ($declared === null) {
            return ['driver' => self::DEFAULT_DRIVER, 'where' => self::DEFAULT_WHERE, 'source' => 'default', 'configuration_required' => false];
        }
        if (!\is_array($declared)) {
            return ['driver' => null, 'where' => null, 'source' => 'config', 'configuration_required' => true];
        }

        $driver = \is_string($declared['driver'] ?? null) && $declared['driver'] !== '' ? $declared['driver'] : null;
        $where = \is_string($declared['path'] ?? null) ? $declared['path'] : null;
        if ($where === null && \is_string($declared['dsn'] ?? null)) {
            // The DSN is where a mysql backend points — minus anything that could be a credential.
            $where = preg_replace('/(user|password)=[^;]*/i', '$1=…', $declared['dsn']);
        }

        $works = $driver !== null && \array_key_exists($driver, self::LOCATION);
        if ($works && self::LOCATION[$driver] !== null) {
            $location = $declared[self::LOCATION[$driver]] ?? null;
            $works = \is_string($location) && $location !== '';
        }

        return ['driver' => $driver, 'where' => $where, 'source' => 'config', 'configuration_required' => !$works];
    }
}
