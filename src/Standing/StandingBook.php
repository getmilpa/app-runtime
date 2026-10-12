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

use Milpa\AppRuntime\Identity\EnrollmentLine;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityKey;
use Milpa\AppRuntime\Identity\SignerAuthority;
use Milpa\AppRuntime\Policy\PolicyConfig;
use Milpa\Command\Standing\Canonical;
use Milpa\Command\Standing\ConditionReceipt;
use Milpa\Command\Standing\GrantState;
use Milpa\Command\Standing\StandingGrant;
use Milpa\ToolRuntime\Identity\ExplainsRefusal;
use Milpa\ToolRuntime\Identity\GnupgSignatureVerifier;
use Milpa\ToolRuntime\Identity\OperationAuthorization;
use Milpa\ToolRuntime\Identity\SignatureRefusal;
use Milpa\ToolRuntime\Identity\SignatureVerifier;
use Milpa\ToolRuntime\Identity\VerifiedSigner;

/**
 * What the book of standing grants SAYS, read at this moment (greenhouse decisions/0615).
 *
 * The ledger keeps lines; this turns them into grants and where each one stands. Nothing it answers is
 * read from a stored verdict:
 *
 *   · a grant is the document inside the signed bytes of its line — never a copy kept beside them — and
 *     its signature is verified again on every read, against who the signer is TODAY;
 *   · a suspension stands the moment it is written, by anyone: shrinking gives nobody authority;
 *   · a reinstatement counts only when a person's signature covers exactly that grant AND the very
 *     suspension it lifts, so an old signature, a line written by hand, or a seat's own key lift nothing;
 *   · a grant is superseded only by a later grant that itself holds;
 *   · WHAT SHRINKS NEVER STOPS APPLYING. Everything here is read against who each key is today, so
 *     a withdrawal or a replacement that held when it was written can stop holding for a reason that
 *     names no grant: its signer left, a key expired. It then STOPS the grant it named — never
 *     nothing — and that stop is not a suspension a person lifts: only a NEW grant that names it
 *     (`supersedes` the grant, `lifts` that stop) takes it out of the way (decided by Rod, 2026-10-12).
 *     A reinstatement that stops holding decays the safe way; these would decay the other;
 *   · and A GRANT NAMES THE RECOGNITION IT WAS GIVEN OVER. A seat or a signer that left the house and
 *     was recognized again is not the one a person lent to: recognizing a key names no grant, so the
 *     grants given before it left are not honoured again — a person gives them again.
 *
 * WHO IS «A PERSON» HERE — and what this cannot know. The house does not know whether a key is held by
 * a person. It knows the key holds `identity:enroll` now, answers for every seat the grant lends to, and
 * is none of them: the same test greenhouse decisions/0586 uses, and no more than that.
 */
final class StandingBook
{
    /** The scope whose holder may give, restore or withdraw a standing grant — the one that recognizes identities. */
    public const AUTHORITY = 'identity:enroll';

    /** @var list<GrantRecord>|null */
    private ?array $records = null;

    public function __construct(
        private readonly string $root,
        private readonly StandingLedger $ledger,
        private readonly SignatureVerifier $verifier = new GnupgSignatureVerifier(),
        private readonly ?string $host = null,
    ) {
    }

    /** The book of the house at this root, verifying with the keyring of the process that asks. */
    public static function forRoot(string $root, ?SignatureVerifier $verifier = null): self
    {
        return new self(rtrim($root, '/'), StandingLedger::forRoot($root), $verifier ?? new GnupgSignatureVerifier());
    }

    /** The ledger this reads. */
    public function ledger(): StandingLedger
    {
        return $this->ledger;
    }

    /**
     * Every grant the book holds, in the order it was granted, each as it stands now.
     *
     * @return list<GrantRecord>
     */
    public function records(): array
    {
        return $this->records ??= $this->derive();
    }

