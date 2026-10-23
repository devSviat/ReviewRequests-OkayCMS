<?php

namespace Okay\Modules\Sviat\ReviewRequests\Entities;

use Okay\Core\Entity\Entity;

/**
 * Запит на відгук — один на замовлення. Статуси: baseline (виконане ще до
 * встановлення модуля, листа не буде ніколи), pending, skipped, sent, error,
 * cancelled.
 */
class ReviewRequestsEntity extends Entity
{
    public const STATUS_BASELINE = 'baseline';
    public const STATUS_PENDING = 'pending';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_SENT = 'sent';
    public const STATUS_ERROR = 'error';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_BASELINE,
        self::STATUS_PENDING,
        self::STATUS_SKIPPED,
        self::STATUS_SENT,
        self::STATUS_ERROR,
        self::STATUS_CANCELLED,
    ];

    protected static $fields = [
        'id',
        'order_id',
        'status',
        'skip_reason',
        'email_hash',
        'lang_id',
        'detected_at',
        'sent_at',
        'token_hash',
        'unsubscribe_hash',
        'token_expires_at',
        'first_visit_at',
        'attempts',
        'retry_at',
        'error_text',
    ];

    protected static $defaultOrderFields = ['id DESC'];
    protected static $table = 'sviat__review_requests';
    protected static $tableAlias = 'rr';
}
