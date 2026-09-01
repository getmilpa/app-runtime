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

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\PendingQuestion;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\AppRuntime\Support\ContratoInstalado;
use Milpa\Command\Consent\ConsentGrant;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * The four falsifiers of decisions/0184: one human ceremony, not two — and never a widened one.
 *
 * Measured in a live run (greenhouse evidence/0444): the operator answered the INTENT question and
 * the CONSENT gate still refused the very same call. The ruling: the confirmed intent is a CLAIM,
 * and the POLICY decides whether that claim is admissible evidence for consent, per the operation's
 * EffectProfile. This battery is what would refute the arc:
 *
 *   F1 REUSE            mid-tier op, intent confirmed for the exact call → no perm: question, and
 *                       the PolicyGate layer sees a covering grant minted from the claim
 *   F2 DIFFERENT TARGET intent op(X), call op(Y) → MUST ask, both layers
 *   F3 DIFFERENT VALUE  config-set(debug=true) confirmed, call (debug=false) → MUST ask —
 *                       the value IS the scope
 *   F4 HIGH TIER        a Privileged op confirmed exactly → STILL asks — the double ceremony of
 *                       evidence/0444 is DELIBERATE policy, not a bug
 *
 * Plus: a «no» never admits, and a claim from another session never admits.
 */
final class GateAdmitsConfirmedIntentTest extends TestCase
{
    /** The mid-tier fixture of F1/F2: persistent, as-user, nothing leaves, target named by contract. */
    private function archiveOp(): Operation
    {
        return new Operation(
            'notes.archive',
            'Archives a note',
            static fn (array $i): array => ['ok' => true],
            inputSchema: ['type' => 'object', 'properties' => []],
            mutating: true,
            namedTarget: 'name',
            effects: new EffectProfile(
                Mutation::Persistent,
                Externality::None,
                Reversibility::Compensatable,
                Authority::WriteAsUser,
                subject: Subject::Data,
            ),
        );
    }

    /** The F3 fixture: mid-tier, no named-target contract — the claim is crafted as a fact. */
    private function configSetOp(): Operation
    {
        return new Operation(
            'config.set',
            'Sets a config key',
            static fn (array $i): array => ['ok' => true],
            inputSchema: ['type' => 'object', 'properties' => []],
            mutating: true,
            effects: new EffectProfile(
                Mutation::Persistent,
                Externality::None,
                Reversibility::Compensatable,
                Authority::WriteAsUser,
                subject: Subject::Configuration,
            ),
        );
    }

    /** The F4 fixture, plugins.register-shaped: Privileged, local, executable — the MEASURED case of 0444. */
    private function registerOp(): Operation
    {
        return new Operation(
            'plugins.register',
            'Wires a plugin into the app',
            static fn (array $i): array => ['ok' => true],
            inputSchema: ['type' => 'object', 'properties' => []],
            mutating: true,
            requiresConfirmation: true,
            effects: new EffectProfile(
                Mutation::Persistent,
                Externality::None,
                Reversibility::Compensatable,
                Authority::Privileged,
                subject: Subject::Executable,
            ),
        );
    }

    /**
     * Leaves in the stream the exact fact the intent ceremony leaves: the question with its
     * structured `why`, answered — the same fact-read the gate resolves claims from.
     *
     * @param array<string, mixed> $arguments
     */
    private function confirmIntent(SessionStore $store, string $sessionId, string $operation, array $arguments, string $answer = 'sí'): void
    {
        $store->ask($sessionId, new PendingQuestion(
            id: 'intent-' . substr(sha1($operation), 0, 12),
            question: "La petición no nombra al objetivo. ¿Confirmas {$operation}?",
            options: ['sí', 'no'],
            why: json_encode(
                ['operation' => $operation, 'arguments' => $arguments],
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
            ) ?: null,
            reason: 'target_not_named',
        ));
        $store->answer($sessionId, 'intent-' . substr(sha1($operation), 0, 12), $answer);
    }

    private function gate(SessionStore $store, string $sessionId, Operation $op, string $petition = ''): SessionToolGate
    {
        $session = $store->load($sessionId);
        self::assertNotNull($session);

        return new SessionToolGate($store, $session, [$op], petition: $petition);
    }

