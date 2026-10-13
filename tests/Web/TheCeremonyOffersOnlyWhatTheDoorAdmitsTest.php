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

namespace Milpa\AppRuntime\Tests\Web;

use Milpa\AppRuntime\Identity\IdentityInvitations;
use Milpa\AppRuntime\Web\Live\GateCeremonyComponent;
use Milpa\AppRuntime\Web\Live\GateCeremonyRender;
use Milpa\AppRuntime\Web\PasskeyPlugin;
use Milpa\Container\DIContainer;
use Milpa\Eventing\EventDispatcher;
use Milpa\Http\Routing\Router;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Runtime\Config;
use Milpa\Runtime\Http\RequestHandler;
use Milpa\Runtime\Kernel;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * WHAT THE PAGE OFFERS IS THE DOOR'S TO SAY, WHOEVER WRITES ITS WORDS.
 *
 * The ceremony hands its copy to any subscriber of `gate.ceremony.before_render`, and that is how a house
 * translates it. So «no button» cannot travel as a word in that copy: a subscriber that writes the button —
 * the only thing a translator can do with it — would put it back on a page whose invitation admits nobody.
 *
 * Every page here is asked for through the runtime's real HTTP door, and every subscriber is a real one on the
 * house's real dispatcher (`Milpa\Eventing\EventDispatcher`), which is the dispatcher the plugin hands the
 * controller. That dispatcher isolates a throwing listener, so each subscriber counts its own runs: a page
 * painted without it would otherwise pass for a page it had no effect on.
 */
final class TheCeremonyOffersOnlyWhatTheDoorAdmitsTest extends TestCase
{
    private const RP_ID = 'localhost';

    private const SIGNER = 'AAAA1111BBBB2222CCCC3333DDDD4444EEEE5555';

    private const REFUSALS = [
        'spent' => 'it was already used',
        'expired' => 'it has expired',
        'unknown' => 'this house did not issue it',
    ];

    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    /**
     * The button's words are read as every other word of the copy is: cast, not required to be a native
     * string. A lazy translation is an object until somebody prints it.
     */
    public function testAButtonHandedAsALazyTranslationIsPaintedLikeAnyOtherWord(): void
    {
        $ran = 0;
        [$root, $http] = $this->house(static function (string $event, array $payload) use (&$ran): void {
            $ceremony = $payload['ceremony'];
            self::assertInstanceOf(GateCeremonyRender::class, $ceremony);
            $ceremony->copy['button'] = self::lazily('[es] ' . $ceremony->copy['button']);
            ++$ran;
        });
        $links = $this->links($root);

        self::assertStringContainsString('<button id="go">[es] Continue with a passkey</button>', $this->get($http, '/webauthn/signin'));
        self::assertStringContainsString('<button id="go">[es] Register with passkey</button>', $this->get($http, '/webauthn/enroll'));
        self::assertStringContainsString('<button id="go">[es] Register and enter</button>', $this->get($http, '/webauthn/enroll?invite=' . $links['live']));
        self::assertSame(3, $ran, 'the subscriber ran for each page');
    }

    /** The same holds for the other word that may be absent: the refusal is said even when it arrives as an object. */
    public function testARefusalHandedAsALazyTranslationIsStillSaid(): void
    {
        $ran = 0;
        [$root, $http] = $this->house(static function (string $event, array $payload) use (&$ran): void {
            $ceremony = $payload['ceremony'];
            self::assertInstanceOf(GateCeremonyRender::class, $ceremony);
            if (isset($ceremony->copy['refused'])) {
                $ceremony->copy['refused'] = self::lazily('[es] ' . $ceremony->copy['refused']);
            }
            ++$ran;
        });
        $links = $this->links($root);

        foreach (self::REFUSALS as $link => $why) {
            $page = $this->get($http, '/webauthn/enroll?invite=' . $links[$link]);

            self::assertStringContainsString('<p class="gate__refused" role="alert">[es] This invitation will not admit anyone: ' . $why . '.</p>', $page, $link);
            self::assertStringNotContainsString('<button', $page, $link);
        }
        self::assertStringNotContainsString('<p class="gate__refused"', $this->get($http, '/webauthn/enroll?invite=' . $links['live']), 'a link that admits carries no refusal');
        self::assertSame(4, $ran);
    }

