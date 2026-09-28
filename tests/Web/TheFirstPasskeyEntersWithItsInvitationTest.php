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

use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityInvitations;
use Milpa\AppRuntime\Operations\SessionOperations;
use Milpa\AppRuntime\Web\PasskeyGateMiddleware;
use Milpa\AppRuntime\Web\PasskeyPlugin;
use Milpa\Auth\AuthContext;
use Milpa\Auth\Http\AuthenticateMiddleware;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
use Milpa\Http\Routing\Router;
use Milpa\Runtime\Config;
use Milpa\Runtime\Http\RequestHandler;
use Milpa\Runtime\Kernel;
use Milpa\ToolRuntime\Identity\GrantedAuthorization;
use Milpa\ToolRuntime\Identity\OperationAuthorization;
use Milpa\ToolRuntime\Identity\VerifiedSigner;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * greenhouse decisions/0498, through the runtime's real HTTP door: a house with NO config/identity.php, an
 * invitation minted by a signed act, and ONE registration that enrolls the passkey and opens the gated
 * route — no file, no command, no second ceremony. Plus the negatives: a spent link, a forged one, and a
 * registration without one grant nothing and leave the ledger as it was.
 */
final class TheFirstPasskeyEntersWithItsInvitationTest extends TestCase
{
    private const RP_ID = 'localhost';

    private const SIGNER = 'AAAA1111BBBB2222CCCC3333DDDD4444EEEE5555';

    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    public function testOneCeremonyEnrollsAndSignsInWithWhatTheInvitationNames(): void
    {
        [$root, $container, $http] = $this->freshApp();
        self::assertFileDoesNotExist($root . '/config/identity.php', 'the fresh house has no root file — and needs none');
        $token = IdentityInvitations::forRoot($root)->mint(['milpa.admin', 'capabilities:enable', 'identity:enroll'], 'key:' . self::SIGNER)['token'];

        // The page says, before any touch, what the invitation grants and who answers for it.
        $page = (string) $http->handle($this->browserGet('/webauthn/enroll?invite=' . $token . '&next=%2Fmilpa%2Fadmin'))->getBody();
        self::assertStringContainsString('Vouched by key:' . self::SIGNER . ', this invitation lets you: milpa.admin, capabilities:enable, identity:enroll', $page);
        $facts = $this->facts($page);
        self::assertSame($token, $facts['invite']);
        self::assertSame('/milpa/admin', $facts['next']);

        // ONE registration.
        $key = SyntheticPasskey::key();
        $reg = $this->register($http, $key, $token);
        self::assertSame(201, $reg->getStatusCode(), (string) $reg->getBody());
        $body = $this->json($reg);
        self::assertTrue($body['enrolled']);
        self::assertSame(['milpa.admin', 'capabilities:enable', 'identity:enroll'], $body['scopes']);
        self::assertSame('key:' . self::SIGNER, $body['authorized_by']);
        $credentialId = $body['credentialId'];

        $ledger = new FileEnrollmentStore($root . '/storage/identity/enrollments.json');
        self::assertSame('key:' . self::SIGNER, $ledger->authorizedBy($credentialId), 'the key that minted it answers for it');

        // …and the cookie it set opens the gated route. No sign-in ceremony in between.
        $panel = $http->handle($this->browserGet('/milpa/admin')->withCookieParams([PasskeyPlugin::DEFAULT_COOKIE => $this->cookie($reg)]));
        self::assertSame(200, $panel->getStatusCode());
        self::assertSame(['principal' => 'passkey:' . $credentialId, 'scopes' => ['milpa.admin', 'capabilities:enable', 'identity:enroll']], $this->json($panel));

        // The invited passkey is rooted: a later re-recognition needs no file either.
        $container->registerService(GrantedAuthorization::class, $this->granted('identity:enroll', ['fingerprint' => $credentialId]));
        $again = $this->operation($container, 'identity:enroll')(['fingerprint' => $credentialId, 'scopes' => ['milpa.admin']]);
        self::assertTrue($again['ok'], (string) ($again['error'] ?? ''));
    }

