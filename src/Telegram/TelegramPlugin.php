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

namespace Milpa\AppRuntime\Telegram;

use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\FanOutBroadcaster;
use Milpa\AppRuntime\Agent\MercureBroadcaster;
use Milpa\AppRuntime\Agent\SurfaceBroadcaster;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Support\AppRoot;
use Milpa\AppRuntime\Web\PasskeyPlugin;
use Milpa\AppRuntime\Web\PasskeySessionResolver;
use Milpa\Attributes\PluginMetadata;
use Milpa\Auth\Contracts\SessionStore as PasskeySessions;
use Milpa\Auth\WebAuthn\RelyingParty;
use Milpa\Command\CommandProvider;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Console\Events\ConsoleEvents;
use Milpa\Console\Events\OperationExecutedEvent;
use Milpa\EventStore\EventStoreInterface;
use Milpa\EventStore\FileEventStore;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Runtime\Config;
use Milpa\Runtime\Http\RouteProviderInterface;

/**
 * Telegram as one more place the house says «something waits for you» — and nothing else (greenhouse
 * decisions/0572).
 *
 * ── A NEW SURFACE, THE SAME OPERATIONS ───────────────────────────────────────────────────────────
 *
 * This plugin adds no way to decide. A card says that a seat's call was refused or that a session asked a
 * question, and its button opens the house: the passkey sign-in, then the panel, where `identity:grant` and
 * `agent:answer` are taken exactly as they are without Telegram. Telegram tells the house only what the house
 * already knew — nothing comes IN from it: no webhook, no polling, no callback. A chat is a place to be told,
 * never a principal.
 *
 * ── OFF UNTIL EVERYTHING IS DECLARED ─────────────────────────────────────────────────────────────
 *
 * It sends nothing and mounts no route until the house declares all of:
 *
 *     telegram.token   the bot's token                  — a secret: `provider:declare --key=telegram.token`
 *     telegram.chat    the chat cards go to             — a secret: `provider:declare --key=telegram.chat`
 *     telegram.for     the principal that chat IS       — `passkey:<id>` or `key:<fingerprint>`
 *     telegram.link    the origin a phone reaches the house at — one of the passkey door's own origins
 *
 * and optionally `telegram.landing` (the panel's path, `/milpa/admin/s/agent`), `telegram.ttl` (seconds a link lives,
 * 900), `telegram.detail` (`names`, or `none` for cards that name nothing), `telegram.locale` and `telegram.api`.
 * `telegram.for` is what keeps the frontier's rule (decisions/0493): a card is derived for ONE principal, so a
 * chat sees what that principal would see in the panel and no more. `telegram.link` must be an origin the
 * passkey door already admits — a link to anywhere else would end in a ceremony the house refuses.
 * `telegram:status` says which of these is missing, and never what any of them holds.
 */
#[PluginMetadata(version: '0.1.0', author: 'Rodrigo Vicente - TeamX Agency', site: 'https://teamx.agency', name: 'Telegram', type: 'Web')]
final class TelegramPlugin implements PluginInterface, RouteProviderInterface, CommandProvider
{
    /** The operations after which a card may have been settled: each is followed by a sweep of its session. */
    private const SETTLING = ['identity:grant', 'agent:answer', 'agent:discard', 'identity:revoke'];

    private ?Notifier $notifier = null;

