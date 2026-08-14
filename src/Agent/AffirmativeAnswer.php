<?php

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
 */
final class AffirmativeAnswer
{
    /** @var list<string> */
    private const SÍ = ['sí', 'si', 'yes', 'y', 's'];

    public static function is(string $answer): bool
    {
        return \in_array(mb_strtolower(trim($answer)), self::SÍ, true);
    }
}
