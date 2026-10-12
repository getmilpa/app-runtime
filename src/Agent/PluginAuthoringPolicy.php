<?php

/**
 * This file is part of Milpa App Runtime.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/app-runtime
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Agent;

use Milpa\Command\Consent\OperationId;
use Milpa\Command\Operation;
use Milpa\AppRuntime\Auth\HostPermissionPolicy;
use Milpa\AppRuntime\Standing\HouseStandingJudge;
use Milpa\AppRuntime\Standing\NotAbsorbed;
use Milpa\AppRuntime\Standing\StandingLedger;
use Milpa\AppRuntime\Standing\OperationPin;
use Milpa\AppRuntime\Standing\StandingDecision;
use Milpa\Console\McpProjector;
use Milpa\Console\OperationBoundary;
use Milpa\Console\OperationPermissionPolicy;
use Milpa\Console\PermissionCallPolicy;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\ToolRuntime\Policy\AuthorizationResult;
use Milpa\ToolRuntime\Contracts\CallPolicy;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\PolicyGate;
use Milpa\ToolRuntime\ToolDefinition;

/** The house's authoring policy: current permission, concrete write set, and full export. */
final class PluginAuthoringPolicy implements CallPolicy, OperationBoundary
{
    /** The scope `screen:declare` requires — the only authority that may promote the declared screens. */
    private const SCREEN_SCOPE = 'milpa:component:data-table:*';

    public const BUILD = ['make', 'implement', 'edit', 'test'];

    /** The trial's own doors: each judges the export or the workspace it touches, whatever admitted the run. */
    private const SANDBOX = ['sandbox_promote', 'sandbox_undo', 'sandbox_discard'];

    /** The plugin state `plugins:write` exports: the registry the boot reads and the lock, by what each one is. */
    private const PLUGIN_STATE = ['storage/plugins.json' => 'registry', 'milpa.lock' => 'lock'];

    /** The one mutation a finite caller runs without a word: stopping a standing grant (greenhouse decisions/0615). */
    private const SUSPEND = 'standing_suspend';

    /** The seat's session this policy answers in, when a leg set one ({@see withSeatSession()}); otherwise none. */
    private ?\Milpa\Agent\SessionStore $seatStore = null;

    private ?string $seatSession = null;

    /**
     * The catalogue's operations typed by `permission`, by tool name — shared with every copy of this policy.
     *
     * @var \ArrayObject<string, Operation>
     */
    private \ArrayObject $permissioned;

    /**
     * Every operation of the catalogue, by its identity — shared with every copy of this policy. The judge of
     * standing grants reads it: for what a call acts on, and for the instruments a grant names.
     *
     * @var \ArrayObject<string, Operation>
     */
    private \ArrayObject $catalogued;

    /**
     * The identities two DIFFERENT operations of the catalogue answer to, with where each of them is written — shared
     * with every copy of this policy. A house takes such a catalogue as the published runtime does; a grant does not.
     *
     * @var \ArrayObject<string, list<string>>
     */
    private \ArrayObject $twice;

    /**
     * What each operation WAS when this process was told of it: the operation as it was told — its identity and where
     * its handler is written — => its pin, or '' when it could not be pinned. Shared with every copy of this policy,
     * and never read again from the disk: it is what says which code this process is running.
     *
     * @var \ArrayObject<string, string>
     */
    private \ArrayObject $loaded;

    /**
     * Under `with a book`: whether this house kept a book of standing grants when this process was first told its
     * catalogue. One that did not has no record of what it loaded, and cannot speak for a grant given since.
     *
     * @var \ArrayObject<string, bool>
     */
    private \ArrayObject $booted;

    /**
     * @param (\Closure(): ?\Milpa\Agent\SessionStore)|null $sessions
     * @param JudgedPermission|null                         $judged       the HTTP policy's verdict on the permissioned
     *                                                                    operation now running; without it a mutation
     *                                                                    typed by `permission` is judged by its scopes
     * @param (\Closure(): mixed)|null                      $permissions  the host's OperationPermissionPolicy, asked
     *                                                                    for a finite caller of an operation typed by
     *                                                                    `permission`; without it that call is refused
     * @param (\Closure(): ?BuiltCapabilities)|null         $capabilities the capabilities built in this house; without
     *                                                                    them no verb is judged as a built one
     * @param HouseStandingJudge|null                       $standing     the judge of standing grants; without one, the
     *                                                                    house's own over this root's book
     */
    public function __construct(
        private readonly string $root,
        private readonly TrialRunner $runner = new TrialRunner(),
        private readonly ?\Closure $sessions = null,
        private readonly ?JudgedPermission $judged = null,
        private readonly ?\Closure $permissions = null,
        private readonly ?\Closure $capabilities = null,
        private readonly ?HouseStandingJudge $standing = null,
    ) {
        $this->permissioned = new \ArrayObject();
        $this->catalogued = new \ArrayObject();
        $this->twice = new \ArrayObject();
        $this->loaded = new \ArrayObject();
        $this->booted = new \ArrayObject();
    }

    /**
     * The same policy, answering inside one seat's session: a refusal the seat's frontier would offer says who
     * grants it (greenhouse decisions/0543). The judgement does not change — only the sentence of a refusal.
     *
     * A copy of THIS class, never a wrapper: the trial executor reads `instanceof PluginAuthoringPolicy` to confine
     * a call's write set, and a decorator would silently widen it.
     */
    public function withSeatSession(\Milpa\Agent\SessionStore $store, string $session): self
    {
        $copy = clone $this;
        $copy->seatStore = $store;
        $copy->seatSession = $session;

        return $copy;
    }

    /** Install the host boundary for both the CLI catalogue and HTTP/agent projections. */
    public static function install(\Milpa\Interfaces\Di\DIContainerInterface $container, string $root): void
    {
        // The verdict follows the runs of the dispatcher the HTTP surface announces them on — only for the policy
        // that becomes the boundary, so a second install never subscribes a mark nobody takes.
        $events = !$container->has(OperationBoundary::class) && $container->has(MilpaEventDispatcherInterface::class)
            ? $container->get(MilpaEventDispatcherInterface::class) : null;
        $policy = new self(
            $root,
            sessions: static fn () => (new \Milpa\AppRuntime\Operations\AgentOperations($container))->sessionStore(),
            judged: $events instanceof MilpaEventDispatcherInterface ? JudgedPermission::listen($events) : null,
            permissions: static fn (): mixed => $container->has(OperationPermissionPolicy::class) ? $container->get(OperationPermissionPolicy::class) : null,
            capabilities: static fn (): ?BuiltCapabilities => BuiltCapabilities::ofContainer($container),
        );
        // THE JUDGE MCP AND A FINITE TERMINAL CALLER ANSWER TO (GHSA-xj7j-99jx-52hh, greenhouse decisions/0545): the
        // host's own resolver, the one its HTTP policy asks. A host that brought its own judge keeps it.
        if (!$container->has(OperationPermissionPolicy::class)) {
            $container->registerService(OperationPermissionPolicy::class, new HostPermissionPolicy($container));
        }
        if (!$container->has(CallPolicy::class)) {
            $container->registerService(CallPolicy::class, $policy);
        }
        if (!$container->has(OperationBoundary::class)) {
            $container->registerService(OperationBoundary::class, $policy);
        }
    }