    /** @var list<string> why nothing is sent, when nothing is */
    private array $off = ['the plugin has not booted'];

    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    /** Wire the surface from config, or leave it off and remember why. */
    public function boot(): void
    {
        $config = $this->config();
        $this->off = $this->missing($config);
        if ($this->off !== []) {
            return;
        }

        $root = AppRoot::of($this->container, 'TelegramPlugin');
        $events = $this->container->has(EventStoreInterface::class) ? $this->container->get(EventStoreInterface::class) : null;
        $sessions = new SessionStore($events instanceof EventStoreInterface ? $events : new FileEventStore($root . '/var/agent-sessions.jsonl'));
        $catalog = new Catalog(\is_string($config['locale'] ?? null) ? $config['locale'] : Catalog::DEFAULT_LOCALE);
        // `config:set` writes what it is given as text, so a number declared there arrives as "600".
        $ttl = is_numeric($config['ttl'] ?? null) && (int) $config['ttl'] > 0 ? (int) $config['ttl'] : Notifier::DEFAULT_TTL;
        $landing = \Milpa\AppRuntime\Web\LocalPath::orRoot($config['landing'] ?? Notifier::DEFAULT_LANDING);

        $this->notifier = new Notifier(
            new HttpBotApi((string) $config['token'], \is_string($config['api'] ?? null) && $config['api'] !== '' ? $config['api'] : HttpBotApi::DEFAULT_API),
            CardLedger::forRoot($root),
            Awaiting::forRoot($root, $sessions),
            new FileEnrollmentStore($root . '/' . PasskeyPlugin::ENROLLMENTS_PATH),
            $catalog,
            (string) $config['chat'],
            (string) $config['for'],
            rtrim((string) $config['link'], '/'),
            $landing,
            $ttl,
            ($config['detail'] ?? 'names') !== 'none',
        );

        $passkey = $this->passkeyConfig();
        $this->container->registerService(LinkController::class, new LinkController(
            $this->notifier,
            new PasskeySessionResolver(
                $this->container->get(PasskeySessions::class),
                new FileEnrollmentStore($root . '/' . PasskeyPlugin::ENROLLMENTS_PATH),
                \is_string($passkey['cookie'] ?? null) && $passkey['cookie'] !== '' ? $passkey['cookie'] : PasskeyPlugin::DEFAULT_COOKIE,
            ),
            $catalog,
        ));

        $this->listen($this->notifier);
    }

    /** The link's door, once everything is declared — otherwise none. */
    public function routes(): array
    {
        return $this->notifier === null ? [] : [
            new Route(path: Notifier::DOOR . '{token}', methods: HttpMethod::GET, name: 'telegram.open', handler: new HandlerReference(LinkController::class, 'open')),
        ];
    }

    /**
     * `telegram:status` and `telegram:notify` — neither decides anything.
     *
     * @return list<Operation>
     */
    public function operations(): array
    {
        return [
            new Operation(
                name: 'telegram:status',
                description: 'Whether this house sends what waits for a person to Telegram, what is still undeclared, and how many cards it sent — never the token or the chat',
                handler: fn (array $input): array => $this->status(),
                inputSchema: ['type' => 'object', 'properties' => [], 'required' => []],
                surfaces: ['cli', 'tui'],
                effects: EffectProfile::readOnly(),
            ),
            new Operation(
                name: 'telegram:notify',
                description: 'Send to Telegram a card for each thing that waits for the declared person, and rewrite the cards that were settled or whose link ran out. It decides nothing: a card only links to the house',
                handler: fn (array $input): array => $this->notify(),
                inputSchema: ['type' => 'object', 'properties' => [], 'required' => []],
                mutating: true,
                scopes: ['telegram:notify'],
                surfaces: ['cli'],
                // It writes the card ledger and it tells a third party that something waits: both said, so the
                // terminal asks for a signature before an unsigned call makes the house speak outside itself.
                effects: new EffectProfile(
                    Mutation::Persistent,
                    Externality::ThirdParty,
                    Reversibility::Irreversible,
                    Authority::WriteAsUser,
                    subject: Subject::Data,
                ),
            ),
        ];
    }

    /** Nothing to create: the card ledger is written under var/ when the first card is sent. */
    public function install(): void
    {
    }

    /** Nothing to remove: the ledger is the app's, under var/. */
    public function uninstall(): void
    {
    }

    /** Enabling is declaring it in config/plugins.php; sending is gated by the `telegram` block. */
    public function enable(): void
    {
    }

    /** Disabling removes it from config/plugins.php; nothing here to tear down. */
    public function disable(): void
    {
    }

    /**
     * @return array{ok: bool, sending: bool, missing: list<string>, cards?: array<string, int>}
     */
    private function status(): array
    {
        if ($this->notifier === null) {
            return ['ok' => true, 'sending' => false, 'missing' => $this->off];
        }
        $cards = ['waiting' => 0, 'expired' => 0, 'settled' => 0];
        foreach (CardLedger::forRoot(AppRoot::of($this->container, 'TelegramPlugin'))->all() as $card) {
            ++$cards[$card['state']];
        }

        return ['ok' => true, 'sending' => true, 'missing' => [], 'cards' => $cards];
    }

