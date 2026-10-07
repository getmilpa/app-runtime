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

namespace Milpa\AppRuntime\Tests\Config;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\ConsentBridge;
use Milpa\AppRuntime\Agent\SessionToolGate;
use Milpa\AppRuntime\Config\AgentEndpoint;
use Milpa\AppRuntime\Config\ProviderCredentials;
use Milpa\AppRuntime\Config\SecretOverlay;
use Milpa\AppRuntime\Config\SecretRedaction;
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\AppRuntime\Operations\ConfigOperations;
use Milpa\Container\DIContainer;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The key of a declared endpoint is a secret the house keeps (greenhouse decisions/0589, evidence/1130).
 *
 * `provider:declare` wrote `agent.apiKey` into the secret overlay and no request read it: a declared endpoint was
 * sent `MILPA_AGENT_API_KEY` and only that. And the redaction of decisions/0569 knew only the overlay — so the key
 * that worked was one the house did not protect, and the key it protected did not work. Measured on published
 * 0.211.1 with two canaries: the overlay's was never sent; the environment's went to the model in 5 of 8 requests
 * once a test printed it; and the ledger kept both.
 *
 * @guards the declared key being the one a declared endpoint is sent, the environment still accepted when nothing is
 *         declared, and `provider:declare` taking the value from a file so it is in no command line
 *
 * @refuses a provider credential — declared, or read from the environment — in a tool result, in a failure's text or
 *          in the ledger; a second reader of those variables; a key file the repository would commit
 *
 * @subject-in milpa/app-runtime
 */
final class TheEndpointKeyIsASecretTheHouseKeepsTest extends TestCase
{
    private const DECLARED = 'canary-declared-0f3a9c2e41d85b60';
    private const EXPORTED = 'canary-exported-b81e04d7a6c3295f';
    private const VARIABLES = ['MILPA_AGENT_API_KEY', 'OPENAI_API_KEY', 'ANTHROPIC_API_KEY', 'MILPA_AGENT_BASIC_AUTH'];
    private const ENDPOINT = ['MILPA_AGENT_BASE_URL', 'MILPA_AGENT_MODEL'];

    private string $root = '';

    /** A directory outside the house: where a key file belongs. */
    private string $keys = '';