    public function testASpentForgedOrAbsentInvitationGrantsNothing(): void
    {
        [$root, , $http] = $this->freshApp();
        $token = IdentityInvitations::forRoot($root)->mint(['milpa.admin'], 'key:' . self::SIGNER)['token'];
        $ledgerPath = $root . '/storage/identity/enrollments.json';
        $credentials = $root . '/var/passkey/credentials.json';

        self::assertSame(201, $this->register($http, SyntheticPasskey::key(), $token)->getStatusCode());
        $ledgerAfterFirst = (string) file_get_contents($ledgerPath);
        $credentialsAfterFirst = (string) file_get_contents($credentials);

        // SPENT: a second key with the same link is refused before anything is registered.
        $second = $this->register($http, SyntheticPasskey::key(), $token);
        self::assertSame(403, $second->getStatusCode());
        self::assertSame(['ok' => false, 'error' => 'invitation_refused', 'reason' => 'already_used'], $this->json($second));

        // FORGED: the same.
        $forged = $this->register($http, SyntheticPasskey::key(), 'x' . $token);
        self::assertSame(403, $forged->getStatusCode());
        self::assertSame('unknown', $this->json($forged)['reason']);
        self::assertStringEqualsFile($ledgerPath, $ledgerAfterFirst, 'the ledger is untouched');
        self::assertStringEqualsFile($credentials, $credentialsAfterFirst, 'and nothing was registered');

        // ABSENT: registering still grants nothing and sets no cookie (decisions/0128).
        $plain = $this->register($http, SyntheticPasskey::key(), '');
        self::assertSame(201, $plain->getStatusCode());
        self::assertFalse($this->json($plain)['enrolled']);
        self::assertSame('', $plain->getHeaderLine('Set-Cookie'));
        self::assertStringEqualsFile($ledgerPath, $ledgerAfterFirst);

        // And the page for a spent link says so, without echoing the secret back as usable data.
        $page = (string) $http->handle($this->browserGet('/webauthn/enroll?invite=' . $token))->getBody();
        self::assertStringContainsString('This invitation will not admit anyone: it was already used', $page);
        self::assertSame('', $this->facts($page)['invite']);
    }

    public function testIdentityOperationsNeedNothingOfTheAgentAndTheInviteIsSignedAtTheTerminal(): void
    {
        [$root, $container] = $this->freshApp();
        $all = (new SessionOperations($container))->operations();
        $names = array_map(static fn (Operation $op): string => $op->name, SessionOperations::withoutTheAgent($all));
        self::assertSame(['identity:enroll', 'identity:revoke', 'identity:bootstrap', 'identity:invite'], $names);

        $invite = $this->operation($container, 'identity:invite');
        $unsigned = $invite([]);
        self::assertFalse($unsigned['ok'], 'nobody answers for an unsigned invitation');

        $container->registerService(GrantedAuthorization::class, $this->granted('identity:invite', ['scopes' => ['milpa.admin']]));
        $minted = $invite(['scopes' => ['milpa.admin']]);
        self::assertTrue($minted['ok'], (string) ($minted['error'] ?? ''));
        self::assertSame(['milpa.admin'], $minted['scopes']);
        self::assertSame('key:' . self::SIGNER, $minted['vouched_by']);
        parse_str((string) parse_url((string) $minted['path'], \PHP_URL_QUERY), $query);
        self::assertTrue(IdentityInvitations::forRoot($root)->check((string) $query['invite'])['ok']);

        // A signature over other scopes does not cover these — the signed arguments ARE the invitation.
        self::assertFalse($invite(['scopes' => ['milpa.admin', 'identity:enroll']])['ok']);

        // A signature for another operation does not cover this one.
        [, $other] = $this->freshApp();
        $other->registerService(GrantedAuthorization::class, $this->granted('identity:enroll', ['fingerprint' => 'x']));
        self::assertFalse($this->operation($other, 'identity:invite')([])['ok']);
    }

    // --- helpers ---

