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

namespace Milpa\AppRuntime\Identity;

/**
 * The fact that the house now RECOGNIZES an institutional identity for a rooted fingerprint.
 *
 * It carries what was recognized (the fingerprint), what it may do (the scopes policy assigned at
 * enrollment), and who authorized the recognition (the verified principal that ran the operation).
 * It is immutable and bound to its fingerprint: a later change to the out-of-band root does not
 * rewrite an enrollment already made — if A was enrolled and the root is later changed to B, A does
 * not become B (decisions/0117).
 */
final readonly class IdentityEnrolled
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $fingerprint,
        public array $scopes,
        public string $authorizedBy,
    ) {
    }

    /**
     * The recognition as a plain map, for persistence and transport.
     *
     * @return array{fingerprint: string, scopes: list<string>, authorized_by: string}
     */
    public function toArray(): array
    {
        return [
            'fingerprint' => $this->fingerprint,
            'scopes' => $this->scopes,
            'authorized_by' => $this->authorizedBy,
        ];
    }
}
