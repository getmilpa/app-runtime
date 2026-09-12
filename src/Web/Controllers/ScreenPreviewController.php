<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Web\Controllers;

use Milpa\AppRuntime\Auth\LivePrincipal;
use Milpa\AppRuntime\Web\{ScreenDrafts,ScreenPreviewRegistry,ScreenBuild,CompositeHtmlRenderer,RegisteredHtmlRenderer,LivePageProvider};
use Milpa\Live\Security\{SignedXhtmlStateTransferCodec,HmacStateSigner,FileNonceStore,HmacCsrfGuard,ContractInteractionAuthorizer};
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\Http\LiveEndpoint;
use Nyholm\Psr7\Response;
use Psr\Http\Message\{ServerRequestInterface,ResponseInterface};

/** An isolated live wire per immutable revision; production signatures never authorize preview actions or vice versa. */
final readonly class ScreenPreviewController
{
    public function __construct(private ScreenDrafts $drafts, private ScreenPreviewRegistry $previews, private ScreenBuild $build, private string $secret, private string $root, private string $route)
    {
    }
    /** GET mounts and POST handles the same isolated graph with normal live authorization. */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $principal = LivePrincipal::fromRequest($request);
        if ($principal === null) {
            return new Response(401);
        }
        if (!$principal->can('milpa:component:screen-review:read') && !$principal->can('milpa:component:screen-review:*')) {
            return new Response(403);
        }
        try {
            $id = $request->getQueryParams()['revision'] ?? '';
            if (!is_string($id)) {
                throw new \DomainException('revision_missing');
            }
            $draft = $this->drafts->load($id);
            if ($draft['build'] !== $this->build->fingerprint()) {
                throw new \DomainException('build_changed');
            }
            $key = hash_hmac('sha256', 'screen-preview:' . $id, $this->secret);
            $codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner($key), new FileNonceStore($this->root . '/var/screen-preview/' . $id . '/nonces.json'));
            $csrf = new HmacCsrfGuard($key);
            $definition = $draft['definition'];
            $env = $this->previews->build($id, $codec, $definition['type'], $definition['props']);
            $endpointRoute = $this->route . '/preview?revision=' . $id;
            $component = $env->components->get($definition['type']);
            $env->components->register($draft['name'], $component);
            if ($request->getMethod() === 'POST') {
                $endpoint = new LiveEndpoint($env->components, $codec, new ContractInteractionAuthorizer($env->components), $csrf, $endpointRoute, renderers:$env->renderers);
                return (new LiveController($endpoint))->handle($request);
            }
            $provider = new class ($draft['name'], $definition['props']) implements LivePageProvider {
                /** @param array<string,mixed> $props */
                public function __construct(private string $name, private array $props)
                {
                }
                public function propsFor(string $component, ServerRequestInterface $request): ?array
                {
                    return $component === $this->name ? $this->props : null;
                }
            };
            $renderer = new CompositeHtmlRenderer(new RegisteredHtmlRenderer($env->renderers), fn (string $type, array $props) => $env->components->has($type) ? $env->components->get($type) : null);
            $page = new LiveComponentPageController($env->components, $renderer, $csrf, $endpointRoute, $provider, assetsRoute:$this->route);
            return $page->show($request->withQueryParams(['component' => $draft['name']]));
        } catch (\DomainException $e) {
            return new Response(409, ['Content-Type' => 'application/json','Cache-Control' => 'no-store'], json_encode(['error' => $e->getMessage()]));
        }
    }
}
