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

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\RouteFailureCause;
use PHPUnit\Framework\TestCase;

/**
 * The cause of a failed route, read from the line the house logged (greenhouse decisions/0539).
 *
 * @guards the request's own `Unhandled` line is read whole — class, message, file:line, reference — with the
 *         JSON tail a logger appends left out; a fatal nobody caught is read in both of PHP's spellings
 *
 * @refuses a stderr with no line this reader knows (null, never a guess)
 *
 * @subject-in milpa/app-runtime
 */
final class RouteFailureCauseTest extends TestCase
{
    /** The line of evidence/1071 B2, as the observing process of Rod's house logged it. */
    public function testTheLineOfRodsBlogIsReadWhole(): void
    {
        $root = '/srv/house';
        $stderr = "[warning] something the boot said\n"
            . '[error] Unhandled Milpa\Exceptions\ContainerResolutionException at /srv/house/vendor/milpa/container/src/DIContainer.php:526'
            . ' — Cannot resolve parameter $container for class App\Plugins\Blog\Controllers\BlogController [ref d6aab729f8529f3e]'
            . ' {"class":"Milpa\\\\Exceptions\\\\ContainerResolutionException","file":"/srv/house/vendor/milpa/container/src/DIContainer.php"}' . "\n";

        self::assertSame([
            'class' => 'Milpa\Exceptions\ContainerResolutionException',
            'message' => 'Cannot resolve parameter $container for class App\Plugins\Blog\Controllers\BlogController',
            'at' => 'vendor/milpa/container/src/DIContainer.php:526',
            'reference' => 'd6aab729f8529f3e',
        ], RouteFailureCause::read($stderr, $root));
    }

    public function testTheRequestsLastUnhandledLineWins(): void
    {
        $stderr = "[error] Unhandled A at /h/src/A.php:1 — first [ref 0000000000000001]\n"
            . "[error] Unhandled B at /h/src/B.php:2 — second\nwith a line break [ref 0000000000000002]\n";

        $cause = RouteFailureCause::read($stderr, '/h');

        self::assertSame('B', $cause['class'] ?? null);
        self::assertSame('second with a line break', $cause['message'] ?? null, 'one line');
        self::assertSame('0000000000000002', $cause['reference'] ?? null);
    }

    public function testAnUncaughtFatalIsReadWithItsClass(): void
    {
        $stderr = "PHP Fatal error:  Uncaught RuntimeException: nope in /h/src/Plugins/Blog/Blog.php:12\nStack trace:\n#0 {main}\n  thrown in /h/src/Plugins/Blog/Blog.php on line 12\n";

        self::assertSame(['class' => 'RuntimeException', 'message' => 'nope', 'at' => 'src/Plugins/Blog/Blog.php:12'], RouteFailureCause::read($stderr, '/h'));
    }

    public function testACompileFatalIsReadWithoutAClass(): void
    {
        $stderr = "Fatal error: Class App\\Broken contains 1 abstract method in /h/src/Broken.php on line 3\n";

        self::assertSame(['message' => 'Class App\Broken contains 1 abstract method', 'at' => 'src/Broken.php:3'], RouteFailureCause::read($stderr, '/h'));
        self::assertSame('Class App\Broken contains 1 abstract method (at src/Broken.php:3)', RouteFailureCause::oneLine(['message' => 'Class App\Broken contains 1 abstract method', 'at' => 'src/Broken.php:3']));
    }

    public function testNothingKnownIsNull(): void
    {
        self::assertNull(RouteFailureCause::read('', '/h'));
        self::assertNull(RouteFailureCause::read("[warning] slow query\nDeprecated: something in /h/x.php on line 2\n", '/h'), 'a warning is not the cause of a 500');
    }

    public function testACopyOfTheHouseIsStrippedLikeTheHouse(): void
    {
        $stderr = '[error] Unhandled E at /h/var/boot-candidates/0a1b2c/src/X.php:4 — in /h/var/boot-candidates/0a1b2c/src/X.php [ref 00000000000000ff]';

        $cause = RouteFailureCause::read($stderr, '/h');

        self::assertSame('src/X.php:4', $cause['at'] ?? null);
        self::assertSame('in src/X.php', $cause['message'] ?? null);
    }
}
