<?php

/**
 * This file is part of Milpa App Runtime — the application runtime of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Tui;

use Milpa\AppRuntime\Tui\AgentScreen;
use PHPUnit\Framework\TestCase;

/**
 * The pin grades what the human UNDERSTANDS painted (greenhouse decisions/0069): when a promotion
 * pause carries a trial's diff, the surface must show WHAT ENTERS readably and up front — not a JSON
 * blob buried after the effect profiles.
 */
final class PromotionDiffPaintTest extends TestCase
{
    public function testTheDiffIsPaintedReadablyAndBeforeTheEffectProfiles(): void
    {
        $why = json_encode([
            'operation' => 'sandbox:promote',
            'arguments' => ['workspace' => 'w1'],
            'base' => ['mutation' => 'persistent'],
            'composed' => ['mutation' => 'persistent'],
            'cambios' => ['config/x.php' => 'modified', 'src/New.php' => 'added', 'src/Old.php' => 'deleted'],
        ]);

        $painted = $this->sobreQue($why);

        self::assertStringContainsString('config/x.php (modificado)', $painted);
        self::assertStringContainsString('src/New.php (nuevo)', $painted);
        self::assertStringContainsString('src/Old.php (borrado)', $painted);
        self::assertStringNotContainsString('"config/x.php":"modified"', $painted, 'no raw JSON for the diff');
        self::assertLessThan(
            mb_strpos($painted, 'base') ?: \PHP_INT_MAX,
            mb_strpos($painted, 'config/x.php'),
            'what enters is read before the effect profiles, not after them',
        );
    }

    public function testAPauseWithoutADiffIsUnchanged(): void
    {
        $why = json_encode(['operation' => 'mail:send', 'arguments' => ['to' => 'x@y']]);

        $painted = $this->sobreQue($why);

        self::assertStringContainsString('operation', $painted);
        self::assertStringContainsString('mail:send', $painted);
        self::assertStringNotContainsString('cambia', $painted, 'no promotion, no diff line');
    }

    private function sobreQue(string $why): string
    {
        $screen = (new \ReflectionClass(AgentScreen::class))->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod($screen, 'sobreQue');
        $m->setAccessible(true);

        return (string) $m->invoke($screen, $why);
    }
}
