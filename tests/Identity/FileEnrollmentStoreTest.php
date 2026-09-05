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

use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use PHPUnit\Framework\TestCase;

/**
 * The ledger records FACTS, not state (greenhouse decisions/0207): a re-recognition does not erase the
 * revocation it follows. Every claim here is falsified by execution — write, then read the JSON back.
 */
final class FileEnrollmentStoreTest extends TestCase
{
    private const FP = 'ABCD1234ABCD1234ABCD1234ABCD1234ABCD1234';

    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/milpa-ledger-' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    /** F1: record → revoke → record keeps the revocation in the history, and the live state is the new one. */
    public function testReRecognizingARevokedKeyKeepsTheRevocationInTheHistory(): void
    {
        $store = $this->store();
        $store->record(new IdentityEnrolled(self::FP, ['agent:read'], 'key:FIRST'));
        self::assertTrue($store->revoke(self::FP, 'key:REVOKER'));
        self::assertNull($store->scopesFor(self::FP), 'revoked: not admitted');

        $report = $store->recordAndReport(new IdentityEnrolled(self::FP, ['milpa.admin'], 'key:SECOND'));

        self::assertSame(['milpa.admin'], $store->scopesFor(self::FP), 'the live state is the re-recognition');
        self::assertSame(['previously_revoked_by' => 'key:REVOKER', 'history_entries' => 1], $report);

        $entry = $this->raw()[self::FP];
        self::assertSame(['milpa.admin'], $entry['scopes']);
        self::assertSame('key:SECOND', $entry['authorized_by']);
        self::assertArrayNotHasKey('revoked_by', $entry, 'the live state carries no revocation');
        self::assertCount(1, $entry['history']);
        self::assertSame('key:REVOKER', $entry['history'][0]['revoked_by'], 'the revocation is not erased');
        self::assertSame(['agent:read'], $entry['history'][0]['scopes'], 'nor the scopes it was laid over');
        self::assertSame('key:FIRST', $entry['history'][0]['authorized_by']);
    }

    /** F2: a scope change with no revocation in between is history too — and no revoked_by appears anywhere. */
    public function testReRecognizingALiveKeyPushesTheEarlierStateOntoTheHistory(): void
    {
        $store = $this->store();
        $store->record(new IdentityEnrolled(self::FP, ['agent:read'], 'key:FIRST'));

        $report = $store->recordAndReport(new IdentityEnrolled(self::FP, ['agent:read', 'agent:answer'], 'key:SECOND'));

        self::assertSame(['agent:read', 'agent:answer'], $store->scopesFor(self::FP));
        self::assertSame(['previously_revoked_by' => null, 'history_entries' => 1], $report);

        $entry = $this->raw()[self::FP];
        self::assertSame(['agent:read'], $entry['history'][0]['scopes']);
        self::assertSame('key:FIRST', $entry['history'][0]['authorized_by']);
        self::assertStringNotContainsString('revoked_by', (string) file_get_contents($this->path), 'nothing was revoked');
    }

    /** The history stays flat: a third write carries the two earlier states along, most recent last. */
    public function testTheHistoryAccumulatesFlatMostRecentLast(): void
    {
        $store = $this->store();
        $store->record(new IdentityEnrolled(self::FP, ['a'], 'key:ONE'));
        $store->record(new IdentityEnrolled(self::FP, ['b'], 'key:TWO'));
        $store->revoke(self::FP, 'key:REVOKER');

        $report = $store->recordAndReport(new IdentityEnrolled(self::FP, ['c'], 'key:THREE'));

        self::assertSame(['previously_revoked_by' => 'key:REVOKER', 'history_entries' => 2], $report);
        $history = $this->raw()[self::FP]['history'];
        self::assertSame([['scopes' => ['a'], 'authorized_by' => 'key:ONE'], ['scopes' => ['b'], 'authorized_by' => 'key:TWO', 'revoked_by' => 'key:REVOKER']], $history);
        self::assertArrayNotHasKey('history', $history[1], 'states do not nest');
        self::assertSame(['c'], $store->scopesFor(self::FP));
    }

