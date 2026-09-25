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
 * The words a HOUSE added to its visual language (greenhouse decisions/0465).
 *
 * Milpa brings a base vocabulary (`data-table`, `content`, `metric-card`, …). A house whose domain
 * thinks in other terms — hypotheses, evidence, contradictions — can name a new expression, and every
 * later session can discover it and use it without the framework changing and without the context of
 * whoever made it.
 *
 * ── WHAT A WORD IS ──────────────────────────────────────────────────────────────────────────────
 *
 * Only what is actually new about it: a NAME, a PURPOSE (`summary`, what discovery shows), typed
 * INPUTS, and a COMPOSITION — a screen tree of components this house already registers, with `"$input"`
 * where an input goes. Painting, escaping, layout, words in the page's language, signed state and
 * serving all exist already; the word only says how its inputs map onto them.
 *
 * ── THE RED LINE ────────────────────────────────────────────────────────────────────────────────
 *
 * A word can never carry markup. The composition names registered types and their props, and any key
 * called `html` or ending in `Html`, at any depth, is refused — the same as an unknown type, a `$name`
 * that is not an input, an input nothing reads, or a name the framework already uses. «Agent, write
 * frontend» is exactly what this store exists not to be.
 *
 * ── WHERE IT LIVES ──────────────────────────────────────────────────────────────────────────────
 *
 * `config/components.json`, in the versioned tree: a word is rehearsed in a trial and promoted like any
 * other work (decisions/0463), and it belongs to THIS house — another house does not know it.
 */
final class ComponentWords
{
    /** Where a house keeps its words. */
    public const PATH = 'config/components.json';

    /** The input types a word may declare — the scalar values a composition can place in a prop. */
    public const INPUT_TYPES = ['string', 'integer', 'number', 'boolean'];

    private const NAME = '/^[a-z][a-z0-9-]{0,40}$/';

    private const INPUT = '/^[a-z][a-zA-Z0-9_]{0,40}$/';

    public function __construct(private readonly string $path, private readonly string $lockPath)
    {
    }

    /** The store of the house rooted at `$root`. */
    public static function forRoot(string $root): self
    {
        $root = rtrim($root, '/');

        return new self($root . '/' . self::PATH, $root . '/var/components.lock');
    }

