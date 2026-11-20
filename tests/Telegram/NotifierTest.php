<?php

/**
 * This file is part of milpa/app-runtime — the runtime an app composes to expose its operations.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AppRuntime\Tests\Telegram;

use Milpa\Agent\Principal;
use Milpa\AppRuntime\Telegram\CardLedger;
use Milpa\AppRuntime\Telegram\Notifier;
use PHPUnit\Framework\TestCase;

/**
 * Telegram is told THAT something waits and where to decide it — never the thing itself, and never twice
 * (greenhouse decisions/0572, evidence/1106).
 */
final class NotifierTest extends TestCase
{
    use House;

    public function testARefusalBecomesOneCardForThePersonWhoAnswersForTheSeat(): void
    {
        $this->aRefusal();

        self::assertSame(['sent' => 1, 'settled' => 0, 'expired' => 0, 'failed' => 0], $this->notifier()->sweep(now: 1000));

        $card = $this->bot->messages[1];
        self::assertSame('4242', $card['chat']);
        self::assertStringContainsString('it lacks plugins.Blog:write (make)', $card['text']);
        self::assertStringContainsString('This message decides nothing.', $card['text']);
        self::assertStringContainsString('lasts 15 min.', $card['text']);
        self::assertSame('Open the house', $card['button']);
        self::assertMatchesRegularExpression('~^https://casa\.example\.ts\.net/telegram/open/[A-Za-z0-9_-]{43}$~', (string) $card['url']);
    }

    public function testACardCarriesNoGoalNoArgumentsNoPathAndNoSessionId(): void
    {
        $this->aRefusal();
        $this->aQuestion('ask-secret-session', new Principal(self::PASSKEY, true));
        $this->notifier()->sweep();

        self::assertCount(2, $this->bot->messages);
        foreach ($this->bot->messages as $card) {
            $sent = $card['text'] . ' ' . $card['url'] . ' ' . $card['button'];
            foreach (['SECRET', '/srv/private', 'Build the blog', 'May I promote', self::SESSION, 'ask-secret-session', 'trial-', self::SEAT] as $private) {
                self::assertStringNotContainsString($private, $sent);
            }
        }
    }

    public function testTheLedgerKeepsTheTokensHashAndNeverTheToken(): void
    {
        $this->aRefusal();
        $this->notifier()->sweep();
        $token = $this->bot->token(1);

        $onDisk = (string) file_get_contents($this->root . '/' . CardLedger::PATH);
        self::assertStringNotContainsString($token, $onDisk);
        self::assertStringContainsString(hash('sha256', $token), $onDisk);
        self::assertSame('0600', substr(sprintf('%o', fileperms($this->root . '/' . CardLedger::PATH)), -4));
        self::assertNotNull(CardLedger::forRoot($this->root)->byToken($token));
        self::assertNull(CardLedger::forRoot($this->root)->byToken($token . 'x'));
    }

    public function testASecondSweepSendsNothingNew(): void
    {
        $this->aRefusal();
        $this->notifier()->sweep(now: 1000);

        self::assertSame(['sent' => 0, 'settled' => 0, 'expired' => 0, 'failed' => 0], $this->notifier()->sweep(now: 1001));
        self::assertCount(1, $this->bot->messages);
    }

    public function testAStrangerIsToldNothing(): void
    {
        $this->aRefusal();
        $this->aQuestion('theirs', new Principal(self::PASSKEY, true));

        self::assertSame(0, $this->notifier(for: self::STRANGER)->sweep()['sent']);
        self::assertSame([], $this->bot->messages, 'the frontier is seen by the line that enrolled the seat, on every surface');
    }

    public function testAQuestionBecomesACardThatNamesOnlyItsOperation(): void
    {
        $this->aQuestion('asking', new Principal(self::PASSKEY, true));
        $this->notifier()->sweep();

        self::assertStringContainsString('A session is waiting for your answer about sandbox:promote.', $this->bot->messages[1]['text']);
    }

    public function testAQuestionOfASessionNobodyVerifiedOpenedIsNobodysHere(): void
    {
        $this->aQuestion('unsigned', new Principal('cli:rod@laptop', false));

        self::assertSame(0, $this->notifier()->sweep()['sent']);
    }

