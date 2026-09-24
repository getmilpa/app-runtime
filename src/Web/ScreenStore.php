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

/**
 * The store of runtime-declared live screens (greenhouse decisions/0158): a JSON file of
 * `name => { columns, rows }` that the agent authors MATERIALLY through the governed `screen:declare`
 * operation, and that {@see LivePlugin} reads to register each declared screen as a live component and
 * {@see DeclaredScreensPageProvider} reads to serve its data — so a screen appears at
 * `GET /live/page?component=<name>` with NO code deploy.
 *
 * It is the ONE seam both halves share: the operation writes here, the door reads here. The agent
 * declares a datum (not code); the framework projects it to a signed, scoped, served screen; the
 * human governs the write (the operation mutates, so the gate pauses and the authority signs). Data,
 * not codegen — powerful and safe.
 */
final class ScreenStore
{
    private const NAME = '/^[a-z][a-z0-9-]{0,40}$/';

    /** A declaration with no `type` is a data-table — the shape the store shipped with (decisions/0159). */
    public const DEFAULT_TYPE = 'data-table';

    /**
     * Where a house keeps its declared screens by default: in the VERSIONED tree, beside the rest of its
     * configuration (greenhouse decisions/0463). A screen is authorship. There it is copied into a trial
     * with what the house already declared, seen by the trial's diff, carried by `sandbox:promote`,
     * guarded by `stale()` and returned by `sandbox:undo`, the same as code.
     */
    public const DEFAULT_PATH = 'config/screens.json';

    /**
     * Where screens lived before 0463. `var/` is the house's STATE: a trial starts with it empty and
     * never diffs it, so a screen declared there could not be promoted, and promoting it would have
     * erased the house's other screens (measured, greenhouse evidence/0997).
     */
    public const LEGACY_PATH = 'var/screens.json';

    /**
     * @param string      $path       the declarations file
     * @param string|null $lockPath   where writers serialise; `<path>.lock` when null. A lock is machinery and
     *                                never result, so the default store keeps it under `var/`, out of any diff
     * @param string|null $legacyPath a pre-0463 `var/screens.json` still read, merged under this one, and
     *                                retired by the first write
     */
    public function __construct(
        private readonly string $path,
        private readonly ?string $lockPath = null,
        private readonly ?string $legacyPath = null,
    ) {
    }

    /**
     * Resolve the store from the app's `live` config: `live.screens_path` (relative paths are taken
     * under the app root), or {@see self::DEFAULT_PATH} under the root by default — with its lock under
     * `var/` and any pre-0463 {@see self::LEGACY_PATH} still read until the first write retires it.
     *
     * @param array<string, mixed> $live
     */
    public static function fromConfig(array $live, string $root): self
    {
        $root = rtrim($root, '/');
        $declared = $live['screens_path'] ?? null;
        if (\is_string($declared) && $declared !== '') {
            return new self(str_starts_with($declared, '/') ? $declared : $root . '/' . $declared);
        }

        return new self(
            $root . '/' . self::DEFAULT_PATH,
            $root . '/var/screens.lock',
            $root . '/' . self::LEGACY_PATH,
        );
    }

    /**
     * The names of every declared screen, in declaration order.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->all());
    }

    /**
     * The declaration for one screen — `['type' => <component>, 'props' => [...]]` — or null if undeclared.
     * A legacy bare `{ columns, rows }` entry is read as a `data-table` (see {@see normalize()}).
     *
     * @return array{type: string, props: array<string, mixed>}|null
     */
    public function screen(string $name): ?array
    {
        $all = $this->all();

        return isset($all[$name]) && \is_array($all[$name]) ? $this->normalize($all[$name]) : null;
    }

    /**
     * Every declared screen's component type, keyed by name, in declaration order — what {@see LivePlugin}
     * reads to register each screen under the class for its type.
     *
     * @return array<string, string>
     */
    public function typedNames(): array
    {
        $out = [];
        foreach ($this->all() as $name => $entry) {
            if (\is_array($entry)) {
                $out[$name] = $this->normalize($entry)['type'];
            }
        }

        return $out;
    }

    /**
     * A summary of every declared screen — name, component type, where it is served, and how many props it
     * carries — in declaration order. The readonly view `screen:list` projects.
     *
     * @return list<array{name: string, type: string, servedAt: string, props: int}>
     */
    public function catalogue(): array
    {
        $out = [];
        foreach ($this->all() as $name => $entry) {
            if (! \is_array($entry)) {
                continue;
            }
            $screen = $this->normalize($entry);
            $out[] = [
                'name' => $name,
                'type' => $screen['type'],
                'servedAt' => '/live/page?component=' . $name,
                'props' => \count($screen['props']),
                ...(\is_array($entry['word'] ?? null) ? ['word' => $entry['word']['name'] ?? null, 'wordVersion' => $entry['word']['version'] ?? null] : []),
            ];
        }

        return $out;
    }

