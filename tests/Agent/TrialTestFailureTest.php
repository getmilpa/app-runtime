<?php

/**
 * This file is part of Milpa App Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\AppRuntime\Agent\TrialAwareRegistry;
use Milpa\AppRuntime\Agent\TrialRouter;
use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Operation;
use Milpa\EventStore\FileEventStore;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Gate\GatedToolCalls;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Failed native tests must survive the error-only transport without becoming positive evidence. */
final class TrialTestFailureTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-test-verdict-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/src', 0o755, true);
        // A routing fixture, not a confinement test; real bwrap is measured on fresh cattle (0695).
        file_put_contents($this->root . '/bwrap', "#!/bin/sh\nwhile [ \"\$1\" != \"--\" ] && [ \$# -gt 0 ]; do shift; done\nshift\nexec \"\$@\"\n");
        chmod($this->root . '/bwrap', 0o755);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    /** @return iterable<string, array{?array<string, mixed>, int}> */
    public static function failures(): iterable
    {
        yield 'assertion failure' => [['ok' => false, 'ran' => true, 'tests' => 1, 'assertions' => 1, 'failures' => 1, 'errors' => 0, 'output' => 'Expected differs from actual'], 1];
        yield 'PHPUnit exception' => [['ok' => false, 'ran' => true, 'tests' => 1, 'assertions' => 0, 'failures' => 0, 'errors' => 1, 'output' => 'RuntimeException'], 1];
        yield 'unknown counts' => [['ok' => false, 'ran' => true, 'tests' => null, 'assertions' => null, 'failures' => null, 'errors' => null, 'output' => 'Unrecognized runner output'], 1];
        yield 'precondition' => [['ok' => false, 'ran' => false, 'error' => 'PHPUnit is unavailable'], 1];
        yield 'no producer output' => [null, 73];
        yield 'producer error at zero exit' => [['error' => 'An operation error'], 0];
    }

    /** @param array<string, mixed>|null $output */
    #[DataProvider('failures')]
    public function testFailureSurvivesTheErrorChannelAndReopenedStore(?array $output, int $exit): void
    {
        [$registry, $sessions, $operation] = $this->registry($output, $exit);
        $direct = $registry->call('test', []);
        self::assertFalse($direct->success);
        self::assertSame($output, $direct->data, 'Existing direct consumers retain the original producer data');
        $envelope = json_decode((string) $direct->error, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('milpa.trial-test-failure/v1', $envelope['schema']);
        self::assertFalse($envelope['ok']);
        self::assertTrue($envelope['ran_in_trial']);
        self::assertFalse($envelope['applied']);
        self::assertSame($exit, $envelope['trial_exit']);
        self::assertSame($output, $envelope['output']);
        self::assertSame("incidental diagnostic\n", $envelope['stderr']);
        self::assertSame($direct->meta['trial']['workspace'], $envelope['workspace']);
        self::assertArrayNotHasKey('to_apply', $envelope);

        $recorder = new SessionToolGate($sessions, $sessions->load('s-1'), [$operation]);
        $channel = new GatedToolCalls($registry, recorder: $recorder);
        $channel->setContext(new ToolContext(principal: 'test-fixture', scopes: ['plugins.Owned:write']));
        $caught = null;
        try {
            $channel->callTool('test', []);
        } catch (\Exception $error) {
            $caught = $error->getMessage();
        }
        self::assertNotNull($caught, 'A failed test must still throw on the native channel');
        self::assertSame($envelope, json_decode($caught, true, flags: JSON_THROW_ON_ERROR));
        $reopened = new SessionStore(new FileEventStore($this->root . '/var/events.jsonl'));
        $calls = array_values(array_filter($reopened->stream('s-1'), static fn ($e) => $e->type === 'session.tool_called'));
        self::assertCount(1, $calls);
        self::assertFalse($calls[0]->payload['ok']);
        self::assertSame($caught, $calls[0]->payload['result']);
        foreach ($reopened->stream('s-1') as $event) {
            if ($event->type === 'session.effect_observed') {
                self::assertSame([], $event->payload['observation']['evidence']);
            }
        }
    }

    public function testSuccessfulTestKeepsItsExistingEnvelope(): void
    {
        [$registry] = $this->registry(['ok' => true, 'ran' => true], 0);
        $result = $registry->call('test', []);
        self::assertTrue($result->success);
        self::assertNull($result->error);
        self::assertSame(['ok' => true, 'ran' => true], $result->data['output']);
        self::assertArrayNotHasKey('schema', $result->data);
    }

    public function testNonTestFailureKeepsItsExistingError(): void
    {
        [$registry] = $this->registry(['error' => 'Original error'], 1, 'edit');
        $result = $registry->call('edit', []);
        self::assertFalse($result->success);
        self::assertSame('Original error', $result->error);
        self::assertSame(['error' => 'Original error'], $result->data);
    }

    public function testInvalidUtf8DiagnosticStillProducesAJsonError(): void
    {
        [$registry] = $this->registry(null, 73, stderr: "binary \xff");
        $result = $registry->call('test', []);
        self::assertFalse($result->success);
        $envelope = json_decode((string) $result->error, true, flags: JSON_THROW_ON_ERROR);
        self::assertNull($envelope['output']);
        self::assertSame("binary \u{FFFD}", $envelope['stderr']);
    }

    /**
     * @param array<string, mixed>|null $output
     *
     * @return array{TrialAwareRegistry, SessionStore, Operation}
     */
    private function registry(?array $output, int $exit, string $name = 'test', string $stderr = "incidental diagnostic\n"): array
    {
        $stdout = $output === null ? '' : json_encode($output, JSON_THROW_ON_ERROR) . "\n";
        file_put_contents($this->root . '/runner.php', '<?php fwrite(STDERR, ' . var_export($stderr, true) . '); echo ' . var_export($stdout, true) . '; exit(' . $exit . ');');
        $inner = new ToolRegistry(new NullLogger());
        $inner->register($name, 'Calibration operation', ['type' => 'object'], static fn () => throw new \RuntimeException('Host handler must not run'));
        $operation = new Operation(name: $name, description: 'Calibration operation', handler: static fn () => [], mutating: true, effects: new EffectProfile(mutation: Mutation::Persistent, externality: Externality::None));
        $router = new TrialRouter($this->root, new TrialRunner(bwrap: $this->root . '/bwrap'), $this->root . '/runner.php', confinedTesting: true);
        $sessions = new SessionStore(new FileEventStore($this->root . '/var/events.jsonl'));
        $sessions->start('s-1', 'Observe a test result', AutonomyMode::Auto);

        return [new TrialAwareRegistry($inner, $router, [$operation], $sessions, 's-1'), $sessions, $operation];
    }
}