    public function testAQuestionOfTheSeatIsTheEnrollersToDecide(): void
    {
        $this->aQuestion('seat-asks', new Principal('key:' . self::SEAT, true), 'plugins.register');

        self::assertSame(1, $this->notifier()->sweep()['sent']);
        self::assertSame(0, $this->notifier(for: self::STRANGER)->sweep()['sent']);
    }

    public function testWithDetailNoneACardNamesNothing(): void
    {
        $this->aRefusal();
        $this->aQuestion('asking', new Principal(self::PASSKEY, true));
        $this->notifier(names: false)->sweep();

        $texts = implode("\n", array_column($this->bot->messages, 'text'));
        self::assertStringContainsString('A seat\'s call was refused for a scope it lacks.', $texts);
        self::assertStringContainsString('A session is waiting for your answer.', $texts);
        self::assertStringNotContainsString('plugins.Blog', $texts);
        self::assertStringNotContainsString('sandbox', $texts);
    }

    public function testWhatDoesNotLookLikeANameIsNotShown(): void
    {
        $this->aQuestion('asking', new Principal(self::PASSKEY, true), "rm -rf /\nsecret words");
        $this->notifier()->sweep();

        self::assertStringContainsString('A session is waiting for your answer.', $this->bot->messages[1]['text']);
        self::assertStringNotContainsString('secret words', $this->bot->messages[1]['text']);
    }

    public function testACardSpeaksTheLocaleTheHouseDeclared(): void
    {
        $this->aRefusal();
        $this->notifier(locale: 'es')->sweep();

        self::assertStringContainsString('le falta plugins.Blog:write (make)', $this->bot->messages[1]['text']);
        self::assertSame('Abrir la casa', $this->bot->messages[1]['button']);
    }

    public function testAGrantRewritesTheCardWithWhoDecidedAndTakesItsButton(): void
    {
        $this->aRefusal();
        $this->notifier()->sweep(now: 1000);
        $this->grant();

        self::assertSame(['sent' => 0, 'settled' => 1, 'expired' => 0, 'failed' => 0], $this->notifier()->sweep(now: 1010));
        self::assertSame('Decided: passkey:cred-of-… granted plugins.Blog:write.', $this->bot->messages[1]['text']);
        self::assertNull($this->bot->messages[1]['url'], 'a settled card keeps no button');
        self::assertSame(0, $this->notifier()->sweep(now: 1020)['settled'], 'settled once');
        self::assertSame(1, $this->bot->messages[1]['edits']);
    }

    public function testAnAnswerRewritesTheCardWithWhoAnsweredAndWhat(): void
    {
        $this->aQuestion('asking', new Principal(self::PASSKEY, true));
        $this->notifier()->sweep();
        $this->sessions->answer('asking', 'perm:sandbox:promote', 'no', new Principal(self::HUMAN, true));

        self::assertSame(1, $this->notifier()->sweep()['settled']);
        self::assertSame('Decided: key:C1FEA43B… answered no.', $this->bot->messages[1]['text']);
    }

    public function testWhoAnsweredThroughThePanelIsShownAsThePrincipalTheLedgerNames(): void
    {
        $this->aQuestion('asking', new Principal(self::PASSKEY, true));
        $this->notifier()->sweep();
        $this->sessions->answer('asking', 'perm:sandbox:promote', 'yes', new Principal('actor:' . self::PASSKEY, true));
        $this->notifier()->sweep();

        self::assertSame('Decided: passkey:cred-of-… answered yes.', $this->bot->messages[1]['text']);
    }

    public function testAnAnswerersNameNobodyVerifiedIsNotRepeated(): void
    {
        $this->aQuestion('asking', new Principal(self::PASSKEY, true));
        $this->notifier()->sweep();
        $this->sessions->answer('asking', 'perm:sandbox:promote', 'yes', new Principal(self::HUMAN, false));
        $this->notifier()->sweep();

        self::assertSame('Decided: … answered yes.', $this->bot->messages[1]['text']);
    }

