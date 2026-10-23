<?php

namespace Modules\Sviat\ReviewRequests;

use DateTimeImmutable;
use Okay\Core\Settings;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestCron;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestMailer;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestQueue;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestRules;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Без базової лінії черга не виявляє «нові» замовлення: інакше обірване
 * встановлення перетворило б історію виконаних замовлень на розсилку.
 */
class CronBaselineGuardTest extends TestCase
{
    private function cron(ReviewRequestQueue $queue): ReviewRequestCron
    {
        $settings = new ReviewRequestSettings($this->createStub(Settings::class));

        return new ReviewRequestCron(
            $queue,
            new ReviewRequestRules($settings),
            $this->createStub(ReviewRequestMailer::class),
            $settings,
            new NullLogger()
        );
    }

    public function testWithoutBaselineCronRecordsBaselineInsteadOfDetecting(): void
    {
        $queue = $this->createMock(ReviewRequestQueue::class);
        $queue->method('hasBaseline')->willReturn(false);
        $queue->expects($this->once())->method('baseline')->willReturn(3);
        $queue->expects($this->never())->method('detect');
        $queue->expects($this->never())->method('pending');

        $stats = $this->cron($queue)->run(new DateTimeImmutable('2026-11-20 12:00:00'));

        $this->assertSame(0, $stats['detected']);
    }

    public function testWithBaselineCronDetects(): void
    {
        $queue = $this->createMock(ReviewRequestQueue::class);
        $queue->method('hasBaseline')->willReturn(true);
        $queue->expects($this->never())->method('baseline');
        $queue->expects($this->once())->method('detect')->willReturn(2);
        $queue->method('pending')->willReturn([]);

        $stats = $this->cron($queue)->run(new DateTimeImmutable('2026-11-20 12:00:00'));

        $this->assertSame(2, $stats['detected']);
    }
}
