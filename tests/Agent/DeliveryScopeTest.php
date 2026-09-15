<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\{DeliveryScope,ObservedExecutor};
use Milpa\EventStore\{Event,InMemoryEventStore};
use PHPUnit\Framework\TestCase;

final class DeliveryScopeTest extends TestCase
{
    public static function scope(): array
    {
        return ['workspace' => 'w123456789abc','artifactPath' => 'src/View.php','test' => ['path' => 'tests','filter' => ''], 'screen' => ['name' => 'focus','type' => 'counter','definition' => ['props' => ['steps' => [2,1]],'type' => 'counter']]];
    }
    public function testCanonicalScopeSurvivesReopenWithoutClaimingIdentity(): void
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s', 'Build');
        self::assertNull(DeliveryScope::read($store->stream('s'), 's'));
        $scope = self::scope();
        DeliveryScope::record($events, 's', $scope, ObservedExecutor::unknown());
        $r = DeliveryScope::read((new SessionStore($events))->stream('s'), 's');
        self::assertSame(DeliveryScope::parse(json_encode($scope)), $r['scope']);
        self::assertSame(['source' => 'agent_invocation','channel' => 'unknown','principal' => null], $r['provenance']);
        self::assertSame([2,1], $r['scope']['screen']['definition']['props']['steps']);
        self::assertSame(2, $r['seq']);
    }
    public function testExplicitInvalidValuesNeverBecomeAbsence(): void
    {
        $cases = [null,false,[], '{}','{'];
        foreach (['workspace','artifactPath','test','screen'] as $key) {
            $v = self::scope();
            unset($v[$key]);
            $cases[] = $v;
        }
        foreach (['../x','/tmp/x','a//b','a/./b','a\\b','x:y',"a\0b",' tests '] as $path) {
            $v = self::scope();
            $v['artifactPath'] = $path;
            $cases[] = $v;
        }
        foreach (['bad-name','',false] as $v) {
            $s = self::scope();
            $s['workspace'] = $v;
            $cases[] = $s;
        }
        $s = self::scope();
        $s['extra'] = true;
        $cases[] = $s;
        $s = self::scope();
        $s['test']['extra'] = true;
        $cases[] = $s;
        $s = self::scope();
        $s['test']['filter'] = false;
        $cases[] = $s;
        $s = self::scope();
        $s['screen']['name'] = '';
        $cases[] = $s;
        $s = self::scope();
        $s['screen']['definition'] = null;
        $cases[] = $s;
        $s = self::scope();
        $s['screen']['definition'] = ['large' => str_repeat('a', 17000)];
        $cases[] = $s;
        foreach ($cases as $case) {
            try {
                DeliveryScope::parse($case);
                self::fail('Invalid declaration was accepted');
            } catch (\InvalidArgumentException|\JsonException) {
                self::assertTrue(true);
            }
        }
    }
    public function testDuplicateAndCorruptRecordsFailClosed(): void
    {
        $events = new InMemoryEventStore();
        DeliveryScope::record($events, 's', self::scope(), ObservedExecutor::unknown());
        $rows = (new SessionStore($events))->stream('s');
        $event = $rows[0];
        foreach (['hash','stream','schema','session','source','channel','duplicate','scope'] as $case) {
            $p = $event->payload;
            $stream = $event->streamId;
            if ($case === 'hash') {
                $p['sha256'] = 'invalid';
            }
            if ($case === 'schema') {
                $p['schema'] = 'invalid';
            }
            if ($case === 'session') {
                $p['session'] = 'other';
            }
            if ($case === 'source') {
                $p['provenance']['source'] = 'model';
            }
            if ($case === 'channel') {
                unset($p['provenance']['channel']);
            }
            if ($case === 'scope') {
                $p['scope']['test']['filter'] = 'Other';
            }
            if ($case === 'stream') {
                $stream = 'other';
            }
            $r = [new Event($stream, DeliveryScope::EVENT, $p, 1)];
            if ($case === 'duplicate') {
                $r[] = $event;
            }
            try {
                DeliveryScope::read($r, 's');
                self::fail($case);
            } catch (\UnexpectedValueException) {
                self::assertTrue(true);
            }
        }
    }
}
