<?php

/**
 * This file is part of milpa/app-runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Operations;

use Milpa\AppRuntime\Agent\SurfaceBroadcaster;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\TestCase;

/**
 * THE WIRING, PINNED (greenhouse decisions/0608, evidence/1174). The streaming redactor is proven on its own in
 * StreamingSecretRedactionTest; this fixes that the operation actually RUNS the model's output through it — the
 * answer it returns, and the live view it broadcasts — so a change that drops the redaction fails a test, not only
 * a lab measure. The reasoning and the model return are pinned in IntakeObserverTest.
 */
final class TheModelsOutputIsRedactedTest extends TestCase
{
    private const CANARY = 'canary-output-7f3a9c2e1b8d4056';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-output-redaction-' . bin2hex(random_bytes(4));
        @mkdir($this->root . '/.milpa', 0o700, true);
        file_put_contents($this->root . '/.milpa/secrets.json', json_encode(['agent' => ['apiKey' => self::CANARY]]));
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/.milpa/secrets.json');
        @rmdir($this->root . '/.milpa');
        @rmdir($this->root);
    }

    public function testTheAnswerASurfaceGetsIsRedacted(): void
    {
        $probe = new RedactionProbe($this->root);

        $answer = $probe->answerForTest('the key is ' . self::CANARY . ' and the house is founded');

        self::assertStringNotContainsString(self::CANARY, $answer, 'the answer a surface returns must not carry the secret');
        self::assertStringContainsString('[secret]', $answer);
        self::assertStringContainsString('the house is founded', $answer, 'the control: the rest of the answer survives');
    }

    public function testANonSecretAnswerIsUntouched(): void
    {
        $probe = new RedactionProbe($this->root);
        self::assertSame('two capabilities are on', $probe->answerForTest('two capabilities are on'));
    }

    public function testTheLiveReasoningViewIsRedacted(): void
    {
        $probe = new RedactionProbe($this->root);
        $closure = $probe->liveClosure('s1');

        // The canary split across two reasoning batches, then content ends the block (flushing the held tail).
        $closure('thinking: ' . substr(self::CANARY, 0, 9), 'reasoning');
        $closure(substr(self::CANARY, 9) . ' — done', 'reasoning');
        $closure('here is the answer', 'content');

        $painted = '';
        foreach ($probe->sent as $payload) {
            $painted .= (string) ($payload['reasoning']['delta'] ?? '');
        }

        self::assertStringNotContainsString(self::CANARY, $painted, 'the live view must not paint the secret, even split across batches');
        self::assertStringContainsString('[secret]', $painted);
        self::assertStringContainsString('thinking:', $painted, 'the control: the thinking around it is still painted');
        self::assertStringContainsString('done', $painted, 'the end of the block is not lost');
    }
}

/**
 * A test double of the operation: it answers for its root and broadcasts into an array, so the answer-redaction
 * seam and the live-view redactor can be driven without a kernel or a live surface.
 */
final class RedactionProbe extends AgentOperations
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function __construct(private readonly ?string $testRoot)
    {
        parent::__construct(new DIContainer());
    }

    public function answerForTest(string $respuesta): string
    {
        return $this->redactedAnswer($respuesta);
    }

    public function liveClosure(string $session): \Closure
    {
        (new \ReflectionProperty(AgentOperations::class, 'intakeSession'))->setValue($this, $session);
        $closure = $this->progresoDelModelo();
        if ($closure === null) {
            throw new \RuntimeException('the broadcaster double should make progresoDelModelo return a closure');
        }

        return $closure;
    }

    protected function redactRoot(): ?string
    {
        return $this->testRoot;
    }

    protected function broadcaster(): ?SurfaceBroadcaster
    {
        $probe = $this;

        return new class ($probe) implements SurfaceBroadcaster {
            public function __construct(private readonly RedactionProbe $probe)
            {
            }

            public function broadcast(string $topic, array $payload): void
            {
                $this->probe->sent[] = $payload;
            }
        };
    }
}