    /** @var array<string, false|string> */
    private array $before = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-endpoint-key-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/.milpa', 0o700, true);
        $this->keys = sys_get_temp_dir() . '/milpa-keys-' . bin2hex(random_bytes(5));
        mkdir($this->keys, 0o700, true);
        foreach ([...self::VARIABLES, ...self::ENDPOINT] as $variable) {
            $this->before[$variable] = getenv($variable);
            putenv($variable);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->before as $variable => $value) {
            $value === false ? putenv($variable) : putenv("{$variable}={$value}");
        }
        foreach ([$this->root . SecretOverlay::RUTA, $this->root . '/endpoint.key', $this->keys . '/link.key', $this->keys . '/endpoint.key'] as $file) {
            @unlink($file);
        }
        @rmdir($this->root . '/.milpa');
        @rmdir($this->root);
        @rmdir($this->keys);
        AgentEndpoint::useProviderFetcher(null);
    }

    public function testTheDeclaredKeyIsTheOneADeclaredEndpointIsSent(): void
    {
        $this->declareInTheOverlay(self::DECLARED);

        self::assertSame([self::DECLARED, 'declared'], ProviderCredentials::endpointKey($this->root));
    }

    public function testTheEnvironmentIsStillAcceptedWhenNothingIsDeclared(): void
    {
        putenv('MILPA_AGENT_API_KEY=' . self::EXPORTED);

        self::assertSame([self::EXPORTED, 'environment'], ProviderCredentials::endpointKey($this->root));
        self::assertSame([self::EXPORTED, 'environment'], ProviderCredentials::endpointKey(null), 'a house whose root is not known has no overlay to read');
    }

    /** One rule for the endpoint and its key: what the house declared wins over the environment (as `agent.baseUrl` does). */
    public function testTheDeclaredKeyWinsOverTheEnvironment(): void
    {
        $this->declareInTheOverlay(self::DECLARED);
        putenv('MILPA_AGENT_API_KEY=' . self::EXPORTED);

        self::assertSame([self::DECLARED, 'declared'], ProviderCredentials::endpointKey($this->root));
    }

    public function testWithNeitherThereIsNoKey(): void
    {
        self::assertNull(ProviderCredentials::endpointKey($this->root));

        file_put_contents($this->root . SecretOverlay::RUTA, (string) json_encode(['agent' => ['apiKey' => '']]));
        self::assertNull(ProviderCredentials::endpointKey($this->root), 'an empty declaration is not a key');
    }

    /** What the gateway is built with — the request's bearer — on a house with a declared endpoint. */
    public function testTheRequestCarriesTheDeclaredKeyThenTheEnvironmentsThenNone(): void
    {
        AgentEndpoint::useProviderFetcher(static fn (string $url): ?string => null);
        putenv('MILPA_AGENT_BASE_URL=http://lab.invalid:11434');
        putenv('MILPA_AGENT_MODEL=lab-model');
        putenv('OPENAI_API_KEY=sk-a-provider-key-that-stays-home');
        $container = new DIContainer();
        $container->registerService(Kernel::class, Kernel::boot(['root' => $this->root, 'container' => $container, 'toolRegistry' => new ToolRegistry(new NullLogger()), 'plugins' => []]));
        $credential = new \ReflectionMethod(AgentOperations::class, 'credential');
        $house = new AgentOperations($container);

        self::assertSame(['openai', 'local', 'lab-model'], $credential->invoke($house), 'no key of its own: the placeholder, never a provider\'s key');

        putenv('MILPA_AGENT_API_KEY=' . self::EXPORTED);
        self::assertSame(self::EXPORTED, $credential->invoke($house)[1]);

        $this->declareInTheOverlay(self::DECLARED);
        self::assertSame(self::DECLARED, $credential->invoke($house)[1], 'declared wins, with the variable still set');
    }

    /** @param list<string> $secrets */
    #[\PHPUnit\Framework\Attributes\DataProvider('credentialsOfTheEnvironment')]
    public function testACredentialTheHouseReadsFromTheEnvironmentIsRedacted(string $variable, string $value, array $secrets): void
    {
        putenv("{$variable}={$value}");

        foreach ($secrets as $secret) {
            $read = SecretRedaction::inText("before {$secret} after", $this->root);
            self::assertSame('before ' . SecretRedaction::REDACTED . ' after', $read, "{$variable}: the rest of the text is untouched");
        }
        self::assertSame('nothing here', SecretRedaction::inText('nothing here', null), 'and with no root the environment is still known');
        self::assertStringNotContainsString($secrets[0], SecretRedaction::inText("x {$secrets[0]} y", null));
    }

    /** @return iterable<string, array{string, string, list<string>}> */
    public static function credentialsOfTheEnvironment(): iterable
    {
        yield 'the key of a declared endpoint' => ['MILPA_AGENT_API_KEY', self::EXPORTED, [self::EXPORTED]];
        yield 'the key of OpenAI' => ['OPENAI_API_KEY', 'sk-openai-canary-0001', ['sk-openai-canary-0001']];
        yield 'the key of Anthropic' => ['ANTHROPIC_API_KEY', 'sk-ant-canary-0002', ['sk-ant-canary-0002']];
        yield 'basic auth, whole and its password' => ['MILPA_AGENT_BASIC_AUTH', 'resident:canary-password-0003', ['resident:canary-password-0003', 'canary-password-0003']];
    }

    /** `user:password`, or nothing: half a pair is not a header worth sending. */
    public function testBasicAuthIsAPairOrNothing(): void
    {
        self::assertNull(ProviderCredentials::basicAuth());

        putenv('MILPA_AGENT_BASIC_AUTH=resident-with-no-password');
        self::assertNull(ProviderCredentials::basicAuth());

        putenv('MILPA_AGENT_BASIC_AUTH=resident:canary-password-0003');
        self::assertSame('resident:canary-password-0003', ProviderCredentials::basicAuth());
    }

    public function testAValueTooShortToBeASecretMasksNothing(): void
    {
        putenv('MILPA_AGENT_API_KEY=abc');

        self::assertSame('abc and abcd', SecretRedaction::inText('abc and abcd', $this->root));
    }

    /** Two readers of one variable are two answers to «which key does this house send». */
    public function testOnlyOneFileReadsAProviderCredentialFromTheEnvironment(): void
    {
        $readers = [];
        $src = \dirname(__DIR__, 2) . '/src';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $code = (string) file_get_contents((string) $file);
            if (preg_match('/getenv\(\s*[\'"](' . implode('|', self::VARIABLES) . ')[\'"]/', $code) === 1) {
                $readers[] = substr((string) $file, \strlen($src) + 1);
            }
        }

        self::assertSame([], $readers, 'they are named once, in ProviderCredentials, and read through it');
        self::assertSame(self::VARIABLES, ProviderCredentials::VARIABLES);
    }

    /** The careless test of the measurement: code inside the house prints what it can reach. */
    public function testAToolResultThatCarriesTheKeyReachesTheModelWithoutIt(): void
    {
        $this->declareInTheOverlay(self::DECLARED);
        putenv('MILPA_AGENT_API_KEY=' . self::EXPORTED);
        $printed = ['ok' => false, 'failures' => ['env=' . self::EXPORTED . ' overlay={"agent":{"apiKey":"' . self::DECLARED . '"}}'], 'tests' => 1];

        $read = (new ConsentBridge($this->registry($printed), root: $this->root))->callTool('test', []);

        self::assertIsArray($read);
        self::assertSame('env=' . SecretRedaction::REDACTED . ' overlay={"agent":{"apiKey":"' . SecretRedaction::REDACTED . '"}}', $read['failures'][0]);
        self::assertSame(1, $read['tests'], 'the rest of the result is the result');

        $noRoot = (new ConsentBridge($this->registry('env=' . self::EXPORTED)))->callTool('test', []);
        self::assertSame('env=' . SecretRedaction::REDACTED, $noRoot, 'a bridge that does not know the house root still knows the environment');
    }

    public function testAFailureWhoseTextCarriesTheKeyIsToldWithoutIt(): void
    {
        putenv('MILPA_AGENT_API_KEY=' . self::EXPORTED);
        $registry = new ToolRegistry(new NullLogger());
        $registry->register('test', 'Run', ['type' => 'object'], static function (): never {
            throw new \RuntimeException('the suite died printing ' . self::EXPORTED);
        });

        try {
            (new ConsentBridge($registry, root: $this->root))->callTool('test', []);
            self::fail('the failure travels');
        } catch (\Throwable $told) {
            self::assertStringNotContainsString(self::EXPORTED, $told->getMessage());
            self::assertStringContainsString('the suite died printing ' . SecretRedaction::REDACTED, $told->getMessage());
        }
    }

    public function testAFailureThatCarriesNoSecretTravelsUntouched(): void
    {
        $failure = new \DomainException('no such tool here');
        $registry = new ToolRegistry(new NullLogger());
        $registry->register('test', 'Run', ['type' => 'object'], static function () use ($failure): never {
            throw $failure;
        });

        try {
            (new ConsentBridge($registry, root: $this->root))->callTool('test', []);
            self::fail('the failure travels');
        } catch (\Throwable $told) {
            self::assertStringContainsString('no such tool here', $told->getMessage());
            self::assertStringNotContainsString(SecretRedaction::REDACTED, $told->getMessage());
        }
    }

    /** The ledger is what a later leg, a surface and `agent:result` read back: it keeps what the tool said, minus the house's secrets. */
    public function testTheLedgerKeepsWhatTheToolAnsweredWithoutTheKey(): void
    {
        $this->declareInTheOverlay(self::DECLARED);
        putenv('MILPA_AGENT_API_KEY=' . self::EXPORTED);
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s', 'read the house', AutonomyMode::Auto);
        $session = $store->load('s');
        self::assertNotNull($session);

        (new SessionToolGate($store, $session, [], houseRoot: $this->root))->recorded(
            'source_read',
            ['path' => '.milpa/secrets.json', 'note' => 'looking for ' . self::DECLARED],
            '{"ok":true,"content":"{\"agent\":{\"apiKey\":\"' . self::DECLARED . '\"}} and env ' . self::EXPORTED . '"}',
            true,
        );

        $recorded = (string) json_encode(array_map(static fn ($event): array => $event->payload, $store->stream('s')), \JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString(self::DECLARED, $recorded);
        self::assertStringNotContainsString(self::EXPORTED, $recorded);
        self::assertStringContainsString('source_read', $recorded, 'the call is on record');
        self::assertStringContainsString('.milpa/secrets.json', $recorded, 'with what it asked for');
    }

    public function testAGateThatDoesNotKnowTheHouseRootStillKeepsTheEnvironmentOut(): void
    {
        putenv('MILPA_AGENT_API_KEY=' . self::EXPORTED);
        $store = new SessionStore(new InMemoryEventStore());
        $store->start('s', 'read the house', AutonomyMode::Auto);
        $session = $store->load('s');
        self::assertNotNull($session);

        (new SessionToolGate($store, $session, []))->recorded('test', [], 'env=' . self::EXPORTED, false);

        self::assertStringNotContainsString(self::EXPORTED, (string) json_encode(array_map(static fn ($event): array => $event->payload, $store->stream('s'))));
    }

    /** The value never rides a command line: not in the shell's history, not in what a signature covers. */
    public function testProviderDeclareTakesTheValueFromAFile(): void
    {
        file_put_contents($this->keys . '/endpoint.key', self::DECLARED . "\nand a second line that is not the key\n");

        $out = $this->declare(['key' => 'agent.apiKey', 'file' => $this->keys . '/endpoint.key']);

        self::assertTrue($out['ok'], (string) ($out['error'] ?? ''));
        self::assertStringNotContainsString(self::DECLARED, (string) json_encode($out), 'and nothing it answers carries it');
        self::assertSame([self::DECLARED, 'declared'], ProviderCredentials::endpointKey($this->root), 'the first line, without its newline');
    }

    /** @param array<string, mixed> $input */
    #[\PHPUnit\Framework\Attributes\DataProvider('filesThatDeclareNothing')]
    public function testAFileThatCannotGiveOneValueWritesNothing(array $input, ?string $content, string $why): void
    {
        if ($content !== null) {
            file_put_contents($this->keys . '/endpoint.key', $content);
        }
        $input = array_map(fn (mixed $v): mixed => \is_string($v) ? str_replace('{keys}', $this->keys, $v) : $v, $input);

        $out = $this->declare(['key' => 'agent.apiKey'] + $input);

        self::assertFalse($out['ok']);
        self::assertStringContainsString($why, (string) $out['error']);
        self::assertFileDoesNotExist($this->root . SecretOverlay::RUTA);
    }

    /** @return iterable<string, array{array<string, mixed>, ?string, string}> */
    public static function filesThatDeclareNothing(): iterable
    {
        yield 'a value and a file' => [['value' => 'sk-x', 'file' => '{keys}/endpoint.key'], 'sk-y', 'one of `value` or `file`, not both'];
        yield 'no such file' => [['file' => '{keys}/endpoint.key'], null, 'is not a file this house can read'];
        yield 'an empty first line' => [['file' => '{keys}/endpoint.key'], "\nsk-on-the-second-line\n", 'holds no value on its first line'];
        yield 'a relative path' => [['file' => 'endpoint.key'], 'sk-y', 'must be an absolute path'];
    }

    /** Inside the app a repository would commit it, and every trial would copy it. */
    public function testAKeyFileInsideTheAppIsRefused(): void
    {
        file_put_contents($this->root . '/endpoint.key', self::DECLARED);

        $out = $this->declare(['key' => 'agent.apiKey', 'file' => $this->root . '/endpoint.key']);

        self::assertFalse($out['ok']);
        self::assertStringContainsString('that file is inside this app', (string) $out['error']);
        self::assertFileDoesNotExist($this->root . SecretOverlay::RUTA);
    }

    public function testALinkIsNotAKeyFile(): void
    {
        file_put_contents($this->keys . '/endpoint.key', self::DECLARED);
        symlink($this->keys . '/endpoint.key', $this->keys . '/link.key');

        $out = $this->declare(['key' => 'agent.apiKey', 'file' => $this->keys . '/link.key']);

        self::assertFalse($out['ok']);
        self::assertStringContainsString('is not a file this house can read', (string) $out['error']);
    }

    private function declareInTheOverlay(string $key): void
    {
        file_put_contents($this->root . SecretOverlay::RUTA, (string) json_encode(['agent' => ['apiKey' => $key], 'live' => ['secret' => 'c0ffee-not-the-key']]));
    }

    private function registry(mixed $result): ToolRegistry
    {
        $registry = new ToolRegistry(new NullLogger());
        $registry->register('test', 'Run the suite', ['type' => 'object'], static fn (): mixed => $result);

        return $registry;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function declare(array $input): array
    {
        $method = new \ReflectionMethod(ConfigOperations::class, 'declareSecret');

        return $method->invoke(ConfigOperations::para($this->root), $input);
    }
}
