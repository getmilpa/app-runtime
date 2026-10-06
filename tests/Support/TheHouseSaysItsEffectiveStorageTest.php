<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app installs, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Support;

use Milpa\AppRuntime\Support\EffectiveStorage;
use Milpa\Data\EntityInterface;
use Milpa\Data\RepositoryFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The house says where its entities ARE kept, not what its config file happens to spell
 * (greenhouse evidence/1109, debt 5 of decisions/0575).
 *
 * Measured on the BV-4 run with the real resident: `house:context` answered `storage: {driver: null, where: null}`
 * on a house that persists, with zero configuration, to a JSON file under `var/`. The resident concluded «if driver
 * is null, entities won't persist», read `config/app.php` (7,770 characters) and found the truth in a comment. The
 * same reading is in the recorded runs of evidence/1088 and 1089. An answer that is true of the file and false of
 * the house costs a read and the reasoning around it.
 *
 * Two authorities, and this test is tied to both: the default is what `make` writes into a plugin's `boot()`
 * (milpa/devtools' stubs), and whether a declared block works is what `Milpa\Data\RepositoryFactory` says.
 *
 * @guards with no `storage` block: the driver and place generated code falls back to, named as a default that needs
 *         no configuration; with a block: what it declares, and whether the factory would build it
 *
 * @refuses `driver: null` for a house that persists; a credential in `where`; a default that drifted from the stubs
 *
 * @subject-in milpa/app-runtime
 */
final class TheHouseSaysItsEffectiveStorageTest extends TestCase
{
    public function testAHouseThatDeclaresNothingPersistsToAJsonFileAndSaysSo(): void
    {
        self::assertSame(
            ['driver' => 'file', 'where' => 'var/<table>.json', 'source' => 'default', 'configuration_required' => false],
            EffectiveStorage::of(null),
        );
    }

    public function testTheDefaultIsTheOneGeneratedCodeFallsBackTo(): void
    {
        $default = EffectiveStorage::of(null);
        $stubs = glob(\dirname(__DIR__, 2) . '/vendor/milpa/devtools/src/Make/stubs/*-plugin.runtime.php.stub') ?: [];
        $falling = array_values(array_filter($stubs, static fn (string $stub): bool => str_contains((string) file_get_contents($stub), "->get('storage', [")));

        self::assertNotSame([], $falling, 'no stub of milpa/devtools falls back any more: this default has no authority left');
        foreach ($falling as $stub) {
            $code = (string) file_get_contents($stub);
            self::assertStringContainsString("'driver' => '{$default['driver']}'", $code, basename($stub));
            self::assertStringContainsString("'/" . str_replace('<table>', '{{table}}', $default['where']) . "'", $code, basename($stub));
        }
    }

    public function testADeclaredBlockIsAnsweredAsDeclared(): void
    {
        self::assertSame(
            ['driver' => 'sqlite', 'where' => '/app/var/app.sqlite', 'source' => 'config', 'configuration_required' => false],
            EffectiveStorage::of(['driver' => 'sqlite', 'path' => '/app/var/app.sqlite']),
        );
        self::assertSame(
            ['driver' => 'memory', 'where' => null, 'source' => 'config', 'configuration_required' => false],
            EffectiveStorage::of(['driver' => 'memory']),
        );
    }

    public function testWhereNeverCarriesACredential(): void
    {
        $answer = EffectiveStorage::of(['driver' => 'mysql', 'dsn' => 'mysql:host=db;dbname=app;user=root;password=hunter2', 'user' => 'root', 'password' => 'hunter2']);

        self::assertSame('mysql:host=db;dbname=app;user=…;password=…', $answer['where']);
        self::assertStringNotContainsString('hunter2', json_encode($answer) ?: '');
        self::assertStringNotContainsString('root', json_encode($answer) ?: '');
        self::assertSame(['driver', 'where', 'source', 'configuration_required'], array_keys($answer));
    }

    /**
     * Whether a declared block needs fixing is the factory's verdict, never a second rule kept here.
     *
     * @param array<string, mixed> $declared
     */
    #[DataProvider('declaredBlocks')]
    public function testConfigurationIsRequiredExactlyWhenTheFactoryWouldRefuseTheBlock(array $declared): void
    {
        $refused = false;
        try {
            RepositoryFactory::fromConfig(
                // A real path is swapped for a throwaway one: a backend may create its file when it is built.
                array_replace($declared, ($declared['path'] ?? '') !== '' ? ['path' => sys_get_temp_dir() . '/milpa-effective-storage-' . bin2hex(random_bytes(4))] : []),
                EffectiveStorageEntity::class,
            );
        } catch (\InvalidArgumentException) {
            $refused = true;
        }

        $answer = EffectiveStorage::of($declared);

        self::assertSame($refused, $answer['configuration_required'], json_encode($declared) ?: '');
        self::assertSame('config', $answer['source']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function declaredBlocks(): iterable
    {
        yield 'file with a path' => [['driver' => 'file', 'path' => 'var/data/app.json']];
        yield 'file without a path' => [['driver' => 'file']];
        yield 'file with an empty path' => [['driver' => 'file', 'path' => '']];
        yield 'sqlite with a path' => [['driver' => 'sqlite', 'path' => 'var/app.sqlite']];
        yield 'sqlite without a path' => [['driver' => 'sqlite']];
        yield 'mysql with a dsn' => [['driver' => 'mysql', 'dsn' => 'mysql:host=127.0.0.1;dbname=app']];
        yield 'mysql without a dsn' => [['driver' => 'mysql']];
        yield 'mysql with a path instead of a dsn' => [['driver' => 'mysql', 'path' => 'var/app.sqlite']];
        yield 'memory' => [['driver' => 'memory']];
        yield 'an unknown driver' => [['driver' => 'postgres', 'dsn' => 'pgsql:host=db']];
        yield 'no driver' => [['path' => 'var/data/app.json']];
        yield 'an empty driver' => [['driver' => '', 'path' => 'var/data/app.json']];
        yield 'a driver that is not a string' => [['driver' => 7]];
        yield 'an empty block' => [[]];
    }

    public function testABlockThatIsNotOneNeedsConfiguration(): void
    {
        // `'storage' => 'sqlite'`: generated code asserts an array and dies at boot.
        self::assertSame(
            ['driver' => null, 'where' => null, 'source' => 'config', 'configuration_required' => true],
            EffectiveStorage::of('sqlite'),
        );
    }

    public function testADeclaredBlockThatDoesNotWorkStillSaysWhatItDeclares(): void
    {
        self::assertSame(
            ['driver' => 'postgres', 'where' => 'pgsql:host=db', 'source' => 'config', 'configuration_required' => true],
            EffectiveStorage::of(['driver' => 'postgres', 'dsn' => 'pgsql:host=db']),
        );
    }
}

/** The smallest entity a factory can be asked to build a repository for. */
final class EffectiveStorageEntity implements EntityInterface
{
    public function id(): int|string|null
    {
        return null;
    }

    public function toArray(): array
    {
        return [];
    }

    public static function fromArray(array $row): static
    {
        return new self();
    }
}