    /**
     * @return array{ok: bool, error?: string, missing?: list<string>, sent?: int, settled?: int, expired?: int, failed?: int}
     */
    private function notify(): array
    {
        if ($this->notifier === null) {
            return ['ok' => false, 'error' => 'this house does not send to Telegram yet; nothing was sent', 'missing' => $this->off];
        }

        return ['ok' => true] + $this->notifier->sweep();
    }

    /**
     * What is still undeclared or wrong, each as one line that names the key — empty when the surface may send.
     *
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    private function missing(array $config): array
    {
        $missing = [];
        foreach (['token' => 'the bot\'s token (a secret: provider:declare --key=telegram.token)', 'chat' => 'the chat cards go to (a secret: provider:declare --key=telegram.chat)'] as $key => $what) {
            if (!\is_string($config[$key] ?? null) || $config[$key] === '') {
                $missing[] = "telegram.{$key} is not declared: {$what}";
            }
        }
        if (!\is_string($config['for'] ?? null) || preg_match('/^(passkey|key):[A-Za-z0-9_\-]+$/', $config['for']) !== 1) {
            $missing[] = 'telegram.for is not declared: the principal that chat is, as passkey:<id> or key:<fingerprint> — cards are derived for that one principal';
        }

        $link = $config['link'] ?? null;
        $party = $this->container->has(RelyingParty::class) ? $this->container->get(RelyingParty::class) : null;
        if (!$party instanceof RelyingParty || !$this->container->has(PasskeySessions::class)) {
            $missing[] = 'the passkey door is not mounted (PasskeyPlugin before TelegramPlugin in config/plugins.php, and passkey.rpId declared): a card links to a passkey ceremony';
        } elseif (!\is_string($link) || !$party->allowsOrigin(rtrim($link, '/'))) {
            $missing[] = 'telegram.link is not one of the passkey door\'s origins (' . implode(', ', $party->allowedOrigins) . '): a link to anywhere else ends in a ceremony the house refuses';
        }

        return $missing;
    }

    /** Join the session facts' audiences and follow the operations that settle a card. */
    private function listen(Notifier $notifier): void
    {
        $cards = new CardBroadcaster($notifier);
        $declared = $this->container->has(SurfaceBroadcaster::class) ? $this->container->get(SurfaceBroadcaster::class) : null;
        if (!$declared instanceof SurfaceBroadcaster) {
            // Declaring a broadcaster stops the bridge from looking for a bare hub, so a hub the app wired joins here.
            $hub = $this->container->has('Milpa\\Mercure\\MercureService') ? $this->container->get('Milpa\\Mercure\\MercureService') : null;
            $declared = \is_object($hub) && method_exists($hub, 'publish') ? new MercureBroadcaster($hub) : null;
        }
        $audience = $declared === null ? $cards : new FanOutBroadcaster([$declared, $cards]);
        if ($this->container->has(SurfaceBroadcaster::class) && method_exists($this->container, 'replaceService')) {
            $this->container->replaceService(SurfaceBroadcaster::class, $audience);
        } elseif (!$this->container->has(SurfaceBroadcaster::class)) {
            $this->container->registerService(SurfaceBroadcaster::class, $audience);
        }

        $dispatcher = $this->container->has(MilpaEventDispatcherInterface::class) ? $this->container->get(MilpaEventDispatcherInterface::class) : null;
        if ($dispatcher instanceof MilpaEventDispatcherInterface) {
            $dispatcher->subscribe(ConsoleEvents::EXECUTED, static function (string $event, array $payload) use ($cards): void {
                $run = $payload['event'] ?? null;
                if ($run instanceof OperationExecutedEvent && \in_array($run->operation->name, self::SETTLING, true)) {
                    $session = $run->input['session'] ?? null;
                    $cards->sweep(\is_string($session) ? $session : null);
                }
            });
        }
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $telegram = $config instanceof Config ? $config->get('telegram') : null;
        $telegram = \is_array($telegram) ? $telegram : [];
        // A chat id is a number to Telegram and text to everything here: one declared as a number is the same chat.
        if (\is_int($telegram['chat'] ?? null)) {
            $telegram['chat'] = (string) $telegram['chat'];
        }

        return $telegram;
    }

    /** @return array<string, mixed> */
    private function passkeyConfig(): array
    {
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $passkey = $config instanceof Config ? $config->get('passkey') : null;

        return \is_array($passkey) ? $passkey : [];
    }
}
