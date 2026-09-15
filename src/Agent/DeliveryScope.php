<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Agent\SessionStore;
use Milpa\EventStore\Event;
use Milpa\EventStore\EventStoreInterface;

/** One immutable caller-declared delivery per session (greenhouse 0386/0703). */
final class DeliveryScope
{
    public const EVENT = 'session.delivery_declared';

    /** Parse the explicit CLI JSON or SDK object. Null is invalid, never a request to erase scope.
     * @return array<string,mixed>
     */
    public static function parse(mixed $input): array
    {
        if (is_string($input)) {
            $input = json_decode($input, true, 32, JSON_THROW_ON_ERROR);
        }
        if (!is_array($input) || array_diff(array_keys($input), ['workspace', 'artifactPath', 'test', 'screen']) !== []
            || !is_string($input['workspace'] ?? null) || !preg_match('/^w[a-f0-9]{12,32}$/D', $input['workspace'])
            || !self::relativePath($input['artifactPath'] ?? null)
            || !is_array($input['test'] ?? null) || !is_array($input['screen'] ?? null)) {
            throw new \InvalidArgumentException('Delivery requires a candidate workspace, relative artifactPath, test and screen.');
        }
        $test = $input['test'];
        $screen = $input['screen'];
        if (array_diff(array_keys($test), ['path', 'filter']) !== []
            || !self::relativePath($test['path'] ?? null) || !is_string($test['filter'] ?? null)
            || array_diff(array_keys($screen), ['name', 'type', 'definition']) !== []
            || !is_string($screen['name'] ?? null) || trim($screen['name']) === ''
            || !is_string($screen['type'] ?? null) || trim($screen['type']) === ''
            || (array_key_exists('definition', $screen) && !is_array($screen['definition']))) {
            throw new \InvalidArgumentException('Delivery requires an exact test path/filter and screen name/type.');
        }
        $input['test'] = ['path' => $test['path'], 'filter' => trim($test['filter'])];
        // JSON roundtrip rejects resources/non-finite values; normalize object key order, not lists.
        $json = json_encode($input, JSON_THROW_ON_ERROR);
        if (strlen($json) > 16384) {
            throw new \InvalidArgumentException('Delivery exceeds 16384 bytes.');
        }
        return self::canonical(json_decode($json, true, 32, JSON_THROW_ON_ERROR));
    }

    /** Read the trusted stream; malformed or duplicate declarations must never become absence.
     * @param list<Event> $events
     *
     * @return array<string,mixed>|null
     */
    public static function read(array $events, string $session): ?array
    {
        $found = null;
        foreach ($events as $event) {
            if ($event->type !== self::EVENT) {
                continue;
            }
            $payload = $event->payload;
            $scope = self::parse($payload['scope'] ?? null);
            if ($found !== null || $event->streamId !== SessionStore::PREFIX . $session
                || ($payload['schema'] ?? null) !== 'milpa.delivery-scope/v1'
                || ($payload['session'] ?? null) !== $session || ($payload['scope'] ?? null) !== $scope
                || ($payload['sha256'] ?? null) !== self::hash($scope)
                || ($payload['provenance']['source'] ?? null) !== 'agent_invocation'
                || !is_string($payload['provenance']['channel'] ?? null)) {
                throw new \UnexpectedValueException('The durable delivery declaration is inconsistent.');
            }
            $found = $payload + ['seq' => $event->seq];
        }
        return $found;
    }

    /** Append once at the invocation boundary, never through a model-callable operation.
     * @param array<string,mixed> $scope
     */
    public static function record(EventStoreInterface $events, string $session, array $scope, ObservedExecutor $caller): void
    {
        $scope = self::parse($scope);
        $events->append(new Event(
            streamId: SessionStore::PREFIX . $session,
            type: self::EVENT,
            payload: ['schema' => 'milpa.delivery-scope/v1', 'session' => $session, 'scope' => $scope,
                'sha256' => self::hash($scope), 'provenance' => ['source' => 'agent_invocation',
                    'channel' => $caller->source, 'principal' => $caller->principal?->toArray()]],
            seq: $events->nextSeq()
        ));
    }

    /** @param array<string,mixed> $scope */
    private static function hash(array $scope): string
    {
        return hash('sha256', json_encode($scope, JSON_THROW_ON_ERROR));
    }

    private static function relativePath(mixed $path): bool
    {
        return is_string($path) && $path !== '' && trim($path) === $path
            && !str_contains($path, "\0") && !str_contains($path, '\\')
            && !str_contains($path, ':') && !str_starts_with($path, '/')
            && !in_array('..', explode('/', $path), true) && !in_array('.', explode('/', $path), true)
            && !in_array('', explode('/', $path), true);
    }

    /** @param array<mixed> $value
     * @return array<mixed>
     */
    private static function canonical(array $value): array
    {
        foreach ($value as &$child) {
            if (is_array($child)) {
                $child = self::canonical($child);
            }
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        return $value;
    }
}
