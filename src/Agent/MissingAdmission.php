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

namespace Milpa\AppRuntime\Agent;

/**
 * What a seat's call to a built verb lacks: a person's admission of that verb as it stands (greenhouse
 * decisions/0590).
 *
 * It carries the judgement — which capability, under which scope, and why the seat's standing admissions do not
 * cover it — so a reader that must know asks this and never the sentence.
 */
final readonly class MissingAdmission
{
    /** Nobody admitted that scope of the capability for the seat. */
    public const string NEVER = 'never';

    /** It was admitted, and the verb's contract moved since. */
    public const string CHANGED = 'changed';

    /** Its scope was admitted before the capability declared this verb. */
    public const string ADDED = 'added';

    /**
     * @param string                                $scope the scope an admission would be given under
     * @param self::NEVER|self::CHANGED|self::ADDED $why
     */
    public function __construct(public BuiltVerb $verb, public string $scope, public string $why)
    {
    }

    /** The scope as a person reads it: the declared word, or — for a verb that declares none — the verb. */
    public function permission(): string
    {
        return self::spelled($this->scope);
    }

    /** How a scope an admission is given under reads: a verb admitted by itself says so. */
    public static function spelled(string $scope): string
    {
        return str_starts_with($scope, BuiltVerb::ITSELF) ? '(no scope) ' . substr($scope, \strlen(BuiltVerb::ITSELF)) : $scope;
    }

    /**
     * The refusal, as every surface says it.
     *
     * It names what an admission would be given under in single quotes, the way every refusal for a missing
     * permission does: that spelling is what the leg's door looks for before it ends a leg to wait for a person
     * (greenhouse evidence/1113), so a failure of another kind never ends one.
     */
    public function sentence(): string
    {
        $verb = \sprintf('«%s» is a verb of the capability «%s», built in this house', $this->verb->operation->name, $this->verb->capability);
        $under = \sprintf("'%s' of «%s»", $this->permission(), $this->verb->capability);

        return match ($this->why) {
            self::CHANGED => \sprintf('%s, and its contract changed since a person admitted it for this seat: that admission, under %s, no longer covers it.', $verb, $under),
            self::ADDED => \sprintf('%s, added after %s was admitted for this seat: no person has admitted this verb.', $verb, $under),
            default => \sprintf('%s, and no person has admitted it for this seat: it is admitted under %s. The scope a built verb declares does not open it — a person admits it, seeing its contract.', $verb, $under),
        };
    }
}
