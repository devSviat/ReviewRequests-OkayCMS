<?php

namespace Okay\Modules\Sviat\ReviewRequests\Helpers;

use DateTimeImmutable;
use Okay\Core\EntityFactory;
use Okay\Core\QueryFactory;
use Okay\Entities\ImagesEntity;
use Okay\Entities\OrdersEntity;
use Okay\Entities\ProductsEntity;
use Okay\Entities\PurchasesEntity;
use Okay\Modules\Sviat\ReviewRequests\Entities\ReviewRequestItemsEntity;
use Okay\Modules\Sviat\ReviewRequests\Entities\ReviewRequestOptoutsEntity;
use Okay\Modules\Sviat\ReviewRequests\Entities\ReviewRequestsEntity;

/**
 * Усе, що черга читає й пише в базі. Рішення «писати чи ні» — у
 * ReviewRequestRules, тут лише дані для нього й наслідки.
 */
class ReviewRequestQueue
{
    private const DATETIME = 'Y-m-d H:i:s';

    /** Скільки нових виконаних замовлень виявляти за один прогін. */
    public const DETECT_BATCH = 500;

    /** Невдала відправка лишає запит у черзі до стількох спроб. */
    public const MAX_ATTEMPTS = 3;

    /**
     * Пауза перед наступною спробою, годин, за номером невдалої спроби.
     * Збій пошти зазвичай спільний для всіх листів: без паузи три прогони
     * планувальника (45 хв) назавжди закрили б увесь чекаючий обсяг.
     */
    private const RETRY_AFTER_HOURS = [1 => 1, 2 => 24];

    private EntityFactory $entityFactory;
    private QueryFactory $queryFactory;
    private ReviewRequestSettings $settings;
    private ReviewRequestRules $rules;

    public function __construct(
        EntityFactory $entityFactory,
        QueryFactory $queryFactory,
        ReviewRequestSettings $settings,
        ReviewRequestRules $rules
    ) {
        $this->entityFactory = $entityFactory;
        $this->queryFactory = $queryFactory;
        $this->settings = $settings;
        $this->rules = $rules;
    }

    /**
     * Базова лінія при встановленні: усе, що вже виконане, записується як
     * baseline і листа не отримає ніколи. Без цього перший запуск розіслав би
     * листи по всій історії замовлень.
     *
     * @return int скільки замовлень записано
     */
    public function baseline(DateTimeImmutable $now): int
    {
        $this->settings->set('baseline_at', $now->format(self::DATETIME));

        $statuses = $this->settings->completedStatuses();
        if ($statuses === []) {
            return 0;
        }

        $this->queryFactory->newSqlQuery()->setStatement(
            'INSERT IGNORE INTO __sviat__review_requests (order_id, status, skip_reason, lang_id, detected_at, attempts)
             SELECT o.id, :status, NULL, o.lang_id, :now, 0
             FROM __orders o
             WHERE o.status_id IN (' . $this->intList($statuses) . ')'
        )->bindValues([
            'status' => ReviewRequestsEntity::STATUS_BASELINE,
            'now' => $now->format(self::DATETIME),
        ])->execute();

        return (int) $this->entityFactory->get(ReviewRequestsEntity::class)
            ->count(['status' => ReviewRequestsEntity::STATUS_BASELINE]);
    }

    /**
     * Чи зафіксовано базову лінію. Без неї detect() прийняв би кожне вже
     * виконане замовлення за нове: так буває, коли ядро записало модуль як
     * встановлений, а install() обірвався до baseline().
     */
    public function hasBaseline(): bool
    {
        return (string) $this->settings->get('baseline_at') !== '';
    }