    /**
     * The documents the book holds, read and NOT verified — for the one question that must stay cheap: does any
     * grant here even name this seat and this operation? Whatever answers yes is then verified in full
     * ({@see self::records()}); nothing is ever lent or refused on this reading alone.
     *
     * @return list<StandingGrant>
     */
    public function documents(): array
    {
        $documents = [];
        foreach ($this->ledger->entries() as $entry) {
            $grant = $entry['type'] === StandingLedger::GRANTED ? self::documentOf($entry['data']) : null;
            if ($grant !== null && !isset($documents[$grant->id])) {
                $documents[$grant->id] = $grant;
            }
        }

        return array_values($documents);
    }

    /** The grant under this id, or `null`. */
    public function record(string $id): ?GrantRecord
    {
        foreach ($this->records() as $record) {
            if ($record->grant->id === $id) {
                return $record;
            }
        }

        return null;
    }

    /**
     * Every receipt the house wrote, in the order it wrote them.
     *
     * @return list<ConditionReceipt>
     */
    public function receipts(): array
    {
        $receipts = [];
        foreach ($this->ledger->entries() as $entry) {
            $receipt = $entry['type'] === StandingLedger::MEASURED ? ConditionReceipt::fromArray($entry['data']) : null;
            if ($receipt !== null) {
                $receipts[] = $receipt;
            }
        }

        return $receipts;
    }

    /**
     * Why this key may not give, restore or withdraw a grant over these seats right now — or `null`: it may.
     *
     * Asked when a person acts, and asked again on every read of what they signed: the answer is about
     * today's ledger of identities, never about the day of the signature.
     *
     * @param list<string> $seats
     */
    public function notAPersonFor(string $fingerprint, array $seats): ?string
    {
        $enrollments = new FileEnrollmentStore($this->root . '/storage/identity/enrollments.json');
        try {
            // The terminal's own rule, asked the same way: a key the ledger never recognized and no policy
            // names is the terminal itself — and the seats below then decide, because it answers for none.
            $authority = (new SignerAuthority($enrollments, PolicyConfig::load($this->root)))->forSigner(new VerifiedSigner($fingerprint));
        } catch (\Throwable $error) {
            return 'who the signer is today cannot be read: ' . $error->getMessage();
        }
        $scopes = $authority === null ? ['*'] : $authority->scopes;
        if (!\in_array(self::AUTHORITY, $scopes, true) && !\in_array('*', $scopes, true)) {
            return \sprintf('the key that signed it does not hold `%s` today', self::AUTHORITY);
        }

        $line = new EnrollmentLine($enrollments);
        $signer = IdentityKey::normalize($fingerprint);
        foreach ($seats as $seat) {
            if ($enrollments->scopesFor($seat) === null) {
                return \sprintf('seat %s is not recognized in this house today', self::short($seat));
            }
            if (IdentityKey::normalize($seat) === $signer) {
                return 'the key that signed it is one of the seats it lends to — nobody lends to themselves';
            }
            if (!$line->answersFor('key:' . $fingerprint, $seat)) {
                return \sprintf('the key that signed it does not answer for seat %s — only whoever enrolled a seat lends to it', self::short($seat));
            }
        }

        return null;
    }

    /**
     * The signer of these bytes when they are a person's signed call to this operation, on this host — or the
     * sentence that says why they are not.
     *
     * @return array{signer: VerifiedSigner, authorization: OperationAuthorization}|string
     */
    public function signedCall(mixed $payload, mixed $signature, mixed $fingerprint, string $operation): array|string
    {
        if (!\is_string($payload) || $payload === '' || !\is_string($signature) || $signature === '') {
            return 'it carries no signed bytes to verify';
        }
        $signer = $this->verifier->verify($payload, $signature);
        if ($signer === null) {
            $refusal = $this->verifier instanceof ExplainsRefusal ? $this->verifier->whyNot($payload, $signature) : null;

            return $refusal === null
                ? 'its signature does not verify here — it was altered, the key expired or was revoked, or the keyring of this process does not hold the public key of whoever signed'
                : 'its signature does not verify here: ' . $refusal->sentence()
                    . ($refusal->reason === SignatureRefusal::MISSING_KEY ? ' — the process that judges needs the PUBLIC key of whoever signed' : '');
        }
        if (!\is_string($fingerprint) || IdentityKey::normalize($fingerprint) !== IdentityKey::normalize($signer->fingerprint)) {
            return 'the key it names is not the key that signed it';
        }
        $authorization = OperationAuthorization::fromCanonical($payload);
        if ($authorization === null || $authorization->operation !== $operation) {
            return \sprintf('what was signed is not a call to `%s`', $operation);
        }
        $host = $this->host ?? (gethostname() ?: 'unknown-host');
        if ($authorization->host !== $host) {
            return 'it was signed on another host, not on this one';
        }

        return ['signer' => $signer, 'authorization' => $authorization];
    }

