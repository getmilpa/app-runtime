<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Telegram;

use Milpa\AppRuntime\Telegram\Catalog;
use PHPUnit\Framework\TestCase;

/** English by default, Spanish beside it, and no key in one that the other lacks (greenhouse decisions/0138). */
final class CatalogTest extends TestCase
{
    public function testEveryLocaleHasEveryKeyWithTheSamePlaceholders(): void
    {
        self::assertSame(['en', 'es'], Catalog::locales());
        self::assertSame(Catalog::keys('en'), Catalog::keys('es'));
        foreach (Catalog::keys('en') as $key) {
            self::assertSame(substr_count((new Catalog('en'))->tr($key), '%s'), substr_count((new Catalog('es'))->tr($key), '%s'), $key);
        }
        self::assertSame([], Catalog::keys('fr'));
    }

    public function testAnUnknownLocaleSpeaksEnglishAndAnUnknownKeySaysItsName(): void
    {
        $catalog = new Catalog('fr');

        self::assertSame('en', $catalog->locale());
        self::assertSame('Open the house', $catalog->tr('card.button'));
        self::assertSame('no.such.key', $catalog->tr('no.such.key'));
        self::assertSame('es', (new Catalog('es'))->locale());
        self::assertSame('Decided: a granted b.', $catalog->tr('done.granted', 'a', 'b'));
    }
}
