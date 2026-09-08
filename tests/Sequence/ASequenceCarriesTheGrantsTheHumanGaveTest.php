<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Sequence;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\PendingQuestion;
use Milpa\Agent\Principal;
use Milpa\Agent\Session;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\ConsentBridge;
use Milpa\AppRuntime\Sequence\GovernedDoor;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * A STEP THE HUMAN APPROVED RUNS ON RESUME — through the sequence door, with the real gates.
 *
 * Measured in the browser ceremony of decisions/0223 F4 (greenhouse evidence/0561): the human approved
 * `config:set` from the Desktop, the ledger held `session.question_answered` (by `actor:passkey:…`)
 * and `session.permission_granted`, and the resume still answered «'config_set' needs explicit
 * consent and channel 'cli' takes consent as a signature naming this call — none was presented».
 * The session ALLOWED the step; the door handed the tool-runtime gate an empty list of grants.
 *
 * The step here is the shape that trips that gate: its ceiling demands consent (Executable +
 * Privileged, rule S2), so the projected tool carries `requiresConfirmation` and the gate wants a
 * covering grant or a signature. The door must hand it the grant the ledger already holds.
 */
final class ASequenceCarriesTheGrantsTheHumanGaveTest extends TestCase
{
    /** F1 · the grant the human gave in the ledger reaches the gate: the approved step runs. */
    public function testAStepTheSessionGrantedPassesTheToolRuntimeGate(): void
    {
        $bridge = $this->doorWithAGrantFor('lab:burn');

        $result = $bridge->callTool('lab:burn', ['what' => 'the approved thing']);

        self::assertIsArray($result);
        self::assertTrue($result['ok'] ?? false, 'the human said yes, the ledger holds it, and the gate still refused: ' . json_encode($result));
        self::assertSame('the approved thing', $result['burned'] ?? null, 'the step ran with its own arguments');
    }

    /**
     * THE CONTROL: a yes given for ANOTHER operation covers nothing. The session allows only what it
     * granted, so the step is refused at the session's own gate — never waved through, and never with
     * the tool-runtime's «none was presented» either: that sentence was the door's defect.
     */
    public function testAGrantForAnotherOperationCoversNothing(): void
    {
        $bridge = $this->doorWithAGrantFor('lab:other');

        try {
            $result = $bridge->callTool('lab:burn', ['what' => 'x']);
            self::assertNotTrue(\is_array($result) ? ($result['ok'] ?? false) : false, 'a step nobody granted ran');
        } catch (\Throwable $refused) {
            self::assertStringNotContainsString('none was presented', $refused->getMessage(), 'refused for the right reason: the session did not allow it');
        }
    }

    private function doorWithAGrantFor(string $granted): ConsentBridge
    {
        $store = new SessionStore(new InMemoryEventStore());
        $sid = 'sequence:deploy';
        $store->start($sid, 'run the deploy sequence', AutonomyMode::Ask);
        // What the gate writes when it pauses: the question, with the FACT inside (operation + arguments).
        $store->ask($sid, new PendingQuestion(
            'perm:' . $granted,
            'El agente quiere correr «' . $granted . '». ¿Lo autorizas en esta sesión?',
            ['sí', 'no'],
            // THE FACT AS THE GATE WRITES IT: with the exact arguments the human saw — never `[]`, a blanket
            // grant the real gate never writes (greenhouse decisions/0226).
            json_encode(['operation' => $granted, 'arguments' => ['what' => 'the approved thing']], \JSON_THROW_ON_ERROR),
            null,
            'permission',
        ));
        // What the Desktop's Approve posts, through `agent:answer`, with the passkey session as principal.
        $store->answer($sid, 'perm:' . $granted, 'sí', new Principal('actor:passkey:test', true), 'rod@desktop');
        $store->grant($sid, $granted);

        $session = $store->load($sid);
        self::assertInstanceOf(Session::class, $session);

        $root = sys_get_temp_dir() . '/sequence-grants-' . bin2hex(random_bytes(4));
        mkdir($root, 0o775, true);

        return GovernedDoor::open($this->kernelWith([self::burn()], $root), $root, $store, $session, 'run the deploy sequence');
    }

    /** The shape that trips the tool-runtime gate: a mutating step whose ceiling demands consent (S2). */
    private static function burn(): Operation
    {
        return new Operation(
            name: 'lab:burn',
            description: 'a governed write only a human may authorize',
            handler: static fn (array $input): array => ['ok' => true, 'burned' => $input['what'] ?? null],
            inputSchema: ['type' => 'object', 'properties' => ['what' => ['type' => 'string']], 'required' => []],
            mutating: true,
            effects: new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::ManualRecovery, Authority::Privileged, subject: Subject::Executable),
        );
    }

    /**
     * A kernel that answers what the door asks of it: its root and a command table holding the step.
     *
     * @param list<Operation> $commands
     */
    private function kernelWith(array $commands, string $root): Kernel
    {
        $kernel = (new \ReflectionClass(Kernel::class))->newInstanceWithoutConstructor();
        // The projector builds the step's tool with the kernel's container, so the stub carries one.
        foreach (['root' => $root, 'commands' => $commands, 'container' => new DIContainer()] as $name => $value) {
            $prop = new \ReflectionProperty(Kernel::class, $name);
            $prop->setAccessible(true);
            $prop->setValue($kernel, $value);
        }

        return $kernel;
    }
}
