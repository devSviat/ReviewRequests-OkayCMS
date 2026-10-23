<?php

namespace Okay\Modules\Sviat\ReviewRequests\Entities;

use Okay\Core\Entity\Entity;

/**
 * Адреси, які відмовились від таких листів. Зберігається лише SHA-256 адреси.
 * user_id — акаунт, з кабінету якого відмовились; NULL — відмова з листа.
 */
class ReviewRequestOptoutsEntity extends Entity
{
    protected static $fields = [
        'id',
        'email_hash',
        'user_id',
        'created_at',
    ];

    protected static $defaultOrderFields = ['id DESC'];
    protected static $table = 'sviat__review_request_optouts';
    protected static $tableAlias = 'rro';

    public function deleteByHashAndUser(string $emailHash, int $userId): void
    {
        $this->queryFactory->newDelete()
            ->from(self::getTable())
            ->where('email_hash = :hash AND user_id = :user_id')
            ->bindValues(['hash' => $emailHash, 'user_id' => $userId])
            ->execute();
    }
}
