<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\EffectObservation;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\RecordedEdit;
use Milpa\AppRuntime\Agent\PluginAuthoringPolicy;
use Milpa\Command\Operation;
use Milpa\DevTools\Operations\EditHandler;
use Milpa\DevTools\Operations\ImplementationBody;
use Milpa\EventStore\Event;
use Milpa\EventStore\InMemoryEventStore;
use Milpa\ToolRuntime\Contracts\ToolContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecordedEditTest extends TestCase
{
    private string $root;
    private array $fixture;
    private SessionStore $sessions;
    private const SESSION = 'desk-00000000000818ac';
    private const SUBJECT = 'src/Plugins/Owned/Services/TodoItemRenderer.php';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/recorded-edit-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src/Plugins/Owned/Services', 0o700, true);
        $this->fixture = json_decode((string) file_get_contents(__DIR__ . '/../Fixtures/recorded-edit-origin.json'), true, flags: JSON_THROW_ON_ERROR);
        file_put_contents($this->root . '/' . self::SUBJECT, $this->fixture['host']);
        $this->reload($this->fixture['events']);
    }

    private function reload(array $events): void
    {
        $store = new InMemoryEventStore();
        foreach ($events as $event) {
            $store->append(Event::fromArray($event));
        }
        $this->sessions = new SessionStore($store);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function arguments(): array
    {
        $body = $this->fixture['events'][2]['payload']['arguments']['content'];
        return ['plugin' => 'Owned', 'class' => 'TodoItemRenderer',
            'source' => ['session' => self::SESSION, 'seq' => 90, 'sha256' => hash('sha256', $body)],
            'edits' => [...$this->fixture['repairs']['locale'], ...$this->fixture['repairs']['form']]];
    }

    private function prepare(?array $arguments = null, array $scopes = ['agent:read', 'plugins.Owned:write']): array
    {
        return (new RecordedEdit($this->root, $this->sessions))->prepare($arguments ?? $this->arguments(), new ToolContext(scopes: $scopes));
    }

    public function testNativeRejectedSourceProducesOnlyTheRepairedImplementation(): void
    {
        $before = $this->sessions->stream(self::SESSION);
        $prepared = $this->prepare();
        self::assertSame($this->fixture['repaired_sha256'], hash('sha256', $prepared['input']['content']));
        self::assertSame(['plugin', 'class', 'content'], array_keys($prepared['input']));
        self::assertSame(self::SUBJECT, $prepared['provenance']['subject']);
        self::assertSame(hash('sha256', $this->fixture['host']), $prepared['provenance']['baseline_sha256']);
        self::assertSame($this->fixture['host'], file_get_contents($this->root . '/' . self::SUBJECT));
        self::assertSame($before, $this->sessions->stream(self::SESSION));
        self::assertDirectoryDoesNotExist($this->root . '/var');
    }

    /** Record a synthetic second rejection to test reference derivation, not to claim another judgment. */
    private function rejectRepair(array $arguments): array
    {
        $prepared = $this->prepare($arguments);
        $body = $prepared['input']['content'];
        $workspace = 'w' . bin2hex(random_bytes(8));
        $this->sessions->recordTrialRun(self::SESSION, ['workspace' => $workspace, 'operation' => 'edit',
            'arguments_digest' => EffectObservation::argumentsDigest($arguments), 'exit' => 1, 'report' => [],
            'execution' => ['operation' => 'implement', 'arguments_digest' => EffectObservation::argumentsDigest($prepared['input']),
                'repair' => $prepared['provenance']]]);
        $witness = $this->sessions->recordEffectObservation(
            self::SESSION,
            'edit',
            $arguments,
            new EffectObservation('unit-rejection-fixture', true, diagnostics: [hash('sha256', $body)])
        );
        $result = json_decode($this->fixture['events'][2]['payload']['result'], true, flags: JSON_THROW_ON_ERROR);
        $result['workspace'] = $workspace;
        $result['output']['diagnostic']['submitted_sha256'] = hash('sha256', $body);
        $result['output']['diagnostic']['judged_sha256'] = hash('sha256', ImplementationBody::normalize($body, self::SUBJECT));
        $raw = json_encode($result, JSON_THROW_ON_ERROR);
        $seq = $this->sessions->recordToolCall(
            self::SESSION,
            'edit',
            $arguments,
            $raw,
            false,
            true,
            resultChars: mb_strlen($raw),
            awaitingConfirmation: false,
            effectObservationSeq: $witness
        );
        return ['session' => self::SESSION, 'seq' => $seq, 'sha256' => hash('sha256', $body)];
    }

    public function testARejectedPartialRepairCanBeRepairedWithoutRepeatingTheWholeBody(): void
    {
        $args = $this->arguments();
        $args['edits'] = $this->fixture['repairs']['locale'];
        $reference = $this->rejectRepair($args);
        $args['source'] = $reference;
        $args['edits'] = $this->fixture['repairs']['form'];
        self::assertSame($this->fixture['repaired_sha256'], hash('sha256', $this->prepare($args)['input']['content']));
        self::assertArrayNotHasKey('content', $args);
    }

    public function testSourceChainHasAFiniteDepth(): void
    {
        $args = $this->arguments();
        $args['edits'] = [['find' => 'declare(strict_types=1);', 'replace' => 'declare(strict_types=1);']];
        for ($i = 0; $i < RecordedEdit::MAX_DEPTH; ++$i) {
            $args['source'] = $this->rejectRepair($args);
        }
        $this->expectExceptionMessage('depth limit');
        $this->prepare($args);
    }

    public function testDerivedInputBindingIgnoresObjectKeyOrderButRefusesDifferentBytes(): void
    {
        $args = $this->arguments();
        $args['edits'] = $this->fixture['repairs']['locale'];
        $reference = $this->rejectRepair($args);
        $events = array_map(static fn (Event $event): array => $event->toArray(), $this->sessions->stream(self::SESSION));
        foreach ($events as &$event) {
            if (isset($event['payload']['execution'])) {
                $event['payload']['execution']['repair']['source'] = array_reverse($event['payload']['execution']['repair']['source'], true);
            }
        }
        unset($event);
        $this->reload($events);
        $args['source'] = $reference;
        $args['edits'] = $this->fixture['repairs']['form'];
        self::assertSame($this->fixture['repaired_sha256'], hash('sha256', $this->prepare($args)['input']['content']));
        foreach ($events as &$event) {
            if (isset($event['payload']['execution'])) {
                $event['payload']['execution']['arguments_digest'] = str_repeat('0', 64);
            }
        }
        unset($event);
        $this->reload($events);
        $this->expectExceptionMessage('effective implementation input');
        $this->prepare($args);
    }

    #[DataProvider('deniedAuthorities')]
    public function testAuthorityIsCheckedBeforeSourceExistence(array $scopes, string $message): void
    {
        $args = $this->arguments();
        $args['source']['session'] = 'missing';
        $this->expectExceptionMessage($message);
        $this->prepare($args, $scopes);
    }

    public static function deniedAuthorities(): iterable
    {
        yield 'no read' => [['plugins.Owned:write'], 'agent:read or agent:answer'];
        yield 'no write' => [['agent:read'], 'plugins.Owned:write'];
        yield 'other plugin' => [['agent:read', 'plugins.Other:write'], 'plugins.Owned:write'];
    }

    public function testExistingAnswerAndUnrestrictedScopesRetainTheirMeaning(): void
    {
        foreach ([['agent:answer','plugins.Owned:write'], ['*']] as $scopes) {
            self::assertSame($this->fixture['repaired_sha256'], hash('sha256', $this->prepare(scopes: $scopes)['input']['content']));
        }
    }

    #[DataProvider('badReferences')]
    public function testWrongReferencesAndExactPairFailuresRefuse(array $patch, string $message): void
    {
        $this->expectExceptionMessage($message);
        $this->prepare(array_replace_recursive($this->arguments(), $patch));
    }

    public static function badReferences(): iterable
    {
        yield 'session' => [['source' => ['session' => 'missing']], 'tool call'];
        yield 'sequence' => [['source' => ['seq' => 2]], 'tool call'];
        yield 'hash' => [['source' => ['sha256' => str_repeat('0', 64)]], 'source hash'];
        yield 'invalid hash' => [['source' => ['sha256' => 'bad']], 'SHA256'];
        yield 'unknown field' => [['source' => ['path' => '/etc/passwd']], 'requires session'];
        yield 'wrong class' => [['class' => 'Another'], 'this plugin and class'];
        yield 'missing text' => [['edits' => [['find' => 'text absent from retained source']]], 'matches nothing'];
        yield 'ambiguous text' => [['edits' => [['find' => 'public function']]], 'ambiguous'];
    }

    #[DataProvider('brokenReceipts')]
    public function testNativeRejectionLinkageCannotBeInvented(string $damage): void
    {
        $events = $this->fixture['events'];
        if ($damage === 'trial') {
            $events[0]['payload']['arguments_digest'] = str_repeat('0', 64);
        }
        if ($damage === 'witness') {
            $events[1]['payload']['argumentsDigest'] = str_repeat('0', 64);
        }
        if ($damage === 'diagnostic') {
            $events[1]['payload']['observation']['diagnostics'] = [];
        }
        if ($damage === 'confirmed') {
            $events[2]['payload']['awaitingConfirmation'] = true;
        }
        if ($damage === 'accepted') {
            $events[2]['payload']['ok'] = true;
        }
        if ($damage === 'truncated') {
            $events[2]['payload']['resultChars'] = 1;
        }
        if ($damage === 'destination') {
            $result = json_decode($events[2]['payload']['result'], true, flags: JSON_THROW_ON_ERROR);
            $result['output']['diagnostic']['subject'] = 'src/Plugins/Other/Services/TodoItemRenderer.php';
            $events[2]['payload']['result'] = json_encode($result, JSON_THROW_ON_ERROR);
            $events[2]['payload']['resultChars'] = mb_strlen($events[2]['payload']['result']);
        }
        $this->reload($events);
        $this->expectException(\RuntimeException::class);
        $this->prepare();
    }

    public static function brokenReceipts(): iterable
    {
        foreach (['trial','witness','diagnostic','confirmed','accepted','truncated','destination'] as $case) {
            yield $case => [$case];
        }
    }

    public function testAChangedHostCannotBeSilentlyRebased(): void
    {
        file_put_contents($this->root . '/' . self::SUBJECT, '<?php // newer implementation');
        $this->expectExceptionMessage('baseline is stale');
        $this->prepare();
    }

    public function testUnrestrictedCallersStillConfineRecordedRepairsToTheNamedPlugin(): void
    {
        $policy = new PluginAuthoringPolicy($this->root);
        self::assertSame(
            ['src/Plugins/Owned', 'tests/Plugins/Owned'],
            $policy->writePaths(new ToolContext(scopes: ['*']), 'edit', $this->arguments())
        );
        self::assertNull($policy->writePaths(new ToolContext(scopes: ['*']), 'edit', ['plugin' => 'Owned']));
    }

    public function testASymlinkCannotTurnTheDestinationIntoAnotherResource(): void
    {
        rename($this->root . '/' . self::SUBJECT, $this->root . '/outside.php');
        symlink($this->root . '/outside.php', $this->root . '/' . self::SUBJECT);
        $this->expectExceptionMessage('symlinks');
        $this->prepare();
    }

    public function testOnlyTheInstalledEditorGetsTheAdditionalSchemaWithoutChangingAuthority(): void
    {
        $operation = new Operation(
            'edit',
            'Edit a class',
            [EditHandler::class,'handle'],
            inputSchema: ['type' => 'object', 'properties' => ['edits' => ['type' => 'array']], 'required' => ['plugin','class','edits']],
            mutating: true,
            namedTarget: 'class'
        );
        $adapted = RecordedEdit::operation($operation);
        self::assertArrayHasKey('source', $adapted->inputSchema['properties']);
        self::assertSame($operation->handler, $adapted->handler);
        self::assertSame($operation->scopes, $adapted->scopes);
        self::assertSame($operation->effects, $adapted->effects);
        self::assertSame($operation->namedTarget, $adapted->namedTarget);
        self::assertSame($operation->inputSchema['required'], $adapted->inputSchema['required']);
        self::assertTrue(RecordedEdit::usesSource($adapted, $this->arguments()));
        self::assertFalse(RecordedEdit::usesSource($adapted, []));
        $custom = new Operation('edit', 'Application edit', static fn () => []);
        self::assertSame($custom, RecordedEdit::operation($custom));
        self::assertFalse(RecordedEdit::usesSource($custom, $this->arguments()));
    }
}