    public function testAnAnswerThatIsNotYesOrNoIsNotRepeated(): void
    {
        $this->aQuestion('asking', new Principal(self::PASSKEY, true));
        $this->notifier()->sweep();
        $this->sessions->answer('asking', 'perm:sandbox:promote', 'use /srv/private instead', new Principal(self::HUMAN, true));
        $this->notifier()->sweep();

        self::assertSame('Decided: key:C1FEA43B… answered.', $this->bot->messages[1]['text']);
    }

    public function testWhatStoppedWaitingWithNobodyDecidingSaysOnlyThat(): void
    {
        $this->aQuestion('asking', new Principal(self::PASSKEY, true));
        $this->notifier()->sweep();
        $this->sessions->answer('asking', 'perm:sandbox:promote', 'yes');

        $this->notifier()->sweep();

        self::assertSame('No longer waiting: it was settled, or the session moved on.', $this->bot->messages[1]['text']);
    }

    public function testAnOlderAnswerToTheSameQuestionDoesNotSettleANewAsking(): void
    {
        $this->aQuestion('asking', new Principal(self::PASSKEY, true));
        $this->sessions->answer('asking', 'perm:sandbox:promote', 'yes', new Principal(self::HUMAN, true));
        $this->sessions->ask('asking', new \Milpa\Agent\PendingQuestion('perm:sandbox:promote', 'Again?', ['yes', 'no'], '{"operation":"sandbox:promote"}'));

        self::assertSame(1, $this->notifier()->sweep()['sent']);
        self::assertSame(0, $this->notifier()->sweep()['settled'], 'the second asking still waits');
    }

    public function testALinkThatRanOutIsRewrittenToThePanelAndOpensNothing(): void
    {
        $this->aRefusal();
        $this->notifier(ttl: 600)->sweep(now: 1000);
        $token = $this->bot->token(1);

        self::assertSame(0, $this->notifier(ttl: 600)->sweep(now: 1599)['expired']);
        self::assertSame(['sent' => 0, 'settled' => 0, 'expired' => 1, 'failed' => 0], $this->notifier(ttl: 600)->sweep(now: 1600));
        self::assertStringContainsString('The link expired. It still waits for you in the house\'s panel.', $this->bot->messages[1]['text']);
        self::assertSame('https://casa.example.ts.net/milpa/admin', $this->bot->messages[1]['url'], 'the panel, with no token');
        self::assertNull($this->notifier()->open($token, self::PASSKEY, 1601));
        self::assertSame(0, $this->notifier(ttl: 600)->sweep(now: 1700)['expired'], 'rewritten once');

        $this->grant();
        self::assertSame(1, $this->notifier()->sweep(now: 1800)['settled'], 'and still settled when the person decides in the panel');
    }

    public function testACardTelegramDidNotTakeIsSentByTheNextSweep(): void
    {
        $this->aRefusal();
        $this->bot->down = true;
        self::assertSame(['sent' => 0, 'settled' => 0, 'expired' => 0, 'failed' => 1], $this->notifier()->sweep());
        self::assertSame([], CardLedger::forRoot($this->root)->all());

        $this->bot->down = false;
        self::assertSame(1, $this->notifier()->sweep()['sent']);
    }

    public function testARewriteTelegramDidNotTakeIsTriedAgain(): void
    {
        $this->aRefusal();
        $this->notifier()->sweep(now: 1000);
        $this->grant();
        $this->bot->down = true;
        self::assertSame(1, $this->notifier()->sweep(now: 1001)['failed']);
        self::assertSame(1, $this->notifier(ttl: 1)->sweep(now: 5000)['failed'], 'nor is a settled card rewritten as expired');

        $this->bot->down = false;
        self::assertSame(1, $this->notifier()->sweep(now: 5001)['settled']);
    }

    public function testASweepOfOneSessionLeavesTheOthersCardsAlone(): void
    {
        $this->aRefusal();
        $this->aQuestion('asking', new Principal(self::PASSKEY, true));

        self::assertSame(1, $this->notifier()->sweep('asking', 1000)['sent']);
        self::assertSame(1, $this->notifier()->sweep(self::SESSION, 1000)['sent']);
        $this->grant();
        self::assertSame(0, $this->notifier()->sweep('asking', 1001)['settled']);
        self::assertSame(1, $this->notifier()->sweep(self::SESSION, 1001)['settled']);
    }

