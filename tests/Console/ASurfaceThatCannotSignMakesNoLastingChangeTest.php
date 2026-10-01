<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Console;

use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Auth\ApiToken;
use Milpa\AppRuntime\Auth\TokenVerifier;
use Milpa\AppRuntime\Console\Application;
use Milpa\AppRuntime\Console\UnsignedTerminal;
use Milpa\AppRuntime\Tests\Fixtures\LabSigner;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Console\OperationRunner;
use Milpa\Console\Tui\OperationsScreen;
use Milpa\Container\DIContainer;
use Milpa\Data\InMemoryRepository;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * `coa chat`, `coa shell` and `coa mcp` sign for nobody either (greenhouse decisions/0526).
 *
 * 0522 took the terminal's wildcard away from its own door; these three surfaces never went through that door and kept
 * running every call as `local-shell` (or `stdio`) with `*`. Measured on the published train (evidence/1060): an
 * unsigned `agent_role_declare` over `coa mcp` wrote its file, `plugins_disable` switched a plugin off, `config_set`
 * ran on a confirm token the same client echoed, and `coa chat` wrote a role on the terminal's own «yes».
 *
 * @guards an unsigned call that declares a lasting change is refused on every surface that cannot sign, before it runs,
 *         with the terminal line that signs it; what changes nothing that lasts runs as it always did; a call that
 *         continues a sequence whose receipt stands is cited through the terminal's runner and runs as its signer
 *
 * @refuses a consent answered by anyone who can echo a token, and a receipt that no longer verifies
 *
 * @subject-in milpa/app-runtime
 */
final class ASurfaceThatCannotSignMakesNoLastingChangeTest extends TestCase
{
    private const string TOKEN = 'surface-1060-token';

    private string $root;

    private string|false $previousToken;

    /** @var list<array<string, mixed>> what each fixture handler was called with */
    private array $ran = [];