    /**
     * Tells the installed boundary which of the catalogue's operations are typed by `permission`, and hands the
     * catalogue back unchanged.
     *
     * A tool definition carries `scopes` and never `permission`, so without this a permission-typed mutation reads,
     * at authorization, as one that declares no authority — and the terminal asks this policy before it asks the
     * permission judge.
     *
     * TWO OPERATIONS UNDER ONE IDENTITY ARE TOLD, AND NO GRANT IS HONOURED OVER THAT NAME (greenhouse decisions/0615,
     * the third review's L5 and the fourth's M4). The judge of standing grants reads the operation this keeps under a
     * name, and a terminal runs the one it finds first under it: with two — `lab:scan` and `lab_scan` are one — they
     * would not mean the same one. That was first closed by refusing such a catalogue WHOLE, here, where every
     * surface hands its catalogue: and a house that never gave a grant refused every command it was asked, the ones
     * that would have taken the second operation away among them, where the published runtime ran. So the catalogue
     * is taken as it always was, the identity is remembered ({@see self::answeredTwice()}), and it is the JUDGE that
     * does not take it: no grant is given over such a name, and one given before the twin appeared is not honoured.
     * The same operation declared twice is one operation, and a catalogue told again replaces what was told before.
     *
     * AND WHAT EACH OPERATION IS, AS THIS PROCESS LOADED IT, IS READ HERE AND KEPT (fourth review, M1) — in a house
     * that keeps a book; in one that does not, nothing is read ({@see self::remembers()}).
     *
     * @param list<Operation> $operations
     *
     * @return list<Operation>
     */
    public static function catalogue(\Milpa\Interfaces\Di\DIContainerInterface $container, array $operations): array
    {
        $boundary = $container->has(OperationBoundary::class) ? $container->get(OperationBoundary::class) : null;
        if ($boundary instanceof self) {
            $told = [];
            foreach ($operations as $operation) {
                $told[(new OperationId($operation->name))->canonical][self::writtenAt($operation)] = true;
            }
            foreach ($told as $identity => $written) {
                if (\count($written) > 1) {
                    $boundary->twice[$identity] = array_keys($written);
                } elseif (isset($boundary->twice[$identity])) {
                    unset($boundary->twice[$identity]);
                }
            }
            foreach ($operations as $operation) {
                if ($operation->permission !== null) {
                    $boundary->permissioned[McpProjector::toolName($operation->name)] = $operation;
                }
                $boundary->catalogued[(new OperationId($operation->name))->canonical] = $operation;
            }
            $boundary->remembers($operations);
        }

        return $operations;
    }

    /**
     * Why no grant is given, or honoured, over this identity — two different operations of this house answer to it —
     * or `null`: one does, or none.
     */
    public function answeredTwice(OperationId|string $operation): ?string
    {
        $id = $operation instanceof OperationId ? $operation : new OperationId($operation);
        $written = $this->twice[$id->canonical] ?? null;

        return $written === null ? null : \sprintf(
            'two operations answer to `%s` in this house (%s): a grant, a judge and a terminal would not mean the same one',
            $id->forCli(),
            implode(', and ', $written),
        );
    }

    /**
     * WHAT THIS PROCESS LOADED IS READ WHEN IT IS TOLD, AND NEVER AGAIN (fourth review, M1; what Rod decided on
     * 2026-10-12 — «a receipt carries the pin of what PRODUCED it» — applied to a process that lives long).
     *
     * A pin is read from the disk, and the disk can be another by the time a process answers: a leg that loaded a
     * guard before a person hardened it measured a candidate with the guard it had loaded, and its yes was written
     * under the pin of the hardened one — the one the grant had just been given over. So what each operation is, is
     * read ONCE, here, where the process is told of it: that is as near to «what was loaded» as a house can read.
     * A yes, a sign of calm and an act under a grant then ask that the pin read NOW be that one.
     *
     * ONLY IN A HOUSE THAT KEEPS A BOOK. Where nobody ever granted anything, nothing is read — such a house behaves
     * as the published runtime, and costs what it costs. So a process that started before its house kept a book has
     * no record of what it loaded, and it does not speak for a grant given since: it is started again.
     *
     * WHAT THIS DOES NOT SEE: a file of the operation's unit that this process loads AFTER this reading, changed in
     * between and put back before it answers. And, as before, code in another unit that the handler reaches.
     *
     * @param list<Operation> $operations
     */
    private function remembers(array $operations): void
    {
        $this->booted['with a book'] ??= StandingLedger::forRoot($this->root)->exists();
        if ($this->booted['with a book'] !== true) {
            return;
        }
        $fresh = array_values(array_filter($operations, fn (Operation $operation): bool => !isset($this->loaded[self::toldAs($operation)])));
        foreach (OperationPin::ofEach($fresh, $this->root) as $at => $pin) {
            $this->loaded[self::toldAs($fresh[$at])] = $pin ?? '';
        }
    }

    /** The pin of this operation as this process read it when it was told of it — or `null`: it has none to give. */
    private function asThisProcessLoadedIt(Operation $operation): ?string
    {
        $pin = $this->loaded[self::toldAs($operation)] ?? '';

        return $pin === '' ? null : $pin;
    }

    /** An operation as a process is told of it: its identity, and where its handler is written. */
    private static function toldAs(Operation $operation): string
    {
        return (new OperationId($operation->name))->canonical . ' @ ' . self::writtenAt($operation);
    }

    /**
     * Whether a leg with trials on runs this operation OUTSIDE its own process, by what the operation declares: as
     * work confined in the house, or rehearsed in a copy ({@see HouseWork::declaresWork()}, {@see TrialRouter::eligible()}).
     * A run in this process is heard whole by the judge of standing grants; one that leaves it, only when it narrows.
     */
    public function runsElsewhereOnALeg(Operation $operation): bool
    {
        return HouseWork::declaresWork($operation) || (new TrialRouter($this->root, $this->runner, ''))->eligible($operation);
    }

    /**
     * THE HOUSE'S POLICY BEHIND WHAT A GATE HOLDS: itself, or the one a guard of permissions holds inside — or `null`
     * (found sweeping every door after the fourth review).
     *
     * The projector of milpa/console wraps a gate's policy the moment ONE operation typed by `permission` is told to
     * a registry, and every door of a leg asked «is the gate's policy the house's?» of the wrapper: it was not, so
     * nothing was lent, no refusal cited the judge, and a run that leaves the process — a rehearsal, work in the
     * house — never crossed the judge at all. An act a grant covers ran with nothing written before it. So a leg asks
     * here, and the wrapper is looked into.
     *
     * The guard keeps the host's policy in a private place and hands it to nobody; it is read by reflection, and a
     * guard that keeps it elsewhere one day makes this THROW: a leg that cannot find the house's judge does not go
     * on as if there were none.
     */
    public static function behind(?CallPolicy $held): ?self
    {
        if ($held instanceof self || !$held instanceof PermissionCallPolicy) {
            return $held instanceof self ? $held : null;
        }
        $host = (new \ReflectionProperty(PermissionCallPolicy::class, 'host'))->getValue($held);

        return $host instanceof self ? $host : null;
    }

