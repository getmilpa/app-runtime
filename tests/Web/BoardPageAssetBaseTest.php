<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Web;

use Milpa\AppRuntime\Web\BoardPage;
use PHPUnit\Framework\TestCase;

/**
 * The board's look comes from @milpa/design. A fresh install points at the versioned CDN (zero-config), but an
 * app may bundle it and point `assetBase` at a local copy — the offline desktop host needs exactly that
 * (greenhouse evidence/0368). Default must stay the CDN (BC); the override must reach every stylesheet link.
 */
final class BoardPageAssetBaseTest extends TestCase
{
    public function testDefaultPointsAtTheVersionedCdn(): void
    {
        $html = (new BoardPage())->render('s');
        self::assertStringContainsString(BoardPage::DESIGN_BASE . '/dist/milpa-tokens.css', $html);
        self::assertStringContainsString('unpkg.com/@milpa/design', $html);
    }

    public function testAssetBaseOverrideReachesEveryStylesheet(): void
    {
        $html = (new BoardPage())->render('s', '/board/data', null, assetBase: '/vendor/milpa-design');
        self::assertStringContainsString('/vendor/milpa-design/dist/milpa-tokens.css', $html);
        self::assertStringContainsString('/vendor/milpa-design/primitives/milpa-primitives.css', $html);
        self::assertStringContainsString('/vendor/milpa-design/components/milpa-components.css', $html);
        self::assertStringNotContainsString('unpkg.com', $html);
    }
}
