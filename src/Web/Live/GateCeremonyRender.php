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

namespace Milpa\AppRuntime\Web\Live;

/**
 * The MUTABLE subject the ceremony's lifecycle pair carries.
 *
 * A component that cannot be observed cannot be extended, and Milpa is event-driven: so the ceremony
 * dispatches `before_render` with the words and facts it is about to paint, and `after_render` with
 * the HTML it painted. A subscriber changes the copy before, or the markup after, without this class
 * knowing it exists.
 *
 * Mutable ON PURPOSE — the point is that a subscriber can write to it. Everything else in this
 * package's value objects is readonly; this one is a subject, which is a different thing.
 */
final class GateCeremonyRender
{
    /**
     * There is no `kind` field, deliberately: it would be a second copy of `facts['kind']`, which a
     * subscriber already reads. A piece built and not wired is debt that looks like a capability
     * (greenhouse decisions/0213), and the house's inventory gate caught this one the same day.
     *
     * @param array<string, mixed> $copy  the act's words, as {@see GateCeremonyComponent::copy()} returns them
     * @param array<string, mixed> $facts what the ceremony's module will be handed as JSON, `kind` included
     */
    public function __construct(
        public array $copy,
        public array $facts,
        public string $markHtml = '',
        public string $html = '',
    ) {
    }
}