    /**
     * Every word, by name.
     *
     * @return array<string, array{name: string, summary: string, inputs: array<string, array<string, mixed>>, composition: array<string, mixed>, version: int}>
     */
    public function all(): array
    {
        if (! is_file($this->path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($this->path), true, 512, JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * One word, or null when this house does not know it.
     *
     * @return array{name: string, summary: string, inputs: array<string, array<string, mixed>>, composition: array<string, mixed>, version: int}|null
     */
    public function word(string $name): ?array
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * Add a word — or a new version of it — after checking every rule; refused by name and path.
     *
     * @param array<string, mixed>                $input      name, summary, inputs, composition
     * @param list<string>                        $primitives the component types this house registers
     * @param array<string, array<string, mixed>> $schemas    each primitive's propsSchema, when known:
     *                                                        what lets a word be judged against its root
     *
     * @return array<string, mixed>
     */
    public function define(array $input, array $primitives, array $schemas = []): array
    {
        try {
            $word = self::validate($input, $primitives, $schemas);
        } catch (InvalidScreenTree $error) {
            return ['ok' => false, 'error' => 'invalid word', 'path' => $error->path, 'reason' => $error->getMessage()];
        }

        $lock = $this->lock();
        try {
            $all = $this->all();
            $word['version'] = (int) ($all[$word['name']]['version'] ?? 0) + 1;
            $all[$word['name']] = $word;
            ksort($all);
            $this->write($all);
        } finally {
            fclose($lock);
        }

        return [
            'ok' => true,
            'word' => $word['name'],
            'version' => $word['version'],
            'use' => ['operation' => 'screen:declare', 'arguments' => ['name' => '<screen>', 'type' => $word['name'], 'props' => array_fill_keys(array_keys($word['inputs']), '<value>')]],
        ];
    }

    /**
     * Retire a word. Screens already declared with it keep what they compiled to.
     *
     * @return array<string, mixed>
     */
    public function forget(string $name): array
    {
        $lock = $this->lock();
        try {
            $all = $this->all();
            if (! isset($all[$name])) {
                return ['ok' => false, 'error' => "this house has no word «{$name}»"];
            }
            unset($all[$name]);
            $this->write($all);
        } finally {
            fclose($lock);
        }

        return ['ok' => true, 'forgotten' => $name];
    }

    /**
     * Compile a use of a word into the screen tree it stands for: the root type, its props with every
     * `"$input"` replaced by the given value, and which word and version produced it.
     *
     * @param array<string, mixed> $props
     *
     * @return array{type: string, props: array<string, mixed>, word: array{name: string, version: int, inputs: array<string, mixed>}}
     */
    public function compile(string $name, array $props): array
    {
        $word = $this->word($name) ?? throw new InvalidScreenTree('type', "this house has no word «{$name}»");
        $given = [];
        foreach ($word['inputs'] as $input => $spec) {
            if (! \array_key_exists($input, $props)) {
                if (($spec['required'] ?? true) === true) {
                    throw new InvalidScreenTree('props.' . $input, "«{$name}» needs «{$input}» ({$spec['type']})");
                }
                $given[$input] = null;
                continue;
            }
            if (! self::isOfType($props[$input], (string) $spec['type'])) {
                throw new InvalidScreenTree('props.' . $input, "«{$input}» must be {$spec['type']}");
            }
            $given[$input] = $props[$input];
        }
        foreach (array_keys($props) as $extra) {
            if (! \array_key_exists((string) $extra, $word['inputs'])) {
                throw new InvalidScreenTree('props.' . $extra, "«{$name}» has no input «{$extra}»; its inputs are: " . implode(', ', array_keys($word['inputs'])));
            }
        }

        $tree = self::substitute($word['composition'], $given);

        return [
            'type' => (string) $tree['type'],
            'props' => \is_array($tree['props'] ?? null) ? $tree['props'] : [],
            'word' => ['name' => $name, 'version' => (int) $word['version'], 'inputs' => $given],
        ];
    }

    /**
     * What discovery shows for each word: its purpose, its inputs, what it composes, who provides it.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogue(): array
    {
        $rows = [];
        foreach ($this->all() as $word) {
            $rows[] = [
                'name' => $word['name'],
                'contractVersion' => 'house-' . $word['version'],
                'providedBy' => 'house',
                'summary' => $word['summary'],
                'inputs' => $word['inputs'],
                'composes' => array_values(array_unique(self::types($word['composition']))),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed>                $input
     * @param list<string>                        $primitives
     * @param array<string, array<string, mixed>> $schemas
     *
     * @return array{name: string, summary: string, inputs: array<string, array<string, mixed>>, composition: array<string, mixed>}
     */
    private static function validate(array $input, array $primitives, array $schemas = []): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if (! preg_match(self::NAME, $name)) {
            throw new InvalidScreenTree('name', 'a word is a-z, 0-9 and dashes, starting with a letter');
        }
        if (\in_array($name, $primitives, true)) {
            throw new InvalidScreenTree('name', "«{$name}» is already a component of this house; a word adds to the language, it does not replace it");
        }
        $summary = trim((string) ($input['summary'] ?? ''));
        if ($summary === '') {
            throw new InvalidScreenTree('summary', 'a word says what it is for — discovery shows this to every later session');
        }

        $inputs = $input['inputs'] ?? null;
        if (! \is_array($inputs) || $inputs === [] || array_is_list($inputs)) {
            throw new InvalidScreenTree('inputs', 'inputs is an object of named inputs: {name: {type, required?, description?}}');
        }
        $clean = [];
        foreach ($inputs as $inputName => $spec) {
            $inputName = (string) $inputName;
            if (! preg_match(self::INPUT, $inputName)) {
                throw new InvalidScreenTree('inputs.' . $inputName, 'an input name is a letter followed by letters, digits or underscores');
            }
            if (! \is_array($spec) || ! \in_array($spec['type'] ?? null, self::INPUT_TYPES, true)) {
                throw new InvalidScreenTree('inputs.' . $inputName . '.type', 'an input type is one of: ' . implode(', ', self::INPUT_TYPES));
            }
            $clean[$inputName] = array_filter([
                'type' => $spec['type'],
                'required' => ($spec['required'] ?? true) !== false,
                'description' => \is_string($spec['description'] ?? null) ? $spec['description'] : null,
            ], static fn ($value): bool => $value !== null);
        }

        $composition = $input['composition'] ?? null;
        if (! \is_array($composition) || ! \is_string($composition['type'] ?? null)) {
            throw new InvalidScreenTree('composition', 'composition is a screen tree: {type, props}');
        }
        self::refuseMarkup($composition, 'composition');
        foreach (self::types($composition) as $type) {
            if (! \in_array($type, $primitives, true)) {
                throw new InvalidScreenTree('composition', "«{$type}» is not a component this house registers; a word composes only those: " . implode(', ', $primitives));
            }
        }
        ScreenTree::validate($composition['type'], \is_array($composition['props'] ?? null) ? $composition['props'] : [], $primitives, 'composition.');

        $used = self::references($composition);
        foreach ($used as $reference => $path) {
            if (! \array_key_exists($reference, $clean)) {
                throw new InvalidScreenTree($path, "«\${$reference}» is not an input of this word");
            }
        }
        foreach (array_keys($clean) as $inputName) {
            if (! \array_key_exists($inputName, $used)) {
                throw new InvalidScreenTree('inputs.' . $inputName, "nothing in the composition reads «\${$inputName}»; an input the word ignores is a contract that lies");
            }
        }
        self::refuseWhatTheRootCannotReceive($composition, 'composition', $clean, $schemas);

        return ['name' => $name, 'summary' => $summary, 'inputs' => $clean, 'composition' => $composition];
    }

    /**
     * An input placed where its component takes a list or an object is a contract that lies too
     * (greenhouse decisions/0470): an input is one scalar value, so it can never fill that prop. Measured
     * in evidence/1006 — a word put `$rows (string)` where `content` takes its rows as an array, was
     * accepted, and every later session that used it was refused at use and rebuilt the page by hand.
     *
     * Only scalar against list/object is judged. Between scalars a component converts (`metric-card`
     * takes `value` as a string and is given integers by words that already live), and a prop the
     * contract gives no type, or an input nested inside an object prop, says nothing to judge by.
     *
     * @param array<array-key, mixed>             $node
     * @param array<string, array<string, mixed>> $inputs
     * @param array<string, array<string, mixed>> $schemas
     */
    private static function refuseWhatTheRootCannotReceive(array $node, string $path, array $inputs, array $schemas): void
    {
        $type = (string) ($node['type'] ?? '');
        $props = \is_array($node['props'] ?? null) ? $node['props'] : [];
        foreach ($props as $prop => $value) {
            if (! \is_string($value) || ! preg_match('/^\$([a-zA-Z0-9_]+)$/', $value, $match) || ! isset($inputs[$match[1]])) {
                continue;
            }
            $declared = \is_string($schemas[$type][$prop]['type'] ?? null) ? $schemas[$type][$prop]['type'] : '';
            $kinds = array_values(array_diff(explode('|', $declared), ['null', '']));
            if ($kinds === [] || array_diff($kinds, ['array', 'object']) !== []) {
                continue;
            }
            // Only the ROOT is bound at use; a nested list has no source to arrive through.
            $way = $prop === 'rows' && $path === 'composition'
                ? "a word's rows arrive where it is used: leave «rows» out of the word and bind it with source in screen:declare"
                : 'put that value in the composition itself';
            throw new InvalidScreenTree(
                "{$path}.props.{$prop}",
                "«\${$match[1]}» is a {$inputs[$match[1]]['type']}, but «{$type}» takes «{$prop}» as {$declared}: an input is one value and can never fill it; {$way}",
            );
        }
        foreach (\is_array($props['children'] ?? null) ? $props['children'] : [] as $index => $child) {
            if (\is_array($child)) {
                self::refuseWhatTheRootCannotReceive($child, "{$path}.props.children.{$index}", $inputs, $schemas);
            }
        }
    }

    /** @param array<array-key, mixed> $node */
    private static function refuseMarkup(array $node, string $path): void
    {
        foreach ($node as $key => $value) {
            $at = $path . '.' . $key;
            if (\is_string($key) && preg_match('/(^html$|Html$)/i', $key)) {
                throw new InvalidScreenTree($at, 'a word composes components; it never carries markup');
            }
            if (\is_array($value)) {
                self::refuseMarkup($value, $at);
            }
        }
    }

    /**
     * Every component type a tree names, the root and each descendant.
     *
     * @param array<string, mixed> $tree
     *
     * @return list<string>
     */
    private static function types(array $tree): array
    {
        $types = [(string) ($tree['type'] ?? '')];
        foreach (\is_array($tree['props']['children'] ?? null) ? $tree['props']['children'] : [] as $child) {
            if (\is_array($child)) {
                $types = [...$types, ...self::types($child)];
            }
        }

        return $types;
    }

    /**
     * The inputs a composition reads, each with the first path that reads it.
     *
     * @param array<array-key, mixed> $node
     *
     * @return array<string, string>
     */
    private static function references(array $node, string $path = 'composition'): array
    {
        $found = [];
        foreach ($node as $key => $value) {
            $at = $path . '.' . $key;
            if (\is_string($value) && preg_match('/^\$([a-zA-Z0-9_]+)$/', $value, $match)) {
                $found[$match[1]] ??= $at;
            } elseif (\is_array($value)) {
                $found += self::references($value, $at);
            }
        }

        return $found;
    }

    /**
     * @param array<array-key, mixed> $node
     * @param array<string, mixed>    $given
     *
     * @return array<array-key, mixed>
     */
    private static function substitute(array $node, array $given): array
    {
        foreach ($node as $key => $value) {
            if (\is_string($value) && preg_match('/^\$([a-zA-Z0-9_]+)$/', $value, $match) && \array_key_exists($match[1], $given)) {
                $node[$key] = $given[$match[1]];
            } elseif (\is_array($value)) {
                $node[$key] = self::substitute($value, $given);
            }
        }

        return $node;
    }

    private static function isOfType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => \is_string($value),
            'integer' => \is_int($value),
            'number' => \is_int($value) || \is_float($value),
            'boolean' => \is_bool($value),
            default => false,
        };
    }

    /** @return resource */
    private function lock()
    {
        if (! is_dir(\dirname($this->lockPath))) {
            mkdir(\dirname($this->lockPath), 0o755, true);
        }
        $lock = fopen($this->lockPath, 'c');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Cannot lock the house words');
        }

        return $lock;
    }

    /** @param array<string, mixed> $words */
    private function write(array $words): void
    {
        @mkdir(\dirname($this->path), 0o755, true);
        $json = json_encode($words, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $temporary = $this->path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($temporary, $json) !== \strlen($json) || ! rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new \RuntimeException('Cannot write the house words');
        }
    }
}
