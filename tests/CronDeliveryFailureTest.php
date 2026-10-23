<?php

namespace Modules\Sviat\ReviewRequests;

use DateTimeImmutable;
use Okay\Core\EntityFactory;
use Okay\Core\QueryFactory;
use Okay\Core\Settings;
use Okay\Modules\Sviat\ReviewRequests\Entities\ReviewRequestItemsEntity;
use Okay\Modules\Sviat\ReviewRequests\Entities\ReviewRequestsEntity;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestCron;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestMailer;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestQueue;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestRules;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Збій пошти чи відсутня адреса сайту спільні для всіх листів: вони не мають
 * за кілька прогонів назавжди закрити чергу статусом error.
 */
class CronDeliveryFailureTest extends TestCase
{
    private const NOW = '2026-11-20 12:00:00';

    private function settings(array $values): ReviewRequestSettings
    {
        $values += ['mode' => 'live', 'statuses' => '4', 'delay_days' => 0, 'window_from' => 0, 'window_to' => 24, 'root_url' => 'https://shop.example'];
        $stub = $this->createStub(Settings::class);
        $stub->method('get')->willReturnCallback(
            fn($name) => $values[substr($name, strlen(ReviewRequestSettings::PREFIX))] ?? null
        );

        return new ReviewRequestSettings($stub);
    }

    /** Черга з двома запитами, кожен готовий до відправки. */
    private function queue(bool $mock = true): ReviewRequestQueue
    {
        $queue = $mock ? $this->createMock(ReviewRequestQueue::class) : $this->createStub(ReviewRequestQueue::class);
        $queue->method('hasBaseline')->willReturn(true);
        $queue->method('pending')->willReturn([
            (object) ['id' => 1, 'order_id' => 11, 'email_hash' => null, 'detected_at' => '2026-11-01 10:00:00', 'attempts' => 0, 'retry_at' => null],
            (object) ['id' => 2, 'order_id' => 12, 'email_hash' => null, 'detected_at' => '2026-11-01 10:00:00', 'attempts' => 0, 'retry_at' => null],
        ]);
        $queue->method('findOrder')->willReturnCallback(
            fn($id) => (object) ['id' => $id, 'status_id' => 4, 'email' => "buyer{$id}@example.com", 'date' => '2026-11-01 09:00:00']
        );
        $queue->method('reviewablePurchases')->willReturn([(object) ['id' => 5, 'product_id' => 7]]);

        return $queue;
    }

    private function cron(ReviewRequestQueue $queue, ReviewRequestMailer $mailer, ReviewRequestSettings $settings): ReviewRequestCron
    {
        return new ReviewRequestCron($queue, new ReviewRequestRules($settings), $mailer, $settings, new NullLogger());
    }

    public function testMissingRootUrlSendsNothingAndSpendsNoAttempts(): void
    {
        $queue = $this->queue();
        $queue->expects($this->never())->method('markFailed');
        $mailer = $this->createMock(ReviewRequestMailer::class);
        $mailer->expects($this->never())->method('send');

        $stats = $this->cron($queue, $mailer, $this->settings(['root_url' => '']))->run(new DateTimeImmutable(self::NOW));

        $this->assertSame(2, $stats['waiting']);
    }

    public function testFailedDeliveryStopsTheRun(): void
    {
        $queue = $this->queue();
        $queue->expects($this->once())->method('markFailed');
        $mailer = $this->createMock(ReviewRequestMailer::class);
        $mailer->expects($this->once())->method('send')->willReturn([false, 'SMTP down']);

        $stats = $this->cron($queue, $mailer, $this->settings([]))->run(new DateTimeImmutable(self::NOW));

        $this->assertSame(['failed' => 1, 'waiting' => 1], ['failed' => $stats['failed'], 'waiting' => $stats['waiting']]);
    }

    /** Контроль: справна пошта отримує обидва листи. */
    public function testWorkingDeliverySendsEverything(): void
    {
        $mailer = $this->createMock(ReviewRequestMailer::class);
        $mailer->expects($this->exactly(2))->method('send')->willReturn([true, '']);

        $stats = $this->cron($this->queue(false), $mailer, $this->settings([]))->run(new DateTimeImmutable(self::NOW));

        $this->assertSame(2, $stats['sent']);
    }

    /** Обірваний після листа процес не має дати другого листа: запит надісланий ще до відправки. */
    public function testRequestIsMarkedSentBeforeDelivery(): void
    {
        $calls = [];
        $queue = $this->queue(false);
        $queue->method('markSent')->willReturnCallback(function () use (&$calls) { $calls[] = 'markSent'; });
        $mailer = $this->createStub(ReviewRequestMailer::class);
        $mailer->method('send')->willReturnCallback(function () use (&$calls) { $calls[] = 'send'; return [true, '']; });

        $this->cron($queue, $mailer, $this->settings(['daily_cap' => 1]))->run(new DateTimeImmutable(self::NOW));

        $this->assertSame(['markSent', 'send'], $calls);
    }

    /** Посилання відписки (воно й у заголовку List-Unsubscribe) не має відкривати форму відгуку. */
    public function testReviewAndUnsubscribeTokensDiffer(): void
    {
        $hashes = [];
        $queue = $this->queue(false);
        $queue->method('markSent')->willReturnCallback(function ($id, $tokenHash, $unsubscribeHash) use (&$hashes) {
            $hashes = [$tokenHash, $unsubscribeHash];
        });
        $sent = [];
        $mailer = $this->createStub(ReviewRequestMailer::class);
        $mailer->method('send')->willReturnCallback(function ($order, $purchases, $token, $unsubscribeToken) use (&$sent) {
            $sent = [$token, $unsubscribeToken];
            return [true, ''];
        });

        $this->cron($queue, $mailer, $this->settings(['daily_cap' => 1]))->run(new DateTimeImmutable(self::NOW));

        $this->assertNotSame($sent[0], $sent[1]);
        $this->assertSame([hash('sha256', $sent[0]), hash('sha256', $sent[1])], $hashes);
    }

    /** @return array<int, array{0: int, 1: string, 2: ?string}> */
    public static function attemptProvider(): array
    {
        return [
            [0, ReviewRequestsEntity::STATUS_PENDING, '2026-11-20 13:00:00'],
            [1, ReviewRequestsEntity::STATUS_PENDING, '2026-11-21 12:00:00'],
            [2, ReviewRequestsEntity::STATUS_ERROR, null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('attemptProvider')]
    public function testFailedAttemptIsRetriedLater(int $attemptsBefore, string $status, ?string $retryAt): void
    {
        $requests = $this->createMock(ReviewRequestsEntity::class);
        $requests->expects($this->once())->method('update')->with(1, $this->callback(
            fn($data) => $data['status'] === $status && $data['retry_at'] === $retryAt && $data['attempts'] === $attemptsBefore + 1
                && $data['token_hash'] === null && $data['sent_at'] === null
        ));
        $entityFactory = $this->createStub(EntityFactory::class);
        $entityFactory->method('get')->willReturnCallback(
            fn($class) => $class === ReviewRequestsEntity::class ? $requests : $this->createStub(ReviewRequestItemsEntity::class)
        );
        $settings = $this->settings([]);
        $queue = new ReviewRequestQueue($entityFactory, $this->createStub(QueryFactory::class), $settings, new ReviewRequestRules($settings));

        $queue->markFailed((object) ['id' => 1, 'attempts' => $attemptsBefore], 'SMTP down', new DateTimeImmutable(self::NOW));
    }
}
