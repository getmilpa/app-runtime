<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Config;

use Milpa\AppRuntime\Config\AgentKeys;
use PHPUnit\Framework\TestCase;

/**
 * THE RAIL, without which `AgentKeys` is the same debt better dressed.
 *
 * There was already a hand-written catalogue of these keys — the comment in `config/app.php` — and
 * greenhouse evidence/0144 measured it documenting four of the seventeen the code reads. A list
 * nothing confronts goes stale, and it goes stale quietly, which is the whole defect being fixed.
 * So the declaration is confronted here against the `Config::get('agent.*')` call sites derived from
 * this package's own source, and the two must agree in BOTH directions: a key read and undeclared
 * teaches nobody, and a key declared and never read is a knob that does not exist.
 *
 * Every reader lives in this package today, which is what makes deriving them honest here rather
 * than in a house that would have to guess at somebody else's tree. The day a sibling reads one,
 * this test goes blind rather than wrong — and that limit is stated in greenhouse evidence/0155.
 */
final class AgentKeysAreNotStaleTest extends TestCase
{
    public function testEveryKeyTheCodeReadsIsDeclared(): void
    {
        $leidas = self::leidasPorElCodigo();

        self::assertNotSame([], $leidas, 'no se derivó ninguna lectura: el instrumento, no el hallazgo');
        self::assertSame([], array_values(array_diff($leidas, array_keys(AgentKeys::todas()))));
    }

    public function testEveryDeclaredKeyIsActuallyRead(): void
    {
        self::assertSame(
            [],
            array_values(array_diff(array_keys(AgentKeys::todas()), self::leidasPorElCodigo())),
        );
    }

    /**
     * THE CONTROL, and the only thing that makes the two zeros above readable.
     *
     * A comparison of two identical lists does not prove it can compare. A key is invented that the
     * declaration cannot contain, and the same difference has to catch it.
     */
    public function testTheComparisonCatchesAKeyThatIsNotDeclared(): void
    {
        $inventada = 'agent.' . bin2hex(random_bytes(6));

        self::assertSame(
            [$inventada],
            array_values(array_diff([...self::leidasPorElCodigo(), $inventada], array_keys(AgentKeys::todas()))),
        );
    }

    /** @return list<string> */
    private static function leidasPorElCodigo(): array
    {
        $llaves = [];

        $archivos = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(\dirname(__DIR__, 2) . '/src', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($archivos as $archivo) {
            if (! $archivo instanceof \SplFileInfo || $archivo->getExtension() !== 'php') {
                continue;
            }
            // The declaration itself is not a reader; charging it would make this compare a list
            // with itself and pass no matter what.
            if ($archivo->getFilename() === 'AgentKeys.php') {
                continue;
            }

            preg_match_all(
                "/get\(\s*'(agent\.[A-Za-z][A-Za-z0-9.]*)'/",
                (string) file_get_contents($archivo->getPathname()),
                $encontradas,
            );

            foreach ($encontradas[1] as $llave) {
                $llaves[$llave] = true;
            }
        }

        $lista = array_keys($llaves);
        sort($lista);

        return $lista;
    }
}
