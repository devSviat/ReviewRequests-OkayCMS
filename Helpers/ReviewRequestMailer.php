<?php

namespace Okay\Modules\Sviat\ReviewRequests\Helpers;

use Okay\Core\Config;
use Okay\Core\Design;
use Okay\Core\EntityFactory;
use Okay\Core\FrontTranslations;
use Okay\Core\Image;
use Okay\Core\Languages;
use Okay\Core\Modules\Extender\ExtenderFacade;
use Okay\Core\Notify;
use Okay\Core\Router;
use Okay\Core\Settings;
use Okay\Entities\ProductsEntity;

/**
 * Лист із проханням оцінити товари. Відправляється з планувальника, тож усе,
 * що в вебі дає контекст запиту, тут задається явно: мова, корінь сайту,
 * абсолютні посилання. Smarty-плагіни ядра в CLI не зареєстровані, тому шаблон
 * листа їх не використовує — посилання й картинки рахуються тут.
 */
class ReviewRequestMailer
{
    public const TEMPLATE = 'Okay/Modules/Sviat/ReviewRequests/design/html/email_review_request.tpl';

    private Notify $notify;
    private Design $design;
    private Settings $settings;
    private Languages $languages;
    private FrontTranslations $frontTranslations;
    private Config $config;
    private Image $image;
    private ReviewRequestSettings $moduleSettings;
    private EntityFactory $entityFactory;
    private string $rootDir;

    /** Заголовок List-Unsubscribe для листа, що зараз відправляється (див. FrontExtender::addUnsubscribeHeader). */
    private static ?string $pendingUnsubscribeUrl = null;

    public function __construct(
        Notify $notify,
        Design $design,
        Settings $settings,
        Languages $languages,
        FrontTranslations $frontTranslations,
        Config $config,
        Image $image,
        ReviewRequestSettings $moduleSettings,
        EntityFactory $entityFactory,
        $rootDir
    ) {
        $this->notify = $notify;
        $this->design = $design;
        $this->settings = $settings;
        $this->languages = $languages;
        $this->frontTranslations = $frontTranslations;
        $this->config = $config;
        $this->image = $image;
        $this->moduleSettings = $moduleSettings;
        $this->entityFactory = $entityFactory;
        $this->rootDir = rtrim((string) $rootDir, '/') . '/';
    }

    public static function pendingUnsubscribeUrl(): ?string
    {
        return self::$pendingUnsubscribeUrl;
    }

    /**
     * @param object[] $purchases позиції з полем product
     * @return array{0: bool, 1: string} [надіслано, текст помилки]
     */
    public function send(object $order, array $purchases, string $token, string $unsubscribeToken, string $to): array
    {
        $rootUrl = $this->moduleSettings->rootUrl();
        if ($rootUrl === '') {
            return [false, 'root_url не налаштовано: посилання в листі були б неробочими'];
        }

        $previousLangId = $this->languages->getLangId();
        $this->switchLanguage($this->languageFor($order));

        $addedServerKeys = $this->fillCliServer($rootUrl);
        try {
            $html = $this->render($order, $purchases, $token, $unsubscribeToken, $rootUrl);
            $subject = (string) $this->design->getVar('subject');
        } finally {
            $this->switchLanguage($previousLangId);
            foreach ($addedServerKeys as $key) {
                unset($_SERVER[$key]);
            }
        }

        if ($subject === '' || trim(strip_tags($html)) === '') {
            return [false, 'лист вийшов порожнім'];
        }

        return $this->deliver($to, $subject, $html, $this->unsubscribeUrl($rootUrl, $unsubscribeToken));
    }

    /**
     * Лист для перевірки в адмінці: справжній шаблон, але токен вигаданий і
     * в черзі нічого не змінюється.
     *
     * @param object[] $purchases
     * @return array{0: bool, 1: string}
     */
    public function sendPreview(object $order, array $purchases, string $to): array
    {
        // Лист іде на адресу, яку вказав менеджер, тож даних покупця в ньому
        // не лишаємо: лише товари.
        $anonymous = clone $order;
        $anonymous->name = '';
        $anonymous->last_name = '';
        $anonymous->email = '';

        return $this->send($anonymous, $purchases, ReviewRequestToken::generate(), ReviewRequestToken::generate(), $to);
    }

    /** @param object[] $purchases */
    public function render(object $order, array $purchases, string $token, string $unsubscribeToken, string $rootUrl): string
    {
        $this->localizeProductNames($purchases);
        foreach ($purchases as $purchase) {
            $product = $purchase->product;
            $product->absolute_url = $rootUrl . Router::generateUrl('product', ['url' => $product->url]);
            $product->image_url = $this->imageUrl($product, $rootUrl);
        }

        $this->design->assign('rr_order', $order);
        $this->design->assign('rr_purchases', $purchases);
        $this->design->assign('rr_review_url', $rootUrl . Router::generateUrl('Sviat_ReviewRequests_open', ['token' => $token]));
        $this->design->assign('rr_unsubscribe_url', $this->unsubscribeUrl($rootUrl, $unsubscribeToken));
        $this->design->assign('rr_root_url', $rootUrl);
        $this->design->assign('rr_logo_url', $rootUrl . '/' . $this->config->get('design_images') . $this->settings->get('site_logo')
            . '?v=' . $this->settings->get('site_logo_version'));
        $this->design->assign('rr_root_dir', $this->rootDir);
        $this->design->assign('rr_first_name', $this->firstName($order));
        $this->design->assign('settings', $this->settings);
        $this->design->assign('lang', $this->frontTranslations);

        $html = $this->design->fetch($this->rootDir . self::TEMPLATE);

        return ExtenderFacade::execute(__METHOD__, $html, func_get_args());
    }

