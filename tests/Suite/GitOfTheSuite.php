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

namespace Milpa\AppRuntime\Tests\Suite;

/**
 * THE SUITE'S GIT IS ITS OWN.
 *
 * A test that builds a repository runs the machine's git, and git reads the machine: its global and system
 * configuration, and whatever the shell that started the suite put in its environment. Measured (greenhouse
 * evidence/1110): on a machine that signs its commits, running this suite signed throwaway commits with the
 * developer's key — and one test hid the failure when the key was locked.
 *
 * So the suite's bootstrap cuts git off from the machine before any test runs, for this process and every process it
 * starts (a child inherits its environment, and so does a trial). No test has to remember anything:
 *
 *  - git reads no global and no system configuration;
 *  - nothing signs — and what asks for a signature outright is answered by a program that only refuses, never by a
 *    key: `false`, whatever the format;
 *  - git is not pointed at another repository by a hook's environment.
 *
 * {@see TheSuiteNeverReachesASigningKeyTest} holds each line of this on a hostile machine built in a temporary
 * directory.
 */
final class GitOfTheSuite
{
    /** Said with the authority of a `-c` on every git the suite runs: over any file, and over any repository's own. */
    private const array NOTHING_SIGNS = [
        'commit.gpgsign' => 'false',
        'tag.gpgsign' => 'false',
        'tag.forceSignAnnotated' => 'false',
        'gpg.program' => 'false',
        'gpg.ssh.program' => 'false',
        'gpg.x509.program' => 'false',
    ];

    /** What a hook, or a `git -c … <command>` around the suite, leaves behind for the gits it starts. */
    private const array OF_ANOTHER_GIT = ['GIT_CONFIG_PARAMETERS', 'GIT_DIR', 'GIT_WORK_TREE', 'GIT_INDEX_FILE'];

    /**
     * Cut git off from the machine, for this process and its children.
     */
    public static function isolate(): void
    {
        putenv('GIT_CONFIG_GLOBAL=/dev/null');
        putenv('GIT_CONFIG_SYSTEM=/dev/null');
        putenv('GIT_CONFIG_COUNT=' . \count(self::NOTHING_SIGNS));
        foreach (array_keys(self::NOTHING_SIGNS) as $at => $key) {
            putenv("GIT_CONFIG_KEY_{$at}={$key}");
            putenv("GIT_CONFIG_VALUE_{$at}=" . self::NOTHING_SIGNS[$key]);
        }
        foreach (self::OF_ANOTHER_GIT as $inherited) {
            putenv($inherited);
        }
    }
}
