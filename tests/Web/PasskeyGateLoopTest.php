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
use Milpa\AppRuntime\Operations\SessionOperations;
use Milpa\AppRuntime\Web\PasskeyGateMiddleware;
use Milpa\AppRuntime\Web\PasskeyPlugin;
use Milpa\Auth\AuthContext;
use Milpa\Auth\Http\AuthenticateMiddleware;
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
 * The whole loop of greenhouse decisions/0206, in process, through the runtime's real HTTP door: a
 * fresh app that declares ONLY `passkey.rpId` and names the gate in a route's middleware; register a
 * passkey (synthetic attestation), root and enroll its credential id through the real `identity:enroll`
 * handler with `milpa.admin`, sign in (synthetic assertion) → cookie → the gated route answers 200
 * carrying the principal. Plus the controls: no cookie → 302 to sign-in with `next`; the same session
 * against a gate that requires another scope → 403.
 *
 * The Kernel is assembled by reflection (as the plugin tests do) with a real Router, so the request
 * travels Router → ContainerHandlerResolver → ContainerMiddlewareResolver (which is what resolves
 * `PasskeyGateMiddleware::class` from the container) → MiddlewarePipeline → controller.
 */
final class PasskeyGateLoopTest extends TestCase
{
    private const RP_ID = 'localhost';
    private const SIGNER = 'AAAA1111BBBB2222CCCC3333DDDD4444EEEE5555';

    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            self::rmdir($root);
        }
    }

    public function testRegisterEnrollSignInAndOpenTheGatedRoute(): void
    {
        [$root, $container, $http] = $this->freshApp();
        $key = SyntheticPasskey::key();

        // (a) Without a cookie a browser is sent to sign in, with next; a client gets 401.
        $res = $http->handle($this->browserGet('/milpa/admin?tab=routes'));
        self::assertSame(302, $res->getStatusCode());
        self::assertSame('/webauthn/signin?next=' . rawurlencode('/milpa/admin?tab=routes'), $res->getHeaderLine('Location'));
        self::assertSame(401, $http->handle((new ServerRequest('GET', '/milpa/admin'))->withHeader('Accept', 'application/json'))->getStatusCode());

        // The sign-in page is served by the same router, with the scope and the validated next.
        $page = $http->handle($this->browserGet('/webauthn/signin?next=' . rawurlencode('/milpa/admin?tab=routes')));
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('Scope requested: <code>milpa.admin</code>', (string) $page->getBody());
        self::assertStringContainsString('const NEXT = "/milpa/admin?tab=routes";', (string) $page->getBody());

        // (b) Register the key through the HTTP door.
        $opt = $this->json($http->handle(new ServerRequest('POST', '/webauthn/register/options')));
        $rawId = random_bytes(16);
        $reg = $http->handle(new ServerRequest(
            'POST',
            '/webauthn/register',
            [],
            (string) json_encode(SyntheticPasskey::attestation($key, self::RP_ID, SyntheticPasskey::unb64u($opt['challenge']), $rawId)),
        ));
        self::assertSame(201, $reg->getStatusCode());
        $credentialId = $this->json($reg)['credentialId'];
        self::assertSame(SyntheticPasskey::b64u($rawId), $credentialId, 'the id is the base64url of the raw id');

        // Registered is not recognized: signing in now is refused.
        $opt = $this->json($http->handle(new ServerRequest('POST', '/webauthn/authenticate/options')));
        // Registered ∩ enrolled is empty: the sign-in list never names a key nobody enrolled (decisions/0206).
        self::assertSame([], $opt['allowCredentials'], 'registered but not enrolled: not offered');
        $refused = $http->handle($this->assertion($key, $opt['challenge'], $credentialId));
        self::assertSame(401, $refused->getStatusCode(), 'registered but not enrolled: no session');

        // (c) Root the credential id out of band and enroll it with milpa.admin through the real op.
        file_put_contents($root . '/config/identity.php', '<?php return ' . var_export(['rooted' => [$credentialId]], true) . ';');
        $this->grant($container, $credentialId);
        $enrolled = $this->call($container, ['fingerprint' => $credentialId, 'scopes' => ['milpa.admin']]);
        self::assertTrue($enrolled['ok'], (string) ($enrolled['error'] ?? ''));
        self::assertSame(['milpa.admin'], (new FileEnrollmentStore($root . '/storage/identity/enrollments.json'))->scopesFor($credentialId));

        // (d) Sign in: a fresh challenge, the assertion, the cookie. The refused attempt above already
        // verified cryptographically and advanced the stored sign counter, so this one must climb past
        // it — a real authenticator does; a replayed counter is refused as a clone.
        $opt = $this->json($http->handle(new ServerRequest('POST', '/webauthn/authenticate/options')));
        $login = $http->handle($this->assertion($key, $opt['challenge'], $credentialId, counter: 8));
        self::assertSame(200, $login->getStatusCode());
        self::assertSame('passkey:' . $credentialId, $this->json($login)['actor']);
        $cookie = $this->cookie($login);

        // (e) The gated route opens, and the handler sees the principal and its scopes.
        $panel = $http->handle($this->browserGet('/milpa/admin?tab=routes')->withCookieParams([PasskeyPlugin::DEFAULT_COOKIE => $cookie]));
        self::assertSame(200, $panel->getStatusCode());
        self::assertSame(['principal' => 'passkey:' . $credentialId, 'scopes' => ['milpa.admin']], $this->json($panel));

        // (f) Control: the SAME session against a gate that requires another scope → 403.
        [, , $other] = $this->freshApp(root: $root, gateScope: 'milpa.ops');
        $denied = $other->handle($this->browserGet('/milpa/admin')->withCookieParams([PasskeyPlugin::DEFAULT_COOKIE => $cookie]));
        self::assertSame(403, $denied->getStatusCode());
        self::assertStringContainsString('Authenticated, but the scope <code>milpa.ops</code> is not granted', (string) $denied->getBody());
        self::assertStringContainsString('passkey:' . $credentialId, (string) $denied->getBody());
        self::assertStringNotContainsString($cookie, (string) $denied->getBody());

        // (g) A cookie nobody minted is no session.
        $forged = $http->handle($this->browserGet('/milpa/admin')->withCookieParams([PasskeyPlugin::DEFAULT_COOKIE => bin2hex(random_bytes(32))]));
        self::assertSame(302, $forged->getStatusCode());
    }

    // --- helpers ---

    /**
     * A fresh app: a container with Config + a reflection-built Kernel whose router carries the plugin's
     * routes and one gated route. No SessionStore registered — the plugin provides it.
     *
     * @return array{0: string, 1: DIContainer, 2: RequestHandler}
     */
    private function freshApp(?string $root = null, string $gateScope = 'milpa.admin'): array
    {
        if ($root === null) {
            $root = sys_get_temp_dir() . '/milpa-gate-loop-' . bin2hex(random_bytes(4));
            mkdir($root . '/config', 0o777, true);
            mkdir($root . '/storage/identity', 0o777, true);
            $this->roots[] = $root;
        }

        $c = new DIContainer();
        $c->registerService(Config::class, new Config(['passkey' => ['rpId' => self::RP_ID, 'gate' => ['scope' => $gateScope]]]));
        $c->registerService('gated.probe', new class () {
            /** What the gate let through: the principal and its scopes, from the attached AuthContext. */
            public function index(ServerRequestInterface $request): ResponseInterface
            {
                $context = $request->getAttribute(AuthenticateMiddleware::ATTRIBUTE);
                $actor = $context instanceof AuthContext ? $context->actor : null;

                return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                    'principal' => $actor?->id,
                    'scopes' => $actor?->scopes,
                ]));
            }
        });

        $plugin = new PasskeyPlugin($c);
        $kernel = (new \ReflectionClass(Kernel::class))->newInstanceWithoutConstructor();
        foreach (['root' => $root, 'commands' => [], 'container' => $c] as $name => $value) {
            $p = new \ReflectionProperty(Kernel::class, $name);
            $p->setValue($kernel, $value);
        }
        $c->registerService(Kernel::class, $kernel);
        $plugin->boot();

        $gated = new Route(
            path: '/milpa/admin',
            methods: HttpMethod::GET,
            name: 'admin.home',
            middleware: [PasskeyGateMiddleware::class],
            handler: new HandlerReference('gated.probe', 'index'),
        );
        $router = new Router(...[...$plugin->routes(), $gated]);
        (new \ReflectionProperty(Kernel::class, 'router'))->setValue($kernel, $router);

        return [$root, $c, new RequestHandler($kernel, new Psr17Factory())];
    }

    private function browserGet(string $target): ServerRequest
    {
        return (new ServerRequest('GET', $target))->withHeader('Accept', 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8');
    }

    private function assertion(\OpenSSLAsymmetricKey $key, string $challengeB64u, string $credentialId, int $counter = 7): ServerRequest
    {
        return new ServerRequest(
            'POST',
            '/webauthn/authenticate',
            [],
            (string) json_encode(SyntheticPasskey::assertion($key, self::RP_ID, SyntheticPasskey::unb64u($challengeB64u), $credentialId, $counter)),
        );
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

    private function grant(DIContainer $c, string $fingerprint): void
    {
        $authorization = new OperationAuthorization(
            operation: 'identity:enroll',
            arguments: ['fingerprint' => $fingerprint],
            host: 'lab-host',
            issuedAt: '2026-09-04T00:00:00+00:00',
            nonce: 'n-1',
        );
        $c->registerService(GrantedAuthorization::class, new GrantedAuthorization(
            authorization: $authorization,
            signer: new VerifiedSigner(self::SIGNER, 'Rod <rodrigo@teamx.agency>'),
            payload: $authorization->canonical(),
            signature: 'exact-signature-bytes',
        ));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function call(DIContainer $c, array $input): array
    {
        foreach ((new SessionOperations($c))->operations() as $op) {
            if ($op->name === 'identity:enroll') {
                $handler = $op->handler;
                self::assertIsCallable($handler);

                /** @var array<string, mixed> */
                return $handler($input);
            }
        }
        self::fail('identity:enroll is not offered');
    }

    private static function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
