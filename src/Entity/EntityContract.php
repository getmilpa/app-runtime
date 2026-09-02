<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app INSTALLS, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Entity;

/**
 * What a generated entity's own SEMANTICS are, proven by execution (greenhouse decisions/0187, D-04).
 *
 * The measured wound: `make resource` generated `final readonly class Tarea`, and the agent then
 * implemented `$actual->titulo = …` — a mutation the CLASS THE HOUSE ITSELF WROTE forbids. The house
 * knew what it created; nothing exposed that knowledge, so the model discovered `readonly` the
 * expensive way, at runtime, as a fatal Error. This extends OperationContract (decisions/0448) to
 * generated artifacts: an entity declares its contract — mutability, update semantics, the identity
 * it persists by — so `implement TareaService` is judged against it. Not because the model must know
 * `readonly`, but because the house does.
 *
 * ── PROVEN, NOT READ ────────────────────────────────────────────────────────────────────────────
 *
 * The house's own rule (D³, CLAUDE.md): «the most expensive form is asking the TEXT what only
 * EXECUTION answers — if you are about to classify by reading, mutate it instead». So `mutability` is
 * not read off the `readonly` keyword or reflection metadata: a throwaway instance is MUTATED, and
 * the verdict is whatever PHP does. A readonly property, written once and then written again, throws
 * the EXACT Error the agent hit («Cannot modify readonly property»); a plain property takes the
 * second write. The contract's word is that thrown Error, or its absence — the same event, not a
 * paraphrase of it. The probe runs on a discarded instance: it reads the class and changes nothing
 * the app can see.
 */
final readonly class EntityContract
{
    public const IMMUTABLE = 'immutable';

    public const MUTABLE = 'mutable';

    /** Replace the whole entity (immutable) vs. mutate the instance in place (mutable). */
    public const REPLACE_ENTITY = 'replace_entity';

    public const MUTATE_IN_PLACE = 'mutate_in_place';

    /**
     * @param string  $class               the fully-qualified entity class the contract describes
     * @param string  $mutability          self::IMMUTABLE or self::MUTABLE, proven by the probe
     * @param string  $updateSemantics     how a caller must update it, derived from mutability
     * @param ?string $persistenceIdentity the field an entity persists by (`id` when it declares one), or null
     * @param bool    $probed              whether a mutation was actually executed to decide mutability
     */
    private function __construct(
        public string $class,
        public string $mutability,
        public string $updateSemantics,
        public ?string $persistenceIdentity,
        public bool $probed,
    ) {
    }

    /**
     * Derive the contract for a class by PROBING it — instantiate without the constructor, then try
     * to mutate a property twice (initialise, then modify) and let PHP decide.
     *
     * `null` when the class cannot be found or has no instance property to probe: fail-closed, the
     * house says «I cannot vouch for this» rather than guessing a semantics.
     */
    public static function of(string $class): ?self
    {
        $class = ltrim(trim($class), '\\');
        if ($class === '' || ! class_exists($class)) {
            return null;
        }

        $reflection = new \ReflectionClass($class);
        if ($reflection->isAbstract() || $reflection->isInterface()) {
            return null;
        }

        $mutability = self::probeMutability($reflection);
        if ($mutability === null) {
            return null;
        }

        return new self(
            class: $class,
            mutability: $mutability,
            updateSemantics: $mutability === self::IMMUTABLE ? self::REPLACE_ENTITY : self::MUTATE_IN_PLACE,
            persistenceIdentity: self::identityOf($reflection),
            probed: true,
        );
    }

    /**
     * Mutate a throwaway instance and let PHP rule. `null` when no instance property could be probed
     * — the honest «inconclusive», never a guess.
     *
     * @param \ReflectionClass<object> $reflection
     */
    private static function probeMutability(\ReflectionClass $reflection): ?string
    {
        try {
            $instance = $reflection->newInstanceWithoutConstructor();
        } catch (\Throwable) {
            return null;
        }

        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $value = self::probeValue($property->getType());
            try {
                // First write initialises (a readonly property allows exactly one write); the second
                // is the real mutation — the one the agent's `$x->field = …` was, and the one a
                // readonly class refuses with the very Error this contract reports.
                $property->setValue($instance, $value);
                $property->setValue($instance, $value);

                return self::MUTABLE;
            } catch (\Error $e) {
                if (str_contains($e->getMessage(), 'readonly')) {
                    return self::IMMUTABLE;
                }

                // A TypeError or other Error means this property could not be probed with this value;
                // the next property may still decide. Not a verdict — a skip.
                continue;
            }
        }

        return null;
    }

    /** A type-appropriate value so the probe tests mutability, not type compatibility. */
    private static function probeValue(?\ReflectionType $type): mixed
    {
        $name = $type instanceof \ReflectionNamedType ? $type->getName() : 'mixed';

        return match ($name) {
            'int' => 0,
            'string' => '',
            'bool' => false,
            'float' => 0.0,
            'array' => [],
            default => null,
        };
    }

    /**
     * The field the entity persists by — `id` when it declares one (property or accessor), else null.
     *
     * @param \ReflectionClass<object> $reflection
     */
    private static function identityOf(\ReflectionClass $reflection): ?string
    {
        if ($reflection->hasProperty('id') || $reflection->hasMethod('id')) {
            return 'id';
        }

        return null;
    }

    /**
     * The serializable form the entity:contract operation projects — every field flattened to data.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ok' => true,
            'class' => $this->class,
            'mutability' => $this->mutability,
            'updateSemantics' => $this->updateSemantics,
            'persistenceIdentity' => $this->persistenceIdentity,
            'probed' => $this->probed,
        ];
    }
}
