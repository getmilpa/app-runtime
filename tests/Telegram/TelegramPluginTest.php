<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Telegram;

use Milpa\Agent\Principal;
use Milpa\AppRuntime\Agent\SurfaceBroadcaster;
use Milpa\AppRuntime\Telegram\LinkController;
use Milpa\AppRuntime\Telegram\TelegramPlugin;
use Milpa\Auth\Contracts\SessionStore as PasskeySessions;
use Milpa\Auth\InMemorySessionStore;
use Milpa\Auth\WebAuthn\RelyingParty;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Operation;
use Milpa\Console\Events\ConsoleEvents;
use Milpa\Console\Events\OperationExecutedEvent;
use Milpa\Container\DIContainer;
use Milpa\EventStore\EventStoreInterface;
use Milpa\Eventing\EventDispatcher;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Runtime\Config;
use Milpa\Runtime\Kernel;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The surface is off until the house declared all of it, says what is missing without saying what it holds, and
 * once on only listens and links (greenhouse decisions/0572).
 */
final class TelegramPluginTest extends TestCase
{
    use House;
    use FakeTelegram;

    private const DECLARED = ['token' => self::TOKEN, 'chat' => '4242', 'for' => self::PASSKEY, 'link' => self::LINK];

    public function testWithNothingDeclaredItMountsNothingAndSaysWhatIsMissing(): void
    {
        $plugin = $this->plugin($c = $this->container([]));
        $plugin->boot();

        self::assertSame([], $plugin->routes());
        self::assertFalse($c->has(SurfaceBroadcaster::class), 'no audience joins the bridge');
        $status = $this->call($plugin, 'telegram:status');
        self::assertFalse($status['sending']);
        self::assertCount(4, $status['missing']);
        self::assertStringContainsString('telegram.token is not declared', $status['missing'][0]);
        self::assertStringContainsString('telegram.chat is not declared', $status['missing'][1]);
        self::assertStringContainsString('telegram.for is not declared', $status['missing'][2]);
        self::assertStringContainsString('telegram.link is not one of the passkey door\'s origins (https://casa.example.ts.net)', $status['missing'][3]);
        $notify = $this->call($plugin, 'telegram:notify');
        self::assertFalse($notify['ok']);
        self::assertStringContainsString('nothing was sent', $notify['error']);
    }

    public function testBeforeBootItSaysSo(): void
    {
        self::assertSame(['the plugin has not booted'], $this->call($this->plugin($this->container(self::DECLARED)), 'telegram:status')['missing']);
    }

