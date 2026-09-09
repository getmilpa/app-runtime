<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app installs, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Support;

use Milpa\AppRuntime\Support\CapabilityIndex;
use PHPUnit\Framework\TestCase;

/**
 * The capability→package index, DERIVED from what the registry publishes — never written by hand.
 *
 * The hand-written list was the exact defect ADR-0041 names: a new package could not announce
 * itself without somebody editing the framework. Since Q-P19-T the packages declare
 * `type: milpa-capability` and Packagist publishes their full contract per version; this class
 * turns that into a DATED artifact ranked below `installed.json` (what IS) and above the offline
 * floor (what this floor remembers).
 *
 * The fetch is a seam: a test that needs the live registry is a test nobody runs offline — and the
 * live derivation was verified against the real Packagist the day this shipped (nine packages).
 */
final class CapabilityIndexTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-capindex-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/var', 0o775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** A canned registry: the type listing plus one p2 document per package. */
    private function fetcher(): callable
    {
        $byUrl = [
            'https://packagist.org/packages/list.json?type=milpa-capability' => json_encode([
                'packageNames' => ['milpa/agent', 'milpa/data', 'milpa/plain'],
            ]),
            'https://repo.packagist.org/p2/milpa/agent.json' => json_encode(['packages' => ['milpa/agent' => [[
                'version' => 'v0.6.0',
                'extra' => ['milpa' => ['capability' => [
                    'id' => 'agent', 'title' => 'Sessions', 'unlocks' => ['coa chat'], 'provides' => ['agent.sessions'],
                ]]],
            ]]]]),
            'https://repo.packagist.org/p2/milpa/data.json' => json_encode(['packages' => ['milpa/data' => [[
                'version' => 'v0.4.1',
                'extra' => ['milpa' => ['capability' => [
                    'id' => 'data', 'title' => 'Persistence', 'unlocks' => [], 'provides' => ['data.repositories'],
                ]]],
            ]]]]),
            // Declares the type but never the contract — real-world sloppiness, not hypothetical.
            'https://repo.packagist.org/p2/milpa/plain.json' => json_encode(['packages' => ['milpa/plain' => [[
                'version' => 'v1.0.0',
            ]]]]),
        ];

        return static fn (string $url): ?string => $byUrl[$url] ?? null;
    }

    /** The index carries what the registry published: package, version, and the whole contract. */
    public function testTheIndexIsDerivedFromWhatTheRegistryPublishes(): void
    {
        $index = CapabilityIndex::derive($this->fetcher());

        self::assertSame(['milpa/agent', 'milpa/data'], array_keys($index['capabilities']));
        self::assertSame('v0.6.0', $index['capabilities']['milpa/agent']['version']);
        self::assertSame(['coa chat'], $index['capabilities']['milpa/agent']['unlocks']);
        self::assertSame('data', $index['capabilities']['milpa/data']['id']);
    }

    /**
     * A package that declares the TYPE but not the CONTRACT is left out AND said — silence would
     * read as «covered» when it is not (ADR-0029: a check must fail on nothing).
     */
    public function testAPackageWithoutTheContractIsLeftOutAndSaid(): void
    {
        $index = CapabilityIndex::derive($this->fetcher());

        self::assertArrayNotHasKey('milpa/plain', $index['capabilities']);
        self::assertSame(['milpa/plain'], $index['undeclared']);
    }

    /** An unreachable registry derives nothing — an empty index is honest, an invented one is not. */
    public function testAnUnreachableRegistryDerivesNothing(): void
    {
        $index = CapabilityIndex::derive(static fn (string $u): ?string => null);

        self::assertSame([], $index['capabilities']);
        self::assertNotSame('', $index['error'] ?? '');
    }

    /** The artifact round-trips with its date — the reader must know how stale it is reading. */
    public function testTheArtifactRoundTripsWithItsDate(): void
    {
        $index = CapabilityIndex::derive($this->fetcher());
        CapabilityIndex::write($index, '2026-08-06T09:00:00+00:00', $this->root);

        $read = CapabilityIndex::read($this->root);
        self::assertNotNull($read);
        self::assertSame('2026-08-06T09:00:00+00:00', $read['derived_at']);
        self::assertSame('v0.6.0', $read['capabilities']['milpa/agent']['version']);
    }

    /** No artifact on disk reads as null — never as an empty index that looks derived. */
    public function testNoArtifactReadsAsNull(): void
    {
        self::assertNull(CapabilityIndex::read($this->root));
    }

    /** The whole refresh: derive, persist dated, summarise — and the artifact is readable after. */
    public function testRefreshDerivesPersistsAndSummarises(): void
    {
        $r = CapabilityIndex::refresh($this->fetcher(), '2026-08-06T10:00:00+00:00', $this->root);

        self::assertTrue($r['ok']);
        self::assertSame(2, $r['capabilities']);
        self::assertSame(['milpa/plain'], $r['undeclared']);
        self::assertSame('2026-08-06T10:00:00+00:00', CapabilityIndex::read($this->root)['derived_at'] ?? null);
    }

    /** A listing entry that is not a package name is skipped before any fetch is spent on it. */
    public function testAMalformedListingEntryIsSkipped(): void
    {
        $index = CapabilityIndex::derive(static function (string $url): ?string {
            if (str_contains($url, 'list.json')) {
                return json_encode(['packageNames' => ['../evil', 42, 'milpa agent']]);
            }
            self::fail("a fetch was spent on a malformed name: {$url}");
        });

        self::assertSame([], $index['capabilities']);
        self::assertSame([], $index['undeclared']);
    }

    /** A root whose var/ cannot exist refuses loudly — a swallowed write is a cache that lies. */
    public function testAnUnwritableRootRefusesLoudly(): void
    {
        $file = $this->root . '/not-a-dir';
        file_put_contents($file, 'x');

        $this->expectException(\RuntimeException::class);
        CapabilityIndex::write(['capabilities' => [], 'undeclared' => []], '2026-08-06T10:00:00+00:00', $file);
    }

    /**
     * F1 — A HOST THAT ANSWERS ITS LANDING PAGE IS NOT A REGISTRY (greenhouse decisions/0239).
     *
     * milpahq.com serves `200` with a 22 KB HTML page on every path, `/index.json` included. Read as
     * a listing it decodes to `null`, names zero packages, and used to come back as a perfectly
     * healthy empty index — which the refresh then stamped with a fresh date over a good one.
     */
    public function testAnAnswerThatIsNotAListingIsRefusedByName(): void
    {
        $page = "<!doctype html>\n<html lang=\"en\"><head><title>milpahq</title></head><body>hi</body></html>";

        $index = CapabilityIndex::derive(static fn (string $u): ?string => $page);

        self::assertSame('not_an_index', $index['reason'] ?? null);
        self::assertStringContainsString('not its listing', (string) ($index['error'] ?? ''));
        self::assertSame([], $index['capabilities']);
    }

    /**
     * F1's CONTROL, and it is the one that matters: a registry that legitimately lists NOTHING is
     * not an error. A reader that refused the truth would be worse than one that swallowed a page.
     */
    public function testAnEmptyRegistryIsAnAnswerAndNotARefusal(): void
    {
        $index = CapabilityIndex::derive(static fn (string $u): ?string => json_encode(['packageNames' => []]));

        self::assertArrayNotHasKey('error', $index);
        self::assertArrayNotHasKey('reason', $index);
        self::assertSame([], $index['capabilities']);
        self::assertSame([], $index['unreadable']);
    }

    /**
     * F1 — and the good catalogue SURVIVES, which is the whole point of refusing.
     *
     * The refusal also says what survived: a caller reading only `error` cannot tell whether
     * yesterday's catalogue is still there, and that decides whether a human worries now or later.
     */
    public function testARefusedRefreshKeepsTheIndexThatWasThereAndSaysSo(): void
    {
        $good = CapabilityIndex::derive($this->fetcher());
        CapabilityIndex::write($good, '2026-09-01T00:00:00+00:00', $this->root);

        $page = '<!doctype html><html><body>milpahq</body></html>';
        $r = CapabilityIndex::refresh(static fn (string $u): ?string => $page, '2026-09-09T00:00:00+00:00', $this->root);

        self::assertFalse($r['ok']);
        self::assertSame('not_an_index', $r['reason'] ?? null);
        self::assertSame('2026-09-01T00:00:00+00:00', $r['kept'] ?? null, 'the refusal names the date that survived');

        $still = CapabilityIndex::read($this->root);
        self::assertNotNull($still);
        self::assertSame('2026-09-01T00:00:00+00:00', $still['derived_at'], 'the good artifact was not touched');
        self::assertNotSame([], $still['capabilities']);
    }

    /**
     * F2 — a network that HALF answers is told apart from a registry that is empty.
     *
     * The listing is real and names packages; not one p2 document comes back. Deriving zero from
     * that and writing it is the same blanking by a slower road.
     */
    public function testAListingWhosePackagesCannotBeReadIsRefusedByName(): void
    {
        $index = CapabilityIndex::derive(static function (string $url): ?string {
            return str_contains($url, 'list.json')
                ? json_encode(['packageNames' => ['milpa/agent', 'milpa/data']])
                : null;
        });

        self::assertSame('registry_unreadable', $index['reason'] ?? null);
        self::assertSame(['milpa/agent', 'milpa/data'], $index['unreadable']);
        self::assertStringContainsString('none could be read', (string) ($index['error'] ?? ''));
    }

    /**
     * F2's CONTROL — «could not read it» and «declares no contract» stop being the same word.
     *
     * One package answers with a contract, one answers without one, one does not answer at all. The
     * derivation keeps the first, and the other two land in DIFFERENT lists.
     */
    public function testUnreadableAndUndeclaredAreDifferentFacts(): void
    {
        $index = CapabilityIndex::derive(static function (string $url): ?string {
            if (str_contains($url, 'list.json')) {
                return json_encode(['packageNames' => ['milpa/good', 'milpa/plain', 'milpa/silent']]);
            }
            if (str_contains($url, 'milpa/good')) {
                return json_encode(['packages' => ['milpa/good' => [['version' => 'v1.0.0', 'extra' => ['milpa' => ['capability' => ['id' => 'good']]]]]]]);
            }
            if (str_contains($url, 'milpa/plain')) {
                return json_encode(['packages' => ['milpa/plain' => [['version' => 'v1.0.0']]]]);
            }

            return null;
        });

        self::assertArrayNotHasKey('error', $index, 'one readable capability is a derivation, not a refusal');
        self::assertSame(['milpa/good'], array_keys($index['capabilities']));
        self::assertSame(['milpa/plain'], $index['undeclared'], 'read it, and it declares no contract');
        self::assertSame(['milpa/silent'], $index['unreadable'], 'could not read it at all');
    }

    /**
     * F4, THE ONE THAT CAN SAY NO: the guarantee lives where the write happens.
     *
     * `refresh()` declines to write a refusal, but the class is public and the next caller is not
     * bound by today's control flow. A refusal handed to `write()` must be refused there too.
     */
    public function testARefusalIsNeverWrittenAsAnIndex(): void
    {
        $refusal = CapabilityIndex::derive(static fn (string $u): ?string => null);

        $this->expectException(\InvalidArgumentException::class);
        CapabilityIndex::write($refusal, '2026-09-09T00:00:00+00:00', $this->root);
    }

    /** An unreachable registry refuses the refresh and writes NOTHING — no artifact born empty. */
    public function testAFailedRefreshWritesNothing(): void
    {
        $r = CapabilityIndex::refresh(static fn (string $u): ?string => null, '2026-08-06T10:00:00+00:00', $this->root);

        self::assertFalse($r['ok']);
        self::assertNull(CapabilityIndex::read($this->root));
    }
}