    /** A translator written before a refused page had no button: it sets the enroll button, knowing only the act. */
    public function testASubscriberThatWritesTheEnrollButtonDoesNotPutItBackOnARefusedPage(): void
    {
        $ran = 0;
        [$root, $http] = $this->house(static function (string $event, array $payload) use (&$ran): void {
            $ceremony = $payload['ceremony'];
            self::assertInstanceOf(GateCeremonyRender::class, $ceremony);
            if ($ceremony->facts['kind'] === GateCeremonyComponent::ENROLL) {
                $ceremony->copy['kicker'] = 'Paso 1 · ¿Quién eres?';
                $ceremony->copy['button'] = 'Registrar con passkey';
            }
            ++$ran;
        });
        $links = $this->links($root);

        foreach (self::REFUSALS as $link => $why) {
            $page = $this->get($http, '/webauthn/enroll?invite=' . $links[$link]);

            self::assertStringContainsString('<p class="kicker">Paso 1 · ¿Quién eres?</p>', $page, "$link: the subscriber wrote this page");
            self::assertStringNotContainsString('<button', $page, "$link: the door refused, so there is nothing to press");
            // Its own words are still its own: what it did not touch says why, where the button would be.
            self::assertStringContainsString('<p class="gate__refused" role="alert">This invitation will not admit anyone: ' . $why . '.</p>', $page);
        }
        // Where the door does admit, the button is the subscriber's to word.
        self::assertStringContainsString('<button id="go">Registrar con passkey</button>', $this->get($http, '/webauthn/enroll?invite=' . $links['live']));
        self::assertStringContainsString('<button id="go">Registrar con passkey</button>', $this->get($http, '/webauthn/enroll'));
        self::assertSame(5, $ran);
    }

    /** One that replaces the whole copy cannot know a key that did not exist when it was written. */
    public function testASubscriberThatReplacesTheWholeCopyDoesNotPutTheButtonBackEither(): void
    {
        $ran = 0;
        [$root, $http] = $this->house(static function (string $event, array $payload) use (&$ran): void {
            $ceremony = $payload['ceremony'];
            self::assertInstanceOf(GateCeremonyRender::class, $ceremony);
            $ceremony->copy = [
                'kicker' => 'Paso 1',
                'heading' => 'Registra una passkey',
                'lede' => ['Usa tu dispositivo.'],
                'doctrine' => 'Registrar te identifica.',
                'next' => null,
                'button' => 'Registrar con passkey',
                'away' => ['href' => '/webauthn/signin', 'text' => 'Entrar'],
            ];
            ++$ran;
        });
        $links = $this->links($root);

        foreach (array_keys(self::REFUSALS) as $link) {
            $page = $this->get($http, '/webauthn/enroll?invite=' . $links[$link]);

            self::assertStringContainsString('<h1>Registra una passkey</h1>', $page, "$link: the subscriber wrote this page");
            self::assertStringNotContainsString('<button', $page, "$link: the door refused, so there is nothing to press");
        }
        self::assertStringContainsString('<button id="go">Registrar con passkey</button>', $this->get($http, '/webauthn/enroll?invite=' . $links['live']));
        self::assertStringContainsString('<button id="go">Registrar con passkey</button>', $this->get($http, '/webauthn/enroll'));
        self::assertSame(5, $ran);
    }

    /** The two together: an object for a button is a button only where the door admits. */
    public function testALazyTranslationIsNoButtonOnARefusedPage(): void
    {
        $ran = 0;
        [$root, $http] = $this->house(static function (string $event, array $payload) use (&$ran): void {
            $ceremony = $payload['ceremony'];
            self::assertInstanceOf(GateCeremonyRender::class, $ceremony);
            $ceremony->copy['kicker'] = 'Paso 1';
            $ceremony->copy['button'] = self::lazily('Registrar con passkey');
            ++$ran;
        });
        $links = $this->links($root);

        foreach (array_keys(self::REFUSALS) as $link) {
            $page = $this->get($http, '/webauthn/enroll?invite=' . $links[$link]);

            self::assertStringContainsString('<p class="kicker">Paso 1</p>', $page, "$link: the subscriber wrote this page");
            self::assertStringNotContainsString('<button', $page, "$link: the door refused, so there is nothing to press");
        }
        self::assertSame(3, $ran);
    }

    /**
     * A refused page does not instruct the act it no longer offers, under the heading of a link that works.
     *
     * The doctrine line stays: it is the one sentence that has to survive this screen (greenhouse
     * decisions/0260), and it is as true of a refused link as of a live one.
     */
    public function testARefusedPageSaysWhatItIsAndTellsNobodyToRegister(): void
    {
        [$root, $http] = $this->house();
        $links = $this->links($root);

        foreach (self::REFUSALS as $link => $why) {
            $page = $this->get($http, '/webauthn/enroll?invite=' . $links[$link]);

            self::assertStringContainsString('This invitation will not admit anyone: ' . $why . '.', $page, "$link is refused for the reason this test means");
            self::assertStringContainsString("      <h1>This invitation admits nobody</h1>\n      <p>There is nothing to register from this link.</p>\n      <p class=\"gate__doctrine\">", $page, $link);
            self::assertStringNotContainsString('Register a passkey</h1>', $page, "$link: not the heading of a link that works");
            self::assertStringNotContainsString('Give this house a verifiable identity for you.', $page, $link);
            self::assertStringNotContainsString('Use your device, password manager, or security key.', $page, $link);
            self::assertStringContainsString('<p class="gate__doctrine">Registering identifies you. It grants no permissions.</p>', $page, $link);
        }
    }

