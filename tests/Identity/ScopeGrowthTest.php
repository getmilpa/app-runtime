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

use Milpa\AppRuntime\Identity\EnrollmentLine;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\AppRuntime\Identity\ScopeGrowth;
use PHPUnit\Framework\TestCase;

/**
 * A recognition grows with what its holder installs, keeps who answers for it, and says why it grew
 * (greenhouse decisions/0498).
 */
final class ScopeGrowthTest extends TestCase
{
    private const KEY = 'AAAA1111BBBB2222CCCC3333DDDD4444EEEE5555';

    private const SEAT = 'FFFF1111BBBB2222CCCC3333DDDD4444EEEE5555';

    private string $path;

    private FileEnrollmentStore $ledger;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/milpa-growth-' . bin2hex(random_bytes(4)) . '.json';
        $this->ledger = new FileEnrollmentStore($this->path);
        // The human's passkey and the seat, both enrolled by the human's key (the line of decisions/0493).
        $this->ledger->record(new IdentityEnrolled('pk-1', ['milpa.admin', 'capabilities:enable'], 'key:' . self::KEY));
        $this->ledger->record(new IdentityEnrolled(self::SEAT, ['agent:run'], 'key:' . self::KEY));
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testTheInstallerGainsWhatTheCapabilityDeclaresAndTheLineHolds(): void
    {
        $granted = (new ScopeGrowth($this->ledger))->grow('passkey:pk-1', ['agent:read', 'agent:answer', 'milpa.admin'], 'milpa/agent-workspace');

        self::assertSame(['agent:read', 'agent:answer'], $granted, 'only what it did not hold');
        self::assertSame(['milpa.admin', 'capabilities:enable', 'agent:read', 'agent:answer'], $this->ledger->scopesFor('pk-1'));
        self::assertSame('key:' . self::KEY, $this->ledger->authorizedBy('pk-1'), 'the enroller still answers for it');

        $entry = json_decode((string) file_get_contents($this->path), true)['pk-1'];
        self::assertSame(['principal' => 'passkey:pk-1', 'capability' => 'milpa/agent-workspace'], $entry['grown_by']);
        self::assertSame(['milpa.admin', 'capabilities:enable'], $entry['history'][0]['scopes'], 'the replaced state is history');

        // THE LINE: the passkey still answers for the seat its key enrolled (decisions/0493).
        self::assertTrue((new EnrollmentLine($this->ledger))->answersFor('passkey:pk-1', self::SEAT));
    }

    public function testTheControlASelfAuthorizedGrowthWouldCloseTheFrontierOnItsOwnHuman(): void
    {
        // Why `authorized_by` is kept: had the growth been recorded as authorized by the passkey itself,
        // the line from the key to the passkey is gone and the passkey no longer answers for the seat.
        $this->ledger->record(new IdentityEnrolled('pk-1', ['milpa.admin', 'agent:read'], 'passkey:pk-1'));

        self::assertFalse((new EnrollmentLine($this->ledger))->answersFor('passkey:pk-1', self::SEAT));
    }

    /** A scope the house grew is one more thing about a standing key, not a list somebody typed (decisions/0590). */
    public function testGrowingAKeyKeepsWhatPersonsAdmittedToIt(): void
    {
        self::assertTrue($this->ledger->admit('pk-1', 'Prestamos', 'herramientas:write', ['herramientas.prestar' => 'sha256:a'], 'key:' . self::KEY));

        self::assertNotSame([], (new ScopeGrowth($this->ledger))->grow('passkey:pk-1', ['agent:read'], 'milpa/agent-workspace'));

        self::assertArrayHasKey('herramientas:write', $this->ledger->admissionsFor('pk-1')['Prestamos'] ?? []);
    }

    public function testNothingGrowsForWhatIsNotALiveRecognitionOrHasNothingNew(): void
    {
        $growth = new ScopeGrowth($this->ledger);

        self::assertSame([], $growth->grow('key:' . self::KEY, ['agent:read'], 'x'), 'an operator key the ledger never enrolled');
        self::assertSame([], $growth->grow('passkey:pk-1', [], 'x'), 'a capability that declares nothing');
        self::assertSame([], $growth->grow('passkey:pk-1', ['milpa.admin'], 'x'), 'nothing it does not hold');
        self::assertSame([], $growth->grow('actor:someone', ['agent:read'], 'x'), 'a principal that is no ledger key');
        $this->ledger->revoke('pk-1', 'key:' . self::KEY);
        self::assertSame([], $growth->grow('passkey:pk-1', ['agent:read'], 'x'), 'a revoked one');
        self::assertNull($this->ledger->scopesFor('pk-1'));
    }
}
