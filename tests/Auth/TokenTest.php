<?php

/**
 * This file is part of Milpa Framework — the composer create-project starting point for a Milpa app.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/framework
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Auth;

use Milpa\AppRuntime\Auth\ApiToken;
use Milpa\AppRuntime\Auth\TokenVerifier;
use Milpa\AppRuntime\Operations\TokenOperations;
use Milpa\Auth\AuthState;
use Milpa\Auth\Credential;
use Milpa\Command\Operation;
use Milpa\Container\DIContainer;
use Milpa\Data\InMemoryRepository;
use PHPUnit\Framework\TestCase;

/**
 * Los tokens con que alguien se identifica ante esta app, y la promesa que los sostiene: el secreto
 * no se guarda.
 *
 * Sin una forma de acuñar el primero, `milpa/auth` queda instalado y nadie puede presentarse — que
 * era el estado de esta plantilla hasta que se cableó la cadena.
 */
final class TokenTest extends TestCase
{
    /** @var InMemoryRepository<ApiToken> */
    private InMemoryRepository $repo;

    private DIContainer $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = new InMemoryRepository(ApiToken::class);
        $this->container = new DIContainer();
        $this->container->registerService(TokenVerifier::class . '.repository', $this->repo);
    }

    private function operacion(string $nombre): Operation
    {
        foreach ((new TokenOperations($this->container))->operations() as $op) {
            if ($op->name === $nombre) {
                return $op;
            }
        }

        self::fail("no hay operación «{$nombre}»");
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function correr(string $nombre, array $input = []): array
    {
        $handler = $this->operacion($nombre)->handler;
        self::assertIsCallable($handler);
        /** @var array<string, mixed> $r */
        $r = $handler($input);

        return $r;
    }

    /**
     * Lo que se guarda es el HASH, y el secreto se ve una sola vez.
     *
     * Es toda la tesis del almacén: robarse el archivo no entrega ninguna sesión. Se comprueba
     * buscando el secreto en lo persistido, no confiando en que el código dice que lo hashea.
     */
    public function testTheSecretIsShownOnceAndNeverStored(): void
    {
        $r = $this->correr('token.new', ['actor' => 'ci', 'scopes' => ['plugins:read']]);

        self::assertTrue($r['ok']);
        $secreto = (string) $r['token'];
        self::assertSame(64, \strlen($secreto), '32 bytes en hexadecimal');

        $guardado = json_encode(array_map(static fn (ApiToken $t): array => $t->toArray(), $this->repo->all()));
        self::assertStringNotContainsString($secreto, (string) $guardado, 'el secreto NO puede estar en el almacén');
        self::assertStringContainsString(TokenVerifier::hash($secreto), (string) $guardado);
    }

    /** Dos tokens acuñados seguidos no son el mismo — ni parecido. */
    public function testTwoMintedTokensDiffer(): void
    {
        $a = (string) $this->correr('token.new', ['actor' => 'a'])['token'];
        $b = (string) $this->correr('token.new', ['actor' => 'b'])['token'];

        self::assertNotSame($a, $b);
    }

    /** El verificador convierte un token válido en un actor con SUS scopes, no con otros. */
    public function testAValidTokenBecomesAnActorWithItsOwnScopes(): void
    {
        $secreto = (string) $this->correr('token.new', ['actor' => 'ci', 'scopes' => ['plugins:read']])['token'];

        $contexto = (new TokenVerifier($this->repo))->verify(Credential::bearer($secreto));

        self::assertSame(AuthState::Authenticated, $contexto->state);
        self::assertNotNull($contexto->actor);
        self::assertSame('ci', $contexto->actor->id);
        self::assertSame(['plugins:read'], $contexto->actor->scopes);
        self::assertTrue($contexto->actor->hasScope('plugins:read'));
        self::assertFalse($contexto->actor->hasScope('plugins:write'), 'un token no concede lo que no le dieron');
    }

    /**
     * Un token desconocido produce un contexto INVÁLIDO, no una excepción.
     *
     * Autenticar no es autorizar: quien decide si la ausencia de actor es un 401 o una ruta pública
     * es la compuerta de cada operación. Un verificador que lanzara se lo quitaría.
     */
    public function testAnUnknownTokenIsAnInvalidContextAndNotAnException(): void
    {
        $contexto = (new TokenVerifier($this->repo))->verify(Credential::bearer('no-existe'));

        self::assertSame(AuthState::Invalid, $contexto->state);
        self::assertNull($contexto->actor);
    }

    /** Revocar surte efecto: el mismo token deja de verificar. */
    public function testRevokingStopsTheTokenFromVerifying(): void
    {
        $creado = $this->correr('token.new', ['actor' => 'ci', 'scopes' => ['plugins:read']]);
        $secreto = (string) $creado['token'];

        $verificador = new TokenVerifier($this->repo);
        self::assertSame(AuthState::Authenticated, $verificador->verify(Credential::bearer($secreto))->state);

        $r = $this->correr('token.revoke', ['id' => $creado['id']]);
        self::assertTrue($r['ok']);

        self::assertSame(AuthState::Invalid, $verificador->verify(Credential::bearer($secreto))->state);
    }

    /** Revocar algo que no existe lo dice, en vez de fingir que revocó. */
    public function testRevokingSomethingThatIsNotThereSaysSo(): void
    {
        $r = $this->correr('token.revoke', ['id' => '404']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('404', (string) $r['error']);
    }

    /** Listar muestra quién y qué puede — y NUNCA el secreto ni su hash. */
    public function testListingShowsWhoAndWhatButNeverTheSecret(): void
    {
        $secreto = (string) $this->correr('token.new', ['actor' => 'ci', 'scopes' => ['plugins:read']])['token'];

        $r = $this->correr('token.list');

        self::assertTrue($r['ok']);
        $texto = (string) json_encode($r['tokens']);
        self::assertStringContainsString('ci', $texto);
        self::assertStringContainsString('plugins:read', $texto);
        self::assertStringNotContainsString($secreto, $texto);
        self::assertStringNotContainsString(TokenVerifier::hash($secreto), $texto, 'ni el hash: no hace falta para nada que se lea');
    }

    /** Las tres son de terminal. Un token se acuña donde ya se tiene el poder de acuñarlo. */
    public function testAllThreeAreTerminalOnly(): void
    {
        foreach (['token.list', 'token.new', 'token.revoke'] as $nombre) {
            $op = $this->operacion($nombre);
            self::assertTrue($op->supportsSurface('cli'));
            self::assertFalse($op->supportsSurface('http'), "{$nombre} por HTTP sería un mapa de a quién robarle");
            self::assertFalse($op->supportsSurface('mcp'));
        }
    }

    /** Sin almacén cableado, las tres lo dicen en vez de tronar. */
    public function testWithoutAStoreTheySaySoInsteadOfCrashing(): void
    {
        $sinAlmacen = new TokenOperations(new DIContainer());

        foreach ($sinAlmacen->operations() as $op) {
            $handler = $op->handler;
            self::assertIsCallable($handler);
            /** @var array{ok: bool, error?: string} $r */
            $r = $handler(['actor' => 'x', 'id' => '1']);
            self::assertFalse($r['ok']);
            self::assertStringContainsString('almacén', (string) $r['error']);
        }
    }
}
