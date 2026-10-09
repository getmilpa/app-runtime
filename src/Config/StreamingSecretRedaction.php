<?php

/**
 * This file is part of milpa/app-runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Config;

/**
 * THE SAME SECRET REDACTION, OVER A STREAM THAT ARRIVES IN PIECES (greenhouse decisions/0608).
 *
 * The session's stream and the live view of a model's thinking arrive a chunk at a time, and a secret value can
 * be split across two consecutive chunks — one piece ends in `cana`, the next begins `ry-1234`. Redacting each
 * piece on its own would let both halves out. This holds the value set of {@see SecretRedaction} — the one place
 * a secret is defined — and reads the pieces as one text, never letting out a character that a later chunk could
 * still turn into part of a secret.
 *
 * ── HOW IT HOLDS BACK, AND WHY EXACTLY THAT MUCH ───────────────────────────────────────────────────
 *
 * A value is at most {@see SecretRedaction::maxValueLength()} characters, so a future chunk can only complete a
 * value that reaches back at most that many characters less one into what is already here. Each push redacts
 * everything seen so far and emits the redacted text except its last `maxLength - 1` characters: those, and only
 * those, could still be swept into a secret by what comes next, so they wait. {@see flush()} lets the tail out,
 * redacted, when the stream ends. With no secrets the hold-back is zero and every push passes straight through.
 *
 * It does NOT rewrite what a surface already showed or a book already recorded: it governs only what is let out
 * from here on. A value that a chunk straddled is caught because its leading half is held until its trailing half
 * arrives; a value already written to an old book before this ran stays there, and `where.py` (the lab's scanner)
 * is how one would find such a book — this is a boundary going forward, not a sweep of the past.
 */
final class StreamingSecretRedaction
{
    /** Every raw character pushed so far; redaction is recomputed over the whole so a split value is caught. */
    private string $raw = '';

    /** How many characters of the redacted stream have already been let out. */
    private int $emitted = 0;

    /** The tail that a future chunk could still turn into a secret, so it is not let out yet. */
    private readonly int $holdback;

    public function __construct(private readonly ?string $root)
    {
        $this->holdback = max(0, SecretRedaction::maxValueLength($root) - 1);
    }

    /**
     * Take the next piece of the stream; return the redacted text safe to let out now — which holds back the
     * last `maxLength - 1` characters, since a later piece could still make them part of a secret.
     */
    public function push(string $piece): string
    {
        $this->raw .= $piece;
        $redacted = SecretRedaction::inText($this->raw, $this->root);
        $safe = max($this->emitted, \strlen($redacted) - $this->holdback);
        $out = substr($redacted, $this->emitted, $safe - $this->emitted);
        $this->emitted = $safe;

        return $out;
    }

    /**
     * A block of the stream has ended (the model turned from thinking to answering): let out the held-back tail,
     * redacted, and RESET. A reasoning block is one window — a value never spans the turn from thinking to content —
     * so the next block starts empty. Resetting is what keeps this O(block), not O(leg): without it a long leg's
     * every push would re-redact all of its accumulated reasoning, and the memory would never be let go
     * (greenhouse decisions/0608, measured in evidence/1174).
     */
    public function flush(): string
    {
        $redacted = SecretRedaction::inText($this->raw, $this->root);
        $out = substr($redacted, $this->emitted);
        $this->raw = '';
        $this->emitted = 0;

        return $out;
    }
}
