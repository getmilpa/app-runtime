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

namespace Milpa\AppRuntime\Entity;

use Composer\Autoload\ClassLoader;
use Milpa\Data\EntityInterface;

/**
 * The one way this house reads the name of an entity, at every door that asks for one (greenhouse
 * decisions/0471, 0472).
 *
 * A PHP class inside a JSON string makes the model escape backslashes, and measured on the resident it
 * failed there: 7 of 94 sessions died re-writing `App\Plugins\…` after a consent (`App\nginx\nginx…` until
 * the output budget ran out). So a name needs no backslash. Accepted, besides the class itself:
 *
 * - the short name, `Post` — the ONE entity of this app that carries it;
 * - the name with its plugin, `Blog/Post` — `App\Plugins\Blog\Entities\Post`;
 * - the class written with `/`.
 *
 * Entities are found as `App\Plugins\<Plugin>\Entities\<Name>` through the autoloader's `App\` prefix.
 * Two entities with the same short name are never chosen between: {@see EntityNameIsAmbiguous} names
 * them in the shortest form that tells them apart, `Desk/Twin`, so what the model writes back is short
 * too. A name nothing resolves is returned as written, for the door to refuse in its own words.
 *
 * One resolver and not one per door: when `screen:declare` learned the short name, the model carried it
 * to `entity:contract`, which still asked for the class and refused it in 16 of 35 sessions (0472).
 */
final class EntityName
{
    /**
     * The class the name stands for — or the name as written, when nothing resolves it.
     *
     * @throws EntityNameIsAmbiguous when a short name is carried by more than one entity
     */
    public static function resolve(string $name): string
    {
        $written = ltrim(str_replace('/', '\\', trim($name)), '\\');
        if (class_exists($written)) {
            return $written;
        }
        $segments = explode('\\', $written);
        foreach ($segments as $segment) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment)) {
                return $written;
            }
        }
        if (\count($segments) === 2) {
            $class = 'App\\Plugins\\' . $segments[0] . '\\Entities\\' . $segments[1];

            return self::isEntity($class) ? $class : $written;
        }
        if (\count($segments) !== 1) {
            return $written;
        }

        $found = [];
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            foreach ($loader->getPrefixesPsr4()['App\\'] ?? [] as $dir) {
                foreach (glob(rtrim($dir, '/') . '/Plugins/*/Entities/' . $written . '.php') ?: [] as $file) {
                    $plugin = basename(\dirname($file, 2));
                    $class = 'App\\Plugins\\' . $plugin . '\\Entities\\' . $written;
                    if (self::isEntity($class)) {
                        $found[$plugin . '/' . $written] = $class;
                    }
                }
            }
        }
        if (\count($found) > 1) {
            ksort($found);
            throw new EntityNameIsAmbiguous($written, array_keys($found));
        }

        return $found === [] ? $written : (string) reset($found);
    }

    private static function isEntity(string $class): bool
    {
        return class_exists($class) && is_subclass_of($class, EntityInterface::class);
    }
}
