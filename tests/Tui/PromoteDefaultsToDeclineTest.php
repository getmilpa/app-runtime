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

namespace Milpa\AppRuntime\Tests\Tui;

use Milpa\Agent\PendingQuestion;
use Milpa\AppRuntime\Tui\AgentScreen;
use PHPUnit\Framework\TestCase;

/**
 * The promotion gate — the door into the house — DEFAULTS TO DECLINE (greenhouse decisions/0071,
 * Rod's ruling): a tired human pressing Enter gets «not promoted», never «promoted». The general
 * low-friction doctrine (Enter = sí) is untouched for every other permission question; only the
 * promote gate, a new high-consequence door, pre-selects decline.
 */
final class PromoteDefaultsToDeclineTest extends TestCase
{
    public function testThePromoteGatePreSelectsDecline(): void
    {
        $screen = $this->screen();
        $q = new PendingQuestion(id: 'perm:sandbox:promote', question: '¿promuevo?', options: ['sí', 'no'], reason: 'permission');

        $this->align($screen, $q);

        self::assertSame(1, $this->option($screen), 'the promote gate pre-selects «no» (index 1), so Enter declines');
    }

    public function testAnOrdinaryPermissionGateStillPreSelectsApprove(): void
    {
        $screen = $this->screen();
        $q = new PendingQuestion(id: 'perm:config:set', question: '¿autorizo?', options: ['sí', 'no'], reason: 'permission');

        $this->align($screen, $q);

        self::assertSame(0, $this->option($screen), 'other gates keep the low-friction default: Enter = sí');
    }

    public function testTheHumansChoiceIsNotOverriddenWhileTheSameQuestionStands(): void
    {
        $screen = $this->screen();
        $q = new PendingQuestion(id: 'perm:sandbox:promote', question: '¿promuevo?', options: ['sí', 'no'], reason: 'permission');

        $this->align($screen, $q);            // pre-selects decline (1)
        $this->setOption($screen, 0);          // human arrows to «sí»
        $this->align($screen, $q);            // same question stands — must NOT snap back

        self::assertSame(0, $this->option($screen), 'once the human chose, aligning the same question does not override them');
    }

    private function screen(): AgentScreen
    {
        return new AgentScreen(static fn (): array => ['ok' => true], null, null, 74, 16, false);
    }

    private function align(AgentScreen $s, PendingQuestion $q): void
    {
        $m = new \ReflectionMethod($s, 'alinearDefault');
        $m->setAccessible(true);
        $m->invoke($s, $q);
    }

    private function option(AgentScreen $s): int
    {
        $p = new \ReflectionProperty($s, 'opcionPregunta');
        $p->setAccessible(true);

        return (int) $p->getValue($s);
    }

    private function setOption(AgentScreen $s, int $v): void
    {
        $p = new \ReflectionProperty($s, 'opcionPregunta');
        $p->setAccessible(true);
        $p->setValue($s, $v);
    }
}
