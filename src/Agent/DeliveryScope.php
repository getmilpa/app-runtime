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
        if (!is_array($input) || array_diff(array_keys($input), ['workspace', 'artifactPath', 'test', 'screen', 'members']) !== []
            || !is_string($input['workspace'] ?? null) || !preg_match('/^w[a-f0-9]{12,32}$/D', $input['workspace'])
            || !self::relativePath($input['artifactPath'] ?? null)
            || !is_array($input['test'] ?? null) || !is_array($input['screen'] ?? null)) {
            throw new \InvalidArgumentException('Delivery requires a candidate workspace, relative artifactPath, test and screen.');
        }
        $expected = self::parseExpected(array_diff_key($input, ['workspace' => true, 'artifactPath' => true]));
        if (isset($expected['members']) && !in_array($input['artifactPath'], $expected['members'], true)) {
            throw new \InvalidArgumentException('The selected candidate must belong to the declared members.');
        }
        $scope = self::canonical(['workspace' => $input['workspace'], 'artifactPath' => $input['artifactPath']] + $expected);
        if (strlen(json_encode($scope, JSON_THROW_ON_ERROR)) > 16384) {
            throw new \InvalidArgumentException('Delivery exceeds 16384 bytes.');
        }
        return $scope;
    }

    /** Normalize the candidate-independent test and screen target shared by both declaration paths.
     * @return array<string,mixed>
     */
    public static function parseExpected(mixed $input): array
    {
        if (is_string($input)) {
            $input = json_decode($input, true, 32, JSON_THROW_ON_ERROR);
        }
        if (!is_array($input) || array_diff(array_keys($input), ['test', 'screen', 'members']) !== []
            || !is_array($input['test'] ?? null) || !is_array($input['screen'] ?? null)) {
            throw new \InvalidArgumentException('An expectation requires test and screen targets, with optional explicit members.');
        }
        if (array_key_exists('members', $input)) {
            if (!is_array($input['members']) || !array_is_list($input['members']) || $input['members'] === [] || count($input['members']) > 32) {
                throw new \InvalidArgumentException('Delivery members must be a nonempty list of at most 32 relative paths.');
            }
            foreach ($input['members'] as $path) {
                if (!self::relativePath($path)) {
                    throw new \InvalidArgumentException('Delivery members require safe relative paths.');
                }
            }
            if (count(array_unique($input['members'])) !== count($input['members'])) {
                throw new \InvalidArgumentException('Delivery members must be unique.');
            }
            sort($input['members'], SORT_STRING);
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
        $expectation = DeliveryExpectation::read($events, $session);
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
            if ($expectation !== null || isset($scope['members']) || array_key_exists('binding', $payload)) {
                self::validateBinding($events, $expectation, $scope, $payload['binding'] ?? null, $event->seq);
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
        if (isset($scope['members']) || DeliveryExpectation::read((new SessionStore($events))->stream($session), $session) !== null) {
            throw new \InvalidArgumentException('An expected delivery must bind a native candidate.');
        }
        self::append($events, $session, $scope, $caller, null);
    }

    /** Append a validated declaration, retaining the native binding when this path has one.
     * @param array<string,mixed>      $scope
     * @param array<string,mixed>|null $binding
     */
    private static function append(EventStoreInterface $events, string $session, array $scope, ObservedExecutor $caller, ?array $binding): void
    {
        $scope = self::parse($scope);
        $events->append(new Event(
            streamId: SessionStore::PREFIX . $session,
            type: self::EVENT,
            payload: ['schema' => 'milpa.delivery-scope/v1', 'session' => $session, 'scope' => $scope,
                'sha256' => self::hash($scope), 'provenance' => ['source' => 'agent_invocation',
                    'channel' => $caller->source, 'principal' => $caller->principal?->toArray()]] + ($binding === null ? [] : ['binding' => $binding]),
            seq: $events->nextSeq()
        ));
    }

    /** Derive a binding from native candidate authorities and the already recorded expectation.
     * @param list<Event> $events
     *
     * @return array{scope:array<string,mixed>,binding:array<string,mixed>}
     */
    public static function forCandidate(string $root, array $events, string $session, string $workspace): array
    {
        $expectation = DeliveryExpectation::read($events, $session);
        if ($expectation === null) {
            throw new \InvalidArgumentException('Declare an expectation before binding a candidate.');
        }
        $candidate = CandidateState::read($root, $events, $workspace);
        if (($candidate['state'] ?? null) !== 'promoted'
            || ($candidate['evidence']['trialRunSeq'] ?? 0) <= $expectation['seq']) {
            throw new \InvalidArgumentException('A current promoted candidate produced after the expectation is required.');
        }
        $scope = self::parse(['workspace' => $workspace, 'artifactPath' => $candidate['artifact']['path']] + $expectation['expected']);
        $binding = ['expectationSeq' => $expectation['seq'], 'expectationSha256' => $expectation['sha256'],
            'candidate' => ['artifact' => $candidate['artifact'], 'trialRunSeq' => $candidate['evidence']['trialRunSeq'],
                'toolCallSeq' => $candidate['evidence']['toolCallSeq']]];
        if (isset($expectation['expected']['members'])) {
            $binding['members'] = DeliveryMembers::read($root, $events, $expectation);
        }
        $existing = self::read($events, $session);
        if ($existing !== null && ($existing['scope'] !== $scope || ($existing['binding'] ?? null) !== $binding)) {
            throw new \InvalidArgumentException('The delivery candidate binding is immutable; use a new session.');
        }
        return ['scope' => $scope, 'binding' => $binding];
    }

    /** Record only facts re-read from the native store and filesystem; callers supply no observations. */
    public static function recordCandidate(EventStoreInterface $events, string $session, string $root, string $workspace, ObservedExecutor $caller): void
    {
        $rows = (new SessionStore($events))->stream($session);
        $derived = self::forCandidate($root, $rows, $session, $workspace);
        if (self::read($rows, $session) !== null) {
            return;
        }
        self::append($events, $session, $derived['scope'], $caller, $derived['binding']);
    }

    /** Keep the expectation and the exact producer pinned across rehydration and later events.
     * @param list<Event>              $events
     * @param array<string,mixed>|null $expectation
     * @param array<string,mixed>      $scope
     */
    private static function validateBinding(array $events, ?array $expectation, array $scope, mixed $binding, int $declaredSeq): void
    {
        if ($expectation === null || !is_array($binding)
            || ($binding['expectationSeq'] ?? null) !== $expectation['seq']
            || ($binding['expectationSha256'] ?? null) !== $expectation['sha256']
            || array_diff_key($scope, ['workspace' => true, 'artifactPath' => true]) !== $expectation['expected']) {
            throw new \UnexpectedValueException('Delivery does not match its prior expectation.');
        }
        self::validateProducer($events, $scope['workspace'], $scope['artifactPath'], $binding['candidate'] ?? null, $expectation['seq'], $declaredSeq);
        if (isset($scope['members'])) {
            $members = $binding['members'] ?? null;
            if (!is_array($members) || !array_is_list($members) || array_column(array_column($members, 'artifact'), 'path') !== $scope['members']) {
                throw new \UnexpectedValueException('Delivery members do not match the expectation.');
            }
            $workspaces = DeliveryMembers::workspaces($events, $scope['members']);
            foreach ($members as $member) {
                $path = $member['artifact']['path'];
                if (($member['workspace'] ?? null) !== ($workspaces[$path] ?? null)) {
                    throw new \UnexpectedValueException('Delivery member producer changed.');
                }
                self::validateProducer($events, $member['workspace'], $path, $member, $expectation['seq'], $declaredSeq);
                foreach (['baselineSha256', 'promotionSha256'] as $key) {
                    if (!is_string($member[$key] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $member[$key])) {
                        throw new \UnexpectedValueException('Delivery member receipt binding is invalid.');
                    }
                }
            }
        } elseif (array_key_exists('members', $binding)) {
            throw new \UnexpectedValueException('Undeclared delivery members.');
        }
    }

    /** Match one pinned producer to its native ledger history, without making a freshness claim.
     * @param list<Event> $events
     */
    private static function validateProducer(array $events, string $workspace, string $path, mixed $c, int $expectedSeq, int $declaredSeq): void
    {
        $run = $call = null;
        foreach ($events as $event) {
            if ($event->type === 'session.trial_run_recorded' && ($event->payload['workspace'] ?? null) === $workspace) {
                $run = $event;
            }
            if ($event->type === 'session.tool_called' && in_array($event->payload['tool'] ?? '', ['edit', 'implement'], true)) {
                $raw = $event->payload['result'] ?? null;
                $result = is_string($raw) ? json_decode($raw, true) : null;
                if (is_array($result) && ($result['workspace'] ?? null) === $workspace) {
                    $call = $event;
                }
            }
        }
        if ($run === null || $call === null || !is_array($c)
            || ($c['trialRunSeq'] ?? null) !== $run->seq || ($c['toolCallSeq'] ?? null) !== $call->seq
            || !($expectedSeq < $run->seq && $run->seq < $call->seq && $call->seq < $declaredSeq)
            || ($c['artifact']['path'] ?? null) !== $path
            || !is_string($c['artifact']['sha256'] ?? null)
            || ($run->payload['report'][$path]['sha256'] ?? null) !== $c['artifact']['sha256']) {
            throw new \UnexpectedValueException('Delivery candidate no longer matches its bound producer.');
        }
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
