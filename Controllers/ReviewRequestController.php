<?php

namespace Okay\Modules\Sviat\ReviewRequests\Controllers;

use DateTimeImmutable;
use Okay\Controllers\AbstractController;
use Okay\Core\FrontTranslations;
use Okay\Core\Router;
use Okay\Core\Security\AttemptLimiter;
use Okay\Core\Security\CustomerCsrfToken;
use Okay\Core\Security\FormToken;
use Okay\Entities\OrdersEntity;
use Okay\Modules\Sviat\ReviewRequests\Entities\ReviewRequestsEntity;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestPreferences;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestReviews;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestToken;

/**
 * Сторінка за посиланням із листа.
 *
 * Межі безпеки:
 * - GET нічого не створює й не «витрачає»: поштові сканери (Outlook Safe Links
 *   тощо) відкривають посилання раніше за покупця;
 * - адреса з токеном нічого не рендерить, лише кладе запит у сесію й робить
 *   303 на адресу без токена, зокрема й для відписки, невалідного токена й
 *   ліміту спроб: інакше токен з адресного рядка забрала б аналітика сторінки;
 * - доступ через сесію живе SESSION_TTL, а не весь строк токена;
 * - відгук створює лише POST із нашого origin, з CSRF-токеном і одноразовим
 *   токеном форми, і лише для позицій свого замовлення, кожну — один раз;
 * - невідомий і протермінований токен дають однакову відповідь;
 * - POST «List-Unsubscribe=One-Click» (RFC 8058) відписує без CSRF і сесії:
 *   його шле поштовий сервіс, а не браузер; доказ — сам токен, і дія лише одна —
 *   відмова від цих листів.
 */
class ReviewRequestController extends AbstractController
{
    public const SESSION_KEY = 'sviat__review_request_id';
    public const PREFERENCES_FLASH = 'sviat__review_requests__preferences_flash';
    public const UNSUBSCRIBE_KEY = 'sviat__review_request_unsubscribe_id';
    private const string INVALID_KEY = 'sviat__review_request_invalid';

    /** Скільки секунд відкрите посилання дає писати відгуки з цього браузера. */
    public const SESSION_TTL = 86400;
    public const FORM = 'sviat_review_request';

    private const int NAME_MAX = 100;
    private const int TEXT_MAX = 5000;

    public function open($token)
    {
        $request = $this->tokenRequest($token, 'token_hash', true);
        if ($request === null) {
            return;
        }

        if (empty($request->first_visit_at)) {
            $this->entityFactory->get(ReviewRequestsEntity::class)->update((int) $request->id, [
                'first_visit_at' => new DateTimeImmutable()->format('Y-m-d H:i:s'),
            ]);
        }

        // Новий id сесії: посилання з листа дає доступ до чужого замовлення, тож
        // заздалегідь підкинутий id сесії не має його отримати.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION[self::SESSION_KEY] = ['id' => (int) $request->id, 'opened_at' => time()];

        $this->redirectWithoutToken('Sviat_ReviewRequests_form');
    }

    public function form(ReviewRequestReviews $reviews, FrontTranslations $translations)
    {
        $this->privatePage();

        if (($code = $this->takeInvalid()) !== null) {
            return $this->invalidLink($code);
        }

        $request = $this->sessionRequest();
        if ($request === null) {
            return $this->invalidLink();
        }

        // findOne() повертає false, а замовлення могли видалити вже після листа.
        $order = $this->entityFactory->get(OrdersEntity::class)->findOne(['id' => (int) $request->order_id]) ?: null;
        $items = $order ? $reviews->items((int) $request->id) : [];

        if ($this->request->method('post') && $order) {
            $this->submit($reviews, $request, $order, $items);
            return;
        }

        $this->design->assign('rr_items', $items);
        $this->design->assign('rr_form_url', Router::generateUrl('Sviat_ReviewRequests_form', [], true));
        $this->design->assign('rr_order', $order);
        $this->design->assign('rr_csrf', CustomerCsrfToken::get());
        // Окремий токен на кожну форму: інакше друга вкладка зі старою сторінкою
        // приймала б уже витрачений токен сусідньої позиції за повтор.
        $formTokens = [];
        foreach ($items as $item) {
            $formTokens[$item->id] = FormToken::get(self::FORM);
        }
        $this->design->assign('rr_form_tokens', $formTokens);
        $this->design->assign('rr_default_name', $this->displayName($order));
        $this->design->assign('rr_flash', $this->takeFlash());
        $this->design->assign('meta_title', $translations->getTranslation('sviat__review_requests__page_title'));

        $this->response->setContent('review_request.tpl');
    }

