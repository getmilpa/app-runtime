<?php

/**
 * This file is part of Milpa App Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

/**
 * HOW THE HOUSE NAMES, IN WHAT A MODEL READS, THE PERSON WHO DECIDED (greenhouse decisions/0609; evidence/1175 §6).
 *
 * When a person grants a scope or admits a capability, the house tells the seat's session so with a turn of its own.
 * That turn is conversation: it goes to the model on every later call of the session, and so to whoever serves the
 * model. It carried the person's whole credential identifier — her passkey's, or the fingerprint of her key —, in 4 of
 * the 6 model calls of a real session. A model needs to know that a person decided, and to tell one decider from
 * another; it does not need the credential.
 *
 * So what a model reads says the KIND and the first eight characters, marked as cut — `passkey:QM1LEWEf…` — and never
 * more than half of an identifier, however short. The whole attribution is the ledger's: the fact of the grant
 * ({@see GrantedCall::granted()}, `authorized_by`), the identity ledger, and the answer to whoever made the grant.
 *
 * NOT HER LABEL. A label is text a person typed: said in a turn of the house it would be free text in the house's own
 * voice, and it may be her name.
 */
final class WhoDecided
{
    /** The most characters of an identifier a model is shown. */
    public const SHOWN = 8;

    /** The kinds of principal that decide: a passkey in the panel, a key at the terminal. */
    private const KINDS = ['passkey:', 'key:'];

    /** What a turn of the house says of the principal that decided. */
    public static function said(string $principal): string
    {
        foreach (self::KINDS as $kind) {
            if (str_starts_with($principal, $kind)) {
                $identifier = substr($principal, \strlen($kind));

                // Never the whole of it: at most eight characters, and at most half of what there is.
                return $kind . mb_substr($identifier, 0, min(self::SHOWN, intdiv(mb_strlen($identifier), 2))) . '…';
            }
        }

        return 'a person';
    }
}
