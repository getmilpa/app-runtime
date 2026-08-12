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

namespace Milpa\AppRuntime\Config;

/**
 * What an agent configuration key LOOKS LIKE, defined once and called by whoever needs to find one.
 *
 * The rule was written twice and the two copies disagreed, which is the only way this defect ever
 * shows up. greenhouse evidence/0158 planted a key beginning with a digit and the narrower copy did
 * not see it — a rail meant to remove a blind spot had one, and only luck decided which copy the
 * control happened to exercise.
 *
 * greenhouse evidence/0141 already settled the shape of the answer: a convention is CALLED, not
 * copied. This class is the call. The pattern lives in one constant, the sweep in one method, and a
 * second implementation anywhere is now a defect somebody can point at rather than a difference
 * nobody can see.
 *
 * IT DELIBERATELY ACCEPTS MORE THAN THIS RUNTIME DECLARES. Its job is to find what the code READS,
 * including keys nobody declared — that gap is the whole finding — so anything a caller could hand
 * `Config::get()` has to match, not only the seventeen `AgentKeys` knows.
 */
final class AgentKeyScan
{
    /**
     * The one definition of an agent key as it appears in a call.
     *
     * A key may start with a digit or an underscore. That is not hospitality toward strange names:
     * a scanner that cannot see a key because of how it starts reports a clean zero over a codebase
     * that is not clean.
     */
    public const PATRON = "/get\(\s*'(agent\.[A-Za-z0-9_][A-Za-z0-9._]*)'/";

    /**
     * Every agent key read under a directory, sorted and deduplicated.
     *
     * @param string      $directorio     a package's `src`, or anything under it
     * @param string|null $exceptoArchivo a filename to skip — the declaration itself is not a
     *                                    reader, and charging it would make a comparison pass by
     *                                    comparing a list with itself
     *
     * @return list<string>
     */
    public static function en(string $directorio, ?string $exceptoArchivo = null): array
    {
        if (! is_dir($directorio)) {
            return [];
        }

        $encontradas = [];

        $archivos = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directorio, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($archivos as $archivo) {
            if (! $archivo instanceof \SplFileInfo || $archivo->getExtension() !== 'php') {
                continue;
            }
            if ($exceptoArchivo !== null && $archivo->getFilename() === $exceptoArchivo) {
                continue;
            }

            preg_match_all(self::PATRON, (string) file_get_contents($archivo->getPathname()), $hallazgos);

            foreach ($hallazgos[1] as $llave) {
                $encontradas[$llave] = true;
            }
        }

        $lista = array_keys($encontradas);
        sort($lista);

        return $lista;
    }
}
