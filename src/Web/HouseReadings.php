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

use Milpa\Interfaces\Di\DIContainerInterface;

/**
 * The readings this house lends to its screens (greenhouse decisions/0484) — one shared registry, the way
 * a renderer joins the shared `ComponentRendererRegistry`: a plugin registers its readings in `boot()`,
 * whichever plugin boots first creates the registry, and the runtime resolves it per request.
 */
final class HouseReadings
{
    /** @var array<string, HouseReading> */
    private array $readings = [];

    /** The registry in `$container`, created and registered there when no plugin has yet. */
    public static function in(DIContainerInterface $container): self
    {
        if ($container->has(self::class)) {
            $registry = $container->get(self::class);
            if ($registry instanceof self) {
                return $registry;
            }
        }
        $registry = new self();
        $container->registerService(self::class, $registry);

        return $registry;
    }

    /** Lend `$reading` to this house's screens; a second reading under one name is refused. */
    public function register(HouseReading $reading): void
    {
        $name = $reading->name();
        if (preg_match('/^[a-z][a-z0-9-]*$/', $name) !== 1) {
            throw new \InvalidArgumentException("a reading is named in kebab-case: «{$name}»");
        }
        if (isset($this->readings[$name])) {
            throw new \InvalidArgumentException("a reading named «{$name}» is already registered");
        }
        $this->readings[$name] = $reading;
    }

    /** The reading named `$name`, or null. */
    public function get(string $name): ?HouseReading
    {
        return $this->readings[$name] ?? null;
    }

    /**
     * What an agent reads to choose a reading: name, summary, arguments, the props it fills and its audience.
     *
     * @return list<array{name: string, summary: string, arguments: array<string, array{type: string, description: string}>, fills: list<string>, audience: string}>
     */
    public function catalogue(): array
    {
        $rows = [];
        foreach ($this->readings as $name => $reading) {
            $rows[] = [
                'name' => $name,
                'summary' => $reading->summary(),
                'arguments' => $reading->arguments(),
                'fills' => $reading->fills(),
                'audience' => $reading->audience()->describe(),
            ];
        }

        return $rows;
    }
}
