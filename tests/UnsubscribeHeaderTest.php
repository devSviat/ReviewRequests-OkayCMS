<?php

namespace Modules\Sviat\ReviewRequests;

use Okay\Modules\Sviat\ReviewRequests\Extenders\FrontExtender;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestMailer;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestReviews;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestSettings;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * PHPMailer один на процес, і Notify::SMTP() не очищує заголовки між листами.
 * List-Unsubscribe має бути рівно в нашому листі й зникати з наступного листа
 * магазину, а «MIME-Version», яку Notify дописує щоразу, — не множитись.
 */
class UnsubscribeHeaderTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->setPendingUrl(null);
    }

    public function testOurEmailGetsTheHeaderAndTheNextEmailDoesNot(): void
    {
        $mailer = new PHPMailer();
        $extender = new FrontExtender($this->createStub(ReviewRequestReviews::class), $this->createStub(ReviewRequestSettings::class));

        $this->sendLikeNotify($mailer, $extender, 'https://shop.test/review-request/unsubscribe/abc');
        $this->assertSame(['<https://shop.test/review-request/unsubscribe/abc>'], $this->header($mailer, 'List-Unsubscribe'));

        $this->sendLikeNotify($mailer, $extender, null);
        $this->assertSame([], $this->header($mailer, 'List-Unsubscribe'), 'заголовок перетік у наступний лист');
    }

    /** RFC 8058: List-Unsubscribe-Post лише з увімкненим налаштуванням і лише в нашому листі. */
    public function testOneClickHeaderFollowsTheSetting(): void
    {
        $mailer = new PHPMailer();
        $off = new FrontExtender($this->createStub(ReviewRequestReviews::class), $this->moduleSettings(false));
        $on = new FrontExtender($this->createStub(ReviewRequestReviews::class), $this->moduleSettings(true));
        $url = 'https://shop.test/review-request/unsubscribe/abc';

        $this->sendLikeNotify($mailer, $off, $url);
        $this->assertSame([], $this->header($mailer, 'List-Unsubscribe-Post'), 'вимкнено — заголовка немає');

        $this->sendLikeNotify($mailer, $on, $url);
        $this->assertSame(['List-Unsubscribe=One-Click'], $this->header($mailer, 'List-Unsubscribe-Post'));

        $this->sendLikeNotify($mailer, $on, null);
        $this->assertSame([], $this->header($mailer, 'List-Unsubscribe-Post'), 'заголовок перетік у наступний лист');
    }

    private function moduleSettings(bool $oneClick): ReviewRequestSettings
    {
        $stub = $this->createStub(\Okay\Core\Settings::class);
        $stub->method('get')->willReturnCallback(fn($name) => $name === ReviewRequestSettings::PREFIX . 'one_click' ? ($oneClick ? '1' : '0') : null);

        return new ReviewRequestSettings($stub);
    }

    public function testRepeatedSendsDoNotAccumulateMimeVersion(): void
    {
        $mailer = new PHPMailer();
        $extender = new FrontExtender($this->createStub(ReviewRequestReviews::class), $this->createStub(ReviewRequestSettings::class));

        for ($i = 0; $i < 3; $i++) {
            $this->sendLikeNotify($mailer, $extender, null);
        }

        // PHPMailer пише MIME-Version сам; власний заголовок був би дублем.
        $this->assertSame([], $this->header($mailer, 'MIME-Version'));
    }

    public function testOtherCustomHeadersSurvive(): void
    {
        $mailer = new PHPMailer();
        $mailer->addCustomHeader('X-Shop', 'shop');
        $extender = new FrontExtender($this->createStub(ReviewRequestReviews::class), $this->createStub(ReviewRequestSettings::class));

        $this->sendLikeNotify($mailer, $extender, null);

        $this->assertSame(['shop'], $this->header($mailer, 'X-Shop'));
    }

    /** Те, що робить Notify::SMTP() перед відправкою: дописує заголовок і віддає PHPMailer розширенню. */
    private function sendLikeNotify(PHPMailer $mailer, FrontExtender $extender, ?string $pendingUrl): void
    {
        $this->setPendingUrl($pendingUrl);
        $mailer->addCustomHeader("MIME-Version: 1.0\n");
        $extender->addUnsubscribeHeader($mailer);
    }

    /** @return string[] */
    private function header(PHPMailer $mailer, string $name): array
    {
        return array_values(array_map(
            fn($h) => $h[1],
            array_filter($mailer->getCustomHeaders(), fn($h) => strcasecmp($h[0], $name) === 0)
        ));
    }

    private function setPendingUrl(?string $url): void
    {
        (new ReflectionProperty(ReviewRequestMailer::class, 'pendingUnsubscribeUrl'))->setValue(null, $url);
    }
}
