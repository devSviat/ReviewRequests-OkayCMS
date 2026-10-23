<?php

namespace Modules\Sviat\ReviewRequests;

use Okay\Core\EntityFactory;
use Okay\Modules\Sviat\ReviewRequests\Entities\ReviewRequestOptoutsEntity;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestPreferences;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestRules;
use PHPUnit\Framework\TestCase;

/**
 * Кабінет керує лише власною відмовою. Відмову за посиланням із листа він не
 * скасовує: адреса акаунта не підтверджена, і акаунт на чужу пошту повернув би
 * їй листи, від яких власник відмовився.
 */
class CabinetPreferencesTest extends TestCase
{
    private const USER = ['id' => 7, 'email' => ' Buyer@Example.com '];

    private function preferences(?object $optout, ?ReviewRequestOptoutsEntity $optouts = null): ReviewRequestPreferences
    {
        $optouts = $optouts ?? $this->createStub(ReviewRequestOptoutsEntity::class);
        $optouts->method('findOne')->willReturn($optout ?? false);
        $optouts->method('count')->willReturn($optout ? 1 : 0);
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturn($optouts);

        return new ReviewRequestPreferences($factory);
    }

    public function testStateWithoutOptOutIsSubscribed(): void
    {
        $state = $this->preferences(null)->stateFor((object) self::USER);

        $this->assertSame(['email' => 'buyer@example.com', 'subscribed' => true, 'locked' => false], $state);
    }

    public function testOptOutFromEmailLinkCannotBeUndoneInCabinet(): void
    {
        $optouts = $this->createMock(ReviewRequestOptoutsEntity::class);
        $optouts->expects($this->never())->method('deleteByHashAndUser');
        $preferences = $this->preferences((object) ['id' => 1, 'user_id' => null], $optouts);

        $this->assertTrue($preferences->stateFor((object) self::USER)['locked']);
        $this->assertFalse($preferences->setFromCabinet((object) self::USER, true));
    }

    public function testOwnOptOutCanBeUndone(): void
    {
        $optouts = $this->createMock(ReviewRequestOptoutsEntity::class);
        $optouts->expects($this->once())->method('deleteByHashAndUser')
            ->with(ReviewRequestRules::emailHash('buyer@example.com'), 7);
        $preferences = $this->preferences((object) ['id' => 1, 'user_id' => 7], $optouts);

        $this->assertTrue($preferences->setFromCabinet((object) self::USER, true));
    }

    public function testUnsubscribingFromCabinetRecordsTheAccount(): void
    {
        $optouts = $this->createMock(ReviewRequestOptoutsEntity::class);
        $optouts->expects($this->once())->method('add')->with($this->callback(
            fn($row) => $row['user_id'] === 7 && $row['email_hash'] === ReviewRequestRules::emailHash('buyer@example.com')
        ));

        $this->assertTrue($this->preferences(null, $optouts)->setFromCabinet((object) self::USER, false));
    }

    /**
     * Акаунт тимчасово ставить чужу адресу й відмовляється з кабінету; власник
     * пошти потім відмовляється з листа. Його відмова має стати «з листа», щоб
     * акаунт не зміг її скасувати.
     */
    public function testEmailLinkOptOutTakesOverAnAccountOptOut(): void
    {
        $optouts = $this->createMock(ReviewRequestOptoutsEntity::class);
        $optouts->method('findOne')->willReturn((object) ['id' => 5, 'user_id' => 7]);
        $optouts->expects($this->never())->method('add');
        $optouts->expects($this->once())->method('update')->with(5, ['user_id' => null]);
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturn($optouts);

        (new ReviewRequestPreferences($factory))->optOut(ReviewRequestRules::emailHash('buyer@example.com'), null);
    }

    /** Контроль: повторна відмова з кабінету рядок не чіпає. */
    public function testCabinetOptOutDoesNotRewriteAnExistingOne(): void
    {
        $optouts = $this->createMock(ReviewRequestOptoutsEntity::class);
        $optouts->method('findOne')->willReturn((object) ['id' => 5, 'user_id' => null]);
        $optouts->expects($this->never())->method('add');
        $optouts->expects($this->never())->method('update');
        $factory = $this->createStub(EntityFactory::class);
        $factory->method('get')->willReturn($optouts);

        (new ReviewRequestPreferences($factory))->optOut(ReviewRequestRules::emailHash('buyer@example.com'), 7);
    }

    public function testAccountWithoutUsableEmailGetsNoBlock(): void
    {
        $this->assertNull($this->preferences(null)->stateFor((object) ['id' => 7, 'email' => 'not-an-email']));
    }
}
