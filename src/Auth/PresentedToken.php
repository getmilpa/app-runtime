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

namespace Milpa\AppRuntime\Auth;

use Milpa\AppRuntime\Support\Capabilities;
use Milpa\Auth\AuthContext;
use Milpa\Auth\Credential;
use Milpa\Data\RepositoryInterface;
use Milpa\Interfaces\Di\DIContainerInterface;

/**
 * THE IDENTITY A CALLER OFFERS OUTSIDE HTTP — a minted token, read once, verified by the same verifier.
 *
 * 🚨 THE SCOPE JUDGE WAS NEVER MISSING. `ToolRegistry::call()` runs `PolicyGate::authorize($ctx, $tool)`
 * on every governed call, the agent's included, and it has produced real refusals — «Missing required
 * scope» was live in `milpa/app-runtime` from v0.29.0 to v0.32.0. What was missing is someone for it to
 * judge: `ToolContext::cli()` hands out `scopes: ['*']`, and {@see \Milpa\AppRuntime\Agent\ConsentBridge}
 * keeps that wildcard ON PURPOSE, because the version that built a context from scratch stripped every
 * scope from every tool and broke `plugins_list` and its siblings for three releases. Its comment is the
 * lesson: «putting a context where there was none is not adding information — it is replacing a default
 * that did say something» (greenhouse decisions/0311).
 *
 * So this class does not change what an unidentified shell may do. `null` in, wildcard kept, every tool
 * that runs today still runs. It only answers the other question: when a caller DOES present a token,
 * the governed path can judge it by what that token was minted for.
 *
 * The token comes from `token:new`, which mints scopes a person chose, and is verified by
 * {@see TokenVerifier} — the same verifier the HTTP door uses, so a token cannot mean one thing to a
 * browser and another to a shell. One token, one verifier, one set of scopes.
 *
 * 🚨 AND «NO SCOPES» IS A SET OF SCOPES. Until v0.220.2 {@see self::scopes()} read an empty list as «nobody
 * identified» and handed the caller the surface's default — the terminal's and MCP's `*` — so a token minted with
 * nothing on it was the widest token in the house, and it made lasting changes with no signature: a token is how a
 * call is made without one. The first version of this class named that case as the boundary it had not decided.
 * It is decided (greenhouse decisions/0616): the default belongs to a caller that presented NOTHING, and a caller
 * that presented a token holds what the token declares — none included. That is how `milpa/auth` has always read
 * an actor ({@see \Milpa\Auth\Actor::hasAnyScope()}), and so how the HTTP door always answered the same token.
 */
final class PresentedToken
{
    /**
     * The environment variable a caller puts a minted token in.
     *
     * An environment variable and not a flag: the token must not land in shell history, in a process
     * list a second user can read, or in an acta someone pastes a command into. The HTTP door reads the
     * same secret from a header for the same reason — neither place is the terminal's scrollback.
     */
    public const string ENV = 'MILPA_TOKEN';

    /**
     * The identity this process offers, or `null` when it offers none.
     *
     * 🚨 AND A RESOLVER THAT ANSWERS `null` FOR EVERY REASON CANNOT BE TOLD APART FROM A BROKEN ONE.
     * The first version of this method imported `Milpa\Interfaces\Data\RepositoryInterface` while the
     * repository implements `Milpa\Data\RepositoryInterface`, so the `instanceof` never matched and this
     * returned `null` for a token that verifies perfectly — the feature was inert, and because `null`
     * keeps the caller's default, NOTHING broke. Every test that only asserts «nothing changed» passed.
     * What caught it is the positive control: a minted token must produce an identity. A fail-soft seam
     * needs one, or its own softness hides its own defect (greenhouse decisions/0311).
     *
     * `null` covers every reason equally on purpose — no token presented, no repository wired, a token
     * that does not verify — because a CALLER cannot be told apart from a MISCONFIGURATION here without
     * leaking whether a secret was close. What the caller sees is the same either way: the default
     * they already had. The judge downstream is what refuses, and it refuses by scope.
     *
     * 🚨 An unverifiable token answers `null`, which keeps the WILDCARD, not `[]`. That is deliberate
     * and it is the opposite of fail-closed: a typo in an environment variable must not silently turn a
     * working shell into one that refuses everything — that is the v0.29.0 regression wearing a
     * different hat. Bounding an unidentified shell is a DECISION nobody has made (Rod, 2026-09-11:
     * «sigue con `['*']`»), and it cannot be made until every operation declares the scope it needs.
     */
    public static function identity(DIContainerInterface $container, ?string $raw = null): ?AuthContext
    {
        $token = $raw ?? (\is_string($env = getenv(self::ENV)) ? trim($env) : '');
        if ($token === '') {
            return null;
        }

        $key = TokenVerifier::class . '.repository';
        if (!$container->has($key)) {
            return null;
        }
        $repository = $container->get($key);
        if (!$repository instanceof RepositoryInterface) {
            return null;
        }

        $identity = (new TokenVerifier($repository))->verify(Credential::bearer($token));

        return $identity->isAuthenticated() ? $identity : null;
    }

    /**
     * The scopes a governed call should carry, given an identity and the default it would have had.
     *
     * The DEFAULT wins when there is no identity — and only then. An identity carries its own scopes, and an
     * identity that declares none carries none: «somebody who holds nothing» is not «nobody», and reading the one as
     * the other handed a token minted with no scopes the wildcard of the surface it was presented on (greenhouse
     * decisions/0616). This method exists so that rule lives in one place instead of at each of the sites that build
     * a context — the regression that cost three releases was one site deciding differently from the other.
     *
     * @param list<string> $default the scopes the context already had, kept when nobody identified
     *
     * @return list<string>
     */
    public static function scopes(?AuthContext $identity, array $default): array
    {
        $actor = $identity?->actor;

        return $actor === null ? $default : $actor->scopes;
    }

    /**
     * True when a caller presented a token and that token declares no scopes: somebody, holding nothing.
     *
     * Never true for `null` — a caller that presented nothing is not asked what it holds.
     */
    public static function declaresNoScopes(?AuthContext $identity): bool
    {
        $actor = $identity?->actor;

        return $actor !== null && $actor->scopes === [];
    }

    /**
     * A refusal as its caller reads it: the judge's sentence, and — for a token that declares no scopes — the way out.
     *
     * The judge says what the call needed and that the context has none. Only this door knows WHY it has none, and
     * that no signature and no retry changes it: such a token was minted that way, and the remedy is another token.
     * Every other caller reads the judge's sentence as it is.
     */
    public static function refusal(?AuthContext $identity, string $reason): string
    {
        $actor = $identity?->actor;
        if ($actor === null || $actor->scopes !== []) {
            return $reason;
        }

        return $reason . "\n"
            . '  This token declares no scopes, so it holds none — and a signature does not widen a presented token.' . "\n"
            . '  Mint one that names what it may do and present that one: '
            . Capabilities::cli() . 'token:new --actor=' . escapeshellarg($actor->id) . " --scopes='[\"<scope>\"]' --sign";
    }
}
