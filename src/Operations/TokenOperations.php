<?php

/**
 * This file is part of milpa/app-runtime — the agent runtime a Milpa app installs, not copies.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Operations;

use Milpa\AppRuntime\Auth\ApiToken;
use Milpa\AppRuntime\Auth\TokenVerifier;
use Milpa\AppRuntime\Support\Capabilities;
use Milpa\Command\CommandProvider;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Data\RepositoryInterface;
use Psr\Container\ContainerInterface;

/**
 * Los tokens con que alguien se identifica ante esta app por HTTP.
 *
 * Sin una forma de acuñar el primero, la cadena de autenticación es decoración: `milpa/auth` queda
 * instalado y nadie puede presentarse. Estas tres son esa forma — y viven en la terminal, no en la
 * red, porque quien puede crear un token puede crear uno con todos los scopes.
 *
 * ── EL SECRETO SE VE UNA VEZ ────────────────────────────────────────────────────────────────────
 *
 * `token:new` imprime el token y guarda sólo su hash. No hay «volver a mostrarlo»: un almacén que
 * puede devolver el secreto es un almacén cuyo robo entrega todas las sesiones. Si se pierde, se
 * revoca y se acuña otro, que es más barato que un almacén que los conserva.
 */
final readonly class TokenOperations implements CommandProvider
{
    public function __construct(private ContainerInterface $container)
    {
    }

    /**
     * @return list<Operation>
     */
    /**
     * Las operaciones de tokens que este grupo aporta al registro.
     *
     * Se devuelven en vez de registrarse solas: quien arma el registro decide qué grupos entran y
     * con qué autoridad, y un grupo que se auto-registrara le quitaría esa decisión.
     */
    public function operations(): array
    {
        // LO QUE NO SE PUEDE HACER NO SE OFRECE.
        //
        // Este framework es tiny por default y crece por opt-in, así que `milpa/auth` + `milpa/data` puede no estar
        // instalado. Anunciar una operación que sólo sabe contestar «esta app no tiene…» es peor que
        // no anunciarla: quien lee `coa list` —persona o agente— la cuenta como disponible, la llama,
        // y aprende que el listado miente. `coa capabilities` es donde se ve lo que FALTA, con el
        // `composer require` que lo enciende.
        if (!Capabilities::installed('identity')) {
            return [];
        }

        return [
            new Operation(
                name: 'token.list',
                effects: new EffectProfile(
                    Mutation::None,
                    Externality::None,
                    Reversibility::Guaranteed,
                    Authority::Read,
                    subject: Subject::None,
                    rollbackContract: 'nothing-to-roll-back',
                ),
                description: 'The API tokens of this app: who each one is and what it can do — never the secret',
                handler: fn (array $input): array => $this->list($input),
                inputSchema: ['type' => 'object', 'properties' => []],
                mutating: false,
                // Sólo la terminal. Listar tokens por HTTP le dice a quien llegue qué actores existen
                // y con qué scopes — un mapa de a quién conviene robarle.
                surfaces: ['cli'],
            ),
            new Operation(
                name: 'token.new',
                effects: new EffectProfile(
                    Mutation::Persistent,
                    // The secret is shown ONCE and never stored in the clear. Nothing leaves this process — but
                    // what it prints is a credential, and a credential that has been read cannot be unread.
                    Externality::None,
                    // Revoking stops the token working. It does not undo whoever already copied it.
                    Reversibility::Compensatable,
                    // Whoever can mint a token can mint one with every scope. Its own description says so.
                    Authority::Privileged,
                    subject: Subject::Data,
                ),
                description: 'Mint a token for an actor with the scopes you name; it is printed ONCE',
                handler: fn (array $input): array => $this->create($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'actor' => ['type' => 'string', 'description' => 'Quién es: "ci", "agente-de-rod", lo que identifique'],
                        'scopes' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Qué puede. `*` los concede todos'],
                    ],
                    'required' => ['actor'],
                ],
                // Muta y NO pide firma: acuñar es reversible —se revoca— y quien corre esto ya está
                // en la terminal de la app, que es el mismo poder. Una compuerta aquí sería trámite.
                mutating: true,
                surfaces: ['cli'],

                // El objetivo lo nombra el humano (ADR-0044), y lo puso una medición: Q-P20-J midió que
                // una puerta que muta sin contrato se usa 8/8 veces sobre un objeto que nadie nombró.
                namedTarget: 'actor',
            ),
            new Operation(
                name: 'token.revoke',
                effects: new EffectProfile(
                    Mutation::Persistent,
                    Externality::None,
                    // Revoking cannot be un-revoked: the honest recovery is minting a new one, which is a
                    // different token with a different identity.
                    Reversibility::Irreversible,
                    Authority::Privileged,
                    subject: Subject::Data,
                    escalatesOn: ['id'],
                ),
                description: 'Revoke a token by its id; it stops working on the next request',
                handler: fn (array $input): array => $this->revoke($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'string', 'description' => 'El id que `token:list` muestra']],
                    'required' => ['id'],
                ],
                mutating: true,
                surfaces: ['cli'],

                // El objetivo lo nombra el humano (ADR-0044), y lo puso una medición: Q-P20-J midió que
                // una puerta que muta sin contrato se usa 8/8 veces sobre un objeto que nadie nombró.
                namedTarget: 'id',
            ),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, tokens?: list<array{id: string, actor: string, scopes: string, createdAt: string}>, error?: string}
     */
    private function list(array $input): array
    {
        unset($input);

        $repo = $this->tokens();
        if ($repo === null) {
            return ['ok' => false, 'error' => 'esta app no cableó un almacén de tokens'];
        }

        $tokens = [];
        foreach ($repo->all() as $token) {
            $tokens[] = [
                'id' => (string) $token->id(),
                'actor' => $token->actor(),
                'scopes' => $token->scopes() === [] ? '—' : implode(', ', $token->scopes()),
                'createdAt' => $token->createdAt(),
            ];
        }

        return ['ok' => true, 'tokens' => $tokens];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, token?: string, id?: string, actor?: string, scopes?: list<string>, warning?: string, error?: string}
     */
    private function create(array $input): array
    {
        $actor = \is_string($input['actor'] ?? null) ? trim($input['actor']) : '';
        if ($actor === '') {
            return ['ok' => false, 'error' => 'falta `actor`: quién va a usar este token'];
        }

        $repo = $this->tokens();
        if ($repo === null) {
            return ['ok' => false, 'error' => 'esta app no cableó un almacén de tokens'];
        }

        /** @var list<string> $scopes */
        $scopes = [];
        foreach ((array) ($input['scopes'] ?? []) as $scope) {
            if (\is_string($scope) && trim($scope) !== '') {
                $scopes[] = trim($scope);
            }
        }

        // 32 bytes de aleatoriedad criptográfica. `random_bytes` lanza si el sistema no puede darla,
        // que es lo correcto: un token adivinable es peor que no tener token.
        $secreto = bin2hex(random_bytes(32));
        $id = $repo->save(new ApiToken(
            hash: TokenVerifier::hash($secreto),
            actor: $actor,
            scopes: $scopes,
            createdAt: (new \DateTimeImmutable())->format('c'),
        ));

        return [
            'ok' => true,
            'token' => $secreto,
            'id' => (string) $id,
            'actor' => $actor,
            'scopes' => $scopes,
            'warning' => 'guárdalo ahora: sólo se guarda su hash y no se puede volver a mostrar',
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, id?: string, error?: string}
     */
    private function revoke(array $input): array
    {
        $id = $input['id'] ?? null;
        if (!\is_string($id) && !\is_int($id)) {
            return ['ok' => false, 'error' => 'falta `id`: cuál revocar'];
        }

        $repo = $this->tokens();
        if ($repo === null) {
            return ['ok' => false, 'error' => 'esta app no cableó un almacén de tokens'];
        }

        if ($repo->find($id) === null) {
            return ['ok' => false, 'error' => "no hay ningún token con id «{$id}»"];
        }

        $repo->delete($id);

        return ['ok' => true, 'id' => (string) $id];
    }

    /**
     * El almacén de tokens que `config/boot.php` registró, o `null`.
     *
     * @return RepositoryInterface<ApiToken>|null
     */
    private function tokens(): ?RepositoryInterface
    {
        if (!$this->container->has(TokenVerifier::class . '.repository')) {
            return null;
        }

        $repo = $this->container->get(TokenVerifier::class . '.repository');

        /** @var RepositoryInterface<ApiToken>|null */
        return $repo instanceof RepositoryInterface ? $repo : null;
    }
}
