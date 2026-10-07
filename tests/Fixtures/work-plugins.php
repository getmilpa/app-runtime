<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

// The course's plugin as far as the house's work layer reads it (greenhouse decisions/0588): it provides domain
// operations, and may name where some of their work keeps state. It lives under App\Plugins\<Plugin> because that
// is how the house knows which plugin an operation belongs to.

namespace App\Plugins\Prestamos;

use Milpa\AppRuntime\Agent\DeclaresWorkState;
use Milpa\Command\CommandProvider;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;

/** A plugin that provides two domain operations, and two of the house's own names it must never be work for. */
class PlainPlugin implements CommandProvider
{
    public function operations(): array
    {
        return array_map(self::work(...), ['herramientas.agregar', 'herramientas.prestar', 'session:close', 'sandbox:promote']);
    }

    public static function work(string $name): Operation
    {
        return new Operation(
            name: $name,
            description: 'Domain work',
            handler: static fn (): array => ['ok' => true],
            mutating: true,
            effects: new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::ManualRecovery, Authority::WriteAsUser, subject: Subject::Data),
        );
    }
}

/** The same plugin, naming where some of its work keeps state. */
final class DeclaringPlugin extends PlainPlugin implements DeclaresWorkState
{
    /** @param array<string, mixed> $state */
    public function __construct(private readonly array $state)
    {
    }

    public function workState(): array
    {
        return $this->state;
    }
}
