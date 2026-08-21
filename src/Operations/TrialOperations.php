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

namespace Milpa\AppRuntime\Operations;

use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\Command\CommandProvider;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Runtime\Kernel;

/**
 * Promotion is the ONLY door from a trial into the house (greenhouse decisions/0068, 0069 §12).
 *
 * ── WHY PROMOTE IS A MUTATION LIKE ANY OTHER, AND PAUSES ────────────────────────────────────────
 *
 * It declares a CONSERVATIVE ceiling — persistent, executable, manually recoverable — because it
 * writes the house's own files, possibly its code. That ceiling is higher than any trial's, so the
 * gate composes it from what ENTERS and pauses for consent, envelope and all (0067). The trial ran
 * without asking; adopting its consequences is precisely where the human is asked.
 *
 * ── WHY A MOVED TARGET IS REFUSED, NOT MERGED ──────────────────────────────────────────────────
 *
 * If a path the trial touched has changed on the host since the copy, the target moved: the diff no
 * longer describes a change from what is there now. That is a NEW proposal (Rule 1, decisions/0065),
 * and the operation refuses rather than silently overwrite the newer host content.
 */
final class TrialOperations implements CommandProvider
{
    public function __construct(
        private readonly DIContainerInterface $container,
        private readonly ?SessionStore $sessions = null,
        private readonly ?string $root = null,
    ) {
    }

    /**
     * The trial doors: `sandbox:promote` (the only way in), `sandbox:list`, `sandbox:discard`.
     *
     * @return list<Operation>
     */
    public function operations(): array
    {
        $root = $this->root ?? $this->rootFromContainer();
        $sessions = $this->sessions ?? $this->sessionsFromContainer();

        return [
            new Operation(
                name: 'sandbox:promote',
                description: 'Adopt a trial\'s changes into the house — the only door in. Pauses for consent.',
                handler: fn (array $input): array => $this->promote($root, $sessions, $input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'workspace' => ['type' => 'string', 'description' => 'Which trial to promote'],
                        'session' => ['type' => 'string', 'description' => 'The session to record the promotion in'],
                    ],
                    'required' => ['workspace'],
                ],
                mutating: true,
                effects: new EffectProfile(
                    mutation: Mutation::Persistent,
                    externality: Externality::None,
                    reversibility: Reversibility::ManualRecovery,
                    authority: Authority::WriteAsUser,
                    subject: Subject::Executable,
                ),
            ),
            new Operation(
                name: 'sandbox:list',
                description: 'The open trials and what each one changed.',
                handler: fn (array $input): array => $this->list($root),
                inputSchema: ['type' => 'object', 'properties' => []],
                mutating: false,
            ),
            new Operation(
                name: 'sandbox:discard',
                description: 'Erase a trial and everything in it.',
                handler: fn (array $input): array => $this->discard($root, $sessions, $input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'workspace' => ['type' => 'string', 'description' => 'Which trial to erase'],
                        'session' => ['type' => 'string', 'description' => 'The session to record the discard in'],
                    ],
                    'required' => ['workspace'],
                ],
                mutating: true,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function promote(string $root, ?SessionStore $sessions, array $input): array
    {
        $id = \is_string($input['workspace'] ?? null) ? $input['workspace'] : '';
        $ws = $id === '' ? null : TrialWorkspace::open($root, $id);
        if ($ws === null) {
            return ['ok' => false, 'error' => "no trial «{$id}» to promote"];
        }

        $stale = $ws->stale();
        if ($stale !== []) {
            return ['ok' => false, 'error' => 'the target moved since the trial; this is a new proposal', 'stale' => $stale];
        }

        $diff = $ws->diff();
        if ($diff === []) {
            return ['ok' => false, 'error' => 'nothing changed in this trial; there is nothing to promote'];
        }

        $preDir = $ws->baseDirectory() . '/pre';
        $paths = array_keys($diff);
        sort($paths);
        foreach ($paths as $rel) {
            $status = $diff[$rel]['status'];
            $hostFile = $root . '/' . $rel;
            // THE PRE-IMAGE: what the house had, kept before we overwrite it, so a promotion can be
            // undone by hand (the reversibility we declared is manual, and this is the material).
            if (is_file($hostFile)) {
                $this->write($preDir . '/' . $rel, (string) file_get_contents($hostFile));
            }

            if ($status === 'deleted') {
                @unlink($hostFile);
                continue;
            }
            // WRITE-THEN-RENAME: the house never sees a half-written file.
            $this->write($hostFile, (string) file_get_contents($ws->copy . '/' . $rel));
        }

        $this->recordPromotion($sessions, $input, $id, $paths, $diff);

        return ['ok' => true, 'promoted' => $paths];
    }

    /** @return array<string, mixed> */
    private function list(string $root): array
    {
        $trials = [];
        foreach (TrialWorkspace::ids($root) as $id) {
            $ws = TrialWorkspace::open($root, $id);
            if ($ws !== null) {
                $trials[] = ['workspace' => $id, 'changes' => $ws->diff()];
            }
        }

        return ['ok' => true, 'trials' => $trials];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function discard(string $root, ?SessionStore $sessions, array $input): array
    {
        $id = \is_string($input['workspace'] ?? null) ? $input['workspace'] : '';
        $ws = $id === '' ? null : TrialWorkspace::open($root, $id);
        if ($ws === null) {
            return ['ok' => false, 'error' => "no trial «{$id}» to discard"];
        }

        $ws->discard();

        $session = \is_string($input['session'] ?? null) ? $input['session'] : '';
        if ($sessions !== null && $session !== '') {
            $sessions->recordTrialDiscard($session, ['workspace' => $id]);
        }

        return ['ok' => true, 'discarded' => $id];
    }

    /**
     * @param array<string, mixed>                                  $input
     * @param list<string>                                          $paths
     * @param array<string, array{status: string, sha256: ?string}> $diff
     */
    private function recordPromotion(?SessionStore $sessions, array $input, string $id, array $paths, array $diff): void
    {
        $session = \is_string($input['session'] ?? null) ? $input['session'] : '';
        if ($sessions === null || $session === '') {
            return;
        }

        $sessions->recordTrialPromotion($session, [
            'workspace' => $id,
            'paths' => $paths,
            'diff_digest' => hash('sha256', (string) json_encode($diff, \JSON_UNESCAPED_SLASHES)),
            'by' => \is_string($input['by'] ?? null) ? $input['by'] : 'cli',
        ]);
    }

    /** Where this app lives, from its kernel — the trials directory hangs under its var/. */
    private function rootFromContainer(): string
    {
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;

        return $kernel instanceof Kernel ? $kernel->root() : (getcwd() ?: '.');
    }

    /** The session store to record promotions and discards in, or null when this app keeps none. */
    private function sessionsFromContainer(): ?SessionStore
    {
        $store = $this->container->has(SessionStore::class) ? $this->container->get(SessionStore::class) : null;

        return $store instanceof SessionStore ? $store : null;
    }

    private function write(string $path, string $contents): void
    {
        $dir = \dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        $tmp = $path . '.trial-tmp';
        file_put_contents($tmp, $contents);
        rename($tmp, $path);
    }
}
