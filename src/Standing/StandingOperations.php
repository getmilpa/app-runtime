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

namespace Milpa\AppRuntime\Standing;

use Milpa\AppRuntime\Agent\PluginAuthoringPolicy;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityKey;
use Milpa\Command\CommandProvider;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\InvocationContext;
use Milpa\Command\Operation;
use Milpa\Command\Standing\Canonical;
use Milpa\Command\Standing\GrantCondition;
use Milpa\Command\Standing\GrantState;
use Milpa\Command\Standing\NamedCall;
use Milpa\Command\Standing\StandingGrant;
use Milpa\Console\OperationBoundary;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Identity\GrantedAuthorization;
use Milpa\ToolRuntime\Identity\SignatureVerifier;

/**
 * The acts on a standing grant: give one, restore one, withdraw one, stop one, and read the book
 * (greenhouse decisions/0615).
 *
 * THE ASYMMETRY IS THE WHOLE DESIGN (greenhouse decisions/0613 §4).
 *
 *   · GROWING is a person's signed act, at the terminal, never through a surface a seat works through:
 *     `standing:grant`, `standing:reinstate`. The signature covers exactly that call — the whole document
 *     for a grant, the very suspension for a reinstatement — and is kept, to be verified again on every read.
 *   · SHRINKING is anyone's: `standing:suspend` asks for no signature and no scope, because stopping gives
 *     nobody authority. Its cost is accepted and written down: a faulty seat can hand every absorbed act
 *     back to the person. Nothing widens.
 *   · `standing:revoke` is a person's too — it is final, and a suspension already does the stopping.
 *
 * A house is born with no grant, and one where nobody gives one works exactly as it did.
 */
final class StandingOperations implements CommandProvider
{
    /**
     * @param string|null            $root     the house these act on; the kernel's own when none is handed in
     * @param SignatureVerifier|null $verifier who verifies what the book holds; gpg, with the keyring of this process, when none is handed in
     */
    public function __construct(
        private readonly DIContainerInterface $container,
        private readonly ?string $root = null,
        private readonly ?SignatureVerifier $verifier = null,
    ) {
    }

    /**
     * The five operations, each declared with its own effects.
     *
     * @return list<Operation>
     */
    public function operations(): array
    {
        // What a person's act on a grant is: it decides what the house lets a seat do without asking.
        $personal = new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::Compensatable, Authority::Privileged, subject: Subject::Configuration);
        $id = ['type' => 'string', 'description' => 'The id of the grant, as `standing:granted` shows it'];