    /** @return list<GrantRecord> */
    private function derive(): array
    {
        $entries = $this->ledger->entries();
        $broken = $this->ledger->fault();
        /** @var array<string, array{grant: StandingGrant, entry: string, at: string, by: string, fault: ?string, state: GrantState, because: ?string, suspension: ?string, signal: ?\DateTimeImmutable, operations: array<string, string>, instruments: array<string, string>, stopped: bool}> $grants */
        $grants = [];

        foreach ($entries as $entry) {
            $data = $entry['data'];
            $id = \is_string($data['grant'] ?? null) ? $data['grant'] : null;

            if ($entry['type'] === StandingLedger::GRANTED) {
                $read = $this->grantOf($data);
                if ($read === null) {
                    // Not a grant anyone can read. The ledger already says so — such a line is a FAULT of the book
                    // ({@see StandingLedger}), so every grant read here carries it and nothing is lent on any.
                    continue;
                }
                [$grant, $fault] = $read;
                if (isset($grants[$grant->id])) {
                    continue; // a second line under an id already taken is not that grant
                }
                $pins = ['operations' => [], 'instruments' => []];
                foreach (array_keys($pins) as $kind) {
                    foreach (\is_array($data[$kind] ?? null) ? $data[$kind] : [] as $operation => $digest) {
                        if (\is_string($operation) && \is_string($digest)) {
                            $pins[$kind][$operation] = $digest;
                        }
                    }
                }
                $grants[$grant->id] = [
                    'grant' => $grant, 'entry' => $entry['hash'], 'at' => $entry['at'],
                    'by' => 'key:' . (\is_string($data['fingerprint'] ?? null) ? $data['fingerprint'] : 'unknown'),
                    'fault' => $broken === null ? $fault : 'the book of standing grants does not verify (' . $broken . ')',
                    'state' => GrantState::InForce, 'because' => null, 'suspension' => null, 'signal' => null,
                    'operations' => $pins['operations'], 'instruments' => $pins['instruments'], 'stopped' => false,
                ];
                // TAKING ANOTHER GRANT OUT OF THE WAY IS HELD TO THE STANDARD OF REINSTATING IT (greenhouse
                // decisions/0615, «Lo que la revisión encontró», 6). The grant that supersedes holds; whoever signed it
                // answers for the seats of the grant it replaces, not only for its own; and when that grant is
                // SUSPENDED it names the very suspension it lifts — so a signature made before the suspension, or one
                // that never saw it, lifts nothing. Anything less leaves the older grant exactly where it stood.
                //
                // …AND A REPLACEMENT THAT DOES NOT HOLD STOPS WHAT IT NAMES; IT NEVER RESTORES IT («Lo que la segunda
                // revisión encontró», 2). All of this is read against who everyone is TODAY, so a replacement that held
                // yesterday can stop holding — the person who signed it is no longer one, a seat it lends to left the
                // house — with no act that names either grant. The older grant must not come back in force for that:
                // a line that took authority away is at least a stop. AND NOT ONE A PERSON LIFTS BY NAME (decided by
                // Rod, 2026-10-12): lifting it would bring back what a signed act replaced, on a signature that says
                // «reinstate». Only a new grant takes it out of the way — one that supersedes this grant and names
                // this stop in `lifts`, which is the branch right above.
                $older = $grant->supersedes;
                if ($older !== null && isset($grants[$older])
                    && !\in_array($grants[$older]['state'], [GrantState::Revoked, GrantState::Superseded], true)) {
                    $notTheirs = $fault ?? $this->notAPersonFor((string) ($data['fingerprint'] ?? ''), $grants[$older]['grant']->to);
                    if ($notTheirs === null && $grant->lifts === $grants[$older]['suspension']) {
                        $grants[$older]['state'] = GrantState::Superseded;
                        $grants[$older]['because'] = \sprintf('«%s» replaced it at %s', $grant->id, $entry['at']);
                        $grants[$older]['suspension'] = null;
                    } elseif ($notTheirs !== null) {
                        // In force or already stopped: this one is now the stop that stands.
                        $grants[$older]['state'] = GrantState::Suspended;
                        $grants[$older]['suspension'] = $entry['hash'];
                        $grants[$older]['signal'] = null;
                        $grants[$older]['stopped'] = true;
                        $grants[$older]['because'] = \sprintf(
                            '«%s» names it as replaced, at %s, and that replacement does not hold today (%s) — which stops this grant; it does not bring it back. %s',
                            $grant->id,
                            $entry['at'],
                            $notTheirs,
                            self::onlyAGrantLifts($older, $entry['hash']),
                        );
                    }
                }

                continue;
            }

            if ($entry['type'] === StandingLedger::WATCHED) {
                // WHAT SPEAKS FOR A GRANT IS READ CLOSED, AND WHAT STOPS IT IS READ OPEN (third review, M2; decided by
                // Rod 2026-10-12). A quiet sign keeps a grant alive, so it counts only from a run that is exactly the
                // one the falsifier describes — an argument nobody described is not this falsifier being watched —
                // AND made by the very operation the grant was given over (M1): a sign carries the pin of what
                // produced it. «It fired» only narrows: it stops every grant in force whose falsifier names that
                // operation, whatever arguments the run carried — one more, the fixed one missing or at another value
                // — and whoever answered. («Always», as it was completed for a red receipt: from any run.)
                $at = date_create_immutable($entry['at']);
                $fired = $data['fired'] ?? null;
                $watcher = (string) ($data['watcher'] ?? '');
                $arguments = \is_array($data['arguments'] ?? null) ? $data['arguments'] : [];
                foreach ($grants as $key => $known) {
                    $named = $known['grant']->falsifier->watcher;
                    if (!\is_bool($fired) || $at === false || $known['state'] !== GrantState::InForce || !$named->operation->is($watcher)) {
                        continue;
                    }
                    if ($fired === false) {
                        $pinned = $known['instruments'][$named->operation->canonical] ?? null;
                        if ($pinned !== null && ($data['pin'] ?? null) === $pinned && $named->matchesExactly($watcher, $arguments)) {
                            $grants[$key]['signal'] = $at;
                        }

                        continue;
                    }
                    // THE LINE THAT SAYS THE FALSIFIER FIRED IS THE SUSPENSION. Nothing else has to be written for it
                    // to stand: a second line that failed to land would have left the grant in force.
                    $grants[$key]['state'] = GrantState::Suspended;
                    $grants[$key]['suspension'] = $entry['hash'];
                    $grants[$key]['signal'] = null;
                    $grants[$key]['because'] = \sprintf(
                        'its falsifier «%s» fired: `%s` reported it, at %s, run by %s (suspension %s)',
                        $known['grant']->falsifier->name,
                        $known['grant']->falsifier->watcher->operation->forCli(),
                        $entry['at'],
                        self::said($data['who'] ?? null, 'someone'),
                        $entry['hash'],
                    );
                }

                continue;
            }

            if ($id === null || !isset($grants[$id]) || \in_array($grants[$id]['state'], [GrantState::Superseded, GrantState::Revoked], true)) {
                continue;
            }

            if ($entry['type'] === StandingLedger::SUSPENDED) {
                if ($grants[$id]['stopped']) {
                    // Already stopped by what only a new grant lifts. A suspension written on top of it is one a
                    // person could lift by name — and it must not take the place of the stop that stands.
                    continue;
                }
                $grants[$id]['state'] = GrantState::Suspended;
                $grants[$id]['suspension'] = $entry['hash'];
                $grants[$id]['signal'] = null;
                $grants[$id]['because'] = \sprintf(
                    '%s, at %s, by %s (suspension %s)',
                    self::said($data['reason'] ?? null, 'no reason was given'),
                    $entry['at'],
                    self::said($data['by'] ?? null, 'someone'),
                    $entry['hash'],
                );
            } elseif ($entry['type'] === StandingLedger::REINSTATED) {
                if ($grants[$id]['state'] === GrantState::Suspended && !$grants[$id]['stopped'] && $this->personSigned($data, 'standing:reinstate', $grants[$id]['grant'], ['grant' => $id, 'suspension' => $grants[$id]['suspension']])) {
                    // In force again, and unwatched: the watcher's sign was forgotten when the suspension was written,
                    // and none is taken while a grant is suspended. It has to speak again.
                    $grants[$id]['state'] = GrantState::InForce;
                    $grants[$id]['because'] = null;
                    $grants[$id]['suspension'] = null;
                }
            } elseif ($entry['type'] === StandingLedger::REVOKED) {
                if ($this->personSigned($data, 'standing:revoke', $grants[$id]['grant'], ['grant' => $id])) {
                    $grants[$id]['state'] = GrantState::Revoked;
                    $grants[$id]['suspension'] = null;
                    $grants[$id]['because'] = \sprintf('withdrawn at %s by key:%s', $entry['at'], (string) $data['fingerprint']);
                } else {
                    // A WITHDRAWAL THAT IS NOT A PERSON'S TODAY IS STILL A STOP (review, second, 2). Whoever signed it
                    // may have left the house since, or their key may no longer verify here: that is no reason for
                    // what they took back to return. Stopping is open to anyone, so a withdrawal the house cannot
                    // read as a person's stops the grant — AND NO REINSTATEMENT LIFTS IT (decided by Rod, 2026-10-12):
                    // one did, and a grant that had read «withdrawn — nothing brings it back» was in force again by one
                    // person's comings and goings. Only a new grant that names this stop takes it out of the way.
                    $grants[$id]['state'] = GrantState::Suspended;
                    $grants[$id]['suspension'] = $entry['hash'];
                    $grants[$id]['signal'] = null;
                    $grants[$id]['stopped'] = true;
                    $grants[$id]['because'] = \sprintf(
                        'a withdrawal was written at %s that this house cannot read today as a person\'s — which stops this grant; it does not bring it back. %s',
                        $entry['at'],
                        self::onlyAGrantLifts($id, $entry['hash']),
                    );
                }
            }
        }

        return array_values(array_map(static fn (array $known): GrantRecord => new GrantRecord(
            $known['grant'],
            $known['entry'],
            $known['at'],
            $known['by'],
            $known['fault'],
            $known['state'],
            $known['because'],
            $known['suspension'],
            $known['signal'],
            $known['operations'],
            $known['instruments'],
            $known['stopped'],
        ), $grants));
    }