    /**
     * The real rebuild path of the PolicyGate layer's grants, never a re-implementation: the same
     * reflection walk LaunchGrantsTest uses on `grantsDeLaSesion()`, with the catalogue captured.
     *
     * @param list<Operation> $catalogue
     *
     * @return list<ConsentGrant>
     */
    private function grantsRebuiltFrom(SessionStore $store, string $sessionId, array $catalogue): array
    {
        $session = $store->load($sessionId);
        self::assertNotNull($session);

        $operations = new AgentOperations(new DIContainer());
        $decisions = new \ReflectionProperty(AgentOperations::class, 'decisionesDeLaSesion');
        $decisions->setValue($operations, ContratoInstalado::arreglo($session, 'decisions'));
        $ownerSession = new \ReflectionProperty(AgentOperations::class, 'sesionDeLosPermisos');
        $ownerSession->setValue($operations, $sessionId);
        $catalogueProp = new \ReflectionProperty(AgentOperations::class, 'catalogueForIntentClaims');
        $catalogueProp->setValue($operations, $catalogue);

        $method = new \ReflectionMethod(AgentOperations::class, 'grantsDeLaSesion');

        /** @var list<ConsentGrant> $grants */
        $grants = $method->invoke($operations);

        return $grants;
    }

    // ── F1 REUSE: the full ceremony, one yes, no second question ────────────────────────────────

    public function testF1AConfirmedIntentOnAMidTierOpAnswersTheConsentQuestionToo(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'Archive the old notes.', AutonomyMode::Ask);

        // The petition does not name the target: the intent ceremony runs for real.
        $first = $this->gate($store, 's1', $this->archiveOp(), 'Archive the old notes.');
        self::assertNotNull($first->refuse('notes_archive', ['name' => 'Ledger2024']));
        $question = $store->load('s1')?->question;
        self::assertNotNull($question);
        self::assertSame('target_not_named', $question->reason, 'the pause is the intent question, not perm:');

        // The human answers the intent question — ONCE.
        $store->answer('s1', $question->id, 'sí');

