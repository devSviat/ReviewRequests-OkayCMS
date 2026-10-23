<?php

namespace Okay\Modules\Sviat\ReviewRequests\Backend\Controllers;

use DateTimeImmutable;
use Okay\Admin\Controllers\IndexAdmin;
use Okay\Core\EntityFactory;
use Okay\Core\Managers;
use Okay\Core\QueryFactory;
use Psr\Log\LoggerInterface;
use Okay\Core\Security\AttemptLimiter;
use Okay\Entities\OrderStatusEntity;
use Okay\Modules\Sviat\ReviewRequests\Entities\ReviewRequestsEntity;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestBackfill;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestMailer;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestPreferences;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestQueue;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestRules;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestSettings;

class ReviewRequestsAdmin extends IndexAdmin
{
    /** Межі числових налаштувань: поза ними значення не зберігається. */
    private const LIMITS = [
        'delay_days' => [0, 90],
        'window_from' => [0, 23],
        'window_to' => [0, 24],
        'daily_cap' => [1, 500],
        'email_interval_days' => [0, 365],
        'max_order_age_days' => [1, 365],
        'token_ttl_days' => [1, 180],
    ];

    private const LOG_SIZE = 50;

    public function fetch(
        ReviewRequestSettings $moduleSettings,
        ReviewRequestQueue $queue,
        ReviewRequestMailer $mailer,
        ReviewRequestBackfill $backfill,
        EntityFactory $entityFactory,
        QueryFactory $queryFactory,
        ReviewRequestPreferences $preferences,
        AttemptLimiter $attemptLimiter,
        LoggerInterface $logger
    ) {
        if ($this->request->method('post')) {
            switch ($this->request->post('action')) {
                case 'save':
                    $rootUrlError = $this->saveSettings($moduleSettings, $queue);
                    $rootUrlError === null ? $this->postRedirectGet->storeMessageSuccess('saved')
                        : $this->postRedirectGet->storeMessageError($rootUrlError);
                    $this->postRedirectGet->redirect();
                    break;
                case 'send_test':
                    [$ok, $error] = $this->sendTest($moduleSettings, $queue, $mailer, $attemptLimiter);
                    $ok ? $this->postRedirectGet->storeMessageSuccess('test_sent')
                        : $this->postRedirectGet->storeMessageError($error);
                    $this->postRedirectGet->redirect();
                    break;
                case 'optout_remove':
                    $email = ReviewRequestRules::normalizeEmail($this->request->post('optout_email'));
                    if ($email !== null && $preferences->removeOptOut($email)) {
                        // Відмову скасовує людина, не покупець: слід у лозі, хто й кого повернув.
                        $logger->info('ReviewRequests: менеджер ' . (string) ($this->manager->login ?? '?')
                            . ' повернув до листів адресу ' . self::maskEmail($email));
                        $this->postRedirectGet->storeMessageSuccess('optout_removed');
                    } else {
                        $this->postRedirectGet->storeMessageError('optout_not_found');
                    }
                    $this->postRedirectGet->redirect();
                    break;
                case 'backfill_preview':
                    $this->design->assign('backfill_preview', $backfill->preview(...$this->backfillPeriod()));
                    $this->design->assign('backfill_period', $this->backfillPeriod());
                    break;
                case 'backfill_confirm':
                    $_SESSION['sviat__review_requests__backfill_count'] = $backfill->enqueue(...$this->backfillPeriod());
                    $this->postRedirectGet->storeMessageSuccess('backfill_done');
                    $this->postRedirectGet->redirect();
                    break;
            }
        }

        if ($this->design->getVar('backfill_period') === null) {
            $this->design->assign('backfill_period', $this->backfillPeriod());
        }
        $this->design->assign('rr_backfill_count', (int) ($_SESSION['sviat__review_requests__backfill_count'] ?? 0));
        unset($_SESSION['sviat__review_requests__backfill_count']);
        $this->design->assign('rr', $this->settingsView($moduleSettings));
        $this->design->assign('rr_statuses', $entityFactory->get(OrderStatusEntity::class)->find());
        $this->design->assign('rr_stats', $this->stats($queryFactory));
        $logStatus = $this->logStatusFilter();
        $this->design->assign('rr_log_status', $logStatus);
        $this->design->assign('rr_log', $this->log($queryFactory, $logStatus));
        $this->design->assign('rr_sent_today', $queue->sentSince((new DateTimeImmutable())->setTime(0, 0)));
        $this->design->assign('rr_server_time', (new DateTimeImmutable())->format('d.m.Y H:i'));
        $this->design->assign('rr_in_window', (new ReviewRequestRules($moduleSettings))->inSendWindow(new DateTimeImmutable()));

        $this->response->setContent($this->design->fetch('review_requests.tpl'));
    }

