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
use Milpa\Console\McpProjector;
use Milpa\Runtime\Kernel;

/**
 * The capabilities built in this house, and the verbs each one declares (greenhouse decisions/0590).
 *
 * BUILT is where the plugin's class lives: under the house's own `src/Plugins/<Name>/` — the tree a seat may write
 * with `plugins.<Name>:write` — and not in a package the house installed. Whether a seat or a person put it there
 * does not matter: what it declares was written in this house, so its scope words are this house's author's, not
 * the house's.
 */
final class BuiltCapabilities
{
    /** @param array<string, BuiltVerb> $verbs by tool name */
    private function __construct(private readonly array $verbs)
    {
    }

    /** A house that built nothing. */
    public static function none(): self
    {
        return new self([]);
    }

    /** What the booted plugins of this house declare from its own tree. */
    public static function of(Kernel $kernel): self
    {
        $base = realpath($kernel->root() . '/src/Plugins');
        if ($base === false) {
            return self::none();
        }
        try {
            $plugins = $kernel->plugins();
        } catch (\Error) {
            return self::none(); // a kernel that cannot say what it booted built nothing it can be asked about
        }
        $verbs = [];
        foreach ($plugins as $plugin) {
            if (!$plugin instanceof CommandProvider) {
                continue;
            }
            $file = (new \ReflectionObject($plugin))->getFileName();
            $real = \is_string($file) ? realpath($file) : false;
            if ($real === false || !str_starts_with($real, $base . \DIRECTORY_SEPARATOR)) {
                continue;
            }
            $inside = explode(\DIRECTORY_SEPARATOR, substr($real, \strlen($base) + 1));
            if (\count($inside) < 2) {
                continue; // a file straight under src/Plugins is no plugin's tree
            }
            foreach ($plugin->operations() as $operation) {
                $verbs[McpProjector::toolName($operation->name)] = new BuiltVerb($inside[0], $operation);
            }
        }

        return new self($verbs);
    }

    /** What the house behind a container built, or null when it has no kernel to ask. */
    public static function ofContainer(\Milpa\Interfaces\Di\DIContainerInterface $container): ?self
    {
        $kernel = $container->has(Kernel::class) ? $container->get(Kernel::class) : null;

        return $kernel instanceof Kernel ? self::of($kernel) : null;
    }

    /** The built verb behind a tool or an operation name, in any surface spelling — or null when no built capability declares it. */
    public function verb(string $name): ?BuiltVerb
    {
        return $this->verbs[McpProjector::toolName($name)] ?? null;
    }

    /**
     * Every verb one capability declares today.
     *
     * @return list<BuiltVerb>
     */
    public function verbsOf(string $capability): array
    {
        return array_values(array_filter($this->verbs, static fn (BuiltVerb $verb): bool => $verb->capability === $capability));
    }

    /**
     * The capabilities this house built that declare at least one verb, by name.
     *
     * @return list<string>
     */
    public function capabilities(): array
    {
        $names = array_values(array_unique(array_map(static fn (BuiltVerb $verb): string => $verb->capability, array_values($this->verbs))));
        sort($names);

        return $names;
    }

    public function isEmpty(): bool
    {
        return $this->verbs === [];
    }
}
