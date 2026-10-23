<?php

namespace Modules\Sviat\ReviewRequests;

use DateTimeImmutable;
use Okay\Core\EntityFactory;
use Okay\Core\QueryFactory;
use Okay\Core\QueryFactory\SqlQuery;
use Okay\Core\Settings;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestQueue;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestRules;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestSettings;
use PHPUnit\Framework\TestCase;

/**
 * Замовлення без адреси не записуються ні при виявленні, ні в базову лінію.
 * Перевіряємо сам запит: інакше фільтр можна прибрати, і тести лишаться
 * зеленими.
 */
class QueueEmailFilterTest extends TestCase
{
    /** @var string[] */
    private array $statements = [];

    public function testDetectAsksOnlyForOrdersWithAnAddress(): void
    {
        $this->queue()->detect(new DateTimeImmutable('2026-10-23 12:00:00'));

        $this->assertCount(1, $this->statements);
        $this->assertStringContainsString(ReviewRequestQueue::HAS_EMAIL, $this->statements[0]);
    }

    /**
     * Без фільтра й тут замовлення без адреси лягло б у базову лінію при
     * зміні статусів і вже ніколи не виявилось би, навіть коли адресу допишуть.
     */
    public function testBaselineSkipsOrdersWithoutAnAddress(): void
    {
        $this->queue()->baseline(new DateTimeImmutable('2026-10-23 12:00:00'), [4]);

        $this->assertCount(1, $this->statements);
        $this->assertStringContainsString(ReviewRequestQueue::HAS_EMAIL, $this->statements[0]);
    }

    /** «@» — ознака адреси: порожнє поле, «0» чи «-» нею не є, будь-яка адреса є. */
    public function testTheFilterIsAboutTheAtSign(): void
    {
        $this->assertSame("LOCATE('@', o.email) > 0", ReviewRequestQueue::HAS_EMAIL);
    }

    private function queue(): ReviewRequestQueue
    {
        $query = $this->createStub(SqlQuery::class);
        $query->method('setStatement')->willReturnCallback(function ($statement) use (&$query) {
            $this->statements[] = (string) $statement;

            return $query;
        });
        $query->method('bindValue')->willReturnSelf();
        $query->method('bindValues')->willReturnSelf();
        $query->method('results')->willReturn([]);

        $queryFactory = $this->createStub(QueryFactory::class);
        $queryFactory->method('newSqlQuery')->willReturn($query);

        $entity = $this->createStub(\Okay\Modules\Sviat\ReviewRequests\Entities\ReviewRequestsEntity::class);
        $entity->method('count')->willReturn(0);
        $entityFactory = $this->createStub(EntityFactory::class);
        $entityFactory->method('get')->willReturn($entity);

        $core = $this->createStub(Settings::class);
        $core->method('get')->willReturnCallback(fn($name) => match ($name) {
            ReviewRequestSettings::PREFIX . 'statuses' => '4',
            ReviewRequestSettings::PREFIX . 'max_order_age_days' => '45',
            default => null,
        });
        $settings = new ReviewRequestSettings($core);

        return new ReviewRequestQueue($entityFactory, $queryFactory, $settings, new ReviewRequestRules($settings));
    }
}