    /** @return string|null код помилки адреси сайту; решта налаштувань зберігається й тоді */
    private function saveSettings(ReviewRequestSettings $settings, ReviewRequestQueue $queue): ?string
    {
        $settings->set('mode', $this->request->post('mode') === ReviewRequestSettings::MODE_LIVE
            ? ReviewRequestSettings::MODE_LIVE
            : ReviewRequestSettings::MODE_TEST);

        // Лише скаляри: intval(['4']) дає 1, і statuses[][]=4 зберіг би статус 1.
        $posted = array_filter((array) $this->request->post('statuses'), 'is_scalar');
        $statuses = array_values(array_unique(array_filter(array_map('intval', $posted))));
        sort($statuses);
        $current = $settings->completedStatuses();
        sort($current);
        if ($statuses !== $current) {
            // Замовлення, що вже стоять у доданому статусі, — історія, а не нові
            // виконані: вони йдуть у baseline. Спершу добираємо нове під старими
            // статусами, щоб baseline не забрав справжні нові замовлення.
            // Baseline нових статусів — до збереження: інакше прогін планувальника
            // між збереженням і baseline() прийняв би їхню історію за нові.
            $now = new DateTimeImmutable();
            if ($queue->hasBaseline()) {
                $queue->detect($now);
            }
            $queue->baseline($now, $statuses);
            $settings->set('statuses', implode(',', $statuses));
        }

        foreach (self::LIMITS as $name => [$min, $max]) {
            $value = $this->request->post($name);
            if ($value !== null && $value !== '' && is_numeric($value) && (int) $value >= $min && (int) $value <= $max) {
                $settings->set($name, (int) $value);
            }
        }

        $settings->set('one_click', $this->request->post('one_click') ? 1 : 0);

        $settings->set('excluded', mb_substr(trim($this->postString('excluded')), 0, 5000));

        $rootUrl = rtrim(trim($this->postString('root_url')), '/');
        if ($rootUrl === '') {
            $settings->set('root_url', '');
            return null;
        }
        if (!ReviewRequestSettings::isBaseUrl($rootUrl)) {
            return 'root_url_foreign';
        }
        if (!$this->mayUseRootUrl((string) parse_url($rootUrl, PHP_URL_HOST))) {
            return 'root_url_foreign';
        }
        $settings->set('root_url', $rootUrl);

        return null;
    }

    /**
     * Адреса в листах — лише домен самого магазину: інакше менеджер із правом
     * лише на цей модуль відправив би покупців із дійсними токенами на чужий
     * сайт. Домен беремо з налаштувань оновлення ядра. Коли їх немає, довіряти
     * нічому з запиту не можна (Host підробляється), тож адресу задає лише той,
     * хто має право на налаштування сайту.
     */
    private function mayUseRootUrl(string $host): bool
    {
        $coreRoot = (string) $this->settings->get('core_updater__root_url');
        if ($coreRoot === '') {
            return !empty($this->manager) && $this->managers->access('settings', $this->manager);
        }

        return $host !== '' && strtolower($host) === strtolower((string) parse_url($coreRoot, PHP_URL_HOST));
    }

    /**
     * Тестовий лист на вказану адресу з даними останнього виконаного замовлення.
     * Черга не змінюється, токен у листі вигаданий.
     *
     * @return array{0: bool, 1: string}
     */
    private function sendTest(ReviewRequestSettings $settings, ReviewRequestQueue $queue, ReviewRequestMailer $mailer, AttemptLimiter $limiter): array
    {
        $to = ReviewRequestRules::normalizeEmail($this->request->post('test_email'));
        if ($to === null) {
            return [false, 'test_bad_email'];
        }

        // Тестовий лист іде на будь-яку адресу з назвами товарів реального
        // замовлення: не більше 10 за 5 хвилин з одного акаунта менеджера.
        $key = 'review_request_test:' . (string) ($this->manager->login ?? '');
        if ($limiter->tooManyAttempts($key)) {
            return [false, 'test_too_many'];
        }
        $limiter->registerFailure($key);

        foreach ($queue->latestCompletedOrders(20) as $order) {
            $purchases = $queue->reviewablePurchases((int) $order->id);
            if ($purchases !== []) {
                [$ok, $error] = $mailer->sendPreview($order, $purchases, $to);
                return $ok ? [true, ''] : [false, 'test_failed'];
            }
        }

        return [false, 'test_no_orders'];
    }

    /** Поле форми як рядок; масив (field[]=…) — порожній рядок, а не «Array». */
    private function postString(string $name): string
    {
        $value = $this->request->post($name);

        return is_string($value) ? $value : '';
    }

    /** @return array{0: string, 1: string} */
    private function backfillPeriod(): array
    {
        $from = $this->postString('date_from');
        $to = $this->postString('date_to');
        // Лише справжня дата: «2026-13-45» пройшла б regex і поклала б сторінку на new DateTimeImmutable().
        $valid = function (string $d): bool {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
            return $date !== false && $date->format('Y-m-d') === $d;
        };

        return [$valid($from) ? $from : date('Y-m-d', strtotime('-14 days')), $valid($to) ? $to : date('Y-m-d')];
    }

