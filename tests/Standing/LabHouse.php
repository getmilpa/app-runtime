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

namespace Milpa\AppRuntime\Tests\Standing;

use Milpa\AppRuntime\Agent\PluginAuthoringPolicy;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\AppRuntime\Standing\HouseStandingJudge;
use Milpa\AppRuntime\Standing\StandingBook;
use Milpa\AppRuntime\Standing\StandingLedger;
use Milpa\AppRuntime\Standing\StandingOperations;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Console\McpProjector;
use Milpa\Console\OperationBoundary;
use Milpa\Container\DIContainer;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Identity\GrantedAuthorization;
use Milpa\ToolRuntime\Identity\OperationAuthorization;
use Milpa\ToolRuntime\Identity\SignatureVerifier;
use Milpa\ToolRuntime\Identity\VerifiedSigner;
use Milpa\ToolRuntime\PolicyGate;
use Milpa\ToolRuntime\ToolDefinition;

/**
 * A house made for these tests: a person, two seats, a release line of made-up operations, and the door every
 * call goes through — the gate's question about a word, then the house's policy, then the boundary.
 *
 * Nothing is signed with a real key. A signature here is a string that only verifies for the bytes it was made
 * over and the key it names, and a key can be taken out of the keyring — which is everything the judge asks of a
 * verifier. Time is a clock the test moves.
 */
final class LabHouse implements SignatureVerifier
{
    public const PERSON = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
    public const SEAT = 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';
    public const OTHER_SEAT = 'CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC';
    public const STRANGER = 'DDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDD';

    public const PERSON_SCOPES = ['identity:enroll', 'lab:publish', 'lab:merge', 'lab:announce', 'lab:scan', 'lab:watch'];
    public const SEAT_SCOPES = ['agent:run', 'lab:scan', 'lab:watch'];

    public string $root;

    public \DateTimeImmutable $now;

    public PluginAuthoringPolicy $policy;

    public DIContainer $container;

    /** What the scanner answers, so a test can replace the instrument without touching the candidate. */
    public \Closure $scanner;

    /** Whether the lab's scanner says which bytes it read. An instrument that does not, measures nothing. */
    public bool $scannerSaysWhatItRead = true;

    /** Whether the falsifier of the lab grant has fired — or something that is not an answer at all. */
    public mixed $fired = false;

    /** Called by `lab:publish` while it runs, so a test can change what it acts on under it. */
    public ?\Closure $whilePublishing = null;

    /** What a handler of this house was handed as its caller's authority, when a test asked it to keep it. */
    public ?ToolContext $seen = null;

    /**
     * Whether every call through this house's door is made by a process that BOOTS FOR IT — which is what a command
     * typed at a terminal is. A test of a process that lives — a leg, a server — turns this off and says when that
     * process boots ({@see self::boots()}): what a process loaded is what it read when it was told its catalogue.
     */
    public bool $aProcessPerCall = true;

    /** What `lab:publish` says it consumed instead of what it read — a test's way of swapping the bytes under an act. */
    public ?string $consumed = null;

    /** @var list<string> keys this keyring no longer verifies */
    private array $gone = [];

    /** @var list<Operation> */
    private array $operations;

    public function __construct(?\Milpa\AppRuntime\Agent\TrialRunner $runner = null)
    {
        $this->root = sys_get_temp_dir() . '/milpa-standing-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/storage/identity', 0o755, true);
        $this->now = new \DateTimeImmutable('2026-10-11T12:00:00+00:00');
        $this->scanner = static fn (string $bytes): array => ['ok' => !str_contains($bytes, 'machine-name:')];
        $this->installed();

        $this->enroll(self::PERSON, self::PERSON_SCOPES, self::PERSON);
        $this->enroll(self::SEAT, self::SEAT_SCOPES, self::PERSON);
        $this->enroll(self::OTHER_SEAT, ['agent:run'], self::PERSON);

