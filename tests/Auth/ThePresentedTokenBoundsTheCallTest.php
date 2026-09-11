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

namespace Milpa\AppRuntime\Tests\Auth;

use Milpa\AppRuntime\Auth\ApiToken;
use Milpa\AppRuntime\Auth\PresentedToken;
use Milpa\AppRuntime\Auth\TokenVerifier;
use Milpa\Container\DIContainer;
use Milpa\Data\InMemoryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * 🚨 THE SCOPE JUDGE WAS NEVER MISSING — IT HAD NOBODY TO JUDGE.
 *
 * `ToolRegistry::call()` runs `PolicyGate::authorize($ctx, $tool)` on every governed call, the agent's
 * included, and that judge refuses for real: «Missing required scope» was live in this package from
 * v0.29.0 to v0.32.0. What it was fed is the problem — `ToolContext::cli()` hands out `scopes: ['*']`,
 * and `ConsentBridge` keeps that wildcard on purpose, because the version that built a context from
 * scratch stripped every scope from every tool and broke `plugins_list` and its siblings for three
 * releases (greenhouse decisions/0311).
 *
 * So this slice does not build a judge and does not change what an unidentified shell may do. It gives
 * the judge an identity when a caller offers one: a token `token:new` minted, verified by the same
 * {@see TokenVerifier} the HTTP door uses, so a token cannot mean one thing to a browser and another to
 * a shell.
 *
 * 🚨 AND THE PARITY CONTROL `decisions/0240` ASKS FOR IS NOT REACHABLE BY WIRING SCOPES, which this
 * slice measured before building: the two species are judged by two DIFFERENT judges. A human running
 * `php bin/coa <op>` is judged by `OperationAuthorizer` — a GPG signature and a nonce ledger. An agent
 * is judged by `PolicyGate` — scopes and consent grants. They cannot refuse identically because they
 * are not asked the same question. That is `0240` F3 («se relegó en vez de alojar») in its sharpest
 * form, and naming it is this slice's finding; joining the two judges is its own arc.
 */
#[CoversClass(PresentedToken::class)]
final class ThePresentedTokenBoundsTheCallTest extends TestCase
{
    private const string SECRET = 'tok_a_person_minted_on_purpose';

    /**
     * A presented token's scopes become the call's scopes.
     *
     * This is the whole point of the slice: the judge downstream compares against these, and until now
     * it always compared against `['*']`.
     */
    public function testAPresentedTokenGivesTheCallItsScopes(): void
    {
        $identity = PresentedToken::identity($this->container(['posts:write', 'files:read']), self::SECRET);

        self::assertNotNull($identity, 'a minted token verifies');
        self::assertSame(['posts:write', 'files:read'], PresentedToken::scopes($identity, ['*']));
        self::assertSame('rod', $identity->actor?->id);
    }

    /**
     * 🚨 THE REGRESSION CONTROL, and it is the reason the default is what it is.
     *
     * With no token presented, the call keeps the scopes it already had. `milpa/app-runtime` v0.29.0
     * replaced that default with an empty list and every tool started answering «Missing required
     * scope» — `plugins_simulate`, `plugins_list` and their siblings — and it stayed broken until
     * v0.32.0. Putting a context where there was none is not adding information: it is replacing a
     * default that did say something.
     *
     * Bounding an unidentified local shell is a decision nobody has made (Rod, 2026-09-11: it keeps
     * `['*']`), and it cannot be made until every operation declares the scope it needs.
     */
    public function testWithNoTokenTheCallKeepsTheScopesItAlreadyHad(): void
    {
        $container = $this->container(['posts:write']);

        self::assertNull(PresentedToken::identity($container, ''), 'nothing presented is not an identity');
        self::assertSame(['*'], PresentedToken::scopes(null, ['*']), 'the wildcard default survives');
        self::assertSame([], PresentedToken::scopes(null, []), 'and so does an empty one — the default wins either way');
    }

    /**
     * 🚨 A TOKEN THAT DOES NOT VERIFY KEEPS THE DEFAULT TOO, AND THAT IS DELIBERATE.
     *
     * It is the opposite of fail-closed on purpose: a typo in an environment variable must not silently
     * turn a working shell into one that refuses everything — that is the v0.29.0 regression wearing a
     * different hat. The refusal belongs to the judge, which refuses BY SCOPE against scopes someone
     * actually minted, never to a resolver that could not read a secret.
     */
    public function testAnUnverifiableTokenIsNotAnIdentityAndNotAnEmptySetOfScopes(): void
    {
        $container = $this->container(['posts:write']);

        self::assertNull(PresentedToken::identity($container, 'tok_never_minted'));
        self::assertSame(['*'], PresentedToken::scopes(PresentedToken::identity($container, 'tok_never_minted'), ['*']));
    }

    /**
     * With no token repository wired, there is nothing to verify against and nobody is identified.
     *
     * A house that never installed `milpa/auth` has no tokens, and asking it for one must answer «none»
     * rather than throw — the governed path runs in apps that never enrolled anybody.
     */
    public function testAHouseWithNoTokenRepositoryIdentifiesNobody(): void
    {
        self::assertNull(PresentedToken::identity(new DIContainer(), self::SECRET));
    }

    /**
     * A token minted with NO scopes does not silently inherit the wildcard.
     *
     * The one case where «empty means the default» would be wrong: somebody minted a token on purpose
     * with nothing on it. `scopes()` cannot tell that apart from «no identity», so the assertion below
     * records what it actually does — the default stands — and names it as the boundary of this slice
     * rather than pretending it was decided.
     */
    public function testAScopelessTokenIsRecordedAsTheBoundaryItIs(): void
    {
        $identity = PresentedToken::identity($this->container([]), self::SECRET);

        self::assertNotNull($identity, 'it verifies — the token is real, it just grants nothing');
        self::assertSame(['*'], PresentedToken::scopes($identity, ['*']), 'and today the default still wins: a scopeless token bounds nothing');
    }

    /** A container holding one minted token for `rod`, with the scopes given. */
    private function container(array $scopes): DIContainer
    {
        $container = new DIContainer();
        /** @var InMemoryRepository<ApiToken> $repository */
        $repository = new InMemoryRepository(ApiToken::class);
        $repository->save(new ApiToken(
            hash: TokenVerifier::hash(self::SECRET),
            actor: 'rod',
            scopes: $scopes,
            createdAt: '2026-09-11T00:00:00+00:00',
        ));
        $container->registerService(TokenVerifier::class . '.repository', $repository);

        return $container;
    }
}