    /**
     * A text a line of the book holds, the way the house says it to a person: as it is when it is one line of plain
     * text, spelled out when it is not, and `$otherwise` when the line does not say it.
     *
     * WHAT THE HOUSE PRINTS IS READ AS IT IS WRITTEN, the same as what a person signs (decided by Rod, 2026-10-12, for
     * every text a grant keeps). The reason of a stop is written by any seat the house recognizes — its door refuses
     * one that is not a line of plain text — and a line written by hand is written by whoever holds the disk: it
     * still stops what it names, and what a person reads of it is its spelling, never the bytes that would clear the
     * line a terminal printed them on.
     */
    public static function said(mixed $text, string $otherwise = ''): string
    {
        if (!\is_string($text) || trim($text) === '') {
            return $otherwise;
        }

        return Canonical::oneLine($text) ? $text : Canonical::shown($text);
    }

    /** What a stop that is not a suspension says of how it ends: by a new grant that names it, and by nothing else. */
    private static function onlyAGrantLifts(string $id, string $stop): string
    {
        return \sprintf('No reinstatement lifts this stop: only a new grant that names it does — one that says "supersedes": "%s" and "lifts": "%s"', $id, $stop);
    }

    /**
     * How many times each of these keys had left the house, as a `granted` line keeps it — which names the
     * recognition of each seat, and of the signer, that the grant is given over.
     *
     * @param list<string> $keys
     *
     * @return array<string, ?int>
     */
    public function recognitions(array $keys): array
    {
        $enrollments = new FileEnrollmentStore($this->root . '/storage/identity/enrollments.json');
        $recognized = [];
        foreach ($keys as $key) {
            $recognized[IdentityKey::normalize($key)] = $enrollments->departuresOf($key);
        }

        return $recognized;
    }

