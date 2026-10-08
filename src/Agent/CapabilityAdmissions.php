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
 * @phpstan-type Verb array{verb: string, tool: string, description: string, mutating: bool, requiresConfirmation: bool, namedTarget: ?string, surfaces: ?list<string>, scopes: list<string>, effects: array<string, mixed>, state: array{paths: list<string>, source: string, refused?: string}|null, runs: array{how: string, why?: string, pre_image?: bool}, digest: string, standing: 'admitted'|'never'|'changed'|'added'|'withdrawn', not_admissible: ?string}
 * @phpstan-type Card array{capability: string, scope: string, permission: string, opens: list<Verb>, contract: string, not_admissible: ?string, withdrawn: array{by: string, at: string}|null, works: array{holders: list<string>}|null}
 */
final readonly class CapabilityAdmissions
{
    /** The intent session a passkey touch for admitting without a refusal is bound to: there is no agent session. */
    public const string INTENT_SESSION = 'identity:admit';

    /** The intent session a passkey touch for withdrawing an admission is bound to. */
    public const string WITHDRAW_INTENT_SESSION = 'identity:withdraw';

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

    /**
     * The same judgement for a seat's key and a verb already in hand.
     *
     * IN WORKS OR ADMITTED, NEVER BOTH (decisions/0590, rule 10). While a seat holds the capability's building
     * permit, no seat uses its verbs: an admission that covers the verb as it stands is kept and SUSPENDED, and the
     * refusal says so. The next admission of that capability closes the permit, and what did not change stands
     * again with no further act.
     */
    public function missingFor(string $seat, BuiltVerb $verb): ?MissingAdmission
    {
        $works = $this->inWorks($verb->capability) !== [];
        $lacks = $this->lacks($seat, $verb);
        if ($lacks !== null) {
            return $works ? new MissingAdmission($lacks->verb, $lacks->scope, $lacks->why, true) : $lacks;
        }

        return $works ? new MissingAdmission($verb, $this->admittedUnder($seat, $verb) ?? $verb->scopes()[0], MissingAdmission::IN_WORKS, true) : null;
    }

    /**
     * The live seats that hold a capability's building permit: it is in works while there is one.
     *
     * @return list<string>
     */
    public function inWorks(string $capability): array
    {
        return $this->ledger->permitHolders($capability);
    }

    /**
     * What each seat has admitted of a capability, as it is read — what a grant of its building permit would
     * suspend. Empty when nobody was admitted anything of it.
     *
     * @return list<array{seat: string, scopes: list<string>}>
     */
    public function admittedOf(string $capability): array
    {
        $out = [];
        foreach ($this->ledger->liveKeys() as $seat) {
            $scopes = array_keys($this->ledger->admissionsFor($seat)[$capability] ?? []);
            if ($scopes !== []) {
                $out[] = ['seat' => $seat, 'scopes' => array_map(MissingAdmission::spelled(...), $scopes)];
            }
        }

        return $out;
    }

    /** The scope a standing admission covers this verb under, as it is now — or null. */
    private function admittedUnder(string $seat, BuiltVerb $verb): ?string
    {
        $held = $this->ledger->admissionsFor($seat)[$verb->capability] ?? [];
        foreach ($verb->scopes() as $scope) {
            if (($held[$scope]['verbs'][$verb->operation->name] ?? null) === $verb->digest()) {
                return $scope;
            }
        }

        return null;
    }

    /**
     * Why this seat's own admissions do not cover the verb as its contract stands — or null: one does. This is
     * the contract's question alone; whether the capability is in works is {@see missingFor()}'s.
     */
    public function lacks(string $seat, BuiltVerb $verb): ?MissingAdmission
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

        // NEVER ADMITTED AND TAKEN BACK ARE NOT THE SAME THING TO READ (decisions/0590, rule 12): where no admission
        // stands under any of the verb's scopes and a person withdrew one, the refusal says so.
        if ($why === MissingAdmission::NEVER) {
            foreach ($verb->scopes() as $scope) {
                if ($this->withdrawalOf($seat, $verb->capability, $scope) !== null) {
                    return new MissingAdmission($verb, $scope, MissingAdmission::WITHDRAWN);
                }
            }
        }

        return new MissingAdmission($verb, $under ?? $verb->scopes()[0], $why);
    }

    /**
     * Who last took that scope of that capability out of the seat, and when — or null: nobody did, or a person
     * admitted it again since and it stands.
     *
     * @return array{by: string, at: string}|null
     */
    public function withdrawalOf(string $seat, string $capability, string $scope): ?array
    {
        if (isset($this->ledger->admissionsFor($seat)[$capability][$scope])) {
            return null;
        }
        $last = null;
        foreach ($this->ledger->withdrawalsFor($seat) as $line) {
            if ($line['capability'] === $capability && $line['scope'] === $scope) {
                $last = ['by' => $line['withdrawn_by'], 'at' => $line['at']];
            }
        }

        return $last;
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
     * Each scope no admission covers carries what a person must read to admit it without waiting for a refusal
     * (decisions/0597): the same card a refusal gets — `opens`, `not_admissible` — and `contract`, its digest.
     *
     * `withdrawn` is the trail of what persons took back from the seat (rule 12), oldest first; a scope that waits
     * because it was withdrawn says by whom.
     *
     * @return array{admitted: list<array{capability: string, scope: string, key: string, admitted_by: string, at: string, verbs: array<string, 'admitted'|'changed'|'gone'>}>, unadmitted: list<array{capability: string, scope: string, verbs: list<string>, ran_before: bool, contract: ?string, opens: list<Verb>, not_admissible: ?string, withdrawn: array{by: string, at: string}|null}>, withdrawn: list<array{capability: string, scope: string, verbs: list<string>, withdrawn_by: string, at: string, admitted_by: string}>}
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
                // `key` is the scope as the ledger keeps it: what a withdrawal names.
                $admitted[] = ['capability' => $capability, 'scope' => MissingAdmission::spelled($scope), 'key' => $scope, 'admitted_by' => $admission['admitted_by'], 'at' => $admission['at'], 'verbs' => $verbs, 'suspended' => $this->inWorks($capability)];
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
                $card = $this->card($seat, $capability, (string) $scope);
                $unadmitted[] = [
                    'capability' => $capability,
                    'scope' => MissingAdmission::spelled((string) $scope),
                    'verbs' => $group['verbs'],
                    'ran_before' => $group['ran'],
                    'contract' => $card['contract'] ?? null,
                    'opens' => $card['opens'] ?? [],
                    'not_admissible' => $card['not_admissible'] ?? null,
                    'withdrawn' => $card['withdrawn'] ?? null,
                    'works' => $card['works'] ?? null,
                ];
            }
        }

        // The trail as a person reads it: a verb admitted by itself is spelled, as in `admitted`.
        $withdrawn = array_map(static function (array $line): array {
            $line['scope'] = MissingAdmission::spelled($line['scope']);

            return $line;
        }, $this->ledger->withdrawalsFor($seat));

        // The building permits it holds of what this house built — each one keeps that capability in works.
        $permits = array_values(array_filter(
            $this->built->capabilities(),
            static fn (string $capability): bool => \in_array(FileEnrollmentStore::permitOf($capability), $scopes, true),
        ));

        return ['admitted' => $admitted, 'unadmitted' => $unadmitted, 'withdrawn' => $withdrawn, 'permits' => $permits, 'closures' => $this->ledger->closuresFor($seat)];
    }

    /**
     * The one scope of one capability this house has TODAY whose contract gives that digest — or null (greenhouse
     * decisions/0597). The digest carries the capability's name, the scope's and each verb's own digest, so it
     * names a group without anybody typing a capability or a scope; and a contract that moved since it was read
     * gives another digest, so it names nothing.
     *
     * @return Group|null
     */
    public function groupByDigest(string $digest): ?array
    {
        foreach ($this->built->capabilities() as $capability) {
            $scopes = [];
            foreach ($this->built->verbsOf($capability) as $verb) {
                $scopes = [...$scopes, ...$verb->scopes()];
            }
            foreach (array_unique($scopes) as $scope) {
                $group = $this->group($capability, $scope);
                if ($group !== null && hash_equals($group['contract'], $digest)) {
                    return $group;
                }
            }
        }

        return null;
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
                // The contract's standing, whatever the works: what changed stays in view while it is suspended.
                'standing' => $this->lacks($seat, $verb)->why ?? 'admitted',
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
            // Not part of what is approved: a fact about this seat, for the person who reads the card.
            'withdrawn' => $this->withdrawalOf($seat, $capability, $scope),
            // Nor is this: who holds the capability's building permit now — admitting takes it from them (rule 10).
            'works' => ($holders = $this->inWorks($capability)) === [] ? null : ['holders' => $holders],
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
