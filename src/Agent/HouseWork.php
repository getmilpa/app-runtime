<?php

/**
 * This file is part of Milpa App Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Command\CommandProvider;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;

/**
 * An agent's work in the domain runs in the house, confined to the state it declares (greenhouse decisions/0588).
 *
 * A mutation runs first in a disposable copy and crosses by promotion — true of what a copy can hold: code and
 * configuration. The domain's state lives in `var/`, or in a database, and a trial's copy is born without it.
 * Measured (decisions/0585, session `w3`): with two tools in the house, lending tool 1 answered from the trial
 * «it does not exist», and adding one answered `ok: true` with nothing to apply. The house did not change.
 *
 * So work does not go to a trial. It needs two of the things a trial gives — confinement and a pre-image — and
 * neither of the other two: there is nothing to judge before it enters, and nothing to look at before a second
 * call applies it.
 *
 * WHAT IS WORK the operation says, with what it already declares: its ceiling is `subject: data` and
 * `externality: none`. Everything else — code, configuration, unclassified — is authoring, and goes to a trial as
 * before: what is not declared never lowers a control.
 *
 * WHERE ITS STATE LIVES is, by default, the store of the entities its plugin registers; a plugin that keeps state
 * elsewhere names it ({@see DeclaresWorkState}). And the house does not believe either: the call runs in a child
 * whose root is read-only and that can write nothing but those paths, so a handler declared `data` that writes
 * code gets the system's error. The state is never where code or authority lives, nor reached through a link —
 * checked here, when it is read, and again on the real path when it is mounted.
 *
 * This class only reads and judges. Running the call is {@see TrialRunner::work()}; the gates before it are the
 * same as for any call.
 */
final class HouseWork
{
    /** The most state, in bytes, the house keeps a pre-image of for one call. Beyond it, a person is asked. */
    public const PRE_IMAGE_LIMIT = 8 * 1024 * 1024;

    /** The house's own operations are never work, whatever they declare: the same names a trial never rehearses. */
    private const HOUSE_PREFIXES = ['agent:', 'session:', 'capabilities:', 'sandbox:', 'foundation:', 'identity:', 'config:', 'screen:', 'work:'];

    /** Where state never lives: code, configuration, dependencies, the record of decisions, authority, the house's own records. */
    private const NEVER_UNDER = [
        'src', 'config', 'vendor', '.milpa', 'storage/identity', 'var/trials', 'var/work', 'var/passkey',
        'public', 'bin', 'tests', 'resources', '.git', 'node_modules',
    ];

    /** Files that are not state wherever a declaration finds them. */
    private const NEVER = ['var/agent-sessions.jsonl', 'storage/plugins.json', 'composer.json', 'composer.lock', 'milpa.lock'];

    /** Directories the house shares with everything it keeps: a state lives inside one, it is never one. */
    private const SHARED = ['var', 'storage'];

    /** @var array<string, array{0: object, 1: string}>|null operation name => [the plugin that provides it, its name] */
    private ?array $providers = null;

    /**
     * @param array<array-key, object>|(\Closure(): array<array-key, object>) $plugins the house's plugins, as its kernel booted them — or how
     *                                                                                 to ask for them, read only when a call declares work
     * @param mixed                                                           $storage the `storage` value of the house's config; null when it declares none
     */
    public function __construct(
        private readonly string $root,
        private readonly array|\Closure $plugins,
        private readonly mixed $storage,
        private readonly TrialRunner $runner,
    ) {
    }

    /**
     * Whether this operation is work in the domain by what it declares: it mutates, its ceiling is data and
     * nothing leaves the house. An operation with no profile carries the ceiling of every axis and is not work.
     */
    public static function declaresWork(Operation $operation): bool
    {
        if (! $operation->mutating || $operation->requiresConfirmation) {
            return false;
        }
        foreach (self::HOUSE_PREFIXES as $prefix) {
            if (str_starts_with($operation->name, $prefix)) {
                return false;
            }
        }
        $ceiling = $operation->effectCeiling();

        return $ceiling->subject === Subject::Data && $ceiling->externality === Externality::None;
    }

    /**
     * Whether this store's receipt of an execution can say where it ran and what it left. Work runs in the house
     * only over a store that can: a move the house cannot record is a move it does not make.
     */
    public static function canBeRecordedBy(object $sessions): bool
    {
        return method_exists($sessions, 'recordExecution') && (new \ReflectionMethod($sessions, 'recordExecution'))->getNumberOfParameters() >= 7;
    }

    /**
     * How this call runs in the house — or null when the operation is not work, and is planned as it always was.
     */
    public function planFor(Operation $operation): ?WorkPlan
    {
        $provider = self::declaresWork($operation) ? ($this->providers()[$operation->name] ?? null) : null;
        if ($provider === null) {
            return null;
        }
        [$plugin, $name] = $provider;
        $declared = $plugin instanceof DeclaresWorkState ? $plugin->workState() : [];
        if (\array_key_exists($operation->name, $declared)) {
            $paths = $declared[$operation->name];
            if (! \is_array($paths) || $paths === [] || ! array_is_list($paths) || array_filter($paths, static fn (mixed $p): bool => ! \is_string($p)) !== []) {
                return new WorkPlan($operation->name, [], 'declared', refused: \sprintf(
                    '«%s» declares its state as something other than a list of paths relative to the house; nothing ran',
                    $operation->name,
                ));
            }
            $source = 'declared';
        } else {
            $stores = $this->storesOf($name);
            if ($stores === null) {
                return null;
            }
            if ($stores['asks'] !== null) {
                return new WorkPlan($operation->name, [], 'entities', asks: $stores['asks']);
            }
            [$paths, $source] = [$stores['paths'], 'entities'];
        }
        /** @var list<string> $paths */
        foreach ($paths as $path) {
            $why = self::notAPlaceForState($this->root, $path);
            if ($why !== null) {
                return new WorkPlan($operation->name, [], $source, refused: \sprintf(
                    'the state «%s» of «%s» is not a place work may keep state: %s; nothing ran',
                    $path,
                    $operation->name,
                    $why,
                ));
            }
        }
        if (! $this->runner->available()) {
            return new WorkPlan($operation->name, $paths, $source, asks: 'this house cannot confine a process here, so the call would run with the whole house open to it');
        }
        $guaranteed = $operation->effectCeiling()->reversibility === Reversibility::Guaranteed;
        $fits = self::size($this->root, $paths) <= self::PRE_IMAGE_LIMIT;
        if (! $guaranteed && ! $fits) {
            return new WorkPlan($operation->name, $paths, $source, asks: 'its state is larger than the pre-image the house keeps, and the operation does not guarantee its own way back', confined: true);
        }

        return new WorkPlan($operation->name, $paths, $source, confined: true, preImage: $fits);
    }

