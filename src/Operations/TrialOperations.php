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
use Milpa\AppRuntime\Agent\AppliedTrials;
use Milpa\AppRuntime\Agent\HouseRouteObserver;
use Milpa\AppRuntime\Agent\RouteFailureCause;
use Milpa\AppRuntime\Agent\PluginAuthoringPolicy;
use Milpa\AppRuntime\Agent\RouteRegression;
use Milpa\AppRuntime\Agent\KeyedDeclarations;
use Milpa\AppRuntime\Agent\TrialWorkspace;
use Milpa\AppRuntime\Support\BootCandidate;
use Milpa\AppRuntime\Support\BootProbe;
use Milpa\AppRuntime\Support\HouseBootWitness;
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
use Milpa\ToolRuntime\Identity\GrantedAuthorization;

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
    /** The methods `route:observe` requests with; any but GET only in a confined rehearsal (decisions/0549 §9). */
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /** The most bytes a request `route:observe` makes may send. */
    private const BODY_MAX = 65536;

    /** The most GET paths observed after a request, in the same copy. */
    private const THEN_MAX = 4;

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
        // Whether the routes the promotion touches still answer, asked in the same copy before the write (decisions/0540);
        // null asks only the boot.
        private readonly ?RouteRegression $routes = new RouteRegression(),
    ) {
    }

    /**
     * The trial doors: `sandbox:promote` (the only way in), `sandbox:list`, `sandbox:discard`, and
     * `sandbox:undo` (the way back out — reverse a promotion from the pre-image it kept) — and `route:observe`,
     * the house asked what one of its routes answers, or what it would answer with a trial (decisions/0549).
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
            // WHAT THE HOUSE APPLIES ON ITS OWN IS A PERSON'S SIGNED ACT (greenhouse decisions/0586). The house lands
            // the verified trial of an admitted operation without any call of the model asking for it, so which
            // operations those are is said here, one at a time, under the signature that names who decides — the
            // scope of recognizing an identity, because it is the same kind of act. Never on the surface a seat works
            // through: a seat does not decide what the house does on its own. A house is born with none.
            new Operation(
                name: 'sandbox:admit',
                description: 'Admit an operation whose verified trial the house applies on its own — one at a time, or everything the house knows how to apply, over the digest of the list `sandbox:admitted` shows. A person\'s signed act (greenhouse decisions/0586)',
                handler: fn (array $input): array => $this->decideWhatTheHouseApplies($root, $input, admit: true),
                inputSchema: self::ADMIT_INPUT,
                scopes: ['identity:enroll'],
                surfaces: ['cli'],
                mutating: true,
                requiresConfirmation: true,
                effects: new EffectProfile(
                    mutation: Mutation::Persistent,
                    externality: Externality::None,
                    // Withdrawing it is the way back; what the house applied meanwhile stays applied.
                    reversibility: Reversibility::Compensatable,
                    // Deciding what the house does without being asked is an institutional act.
                    authority: Authority::Privileged,
                    subject: Subject::Configuration,
                ),
            ),
            new Operation(
                name: 'sandbox:withdraw',
                description: 'Withdraw an admission: from the next call the model applies that operation\'s trials again — a person\'s signed act (greenhouse decisions/0586)',
                handler: fn (array $input): array => $this->decideWhatTheHouseApplies($root, $input, admit: false),
                inputSchema: self::ADMISSION_INPUT,
                scopes: ['identity:enroll'],
                surfaces: ['cli'],
                mutating: true,
                requiresConfirmation: true,
                effects: new EffectProfile(
                    mutation: Mutation::Persistent,
                    externality: Externality::None,
                    reversibility: Reversibility::Compensatable,
                    authority: Authority::Privileged,
                    subject: Subject::Configuration,
                ),
            ),
            new Operation(
                name: 'sandbox:admitted',
                description: 'Which operations this house applies on its own once their trial verifies, who admitted each and when — and which can be admitted (greenhouse decisions/0586)',
                handler: fn (array $input): array => self::whatTheHouseApplies($root),
                inputSchema: ['type' => 'object', 'properties' => []],
                surfaces: ['cli'],
                mutating: false,
                effects: EffectProfile::readOnly(),
            ),
            // THE HOUSE ASKED, WHEN THE RESIDENT WANTS TO KNOW (greenhouse decisions/0549). Measured on a copy of Rod's
            // first live run (t-0074, B-c): /blog answered 500, and the resident read its controller and wrote «GET /blog
            // → 200» — the house only requested a route when a promotion landed. A read in every sense the gate weighs:
            // it is a visitor's request through the house's own front controller, with nobody's credentials, so it needs
            // no consent and no signature. What it hands back is NOT a visitor's, though: the cause is for the agent only
            // (0506, 0539), so it carries the scopes of the readers of the agent's own receipts (`agent:result`) — the
            // seat holds `agent:read`, and no surface serves it to an anonymous caller without a policy judging them.
            //
            // A REQUEST THAT WRITES IS ONLY EVER A REHEARSAL'S (0549 §9). A POST on the live house would be a mutation
            // that no operation's judgment weighed; in a copy, confined so that nothing but the copy can be written, it is
            // the verification a POST route needs — and `then` reads, in that same copy, what it wrote.
            new Operation(
                name: 'route:observe',
                description: 'Request a path of THIS HOUSE the way a browser does — anonymously, through its own front '
                    . 'controller — and see what it answers: the status, an excerpt of the body and, on a 5xx or a request that '
                    . 'died, the cause the house logged (never shown to visitors). This is how you confirm a route serves; reading '
                    . 'its code does not tell you. Give a concrete path (/<path>, /<path>/7?page=2), never a pattern like /<path>/{id}. '
                    . 'With `workspace`, it asks the house as that trial would leave it. A POST, PUT, PATCH or DELETE (with a '
                    . '`body`) is observed ONLY with `workspace`, in a confined copy that is thrown away, and `then` lists GET paths '
                    . 'to observe in that same copy afterwards — to see what the request wrote. Read-only for the house.',
                handler: fn (array $input): array => $this->observeRoute($root, $input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'The path to request, starting with / — e.g. /<path>'],
                        'workspace' => ['type' => 'string', 'description' => 'A trial to ask instead of the house'],
                        'method' => ['type' => 'string', 'enum' => self::METHODS, 'description' => 'GET by default; any other only with workspace'],
                        'body' => ['type' => 'string', 'description' => 'What the request sends, at most ' . self::BODY_MAX . ' bytes; not on a GET'],
                        'content_type' => ['type' => 'string', 'description' => 'The body\'s type: application/json by default'],
                        'then' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Up to ' . self::THEN_MAX
                            . ' GET paths observed after it, in the same copy (workspace only)'],
                        'excerpt' => ['type' => 'integer', 'description' => 'Bytes of each body to hand back: default '
                            . HouseRouteObserver::EXCERPT . ', at most ' . HouseRouteObserver::EXCERPT_MAX . ', 0 for none'],
                    ],
                    'required' => ['path'],
                ],
                mutating: false,
                scopes: ['agent:read', 'agent:answer'],
                effects: EffectProfile::readOnly(),
            ),
        ];
    }

    /**
     * What the house — or the house as a trial would leave it — answers one request (decisions/0549).
     *
     * `ok: true` means the house was asked; what it answered, a 500 included, is the observation. In the house the
     * entry is the one a promotion records, so the derived closure reads it as the house's own (0487, 0494); asked
     * of a trial it is `environment: trial` and observes nothing about the house.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function observeRoute(string $root, array $input): array
    {
        $path = $input['path'] ?? null;
        if (\is_string($path) && (str_contains($path, '{') || str_contains($path, '}'))) {
            return ['ok' => false, 'error' => "«{$path}» is a route's pattern, not a path of this house: give the value a visitor "
                . 'would — e.g. /<path>/1 for /<path>/{id}'];
        }
        if (!self::isPath($path)) {
            return ['ok' => false, 'error' => 'path must be a path of this house, starting with «/» — e.g. /<path> — with no scheme, '
                . 'host, fragment or spaces'];
        }
        // A FORM IS NOT A PATH (greenhouse decisions/0594 §5). The contract shows a path by its form, and an example is
        // copied letter for letter (evidence/1071, B11). Requested as written it answered a 404 that says nothing: the
        // house has no such route because nobody could have one.
        foreach ([$path, ...(\is_array($input['then'] ?? null) ? array_filter($input['then'], '\is_string') : [])] as $asked) {
            if (preg_match('/<[^<>\s]+>/', $asked, $form) === 1) {
                return ['ok' => false, 'error' => "«{$asked}» is the form the contract shows, not a path of this house: write the path "
                    . "itself where «{$form[0]}» is"];
            }
        }
        $asked = self::request($input);
        if (\is_string($asked)) {
            return ['ok' => false, 'error' => $asked];
        }
        ['method' => $method, 'body' => $body, 'type' => $type, 'then' => $then] = $asked;
        $excerpt = \is_int($input['excerpt'] ?? null) ? $input['excerpt'] : HouseRouteObserver::EXCERPT;
        $observer = $this->observer ?? new HouseRouteObserver();

        $id = \is_string($input['workspace'] ?? null) ? $input['workspace'] : '';
        if ($id === '') {
            if ($method !== 'GET' || $then !== []) {
                return ['ok' => false, 'error' => "a {$method} is observed only in a rehearsal (`workspace`): on the live house it would "
                    . 'write without the judgment of the operation that writes. Give the trial to ask, or promote and observe with GET'];
            }
            if (!is_file($root . '/public/index.php') || !is_file($root . '/vendor/autoload.php')) {
                return ['ok' => false, 'error' => 'this house has no front controller (public/index.php) to request a route through'];
            }

            return self::observedRoute([$observer->observeRoute($root, $path, $excerpt)], null);
        }

        $ws = str_contains($id, '/') || str_contains($id, '\\') || str_contains($id, '..') ? null : TrialWorkspace::open($root, $id);
        if ($ws === null) {
            return ['ok' => false, 'error' => "no trial «{$id}» to observe"];
        }
        // A request that may write runs where nothing but its copy can be written: the copy LINKS the house's vendor/ and
        // secrets, and a write through a link would land in the live house (decisions/0549 §9).
        $confined = $method !== 'GET' || $then !== [];
        if ($confined && !$observer->confines()) {
            return ['ok' => false, 'error' => "a {$method} is observed only where its copy can be confined, and this host cannot "
                . 'confine one (bubblewrap with a user namespace, as the trials use)'];
        }
        // The house AS THE TRIAL WOULD LEAVE IT, built beside it — the trial's own copy has no vendor/ to boot with.
        $writes = [];
        $deletes = [];
        foreach ($ws->diff() as $rel => $entry) {
            if ($entry['status'] === 'deleted') {
                $deletes[] = $rel;
            } else {
                $writes[$rel] = (string) file_get_contents($ws->copy . '/' . $rel);
            }
        }
        $candidate = BootCandidate::of($root, $writes, $deletes);
        try {
            $seen = [$observer->observeRoute($candidate->path, $path, $excerpt, $method, $body, $type, $confined)];
            foreach ($then as $next) {
                $seen[] = $observer->observeRoute($candidate->path, $next, $excerpt, 'GET', null, null, true);
            }
        } finally {
            $candidate->remove();
        }
        // Each cause was read against the copy's root: the copy's files read as the house's, any other path is cut (0539).
        foreach ($seen as $i => $one) {
            $seen[$i]['entry']['environment'] = ['kind' => 'trial', 'workspace' => $id];
        }

        return self::observedRoute($seen, $id);
    }

    /** Whether `$path` is a concrete path of this house: a leading `/`, no host, fragment, space or control. */
    private static function isPath(mixed $path): bool
    {
        return \is_string($path) && \strlen($path) <= 2048 && preg_match('~^/(?!/)[^\s#{}\x00-\x1f\x7f]*$~', $path) === 1;
    }

    /**
     * The request beyond its path — method, body, its type and the GETs after it — or why it is not one.
     *
     * @param array<string, mixed> $input
     *
     * @return array{method: string, body: ?string, type: ?string, then: list<string>}|string
     */
    private static function request(array $input): array|string
    {
        $method = strtoupper(\is_string($input['method'] ?? null) ? $input['method'] : 'GET');
        if (!\in_array($method, self::METHODS, true)) {
            return 'method must be one of ' . implode(', ', self::METHODS);
        }
        $body = $input['body'] ?? null;
        if ($body !== null && !\is_string($body)) {
            return 'body must be a string';
        }
        if ($body !== null && $method === 'GET') {
            return 'a GET carries no body: give method POST, PUT, PATCH or DELETE';
        }
        if ($body !== null && \strlen($body) > self::BODY_MAX) {
            return 'body must be at most ' . self::BODY_MAX . ' bytes';
        }
        $type = $input['content_type'] ?? ($body !== null ? 'application/json' : null);
        if ($type !== null && (!\is_string($type) || preg_match('~^[\w.+-]+/[\w.+-]+(?:;[^\x00-\x1f\x7f]*)?$~', $type) !== 1)) {
            return 'content_type must be a media type, e.g. application/json';
        }
        $then = $input['then'] ?? [];
        if (!\is_array($then) || !array_is_list($then) || \count($then) > self::THEN_MAX || array_filter($then, static fn (mixed $p): bool => !self::isPath($p)) !== []) {
            return 'then must be a list of at most ' . self::THEN_MAX . ' GET paths of this house, e.g. ["/<path>"]';
        }

        /** @var list<string> $then */
        return ['method' => $method, 'body' => $body, 'type' => $type, 'then' => $then];
    }

    /**
     * The result of `route:observe`: the entries under `observed`, the first excerpt beside them, one sentence each.
     *
     * @param non-empty-list<array{entry: array<string, mixed>, excerpt: ?string, truncated: int}> $seen
     *
     * @return array<string, mixed>
     */
    private static function observedRoute(array $seen, ?string $workspace): array
    {
        $said = [];
        $guarded = '';
        foreach ($seen as $one) {
            $entry = $one['entry'];
            $status = \is_int($entry['status'] ?? null) ? $entry['status'] : null;
            $line = $entry['route'] . ' answered ' . ($status === null ? 'nothing (' . (string) ($entry['error'] ?? 'no status') . ')' : 'HTTP ' . $status);
            if (\is_array($entry['cause'] ?? null)) {
                $line .= ' — ' . RouteFailureCause::oneLine($entry['cause']);
            } elseif ($status === null || $status >= 500) {
                $line .= ' (the house logged no cause this observer could read)';
            }
            $said[] = $line;
            if ($status !== null && ($status === 401 || $status === 403 || ($status >= 300 && $status < 400))) {
                $guarded = ' A route that asks who you are answers an anonymous visitor this way: nobody\'s credentials travel.';
            }
        }
        $who = $workspace === null
            ? 'Requested in this house'
            : "Requested in a copy of this house as trial «{$workspace}» would leave it — not the house itself, and thrown away after";
        $first = $seen[0];
        $after = array_map(static fn (array $one): array => ['route' => $one['entry']['route']]
            + ($one['excerpt'] !== null ? ['excerpt' => $one['excerpt']] : [])
            + ($one['truncated'] > 0 ? ['truncated' => $one['truncated']] : []), \array_slice($seen, 1));

        return [
            'ok' => true,
            'observed' => array_map(static fn (array $one): array => $one['entry'], $seen),
            ...($first['excerpt'] !== null ? ['excerpt' => $first['excerpt']] : []),
            ...($first['truncated'] > 0 ? ['truncated' => $first['truncated']] : []),
            ...($after !== [] ? ['then' => $after] : []),
            'note' => implode('; then ', $said) . '. ' . $who . ', through its front controller, as an anonymous visitor.' . $guarded,
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
            // ALREADY PROMOTED IS AN ANSWER, NOT AN ERROR (greenhouse decisions/0586). When the house applied the
            // verified trial of an admitted operation, a model that then asks for the same promotion is told so.
            // Nothing is written again, and nothing here says the house changed: no `promoted`, no receipt, no record.
            $already = $id === '' ? null : TrialWorkspace::promotedPaths($root, $id);
            if ($already !== null) {
                return [
                    'ok' => true,
                    self::ALREADY_PROMOTED => true,
                    'workspace' => $id,
                    'paths' => $already,
                    'note' => 'This trial was already promoted into the house; nothing was written again.',
                ];
            }

            return ['ok' => false, 'error' => "no trial «{$id}» to promote"];
        }

        $diff = $ws->diff();
        // A TRIAL NEVER CARRIES WHAT THE HOUSE APPLIES ON ITS OWN (greenhouse decisions/0586). That list is a
        // person's act; a seat does not write it through a rehearsal, whatever it may write.
        if (isset($diff[AppliedTrials::PATH])) {
            return ['ok' => false, 'error' => 'a trial never carries the list of what the house applies on its own — a person admits an operation with sandbox:admit; nothing was promoted'];
        }

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

        // ONE RULE, IN ONE PLACE (greenhouse decisions/0515, Rod's answer 4). The promotion writes through the same
        // witness as every other writer of what the kernel boots from:
        //   - BEFORE the write (0512): the house AS IT WOULD BE is built beside it and booted; a promotion it cannot
        //     boot with never touches the live tree — for the ~0.1–0.3 s 0506's witness took, the broken file was
        //     on disk and a server that revalidates every request served one fatal answer in ~60 (evidence/1039, B5);
        //   - AFTER the write (0506): the house is asked in a process of its own — measured on the published train
        //     (evidence/1036, R1, seq 442), a promotion the house could not boot with answered `ok: true` and from
        //     then on EVERY `coa` died at boot; if it does not boot now, what was written is put back.
        // `probeBeforeWriting: false` is 0506's order, kept as the positive control of the window.
        $deletes = array_keys(array_filter($diff, static fn (array $entry): bool => $entry['status'] === 'deleted'));
        $preDir = $ws->baseDirectory() . '/pre';
        $land = function () use ($root, $paths, $diff, $payload, $preDir): void {
            foreach ($paths as $rel) {
                $hostFile = $root . '/' . $rel;
                // THE PRE-IMAGE: what the house had, kept before we overwrite it, so a promotion can be
                // undone (the reversibility we declared is manual, and this is the material).
                if (is_file($hostFile)) {
                    $this->write($preDir . '/' . $rel, (string) file_get_contents($hostFile));
                }
                if ($diff[$rel]['status'] === 'deleted') {
                    @unlink($hostFile);
                    continue;
                }
                // WRITE-THEN-RENAME: the house never sees a half-written file.
                $this->write($hostFile, $payload[$rel]);
            }
            // THE NEXT REQUEST RUNS WHAT LANDED (0506): when this process is the server, its OPcache would otherwise
            // serve the old bytecode for up to `revalidate_freq` seconds (evidence/1038, o4).
            CompiledCode::forget($root, $paths);
        };
        // A ROUTE IT BREAKS IS A REFUSAL TOO (greenhouse decisions/0540). The house booted with this promotion in the copy
        // — and in that same copy, before the write, the touched plugins' GET routes are requested. Measured in Rod's
        // first live run (evidence/1071, B3, seq 291): the house booted, GET /blog went 200 → 500, and the receipt said
        // `ok: true`. A route that answered without the promotion and answers 5xx with it — or is new and born 5xx —
        // is the promotion's doing: nothing is written.
        $routes = ['regressed' => [], 'unjudged' => []];
        $judge = $this->routes === null ? null : function (string $candidate) use ($root, $paths, &$routes): ?string {
            $routes = $this->routes->judge($root, $candidate, $paths);

            return $routes['regressed'] === [] ? null : RouteRegression::sentence($routes['regressed']);
        };
        if ($this->bootProbe === null) {
            $land();
        } else {
            $boot = (new HouseBootWitness($root, $this->bootProbe, $this->probeBeforeWriting))
                ->writeIfItBoots(array_diff_key($payload, array_flip($deletes)), $land, recovery: false, deletes: $deletes, judge: $judge);
            if (isset($boot['said']['judged'])) {
                return $this->refuseBrokenRoutes($paths, $routes['regressed']);
            }
            if ($boot['refused'] !== null) {
                return isset($boot['said']['rolled_back'])
                    ? $this->rolledBack($preDir, $paths, $boot['said'])
                    : $this->refuseUnwritten($paths, $boot['said']);
            }
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
        // THE HOUSE SEEDS WHAT WAS DECLARED (greenhouse decisions/0574). A trial cannot write the house's store — it
        // lives in var/, which no trial copies or promotes — so the rows of a seed declaration that just landed are
        // saved now, by a fresh process of the house, BEFORE it looks at the pages that list them.
        $sown = $this->observer?->seed($root, $paths) ?? ['seeded' => []];

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
            ...($sown['seeded'] !== [] ? ['seeded' => $sown['seeded']] : []),
            ...(isset($sown['error']) ? ['seed_error' => $sown['error']] : []),
            ...($observation['observed'] !== [] ? ['observed' => $observation['observed']] : []),
            // WHAT THE BUILT CAPABILITIES NOW DECLARE (greenhouse decisions/0595) — under a key of its own: a reader that
            // does not know it keeps reading `observed` as routes, and a capability is never taken for one.
            ...(($observation['capabilities'] ?? []) !== [] ? ['capabilities' => $observation['capabilities']] : []),
            // Routes that answered 5xx in the copy WITH the promotion and WITHOUT it too: not its doing, so not refused (0540).
            ...($routes['unjudged'] !== [] ? ['unjudged' => $routes['unjudged']] : []),
            ...(isset($observation['error']) ? ['observation_error' => $observation['error']] : []),
            'note' => 'Promoted into the house. What the trial observed (served, passed) was observed in the '
                . 'copy; observe it here before claiming it about the house.' . self::whatTheHouseSowed($sown['seeded']) . self::whatTheHouseSaw($observation),
        ];
    }

    /**
     * Refuse a promotion the house would not boot with — nothing was written, so nothing is put back.
     *
     * The trial is kept (its copy is what the author fixes) and no pre-image was taken. The witness asked the
     * live house too, only on this path, so the answer can say whether it boots as it is — a promotion that
     * would have FIXED a broken house boots in its candidate and lands.
     *
     * @param list<string>         $paths
     * @param array<string, mixed> $said  what the witness said: `reason`, `house_boots`
     *
     * @return array<string, mixed>
     */
    private function refuseUnwritten(array $paths, array $said): array
    {
        $boots = ($said['house_boots'] ?? false) === true;

        return [
            'ok' => false,
            'error' => 'the house does not boot with this promotion: ' . (string) ($said['reason'] ?? 'unknown'),
            'unwritten' => $paths,
            'house_boots' => $boots,
            'note' => 'Nothing was written: the house was booted as it would be, beside it, and did not boot — the live '
                . 'files were never touched. The trial is kept; fix it there and promote again.' . ($boots
                    ? ' The house boots as it is.'
                    : ' The house does not boot as it is either.'),
        ];
    }

    /**
     * Refuse a promotion that breaks a route of the house — it boots with it, and nothing was written (decisions/0540).
     *
     * @param list<string>               $paths
     * @param list<array<string, mixed>> $regressed rows of {@see RouteRegression::compare()}
     *
     * @return array<string, mixed>
     */
    private function refuseBrokenRoutes(array $paths, array $regressed): array
    {
        return [
            'ok' => false,
            'error' => RouteRegression::sentence($regressed),
            'regressed' => $regressed,
            'unwritten' => $paths,
            'house_boots' => true,
            'note' => 'Nothing was written: the house was built as it would be, beside it, and it booted — but the routes '
                . 'this promotion touches were requested there, and one it did not break before answers a server error with '
                . 'it. The trial is kept; fix it there and promote again.',
        ];
    }

    /**
     * A promotion the witness put back after the write — the promotion never landed.
     *
     * The trial is NOT collapsed: its copy is what the author fixes and promotes again. Its pre-image is
     * removed, so a later promotion of the same trial keeps a fresh one of the house as it is then.
     *
     * @param list<string>         $paths
     * @param array<string, mixed> $said  what the witness said: `reason`, `house_boots`
     *
     * @return array<string, mixed>
     */
    private function rolledBack(string $preDir, array $paths, array $said): array
    {
        exec('rm -rf ' . escapeshellarg($preDir));
        $boots = ($said['house_boots'] ?? false) === true;

        return [
            'ok' => false,
            'error' => 'the house does not boot with this promotion: ' . (string) ($said['reason'] ?? 'unknown'),
            'rolled_back' => $paths,
            'house_boots' => $boots,
            'note' => 'Nothing landed: every file this promotion wrote is back as it was, and the trial is kept — fix it '
                . 'there and promote again.' . ($boots
                    ? ' The house boots as it did before.'
                    : ' The house did not boot before this promotion either.'),
        ];
    }

    /**
     * The house's own observation of what landed, in one sentence for the note — empty when it asked nothing.
     *
     * @param array{observed: list<array<string, mixed>>, capabilities?: list<array<string, mixed>>, error?: string, unobserved?: int} $observation
     */
    private static function whatTheHouseSaw(array $observation): string
    {
        if (isset($observation['error'])) {
            return ' The house could not be observed: ' . $observation['error'] . '.';
        }
        $declared = self::whatTheHouseSawDeclared($observation['capabilities'] ?? []);
        if ($observation['observed'] === []) {
            return $declared;
        }
        $answers = array_map(
            static fn (array $entry): string => $entry['route'] . ' answered ' . ($entry['status'] === null ? 'nothing' : 'HTTP ' . $entry['status'])
                // Why it failed, as the house logged it — here and never on the page a visitor gets (decisions/0539).
                . (\is_array($entry['cause'] ?? null) ? ' — ' . RouteFailureCause::oneLine($entry['cause'])
                    : (($entry['status'] ?? 0) >= 500 || isset($entry['error']) ? ' (the house logged no cause this observer could read)' : '')),
            $observation['observed'],
        );

        return ' The house requested the routes this promotion declares, the way a browser does: ' . implode('; ', $answers)
            . (isset($observation['unobserved']) ? "; {$observation['unobserved']} more were not requested" : '') . '.' . $declared;
    }

    /**
     * What the house read of the capabilities built where this landed, in one sentence — it read their declarations
     * and called nothing (greenhouse decisions/0595).
     *
     * @param list<array<string, mixed>> $capabilities
     */
    private static function whatTheHouseSawDeclared(array $capabilities): string
    {
        $said = [];
        foreach ($capabilities as $capability) {
            $operations = \is_array($capability['operations'] ?? null) ? $capability['operations'] : [];
            $said[] = sprintf('«%s» declares %d operation%s', (string) ($capability['subject'] ?? '?'), \count($operations), \count($operations) === 1 ? '' : 's');
        }

        return $said === [] ? '' : ' The house read what the capabilities built here declare, and called none of it: ' . implode('; ', $said) . '.';
    }

    /**
     * What the house seeded, in one sentence for whoever reads the receipt.
     *
     * @param list<array<string, mixed>> $seeded
     */
    private static function whatTheHouseSowed(array $seeded): string
    {
        $said = [];
        foreach ($seeded as $one) {
            $said[] = sprintf(
                'seeded %d row(s) of %s%s',
                (int) ($one['added'] ?? 0),
                (string) ($one['entity'] ?? '?'),
                isset($one['unseeded']) ? ' — ' . $one['unseeded'] : ((int) ($one['already'] ?? 0) > 0 ? ' (' . (int) $one['already'] . ' were already there)' : ''),
            );
        }

        return $said === [] ? '' : ' The house ' . implode('; ', $said) . '.';
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
        // The way back boots in a copy first, as RECOVERY: never refused because the house is broken now,
        // refused only when it would break a house that boots (greenhouse decisions/0515).
        return TrialWorkspace::undo($root, $id, $this->bootProbe !== null ? new HouseBootWitness($root, $this->bootProbe) : null);
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
    /**
     * The word a promotion answers with when its trial was already promoted: ok, and nothing written. Whoever keeps
     * that call reads it here — it is no change of the house ({@see \Milpa\AppRuntime\Agent\SessionToolGate::recorded()}).
     */
    public const ALREADY_PROMOTED = 'already_promoted';

    private const ADMIT_INPUT = [
        'type' => 'object',
        'properties' => [
            'operation' => ['type' => 'string', 'description' => 'The operation, by its name: plugins.register, entity:seed or make'],
            'what' => ['type' => 'string', 'description' => 'For make, the one thing it makes: page, plugin, operation or entity'],
            'everything' => ['type' => 'string', 'description' => 'Instead of one operation: the digest `sandbox:admitted` shows as `everything`, to admit all the house knows how to apply — exactly the list that digest was taken of'],
        ],
    ];

    private const ADMISSION_INPUT = [
        'type' => 'object',
        'properties' => [
            'operation' => ['type' => 'string', 'description' => 'The operation, by its name: plugins.register, entity:seed or make'],
            'what' => ['type' => 'string', 'description' => 'For make, the one thing it makes: page, plugin, operation or entity'],
        ],
        'required' => ['operation'],
    ];

    /**
     * Admit one operation in this house, or withdraw it — by the person whose signature covers exactly this call.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function decideWhatTheHouseApplies(string $root, array $input, bool $admit): array
    {
        if (\array_key_exists('everything', $input)) {
            return $admit
                ? $this->admitEverythingThePersonSaw($root, $input)
                : ['ok' => false, 'error' => 'sandbox:withdraw takes back one operation at a time: what the house applied meanwhile is not one thing — nothing was written'];
        }
        $operation = \is_string($input['operation'] ?? null) ? trim($input['operation']) : '';
        $what = $input['what'] ?? null;
        $key = AppliedTrials::key($operation, $what === null ? [] : ['what' => $what]);
        // The name a person signs is the name on the list, whole: a `what` an operation does not take is not ignored.
        if ($key === null || ($what !== null && $key === $operation)) {
            return ['ok' => false, 'error' => \sprintf(
                '«%s» cannot be admitted: the house applies on its own the verified trial of %s, and of no other operation (greenhouse decisions/0586); nothing was written',
                trim($operation . (\is_string($what) ? ' what=' . $what : '')),
                implode(', ', AppliedTrials::admissible()),
            )];
        }
        $name = $admit ? 'sandbox:admit' : 'sandbox:withdraw';
        $granted = $this->container->has(GrantedAuthorization::class) ? $this->container->get(GrantedAuthorization::class) : null;
        if (!$granted instanceof GrantedAuthorization) {
            return ['ok' => false, 'error' => "{$name} requires the signature that names WHO decides; re-run with --sign — nothing was written"];
        }
        if ($granted->authorization->operation !== $name || $granted->authorization->arguments != ['operation' => $operation] + ($what === null ? [] : ['what' => $what])) {
            return ['ok' => false, 'error' => "the granted signature does not cover THIS {$name} — nothing was written"];
        }
        $who = 'key:' . $granted->signer->fingerprint;
        $list = AppliedTrials::forRoot($root);
        try {
            $done = $admit
                ? $list->admit($key, $who, (new \DateTimeImmutable())->format(\DATE_ATOM))
                : $list->withdraw($key, $who, (new \DateTimeImmutable())->format(\DATE_ATOM));
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if (!$done) {
            return ['ok' => false, 'error' => $admit
                ? "«{$key}» is already admitted in this house; nothing was written"
                : "«{$key}» is not admitted in this house; nothing was written"];
        }

        return [
            'ok' => true,
            'operation' => $key,
            ($admit ? 'admitted_by' : 'withdrawn_by') => $who,
            'admitted' => array_keys($list->admitted()),
            'note' => $admit
                ? "From the next leg, the house applies the verified trial of «{$key}» on its own: it plays sandbox:promote for it through the governed door, as whoever runs the leg. Withdraw it with sandbox:withdraw."
                : "From the next call, a trial of «{$key}» is applied by whoever asks for its promotion, as before. What the house already applied stays applied.",
        ];
    }

    /**
     * ONE SIGNED ACT ADMITS EVERYTHING THE PERSON SAW (greenhouse decisions/0586, amended on 2026-10-08: whoever
     * founds a house admits what it applies, with an explicit signed act — the house is still born with none).
     *
     * It stretches nobody's authority because it is the same act, the same scope and the same signature, over a
     * digest instead of a name: the digest of the list `sandbox:admitted` shows, with what each operation does.
     * Without that digest —none, a word, or the digest of a list that is no longer the one this house would admit—
     * nothing is written and the person is shown what the act would admit and what to sign. Each operation gets its
     * own entry, as if admitted by itself, and is withdrawn by itself.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function admitEverythingThePersonSaw(string $root, array $input): array
    {
        $shown = ['would_admit' => AppliedTrials::whatEachDoes(), 'everything' => AppliedTrials::digestOfEverything()];
        if (isset($input['operation']) || isset($input['what'])) {
            return ['ok' => false, 'error' => 'sandbox:admit admits one operation, or everything the house knows how to apply: not both in one act — nothing was written'] + $shown;
        }
        $seen = \is_string($input['everything']) ? trim($input['everything']) : '';
        if ($seen !== $shown['everything']) {
            return ['ok' => false, 'error' => \sprintf(
                'sandbox:admit --everything admits exactly the list a person saw, by its digest%s. This is what it would admit; sign that: sandbox:admit --everything=%s --sign — nothing was written',
                $seen === '' ? '' : ', and that digest is not of the list this house would admit now',
                $shown['everything'],
            )] + $shown;
        }
        $granted = $this->container->has(GrantedAuthorization::class) ? $this->container->get(GrantedAuthorization::class) : null;
        if (!$granted instanceof GrantedAuthorization) {
            return ['ok' => false, 'error' => 'sandbox:admit requires the signature that names WHO decides; re-run with --sign — nothing was written'];
        }
        if ($granted->authorization->operation !== 'sandbox:admit' || $granted->authorization->arguments != ['everything' => $seen]) {
            return ['ok' => false, 'error' => 'the granted signature does not cover THIS sandbox:admit — nothing was written'];
        }
        $who = 'key:' . $granted->signer->fingerprint;
        $list = AppliedTrials::forRoot($root);
        try {
            $now = $list->admitEverything($who, (new \DateTimeImmutable())->format(\DATE_ATOM));
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if ($now === []) {
            return ['ok' => false, 'error' => 'everything the house knows how to apply is already admitted in this house; nothing was written'];
        }

        return [
            'ok' => true,
            'admitted_now' => $now,
            'admitted_by' => $who,
            'admitted' => array_keys($list->admitted()),
            'note' => 'From the next leg, in a session in auto, the house applies the verified trial of each of these on its own: it plays sandbox:promote for it through the governed door, as whoever runs the leg. Each is withdrawn by itself with sandbox:withdraw.',
        ];
    }

    /**
     * What this house applies on its own, what it once did, and what can be admitted at all — with what each
     * of those does, and the digest one signed act admits them all over.
     *
     * @return array<string, mixed>
     */
    private static function whatTheHouseApplies(string $root): array
    {
        $list = AppliedTrials::forRoot($root);
        $admitted = $list->admitted();

        return [
            'ok' => true,
            'admissible' => AppliedTrials::admissible(),
            'what_each_does' => AppliedTrials::whatEachDoes(),
            'everything' => AppliedTrials::digestOfEverything(),
            'to_admit_everything' => 'sandbox:admit --everything=' . AppliedTrials::digestOfEverything() . ' --sign',
            'admitted' => $admitted,
            'withdrawn' => array_filter($list->record(), static fn (array $entry): bool => isset($entry['withdrawn_by'])),
        ];
    }

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