        return [
            new Operation(
                name: 'standing:grant',
                description: 'Give a standing grant: the seats it names repeat the operations it names without asking — under its ceiling, on measured content, while its falsifier stays quiet. A person\'s signed act (greenhouse decisions/0615)',
                handler: fn (array $input): array => $this->grant($input),
                inputSchema: ['type' => 'object', 'properties' => [
                    'grant' => ['type' => 'string', 'description' => 'The grant, as one JSON object: id, to, covers, ceiling, conditions, undo, falsifier and, optionally, supersedes and expires_at'],
                ], 'required' => ['grant']],
                mutating: true,
                requiresConfirmation: true,
                scopes: [StandingBook::AUTHORITY],
                surfaces: ['cli'],
                effects: $personal,
            ),
            new Operation(
                name: 'standing:reinstate',
                description: 'Lift the suspension of a standing grant — a person\'s signed act that names the very suspension it lifts (greenhouse decisions/0615)',
                handler: fn (array $input): array => $this->decide($input, 'standing:reinstate'),
                inputSchema: ['type' => 'object', 'properties' => [
                    'grant' => $id,
                    'suspension' => ['type' => 'string', 'description' => 'The suspension to lift, by the digest `standing:granted` shows for it'],
                ], 'required' => ['grant', 'suspension']],
                mutating: true,
                requiresConfirmation: true,
                scopes: [StandingBook::AUTHORITY],
                surfaces: ['cli'],
                effects: $personal,
            ),
            new Operation(
                name: 'standing:revoke',
                description: 'Withdraw a standing grant for good — a person\'s signed act (greenhouse decisions/0615)',
                handler: fn (array $input): array => $this->decide($input, 'standing:revoke'),
                inputSchema: ['type' => 'object', 'properties' => ['grant' => $id], 'required' => ['grant']],
                mutating: true,
                requiresConfirmation: true,
                scopes: [StandingBook::AUTHORITY],
                surfaces: ['cli'],
                effects: $personal,
            ),
            // ANYONE THE HOUSE RECOGNIZES MAY STOP A GRANT — the seat that notices its own fault above all. It asks for
            // no scope because stopping gives nobody authority, and the house's policy lets it through for that reason
            // alone ({@see PluginAuthoringPolicy}). Not over HTTP: an operation with no scope is not served to a
            // caller nobody judged.
            new Operation(
                name: 'standing:suspend',
                description: 'Stop a standing grant: every act it covers is the person\'s again until a person lifts it. Anyone recognized here may ask — it only narrows (greenhouse decisions/0615)',
                handler: fn (array $input, ?InvocationContext $invocation = null, ?ToolContext $authority = null): array => $this->suspend($input, $authority),
                inputSchema: ['type' => 'object', 'properties' => [
                    'grant' => $id,
                    'reason' => ['type' => 'string', 'description' => 'What was seen, in words — it is what the person reads before lifting it'],
                ], 'required' => ['grant', 'reason']],
                mutating: true,
                surfaces: ['cli', 'mcp'],
                effects: new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::Compensatable, Authority::WriteAsUser, subject: Subject::Configuration),
            ),
            new Operation(
                name: 'standing:granted',
                description: 'The standing grants of this house: what each lends and to whom, where it stands and why, and what the house absorbed or refused under it (greenhouse decisions/0615)',
                handler: fn (array $input): array => $this->granted($input),
                inputSchema: ['type' => 'object', 'properties' => [
                    'grant' => ['type' => 'string', 'description' => 'One grant, by its id, with everything the book holds about it'],
                ]],
                mutating: false,
                surfaces: ['cli'],
                effects: EffectProfile::readOnly(),
            ),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function grant(array $input): array
    {
        $text = \is_string($input['grant'] ?? null) ? $input['grant'] : '';
        try {
            $grant = StandingGrant::fromJson($text);
        } catch (\InvalidArgumentException $refused) {
            return self::no($refused->getMessage() . ' — nothing was granted');
        }
        $granted = $this->signature('standing:grant', ['grant' => $text]);
        if (\is_string($granted)) {
            return self::no($granted . ' — nothing was granted');
        }
        $root = $this->root();
        $policy = $this->policy();
        if ($root === null || $policy === null) {
            return self::no('this house does not judge standing grants: its boundary is not the one that reads them, so a grant here would be a sentence nobody enforces — nothing was granted');
        }

        $book = StandingBook::forRoot($root, $this->verifier);
        $unverified = self::unverified($book);
        if ($unverified !== null) {
            return self::no($unverified . ' — nothing was granted');
        }
        if ($book->record($grant->id) !== null) {
            return self::no("a grant «{$grant->id}» already exists in this house. What a person signed is never edited: a changed grant takes a new id and names the one it supersedes — nothing was granted");
        }
        $older = $grant->supersedes === null ? null : $book->record($grant->supersedes);
        if ($grant->supersedes !== null && $older === null) {
            return self::no("«{$grant->id}» supersedes «{$grant->supersedes}», and this house has no such grant — nothing was granted");
        }
        foreach ($grant->to as $seat) {
            if (IdentityKey::normalize($seat) !== $seat) {
                return self::no('write each seat\'s key as the house shows it — ' . IdentityKey::normalize($seat) . ' — nothing was granted');
            }
        }
        $notAPerson = $book->notAPersonFor($granted->signer->fingerprint, $grant->to);
        if ($notAPerson !== null) {
            return self::no($notAPerson . ' — nothing was granted');
        }
        // TAKING A GRANT OUT OF THE WAY IS HELD TO THE STANDARD OF REINSTATING IT (greenhouse decisions/0615, «Lo que la
        // revisión encontró», 6): whoever supersedes answers for the seats of the grant it replaces, and when that
        // grant is suspended the new one names the very suspension it lifts.
        if ($older !== null) {
            if (\in_array($older->state, [GrantState::Revoked, GrantState::Superseded], true)) {
                return self::no(\sprintf('«%s» is already %s: there is nothing to supersede — give «%s» without «supersedes» — nothing was granted', $older->grant->id, $older->state->value, $grant->id));
            }
            $notTheirs = $book->notAPersonFor($granted->signer->fingerprint, $older->grant->to);
            if ($notTheirs !== null) {
                return self::no("«{$grant->id}» supersedes «{$older->grant->id}», and {$notTheirs} — nothing was granted");
            }
            if ($older->state === GrantState::Suspended && $grant->lifts !== $older->suspension) {
                return self::no(\sprintf(
                    '«%1$s» is %4$s (%2$s). A grant that supersedes it takes that stop out of the way, which is growing back: it names the stop it lifts — add "lifts": "%2$s" to «%3$s»%5$s — nothing was granted',
                    $older->grant->id,
                    (string) $older->suspension,
                    $grant->id,
                    $older->stopped ? 'stopped' : 'suspended',
                    $older->stopped ? '' : ' — or a person lifts it first',
                ));
            }
            if ($older->state !== GrantState::Suspended && $grant->lifts !== null) {
                return self::no("«{$grant->id}» says it lifts a suspension of «{$older->grant->id}», and no suspension stands on it — nothing was granted");
            }
        }

        $enrollments = new FileEnrollmentStore($root . '/storage/identity/enrollments.json');
        $warnings = [];
        $operations = [];
        foreach ($grant->covers as $act) {
            $operation = $policy->operationNamed($act->operation);
            if ($operation === null) {
                return self::no('this house has no operation `' . $act->operation->forCli() . '` to grant — nothing was granted');
            }
            // A VERB OF A CAPABILITY BUILT IN THIS HOUSE IS NOT GRANTED (decided by Rod, 2026-10-12). What opens such
            // a verb to a seat is a person's admission of that verb (greenhouse decisions/0590), asked before any
            // grant is: a grant over it would be signed, written and listed, and would never lend — measured on a
            // real leg, where the seat was told to ask for the admission.
            $built = $policy->admissions()?->verb($operation->name);
            if ($built !== null) {
                return self::no(\sprintf(
                    '`%s` is a verb of «%s», a capability built in this house. What opens it to a seat is a person admitting that verb, not a standing grant: one over it would never lend — nothing was granted',
                    $act->operation->forCli(),
                    $built->capability,
                ));
            }
            $refusal = GrantableOperation::refusal($operation);
            if ($refusal !== null) {
                return self::no($refusal . ' — nothing was granted');
            }
            // What the operation IS today, written beside the grant: the day it is something else — an update, a
            // plugin registered again under the same name — it is not the operation a person granted.
            $pin = OperationPin::of($operation, $root);
            if ($pin === null) {
                return self::no(\sprintf('`%s` cannot be pinned: %s. A grant is given over what an operation is — nothing was granted', $act->operation->forCli(), (string) OperationPin::whyNot($operation, $root)));
            }
            $operations[$act->operation->canonical] = $pin;
            if (!$operation->effectCeiling()->isNoWiderThan($grant->ceiling)) {
                $warnings[] = \sprintf('`%s` can do more than this grant\'s ceiling allows: only calls whose arguments bring its effect under the ceiling will pass', $act->operation->forCli());
            }
            foreach ($grant->to as $seat) {
                $held = array_values(array_intersect($enrollments->scopesFor($seat) ?? [], $operation->scopes));
                if ($held !== []) {
                    $warnings[] = \sprintf('seat …%s already holds «%s» on its own: from now on this grant governs its calls to `%s` that the grant names', substr($seat, -16), implode('», «', $held), $act->operation->forCli());
                }
            }
        }
        // THE INSTRUMENTS AND THE WATCHER ARE WRITTEN DOWN TOO (greenhouse decisions/0615, «Lo que la segunda revisión
        // encontró», 4). A grant asks that something be MEASURED by an operation and WATCHED by another; under their
        // names alone, a softer one registered later measures green what the first measured red. So what each of them
        // is today is kept beside the grant, like the operations it covers.
        $instruments = [];
        foreach ($grant->conditions as $condition) {
            $instrument = $policy->operationNamed($condition->by->operation);
            $pin = $instrument?->instrumentDigest() === null ? null : OperationPin::of($instrument, $root);
            if ($pin === null) {
                return self::no(\sprintf(
                    'condition «%s» is measured by `%s`, and this house cannot use it as an instrument: %s. A condition nothing can measure does not fit in a grant — nothing was granted',
                    $condition->name,
                    $condition->by->operation->forCli(),
                    $instrument === null ? 'it does not exist here' : ($instrument->instrumentDigest() === null ? 'it does not say what it acts on, or its handler cannot be read' : (string) OperationPin::whyNot($instrument, $root)),
                ));
            }
            $instruments[$condition->by->operation->canonical] = $pin;
        }
        $watcher = $policy->operationNamed($grant->falsifier->watcher->operation);
        if ($watcher === null) {
            return self::no(\sprintf('falsifier «%s» is watched by `%s`, which this house does not have. A position nothing watches is not granted — nothing was granted', $grant->falsifier->name, $grant->falsifier->watcher->operation->forCli()));
        }
        $pin = OperationPin::of($watcher, $root);
        if ($pin === null) {
            return self::no(\sprintf('falsifier «%s» is watched by `%s`, which this house cannot pin: %s. It could not tell the day another watcher took its name — nothing was granted', $grant->falsifier->name, $grant->falsifier->watcher->operation->forCli(), (string) OperationPin::whyNot($watcher, $root)));
        }
        $instruments[$grant->falsifier->watcher->operation->canonical] = $pin;

        try {
            $entry = $book->ledger()->append(StandingLedger::GRANTED, [
                'grant' => $grant->id,
                'digest' => $grant->digest(),
                'payload' => $granted->payload,
                'signature' => $granted->signature,
                'fingerprint' => $granted->signer->fingerprint,
                'operations' => $operations,
                'instruments' => $instruments,
                // …and WHICH RECOGNITION of each seat, and of whoever signs, it is given over: a key that leaves the
                // house and is recognized again is not the one this grant lent to ({@see StandingBook}).
                'recognized' => $book->recognitions([...$grant->to, $granted->signer->fingerprint]),
            ]);
        } catch (\RuntimeException $error) {
            return self::no('nothing was granted: ' . $error->getMessage());
        }

        $watcher = $grant->falsifier->watcher->operation->forCli();

        return [
            'ok' => true,
            'grant' => $grant->id,
            'digest' => $grant->digest(),
            'granted_by' => 'key:' . $granted->signer->fingerprint,
            'at' => $entry['at'],
            'lends_to' => $grant->to,
            'covers' => array_map(static fn (NamedCall $act): string => self::spelled($act), $grant->covers),
            'conditions' => array_map(static fn (GrantCondition $condition): string => $condition->name . ' — measured by ' . self::spelled($condition->by), $grant->conditions),
            'watched_by' => self::spelled($grant->falsifier->watcher) . ', every ' . $grant->falsifier->withinSeconds . ' s at most',
            'supersedes' => $grant->supersedes,
            'next' => "It lends nothing until its watcher has given a sign of life: run `{$watcher}`. It cannot bound what a call spends — this house has no way to tell which operation spends.",
            'warnings' => $warnings,
        ];
    }

