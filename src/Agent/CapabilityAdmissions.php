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
 * @phpstan-type Verb array{verb: string, tool: string, description: string, mutating: bool, requiresConfirmation: bool, namedTarget: ?string, surfaces: ?list<string>, scopes: list<string>, effects: array<string, mixed>, state: array{paths: list<string>, source: string, refused?: string}|null, runs: array{how: string, why?: string, pre_image?: bool}, digest: string, standing: 'admitted'|'never'|'changed'|'added', not_admissible: ?string}
 * @phpstan-type Card array{capability: string, scope: string, permission: string, opens: list<Verb>, contract: string, not_admissible: ?string}
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
     * What one seat holds of the capabilities built here, for a person to read (decisions/0590): what persons
     * admitted to it, verb by verb, with whether that still stands — `admitted`, `changed` since, or `gone` from
     * the capability — and what no standing admission covers.
     *
     * `ran_before` marks what this rule shut: a verb the seat's own words opened where only the declared word was
     * asked — it holds one of them, or the verb reads and asks for none. Nothing is migrated for it: a word
     * somebody typed carried no contract anybody saw. It is said, so a person finds it here and not when a seat
     * trips on it.
     *
     * @param list<string> $scopes the seat's own scopes
     *
     * @return array{admitted: list<array{capability: string, scope: string, admitted_by: string, at: string, verbs: array<string, 'admitted'|'changed'|'gone'>}>, unadmitted: list<array{capability: string, scope: string, verbs: list<string>, ran_before: bool}>}
     */
    public function holdingsOf(string $seat, array $scopes): array
    {
        $admitted = [];
        foreach ($this->ledger->admissionsFor($seat) as $capability => $byScope) {
            foreach ($byScope as $scope => $admission) {
                $verbs = [];
                foreach ($admission['verbs'] as $name => $digest) {
                    $verb = $this->built->verb($name);
                    $verbs[$name] = $verb === null || $verb->capability !== $capability ? 'gone' : ($verb->digest() === $digest ? 'admitted' : 'changed');
                }
                $admitted[] = ['capability' => $capability, 'scope' => MissingAdmission::spelled($scope), 'admitted_by' => $admission['admitted_by'], 'at' => $admission['at'], 'verbs' => $verbs];
            }
        }

        $unadmitted = [];
        foreach ($this->built->capabilities() as $capability) {
            $groups = [];
            foreach ($this->built->verbsOf($capability) as $verb) {
                if ($this->missingFor($seat, $verb) === null) {
                    continue;
                }
                $declared = $verb->operation->scopes;
                $ran = $declared === [] ? !$verb->operation->mutating && $verb->operation->permission === null : array_intersect($declared, $scopes) !== [];
                foreach ($verb->scopes() as $scope) {
                    $groups[$scope]['verbs'][] = $verb->operation->name;
                    $groups[$scope]['ran'] = ($groups[$scope]['ran'] ?? false) || $ran;
                }
            }
            ksort($groups);
            foreach ($groups as $scope => $group) {
                sort($group['verbs']);
                $unadmitted[] = ['capability' => $capability, 'scope' => MissingAdmission::spelled((string) $scope), 'verbs' => $group['verbs'], 'ran_before' => $group['ran']];
            }
        }

        return ['admitted' => $admitted, 'unadmitted' => $unadmitted];
    }

    /**
     * What a person is shown before admitting one scope of a capability to a seat (decisions/0590): every verb that
     * scope opens today — what it declares, where its work keeps its state and how a call of it would run in this
     * house, whether this seat already has it — the first reason the scope is not admissible, if any, and
     * `contract`: the digest of exactly this, which is what the admission approves. Null when the capability
     * declares nothing under that scope.
     *
     * @return Card|null
     */
    public function card(string $seat, string $capability, string $scope): ?array
    {
        $group = $this->group($capability, $scope);
        if ($group === null) {
            return null;
        }
        $opens = [];
        $notAdmissible = null;
        foreach ($this->verbsUnder($capability, $scope) as $verb) {
            $operation = $verb->operation;
            $notAdmissible ??= $verb->notAdmissible();
            $opens[] = [
                'verb' => $operation->name,
                'tool' => $verb->tool(),
                'description' => $operation->description,
                'mutating' => $operation->mutating,
                'requiresConfirmation' => $operation->requiresConfirmation,
                'namedTarget' => $operation->namedTarget,
                'surfaces' => $operation->surfaces,
                'scopes' => $operation->scopes,
                'effects' => $operation->effectCeiling()->toArray(),
                'state' => $verb->state(),
                'runs' => $this->built->runs($verb),
                'digest' => $verb->digest(),
                'standing' => $this->missingFor($seat, $verb)->why ?? 'admitted',
                'not_admissible' => $verb->notAdmissible(),
            ];
        }
        usort($opens, static fn (array $a, array $b): int => strcmp($a['verb'], $b['verb']));

        return [
            'capability' => $capability,
            'scope' => $scope,
            'permission' => MissingAdmission::spelled($scope),
            'opens' => $opens,
            'contract' => $group['contract'],
            'not_admissible' => $notAdmissible,
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
