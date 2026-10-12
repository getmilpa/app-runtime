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
        foreach ($this->ledger->kept() as $entry) {
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
        foreach ($this->ledger->kept() as $entry) {
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
        return $this->derived($this->ledger->kept(), $this->ledger->fault());
    }

    /**
     * Every grant these lines hold, each as it stands after the last of them.
     *
     * Asked of what a reading of the book keeps ({@see StandingLedger::kept()}) — and it answers the same of every
     * line of the book: what a reading lets go of is nothing this reads.
     *
     * @param list<array{type: string, at: string, prev: ?string, hash: string, data: array<string, mixed>}> $entries
     *
     * @return list<GrantRecord>
     */
    private function derived(array $entries, ?string $broken): array
    {
        /** @var array<string, array{grant: StandingGrant, entry: string, at: string, by: string, fault: ?string, state: GrantState, because: ?string, suspension: ?string, signal: ?\DateTimeImmutable, operations: array<string, string>, instruments: array<string, string>, stopped: bool, stoppers: list<string>}> $grants */
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
                    'operations' => $pins['operations'], 'instruments' => $pins['instruments'], 'stopped' => false, 'stoppers' => [], 'stops' => 0, 'first' => null,
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
                    // THE STOP THAT STANDS IS THE LAST ONE WRITTEN (the sixth review, 6-L1; it replaces «a fired takes
                    // the place of a pause and a pause does not take the place of a fired», which left a stop the door
                    // had named as written standing for nothing). A «fired» stops a grant in force, and one written
                    // over a stop a person could lift is now the stop that stands. Whoever lifts names the last one —
                    // and every line of this book names all the lines before it.
                    $standsIn = $known['state'] === GrantState::InForce || ($fired === true && $known['state'] === GrantState::Suspended && !$known['stopped']);
                    if (!\is_bool($fired) || $at === false || !$standsIn || !$named->operation->is($watcher)) {
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
                    $grants[$key] = self::stoppedBy($grants[$key], $entry['hash'], \sprintf(
                        'its falsifier «%s» fired: `%s` reported it, at %s, run by %s (suspension %s)',
                        $known['grant']->falsifier->name,
                        $known['grant']->falsifier->watcher->operation->forCli(),
                        $entry['at'],
                        self::said($data['who'] ?? null, 'someone'),
                        $entry['hash'],
                    ));
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
                $grants[$id]['stoppers'][] = \is_string($data['by'] ?? null) ? $data['by'] : '';
                $grants[$id] = self::stoppedBy($grants[$id], $entry['hash'], \sprintf(
                    '%s, at %s, by %s (suspension %s)',
                    self::said($data['reason'] ?? null, 'no reason was given'),
                    $entry['at'],
                    self::said($data['by'] ?? null, 'someone'),
                    $entry['hash'],
                ));
            } elseif ($entry['type'] === StandingLedger::REINSTATED) {
                // A REINSTATEMENT HOLDS ONLY WHILE THE RECOGNITION OF WHOEVER SIGNED IT STANDS (fourth review, M2 —
                // what Rod decided on 2026-10-12 for a grant's own line, applied to the line that lifts a stop). It was
                // read against who its signer is TODAY and nothing else: a second person lifted a suspension, left the
                // house — the grant was stopped again, the safe way — and was recognized again, which names no grant,
                // and the grant was in force with no signature that named it written since. So the line says which
                // recognition of its signer it was signed over, as a `granted` line does, and one that is not that
                // recognition any more — or that does not say — lifts nothing: a person lifts the suspension again.
                //
                // AND WHOEVER STOPPED IT BEFORE A LIFT WAS WRITTEN HAS NOT «ALREADY STOPPED» IT, WHETHER OR NOT THAT
                // LIFT HOLDS TODAY (the fifth review, 5-M1). A key's second stop of what it already stopped writes no
                // line (D15) — and «already» was read from this very derivation, which depends on today's
                // identities: while a grant was stopped only because its lift did not hold, a second fault a seat
                // saw left nothing in the book.
                $lifts = $grants[$id]['state'] === GrantState::Suspended && !$grants[$id]['stopped']
                    && $this->personSigned($data, 'standing:reinstate', $grants[$id]['grant'], ['grant' => $id, 'suspension' => $grants[$id]['suspension']])
                    && $this->isTheRecognitionItWasSignedOver($data);
                $grants[$id]['stoppers'] = [];
                if ($lifts) {
                    // In force again, and unwatched: the watcher's sign was forgotten when the suspension was written,
                    // and none is taken while a grant is suspended. It has to speak again.
                    $grants[$id]['state'] = GrantState::InForce;
                    $grants[$id]['because'] = null;
                    $grants[$id]['suspension'] = null;
                    $grants[$id]['stops'] = 0;
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
            array_values(array_unique($known['stoppers'])),
        ), $grants));
    }

    /**
     * A grant a person could still lift, stopped by one more line: that line is now the stop that stands, and what
     * is said of the grant says how many were written since it was last in force, and the first of them — a person
     * who lifts the last one is shown what it was written over.
     *
     * @param array<string, mixed> $known
     *
     * @return array<string, mixed>
     */
    private static function stoppedBy(array $known, string $line, string $because): array
    {
        $first = $known['stops'] === 0 ? $because : $known['first'];

        return ['state' => GrantState::Suspended, 'suspension' => $line, 'signal' => null, 'stops' => $known['stops'] + 1, 'first' => $first, 'because' => $known['stops'] === 0 ? $because : \sprintf(
            '%s — the last of %d stops written since it was last in force; the first: %s',
            $because,
            $known['stops'] + 1,
            $first,
        )] + $known;
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
     * Which state of the ledger's entry each of these keys stands in, as a line a person signed keeps it — which
     * names the recognition of each key that the line was written over.
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
            $recognized[IdentityKey::normalize($key)] = $enrollments->stateOf($key);
        }

        return $recognized;
    }

    /**
     * Every key a grant to these seats, signed by this key, is given over: each seat, the signer, and every key on
     * the line of who recognized each seat — the keys through which the signer answers for it.
     *
     * @param list<string> $seats
     *
     * @return list<string>
     */
    public function givenOver(array $seats, string $signer): array
    {
        $line = new EnrollmentLine(new FileEnrollmentStore($this->root . '/storage/identity/enrollments.json'));
        $keys = [...$seats, $signer];
        foreach ($seats as $seat) {
            foreach ($line->chainOf($seat) as $principal) {
                $key = EnrollmentLine::keyOf($principal);
                if ($key !== null) {
                    $keys[] = $key;
                }
            }
        }

        return array_values(array_unique(array_map(IdentityKey::normalize(...), $keys)));
    }

    /**
     * Why the recognitions a grant was given over are not the ones that stand today — or `null`: they are.
     *
     * A GRANT IS HONOURED ONLY WHILE THE RECOGNITION OF EACH OF ITS SEATS, AND OF ITS SIGNER, IS THE ONE ITS LINE WAS
     * WRITTEN OVER (third review, L4; decided by Rod 2026-10-12: what shrinks does not come back on its own). The
     * ledger of identities keeps no clock, so the grant's line says which state of each key's entry it was given
     * over, and the ledger keeps every state a key has had since ({@see self::notTheRecognitionItWas()}).
     * Recognizing a key names no grant.
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
        foreach ([...array_map(IdentityKey::normalize(...), $grant->to), $signer, ...array_map(strval(...), array_keys($kept))] as $key) {
            $why = $this->notTheRecognitionItWas($key, $kept, $key === $signer);
            if ($why !== null) {
                return \sprintf(
                    '%s %s since this grant was given — recognizing a key names no grant, so this one is not honoured until a person gives it again',
                    $key === $signer ? 'the key that signed it' : (\in_array($key, array_map(IdentityKey::normalize(...), $grant->to), true) ? 'seat ' : 'the key ') . self::short($key),
                    $why,
                );
            }
        }

        return null;
    }

    /**
     * Whether whoever signed this line is still the recognition of that key the line was signed over.
     *
     * @param array<string, mixed> $data a line a person signed
     */
    private function isTheRecognitionItWasSignedOver(array $data): bool
    {
        $kept = \is_array($data['recognized'] ?? null) ? $data['recognized'] : null;
        $signer = \is_string($data['fingerprint'] ?? null) ? IdentityKey::normalize($data['fingerprint']) : '';

        return $kept !== null && $signer !== '' && $this->notTheRecognitionItWas($signer, $kept, true) === null;
    }

    /**
     * What a key did since the state a line was written over that makes it another recognition — or `null`: it is
     * still that one.
     *
     * WHAT SHRINKS DOES NOT COME BACK ON ITS OWN — BY ANY WAY OF SHRINKING THE LEDGER KEEPS (the fifth review, 5-M1).
     * This counted how many times a key had LEFT the house, and `identity:enroll` over a live key replaces its state
     * in place: a signer whose word was taken that way and given back, and a seat that somebody else recognized in
     * place and its first enroller then recognized again, were «the same recognition» — a lift and a grant came
     * back with no signature that named them written since. The ledger keeps every state a key has had, in order.
     * So a line holds only while EVERY state of the key since the one it was written over is one in which the key
     * was still what the line needs:
     *
     *  - in the house: not withdrawn, and a state the ledger can read;
     *  - recognized by whoever had recognized it — the line of who answers for whom is read through that;
     *  - and, for the key that signed, a person: it held the word that recognizes identities.
     *
     * Words typed again by the same enroller, a scope more, an admission: none of those is ceasing to be it.
     *
     * @param array<string, mixed> $kept what the line says each key was when it was written
     */
    private function notTheRecognitionItWas(string $key, array $kept, bool $asSigner): ?string
    {
        $never = 'is not at a recognition the ledger holds for it — its line does not say which one it was given over, or says one that key never had —';
        $enrollments = new FileEnrollmentStore($this->root . '/storage/identity/enrollments.json');
        $state = $kept[$key] ?? null;
        if ($state === null && \array_key_exists($key, $kept)) {
            // A KEY THE LEDGER DID NOT HOLD WHEN THE LINE WAS WRITTEN, AND DOES NOT HOLD TODAY, IS THE SAME RECOGNITION
            // (the sixth review, 6-M5). The operator of a house — a key the ledger never recognized, by the
            // terminal's own rule — seats its first person, and so stands on the line of who answers for every seat
            // with no state at all: asked for a number, every grant of such a house was dead on arrival. The day
            // the ledger recognizes that key it is another one.
            return $enrollments->stateOf($key) === null ? null : 'was recognized by this house for the first time';
        }
        if (!\is_int($state)) {
            return $never;
        }
        $since = $enrollments->statesSince($key, $state);
        if ($since === null) {
            return $never;
        }
        $by = \is_array($since[0]) ? ($since[0]['authorized_by'] ?? null) : null;
        foreach ($since as $then) {
            if (!\is_array($then) || \array_key_exists('raw', $then) || ($then['revoked_by'] ?? null) !== null) {
                return 'left this house and was recognized again';
            }
            if (($then['authorized_by'] ?? null) !== $by) {
                return 'was recognized again by somebody else';
            }
            $scopes = \is_array($then['scopes'] ?? null) ? $then['scopes'] : [];
            if ($asSigner && !\in_array(self::AUTHORITY, $scopes, true) && !\in_array('*', $scopes, true)) {
                return 'stopped holding the word that makes it a person, and holding it again names nothing it signed';
            }
        }

        return null;
    }

    /**
     * Why a line that says a grant was given would not be honoured as this book reads it today — or `null`: it would.
     * What the door asks before it writes one ({@see StandingOperations}): the door and the book say the same thing.
     *
     * @param array<string, mixed> $line
     */
    public function asItWouldBeRead(array $line): ?string
    {
        $read = $this->grantOf($line);

        return $read === null ? 'it carries no document this house reads as a grant' : $read[1];
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