        // The SAME exact call passes whole: intent contract satisfied AND no perm: question minted.
        $second = $this->gate($store, 's1', $this->archiveOp(), 'Archive the old notes.');
        self::assertNull(
            $second->refuse('notes_archive', ['name' => 'Ledger2024']),
            'the confirmed claim is admissible evidence for consent on this tier (decisions/0184)',
        );
        self::assertNull($store->load('s1')?->question, 'no second ceremony was opened');
    }

    public function testF1AtThePolicyGateLayerTheMintedGrantCoversTheExactCall(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'Archive the old notes.', AutonomyMode::Ask);
        $this->confirmIntent($store, 's1', 'notes.archive', ['name' => 'Ledger2024']);

        $grants = $this->grantsRebuiltFrom($store, 's1', [$this->archiveOp()]);

        self::assertCount(1, $grants);
        self::assertSame('intent-confirmed', $grants[0]->provenance);
        self::assertSame('s1', $grants[0]->session);
        self::assertSame(['name' => 'Ledger2024'], $grants[0]->arguments);
        self::assertTrue($grants[0]->covers('notes_archive', ['name' => 'Ledger2024'], 's1'));
    }

    // ── F2 DIFFERENT TARGET: the claim names one act, not the operation ─────────────────────────

    public function testF2ADifferentTargetStillAsksAtTheGate(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'Archive the old notes.', AutonomyMode::Ask);
        $this->confirmIntent($store, 's1', 'notes.archive', ['name' => 'Ledger2024']);

        $gate = $this->gate($store, 's1', $this->archiveOp(), 'Archive the old notes.');

        self::assertNotNull($gate->refuse('notes_archive', ['name' => 'Otro']), 'the yes was about Ledger2024');
        self::assertNotNull($store->load('s1')?->question, 'and the session is left waiting');
    }

    public function testF2ADifferentTargetIsNotCoveredAtThePolicyGateLayer(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'Archive the old notes.', AutonomyMode::Ask);
        $this->confirmIntent($store, 's1', 'notes.archive', ['name' => 'Ledger2024']);

        $grants = $this->grantsRebuiltFrom($store, 's1', [$this->archiveOp()]);

        self::assertCount(1, $grants);
        self::assertFalse($grants[0]->covers('notes_archive', ['name' => 'Otro'], 's1'));
    }

    /** A claim for ANOTHER operation admits nothing, even with identical arguments. */
    public function testF2AClaimForAnotherOperationDoesNotAdmit(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'x', AutonomyMode::Ask);
        $this->confirmIntent($store, 's1', 'other.op', ['key' => 'debug', 'value' => true]);

        $gate = $this->gate($store, 's1', $this->configSetOp());

        self::assertNotNull($gate->refuse('config_set', ['key' => 'debug', 'value' => true]));
        self::assertSame('perm:config.set', $store->load('s1')?->question?->id);
    }

    // ── F3 DIFFERENT VALUE: the value IS the scope ──────────────────────────────────────────────

    public function testF3TheExactConfirmedValueAdmitsAndTheOppositeValueAsks(): void
    {
        // Positive control: the exact confirmed call is admitted without a perm: question.
        $exact = new SessionStore(new InMemoryEventStore());
        $exact->start('s1', 'x', AutonomyMode::Ask);
        $this->confirmIntent($exact, 's1', 'config.set', ['key' => 'debug', 'value' => true]);
        self::assertNull(
            $this->gate($exact, 's1', $this->configSetOp())->refuse('config_set', ['key' => 'debug', 'value' => true]),
            'control: the exact confirmed call is admitted — without it the negative below proves nothing',
        );

        // The discriminator: same key, opposite value — MUST ask.
        $flipped = new SessionStore(new InMemoryEventStore());
        $flipped->start('s2', 'x', AutonomyMode::Ask);
        $this->confirmIntent($flipped, 's2', 'config.set', ['key' => 'debug', 'value' => true]);

        self::assertNotNull(
            $this->gate($flipped, 's2', $this->configSetOp())->refuse('config_set', ['key' => 'debug', 'value' => false]),
            'config-set(debug=true) confirmed does not authorize config-set(debug=false)',
        );
        self::assertSame('perm:config.set', $flipped->load('s2')?->question?->id);
    }

    // ── F4 HIGH TIER: for this profile, intent does not equal consent — 0444 pinned ─────────────

    public function testF4APrivilegedOpStillAsksAfterAnExactConfirmedIntent(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'x', AutonomyMode::Ask);
        $this->confirmIntent($store, 's1', 'plugins.register', ['plugin' => 'TareasPlugin']);

        $gate = $this->gate($store, 's1', $this->registerOp());

        self::assertNotNull(
            $gate->refuse('plugins_register', ['plugin' => 'TareasPlugin']),
            'the double ceremony of evidence/0444 is DELIBERATE policy for a Privileged profile',
        );
        self::assertSame('perm:plugins.register', $store->load('s1')?->question?->id);
    }

    public function testF4NoGrantIsMintedFromAHighTierClaim(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'x', AutonomyMode::Ask);
        $this->confirmIntent($store, 's1', 'plugins.register', ['plugin' => 'TareasPlugin']);

        self::assertSame([], $this->grantsRebuiltFrom($store, 's1', [$this->registerOp()]));
    }

    // ── THE REMAINING NEGATIVES ─────────────────────────────────────────────────────────────────

    public function testANoNeverAdmits(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'x', AutonomyMode::Ask);
        $this->confirmIntent($store, 's1', 'config.set', ['key' => 'debug', 'value' => true], answer: 'no');

        $gate = $this->gate($store, 's1', $this->configSetOp());

        self::assertNotNull($gate->refuse('config_set', ['key' => 'debug', 'value' => true]));
        self::assertSame([], $this->grantsRebuiltFrom($store, 's1', [$this->configSetOp()]), 'a no mints nothing');
    }

    public function testAClaimFromAnotherSessionNeverAdmits(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'x', AutonomyMode::Ask);
        $store->start('s2', 'x', AutonomyMode::Ask);
        $this->confirmIntent($store, 's1', 'config.set', ['key' => 'debug', 'value' => true]);

        $gate = $this->gate($store, 's2', $this->configSetOp());

        self::assertNotNull(
            $gate->refuse('config_set', ['key' => 'debug', 'value' => true]),
            'the claim lives in s1; s2 owes its own question',
        );
    }

    /** An operation that never declared its effects admits nothing: unclassified is not harmless. */
    public function testAnUnclassifiedOperationNeverAdmitsAConfirmedIntent(): void
    {
        $bare = new Operation(
            'config.set',
            'Sets a config key, effects undeclared',
            static fn (array $i): array => ['ok' => true],
            inputSchema: ['type' => 'object', 'properties' => []],
            mutating: true,
        );
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'x', AutonomyMode::Ask);
        $this->confirmIntent($store, 's1', 'config.set', ['key' => 'debug', 'value' => true]);

        self::assertNotNull($this->gate($store, 's1', $bare)->refuse('config_set', ['key' => 'debug', 'value' => true]));
        self::assertSame([], $this->grantsRebuiltFrom($store, 's1', [$bare]), 'no declared ceiling, no minted grant');
    }

    /** A claim that names no arguments scopes nothing: it must not become a blanket grant. */
    public function testAClaimWithEmptyArgumentsMintsNoGrant(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'x', AutonomyMode::Ask);
        $this->confirmIntent($store, 's1', 'config.set', []);

        self::assertSame(
            [],
            $this->grantsRebuiltFrom($store, 's1', [$this->configSetOp()]),
            'a ConsentGrant with empty arguments covers every call of the operation — a claim may never buy that',
        );
    }

    /** Without the captured catalogue there is no ceiling to judge by, and judgment fails closed. */
    public function testWithoutACatalogueNoIntentGrantIsMinted(): void
    {
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s1', 'x', AutonomyMode::Ask);
        $this->confirmIntent($store, 's1', 'config.set', ['key' => 'debug', 'value' => true]);

        self::assertSame([], $this->grantsRebuiltFrom($store, 's1', []));
    }
}
