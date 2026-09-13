<?php

/** The governed bridge distinguishes real table withdrawal from record-only history (greenhouse0362/0679).
 * Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Agent;

use Milpa\Agent\SessionStore;
use Milpa\AppRuntime\Agent\{ConsentBridge,RecordOnlyOptionTable,SessionOptionTable};
use Milpa\EventStore\InMemoryEventStore;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Gate\ToolCallRefused;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ValueObjects\Tooling\ToolOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ActiveWithdrawalTest extends TestCase
{
    public function testTableWithdrawalBlocksAndRecordOnlyHistoryDoesNot(): void
    {
        $handled = 0;
        $r = new ToolRegistry(new NullLogger());
        $r->register('mark', 'Mark', ['type' => 'object'], static function () use (&$handled): string {
            ++$handled;
            return 'marked';
        });
        $sessions = new SessionStore(new InMemoryEventStore());
        $sessions->start('withdrawal', 'Record the marker');
        $table = new SessionOptionTable($sessions, 'withdrawal');
        $bridge = new ConsentBridge($r, table:$table);
        self::assertSame('marked', $bridge->callTool('mark', []));
        $table->remove('mark', 'denied-by-operator');
        self::assertSame([], $bridge->getToolSummaries());
        try {
            $bridge->callTool('mark', []);
            self::fail('Active withdrawal executed');
        } catch (ToolCallRefused $e) {
            self::assertTrue($e->optionRemoved);
            self::assertStringContainsString('withdrawn', $e->getMessage());
        }
        self::assertSame(1, $handled);
        $recordOnly = new RecordOnlyOptionTable($table);
        self::assertTrue($recordOnly->wasRemoved('mark'));
        self::assertSame('marked', (new ConsentBridge($r, table:$recordOnly))->callTool('mark', []));
        self::assertSame(2, $handled);
    }
    public function testScopeRefusalPrecedesWithdrawalAndNoTableStillRuns(): void
    {
        $r = new ToolRegistry(new NullLogger());
        $r->register('mark', 'Mark', ['type' => 'object'], static fn (): string => 'marked', new ToolOptions(scopes:['mark:write']));
        $sessions = new SessionStore(new InMemoryEventStore());
        $sessions->start('withdrawal', 'Record the marker');
        $table = new SessionOptionTable($sessions, 'withdrawal');
        $table->remove('mark', 'denied-by-operator');
        $bridge = new ConsentBridge($r, table:$table, authority:new ToolContext(scopes:['read']));
        try {
            $bridge->callTool('mark', []);
            self::fail('Out-of-scope call executed');
        } catch (ToolCallRefused) {
            self::fail('Scope refusal was replaced');
        } catch (\Exception $e) {
            self::assertStringContainsString('Missing required scope', $e->getMessage());
        }
        self::assertSame('marked', (new ConsentBridge($r, authority:new ToolContext(scopes:['mark:write'])))->callTool('mark', []));
    }
}
