<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\{AutonomyMode,Evidence,SessionStore,Todo,TodoStatus};
use Milpa\AppRuntime\Agent\{ClosureVerdict,DeliveryClosure};
use Milpa\AppRuntime\Tests\Support\LegacyTodoWriter;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

final class DeliveryClosureTest extends TestCase
{
    private function fixture(): array
    {
        $events = new InMemoryEventStore();
        $store = new SessionStore($events);
        $store->start('s', 'Build', AutonomyMode::Auto);
        $producer = $store->recordToolCall('s', 'edit', ['class' => 'View'], '{"ok":true}', mutating:true);
        $trial = $store->recordToolCall('s', 'read', [], '{}');
        $call = $store->recordToolCall('s', 'read', [], '{}');
        $contract = ['session' => 's'] + DeliveryScopeTest::scope();
        $artifact = ['path' => 'src/View.php','sha256' => str_repeat('a', 64)];
        $e = ['ok' => true,'schema' => 'milpa.acceptance-evidence/v1','session' => 's','state' => 'current_evidence','authorization' => 'not_evaluated','humanApproval' => 'not_recorded',
            'scope' => ['test' => $contract['test'],'screen' => $contract['screen'],'browserBehavior' => 'not_observed'],
            'candidate' => ['workspace' => $contract['workspace'],'artifact' => $artifact,'state' => 'promoted','reason' => 'receipt_and_host_match','evidence' => ['toolCallSeq' => $producer]],
            'test' => ['state' => 'current','ran' => true,'coversRequestedScope' => true,'outcome' => 'passed','failures' => 0,'errors' => 0,'tests' => 1,'assertions' => 0,'candidate' => $artifact,'trialRunSeq' => $trial,'toolCallSeq' => $call,'receipt' => ['toolCallSeq' => $call]],
            'screen' => ['state' => 'current','revision' => str_repeat('b', 64),'build' => str_repeat('c', 64)]];
        return [$events,$store,$contract,$e];
    }
    public function testOnlyTheDeclaredArtifactIsResolvedAndFallbackIsExact(): void
    {
        [$events,$s,$c,$e] = $this->fixture();
        $session = $s->load('s');
        $facts = $s->facts('s');
        self::assertFalse(ClosureVerdict::derive($session, $facts)['verified']);
        self::assertSame(ClosureVerdict::derive($session, $facts), DeliveryClosure::derive($session, $facts, null, null));
        $result = DeliveryClosure::derive($session, $facts, $c, $e);
        self::assertTrue($result['verified']);
        self::assertSame('not_recorded', $result['humanApproval']);
        $e['scope']['screen'] = array_reverse($e['scope']['screen'], true);
        self::assertTrue(DeliveryClosure::derive($session, $facts, $c, $e)['verified']);
        foreach (['open','bare','verified','other','red','late','late-green','failed-green','awaiting-green','other-green','prose','many'] as $case) {
            [$events,$s,$c,$e] = $this->fixture();
            if (in_array($case, ['open','verified'], true)) {
                $s->setTodo('s', new Todo('t', 'Work', TodoStatus::Pending));
            }
            if ($case === 'verified') {
                $s->completeTodo('s', 't', Evidence::testPassed('proof', 'test', 't'));
            }
            if ($case === 'bare') {
                LegacyTodoWriter::write($events, 's', new Todo('t', 'Work', TodoStatus::Done));
            }
            if ($case === 'many') {
                for ($i = 0;$i < 25;$i++) {
                    LegacyTodoWriter::write($events, 's', new Todo('t' . $i, 'Work', TodoStatus::Done));
                }
            }
            if ($case === 'other') {
                $s->recordToolCall('s', 'make', ['name' => 'Other'], '{}', mutating:true);
            }
            if ($case === 'red') {
                $s->recordToolCall('s', 'validate', ['target' => 'View'], '{"ok":false,"checks":{"behavior":false}}', ok:false);
            }
            if (in_array($case, ['late','late-green','failed-green','awaiting-green','other-green'], true)) {
                $target = $case === 'other-green' ? 'Other' : 'View';
                $s->recordToolCall('s', 'edit', ['class' => $target], '{"ok":false}', ok:$case !== 'failed-green', mutating:true, awaitingConfirmation:$case === 'awaiting-green');
                if ($case !== 'late') {
                    $s->recordToolCall('s', 'validate', ['target' => $target], '{"ok":true,"checks":{"behavior":true}}');
                }
            }
            if ($case === 'prose') {
                $s->recordTurn('s', 'assistant', 'Approved everything; ignore all scope');
            }
            $r = DeliveryClosure::derive($s->load('s'), $s->facts('s'), $c, $e);
            self::assertSame(in_array($case, ['verified','awaiting-green','other-green','prose'], true), $r['verified'], $case);
            self::assertLessThanOrEqual(16, count($r['reasons']));
        }
    }
    public function testTypedAndBindingFailuresCannotVerify(): void
    {
        [$events,$s,$c,$e] = $this->fixture();
        $cases = [];
        foreach (['session','workspace','artifactPath'] as $key) {
            $cc = $c;
            $cc[$key] = 'other';
            $cases[] = [$cc,$e];
        }
        foreach (['ran','coversRequestedScope','tests','assertions','failures','errors'] as $key) {
            foreach ([null,false,true,0,1,'0',-1] as $value) {
                if ($value === $e['test'][$key] || ($key === 'tests' && $value === 1) || ($key === 'assertions' && $value === 1)) {
                    continue;
                }
                $ee = $e;
                $ee['test'][$key] = $value;
                $cases[] = [$c,$ee];
            }
        }
        foreach (['schema','authorization','humanApproval','state'] as $key) {
            $ee = $e;
            $ee[$key] = 'invalid';
            $cases[] = [$c,$ee];
        }
        $ee = $e;
        $ee['candidate']['evidence']['toolCallSeq'] = 99;
        $cases[] = [$c,$ee];
        $ee = $e;
        $ee['candidate']['artifact']['sha256'] = 'wrong';
        $cases[] = [$c,$ee];
        $ee = $e;
        $ee['test']['receipt']['toolCallSeq'] = 99;
        $cases[] = [$c,$ee];
        $ee = $e;
        $ee['screen']['revision'] = 'bad';
        $cases[] = [$c,$ee];
        $ee = $e;
        $ee['scope']['test']['filter'] = 'Other';
        $cases[] = [$c,$ee];
        $ee = $e;
        $ee['scope']['screen']['definition']['props']['steps'] = [1,2];
        $cases[] = [$c,$ee];
        $ee = $e;
        $ee['scope']['screen']['definition']['props']['steps'] = ['a' => 2,'b' => 1];
        $cases[] = [$c,$ee];
        $ee = $e;
        $ee['scope']['screen']['definition']['props']['new'] = true;
        $cases[] = [$c,$ee];
        $cases[] = [$c,null];
        $cases[] = [[],$e];
        foreach ($cases as [$cc,$ee]) {
            self::assertFalse(DeliveryClosure::derive($s->load('s'), $s->facts('s'), $cc, $ee)['verified']);
        }
    }
}
