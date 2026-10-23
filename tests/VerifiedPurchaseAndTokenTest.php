<?php

namespace Modules\Sviat\ReviewRequests;

use Okay\Core\Settings;
use Okay\Modules\Sviat\ReviewRequests\Extenders\FrontExtender;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestReviews;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestSettings;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestToken;
use PHPUnit\Framework\TestCase;

class VerifiedPurchaseAndTokenTest extends TestCase
{
    /** Позначка — лише в тих відгуків, які база визнала відгуками покупців; решта її не мають. */
    public function testOnlyBuyerCommentsAreMarked(): void
    {
        $reviews = $this->createMock(ReviewRequestReviews::class);
        $reviews->expects($this->once())->method('verifiedCommentIds')
            ->with([11, 12, 13], [4])
            ->willReturn([12 => true]);

        $comments = [(object) ['id' => 11], (object) ['id' => 12], (object) ['id' => 13]];
        $marked = (new FrontExtender($reviews, $this->settings()))->markVerifiedPurchases($comments);

        $this->assertSame([false, true, false], array_map(fn($c) => $c->sviat__verified_purchase, $marked));
    }

    public function testEmptyListIsLeftAlone(): void
    {
        $reviews = $this->createMock(ReviewRequestReviews::class);
        $reviews->expects($this->never())->method('verifiedCommentIds');

        $this->assertSame([], (new FrontExtender($reviews, $this->settings()))->markVerifiedPurchases([]));
    }

    private function settings(): ReviewRequestSettings
    {
        $stub = $this->createStub(Settings::class);
        $stub->method('get')->willReturnCallback(fn($name) => $name === ReviewRequestSettings::PREFIX . 'statuses' ? '4' : null);

        return new ReviewRequestSettings($stub);
    }

    public function testTokenIs32RandomBytesAndOnlyItsHashIsStored(): void
    {
        $a = ReviewRequestToken::generate();
        $b = ReviewRequestToken::generate();

        $this->assertNotSame($a, $b);
        $this->assertTrue(ReviewRequestToken::isWellFormed($a));
        $this->assertSame(32, strlen(base64_decode(strtr($a, '-_', '+/') . '=')));
        $this->assertSame(hash('sha256', $a), ReviewRequestToken::hash($a));
        $this->assertNotSame($a, ReviewRequestToken::hash($a));
    }

    /** Маршрут приймає лише такий токен, тож усе інше не доходить навіть до бази. */
    public function testMalformedTokensAreRejected(): void
    {
        foreach (['', 'short', str_repeat('A', 42), str_repeat('A', 44), str_repeat('A', 42) . '/', str_repeat('A', 42) . '=', ['x'], null] as $token) {
            $this->assertFalse(ReviewRequestToken::isWellFormed($token), var_export($token, true));
        }
    }

    /** Менеджер без права на замовлення: навіть коротка адреса не має лишитись цілою. */
    public function testMaskEmailHidesShortAddressesToo(): void
    {
        $this->assertSame('bu***@example.com', \Okay\Modules\Sviat\ReviewRequests\Backend\Controllers\ReviewRequestsAdmin::maskEmail('buyer@example.com'));
        $this->assertSame('a*@x.com', \Okay\Modules\Sviat\ReviewRequests\Backend\Controllers\ReviewRequestsAdmin::maskEmail('ab@x.com'));
        $this->assertSame('a**@x.com', \Okay\Modules\Sviat\ReviewRequests\Backend\Controllers\ReviewRequestsAdmin::maskEmail('abc@x.com'));
    }

    /**
     * Повну адресу показуємо лише тому, хто й так бачить її в замовленнях.
     * Право модуля саме по собі адрес покупців не відкриває.
     */
    public function testFullEmailsOnlyForManagersWhoSeeOrders(): void
    {
        $admin = \Okay\Modules\Sviat\ReviewRequests\Backend\Controllers\ReviewRequestsAdmin::class;
        $managers = (new \ReflectionClass(\Okay\Core\Managers::class))->newInstanceWithoutConstructor();

        $this->assertTrue($admin::showsFullEmails($managers, (object) ['permissions' => ['orders', 'sviat__review_requests']]));
        $this->assertFalse($admin::showsFullEmails($managers, (object) ['permissions' => ['sviat__review_requests']]));
        $this->assertFalse($admin::showsFullEmails($managers, null));
    }

    /** Те, що йде в шаблон: адреса повна або маска, а сирої адреси, хешів і токенів немає. */
    public function testJournalRowsCarryOnlyTheShownAddress(): void
    {
        $admin = \Okay\Modules\Sviat\ReviewRequests\Backend\Controllers\ReviewRequestsAdmin::class;
        $row = fn() => (object) ['email' => 'buyer@example.com', 'token_hash' => 't', 'unsubscribe_hash' => 'u', 'email_hash' => 'h', 'status' => 'sent'];

        [$full] = $admin::journalRows([$row()], true);
        [$masked] = $admin::journalRows([$row()], false);

        $this->assertSame('buyer@example.com', $full->email_shown);
        $this->assertSame('bu***@example.com', $masked->email_shown);
        foreach ([$full, $masked] as $shown) {
            $this->assertSame(['status', 'email_shown'], array_keys(get_object_vars($shown)));
        }
    }
}
