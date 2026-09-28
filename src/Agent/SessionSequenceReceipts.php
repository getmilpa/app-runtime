<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\SessionStore;
use Milpa\Console\SequenceReceipts;
use Milpa\ToolRuntime\Identity\GrantedAuthorization;

/**
 * The sequence receipts of this app, kept in the session a sequence runs in (greenhouse
 * decisions/0458, 0500).
 *
 * A sequence here IS a session: `agent --session` drives one leg by leg, and `recipe:apply` pauses
 * and resumes inside `recipe:<name>`. So the receipt lives in that session's stream, next to what
 * the sequence did, and whoever audits a leg reads which signature it ran under from the same place.
 *
 * THE STORE IS RESOLVED ONLY WHEN ASKED. The terminal door consults this book only for an operation
 * that declares a sequence; resolving the session store for every command would create `var/` and
 * open the event log for `coa list`.
 */
final class SessionSequenceReceipts implements SequenceReceipts
{
    private ?SessionStore $store = null;

    private bool $resolved = false;

    /**
     * @param \Closure(): ?SessionStore $sessions resolves this app's session store, or null when it has none
     */
    public function __construct(private readonly \Closure $sessions)
    {
    }

    /**
     * Keep the receipt in the sequence's session — only when that session exists and the call did
     * not already end it.
     *
     * A call that failed before its handler opened the session leaves nothing to continue, and a
     * receipt appended to a stream nobody started would be read back as a session of its own. A
     * recipe applied in one signed call, or a task the house closed in its first leg, has nothing
     * left to continue either: keeping the receipt would leave a key standing after the sequence.
     */
    public function record(string $sequence, string $operation, GrantedAuthorization $granted, mixed $result): void
    {
        $store = $this->store();
        if ($store === null || $store->load($sequence) === null || self::ended($operation, $result) !== null) {
            return;
        }

        $store->authorizeSequence($sequence, $operation, [
            'payload' => $granted->payload,
            'signature' => $granted->signature,
            'fingerprint' => $granted->signer->fingerprint,
            'uid' => $granted->signer->uid,
        ]);
    }

    /**
     * The receipt the session's fold still holds — none once released, or once the session ended.
     *
     * @return array{operation: string, payload: string, signature: string, fingerprint: string, uid?: ?string}|null
     */
    public function standing(string $sequence): ?array
    {
        $standing = $this->store()?->load($sequence)?->sequenceAuthorization();
        if ($standing === null) {
            return null;
        }
        $receipt = $standing['receipt'];
        $payload = $receipt['payload'] ?? null;
        $signature = $receipt['signature'] ?? null;
        $fingerprint = $receipt['fingerprint'] ?? null;
        if (!\is_string($payload) || !\is_string($signature) || !\is_string($fingerprint)) {
            return null;
        }
        $uid = $receipt['uid'] ?? null;

        return [
            'operation' => $standing['operation'],
            'payload' => $payload,
            'signature' => $signature,
            'fingerprint' => $fingerprint,
            'uid' => \is_string($uid) ? $uid : null,
        ];
    }

    /**
     * Write the audit line of a leg that ran citing the receipt.
     */
    public function cited(string $sequence, string $operation, string $receiptId): void
    {
        $this->store()?->citeAuthorization($sequence, $operation, $receiptId);
    }

    /**
     * Release the receipt when the cited call ended its sequence.
     */
    public function settled(string $sequence, string $operation, mixed $result): void
    {
        $because = self::ended($operation, $result);
        if ($because === null) {
            return;
        }
        $store = $this->store();
        if ($store?->load($sequence)?->sequenceAuthorization() !== null) {
            $store->releaseAuthorization($sequence, $because);
        }
    }

    /**
     * Why this result ends its sequence, or null while the sequence goes on.
     *
     * Read from what each operation already answers, never re-derived: a recipe is over when it
     * says `applied`, and an agent task when the house derived a VERIFIED closure for it
     * (greenhouse decisions/0487). An unverified closure, a pause, a spent budget or a stall leave
     * the work open — the sequence continues, and so does its receipt.
     */
    public static function ended(string $operation, mixed $result): ?string
    {
        if (!\is_array($result)) {
            return null;
        }

        return match ($operation) {
            'recipe:apply' => ($result['applied'] ?? false) === true ? 'the recipe was applied' : null,
            'agent' => \is_array($result['closure'] ?? null) && ($result['closure']['verified'] ?? false) === true
                ? 'the house derived a verified closure' : null,
            default => null,
        };
    }

    private function store(): ?SessionStore
    {
        if (!$this->resolved) {
            $this->resolved = true;
            $this->store = ($this->sessions)();
        }

        return $this->store;
    }
}
