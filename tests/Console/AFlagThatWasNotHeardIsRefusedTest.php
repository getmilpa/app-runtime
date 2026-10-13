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

namespace Milpa\AppRuntime\Tests\Console;

use Milpa\AppRuntime\Auth\ApiToken;
use Milpa\AppRuntime\Auth\TokenVerifier;
use Milpa\AppRuntime\Console\Application;
use Milpa\AppRuntime\Console\UnsignedTerminal;
use Milpa\AppRuntime\Operations\SessionOperations;
use Milpa\AppRuntime\Operations\TokenOperations;
use Milpa\AppRuntime\Tests\Fixtures\LabSigner;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\Data\InMemoryRepository;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\Plugin\Contracts\AppRoot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A flag the operation does not declare is refused where it would have delegated in silence (greenhouse decisions/0616).
 *
 * `identity:invite` takes its scopes as an OPTION: omitted, the invitation holds what the installed capabilities
 * declare their operator needs, plus `identity:enroll` — in a house with a panel, the scopes of its administrator.
 * The terminal dropped a flag it did not know, so `identity:invite --scope=agent:read` (the flag mistyped) and
 * `identity:invite agent:read` (the word with no flag) both minted that invitation: whoever meant to narrow delegated
 * the most. `token:new` dropped `--scope=` the same way and minted a token with no scopes, and dropped `--expires=`
 * for a token that never expires.
 *
 * @guards the operations no web surface offers — `identity:invite`, `token:new`, `token:list`, `token:revoke` — close
 *         their schema: a flag they do not declare, and a word given with no flag, are refused before anything runs
 *
 * @refuses nothing that was said right: the declared flag runs as it did, and an invitation asked for with no scopes
 *          still holds the default
 *
 * @subject-in milpa/app-runtime
 */
final class AFlagThatWasNotHeardIsRefusedTest extends TestCase
{
    private string $root;
    private string|false $previousToken;

    /** @var list<array<string, mixed>> what the invitation's handler was called with */
    private array $invited = [];

    /** @var InMemoryRepository<ApiToken>|null */
    private static ?InMemoryRepository $store = null;