    /**
     * Normalize a stored entry to `{ type, props }`, upgrading a legacy bare `{ columns, rows }` data-table
     * so screens declared before types existed keep serving without a migration.
     *
     * @param array<string, mixed> $entry
     *
     * @return array{type: string, props: array<string, mixed>}
     */
    private function normalize(array $entry): array
    {
        if (\is_string($entry['type'] ?? null)) {
            return ['type' => $entry['type'], 'props' => \is_array($entry['props'] ?? null) ? $entry['props'] : []];
        }

        return ['type' => self::DEFAULT_TYPE, 'props' => [
            'columns' => \is_array($entry['columns'] ?? null) ? $entry['columns'] : [],
            'rows' => \is_array($entry['rows'] ?? null) ? $entry['rows'] : [],
        ]];
    }

    /**
     * Forget a declared screen — the rollback of {@see declare()}. Removing an entry is COMPENSATABLE, not
     * free: re-declaring the screen restores it, which is why the operation that calls this declares
     * `Reversibility::Compensatable` and names the screen it targets.
     *
     * @return array<string, mixed>
     */
    public function forget(string $name): array
    {
        $name = trim($name);
        $lock = $this->lock();
        try {
            $screens = $this->all();
            if ($name === '' || ! \array_key_exists($name, $screens)) {
                return ['ok' => false, 'error' => 'no such declared screen', 'screen' => $name];
            }
            unset($screens[$name]);
            $this->write($screens);
        } finally {
            fclose($lock);
        }

        return ['ok' => true, 'forgotten' => $name];
    }

    /**
     * Declare (or redeclare) a screen of a component `type` (default `data-table`) with its `props`. For a
     * data-table, `columns`/`rows` may be given at the top level as a convenience; any type may pass its
     * props under `props`. Validates the name shape; persists `{ type, props }`.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function declare(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || ! preg_match(self::NAME, $name)) {
            return ['ok' => false, 'error' => 'a screen name is a-z, 0-9, dash; starts with a letter'];
        }
        $type = trim((string) ($input['type'] ?? self::DEFAULT_TYPE));
        if ($type === '') {
            $type = self::DEFAULT_TYPE;
        }
        $props = \is_array($input['props'] ?? null) ? $input['props'] : [];
        // data-table convenience: columns/rows at the top level fold into props.
        if (\array_key_exists('columns', $input)) {
            $props['columns'] = \is_array($input['columns']) ? $input['columns'] : [];
        }
        if (\array_key_exists('rows', $input)) {
            $props['rows'] = \is_array($input['rows']) ? $input['rows'] : [];
        }
        // A bound data-table (greenhouse decisions/0462): the binding is stored, the rows are not —
        // the runtime reads them per request through PublicSource.
        if (\array_key_exists('source', $input)) {
            $props['source'] = $input['source'];
        }
        $props['name'] ??= $name;

        $lock = $this->lock();
        try {
            $screens = $this->all();
            $screens[$name] = ['type' => $type, 'props' => $props];
            // Which word of the house produced this screen, and which version (decisions/0465).
            if (\is_array($input['word'] ?? null)) {
                $screens[$name]['word'] = $input['word'];
            }
            $this->write($screens);
        } finally {
            fclose($lock);
        }

        return [
            'ok' => true,
            'screen' => $name,
            'type' => $type,
            'servedAt' => '/live/page?component=' . $name,
            'props' => \count($props),
        ];
    }

    /**
     * Replace one declaration atomically against its reviewed base, preserving other screens.
     *
     * @param array{type:string,props:array<string,mixed>}|null $next
     */
    public function compareAndSwap(string $name, string $expected, ?array $next): bool
    {
        $lock = $this->lock();
        try {
            if (!hash_equals($expected, ScreenDrafts::hash($this->screen($name)))) {
                return false;
            }
            $all = $this->all();
            if ($next === null) {
                unset($all[$name]);
            } else {
                $all[$name] = $next;
            }
            $this->write($all);
            return true;
        } finally {
            fclose($lock);
        }
    }

    /** @return resource */
    private function lock()
    {
        if (!is_dir(dirname($this->path))) {
            mkdir(dirname($this->path), 0755, true);
        }
        $path = $this->lockPath ?? $this->path . '.lock';
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        $lock = fopen($path, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Cannot lock screen declarations');
        }
        return $lock;
    }

    /** @return array<string, mixed> */
    private function all(): array
    {
        // A pre-0463 house reads BOTH, the versioned file winning: a promotion that created it inside a
        // trial (where `var/` is empty) must not hide the screens the house still keeps in `var/`.
        return [...self::read($this->legacyPath), ...self::read($this->path)];
    }

    /** @return array<string, mixed> */
    private static function read(?string $path): array
    {
        if ($path === null || ! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid screen store');
        }
        return $decoded;
    }

    /** @param array<string, mixed> $screens */
    private function write(array $screens): void
    {
        @mkdir(\dirname($this->path), 0o755, true);
        $temporary = tempnam(dirname($this->path), '.screens-');
        if ($temporary === false) {
            throw new \RuntimeException('Cannot stage screen declaration');
        }
        try {
            $json = json_encode($screens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $json) !== strlen($json) || !rename($temporary, $this->path)) {
                throw new \RuntimeException('Cannot activate screen declaration');
            }
            // What was read from the legacy file is now IN the versioned one (all() merged it), so the
            // old file retires — otherwise a screen forgotten here would come back from `var/`.
            if ($this->legacyPath !== null && is_file($this->legacyPath)) {
                unlink($this->legacyPath);
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
