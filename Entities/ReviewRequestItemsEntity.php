<?php

namespace Okay\Modules\Sviat\ReviewRequests\Entities;

use Okay\Core\Entity\Entity;

/**
 * Позиції замовлення, які покупець може оцінити за запитом. comment_id
 * заповнюється, коли відгук створено: кожну позицію оцінюють лише раз.
 */
class ReviewRequestItemsEntity extends Entity
{
    protected static $fields = [
        'id',
        'request_id',
        'purchase_id',
        'product_id',
        'comment_id',
        'reviewed_at',
    ];

    protected static $defaultOrderFields = ['id'];
    protected static $table = 'sviat__review_request_items';
    protected static $tableAlias = 'rri';

    public function deleteByRequest(int $requestId): void
    {
        $delete = $this->queryFactory->newDelete();
        $delete->from(self::getTable())
            ->where('request_id = :request_id')
            ->bindValue('request_id', $requestId)
            ->execute();
    }
}
