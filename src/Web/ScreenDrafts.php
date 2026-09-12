<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Web;

/** Immutable screen revisions and compare-and-swap activation; application records never move. */
final readonly class ScreenDrafts
{
    /** @param \Closure(string,string,array<string,mixed>):void $validate */
    public function __construct(private ScreenStore $active, private string $directory, private \Closure $validate, private \Closure $build)
    {
    }

    /**
     * Current active screens and durable immutable proposals.
     *
     * @return array<string,mixed>
     */
    public function catalogue(): array
    {
        $drafts = [];
        foreach (glob($this->directory . '/*.json') ?: [] as $path) {
            $record = $this->load(basename($path, '.json'));
            $drafts[] = $record;
        }
        $screens = array_map(fn (array $screen): array => $screen + ['definition' => $this->active->screen($screen['name'])], $this->active->catalogue());
        return ['screens' => $screens,'drafts' => $drafts];
    }

    /**
     * Create a new immutable proposal against the current declaration.
     *
     * @param array<string,mixed> $props
     *
     * @return array<string,mixed>
     */
    public function draft(string $name, string $type, array $props): array
    {
        if (!preg_match('/^[a-z][a-z0-9-]{0,40}$/', $name)) {
            throw new \DomainException('invalid_name');
        }
        ($this->validate)($name, $type, $props);
        $props['name'] ??= $name;
        return $this->save(['kind' => 'draft','name' => $name,'before' => $this->active->screen($name),
            'definition' => ['type' => $type,'props' => $props],'build' => ($this->build)()]);
    }

    /**
     * Re-read and verify the exact immutable record; malformed presence is never absence.
     *
     * @return array<string,mixed>
     */
    public function load(string $id): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $id) || !is_file($this->directory . '/' . $id . '.json')) {
            throw new \DomainException('revision_missing');
        }
        try {
            $record = json_decode((string)file_get_contents($this->directory . '/' . $id . '.json'), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \DomainException('revision_changed', previous: $e);
        }
        if (!is_array($record) || !hash_equals($id, self::hash($record))) {
            throw new \DomainException('revision_changed');
        }
        return $record + ['id' => $id];
    }

    /**
     * A review names the exact proposal and whether its base/build are still current.
     *
     * @return array<string,mixed>
     */
    public function review(string $id): array
    {
        $d = $this->load($id);
        $d['current'] = $this->active->screen($d['name']);
        $d['restorable'] = $d['build'] === ($this->build)() && self::hash($d['definition']) === self::hash($d['current']);
        $d['fresh'] = $d['build'] === ($this->build)() && self::hash($d['before']) === self::hash($d['current']);
        return $d;
    }

    /**
     * Refuse a changed build or base before activating exactly this declaration.
     *
     * @return array<string,mixed>
     */
    public function promote(string $id): array
    {
        $d = $this->load($id);
        if ($d['kind'] !== 'draft') {
            throw new \DomainException('not_a_draft');
        }
        return $this->activate($d, $d['before'], $d['definition'], 'promotion');
    }

    /**
     * Restore the prior declaration only while the promoted declaration remains current.
     *
     * @return array<string,mixed>
     */
    public function rollback(string $id): array
    {
        $r = $this->load($id);
        if ($r['kind'] !== 'draft') {
            throw new \DomainException('not_a_draft');
        }
        return $this->activate($r, $r['definition'], $r['before'], 'rollback');
    }

    /**
     * @param array<string,mixed>      $source
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     *
     * @return array<string,mixed>
     */
    private function activate(array $source, ?array $before, ?array $after, string $action): array
    {
        if ($source['build'] !== ($this->build)()) {
            throw new \DomainException('build_changed');
        }
        if ($after !== null) {
            ($this->validate)($source['name'], $after['type'], $after['props']);
        }
        if (!$this->active->compareAndSwap($source['name'], self::hash($before), $after)) {
            throw new \DomainException('base_changed');
        }
        return ['id' => $source['id'],'name' => $source['name'],'action' => $action,'before' => $before,'after' => $after];
    }

    /** Canonical map ordering makes a revision describe values, not object key order. */
    public static function hash(mixed $value): string
    {
        $canonical = function (mixed $v) use (&$canonical): mixed {
            if (!is_array($v)) {
                return $v;
            }
            if (!array_is_list($v)) {
                ksort($v);
            }
            return array_map($canonical, $v);
        };
        return hash('sha256', json_encode($canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string,mixed> $record
     *
     * @return array<string,mixed>
     */
    private function save(array $record): array
    {
        $record += ['createdAt' => gmdate(DATE_ATOM),'nonce' => bin2hex(random_bytes(12))];
        $id = self::hash($record);
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0700, true);
        }
        $temporary = tempnam($this->directory, '.draft-');
        if ($temporary === false) {
            throw new \RuntimeException('Cannot stage immutable revision');
        }
        try {
            $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (file_put_contents($temporary, $json) !== strlen($json)) {
                throw new \RuntimeException('Cannot write immutable revision');
            }
            // Publish the complete file atomically, refusing to replace an existing revision.
            if (!link($temporary, $this->directory . '/' . $id . '.json')) {
                throw new \RuntimeException('Cannot publish immutable revision');
            }
        } finally {
            unlink($temporary);
        }
        return $record + ['id' => $id];
    }
}
