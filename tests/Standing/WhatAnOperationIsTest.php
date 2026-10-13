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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the house reads as «the same operation» when a standing grant was given over it (greenhouse decisions/0615,
 * «Lo que la segunda revisión encontró», 4, and the decision of 2026-10-12): its declaration, the lines of its
 * handler, the bytes of the files its handler's own code is written in — AND THE UNIT IT SHIPS IN: a package this
 * house installed, by its reference, or a plugin, by every PHP file under its directory.
 *
 * A house is written to disk here — a package under `vendor/` with what Composer says of it, and a plugin under
 * `src/Plugins/` — so that both can be changed under their operations, which is what an update does.
 *
 * @subject-in milpa/app-runtime
 */
final class WhatAnOperationIsTest extends TestCase
{
    private string $house;

    /** Where the package's files are. */
    private string $package;

    /** Where the plugin's files are. */
    private string $plugin;

    private object $shipped;

    private object $built;

    protected function setUp(): void
    {
        $this->house = sys_get_temp_dir() . '/milpa-pin-' . bin2hex(random_bytes(6));
        $this->package = $this->house . '/vendor/lab/thing/src';
        mkdir($this->package, 0o755, true);
        mkdir($this->house . '/vendor/composer', 0o755, true);
        $this->installed('ref-1');
        $name = 'Pinned' . bin2hex(random_bytes(6));
        file_put_contents($this->package . '/Base.php', "<?php\nabstract class {$name}Base\n{\n    protected function deliver(array \$input): array\n    {\n        return ['ok' => true];\n    }\n}\n");
        file_put_contents($this->package . '/Helps.php', "<?php\ntrait {$name}Helps\n{\n    protected function note(): string\n    {\n        return 'noted';\n    }\n}\n");
        file_put_contents($this->package . '/Provider.php', "<?php\nfinal class {$name} extends {$name}Base\n{\n    use {$name}Helps;\n\n    public function handler(): \\Closure\n    {\n        return fn (array \$input): array => \$this->deliver(\$input);\n    }\n\n    public function method(array \$input): array\n    {\n        return \$this->deliver(\$input);\n    }\n}\n");
        file_put_contents($this->package . '/Other.php', "<?php\nfinal class {$name}Other extends {$name}Base\n{\n    public function handler(): \\Closure\n    {\n        return fn (array \$input): array => \$this->deliver(\$input);\n    }\n}\n");
        file_put_contents($this->package . '/Service.php', "<?php\n// a class of the package the handler's own class is not written in\n");
        foreach (['Base', 'Helps', 'Provider', 'Other'] as $file) {
            require $this->package . '/' . $file . '.php';
        }
        $this->shipped = new $name();

        $plugin = 'Shelf' . bin2hex(random_bytes(6));
        $this->plugin = $this->house . '/src/Plugins/' . $plugin;
        mkdir($this->plugin . '/Support', 0o755, true);
        file_put_contents($this->plugin . '/' . $plugin . '.php', "<?php\nnamespace App\\Plugins\\{$plugin};\n\nfinal class {$plugin}\n{\n    public function handler(): \\Closure\n    {\n        return fn (array \$input): array => Support\\Rules::deliver(\$input);\n    }\n}\n");
        file_put_contents($this->plugin . '/Support/Rules.php', "<?php\nnamespace App\\Plugins\\{$plugin}\\Support;\n\nfinal class Rules\n{\n    public static function deliver(array \$input): array\n    {\n        return ['ok' => true];\n    }\n\n    public function handler(): \\Closure\n    {\n        return fn (array \$input): array => self::deliver(\$input);\n    }\n}\n");
        file_put_contents($this->plugin . '/notes.txt', "what the plugin is for\n");
        require $this->plugin . '/' . $plugin . '.php';
        require $this->plugin . '/Support/Rules.php';
        $class = "App\\Plugins\\{$plugin}\\{$plugin}";
        $this->built = new $class();
    }

    protected function tearDown(): void
    {
        LabHouse::aProcessStartedIn(null);
        exec('rm -rf ' . escapeshellarg($this->house));
    }

    /**
     * What Composer says this house installed.
     *
     * @param array<string, mixed>|null $package what it says of the lab's package, when not the usual
     */
    private function installed(?string $reference, ?array $package = null): void
    {
        file_put_contents($this->house . '/vendor/composer/installed.json', (string) json_encode(['packages' => [
            ['name' => 'someone/else', 'version' => '2.0.0', 'dist' => ['reference' => 'theirs'], 'install-path' => '../someone/else'],
            $package ?? ['name' => 'lab/thing', 'version' => '1.0.0', 'install-path' => '../lab/thing'] + ($reference === null ? [] : ['source' => ['reference' => $reference], 'dist' => ['reference' => $reference]]),
        ]]));
    }

