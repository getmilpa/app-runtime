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

namespace Milpa\AppRuntime\Tests\Web;

use Milpa\AppRuntime\Web\ScreenStore;
use Milpa\AppRuntime\Web\Surfaces;
use PHPUnit\Framework\TestCase;

/**
 * THE PAGES THE HOUSE SERVES FROM A DECLARATION (greenhouse decisions/0579 §5, slice BV-3).
 *
 * `house:context` listed the paths of the route table and nothing about what answers there. The house knows, without
 * asking any route, which of them a declared screen serves: it is written in the declarations it already reads.
 */
final class SurfacesTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/milpa-surfaces-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testAHouseWithNoMountedScreenSaysTheConventionAndNoPages(): void
    {
        $this->store()->declare(['name' => 'tasks', 'columns' => [['key' => 't', 'label' => 'T']], 'rows' => [['t' => 'x']]]);

        self::assertSame(
            ['pages' => [], 'convention' => 'a page a visitor reads is a screen mounted at a route — make what=page'],
            Surfaces::declared($this->store()),
            'a screen that is mounted nowhere is not a page of the house',
        );
    }

    public function testEachMountedScreenIsAPageWithWhatItLists(): void
    {
        $this->store()->declare(['name' => 'zine', 'route' => '/zine', 'columns' => [['key' => 't', 'label' => 'T']], 'rows' => [['t' => 'one']]]);
        $this->store()->declare(['name' => 'about', 'route' => '/about', 'columns' => [['key' => 't', 'label' => 'T']], 'rows' => [['t' => 'us']]]);
        $this->store()->declare(['name' => 'tasks', 'columns' => [['key' => 't', 'label' => 'T']], 'rows' => [['t' => 'x']]]);
        // A bound screen as the store keeps it: written by hand here, because declaring one asks that its entity be loaded.
        $kept = json_decode((string) file_get_contents($this->dir . '/screens.json'), true);
        $kept['blog'] = ['type' => 'content', 'route' => '/blog', 'props' => ['name' => 'blog', 'roles' => ['title' => 'title'],
            'source' => ['entity' => 'App\\Plugins\\ListBlog\\Entities\\Post', 'columns' => ['title'], 'limit' => 5]]];
        file_put_contents($this->dir . '/screens.json', (string) json_encode($kept));

        self::assertSame(
            [
                ['route' => '/about', 'screen' => 'about', 'type' => 'data-table'],
                ['route' => '/blog', 'screen' => 'blog', 'type' => 'content', 'lists' => 'ListBlog/Post'],
                ['route' => '/zine', 'screen' => 'zine', 'type' => 'data-table'],
            ],
            Surfaces::declared($this->store())['pages'],
        );
    }

    public function testDeclarationsTheHouseCannotReadDeclareNoPage(): void
    {
        file_put_contents($this->dir . '/screens.json', '{not json');

        self::assertSame(['pages' => [], 'convention' => Surfaces::CONVENTION], Surfaces::declared($this->store()));
    }

    private function store(): ScreenStore
    {
        return ScreenStore::fromConfig(['screens_path' => $this->dir . '/screens.json'], $this->dir);
    }
}