    /**
     * Нові виконані замовлення: у виконаному статусі й ще без запису в черзі.
     * Шлях зміни статусу (картка, масова дія, API, модуль) значення не має, тож
     * час виявлення — і є «час виконання» для затримки.
     *
     * @return int скільки записано
     */
    public function detect(DateTimeImmutable $now): int
    {
        $statuses = $this->settings->completedStatuses();
        if ($statuses === []) {
            return 0;
        }

        // Старші за граничний вік однаково були б пропущені як too_old, а межа за
        // датою не дає запиту проходити всю історію виконаних замовлень щопрогону.
        $maxAge = max(1, $this->settings->int('max_order_age_days'));
        $orders = $this->queryFactory->newSqlQuery()->setStatement(
            'SELECT o.id, o.email, o.lang_id, o.date
             FROM __orders o
             LEFT JOIN __sviat__review_requests rr ON rr.order_id = o.id
             WHERE rr.id IS NULL AND o.status_id IN (' . $this->intList($statuses) . ')
               AND o.date >= :since
             ORDER BY o.id
             LIMIT ' . self::DETECT_BATCH
        )->bindValue('since', $now->modify("-{$maxAge} days")->format(self::DATETIME))->results();

        $requests = $this->entityFactory->get(ReviewRequestsEntity::class);
        foreach ($orders as $order) {
            [$status, $reason] = $this->rules->onDetect($order, $now);
            $email = ReviewRequestRules::normalizeEmail($order->email);
            $requests->add([
                'order_id' => (int) $order->id,
                'status' => $status,
                'skip_reason' => $reason,
                'email_hash' => $email === null ? null : ReviewRequestRules::emailHash($email),
                'lang_id' => (int) $order->lang_id,
                'detected_at' => $now->format(self::DATETIME),
                'attempts' => 0,
            ]);
        }

        return count($orders);
    }

    /** @return object[] запити в черзі, найстаріші першими */
    public function pending(int $limit): array
    {
        return $this->entityFactory->get(ReviewRequestsEntity::class)
            ->order('id')
            ->find(['status' => ReviewRequestsEntity::STATUS_PENDING, 'limit' => max(1, $limit)]);
    }

    /** @return object[] останні виконані замовлення з email — для тестового листа */
    public function latestCompletedOrders(int $limit): array
    {
        $statuses = $this->settings->completedStatuses();
        if ($statuses === []) {
            return [];
        }

        return $this->entityFactory->get(OrdersEntity::class)->find([
            'status_id' => $statuses,
            'limit' => max(1, $limit),
        ]);
    }

    public function findOrder(int $orderId): ?object
    {
        $order = $this->entityFactory->get(OrdersEntity::class)->findOne(['id' => $orderId]);

        return $order ?: null;
    }

    /**
     * Позиції, які можна оцінити: товар існує й видимий. Видалений чи
     * прихований товар не має сторінки, де відгук було б видно.
     *
     * @return object[] purchase з полем product
     */
    public function reviewablePurchases(int $orderId): array
    {
        $purchases = $this->entityFactory->get(PurchasesEntity::class)->find(['order_id' => $orderId]);
        $productIds = array_values(array_unique(array_filter(array_map(fn($p) => (int) $p->product_id, $purchases))));
        if ($productIds === []) {
            return [];
        }

        $products = [];
        foreach ($this->entityFactory->get(ProductsEntity::class)->find(['id' => $productIds, 'visible' => 1, 'limit' => count($productIds)]) as $product) {
            $products[(int) $product->id] = $product;
        }

        $imageIds = array_values(array_filter(array_map(fn($p) => (int) $p->main_image_id, $products)));
        if ($imageIds !== []) {
            $images = $this->entityFactory->get(ImagesEntity::class)->mappedBy('id')->find(['id' => $imageIds, 'limit' => count($imageIds)]);
            foreach ($products as $product) {
                $product->image = $images[(int) $product->main_image_id] ?? null;
            }
        }

        $reviewable = [];
        $seen = [];
        foreach ($purchases as $purchase) {
            $productId = (int) $purchase->product_id;
            // Той самий товар двома варіантами — один відгук: оцінюють товар, не варіант.
            if (!isset($products[$productId]) || isset($seen[$productId])) {
                continue;
            }
            $seen[$productId] = true;
            $purchase->product = $products[$productId];
            $reviewable[] = $purchase;
        }

        return $reviewable;
    }

    public function isOptedOut(?string $emailHash): bool
    {
        return $emailHash !== null
            && $this->entityFactory->get(ReviewRequestOptoutsEntity::class)->count(['email_hash' => $emailHash]) > 0;
    }

