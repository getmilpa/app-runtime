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

namespace Milpa\AppRuntime\Agent;

/**
 * Whether a human's typed reply means yes — in ONE place, because it used to mean two things.
 *
 * `SessionOperations` accepted five spellings and `SessionToolGate` compared against the accented
 * «sí» alone, so answering «yes» recorded the permission and then failed the intent contract: the
 * human saw their answer accepted and the operation still did not run (greenhouse evidence/0183).
 * Two readers of the same yes, disagreeing on spelling, and spelling deciding authority.
 *
 * After `decisions/0031` this is no longer a security surface. **The authority is the
 * `ConsentGrant`**; this is the parsing a surface does before producing one, and parsing belongs to
 * the surface. That is exactly why it can afford to be generous — and exactly why it must be one
 * function rather than two opinions.
 *
 * **The producer is not generous** (greenhouse decisions/0518). Every answer the house itself offers or
 * writes — the options a consent question carries, a launch grant's recorded answer, a panel button —
 * is {@see YES} or {@see NO}: the questions are English, so are their answers. The reader keeps every
 * older spelling: a recorded «sí» is history, and replaying it must reach the same verdict it reached
 * when it was written; an older client that still posts «sí» keeps working through the 0.x line.
 */
final class AffirmativeAnswer
{
    /** The consent answer the house produces for "allow". */
    public const YES = 'yes';

    /** The consent answer the house produces for "deny". */
    public const NO = 'no';

    /** The options a consent question offers, in the order surfaces show them (allow first). */
    public const OPTIONS = [self::YES, self::NO];

    /**
     * Every spelling that reads as yes: the produced one first, then what older clients and recorded
     * sessions wrote («sí», «si», «s») — accepted, never produced.
     *
     * @var list<string>
     */
    private const ACCEPTED = [self::YES, 'y', 'sí', 'si', 's'];

    /** Does this typed reply mean yes? Case and surrounding space are the surface's noise, not the answer. */
    public static function is(string $answer): bool
    {
        return \in_array(mb_strtolower(trim($answer)), self::ACCEPTED, true);
    }
}