    /**
     * Notify::email() нічого не повертає, тож через SMTP шлемо самі й беремо
     * результат Notify::SMTP(); без SMTP (mail()) успіх відомий лише зі слів PHP.
     *
     * @return array{0: bool, 1: string}
     */
    private function deliver(string $to, string $subject, string $html, string $unsubscribeUrl): array
    {
        $from = $this->settings->get('notify_from_name')
            ? $this->settings->get('notify_from_name') . ' <' . $this->settings->get('notify_from_email') . '>'
            : (string) $this->settings->get('notify_from_email');

        self::$pendingUnsubscribeUrl = $unsubscribeUrl;
        try {
            if ($this->settings->get('use_smtp')) {
                $result = $this->notify->SMTP($to, '=?utf-8?B?' . base64_encode($subject) . '?=', $html, $from);
                return $result === true ? [true, ''] : [false, 'SMTP не прийняв лист (деталі — в Okay/log)'];
            }

            $this->notify->email($to, $subject, $html, $from);
            return [true, ''];
        } finally {
            self::$pendingUnsubscribeUrl = null;
        }
    }

    /**
     * Маршрути ядра при першому зверненні читають протокол, порт і URI з
     * $_SERVER, а в CLI цих ключів немає: кожен прогін планувальника з листом
     * писав би warning'и. Підставляємо лише відсутні ключі, взяті з root_url.
     *
     * @return string[] додані ключі, щоб прибрати їх після рендеру
     */
    private function fillCliServer(string $rootUrl): array
    {
        if (PHP_SAPI !== 'cli') {
            return [];
        }

        $https = str_starts_with(strtolower($rootUrl), 'https://');
        $defaults = [
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'SERVER_PORT' => (string) (parse_url($rootUrl, PHP_URL_PORT) ?: ($https ? 443 : 80)),
            'HTTPS' => $https ? 'on' : 'off',
            'HTTP_HOST' => (string) parse_url($rootUrl, PHP_URL_HOST),
            'REQUEST_URI' => '/',
            'QUERY_STRING' => '',
        ];

        $added = [];
        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $_SERVER)) {
                $_SERVER[$key] = $value;
                $added[] = $key;
            }
        }

        return $added;
    }

    private function unsubscribeUrl(string $rootUrl, string $token): string
    {
        return $rootUrl . Router::generateUrl('Sviat_ReviewRequests_unsubscribe', ['token' => $token]);
    }

    /**
     * Лист завжди українською: мова замовлення лише
     * визначає, яку з наявних мов сайту взяти, якщо української серед них немає.
     */
    private function languageFor(object $order): int
    {
        foreach ($this->languages->getAllLanguages() as $language) {
            // Вимкнена мова дала б посилання з префіксом, якого сайт не відкриває.
            if ($language->label === 'ua' && !empty($language->enabled)) {
                return (int) $language->id;
            }
        }

        return (int) ($order->lang_id ?: $this->languages->getMainLanguage()->id);
    }

    private function switchLanguage($langId): void
    {
        if (empty($langId) || (int) $langId === (int) $this->languages->getLangId()) {
            return;
        }
        $this->languages->setLangId((int) $langId);
        $this->frontTranslations->init();
        $this->settings->initSettings();
    }

    /**
     * Назва в позиції замовлення збережена мовою, якою його оформили, а лист іде
     * мовою листа: беремо назву товару в поточній мові.
     *
     * @param object[] $purchases
     */
    private function localizeProductNames(array $purchases): void
    {
        $ids = array_values(array_unique(array_map(fn($p) => (int) $p->product->id, $purchases)));
        if ($ids === []) {
            return;
        }

        $names = [];
        foreach ($this->entityFactory->get(ProductsEntity::class)->find(['id' => $ids, 'limit' => count($ids)]) as $product) {
            $names[(int) $product->id] = (string) $product->name;
        }
        foreach ($purchases as $purchase) {
            $purchase->product->name = ($names[(int) $purchase->product->id] ?? '') !== ''
                ? $names[(int) $purchase->product->id]
                : (string) $purchase->product_name;
        }
    }

    private function imageUrl(object $product, string $rootUrl): ?string
    {
        if (empty($product->image->filename) || preg_match('~^https?://~', (string) $product->image->filename)) {
            return null;
        }

        // Те саме, що Image::getResizeModifier(), але без Request::getRootUrl():
        // той у CLI читає $_SERVER['SERVER_PROTOCOL'] і сипле warning'ами на кожен лист.
        $this->image->addImagesSize('120x120', 'product');
        $resized = $this->image->addResizeParams($product->image->filename, 120, 120);

        return $rootUrl . '/' . trim((string) $this->config->get('resized_images_dir'), '/') . '/' . rawurlencode($resized);
    }

    /**
     * Звертаємось на ім'я, без прізвища: лист бачить лише покупець, але зайвого не треба.
     * Лише слово з літер: «www.shop-evil.com» у полі імені поштовий клієнт
     * перетворив би на посилання всередині листа від магазину.
     */
    private function firstName(object $order): string
    {
        $first = (string) (preg_split('/\s+/u', trim((string) ($order->name ?? '')))[0] ?? '');

        return preg_match("/^[\p{L}][\p{L}'’-]{0,39}$/u", $first) ? $first : '';
    }
}
