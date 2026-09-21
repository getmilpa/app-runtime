<?php

/**
 * This file is part of milpa/app-runtime.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\PrerequisiteGate;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Operation;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The live gate and durable fold must agree when an operation returned an error. */
final class PrerequisiteOutcomeTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function failures(): iterable
    {
        yield 'operation error' => ['{"ok":false,"error":"unknown skill"}', true];
        yield 'dispatch error' => ['failed', false];
        yield 'invalid success flag' => ['{"ok":"false"}', true];
        yield 'confirmation only' => ['{"requires_confirmation":true}', true];
    }

    /** Both gates stay closed until the successful result, then remain open after another failure. */
    #[DataProvider('failures')]
    public function testARejectedLoadCannotOpenTheTable(string $result, bool $ok): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s', 'Read the guide first', AutonomyMode::Auto);
        $store->requireFirst('s', ['skill_load']);
        $session = $store->load('s');
        self::assertNotNull($session);
        $gate = new SessionToolGate($store, $session, [
            new Operation('source:read', 'Read source', static fn (): array => ['ok' => true], effects: EffectProfile::readOnly()),
        ], compuertaPrevia: new PrerequisiteGate($session->runFirst));
        $gate->recorded('skill_load', [], $result, $ok);
        self::assertNotNull($gate->refuse('source_read', []));
        self::assertSame(['skill_load'], $store->load('s')?->runFirst);

        $gate->recorded('skill_load', [], '{"ok":true,"body":"guide"}', true);
        self::assertNull($gate->refuse('source_read', []));
        self::assertSame([], $store->load('s')?->runFirst);
        $gate->recorded('skill_load', [], $result, $ok);
        self::assertNull($gate->refuse('source_read', []));
        self::assertSame([], $store->load('s')?->runFirst);
    }
}
