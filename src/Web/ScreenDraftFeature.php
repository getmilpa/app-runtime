<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Web;

use Milpa\AppRuntime\Web\Controllers\{ScreenPreviewController,ScreenReviewController,LiveComponentPageController,LiveController};
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Live\Security\{SignedXhtmlStateTransferCodec,HmacStateSigner,FileNonceStore,HmacCsrfGuard,ContractInteractionAuthorizer};
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\Runtime\InMemoryComponentRegistry;
use Milpa\Live\Rendering\ComponentRendererRegistry;
use Milpa\Live\Http\LiveEndpoint;
use Psr\Http\Message\ServerRequestInterface;

/** Assemble separate review and preview wires without exposing their signatures or components on the active wire. */
final class ScreenDraftFeature
{
    /** Boot late-bound factories: app plugins may register preview graphs after LivePlugin. */
    public static function boot(DIContainerInterface $container, ScreenComponents $screens, string $root, string $route, string $secret): void
    {
        $previews = new ScreenPreviewRegistry();
        $build = new ScreenBuild($root);
        $drafts = new ScreenDrafts(
            ScreenStore::fromConfig(($container->get(\Milpa\Runtime\Config::class))->get('live', []), $root),
            $root . '/var/screen-drafts',
            static function (string $name, string $type, array $props) use ($screens, $previews): void {
                if ($screens->conflicts($name, $type)) {
                    throw new \DomainException('invalid_name');
                }
                ScreenTree::validate($type, $props, $screens->types());
                if ($previews->types() === []) {
                    throw new \DomainException('preview_not_configured');
                }
                ScreenTree::validate($type, $props, $previews->types());
            },
            $build->fingerprint(...)
        );
        $container->registerService(ScreenPreviewRegistry::class, $previews);
        $container->registerService(ScreenDrafts::class, $drafts);
        $container->registerService(ScreenPreviewController::class, new ScreenPreviewController($drafts, $previews, $build, $secret, $root, $route));
        $key = hash_hmac('sha256', 'screen-review', $secret);
        $codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner($key), new FileNonceStore($root . '/var/screen-review/nonces.json'));
        $csrf = new HmacCsrfGuard($key);
        $registry = new InMemoryComponentRegistry();
        $renderers = new ComponentRendererRegistry();
        $registry->register('screen-review', new ScreenReviewComponent($drafts, $route));
        $renderers->registerFor('screen-review', new ScreenReviewRenderer($codec));
        $wire = $route . '/review';
        $provider = new class () implements LivePageProvider {
            public function propsFor(string $component, ServerRequestInterface $request): ?array
            {
                $id = $request->getQueryParams()['revision'] ?? '';
                return $component === 'screen-review' ? ['revision' => is_string($id) ? $id : ''] : null;
            }
        };
        $page = new LiveComponentPageController($registry, new RegisteredHtmlRenderer($renderers), $csrf, $wire, $provider, assetsRoute:$route);
        $endpoint = new LiveEndpoint($registry, $codec, new ContractInteractionAuthorizer($registry), $csrf, $wire, renderers:$renderers);
        $container->registerService(ScreenReviewController::class, new ScreenReviewController($page, new LiveController($endpoint)));
    }
}
