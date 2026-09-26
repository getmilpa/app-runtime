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
 * A declared screen bound to a {@see HouseReading} (greenhouse decisions/0484): `{reading, arguments}`.
 *
 * The sibling of {@see PublicSource}, with the same discipline and a different authority. There, the
 * ENTITY declared what is public and the binding can only read through it. Here, the HOUSE wrote the
 * reading and its audience, and the binding can only name it: it chooses which reading and with which
 * arguments, never what is read nor who sees it. Declaring validates the shape without reading;
 * serving judges the audience BEFORE reading, and projects what was read to what the reading fills.
 */
final class ReadingSource
{
    /** Whether `$source` is this shape — an object that names a reading. */
    public static function is(mixed $source): bool
    {
        return \is_array($source) && \array_key_exists('reading', $source);
    }

    /**
     * The binding, checked and normalised against the house's readings — or {@see InvalidScreenTree} by path.
     * It never calls {@see HouseReading::read()}.
     *
     * @return array{reading: string, arguments: array<string, mixed>}
     */
    public static function validate(mixed $source, ?HouseReadings $readings): array
    {
        if (! \is_array($source) || array_is_list($source)) {
            throw new InvalidScreenTree('source', 'source must be an object: {reading, arguments}');
        }
        $name = $source['reading'] ?? null;
        if (! \is_string($name) || trim($name) === '') {
            throw new InvalidScreenTree('source.reading', 'source.reading must name a reading; discover them with screen:readings');
        }
        $unknownKeys = array_diff(array_keys($source), ['reading', 'arguments']);
        if ($unknownKeys !== []) {
            throw new InvalidScreenTree('source.' . reset($unknownKeys), 'a reading binding is {reading, arguments}; nothing else');
        }
        $reading = $readings?->get($name);
        if ($reading === null) {
            throw new InvalidScreenTree('source.reading', "this house lends no reading named «{$name}»; discover them with screen:readings");
        }
        $arguments = $source['arguments'] ?? [];
        if (! \is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
            throw new InvalidScreenTree('source.arguments', 'source.arguments must be an object of named arguments');
        }
        $declared = $reading->arguments();
        foreach ($arguments as $key => $_) {
            if (! \array_key_exists((string) $key, $declared)) {
                throw new InvalidScreenTree('source.arguments.' . $key, "«{$name}» takes no argument «{$key}»; it takes: " . (implode(', ', array_keys($declared)) ?: 'none'));
            }
        }
        foreach ($declared as $key => $spec) {
            if (! \array_key_exists($key, $arguments)) {
                throw new InvalidScreenTree('source.arguments.' . $key, "«{$name}» needs «{$key}»: {$spec['description']}");
            }
            if (! self::isOfType($arguments[$key], $spec['type'])) {
                throw new InvalidScreenTree('source.arguments.' . $key, "«{$key}» must be of type {$spec['type']}");
            }
        }

        return ['reading' => $name, 'arguments' => $arguments];
    }

    /**
     * The values the bound reading fills for `$principal`, read NOW — or {@see ReadingDenied} when the
     * reading's audience does not admit the principal, before anything is read.
     *
     * @param array{reading: string, arguments: array<string, mixed>} $binding
     *
     * @return array<string, mixed> exactly the keys the reading fills
     */
    public static function fill(array $binding, ?HouseReadings $readings, ?SecurityPrincipal $principal): array
    {
        $binding = self::validate($binding, $readings);
        $reading = $readings?->get($binding['reading']);
        \assert($reading instanceof HouseReading);
        $status = $reading->audience()->denies($principal);
        if ($status !== null) {
            throw new ReadingDenied($status, $binding['reading']);
        }
        $read = $reading->read($binding['arguments']);
        $values = [];
        foreach ($reading->fills() as $key) {
            if (! \array_key_exists($key, $read)) {
                throw new InvalidScreenTree('source.reading', "«{$binding['reading']}» read no «{$key}», which it declares it fills");
            }
            $values[$key] = $read[$key];
        }

        return $values;
    }

    private static function isOfType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => \is_string($value),
            'integer' => \is_int($value),
            'number' => \is_int($value) || \is_float($value),
            'boolean' => \is_bool($value),
            default => false,
        };
    }
}