    /** Where an operation's handler is written — which tells one operation from another that took its name. */
    private static function writtenAt(Operation $operation): string
    {
        if (!\is_callable($operation->handler)) {
            return 'a handler that cannot be called';
        }
        $handler = new \ReflectionFunction(\Closure::fromCallable($operation->handler));
        $class = $handler->getClosureScopeClass()?->getName();

        return \sprintf('%s, %s:%d', $class ?? 'no class', basename((string) $handler->getFileName()), (int) $handler->getStartLine());
    }

    /**
     * Judge the requested resource and export against this call's current authority.
     *
     * @param array<string, mixed> $arguments
     */
    public function authorize(ToolContext $context, ToolDefinition $tool, array $arguments): AuthorizationResult
    {
        return $this->verdict($context, $tool, $arguments, $this->permissioned[$tool->name] ?? null);
    }

    /** The operation the catalogue holds under this identity, however a surface spells it — or null. */
    public function operationNamed(OperationId|string $operation): ?Operation
    {
        $id = $operation instanceof OperationId ? $operation : new OperationId($operation);

        return $this->catalogued[$id->canonical] ?? null;
    }

    /** The judge of standing grants over this house's book (greenhouse decisions/0615). */
    public function standing(): HouseStandingJudge
    {
        return $this->standing ?? new HouseStandingJudge($this->root);
    }

    /**
     * The verdict for one call; `$permissioned` is the operation behind the tool when it is typed by `permission`.
     *
     * @param array<string, mixed>  $arguments
     * @param StandingDecision|null $decided   set to what the judge of standing grants answered about this call, when
     *                                         some grant has to do with it
     */
    private function verdict(ToolContext $context, ToolDefinition $tool, array $arguments, ?Operation $permissioned, ?StandingDecision &$decided = null): AuthorizationResult
    {
        try {
            $name = $tool->name;
            // WHAT WAS LENT FOR THE ASKING IS READ BY THE GATE AND BY NOTHING ELSE (greenhouse decisions/0615, «Lo que
            // la revisión encontró», 1 and 2). Everything below asks the caller as it is on its own — whatever tool the
            // words were lent for, and whether or not a grant passes. What was lent only tells this policy that the
            // gate's question about a word was answered with a loan, and so has to be asked again.
            $handed = $context;
            $context = HouseStandingJudge::withoutLent($context);
            $lent = $handed !== $context;
            // THE BOOK IS ASKED BEFORE ANYTHING ELSE ANSWERS (greenhouse decisions/0615 §5; «Lo que la segunda revisión
            // encontró», 5). A book that does not verify refuses every enrolled seat whatever it calls — and a door
            // that answered before asking let a seat promote a trial with the book broken.
            $stopped = $this->standing()->stops($context);
            if ($stopped !== null) {
                return AuthorizationResult::denied($stopped);
            }
            if ($name === 'edit' && array_key_exists('source', $arguments)
                && !$context->hasScope('agent:read') && !$context->hasScope('agent:answer')) {
                throw new \RuntimeException('Recorded repair requires agent:read or agent:answer.');
            }
            if ($name === 'sandbox_promote' || $name === 'sandbox_undo') {
                $this->checkExport($context, $name, $arguments);
                return AuthorizationResult::allowed();
            }
            // A CALL THAT A STANDING GRANT NAMES IS JUDGED BY THAT GRANT, AT THIS MOMENT (greenhouse decisions/0615).
            // When the grant stands, the seat was handed the operation's words for the asking ({@see contextFor()}),
            // so the gate's question about a word decided nothing; this one does. The book is read now — not when the session started, not
            // from anyone's memory — and a grant that is suspended refuses its whole class, the harmless act included.
            // A pass lends exactly this call. A grant that names the operation but not THESE arguments lends nothing:
            // the call is judged as it was before any grant existed.
            //
            // BEFORE the question of who holds every scope, on purpose: a grant governs the seat it names whatever
            // that seat holds on its own — a condition that a scope given separately walks around is not a condition.
            // The operator's own terminal and a key no grant names are not this judge's: it says nothing of them.
            $operation = $this->operationNamed($name);
            $decided = $operation === null ? null : $this->standing()->decide($context, $operation, $arguments, $this->operationNamed(...), $this->answeredTwice(...));
            if ($decided?->named() === true && !$decided->passes()) {
                $this->standing()->refused($decided);

                return AuthorizationResult::denied((string) $decided->refusal());
            }
            if ($context->hasScope('*')) {
                return AuthorizationResult::allowed();
            }
            if ($lent && $decided?->passes() !== true) {
                // The gate was answered with a loan and no grant passes this call: its question, asked again of what the
                // caller holds on its own — in its own sentence, because this is the refusal it would have given.
                $scoped = (new PolicyGate())->authorizeScopes($context, $name, $tool->scopes);
                if (!$scoped->allowed) {
                    return $scoped;
                }
            }
            // ANYONE THE HOUSE RECOGNIZES MAY STOP A STANDING GRANT (decisions/0615 §4): stopping gives nobody
            // authority, so the operation asks for no word and is let through here — the one mutation that is.
            // «Recognizes» is the ledger's word: a key or a passkey it holds live, not whatever name a surface gives
            // a client nobody authenticated.
            if ($name === self::SUSPEND && $this->recognizes($context->principal)) {
                return AuthorizationResult::allowed();
            }
            // A VERB OF A CAPABILITY BUILT IN THIS HOUSE RUNS FOR A SEAT ONLY IF A PERSON ADMITTED IT (greenhouse
            // decisions/0590). The word it declares as its scope is its author's, and the author may be the seat:
            // measured on the published train, a capability naming its scope after one the seat already held was
            // read and written by it with no human act. So for an enrolled key the admission is the whole verdict —
            // its declared scopes are not asked here, and holding them is not asked either.
            $admissions = $this->admissions();
            $built = $admissions?->seatOf($context->principal) === null ? null : $admissions->verb($name);
            if ($admissions !== null && $built !== null) {
                $missing = $admissions->missing($context->principal, $name);

                return $missing === null
                    ? AuthorizationResult::allowed()
                    : AuthorizationResult::denied($missing->sentence() . $this->whoGrantsIt($name, $arguments));
            }
            if (in_array($name, self::BUILD, true)) {
                $this->writePaths($context, $name, $arguments);
                if (!$this->runner->available()) {
                    throw new \RuntimeException('Plugin authoring requires an available write-confined trial; the host cannot execute this call directly.');
                }
            } elseif ($name === 'sandbox_discard') {
                $workspace = $this->workspace($arguments);
                $record = json_decode((string) @file_get_contents($workspace->baseDirectory() . '/authoring.json'), true);
                $plugin = is_array($record) ? ($record['plugin'] ?? null) : null;
                $this->requirePlugin($context, $plugin);
            } elseif ($permissioned !== null) {
                // `Operation` holds `scopes` XOR `permission`: empty scopes here are a permission to judge, by the same
                // judge MCP and the terminal ask — never a pass, and never "declares no authority" (decisions/0545).
                return $this->judgePermission($permissioned, $context, $arguments);
            } elseif ($tool->mutating && $tool->scopes === []) {
                throw new \RuntimeException("Mutation '{$name}' declares no authority for a finite principal.");
            }
            return AuthorizationResult::allowed();
        } catch (MissingPermission $missing) {
            return AuthorizationResult::denied($missing->getMessage() . $this->whoGrantsIt($tool->name, $arguments, $missing->permission));
        } catch (\Throwable $error) {
            return AuthorizationResult::denied($error->getMessage());
        }
    }