    /**
     * @param array<string, mixed> $telegram
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('undeclared')]
    public function testOneThingMissingKeepsItOff(array $telegram, string $says): void
    {
        $plugin = $this->plugin($this->container($telegram + self::DECLARED));
        $plugin->boot();

        $status = $this->call($plugin, 'telegram:status');
        self::assertSame([], $plugin->routes());
        self::assertCount(1, $status['missing']);
        self::assertStringContainsString($says, $status['missing'][0]);
        self::assertStringNotContainsString(self::TOKEN, (string) json_encode($status), 'status never says what a key holds');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function undeclared(): iterable
    {
        yield 'no token' => [['token' => ''], 'telegram.token'];
        yield 'no chat' => [['chat' => null], 'telegram.chat'];
        yield 'a chat as a principal' => [['for' => 'telegram:4242'], 'telegram.for'];
        yield 'a principal with spaces' => [['for' => 'passkey:a b'], 'telegram.for'];
        yield 'a link to another host' => [['link' => 'https://evil.example'], 'telegram.link is not one of the passkey door\'s origins'];
        yield 'a link over http' => [['link' => 'http://casa.example.ts.net'], 'telegram.link'];
        yield 'a link with a path' => [['link' => self::LINK . '/x'], 'telegram.link'];
    }

    public function testWithoutThePasskeyDoorItStaysOffAndNamesThePluginOrder(): void
    {
        $c = new DIContainer();
        $c->registerService(Config::class, new Config(['telegram' => self::DECLARED]));
        $plugin = $this->plugin($c);
        $plugin->boot();

        self::assertSame([], $plugin->routes());
        self::assertStringContainsString('PasskeyPlugin before TelegramPlugin', $this->call($plugin, 'telegram:status')['missing'][0]);
    }

    public function testFullyDeclaredItMountsOneDoorAndJoinsTheBridge(): void
    {
        $plugin = $this->plugin($c = $this->container(self::DECLARED));
        $plugin->boot();

        self::assertSame(['/telegram/open/{token}'], array_map(static fn ($r) => $r->path, $plugin->routes()));
        self::assertInstanceOf(LinkController::class, $c->get(LinkController::class));
        self::assertInstanceOf(SurfaceBroadcaster::class, $c->get(SurfaceBroadcaster::class));
        self::assertSame(['ok' => true, 'sending' => true, 'missing' => [], 'cards' => ['waiting' => 0, 'expired' => 0, 'settled' => 0]], $this->call($plugin, 'telegram:status'));
    }

    public function testNotifySendsWhatWaitsAndAFactFromTheBridgeDoesTheSame(): void
    {
        $plugin = $this->plugin($c = $this->container(self::DECLARED + ['api' => self::$api, 'locale' => 'es', 'ttl' => 120, 'landing' => '/panel']));
        $plugin->boot();
        $before = \count(self::told()['messages']);

        $this->aRefusal();
        self::assertSame(['ok' => true, 'sent' => 1, 'settled' => 0, 'expired' => 0, 'failed' => 0], $this->call($plugin, 'telegram:notify'));
        $this->aQuestion('asking', new Principal(self::PASSKEY, true));
        $c->get(SurfaceBroadcaster::class)->broadcast('milpa/sessions/asking', ['session' => 'asking', 'kind' => 'waiting']);

        $messages = array_slice(self::told()['messages'], $before);
        self::assertCount(2, $messages);
        self::assertSame('4242', $messages[0]['chat_id']);
        self::assertStringContainsString('le falta plugins.Blog:write (make)', $messages[0]['text']);
        self::assertStringContainsString('dura 2 min.', $messages[0]['text']);
        self::assertStringStartsWith(self::LINK . '/telegram/open/', $messages[0]['reply_markup']['inline_keyboard'][0][0]['url']);
        self::assertSame(2, $this->call($plugin, 'telegram:status')['cards']['waiting']);
    }

    public function testWithDetailNoneTheHouseNamesNothingOutside(): void
    {
        $plugin = $this->plugin($this->container(self::DECLARED + ['api' => self::$api, 'detail' => 'none']));
        $plugin->boot();
        $this->aRefusal();
        $this->call($plugin, 'telegram:notify');

        $messages = self::told()['messages'];
        self::assertStringStartsWith('A seat\'s call was refused for a scope it lacks.', end($messages)['text']);
    }

    public function testANumberDeclaredAsTextOrAChatDeclaredAsANumberIsWhatItSays(): void
    {
        $plugin = $this->plugin($this->container(['chat' => -100123, 'ttl' => '120'] + self::DECLARED + ['api' => self::$api]));
        $plugin->boot();
        $this->aRefusal();
        $this->call($plugin, 'telegram:notify');

        $messages = self::told()['messages'];
        self::assertSame('-100123', end($messages)['chat_id']);
        self::assertStringContainsString('lasts 2 min.', end($messages)['text']);
    }

    public function testALifeThatIsNotAPositiveNumberIsTheDefault(): void
    {
        foreach (['0', -5, 'soon', null] as $ttl) {
            $this->setUp();
            $plugin = $this->plugin($this->container(['ttl' => $ttl] + self::DECLARED + ['api' => self::$api]));
            $plugin->boot();
            $this->aRefusal();
            $this->call($plugin, 'telegram:notify');
            $messages = self::told()['messages'];
            self::assertStringContainsString('lasts 15 min.', end($messages)['text'], var_export($ttl, true));
        }
    }

    public function testAnAudienceTheAppAlreadyDeclaredKeepsHearingEveryFact(): void
    {
        $c = $this->container(self::DECLARED + ['api' => self::$api]);
        $hub = new class () implements SurfaceBroadcaster {
            /** @var list<string> */
            public array $heard = [];

