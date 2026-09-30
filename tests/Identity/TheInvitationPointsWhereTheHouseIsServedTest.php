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

namespace Milpa\AppRuntime\Tests\Identity;

use Milpa\AppRuntime\Identity\FirstHuman;
use Milpa\AppRuntime\Web\PasskeyPlugin;
use PHPUnit\Framework\TestCase;

/**
 * The invitation's URL points where the house is served (greenhouse decisions/0534). Inside the Desktop's
 * container `identity:invite` printed `url: http://localhost:8000/…` while the house answered on :8899
 * (evidence/1068): a link to a port where nothing — or another house — listens. When the process serving the
 * house declares its origin, the URL uses it; when nobody declares one, it stays `coa serve`'s default.
 */
final class TheInvitationPointsWhereTheHouseIsServedTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-invite-url-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/storage/identity', 0o777, true);
    }

    protected function tearDown(): void
    {
        putenv(PasskeyPlugin::SERVED_ORIGINS_ENV);
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testWithoutADeclarationTheUrlIsWhereCoaServeAnswers(): void
    {
        putenv(PasskeyPlugin::SERVED_ORIGINS_ENV);

        $invite = FirstHuman::invite($this->root, 'key:TEST', ['identity:enroll']);

        self::assertSame('http://localhost:8000' . $invite['path'], $invite['url']);
    }

    public function testTheUrlIsTheFirstOriginTheServingProcessDeclared(): void
    {
        putenv(PasskeyPlugin::SERVED_ORIGINS_ENV . '=http://localhost:8899, https://localhost');

        $invite = FirstHuman::invite($this->root, 'key:TEST', ['identity:enroll']);

        self::assertStringStartsWith('/webauthn/enroll?invite=', $invite['path'], 'the path is the fact, unchanged');
        self::assertSame('http://localhost:8899' . $invite['path'], $invite['url']);
    }
}
