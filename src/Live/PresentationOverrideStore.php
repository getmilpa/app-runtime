<?php

/**
 * This file is part of Milpa App Runtime — the agent runtime a Milpa app installs.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Live;

use Milpa\Live\Assets\PresentationOverrides;
use Milpa\Live\ValueObjects\ComponentPresentation;

/**
 * The record of who was allowed to change what somebody else's component looks like.
 *
 * `milpa/live-web` asks a question — *what was authorized on top of this component?* — and knows
 * nothing about the answer's provenance. This is the answer, and the reason it is a LEDGER rather
 * than a declaration is the whole of `decisions/0246` §2: if a package could restyle another
 * package's component by declaring it, the effect would already have happened by the time anybody
 * was asked, and the authorization would be theatre.
 *
 * So nothing writes here except an operation a human authorized, and every entry keeps
 * `authorized_by` beside `by` — who allowed it, separately from who is doing it. A revocation
 * removes the entry rather than flagging it: an override that stops being authorized must stop being
 * emitted, and a page cannot read a flag.
 *
 * Paths are stored RELATIVE to the app root and refused if they resolve outside it. That keeps the
 * ledger portable between installs, and it bounds where an override may come from: this app, not
 * an absolute path somebody wrote into a JSON file.
 */
final class PresentationOverrideStore implements PresentationOverrides
{
    public const DEFAULT_PATH = 'var/presentation-overrides.json';

    public function __construct(
        private readonly string $path,
        private readonly string $root,
    ) {
    }

    /**
     * The ledger this app keeps, from `live.overrides` or the default under `var/`.
     *
     * @param array<string, mixed> $live
     */
    public static function fromConfig(array $live, string $root): self
    {
        $declared = \is_string($live['overrides'] ?? null) && $live['overrides'] !== ''
            ? (string) $live['overrides']
            : self::DEFAULT_PATH;

        return new self(rtrim($root, '/') . '/' . ltrim($declared, '/'), $root);
    }

    /**
     * What a human authorized on top of this component, or `null` when nobody did.
     */
    public function forComponent(string $component): ?ComponentPresentation
    {
        $entry = $this->all()[$component] ?? null;

        if (!\is_array($entry)) {
            return null;
        }

        $styles = $this->resolve($entry['styles'] ?? null);
        $messages = $this->resolve($entry['messages'] ?? null);

        return $styles === null && $messages === null ? null : new ComponentPresentation(
            styles: $styles,
            messages: $messages,
        );
    }

    /**
     * Every standing grant, as it is written.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        if (!is_file($this->path) || !is_readable($this->path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);
        $entries = [];

        foreach (\is_array($decoded) ? $decoded : [] as $component => $entry) {
            if (\is_string($component) && \is_array($entry)) {
                $entries[$component] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Record a grant, keeping who authorized it apart from who asked for it.
     *
     * @return array<string, mixed>|null Whatever grant this laid over, so the ceremony can say what changed.
     *
     * @throws \RuntimeException         when the ledger could not be written — never a quiet no-op
     * @throws \InvalidArgumentException when a path leaves the app or names a file that is not there
     */
    public function grant(string $component, ?string $styles, ?string $messages, string $by, string $authorizedBy): ?array
    {
        $entry = array_filter([
            'styles' => $styles === null ? null : $this->relative($styles, 'css'),
            'messages' => $messages === null ? null : $this->relative($messages, 'php'),
        ], static fn (?string $value): bool => $value !== null);

        if ($entry === []) {
            throw new \InvalidArgumentException('A grant that changes nothing is not a grant: name a stylesheet, a message catalogue, or both.');
        }

        $entries = $this->all();
        $previous = $entries[$component] ?? null;
        $entries[$component] = $entry + ['by' => $by, 'authorized_by' => $authorizedBy];
        $this->write($entries);

        return \is_array($previous) ? $previous : null;
    }

    /**
     * Withdraw a grant. Returns false when there was nothing to withdraw.
     *
     * @throws \RuntimeException when the ledger could not be written
     */
    public function revoke(string $component): bool
    {
        $entries = $this->all();

        if (!\array_key_exists($component, $entries)) {
            return false;
        }

        unset($entries[$component]);
        $this->write($entries);

        return true;
    }

    /**
     * An app-root-relative path for something that is inside this app and exists.
     *
     * Both halves matter. Outside the app is a path somebody could point anywhere; not there yet is
     * a grant that reads as honoured and emits nothing, which is the failure this whole arc keeps
     * finding — no error, and a page that looks fine.
     */
    private function relative(string $path, string $extension): string
    {
        // A catalogue granted in the stylesheet slot is not a security hole — it is worse to debug
        // than one: the scoper dutifully rewrites PHP source as CSS and the page carries it, styling
        // nothing, raising nothing. The slots are named, so the grant checks the file is the kind of
        // thing the slot is for rather than discovering it at render time.
        if (strtolower(pathinfo($path, \PATHINFO_EXTENSION)) !== $extension) {
            throw new \InvalidArgumentException(\sprintf('An override\'s %s must be a .%s file; %s is not.', $extension === 'css' ? 'stylesheet' : 'message catalogue', $extension, $path));
        }

        $real = realpath($path === '' ? $path : (str_starts_with($path, '/') ? $path : rtrim($this->root, '/') . '/' . ltrim($path, '/')));
        $root = realpath($this->root);

        if ($real === false || $root === false) {
            throw new \InvalidArgumentException('This app does not ship ' . $path . ', so nothing would be emitted for it.');
        }

        if (!str_starts_with($real, rtrim($root, '/') . '/')) {
            throw new \InvalidArgumentException('An override must come from inside this app: ' . $path . ' resolves outside it.');
        }

        return ltrim(substr($real, \strlen(rtrim($root, '/'))), '/');
    }

    private function resolve(mixed $relative): ?string
    {
        if (!\is_string($relative) || $relative === '') {
            return null;
        }

        return rtrim($this->root, '/') . '/' . ltrim($relative, '/');
    }

    /**
     * @param array<string, array<string, mixed>> $entries
     */
    private function write(array $entries): void
    {
        $directory = \dirname($this->path);

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create ' . $directory . ' for the override ledger.');
        }

        $json = json_encode($entries, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        if ($json === false || file_put_contents($this->path, $json . "\n", \LOCK_EX) === false) {
            throw new \RuntimeException('Could not write the override ledger at ' . $this->path . '.');
        }
    }
}
