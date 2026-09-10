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

namespace Milpa\AppRuntime\Tests\Web;

use Milpa\AppRuntime\Web\Controllers\PasskeyController;
use Milpa\AppRuntime\Web\Live\GateCeremonyAssets;
use Milpa\AppRuntime\Web\Live\GateCeremonyComponent;
use Milpa\AppRuntime\Web\Live\GateCeremonyHtmlRenderer;
use Milpa\AppRuntime\Web\Live\GateCeremonyRender;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderTarget;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * The ceremony as a COMPONENT: its contract, its state, its assets and the seam a plugin extends it
 * through.
 *
 * These run everywhere. The four falsifiers that drive the module under `node` cannot — they skip on
 * a box without it, which is CI, so CI proves strictly less about the ceremony than a developer's
 * machine does (greenhouse decisions/0263). That is a declared gap, not a covered one, and the
 * server-side half is asserted here so it is not the half that goes unmeasured too.
 */
final class GateCeremonyComponentTest extends TestCase
{
    public function testTheContractNamesItselfAndTakesNoLiveActions(): void
    {
        $contract = GateCeremonyComponent::contract();

        self::assertSame('gate-ceremony', $contract->name);
        self::assertSame('1', $contract->contractVersion);
        // NO ACTIONS, AND THAT IS THE POINT: an action would need the live wire, and the wire is
        // exactly what an unauthenticated page cannot have.
        self::assertSame([], $contract->actions);
        self::assertNull($contract->presentation, 'the sheet is a <link>, not a scoped declaration');
        foreach (['kind', 'rpId', 'scope'] as $required) {
            self::assertTrue($contract->propsSchema[$required]['required'] ?? false, "$required is required");
        }
    }

    public function testMountCarriesWhatTheHouseDeclared(): void
    {
        $state = (new GateCeremonyComponent())->mount([
            'kind' => 'enroll',
            'rpId' => 'milpa.local',
            'scope' => 'agent:run',
            'next' => '/milpa/admin',
            'attachment' => 'platform',
            'markHtml' => '<svg></svg>',
        ], new ComponentContext('gate', route: '/webauthn'));

        self::assertSame('gate-ceremony', $state->componentName);
        self::assertSame([
            'kind' => 'enroll',
            'rpId' => 'milpa.local',
            'scope' => 'agent:run',
            'next' => '/milpa/admin',
            'attachment' => 'platform',
            'markHtml' => '<svg></svg>',
        ], $state->data);
    }

    /**
     * An unknown act falls to signing in, and an empty return path to the root.
     *
     * Signing in is the safe default of the two: it asks for a key the house already recognises and
     * mints nothing new. Falling to ENROLL would offer to create an identity to somebody whose
     * request never asked for one.
     */
    public function testMountFallsToSigningInAndToTheRoot(): void
    {
        $state = (new GateCeremonyComponent())->mount([
            'kind' => 'something-else',
            'next' => '',
        ], new ComponentContext('gate', route: '/webauthn'));

        self::assertSame('signin', $state->data['kind']);
        self::assertSame('/', $state->data['next']);
        self::assertSame('', $state->data['rpId'], 'absent is empty, never invented');
        self::assertNull($state->data['attachment']);
    }

    /** An empty attachment is the same as none: a browser reads `null` as a constraint nothing satisfies. */
    public function testAnEmptyAttachmentIsTheSameAsNoneAtAll(): void
    {
        $state = (new GateCeremonyComponent())->mount([
            'kind' => 'enroll',
            'attachment' => '',
        ], new ComponentContext('gate', route: '/webauthn'));

        self::assertNull($state->data['attachment']);
    }

    public function testAnActionReachingTheComponentIsReportedAndNotThrown(): void
    {
        $component = new GateCeremonyComponent();
        $context = new ComponentContext('gate', route: '/webauthn');
        $state = $component->mount(['kind' => 'signin'], $context);

        $result = $component->handle(new InteractionRequest('gate', 'gate-ceremony', 'anything', $state));

        self::assertSame($state, $result->state, 'nothing changes');
        self::assertArrayHasKey('action', $result->errors);
    }

    public function testTheTwoActsSayDifferentThingsAndEachKeepsItsDoctrine(): void
    {
        $enroll = GateCeremonyComponent::copy('enroll');
        $signin = GateCeremonyComponent::copy('signin');

        // THE LINES THAT HAVE TO SURVIVE THESE SCREENS (greenhouse decisions/0260) — and they are not
        // the same line, because enrolling and signing in are not the same act.
        self::assertSame('Registering identifies you. It grants no permissions.', $enroll['doctrine']);
        self::assertSame('The house checks the signature before it mints a session.', $signin['doctrine']);
        // Step 2 finishes the sentence step 1 started; the sign-in act has no second step to promise.
        self::assertSame('Step 2 · What may you do?', $enroll['next']['kicker']);
        self::assertNull($signin['next']);
        self::assertNotSame($enroll['heading'], $signin['heading']);
    }

