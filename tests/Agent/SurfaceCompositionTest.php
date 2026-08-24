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

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\SurfaceBroadcaster;
use Milpa\AppRuntime\Agent\SurfaceComposition;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\TestCase;

/**
 * A chat opens a screen that is itself a surface; an app may ALSO declare a realtime transport (a
 * Surface registers one from config). They are audiences of the same fact, not rivals for the
 * channel (greenhouse evidence/0294): composing them must fan the fact out to BOTH, and must not
 * throw when a transport already occupies SurfaceBroadcaster::class — the exact collision a real
 * `coa chat` with transport: websocket hit under the pin method.
 */
final class SurfaceCompositionTest extends TestCase
{
    public function testItFansTheScreenOutWithATransportASurfaceAlreadyDeclared(): void
    {
        $container = new DIContainer();
        $transport = new TallyingSurface();               // what BoardPlugin registered from config
        $container->registerService(SurfaceBroadcaster::class, $transport);
        $screen = new TallyingSurface();

        SurfaceComposition::compose($container, $screen);   // must NOT throw (registerService would)

        $composed = $container->get(SurfaceBroadcaster::class);
        self::assertInstanceOf(SurfaceBroadcaster::class, $composed);
        $composed->broadcast('milpa/sessions/x', ['a' => 1]);
        self::assertSame(1, $screen->count, 'the screen still receives the fact');
        self::assertSame(1, $transport->count, 'and so does the declared transport — both audiences');
    }

    public function testItLeavesTheScreenAloneWhenNoTransportWasDeclared(): void
    {
        $container = new DIContainer();
        $screen = new TallyingSurface();

        SurfaceComposition::compose($container, $screen);

        self::assertSame($screen, $container->get(SurfaceBroadcaster::class), 'a single audience is the screen itself, not a wrapper');
    }

    public function testItWrapsABareMercureHubWhenNoSurfaceDeclaredATransport(): void
    {
        $container = new DIContainer();
        $hub = new CompositionFakeHub();                        // an app that wired a hub directly, no Surface
        $container->registerService('Milpa\\Mercure\\MercureService', $hub);
        $screen = new TallyingSurface();

        SurfaceComposition::compose($container, $screen);

        $composed = $container->get(SurfaceBroadcaster::class);
        $composed->broadcast('milpa/sessions/x', ['b' => 2]);
        self::assertSame(1, $screen->count, 'the screen receives the fact');
        self::assertSame(1, $hub->published, 'the bare hub is wrapped and receives it too');
    }
}

final class TallyingSurface implements SurfaceBroadcaster
{
    public int $count = 0;

    public function broadcast(string $topic, array $payload): void
    {
        $this->count++;
    }
}

final class CompositionFakeHub
{
    public int $published = 0;

    /** @param array<string, mixed> $data */
    public function publish(string $topic, array $data): void
    {
        $this->published++;
    }
}
