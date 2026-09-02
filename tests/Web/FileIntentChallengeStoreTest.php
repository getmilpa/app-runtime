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

namespace Milpa\AppRuntime\Tests\Web;

use Milpa\AppRuntime\Agent\IntentChallengeBinding;
use Milpa\AppRuntime\Web\FileIntentChallengeStore;
use Milpa\Command\Consent\OperationId;
use PHPUnit\Framework\TestCase;

/**
 * The persistent binding that lets the browser ceremony span two requests (greenhouse decisions/0187):
 * a challenge remembers its call across the gap between issuing it and admitting the assertion, and
 * `take` is single-use so a challenge stands for exactly one admission.
 */
final class FileIntentChallengeStoreTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/milpa-ficst-' . bin2hex(random_bytes(4)) . '/bindings.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @rmdir(\dirname($this->path));
    }

    public function testABoundCallSurvivesAcrossInstances(): void
    {
        (new FileIntentChallengeStore($this->path))->bind("\x01\x02\x03", new IntentChallengeBinding(new OperationId('capabilities.enable'), ['name' => 'a2a'], 'ses-A'));

        // A fresh instance — the second request — reads the binding back from disk.
        $binding = (new FileIntentChallengeStore($this->path))->take("\x01\x02\x03");

        self::assertNotNull($binding);
        self::assertTrue($binding->operation->is('capabilities.enable'));
        self::assertSame(['name' => 'a2a'], $binding->arguments);
        self::assertSame('ses-A', $binding->session);
    }

    public function testTakeIsSingleUse(): void
    {
        $store = new FileIntentChallengeStore($this->path);
        $store->bind('c', new IntentChallengeBinding(new OperationId('config.set'), ['k' => 'v'], 'ses-A'));

        self::assertNotNull($store->take('c'));
        self::assertNull($store->take('c'), 'a challenge stands for exactly one admission');
    }

    public function testAnUnknownChallengeIsNull(): void
    {
        self::assertNull((new FileIntentChallengeStore($this->path))->take('nope'));
    }

    public function testAnExpiredBindingIsRefusedAndConsumed(): void
    {
        $store = new FileIntentChallengeStore($this->path, ttlSeconds: -1); // already expired
        $store->bind('c', new IntentChallengeBinding(new OperationId('config.set'), ['k' => 'v'], 'ses-A'));

        self::assertNull($store->take('c'), 'a stale ceremony grants nothing');
        self::assertNull($store->take('c'), 'and it was consumed, not left standing');
    }
}