    public function testTheRendererDeclaresBothItsFilesAndOnlyPaintsHtml(): void
    {
        $renderer = new GateCeremonyHtmlRenderer();

        self::assertTrue($renderer->supportsTarget(RenderTarget::HTML));
        self::assertFalse($renderer->supportsTarget(RenderTarget::TUI), 'a ceremony is not a terminal frame');

        $declared = $renderer->clientAssets()->toArray();
        self::assertSame(['/webauthn/assets/gate-ceremony.js'], $declared['scripts']);
        self::assertSame(['/webauthn/assets/gate-ceremony.css'], $declared['styles']);
    }

    /**
     * A DECLARATION THAT NAMES A FILE THE PACKAGE DOES NOT SHIP IS A LYING DECLARATION.
     *
     * Its `<link>` 404s and the page looks styled while it is not, in silence — so the declaration is
     * checked against the disk rather than trusted.
     */
    public function testEveryFileTheCeremonyDeclaresIsActuallyShipped(): void
    {
        foreach (GateCeremonyAssets::FILES as $name) {
            $path = GateCeremonyAssets::path($name);

            self::assertNotNull($path, "$name is declared and must be shipped");
            self::assertFileExists($path);
            self::assertNotSame('', trim((string) file_get_contents($path)), "$name is not empty");
        }
    }

    public function testAssetsAreAnsweredFromANamedListAndNothingElse(): void
    {
        self::assertNotNull(GateCeremonyAssets::path(GateCeremonyAssets::STYLESHEET));
        // Traversal is unrepresentable rather than filtered: a name that is not in the list has no path.
        self::assertNull(GateCeremonyAssets::path('../PasskeyPlugin.php'));
        self::assertNull(GateCeremonyAssets::path('gate-ceremony.css.bak'));
        self::assertNull(GateCeremonyAssets::path(''));
        self::assertSame('text/css; charset=utf-8', GateCeremonyAssets::contentType(GateCeremonyAssets::STYLESHEET));
        self::assertSame('text/javascript; charset=utf-8', GateCeremonyAssets::contentType(GateCeremonyAssets::MODULE));
        self::assertSame('/webauthn/assets/gate-ceremony.css', GateCeremonyAssets::url(GateCeremonyAssets::STYLESHEET));
    }

    /**
     * THE SEAM IS REAL, AND THIS IS WHAT PROVES IT.
     *
     * Milpa is event-driven, so a component nobody can observe is a component nobody can extend. The
     * claim is only worth its docblock if a subscriber can actually change what gets painted — so one
     * does: it rewrites the heading before, and appends to the HTML after.
     */
    public function testASubscriberCanChangeTheCopyBeforeAndTheMarkupAfter(): void
    {
        $dispatcher = new class () implements MilpaEventDispatcherInterface {
            /** @var list<string> */
            public array $seen = [];

            public function dispatch(string $eventName, array $payload = [], bool $async = false): void
            {
                $this->seen[] = $eventName;
                $subject = $payload['ceremony'] ?? null;
                if (!$subject instanceof GateCeremonyRender) {
                    return;
                }
                // `dispatch` returns void, so the ONLY way a subscriber changes anything is by
                // writing to the subject it was handed — which is why that object is mutable and
                // everything else in this package is not.
                if ($eventName === GateCeremonyComponent::BEFORE_RENDER) {
                    $subject->copy['heading'] = 'A different heading';
                } else {
                    $subject->html .= '<!-- a plugin was here -->';
                }
            }

            public function subscribe(string $eventName, callable $handler, int $priority = 0): void
            {
            }

            public function getSubscribers(string $eventName): array
            {
                return [];
            }

            public function hasSubscribers(string $eventName): bool
            {
                return false;
            }
        };

        $component = new GateCeremonyComponent();
        $context = new ComponentContext('gate', route: '/webauthn');
        $state = $component->mount(['kind' => 'enroll', 'rpId' => 'localhost', 'scope' => 'milpa.admin'], $context);
        $painted = (new GateCeremonyHtmlRenderer($dispatcher))->render($component, new RenderRequest(context: $context, state: $state));

        self::assertSame(
            [GateCeremonyComponent::BEFORE_RENDER, GateCeremonyComponent::AFTER_RENDER],
            $dispatcher->seen,
            'before, then after — in that order',
        );
        self::assertStringContainsString('<h1>A different heading</h1>', $painted->output, 'the subscriber changed the copy');
        self::assertStringNotContainsString('Register a passkey</h1>', $painted->output);
        self::assertStringContainsString('<!-- a plugin was here -->', $painted->output, 'and the markup after');
    }