        $this->container = new DIContainer();
        $this->policy = new PluginAuthoringPolicy($this->root, ...($runner === null ? [] : ['runner' => $runner]), standing: new HouseStandingJudge($this->root, $this, fn (): \DateTimeImmutable => $this->now));
        $this->container->registerService(OperationBoundary::class, $this->policy);
        $this->operations = PluginAuthoringPolicy::catalogue($this->container, [
            ...$this->releaseLine(),
            ...(new StandingOperations($this->container, $this->root, $this))->operations(),
        ]);
    }

    public function destroy(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /**
     * What this house says it installed, the way Composer writes it down: the lab's operations ship in a package, and
     * so does the runtime. An operation is pinned by the unit it ships in, and one that ships in none is not granted.
     * A test hands another reference to say «the package was updated», and none to say «nothing here was installed».
     */
    public function installed(?string $lab = 'lab-1'): void
    {
        @mkdir($this->root . '/vendor/composer', 0o755, true);
        file_put_contents($this->root . '/vendor/composer/installed.json', (string) json_encode(['packages' => $lab === null ? [] : [
            ['name' => 'lab/release-line', 'version' => '1.0.0', 'dist' => ['reference' => $lab], 'install-path' => __DIR__],
            ['name' => 'milpa/app-runtime', 'version' => 'dev-lab', 'source' => ['reference' => 'the-checkout-under-test'], 'install-path' => \dirname(__DIR__, 2)],
        ]], \JSON_UNESCAPED_SLASHES));
    }

    /** @param list<string> $scopes */
    public function enroll(string $key, array $scopes, string $by): void
    {
        (new FileEnrollmentStore($this->root . '/storage/identity/enrollments.json'))->record(new IdentityEnrolled($key, $scopes, 'key:' . $by));
    }

    public function revokeKey(string $key): void
    {
        $this->gone[] = $key;
    }

    public function candidate(string $name, string $bytes): string
    {
        file_put_contents($this->root . '/' . $name, $bytes);

        return $name;
    }

    public function digestOf(string $name): string
    {
        return 'sha256:' . hash_file('sha256', $this->root . '/' . $name);
    }

    public function later(int $seconds): void
    {
        $this->now = $this->now->modify("+{$seconds} seconds");
    }

    /**
     * The grant these tests give, as the document a person writes. `$change` replaces top-level keys.
     *
     * @param array<string, mixed> $change
     */
    public function document(array $change = []): string
    {
        return (string) json_encode($change + [
            'id' => 'release-line',
            'to' => [self::SEAT],
            'covers' => [['operation' => 'lab:publish', 'arguments' => ['channel' => 'stable']], ['operation' => 'lab:merge'], ['operation' => 'lab:announce']],
            'ceiling' => ['mutation' => 'persistent', 'externality' => 'public', 'reversibility' => 'compensatable', 'authority' => 'write_as_user', 'subject' => 'executable'],
            'conditions' => [['name' => 'no-host-names', 'statement' => 'what is published names no machine', 'by' => ['operation' => 'lab:scan', 'free' => ['path']]]],
            'undo' => 'withdraw the release and publish the one before it',
            'falsifier' => ['name' => 'a-published-name', 'statement' => 'a published release names a machine', 'watcher' => ['operation' => 'lab:watch'], 'within_seconds' => 3600],
        ]);
    }

    /**
     * A person gives the lab grant, and its watcher gives a first sign of life.
     *
     * @param array<string, mixed> $change
     */
    public function granted(array $change = [], bool $watched = true): void
    {
        $result = $this->signed(self::PERSON, 'standing:grant', ['grant' => $this->document($change)]);
        if (($result['ok'] ?? false) !== true) {
            throw new \LogicException('the lab grant was not given: ' . (string) ($result['error'] ?? json_encode($result)));
        }
        if ($watched) {
            $this->as(self::SEAT, 'lab:watch');
        }
    }

    /**
     * One call through the whole door, as this key — or as the terminal's own operator when `$key` is null.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed> what the handler returned, or `['refused' => sentence]`
     */
    public function as(?string $key, string $name, array $input = [], ?GrantedAuthorization $signature = null): array
    {
        return $this->through($this->contextOf($key), $name, $input, $signature);
    }

    /** Who a key is in this house right now: what the ledger says it holds, or the terminal's own authority. */
    public function contextOf(?string $key): ToolContext
    {
        return $key === null
            ? ToolContext::cli()
            : new ToolContext(
                principal: 'key:' . $key,
                channel: 'cli',
                scopes: (new FileEnrollmentStore($this->root . '/storage/identity/enrollments.json'))->scopesFor($key) ?? ($key === self::STRANGER ? ['*'] : []),
            );
    }

    /**
     * One call through the whole door with THIS context, the way every surface makes one: the caller as it is to be
     * seen for this tool and these arguments, the gate's question about a word, the house's policy, the boundary.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed> what the handler returned, or `['refused' => sentence]`
     */
    public function through(ToolContext $context, string $name, array $input = [], ?GrantedAuthorization $signature = null): array
    {
        if ($this->aProcessPerCall && !$this->atTheBoundary) {
            $this->boots();
        }
        $operation = $this->operation($name);
        $tool = McpProjector::toolName($name);
        $context = $this->policy->contextFor($context, $tool, $input);
        // As the terminal does before its runner asks the gate: a seat lent nothing reads why, in the gate's refusal.
        $cited = $this->policy->refusalAtTheGate($context, $tool, $operation->scopes, $input);
        if ($cited !== null) {
            return ['refused' => $cited];
        }

        $gate = new PolicyGate();
        $gate->setCallPolicy($this->policy);
        $verdict = $gate->authorizeCall($context, new ToolDefinition($tool, $operation->description, $operation->inputSchema ?? [], $operation->handler, scopes: $operation->scopes, mutating: $operation->mutating), $input);
        if (!$verdict->allowed) {
            return ['refused' => (string) $verdict->reason];
        }

        if ($signature !== null) {
            $this->container->replaceService(GrantedAuthorization::class, $signature);
        }
        $outer = $this->atTheBoundary;
        $this->atTheBoundary = true;
        try {
            $result = $this->policy->execute($operation, $input, $context, fn (): mixed => ($operation->handler)($input, null, $context));
        } catch (\RuntimeException $refused) {
            return ['refused' => $refused->getMessage()];
        } finally {
            $this->atTheBoundary = $outer;
            $this->container->replaceService(GrantedAuthorization::class, new \stdClass());
        }

        // What the caller reads back, without the digest an operation reports for the HOUSE to read (`acted_on`): the
        // boundary has already read it, and these tests assert on what a caller sees.
        if (\is_array($result)) {
            unset($result['acted_on']);
        }

        return \is_array($result) ? $result : ['result' => $result];
    }

    /**
     * A process of this house boots NOW: it forgets what another one had read, and is told the catalogue — in a house
     * that keeps a book, that is when it reads what each operation is, which is what it then says it loaded.
     */
    public function boots(): void
    {
        (function (): void {
            $this->loaded->exchangeArray([]);
            $this->booted->exchangeArray([]);
        })->call($this->policy);
        PluginAuthoringPolicy::catalogue($this->container, $this->operations);
    }

    /** Operations added to this house's catalogue after it was built. */
    public function offer(Operation ...$operations): void
    {
        PluginAuthoringPolicy::catalogue($this->container, array_values($operations));
        $names = array_map(static fn (Operation $operation): string => $operation->name, $operations);
        $this->operations = [...array_values(array_filter($this->operations, static fn (Operation $known): bool => !\in_array($known->name, $names, true))), ...array_values($operations)];
    }

    /**
     * Operations a second provider declares: the catalogue is told again WHOLE, with these beside what it held — which
     * is how two operations come to answer to one identity (`lab:scan` and `lab_scan` are one).
     */
    public function offerBeside(Operation ...$operations): void
    {
        $this->operations = PluginAuthoringPolicy::catalogue($this->container, [...$this->operations, ...array_values($operations)]);
    }

    /**
     * A grant as it stands in the book when it did NOT come through `standing:grant`: genuinely signed by this key,
     * appended by whoever holds the disk. What `standing:grant` refuses to write is what this lets a test write.
     *
     * @param array<string, mixed> $change
     * @param array<string, mixed> $line   what the line says besides the signed bytes
     */
    public function grantedByHand(array $change = [], string $signer = self::PERSON, array $line = []): void
    {
        $document = $this->document($change);
        $grant = \Milpa\Command\Standing\StandingGrant::fromJson($document);
        $signed = $this->signature($signer, 'standing:grant', ['grant' => $document]);
        // What the door writes beside a grant: what each operation it is given over IS, that day.
        $pin = fn (\Milpa\Command\Standing\NamedCall $named): array => [$named->operation->canonical => (string) \Milpa\AppRuntime\Standing\OperationPin::of($this->operation($named->operation->forCli()), $this->root)];
        $this->writtenByHand(StandingLedger::GRANTED, $line + [
            'grant' => $grant->id,
            'digest' => $grant->digest(),
            'payload' => $signed->payload,
            'signature' => $signed->signature,
            'fingerprint' => $signer,
            'operations' => array_merge(...array_map($pin, $grant->covers)),
            'instruments' => array_merge(...array_map($pin, [...array_map(static fn (\Milpa\Command\Standing\GrantCondition $condition): \Milpa\Command\Standing\NamedCall => $condition->by, $grant->conditions), $grant->falsifier->watcher])),
            'recognized' => $this->book()->recognitions([...$grant->to, $signer]),
        ]);
    }

    /**
     * A call this key signs for itself, the way the terminal hands a handler the signature it ran under.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function signed(string $key, string $name, array $input): array
    {
        return $this->as($key, $name, $input, $this->signature($key, $name, $input));
    }

    /** @param array<string, mixed> $arguments */
    public function signature(string $key, string $operation, array $arguments, ?string $host = null): GrantedAuthorization
    {
        $authorization = new OperationAuthorization($operation, $arguments, $host ?? (gethostname() ?: 'unknown-host'), $this->now->format(\DATE_ATOM), bin2hex(random_bytes(8)));

        return new GrantedAuthorization($authorization, new VerifiedSigner($key, 'Lab <lab@example.invalid>'), $authorization->canonical(), self::sign($key, $authorization->canonical()));
    }

    /** Called every time this keyring is asked to verify — a test's way of doing something while the book is being read. */
    public ?\Closure $whileVerifying = null;

    /** True while a call is inside the boundary — past the gate's question, where the act is decided and run. */
    public bool $atTheBoundary = false;

    public function verify(string $payload, string $signature): ?VerifiedSigner
    {
        if ($this->whileVerifying !== null) {
            ($this->whileVerifying)();
        }
        foreach ([self::PERSON, self::SEAT, self::OTHER_SEAT, self::STRANGER] as $key) {
            if (!\in_array($key, $this->gone, true) && hash_equals(self::sign($key, $payload), $signature)) {
                return new VerifiedSigner($key);
            }
        }

        return null;
    }

    public function ledger(): StandingLedger
    {
        return StandingLedger::forRoot($this->root);
    }

    public function book(): StandingBook
    {
        return StandingBook::forRoot($this->root, $this);
    }

    /**
     * The lines of the book of one kind, as data.
     *
     * @return list<array<string, mixed>>
     */
    public function lines(string $type): array
    {
        return array_values(array_map(
            static fn (array $entry): array => $entry['data'],
            array_filter($this->ledger()->entries(), static fn (array $entry): bool => $entry['type'] === $type),
        ));
    }

    /** What the acts left behind: the digests that went through `lab:publish`, `lab:merge` or `lab:announce`. */
    public function done(string $what): array
    {
        $log = @file($this->root . '/var/lab-' . $what . '.log', \FILE_IGNORE_NEW_LINES);

        return $log === false ? [] : $log;
    }

    /**
     * A line appended by whoever holds the disk, with a link that verifies — what the chain does not stop.
     *
     * @param array<string, mixed> $data
     */
    public function writtenByHand(string $type, array $data): void
    {
        $this->ledger()->append($type, $data, $this->now);
    }

    /**
     * The scanner is replaced by a stricter one under the same name: another handler, so another instrument.
     */
    public function hardenScanner(): void
    {
        $old = $this->operation('lab:scan');
        $hardened = new Operation(
            'lab:scan',
            $old->description,
            function (array $input): array {
                $bytes = (string) file_get_contents($this->root . '/' . $input['path']);

                return ['ok' => !preg_match('/machine-name:|host:/', $bytes), 'acted_on' => 'sha256:' . hash('sha256', $bytes)];
            },
            $old->inputSchema,
            scopes: $old->scopes,
            effects: $old->effects,
            actsOn: $old->actsOn,
        );
        PluginAuthoringPolicy::catalogue($this->container, [$hardened]);
        $this->operations = array_map(static fn (Operation $operation): Operation => $operation->name === 'lab:scan' ? $hardened : $operation, $this->operations);
    }

    public function operation(string $name): Operation
    {
        foreach ($this->operations as $operation) {
            if ($operation->name === $name) {
                return $operation;
            }
        }
        throw new \LogicException("{$name} is not offered");
    }

    /**
     * What the lab's scanner answers: whatever the test's scanner says, and — unless that scanner says otherwise —
     * the digest of the very bytes it was handed.
     *
     * @return array<string, mixed>
     */
    private function scan(string $bytes): array
    {
        $said = ($this->scanner)($bytes);

        return $this->scannerSaysWhatItRead ? $said + ['acted_on' => 'sha256:' . hash('sha256', $bytes)] : $said;
    }

    private static function sign(string $key, string $payload): string
    {
        return 'lab-signature:' . $key . ':' . hash('sha256', $payload);
    }

    /** @return list<Operation> */
    private function releaseLine(): array
    {
        $actsOn = fn (array $input): ?string => is_file($this->root . '/' . ($input['path'] ?? '')) ? $this->digestOf((string) $input['path']) : null;
        $act = function (string $what, array $input): array {
            if ($what === 'published' && $this->whilePublishing !== null) {
                ($this->whilePublishing)($input);
            }
            @mkdir($this->root . '/var', 0o755, true);
            $consumed = (string) hash_file('sha256', $this->root . '/' . $input['path']);
            file_put_contents($this->root . '/var/lab-' . $what . '.log', $consumed . "\n", \FILE_APPEND);

            return ['ok' => true, 'acted_on' => $what === 'published' && $this->consumed !== null ? $this->consumed : 'sha256:' . $consumed];
        };
        $path = ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']];
        $effect = static fn (Reversibility $reversibility, Subject $subject): EffectProfile => new EffectProfile(Mutation::Persistent, Externality::Public, $reversibility, Authority::WriteAsUser, subject: $subject);

        return [
            new Operation('lab:scan', 'Lab: measure a candidate', fn (array $input): array => $this->scan((string) file_get_contents($this->root . '/' . $input['path'])), $path, scopes: ['lab:scan'], effects: EffectProfile::readOnly(), actsOn: $actsOn),
            new Operation('lab:watch', 'Lab: watch the falsifier', fn (array $input): mixed => \is_bool($this->fired) ? ['ok' => true, 'fired' => $this->fired] : $this->fired, scopes: ['lab:watch'], effects: EffectProfile::readOnly()),
            new Operation('lab:publish', 'Lab: publish a candidate', fn (array $input): array => $act('published', $input), $path, mutating: true, requiresConfirmation: true, scopes: ['lab:publish'], effects: $effect(Reversibility::Compensatable, Subject::Executable), actsOn: $actsOn),
            new Operation('lab:merge', 'Lab: merge a change that only adds evidence', fn (array $input): array => $act('merged', $input), $path, mutating: true, requiresConfirmation: true, scopes: ['lab:merge'], effects: $effect(Reversibility::Compensatable, Subject::Data), actsOn: $actsOn),
            new Operation('lab:announce', 'Lab: announce a release', fn (array $input): array => $act('announced', $input), $path, mutating: true, requiresConfirmation: true, scopes: ['lab:announce'], effects: $effect(Reversibility::Irreversible, Subject::Data), actsOn: $actsOn),
            // What a grant must NOT be given over, each for its own reason.
            new Operation('lab:enroll', 'Lab: an operation that gives authority', static fn (array $input): array => ['ok' => true], $path, mutating: true, scopes: ['identity:enroll'], effects: $effect(Reversibility::Compensatable, Subject::Configuration), actsOn: $actsOn),
            new Operation('lab:blind', 'Lab: an operation that does not say what it acts on', static fn (array $input): array => ['ok' => true], $path, mutating: true, scopes: ['lab:blind'], effects: $effect(Reversibility::Compensatable, Subject::Data)),
            new Operation('lab:wordless', 'Lab: an operation that declares no scope', static fn (array $input): array => ['ok' => true], $path, effects: EffectProfile::readOnly(), actsOn: $actsOn),
            new Operation('lab:undeclared', 'Lab: an operation that does not declare its effects', static fn (array $input): array => ['ok' => true], $path, mutating: true, scopes: ['lab:undeclared'], actsOn: $actsOn),
        ];
    }
}
