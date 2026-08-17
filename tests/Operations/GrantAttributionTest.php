<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\Agent\Principal;
use Milpa\AppRuntime\Operations\AgentOperations;
use PHPUnit\Framework\TestCase;

/**
 * A durable fact does not change author according to who reads it (greenhouse decisions/0037).
 *
 * Consent is never stored: the runtime REBUILDS it from `question_asked.why` plus the recorded answer
 * whenever it has to decide. That rebuild used to mint its principal from `getenv('USER')` and
 * `gethostname()` — the environment of whoever happened to be running — so the same recorded consent
 * came back owned by a different person depending on who resumed the session. Measured on cattle:
 * rod answered, impostor resumed, the operation ran, and no event named either of them
 * (greenhouse evidence/0209).
 *
 * Reading the recorded `by` instead is not a promotion. It arrives `verified:false` and it stays
 * `verified:false`; what changes is only that the authority stops belonging to the reader.
 *
 * @internal
 */
final class GrantAttributionTest extends TestCase
{
    /** @param array<string, mixed> $decision */
    private function grantsUnder(string $user, array $decision): array
    {
        $antes = getenv('USER');
        putenv('USER=' . $user);

        try {
            $r = new \ReflectionClass(AgentOperations::class);
            $ops = $r->newInstanceWithoutConstructor();
            foreach (['decisionesDeLaSesion' => [$decision], 'sesionDeLosPermisos' => 's1'] as $prop => $valor) {
                $p = $r->getProperty($prop);
                $p->setAccessible(true);
                $p->setValue($ops, $valor);
            }
            $m = $r->getMethod('grantsDeLaSesion');
            $m->setAccessible(true);

            return (array) $m->invoke($ops);
        } finally {
            $antes === false ? putenv('USER') : putenv('USER=' . $antes);
        }
    }

    /** @return array<string, mixed> */
    private function decision(): array
    {
        return [
            'reason' => 'permission',
            'answer' => 'sí',
            'by' => new Principal('cli:rod@cm4070'),
            'why' => (string) json_encode([
                'operation' => 'config:set',
                'arguments' => ['key' => 'agent.treeBudget', 'value' => 42],
            ]),
        ];
    }

    /** THE SAME RECORDED CONSENT, TWO READERS, ONE OWNER. This is the whole invariant. */
    public function testTheRebuiltGrantKeepsWhoeverActuallyAnswered(): void
    {
        $comoRod = $this->grantsUnder('rod', $this->decision());
        $comoOtro = $this->grantsUnder('impostor', $this->decision());

        self::assertCount(1, $comoRod);
        self::assertCount(1, $comoOtro);
        self::assertSame('cli:rod@cm4070', $comoRod[0]->principal);
        self::assertSame(
            $comoRod[0]->principal,
            $comoOtro[0]->principal,
            'authority belongs to whoever gave it, not to whoever is reading',
        );
    }

    /**
     * AN ANSWER WITH NO RECORDED PRINCIPAL LEAVES THE GAP OPEN.
     *
     * Streams written before the answer carried one exist, and filling them in from the environment is
     * exactly the defect this replaces. A null principal is a worse-looking record and a truer one.
     */
    public function testAnAnswerWithoutARecordedPrincipalIsNotInventedFromTheEnvironment(): void
    {
        $decision = $this->decision();
        unset($decision['by']);

        $grants = $this->grantsUnder('impostor', $decision);

        self::assertCount(1, $grants, 'the consent still counts: somebody said yes');
        self::assertNull($grants[0]->principal, 'and nobody is invented to have been them');
    }
}
