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

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Operations\CapabilityOperations;
use Milpa\AppRuntime\Support\Capabilities;
use Milpa\Command\DeclaredCondition;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Operation;
use Milpa\Console\Consent;
use PHPUnit\Framework\TestCase;

/**
 * F-PRE / F-POST for `capabilities:enable` (greenhouse decisions/0183): every condition the
 * operation DECLARES is one the handler actually enforces — the declaration↔violation map below is
 * asserted complete against the declaration itself, so a condition declared without enforcement has
 * no row here and turns the test red. The handler delegates to {@see Capabilities::install()}, so
 * the refusals are provoked there, through the same seams its own tests use.
 */
final class CapabilityContractTest extends TestCase
{
    private string $vendor;

    protected function setUp(): void
    {
        $this->vendor = sys_get_temp_dir() . '/milpa-cap-contract-' . bin2hex(random_bytes(6));
        mkdir($this->vendor . '/composer', 0o775, true);
        file_put_contents(
            $this->vendor . '/composer/installed.json',
            json_encode(['packages' => []], \JSON_THROW_ON_ERROR),
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->vendor . '/composer/installed.json');
        @rmdir($this->vendor . '/composer');
        @rmdir($this->vendor);
    }

    /** F-PRE: each declared precondition, violated, is refused by the enforcing handler. */
    public function testEveryDeclaredPreconditionIsBackedByARefusal(): void
    {
        // declaration name → [violating call, fragment the refusal must carry]
        $violations = [
            'capability-named' => [
                fn (): array => Capabilities::install('', $this->vendor),
                'missing `capability`',
            ],
            'capability-known' => [
                fn (): array => Capabilities::install('totally/unknown', $this->vendor),
                'unknown capability «totally/unknown»',
            ],
        ];

        $declared = array_map(
            static fn (DeclaredCondition $c): string => $c->name,
            $this->enable()->preconditions,
        );
        self::assertSame(
            array_keys($violations),
            $declared,
            'the map covers EXACTLY what capabilities:enable declares — an unenforced declaration has no row here and is red',
        );

        foreach ($violations as $condition => [$violate, $fragment]) {
            $result = $violate();

            self::assertFalse($result['ok'], "violating «{$condition}» must be refused");
            self::assertStringContainsString($fragment, (string) $result['error'], "the refusal backs «{$condition}»");
        }
    }

    /**
     * F-PRE, the second half of `capability-known`: the refusal CARRIES the valid answers, so the
     * caller does not need a second operation to learn what it should have said.
     */
    public function testTheUnknownCapabilityRefusalCarriesTheValidAnswers(): void
    {
        $result = Capabilities::install('totally/unknown', $this->vendor);

        self::assertFalse($result['ok']);
        self::assertContains('milpa/agent', $result['available'], 'the offline floor is offered with the refusal');
    }

    /**
     * F-POST: the declared postcondition is verified by the handler itself — a composer exit of 0
     * without the capability landing in the vendor tree is a FAILURE naming the gap, never success.
     */
    public function testEveryDeclaredPostconditionIsBackedByTheHandlersVerification(): void
    {
        $violations = [
            'capability-declared-after-install' => [
                fn (): array => Capabilities::install(
                    'milpa/agent',
                    $this->vendor,
                    static fn (string $cmd): array => [0, ['Nothing to install or update']],
                    dryRun: false,
                    index: null,
                    vendorAfter: $this->vendor,
                ),
                'no apareció',
            ],
        ];

        $declared = array_map(
            static fn (DeclaredCondition $c): string => $c->name,
            $this->enable()->postconditions,
        );
        self::assertSame(
            array_keys($violations),
            $declared,
            'the map covers EXACTLY the declared postconditions — one declared without verification is red',
        );

        foreach ($violations as $condition => [$violate, $fragment]) {
            $result = $violate();

            self::assertFalse($result['ok'], "a run that cannot prove «{$condition}» must not report success");
            self::assertStringContainsString($fragment, (string) $result['error'], "the verdict names «{$condition}»'s gap");
        }
    }

    /** The declared artifacts and evidence exist, and dry_run stays a contract-free preview. */
    public function testArtifactsAndEvidenceAreDeclaredAndDryRunStillPreviews(): void
    {
        $enable = $this->enable();
        self::assertNotSame([], $enable->artifacts);
        self::assertStringContainsString('promise mismatch', (string) $enable->observableEvidence);

        $preview = Capabilities::install('milpa/agent', $this->vendor, dryRun: true);
        self::assertTrue($preview['ok']);
        self::assertSame('composer require milpa/agent', $preview['command'], 'the line shown IS the line that would run');
    }

    /** A dry run lowers only this call to read-only; the real install keeps its declared ceiling. */
    public function testDryRunDoesNotDemandConsentButTheRealInstallStillDoes(): void
    {
        $enable = $this->enable();
        $declared = $enable->effectCeiling();
        $dryRun = ['capability' => 'milpa/agent', 'dry_run' => true];

        self::assertSame(EffectProfile::readOnly()->toArray(), $enable->ceilingForCall($dryRun)->toArray());
        self::assertFalse(Consent::demanded($enable, $dryRun));
        self::assertSame($declared, $enable->effectCeiling(), 'the operation declaration does not change per call');

        $install = ['capability' => 'milpa/agent'];
        self::assertSame($declared->toArray(), $enable->ceilingForCall($install)->toArray());
        self::assertTrue(Consent::demanded($enable, $install));
    }

    private function enable(): Operation
    {
        foreach ((new CapabilityOperations())->operations() as $operation) {
            if ($operation->name === 'capabilities:enable') {
                return $operation;
            }
        }
        self::fail('capabilities:enable is not declared');
    }
}
