<?php

namespace Okay\Modules\Sviat\ReviewRequests\Helpers;

use Okay\Core\Database;
use Okay\Core\EntityFactory;
use Okay\Core\Modules\Extender\ExtenderFacade;
use Okay\Core\Notify;
use Okay\Core\QueryFactory;
use Okay\Core\ServiceLocator;
use Okay\Core\Settings;
use Okay\Entities\CommentsEntity;
use Okay\Entities\ProductsEntity;
use Okay\Helpers\CommentsHelper;
use Okay\Helpers\RatingHelper;
use Okay\Modules\Sviat\ReviewRequests\Entities\ReviewRequestItemsEntity;

/**
 * Відгук за запитом — звичайний рядок __comments: модерація, відповіді адміна,
 * список і рейтинг працюють як для будь-якого відгуку. Зв'язок «відгук ↔
 * позиція замовлення» не дає оцінити позицію двічі й дає позначку «Перевірена
 * покупка» (verifiedCommentIds()).
 */
class ReviewRequestReviews
{
    private EntityFactory $entityFactory;
    private QueryFactory $queryFactory;
    private Settings $settings;

    public function __construct(EntityFactory $entityFactory, QueryFactory $queryFactory, Settings $settings)
    {
        $this->entityFactory = $entityFactory;
        $this->queryFactory = $queryFactory;
        $this->settings = $settings;
    }

    /**
     * Відгуки від покупців — за одним із трьох доказів, і в кожному замовлення
     * досі у виконаному статусі:
     * - кабінет: автор увійшов, серед його замовлень є це з товаром, оформлене не
     *   пізніше за відгук;
     * - посилання з листа: відгук залишено за позицією надісланого запиту — посилання
     *   одноразове й прив'язане до цієї покупки;
     * - разова дія адміна для старих відгуків (ReviewRequestVerifiedBackfill).
     * Відповідь у гілці позначки не має.
     *
     * @param int[] $commentIds
     * @param int[] $completedStatuses
     * @return array<int, true>
     */
    public function verifiedCommentIds(array $commentIds, array $completedStatuses): array
    {
        $commentIds = array_values(array_filter(array_map(intval(...), $commentIds)));
        $completedStatuses = array_values(array_filter(array_map(intval(...), $completedStatuses)));
        if ($commentIds === [] || $completedStatuses === []) {
            return [];
        }

        $in = implode(',', $commentIds);
        $statuses = implode(',', $completedStatuses);
        $sql = 'SELECT c.id
             FROM __comments c
             JOIN __orders o ON o.user_id = c.user_id AND o.date <= c.date
             JOIN __purchases p ON p.order_id = o.id AND p.product_id = c.object_id
             WHERE c.id IN (' . $in . ')
               AND c.type = :type_cabinet
               AND c.parent_id = 0
               AND c.user_id > 0
               AND o.status_id IN (' . $statuses . ')
             UNION
             SELECT c.id
             FROM __comments c
             JOIN __sviat__review_request_items rri ON rri.comment_id = c.id AND rri.product_id = c.object_id
             JOIN __sviat__review_requests rr ON rr.id = rri.request_id
             JOIN __orders o ON o.id = rr.order_id
             WHERE c.id IN (' . $in . ')
               AND c.type = :type_link
               AND c.parent_id = 0
               AND o.status_id IN (' . $statuses . ')';
        // Таблиця разової дії з'являється з оновленням модуля, а до натискання вона
        // порожня: без цієї умови кожна сторінка відгуків до оновлення давала б SQL-помилку.
        if ((string) $this->settings->get(ReviewRequestSettings::PREFIX . 'verified_backfill_at') !== '') {
            $sql .= '
             UNION
             SELECT c.id
             FROM __comments c
             JOIN __sviat__review_request_verified rrv ON rrv.comment_id = c.id
             JOIN __orders o ON o.id = rrv.order_id
             WHERE c.id IN (' . $in . ')
               AND c.parent_id = 0
               AND o.status_id IN (' . $statuses . ')';
        }

        $ids = $this->queryFactory->newSqlQuery()->setStatement($sql)
            ->bindValues(['type_cabinet' => 'product', 'type_link' => 'product'])->results('id');

        return array_fill_keys(array_map(intval(...), $ids), true);
    }

