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

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\AutonomyMode;
use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\SubAgentSpawner;
use Milpa\Command\Operation;
use Milpa\EventStore\InMemoryEventStore;
use PHPUnit\Framework\TestCase;

/**
 * What `agent_spawn` and `agent_resume` tell the model is English.
 *
 * A contract is what the model reads of a tool, and the contracts of this house are in English. These two
 * were in Spanish: every session that could delegate read them, and their words were counted as the words
 * of whatever the session had been asked to build, when that was asked in Spanish.
 *
 * @guards the description of both tools, and of every argument of theirs, being English
 *
 * @subject-in milpa/app-runtime
 */
final class TheSpawnContractsAreEnglishTest extends TestCase
{
    /** Spanish marks no English description carries: its letters, and words English does not have. */
    private const SPANISH = '/[áéíóúñ¿¡]|\b(el|los|las|del|una|para|con|que|por|sin|sus|herramientas?|encargo|sesi[oó]n|hija|p\. ej)\b/iu';

    public function testEveryDescriptionOfBothToolsIsEnglish(): void
    {
        $spawner = new SubAgentSpawner($this->sessions(), 'parent', static fn (): array => []);

        $read = 0;
        foreach ([$spawner->operation(), $spawner->resumeOperation()] as $tool) {
            foreach (self::descriptions($tool) as $where => $text) {
                ++$read;
                self::assertDoesNotMatchRegularExpression(self::SPANISH, $text, "{$where} is read in Spanish");
            }
        }

        self::assertGreaterThanOrEqual(10, $read, 'the control: both contracts and their arguments were read');
    }

    /** The control of the mark: it does tell the sentence these contracts began with. */
    public function testTheMarkTellsSpanish(): void
    {
        self::assertMatchesRegularExpression(self::SPANISH, 'Herramientas que el sub-agente NO debe tener, por nombre');
        self::assertDoesNotMatchRegularExpression(self::SPANISH, 'Tools the sub-agent must NOT have, by name');
    }

    /** @return array<string, string> every description of a tool, keyed by where it sits */
    private static function descriptions(Operation $tool): array
    {
        $found = [$tool->name => $tool->description];
        foreach ((array) ($tool->inputSchema['properties'] ?? []) as $argument => $property) {
            if (\is_array($property) && \is_string($property['description'] ?? null)) {
                $found["{$tool->name} · {$argument}"] = $property['description'];
            }
        }

        return $found;
    }

    private function sessions(): SessionStore
    {
        $sessions = new SessionStore(new InMemoryEventStore());
        $sessions->start('parent', 'the big task', AutonomyMode::Auto);

        return $sessions;
    }
}
