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

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Agent\SessionIdentity;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\TestCase;

/**
 * A key bootstrapped or enrolled into the store must be admissible even with an empty config root and no
 * policy — otherwise identity:bootstrap self-enrolls a key that nothing then admits (greenhouse
 * evidence/0384, found on a live desktop backend). Admission is built when there is ANY basis for
 * recognition: a policy, a declared root, OR standing enrollments.
 */
final class IdentityBuiltFromEnrollmentsTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            @unlink($d . '/storage/identity/enrollments.json');
            @rmdir($d . '/storage/identity');
            @rmdir($d . '/storage');
            @rmdir($d);
        }
    }

    public function testIdentityIsBuiltWhenOnlyEnrollmentsExist(): void
    {
        $root = sys_get_temp_dir() . '/milpa-enr-' . bin2hex(random_bytes(4));
        mkdir($root . '/storage/identity', 0o777, true);
        // No config/identity.php (empty root), no config/policy.php — only a standing enrollment.
        file_put_contents($root . '/storage/identity/enrollments.json', (string) json_encode([
            'ABCD' => ['scopes' => ['agent:read'], 'authorized_by' => 'bootstrap'],
        ]));
        $this->dirs[] = $root;

        [$provider, $identity] = $this->policyAndIdentity($root);

        self::assertNull($provider, 'no PolicyProvider is declared');
        self::assertInstanceOf(SessionIdentity::class, $identity, 'a standing enrollment is a basis for admission');
    }

    public function testIdentityIsNullWithNoBasisAtAll(): void
    {
        $root = sys_get_temp_dir() . '/milpa-enr-' . bin2hex(random_bytes(4));
        mkdir($root . '/storage/identity', 0o777, true);
        // Empty store, no root config, no policy — the app opts out of identity.
        file_put_contents($root . '/storage/identity/enrollments.json', '{}');
        $this->dirs[] = $root;

        [, $identity] = $this->policyAndIdentity($root);

        self::assertNull($identity, 'no policy, no root, no enrollment is no identity system');
    }

    /** @return array{0: mixed, 1: ?SessionIdentity} */
    private function policyAndIdentity(string $root): array
    {
        $ops = new AgentOperations(new DIContainer());
        $m = (new \ReflectionClass($ops))->getMethod('policyAndIdentity');
        $m->setAccessible(true);

        /** @var array{0: mixed, 1: ?SessionIdentity} */
        return $m->invoke($ops, $root);
    }
}