    /**
     * Why the recognitions a grant was given over are not the ones that stand today — or `null`: they are.
     *
     * A GRANT IS HONOURED ONLY WHILE THE RECOGNITION OF EACH OF ITS SEATS, AND OF ITS SIGNER, IS OLDER THAN ITS LINE
     * (third review, L4; decided by Rod 2026-10-12). The ledger of identities keeps no clock, so the grant's line
     * says which recognition of each key it was given over — by how many times that key had left — and a key that
     * left and was recognized again, by whoever, is another one. Recognizing a key names no grant.
     *
     * @param array<string, mixed> $data the `granted` line
     */
    private function notTheRecognitionsItWasGivenOver(array $data, StandingGrant $grant, string $signer): ?string
    {
        $kept = \is_array($data['recognized'] ?? null) ? $data['recognized'] : null;
        if ($kept === null) {
            return 'its line does not say which recognition of each seat, and of its signer, it was given over';
        }
        $signer = IdentityKey::normalize($signer);
        foreach ($this->recognitions([...$grant->to, $signer]) as $key => $today) {
            $key = (string) $key;
            if (!\array_key_exists($key, $kept) || $kept[$key] !== $today) {
                return \sprintf(
                    '%s left this house and was recognized again since this grant was given — recognizing a key names no grant, so this one is not honoured until a person gives it again',
                    $key === $signer ? 'the key that signed it' : 'seat ' . self::short($key),
                );
            }
        }

        return null;
    }

