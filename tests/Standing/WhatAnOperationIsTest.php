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

namespace Milpa\AppRuntime\Tests\Standing;

use Milpa\AppRuntime\Standing\OperationPin;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Operation;
use PHPUnit\Framework\TestCase;

/**
 * What the house reads as «the same operation» when a standing grant was given over it (greenhouse decisions/0615,
 * «Lo que la segunda revisión encontró», 4): its declaration, the lines of its handler, AND the bytes of the files
 * its handler's own code is written in.
 *
 * The provider under test is written to disk here — a class, its parent and a trait, each in its own file — so that
 * the files can be changed under it, which is what an update does.
 *
 * @subject-in milpa/app-runtime
 */
final class WhatAnOperationIsTest extends TestCase
{
    private string $folder;

    private object $provider;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir() . '/milpa-pin-' . bin2hex(random_bytes(6));
        mkdir($this->folder);
        $name = 'Pinned' . bin2hex(random_bytes(6));
        file_put_contents($this->folder . '/Base.php', "<?php\nabstract class {$name}Base\n{\n    protected function deliver(array \$input): array\n    {\n        return ['ok' => true];\n    }\n}\n");
        file_put_contents($this->folder . '/Helps.php', "<?php\ntrait {$name}Helps\n{\n    protected function note(): string\n    {\n        return 'noted';\n    }\n}\n");
        file_put_contents($this->folder . '/Provider.php', "<?php\nfinal class {$name} extends {$name}Base\n{\n    use {$name}Helps;\n\n    public function handler(): \\Closure\n    {\n        return fn (array \$input): array => \$this->deliver(\$input);\n    }\n\n    public function method(array \$input): array\n    {\n        return \$this->deliver(\$input);\n    }\n}\n");
        foreach (['Base', 'Helps', 'Provider'] as $file) {
            require $this->folder . '/' . $file . '.php';
        }
        $this->provider = new $name();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->folder));
    }

    private function operation(?callable $handler = null, string $name = 'lab:deliver', array $scopes = ['lab:deliver'], bool $actsOn = true): Operation
    {
        return new Operation(
            $name,
            'Lab: an operation whose handler delegates',
            $handler ?? $this->provider->handler(),
            ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]],
            scopes: $scopes,
            effects: EffectProfile::readOnly(),
            actsOn: $actsOn ? static fn (array $input): ?string => null : null,
        );
    }

    /** @return iterable<string, array{string}> */
    public static function theFilesItsCodeIsWrittenIn(): iterable
    {
        yield 'the class the handler is written in' => ['Provider'];
        yield 'the class it extends' => ['Base'];
        yield 'a trait it uses' => ['Helps'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('theFilesItsCodeIsWrittenIn')]
    public function testItIsAnotherOperationWhenAFileItsCodeIsWrittenInChanges(string $file): void
    {
        $pinned = OperationPin::of($this->operation());
        self::assertNotNull($pinned);
        self::assertSame($pinned, OperationPin::of($this->operation()), 'control: read again, it is the same operation');
        $contract = $this->operation()->instrumentDigest();
        $path = $this->folder . '/' . $file . '.php';
        $asGranted = (string) file_get_contents($path);

        // What an update does: the same names, the same handler line, other code under it.
        file_put_contents($path, $asGranted . "// and something nobody granted\n");

        self::assertNotSame($pinned, OperationPin::of($this->operation()));
        if ($file !== 'Provider') {
            self::assertSame($contract, $this->operation()->instrumentDigest(), "control: the contract's own digest does not see it");
        }
        // …and put back as it was, it is the operation that was granted.
        file_put_contents($path, $asGranted);
        self::assertSame($pinned, OperationPin::of($this->operation()));
    }

    public function testItIsAnotherOperationWhenWhatItDeclaresChanges(): void
    {
        $pinned = OperationPin::of($this->operation());

        self::assertNotSame($pinned, OperationPin::of($this->operation(name: 'lab:delivered')));
        self::assertNotSame($pinned, OperationPin::of($this->operation(scopes: ['lab:deliver', 'lab:other'])));
        self::assertSame($pinned, OperationPin::of($this->operation(scopes: ['lab:deliver'])), 'control: the same declaration is the same operation');
    }

    public function testAnOperationThatDoesNotSayWhatItActsOnIsPinnedTooBecauseAWatcherHasToBe(): void
    {
        $watcher = $this->operation(actsOn: false);

        self::assertNull($watcher->instrumentDigest(), 'control: the contract pins no operation that does not say what it acts on');
        $pinned = OperationPin::of($watcher);
        self::assertNotNull($pinned);
        self::assertNotSame($pinned, OperationPin::of($this->operation(actsOn: false, scopes: ['lab:other'])), 'and its declaration is part of what it is');
        self::assertNotSame($pinned, OperationPin::of($this->operation()), 'as is whether it says what it acts on');
    }

    public function testAHandlerGivenAsAMethodIsReadTheSameWay(): void
    {
        $pinned = OperationPin::of($this->operation([$this->provider, 'method']));
        self::assertNotNull($pinned);

        file_put_contents($this->folder . '/Base.php', (string) file_get_contents($this->folder . '/Base.php') . "// changed\n");

        self::assertNotSame($pinned, OperationPin::of($this->operation([$this->provider, 'method'])));
    }

    public function testAHouseMovedToAnotherFolderHoldsTheSameOperations(): void
    {
        // The pin reads what the files hold, never where they are.
        $here = OperationPin::of($this->operation());
        $name = 'Moved' . bin2hex(random_bytes(6));
        $there = $this->folder . '/elsewhere';
        mkdir($there);
        $old = $this->provider::class;
        foreach (['Base', 'Helps', 'Provider'] as $file) {
            file_put_contents($there . '/' . $file . '.php', str_replace($old, $name, (string) file_get_contents($this->folder . '/' . $file . '.php')));
            require $there . '/' . $file . '.php';
        }
        $moved = new $name();

        self::assertNotSame($here, OperationPin::of($this->operation($moved->handler())), 'control: other bytes (another class name) are another operation');
        rename($this->folder . '/Provider.php', $this->folder . '/Provider.kept');
        copy($this->folder . '/Provider.kept', $this->folder . '/Provider.php');
        self::assertSame($here, OperationPin::of($this->operation()), 'the same bytes written again are the same operation');
    }

    public function testAHandlerWrittenOutsideAnyClassIsReadFromItsOwnFile(): void
    {
        $plain = $this->folder . '/plain.php';
        file_put_contents($plain, "<?php\nreturn static fn (array \$input): array => ['ok' => true];\n");
        $pinned = OperationPin::of($this->operation(require $plain));
        self::assertNotNull($pinned);

        file_put_contents($plain, "<?php\nreturn static fn (array \$input): array => ['ok' => true];\n// and something nobody granted\n");

        self::assertNotSame($pinned, OperationPin::of($this->operation(require $plain)));
    }

    public function testWhatCannotBeReadCannotBePinned(): void
    {
        self::assertNull(OperationPin::of($this->operation('strlen')), 'a handler that is not written in a file');
        unlink($this->folder . '/Base.php');
        self::assertNull(OperationPin::of($this->operation()), 'a file its code is written in that is gone');
    }
}
