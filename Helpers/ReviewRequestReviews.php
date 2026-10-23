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
use Okay\Helpers\RatingHelper;
use Okay\Modules\Sviat\ReviewRequests\Entities\ReviewRequestItemsEntity;

/**
 * Відгук за запитом — звичайний рядок __comments: модерація, відповіді адміна,
 * список і рейтинг працюють як для будь-якого відгуку. Зв'язок «відгук ↔
 * позиція замовлення» лише не дає оцінити позицію двічі; позначку «Перевірена
 * покупка» дає кабінет (verifiedCommentIds()).
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
     * Відгуки від покупців: автор увійшов у кабінет, а серед його замовлень є
     * виконане з цим товаром, оформлене не пізніше за відгук. Те саме правило
     * для відгуку зі сторінки товару й за посиланням із листа: доказ — кабінет,
     * а не саме посилання. Гостьовий відгук і відповідь у гілці позначки не мають.
     *
     * @param int[] $commentIds
     * @param int[] $completedStatuses
     * @return array<int, true>
     */
    public function verifiedCommentIds(array $commentIds, array $completedStatuses): array
    {
        $commentIds = array_values(array_filter(array_map('intval', $commentIds)));
        $completedStatuses = array_values(array_filter(array_map('intval', $completedStatuses)));
        if ($commentIds === [] || $completedStatuses === []) {
            return [];
        }

        $ids = $this->queryFactory->newSqlQuery()->setStatement(
            'SELECT DISTINCT c.id
             FROM __comments c
             JOIN __orders o ON o.user_id = c.user_id AND o.date <= c.date
             JOIN __purchases p ON p.order_id = o.id AND p.product_id = c.object_id
             WHERE c.id IN (' . implode(',', $commentIds) . ')
               AND c.type = :type
               AND c.parent_id = 0
               AND c.user_id > 0
               AND o.status_id IN (' . implode(',', $completedStatuses) . ')'
        )->bindValue('type', 'product')->results('id');

        return array_fill_keys(array_map('intval', $ids), true);
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