    /** With no dispatcher the ceremony still paints — it simply announces nothing. */
    public function testWithoutADispatcherItStillPaints(): void
    {
        $component = new GateCeremonyComponent();
        $context = new ComponentContext('gate', route: '/webauthn');
        $state = $component->mount(['kind' => 'signin', 'rpId' => 'localhost', 'scope' => 'milpa.admin'], $context);

        $painted = (new GateCeremonyHtmlRenderer())->render($component, new RenderRequest(context: $context, state: $state));

        self::assertStringContainsString('<h1>Sign in</h1>', $painted->output);
    }

    /** A render with no state at all falls to the safe act rather than throwing inside somebody's page. */
    public function testARenderWithNoStateFallsToSigningIn(): void
    {
        $context = new ComponentContext('gate', route: '/webauthn');

        $painted = (new GateCeremonyHtmlRenderer())->render(
            new GateCeremonyComponent(),
            new RenderRequest(context: $context),
        );

        self::assertStringContainsString('<h1>Sign in</h1>', $painted->output);
        self::assertStringContainsString('"kind":"signin"', $painted->output);
    }

    /** Nothing a house declares can escape its attribute or close the facts tag. */
    public function testAHostileRelyingPartyCannotEscapeTheMarkupOrTheFactsTag(): void
    {
        $component = new GateCeremonyComponent();
        $context = new ComponentContext('gate', route: '/webauthn');
        $state = $component->mount([
            'kind' => 'signin',
            'rpId' => '"><script>alert(1)</script>',
            'scope' => '</script><b>',
        ], $context);

        $painted = (new GateCeremonyHtmlRenderer())->render($component, new RenderRequest(context: $context, state: $state));

        self::assertStringNotContainsString('<script>alert(1)</script>', $painted->output);
        self::assertStringNotContainsString('</script><b>', $painted->output);
        // And it decodes back to exactly what came in: escaped, not mangled.
        preg_match('#id="milpa-gate-ceremony">(.*?)</script>#s', $painted->output, $m);
        $facts = json_decode($m[1], true);
        self::assertSame('"><script>alert(1)</script>', $facts['rpId']);
        self::assertSame('</script><b>', $facts['scope']);
    }

    /**
     * The asset route answers its two files and refuses everything else.
     *
     * Driven through the controller so the ROUTE is what gets measured — a `path()` unit test cannot
     * say whether a request reaches it, and the plugin's own suite asserts the route exists without
     * asserting it answers (the wordmark route was a 404 for as long as live-web was pinned below the
     * version carrying it, and every test stayed green).
     */
    public function testTheAssetRouteServesTheTwoFilesAndRefusesAnythingElse(): void
    {
        $controller = self::bareController();

        foreach ([GateCeremonyAssets::STYLESHEET => 'text/css', GateCeremonyAssets::MODULE => 'text/javascript'] as $name => $type) {
            $res = $controller->ceremonyAsset(new ServerRequest('GET', GateCeremonyAssets::url($name)));

            self::assertSame(200, $res->getStatusCode(), "$name is served");
            self::assertStringContainsString($type, $res->getHeaderLine('Content-Type'));
            self::assertNotSame('', (string) $res->getBody(), "$name has real bytes");
        }

        // THE CONTROL: a name the package does not ship, and a traversal attempt, both 404 — the
        // basename lands in a named list, so neither is a path the filesystem ever sees.
        foreach (['gate-ceremony.css.bak', '../PasskeyPlugin.php', 'index.php'] as $name) {
            self::assertSame(404, $controller->ceremonyAsset(new ServerRequest('GET', '/webauthn/assets/' . $name))->getStatusCode(), "$name is refused");
        }
    }

    /** A controller with only what the page path reads — the rest of the ceremony needs no collaborator. */
    private static function bareController(): PasskeyController
    {
        $class = new \ReflectionClass(PasskeyController::class);
        $controller = $class->newInstanceWithoutConstructor();
        foreach (['rpId' => 'localhost', 'gateScope' => 'milpa.admin', 'authenticatorAttachment' => null, 'events' => null] as $name => $value) {
            $property = $class->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($controller, $value);
        }

        return $controller;
    }
}
