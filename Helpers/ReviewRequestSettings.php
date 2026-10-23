<?php

namespace Okay\Modules\Sviat\ReviewRequests\Helpers;

use Okay\Core\Settings;

/** Налаштування модуля; типові значення — запобіжники від потоку листів (README). */
class ReviewRequestSettings
{
    public const PREFIX = 'sviat__review_requests__';

    public const MODE_TEST = 'test';
    public const MODE_LIVE = 'live';

    public const DEFAULTS = [
        'mode' => self::MODE_TEST,
        // Ідентифікатори статусів у кожного магазину свої: поки адмін не обрав
        // статус, модуль нічого не виявляє й не надсилає.
        'statuses' => '',
        'delay_days' => 7,
        'window_from' => 10,
        'window_to' => 19,
        'daily_cap' => 40,
        'email_interval_days' => 60,
        'max_order_age_days' => 45,
        'token_ttl_days' => 60,
        'excluded' => '',
        'root_url' => '',
        'one_click' => 0,
    ];

    private Settings $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    public function get(string $name)
    {
        $value = $this->settings->get(self::PREFIX . $name);

        return $value === null || $value === '' ? (self::DEFAULTS[$name] ?? null) : $value;
    }

    public function set(string $name, $value): void
    {
        $this->settings->set(self::PREFIX . $name, $value);
    }

    /**
     * Відписка в один клік (RFC 8058, List-Unsubscribe-Post). Gmail і Yahoo
     * вимагають її від тих, хто шле понад 5000 листів на добу.
     */
    public function oneClickUnsubscribe(): bool
    {
        return (bool) (int) $this->get('one_click');
    }

    public function isLive(): bool
    {
        return $this->get('mode') === self::MODE_LIVE;
    }

    /** @return int[] */
    public function completedStatuses(): array
    {
        return array_values(array_filter(array_map('intval', explode(',', (string) $this->get('statuses')))));
    }

    public function int(string $name): int
    {
        return max(0, (int) $this->get($name));
    }

    /**
     * Корінь сайту для посилань у листі. З планувальника домен запиту невідомий
     * (Request::getDomain() у CLI порожній), тож без налаштування посилання
     * вийшли б «http:///…».
     */
    public function rootUrl(): string
    {
        $url = rtrim((string) $this->get('root_url'), '/');
        if ($url === '') {
            $url = rtrim((string) $this->settings->get('core_updater__root_url'), '/');
        }

        return preg_match('~^https?://[^/\s]+$~i', $url) ? $url : '';
    }

    /** @return string[] адреси й домени в нижньому регістрі */
    public function excluded(): array
    {
        $lines = preg_split('/[\s,;]+/', mb_strtolower((string) $this->get('excluded')));

        return array_values(array_filter(array_map('trim', $lines ?: [])));
    }
}
