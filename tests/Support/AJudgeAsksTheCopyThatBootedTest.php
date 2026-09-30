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

namespace Milpa\AppRuntime\Tests\Support;

use Milpa\AppRuntime\Agent\RouteRegression;
use Milpa\AppRuntime\Support\HouseBootWitness;
use Milpa\AppRuntime\Tests\Fixtures\TinyHouse;
use PHPUnit\Framework\TestCase;

/**
 * The boot witness asks a judge of the copy that booted, before it writes (greenhouse decisions/0540) — and the
 * route comparison that judge makes for `sandbox:promote`.
 *
 * @guards the judge is called with the copy's path while it exists, only when the copy booted; a sentence refuses
 *         the write (`judged`, `unwritten`, the live bytes untouched); null lets it land; a route broken with the
 *         promotion and not without it is `regressed`, one broken without it too is `unjudged`, a route absent
 *         without it is new; the observation's `cause` (S2, decisions/0539) and a dead process's `error` travel into the sentence
 *
 * @refuses a judge's sentence as a write; a judge on a copy that did not boot; a 404 or a 401 as broken
 *
 * @subject-in milpa/app-runtime
 */
final class AJudgeAsksTheCopyThatBootedTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TinyHouse::create('Blog');
    }

    protected function tearDown(): void
    {
        TinyHouse::remove($this->root);
    }

    public function testAJudgesSentenceRefusesTheWriteAndNothingIsWritten(): void
    {
        $before = (string) file_get_contents($this->root . '/config/plugins.php');
        $seen = null;
        $wrote = false;

        $boot = (new HouseBootWitness($this->root))->writeIfItBoots(
            ['config/app.php' => "<?php return ['x' => 1];\n"],
            static function () use (&$wrote): void {
                $wrote = true;
            },
            judge: static function (string $candidate) use (&$seen): ?string {
                $seen = is_file($candidate . '/config/app.php') ? (string) file_get_contents($candidate . '/config/app.php') : null;

                return 'the promotion breaks GET /x';
            },
        );

        self::assertSame("<?php return ['x' => 1];\n", $seen, 'the judge saw the copy WITH the change, while it existed');
        self::assertFalse($wrote);
        self::assertSame('the promotion breaks GET /x', $boot['refused']);
        self::assertSame(['unwritten' => ['config/app.php'], 'judged' => 'the promotion breaks GET /x'], $boot['said']);
        self::assertSame($before, file_get_contents($this->root . '/config/plugins.php'));
        self::assertSame([], glob($this->root . '/var/boot-candidates/*') ?: []);
    }

    public function testAJudgeThatSaysNothingLetsTheWriteLand(): void
    {
        $wrote = false;
        $boot = (new HouseBootWitness($this->root))->writeIfItBoots(['config/app.php' => "<?php return [];\n"], static function () use (&$wrote): void {
            $wrote = true;
        }, judge: static fn (string $c): ?string => null);

        self::assertTrue($wrote);
        self::assertNull($boot['refused']);
    }

    public function testAJudgeIsNotAskedOfACopyThatDidNotBoot(): void
    {
        $asked = false;
        $boot = (new HouseBootWitness($this->root))->writeIfItBoots(
            ['src/Plugins/Blog/Blog.php' => TinyHouse::pluginSource('Blog', broken: true)],
            static function (): void {
            },
            judge: static function (string $c) use (&$asked): ?string {
                $asked = true;
                return 'no';
            },
        );

        self::assertFalse($asked);
        self::assertStringStartsWith('The house does not boot with this change', (string) $boot['refused']);
        self::assertArrayNotHasKey('judged', $boot['said']);
    }

    public function testRoutesAreComparedWithAndWithoutThePromotion(): void
    {
        $after = [
            ['route' => 'GET /blog', 'status' => 500, 'cause' => ['class' => 'Milpa\\Exceptions\\ContainerResolutionException', 'message' => 'Cannot resolve parameter $container', 'at' => 'vendor/milpa/container/src/DIContainer.php:526', 'reference' => 'd6aab729f8529f3e']],
            ['route' => 'GET /fresh', 'status' => null, 'error' => 'the request process exited 255'],
            ['route' => 'GET /old', 'status' => 503],
            ['route' => 'GET /gone', 'status' => 404],
            ['route' => 'GET /door', 'status' => 401],
            ['route' => 'GET /fine', 'status' => 200, 'predicate' => 'served'],
        ];
        $before = [
            ['route' => 'GET /blog', 'status' => 200],
            ['route' => 'GET /old', 'status' => 500],
            ['route' => 'GET /gone', 'status' => 200],
            ['route' => 'GET /door', 'status' => 200],
            ['route' => 'GET /fine', 'status' => 200],
        ];

        $judged = RouteRegression::compare($after, $before);

        self::assertSame([
            ['route' => 'GET /blog', 'before' => 200, 'after' => 500, 'cause' => ['class' => 'Milpa\\Exceptions\\ContainerResolutionException', 'message' => 'Cannot resolve parameter $container', 'at' => 'vendor/milpa/container/src/DIContainer.php:526', 'reference' => 'd6aab729f8529f3e']],
            ['route' => 'GET /fresh', 'before' => null, 'after' => null, 'error' => 'the request process exited 255'],
        ], $judged['regressed']);
        self::assertSame([['route' => 'GET /old', 'before' => 500, 'after' => 503]], $judged['unjudged']);
        self::assertSame(
            'the promotion breaks GET /blog: it answered HTTP 200 without this promotion and HTTP 500 with it — Milpa\\Exceptions\\ContainerResolutionException: '
            . 'Cannot resolve parameter $container (at vendor/milpa/container/src/DIContainer.php:526); GET /fresh is new with this promotion and answers nothing (the request process exited 255)',
            RouteRegression::sentence($judged['regressed']),
        );
    }

    public function testARouteThatDiedWithoutThePromotionIsNotItsDoing(): void
    {
        $judged = RouteRegression::compare([['route' => 'GET /x', 'status' => 500]], [['route' => 'GET /x', 'status' => null, 'error' => 'timed out after 20s']]);

        self::assertSame([], $judged['regressed']);
        self::assertSame([['route' => 'GET /x', 'before' => null, 'after' => 500]], $judged['unjudged']);
    }

    public function testACauseSaysTheHousesPathsNeverTheCopysOrAnAbsoluteOne(): void
    {
        $candidate = $this->root . '/var/boot-candidates/0123456789ab';
        $judged = ['regressed' => [['route' => 'GET /blog', 'before' => 200, 'after' => 500, 'cause' => [
            'class' => 'Milpa\\Exceptions\\ContainerResolutionException',
            'message' => "Cannot resolve parameter \$container for class App\\Plugins\\Blog\\Controllers\\BlogController in {$candidate}/src/Plugins/Blog/Blog.php",
            'at' => $this->root . '/vendor/milpa/container/src/DIContainer.php:526',
        ]]], 'unjudged' => [['route' => 'GET /x', 'before' => 500, 'after' => 500, 'cause' => ['message' => '/opt/elsewhere/lib/Thing.php broke']]]];

        $relative = RouteRegression::relative($judged, $this->root);

        self::assertSame('vendor/milpa/container/src/DIContainer.php:526', $relative['regressed'][0]['cause']['at']);
        self::assertStringEndsWith('BlogController in src/Plugins/Blog/Blog.php', $relative['regressed'][0]['cause']['message']);
        self::assertSame('…/Thing.php broke', $relative['unjudged'][0]['cause']['message']);
        self::assertStringNotContainsString($this->root, (string) json_encode($relative));
    }
}
