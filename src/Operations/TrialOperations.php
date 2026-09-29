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
use Milpa\Command\InvocationContext;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\AppRuntime\Agent\HouseRouteObserver;
use Milpa\AppRuntime\Agent\PluginAuthoringPolicy;
use Milpa\AppRuntime\Agent\KeyedDeclarations;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\AppRuntime\Support\BootProbe;
use Milpa\AppRuntime\Support\CompiledCode;
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
        private readonly ?HouseRouteObserver $observer = new HouseRouteObserver(),
        // Whether the house still boots after a promotion writes (greenhouse decisions/0506); null asks nothing.
        private readonly ?BootProbe $bootProbe = new BootProbe(),
        // Whether the house AS IT WOULD BE is booted before anything is written (decisions/0512); false is the
        // 0506 order — write, ask, roll back — kept as the positive control of the window it leaves.
        private readonly bool $probeBeforeWriting = true,
    ) {
    }

    /**
     * The trial doors: `sandbox:promote` (the only way in), `sandbox:list`, `sandbox:discard`, and
     * `sandbox:undo` (the way back out — reverse a promotion from the pre-image it kept).
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
                handler: fn (array $input, ?InvocationContext $context = null, ?ToolContext $authority = null): array => $this->promote($root, $sessions, $input, $authority),
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
                // A read that says so: ids, manifests and hashes under var/trials, nothing written. Undeclared it
                // carried Unknown on every axis and the governed door asked for it (greenhouse decisions/0227).
                effects: EffectProfile::readOnly(),
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
                // It erases the trial's copy and manifest under var/trials: persistent, reaches nobody, gone for
                // good, as the user, on data — never on the house's code (greenhouse decisions/0227).
                effects: new EffectProfile(
                    Mutation::Persistent,
                    Externality::None,
                    Reversibility::Irreversible,
                    Authority::WriteAsUser,
                    subject: Subject::Data,
                ),
            ),
            new Operation(
                name: 'sandbox:undo',
                description: 'Reverse a promotion from the pre-image it kept, returning the house. Pauses for consent.',
                handler: fn (array $input, ?InvocationContext $context = null, ?ToolContext $authority = null): array => $this->undo($root, $input, $authority),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'workspace' => ['type' => 'string', 'description' => 'Which promoted trial to reverse'],
                        'session' => ['type' => 'string', 'description' => 'The session to record the undo in'],
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
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function promote(string $root, ?SessionStore $sessions, array $input, ?ToolContext $authority = null): array
    {
        $id = \is_string($input['workspace'] ?? null) ? $input['workspace'] : '';
        $ws = $id === '' ? null : TrialWorkspace::open($root, $id);
        if ($ws === null) {
            return ['ok' => false, 'error' => "no trial «{$id}» to promote"];
        }

        $diff = $ws->diff();

        // NO MERGE OF WHAT ACTUALLY COLLIDES (greenhouse decisions/0467, refining 0068). A moved keyed
        // declaration store is judged per key: the keys this trial touched merge into the house when the
        // house did not move them; a key both sides changed is a conflict, named. Any other moved file is
        // a new proposal, exactly as before.
        $merges = [];
        $conflicts = [];
        $stale = [];
        foreach ($ws->stale() as $rel) {
            $merge = KeyedDeclarations::handles($rel) && ($diff[$rel]['status'] ?? null) !== 'deleted'
                ? KeyedDeclarations::merge($root, $ws->baseDirectory(), $ws->copy, $rel, $ws->manifest())
                : null;
            if ($merge === null) {
                $stale[] = $rel;
            } elseif ($merge['conflicts'] !== []) {
                $conflicts[$rel] = $merge['conflicts'];
            } else {
                $merges[$rel] = $merge;
            }
        }
        if ($stale !== [] || $conflicts !== []) {
            return [
                'ok' => false,
                'error' => 'the target moved since the trial; this is a new proposal',
                ...($stale !== [] ? ['stale' => $stale] : []),
                ...($conflicts !== [] ? ['conflicts' => $conflicts] : []),
            ];
        }

        if ($diff === []) {
            return ['ok' => false, 'error' => 'nothing changed in this trial; there is nothing to promote'];
        }

        (new PluginAuthoringPolicy($root))->authorizePaths($authority ?? ToolContext::cli(), array_keys($diff), $ws->copy);
        // Read the judged payload in full before writing anything; a moved source is a new proposal.
        $payload = [];
        foreach ($diff as $path => $entry) {
            if ($entry['status'] !== 'deleted') {
                $bytes = file_get_contents($ws->copy . '/' . $path);
                if ($bytes === false || hash('sha256', $bytes) !== $entry['sha256']) {
                    return ['ok' => false, 'error' => 'the trial changed while preparing its promotion'];
                }
                $payload[$path] = $bytes;
            }
        }
        // What a merged store writes is the house plus the keys this trial touched — and that is what
        // the promotion record carries, so sandbox:undo checks the bytes that actually landed.
        foreach ($merges as $rel => $merge) {
            $payload[$rel] = $merge['bytes'];
            $diff[$rel]['sha256'] = hash('sha256', $merge['bytes']);
        }
        foreach ($ws->stale() as $rel) {
            $current = is_file($root . '/' . $rel) ? (hash_file('sha256', $root . '/' . $rel) ?: null) : null;
            if (! isset($merges[$rel]) || $merges[$rel]['houseSha'] !== $current) {
                return ['ok' => false, 'error' => 'the target moved while preparing its promotion'];
            }
        }
        $paths = array_keys($diff);
        sort($paths);

        // PROBE BEFORE WRITING (greenhouse decisions/0512). 0506 asked after the write and rolled back — and for
        // the ~0.1–0.3 s its witness took, the broken file was on disk: a server that revalidates every request
        // served one fatal answer in ~60 (evidence/1039, B5). So the house AS IT WOULD BE is built beside it and
        // booted first; a promotion it cannot boot with never touches the live tree. The ask after the write
        // stays below, for what a candidate cannot see (a boot that depends on the house's own `var/`).
        $wouldBreak = $this->probeBeforeWriting && is_file($root . '/vendor/autoload.php')
            ? $this->bootProbe?->whyNotWith($root, $payload, array_keys(array_filter($diff, static fn (array $entry): bool => $entry['status'] === 'deleted')))
            : null;
        if ($wouldBreak !== null) {
            return $this->refuseUnwritten($root, $paths, $wouldBreak);
        }

        $preDir = $ws->baseDirectory() . '/pre';
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
            $this->write($hostFile, $payload[$rel]);
        }

        // THE NEXT REQUEST RUNS WHAT LANDED (greenhouse decisions/0506): when this process is the server, its
        // OPcache would otherwise serve the old bytecode for up to `revalidate_freq` seconds (evidence/1038, o4).
        CompiledCode::forget($root, $paths);

        // A PROMOTION THAT THE HOUSE CANNOT BOOT WITH DOES NOT LAND (greenhouse decisions/0506). Measured on
        // the published train (evidence/1036, R1, seq 442): a seeder whose constructor wanted a repository its
        // plugin did not pass was promoted, the receipt said `ok: true` beside «the house did not boot», and
        // from then on the panel answered 500 and EVERY `coa` died at boot — undo and disable-unsafe included,
        // and the resident's own next leg. Only a hand copy of the pre-image brought the house back. So the
        // house is asked, in a process of its own, right after the write; if it does not boot, the pre-image
        // goes back in, the trial is kept to be fixed, and the answer is a refusal that says why.
        $broken = is_file($root . '/vendor/autoload.php') ? $this->bootProbe?->whyNot($root) : null;
        if ($broken !== null) {
            return $this->rollBack($root, $ws->baseDirectory(), $paths, $broken);
        }

        $this->recordPromotion($sessions, $input, $id, $paths, $diff);

        // What sandbox:undo reads to reverse this: the diff, whose sha256 per path is exactly the
        // content the promotion just wrote to the host (0069). It outlives collapse() with the pre-image.
        $this->write($ws->baseDirectory() . '/promoted.json', (string) json_encode($diff, \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT));

        // DISCARD-ON-PROMOTE (decisions/0071): the consequence has crossed the door (0068); the copy
        // is spent. Collapse it — free the ~656 KB, keep the tiny pre-image for manual undo (0069).
        $ws->collapse();

        // THE HOUSE LOOKS AT WHAT LANDED (greenhouse decisions/0494). A route is code, and this process booted
        // before that code existed — so a fresh process of the house requests the GET routes the touched
        // plugins declare, through its own front controller, and the receipt says what the house answered.
        $observation = $this->observer?->observe($root, $paths) ?? ['observed' => []];

        // THE PROMOTION EARNS ITS OWN VERB (greenhouse decisions/0463). Its receipt says what crossed
        // and where from; it does NOT carry forward what the trial observed. «Served in the copy» stays
        // a fact about the copy, and a fact about the house is observed in the house.
        return [
            'ok' => true,
            'promoted' => $paths,
            // Which keys of a moved declaration store were joined into the house (decisions/0467).
            ...($merges !== [] ? ['merged' => array_map(static fn (array $merge): array => $merge['touched'], $merges)] : []),
            'evidence' => [
                'predicate' => 'promoted',
                'subject' => $id,
                'environment' => ['kind' => 'house'],
                'from' => ['kind' => 'trial', 'workspace' => $id],
                'paths' => $paths,
            ],
            ...($observation['observed'] !== [] ? ['observed' => $observation['observed']] : []),
            ...(isset($observation['error']) ? ['observation_error' => $observation['error']] : []),
            'note' => 'Promoted into the house. What the trial observed (served, passed) was observed in the '
                . 'copy; observe it here before claiming it about the house.' . self::whatTheHouseSaw($observation),
        ];
    }

    /**
     * Refuse a promotion the house would not boot with — nothing was written, so nothing is put back.
     *
     * The trial is kept (its copy is what the author fixes) and no pre-image was taken. The live house is
     * asked too, only here, so the answer can say whether it boots as it is — a promotion that would have
     * FIXED a broken house boots in its candidate and lands.
     *
     * @param list<string> $paths
     *
     * @return array<string, mixed>
     */
    private function refuseUnwritten(string $root, array $paths, string $broken): array
    {
        $now = $this->bootProbe?->whyNot($root);

        return [
            'ok' => false,
            'error' => 'the house does not boot with this promotion: ' . $broken,
            'unwritten' => $paths,
            'house_boots' => $now === null,
            'note' => 'Nothing was written: the house was booted as it would be, beside it, and did not boot — the live '
                . 'files were never touched. The trial is kept; fix it there and promote again.' . ($now === null
                    ? ' The house boots as it is.'
                    : ' The house does not boot as it is either: ' . $now . '.'),
        ];
    }

    /**
     * Put back what a promotion just wrote, from the pre-image it kept — the promotion never landed.
     *
     * The trial is NOT collapsed: its copy is what the author fixes and promotes again. Its pre-image is
     * removed, so a later promotion of the same trial keeps a fresh one of the house as it is then.
     *
     * @param list<string> $paths
     *
     * @return array<string, mixed>
     */
    private function rollBack(string $root, string $base, array $paths, string $broken): array
    {
        $preDir = $base . '/pre';
        foreach ($paths as $rel) {
            if (is_file($preDir . '/' . $rel)) {
                $this->write($root . '/' . $rel, (string) file_get_contents($preDir . '/' . $rel));
            } else {
                @unlink($root . '/' . $rel); // the promotion added it; the house never had it
            }
        }
        CompiledCode::forget($root, $paths);
        exec('rm -rf ' . escapeshellarg($preDir));
        $after = $this->bootProbe?->whyNot($root);

        return [
            'ok' => false,
            'error' => 'the house does not boot with this promotion: ' . $broken,
            'rolled_back' => $paths,
            'house_boots' => $after === null,
            'note' => 'Nothing landed: every file this promotion wrote is back as it was, and the trial is kept — fix it '
                . 'there and promote again.' . ($after === null
                    ? ' The house boots as it did before.'
                    : ' The house did not boot before this promotion either: ' . $after . '.'),
        ];
    }

    /**
     * The house's own observation of what landed, in one sentence for the note — empty when it asked nothing.
     *
     * @param array{observed: list<array<string, mixed>>, error?: string, unobserved?: int} $observation
     */
    private static function whatTheHouseSaw(array $observation): string
    {
        if (isset($observation['error'])) {
            return ' The house could not be observed: ' . $observation['error'] . '.';
        }
        if ($observation['observed'] === []) {
            return '';
        }
        $answers = array_map(
            static fn (array $entry): string => $entry['route'] . ' answered ' . ($entry['status'] === null ? 'nothing' : 'HTTP ' . $entry['status']),
            $observation['observed'],
        );

        return ' The house requested the routes this promotion declares, the way a browser does: ' . implode('; ', $answers)
            . (isset($observation['unobserved']) ? "; {$observation['unobserved']} more were not requested" : '') . '.';
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
     * Reverse a promotion, the way back out that makes its ManualRecovery real (0069).
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function undo(string $root, array $input, ?ToolContext $authority = null): array
    {
        $id = \is_string($input['workspace'] ?? null) ? $input['workspace'] : '';
        if ($id === '') {
            return ['ok' => false, 'error' => 'no trial to undo'];
        }

        $tool = new \Milpa\ToolRuntime\ToolDefinition('sandbox_undo', '', [], static fn (): null => null, mutating: true);
        $verdict = (new PluginAuthoringPolicy($root))->authorize($authority ?? ToolContext::cli(), $tool, $input);
        if (!$verdict->allowed) {
            return ['ok' => false, 'error' => (string) $verdict->reason];
        }
        return TrialWorkspace::undo($root, $id);
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

        return $kernel instanceof Kernel
            ? $kernel->root()
            : \Milpa\AppRuntime\Support\AppRoot::of($this->container, 'TrialOperations');
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