    /**
     * Why this path is not a place work may keep state — or null when it is. Asked when a declaration is read and
     * again, of the same path, when it is about to be mounted: between the two a link may have appeared.
     */
    public static function notAPlaceForState(string $root, string $path): ?string
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, "\0")) {
            return 'a state is a path relative to the house';
        }
        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return 'a state is a path relative to the house, with no traversal';
            }
        }
        foreach (self::NEVER_UNDER as $place) {
            if ($path === $place || str_starts_with($path, $place . '/')) {
                return "nothing under {$place}/ is state";
            }
        }
        if (\in_array($path, self::NEVER, true) || str_starts_with(basename($path), '.env') || strtolower(pathinfo($path, \PATHINFO_EXTENSION)) === 'php') {
            return 'it is one of the house\'s own files, or code';
        }
        if (\in_array($path, self::SHARED, true)) {
            return "{$path}/ holds the house's own records: a state lives inside it, in a file or a directory of its own";
        }
        $walked = rtrim($root, '/');
        foreach ($segments as $segment) {
            $walked .= '/' . $segment;
            if (is_link($walked)) {
                return 'it is reached through a link';
            }
        }

        return null;
    }

    /**
     * The stores of the entities this plugin registers, from the house's own storage — null when it has nowhere to
     * keep state the house can name.
     *
     * @return array{paths: list<string>, asks: ?string}|null
     */
    private function storesOf(string $plugin): ?array
    {
        $entities = array_map(
            static fn (string $file): string => basename($file, '.php'),
            glob($this->root . '/src/Plugins/' . $plugin . '/Entities/*.php') ?: [],
        );
        if ($entities === []) {
            return null;
        }
        sort($entities);
        if ($this->storage === null) {
            // The store generated code falls back to when the house declares none: one JSON file per table.
            return ['paths' => array_map(static fn (string $entity): string => 'var/' . strtolower($entity) . 's.json', $entities), 'asks' => null];
        }
        $driver = \is_array($this->storage) ? ($this->storage['driver'] ?? null) : null;
        if ($driver === 'mysql') {
            return ['paths' => [], 'asks' => 'its store is a database on the network, and a confined call has no network'];
        }
        $where = \is_array($this->storage) && \is_string($this->storage['path'] ?? null) ? $this->relative($this->storage['path']) : null;
        if (($driver !== 'file' && $driver !== 'sqlite') || $where === null) {
            return null;
        }
        if ($driver === 'file') {
            return ['paths' => [$where], 'asks' => null];
        }
        // SQLite writes a journal beside its database, so it is confined by its directory — when it has one to itself.
        $directory = \dirname($where);
        if ($directory === '.' || \in_array($directory, self::SHARED, true)) {
            return ['paths' => [], 'asks' => \sprintf(
                'its SQLite database «%s» shares its directory with the house, which is never opened to a handler: it is confined when it lives in a directory of its own',
                $where,
            )];
        }

        return ['paths' => [$directory], 'asks' => null];
    }

    /** A path of the house's storage block, relative to the house — or null when it lies outside it. */
    private function relative(string $path): ?string
    {
        $root = rtrim($this->root, '/') . '/';
        if (! str_starts_with($path, '/')) {
            return $path;
        }

        return str_starts_with($path, $root) && \strlen($path) > \strlen($root) ? substr($path, \strlen($root)) : null;
    }

    /** @return array<string, array{0: object, 1: string}> */
    private function providers(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }
        $this->providers = [];
        foreach ($this->plugins instanceof \Closure ? ($this->plugins)() : $this->plugins as $plugin) {
            if (! $plugin instanceof CommandProvider || preg_match('~\\\\Plugins\\\\([A-Za-z_][A-Za-z0-9_]*)\\\\~', '\\' . $plugin::class, $match) !== 1) {
                continue;
            }
            foreach ($plugin->operations() as $operation) {
                $this->providers[$operation->name] ??= [$plugin, $match[1]];
            }
        }

        return $this->providers;
    }

    /**
     * How many bytes these paths hold now; a path that does not exist yet holds none.
     *
     * @param list<string> $paths
     */
    public static function size(string $root, array $paths): int
    {
        $bytes = 0;
        foreach ($paths as $path) {
            $absolute = rtrim($root, '/') . '/' . $path;
            if (is_file($absolute)) {
                $bytes += (int) filesize($absolute);
            } elseif (is_dir($absolute)) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    $bytes += $file instanceof \SplFileInfo && $file->isFile() && ! $file->isLink() ? (int) $file->getSize() : 0;
                }
            }
        }

        return $bytes;
    }
}