    /**
     * The caller as the tool runtime's gate is to see it for ONE call to this tool (greenhouse decisions/0590).
     *
     * That gate asks for the word a tool declares before it asks this policy, and for a seat calling a built verb
     * the word is not the question: the admission is, and this policy answers it right after. So that one call
     * carries the verb's own declared words — admitted or not; the verdict is this policy's. Any other caller, and
     * any other tool, gets the context back as it came: nothing is written to the seat, and no other operation
     * that asks for the same word ever sees it.
     *
     * @param array<string, mixed>|null $arguments the arguments of the call, or null when only the tool is asked
     *                                             about (is it offered to this caller)
     */
    public function contextFor(ToolContext $context, string $tool, ?array $arguments = null): ToolContext
    {
        // Whatever was lent to this context for ANOTHER tool — the call around this one — is taken back before this
        // tool is asked about: a nested call starts from the caller as it is on its own (decisions/0615, review 2).
        $context = HouseStandingJudge::withoutLent($context);
        $admissions = $context->hasScope('*') ? null : $this->admissions();
        $verb = $admissions?->seatOf($context->principal) === null ? null : $admissions->verb($tool);
        $words = $verb === null ? [] : array_values(array_diff($verb->operation->scopes, $context->scopes));
        if ($words !== []) {
            $context = new ToolContext(
                principal: $context->principal,
                channel: $context->channel,
                scopes: [...$context->scopes, ...$words],
                request_id: $context->request_id,
                ip: $context->ip,
                userAgent: $context->userAgent,
                extra: $context->extra,
                mode: $context->mode,
                resultBudget: $context->resultBudget,
            );
        }
        // THE SAME HANDING-OVER FOR A CALL A STANDING GRANT NAMES FOR THIS SEAT (greenhouse decisions/0615): the words
        // the operation declares, for the gate's question about this one call — so that the question that decides is
        // this policy's judge and not a word the seat lacks. With the call's arguments in hand a word is lent only
        // when a grant names THIS call; a call no grant names is handed nothing. What is lent is marked with the tool
        // it was lent for, and every reader after the gate takes it back.
        //
        // ONLY ON A GRANT THAT IS IN FORCE AND VERIFIED. One that is suspended, superseded, revoked, expired or not
        // honoured lends nothing, even for the asking, and the context goes on as it came: the gate refuses the seat
        // for the word it lacks, and the door that asked has that refusal cite the judge ({@see refusalAtTheGate()}).
        $operation = $verb === null ? $this->operationNamed($tool) : null;
        if ($operation === null) {
            return $context;
        }
        $standing = $this->standing();
        if ($arguments === null) {
            $lent = $standing->offers($context, $operation, $this->operationNamed(...), $this->answeredTwice(...));

            return $lent === [] ? $context : HouseStandingJudge::withLent($context, $tool, $lent);
        }
        $lent = $standing->lends($context, $operation, $standing->decide($context, $operation, $arguments, $this->operationNamed(...), $this->answeredTwice(...)));

        return $lent === [] ? $context : HouseStandingJudge::withLent($context, $tool, $lent);
    }

    /**
     * What the house adds to the gate's refusal of a call for a word the caller lacks — or null: it adds nothing,
     * and the gate's own answer stands (greenhouse decisions/0615, sent back by the coordinator on 2026-10-11).
     *
     * A seat whose call a standing grant names, when that grant does not stand, is lent nothing and is refused for
     * the word. That refusal alone would name the wrong thing to fix — the seat was never meant to hold the word —
     * so the house's doors follow it with the judge's reason. Nothing rides in the context for this: the judge is
     * asked again, about the caller as it is on its own.
     *
     * @param ToolContext          $seen      the caller as {@see contextFor()} returned it for this call
     * @param list<string>         $scopes    the words the tool declares
     * @param array<string, mixed> $arguments the arguments of the call
     * @param string|null          $said      what the gate answered, when the door has it: only its refusal for the
     *                                        missing word is added to
     * @param bool                 $written   whether this is the call itself — whose refusal the house writes down —
     *                                        or only a question about it
     */
    public function refusalAtTheGate(ToolContext $seen, string $tool, array $scopes, array $arguments, ?string $said = null, bool $written = true): ?string
    {
        $scoped = (new PolicyGate())->authorizeScopes($seen, $tool, $scopes);
        if ($scoped->allowed || ($said !== null && $said !== $scoped->reason)) {
            return null;
        }
        $operation = $this->operationNamed($tool);
        $standing = $this->standing();
        $decided = $operation === null ? null : $standing->decide(HouseStandingJudge::withoutLent($seen), $operation, $arguments, $this->operationNamed(...), $this->answeredTwice(...));
        $because = $decided?->refusal();
        if ($decided === null || $because === null) {
            return null;
        }
        if ($written) {
            $standing->refused($decided);
        }

        return $scoped->reason . ' ' . $because;
    }