    /**
     * Lift a suspension, or withdraw a grant — a person's signed act over exactly this call.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function decide(array $input, string $operation): array
    {
        $reinstating = $operation === 'standing:reinstate';
        $nothing = $reinstating ? ' — nothing was lifted' : ' — nothing was withdrawn';
        $id = \is_string($input['grant'] ?? null) ? trim($input['grant']) : '';
        $suspension = \is_string($input['suspension'] ?? null) ? trim($input['suspension']) : '';
        $granted = $this->signature($operation, ['grant' => $id] + ($reinstating ? ['suspension' => $suspension] : []));
        if (\is_string($granted)) {
            return self::no($granted . $nothing);
        }
        $root = $this->root();
        $book = $root === null ? null : StandingBook::forRoot($root, $this->verifier);
        $unverified = self::unverified($book);
        if ($unverified !== null) {
            return self::no($unverified . $nothing);
        }
        $record = $book?->record($id);
        if ($book === null || $record === null) {
            return self::no("this house has no standing grant «{$id}»{$nothing}");
        }

        if ($reinstating) {
            if ($record->state !== GrantState::Suspended) {
                return self::no(\sprintf('«%s» is not suspended — it is %s%s', $id, str_replace('_', ' ', $record->state->value), $nothing));
            }
            if ($record->stopped) {
                return self::no(\sprintf(
                    '«%1$s» is stopped by a withdrawal or a replacement that does not hold today, and a reinstatement does not lift that: it would bring back what a signed act took away. Only a new grant that names it does — one that says "supersedes": "%1$s" and "lifts": "%2$s"%3$s',
                    $id,
                    (string) $record->suspension,
                    $nothing,
                ));
            }
            if ($record->suspension !== $suspension) {
                return self::no("the suspension that stands on «{$id}» is {$record->suspension}; a signature lifts the one it names and no other{$nothing}");
            }
            if ($record->fault !== null) {
                return self::no("«{$id}» is not honoured: {$record->fault}. Lifting its suspension would restore nothing; a person gives it again{$nothing}");
            }
        } elseif (\in_array($record->state, [GrantState::Revoked, GrantState::Superseded], true)) {
            return self::no(\sprintf('«%s» is already %s%s', $id, $record->state->value, $nothing));
        }
        $notAPerson = $book->notAPersonFor($granted->signer->fingerprint, $record->grant->to);
        if ($notAPerson !== null) {
            return self::no($notAPerson . $nothing);
        }

        try {
            $entry = $book->ledger()->append($reinstating ? StandingLedger::REINSTATED : StandingLedger::REVOKED, [
                'grant' => $id,
                'payload' => $granted->payload,
                'signature' => $granted->signature,
                'fingerprint' => $granted->signer->fingerprint,
            ] + ($reinstating ? ['suspension' => $suspension] : []));
        } catch (\RuntimeException $error) {
            return self::no($error->getMessage() . $nothing);
        }
        $watcher = $record->grant->falsifier->watcher->operation->forCli();

        return [
            'ok' => true,
            'grant' => $id,
            'by' => 'key:' . $granted->signer->fingerprint,
            'at' => $entry['at'],
            'stands_as' => $reinstating ? 'in force' : 'revoked',
            'next' => $reinstating
                ? "It lends nothing until its watcher gives a sign of life again: run `{$watcher}`. A sign from before the suspension does not count."
                : 'It lends nothing from this moment. What the house absorbed under it stays absorbed.',
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function suspend(array $input, ?ToolContext $authority): array
    {
        $id = \is_string($input['grant'] ?? null) ? trim($input['grant']) : '';
        $reason = \is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if ($reason === '') {
            return self::no('say what was seen, in `reason`: it is what the person reads before lifting it — nothing was suspended');
        }
        // …AND IT IS READ AS IT IS WRITTEN. The reason is printed by the listing of grants and by the judge's own
        // refusal, and whoever writes it is any seat the house recognizes: one that carried what a terminal obeys
        // read «in force — nothing was stopped» over a grant it had just stopped. The same rule as every text a grant
        // keeps (decided by Rod, 2026-10-12).
        if (!Canonical::oneLine($reason)) {
            return self::no(Canonical::notOneLine('say what was seen, in `reason`,', $reason) . ' — nothing was suspended');
        }
        $root = $this->root();
        $book = $root === null ? null : StandingBook::forRoot($root, $this->verifier);
        $unverified = self::unverified($book);
        if ($unverified !== null) {
            return self::no($unverified . ' — nothing was suspended');
        }
        $record = $book?->record($id);
        if ($book === null || $record === null) {
            return self::no("this house has no standing grant «{$id}» — nothing was suspended");
        }
        if (\in_array($record->state, [GrantState::Revoked, GrantState::Superseded], true) || $record->stopped) {
            return self::no(\sprintf('«%s» is already %s: it lends nothing, and there is nothing to stop', $id, $record->stopped ? 'stopped, by what only a new grant lifts' : $record->state->value));
        }
        try {
            $entry = $book->ledger()->append(StandingLedger::SUSPENDED, [
                'grant' => $id,
                'by' => $authority === null ? 'local-shell' : ($authority->principal ?? 'unknown'),
                'reason' => $reason,
            ]);
        } catch (\RuntimeException $error) {
            return self::no('nothing was suspended: ' . $error->getMessage());
        }

        return [
            'ok' => true,
            'grant' => $id,
            'stands_as' => 'suspended',
            'suspension' => $entry['hash'],
            'at' => $entry['at'],
            'next' => "Every act «{$id}» covers is the person's again. Only a person lifts it: standing:reinstate --grant={$id} --suspension={$entry['hash']} --sign",
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function granted(array $input): array
    {
        $root = $this->root();
        $book = $root === null ? null : StandingBook::forRoot($root, $this->verifier);
        if ($book === null || !$book->ledger()->exists()) {
            return ['ok' => true, 'grants' => [], 'note' => 'Nobody has given a standing grant in this house. A person gives one with standing:grant --sign.'];
        }
        $now = new \DateTimeImmutable();
        $only = \is_string($input['grant'] ?? null) ? trim($input['grant']) : null;
        $policy = $this->policy();
        $grants = [];
        foreach ($book->records() as $record) {
            if ($only !== null && $only !== '' && $record->grant->id !== $only) {
                continue;
            }
            // WHAT IS SHOWN IS WHAT THE JUDGE HONOURS (third review, L3). The book says where a grant stands; whether
            // the operations it was given over are still those is the judge's question — and a grant the book has in
            // force under which nothing runs was shown as «in force», with nothing to look at.
            $stands = $record->standsAs($now);
            $unhonoured = $stands === 'in force' && $policy !== null ? $policy->standing()->notHonoured($record, $policy->operationNamed(...)) : [];
            $nothingRuns = $unhonoured !== [] && \count($unhonoured) === \count(array_unique(array_map(static fn (NamedCall $act): string => $act->operation->canonical, $record->grant->covers)));
            $grants[] = [
                'grant' => $record->grant->id,
                'stands_as' => $nothingRuns ? 'not honoured' : $stands,
                'because' => $record->why() ?? ($nothingRuns ? implode(' · ', array_values(array_unique($unhonoured))) : null),
                'not_honoured_for' => $unhonoured === [] || $nothingRuns ? null : $unhonoured,
                'suspension' => $record->suspension,
                'granted_by' => $record->grantedBy,
                'granted_at' => $record->grantedAt,
                'lends_to' => $record->grant->to,
                'covers' => array_map(static fn (NamedCall $act): string => self::spelled($act), $record->grant->covers),
                'ceiling' => $record->grant->toArray()['ceiling'],
                'conditions' => array_map(static fn (GrantCondition $condition): string => $condition->name . ' — ' . $condition->statement . ' — measured by ' . self::spelled($condition->by), $record->grant->conditions),
                'undo' => $record->grant->undo,
                'falsifier' => $record->grant->falsifier->name . ' — ' . $record->grant->falsifier->statement,
                'watched_by' => self::spelled($record->grant->falsifier->watcher) . ', every ' . $record->grant->falsifier->withinSeconds . ' s at most',
                'watcher_last_sign' => $record->watcherSignalAt?->format(\DATE_ATOM),
                'expires_at' => $record->grant->expiresAt?->format(\DATE_ATOM),
            ];
        }

        $recent = [];
        foreach (\array_slice($book->ledger()->entries(), -40) as $entry) {
            if ($only !== null && $only !== '' && isset($entry['data']['grant']) && $entry['data']['grant'] !== $only) {
                continue;
            }
            $data = $entry['data'];
            $recent[] = trim(\sprintf(
                '%s  %s  %s',
                $entry['at'],
                $entry['type'],
                match ($entry['type']) {
                    // Every text of a line is said as it is spelled when it is not one line of plain text.
                    StandingLedger::MEASURED => \sprintf('%s on %s: %s', StandingBook::said($data['by'] ?? null), StandingBook::said($data['subject'] ?? null), ($data['ok'] ?? false) === true ? 'green' : 'red'),
                    StandingLedger::WATCHED => \sprintf('%s: %s', StandingBook::said($data['watcher'] ?? null), ($data['fired'] ?? null) === true ? 'FIRED' : 'quiet'),
                    StandingLedger::ABSORBED => \sprintf('«%s» %s on %s', StandingBook::said($data['grant'] ?? null), StandingBook::said($data['operation'] ?? null), StandingBook::said($data['subject'] ?? null)),
                    StandingLedger::REFUSED => \sprintf('«%s» %s: %s%s', StandingBook::said($data['grant'] ?? null), StandingBook::said($data['operation'] ?? null), StandingBook::said($data['reason'] ?? null), \is_string($data['then'] ?? null) ? ' — ' . StandingBook::said($data['then']) : ''),
                    StandingLedger::SUSPENDED => \sprintf('«%s» by %s: %s', StandingBook::said($data['grant'] ?? null), StandingBook::said($data['by'] ?? null), StandingBook::said($data['reason'] ?? null)),
                    default => '«' . StandingBook::said($data['grant'] ?? null) . '»',
                },
            ));
        }

        return [
            'ok' => true,
            'grants' => $grants,
            'recent' => $recent,
        ] + ($book->ledger()->fault() === null ? [] : ['book' => 'DOES NOT VERIFY: ' . $book->ledger()->fault() . '. No grant in it is honoured until a person has looked.']);
    }

    /**
     * The signature this call ran under, when it covers exactly this operation with exactly these arguments —
     * otherwise the sentence that says what is missing.
     *
     * @param array<string, mixed> $arguments
     */
    private function signature(string $operation, array $arguments): GrantedAuthorization|string
    {
        $granted = $this->container->has(GrantedAuthorization::class) ? $this->container->get(GrantedAuthorization::class) : null;
        if (!$granted instanceof GrantedAuthorization) {
            return "{$operation} requires the signature that names WHO decides; re-run with --sign";
        }
        $signed = $granted->authorization->arguments;
        ksort($signed);
        ksort($arguments);
        if ($granted->authorization->operation !== $operation || $signed !== $arguments) {
            return "the granted signature does not cover THIS {$operation}";
        }

        return $granted;
    }

