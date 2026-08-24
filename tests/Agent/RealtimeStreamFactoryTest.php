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

use Milpa\AppRuntime\Agent\RealtimeStreamFactory;
use Milpa\AppRuntime\Agent\SurfaceBroadcaster;
use PHPUnit\Framework\TestCase;

/**
 * A surface gets its live feed by CONFIG, not by editing boot.php (greenhouse evidence/0289). The
 * factory is the ONE place that maps a declared transport to its adapter — a surface names «realtime»,
 * never «mercure».
 */
final class RealtimeStreamFactoryTest extends TestCase
{
    public function testItBuildsTheBroadcasterAConfiguredTransportDeclares(): void
    {
        $b = RealtimeStreamFactory::fromConfig([
            'transport' => 'mercure',
            'hub' => 'http://localhost:3999/.well-known/mercure',
            'public' => 'http://localhost:3999/.well-known/mercure',
            'key' => 'a-shared-secret',
        ]);

        self::assertInstanceOf(SurfaceBroadcaster::class, $b, 'a declared mercure stream becomes the neutral contract');
    }

    public function testAnUnknownOrIncompleteTransportBuildsNothing(): void
    {
        self::assertNull(RealtimeStreamFactory::fromConfig(['transport' => 'carrier-pigeon']));
        self::assertNull(RealtimeStreamFactory::fromConfig(['transport' => 'mercure', 'hub' => 'http://h']), 'no key: nothing');
        self::assertNull(RealtimeStreamFactory::fromConfig([]), 'no transport: nothing');
    }

    public function testThePublicUrlIsTheBrowserReachableHub(): void
    {
        self::assertSame('http://pub', RealtimeStreamFactory::publicUrlFromConfig(['public' => 'http://pub', 'hub' => 'http://internal']));
        self::assertSame('http://internal', RealtimeStreamFactory::publicUrlFromConfig(['hub' => 'http://internal']), 'public defaults to hub');
        self::assertNull(RealtimeStreamFactory::publicUrlFromConfig(null));
    }
}
