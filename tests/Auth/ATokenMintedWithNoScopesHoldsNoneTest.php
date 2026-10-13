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

namespace Milpa\AppRuntime\Tests\Auth;

use Milpa\AppRuntime\Agent\ConsentBridge;
use Milpa\AppRuntime\Agent\PluginAuthoringPolicy;
use Milpa\AppRuntime\Auth\ApiToken;
use Milpa\AppRuntime\Auth\PresentedToken;
use Milpa\AppRuntime\Auth\TokenVerifier;
use Milpa\AppRuntime\Console\Application;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\AppRuntime\Operations\TokenOperations;
use Milpa\AppRuntime\Tests\Fixtures\LabSigner;
use Milpa\AppRuntime\Tests\Fixtures\TinyHouse;
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
use Milpa\Plugin\Contracts\AppRoot;
use Milpa\ValueObjects\Tooling\ToolOptions;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A token minted with no scopes holds none — at every door that takes a token (greenhouse decisions/0616).
 *
 * Measured on the published train (v0.220.2): `token:new --actor=ci --scopes='[]'` mints a token, and the terminal
 * and `coa mcp` read its empty list as «nobody identified» and hand it the surface's default, which is `*`. With that
 * token in `MILPA_TOKEN` an unsigned call that asks for a scope ran, and so did a lasting change — the rule against
 * unsigned lasting changes is not asked of a token's bearer, because a token is how a call is made without a
 * signature. The control, a token holding one unrelated scope, was refused.
 *
 * And two doors never read what a token holds at all: `coa shell` and `coa chat` asked only whether one was presented,
 * so there the control ran too. A rule about what a token holds is not kept by a door that does not look.
 *
 * @guards a caller that presents a token is judged by the scopes that token declares, none included, at the terminal,
 *         over `coa mcp`, in `coa shell`, in `coa chat` and on the agent's leg; a signature does not widen it
 *
 * @refuses the reading «an empty list means nobody identified»: the default belongs to a caller that presented nothing
 *          (greenhouse decisions/0311), and it stays there — `null` in, wildcard kept
 *
 * @subject-in milpa/app-runtime
 */
final class ATokenMintedWithNoScopesHoldsNoneTest extends TestCase
{
    /** Minted with no scopes — the token this test is about. */
    private const string NONE = 'token-0616-minted-with-no-scopes';

    /** The control: minted with one scope nothing here asks for. Refused before and after. */
    private const string OTHER = 'token-0616-minted-with-another-scope';

    /** The positive control: minted with the scopes the fixtures ask for. Runs before and after. */
    private const string HOLDS = 'token-0616-minted-with-the-scopes';

    /** Minted with every scope, asked for by name: the token that may do what the terminal does. */
    private const string EVERY = 'token-0616-minted-with-every-scope';

    private const string UNKNOWN_KEY = 'AAAA1111BBBB2222CCCC3333DDDD4444EEEE5555';

    private string $root;
    private string|false $previousToken;

    /** @var list<string> the fixture operations that ran */
    private array $ran = [];

    /** @var InMemoryRepository<ApiToken>|null the house's token store, one for every boot of a test */
    private static ?InMemoryRepository $store = null;