    /** Whether the house's ledger of identities holds this principal live: a key or a passkey it recognized. */
    private function recognizes(?string $principal): bool
    {
        $key = $principal === null ? null : \Milpa\AppRuntime\Identity\EnrollmentLine::keyOf($principal);
        try {
            return $key !== null && (new \Milpa\AppRuntime\Identity\FileEnrollmentStore($this->root . '/storage/identity/enrollments.json'))->scopesFor($key) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /** The judge of built verbs over this house's ledger, or null when nobody told this policy what was built. */
    public function admissions(): ?CapabilityAdmissions
    {
        $built = $this->built();

        return $built === null ? null : CapabilityAdmissions::forRoot($this->root, $built);
    }

    /** What this house built, as this policy was told — or null: nobody told it, or the house built nothing. */
    private function built(): ?BuiltCapabilities
    {
        try {
            $built = $this->capabilities === null ? null : ($this->capabilities)();
        } catch (\Throwable) {
            return null;
        }

        return $built instanceof BuiltCapabilities && !$built->isEmpty() ? $built : null;
    }

    /**
     * A finite caller of an operation typed by `permission`: the host's judge decides, and without one nothing runs.
     *
     * @param array<string, mixed> $arguments
     */
    private function judgePermission(Operation $operation, ToolContext $context, array $arguments): AuthorizationResult
    {
        $judge = $this->permissions === null ? null : ($this->permissions)();
        if (!$judge instanceof OperationPermissionPolicy) {
            return AuthorizationResult::denied(\sprintf(
                "Operation '%s' requires the permission '%s' and this host wired no %s to judge it. Nothing ran.",
                $operation->name,
                $operation->permission,
                OperationPermissionPolicy::class,
            ));
        }

        return PermissionCallPolicy::judge($judge, $operation, $context, $arguments);
    }

    /**
     * For a refusal the seat's frontier would offer, the sentence that says who grants it — otherwise nothing.
     *
     * A missing scope stays a refusal (decisions/0317); what it gains is the one fact the model could not know:
     * a person can grant it in the panel, so it is not a gap in the house. Unsaid, a resident in evidence/1071
     * declared `plugins.Blog:write` the scaffolder's chicken-and-egg and ended its leg as a false `HOUSE_DEBT`.
     * The frontier decides (decisions/0496): an invented name, or a session no one enrolled, gets nothing added.
     *
     * A PERSON'S SESSION IS TOLD WHOSE DECISION IT IS (decisions/0609, path 1): it is nobody's seat, so the frontier
     * offers nothing for it and it read the bare sentence — and a model with no way out declared a debt of the house
     * (evidence/1175). It is told what is true of it instead ({@see toAPersonsSession()}). What a seat reads is
     * untouched: a session a person opened is nobody's seat, so the two never meet.
     *
     * @param array<string, mixed> $arguments
     * @param string|null          $lacked    the permission this refusal names, when it names one
     */
    private function whoGrantsIt(string $tool, array $arguments, ?string $lacked = null): string
    {
        if ($this->seatStore === null || $this->seatSession === null) {
            return '';
        }
        try {
            $frontier = SeatFrontier::forRoot($this->root, $this->seatStore, $this->built());
            if ($lacked !== null && $frontier->openedByAPerson($this->seatSession)) {
                return self::toAPersonsSession($lacked);
            }
            $offered = $frontier->wouldOffer($this->seatSession, $tool, $arguments);
        } catch (\Throwable) {
            return '';
        }
        if (($offered['kind'] ?? null) === 'capability') {
            // Admitting is another act than granting a scope (greenhouse decisions/0590): a person sees the verb's
            // contract and approves its digest. And the house does not replay a call of the domain after it
            // (decisions/0577 resumes only what a trial confines), so the sentence must not promise that it will.
            return ' Whoever enrolled this seat admits it, seeing its contract — Agent → Decisions in the panel, or'
                . ' `identity:grant` with the digest the house shows. This is a person\'s decision, not a gap in the'
                . ' house: do not declare HOUSE_DEBT for it. The leg ends here and waits for that admission; after'
                . ' it, `continue` and make this same call again.';
        }

        return $offered === null ? '' : sprintf(
            ' Whoever enrolled this seat can grant «%s» in the panel (Agent → Decisions). This is a person\'s decision,'
            . ' not a gap in the house: do not declare HOUSE_DEBT for it. The leg ends here and waits for that grant;'
            . ' after it, `continue` runs this same call again.',
            $offered['permission'],
        );
    }

    /**
     * What a session a PERSON opened is told after a refusal for a permission of a plugin (greenhouse decisions/0609,
     * I1). Information, and nothing else: it grants nothing, it runs nothing, and it promises nothing the house will
     * do — nobody can grant that scope to a person's session (decisions/0493), so there is nothing to wait for. It
     * says whose decision this is, that it is no debt of the house, and the act that works today.
     */
    public static function toAPersonsSession(string $permission): string
    {
        return sprintf(
            " A person opened this session, with a passkey, and in this house a seat builds, not a person's session: no one"
            . ' can grant «%1$s» to it. This is how the house is decided, not a gap in the house: do not declare'
            . " HOUSE_DEBT for it, and do not make this call again. What works today is the person's own act — to seat a resident"
            . ' and grant it «%1$s» in the panel (Agent → Decisions) when it asks. Tell the person that.',
            $permission,
        );
    }

    /**
     * The permission a recorded call still lacks under this authority, or null (greenhouse decisions/0493).
     *
     * The refusal is judged again, not read back: the same resource checks {@see authorize()} runs before
     * a call executes, asked with the scopes the principal holds NOW. A refusal already granted, or a call
     * that failed for any other reason, names no permission.
     *
     * @param array<string, mixed> $arguments the arguments the call was recorded with
     */
    public function missingPermission(ToolContext $context, string $tool, array $arguments): ?string
    {
        return $this->missing($context, $tool, $arguments)?->permission;
    }

    /**
     * The refusal a recorded call still earns under this authority, with the plugin it targets — or null.
     *
     * The same judgement as {@see missingPermission()}, kept whole: a reader that must know WHICH plugin
     * a missing write scope is for reads it here, never from the scope's spelling (decisions/0496).
     *
     * @param array<string, mixed> $arguments the arguments the call was recorded with
     */
    public function missing(ToolContext $context, string $tool, array $arguments): ?MissingPermission
    {
        if ($context->hasScope('*')) {
            return null;
        }
        try {
            if (in_array($tool, self::BUILD, true)) {
                $this->writePaths($context, $tool, $arguments);
            } elseif ($tool === 'sandbox_promote' || $tool === 'sandbox_undo') {
                $this->checkExport($context, $tool, $arguments);
            } elseif ($tool === 'sandbox_discard') {
                $record = json_decode((string) @file_get_contents($this->workspace($arguments)->baseDirectory() . '/authoring.json'), true);
                $this->requirePlugin($context, is_array($record) ? ($record['plugin'] ?? null) : null);
            }
        } catch (MissingPermission $missing) {
            return $missing;
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Resolve the exact write set; null preserves the explicitly unrestricted local mode.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<string>|null
     */
    public function writePaths(ToolContext $context, string $name, array $arguments): ?array
    {
        if ($context->hasScope('*') && !($name === 'edit' && array_key_exists('source', $arguments))) {
            return null;
        }
        $plugin = $arguments['plugin'] ?? null;
        if ($name === 'test') {
            $path = $arguments['path'] ?? null;
            if (!is_string($path) || !preg_match('~^tests/Plugins/([A-Za-z_][A-Za-z0-9_]*)(?:/|$)~D', $path, $match)) {
                throw new \RuntimeException('Scoped test requires a path under tests/Plugins/<Plugin>.');
            }
            $this->regularPath($this->root, $path);
            $plugin = $match[1];
        }
        if ($name === 'edit' && isset($arguments['mode'])) {
            throw new \RuntimeException('Scoped authoring currently requires a complete implementation in one call.');
        }
        // implement's parts live beside the scaffold inside this same write set. Each part still
        // runs in a confined trial and requires an authorized promotion; only finish publishes PHP
        // after the existing verifier accepts the assembled source (greenhouse decisions/0332).
        $plugin = $this->requirePlugin($context, $plugin);
        $paths = ['src/Plugins/' . $plugin, 'tests/Plugins/' . $plugin];
        foreach ($paths as $path) {
            $this->regularPath($this->root, $path);
        }
        return $paths;
    }

    /**
     * The operation boundary covers direct CLI/HTTP/MCP execution as well as the agent's registry.
     *
     * @param array<string, mixed> $input
     * @param \Closure(): mixed    $next
     */
    public function execute(Operation $operation, array $input, ?ToolContext $authority, \Closure $next): mixed
    {
        // WHAT A MEASURING OR A WATCHING OPERATION ANSWERS, THE HOUSE WRITES DOWN ITSELF (greenhouse decisions/0615
        // §2, §4). Noted before the run — which content it is about to measure — and written after it, from the
        // result the handler returned: a verdict never arrives as an argument. An operation no grant names as an
        // instrument or a watcher leaves no trace here, and in a house with no grant nothing is even read.
        $standing = $this->standing();
        $noted = $standing->witnessing($operation, $input);
        $result = $this->governed($operation, $input, $authority, $next);
        if ($noted !== null) {
            $standing->witnessed($noted, $operation, $input, $result, $authority, $this->asThisProcessLoadedIt($operation));
        }

        return $result;
    }

    /**
     * One run through the boundary, judged — and, for an act a standing grant covers, written down before it
     * runs and looked at again after.
     *
     * @param array<string, mixed> $input
     * @param \Closure(): mixed    $next
     */
    private function governed(Operation $operation, array $input, ?ToolContext $authority, \Closure $next): mixed
    {
        // The boundary reads the caller as it is on its own: what was lent for the gate's question stays at the gate.
        $handed = $authority ?? ToolContext::cli();
        $context = HouseStandingJudge::withoutLent($handed);
        $name = McpProjector::toolName($operation->name);
        // …and it asks the book before the one door below that answers without a verdict (review, second, 5).
        $stopped = $this->standing()->stops($context);
        if ($stopped !== null) {
            throw new \RuntimeException($stopped);
        }
        // A PERMISSION IS JUDGED FOR THE OPERATION THAT RUNS (greenhouse decisions/0544). `Operation` holds `scopes`
        // XOR `permission`, so empty scopes are not "declares no authority" when the host's HTTP policy admitted this
        // very run. Asked on every run, so a verdict never waits for a later one; authoring and sandbox calls keep
        // their own checks.
        if ($this->judged?->take($operation) === true && !in_array($name, [...self::BUILD, ...self::SANDBOX], true)) {
            return $next();
        }
        $tool = new ToolDefinition(
            $name,
            $operation->description,
            $operation->inputSchema ?? [],
            $operation->handler,
            scopes: $operation->scopes,
            mutating: $operation->mutating
        );
        // THE VERDICT IS ASKED OF THE CALLER AS IT WAS HANDED, so that this policy knows whether the gate's question
        // about a word was answered with a loan — and asks it again, of what the caller holds on its own, when no grant
        // passes THIS call. A word lent for one call does not open the boundary for another (review, second, 7).
        $decided = null;
        $verdict = $this->verdict($handed, $tool, $input, $operation->permission !== null ? $operation : null, $decided);
        if (!$verdict->allowed) {
            throw new \RuntimeException((string) $verdict->reason);
        }
        if ($decided?->passes() !== true) {
            return $this->carriedOut($operation, $input, $context, $name, $next);
        }
        // …AND ONLY AS THE OPERATION THIS PROCESS LOADED (fourth review, M1). The judge asked that the operation ON
        // DISK be the one the grant was given over; what runs here is what this process loaded, and a process that
        // outlived an update runs the code from before it. Asked before the house writes that it lets the act pass.
        $loaded = $this->asThisProcessLoadedIt($operation);
        if ($loaded === null || $loaded !== OperationPin::of($operation, $this->root)) {
            throw new \RuntimeException(\sprintf(
                '`%s` is covered by a standing grant, and a grant is given over what an operation IS: %s. Start this process again — a leg, a server — and it runs what the grant was given over. Nothing ran.',
                (new OperationId($operation->name))->forCli(),
                $loaded === null
                    ? 'this process loaded it before this house kept a book of standing grants, or could not pin it, so it cannot say which code it is running'
                    : 'what this process loaded is not what the disk holds now — its code or its package changed while this process was running',
            ));
        }

        // Then the act goes the way any call of that operation goes: a build call still runs in its trial.
        return $this->underAGrant($context, $operation, $input, fn (): mixed => $this->carriedOut($operation, $input, $context, $name, $next));
    }

    /**
     * The judge's boundary for a run that does NOT pass through {@see execute()} in this process (greenhouse
     * decisions/0615, «Lo que la segunda revisión encontró», 1).
     *
     * A leg rehearses a mutation in a copy of the house and runs work in the domain in another process; neither
     * reaches the handler its registry holds, and the process that does run it is handed no caller. So an act a
     * standing grant covers ran on a leg with nothing written before it, no judgment under the lock and no look
     * afterwards — the three things this house says of every act it lets pass. Whoever starts such a run asks here,
     * with the run in hand: a call no grant has to do with runs as it always did; one a grant names is judged again
     * with the book locked, written down, run, and looked at again.
     *
     * @template T
     *
     * @param array<string, mixed> $input
     * @param \Closure(): T        $run      the run, wherever it happens
     * @param \Closure(T): mixed   $answered what the act itself answered, read out of what the run gave back — or
     *                                       `null` when it gave no answer at all
     *
     * @return T
     *
     * @throws NotAbsorbed the judge's refusal: nothing ran
     */
    public function crossing(?ToolContext $authority, Operation $operation, array $input, \Closure $run, \Closure $answered): mixed
    {
        $context = HouseStandingJudge::withoutLent($authority ?? ToolContext::cli());
        $standing = $this->standing();
        // WHAT SPEAKS ABOUT AN ACT IS HEARD ON THIS DOOR TOO — AND ONLY WHAT STOPS (fourth review, H1; WHAT is heard
        // is a PROPOSAL, not decided). The judge was put on this door for the act a grant covers, and not for what
        // speaks about it: a watcher or an instrument that a leg runs as work, or rehearses, answered the seat «fired»
        // or «no», the process that ran it saw no book, and the house wrote nothing — the grant stayed in force and
        // the next covered act ran. So the run is noted before and read after, here, as {@see self::execute()} does
        // for a run in this process. From a run that left it only what NARROWS is taken: a «fired», and a no said of
        // the content the house itself reads. A yes and a sign of calm are not — the house did not run that code.
        $noted = $standing->witnessing($operation, $input);
        $heard = static function (mixed $result) use ($standing, $noted, $operation, $input, $answered, $authority): mixed {
            if ($noted !== null) {
                $standing->witnessed($noted, $operation, $input, $answered($result), $authority, null);
            }

            return $result;
        };
        if ($standing->decide($context, $operation, $input, $this->operationNamed(...), $this->answeredTwice(...))?->named() !== true) {
            return $heard($run());
        }

        return $heard($this->underAGrant($context, $operation, $input, $run, $answered));
    }

    /**
     * THE ACT A STANDING GRANT COVERS IS WRITTEN DOWN BEFORE IT RUNS, AND LOOKED AT AGAIN AFTER (decisions/0615).
     *
     * Before: the judgment is asked AGAIN with the book locked, and the line that says under which grant, on which
     * content and on which receipts is written under that same lock — a suspension that landed after the first
     * reading stops the act, and if the line cannot be written nothing runs. After: what it acted on is read again,
     * and the act says what it consumed; if either is not what was judged, the grant is suspended.
     *
     * @template T
     *
     * @param array<string, mixed>      $input
     * @param \Closure(): T             $run
     * @param (\Closure(T): mixed)|null $answered how to read the act's own answer out of what the run gave back, when
     *                                            the run is not the act itself; an act that gave none did not return
     *
     * @return T
     *
     * @throws NotAbsorbed when the grant does not stand any more, or the line could not be written: nothing ran
     */
    private function underAGrant(ToolContext $context, Operation $operation, array $input, \Closure $run, ?\Closure $answered = null): mixed
    {
        $standing = $this->standing();
        try {
            $absorbed = $standing->absorbing($context, $operation, $input, $this->operationNamed(...), $this->answeredTwice(...));
        } catch (\RuntimeException $unwritten) {
            throw new NotAbsorbed($unwritten->getMessage(), previous: $unwritten);
        }
        if ($absorbed?->passes() !== true) {
            if ($absorbed !== null) {
                $standing->refused($absorbed);
            }
            throw new NotAbsorbed($absorbed?->refusal() ?? "No standing grant names this call of «{$operation->name}» any more: the one it was judged under is gone. Nothing ran.");
        }
        $result = null;
        $returned = false;
        try {
            $result = $run();
            $returned = true;

            return $result;
        } finally {
            $said = $returned && $answered !== null ? $answered($result) : $result;
            $standing->absorbed($absorbed, $operation, $said, $returned && ($answered === null || $said !== null));
        }
    }

    /**
     * The run itself, once the call is allowed: straight through, or — for an authoring call of a finite caller —
     * in a write-confined trial whose result says how to apply it.
     *
     * @param array<string, mixed> $input
     * @param \Closure(): mixed    $next
     */
    private function carriedOut(Operation $operation, array $input, ToolContext $context, string $name, \Closure $next): mixed
    {
        $recorded = RecordedEdit::usesSource($operation, $input);
        if (($context->hasScope('*') && !$recorded) || !in_array($name, self::BUILD, true)) {
            return $next();
        }
        $prepared = null;
        if ($recorded) {
            $sessions = $this->sessions === null ? null : ($this->sessions)();
            if ($sessions === null) {
                throw new \RuntimeException('Recorded repair requires the host session store.');
            }
            $prepared = (new RecordedEdit($this->root, $sessions))->prepare($input, $context);
        }
        $router = new TrialRouter($this->root, $this->runner, dirname(__DIR__, 2) . '/resources/trial-run.php', confinedTesting: true);
        $plan = $router->planFor($operation, $input);
        if ($plan === null) {
            throw new \RuntimeException('This authoring call cannot run in a confined trial.');
        }
        if ($prepared !== null) {
            RecordedEdit::assertBaseline($plan->workspace->copy, $prepared['provenance']['subject'], $prepared['provenance']['baseline_sha256']);
        }
        $run = $this->runner->run(
            $plan->workspace,
            $prepared === null ? $operation->name : 'implement',
            $prepared['input'] ?? $input,
            $this->writePaths($context, $name, $input)
        );
        if (!$run->ok()) {
            return ['ok' => false, 'error' => $run->output['error'] ?? ($run->stderr !== '' ? $run->stderr : 'the trial did not succeed'),
                'workspace' => $plan->workspace->id, 'output' => $run->output,
                ...($prepared === null ? [] : ['repair' => $prepared['provenance']])];
        }
        $data = ['ok' => true, 'ran_in_trial' => true, 'applied' => false, 'workspace' => $plan->workspace->id,
            'changed' => array_map(static fn (array $entry): string => $entry['status'], $run->report), 'output' => $run->output];
        if ($prepared !== null) {
            $data['repair'] = $prepared['provenance'];
        }
        if ($run->report !== []) {
            $data['to_apply'] = ['operation' => 'sandbox:promote', 'arguments' => ['workspace' => $plan->workspace->id]];
        }
        return $data;
    }

    /**
     * Inspect every path before a handler may write the first one.
     *
     * @param array<string, mixed> $arguments
     */
    private function checkExport(ToolContext $context, string $name, array $arguments): void
    {
        $id = $arguments['workspace'] ?? null;
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]+$/D', $id)) {
            throw new \RuntimeException('A workspace must name one trial directory.');
        }
        $base = $this->root . '/var/trials/' . $id;
        if ($name === 'sandbox_promote') {
            // ALREADY PROMOTED IS AN ANSWER, AND THE DOOR LETS IT BE GIVEN (greenhouse decisions/0586). A trial the
            // house applied has collapsed; asking for its promotion again is answered by the handler, which writes
            // nothing — to whoever holds the write set those same paths ask for, exactly as undoing it would.
            $already = TrialWorkspace::open($this->root, $id) === null ? TrialWorkspace::promotedPaths($this->root, $id) : null;
            if ($already !== null) {
                $this->authorizePaths($context, $already, $base . '/pre');
                return;
            }
            $workspace = $this->workspace($arguments);
            $this->authorizePaths($context, array_keys($workspace->diff()), $workspace->copy);
            return;
        }
        $record = json_decode((string) @file_get_contents($base . '/promoted.json'), true);
        if (!is_array($record) || $record === []) {
            throw new \RuntimeException('No recorded promotion to undo.');
        }
        $this->authorizePaths($context, array_keys($record), $base . '/pre');
    }

    /**
     * Reject the entire export when any source or destination is outside the current write set.
     *
     * @param list<string> $paths
     */
    public function authorizePaths(ToolContext $context, array $paths, string $source): void
    {
        foreach ($paths as $path) {
            $this->regularPath($this->root, $path);
            $this->regularPath($source, $path);
            if ($context->hasScope('*')) {
                continue;
            }
            // `plugins:register` is rehearsed like every other reversible local mutation, but its
            // artifact is the house's explicit plugin list rather than a file below one plugin's
            // source tree. Promotion must preserve that operation's own narrow authority: the
            // principal that may write plugin configuration may export this one file, and nothing
            // else under config/. Without this branch the trial truthfully returned a
            // sandbox:promote next step that could never cross the same boundary.
            if ($path === 'config/plugins.php') {
                if (!$context->hasScope('plugins.config:write')) {
                    throw new MissingPermission('plugins.config:write', "Missing required permission 'plugins.config:write' for plugin configuration.");
                }

                continue;
            }
            // The plugin switch crosses the same way (greenhouse decisions/0532). `plugins.enable` / `plugins.disable`
            // write the registry the boot reads, and `plugins.lock` writes the lock — all three under
            // `plugins:write`. Since 0530 they run inside a leg's trial, and without this branch a seat could
            // switch a plugin in the rehearsal and never in the house. The same authority, and nothing else.
            if (isset(self::PLUGIN_STATE[$path])) {
                if (!$context->hasScope('plugins:write')) {
                    throw new MissingPermission('plugins:write', "Missing required permission 'plugins:write' for the plugin " . self::PLUGIN_STATE[$path] . '.');
                }

                continue;
            }
            // The same shape for the house's declared screens (greenhouse decisions/0463): a screen is
            // rehearsed in a trial like any work and crosses on promotion — with the authority of the
            // operation that writes it, and nothing else under config/.
            if ($path === \Milpa\AppRuntime\Web\ScreenStore::DEFAULT_PATH) {
                if (!$context->hasScope(self::SCREEN_SCOPE)) {
                    throw new MissingPermission(self::SCREEN_SCOPE, "Missing required permission '" . self::SCREEN_SCOPE . "' for declared screens.");
                }

                continue;
            }
            // The house's visual language crosses the same way (decisions/0465), with the authority of
            // the operation that writes it.
            if ($path === \Milpa\AppRuntime\Web\ComponentWords::PATH) {
                if (!$context->hasScope(\Milpa\AppRuntime\Web\ComponentWordOperations::SCOPE)) {
                    throw new MissingPermission(\Milpa\AppRuntime\Web\ComponentWordOperations::SCOPE, "Missing required permission '" . \Milpa\AppRuntime\Web\ComponentWordOperations::SCOPE . "' for the house's words.");
                }

                continue;
            }
            if (!preg_match('~^(?:src|tests)/Plugins/([A-Za-z_][A-Za-z0-9_]*)/~D', $path, $match)) {
                throw new \RuntimeException("Export '{$path}' is outside a plugin write set.");
            }
            $this->requirePlugin($context, $match[1]);
        }
    }

    /** @param array<string, mixed> $arguments */
    private function workspace(array $arguments): TrialWorkspace
    {
        $id = $arguments['workspace'] ?? null;
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]+$/D', $id)) {
            throw new \RuntimeException('A workspace must name one trial directory.');
        }
        $workspace = TrialWorkspace::open($this->root, $id);
        if ($workspace === null) {
            throw new \RuntimeException("No trial '{$id}' to inspect.");
        }
        return $workspace;
    }

