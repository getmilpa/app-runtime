<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Operations\FoundationOperations;
use Milpa\AppRuntime\Support\Foundation;
use Milpa\AppRuntime\Support\Operations;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\Mutation;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\TestCase;

/**
 * The rite of foundation gets hands — greenhouse decisions/0004.
 *
 * What this fixes is the finding of greenhouse evidence/0004: no operation of the system could
 * read or write `.milpa/foundation.json`, so a tenant could not found even if it wanted to —
 * `dominio_fundado = null` three times out of three, measured before these hands existed. The
 * contract these tests pin: reading teaches, founding is consent-gated and human-named, and a
 * constitution is written exactly once — amending it is a recorded decision, never a second found.
 */
final class FoundationOperationsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-foundation-test-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        // A recursive rm in a test teardown deletes exactly what setUp created, nothing else.
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->root);
    }

    /** The group declares exactly the two operations of the contract, with their gates. */
    public function testTheGroupDeclaresTheReadAndTheRiteWithTheirGates(): void
    {
        $ops = [];
        foreach ((new FoundationOperations())->operations() as $op) {
            $ops[$op->name] = $op;
        }

        self::assertArrayHasKey('foundation', $ops);
        self::assertArrayHasKey('foundation:found', $ops);

        // Reading is free and everywhere: identity that only some surfaces can see is not identity.
        self::assertFalse($ops['foundation']->mutating);
        self::assertSame(Mutation::None, $ops['foundation']->effects->mutation);
        self::assertSame(Authority::Read, $ops['foundation']->effects->authority);
        self::assertContains('http', $ops['foundation']->surfaces);

        // Founding changes what the app IS: consent-gated, human-named domain, and never over http.
        self::assertTrue($ops['foundation:found']->mutating);
        self::assertSame(Mutation::Persistent, $ops['foundation:found']->effects->mutation);
        self::assertSame(Authority::Privileged, $ops['foundation:found']->effects->authority);
        self::assertSame('domain', $ops['foundation:found']->namedTarget);
        self::assertNotContains('http', $ops['foundation:found']->surfaces);
    }

    /** An unfounded app does not answer emptiness — it teaches the rite. */
    public function testAnUnfoundedAppTeachesTheRite(): void
    {
        $r = Foundation::answer($this->root);

        self::assertFalse($r['founded']);
        self::assertStringContainsString('foundation:found', json_encode($r, JSON_THROW_ON_ERROR));
    }

    /** A founded app answers its constitution as it was declared. */
    public function testAFoundedAppAnswersItsConstitution(): void
    {
        mkdir($this->root . '/.milpa');
        file_put_contents($this->root . '/.milpa/foundation.json', json_encode([
            'schema' => 'milpa.foundation/v1',
            'domain' => 'travel-agency',
            'objective' => 'Manage travel requests for human advisors',
            'boundaries' => ['Does not process payments directly'],
            'authorities' => ['product' => 'human', 'destructive_changes' => 'human'],
            'founded_at' => '2026-08-06T00:00:00Z',
        ], JSON_THROW_ON_ERROR));

        $r = Foundation::answer($this->root);

        self::assertTrue($r['founded']);
        self::assertSame('travel-agency', $r['foundation']['domain']);
        self::assertSame(['Does not process payments directly'], $r['foundation']['boundaries']);
    }

    /** Founding writes the constitution AND its acta — a foundation without its record is hearsay. */
    public function testFoundingWritesTheConstitutionAndItsActa(): void
    {
        $r = Foundation::found([
            'domain' => 'travel-agency',
            'objective' => 'Manage travel requests for human advisors',
            'boundaries' => ['Does not process payments directly'],
        ], $this->root);

        self::assertTrue($r['ok']);

        $constitution = json_decode(
            (string) file_get_contents($this->root . '/.milpa/foundation.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame('milpa.foundation/v1', $constitution['schema']);
        self::assertSame('travel-agency', $constitution['domain']);
        self::assertNotEmpty($constitution['founded_at']);
        // Authorities the caller did not declare default to the human — never to the agent.
        self::assertSame('human', $constitution['authorities']['product']);
        self::assertSame('human', $constitution['authorities']['destructive_changes']);

        $actas = glob($this->root . '/.milpa/decisions/*fundacion*.md') ?: [];
        self::assertCount(1, $actas);
        self::assertStringContainsString('travel-agency', (string) file_get_contents($actas[0]));
    }

    /**
     * The placeholder constitution a newborn ships — `domain: null` — is NOT a founded app.
     *
     * Caught live on the first cattle born from the migrated skeleton: the template ships
     * `.milpa/foundation.json` with a null domain, and a file-exists check read that as founded —
     * which would refuse the rite on EVERY newborn. Founded means the domain is declared.
     */
    public function testANewbornPlaceholderConstitutionTeachesTheRite(): void
    {
        mkdir($this->root . '/.milpa');
        file_put_contents($this->root . '/.milpa/foundation.json', json_encode([
            'schema' => 'milpa.foundation/v1',
            'domain' => null,
            'objective' => null,
            'boundaries' => [],
            'authorities' => ['product' => 'human', 'destructive_changes' => 'human'],
            'founded_at' => null,
        ], JSON_THROW_ON_ERROR));

        $r = Foundation::answer($this->root);

        self::assertFalse($r['founded']);
        self::assertStringContainsString('foundation:found', json_encode($r, JSON_THROW_ON_ERROR));
    }

    /** The rite fills the placeholder — founding a newborn is the normal case, not an overwrite. */
    public function testFoundingFillsThePlaceholderConstitution(): void
    {
        mkdir($this->root . '/.milpa');
        file_put_contents($this->root . '/.milpa/foundation.json', json_encode([
            'schema' => 'milpa.foundation/v1',
            'domain' => null,
            'founded_at' => null,
        ], JSON_THROW_ON_ERROR));

        $r = Foundation::found(['domain' => 'travel-agency', 'objective' => 'x'], $this->root);

        self::assertTrue($r['ok']);
        self::assertSame(
            'travel-agency',
            json_decode((string) file_get_contents($this->root . '/.milpa/foundation.json'), true)['domain'],
        );
        self::assertNotEmpty(glob($this->root . '/.milpa/decisions/*fundacion*.md'));
    }

    /** A second found refuses: amending a constitution is a recorded decision, not an overwrite. */
    public function testFoundingRefusesWhenAlreadyFounded(): void
    {
        Foundation::found(['domain' => 'travel-agency', 'objective' => 'x'], $this->root);
        $r = Foundation::found(['domain' => 'bakery', 'objective' => 'y'], $this->root);

        self::assertFalse($r['ok']);
        self::assertSame(
            'travel-agency',
            json_decode((string) file_get_contents($this->root . '/.milpa/foundation.json'), true)['domain'],
        );
    }

    /** Consent must be given to something legible: dry_run shows the writes without writing. */
    public function testDryRunShowsTheWritesWithoutWriting(): void
    {
        $r = Foundation::found(['domain' => 'travel-agency', 'objective' => 'x', 'dry_run' => true], $this->root);

        self::assertTrue($r['ok']);
        self::assertFileDoesNotExist($this->root . '/.milpa/foundation.json');
        self::assertSame('travel-agency', $r['would_write']['foundation']['domain']);
    }

    /** Without a domain there is nothing to found — the handler refuses instead of guessing one. */
    public function testFoundingRequiresADomainAndAnObjective(): void
    {
        self::assertFalse(Foundation::found(['objective' => 'x'], $this->root)['ok']);
        self::assertFalse(Foundation::found(['domain' => 'travel-agency'], $this->root)['ok']);
        self::assertFileDoesNotExist($this->root . '/.milpa/foundation.json');
    }

    /**
     * The app's dispatcher offers the group like any other — through `Support\Operations`.
     *
     * This one intentionally routes through the file the 0005 migration control caught NO package
     * test loading: the blind spot must shrink, not grow, with the first operation born in this house.
     */
    public function testTheDispatcherOffersTheGroup(): void
    {
        $names = array_map(
            static fn ($op) => $op->name,
            Operations::declared(new DIContainer(), [FoundationOperations::class], $this->root),
        );

        self::assertContains('foundation', $names);
        self::assertContains('foundation:found', $names);
    }
}
