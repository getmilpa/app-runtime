<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Web;

use Milpa\AppRuntime\Web\{ScreenDrafts,ScreenStore,ScreenBuild,ScreenDraftOperations,ScreenPreviewRegistry,PreviewEnvironment,ScreenDraftFeature,ScreenComponents,ScreenReviewRenderer};
use Milpa\AppRuntime\Web\Controllers\{ScreenReviewController,ScreenPreviewController};
use Milpa\Auth\{Actor,ActorType,AuthContext};
use Milpa\Auth\Http\AuthenticateMiddleware;
use Milpa\Container\DIContainer;
use Milpa\Runtime\Config;
use Milpa\Live\Runtime\InMemoryComponentRegistry;
use Milpa\Live\Rendering\ComponentRendererRegistry;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\Contracts\Transport\StateTransferCodecInterface;
use Milpa\Live\ValueObjects\{ComponentContract,ComponentContext,StateSnapshot,InteractionRequest,InteractionResult,RenderRequest,RenderResult,RenderTarget};
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class ScreenDraftsTest extends TestCase
{
    private string $root;
    private ScreenStore $active;
    private ScreenDrafts $drafts;
    private string $build = 'build-a';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-drafts-' . bin2hex(random_bytes(6));
        mkdir($this->root);
        $this->active = new ScreenStore($this->root . '/screens.json');
        $this->drafts = new ScreenDrafts($this->active, $this->root . '/drafts', static function (): void {
        }, fn () => $this->build);
    }
    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }
    private function seed(): void
    {
        $this->active->declare(['name' => 'tasks','type' => 'draft-counter','props' => ['locale' => 'en']]);
    }
    private function refuses(string $reason, callable $call): void
    {
        try {
            $call();
            self::fail('Expected refusal: ' . $reason);
        } catch (\DomainException $e) {
            self::assertSame($reason, $e->getMessage());
        }
    }
    public function testImmutableDraftsPromoteAndRestoreOnlyTheReviewedScreen(): void
    {
        $this->seed();
        $before = $this->active->screen('tasks');
        $a = $this->drafts->draft('tasks', 'draft-counter', ['locale' => 'es']);
        $b = $this->drafts->draft('tasks', 'draft-counter', ['locale' => 'en']);
        self::assertNotSame($a['id'], $b['id']);
        self::assertSame($a, $this->drafts->load($a['id']));
        self::assertSame($before, $this->active->screen('tasks'));
        self::assertCount(2, $this->drafts->catalogue()['drafts']);
        $this->active->declare(['name' => 'other','props' => ['rows' => []]]);
        self::assertTrue($this->drafts->review($a['id'])['fresh']);
        $result = $this->drafts->promote($a['id']);
        self::assertSame('promotion', $result['action']);
        self::assertSame($a['definition'], $this->active->screen('tasks'));
        self::assertTrue($this->drafts->review($a['id'])['restorable']);
        self::assertFalse($this->drafts->review($b['id'])['fresh']);
        $this->refuses('base_changed', fn () => $this->drafts->promote($b['id']));
        self::assertSame('rollback', $this->drafts->rollback($a['id'])['action']);
        self::assertSame($before, $this->active->screen('tasks'));
        self::assertNotNull($this->active->screen('other'));
    }
    public function testNewScreenRollbackRemovesOnlyItsDeclaration(): void
    {
        $d = $this->drafts->draft('tasks', 'draft-counter', []);
        $this->drafts->promote($d['id']);
        $this->drafts->rollback($d['id']);
        self::assertNull($this->active->screen('tasks'));
        self::assertTrue($this->drafts->review($d['id'])['fresh']);
    }

    public function testRestorationDoesNotRequireAPreviewFactoryForTheOlderActiveType(): void
    {
        $this->active->declare(['name' => 'tasks', 'type' => 'data-table', 'props' => ['rows' => []]]);
        $before = $this->active->screen('tasks');
        $drafts = new ScreenDrafts($this->active, $this->root . '/drafts', static function (string $name, string $type): void {
            if ($type !== 'draft-counter') {
                throw new \DomainException('preview_not_configured');
            }
        }, fn () => $this->build);
        $d = $drafts->draft('tasks', 'draft-counter', []);
        $drafts->promote($d['id']);
        $drafts->rollback($d['id']);
        self::assertSame($before, $this->active->screen('tasks'));
    }
    public function testChangedBuildRefusesPromotionAndRollback(): void
    {
        $d = $this->drafts->draft('tasks', 'draft-counter', []);
        $this->build = 'build-b';
        $this->refuses('build_changed', fn () => $this->drafts->promote($d['id']));
        $this->refuses('build_changed', fn () => $this->drafts->rollback($d['id']));
        self::assertFalse($this->drafts->review($d['id'])['fresh']);
        self::assertNull($this->active->screen('tasks'));
    }
    public function testChangedActiveBaseRefusesBothDirections(): void
    {
        $this->seed();
        $d = $this->drafts->draft('tasks', 'draft-counter', ['locale' => 'es']);
        $this->active->declare(['name' => 'tasks','props' => ['value' => 'a concurrent declaration']]);
        $this->refuses('base_changed', fn () => $this->drafts->promote($d['id']));
        $this->refuses('base_changed', fn () => $this->drafts->rollback($d['id']));
        self::assertSame('a concurrent declaration', $this->active->screen('tasks')['props']['value']);
    }
    public function testTamperedAndMalformedRevisionsCannotBecomeAbsence(): void
    {
        $d = $this->drafts->draft('tasks', 'draft-counter', []);
        $path = $this->root . '/drafts/' . $d['id'] . '.json';
        file_put_contents($path, '{"definition":"changed"}');
        $this->refuses('revision_changed', fn () => $this->drafts->promote($d['id']));
        file_put_contents($path, '{broken');
        $this->refuses('revision_changed', fn () => $this->drafts->rollback($d['id']));
        $this->refuses('revision_missing', fn () => $this->drafts->load('../screens'));
        $this->refuses('revision_missing', fn () => $this->drafts->load(str_repeat('a', 64)));
        $this->refuses('invalid_name', fn () => $this->drafts->draft('bad/name', 'draft-counter', []));
        self::assertNull($this->active->screen('tasks'));
    }
    public function testCanonicalValuesIgnoreMapOrderButPreserveListOrder(): void
    {
        self::assertSame(ScreenDrafts::hash(['b' => 2,'a' => 1]), ScreenDrafts::hash(['a' => 1,'b' => 2]));
        self::assertNotSame(ScreenDrafts::hash(['a','b']), ScreenDrafts::hash(['b','a']));
    }
    public function testMalformedActiveStoreIsNeverOverwrittenAsAnEmptyStore(): void
    {
        file_put_contents($this->root . '/screens.json', '{broken');
        try {
            $this->active->declare(['name' => 'tasks']);
            self::fail('Corrupt store was accepted');
        } catch (\JsonException) {
            self::assertSame('{broken', file_get_contents($this->root . '/screens.json'));
        }
        file_put_contents($this->root . '/screens.json', 'null');
        $this->expectException(\RuntimeException::class);
        $this->active->names();
    }
    public function testBuildTracksSourceConfigurationAndLockButIgnoresApplicationData(): void
    {
        $b = new ScreenBuild($this->root);
        $empty = $b->fingerprint();
        mkdir($this->root . '/src');
        file_put_contents($this->root . '/src/Component.php', 'one');
        $first = $b->fingerprint();
        self::assertNotSame($empty, $first);
        mkdir($this->root . '/storage');
        file_put_contents($this->root . '/storage/tasks.json', 'records');
        self::assertSame($first, $b->fingerprint());
        file_put_contents($this->root . '/composer.lock', 'different dependency selection');
        self::assertNotSame($first, $b->fingerprint());
    }
    public function testOperationsExposeTheSameRevisionServiceAndActionScopes(): void
    {
        $ops = [];
        foreach ((new ScreenDraftOperations($this->drafts, '/custom-ui'))->operations() as $op) {
            $ops[$op->name] = $op;
        }
        self::assertSame(['milpa:component:screen-review:promote'], $ops['screen:promote']->scopes);
        self::assertFalse($ops['screen:review']->mutating);
        self::assertTrue($ops['screen:draft']->mutating);
        $d = ($ops['screen:draft']->handler)(['name' => 'tasks','type' => 'draft-counter','props' => []])['result'];
        self::assertSame('/custom-ui/review?revision=' . $d['id'], $d['reviewAt']);
        self::assertSame('/custom-ui/preview?revision=' . $d['id'], $d['previewAt']);
        self::assertArrayNotHasKey('reviewAt', $this->drafts->load($d['id']), 'navigation metadata must not alter the immutable record');
        self::assertCount(1, ($ops['screen:review']->handler)([])['result']['drafts']);
        self::assertSame($d['id'], ($ops['screen:review']->handler)(['revision' => $d['id']])['result']['id']);
        self::assertTrue(($ops['screen:promote']->handler)(['revision' => $d['id']])['ok']);
        self::assertTrue(($ops['screen:rollback']->handler)(['revision' => $d['id']])['ok']);
        self::assertFalse(($ops['screen:promote']->handler)([])['ok']);
    }
    public function testAgentDeliversAHostRevisionAndReadsItsGeneratedIdWithoutAnotherQuestion(): void
    {
        $this->seed();
        $before = $this->active->screen('tasks');
        $ops = (new ScreenDraftOperations($this->drafts))->operations();
        $router = new \Milpa\AppRuntime\Agent\TrialRouter($this->root, new \Milpa\AppRuntime\Agent\TrialRunner(), __DIR__ . '/../../src/Agent/trial-run.php');
        $registry = new \Milpa\ToolRuntime\ToolRegistry(new \Psr\Log\NullLogger());
        (new \Milpa\Console\McpProjector())->projectAll($ops, $registry, new DIContainer());
        $door = new \Milpa\AppRuntime\Agent\TrialAwareRegistry($registry, $router, $ops);
        $author = new \Milpa\ToolRuntime\Contracts\ToolContext(principal:'author', channel:'cli', scopes:['milpa:component:screen-review:draft','milpa:component:screen-review:read']);
        $args = ['name' => 'tasks','type' => 'draft-counter','props' => ['locale' => 'es']];
        $result = $door->call('screen_draft', $args, $author);
        self::assertTrue($result->success, (string)$result->error);
        self::assertArrayNotHasKey('ran_in_trial', $result->data);
        $id = $result->data['result']['id'];
        self::assertSame($before, $this->drafts->load($id)['before']);
        self::assertSame($before, $this->active->screen('tasks'));
        self::assertSame([], \Milpa\AppRuntime\Agent\TrialWorkspace::ids($this->root));
        $sessions = new \Milpa\Agent\SessionStore(new \Milpa\EventStore\InMemoryEventStore());
        $sessions->start('author-session', 'Prepare a draft for tasks and review its revision.', \Milpa\Agent\AutonomyMode::Auto);
        $gate = new \Milpa\AppRuntime\Agent\SessionToolGate($sessions, $sessions->load('author-session'), $ops, petition:'Prepare a draft for tasks and review its revision.', trialRouter:$router);
        self::assertNull($gate->refuse('screen_review', ['revision' => $id]));
        self::assertSame($id, $door->call('screen_review', ['revision' => $id], $author)->data['result']['id']);
        self::assertSame('/live/review?revision=' . $id, $door->call('screen_review', ['revision' => $id], $author)->data['result']['reviewAt']);
        self::assertFalse($door->call('screen_promote', ['revision' => $id], $author)->success);
        self::assertSame($before, $this->active->screen('tasks'));
        $reader = new \Milpa\ToolRuntime\Contracts\ToolContext(principal:'reader', channel:'cli', scopes:['milpa:component:screen-review:read']);
        self::assertFalse($door->call('screen_draft', $args, $reader)->success);
        self::assertCount(1, $this->drafts->catalogue()['drafts']);
        $reviewer = new \Milpa\ToolRuntime\Contracts\ToolContext(principal:'reviewer', channel:'cli', scopes:['milpa:component:screen-review:promote','milpa:component:screen-review:rollback']);
        self::assertTrue($door->call('screen_promote', ['revision' => $id], $reviewer)->success);
        self::assertSame('es', $this->active->screen('tasks')['props']['locale']);
        self::assertTrue($door->call('screen_rollback', ['revision' => $id], $reviewer)->success);
        self::assertSame($before, $this->active->screen('tasks'));
    }
    private function feature(): DIContainer
    {
        $c = new DIContainer();
        $c->registerService(Config::class, new Config());
        $registry = new InMemoryComponentRegistry();
        $registry->register('draft-counter', new DraftCounter());
        $renderers = new ComponentRendererRegistry();
        $renderers->registerFor('draft-counter', new DraftCounterRenderer(new XhtmlStateTransferCodec()));
        ScreenDraftFeature::boot($c, new ScreenComponents($registry, $renderers, $this->active), $this->root, '/ui', str_repeat('s', 32));
        return $c;
    }
    private function factory(PreviewEnvironment $env): void
    {
        $env->components->register('draft-counter', new DraftCounter());
        $env->renderers->registerFor('draft-counter', new DraftCounterRenderer($env->codec));
    }
    private function req(string $path, ?array $body = null, array $scopes = ['milpa:component:screen-review:*','milpa:component:draft-counter:*']): ServerRequest
    {
        parse_str(parse_url($path, PHP_URL_QUERY) ?? '', $query);
        return (new ServerRequest($body === null ? 'GET' : 'POST', $path, [], $body === null ? '' : json_encode($body)))->withQueryParams($query)->withParsedBody($body)
            ->withAttribute(AuthenticateMiddleware::ATTRIBUTE, AuthContext::authenticated(new Actor('reviewer', ActorType::User, $scopes)));
    }
    private function wire(string $html, string $id): array
    {
        preg_match('#id="milpa-live-boot"[^>]*>(.*?)</script>#s', $html, $boot);
        preg_match('#data-milpa-state="' . $id . '"[^>]*>(.*?)</script>#s', $html, $state);
        self::assertNotEmpty($state);
        return json_decode($boot[1], true) + ['state' => $state[1]];
    }
    public function testReviewAndPreviewRequireReadPermissionAndExplicitFactories(): void
    {
        $c = $this->feature();
        $review = $c->get(ScreenReviewController::class);
        $preview = $c->get(ScreenPreviewController::class);
        foreach ([$review->show(...),$preview->handle(...)] as $handle) {
            self::assertSame(401, $handle(new ServerRequest('GET', '/ui/review'))->getStatusCode());
            self::assertSame(403, $handle($this->req('/ui/review', scopes:[]))->getStatusCode());
        }
        $d = $c->get(ScreenDrafts::class);
        $this->refuses('preview_not_configured', fn () => $d->draft('tasks', 'draft-counter', []));
        $this->refuses('invalid_name', fn () => $d->draft('draft-counter', 'data-table', []));
        $c->get(ScreenPreviewRegistry::class)->register('draft-counter', $this->factory(...));
        $draft = $d->draft('tasks', 'draft-counter', []);
        self::assertSame(409, $preview->handle($this->req('/ui/preview?revision=missing'))->getStatusCode());
        self::assertSame(409, $preview->handle($this->req('/ui/preview?revision[]=x'))->getStatusCode());
        mkdir($this->root . '/config');
        file_put_contents($this->root . '/config/new.php', 'changed build');
        self::assertSame(409, $preview->handle($this->req('/ui/preview?revision=' . $draft['id']))->getStatusCode());
    }
    public function testIsolatedWireRendersAndActsWithItsOwnCodecAndCorrectAssetRoute(): void
    {
        $c = $this->feature();
        $c->get(ScreenPreviewRegistry::class)->register('draft-counter', $this->factory(...));
        $d = $c->get(ScreenDrafts::class)->draft('tasks', 'draft-counter', []);
        $preview = $c->get(ScreenPreviewController::class);
        $path = '/ui/preview?revision=' . $d['id'];
        $html = (string)$preview->handle($this->req($path))->getBody();
        self::assertStringContainsString('/ui/assets/milpa-tokens.css', $html);
        self::assertStringNotContainsString($path . '/assets', $html);
        $wire = $this->wire($html, 'tasks');
        $result = $preview->handle($this->req($path, $wire + ['action' => 'increment','payload' => []]));
        self::assertSame(200, $result->getStatusCode());
        self::assertSame(1, json_decode((string)$result->getBody(), true)['data']['count']);
        $other = $c->get(ScreenDrafts::class)->draft('tasks', 'draft-counter', []);
        $otherPath = '/ui/preview?revision=' . $other['id'];
        $otherWire = $this->wire((string)$preview->handle($this->req($otherPath))->getBody(), 'tasks');
        $cross = array_replace($otherWire, ['state' => $wire['state'],'action' => 'increment','payload' => []]);
        self::assertSame(400, $preview->handle($this->req($otherPath, $cross))->getStatusCode());
    }
    public function testReviewWireSavesSelectsPromotesAndRestoresWithExplicitSelection(): void
    {
        $c = $this->feature();
        $c->get(ScreenPreviewRegistry::class)->register('draft-counter', $this->factory(...));
        $review = $c->get(ScreenReviewController::class);
        $html = (string)$review->show($this->req('/ui/review'))->getBody();
        self::assertStringContainsString('data-create-revision', $html);
        $wire = $this->wire($html, 'screen-review');
        $call = function (string $action, array $payload) use ($review, &$wire): array {
            $response = $review->show($this->req('/ui/review', array_replace($wire, ['action' => $action,'payload' => $payload])));
            self::assertSame(200, $response->getStatusCode());
            $r = json_decode((string)$response->getBody(), true);
            $wire['state'] = $r['state'];
            return $r;
        };
        $draft = $call('draft', ['name' => 'tasks','type' => 'draft-counter','props' => '{}']);
        $id = $draft['data']['selected'];
        self::assertEmpty($draft['errors']);
        self::assertStringContainsString('data-draft-preview', $draft['html']);
        self::assertStringContainsString($id, (string) $review->show($this->req('/ui/review?revision=' . $id))->getBody());
        self::assertSame(409, $review->show($this->req('/ui/review?revision=unknown'))->getStatusCode());
        self::assertSame('es', $call('read', ['locale' => 'es'])['data']['locale']);
        self::assertEmpty($call('promote', ['revision' => $id])['errors']);
        self::assertEmpty($call('rollback', ['revision' => $id])['errors']);
        self::assertSame('revision_changed', $call('promote', ['revision' => str_repeat('a', 64)])['errors']['review']);
        self::assertSame('invalid_props', $call('draft', ['name' => 'tasks','type' => 'draft-counter','props' => '[1]'])['errors']['review']);
        self::assertSame('invalid_props', $call('draft', ['name' => 'tasks','type' => 'draft-counter','props' => '{broken'])['errors']['review']);
        $fresh = $this->wire((string)$review->show($this->req('/ui/review', scopes:['milpa:component:screen-review:read']))->getBody(), 'screen-review');
        self::assertSame(403, $review->show($this->req('/ui/review', $fresh + ['action' => 'draft','payload' => []], ['milpa:component:screen-review:read']))->getStatusCode());
    }
    public function testPreviewRegistryRefusesMissingCollaborators(): void
    {
        $p = new ScreenPreviewRegistry();
        $codec = new XhtmlStateTransferCodec();
        $this->refuses('preview_not_configured', fn () => $p->build('revision', $codec, 'draft-counter', []));
        $p->register('draft-counter', static function (PreviewEnvironment $env): void {
            $env->components->register('draft-counter', new DraftCounter());
        });
        $this->refuses('preview_not_configured', fn () => $p->build('revision', $codec, 'draft-counter', []));
        $p->register('draft-counter', $this->factory(...));
        self::assertTrue($p->build('revision', $codec, 'draft-counter', [])->components->has('draft-counter'));
        $renderer = new ScreenReviewRenderer($codec);
        self::assertFalse($renderer->supportsTarget(RenderTarget::TUI));
    }
}

final class DraftCounter implements ComponentDefinitionInterface
{
    public static function contract(): ComponentContract
    {
        return new ComponentContract('draft-counter', '1', actions:['increment' => []]);
    }
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot($context->componentId, 'draft-counter', '1', ['count' => 0], ['principal' => $context->principal,'route' => $context->route]);
    }
    public function handle(InteractionRequest $request): InteractionResult
    {
        $s = $request->state;
        return new InteractionResult(new StateSnapshot($s->componentId, $s->componentName, $s->version, ['count' => $s->data['count'] + 1], $s->meta));
    }
}
final readonly class DraftCounterRenderer implements ComponentRendererInterface
{
    public function __construct(private StateTransferCodecInterface $codec)
    {
    }
    public function supportsTarget(RenderTarget $target): bool
    {
        return $target === RenderTarget::HTML;
    }
    public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
    {
        $s = $request->state ?? $component->mount($request->props, $request->context);
        return new RenderResult('<div>' . $s->data['count'] . '</div><script type="application/milpa+xhtml" data-milpa-state="' . $s->componentId . '">' . $this->codec->encodeState($s) . '</script>', state:$s);
    }
}
