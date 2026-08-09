<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Support;

use Milpa\AppRuntime\Support\Foundation;
use PHPUnit\Framework\TestCase;

/**
 * The first arrow's condition, adjudicated from durable state — greenhouse evidence/0009.
 *
 * Four verdicts, frozen: `unfounded` (no sufficient foundation — the rite proceeds), `founded`
 * (the transition is earned), `invalid` (an artifact pretends to be a foundation and contradicts
 * its contract — repair, never re-found), `indeterminate` (the system cannot honestly adjudicate
 * — typed reason, touch nothing). The distinction this fixes is the one the house keeps hunting:
 * «could not verify» is not «is wrong». And the frozen property: ONLY ABSENCE authorizes the
 * founding rite; defective presence authorizes repair, never silent substitution.
 */
final class FoundationVerdictTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-verdict-test-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/.milpa', 0o777, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->root);
    }

    /** @param array<string, mixed> $doc */
    private function write(array $doc): void
    {
        file_put_contents($this->root . '/.milpa/foundation.json', json_encode($doc, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> a structurally valid, RICH-free foundation */
    private function valid(): array
    {
        return [
            'schema' => 'milpa.foundation/v1',
            'domain' => 'travel-agency',
            'authorities' => ['product' => 'human', 'destructive_changes' => 'human'],
            'founded_at' => '2026-08-07T00:00:00Z',
        ];
    }

    /** Battery 1 — no document at all: absence, and only absence, authorizes the rite. */
    public function testAbsentFileIsUnfounded(): void
    {
        $v = Foundation::verdict($this->root);

        self::assertSame('unfounded', $v['verdict']);
    }

    /** Battery 2 — broken JSON pretends and contradicts: repair, never re-found. */
    public function testBrokenJsonIsInvalidNeverUnfounded(): void
    {
        file_put_contents($this->root . '/.milpa/foundation.json', '{not json');

        $v = Foundation::verdict($this->root);

        self::assertSame('invalid', $v['verdict']);
    }

    /** A future Milpa's foundation must never read as «not founded» — that invites re-founding. */
    public function testUnknownSchemaIsIndeterminateWithTypedReason(): void
    {
        $this->write(['schema' => 'milpa.foundation/v9', 'domain' => 'x'] + $this->valid());

        $v = Foundation::verdict($this->root);

        self::assertSame('indeterminate', $v['verdict']);
        self::assertSame('foundation_schema_unsupported', $v['reason']);
    }

    /** A document that does not even claim the contract is malformed, not mysterious. */
    public function testMissingSchemaIsInvalid(): void
    {
        $doc = $this->valid();
        unset($doc['schema']);
        $this->write($doc);

        self::assertSame('invalid', Foundation::verdict($this->root)['verdict']);
    }

    /**
     * Battery 3 — the pristine placeholder a newborn ships embodies ABSENCE of declaration:
     * nothing pretends a completed founding, so the rite proceeds (evidence/0007 fills it).
     */
    public function testPristinePlaceholderIsUnfounded(): void
    {
        $this->write([
            'schema' => 'milpa.foundation/v1',
            'domain' => null,
            'objective' => null,
            'boundaries' => [],
            'authorities' => ['product' => 'human', 'destructive_changes' => 'human'],
            'founded_at' => null,
        ]);

        self::assertSame('unfounded', Foundation::verdict($this->root)['verdict']);
    }

    /** An empty domain on a document that PRETENDS (founded_at set) contradicts the contract. */
    public function testEmptyDomainOnAPretendingDocumentIsInvalid(): void
    {
        $doc = $this->valid();
        $doc['domain'] = '';
        $this->write($doc);

        self::assertSame('invalid', Foundation::verdict($this->root)['verdict']);
    }

    /** Battery 6 — a foundation that cannot say who decides cannot govern its evolution. */
    public function testMissingAuthorityIsInvalid(): void
    {
        $doc = $this->valid();
        unset($doc['authorities']['destructive_changes']);
        $this->write($doc);

        self::assertSame('invalid', Foundation::verdict($this->root)['verdict']);
    }

    /** `human` is a closed vocabulary in v1: an unrecognized authority form does not adjudicate. */
    public function testUnrecognizedAuthorityFormIsInvalid(): void
    {
        $doc = $this->valid();
        $doc['authorities']['product'] = 'the-vibes';
        $this->write($doc);

        self::assertSame('invalid', Foundation::verdict($this->root)['verdict']);
    }

    /** Battery 5 — a structurally valid foundation earns the transition. */
    public function testValidFoundationIsFounded(): void
    {
        $this->write($this->valid());

        $v = Foundation::verdict($this->root);

        self::assertSame('founded', $v['verdict']);
        self::assertSame('travel-agency', $v['foundation']['domain']);
    }

    /**
     * Battery 8 — Rod's killer case: FOUNDED means identity + minimal authority, NOTHING MORE.
     * `objective` and `boundaries` are RICH foundation; making them constitutive here would
     * legislate ceremony before measurement earns it.
     */
    public function testValidFoundationWithoutObjectiveOrBoundariesIsStillFounded(): void
    {
        $this->write($this->valid());

        self::assertSame('founded', Foundation::verdict($this->root)['verdict']);
    }

    /** On `invalid`, the answer offers REPAIR and never teaches the founding rite. */
    public function testAnswerOnInvalidOffersRepairNotTheRite(): void
    {
        file_put_contents($this->root . '/.milpa/foundation.json', '{not json');

        $r = Foundation::answer($this->root);

        self::assertFalse($r['founded']);
        $texto = json_encode($r, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('foundation:found', $texto);
    }

    /** The rite refuses defective presence: repair, never silent substitution. */
    public function testFoundRefusesOnInvalidInsteadOfOverwriting(): void
    {
        file_put_contents($this->root . '/.milpa/foundation.json', '{not json');

        $r = Foundation::found(['domain' => 'travel-agency', 'objective' => 'x'], $this->root);

        self::assertFalse($r['ok']);
        self::assertSame('{not json', file_get_contents($this->root . '/.milpa/foundation.json'));
    }

    /** The rite also refuses what it cannot adjudicate — indeterminate is not an invitation. */
    public function testFoundRefusesOnIndeterminate(): void
    {
        $this->write(['schema' => 'milpa.foundation/v9'] + $this->valid());

        $r = Foundation::found(['domain' => 'travel-agency', 'objective' => 'x'], $this->root);

        self::assertFalse($r['ok']);
    }
}