    /**
     * The grant a `granted` line carries and why it is not honoured now — or `null` when the line holds no
     * document that can be read as a grant.
     *
     * The document is read from the SIGNED bytes whenever they parse, so what is judged is what was signed.
     *
     * @param array<string, mixed> $data
     *
     * @return array{StandingGrant, ?string}|null
     */
    private function grantOf(array $data): ?array
    {
        $grant = self::documentOf($data);
        if ($grant === null) {
            return null;
        }

        $signed = $this->signedCall($data['payload'] ?? null, $data['signature'] ?? null, $data['fingerprint'] ?? null, 'standing:grant');
        if (\is_string($signed)) {
            return [$grant, $signed];
        }
        if (($data['digest'] ?? null) !== $grant->digest()) {
            return [$grant, 'the digest its line records is not the digest of what was signed'];
        }

        return [$grant, $this->notAPersonFor($signed['signer']->fingerprint, $grant->to) ?? $this->notTheRecognitionsItWasGivenOver($data, $grant, $signed['signer']->fingerprint)];
    }

    /**
     * Whether a line is a person's signed call to this operation with exactly these arguments, by someone who may
     * act on this grant today.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $arguments
     */
    private function personSigned(array $data, string $operation, StandingGrant $grant, array $arguments): bool
    {
        $signed = $this->signedCall($data['payload'] ?? null, $data['signature'] ?? null, $data['fingerprint'] ?? null, $operation);
        if (\is_string($signed)) {
            return false;
        }
        $given = $signed['authorization']->arguments;
        ksort($given);
        ksort($arguments);

        return $given === $arguments && $this->notAPersonFor($signed['signer']->fingerprint, $grant->to) === null;
    }

    /**
     * The document inside a `granted` line's signed bytes, or `null` when they hold none that reads as a grant.
     *
     * @param array<string, mixed> $data
     */
    private static function documentOf(array $data): ?StandingGrant
    {
        $authorization = \is_string($data['payload'] ?? null) ? OperationAuthorization::fromCanonical($data['payload']) : null;
        $text = $authorization?->arguments['grant'] ?? null;
        try {
            return \is_string($text) ? StandingGrant::fromJson($text) : null;
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private static function short(string $key): string
    {
        return \strlen($key) > 16 ? '…' . substr($key, -16) : $key;
    }
}
