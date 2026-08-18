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

namespace Milpa\AppRuntime\Tests\Policy;

use Milpa\AppRuntime\Policy\PolicyConfig;
use Milpa\AppRuntime\Policy\PolicyProvider;
use PHPUnit\Framework\TestCase;

/**
 * The config seam of greenhouse decisions/0056: an app declares its PolicyProvider in
 * `config/policy.php`, the same reviewed-code path `config/operations.php` already walks.
 *
 * A missing file is an app that declares nothing — null, and every consumer fails closed. A file
 * that EXISTS and returns garbage is a declaration error the author must see, not a silent null
 * that would read as «no policy» when the author thought they had one.
 */
final class PolicyConfigTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/policy-config-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0o775, true);
    }

    /** 1 · no file: the app declares nothing, and that is a fact, not an error. */
    public function testAMissingFileDeclaresNothing(): void
    {
        self::assertNull(PolicyConfig::load($this->root));
    }

    /** 2 · a file returning an instance is the declaration. */
    public function testAFileReturningAnInstanceLoads(): void
    {
        file_put_contents($this->root . '/config/policy.php', '<?php return new class implements \Milpa\AppRuntime\Policy\PolicyProvider {
            public function authorityPolicy(): ?\Milpa\Command\Effect\AuthorityPolicy { return null; }
            public function scopesForSigner(string $fingerprint): ?array { return $fingerprint === "AA" ? ["probes:run"] : null; }
        };');

        $policy = PolicyConfig::load($this->root);

        self::assertInstanceOf(PolicyProvider::class, $policy);
        self::assertSame(['probes:run'], $policy->scopesForSigner('AA'));
        self::assertNull($policy->scopesForSigner('BB'));
    }

    /** 3 · a file that exists and returns garbage is a declaration error the author must see. */
    public function testAFileReturningGarbageThrows(): void
    {
        file_put_contents($this->root . '/config/policy.php', '<?php return "not a provider";');

        $this->expectException(\InvalidArgumentException::class);
        PolicyConfig::load($this->root);
    }
}
