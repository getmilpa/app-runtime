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

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\FanOutBroadcaster;
use Milpa\AppRuntime\Agent\SurfaceBroadcaster;
use PHPUnit\Framework\TestCase;

/**
 * One fact, every watching surface — and no surface can take the push away from another.
 *
 * The defect this guards was found by a human watching the browser: the chat TUI registered itself
 * as THE broadcaster, so the web board went still the moment a session ran inside the chat, and
 * only a reload showed the outcome. The bridge is one; the audiences are many.
 */
final class FanOutBroadcasterTest extends TestCase
{
    /** Every surface receives the same fact, in registration order. */
    public function testEverySurfaceReceivesTheSameFact(): void
    {
        $a = new RecordingSurface();
        $b = new RecordingSurface();

        (new FanOutBroadcaster([$a, $b]))->broadcast('milpa/sessions/s1', ['kind' => 'card']);

        self::assertSame([['milpa/sessions/s1', ['kind' => 'card']]], $a->received);
        self::assertSame([['milpa/sessions/s1', ['kind' => 'card']]], $b->received);
    }

    /**
     * A dead hub must not blind the terminal, nor the other way around: each surface is delivered
     * to even when another throws — and the failure still surfaces afterwards, so the bridge's
     * warning fires instead of a silent half-delivery.
     */
    public function testOneFailingSurfaceNeitherStopsTheRestNorGoesSilent(): void
    {
        $dead = new class () implements SurfaceBroadcaster {
            public function broadcast(string $topic, array $payload): void
            {
                throw new \RuntimeException('hub down');
            }
        };
        $living = new RecordingSurface();

        $fanOut = new FanOutBroadcaster([$dead, $living]);

        try {
            $fanOut->broadcast('milpa/sessions/s1', ['kind' => 'card']);
            self::fail('the failure was swallowed — the bridge would never log it');
        } catch (\RuntimeException $e) {
            self::assertSame('hub down', $e->getMessage());
        }

        self::assertCount(1, $living->received, 'the living surface was skipped because a dead one threw first');
    }
}

/** Remembers what reached it — the test double the port's own docblock prescribes. */
final class RecordingSurface implements SurfaceBroadcaster
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $received = [];

    public function broadcast(string $topic, array $payload): void
    {
        $this->received[] = [$topic, $payload];
    }
}
