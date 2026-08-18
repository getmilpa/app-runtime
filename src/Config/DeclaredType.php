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
 * One executable type declaration: it describes a value and checks the value written for it.
 *
 * Text is accepted as a transport spelling only where the declaration makes that spelling
 * unambiguous. Integers use the JSON integer grammar, booleans accept only `true` or `false`, and
 * structured values must be valid JSON of the declared shape. Nested values are never coerced.
 */
final readonly class DeclaredType
{
    /**
     * @param list<self>                                        $members
     * @param array<string, array{type: self, optional?: bool}> $fields
     */
    private function __construct(
        private string $kind,
        private ?string $qualifier = null,
        private string|bool|null $literal = null,
        private array $members = [],
        private array $fields = [],
        private ?self $keyType = null,
        private ?self $valueType = null,
    ) {
    }

    /** Declare a string value, with an optional qualifier for the catalogue. */
    public static function text(?string $qualifier = null): self
    {
        return new self('string', qualifier: $qualifier);
    }

    /** Declare an integer value. */
    public static function integer(): self
    {
        return new self('int');
    }

    /** Declare a boolean value. */
    public static function boolean(): self
    {
        return new self('bool');
    }

    /** Declare an array of any JSON shape. */
    public static function anyArray(): self
    {
        return new self('array');
    }

    /** Declare one exact string or boolean value. */
    public static function literal(string|bool $literal): self
    {
        return new self('literal', literal: $literal);
    }

    /** Declare that any one of these member types is valid. */
    public static function union(self ...$members): self
    {
        if ($members === []) {
            throw new \InvalidArgumentException('A declared union requires at least one member.');
        }

        return new self('union', members: $members);
    }

    /** Declare a JSON list whose items share one type. */
    public static function listOf(self $valueType): self
    {
        return new self('list', valueType: $valueType);
    }

    /** Declare a JSON object whose keys and values share types. */
    public static function mapOf(self $keyType, self $valueType): self
    {
        return new self('map', keyType: $keyType, valueType: $valueType);
    }

    /**
     * Declare a closed JSON object shape.
     *
     * @param array<string, array{type: self, optional?: bool}> $fields
     */
    public static function shape(array $fields): self
    {
        return new self('shape', fields: $fields);
    }

    /** Render the declaration in the notation exposed by the configuration catalogue. */
    public function description(): string
    {
        return match ($this->kind) {
            'string' => 'string' . ($this->qualifier === null ? '' : " ({$this->qualifier})"),
            'int' => 'int',
            'bool' => 'bool',
            'array' => 'array',
            'literal' => \is_bool($this->literal)
                ? ($this->literal ? 'true' : 'false')
                : "'{$this->literal}'",
            'union' => implode(' | ', array_map(
                static fn (self $member): string => $member->description(),
                $this->members,
            )),
            'list' => 'list<' . $this->value()->description() . '>',
            'map' => 'array<' . $this->key()->description() . ', ' . $this->value()->description() . '>',
            'shape' => 'array{' . implode(', ', array_map(
                static fn (string $name, array $field): string => $name
                    . (($field['optional'] ?? false) ? '?' : '')
                    . ': ' . $field['type']->description(),
                array_keys($this->fields),
                array_values($this->fields),
            )) . '}',
            default => throw new \LogicException("Unsupported declared type kind '{$this->kind}'."),
        };
    }

    /**
     * Turn a surface's transport spelling into this declared type, or reject it.
     *
     * @throws \InvalidArgumentException when the value does not conform
     */
    public function coerce(mixed $value): mixed
    {
        return $this->conform($value, transport: true);
    }

    private function conform(mixed $value, bool $transport): mixed
    {
        return match ($this->kind) {
            'string' => $this->conformString($value),
            'int' => $this->conformInteger($value, $transport),
            'bool' => $this->conformBoolean($value, $transport),
            'array' => $this->conformArray($value, $transport),
            'literal' => $this->conformLiteral($value, $transport),
            'union' => $this->conformUnion($value, $transport),
            'list' => $this->conformList($value, $transport),
            'map' => $this->conformMap($value, $transport),
            'shape' => $this->conformShape($value, $transport),
            default => throw new \LogicException("Unsupported declared type kind '{$this->kind}'."),
        };
    }

    private function conformString(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }

        throw $this->mismatch($value);
    }

    private function conformInteger(mixed $value, bool $transport): int
    {
        if (\is_int($value)) {
            return $value;
        }

        if ($transport && \is_string($value) && preg_match('/^-?(?:0|[1-9]\d*)$/D', $value) === 1) {
            $integer = filter_var($value, \FILTER_VALIDATE_INT);
            if (\is_int($integer)) {
                return $integer;
            }
        }

        throw $this->mismatch($value, 'only a base-10 integer spelling is accepted from text');
    }

    private function conformBoolean(mixed $value, bool $transport): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        if ($transport && $value === 'true') {
            return true;
        }
        if ($transport && $value === 'false') {
            return false;
        }

        throw $this->mismatch($value, 'only the exact text `true` or `false` is accepted from text');
    }

    /** @return array<mixed>|\stdClass */
    private function conformArray(mixed $value, bool $transport): array|\stdClass
    {
        $value = $this->decodeStructuredTransport($value, $transport);
        if (!\is_array($value) && !$value instanceof \stdClass) {
            throw $this->mismatch($value, 'expected a JSON array or object');
        }

        /** @var array<mixed>|\stdClass */
        return $this->containersToArrays($value);
    }

    private function conformLiteral(mixed $value, bool $transport): string|bool
    {
        if ($value === $this->literal) {
            return $value;
        }

        if ($transport && \is_bool($this->literal) && \is_string($value)) {
            $spelling = $this->literal ? 'true' : 'false';
            if ($value === $spelling) {
                return $this->literal;
            }
        }

        throw $this->mismatch($value);
    }

    private function conformUnion(mixed $value, bool $transport): mixed
    {
        foreach ($this->members as $member) {
            try {
                return $member->conform($value, $transport);
            } catch (\InvalidArgumentException) {
                // The union accepts the value if any declared member accepts it.
            }
        }

        throw $this->mismatch($value);
    }

    /** @return list<mixed> */
    private function conformList(mixed $value, bool $transport): array
    {
        $value = $this->decodeStructuredTransport($value, $transport);
        if (!\is_array($value) || !array_is_list($value)) {
            throw $this->mismatch($value, 'expected a JSON list');
        }

        $conformed = [];
        foreach ($value as $index => $item) {
            try {
                $conformed[] = $this->value()->conform($item, transport: false);
            } catch (\InvalidArgumentException $error) {
                throw new \InvalidArgumentException(
                    "item {$index} does not conform: {$error->getMessage()}",
                    previous: $error,
                );
            }
        }

        return $conformed;
    }

    /** @return array<int|string, mixed>|\stdClass */
    private function conformMap(mixed $value, bool $transport): array|\stdClass
    {
        $rawValue = $value;
        $expectsJsonObject = $transport && \is_string($value);
        $value = $this->decodeStructuredTransport($value, $transport);
        if ($expectsJsonObject && !$value instanceof \stdClass) {
            throw $this->mismatch($rawValue, 'expected a JSON object');
        }
        $isObject = $value instanceof \stdClass;
        if ($isObject) {
            $value = get_object_vars($value);
        }
        if (!\is_array($value) || (!$isObject && array_is_list($value))) {
            throw $this->mismatch($value, 'expected a JSON object');
        }

        $conformed = [];
        foreach ($value as $key => $item) {
            try {
                $conformedKey = $this->key()->conform($key, transport: false);
            } catch (\InvalidArgumentException $error) {
                throw new \InvalidArgumentException(
                    "map key '{$key}' does not conform: {$error->getMessage()}",
                    previous: $error,
                );
            }

            try {
                $conformed[$conformedKey] = $this->value()->conform($item, transport: false);
            } catch (\InvalidArgumentException $error) {
                throw new \InvalidArgumentException(
                    "map entry '{$key}' does not conform: {$error->getMessage()}",
                    previous: $error,
                );
            }
        }

        return $conformed === [] ? new \stdClass() : $conformed;
    }

    /** @return array<string, mixed>|\stdClass */
    private function conformShape(mixed $value, bool $transport): array|\stdClass
    {
        $rawValue = $value;
        $expectsJsonObject = $transport && \is_string($value);
        $value = $this->decodeStructuredTransport($value, $transport);
        if ($expectsJsonObject && !$value instanceof \stdClass) {
            throw $this->mismatch($rawValue, 'expected a JSON object');
        }
        $isObject = $value instanceof \stdClass;
        if ($isObject) {
            $value = get_object_vars($value);
        }
        if (!\is_array($value) || (!$isObject && array_is_list($value))) {
            throw $this->mismatch($value, 'expected a JSON object');
        }

        foreach ($value as $name => $_) {
            if (!\is_string($name) || !isset($this->fields[$name])) {
                throw $this->mismatch($value, "field '{$name}' is not declared");
            }
        }

        $conformed = [];
        foreach ($this->fields as $name => $field) {
            if (!\array_key_exists($name, $value)) {
                if (!($field['optional'] ?? false)) {
                    throw $this->mismatch($value, "required field '{$name}' is missing");
                }
                continue;
            }

            try {
                $conformed[$name] = $field['type']->conform($value[$name], transport: false);
            } catch (\InvalidArgumentException $error) {
                throw new \InvalidArgumentException(
                    "field '{$name}' does not conform: {$error->getMessage()}",
                    previous: $error,
                );
            }
        }

        return $conformed === [] ? new \stdClass() : $conformed;
    }

    private function decodeStructuredTransport(mixed $value, bool $transport): mixed
    {
        if (!$transport || !\is_string($value)) {
            return $value;
        }

        try {
            return json_decode($value, associative: false, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw $this->mismatch($value, 'the text is not valid JSON: ' . $error->getMessage());
        }
    }

    private function containersToArrays(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            if ($properties === []) {
                return $value;
            }
            $value = $properties;
        }
        if (!\is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = $this->containersToArrays($item);
        }

        return $normalized;
    }

    private function key(): self
    {
        return $this->keyType ?? throw new \LogicException("Declared {$this->kind} type has no key type.");
    }

    private function value(): self
    {
        return $this->valueType ?? throw new \LogicException("Declared {$this->kind} type has no value type.");
    }

    private function mismatch(mixed $value, ?string $detail = null): \InvalidArgumentException
    {
        $message = 'expected ' . $this->description() . ', received ' . $this->describeValue($value);
        if ($detail !== null) {
            $message .= "; {$detail}";
        }

        return new \InvalidArgumentException($message);
    }

    private function describeValue(mixed $value): string
    {
        if (!\is_string($value)) {
            return get_debug_type($value);
        }

        $shown = mb_strlen($value) > 80 ? mb_substr($value, 0, 77) . '...' : $value;
        $encoded = json_encode($shown, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return 'string ' . (\is_string($encoded) ? $encoded : '(not valid UTF-8)');
    }
}
