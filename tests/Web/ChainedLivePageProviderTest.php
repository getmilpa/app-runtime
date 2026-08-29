<?php

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Web;

use Milpa\AppRuntime\Web\ChainedLivePageProvider;
use Milpa\AppRuntime\Web\LivePageProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * La cadena de providers (greenhouse decisions/0159): deja que una app conserve SU provider mientras el
 * runtime sigue sirviendo las pantallas que el agente declaró. Lo que fija: la precedencia declarada —el
 * provider de la app va primero, las pantallas declaradas rellenan sólo lo que la app declina—, que la
 * app NUNCA queda ensombrecida por el store para un nombre que sí sirve, y que un nombre que nadie sirve
 * cae a null (el controller contesta 404, no dato inventado).
 */
final class ChainedLivePageProviderTest extends TestCase
{
    private function provider(array $table): LivePageProvider
    {
        return new class($table) implements LivePageProvider {
            public function __construct(private readonly array $table)
            {
            }

            public function propsFor(string $component, ServerRequestInterface $request): ?array
            {
                return $this->table[$component] ?? null;
            }
        };
    }

    public function testTheFirstProviderThatServesWins(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $chain = new ChainedLivePageProvider(
            $this->provider(['data-table' => ['rows' => ['app']]]),
            $this->provider(['data-table' => ['rows' => ['store']], 'equipo' => ['rows' => ['store']]]),
        );

        // The app owns 'data-table' and is never shadowed by the store behind it.
        self::assertSame(['rows' => ['app']], $chain->propsFor('data-table', $request));
        // A runtime-declared screen the app declines falls through to the store.
        self::assertSame(['rows' => ['store']], $chain->propsFor('equipo', $request));
        // A name nobody serves stays null.
        self::assertNull($chain->propsFor('nadie', $request));
    }

    public function testASingleProviderChainIsJustThatProvider(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $chain = new ChainedLivePageProvider($this->provider(['x' => ['rows' => []]]));

        self::assertSame(['rows' => []], $chain->propsFor('x', $request));
        self::assertNull($chain->propsFor('y', $request));
    }
}