            public function broadcast(string $topic, array $payload): void
            {
                $this->heard[] = $topic;
            }
        };
        $c->registerService(SurfaceBroadcaster::class, $hub);
        $this->plugin($c)->boot();

        $c->get(SurfaceBroadcaster::class)->broadcast('milpa/sessions/x', ['session' => 'x', 'kind' => 'plan']);

        self::assertSame(['milpa/sessions/x'], $hub->heard);
        self::assertNotSame($hub, $c->get(SurfaceBroadcaster::class));
    }

    public function testABareMercureHubKeepsHearingToo(): void
    {
        $c = $this->container(self::DECLARED + ['api' => self::$api]);
        $hub = new class () {
            /** @var list<string> */
            public array $topics = [];

            /** @param array<string, mixed> $data */
            public function publish(string $topic, array $data): void
            {
                $this->topics[] = $topic;
            }
        };
        $c->registerService('Milpa\\Mercure\\MercureService', $hub);
        $this->plugin($c)->boot();

        $c->get(SurfaceBroadcaster::class)->broadcast('milpa/sessions/x', ['session' => 'x', 'kind' => 'plan']);

        self::assertSame(['milpa/sessions/x'], $hub->topics, 'declaring a broadcaster must not silence the hub the bridge would have found');
    }

    public function testAGrantThatRanRewritesItsCardWithoutAnybodyAsking(): void
    {
        $c = $this->container(self::DECLARED + ['api' => self::$api]);
        $events = new EventDispatcher(new NullLogger());
        $c->registerService(MilpaEventDispatcherInterface::class, $events);
        $plugin = $this->plugin($c);
        $plugin->boot();
        $this->aRefusal();
        $this->call($plugin, 'telegram:notify');
        $this->grant();

        $ran = static fn (string $name, array $input): array => ['event' => new OperationExecutedEvent(new Operation($name, '', static fn (): array => []), $input, 'http', ['ok' => true])];
        $events->dispatch(ConsoleEvents::EXECUTED, $ran('config:set', ['session' => self::SESSION]));
        self::assertSame(1, $this->call($plugin, 'telegram:status')['cards']['waiting'], 'an operation that settles nothing moves nothing');
        $events->dispatch(ConsoleEvents::EXECUTED, $ran('identity:grant', ['session' => self::SESSION, 'seq' => 2]));

        self::assertSame(1, $this->call($plugin, 'telegram:status')['cards']['settled']);
        $last = self::told()['messages'];
        self::assertStringStartsWith('Decided: passkey:cred-of-… granted plugins.Blog:write.', end($last)['text']);
    }

    public function testNeitherOperationDecidesAndNotifyDeclaresThatItSpeaksOutside(): void
    {
        $operations = [];
        foreach ($this->plugin($this->container([]))->operations() as $operation) {
            $operations[$operation->name] = $operation;
        }

        self::assertSame(['telegram:status', 'telegram:notify'], array_keys($operations));
        self::assertFalse($operations['telegram:status']->mutating);
        self::assertSame(['cli', 'tui'], $operations['telegram:status']->surfaces);
        self::assertTrue($operations['telegram:notify']->mutating);
        self::assertSame(['telegram:notify'], $operations['telegram:notify']->scopes);
        self::assertSame(['cli'], $operations['telegram:notify']->surfaces, 'never over MCP or HTTP');
        self::assertSame(Externality::ThirdParty, $operations['telegram:notify']->effects?->externality);
    }

    public function testItCarriesTheMetadataTheKernelAsksOfEveryPlugin(): void
    {
        self::assertNotSame([], (new \ReflectionClass(TelegramPlugin::class))->getAttributes(\Milpa\Attributes\PluginMetadata::class));
        $plugin = $this->plugin($this->container([]));
        $plugin->install();
        $plugin->enable();
        $plugin->disable();
        $plugin->uninstall();
        $this->addToAssertionCount(1);
    }

    /**
     * @param array<string, mixed> $telegram
     */
    private function container(array $telegram): DIContainer
    {
        $c = new DIContainer();
        $c->registerService(Config::class, new Config(['telegram' => $telegram, 'passkey' => ['rpId' => 'casa.example.ts.net']]));
        $kernel = (new \ReflectionClass(Kernel::class))->newInstanceWithoutConstructor();
        foreach (['root' => $this->root, 'commands' => []] as $name => $value) {
            (new \ReflectionProperty(Kernel::class, $name))->setValue($kernel, $value);
        }
        $c->registerService(Kernel::class, $kernel);
        $c->registerService(RelyingParty::class, new RelyingParty('casa.example.ts.net', 'Milpa', [self::LINK]));
        $c->registerService(PasskeySessions::class, new InMemorySessionStore());
        $c->registerService(EventStoreInterface::class, $this->events);

        return $c;
    }

    private function plugin(DIContainer $container): TelegramPlugin
    {
        return new TelegramPlugin($container);
    }

    /** @return array<string, mixed> */
    private function call(TelegramPlugin $plugin, string $operation): array
    {
        foreach ($plugin->operations() as $candidate) {
            if ($candidate->name === $operation) {
                return ($candidate->handler)([]);
            }
        }
        self::fail('no such operation: ' . $operation);
    }
}