    protected function setUp(): void
    {
        $this->previousToken = getenv('MILPA_TOKEN');
        putenv('MILPA_TOKEN');
        $this->root = sys_get_temp_dir() . '/milpa-surface-1060-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/config', 0o775, true);
        file_put_contents($this->root . '/config/app.php', '<?php return [];');
        file_put_contents($this->root . '/config/boot.php', '<?php return ["container" => \\' . self::class . '::container(), "plugins" => []];');
    }

    protected function tearDown(): void
    {
        $this->previousToken === false ? putenv('MILPA_TOKEN') : putenv('MILPA_TOKEN=' . $this->previousToken);
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** The container a fixture house boots with: sessions in memory, and one token the house minted. */
    public static function container(): DIContainer
    {
        $container = new DIContainer();
        $container->registerService(EventStoreInterface::class, new InMemoryEventStore());
        $repository = new InMemoryRepository(ApiToken::class);
        $repository->save(new ApiToken(hash: TokenVerifier::hash(self::TOKEN), actor: 'deploy-bot', scopes: ['roles:write'], createdAt: '2026-09-29T00:00:00+00:00'));
        $container->registerService(TokenVerifier::class . '.repository', $repository);

        return $container;
    }

    // ── The judge ────────────────────────────────────────────────────────────────────────────────────────────────────

    public function testOverASurfaceThatCannotSignALastingChangeIsRefusedWithTheLineThatSignsIt(): void
    {
        $lines = UnsignedTerminal::refusalOver('php bin/coa mcp', $this->write(), ['name' => 'r1'], null);

        self::assertSame([
            'This call is not signed, and an unsigned call changes nothing that lasts: «agent:role:declare» declares a persistent change (write_as_user).',
            '  Over php bin/coa mcp it does not run as the terminal either. Nothing ran.',
            "  Run it signed from the terminal: php bin/coa agent:role:declare --name='r1' --sign",
            '  Or present a token the house minted with token:new (MILPA_TOKEN, an opaque secret, not a JWT): its scopes are what the call runs with.',
        ], $lines);
        self::assertNull(UnsignedTerminal::refusalOver('php bin/coa mcp', $this->read(), [], null), 'a read runs');
    }

    /** At the terminal the runner asks for --sign; over MCP a confirm token anyone echoes stood in for it (1060). */
    public function testOverASurfaceThatCannotSignConsentIsRefusedToo(): void
    {
        $privileged = $this->operation('plugins.register', new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::Compensatable, Authority::Privileged, subject: Subject::Executable));

        self::assertNull(UnsignedTerminal::refusal($privileged, [], null), 'the terminal leaves it to the runner');
        self::assertSame([
            '«plugins.register» demands consent, and consent is a signature over THIS call — php bin/coa shell cannot carry one. Nothing ran.',
            "  Run it signed from the terminal: php bin/coa plugins:register --class='App\\Plugins\\Blog' --sign",
        ], UnsignedTerminal::refusalOver('php bin/coa shell', $privileged, ['class' => 'App\\Plugins\\Blog'], null));
    }

    public function testTheSignedLineIsTypedNotDescribed(): void
    {
        self::assertSame(
            "php bin/coa agent:role:declare --name='a b' --dry-run --skills='[\"x\"]' --steps='3' --sign",
            UnsignedTerminal::signedLine($this->write(), ['name' => 'a b', 'dry_run' => true, 'hidden' => false, 'none' => null, 'skills' => ['x'], 'steps' => 3]),
        );
    }

    public function testACopyWithAnotherHandlerKeepsEverythingTheOperationDeclares(): void
    {
        $original = new Operation(
            name: 'agent',
            description: 'd',
            handler: static fn (): array => ['original'],
            inputSchema: ['type' => 'object'],
            mutating: true,
            scopes: ['agent:run'],
            surfaces: ['cli', 'mcp'],
            effects: new EffectProfile(Mutation::Persistent, Externality::ThirdParty, Reversibility::Irreversible, Authority::WriteAsUser, subject: Subject::Data),
            continues: static fn (array $a): ?string => \is_string($a['session'] ?? null) ? $a['session'] : null,
        );
        $copy = UnsignedTerminal::withHandler($original, static fn (): array => ['door']);

        self::assertSame(['door'], ($copy->handler)());
        self::assertSame(['original'], ($original->handler)());
        foreach (['name', 'description', 'inputSchema', 'mutating', 'scopes', 'surfaces', 'effects'] as $property) {
            self::assertSame($original->{$property}, $copy->{$property}, $property);
        }
        self::assertSame('s1', $copy->sequenceFor(['session' => 's1']));
    }

    // ── coa chat ─────────────────────────────────────────────────────────────────────────────────────────────────────

    public function testTheChatRefusesAnUnsignedLastingChangeAndRunsNothing(): void
    {
        $app = $this->application();

        $refused = $this->privately($app, 'correr', 'agent:role:declare', ['name' => 'r1', 'prompt' => 'p']);

        self::assertIsArray($refused);
        self::assertFalse($refused['ok']);
        self::assertSame('unsigned', $refused['refused']);
        self::assertStringContainsString('Over php bin/coa chat it does not run as the terminal either. Nothing ran.', (string) $refused['error']);
        self::assertSame("php bin/coa agent:role:declare --name='r1' --prompt='p' --sign", $refused['sign']);
        self::assertSame([], $this->ran, 'the handler never ran');

        self::assertSame(['ok' => true, 'roles' => []], $this->privately($app, 'correr', 'agent:role:list', []), 'a read runs');
        self::assertSame([['op' => 'agent:role:list']], $this->ran);
    }

    public function testAPresentedTokenRunsTheCallAsItselfButNeverAnswersForAConsent(): void
    {
        putenv('MILPA_TOKEN=' . self::TOKEN);
        $app = $this->application();

        self::assertSame(['ok' => true, 'wrote' => 'r1'], $this->privately($app, 'correr', 'agent:role:declare', ['name' => 'r1']));
        $consent = $this->privately($app, 'correr', 'plugins.register', ['class' => 'X']);
        self::assertIsArray($consent);
        self::assertSame('consent', $consent['refused'], 'a token does not name THIS call');
        self::assertSame([['op' => 'agent:role:declare']], $this->ran);
    }

    public function testTheChatCitesAStandingReceiptThroughTheTerminalsRunner(): void
    {
        $key = new LabSigner();
        $app = $this->application($key);
        $store = $this->privately($app, 'almacenDeSesiones');
        self::assertInstanceOf(SessionStore::class, $store);
        $store->start('s1', 'build the blog');
        [$payload, $signature] = (array) $key->sign('agent', ['prompt' => 'open', 'session' => 's1'], gethostname() ?: 'unknown-host', time());
        $store->authorizeSequence('s1', 'agent', ['payload' => $payload, 'signature' => $signature, 'fingerprint' => $key->fingerprint, 'uid' => null]);

        $cited = $this->privately($app, 'correr', 'agent', ['prompt' => 'next', 'session' => 's1']);

        self::assertSame(['ok' => true, 'leg' => 'next'], $cited);
        self::assertSame([['op' => 'agent', 'actor' => 'key:' . $key->fingerprint, 'verified' => true]], $this->ran);
        $types = array_map(static fn ($e): string => $e->type, [...$this->events($app, 's1')]);
        self::assertContains('session.authorization_cited', $types);
    }

    public function testAReceiptThatNoLongerVerifiesIsRefusedAndNothingRuns(): void
    {
        $key = new LabSigner();
        $app = $this->application($key);
        $store = $this->privately($app, 'almacenDeSesiones');
        self::assertInstanceOf(SessionStore::class, $store);
        $store->start('s1', 'build the blog');
        [$payload] = (array) $key->sign('agent', ['prompt' => 'open', 'session' => 's1'], gethostname() ?: 'unknown-host', time());
        $store->authorizeSequence('s1', 'agent', ['payload' => $payload, 'signature' => 'forged', 'fingerprint' => $key->fingerprint, 'uid' => null]);

        $refused = $this->privately($app, 'correr', 'agent', ['prompt' => 'next', 'session' => 's1']);

        self::assertIsArray($refused);
        self::assertFalse($refused['ok']);
        self::assertStringContainsString('its receipt no longer holds', (string) $refused['error']);
        self::assertSame([], $this->ran);
    }

    /**
     * A signed chat in ask mode answers its own question (greenhouse decisions/0526 §2): the answer cites the `agent`
     * receipt of the chat's session through the terminal's runner and runs as that signer. The operation is the house's
     * own `agent:answer` declaration, with a handler that only records.
     */
    public function testTheChatAnswersUnderTheAgentReceiptOfItsOwnSession(): void
    {
        $key = new LabSigner();
        $app = $this->application($key, [$this->agent(), $this->answer()]);
        $store = $this->privately($app, 'almacenDeSesiones');
        self::assertInstanceOf(SessionStore::class, $store);
        $store->start('s1', 'build the blog');
        [$payload, $signature] = (array) $key->sign('agent', ['prompt' => 'open', 'session' => 's1'], gethostname() ?: 'unknown-host', time());
        $store->authorizeSequence('s1', 'agent', ['payload' => $payload, 'signature' => $signature, 'fingerprint' => $key->fingerprint, 'uid' => null]);

        $answered = $this->privately($app, 'correr', 'agent:answer', ['session' => 's1', 'answer' => 'yes']);

        self::assertSame(['ok' => true, 'answered' => 'yes'], $answered);
        self::assertSame([['op' => 'agent:answer', 'actor' => 'key:' . $key->fingerprint, 'verified' => true]], $this->ran);
        $types = array_map(static fn ($e): string => $e->type, [...$this->events($app, 's1')]);
        self::assertContains('session.authorization_cited', $types);
        self::assertNotContains('session.authorization_released', $types, 'an answer never ends the sequence');
    }

    /** Only in that session: answering a session that holds no receipt (a child, another chat) is refused unsigned. */
    public function testTheChatsReceiptDoesNotAnswerAnotherSession(): void
    {
        $key = new LabSigner();
        $app = $this->application($key, [$this->agent(), $this->answer()]);
        $store = $this->privately($app, 'almacenDeSesiones');
        self::assertInstanceOf(SessionStore::class, $store);
        $store->start('s1', 'build the blog');
        $store->start('s1-child', 'a child');
        [$payload, $signature] = (array) $key->sign('agent', ['prompt' => 'open', 'session' => 's1'], gethostname() ?: 'unknown-host', time());
        $store->authorizeSequence('s1', 'agent', ['payload' => $payload, 'signature' => $signature, 'fingerprint' => $key->fingerprint, 'uid' => null]);

        $refused = $this->privately($app, 'correr', 'agent:answer', ['session' => 's1-child', 'answer' => 'yes']);

        self::assertIsArray($refused);
        self::assertFalse($refused['ok']);
        self::assertSame('unsigned', $refused['refused']);
        self::assertSame([], $this->ran);
    }

    // ── coa shell ────────────────────────────────────────────────────────────────────────────────────────────────────

    public function testTheShellsFormRefusesAnUnsignedLastingChangeAndRunsAReadAsBefore(): void
    {
        $app = $this->application();
        // The screen `coa shell` opens, and the operations it offers: what its form runs.
        $screen = $this->privately($app, 'pantallaDelShell');
        self::assertInstanceOf(OperationsScreen::class, $screen);
        $shell = $screen->operations();
        $byName = array_column(array_map(static fn (Operation $o): array => [$o->name, $o], $shell), 1, 0);

        // The form runs a call through the console's own runner, as `coa shell` does (OperationScreen::correr).
        $runner = new OperationRunner(new DIContainer());
        $refused = $runner->run($byName['agent:role:declare'], ['name' => 'r1'], 'tui');
        self::assertIsArray($refused);
        self::assertSame('unsigned', $refused['refused']);
        self::assertStringContainsString('Over php bin/coa shell it does not run as the terminal either.', (string) $refused['error']);
        self::assertSame([], $this->ran);

        self::assertSame(['ok' => true, 'roles' => []], $runner->run($byName['agent:role:list'], [], 'tui'));
        $offered = array_map(static fn (Operation $o): string => $o->name, $shell);
        sort($offered);
        self::assertSame(['agent', 'agent:role:declare', 'agent:role:list', 'plugins.register'], $offered, 'every operation is still offered');
    }

    public function testTheShellResolvesAServiceHandlerBehindTheDoorToo(): void
    {
        $service = new class () {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            /** @param array<string, mixed> $input @return array<string, mixed> */
            public function write(array $input): array
            {
                $this->calls[] = $input;

                return ['ok' => true];
            }
        };
        $app = $this->application(operations: [
            $this->operation('store.write', $this->persistent(), handler: [$service::class, 'write']),
            $this->operation('store.read', EffectProfile::readOnly(), handler: [$service::class, 'write']),
        ]);
        $container = $this->privately($app, 'kernel')->container();
        self::assertInstanceOf(DIContainer::class, $container);
        $container->registerService($service::class, $service);
        /** @var list<Operation> $shell */
        $shell = $this->privately($app, 'operacionesDelShell');

        self::assertSame('unsigned', ($shell[0]->handler)(['k' => 1])['refused']);
        self::assertSame(['ok' => true], ($shell[1]->handler)(['k' => 2]));
        self::assertSame([['k' => 2]], $service->calls);
    }

    // ── coa mcp ──────────────────────────────────────────────────────────────────────────────────────────────────────

    public function testMcpAnswersARefusalAsAToolErrorTheClientCanRead(): void
    {
        $app = $this->application();
        $tools = $this->tools($app);

        $answer = $this->privately($app, 'mcpPorLaPuerta', ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'agent_role_declare', 'arguments' => ['name' => 'r1']]], $tools);

        self::assertIsArray($answer);
        self::assertSame(7, $answer['id']);
        self::assertTrue($answer['result']['isError']);
        $text = json_decode($answer['result']['content'][0]['text'], true);
        self::assertFalse($text['success']);
        self::assertSame('UNSIGNED_LASTING_CHANGE', $text['meta']['code']);
        self::assertSame("php bin/coa agent:role:declare --name='r1' --sign", $text['meta']['sign']);
        self::assertStringContainsString('Over php bin/coa mcp it does not run as the terminal either.', $text['error']);
        self::assertSame([], $this->ran);
    }

    /**
     * Over MCP a call cites only a receipt its own operation signed (greenhouse decisions/0546). Answering the question
     * a signed session asked is a person's act: the chat, the shell and the terminal have one at the keys; an MCP
     * client is whatever process holds the pipe — a model included. So an `agent` receipt does not answer over MCP.
     */
    public function testOverMcpAnAnswerDoesNotCiteTheAgentReceiptOfTheSession(): void
    {
        $key = new LabSigner();
        $app = $this->application($key, [$this->agent(), $this->answer()]);
        $store = $this->privately($app, 'almacenDeSesiones');
        self::assertInstanceOf(SessionStore::class, $store);
        $store->start('s1', 'build the blog');
        [$payload, $signature] = (array) $key->sign('agent', ['prompt' => 'open', 'session' => 's1'], gethostname() ?: 'unknown-host', time());
        $store->authorizeSequence('s1', 'agent', ['payload' => $payload, 'signature' => $signature, 'fingerprint' => $key->fingerprint, 'uid' => null]);

        $answer = $this->privately($app, 'mcpPorLaPuerta', ['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call', 'params' => ['name' => 'agent_answer', 'arguments' => ['session' => 's1', 'answer' => 'yes']]], $this->tools($app));

        self::assertIsArray($answer);
        self::assertTrue($answer['result']['isError']);
        $text = json_decode($answer['result']['content'][0]['text'], true);
        self::assertSame('UNSIGNED_LASTING_CHANGE', $text['meta']['code']);
        self::assertStringContainsString('The receipt standing for «s1» signed «agent»', $text['error']);
        self::assertSame([], $this->ran, 'nothing answered');
        $types = array_map(static fn ($e): string => $e->type, [...$this->events($app, 's1')]);
        self::assertNotContains('session.authorization_cited', $types);
    }

    /** Nor does a read that names the receipt: over MCP it goes to the server as any read does, never as the signer. */
    public function testOverMcpAReadThatNamesAnotherReceiptIsNeverCitedUnderIt(): void
    {
        $key = new LabSigner();
        $peek = new Operation(
            name: 'agent:peek',
            description: 'd',
            handler: static fn (): array => ['ok' => true],
            inputSchema: ['type' => 'object', 'properties' => ['session' => ['type' => 'string']]],
            effects: EffectProfile::readOnly(),
            continues: static fn (array $a): ?string => \is_string($a['session'] ?? null) ? $a['session'] : null,
            citesReceiptsOf: ['agent'],
        );
        $app = $this->application($key, [$this->agent(), $peek]);
        $store = $this->privately($app, 'almacenDeSesiones');
        self::assertInstanceOf(SessionStore::class, $store);
        $store->start('s1', 'build the blog');
        [$payload, $signature] = (array) $key->sign('agent', ['prompt' => 'open', 'session' => 's1'], gethostname() ?: 'unknown-host', time());
        $store->authorizeSequence('s1', 'agent', ['payload' => $payload, 'signature' => $signature, 'fingerprint' => $key->fingerprint, 'uid' => null]);

        $passed = $this->privately($app, 'mcpPorLaPuerta', ['jsonrpc' => '2.0', 'id' => 10, 'method' => 'tools/call', 'params' => ['name' => 'agent_peek', 'arguments' => ['session' => 's1']]], $this->tools($app));

        self::assertNull($passed, 'the door stands aside and the server answers the read');
        $types = array_map(static fn ($e): string => $e->type, [...$this->events($app, 's1')]);
        self::assertNotContains('session.authorization_cited', $types);
    }

    public function testMcpRefusesAConsentEvenWithTheConfirmTokenItHandedOut(): void
    {
        $app = $this->application();

        $answer = $this->privately($app, 'mcpPorLaPuerta', ['id' => 8, 'params' => ['name' => 'plugins_register', 'arguments' => ['class' => 'X', 'confirm_token' => 'echoed']]], $this->tools($app));

        self::assertIsArray($answer);
        $text = json_decode($answer['result']['content'][0]['text'], true);
        self::assertSame('CONSENT_NEEDS_SIGNATURE', $text['meta']['code']);
        self::assertSame("php bin/coa plugins:register --class='X' --sign", $text['meta']['sign'], 'the confirm token is the transport\'s, never the call\'s');
    }

    public function testMcpLetsAReadAndAnUndeclaredToolPassToTheServer(): void
    {
        $app = $this->application();

        self::assertNull($this->privately($app, 'mcpPorLaPuerta', ['id' => 9, 'params' => ['name' => 'agent_role_list', 'arguments' => []]], $this->tools($app)));
        self::assertNull($this->privately($app, 'mcpPorLaPuerta', ['id' => 10, 'params' => ['name' => 'not_an_operation']], $this->tools($app)));
        self::assertSame([], $this->ran, 'the door runs nothing it lets through — the server does');
    }

    public function testMcpAnswersACitedCallWithItsResult(): void
    {
        $key = new LabSigner();
        $app = $this->application($key);
        $store = $this->privately($app, 'almacenDeSesiones');
        self::assertInstanceOf(SessionStore::class, $store);
        $store->start('s1', 'build the blog');
        [$payload, $signature] = (array) $key->sign('agent', ['prompt' => 'open', 'session' => 's1'], gethostname() ?: 'unknown-host', time());
        $store->authorizeSequence('s1', 'agent', ['payload' => $payload, 'signature' => $signature, 'fingerprint' => $key->fingerprint, 'uid' => null]);

        $answer = $this->privately($app, 'mcpPorLaPuerta', ['id' => 11, 'params' => ['name' => 'agent', 'arguments' => ['prompt' => 'next', 'session' => 's1']]], $this->tools($app));

        self::assertIsArray($answer);
        self::assertFalse($answer['result']['isError']);
        self::assertSame(['ok' => true, 'leg' => 'next'], json_decode($answer['result']['content'][0]['text'], true)['data']);
    }

    /**
     * The real pipe: `coa mcp` spoken to line by line by a client, on a house that boots (a child process — what the
     * in-process tests above cannot reach is the wiring of the child's own `tools/call`).
     */
    public function testOverTheRealStdioPipeAnUnsignedWriteIsAToolErrorAndWritesNothing(): void
    {
        $house = \Milpa\AppRuntime\Tests\Fixtures\TinyHouse::create('HelloPlugin');
        try {
            file_put_contents($house . '/config/operations.php', '<?php return [\\' . \Milpa\AppRuntime\Operations\AgentOperations::class . "::class];\n");
            @mkdir($house . '/bin', 0o775, true);
            file_put_contents($house . '/bin/coa', '<?php require ' . var_export($house . '/vendor/autoload.php', true) . '; exit((new Milpa\AppRuntime\Console\Application(' . var_export($house, true) . '))->run($argv));');
            $client = proc_open(
                'MILPA_TOKEN= ' . escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg($house . '/bin/coa') . ' mcp',
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $house,
            );
            self::assertIsResource($client);
            $ask = static function (array $message) use ($pipes): array {
                fwrite($pipes[0], json_encode($message) . "\n");
                fflush($pipes[0]);
                $deadline = microtime(true) + 60;
                while (microtime(true) < $deadline && ($line = fgets($pipes[1])) !== false) {
                    $answer = json_decode($line, true);
                    if (\is_array($answer) && ($answer['id'] ?? null) === $message['id']) {
                        return $answer;
                    }
                }

                return ['timeout' => true];
            };
            $ask(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 't', 'version' => '0']]]);
            $write = $ask(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'agent_role_declare', 'arguments' => ['name' => 'r1', 'prompt' => 'a role', 'skills' => [], 'deny' => []]]]);
            $read = $ask(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'agent_role_list', 'arguments' => new \stdClass()]]);
            fclose($pipes[0]);
            proc_close($client);

            self::assertTrue($write['result']['isError'] ?? null, (string) json_encode($write));
            self::assertSame('UNSIGNED_LASTING_CHANGE', json_decode($write['result']['content'][0]['text'], true)['meta']['code']);
            self::assertFileDoesNotExist($house . '/.milpa/agents/r1.md');
            self::assertTrue(json_decode($read['result']['content'][0]['text'] ?? 'null', true)['success'] ?? false, (string) json_encode($read));
        } finally {
            \Milpa\AppRuntime\Tests\Fixtures\TinyHouse::remove($house);
        }
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @param list<Operation>|null $operations */
    private function application(?LabSigner $key = null, ?array $operations = null): Application
    {
        $app = new Application($this->root, $key, $key);
        (new \ReflectionProperty($app, 'operations'))->setValue($app, $operations ?? [
            $this->write(),
            $this->read(),
            $this->operation('plugins.register', new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::Compensatable, Authority::Privileged, subject: Subject::Executable)),
            $this->agent(),
        ]);

        return $app;
    }

    private function write(): Operation
    {
        return $this->operation('agent:role:declare', $this->persistent(), function (array $input): array {
            $this->ran[] = ['op' => 'agent:role:declare'];

            return ['ok' => true, 'wrote' => $input['name'] ?? null];
        });
    }

    private function read(): Operation
    {
        return $this->operation('agent:role:list', EffectProfile::readOnly(), function (): array {
            $this->ran[] = ['op' => 'agent:role:list'];

            return ['ok' => true, 'roles' => []];
        });
    }

    /** The house's own `agent:answer` declaration, its handler replaced by one that records who ran it. */
    private function answer(): Operation
    {
        foreach ((new \Milpa\AppRuntime\Operations\SessionOperations(new DIContainer()))->operations() as $op) {
            if ($op->name === 'agent:answer') {
                return UnsignedTerminal::withHandler($op, function (array $input, ?\Milpa\Command\InvocationContext $context = null): array {
                    $this->ran[] = ['op' => 'agent:answer', 'actor' => $context?->actor, 'verified' => $context?->verified];

                    return ['ok' => true, 'answered' => $input['answer'] ?? null];
                });
            }
        }
        self::fail('the house declares no agent:answer');
    }

    /** An `agent` whose sequence is its session — what the chat's turn and an MCP leg continue. */
    private function agent(): Operation
    {
        return new Operation(
            name: 'agent',
            description: 'd',
            handler: function (array $input, ?\Milpa\Command\InvocationContext $context = null): array {
                $this->ran[] = ['op' => 'agent', 'actor' => $context?->actor, 'verified' => $context?->verified];

                return ['ok' => true, 'leg' => $input['prompt'] ?? null];
            },
            inputSchema: ['type' => 'object', 'properties' => ['prompt' => ['type' => 'string'], 'session' => ['type' => 'string']]],
            mutating: true,
            effects: new EffectProfile(Mutation::Persistent, Externality::ThirdParty, Reversibility::Irreversible, Authority::WriteAsUser, subject: Subject::Data),
            continues: static fn (array $a): ?string => \is_string($a['session'] ?? null) ? $a['session'] : null,
        );
    }

    private function persistent(): EffectProfile
    {
        return new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::Compensatable, Authority::WriteAsUser, subject: Subject::None);
    }

    private function operation(string $name, EffectProfile $effects, mixed $handler = null): Operation
    {
        return new Operation(
            name: $name,
            description: 'd',
            handler: $handler ?? static fn (): array => ['ok' => true],
            inputSchema: ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'prompt' => ['type' => 'string'], 'class' => ['type' => 'string']]],
            mutating: $effects->mutation !== Mutation::None,
            effects: $effects,
        );
    }

    /** @return array<string, Operation> the tool name each operation goes by over MCP */
    private function tools(Application $app): array
    {
        $tools = [];
        foreach ((array) $this->privately($app, 'all') as $op) {
            $tools[\Milpa\Console\McpProjector::toolName($op->name)] = $op;
        }

        return $tools;
    }

    /** @return iterable<\Milpa\EventStore\Event> */
    private function events(Application $app, string $session): iterable
    {
        $events = $this->privately($app, 'kernel')->container()->get(EventStoreInterface::class);
        self::assertInstanceOf(EventStoreInterface::class, $events);

        return $events->replay('agent-session:' . $session);
    }

    private function privately(Application $app, string $method, mixed ...$arguments): mixed
    {
        return (new \ReflectionMethod($app, $method))->invoke($app, ...$arguments);
    }
}