    /**
     * THE CONTROL: with nobody listening, the three pages that offer an act are what they were.
     *
     * Nothing above may be bought with a change to a page whose button works.
     */
    public function testWithNobodyListeningThePagesThatOfferAnActKeepTheirWordsAndTheirButton(): void
    {
        [$root, $http] = $this->house();
        $links = $this->links($root);

        $enroll = "      <p class=\"kicker\">Step 1 · Who are you?</p>\n      <h1>Register a passkey</h1>\n"
            . "      <p>Give this house a verifiable identity for you.</p>\n      <p>Use your device, password manager, or security key.</p>\n";
        $offered = [
            '/webauthn/signin' => ["      <p class=\"kicker\">House identity · the gate</p>\n      <h1>Sign in</h1>\n      <p>Confirm the key this house already recognizes.</p>\n", 'Continue with a passkey'],
            '/webauthn/enroll' => [$enroll, 'Register with passkey'],
            '/webauthn/enroll?invite=' . $links['live'] => [$enroll, 'Register and enter'],
        ];
        foreach ($offered as $target => [$words, $button]) {
            $page = $this->get($http, $target);

            self::assertStringContainsString($words, $page, $target);
            self::assertStringContainsString(
                "  <main class=\"gate__act\">\n    <div class=\"gate__body\">\n      <button id=\"go\">" . $button . "</button>\n      <div id=\"out\"></div>\n    </div>\n",
                $page,
                $target . ': the button, and nothing above it',
            );
        }
    }

    // --- helpers ---

    /** A word that is an object until somebody prints it. */
    private static function lazily(string $words): \Stringable
    {
        return new class ($words) implements \Stringable {
            public function __construct(private readonly string $words)
            {
            }

            public function __toString(): string
            {
                return $this->words;
            }
        };
    }

    /**
     * A house with the passkey door booted and the house's real dispatcher in its container.
     *
     * @param (callable(string, array<string, mixed>): void)|null $subscriber subscribed to `gate.ceremony.before_render`
     *
     * @return array{0: string, 1: RequestHandler}
     */
    private function house(?callable $subscriber = null): array
    {
        $root = sys_get_temp_dir() . '/milpa-ceremony-offers-' . bin2hex(random_bytes(4));
        mkdir($root . '/config', 0o777, true);
        $this->roots[] = $root;

        $events = new EventDispatcher(new NullLogger());
        if ($subscriber !== null) {
            $events->subscribe(GateCeremonyComponent::BEFORE_RENDER, $subscriber);
        }

        $c = new DIContainer();
        $c->registerService(Config::class, new Config(['passkey' => ['rpId' => self::RP_ID, 'origins' => ['https://' . self::RP_ID]]]));
        $c->registerService(MilpaEventDispatcherInterface::class, $events);

        $plugin = new PasskeyPlugin($c);
        $kernel = (new \ReflectionClass(Kernel::class))->newInstanceWithoutConstructor();
        foreach (['root' => $root, 'commands' => [], 'container' => $c] as $name => $value) {
            (new \ReflectionProperty(Kernel::class, $name))->setValue($kernel, $value);
        }
        $c->registerService(Kernel::class, $kernel);
        $plugin->boot();
        (new \ReflectionProperty(Kernel::class, 'router'))->setValue($kernel, new Router(...$plugin->routes()));

        return [$root, new RequestHandler($kernel, new Psr17Factory())];
    }

    /**
     * One link of each kind, made by the store the door reads: one that admits, and three that do not.
     *
     * @return array{live: string, spent: string, expired: string, unknown: string}
     */
    private function links(string $root): array
    {
        $store = IdentityInvitations::forRoot($root);
        $live = $store->mint(['milpa.admin'], 'key:' . self::SIGNER)['token'];
        $spent = $store->mint(['milpa.admin'], 'key:' . self::SIGNER)['token'];
        self::assertTrue($store->redeem($spent, 'cred-that-spent-it')['ok']);
        $yesterdays = new IdentityInvitations($root . '/storage/identity/invitations.json', static fn (): \DateTimeImmutable => new \DateTimeImmutable('-2 days'));

        return [
            'live' => $live,
            'spent' => $spent,
            'expired' => $yesterdays->mint(['milpa.admin'], 'key:' . self::SIGNER)['token'],
            'unknown' => 'x' . $live,
        ];
    }

    private function get(RequestHandler $http, string $target): string
    {
        $request = (new ServerRequest('GET', $target))->withHeader('Accept', 'text/html');
        parse_str((string) parse_url($target, \PHP_URL_QUERY), $query);
        $response = $http->handle($request->withQueryParams($query));
        self::assertSame(200, $response->getStatusCode(), $target);

        return (string) $response->getBody();
    }
}
