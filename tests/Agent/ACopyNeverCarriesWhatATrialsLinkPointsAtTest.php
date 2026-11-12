<?php

/**
 * This file is part of milpa/app-runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\AppRuntime\Agent\TrialRunner;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\AppRuntime\Operations\TrialOperations;
use Milpa\AppRuntime\Support\BootProbe;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\TestCase;

/**
 * A COPY OF THE HOUSE NEVER CARRIES WHAT A TRIAL'S LINK POINTS AT (greenhouse decisions/0632).
 *
 * A trial binds its copy writable, so a file in it can be a symbolic link to any file of the house — the masked ones
 * too. Nothing the house builds from a trial reads through such a link: not the diff and its listing, not the copy a
 * route of the trial is observed on. Each probe has its control: the same bytes written as a plain file ARE seen.
 */
final class ACopyNeverCarriesWhatATrialsLinkPointsAtTest extends TestCase
{
    private string $root;

    private string $canary;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-trial-link-' . bin2hex(random_bytes(4));
        $this->canary = 'canary-envelope-' . bin2hex(random_bytes(6));
        foreach (['src/Plugins/Blog', 'vendor', 'config', 'public', 'var', '.milpa'] as $dir) {
            mkdir($this->root . '/' . $dir, 0o777, true);
        }
        file_put_contents($this->root . '/vendor/autoload.php', '<?php return require ' . var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';');
        file_put_contents($this->root . '/config/plugins.php', '<?php return [];');
        file_put_contents($this->root . '/config/boot.php', '<?php return ["container" => new \Milpa\Container\DIContainer(), "plugins" => []];');
        file_put_contents($this->root . '/config/app.php', '<?php return [];');
        file_put_contents($this->root . '/public/index.php', '<?php echo "ok";');
        file_put_contents($this->root . '/.milpa/secrets.json', '{"note":"' . $this->canary . '"}');
    }

    protected function tearDown(): void
    {
        exec('chmod -R u+w ' . escapeshellarg($this->root) . ' 2>/dev/null; rm -rf ' . escapeshellarg($this->root));
    }

    public function testTheDiffOfATrialNeverReadsThroughALink(): void
    {
        $ws = $this->trial('w1');
        symlink($this->root . '/.milpa/secrets.json', $ws->copy . '/src/Plugins/Blog/leak.php');
        copy($this->root . '/.milpa/secrets.json', $ws->copy . '/src/Plugins/Blog/plain.php');
        $secret = hash_file('sha256', $this->root . '/.milpa/secrets.json');

        $diff = $ws->diff();

        self::assertSame('added', $diff['src/Plugins/Blog/leak.php']['status'] ?? null, 'the link is still seen as a change');
        self::assertNotSame($secret, $diff['src/Plugins/Blog/leak.php']['sha256'] ?? null, 'the diff read through the link');
        self::assertSame(hash('sha256', "milpa-trial-link\0" . $this->root . '/.milpa/secrets.json'), $diff['src/Plugins/Blog/leak.php']['sha256'] ?? null, 'the link is fingerprinted by what it says');
        self::assertSame($secret, $diff['src/Plugins/Blog/plain.php']['sha256'] ?? null, 'the control: a plain file is hashed by its bytes');
        self::assertSame(['src/Plugins/Blog/leak.php'], $ws->links());
    }

    public function testTheListingOfTrialsCarriesNothingOfWhatALinkPointsAt(): void
    {
        $ws = $this->trial('w2');
        symlink($this->root . '/.milpa/secrets.json', $ws->copy . '/src/Plugins/Blog/leak.php');

        $listed = (string) json_encode($this->operation('sandbox:list')([]));

        self::assertStringContainsString('leak.php', $listed, 'the instrument: the trial and its change are listed');
        self::assertStringNotContainsString((string) hash_file('sha256', $this->root . '/.milpa/secrets.json'), $listed);
    }

    public function testATrialThatHoldsALinkIsNotObservedAndNoCopyCarriesItsTarget(): void
    {
        if (!(new TrialRunner())->available()) {
            self::markTestSkipped('this host offers no unprivileged user namespace for bwrap');
        }
        $ws = $this->trial('w3');
        symlink($this->root . '/.milpa/secrets.json', $ws->copy . '/src/Plugins/Blog/leak.php');

        $seen = $this->operation('route:observe')(['path' => '/', 'workspace' => 'w3']);

        self::assertFalse($seen['ok'] ?? true, (string) json_encode($seen));
        self::assertStringContainsString('symbolic link', (string) ($seen['error'] ?? ''));
        self::assertStringContainsString("'src/Plugins/Blog/leak.php'", (string) ($seen['error'] ?? ''));
        self::assertSame([], glob($this->root . '/var/boot-candidates/*') ?: [], 'no copy of the house was built');

        unlink($ws->copy . '/src/Plugins/Blog/leak.php');
        $control = $this->operation('route:observe')(['path' => '/', 'workspace' => 'w3']);
        self::assertStringNotContainsString('symbolic link', (string) json_encode($control), 'the control: without the link the trial is observed');
    }

    public function testWhatIsBuiltFromATrialIsOnlyWhatItsDiffJudged(): void
    {
        $ws = $this->trial('w4');
        file_put_contents($ws->copy . '/src/Plugins/Blog/page.php', 'judged');
        $judged = $ws->diff()['src/Plugins/Blog/page.php']['sha256'] ?? null;

        self::assertSame('judged', $ws->judgedBytes('src/Plugins/Blog/page.php', $judged), 'the control: what was judged is read');

        file_put_contents($ws->copy . '/src/Plugins/Blog/page.php', 'changed after the diff');
        self::assertNull($ws->judgedBytes('src/Plugins/Blog/page.php', $judged), 'bytes that changed after the diff are not carried');

        // A directory swapped for a link to a house directory, after the diff: what it shows now is not what was judged.
        mkdir($this->root . '/.milpa/elsewhere', 0o777, true);
        file_put_contents($this->root . '/.milpa/elsewhere/page.php', $this->canary);
        rename($ws->copy . '/src/Plugins/Blog', $ws->copy . '/src/Plugins/Blog.moved');
        symlink($this->root . '/.milpa/elsewhere', $ws->copy . '/src/Plugins/Blog');
        self::assertNull($ws->judgedBytes('src/Plugins/Blog/page.php', $judged));

        unlink($ws->copy . '/src/Plugins/Blog');
        rename($ws->copy . '/src/Plugins/Blog.moved', $ws->copy . '/src/Plugins/Blog');
        unlink($ws->copy . '/src/Plugins/Blog/page.php');
        symlink($this->root . '/.milpa/secrets.json', $ws->copy . '/src/Plugins/Blog/page.php');
        self::assertNull($ws->judgedBytes('src/Plugins/Blog/page.php', $judged), 'a file that became a link is not read through');
    }

    public function testAHouseLinkKeepsATrialCurrentAndOutOfItsDiff(): void
    {
        // A file link the house itself holds travels as a link, and both sides fingerprint it the same way.
        file_put_contents($this->root . '/public/real.txt', 'house file');
        symlink($this->root . '/public/real.txt', $this->root . '/public/alias.txt');
        $ws = $this->trial('w5');

        self::assertTrue(is_link($ws->copy . '/public/alias.txt'), 'the link reached the copy as a link');
        self::assertSame([], $ws->diff(), 'an untouched house link is no change');
        self::assertSame([], $ws->links());
        self::assertTrue($ws->hasCurrentInputs(), 'a copy with a house link is still current');
    }

    private function trial(string $id): TrialWorkspace
    {
        $ws = TrialWorkspace::materialize($this->root, $id, \dirname(__DIR__) . '/Fixtures/trial-stub-runner.php');
        @mkdir($ws->copy . '/src/Plugins/Blog', 0o777, true);

        return $ws;
    }

    private function operation(string $name): callable
    {
        foreach ((new TrialOperations(new DIContainer(), null, $this->root, bootProbe: new BootProbe()))->operations() as $op) {
            if ($op->name === $name) {
                return $op->handler;
            }
        }
        self::fail('no ' . $name);
    }
}
