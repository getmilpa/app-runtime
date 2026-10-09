<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Config;

use Milpa\AppRuntime\Config\AgentKeys;
use Milpa\AppRuntime\Config\Containment;
use Milpa\AppRuntime\Operations\ConfigOperations;
use Milpa\Command\Operation;
use PHPUnit\Framework\TestCase;

/**
 * A house DECLARES whether it runs contained, and by what (greenhouse decisions/0607, annex, slice 1).
 *
 * decisions/0606 chose the posture: a house runs contained — a container, a dedicated user — so that what a trial
 * can read is what that holds, on every machine. Nothing in a house said whether it does. Now one key does, and its
 * default says today's truth: not contained. The house does not check it; it is a person's statement about where
 * they put it, and what `coa doctor` reads.
 *
 * @guards the key is one the house lists, types and writes; only `container` and `user` are read as contained;
 *         absent and `false` are «not contained»; the machine's own file is read over the human's
 *
 * @refuses a house read as contained because something truthy was written; a value `config:set` would take that
 *          names nothing that holds a house
 */
final class AHouseSaysWhetherItRunsContainedTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/contained-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/.milpa', 0o777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/.milpa/agent.json');
        @rmdir($this->root . '/.milpa');
        @rmdir($this->root);
    }

    public function testTheKeyIsOneTheHouseListsWithItsDefaultSaid(): void
    {
        $keys = AgentKeys::todas();

        self::assertArrayHasKey(Containment::KEY, $keys);
        self::assertSame('agent.contained', Containment::KEY);
        self::assertSame("false | 'container' | 'user'", $keys[Containment::KEY]['type']);
        self::assertStringContainsString('absent or false, nothing does', $keys[Containment::KEY]['does'], 'the default says today\'s truth');
        self::assertStringContainsString('The house does not check it', $keys[Containment::KEY]['does'], 'and that it is a statement, not a measurement');
    }

    public function testOnlyAContainerOrADedicatedUserIsReadAsContained(): void
    {
        self::assertSame('container', Containment::of(['agent' => ['contained' => 'container']], $this->root)->by());
        self::assertSame('user', Containment::of(['agent' => ['contained' => 'user']], $this->root)->by());

        foreach ([[], ['agent' => []], ['agent' => ['contained' => false]], ['agent' => ['contained' => null]]] as $notContained) {
            $said = Containment::of($notContained, $this->root);
            self::assertNull($said->by());
            self::assertNull($said->unreadable(), 'not contained is the default: nothing to remark');
        }

        foreach ([true, 'yes', 'docker', 'Container', 1, ['container']] as $namesNothing) {
            $said = Containment::of(['agent' => ['contained' => $namesNothing]], $this->root);
            self::assertNull($said->by(), json_encode($namesNothing) . ' does not say what holds the house');
            self::assertSame(json_encode($namesNothing), $said->unreadable());
        }
    }

    public function testTheMachinesOwnFileIsReadOverTheHumans(): void
    {
        file_put_contents($this->root . '/.milpa/agent.json', '{"agent":{"contained":"user"}}');

        self::assertSame('user', Containment::of(['agent' => ['contained' => 'container']], $this->root)->by());
        self::assertSame('user', Containment::of([], $this->root)->by());
    }

    public function testConfigSetWritesItTypedAndRefusesAValueThatNamesNothing(): void
    {
        $set = $this->op('config:set')->handler;

        $written = $set(['key' => 'agent.contained', 'value' => 'container']);
        self::assertTrue($written['ok'] ?? false, json_encode($written) ?: '');
        self::assertArrayNotHasKey('unknown_key', $written, 'the house knows this key');
        self::assertSame('container', Containment::of([], $this->root)->by(), 'what was written is what is read');

        $refused = $set(['key' => 'agent.contained', 'value' => 'docker']);
        self::assertFalse($refused['ok'] ?? true);
        self::assertSame('container', Containment::of([], $this->root)->by(), 'a refused write changed nothing');

        $back = $set(['key' => 'agent.contained', 'value' => false]);
        self::assertTrue($back['ok'] ?? false, json_encode($back) ?: '');
        self::assertNull(Containment::of([], $this->root)->by(), 'and a house can say it is no longer contained');
    }

    /**
     * THE FIRST TWO SLICES ONLY SAY (greenhouse decisions/0607, annex §4). The declaration is a person's statement
     * and nothing the house checks; if anything read it to decide what runs — a trial that widens for a «contained»
     * house, a refusal that lifts — a word in a file would be buying authority. So: one reader of the key in the
     * whole package, and one caller of that reader, the doctor.
     */
    public function testNothingButTheDoctorReadsTheDeclaration(): void
    {
        $src = \dirname(__DIR__, 2) . '/src';
        $readers = [];
        $callers = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            $name = substr($file->getPathname(), \strlen($src) + 1);
            if (preg_match("/get\\(\\s*'agent\\.contained'/", $code) === 1 || str_contains($code, 'Containment::KEY)')) {
                $readers[] = $name;
            }
            if ($name !== 'Config/Containment.php' && preg_match('/\\bContainment::/', $code) === 1) {
                $callers[] = $name;
            }
        }

        self::assertSame(['Config/Containment.php'], $readers, 'the key is read in one place');
        self::assertSame(['Console/Application.php'], $callers, 'and that place is asked by the doctor alone');
    }

    private function op(string $nombre): Operation
    {
        foreach (ConfigOperations::para($this->root)->operations() as $op) {
            if ($op->name === $nombre) {
                return $op;
            }
        }

        self::fail("no existe la operación «{$nombre}»");
    }
}