    public function lastSentToEmail(?string $emailHash, int $exceptRequestId): ?DateTimeImmutable
    {
        if ($emailHash === null) {
            return null;
        }

        $sentAt = $this->queryFactory->newSqlQuery()->setStatement(
            'SELECT MAX(sent_at) AS sent_at FROM __sviat__review_requests
             WHERE email_hash = :hash AND status = :sent AND id <> :id'
        )->bindValues([
            'hash' => $emailHash,
            'sent' => ReviewRequestsEntity::STATUS_SENT,
            'id' => $exceptRequestId,
        ])->result('sent_at');

        return $sentAt ? new DateTimeImmutable($sentAt) : null;
    }

    public function sentSince(DateTimeImmutable $since): int
    {
        return (int) $this->queryFactory->newSqlQuery()->setStatement(
            'SELECT COUNT(*) AS cnt FROM __sviat__review_requests WHERE status = :sent AND sent_at >= :since'
        )->bindValues([
            'sent' => ReviewRequestsEntity::STATUS_SENT,
            'since' => $since->format(self::DATETIME),
        ])->result('cnt');
    }

    public function close(int $requestId, string $status, ?string $reason): void
    {
        $this->entityFactory->get(ReviewRequestsEntity::class)->update($requestId, [
            'status' => $status,
            'skip_reason' => $reason,
        ]);
    }

    /**
     * Позиції записуються до відправки: посилання з листа мусить відкривати
     * рівно ці товари, навіть якщо замовлення потім змінять.
     *
     * @param object[] $purchases
     */
    public function storeItems(int $requestId, array $purchases): void
    {
        /** @var ReviewRequestItemsEntity $items */
        $items = $this->entityFactory->get(ReviewRequestItemsEntity::class);
        // Попередня спроба могла обірватися між вставкою позицій і markSent.
        // Запит ще не надіслано, тож відгуків на ці позиції бути не може.
        $items->deleteByRequest($requestId);
        foreach ($purchases as $purchase) {
            $items->add([
                'request_id' => $requestId,
                'purchase_id' => (int) $purchase->id,
                'product_id' => (int) $purchase->product_id,
            ]);
        }
    }

    /** Email у замовленні могли змінити після виявлення: відписка й частота — за поточним. */
    public function updateEmailHash(int $requestId, ?string $emailHash): void
    {
        $this->entityFactory->get(ReviewRequestsEntity::class)->update($requestId, ['email_hash' => $emailHash]);
    }

    public function markSent(int $requestId, string $tokenHash, string $unsubscribeHash, DateTimeImmutable $now): void
    {
        $ttl = max(1, $this->settings->int('token_ttl_days'));
        $this->entityFactory->get(ReviewRequestsEntity::class)->update($requestId, [
            'status' => ReviewRequestsEntity::STATUS_SENT,
            'skip_reason' => null,
            'sent_at' => $now->format(self::DATETIME),
            'token_hash' => $tokenHash,
            'unsubscribe_hash' => $unsubscribeHash,
            'token_expires_at' => $now->modify("+{$ttl} days")->format(self::DATETIME),
            'error_text' => null,
            'retry_at' => null,
        ]);
    }

    public function markFailed(object $request, string $error, DateTimeImmutable $now): void
    {
        $attempts = (int) $request->attempts + 1;
        $hours = self::RETRY_AFTER_HOURS[$attempts] ?? null;
        $this->entityFactory->get(ReviewRequestsEntity::class)->update((int) $request->id, [
            'status' => $attempts >= self::MAX_ATTEMPTS ? ReviewRequestsEntity::STATUS_ERROR : ReviewRequestsEntity::STATUS_PENDING,
            'attempts' => $attempts,
            'retry_at' => $hours === null ? null : $now->modify("+{$hours} hours")->format(self::DATETIME),
            'error_text' => mb_substr($error, 0, 1000),
            'sent_at' => null,
            'token_hash' => null,
            'unsubscribe_hash' => null,
            'token_expires_at' => null,
        ]);
        $this->entityFactory->get(ReviewRequestItemsEntity::class)->deleteByRequest((int) $request->id);
    }

    /** @param int[] $ids */
    private function intList(array $ids): string
    {
        return implode(',', array_map('intval', $ids));
    }
}