    public function unsubscribe($token, ReviewRequestPreferences $preferences)
    {
        if ($this->request->method('post') && $this->request->post('List-Unsubscribe') === 'One-Click') {
            $this->oneClickUnsubscribe($token, $preferences);
            return;
        }

        // Відписка діє й після строку посилання: людина має право відмовитись
        // від листів будь-коли.
        $request = $this->tokenRequest($token, 'unsubscribe_hash', false, 'Sviat_ReviewRequests_unsubscribe_form');
        if ($request === null) {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION[self::UNSUBSCRIBE_KEY] = ['id' => (int) $request->id, 'opened_at' => time()];
        $this->redirectWithoutToken('Sviat_ReviewRequests_unsubscribe_form');
    }

    public function unsubscribeForm(ReviewRequestPreferences $preferences)
    {
        $this->privatePage();

        if (($code = $this->takeInvalid()) !== null) {
            return $this->invalidLink($code);
        }

        // Той самий строк, що й для форми: на спільному комп'ютері наступна
        // людина не має відписати попередню.
        $stored = $_SESSION[self::UNSUBSCRIBE_KEY] ?? null;
        $id = is_array($stored) && time() - (int) ($stored['opened_at'] ?? 0) <= self::SESSION_TTL ? (int) ($stored['id'] ?? 0) : 0;
        $request = $id > 0
            ? $this->entityFactory->get(ReviewRequestsEntity::class)->findOne(['id' => $id, 'status' => ReviewRequestsEntity::STATUS_SENT])
            : null;
        if (!$request || empty($request->email_hash)) {
            return $this->invalidLink();
        }

        $done = false;
        if ($this->request->method('post')) {
            $this->checkCsrf();
            $preferences->optOut((string) $request->email_hash, null);
            unset($_SESSION[self::UNSUBSCRIBE_KEY]);
            $done = true;
        }

        $this->design->assign('rr_unsubscribed', $done);
        $this->design->assign('rr_unsubscribe_form_url', Router::generateUrl('Sviat_ReviewRequests_unsubscribe_form', [], true));
        $this->design->assign('rr_csrf', CustomerCsrfToken::get());
        $this->response->setContent('review_request_unsubscribe.tpl');
    }

    /** Галочка в кабінеті: лише для того, хто увійшов, і з CSRF; назад — у кабінет. */
    public function preferences(ReviewRequestPreferences $preferences)
    {
        $this->privatePage();
        $back = Router::generateUrl('user', [], true);

        if (empty($this->user->id) || !$this->request->method('post')) {
            $this->response->redirectTo(empty($this->user->id) ? Router::generateUrl('login', [], true) : $back, 303);
            return;
        }

        $this->checkCsrf();
        $preferences->setFromCabinet($this->user, (bool) $this->request->post('subscribed'));
        $_SESSION[self::PREFERENCES_FLASH] = true;

        $this->response->redirectTo($back, 303);
    }

    private function oneClickUnsubscribe($token, ReviewRequestPreferences $preferences): void
    {
        $this->response->addHeader('Cache-Control: no-store, private');
        $request = $this->findSentRequest($token, 'unsubscribe_hash', false);
        if ($request === null && $this->tooManyFailures()) {
            $this->response->setStatusCode(429)->setContent('Too Many Requests', RESPONSE_TEXT);
            return;
        }
        if ($request === null || empty($request->email_hash)) {
            $this->registerFailure();
            $this->response->setStatusCode(404)->setContent('Not Found', RESPONSE_TEXT);
            return;
        }

        $preferences->optOut((string) $request->email_hash, null);
        $this->response->setContent('Unsubscribed', RESPONSE_TEXT);
    }

    /** @param object[] $items */
    private function submit(ReviewRequestReviews $reviews, object $request, object $order, array $items): void
    {
        $this->checkCsrf();

        $itemId = (int) $this->request->post('item_id', 'integer');
        $item = $items[$itemId] ?? null;
        $data = [
            'name' => trim($this->postString('name')),
            'text' => trim($this->postString('text')),
            'rating' => $this->rating(),
        ];

        $error = $this->validate($item, $data);
        if ($error === null && !$this->acceptFormToken([$itemId, $data])) {
            // Повтор тієї ж відправки (подвійний клік, F5): відгук уже є.
            $error = 'duplicate';
        }

        if ($error === null) {
            $commentId = $reviews->create(
                $item,
                $order,
                $data,
                (int) ($this->language->id ?? 0),
                (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                !empty($this->user->id) ? (int) $this->user->id : null
            );
            if ($commentId === null) {
                $this->releaseFormToken();
                $error = 'not_saved';
            }
        }

        // У сесію — не більше, ніж поле форми прийме назад.
        $data['name'] = mb_substr($data['name'], 0, self::NAME_MAX);
        $data['text'] = mb_substr($data['text'], 0, self::TEXT_MAX);
        $this->storeFlash($error === null ? ['saved' => $itemId] : ['error' => $error, 'item_id' => $itemId, 'data' => $data]);
        $this->response->redirectTo(Router::generateUrl('Sviat_ReviewRequests_form', [], true), 303);
    }

    private function validate(?object $item, array $data): ?string
    {
        if ($item === null) {
            return 'unknown_item';
        }
        if (!empty($item->comment_id) || !empty($item->reviewed_at)) {
            return 'already_reviewed';
        }
        if ($data['name'] === '' || mb_strlen($data['name']) > self::NAME_MAX) {
            return 'empty_name';
        }
        if ($data['rating'] === null) {
            return 'empty_rating';
        }
        if ($data['text'] === '' || mb_strlen($data['text']) > self::TEXT_MAX) {
            return 'empty_text';
        }

        return null;
    }

    /**
     * Поле форми як рядок; масив (name[]=…) — порожній рядок, а не «Array».
     * Керівні символи (NUL тощо) прибираються, переноси рядків і табуляція лишаються.
     */
    private function postString(string $name): string
    {
        $value = $this->request->post($name);

        return is_string($value) ? (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) : '';
    }

    private function rating(): ?int
    {
        $rating = (int) $this->request->post('rating', 'integer');

        return $rating >= 1 && $rating <= 5 ? $rating : null;
    }

    /**
     * Запит, відправлений і з токеном, хеш якого збігається. Порівняння за
     * хешем у базі: таблиця не зберігає самих токенів.
     *
     * @param string $hashField token_hash — посилання на форму, unsubscribe_hash —
     *        відписка; токени різні, тож посилання відписки (воно й у заголовку
     *        List-Unsubscribe) не відкриває форму відгуку
     */
    private function findSentRequest($token, string $hashField, bool $mustBeValid): ?object
    {
        // Entity мовчки ігнорує невідомий фільтр: хибна назва поля дала б будь-який надісланий запит.
        if (!in_array($hashField, ['token_hash', 'unsubscribe_hash'], true) || !ReviewRequestToken::isWellFormed($token)) {
            return null;
        }

        $request = $this->entityFactory->get(ReviewRequestsEntity::class)->findOne([
            $hashField => ReviewRequestToken::hash($token),
            'status' => ReviewRequestsEntity::STATUS_SENT,
        ]);
        if (!$request) {
            return null;
        }
        if ($mustBeValid && (empty($request->token_expires_at) || new DateTimeImmutable($request->token_expires_at) < new DateTimeImmutable())) {
            return null;
        }

        return $request;
    }

    /**
     * Запит за токеном з адреси. Якщо його немає, сторінку з помилкою покаже
     * адреса без токена, куди тут же йде 303.
     */
    private function tokenRequest($token, string $hashField, bool $mustBeValid, string $invalidRoute = 'Sviat_ReviewRequests_form'): ?object
    {
        // Спершу токен, потім ліміт: за проксі (Cloudflare тощо) багато покупців
        // мають одну REMOTE_ADDR, і чужі хибні спроби не мають блокувати дійсне
        // посилання. 256-бітний токен перебором не вгадати — ліміт лише гасить шум.
        $request = $this->findSentRequest($token, $hashField, $mustBeValid);
        if ($request === null && $this->tooManyFailures()) {
            $this->redirectWithoutToken($invalidRoute, 429);
            return null;
        }
        if ($request === null) {
            $this->registerFailure();
            $this->redirectWithoutToken($invalidRoute, 404);
        }

        return $request;
    }

    /**
     * Redirect ставить ядрову Referrer-Policy (strict-origin-when-cross-origin):
     * адреса з токеном іде як Referer лише на цей самий домен.
     */
    private function redirectWithoutToken(string $route, ?int $invalidCode = null): void
    {
        if ($invalidCode !== null) {
            $_SESSION[self::INVALID_KEY] = $invalidCode;
        }
        // redirectTo() не віддає заголовків, доданих через addHeader(): відповідь
        // на адресу з токеном не має осідати в кеші проксі чи браузера.
        header('Cache-Control: no-store, private');
        $this->response->redirectTo(Router::generateUrl($route, [], true), 303);
    }

    private function takeInvalid(): ?int
    {
        $code = $_SESSION[self::INVALID_KEY] ?? null;
        unset($_SESSION[self::INVALID_KEY]);

        return in_array($code, [404, 429], true) ? $code : null;
    }

    private function sessionRequest(): ?object
    {
        $stored = $_SESSION[self::SESSION_KEY] ?? null;
        $id = (int) ($stored['id'] ?? 0);
        if ($id < 1 || time() - (int) ($stored['opened_at'] ?? 0) > self::SESSION_TTL) {
            unset($_SESSION[self::SESSION_KEY]);
            return null;
        }

        $request = $this->entityFactory->get(ReviewRequestsEntity::class)->findOne(['id' => $id, 'status' => ReviewRequestsEntity::STATUS_SENT]);
        if (!$request || empty($request->token_expires_at) || new DateTimeImmutable($request->token_expires_at) < new DateTimeImmutable()) {
            unset($_SESSION[self::SESSION_KEY]);
            return null;
        }

        return $request;
    }

    /**
     * Сторінка особиста: не індексується, не кешується й не передає адресу на
     * чужі сайти. same-origin, а не no-referrer: з no-referrer браузер шле
     * «Origin: null», і requireSameOrigin() відхилив би власну форму.
     */
    private function privatePage(): void
    {
        $this->design->assign('noindex_nofollow', true);
        $this->response->addHeader('Referrer-Policy: same-origin');
        $this->response->addHeader('Cache-Control: no-store, private');
        $this->response->addHeader('X-Robots-Tag: noindex, nofollow');
    }

    private function invalidLink(int $code = 404)
    {
        $this->response->setStatusCode($code);
        $this->design->assign('rr_invalid', true);
        $this->response->setContent('review_request.tpl');
    }

    private function displayName(?object $order): string
    {
        if (!$order) {
            return '';
        }
        $first = trim((string) ($order->name ?? ''));
        $last = trim((string) ($order->last_name ?? ''));
        // «Ім'я П.»: повне прізвище покупця публічно не показуємо, змінити може він сам.
        return trim($first . ($last !== '' ? ' ' . mb_substr($last, 0, 1) . '.' : ''));
    }

    /** Як вхід у кабінет: форму видали ми (токен) і відправили з нашої сторінки (Origin). */
    private function checkCsrf(): void
    {
        $this->requireSameOrigin();
        $this->requireCustomerCsrf();
    }

    private function acceptFormToken(array $payload): bool
    {
        return FormToken::accept(self::FORM, $this->request->post('form_token'), $payload, FormToken::ACCIDENT_TTL);
    }

    private function releaseFormToken(): void
    {
        FormToken::release(self::FORM, $this->request->post('form_token'));
    }

    private function tooManyFailures(): bool
    {
        return $this->attemptLimiter()->tooManyAttempts($this->clientKey());
    }

    private function registerFailure(): void
    {
        $this->attemptLimiter()->registerFailure($this->clientKey());
    }

    private function attemptLimiter(): AttemptLimiter
    {
        return $this->serviceLocator->getService(AttemptLimiter::class);
    }

    /**
     * Ключ ліміту спроб. IPv6 — за /64: одна мережа має їх мільярди, і кожна
     * адреса інакше давала б нові 10 спроб і новий файл лічильника.
     */
    private function clientKey(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $packed = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? inet_pton($ip) : false;
        if ($packed !== false) {
            $ip = bin2hex(substr($packed, 0, 8)) . '::/64';
        }

        return 'review_request:' . $ip;
    }

    private function storeFlash(array $flash): void
    {
        $_SESSION['sviat__review_requests__flash'] = $flash;
    }

    private function takeFlash(): array
    {
        $flash = $_SESSION['sviat__review_requests__flash'] ?? [];
        unset($_SESSION['sviat__review_requests__flash']);

        return is_array($flash) ? $flash : [];
    }
}
