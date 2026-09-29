<?php

/**
 * This file is part of milpa/app-runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Operations\ConfigOperations;
use Milpa\Command\CommandProvider;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 🚨 A PROPERTY WITH NO TYPE IS READ AS A PLACE FOR STRUCTURE, so every property says what it takes.
 *
 * `config:set` declared its `value` with a description and no `type`. Asked to write a string, the
 * local resident sent `{"value": …}`, `{"__type": "string", "__value": …}` and `{"instructions": …}`
 * instead — 0 plain strings in 8 calls on the house's own request — and the declared string key
 * refused every one (greenhouse evidence/1052, re-measured in evidence/1059). Nothing in the family
 * wrapped the value: the provider's reply already carried it. The schema was the only thing that
 * could say otherwise, and it said nothing.
 *
 * So every property of every operation this package ships declares a `type` (a single one, or the
 * list of JSON types it admits), recursively through nested objects and list items.
 */
final class EveryPropertyDeclaresItsTypeTest extends TestCase
{
    /**
     * Every provider this package ships, built the way `config/operations.php` builds them.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function providers(): iterable
    {
        $dir = \dirname(__DIR__, 2) . '/src/Operations';
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $class = 'Milpa\\AppRuntime\\Operations\\' . basename($file, '.php');
            if (class_exists($class) && is_a($class, CommandProvider::class, true)) {
                yield basename($file, '.php') => [$class];
            }
        }
    }

    /** @param class-string<CommandProvider> $provider */
    #[DataProvider('providers')]
    public function testEveryPropertyOfEveryOperationDeclaresItsType(string $provider): void
    {
        $built = $this->build($provider);
        if ($built === null) {
            self::markTestSkipped($provider . ' needs collaborators no app hands a bare provider');
        }

        $typeless = [];
        foreach ($built->operations() as $op) {
            if ($op instanceof Operation) {
                $typeless = [...$typeless, ...self::typeless($op->name, $op->inputSchema ?? [])];
            }
        }

        self::assertSame([], $typeless, 'a property without a type is read as a place for structure');
    }

    /** The guard against a vacuous battery: it has to have looked at real properties. */
    public function testThePackageDeclaresEnoughPropertiesForThisToMeanSomething(): void
    {
        $properties = 0;
        foreach (self::providers() as [$provider]) {
            foreach ($this->build($provider)?->operations() ?? [] as $op) {
                if ($op instanceof Operation && \is_array($op->inputSchema['properties'] ?? null)) {
                    $properties += \count($op->inputSchema['properties']);
                }
            }
        }

        self::assertGreaterThan(50, $properties, 'the check above reads this many declared properties');
    }

    /** The positive control: `config:set`'s old declaration is exactly what the assertion above catches. */
    public function testTheCheckCatchesThePropertyThatHadNoType(): void
    {
        $before = [
            'type' => 'object',
            'properties' => [
                'key' => ['type' => 'string'],
                'value' => ['description' => 'The value to write. Declared agent keys enforce the type shown by `php bin/coa config`.'],
                'nested' => ['type' => 'object', 'properties' => ['inner' => ['description' => 'no type either']]],
                'rows' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['cell' => []]]],
            ],
        ];

        self::assertSame(
            ['config:set value', 'config:set nested.inner', 'config:set rows[].cell'],
            self::typeless('config:set', $before),
        );
    }

    /** What `config:set` declares now: every JSON type by name, which admits what it always admitted. */
    public function testConfigSetDeclaresAnyJsonValueByName(): void
    {
        $set = null;
        foreach (ConfigOperations::para(sys_get_temp_dir())->operations() as $op) {
            if ($op->name === 'config:set') {
                $set = $op;
            }
        }

        self::assertNotNull($set);
        self::assertSame(
            ['string', 'integer', 'number', 'boolean', 'array', 'object', 'null'],
            $set->inputSchema['properties']['value']['type'] ?? null,
        );
    }

    /**
     * The dotted paths of the properties in this schema that declare no type.
     *
     * @param array<array-key, mixed> $schema
     *
     * @return list<string>
     */
    private static function typeless(string $operation, array $schema, string $path = ''): array
    {
        $found = [];
        $properties = \is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        foreach ($properties as $name => $spec) {
            $at = $path . $name;
            $spec = \is_array($spec) ? $spec : [];
            if (!\array_key_exists('type', $spec)) {
                $found[] = $operation . ' ' . $at;
            }
            $found = [...$found, ...self::typeless($operation, $spec, $at . '.')];
            if (\is_array($spec['items'] ?? null)) {
                $found = [...$found, ...self::typeless($operation, $spec['items'], $at . '[].')];
            }
        }

        return $found;
    }

    /** @param class-string<CommandProvider> $provider */
    private function build(string $provider): ?CommandProvider
    {
        try {
            $reflected = new \ReflectionClass($provider);
            $built = $reflected->getConstructor() === null
                ? $reflected->newInstance()
                : $reflected->newInstance(new DIContainer());

            return $built instanceof CommandProvider ? $built : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
