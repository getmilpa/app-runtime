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

namespace Milpa\AppRuntime\Tests\Config;

use Milpa\AppRuntime\Config\SecretFiles;
use Milpa\AppRuntime\Standing\StandingLedger;
use PHPUnit\Framework\TestCase;

/**
 * WHAT NO COPY CARRIES HAS ONE AUTHORITY (greenhouse decisions/0625 §2). The rsync way of `TrialWorkspace` once
 * built its own list from the constants, and left out the books the path-by-path walk would have withheld once
 * the rule named them. A copier asks {@see SecretFiles::withheldFromCopy()}, or takes its list from
 * {@see SecretFiles::withheldUnder()} — it never rebuilds the list from the constants.
 *
 * @guards the list a copier takes up front is the rule's own; no code outside SecretFiles names the stores or the
 *         books to build a list of its own
 *
 * @subject-in milpa/app-runtime
 */
final class EveryCopierAsksTheOneRuleTest extends TestCase
{
    public function testTheListTakenUpFrontNamesEveryStoreAndBookAndOnlyWhatTheRuleWithholds(): void
    {
        $root = sys_get_temp_dir() . '/milpa-one-rule-' . bin2hex(random_bytes(4));
        mkdir($root . '/.milpa', 0o777, true);
        file_put_contents($root . '/.env', "A=1\n");
        file_put_contents($root . '/.milpa/secrets.json', '{}');
        try {
            $list = SecretFiles::withheldUnder($root);

            foreach ([...SecretFiles::BEARER_CREDENTIAL_STORES, ...SecretFiles::NON_BEARER_STATE, StandingLedger::PATH, StandingLedger::PATH . StandingLedger::HEAD, '.env', '.milpa/secrets.json'] as $path) {
                self::assertContains($path, $list, "the list a copier takes up front leaves {$path} in the copy");
            }
            foreach ($list as $path) {
                self::assertTrue(SecretFiles::withheldFromCopy($path), "the list names {$path}, which the rule does not withhold");
            }
            self::assertFalse(SecretFiles::withheldFromCopy('config/plugins.php'), 'the control: the rule does not withhold the rest of the house');
            // The rule folds ASCII case only, and `sandbox:promote` carries plain ASCII names only, so a named path
            // must itself be plain lowercase ASCII for the comparison to be exact.
            foreach ($list as $path) {
                self::assertMatchesRegularExpression('#^[a-z0-9._/-]+$#', $path, "{$path} holds a character the fold does not account for");
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    public function testNoCodeOutsideSecretFilesNamesTheStoresOrTheBooks(): void
    {
        $src = \dirname(__DIR__, 2) . '/src';
        $named = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php' || $file->getPathname() === $src . '/Config/SecretFiles.php') {
                continue;
            }
            // Tokens, not text: a comment that cites a constant ({@see …}) is not code that builds a list.
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (\is_array($token) && $token[0] === \T_STRING && \in_array($token[1], ['BEARER_CREDENTIAL_STORES', 'NON_BEARER_STATE'], true)) {
                    $named[] = substr($file->getPathname(), \strlen($src) + 1) . ':' . $token[2] . ' ' . $token[1];
                }
            }
        }

        self::assertSame([], $named, 'code outside SecretFiles names the stores or the books: ask withheldFromCopy() or take withheldUnder()');
    }
}
