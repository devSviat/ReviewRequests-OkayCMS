<?php

namespace Okay\Modules\Sviat\ReviewRequests\Helpers;

use DateTimeImmutable;

/**
 * Рішення «писати чи ні» без звернень до бази: усе, що треба, приходить
 * аргументами. На цьому тримається захист від потоку листів, тож логіку
 * перевіряють тести без жодної інфраструктури.
 */
class ReviewRequestRules
{
    public const WAIT = 'wait';
    public const SEND = 'send';
    public const SKIP = 'skip';
    public const CANCEL = 'cancel';

    private ReviewRequestSettings $settings;

    public function __construct(ReviewRequestSettings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Нижній регістр і перевірка формату; null — адреси немає або вона невалідна.
     * Кома й лапки відкидаються, хоч FILTER_VALIDATE_EMAIL їх і пропускає в
     * «"a,b@c"@d»: Notify::SMTP() ділить адресата комою, і такий лист пішов би
     * ще й на «b@c» в обхід стоп-листа.
     */
    public static function normalizeEmail($email): ?string
    {
        if (!is_string($email)) {
            return null;
        }
        $email = mb_strtolower(trim($email));

        return $email !== '' && strpbrk($email, ',"') === false && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /** Стоп-лист і частота на адресу зберігають хеш, а не саму адресу. */
    public static function emailHash(string $normalizedEmail): string
    {
        return hash('sha256', $normalizedEmail);
    }

    public function isExcluded(string $normalizedEmail): bool
    {
        $domain = substr((string) strrchr($normalizedEmail, '@'), 1);
        foreach ($this->settings->excluded() as $entry) {
            $entry = ltrim($entry, '@');
            if ($entry === $normalizedEmail || $entry === $domain) {
                return true;
            }
        }

        return false;
    }

    /**
     * Що записати для щойно виявленого виконаного замовлення.
     *
     * @return array{0: string, 1: ?string} [статус запиту, причина пропуску]
     */
    public function onDetect(object $order, DateTimeImmutable $now): array
    {
        if (self::normalizeEmail($order->email ?? null) === null) {
            return ['skipped', empty($order->email) ? 'no_email' : 'invalid_email'];
        }
        if ($this->isTooOld($order, $now)) {
            return ['skipped', 'too_old'];
        }

        return ['pending', null];
    }

    /**
     * Рішення в момент відправки. Порядок важливий: спершу те, що назавжди
     * прибирає запит із черги, потім те, що лише відкладає його.
     *
     * @param object|null $order замовлення зараз (null — видалене)
     * @param array{
     *     detected_at: DateTimeImmutable,
     *     reviewable_items: int,
     *     opted_out: bool,
     *     last_sent_to_email: ?DateTimeImmutable,
     *     sent_today: int,
     *     retry_at?: ?DateTimeImmutable
     * } $context
     * @return array{0: string, 1: ?string} [рішення, причина]
     */
    public function onSend(?object $order, array $context, DateTimeImmutable $now): array
    {
        if ($order === null) {
            return [self::CANCEL, 'order_deleted'];
        }
        if (!in_array((int) $order->status_id, $this->settings->completedStatuses(), true)) {
            return [self::CANCEL, 'not_completed'];
        }

        $email = self::normalizeEmail($order->email ?? null);
        if ($email === null) {
            return [self::SKIP, empty($order->email) ? 'no_email' : 'invalid_email'];
        }
        if ($context['opted_out']) {
            return [self::SKIP, 'opted_out'];
        }
        if ($this->isExcluded($email)) {
            return [self::SKIP, 'excluded'];
        }
        if ($this->isTooOld($order, $now)) {
            return [self::SKIP, 'too_old'];
        }
        if ($context['reviewable_items'] < 1) {
            return [self::SKIP, 'no_products'];
        }

        $interval = $this->settings->int('email_interval_days');
        if ($context['last_sent_to_email'] !== null && $interval > 0
            && $context['last_sent_to_email'] > $now->modify("-{$interval} days")) {
            return [self::SKIP, 'recently_asked'];
        }

        if (($context['retry_at'] ?? null) !== null && $context['retry_at'] > $now) {
            return [self::WAIT, 'retry'];
        }

        $delay = $this->settings->int('delay_days');
        if ($context['detected_at']->modify("+{$delay} days") > $now) {
            return [self::WAIT, 'delay'];
        }
        if (!$this->inSendWindow($now)) {
            return [self::WAIT, 'window'];
        }
        if ($context['sent_today'] >= $this->settings->int('daily_cap')) {
            return [self::WAIT, 'daily_cap'];
        }

        return [self::SEND, null];
    }

    public function inSendWindow(DateTimeImmutable $now): bool
    {
        $hour = (int) $now->format('G');
        $from = $this->settings->int('window_from');
        $to = $this->settings->int('window_to');

        return $from <= $to ? ($hour >= $from && $hour < $to) : ($hour >= $from || $hour < $to);
    }

    private function isTooOld(object $order, DateTimeImmutable $now): bool
    {
        $maxAge = $this->settings->int('max_order_age_days');
        $orderDate = new DateTimeImmutable((string) $order->date);

        return $maxAge > 0 && $orderDate < $now->modify("-{$maxAge} days");
    }
}
