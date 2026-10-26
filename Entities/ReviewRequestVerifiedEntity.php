<?php

namespace Okay\Modules\Sviat\ReviewRequests\Entities;

use Okay\Core\Entity\Entity;

/**
 * Старі відгуки, які разова дія в адмінці визнала відгуками покупців за збігом
 * email (ReviewRequestVerifiedBackfill). order_id — замовлення, що це підтвердило:
 * без нього в «виконаному» статусі позначка знімається, як і для кабінету.
 */
class ReviewRequestVerifiedEntity extends Entity
{
    protected static $fields = [
        'id',
        'comment_id',
        'order_id',
        'manager_id',
        'created_at',
    ];

    protected static $defaultOrderFields = ['id'];
    protected static $table = 'sviat__review_request_verified';
    protected static $tableAlias = 'rrv';
}
