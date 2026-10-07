<?php

/**
 * This file is part of Milpa App Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\AppRuntime\Identity\FileEnrollmentStore;

/**
 * The one judge of whether a seat may run a verb of a capability built in this house (greenhouse decisions/0590).
 *
 * For a seat — a key the house enrolled — such a verb runs only if a person admitted THAT verb with the contract it
 * has today. The word the verb declares as its scope is not asked: whoever writes a capability chooses it, and on
 * the published train a capability that named its scope after one the seat already held was read and written by it
 * with no human act. The gate and the seat's frontier both ask here, so what is refused and what is offered cannot
 * disagree.
 *
 * Who is not an enrolled key is not judged here: the terminal's operator, a passkey, a token and a key recognized
 * only by static policy keep being judged by the declared word, wherever that is done today.
 *
 * @phpstan-type Group array{capability: string, scope: string, verbs: array<string, string>, contract: string}
 */
final readonly class CapabilityAdmissions
{
    public function __construct(private FileEnrollmentStore $ledger, private BuiltCapabilities $built)
    {
    }

    /** The judge over an app root's ledger and the capabilities its kernel booted. */
    public static function forRoot(string $root, BuiltCapabilities $built): self
    {
        return new self(new FileEnrollmentStore($root . '/storage/identity/enrollments.json'), $built);
    }

    /** The enrolled key behind a principal, or null: not a `key:`, or one the ledger never recognized. */
    public function seatOf(?string $principal): ?string
    {
        if ($principal === null || !str_starts_with($principal, 'key:') || \strlen($principal) === 4) {
            return null;
        }
        $key = substr($principal, 4);

        return $this->ledger->contains($key) ? $key : null;
    }

    /** The built verb a tool names, or null. */
    public function verb(string $tool): ?BuiltVerb
    {
        return $this->built->verb($tool);
    }

    /**
     * What this principal's call to this tool lacks — or null: the tool is no built verb, the principal is no
     * enrolled key, or a standing admission covers the verb as it is declared today.
     */
    public function missing(?string $principal, string $tool): ?MissingAdmission
    {
        $verb = $this->built->verb($tool);
        $seat = $verb === null ? null : $this->seatOf($principal);

        return $verb === null || $seat === null ? null : $this->missingFor($seat, $verb);
    }

    /** The same judgement for a seat's key and a verb already in hand. */
    public function missingFor(string $seat, BuiltVerb $verb): ?MissingAdmission
    {
        $held = $this->ledger->admissionsFor($seat)[$verb->capability] ?? [];
        $name = $verb->operation->name;
        $digest = $verb->digest();
        $why = MissingAdmission::NEVER;
        $under = null;
        foreach ($verb->scopes() as $scope) {
            $admitted = $held[$scope]['verbs'] ?? null;
            if (!\is_array($admitted)) {
                continue;
            }
            if (($admitted[$name] ?? null) === $digest) {
                return null;
            }
            // The scope it was admitted under is the one to admit again: the card then shows what moved.
            if ($why !== MissingAdmission::CHANGED) {
                $why = \array_key_exists($name, $admitted) ? MissingAdmission::CHANGED : MissingAdmission::ADDED;
                $under = $scope;
            }
        }

        return new MissingAdmission($verb, $under ?? $verb->scopes()[0], $why);
    }

    /**
     * What one act of admission covers today: every verb the capability declares under that scope, each by the
     * digest of its contract — and the digest of the whole, which is what a person approves. Null when the
     * capability declares nothing under it.
     *
     * @return Group|null
     */
    public function group(string $capability, string $scope): ?array
    {
        $verbs = [];
        foreach ($this->built->verbsOf($capability) as $verb) {
            if (\in_array($scope, $verb->scopes(), true)) {
                $verbs[$verb->operation->name] = $verb->digest();
            }
        }
        if ($verbs === []) {
            return null;
        }
        ksort($verbs);

        return [
            'capability' => $capability,
            'scope' => $scope,
            'verbs' => $verbs,
            'contract' => BuiltVerb::digestOf(['capability' => $capability, 'scope' => $scope, 'verbs' => $verbs]),
        ];
    }

    /**
     * The verbs of that group, to be shown.
     *
     * @return list<BuiltVerb>
     */
    public function verbsUnder(string $capability, string $scope): array
    {
        return array_values(array_filter(
            $this->built->verbsOf($capability),
            static fn (BuiltVerb $verb): bool => \in_array($scope, $verb->scopes(), true),
        ));
    }
}
