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
use Milpa\Command\Operation;
use Milpa\Console\McpProjector;
use Milpa\Runtime\Config;
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
    /** A sandbox binary that is not there: a runner given it answers «unavailable» without starting a process. */
    private const string NO_PROBE = '/nonexistent/no-confinement-is-asked-here';

    private ?HouseWork $drawing = null;

    /**
     * @param array<string, BuiltVerb>                                        $verbs by tool name
     * @param array{root: string, plugins: list<object>, storage: mixed}|null $house what a card is drawn from
     */
    private function __construct(private readonly array $verbs, private readonly ?array $house = null)
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
        $root = $kernel->root();
        $storage = self::storageOf($kernel);
        // WHERE A VERB'S STATE LIVES is read from its declaration by the judge of work (decisions/0588) — with a
        // runner that never probes: the paths and who said them are decided before that judge asks whether this
        // house can confine a process, and a gate that is asked on every call must not start one to find out.
        $declared = new HouseWork($root, $plugins, $storage, new TrialRunner(self::NO_PROBE));
        $planOf = static fn (Operation $operation): ?WorkPlan => $declared->planFor($operation);
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
                $verbs[McpProjector::toolName($operation->name)] = new BuiltVerb($inside[0], $operation, $planOf);
            }
        }

        return new self($verbs, ['root' => $root, 'plugins' => $plugins, 'storage' => $storage]);
    }

    /**
     * How a call of this verb would run in this house NOW, for a card a person reads — never for the gate.
     *
     * `reads`: it changes nothing. `trial`: it is not work in the domain, so it runs in a trial and lands by a
     * promotion, as authoring does. Otherwise it is work (decisions/0588): `house` — confined to its state, with a
     * pre-image kept or not; `asks` — a person is asked first, and why; `refused` — its state is not a place for
     * state, and no call of it runs. This one does ask whether the house can confine a process.
     *
     * @return array{how: 'reads'|'trial'|'house'|'asks'|'refused', why?: string, pre_image?: bool}
     */
    public function runs(BuiltVerb $verb): array
    {
        if (!$verb->operation->mutating) {
            return ['how' => 'reads'];
        }
        if ($this->house !== null) {
            $this->drawing ??= new HouseWork($this->house['root'], $this->house['plugins'], $this->house['storage'], new TrialRunner());
        }
        $plan = $this->drawing?->planFor($verb->operation);

        return match (true) {
            $plan === null => ['how' => 'trial'],
            $plan->refused !== null => ['how' => 'refused', 'why' => $plan->refused],
            $plan->asks !== null => ['how' => 'asks', 'why' => $plan->asks],
            default => ['how' => 'house', 'pre_image' => $plan->preImage],
        };
    }

    /** The `storage` block of the house's config, or null: it declares none, or the kernel cannot be asked. */
    private static function storageOf(Kernel $kernel): mixed
    {
        try {
            $container = $kernel->container();
            $config = $container->has(Config::class) ? $container->get(Config::class) : null;
        } catch (\Throwable) {
            return null;
        }

        return $config instanceof Config ? $config->get('storage') : null;
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

    /** Whether this house built no verb at all: then there is nothing here to admit, and nothing is judged. */
    public function isEmpty(): bool
    {
        return $this->verbs === [];
    }
}