    /** @return array{0: string, 1: DIContainer, 2: RequestHandler} */
    private function freshApp(): array
    {
        $root = sys_get_temp_dir() . '/milpa-invite-loop-' . bin2hex(random_bytes(4));
        mkdir($root . '/config', 0o777, true);
        $this->roots[] = $root;

        $c = new DIContainer();
        $c->registerService(Config::class, new Config(['passkey' => ['rpId' => self::RP_ID]]));
        $c->registerService('gated.probe', new class () {
            /** What the gate let through. */
            public function index(ServerRequestInterface $request): ResponseInterface
            {
                $context = $request->getAttribute(AuthenticateMiddleware::ATTRIBUTE);
                $actor = $context instanceof AuthContext ? $context->actor : null;

                return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['principal' => $actor?->id, 'scopes' => $actor?->scopes]));
            }
        });

        $plugin = new PasskeyPlugin($c);
        $kernel = (new \ReflectionClass(Kernel::class))->newInstanceWithoutConstructor();
        foreach (['root' => $root, 'commands' => [], 'container' => $c] as $name => $value) {
            (new \ReflectionProperty(Kernel::class, $name))->setValue($kernel, $value);
        }
        $c->registerService(Kernel::class, $kernel);
        $plugin->boot();

        $gated = new Route(path: '/milpa/admin', methods: HttpMethod::GET, name: 'admin.home', middleware: [PasskeyGateMiddleware::class], handler: new HandlerReference('gated.probe', 'index'));
        (new \ReflectionProperty(Kernel::class, 'router'))->setValue($kernel, new Router(...[...$plugin->routes(), $gated]));

        return [$root, $c, new RequestHandler($kernel, new Psr17Factory())];
    }

    private function register(RequestHandler $http, \OpenSSLAsymmetricKey $key, string $invite): ResponseInterface
    {
        $opt = $this->json($http->handle(new ServerRequest('POST', '/webauthn/register/options')));
        $body = SyntheticPasskey::attestation($key, self::RP_ID, SyntheticPasskey::unb64u($opt['challenge']), random_bytes(16));
        if ($invite !== '') {
            $body['invite'] = $invite;
        }

        return $http->handle(new ServerRequest('POST', '/webauthn/register', [], (string) json_encode($body)));
    }

    /** @param array<string, mixed> $arguments */
    private function granted(string $operation, array $arguments): GrantedAuthorization
    {
        $authorization = new OperationAuthorization(operation: $operation, arguments: $arguments, host: 'lab-host', issuedAt: '2026-09-28T00:00:00+00:00', nonce: 'n-' . bin2hex(random_bytes(2)));

        return new GrantedAuthorization(authorization: $authorization, signer: new VerifiedSigner(self::SIGNER, 'Rod <rodrigo@teamx.agency>'), payload: $authorization->canonical(), signature: 'sig');
    }

    /** @return callable(array<string, mixed>): array<string, mixed> */
    private function operation(DIContainer $c, string $name): callable
    {
        foreach ((new SessionOperations($c))->operations() as $op) {
            if ($op->name === $name && \is_callable($op->handler)) {
                return $op->handler;
            }
        }
        self::fail($name . ' is not offered');
    }

    private function browserGet(string $target): ServerRequest
    {
        $request = (new ServerRequest('GET', $target))->withHeader('Accept', 'text/html');
        parse_str((string) parse_url($target, \PHP_URL_QUERY), $query);

        return $request->withQueryParams($query);
    }

    /** @return array<string, mixed> */
    private function facts(string $page): array
    {
        $facts = json_decode((string) preg_replace('#.*<script type="application/json" id="milpa-gate-ceremony">(.*?)</script>.*#s', '$1', $page), true);
        self::assertIsArray($facts);

        return $facts;
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $res): array
    {
        $body = json_decode((string) $res->getBody(), true);
        self::assertIsArray($body, 'a JSON object body');

        return $body;
    }

    private function cookie(ResponseInterface $res): string
    {
        $header = $res->getHeaderLine('Set-Cookie');
        self::assertStringStartsWith(PasskeyPlugin::DEFAULT_COOKIE . '=', $header);

        return explode(';', explode('=', $header, 2)[1])[0];
    }
}