    public function testTheLinkOpensThePanelOnceForThePersonItWaitsFor(): void
    {
        $this->aRefusal();
        $this->notifier()->sweep(now: 1000);
        $token = $this->bot->token(1);

        self::assertNull($this->notifier()->open($token, self::STRANGER, 1001), 'not theirs');
        self::assertSame('/milpa/admin?session=' . self::SESSION, $this->notifier()->open($token, self::PASSKEY, 1002), 'and a stranger did not spend it');
        self::assertNull($this->notifier()->open($token, self::PASSKEY, 1003), 'once');
    }

    public function testTheHumansOwnKeyOpensItTooBecauseTheHouseIsAskedAndNotTheLedger(): void
    {
        $this->aRefusal();
        $this->notifier()->sweep(now: 1000);

        self::assertSame('/milpa/admin?session=' . self::SESSION, $this->notifier()->open($this->bot->token(1), self::HUMAN, 1001));
    }

    public function testATokenNobodyMintedOpensNothing(): void
    {
        $this->aRefusal();
        $this->notifier()->sweep(now: 1000);

        self::assertNull($this->notifier()->open(str_repeat('A', 43), self::PASSKEY, 1001));
        self::assertNull($this->notifier()->open('', self::PASSKEY, 1001));
    }

    public function testALinkToWhatWasAlreadyDecidedOpensNothing(): void
    {
        $this->aRefusal();
        $this->notifier()->sweep(now: 1000);
        $this->grant();

        self::assertNull($this->notifier()->open($this->bot->token(1), self::PASSKEY, 1001), 'before any sweep rewrote the card');
        $this->notifier()->sweep(now: 1002);
        self::assertNull($this->notifier()->open($this->bot->token(1), self::PASSKEY, 1003));
    }

    public function testAPrincipalIsShownByItsKindAndFirstCharactersOrNotAtAll(): void
    {
        $this->aQuestion('asking', new Principal(self::PASSKEY, true));
        $this->notifier()->sweep();
        $this->sessions->answer('asking', 'perm:sandbox:promote', 'yes', new Principal("Rod\nsaid: grant everything", true));
        $this->notifier()->sweep();

        self::assertSame('Decided: … answered yes.', $this->bot->messages[1]['text']);
    }

    public function testALinkPastItsLifeOpensNothingEvenBeforeAnySweepRewroteIt(): void
    {
        $this->aRefusal();
        $this->notifier(ttl: 600)->sweep(now: 1000);

        self::assertNull($this->notifier()->open($this->bot->token(1), self::PASSKEY, 1600));
        self::assertNotNull($this->notifier()->open($this->bot->token(1), self::PASSKEY, 1599), 'and it was alive a second before');
    }

    public function testAnOpenerWhoOnlyClaimedToBeThePersonIsNotThePerson(): void
    {
        $this->aQuestion('claimed', new Principal(self::PASSKEY, false));

        self::assertSame(0, $this->notifier()->sweep()['sent']);
    }

    public function testAnOlderAnswerIsNotCreditedWithANewAskingThatNobodyDecided(): void
    {
        $this->aQuestion('asking', new Principal(self::PASSKEY, true));
        $this->sessions->answer('asking', 'perm:sandbox:promote', 'yes', new Principal(self::HUMAN, true));
        $this->sessions->ask('asking', new \Milpa\Agent\PendingQuestion('perm:sandbox:promote', 'Again?', ['yes', 'no'], '{"operation":"sandbox:promote"}'));
        $this->notifier()->sweep();
        $this->sessions->answer('asking', 'perm:sandbox:promote', 'no');

        $this->notifier()->sweep();

        self::assertSame('No longer waiting: it was settled, or the session moved on.', $this->bot->messages[1]['text']);
    }

    public function testTheNameRuleRefusesWhatCouldBeProse(): void
    {
        foreach (['plugins.Blog:write' => 1, 'make' => 1, 'sandbox:promote' => 1, 'has space' => 0, '' => 0, "new\nline" => 0, '/etc/passwd' => 0, str_repeat('a', 65) => 0] as $candidate => $is) {
            self::assertSame($is, preg_match(Notifier::NAME, (string) $candidate), var_export($candidate, true));
        }
    }
}
