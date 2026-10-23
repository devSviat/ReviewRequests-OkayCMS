<?php

namespace Okay\Modules\Sviat\ReviewRequests\Helpers;

use DateTimeImmutable;
use Okay\Core\EntityFactory;
use Okay\Core\QueryFactory;
use Okay\Modules\Sviat\ReviewRequests\Entities\ReviewRequestsEntity;

/**
 * Ручний добір минулого: замовлення, виконані до встановлення модуля
 * (baseline), ставляться в чергу лише свідомою дією адміна й лише після
 * попереднього перегляду. Далі діють ті самі правила й ліміти, що й для нових.
 */
class ReviewRequestBackfill
{
    /** Скільки замовлень можна поставити в чергу за одну дію. */
    public const MAX_PER_RUN = 500;

    private EntityFactory $entityFactory;
    private QueryFactory $queryFactory;
    private ReviewRequestSettings $settings;
    private ReviewRequestRules $rules;

    public function __construct(EntityFactory $entityFactory, QueryFactory $queryFactory, ReviewRequestSettings $settings, ReviewRequestRules $rules)
    {
        $this->entityFactory = $entityFactory;
        $this->queryFactory = $queryFactory;
        $this->settings = $settings;
        $this->rules = $rules;
    }

    /**
     * @return array{total: int, pending: int, skipped: array<string, int>, truncated: bool}
     */
    public function preview(string $from, string $to): array
    {
        $result = ['total' => 0, 'pending' => 0, 'skipped' => [], 'truncated' => false];
        $now = new DateTimeImmutable();

        $candidates = $this->candidates($from, $to);
        $result['truncated'] = count($candidates) > self::MAX_PER_RUN;
        foreach (array_slice($candidates, 0, self::MAX_PER_RUN) as $row) {
            $result['total']++;
            [$status, $reason] = $this->rules->onDetect($row, $now);
            if ($status === ReviewRequestsEntity::STATUS_PENDING) {
                $result['pending']++;
            } else {
                $result['skipped'][$reason] = ($result['skipped'][$reason] ?? 0) + 1;
            }
        }

        return $result;
    }

    /**
     * Ставить у чергу те, що пройшло правила виявлення. detected_at — «зараз
     * мінус затримка»: замовлення давно виконане, чекати ще тиждень нема чого,
     * але вікно відправки, добовий ліміт і частота на адресу діють як завжди.
     *
     * @return int скільки поставлено в чергу
     */
    public function enqueue(string $from, string $to): int
    {
        $now = new DateTimeImmutable();
        $delay = $this->settings->int('delay_days');
        $detectedAt = $now->modify("-{$delay} days")->format('Y-m-d H:i:s');
        $requests = $this->entityFactory->get(ReviewRequestsEntity::class);

        $count = 0;
        foreach (array_slice($this->candidates($from, $to), 0, self::MAX_PER_RUN) as $row) {
            [$status] = $this->rules->onDetect($row, $now);
            if ($status !== ReviewRequestsEntity::STATUS_PENDING) {
                continue;
            }
            $email = ReviewRequestRules::normalizeEmail($row->email);
            $requests->update((int) $row->request_id, [
                'status' => ReviewRequestsEntity::STATUS_PENDING,
                'skip_reason' => null,
                'email_hash' => ReviewRequestRules::emailHash((string) $email),
                'detected_at' => $detectedAt,
            ]);
            $count++;
        }

        return $count;
    }

    /** @return object[] */
    private function candidates(string $from, string $to): array
    {
        $statuses = $this->settings->completedStatuses();
        if ($statuses === []) {
            return [];
        }

        return $this->queryFactory->newSqlQuery()->setStatement(
            'SELECT rr.id AS request_id, o.id, o.email, o.date, o.lang_id
             FROM __sviat__review_requests rr
             JOIN __orders o ON o.id = rr.order_id
             WHERE rr.status = :baseline
               AND o.status_id IN (' . implode(',', array_map('intval', $statuses)) . ')
               AND o.date >= :from AND o.date < :to
             ORDER BY o.id
             LIMIT ' . (self::MAX_PER_RUN + 1)
        )->bindValues([
            'baseline' => ReviewRequestsEntity::STATUS_BASELINE,
            'from' => $from . ' 00:00:00',
            'to' => (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00',
        ])->results();
    }
}
