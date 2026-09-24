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

namespace Milpa\AppRuntime\Tests\Web;

use Milpa\AppRuntime\Web\ScreenOperations;
use Milpa\AppRuntime\Web\ScreenStore;
use Milpa\Command\Operation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `screen:declare` earns its «served» receipt, and a screen it cannot serve says why.
 *
 * The receipt is not a message: a judge closes a work claim on predicate «served»
 * (greenhouse decisions/0187). It was emitted for having STORED the screen, and measured on cattle it
 * answered `predicate: served` for a screen whose page answered 500 — so an agent could close
 * «I delivered the screen» over a broken page (greenhouse evidence/0995). Where the host can serve
 * the page, only a 200 earns the receipt.
 *
 * And the refusal when the live wire is not mounted used to name the symptom — «the registry is not
 * mounted», which nobody can act on — instead of the fix.
 *
 * @guards the served receipt, and the refusal of an unmounted live wire
 *
 * @fires  on every screen:declare
 *
 * @refuses a served receipt for a page that did not answer 200
 *
 * @subject-in milpa/app-runtime
 */
#[CoversClass(ScreenOperations::class)]
final class AServedReceiptIsEarnedTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/milpa-served-' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testAPageThatAnswers200EarnsTheReceipt(): void
    {
        $result = $this->declare(static fn (string $name): int => 200);

        self::assertTrue($result['ok']);
        self::assertSame('served', $result['evidence']['predicate'] ?? null, 'a page that served earns the receipt');
        self::assertArrayNotHasKey('served', $result, 'and no disclaimer rides along with it');
    }

    public function testAPageThatFailsGetsNoReceiptAndSaysWhy(): void
    {
        $result = $this->declare(static fn (string $name): int => 500);

        self::assertTrue($result['ok'], 'the screen is still declared — storing it succeeded');
        self::assertArrayNotHasKey('evidence', $result, 'a page that answered 500 earns NO served receipt');
        self::assertFalse($result['served'] ?? null);
        self::assertSame(500, $result['status'] ?? null);
        self::assertStringContainsString('HTTP 500', (string) ($result['note'] ?? ''));
    }

    public function testAPageThatCannotBeRequestedIsNotCalledServed(): void
    {
        // «I could not ask» is not «it served» — an empty answer is not a fact.
        $result = $this->declare(static fn (string $name): ?int => null);

        self::assertArrayNotHasKey('evidence', $result);
        self::assertFalse($result['served'] ?? null);
    }

    public function testAStandaloneCallerKeepsThePreviousBehaviour(): void
    {
        // THE CONTROL on scope: nothing wired, nothing changed. Other hosts that build these operations
        // by hand are not silently stripped of the receipt their judge already reads.
        $result = $this->declare(null);

        self::assertSame('served', $result['evidence']['predicate'] ?? null);
    }

    public function testAnUnmountedLiveWireNamesTheFixNotTheSymptom(): void
    {
        $operations = (new ScreenOperations(
            new ScreenStore($this->path),
            [],
            null,
            static fn (): mixed => null,
            static fn (): string => 'the live wire has no secret — set live.secret in config/app.php',
        ))->operations();

        $result = ($this->named($operations, 'screen:declare')->handler)(['name' => 'probe']);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('live.secret', (string) $result['error'], 'the reader is told what to set');
    }

    /** @return array<string, mixed> */
    private function declare(?\Closure $serve): array
    {
        $operations = (new ScreenOperations(new ScreenStore($this->path), ['data-table'], null, null, null, $serve))->operations();

        return ($this->named($operations, 'screen:declare')->handler)([
            'name' => 'blog-probe',
            'columns' => [['key' => 'title', 'label' => 'Title']],
            'rows' => [['title' => 'hello']],
        ]);
    }

    /** @param list<Operation> $operations */
    private function named(array $operations, string $name): Operation
    {
        foreach ($operations as $operation) {
            if ($operation->name === $name) {
                return $operation;
            }
        }
        self::fail("no {$name} offered");
    }
}