    protected function setUp(): void
    {
        $this->previousToken = getenv('MILPA_TOKEN');
        putenv('MILPA_TOKEN');
        self::$store = null;
        $this->root = sys_get_temp_dir() . '/milpa-flags-0616-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/config', 0o775, true);
        file_put_contents($this->root . '/config/app.php', '<?php return [];');
        file_put_contents($this->root . '/config/boot.php', '<?php return ["container" => \\' . self::class . '::container(' . var_export($this->root, true) . '), "plugins" => []];');
    }

    protected function tearDown(): void
    {
        $this->previousToken === false ? putenv('MILPA_TOKEN') : putenv('MILPA_TOKEN=' . $this->previousToken);
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** The container the fixture house boots with: where it lives, and one token store for every boot of a test. */
    public static function container(string $root): DIContainer
    {
        $container = new DIContainer();
        $container->registerService(EventStoreInterface::class, new InMemoryEventStore());
        $container->registerService(AppRoot::class, new AppRoot($root));
        $container->registerService(TokenVerifier::class . '.repository', self::$store ??= new InMemoryRepository(ApiToken::class));

        return $container;
    }

    // ── identity:invite ──────────────────────────────────────────────────────────────────────────────────────────────

    /** @return iterable<string, array{list<string>, string}> */
    public static function notHeard(): iterable
    {
        yield 'the flag mistyped' => [['--scope=agent:read'], "unknown field 'scope'"];
        yield 'the word with no flag' => [['agent:read'], '«agent:read» was given without a flag'];
        yield 'a flag it never had' => [['--scopes=agent:read', '--expires=1'], "unknown field 'expires'"];
    }

    /** @param list<string> $arguments */
    #[DataProvider('notHeard')]
    public function testAnInvitationIsNotMintedWhenWhatWasAskedWasNotHeard(array $arguments, string $says): void
    {
        [$exit, $output] = $this->terminal(['identity:invite', ...$arguments, '--sign']);

        self::assertSame([], $this->invited, 'nothing was minted — least of all the default it would have fallen to: ' . $output);
        self::assertSame(1, $exit);
        self::assertStringContainsString($says, $output);
    }

    public function testTheDeclaredFlagStillNarrowsAndNoFlagStillAsksForTheDefault(): void
    {
        [$exit] = $this->terminal(['identity:invite', '--scopes=agent:read', '--sign']);
        self::assertSame(0, $exit);
        [$exit] = $this->terminal(['identity:invite', '--sign']);
        self::assertSame(0, $exit);

        self::assertSame([['scopes' => ['agent:read']], []], $this->invited, 'said right, it runs as it did; said with no scopes, the handler decides the default as it did');
    }

    // ── token:new ────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return iterable<string, array{list<string>, string}> */
    public static function notHeardByTheMint(): iterable
    {
        yield 'the flag mistyped' => [['--actor=ci', '--scope=agent:read'], "unknown field 'scope'"];
        yield 'an expiry no token has' => [['--actor=ci', '--scopes=agent:read', '--expires=2020-01-01'], "unknown field 'expires'"];
    }

    /** @param list<string> $arguments */
    #[DataProvider('notHeardByTheMint')]
    public function testATokenIsNotMintedWhenWhatWasAskedWasNotHeard(array $arguments, string $says): void
    {
        [$exit, $output] = $this->terminal(['token:new', ...$arguments, '--sign']);

        self::assertSame([], self::$store?->all(), $output);
        self::assertSame(1, $exit);
        self::assertStringContainsString($says, $output);
    }

    /**
     * `scopes` is not made a required field: at every door that offers `token:new`, whoever asks for a token without
     * scopes reads the house's own sentence — the one that names what to type — and never the surface's «missing
     * required field», which would win over it.
     */
    public function testAtEveryDoorThatOffersItATokenAskedForWithoutScopesIsAnsweredByTheHousesSentence(): void
    {
        $new = $this->operations()['token.new'];
        self::assertSame(['actor'], $new->inputSchema['required'] ?? null, '`scopes` stays out of the required fields');
        $doors = array_values(array_filter(['cli', 'tui', 'mcp', 'http'], static fn (string $surface): bool => $new->supportsSurface($surface)));
        self::assertSame(['cli'], $doors, 'the terminal is the one door that offers it; a second door needs its own row here');

        foreach ([['--actor=ci'], ['--actor=ci', '--scopes=[]'], ['--actor=ci', '--scopes=']] as $arguments) {
            [$exit, $output] = $this->terminal(['token:new', ...$arguments, '--sign']);

            self::assertSame(1, $exit, $output);
            self::assertStringContainsString('missing `scopes`: a token holds only the scopes it is minted with', $output);
            self::assertStringContainsString("php bin/coa token:new --actor='ci' --scopes='[\"<scope>\"]' --sign", $output);
            self::assertStringNotContainsString('missing required field', $output);
            self::assertSame([], self::$store?->all());
        }
    }

    public function testTheControlATokenAskedForRightIsMinted(): void
    {
        [$exit, $output] = $this->terminal(['token:new', '--actor=ci', '--scopes=agent:read', '--sign']);

        self::assertSame(0, $exit, $output);
        self::assertSame(['agent:read'], self::$store?->all()[0]->scopes());
    }

    // ── The declaration ──────────────────────────────────────────────────────────────────────────────────────────────

    public function testTheOperationsNoWebSurfaceOffersCloseTheirSchema(): void
    {
        $operations = $this->operations();

        foreach (['identity:invite', 'token.new', 'token.list', 'token.revoke'] as $name) {
            self::assertFalse($operations[$name]->inputSchema['additionalProperties'] ?? null, "{$name} takes nothing it does not declare");
            self::assertFalse($operations[$name]->supportsSurface('http'), "{$name} is closed because no web surface sends it anything");
        }
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string}
     */
    private function terminal(array $arguments): array
    {
        // A key this house never recognized signs as the terminal: what is measured here is what the arguments say.
        $key = new LabSigner();
        $app = new Application($this->root, $key, $key);
        $operations = $this->operations();
        // The house's own declarations, as shipped. The invitation's handler is replaced by one that records what it
        // was handed — the scopes that reach it, or their absence, are what decides what the invitation holds.
        $operations['identity:invite'] = UnsignedTerminal::withHandler($operations['identity:invite'], function (array $input): array {
            $this->invited[] = $input;

            return ['ok' => true];
        });
        (new \ReflectionProperty($app, 'operations'))->setValue($app, array_values($operations));
        ob_start();
        try {
            $exit = $app->run(['coa', ...$arguments]);

            return [$exit, (string) ob_get_contents()];
        } finally {
            ob_end_clean();
        }
    }

    /** @return array<string, Operation> the identity and token operations this house declares, by name */
    private function operations(): array
    {
        $container = self::container($this->root);
        $byName = [];
        foreach ([...(new SessionOperations($container))->operations(), ...(new TokenOperations($container))->operations()] as $operation) {
            if (\in_array($operation->name, ['identity:invite', 'token.new', 'token.list', 'token.revoke'], true)) {
                $byName[$operation->name] = $operation;
            }
        }

        return $byName;
    }
}