    /** The house's own policy, when it is the boundary every operation runs through — the one that judges grants. */
    private function policy(): ?PluginAuthoringPolicy
    {
        $boundary = $this->container->has(OperationBoundary::class) ? $this->container->get(OperationBoundary::class) : null;

        return $boundary instanceof PluginAuthoringPolicy ? $boundary : null;
    }

    private function root(): ?string
    {
        if ($this->root !== null) {
            return rtrim($this->root, '/');
        }
        $kernel = $this->container->has(\Milpa\Runtime\Kernel::class) ? $this->container->get(\Milpa\Runtime\Kernel::class) : null;

        return $kernel instanceof \Milpa\Runtime\Kernel ? $kernel->root() : null;
    }

    /**
     * A named call as a person reads it before signing and in every listing: each fixed value AS IT IS SPELLED, AND
     * WHOLE. The word «true» and the answer true are two values a call is compared with: printed bare, the pair was
     * one.
     *
     * NEVER THROUGH {@see Canonical::shown()} ALONE (the contract's tenth review, L1; greenhouse decisions/0615). That
     * is how a REFUSAL says a value back, and it stops at a line: two values a grant can fix that differ after their
     * 95th byte were the same line here, and whoever drafted the grant chose what stood after it. A fixed value is
     * visible ASCII by the contract's own rule, so its JSON spelling is safe to print in full — and should a value
     * ever hold anything else, that spelling writes it as an escape.
     */
    private static function spelled(NamedCall $call): string
    {
        $arguments = [];
        foreach ($call->arguments as $name => $value) {
            $arguments[] = $name . '=' . json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        }

        return $call->operation->forCli() . ($arguments === [] ? '' : ' (' . implode(', ', $arguments) . ')');
    }

    /**
     * Why nothing is added to this book — or `null`: it verifies.
     *
     * A BOOK THAT DOES NOT VERIFY TAKES NO LINE, FROM ANYONE: not a grant, not a reinstatement, not a withdrawal, not
     * even a suspension. And it is said in those words, before any grant is looked up in it — a book broken at its
     * first line holds no grant at all, and «this house has no such grant» would name the wrong thing to fix.
     */
    private static function unverified(?StandingBook $book): ?string
    {
        $fault = $book?->ledger()->fault();

        return $fault === null ? null : 'the book of standing grants does not verify (' . $fault . '); a person looks at it before anything is added';
    }

    /** @return array{ok: false, error: string} */
    private static function no(string $error): array
    {
        return ['ok' => false, 'error' => $error];
    }
}
