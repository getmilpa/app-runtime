<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Config\AgentKeys;
use Milpa\AppRuntime\Operations\AgentOperations;
use PHPUnit\Framework\TestCase;

/**
 * The compaction the app declares is the compaction the app reads.
 *
 * `AgentKeys` declares `agent.compaction` as `array{maxTurns?: int, keepLast?: int}`, and `coa config`
 * prints exactly that to whoever is configuring the app. The bridge that builds the `Compactor` read
 * `keepRecent` instead — the name of the LIBRARY's constructor parameter, which is legitimate where it
 * lives and wrong here.
 *
 * It was not cosmetic. `Compactor::shouldCompact()` requires `maxTurns > keepRecent`, so a key nobody
 * could set left it at its default of 12, and anyone lowering `maxTurns` below that SILENTLY DISABLED
 * COMPACTION. Measured on cattle (greenhouse `evidence/0218`): with the declared key, zero compactions
 * in fourteen turns; with the read key, four. Nothing fails and nothing warns — the window just grows
 * until the provider refuses it, which is the mid-session death this code exists to prevent.
 *
 * `decisions/0038`: the public name wins, because changing it to match the implementation would let
 * the bug legislate. And BOTH are not accepted — two spellings for one decision end up being two
 * contracts (`evidence/0141`), and here it would invent the second key while fixing the first.
 *
 * @internal
 */
final class CompactionConfigTest extends TestCase
{
    /** The declared key is the one the bridge honours. */
    public function testTheDeclaredKeyIsTheOneThatConfiguresCompaction(): void
    {
        $compactor = $this->compactorFor(['maxTurns' => 6, 'keepLast' => 2]);

        self::assertSame(6, $this->readInt($compactor, 'maxTurns'));
        self::assertSame(2, $this->readInt($compactor, 'keepRecent'), 'keepLast configures what the Compactor calls keepRecent');
    }

    /**
     * AND THE GUARD THAT MADE IT DANGEROUS holds with the declared key.
     *
     * `maxTurns > keepRecent` is what decides whether compaction can ever fire. With the bridge broken
     * this comparison silently answered 6 > 12 = false.
     */
    public function testALoweredCeilingCanStillCompact(): void
    {
        $compactor = $this->compactorFor(['maxTurns' => 6, 'keepLast' => 2]);

        self::assertGreaterThan(
            $this->readInt($compactor, 'keepRecent'),
            $this->readInt($compactor, 'maxTurns'),
            'a lowered maxTurns must still leave room to compact, or compaction is off and says nothing',
        );
    }

    /** An unset key keeps the defaults rather than inventing a number. */
    public function testWithoutConfigurationTheDefaultsStand(): void
    {
        $compactor = $this->compactorFor(null);

        self::assertSame(40, $this->readInt($compactor, 'maxTurns'));
        self::assertSame(12, $this->readInt($compactor, 'keepRecent'));
    }

    /** The declaration this test is defending actually says `keepLast`. */
    public function testTheDeclarationStillNamesKeepLast(): void
    {
        $declarado = (string) \json_encode(AgentKeys::todas()['agent.compaction'] ?? []);

        self::assertStringContainsString('keepLast', $declarado);
        self::assertStringNotContainsString('keepRecent', $declarado, 'the config contract has one name, not two');
    }

    /** @param array<string, mixed>|null $ajustes */
    private function compactorFor(?array $ajustes): object
    {
        $ops = (new \ReflectionClass(AgentOperations::class))->newInstanceWithoutConstructor();

        $contenedor = new class ($ajustes) implements \Milpa\Interfaces\Di\DIContainerInterface {
            public function __construct(private readonly ?array $ajustes)
            {
            }

            public function getContainer(): \Psr\Container\ContainerInterface
            {
                throw new \LogicException('el doble sólo contesta lo que el puente pregunta');
            }

            public function registerService(string $id, string|object $classOrInstance): void
            {
            }

            public function compileContainer(): void
            {
            }

            public function resolve(string $className, bool $singleton = true): mixed
            {
                return null;
            }

            public function tryGet(string $id): mixed
            {
                return $this->has($id) ? $this->get($id) : null;
            }

            public function has(string $id): bool
            {
                return $id === \Milpa\Runtime\Config::class;
            }

            public function get(string $id): mixed
            {
                // Una `Config` DE VERDAD, no un doble: el puente lee por notación de punto, y un
                // doble que contesta la llave entera se saltaría justo el camino que se está midiendo.
                return new \Milpa\Runtime\Config(
                    $this->ajustes === null ? [] : ['agent' => ['compaction' => $this->ajustes]],
                );
            }
        };

        $p = (new \ReflectionClass(AgentOperations::class))->getProperty('container');
        $p->setAccessible(true);
        $p->setValue($ops, $contenedor);

        $m = (new \ReflectionClass(AgentOperations::class))->getMethod('compactor');
        $m->setAccessible(true);

        return $m->invoke($ops);
    }

    private function readInt(object $compactor, string $prop): int
    {
        $p = (new \ReflectionClass($compactor))->getProperty($prop);
        $p->setAccessible(true);

        return (int) $p->getValue($compactor);
    }
}
