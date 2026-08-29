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
 * The ONE truth a layout's components share (greenhouse decisions/0169): a small bag of values, keyed by
 * SESSION and screen, that a child can WRITE (a select sets `role`) and another can READ (a data-table filters
 * by `role`). It is server-authoritative — the browser never owns it, it only receives the representation the
 * server recomputes — and ISOLATED per session, so one viewer's filter never leaks into another's.
 *
 * It is deliberately NOT a «shared store» with its own reactivity: there is no second owner of the truth. The
 * agent declares a RELATION between components; the framework executes it here, at render, from this bag. A
 * write is cheap by its own {@see \Milpa\Command\Effect\EffectProfile} (ephemeral, session authority), never by
 * assumption — the contract decides the cost, not the syntax.
 */
final class LayoutStateStore
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * Resolve the store from the app's `live` config: `live.layout_state_path` (relative under the app root),
     * or `var/layout-state.json` by default.
     *
     * @param array<string, mixed> $live
     */
    public static function fromConfig(array $live, string $root): self
    {
        $declared = $live['layout_state_path'] ?? null;
        $path = \is_string($declared) && $declared !== '' ? $declared : 'var/layout-state.json';
        if (! str_starts_with($path, '/')) {
            $path = rtrim($root, '/') . '/' . $path;
        }

        return new self($path);
    }

    /**
     * The shared values for one screen in one session, or an empty bag — the state a layout renders FROM.
     *
     * @return array<string, string>
     */
    public function values(string $session, string $screen): array
    {
        $all = $this->all();
        $bag = $all[$session][$screen] ?? null;
        if (! \is_array($bag)) {
            return [];
        }
        $out = [];
        foreach ($bag as $key => $value) {
            if (\is_string($key) && \is_scalar($value)) {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * Set one shared value for a screen in a session. Ephemeral by contract; the write touches only THIS
     * session's slice, so it can never coordinate two viewers by accident.
     *
     * @return array<string, mixed>
     */
    public function set(string $session, string $screen, string $key, string $value): array
    {
        $session = trim($session);
        $screen = trim($screen);
        $key = trim($key);
        if ($session === '' || $screen === '' || $key === '') {
            return ['ok' => false, 'error' => 'session, screen and key are required'];
        }
        $all = $this->all();
        if (! \is_array($all[$session] ?? null)) {
            $all[$session] = [];
        }
        if (! \is_array($all[$session][$screen] ?? null)) {
            $all[$session][$screen] = [];
        }
        $all[$session][$screen][$key] = $value;
        $this->write($all);

        return ['ok' => true, 'session' => $session, 'screen' => $screen, 'set' => [$key => $value]];
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

    /** @param array<string, mixed> $all */
    private function write(array $all): void
    {
        @mkdir(\dirname($this->path), 0o755, true);
        file_put_contents($this->path, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