    /** Require a canonical resource and the exact Permission-compatible scope for it. */
    private function requirePlugin(ToolContext $context, mixed $plugin): string
    {
        if (!is_string($plugin) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $plugin)) {
            throw new \RuntimeException('Scoped authoring requires one canonical plugin name.');
        }
        $permission = 'plugins.' . $plugin . ':write';
        // THE BUILDING PERMIT OF A SEAT IS WHAT THE LEDGER SAYS NOW (greenhouse decisions/0590, rule 10). An
        // admission closes it for every seat, and a leg that started before carries the word until it ends: read
        // from that context, a seat could go on writing a capability a person had just admitted by its contract.
        $closed = $this->closedFor($context, $permission);
        if (!$context->hasScope($permission) || $closed !== null) {
            $message = "Missing required permission '{$permission}' for plugin '{$plugin}'.";
            if ($closed !== null) {
                $message .= \sprintf(
                    ' Its building permit was closed when «%s» was admitted — by %s, %s: a capability is in works or admitted, never both.'
                    . ' A person reopens the works by granting it again over the existing plugin; while it stands, no seat uses the verbs of «%1$s».',
                    $plugin,
                    $closed['closed_by'],
                    $closed['at'],
                );
            }
            $matching = [];
            foreach (array_filter($context->scopes, 'is_string') as $scope) {
                if (preg_match('/^plugins\.([A-Za-z_][A-Za-z0-9_]*):write$/D', $scope, $parts) !== 1) {
                    continue;
                }
                if ($parts[1] !== $plugin && strcasecmp($parts[1], $plugin) === 0) {
                    $matching[$scope] = true;
                }
            }
            if (count($matching) === 1) {
                $scope = array_key_first($matching);
                $message .= " Plugin identifiers and grants are case-sensitive. The current grant is '{$scope}'."
                    . ' Verify the installed plugin identifier before requesting a different permission.';
            }
            // A fact of the disk, not a guess about the goal: this policy never reads the session. The
            // skeleton only shows `<Something>Plugin` directories, and a resident told to build «a plugin
            // named Blog» asked for `BlogPlugin` three times on the bare refusal (evidence/1028).
            if (!$this->pluginExists($plugin)) {
                $message .= " No plugin '{$plugin}' exists in this house yet. A new plugin takes exactly the name the task gives it.";
            }
            throw new MissingPermission($permission, $message, $plugin);
        }
        return $plugin;
    }

    /**
     * The closure that took this permit from the caller's seat and that no grant has undone — or null: the caller
     * is no seat of this ledger, its ledger entry holds the permit now, or no admission ever closed it.
     *
     * Only a key this ledger enrolled is asked: whoever carries the word from elsewhere — the terminal's operator,
     * a passkey, a key recognized by static policy — is judged by what its context holds, as before.
     *
     * @return array{permit: string, capability: string, closed_by: string, at: string, admitted: array{seat: string, scope: string}}|null
     */
    private function closedFor(ToolContext $context, string $permission): ?array
    {
        $principal = $context->principal;
        if (!\is_string($principal) || !str_starts_with($principal, 'key:')) {
            return null;
        }
        $seat = substr($principal, 4);
        $ledger = new \Milpa\AppRuntime\Identity\FileEnrollmentStore($this->root . '/storage/identity/enrollments.json');
        try {
            $scopes = \Milpa\AppRuntime\Identity\IdentityKey::isFingerprint($seat) ? $ledger->scopesFor($seat) : null;
            if ($scopes === null || \in_array($permission, $scopes, true)) {
                return null;
            }
            $last = null;
            foreach ($ledger->closuresFor($seat) as $closure) {
                if ($closure['permit'] === $permission) {
                    $last = $closure;
                }
            }

            return $last;
        } catch (\Throwable) {
            return null; // a ledger that cannot be read closes nothing: the context's own word is still asked
        }
    }

    /**
     * Whether the house already has this plugin: its source directory, the one its write scope would open.
     */
    public function pluginExists(string $plugin): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $plugin) === 1 && is_dir($this->root . '/src/Plugins/' . $plugin);
    }

    /** Refuse traversal, aliases and links in either the source or destination tree. */
    private function regularPath(string $base, string $relative): void
    {
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\') || str_contains($relative, "\0")) {
            throw new \RuntimeException('An authoring path must be relative to the house.');
        }
        $path = $base;
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \RuntimeException('An authoring path must not contain traversal or empty segments.');
            }
            $path .= '/' . $segment;
            if (is_link($path)) {
                throw new \RuntimeException("Authoring refuses a symbolic link at '{$relative}'.");
            }
        }
    }
}
