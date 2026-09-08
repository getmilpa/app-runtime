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

namespace Milpa\AppRuntime\Config;

/**
 * Where the context window that governs this run came from.
 *
 * A budget that changes without saying why is a budget nobody can debug: a human who declared
 * 100,000 and finds the run compacting at 32,768 must be able to see that the PROVIDER said so,
 * not wonder whether their configuration was read at all (greenhouse decisions/0233, point 4).
 *
 * The four cases are exhaustive over one question — which of the two sources produced the number —
 * and deliberately do NOT also answer «did the provider answer», which is a different question with
 * its own answer in {@see ContextWindow::couldNotAsk()}. One fact, one authority.
 */
enum ContextWindowSource: string
{
    /**
     * A human declared it, and nothing tightened it — either nobody was asked, or the provider's
     * answer was the same or larger.
     *
     * A declaration that is SMALLER than the measured window lands here on purpose: declaring less
     * is how a human leaves air deliberately, and the provider's larger number must not overwrite
     * that decision. Only the tighter of the two governs, whichever side it comes from.
     */
    case Declared = 'declared';

    /** Nobody declared one; the provider said what it had allocated. */
    case Measured = 'measured';

    /**
     * Both spoke and the PROVIDER's was smaller, so the declaration was clipped down to it.
     *
     * This is the case the slice exists for: a declaration that is too large is not caught by
     * anything until the provider rejects the prompt mid-run (greenhouse evidence/0443 measured a
     * 32,768-token model re-entered at 35.6k).
     */
    case TightenedByTheProvider = 'tightened-by-the-provider';

    /**
     * No window from any source — nobody declared one and no provider produced one.
     *
     * The orchestrator then behaves exactly as it did before this slice existed: it gives up on a
     * derived budget and falls back to its fixed cap. Nothing is invented to fill the hole.
     */
    case Undeclared = 'undeclared';
}
