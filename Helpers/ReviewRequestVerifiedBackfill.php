<?php

namespace Okay\Modules\Sviat\ReviewRequests\Helpers;

use DateTimeImmutable;
use Okay\Core\Config;
use Okay\Core\QueryFactory;
use Okay\Modules\Sviat\ReviewRequests\Entities\ReviewRequestVerifiedEntity;

/**
 * Разова дія адміна: «Перевірена покупка» старим відгукам, email яких збігається
 * з email виконаного замовлення цього товару, оформленого не пізніше за відгук.
 *
 * Email у формі відгуку ніхто не перевіряє, тож як постійне правило цей збіг
 * дав би позначку будь-кому, хто знає адресу покупця. Тому лише раз і лише для
 * відгуків, написаних до натискання; нові відгуки покупців приходять за посиланням
 * із листа (ReviewRequestReviews::verifiedCommentIds()).
 */
class ReviewRequestVerifiedBackfill
{
    /** Скільки рядків показати в попередньому перегляді; рахуються всі. */
    public const PREVIEW_LIMIT = 200;

    public function __construct(
        private QueryFactory $queryFactory,
        private ReviewRequestSettings $settings,
        private ReviewRequestReviews $reviews,
        private Config $config,
    ) {
    }

    /** Коли дію виконано; null — ще ні. */
    public function doneAt(): ?string
    {
        $doneAt = (string) $this->settings->get('verified_backfill_at');

        return $doneAt === '' ? null : $doneAt;
    }

    public function doneCount(): int
    {
        return $this->settings->int('verified_backfill_count');
    }

    /**
     * @return array{total: int, rows: object[]} rows — перші PREVIEW_LIMIT відгуків
     *         з comment_id, order_id, date, name, email, product_name
     */
    public function preview(DateTimeImmutable $now): array
    {
        $candidates = $this->candidates($now);

        return ['total' => count($candidates), 'rows' => array_slice($candidates, 0, self::PREVIEW_LIMIT)];
    }

    /**
     * @return int|null скільки відгуків позначено; null — дію вже виконано або
     *         таблиці ще немає (модуль не оновлено), і нічого не змінено
     */
    public function apply(DateTimeImmutable $now, int $managerId): ?int
    {
        if ($this->doneAt() !== null || !$this->tableExists()) {
            return null;
        }

        $candidates = $this->candidates($now);
        $createdAt = $now->format('Y-m-d H:i:s');
        // Порціями: один INSERT на тисячі рядків упирається в max_allowed_packet.
        foreach (array_chunk($candidates, 500) as $chunk) {
            $values = [];
            $bind = [];
            foreach ($chunk as $i => $row) {
                $values[] = "(:c{$i}, :o{$i}, :m, :at)";
                $bind["c{$i}"] = (int) $row->comment_id;
                $bind["o{$i}"] = (int) $row->order_id;
            }
            // IGNORE: comment_id унікальний, тож подвійне натискання не дасть дублів.
            $this->queryFactory->newSqlQuery()->setStatement(
                'INSERT IGNORE INTO ' . ReviewRequestVerifiedEntity::getTable() . ' (comment_id, order_id, manager_id, created_at)
                 VALUES ' . implode(',', $values)
            )->bindValues($bind + ['m' => $managerId, 'at' => $createdAt])->execute();
        }

        $this->settings->set('verified_backfill_at', $createdAt);
        $this->settings->set('verified_backfill_count', count($candidates));

        return count($candidates);
    }

    /**
     * Відгуки, які дія позначила б: збіг email без регістру й пробілів, замовлення
     * саме цього товару, виконане, оформлене не пізніше за відгук; лише відгук,
     * а не відповідь у гілці. Ті, що вже мають позначку, сюди не потрапляють.
     *
     * @return object[]
     */
    private function candidates(DateTimeImmutable $now): array
    {
        $statuses = $this->settings->completedStatuses();
        if ($statuses === []) {
            return [];
        }

        // Від покупок до замовлень, а не від email: LOWER(TRIM()) не бере індекс,
        // тож порівнюються лише замовлення з цим товаром.
        $rows = $this->queryFactory->newSqlQuery()->setStatement(
            'SELECT c.id AS comment_id, MIN(o.id) AS order_id, c.date, c.name, c.email, MAX(p.product_name) AS product_name
             FROM __comments c
             JOIN __purchases p ON p.product_id = c.object_id
             JOIN __orders o ON o.id = p.order_id
             WHERE c.type = :type
               AND c.parent_id = 0
               AND c.date <= :now
               AND TRIM(c.email) <> \'\'
               AND LOWER(TRIM(o.email)) = LOWER(TRIM(c.email))
               AND o.date <= c.date
               AND o.status_id IN (' . implode(',', array_map(intval(...), $statuses)) . ')
             GROUP BY c.id, c.date, c.name, c.email
             ORDER BY c.date DESC, c.id DESC'
        )->bindValues(['type' => 'product', 'now' => $now->format('Y-m-d H:i:s')])->results();

        if ($rows === []) {
            return [];
        }

        $verified = $this->reviews->verifiedCommentIds(array_map(fn($r) => (int) $r->comment_id, $rows), $statuses);

        return array_values(array_filter($rows, fn($r) => !isset($verified[(int) $r->comment_id])));
    }

    private function tableExists(): bool
    {
        $name = (string) $this->config->get('db_prefix') . substr(ReviewRequestVerifiedEntity::getTable(), 2);
        $found = $this->queryFactory->newSqlQuery()->setStatement(
            'SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :name'
        )->bindValue('name', $name)->result('n');

        return (int) $found > 0;
    }
}