    /** A first recognition reports nothing replaced and writes no history field. */
    public function testAFirstRecognitionHasNoHistory(): void
    {
        $report = $this->store()->recordAndReport(new IdentityEnrolled(self::FP, ['agent:read'], 'key:FIRST'));

        self::assertSame(['previously_revoked_by' => null, 'history_entries' => 0], $report);
        self::assertArrayNotHasKey('history', $this->raw()[self::FP]);
    }

    /** F4 (control): a ledger in the 0.118 format — no history — reads exactly as before. */
    public function testALedgerWrittenBeforeHistoryExistedReadsAsBefore(): void
    {
        file_put_contents($this->path, (string) json_encode([
            self::FP => ['scopes' => ['agent:read'], 'authorized_by' => 'key:FIRST'],
            'revoked-one' => ['scopes' => ['milpa.admin'], 'authorized_by' => 'key:FIRST', 'revoked_by' => 'key:REVOKER'],
        ]));
        $store = $this->store();

        self::assertSame(['agent:read'], $store->scopesFor(self::FP));
        self::assertNull($store->scopesFor('revoked-one'), 'a revocation still denies');
        self::assertNull($store->scopesFor('never'), 'a key never enrolled resolves to nothing');
        self::assertFalse($store->isEmpty());
        self::assertTrue($store->revoke(self::FP, 'key:REVOKER'), 'revocation still finds a live entry');
        self::assertNull($store->scopesFor(self::FP));
        self::assertArrayNotHasKey('history', $this->raw()[self::FP], 'revoking writes no history — only a re-write does');
    }

    /** F5 (control): revoking an already-revoked key returns false and pushes nothing. */
    public function testRevokingAnAlreadyRevokedKeyAddsNoHistory(): void
    {
        $store = $this->store();
        $store->record(new IdentityEnrolled(self::FP, ['agent:read'], 'key:FIRST'));
        self::assertTrue($store->revoke(self::FP, 'key:REVOKER'));
        $before = (string) file_get_contents($this->path);

        self::assertFalse($store->revoke(self::FP, 'key:OTHER'));

        self::assertSame($before, (string) file_get_contents($this->path), 'the ledger did not change');
        self::assertSame('key:REVOKER', $this->raw()[self::FP]['revoked_by'], 'the first revoker stands');
        self::assertArrayNotHasKey('history', $this->raw()[self::FP]);
    }

    public function testIsEmptyIsSealedByAnyEntryAndHistoryDoesNotChangeThat(): void
    {
        $store = $this->store();
        self::assertTrue($store->isEmpty());

        $store->record(new IdentityEnrolled(self::FP, ['agent:read'], 'key:FIRST'));
        $store->revoke(self::FP, 'key:REVOKER');
        $store->record(new IdentityEnrolled(self::FP, ['agent:read'], 'key:SECOND'));

        self::assertFalse($store->isEmpty());
    }