    private function operation(?callable $handler = null, string $name = 'lab:deliver', array $scopes = ['lab:deliver'], bool $actsOn = true): Operation
    {
        return new Operation(
            $name,
            'Lab: an operation whose handler delegates',
            $handler ?? $this->shipped->handler(),
            ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]],
            scopes: $scopes,
            effects: EffectProfile::readOnly(),
            actsOn: $actsOn ? static fn (array $input): ?string => null : null,
        );
    }

    private function pin(?Operation $operation = null): ?string
    {
        return OperationPin::of($operation ?? $this->operation(), $this->house);
    }

    private function builtPin(): ?string
    {
        return $this->pin($this->operation($this->built->handler()));
    }

    // ── a package: what the house installed, and the files of the handler's own class ───────────────────────

    public function testAnOperationOfAPackageIsAnotherWhenThePackageIsInstalledAtAnotherReference(): void
    {
        $pinned = $this->pin();
        self::assertNotNull($pinned);
        self::assertSame($pinned, $this->pin(), 'control: read again, it is the same operation');
        $contract = $this->operation()->instrumentDigest();

        // What an update does, as far as Composer writes it down: not one byte of the handler's files changed here.
        $this->installed('ref-2');

        self::assertNotSame($pinned, $this->pin());
        self::assertSame($contract, $this->operation()->instrumentDigest(), "control: the contract's own digest does not see it");
        $this->installed('ref-1');
        self::assertSame($pinned, $this->pin(), 'at the reference it was granted over, it is the operation that was granted');
        // Another package of the house updated is not this operation changing.
        file_put_contents($this->house . '/vendor/composer/installed.json', str_replace('theirs', 'theirs-updated', (string) file_get_contents($this->house . '/vendor/composer/installed.json')));
        self::assertSame($pinned, $this->pin());
    }

    /** @return iterable<string, array{string}> */
    public static function theFilesItsCodeIsWrittenIn(): iterable
    {
        yield 'the class the handler is written in' => ['Provider'];
        yield 'the class it extends' => ['Base'];
        yield 'a trait it uses' => ['Helps'];
    }

    #[DataProvider('theFilesItsCodeIsWrittenIn')]
    public function testItIsAnotherOperationWhenAFileItsCodeIsWrittenInChanges(string $file): void
    {
        // The reference says what was installed; these files say what is there. Both have to be what they were.
        $pinned = $this->pin();
        self::assertNotNull($pinned);
        $contract = $this->operation()->instrumentDigest();
        $path = $this->package . '/' . $file . '.php';
        $asGranted = (string) file_get_contents($path);

        file_put_contents($path, $asGranted . "// and something nobody granted\n");

        self::assertNotSame($pinned, $this->pin());
        if ($file !== 'Provider') {
            self::assertSame($contract, $this->operation()->instrumentDigest(), "control: the contract's own digest does not see it");
        }
        // …and put back as it was, it is the operation that was granted.
        file_put_contents($path, $asGranted);
        self::assertSame($pinned, $this->pin());
        // Written again to the same length, within the same second: its time and its size say nothing changed.
        $sameLength = strtr($asGranted, ['class' => 'CLASS', 'trait' => 'TRAIT']);
        self::assertSame(\strlen($asGranted), \strlen($sameLength));
        file_put_contents($path, $sameLength);
        touch($path, (int) filemtime($this->package . '/Other.php'));
        self::assertNotSame($pinned, $this->pin(), 'a file of the same length and the same time is read as the file before it');
    }

    public function testAHandlerWrittenOutsideAnyClassIsPinnedByTheFileItIsWrittenInAndItsPackage(): void
    {
        $plain = $this->package . '/plain.php';
        $make = 'plain_' . bin2hex(random_bytes(6));
        $written = "<?php\nfunction {$make}(): \\Closure\n{\n    return static fn (array \$input): array => ['ok' => true];\n}\n";
        file_put_contents($plain, $written);
        require $plain;
        $pinned = $this->pin($this->operation($make()));
        self::assertNotNull($pinned);

        file_put_contents($plain, $written . "// and something nobody granted\n");
        self::assertNotSame($pinned, $this->pin($this->operation($make())));
        file_put_contents($plain, $written);
        $this->installed('ref-2');
        self::assertNotSame($pinned, $this->pin($this->operation($make())));
    }

    public function testWhatItDoesNotSeeInAPackageIsSaidAndPinnedHere(): void
    {
        // A file of the package that the handler's own class is not written in, edited in place while the reference
        // stays: the pin says what was installed, not what is there. The class says so; this keeps it true.
        $pinned = $this->pin();

        file_put_contents($this->package . '/Service.php', "<?php\n// edited in place under vendor/\n");

        self::assertSame($pinned, $this->pin());
        self::assertStringContainsString('A package edited in place under', (string) (new \ReflectionClass(OperationPin::class))->getDocComment());
    }

    public function testTwoClassesOfOneUnitWithTheSameHandlerLineAreTwoOperations(): void
    {
        $other = new ($this->shipped::class . 'Other')();

        self::assertSame($this->operation()->handlerDigest(), $this->operation($other->handler())->handlerDigest(), 'control: the same line');
        self::assertNotSame($this->pin(), $this->pin($this->operation($other->handler())));
    }

    // ── a plugin: every PHP file under its directory ────────────────────────────────────────────────────────

    /** @return iterable<string, array{\Closure(string): void}> */
    public static function whatChangesAPlugin(): iterable
    {
        yield 'the file its class is written in' => [static fn (string $plugin) => file_put_contents((string) (glob($plugin . '/Shelf*.php') ?: [''])[0], "\n// changed\n", \FILE_APPEND)];
        yield 'another PHP file of the plugin, which its handler reaches' => [static fn (string $plugin) => file_put_contents($plugin . '/Support/Rules.php', "\n// changed\n", \FILE_APPEND)];
        yield 'a PHP file added to it' => [static fn (string $plugin) => file_put_contents($plugin . '/Support/Added.php', "<?php\n// new\n")];
        yield 'a PHP file taken out of it' => [static fn (string $plugin) => unlink($plugin . '/Support/Rules.php')];
        yield 'a PHP file of it moved' => [static fn (string $plugin) => rename($plugin . '/Support/Rules.php', $plugin . '/Rules.php')];
    }

    #[DataProvider('whatChangesAPlugin')]
    public function testAnOperationOfAPluginIsAnotherWhenAnyPhpFileOfThePluginChanges(\Closure $change): void
    {
        $pinned = $this->builtPin();
        self::assertNotNull($pinned, (string) OperationPin::whyNot($this->operation($this->built->handler()), $this->house));
        self::assertSame($pinned, $this->builtPin(), 'control: read again, it is the same operation');

        $change($this->plugin);

        self::assertNotSame($pinned, $this->builtPin());
    }

    public function testWhatIsNotCodeOfThePluginIsNotTheOperationChanging(): void
    {
        $pinned = $this->builtPin();
        self::assertNotNull($pinned);

        // A file of the plugin that is not PHP, a file of another plugin, a file of the house outside any plugin,
        // and another package updated: it is not the whole tree of the house.
        file_put_contents($this->plugin . '/notes.txt', "changed\n");
        mkdir($this->house . '/src/Plugins/Neighbour');
        file_put_contents($this->house . '/src/Plugins/Neighbour/Neighbour.php', "<?php\n// another plugin\n");
        file_put_contents($this->house . '/src/Loose.php', "<?php\n// the house's own\n");
        $this->installed('ref-2');

        self::assertSame($pinned, $this->builtPin());
    }

    public function testAClassOfAPluginThatIsNotItsOwnClassIsPinnedByTheSamePlugin(): void
    {
        // The handler is written in a class two levels down: the unit is still the plugin's whole directory.
        $rules = new (substr($this->built::class, 0, (int) strrpos($this->built::class, '\\')) . '\\Support\\Rules')();
        $pinned = $this->pin($this->operation($rules->handler()));
        self::assertNotNull($pinned);

        file_put_contents((string) (glob($this->plugin . '/Shelf*.php') ?: [''])[0], "\n// changed, one level up\n", \FILE_APPEND);

        self::assertNotSame($pinned, $this->pin($this->operation($rules->handler())));
    }

    // ── what cannot be pinned is not granted over ───────────────────────────────────────────────────────────

    public function testAnOperationWhoseUnitCannotBeToldIsNotPinnedAndItSaysWhy(): void
    {
        // Code of the house that belongs to no package and to no plugin — made by a plain function, so that the file
        // it is written in is the only one: a closure handed over by `require` would take this test's class with it.
        $loose = $this->house . '/src/loose.php';
        $make = 'loose_' . bin2hex(random_bytes(6));
        file_put_contents($loose, "<?php\nfunction {$make}(): \\Closure\n{\n    return static fn (array \$input): array => ['ok' => true];\n}\n");
        require $loose;
        $operation = $this->operation($make());
        self::assertNull((new \ReflectionFunction($operation->handler))->getClosureScopeClass(), 'control: no class comes with it');
        self::assertNull($this->pin($operation));
        self::assertStringContainsString('belongs to no unit this house can tell', (string) OperationPin::whyNot($operation, $this->house));

        // A package the house installed with no reference: the day it changed could not be told.
        $this->installed(null);
        self::assertNull($this->pin());
        self::assertStringContainsString('`lab/thing`, which this house installed with no reference', (string) OperationPin::whyNot($this->operation(), $this->house));

        // What Composer says it installed is gone, or says nothing of that package: its code is nobody's.
        unlink($this->house . '/vendor/composer/installed.json');
        self::assertNull($this->pin());
        self::assertStringContainsString('belongs to no unit this house can tell', (string) OperationPin::whyNot($this->operation(), $this->house));

        // A class named like a plugin's, written under what the house installed — and Composer says nothing of it:
        // it is not a plugin of the house, and it is not a package either.
        $name = 'Stray' . bin2hex(random_bytes(6));
        mkdir($this->house . '/vendor/stray/thing', 0o755, true);
        file_put_contents($this->house . '/vendor/stray/thing/Thing.php', "<?php\nnamespace Stray\\Plugins\\{$name};\n\nfinal class Thing\n{\n    public function handler(): \\Closure\n    {\n        return fn (array \$input): array => ['ok' => true];\n    }\n}\n");
        require $this->house . '/vendor/stray/thing/Thing.php';
        $stray = new ("Stray\\Plugins\\{$name}\\Thing")();
        self::assertNull($this->pin($this->operation($stray->handler())));
        // …and one whose plugin directory would be the house itself: the unit of an operation is never the whole tree.
        file_put_contents($this->house . '/Whole.php', "<?php\nnamespace Plugins\\{$name};\n\nfinal class Whole\n{\n    public function handler(): \\Closure\n    {\n        return fn (array \$input): array => ['ok' => true];\n    }\n}\n");
        require $this->house . '/Whole.php';
        $whole = new ("Plugins\\{$name}\\Whole")();
        self::assertNull($this->pin($this->operation($whole->handler())));
        self::assertStringContainsString('belongs to no unit this house can tell', (string) OperationPin::whyNot($this->operation($whole->handler()), $this->house));

        // A class named like a plugin's, asked of a house it is not written under.
        self::assertNotNull($this->builtPin(), 'control: under its own house it is pinned');
        $elsewhere = sys_get_temp_dir() . '/milpa-pin-other-' . bin2hex(random_bytes(6));
        mkdir($elsewhere);
        try {
            self::assertNull(OperationPin::of($this->operation($this->built->handler()), $elsewhere));
        } finally {
            rmdir($elsewhere);
        }
    }

    public function testAPluginWithAPhpFileThatCannotBeReadIsNotPinned(): void
    {
        self::assertNotNull($this->builtPin(), 'control');
        $why = fn (): string => (string) OperationPin::whyNot($this->operation($this->built->handler()), $this->house);

        // A file of the plugin the process may not read: what the plugin is cannot be told.
        chmod($this->plugin . '/Support/Rules.php', 0o000);
        try {
            self::assertNull($this->builtPin());
            self::assertStringContainsString('a PHP file of that plugin cannot be read', $why());
        } finally {
            chmod($this->plugin . '/Support/Rules.php', 0o644);
        }
        self::assertNotNull($this->builtPin(), 'control: readable again, it is pinned');
        // …and a directory of it the process may not open.
        chmod($this->plugin . '/Support', 0o000);
        try {
            self::assertNull($this->builtPin());
            self::assertStringContainsString('a PHP file of that plugin cannot be read', $why());
        } finally {
            chmod($this->plugin . '/Support', 0o755);
        }
    }

    public function testWhatComposerSaysBadlyOfAPackageIsNotAPackage(): void
    {
        // An entry with no name or no place, one whose place is not there, and a file that is not what Composer
        // writes: none of them is a unit, and the operation of the lab's package is not pinned by any of them.
        $pinned = $this->pin();
        $entries = [['version' => '1.0.0'], ['name' => 'lab/nowhere', 'install-path' => ''], ['name' => 'lab/gone', 'install-path' => '../lab/gone', 'dist' => ['reference' => 'x']], 'not an entry'];
        $said = json_decode((string) file_get_contents($this->house . '/vendor/composer/installed.json'), true);
        file_put_contents($this->house . '/vendor/composer/installed.json', (string) json_encode(['packages' => [...$entries, ...$said['packages']]]));
        self::assertSame($pinned, $this->pin(), 'beside entries that say nothing, the package is the package');

        file_put_contents($this->house . '/vendor/composer/installed.json', (string) json_encode(['packages' => $entries]));
        self::assertNull($this->pin());
        foreach (['not json', '[]', '{"packages": "none"}'] as $written) {
            file_put_contents($this->house . '/vendor/composer/installed.json', $written);
            self::assertNull($this->pin(), $written);
        }
        // An absolute place is read as it is written.
        file_put_contents($this->house . '/vendor/composer/installed.json', (string) json_encode(['packages' => [['name' => 'lab/thing', 'version' => '1.0.0', 'dist' => ['reference' => 'ref-1'], 'install-path' => $this->house . '/vendor/lab/thing']]]));
        self::assertNotNull($this->pin());
        // The most specific package a file is under is its unit, wherever each is listed: a package inside another.
        file_put_contents($this->house . '/vendor/composer/installed.json', (string) json_encode(['packages' => [
            ['name' => 'lab/whole-vendor', 'version' => '9', 'dist' => ['reference' => 'outer-1'], 'install-path' => '..'],
            ['name' => 'lab/thing', 'version' => '1.0.0', 'dist' => ['reference' => 'ref-1'], 'install-path' => '../lab/thing'],
            ['name' => 'lab/whole-vendor-again', 'version' => '9', 'dist' => ['reference' => 'outer-1'], 'install-path' => '../lab'],
        ]]));
        $inner = $this->pin();
        file_put_contents($this->house . '/vendor/composer/installed.json', str_replace('outer-1', 'outer-2', (string) file_get_contents($this->house . '/vendor/composer/installed.json')));
        self::assertSame($inner, $this->pin(), 'the outer package is not the unit of a file of the inner one');
        file_put_contents($this->house . '/vendor/composer/installed.json', str_replace('ref-1', 'ref-2', (string) file_get_contents($this->house . '/vendor/composer/installed.json')));
        self::assertNotSame($inner, $this->pin());
    }

    public function testWhatCannotBeReadCannotBePinned(): void
    {
        self::assertNull($this->pin($this->operation('strlen')), 'a handler that is not written in a file');
        self::assertStringContainsString('its handler cannot be read', (string) OperationPin::whyNot($this->operation('strlen'), $this->house));
        self::assertNull(OperationPin::whyNot($this->operation(), $this->house), 'control: what can be pinned has no reason not to be');
        unlink($this->package . '/Base.php');
        self::assertNull($this->pin(), 'a file its code is written in that is gone');
        self::assertStringContainsString('cannot be read', (string) OperationPin::whyNot($this->operation(), $this->house));
        unlink($this->plugin . '/Support/Rules.php');
        symlink($this->plugin . '/Support/Gone.php', $this->plugin . '/Support/Rules.php');
        self::assertNotNull($this->builtPin(), 'a link to nothing is not a file of the plugin');
    }

    // ── what it declares ────────────────────────────────────────────────────────────────────────────────────

    public function testItIsAnotherOperationWhenWhatItDeclaresChanges(): void
    {
        $pinned = $this->pin();

        self::assertNotSame($pinned, $this->pin($this->operation(name: 'lab:delivered')));
        self::assertNotSame($pinned, $this->pin($this->operation(scopes: ['lab:deliver', 'lab:other'])));
        self::assertSame($pinned, $this->pin($this->operation(scopes: ['lab:deliver'])), 'control: the same declaration is the same operation');
    }

    public function testAnOperationThatDoesNotSayWhatItActsOnIsPinnedTooBecauseAWatcherHasToBe(): void
    {
        $watcher = $this->operation(actsOn: false);

        self::assertNull($watcher->instrumentDigest(), 'control: the contract pins no operation that does not say what it acts on');
        $pinned = $this->pin($watcher);
        self::assertNotNull($pinned);
        self::assertNotSame($pinned, $this->pin($this->operation(actsOn: false, scopes: ['lab:other'])), 'and its declaration is part of what it is');
        self::assertNotSame($pinned, $this->pin(), 'as is whether it says what it acts on');
    }

    public function testAHandlerGivenAsAMethodIsReadTheSameWay(): void
    {
        $pinned = $this->pin($this->operation([$this->shipped, 'method']));
        self::assertNotNull($pinned);

        file_put_contents($this->package . '/Base.php', (string) file_get_contents($this->package . '/Base.php') . "// changed\n");

        self::assertNotSame($pinned, $this->pin($this->operation([$this->shipped, 'method'])));
    }

    public function testAHouseAtAnotherFolderHoldsTheSameOperations(): void
    {
        // The pin reads what the units and the files hold, never where they are. A class cannot be loaded twice, so
        // each house is read by a process of its own: the same bytes, written under two folders.
        $script = $this->house . '/pin.php';
        file_put_contents($script, <<<'PHP'
            <?php
            require $argv[1];
            $house = $argv[2];
            mkdir($house . '/vendor/composer', 0755, true);
            mkdir($house . '/vendor/lab/thing/src', 0755, true);
            mkdir($house . '/src/Plugins/Shelf/Support', 0755, true);
            file_put_contents($house . '/vendor/composer/installed.json', json_encode(['packages' => [['name' => 'lab/thing', 'version' => '1.0.0', 'dist' => ['reference' => 'ref-1'], 'install-path' => '../lab/thing']]]));
            file_put_contents($house . '/vendor/lab/thing/src/Shipped.php', "<?php\nfinal class Shipped\n{\n    public function handler(): \\Closure\n    {\n        return fn (array \$input): array => ['ok' => true];\n    }\n}\n");
            file_put_contents($house . '/src/Plugins/Shelf/Shelf.php', "<?php\nnamespace App\\Plugins\\Shelf;\n\nfinal class Shelf\n{\n    public function handler(): \\Closure\n    {\n        return fn (array \$input): array => ['ok' => true];\n    }\n}\n");
            file_put_contents($house . '/src/Plugins/Shelf/Support/Rules.php', "<?php\n// rules\n");
            require $house . '/vendor/lab/thing/src/Shipped.php';
            require $house . '/src/Plugins/Shelf/Shelf.php';
            $operation = static fn (object $provider): Milpa\Command\Operation => new Milpa\Command\Operation('lab:deliver', 'Lab', $provider->handler(), scopes: ['lab:deliver'], effects: Milpa\Command\Effect\EffectProfile::readOnly());
            echo json_encode([
                Milpa\AppRuntime\Standing\OperationPin::of($operation(new Shipped()), $house),
                Milpa\AppRuntime\Standing\OperationPin::of($operation(new App\Plugins\Shelf\Shelf()), $house),
            ]);
            PHP);
        $read = function (string $house) use ($script): array {
            $said = (string) shell_exec(escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg(\dirname(__DIR__, 2) . '/vendor/autoload.php') . ' ' . escapeshellarg($house) . ' 2>&1');

            return (array) json_decode($said, true) ?: [$said];
        };

        $here = $read($this->house . '/one/house');
        $there = $read($this->house . '/another/folder/altogether');

        self::assertCount(2, $here);
        self::assertStringStartsWith('sha256:', (string) $here[0], 'control: the package\'s operation is pinned — ' . json_encode($here));
        self::assertStringStartsWith('sha256:', (string) $here[1], 'control: the plugin\'s operation is pinned');
        self::assertSame($here, $there);
    }

    // ── D19 · a process speaks for an operation only when nothing its pin reads is dated at or after the second ──
    // ── it started. Asked here with the MACHINE's clock: the files are dated by the machine, and the test says  ──
    // ── in which second «this process» started, before or after them — it never waits for a second to pass.    ──

    /** What was found dated at or after the second a process that started in `$second` started — or `null`. */
    private function datedSince(int $second, ?Operation $operation = null): ?string
    {
        LabHouse::aProcessStartedIn($second);

        return OperationPin::changedSinceThisProcessStarted($operation ?? $this->operation($this->built->handler()), $this->house);
    }

    /** The last second the machine dates anything under this directory in: written, or changed in any way. */
    private static function lastDatedUnder(string $directory): int
    {
        clearstatcache();
        $last = max((int) filemtime($directory), (int) filectime($directory));
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $found) {
            $last = max($last, $found->getMTime(), $found->getCTime());
        }

        return $last;
    }

    /** The plugin's directory as the rule names it: from the house's root. */
    private function pluginFromTheRoot(): string
    {
        return substr($this->plugin, \strlen($this->house) + 1);
    }

    public function testAProcessThatStartedAfterEverythingWasWrittenSpeaksForIt(): void
    {
        self::assertNull($this->datedSince(time() + 2));
        self::assertNull($this->datedSince(time() + 2, $this->operation()));
    }

    public function testAFileWrittenInTheVerySecondAProcessStartedIsOneItCannotSpeakFor(): void
    {
        // A date is a whole second: of a file dated in the second a process started, nobody can say on which side
        // of the start it was written. One second later, it can be said.
        $last = self::lastDatedUnder($this->plugin);

        self::assertNotNull($this->datedSince($last), 'a file dated in the second the process started was taken as written before it');
        self::assertNotNull($this->datedSince($last - 1));
        self::assertNull($this->datedSince($last + 1), 'control: a process that started the second after speaks for it');
    }

    /** @return iterable<string, array{string, ?string}> what is dated after the process started, and what the rule names — from the plugin's directory — or null: it is not dated */
    public static function whatOfAPluginIsDated(): iterable
    {
        yield 'its own file' => ['/{plugin}.php', '/{plugin}.php'];
        yield 'a PHP file of it the handler never names' => ['/Support/Rules.php', '/Support/Rules.php'];
        yield 'its directory — where a file is added or taken away' => ['', ''];
        yield 'a directory under it' => ['/Support', '/Support'];
        yield 'a file of it that is not PHP — said: it is in no pin' => ['/notes.txt', null];
        yield 'the directory its directory is in — every other plugin is added there' => ['/..', null];
    }

    #[DataProvider('whatOfAPluginIsDated')]
    public function testOfAPluginEveryDirectoryAndEveryPhpFileIsDated(string $what, ?string $named): void
    {
        // Asked of two operations of the plugin: one written in its own file, at the top of its directory, and one
        // written in a class two levels down — the unit is the plugin's whole directory for both.
        $rules = new (substr($this->built::class, 0, (int) strrpos($this->built::class, '\\')) . '\\Support\\Rules')();
        $start = time() + 5;
        self::assertNull($this->datedSince($start), 'control: nothing is dated after it yet');
        self::assertNull($this->datedSince($start, $this->operation($rules->handler())), 'control: nothing is dated after it yet');

        touch($this->plugin . str_replace('{plugin}', basename($this->plugin), $what), $start);

        $expected = $named === null ? null : $this->pluginFromTheRoot() . str_replace('{plugin}', basename($this->plugin), $named);
        self::assertSame($expected, $this->datedSince($start));
        self::assertSame($expected, $this->datedSince($start, $this->operation($rules->handler())));
    }

    /** @return iterable<string, array{string, ?string}> what is dated after the process started, and what the rule names — or null: it is not dated */
    public static function whatOfAPackageIsDated(): iterable
    {
        yield 'what the house wrote down that it installed' => ['vendor/composer/installed.json', 'vendor/composer/installed.json'];
        yield 'the file the handler is written in' => ['vendor/lab/thing/src/Provider.php', 'vendor/lab/thing/src/Provider.php'];
        yield 'the file of its parent' => ['vendor/lab/thing/src/Base.php', 'vendor/lab/thing/src/Base.php'];
        yield 'the file of its trait' => ['vendor/lab/thing/src/Helps.php', 'vendor/lab/thing/src/Helps.php'];
        yield 'the directory those files are in' => ['vendor/lab/thing/src', 'vendor/lab/thing/src'];
        yield 'a file of the package its class is not written in — said: a package is pinned by what was installed' => ['vendor/lab/thing/src/Service.php', null];
        yield 'a file of the house that is no unit of the operation' => ['src/Plugins', null];
    }

    #[DataProvider('whatOfAPackageIsDated')]
    public function testOfAPackageWhatWasInstalledAndTheFilesOfTheHandlersClassAreDated(string $what, ?string $named): void
    {
        $start = time() + 5;
        self::assertNull($this->datedSince($start, $this->operation()), 'control: nothing is dated after it yet');

        touch($this->house . '/' . $what, $start);

        self::assertSame($named, $this->datedSince($start, $this->operation()));
    }

    public function testAFileWhoseDateWasSetBackIsDatedWhenThatWasDone(): void
    {
        // What `cp -p`, `rsync -a`, `mv` and `touch -d` leave: a file — and its directory — that say they were
        // written long ago. The machine also keeps when anything about a file last CHANGED, and nobody sets that
        // one: a process that started before the file arrived does not speak for it.
        $arrived = self::lastDatedUnder($this->plugin);
        foreach ([$this->plugin . '/Support/Rules.php', $this->plugin . '/Support', $this->plugin . '/' . basename($this->plugin) . '.php', $this->plugin] as $path) {
            touch($path, $arrived - 1000);
        }
        clearstatcache();
        self::assertSame($arrived - 1000, max(filemtime($this->plugin), filemtime($this->plugin . '/Support'), filemtime($this->plugin . '/Support/Rules.php'), filemtime($this->plugin . '/' . basename($this->plugin) . '.php')), 'control: every one of them says it was written long ago');

        self::assertNotNull($this->datedSince($arrived - 500), 'a file moved into place with the date it had was taken as older than the process');
        self::assertNull($this->datedSince(time() + 2), 'control: for a process that starts after it arrived, it is old');
    }

    public function testTheMachineDatesADirectoryWhenAFileIsAddedToItTakenFromItOrRenamedInIt(): void
    {
        // What the rule leans on to see a file that is gone, and one moved over another: the machine's own date of
        // the directory. Asked of the machine, not of the rule.
        $directory = $this->plugin . '/Support';
        $dated = static function () use ($directory): int {
            clearstatcache();

            return (int) filemtime($directory);
        };
        foreach ([
            'a file is added' => static fn (): mixed => file_put_contents($directory . '/New.php', "<?php\n"),
            'a file is renamed over another' => static fn (): bool => rename($directory . '/New.php', $directory . '/Rules.php'),
            'a file is taken away' => static fn (): bool => unlink($directory . '/Rules.php'),
        ] as $when => $change) {
            touch($directory, 1_000_000_000);
            self::assertSame(1_000_000_000, $dated());
            $change();
            self::assertGreaterThan(1_000_000_000, $dated(), 'the machine did not date the directory when ' . $when);
        }
    }

    public function testWhatIsOutsideTheHouseIsNamedByItsNameAlone(): void
    {
        // A package may be installed from a folder beside the house. A sentence a seat reads, and a line of the book,
        // never carry where that is.
        $outside = sys_get_temp_dir() . '/milpa-beside-' . bin2hex(random_bytes(6));
        $name = 'Beside' . bin2hex(random_bytes(6));
        mkdir($outside, 0o755, true);
        try {
            file_put_contents($outside . '/Tool.php', "<?php\nfinal class {$name}\n{\n    public function handler(): \\Closure\n    {\n        return fn (array \$input): array => ['ok' => true];\n    }\n}\n");
            require $outside . '/Tool.php';
            $this->installed('ref-1', ['name' => 'lab/beside', 'version' => '1.0.0', 'dist' => ['reference' => 'r'], 'install-path' => $outside]);
            $operation = $this->operation((new $name())->handler());
            $start = time() + 5;
            self::assertNotNull($this->pin($operation), 'control: it is pinned');
            self::assertNull($this->datedSince($start, $operation));

            touch($outside . '/Tool.php', $start);

            self::assertSame('Tool.php', $this->datedSince($start, $operation));
        } finally {
            exec('rm -rf ' . escapeshellarg($outside));
        }
    }

    public function testOfAnOperationThatCannotBePinnedNothingIsSaid(): void
    {
        // Nothing is written under the pin of an operation that has none: there is nothing to speak for.
        $unpinned = $this->operation(static fn (array $input): array => ['ok' => true]);

        self::assertNull($this->pin($unpinned), 'control');
        self::assertNull($this->datedSince(0, $unpinned));
    }

    public function testAProcessReadsTheSecondItStartedInOnceAndKeepsIt(): void
    {
        $was = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
        try {
            LabHouse::aProcessStartedIn(null);
            $_SERVER['REQUEST_TIME_FLOAT'] = 1_700_000_000.9;
            self::assertSame(1_700_000_000, OperationPin::processStarted());
            // A process that answers many requests is told, at each, when THAT one began.
            $_SERVER['REQUEST_TIME_FLOAT'] = 1_800_000_000.0;
            self::assertSame(1_700_000_000, OperationPin::processStarted(), 'the second a process started in moved with the request it was answering');

            LabHouse::aProcessStartedIn(null);
            unset($_SERVER['REQUEST_TIME_FLOAT']);
            $before = time();
            self::assertGreaterThanOrEqual($before, OperationPin::processStarted(), 'a process that is not told when it started takes the second it is asked in');
            self::assertLessThanOrEqual(time(), OperationPin::processStarted());
        } finally {
            $_SERVER['REQUEST_TIME_FLOAT'] = $was;
        }
    }

    public function testItIsAskedWhereAProcessBuildsItsBoundary(): void
    {
        // …so that the one kept is the second the process started in, and not the second of the first request that
        // came to need it.
        $was = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
        try {
            LabHouse::aProcessStartedIn(null);
            $_SERVER['REQUEST_TIME_FLOAT'] = 1_700_000_000.9;
            new \Milpa\AppRuntime\Agent\PluginAuthoringPolicy($this->house);
            $_SERVER['REQUEST_TIME_FLOAT'] = 1_800_000_000.0;

            self::assertSame(1_700_000_000, OperationPin::processStarted());
        } finally {
            $_SERVER['REQUEST_TIME_FLOAT'] = $was;
        }
    }
}
