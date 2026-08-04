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

namespace Milpa\AppRuntime\Auth;

use Milpa\Auth\Actor;
use Milpa\Auth\ActorType;
use Milpa\Auth\AuthContext;
use Milpa\Auth\Contracts\CredentialVerifier;
use Milpa\Auth\Credential;
use Milpa\Data\RepositoryInterface;

/**
 * Verifica un token de API contra lo que esta app guardó, y produce el contexto que prueba.
 *
 * Es la cadena de autenticación más simple que sirve de verdad: un `Authorization: Bearer <token>`,
 * un hash, y los scopes que ese token declara. Sin esto, `milpa/auth` está instalado y no hay forma
 * de que alguien se identifique — que era el estado de esta plantilla hasta ahora.
 *
 * ── SE COMPARA EL HASH, Y EN TIEMPO CONSTANTE ───────────────────────────────────────────────────
 *
 * El almacén no guarda el token, guarda su hash: robarse el archivo no entrega ninguna sesión. Y la
 * comparación usa `hash_equals` porque un `===` sobre cadenas termina antes en el primer byte que
 * difiere, y esa diferencia de tiempo se puede medir para adivinar el token byte por byte.
 *
 * ── FALLAR NO ES NEGAR ──────────────────────────────────────────────────────────────────────────
 *
 * Un token que no existe devuelve un contexto INVÁLIDO, no una excepción: quien decide si eso es un
 * 401 o un «adelante, esta ruta es pública» es la compuerta, no el verificador. Ésa es la razón de
 * que el middleware de `milpa/auth` sea fail-open en autenticación y fail-closed en autorización.
 */
final readonly class TokenVerifier implements CredentialVerifier
{
    /** @param RepositoryInterface<ApiToken> $tokens */
    public function __construct(private RepositoryInterface $tokens)
    {
    }

    /** El hash con que se guarda y se busca un token — SHA-256, sin sal: la entrada ya es aleatoria. */
    public static function hash(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Resuelve una credencial a un contexto de autenticación, o lanza si no la reconoce.
     *
     * No devuelve `null` para el caso malo: un `null` se puede ignorar por descuido y el llamador
     * seguiría adelante creyendo que autenticó. Una excepción no se ignora sin escribirlo.
     */
    public function verify(Credential $credential): AuthContext
    {
        $presentado = self::hash($credential->value());

        foreach ($this->tokens->all() as $token) {
            if (hash_equals($token->hash(), $presentado)) {
                return AuthContext::authenticated(new Actor($token->actor(), ActorType::Service, $token->scopes()));
            }
        }

        return AuthContext::invalid('token desconocido');
    }
}
