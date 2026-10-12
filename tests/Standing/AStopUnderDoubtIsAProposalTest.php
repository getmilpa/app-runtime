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

use Milpa\Command\Standing\GrantState;
use PHPUnit\Framework\TestCase;

/**
 * A PROPOSAL OF THE CONTRACT'S SEVENTH REVIEW, NOT DECIDED (greenhouse decisions/0615): under doubt, a stop stops —
 * asked here by the door a seat works through. If the proposal is taken out of milpa/command, this file goes with it.
 *
 * A narrow grant fixes the channel and is stopped; a wider grant is in force; the seat spells the same act without
 * the channel. The contract's judge used to say the stopped grant «does not name» that call, and the wide one lent it.
 */
final class AStopUnderDoubtIsAProposalTest extends TestCase
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

    private function measured(string $name): string
    {
        $path = $this->house->candidate($name, "clean release\n");
        $this->house->as(LabHouse::SEAT, 'lab:scan', ['path' => $path]);

        return $path;
    }

    public function testAWiderGrantDoesNotLendTheActAStoppedGrantMayBeNaming(): void
    {
        $this->house->granted(); // «release-line»: publish with the channel fixed to "stable", merge, announce
        $this->house->granted(['id' => 'any-release', 'covers' => [['operation' => 'lab:publish']]]);
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $this->measured('a.txt')]), 'control: both in force, the call that says no channel runs');

        $this->house->as(LabHouse::SEAT, 'standing:suspend', ['grant' => 'release-line', 'reason' => 'a published release names a machine']);
        self::assertSame(GrantState::Suspended, $this->house->book()->record('release-line')?->state);
        self::assertSame(GrantState::InForce, $this->house->book()->record('any-release')?->state);
        $published = \count($this->house->done('published'));

        // Spelled as the stopped grant spells it: refused, as it always was.
        self::assertStringContainsString('«release-line» is suspended', (string) ($this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $this->measured('b.txt'), 'channel' => 'stable'])['refused'] ?? 'RAN'));
        // The same act, spelled without the channel: the wide grant would lend it, and the stop stands in the way.
        $refused = (string) ($this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $this->measured('c.txt')])['refused'] ?? 'RAN');
        self::assertStringContainsString('Standing grant «release-line» is suspended, and it may be the one that names this call', $refused);
        self::assertCount($published, $this->house->done('published'), 'the act ran');
        // A call that SAYS another channel is certainly another act: the wide grant lends it.
        self::assertSame(['ok' => true], $this->house->as(LabHouse::SEAT, 'lab:publish', ['path' => $this->measured('d.txt'), 'channel' => 'beta']));
    }
}
