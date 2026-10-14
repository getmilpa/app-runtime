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

use Milpa\AppRuntime\Standing\StandingLedger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * WHAT THE TENTH REVIEW OF THE CONTRACT ASKS OF A HOUSE, FROM TODAY (greenhouse decisions/0615). Its three findings
 * of low severity are not fixed in the contract that is published as 0.30.2: they are known, and planned for the
 * next version. Until then a house does four things itself, and each is asked here.
 */
final class WhatTheContractsTenthReviewAsksOfAHouseTest extends TestCase
{
    private LabHouse $house;

    protected function setUp(): void
    {
        $this->house = new LabHouse();
    }

    protected function tearDown(): void
    {
        $this->house->destroy();
    }

    /** L1 · whoever signs sees a fixed value WHOLE — never only the line a refusal would say back. */
    public function testWhoeverSignsSeesAFixedValueWhole(): void
    {
        // Two values a grant can fix that share their first 96 bytes: the contract's `shown()` says both back as the
        // same line, cut after 95 bytes with the length it had.
        $shared = 'https://releases.example.org/pool/' . str_repeat('a', 62);
        self::assertSame(96, \strlen($shared));
        [$one, $other] = [$shared . 'to-the-stable-shelf', $shared . 'to-another-shelf!!!'];
        self::assertSame(\strlen($one), \strlen($other));
        self::assertSame(\Milpa\Command\Standing\Canonical::shown($one), \Milpa\Command\Standing\Canonical::shown($other), 'control: the contract shows the two as one line');

        $given = [];
        foreach (['first' => $one, 'second' => $other] as $id => $target) {
            $given[$id] = $this->house->signed(LabHouse::PERSON, 'standing:grant', ['grant' => $this->house->document(['id' => $id, 'covers' => [['operation' => 'lab:publish', 'arguments' => ['channel' => $target]]]])]);
            self::assertTrue($given[$id]['ok'] ?? false, (string) json_encode($given[$id]));
        }
        // What the house answers to the person who has just signed…
        self::assertSame('lab:publish (channel="' . $one . '")', $given['first']['covers'][0]);
        self::assertSame('lab:publish (channel="' . $other . '")', $given['second']['covers'][0]);
        // …and what it lists for whoever comes to read the book before signing the next one.
        $listed = array_column($this->house->as(null, 'standing:granted')['grants'], 'covers', 'grant');
        self::assertSame('lab:publish (channel="' . $one . '")', $listed['first'][0]);
        self::assertSame('lab:publish (channel="' . $other . '")', $listed['second'][0]);
        self::assertNotSame($listed['first'][0], $listed['second'][0]);
    }

    /** …and it is still the value AS IT IS SPELLED: the word and the answer, a number and its digits, are two. */
    public function testAFixedValueShownWholeIsStillShownAsItIsSpelled(): void
    {
        $this->house->granted(['conditions' => [['name' => 'no-host-names', 'statement' => 'what is published names no machine', 'by' => ['operation' => 'lab:scan', 'arguments' => ['strict' => true, 'mode' => 'true', 'level' => 7, 'tag' => '7', 'note' => 'a "quoted" \\ word'], 'free' => ['path']]]]]);

        $listed = $this->house->as(null, 'standing:granted')['grants'][0];

        self::assertStringEndsWith('measured by lab:scan (level=7, mode="true", note="a \\"quoted\\" \\\\ word", strict=true, tag="7", path: any value)', $listed['conditions'][0]);
    }

    /** L2 · a seat is handed to the contract as text, from files that declare strict_types. */
    public function testEveryFileOfThisPackageDeclaresStrictTypes(): void
    {
        $loose = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2) . '/src', \FilesystemIterator::SKIP_DOTS));
        $read = 0;
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            ++$read;
            if (!str_contains((string) file_get_contents($file->getPathname()), 'declare(strict_types=1);')) {
                $loose[] = $file->getPathname();
            }
        }
        self::assertGreaterThan(300, $read, 'control: the source was read');
        self::assertSame([], $loose, 'from a file in PHP\'s default mode a boolean handed in as a seat is the seat "1"');
    }

    /** L3 · this house asks the door for several grants, always — also when it holds one. */
    public function testTheHouseNeverAsksTheDoorForOneGrant(): void
    {
        $asks = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            // The CODE of the file, its comments left out: a docblock that names a door does not ask it.
            $code = implode('', array_map(
                static fn (\PhpToken $token): string => $token->is([\T_COMMENT, \T_DOC_COMMENT]) ? '' : $token->text,
                \PhpToken::tokenize((string) file_get_contents($file->getPathname())),
            ));
            if (preg_match_all('/(?<![A-Za-z_\\\\])StandingJudge::(\w+)\(/', $code, $found) > 0) {
                foreach ($found[1] as $door) {
                    $asks[$door] = true;
                }
            }
        }
        ksort($asks);

        self::assertSame(['among', 'mayBeNaming'], array_keys($asks), 'the door for one grant does not hear a stop beside the call, nor the whole record');
    }

    /** @return iterable<string, array{mixed}> what an instrument answered as `ok` that is not the boolean true */
    public static function answersThatAreNotYes(): iterable
    {
        yield 'false' => [false];
        yield 'the number zero' => [0];
        yield 'the word false' => ['false'];
        yield 'the word no' => ['no'];
        yield 'the number 2' => [2];
        yield 'the WORD true: only the answer true is a yes' => ['true'];
        yield 'nothing' => [null];
    }

    /** N1 · the path that writes a receipt never hands the contract a «no» it would throw on: it writes it red. */
    #[DataProvider('answersThatAreNotYes')]
    public function testANoSaidAnyWayIsWrittenAsARedReceiptAndNeverLost(mixed $answered): void
    {
        $this->house->granted();
        $path = $this->house->candidate('candidate.txt', "clean release\n");
        $this->house->scanner = static fn (string $bytes): array => ['ok' => $answered];
        $before = \count($this->house->lines(StandingLedger::MEASURED));

        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);

        $receipts = $this->house->lines(StandingLedger::MEASURED);
        self::assertCount($before + 1, $receipts, 'the run left no receipt: the green before it would go on speaking');
        self::assertFalse(end($receipts)['ok']);
        self::assertStringContainsString('measured it and said no', (string) ($this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $path, 'channel' => 'stable'])['refused'] ?? 'RAN'));
    }
}
