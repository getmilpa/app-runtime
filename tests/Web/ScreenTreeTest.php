<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Web;

use Milpa\AppRuntime\Web\ScreenOperations;
use Milpa\AppRuntime\Web\ScreenStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A failed replacement keeps the last usable screen and never issues served evidence (Greenhouse 0326). */
final class ScreenTreeTest extends TestCase
{
    public static function invalidTrees(): iterable
    {
        yield 'unknown child' => [['children' => [['type' => 'missing']]], 'props.children.0.type'];
        yield 'nested unknown' => [['children' => [['type' => 'dashboard-grid', 'props' => ['children' => [['type' => 'missing']]]]]], 'props.children.0.props.children.0.type'];
        yield 'not a list' => [['children' => 'bad'], 'props.children'];
        yield 'map of children' => [['children' => ['named' => ['type' => 'input']]], 'props.children'];
        yield 'not an object' => [['children' => ['bad']], 'props.children.0'];
        yield 'no type' => [['children' => [['props' => []]]], 'props.children.0.type'];
        yield 'invalid props' => [['children' => [['type' => 'input', 'props' => 'bad']]], 'props.children.0.props'];
        yield 'positional props' => [['children' => [['type' => 'input', 'props' => ['bad']]]], 'props.children.0.props'];
        yield 'children on a leaf' => [['children' => [['type' => 'input', 'props' => ['children' => [['type' => 'input']]]]]], 'props.children.0.props.children'];
    }

    #[DataProvider('invalidTrees')]
    public function testInvalidReplacementIsAtomic(array $props, string $path): void
    {
        $file = sys_get_temp_dir() . '/screen-tree-' . bin2hex(random_bytes(8)) . '.json';
        $store = new ScreenStore($file);
        $declare = (new ScreenOperations($store, ['dashboard-grid', 'input']))->operations()[0]->handler;
        try {
            $valid = $declare(['name' => 'tasks', 'type' => 'dashboard-grid', 'props' => ['children' => [['type' => 'input', 'props' => ['label' => 'Task']]]]]);
            self::assertTrue($valid['ok']);
            self::assertArrayHasKey('evidence', $valid);
            $before = file_get_contents($file);
            $invalid = $declare(['name' => 'tasks', 'type' => 'dashboard-grid', 'props' => $props]);
            self::assertFalse($invalid['ok']);
            self::assertSame($path, $invalid['path']);
            self::assertArrayNotHasKey('evidence', $invalid);
            self::assertSame($before, file_get_contents($file));
        } finally {
            @unlink($file);
        }
    }
}
