<?php

/**
 * This file is part of Milpa App Runtime — the application runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Web;

use Milpa\Live\ValueObjects\SecurityPrincipal;

/**
 * Who may see a {@see HouseReading} (greenhouse decisions/0484): anyone, any authenticated principal, or a
 * principal carrying one scope. The principal is the one {@see \Milpa\AppRuntime\Auth\LivePrincipal} reads
 * — ONE identity for the live surface, never a second verifier.
 */
final class ReadingAudience
{
    private function __construct(
        /** Null: anyone. '': any authenticated principal. Otherwise the scope the principal must carry. */
        private readonly ?string $scope,
    ) {
    }

    /** Anyone — the reading shows nothing its house keeps private. */
    public static function public(): self
    {
        return new self(null);
    }

    /** Any authenticated principal of the house. */
    public static function members(): self
    {
        return new self('');
    }

    /** A principal that carries `$scope` (or the `milpa:*` wildcard). */
    public static function scope(string $scope): self
    {
        if (trim($scope) === '') {
            throw new \InvalidArgumentException('a reading audience scope must name the scope; use members() for any principal');
        }

        return new self($scope);
    }

    /** The HTTP status that denies `$principal`, or null when it may see the reading: 401 without one, 403 without the scope. */
    public function denies(?SecurityPrincipal $principal): ?int
    {
        if ($this->scope === null) {
            return null;
        }
        if ($principal === null) {
            return 401;
        }

        return $this->scope === '' || $principal->can($this->scope) ? null : 403;
    }

    /** How the audience reads in a catalogue: `public`, `members` or `scope:<scope>`. */
    public function describe(): string
    {
        return match ($this->scope) {
            null => 'public',
            '' => 'members',
            default => 'scope:' . $this->scope,
        };
    }
}