    /** F6: a write that cannot happen is said, not reported on — the report never stands for a write that did not occur. */
    public function testAWriteThatCannotHappenThrowsInsteadOfReporting(): void
    {
        // A path whose parent is a regular file: the directory cannot be made and the file cannot be opened.
        file_put_contents($this->path, 'not a directory');
        $store = new FileEnrollmentStore($this->path . '/ledger.json');

        try {
            $store->recordAndReport(new IdentityEnrolled(self::FP, ['agent:read'], 'key:FIRST'));
            self::fail('a report was returned for a write that did not happen');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('could not be opened', $e->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        $store->record(new IdentityEnrolled(self::FP, ['agent:read'], 'key:FIRST'));
    }

    /** The report follows scopesFor()'s rule: any non-null revoked_by is a revocation, named however it was written. */
    public function testARevocationWrittenAsANonStringIsStillReported(): void
    {
        file_put_contents($this->path, (string) json_encode([
            self::FP => ['scopes' => ['agent:read'], 'authorized_by' => 'key:FIRST', 'revoked_by' => true],
        ]));
        $store = $this->store();
        self::assertNull($store->scopesFor(self::FP), 'a non-string revoked_by still denies admission');

        $report = $store->recordAndReport(new IdentityEnrolled(self::FP, ['milpa.admin'], 'key:SECOND'));

        self::assertSame('true', $report['previously_revoked_by'], 'the act does not omit that it re-recognized over a revocation');
        self::assertSame(1, $report['history_entries']);
        self::assertTrue($this->raw()[self::FP]['history'][0]['revoked_by']);
    }

    /** A `history` that is not a list is not lifted — it rides inside the pushed state, nothing erased. */
    public function testAMalformedHistoryRidesInsideThePushedState(): void
    {
        file_put_contents($this->path, (string) json_encode([
            self::FP => ['scopes' => ['agent:read'], 'authorized_by' => 'key:FIRST', 'history' => 'garbage'],
        ]));

        $report = $this->store()->recordAndReport(new IdentityEnrolled(self::FP, ['milpa.admin'], 'key:SECOND'));

        self::assertSame(['previously_revoked_by' => null, 'history_entries' => 1], $report);
        self::assertSame(
            [['scopes' => ['agent:read'], 'authorized_by' => 'key:FIRST', 'history' => 'garbage']],
            $this->raw()[self::FP]['history'],
        );
    }

    /** A standing entry the store cannot read as a state is a fact it found there: kept raw and counted, not overwritten. */
    public function testAnEntryThatIsNotAStateIsKeptRawInTheHistory(): void
    {
        file_put_contents($this->path, (string) json_encode([self::FP => 'garbage']));
        $store = $this->store();
        self::assertFalse($store->isEmpty(), 'the entry seals the ledger');
        self::assertNull($store->scopesFor(self::FP));

        $report = $store->recordAndReport(new IdentityEnrolled(self::FP, ['agent:read'], 'key:FIRST'));

        self::assertSame(['previously_revoked_by' => null, 'history_entries' => 1], $report);
        self::assertSame([['raw' => 'garbage']], $this->raw()[self::FP]['history']);
        self::assertSame(['agent:read'], $store->scopesFor(self::FP));
    }

    /** Content the store cannot read is neither a greenfield nor something to write over — the last erasure path is closed. */
    public function testALedgerTheStoreCannotReadIsNeitherAGreenfieldNorOverwritten(): void
    {
        $garbage = '{"' . self::FP . '": {"scopes": ["agent:read"]}} trailing garbage';
        file_put_contents($this->path, $garbage);
        $store = $this->store();

        self::assertFalse($store->isEmpty(), 'unreadable is not empty: bootstrap must not mint a root over it');
        self::assertNull($store->scopesFor(self::FP), 'nor is anything admitted from it');

        foreach ([
            static fn () => $store->record(new IdentityEnrolled('K9b', ['*'], 'key:NEW')),
            static fn () => $store->revoke(self::FP, 'key:REVOKER'),
        ] as $write) {
            try {
                $write();
                self::fail('the store wrote over content it could not read');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('refuses to write over it', $e->getMessage());
            }
        }

        self::assertSame($garbage, (string) file_get_contents($this->path), 'byte for byte, nothing was erased');
    }

    private function store(): FileEnrollmentStore
    {
        return new FileEnrollmentStore($this->path);
    }

    /** @return array<string, array<string, mixed>> */
    private function raw(): array
    {
        $decoded = json_decode((string) file_get_contents($this->path), true);
        self::assertIsArray($decoded);

        /** @var array<string, array<string, mixed>> */
        return $decoded;
    }
}