    protected function setUp(): void
    {
        $this->previousToken = getenv('MILPA_TOKEN');
        putenv('MILPA_TOKEN');
        self::$store = null;
        $this->root = sys_get_temp_dir() . '/milpa-token-0616-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/config', 0o775, true);
        file_put_contents($this->root . '/config/app.php', '<?php return [];');
        file_put_contents($this->root . '/config/boot.php', '<?php return ["container" => \\' . self::class . '::container(' . var_export($this->root, true) . '), "plugins" => []];');
    }

    protected function tearDown(): void
    {
        $this->previousToken === false ? putenv('MILPA_TOKEN') : putenv('MILPA_TOKEN=' . $this->previousToken);
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /**
     * The container the fixture house boots with: the tokens it minted, where it lives, and the house's own call
     * policy — the one every real house installs when it reads its catalogue, which is what refuses a caller without
     * the wildcard a mutation that declares no scope.
     */
    public static function container(string $root): DIContainer
    {
        $container = new DIContainer();
        $container->registerService(EventStoreInterface::class, new InMemoryEventStore());
        $container->registerService(AppRoot::class, new AppRoot($root));
        $container->registerService(TokenVerifier::class . '.repository', self::tokens());
        PluginAuthoringPolicy::install($container, $root);

        return $container;
    }

    /** @return InMemoryRepository<ApiToken> */
    private static function tokens(): InMemoryRepository
    {
        if (self::$store !== null) {
            return self::$store;
        }
        /** @var InMemoryRepository<ApiToken> $repository */
        $repository = new InMemoryRepository(ApiToken::class);
        foreach ([self::NONE => [], self::OTHER => ['other:read'], self::HOLDS => ['shelf:read', 'shelf:write'], self::EVERY => ['*']] as $secret => $scopes) {
            $repository->save(new ApiToken(hash: TokenVerifier::hash($secret), actor: 'ci', scopes: $scopes, createdAt: '2026-10-12T00:00:00+00:00'));
        }

        return self::$store = $repository;
    }

    // ── The rule ─────────────────────────────────────────────────────────────────────────────────────────────────────

    public function testAnIdentityThatDeclaresNoScopesCarriesNoneWhateverTheDefault(): void
    {
        $identity = PresentedToken::identity(self::container($this->root), self::NONE);

        self::assertNotNull($identity, 'the token is real: it verifies');
        self::assertSame([], PresentedToken::scopes($identity, ['*']), 'and the surface\'s wildcard is not its own');
        self::assertTrue(PresentedToken::declaresNoScopes($identity));
    }

    public function testTheDefaultStaysWithACallerThatPresentedNothing(): void
    {
        self::assertSame(['*'], PresentedToken::scopes(null, ['*']), 'null in, wildcard kept (greenhouse decisions/0311)');
        self::assertFalse(PresentedToken::declaresNoScopes(null), 'nobody is not somebody with nothing');
        self::assertFalse(PresentedToken::declaresNoScopes(PresentedToken::identity(self::container($this->root), self::OTHER)));
    }

    // ── The terminal ─────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return iterable<string, array{string}> */
    public static function guarded(): iterable
    {
        yield 'a read that asks for a scope' => ['shelf:read'];
        yield 'a lasting change that asks for a scope, unsigned' => ['shelf:put'];
    }

    #[DataProvider('guarded')]
    public function testAtTheTerminalItRunsNothingAScopeGuards(string $command): void
    {
        putenv('MILPA_TOKEN=' . self::NONE);

        [$exit, $output] = $this->terminal([$command]);

        self::assertSame([], $this->ran, $output);
        self::assertSame(1, $exit);
        self::assertStringContainsString('Missing required scope', $output);
        self::assertStringContainsString('context has: (none)', $output);
        self::assertStringContainsString('This token declares no scopes, so it holds none', $output, 'the refusal names the way out');
        self::assertStringContainsString("php bin/coa token:new --actor='ci' --scopes='[\"<scope>\"]' --sign", $output);
    }

    /** @return iterable<string, array{?string, string, bool, string}> */
    public static function controls(): iterable
    {
        yield 'another scope: refused, as it was' => [self::OTHER, 'shelf:read', false, 'context has: other:read.'];
        yield 'another scope, a lasting change: refused, as it was' => [self::OTHER, 'shelf:put', false, 'context has: other:read.'];
        yield 'the scope: the read runs, as it did' => [self::HOLDS, 'shelf:read', true, 'did: shelf:read'];
        yield 'the scope: the lasting change runs unsigned, as it did' => [self::HOLDS, 'shelf:put', true, 'did: shelf:put'];
        yield 'no token: the read runs as the terminal, as it did (0311)' => [null, 'shelf:read', true, 'did: shelf:read'];
        yield 'a token nobody minted: the read runs as the terminal, as it did (0311)' => ['never-minted', 'shelf:read', true, 'did: shelf:read'];
        yield 'no token: an unsigned lasting change is refused for being unsigned, as it was (0522)' => [null, 'shelf:put', false, 'This call is not signed'];
    }

    #[DataProvider('controls')]
    public function testAtTheTerminalEveryOtherCallerIsAnsweredAsBefore(?string $token, string $command, bool $runs, string $says): void
    {
        $token === null ? putenv('MILPA_TOKEN') : putenv('MILPA_TOKEN=' . $token);

        [$exit, $output] = $this->terminal([$command]);

        self::assertSame($runs ? [$command] : [], $this->ran, $output);
        self::assertSame($runs ? 0 : 1, $exit);
        self::assertStringContainsString($says, $output);
        self::assertStringNotContainsString('declares no scopes', $output, 'the sentence is for the token that declares none');
    }

    /** @return iterable<string, array{?string, bool, string}> */
    public static function mutationThatDeclaresNoScope(): iterable
    {
        yield 'no scopes' => [self::NONE, false, 'declares no authority for a finite principal'];
        yield 'another scope: refused, as it was' => [self::OTHER, false, 'declares no authority for a finite principal'];
        yield 'every scope, by name: runs, as it did' => [self::EVERY, true, 'did: shelf:bare'];
        yield 'no token: refused for being unsigned, as it was (0522)' => [null, false, 'This call is not signed'];
    }

    /**
     * A lasting change that declares no scope is the terminal's own, and whoever holds the wildcard's: the house's
     * call policy refuses it to every other caller. A token with no scopes is one of those callers.
     */
    #[DataProvider('mutationThatDeclaresNoScope')]
    public function testAtTheTerminalItMakesNoLastingChangeThatDeclaresNoScopeEither(?string $token, bool $runs, string $says): void
    {
        $token === null ? putenv('MILPA_TOKEN') : putenv('MILPA_TOKEN=' . $token);

        [$exit, $output] = $this->terminal(['shelf:bare']);

        self::assertSame($runs ? ['shelf:bare'] : [], $this->ran, $output);
        self::assertSame($runs ? 0 : 1, $exit);
        self::assertStringContainsString($says, $output);
    }

    /** @return iterable<string, array{?string, bool, string}> */
    public static function minters(): iterable
    {
        yield 'no scopes' => [self::NONE, false, 'declares no authority for a finite principal'];
        yield 'another scope: refused, as it was' => [self::OTHER, false, 'declares no authority for a finite principal'];
        yield 'every scope, by name: mints, as it did' => [self::EVERY, true, 'ok: yes'];
        yield 'no token: refused for being unsigned, as it was (0522)' => [null, false, 'This call is not signed'];
    }

    /**
     * The step that made the defect durable: with a token that declared nothing, the bearer minted itself one that
     * declares everything — `token:new` asks for no scope and demands no consent, so the terminal's wildcard was all
     * it took, and that token had it.
     */
    #[DataProvider('minters')]
    public function testItCannotMintItselfAToken(?string $token, bool $mints, string $says): void
    {
        $token === null ? putenv('MILPA_TOKEN') : putenv('MILPA_TOKEN=' . $token);
        $app = $this->application();
        $before = \count(self::tokens()->all());

        ob_start();
        try {
            $exit = $app->run(['coa', 'token:new', '--actor=mine', '--scopes=["*"]']);
        } finally {
            $output = (string) ob_get_clean();
        }

        self::assertSame($mints ? 1 : 0, \count(self::tokens()->all()) - $before, $output);
        self::assertSame($mints ? 0 : 1, $exit);
        self::assertStringContainsString($says, $output);
    }

    public function testASignatureDoesNotWidenIt(): void
    {
        // A key this house never recognized signs as the terminal (its wildcard); a presented token narrows that, and
        // one that declares nothing narrows it to nothing.
        $key = new LabSigner(self::UNKNOWN_KEY);

        putenv('MILPA_TOKEN');
        [$exit, $output] = $this->terminal(['shelf:put', '--sign'], $key);
        self::assertSame(['shelf:put'], $this->ran, 'the control: with no token the same signature runs it — ' . $output);
        self::assertSame(0, $exit);

        $this->ran = [];
        putenv('MILPA_TOKEN=' . self::NONE);
        [$exit, $output] = $this->terminal(['shelf:put', '--sign'], $key);
        self::assertSame([], $this->ran, $output);
        self::assertSame(1, $exit);
        self::assertStringContainsString('context has: (none)', $output);
        self::assertStringNotContainsString('authorized by', $output, 'refused before the signature is asked for');
    }

    /**
     * The sibling read beside the defect: a signer the house recognizes WITH AN EMPTY LIST of scopes (`$signed->scopes
     * ?? ['*']` in the terminal's door). `??` answers for a signer nobody recognized, not for an empty list: a
     * recognized key that holds nothing signs for nothing. No door writes such an entry — `identity:enroll` refuses an
     * empty list — so the ledger is written here as a revocation of every scope would leave it.
     */
    public function testAKeyRecognizedWithNoScopesSignsForNothing(): void
    {
        mkdir($this->root . '/storage/identity', 0o775, true);
        (new FileEnrollmentStore($this->root . '/storage/identity/enrollments.json'))->record(new IdentityEnrolled(self::UNKNOWN_KEY, [], 'key:someone'));

        [$exit, $output] = $this->terminal(['shelf:put', '--sign'], new LabSigner(self::UNKNOWN_KEY));

        self::assertSame([], $this->ran, $output);
        self::assertSame(1, $exit);
        self::assertStringContainsString('context has: (none)', $output);
        self::assertStringNotContainsString('This token declares no scopes', $output, 'no token was presented: the sentence about one is not said');
    }

    // ── coa shell, coa chat ──────────────────────────────────────────────────────────────────────────────────────────

    /** @return iterable<string, array{?string, string, bool, string}> */
    public static function withoutASignature(): iterable
    {
        yield 'no scopes, a read' => [self::NONE, 'shelf:read', false, 'This token declares no scopes, so it holds none'];
        yield 'no scopes, a lasting change' => [self::NONE, 'shelf:put', false, 'This token declares no scopes, so it holds none'];
        yield 'another scope, a read' => [self::OTHER, 'shelf:read', false, 'context has: other:read.'];
        yield 'another scope, a lasting change' => [self::OTHER, 'shelf:put', false, 'context has: other:read.'];
        yield 'the scope, a read' => [self::HOLDS, 'shelf:read', true, ''];
        yield 'the scope, a lasting change' => [self::HOLDS, 'shelf:put', true, ''];
        yield 'no token, a read: as the terminal, as it did' => [null, 'shelf:read', true, ''];
        yield 'no token, a lasting change: refused for being unsigned, as it was' => [null, 'shelf:put', false, 'This call is not signed'];
        yield 'no scopes, a lasting change that declares no scope' => [self::NONE, 'shelf:bare', false, 'declares no authority for a finite principal'];
        yield 'another scope, a lasting change that declares no scope' => [self::OTHER, 'shelf:bare', false, 'declares no authority for a finite principal'];
        yield 'every scope, a lasting change that declares no scope' => [self::EVERY, 'shelf:bare', true, ''];
    }

    #[DataProvider('withoutASignature')]
    public function testTheShellsFormAsksWhatAPresentedTokenHolds(?string $token, string $command, bool $runs, string $says): void
    {
        $token === null ? putenv('MILPA_TOKEN') : putenv('MILPA_TOKEN=' . $token);
        $app = $this->application();
        $screen = $this->privately($app, 'pantallaDelShell');
        self::assertInstanceOf(OperationsScreen::class, $screen);
        $byName = array_column(array_map(static fn (Operation $o): array => [$o->name, $o], $screen->operations()), 1, 0);

        // The form runs a call through the console's own runner, as `coa shell` does (OperationScreen::correr).
        $answer = (new OperationRunner(new DIContainer()))->run($byName[$command], [], 'tui');

        $this->assertAnswered($answer, $command, $runs, $says);
    }

    #[DataProvider('withoutASignature')]
    public function testTheChatAsksWhatAPresentedTokenHolds(?string $token, string $command, bool $runs, string $says): void
    {
        $token === null ? putenv('MILPA_TOKEN') : putenv('MILPA_TOKEN=' . $token);

        $answer = $this->privately($this->application(), 'correr', $command, []);

        $this->assertAnswered($answer, $command, $runs, $says);
    }

    // ── coa mcp ──────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The real pipe: `coa mcp` on a house that boots, spoken to line by line by a client that launched it with a token
     * in its environment — the wiring of the child's own `tools/call` is what no in-process test reaches.
     *
     * @return iterable<string, array{?string, bool, bool, string, string}>
     */
    public static function overThePipe(): iterable
    {
        yield 'no scopes' => [self::NONE, false, false, 'context has: (none)', 'context has: (none)'];
        yield 'another scope: refused, as it was' => [self::OTHER, false, false, 'context has: other:read', 'context has: other:read'];
        yield 'the scope: both run, as they did' => [self::HOLDS, true, true, '', ''];
        yield 'no token: the read runs and the lasting change is refused unsigned, as they were' => [null, true, false, '', 'This call is not signed'];
    }

    #[DataProvider('overThePipe')]
    public function testOverTheRealStdioPipeTheTokenIsJudgedByWhatItHolds(?string $token, bool $reads, bool $writes, string $readSays, string $writeSays): void
    {
        $house = $this->houseThatBoots();
        try {
            $client = proc_open(
                'MILPA_TOKEN=' . escapeshellarg((string) $token) . ' ' . escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg($house . '/bin/coa') . ' mcp',
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
            $read = $ask(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'shelf_read', 'arguments' => new \stdClass()]]);
            $write = $ask(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'shelf_put', 'arguments' => new \stdClass()]]);
            fclose($pipes[0]);
            $stderr = (string) stream_get_contents($pipes[2]);
            proc_close($client);

            // Whoever launched the server is told once, where a person reads, why its calls are refused.
            self::assertSame($token === self::NONE, str_contains($stderr, 'This token declares no scopes, so it holds none'), $stderr);
            $readText = (string) ($read['result']['content'][0]['text'] ?? json_encode($read));
            $writeText = (string) ($write['result']['content'][0]['text'] ?? json_encode($write));
            self::assertSame($reads, (json_decode($readText, true)['success'] ?? null) === true, $readText);
            self::assertStringContainsString($readSays, $readText);
            self::assertSame($writes, (json_decode($writeText, true)['success'] ?? null) === true, $writeText);
            self::assertStringContainsString($writeSays, $writeText);
            self::assertSame($writes, is_file($house . '/var/shelf.log'), 'the lasting change is on disk exactly when the call was admitted');
        } finally {
            TinyHouse::remove($house);
        }
    }

    // ── The agent's leg ──────────────────────────────────────────────────────────────────────────────────────────────

    /** @return iterable<string, array{?string, bool}> */
    public static function onTheLeg(): iterable
    {
        yield 'no scopes' => [self::NONE, false];
        yield 'another scope: refused, as it was' => [self::OTHER, false];
        yield 'the scope: runs, as it did' => [self::HOLDS, true];
        yield 'no token: runs as the terminal, as it did (0311)' => [null, true];
    }

    /** The door every tool call of a leg and of a sequence goes through, handed the identity the caller presented. */
    #[DataProvider('onTheLeg')]
    public function testOnTheAgentsLegAToolIsJudgedByWhatThePresentedTokenHolds(?string $token, bool $runs): void
    {
        $executed = 0;
        $registry = new ToolRegistry(new NullLogger());
        $registry->register('shelf_read', 'Read the shelf', ['type' => 'object', 'properties' => []], function () use (&$executed): array {
            ++$executed;

            return ['items' => []];
        }, new ToolOptions(scopes: ['shelf:read']));
        $door = new ConsentBridge($registry, identity: $token === null ? null : PresentedToken::identity(self::container($this->root), $token));

        $refusal = null;
        try {
            $door->callTool('shelf_read', []);
        } catch (\Throwable $refused) {
            $refusal = $refused->getMessage();
        }

        self::assertSame($runs ? 1 : 0, $executed, (string) $refusal);
        if (!$runs) {
            self::assertStringContainsString('Missing required scope', (string) $refusal);
        }
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @param array<mixed>|null $answer */
    private function assertAnswered(mixed $answer, string $command, bool $runs, string $says): void
    {
        self::assertIsArray($answer);
        self::assertSame($runs ? [$command] : [], $this->ran, (string) json_encode($answer));
        self::assertSame($runs, $answer['ok']);
        if (!$runs) {
            self::assertStringContainsString($says, (string) $answer['error']);
            self::assertSame($says === 'This call is not signed' ? 'unsigned' : null, $answer['refused'] ?? null, 'a signature is not what a missing scope asks for');
        }
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string}
     */
    private function terminal(array $arguments, ?LabSigner $key = null): array
    {
        $app = $this->application($key);
        ob_start();
        try {
            $exit = $app->run(['coa', ...$arguments]);

            return [$exit, (string) ob_get_contents()];
        } finally {
            ob_end_clean();
        }
    }

    private function application(?LabSigner $key = null): Application
    {
        $app = new Application($this->root, $key, $key);
        // The fixture supplies the operations; dispatch, boot and every door are the shipped implementations.
        $lasting = new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::Compensatable, Authority::WriteAsUser, subject: Subject::None);
        (new \ReflectionProperty($app, 'operations'))->setValue($app, [
            $this->operation('shelf:read', EffectProfile::readOnly(), ['shelf:read']),
            $this->operation('shelf:put', $lasting, ['shelf:write']),
            $this->operation('shelf:bare', $lasting, []),
            // The house's own `token:new`, as shipped, over the store this house boots with.
            ...array_filter((new TokenOperations(self::container($this->root)))->operations(), static fn (Operation $op): bool => $op->name === 'token.new'),
        ]);

        return $app;
    }

    /** @param list<string> $scopes */
    private function operation(string $name, EffectProfile $effects, array $scopes): Operation
    {
        return new Operation(
            name: $name,
            description: 'd',
            handler: function () use ($name): array {
                $this->ran[] = $name;

                return ['ok' => true, 'did' => $name];
            },
            inputSchema: ['type' => 'object', 'properties' => []],
            mutating: $effects->mutation !== Mutation::None,
            effects: $effects,
            scopes: $scopes,
        );
    }

    /** A house a fresh process boots: the same three tokens, and a shelf with a read and a lasting change. */
    private function houseThatBoots(): string
    {
        $house = TinyHouse::create('HelloPlugin');
        file_put_contents($house . '/config/boot.php', '<?php $c = \\' . self::class . '::container(dirname(__DIR__)); return ["container" => $c, "plugins" => \Milpa\Plugin\Activation\ActivePlugins::wire($c, require __DIR__ . "/plugins.php", dirname(__DIR__) . "/storage/plugins.json")];');
        file_put_contents($house . '/config/operations.php', "<?php return [\\App\\Shelf::class];\n");
        file_put_contents($house . '/src/Shelf.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App;

            use Milpa\Command\CommandProvider;
            use Milpa\Command\Effect\Authority;
            use Milpa\Command\Effect\EffectProfile;
            use Milpa\Command\Effect\Externality;
            use Milpa\Command\Effect\Mutation;
            use Milpa\Command\Effect\Reversibility;
            use Milpa\Command\Effect\Subject;
            use Milpa\Command\Operation;

            final class Shelf implements CommandProvider
            {
                public function operations(): array
                {
                    $root = \dirname(__DIR__);

                    return [
                        new Operation(
                            name: 'shelf.read',
                            description: 'Read the shelf',
                            handler: static fn (): array => ['ok' => true, 'items' => []],
                            inputSchema: ['type' => 'object', 'properties' => []],
                            effects: EffectProfile::readOnly(),
                            scopes: ['shelf:read'],
                        ),
                        new Operation(
                            name: 'shelf.put',
                            description: 'Put one thing on the shelf',
                            handler: static function () use ($root): array {
                                file_put_contents($root . '/var/shelf.log', "put\n", \FILE_APPEND);

                                return ['ok' => true];
                            },
                            inputSchema: ['type' => 'object', 'properties' => []],
                            mutating: true,
                            effects: new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::Compensatable, Authority::WriteAsUser, subject: Subject::None),
                            scopes: ['shelf:write'],
                        ),
                    ];
                }
            }
            PHP);
        @mkdir($house . '/bin', 0o775, true);
        file_put_contents($house . '/bin/coa', '<?php require ' . var_export($house . '/vendor/autoload.php', true) . '; exit((new Milpa\AppRuntime\Console\Application(' . var_export($house, true) . '))->run($argv));');

        return $house;
    }

    private function privately(Application $app, string $method, mixed ...$arguments): mixed
    {
        return (new \ReflectionMethod($app, $method))->invoke($app, ...$arguments);
    }
}
