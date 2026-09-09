<?php

/**
 * This file is part of Milpa App Runtime — the agent runtime a Milpa app installs.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Live;

use Milpa\AppRuntime\Live\PresentationOverrideStore;
use Milpa\AppRuntime\Operations\PresentationOverrideOperations;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use PHPUnit\Framework\TestCase;

/**
 * An override happens because a human said so, and the record keeps who said it apart from who did it.
 *
 * The failure this exists to make impossible is the quiet one: a package that could restyle another
 * package's component by declaring it would have done the effect before anybody was asked, and the
 * ceremony would be theatre performed after the fact (greenhouse `decisions/0246` §2).
 */
final class PresentationOverrideStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-override-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/resources', 0o775, true);
        file_put_contents($this->root . '/resources/skin.css', '.star { color: red; }');
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->root . '/{,*/}*', \GLOB_BRACE) as $path) {
            if (is_file((string) $path)) {
                unlink((string) $path);
            }
        }

        foreach ([$this->root . '/resources', $this->root . '/var', $this->root] as $dir) {
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }

    private function store(): PresentationOverrideStore
    {
        return PresentationOverrideStore::fromConfig([], $this->root);
    }

    /**
     * THE ONE THAT DECIDES — nothing is overridden until somebody authorized it.
     */
    public function testAComponentIsNotOverriddenUntilSomebodyAuthorizedIt(): void
    {
        self::assertNull($this->store()->forComponent('data-table'));
        self::assertSame([], $this->store()->all());
    }

    public function testAGrantedOverrideIsWhatTheTransportReadsBack(): void
    {
        $this->store()->grant('data-table', $this->root . '/resources/skin.css', null, 'acme/theme', 'passkey:abc');

        $presentation = $this->store()->forComponent('data-table');

        self::assertNotNull($presentation);
        self::assertSame($this->root . '/resources/skin.css', $presentation->styles);
        self::assertNull($presentation->script, 'the store never hands back a script');
    }

    /**
     * Who authorized it and who asked for it are two facts, and the ledger keeps them apart.
     */
    public function testTheLedgerKeepsWhoAuthorizedItApartFromWhoAskedForIt(): void
    {
        $this->store()->grant('data-table', $this->root . '/resources/skin.css', null, 'acme/theme', 'passkey:abc');

        $entry = $this->store()->all()['data-table'];

        self::assertSame('acme/theme', $entry['by']);
        self::assertSame('passkey:abc', $entry['authorized_by']);
        self::assertNotSame($entry['by'], $entry['authorized_by']);
    }

    /**
     * Revoking removes the entry rather than flagging it: a page cannot read a flag.
     */
    public function testRevokingStopsTheOverrideBeingEmittedAtAll(): void
    {
        $this->store()->grant('data-table', $this->root . '/resources/skin.css', null, 'acme/theme', 'passkey:abc');
        self::assertNotNull($this->store()->forComponent('data-table'));

        self::assertTrue($this->store()->revoke('data-table'));
        self::assertNull($this->store()->forComponent('data-table'));
        self::assertFalse($this->store()->revoke('data-table'), 'withdrawing nothing says so');
    }

    /**
     * A path is stored relative to the app, so the ledger survives a different install.
     */
    public function testThePathIsRecordedRelativeToTheApp(): void
    {
        $this->store()->grant('data-table', $this->root . '/resources/skin.css', null, 'acme/theme', 'passkey:abc');

        self::assertSame('resources/skin.css', $this->store()->all()['data-table']['styles']);
    }

    /**
     * An override must come from inside this app, and it must actually be there.
     *
     * Outside is a path somebody could point anywhere. Not there yet is the failure this whole arc
     * keeps finding: a grant that reads as honoured, emits nothing, and raises no error.
     */
    public function testAPathOutsideTheAppIsRefused(): void
    {
        // A REAL stylesheet, outside the app. The first version of this test used `/etc/hosts`, which
        // the extension check rejected before containment was ever consulted — so it passed while
        // proving nothing, and a mutation that removed containment entirely stayed green.
        $outside = sys_get_temp_dir() . '/milpa-outside-' . bin2hex(random_bytes(6)) . '.css';
        file_put_contents($outside, '.x { color: red; }');

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/inside this app/');
            $this->store()->grant('data-table', $outside, null, 'acme/theme', 'passkey:abc');
        } finally {
            unlink($outside);
        }
    }

    public function testAPathTheAppDoesNotShipIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store()->grant('data-table', $this->root . '/resources/nope.css', null, 'acme/theme', 'passkey:abc');
    }

    public function testAGrantThatChangesNothingIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store()->grant('data-table', null, null, 'acme/theme', 'passkey:abc');
    }

    /**
     * A catalogue granted in the stylesheet slot is worse to debug than a refusal.
     *
     * The scoper would dutifully rewrite PHP source as CSS and the page would carry it, styling
     * nothing and raising nothing — the exact failure shape this arc keeps finding. Caught by my own
     * end-to-end test passing the wrong argument, which is how it came to be checked at all.
     */
    public function testAFileGrantedInTheWrongSlotIsRefused(): void
    {
        file_put_contents($this->root . '/resources/words.php', '<?php return [];');

        $this->expectException(\InvalidArgumentException::class);
        $this->store()->grant('data-table', $this->root . '/resources/words.php', null, 'acme/theme', 'passkey:abc');
    }

    /**
     * The operation says what it is: privileged, because it acts on somebody else's resource, and
     * configuration rather than executable, which is only true because a script cannot ride along.
     */
    public function testTheGrantDeclaresWhatItReallySpends(): void
    {
        $grant = $this->operation('ui:override:grant');

        self::assertSame(Authority::Privileged, $grant->effects->authority);
        self::assertSame(Subject::Configuration, $grant->effects->subject);
        self::assertTrue($grant->mutating);
        self::assertTrue($grant->requiresConfirmation, 'a privileged effect that never pauses is not governed');
        self::assertSame('ui:override:revoke', $grant->effects->rollbackContract, 'the inverse is NAMED, not described');
    }

    /**
     * The inverse the grant names is an operation that exists in the same table.
     *
     * A rollback contract citing something nobody ships is prose with a colon in it.
     */
    public function testTheNamedInverseIsAnOperationThisPackageActuallyShips(): void
    {
        $names = array_map(
            static fn (Operation $operation): string => $operation->name,
            (new PresentationOverrideOperations($this->store()))->operations(),
        );

        self::assertContains((string) $this->operation('ui:override:grant')->effects->rollbackContract, $names);
    }

    /**
     * `authorized_by` is read from the call and never invented by the handler.
     */
    public function testTheHandlerDoesNotInventWhoAuthorizedIt(): void
    {
        $grant = $this->operation('ui:override:grant');
        ($grant->handler)([
            'component' => 'data-table',
            'by' => 'acme/theme',
            'styles' => $this->root . '/resources/skin.css',
        ]);

        self::assertSame('unknown', $this->store()->all()['data-table']['authorized_by'], 'an unnamed authorizer is recorded as unknown, not as the executor');
    }

    private function operation(string $name): Operation
    {
        foreach ((new PresentationOverrideOperations($this->store()))->operations() as $operation) {
            if ($operation->name === $name) {
                return $operation;
            }
        }

        self::fail($name . ' is not offered');
    }
}