    private function settingsView(ReviewRequestSettings $settings): array
    {
        $view = [];
        foreach (array_keys(ReviewRequestSettings::DEFAULTS) as $name) {
            $view[$name] = $settings->get($name);
        }
        $view['statuses'] = $settings->completedStatuses();
        $view['statuses_map'] = array_fill_keys($view['statuses'], true);
        $view['effective_root_url'] = $settings->rootUrl();
        $view['is_live'] = $settings->isLive();
        $view['one_click'] = $settings->oneClickUnsubscribe();
        $view['window_empty'] = $settings->int('window_from') === $settings->int('window_to');

        return $view;
    }

    private function stats(QueryFactory $queryFactory): array
    {
        $byStatus = [];
        foreach ($queryFactory->newSqlQuery()->setStatement(
            'SELECT status, COUNT(*) AS cnt FROM __sviat__review_requests GROUP BY status'
        )->results() as $row) {
            $byStatus[$row->status] = (int) $row->cnt;
        }

        $since = (new DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s');
        $recent = $queryFactory->newSqlQuery()->setStatement(
            'SELECT
                SUM(rr.sent_at >= :since) AS sent,
                SUM(rr.sent_at >= :since AND rr.first_visit_at IS NOT NULL) AS visited
             FROM __sviat__review_requests rr WHERE rr.status = :sent'
        )->bindValues(['since' => $since, 'sent' => ReviewRequestsEntity::STATUS_SENT])->results();

        $reviews = $queryFactory->newSqlQuery()->setStatement(
            'SELECT COUNT(*) AS total, SUM(reviewed_at >= :since) AS recent
             FROM __sviat__review_request_items WHERE comment_id IS NOT NULL'
        )->bindValues(['since' => $since])->results();

        $sent = (int) ($recent[0]->sent ?? 0);
        $visited = (int) ($recent[0]->visited ?? 0);
        $reviewed = (int) ($reviews[0]->recent ?? 0);

        return [
            'by_status' => $byStatus,
            'sent_30' => $sent,
            'visited_30' => $visited,
            'reviews_30' => $reviewed,
            'reviews_total' => (int) ($reviews[0]->total ?? 0),
            'baseline_formatted' => number_format((int) ($byStatus[ReviewRequestsEntity::STATUS_BASELINE] ?? 0), 0, '', ' '),
            'open_pct' => $sent > 0 ? (int) round($visited * 100 / $sent) : 0,
            'review_pct' => $visited > 0 ? (int) round($reviewed * 100 / $visited) : 0,
        ];
    }

    /** Фільтр журналу з адреси: лише відомий стан, baseline у журнал не потрапляє. */
    private function logStatusFilter(): ?string
    {
        $status = $this->request->get('log_status');

        return is_string($status) && $status !== ReviewRequestsEntity::STATUS_BASELINE
            && in_array($status, ReviewRequestsEntity::STATUSES, true) ? $status : null;
    }

    private function log(QueryFactory $queryFactory, ?string $status): array
    {
        $rows = $queryFactory->newSqlQuery()->setStatement(
            'SELECT rr.*, o.email, o.date AS order_date,
                (SELECT COUNT(*) FROM __sviat__review_request_items i WHERE i.request_id = rr.id AND i.comment_id IS NOT NULL) AS reviews
             FROM __sviat__review_requests rr
             LEFT JOIN __orders o ON o.id = rr.order_id
             WHERE rr.status IN (:statuses)
             ORDER BY rr.id DESC
             LIMIT ' . self::LOG_SIZE
        )->bindValues([
            // IN за індексом status, а не «<> baseline»: інакше запит іде всією
            // таблицею, де майже все — baseline.
            'statuses' => $status !== null ? [$status] : array_values(array_diff(ReviewRequestsEntity::STATUSES, [ReviewRequestsEntity::STATUS_BASELINE])),
        ])->results();

        return self::journalRows($rows, self::showsFullEmails($this->managers, !empty($this->manager) ? $this->manager : null));
    }

    /**
     * Рядки журналу для шаблону: адреса — повна або маска, а сирої адреси,
     * хешів і токенів у рядку не лишається.
     *
     * @param object[] $rows
     * @return object[]
     */
    public static function journalRows(array $rows, bool $fullEmails): array
    {
        foreach ($rows as $row) {
            $row->email_shown = $fullEmails ? (string) $row->email : self::maskEmail((string) $row->email);
            unset($row->email, $row->token_hash, $row->unsubscribe_hash, $row->email_hash);
        }

        return $rows;
    }

    /**
     * Повна адреса — лише тому, хто й так бачить її в замовленнях. Право модуля
     * без права на замовлення не повинно відкривати адреси всіх покупців.
     */
    public static function showsFullEmails(Managers $managers, ?object $manager): bool
    {
        return $manager !== null && $managers->access('orders', $manager);
    }

    /** Адреса для лога й для менеджера без права на замовлення. */
    public static function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) {
            return $email === '' ? '' : '***';
        }
        [$local, $domain] = explode('@', $email, 2);
        // Коротку адресу відкриваємо на один символ, а не на два: «ab@» інакше лишилась би цілою.
        $visible = mb_strlen($local) > 3 ? 2 : 1;

        return mb_substr($local, 0, $visible) . str_repeat('*', max(1, mb_strlen($local) - $visible)) . '@' . $domain;
    }
}
