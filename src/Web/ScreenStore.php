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

    public function __construct(private readonly string $path)
    {
    }

    /**
     * Resolve the store from the app's `live` config: `live.screens_path` (relative paths are taken
     * under the app root), or `var/screens.json` under the root by default.
     *
     * @param array<string, mixed> $live
     */
    public static function fromConfig(array $live, string $root): self
    {
        $declared = $live['screens_path'] ?? null;
        $path = \is_string($declared) && $declared !== '' ? $declared : 'var/screens.json';
        if (! str_starts_with($path, '/')) {
            $path = rtrim($root, '/') . '/' . $path;
        }

        return new self($path);
    }

    /** The names of every declared screen, in declaration order. @return list<string> */
    public function names(): array
    {
        return array_values(array_keys($this->all()));
    }

    /**
     * The declaration for one screen — `['columns' => [...], 'rows' => [...]]` — or null if undeclared.
     *
     * @return array<string, mixed>|null
     */
    public function screen(string $name): ?array
    {
        $all = $this->all();

        return isset($all[$name]) && \is_array($all[$name]) ? $all[$name] : null;
    }

    /**
     * A summary of every declared screen — name, where it is served, and its shape (column/row counts) —
     * in declaration order. The readonly view `screen:list` projects.
     *
     * @return list<array{name: string, servedAt: string, columns: int, rows: int}>
     */
    public function catalogue(): array
    {
        $out = [];
        foreach ($this->all() as $name => $screen) {
            if (! \is_string($name) || ! \is_array($screen)) {
                continue;
            }
            $out[] = [
                'name' => $name,
                'servedAt' => '/live/page?component=' . $name,
                'columns' => \is_array($screen['columns'] ?? null) ? \count($screen['columns']) : 0,
                'rows' => \is_array($screen['rows'] ?? null) ? \count($screen['rows']) : 0,
            ];
        }

        return $out;
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
        $screens = $this->all();
        if ($name === '' || ! \array_key_exists($name, $screens)) {
            return ['ok' => false, 'error' => 'no such declared screen', 'screen' => $name];
        }
        unset($screens[$name]);
        $this->write($screens);

        return ['ok' => true, 'forgotten' => $name];
    }

    /**
     * Declare (or redeclare) a screen. Validates the name shape; persists `{ columns, rows }`.
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
        $columns = \is_array($input['columns'] ?? null) ? $input['columns'] : [];
        $rows = \is_array($input['rows'] ?? null) ? $input['rows'] : [];

        $screens = $this->all();
        $screens[$name] = ['columns' => $columns, 'rows' => $rows];
        $this->write($screens);

        return [
            'ok' => true,
            'screen' => $name,
            'servedAt' => '/live/page?component=' . $name,
            'columns' => \count($columns),
            'rows' => \count($rows),
        ];
    }

    /** @return array<string, mixed> */
    private function all(): array
    {
        if (! is_file($this->path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($this->path), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $screens */
    private function write(array $screens): void
    {
        @mkdir(\dirname($this->path), 0o755, true);
        file_put_contents(
            $this->path,
            json_encode($screens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }
}