    /** @return object[] позиції запиту з полем product, ключ — id позиції */
    public function items(int $requestId): array
    {
        $items = $this->entityFactory->get(ReviewRequestItemsEntity::class)->mappedBy('id')->find(['request_id' => $requestId]);
        if ($items === []) {
            return [];
        }

        $productIds = array_values(array_unique(array_map(fn($i) => (int) $i->product_id, $items)));
        $products = $this->entityFactory->get(ProductsEntity::class)->mappedBy('id')
            ->find(['id' => $productIds, 'limit' => count($productIds)]);

        foreach ($items as $id => $item) {
            $item->product = $products[(int) $item->product_id] ?? null;
            // Товар зник чи приховали після листа — оцінити його вже нема де.
            if ($item->product === null || empty($item->product->visible)) {
                unset($items[$id]);
            }
        }

        return $items;
    }

    /**
     * Створює відгук для позиції. Позицію спершу «займає» атомарний UPDATE:
     * дві паралельні відправки форми не дадуть двох відгуків на одну покупку.
     *
     * @param array{name: string, text: string, rating: int} $data
     * @return int|null id відгуку або null, якщо позицію вже оцінено чи запис не вдався
     */
    public function create(object $item, object $order, array $data, int $langId, string $ip, ?int $userId): ?int
    {
        if (!$this->claim((int) $item->id)) {
            return null;
        }

        $comment = [
            'parent_id' => 0,
            'object_id' => (int) $item->product_id,
            'type' => 'product',
            'name' => $data['name'],
            'email' => (string) ($order->email ?? ''),
            'text' => $data['text'],
            'ip' => $ip,
            'lang_id' => $langId,
            // Лише той, хто зараз у кабінеті власника замовлення: посилання з листа
            // доводить доступ до пошти, а не до кабінету.
            'user_id' => $userId !== null && $userId > 0 && $userId === (int) ($order->user_id ?? 0) ? $userId : null,
            // Відгук за запитом модерацію не обходить: правило магазину одне для всіх відгуків.
            'approved' => $this->settings->get('auto_approved') ? 1 : 0,
        ];
        $comment['rating'] = $data['rating'];

        /** @var CommentsEntity $comments */
        $comments = $this->entityFactory->get(CommentsEntity::class);
        $commentId = (int) $comments->add($comment);
        // Database ковтає помилку INSERT, а insertId() тоді віддає id попереднього
        // запису з'єднання: прив'язали б позицію до чужого відгуку.
        $saved = $commentId > 0 ? $comments->findOne(['id' => $commentId]) : null;
        if (!is_object($saved) || (int) $saved->object_id !== (int) $item->product_id || (string) $saved->type !== 'product'
            || (string) $saved->email !== $comment['email']) {
            $this->release((int) $item->id);
            return null;
        }

        $this->entityFactory->get(ReviewRequestItemsEntity::class)->update((int) $item->id, ['comment_id' => $commentId]);

        if ($comment['approved']) {
            ServiceLocator::getInstance()->getService(RatingHelper::class)
                ->recalculateFromReviews($this->entityFactory->get(ProductsEntity::class), (int) $item->product_id, 'product');
        } else {
            $commentsHelper = ServiceLocator::getInstance()->getService(CommentsHelper::class);
            // Ядро без цього методу (стоковий 4.5.3) показує несхвалений відгук за IP автора.
            if (method_exists($commentsHelper, 'rememberOwnComment')) {
                $commentsHelper->rememberOwnComment($commentId);
            }
        }
        try {
            ServiceLocator::getInstance()->getService(Notify::class)->emailCommentAdmin($commentId);
        } catch (\Throwable $e) {
            // Відгук уже збережено: збій листа адміну не має дати покупцю помилку.
            ServiceLocator::getInstance()->getService(\Psr\Log\LoggerInterface::class)
                ->warning('ReviewRequests: лист адміну про відгук ' . $commentId . ' не надіслано: ' . $e->getMessage());
        }

        return ExtenderFacade::execute(__METHOD__, $commentId, func_get_args());
    }

    private function claim(int $itemId): bool
    {
        $update = $this->queryFactory->newUpdate()
            ->table(ReviewRequestItemsEntity::getTable())
            ->set('reviewed_at', 'NOW()')
            ->where('id = :id AND reviewed_at IS NULL')
            ->bindValue('id', $itemId);

        $db = ServiceLocator::getInstance()->getService(Database::class);
        $db->query($update);

        return (int) $db->affectedRows() === 1;
    }

    private function release(int $itemId): void
    {
        $this->entityFactory->get(ReviewRequestItemsEntity::class)->update($itemId, ['reviewed_at' => null]);
    }
}
