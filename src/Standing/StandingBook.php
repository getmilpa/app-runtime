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
 *   · and WHAT SHRINKS NEVER STOPS APPLYING. Everything here is read against who each key is today, so
 *     a withdrawal or a replacement that held when it was written can stop holding for a reason that
 *     names no grant: its signer left, a key expired. It then stands as a suspension — never as
 *     nothing. A reinstatement that stops holding decays the safe way; these would decay the other.
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
        /** @var array<string, array{grant: StandingGrant, entry: string, at: string, by: string, fault: ?string, state: GrantState, because: ?string, suspension: ?string, signal: ?\DateTimeImmutable, operations: array<string, string>, instruments: array<string, string>}> $grants */
        $grants = [];

        foreach ($entries as $entry) {
            $data = $entry['data'];
            $id = \is_string($data['grant'] ?? null) ? $data['grant'] : null;

            if ($entry['type'] === StandingLedger::GRANTED) {
                $read = $this->grantOf($data);
                if ($read === null) {
                    continue; // not a grant anyone can read: it names nothing, so it lends nothing and refuses nothing
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
                    'operations' => $pins['operations'], 'instruments' => $pins['instruments'],
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
                // a line that took authority away is at least a stop, lifted like any other, by a person who names it.
                $older = $grant->supersedes;
                if ($older !== null && isset($grants[$older])
                    && !\in_array($grants[$older]['state'], [GrantState::Revoked, GrantState::Superseded], true)) {
                    $notTheirs = $fault ?? $this->notAPersonFor((string) ($data['fingerprint'] ?? ''), $grants[$older]['grant']->to);
                    if ($notTheirs === null && $grant->lifts === $grants[$older]['suspension']) {
                        $grants[$older]['state'] = GrantState::Superseded;
                        $grants[$older]['because'] = \sprintf('«%s» replaced it at %s', $grant->id, $entry['at']);
                        $grants[$older]['suspension'] = null;
                    } elseif ($notTheirs !== null) {
                        // In force or already stopped: like a second suspension, this one is now the stop that
                        // stands, and whoever lifts it names it — having seen it.
                        $grants[$older]['state'] = GrantState::Suspended;
                        $grants[$older]['suspension'] = $entry['hash'];
                        $grants[$older]['signal'] = null;
                        $grants[$older]['because'] = \sprintf(
                            '«%s» names it as replaced, at %s, and that replacement does not hold today (%s) — which stops this grant; it does not bring it back (suspension %s)',
                            $grant->id,
                            $entry['at'],
                            $notTheirs,
                            $entry['hash'],
                        );
                    }
                }

                continue;
            }

            if ($entry['type'] === StandingLedger::WATCHED) {
                // What a watcher saw speaks only for the grants whose falsifier describes exactly that call — a run
                // with an argument nobody described is not this falsifier being watched.
                $at = date_create_immutable($entry['at']);
                $fired = $data['fired'] ?? null;
                foreach ($grants as $key => $known) {
                    if (!\is_bool($fired) || $at === false || $known['state'] !== GrantState::InForce
                        || !$known['grant']->falsifier->watcher->matchesExactly((string) ($data['watcher'] ?? ''), \is_array($data['arguments'] ?? null) ? $data['arguments'] : [])) {
                        continue;
                    }
                    if ($fired === false) {
                        $grants[$key]['signal'] = $at;

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
                        \is_string($data['who'] ?? null) ? $data['who'] : 'someone',
                        $entry['hash'],
                    );
                }

                continue;
            }

            if ($id === null || !isset($grants[$id]) || \in_array($grants[$id]['state'], [GrantState::Superseded, GrantState::Revoked], true)) {
                continue;
            }

            if ($entry['type'] === StandingLedger::SUSPENDED) {
                $grants[$id]['state'] = GrantState::Suspended;
                $grants[$id]['suspension'] = $entry['hash'];
                $grants[$id]['signal'] = null;
                $grants[$id]['because'] = \sprintf(
                    '%s, at %s, by %s (suspension %s)',
                    \is_string($data['reason'] ?? null) && trim($data['reason']) !== '' ? trim($data['reason']) : 'no reason was given',
                    $entry['at'],
                    \is_string($data['by'] ?? null) ? $data['by'] : 'someone',
                    $entry['hash'],
                );
            } elseif ($entry['type'] === StandingLedger::REINSTATED) {
                if ($grants[$id]['state'] === GrantState::Suspended && $this->personSigned($data, 'standing:reinstate', $grants[$id]['grant'], ['grant' => $id, 'suspension' => $grants[$id]['suspension']])) {
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
                    // read as a person's stands as a suspension — and only a person, naming it, lifts it.
                    $grants[$id]['state'] = GrantState::Suspended;
                    $grants[$id]['suspension'] = $entry['hash'];
                    $grants[$id]['signal'] = null;
                    $grants[$id]['because'] = \sprintf(
                        'a withdrawal was written at %s that this house cannot read today as a person\'s — which stops this grant; it does not bring it back (suspension %s)',
                        $entry['at'],
                        $entry['hash'],
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
        ), $grants));
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

        return [$grant, $this->notAPersonFor($signed['signer']->fingerprint, $grant->to)];
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
