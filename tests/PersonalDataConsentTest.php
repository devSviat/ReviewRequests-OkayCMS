<?php

namespace Modules\Sviat\ReviewRequests;

use Modules\Sviat\PersonalData\Support\BuildsConsentLedger;
use Okay\Core\Design;
use Okay\Core\EntityFactory;
use Okay\Core\FrontTranslations;
use Okay\Core\Languages;
use Okay\Core\Modules\Modules;
use Okay\Core\QueryFactory;
use Okay\Core\Security\FormToken;
use Okay\Core\Settings;
use Okay\Entities\CommentsEntity;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentLedger;
use Okay\Modules\Sviat\PersonalData\Consent\ConsentRecorder;
use Okay\Modules\Sviat\PersonalData\Consent\ErrorText;
use Okay\Modules\Sviat\PersonalData\Consent\RenderProof;
use Okay\Modules\Sviat\PersonalData\Forms\FormSettings;
use Okay\Modules\Sviat\PersonalData\Integration\Consent;
use Okay\Modules\Sviat\PersonalData\Plugins\ConfirmPersonalDataProcessingPlugin;
use Okay\Modules\Sviat\ReviewRequests\Controllers\ReviewRequestController;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestReviews;
use Okay\Modules\Sviat\ReviewRequests\Integration\PersonalDataConsent;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Smarty\Smarty;
use Modules\Sviat\PersonalData\Support\ModuleTestCase;
use Modules\Sviat\PersonalData\Support\RequestStub;
use PHPUnit\Framework\TestCase;

// Помічники тестів беруться з тестів PersonalData: без цього модуля перевіряти зв'язку нічим.
// Класи оголошено в гілках if, тож PHP оголошує лише один із них і лише під час виконання.
if (is_file(__DIR__ . '/../PersonalData/Support/BuildsConsentLedger.php')) {
    require_once __DIR__ . '/../PersonalData/Support/ModuleTestCase.php';
    require_once __DIR__ . '/../PersonalData/Support/BuildsConsentLedger.php';

    /**
     * Відгук за запрошенням питає згоду на обробку даних через фасад PersonalData, лише коли той модуль
     * встановлений і ввімкнений. Форма звичайна (POST, редірект, флеш), тож відмова повертає введене через флеш.
     */
    #[AllowMockObjectsWithoutExpectations]
    class PersonalDataConsentTest extends ModuleTestCase
    {
        use BuildsConsentLedger;

        private const string MODULE = __DIR__ . '/../../../../Okay/Modules/Sviat/ReviewRequests';

        private const string PERSONAL_DATA = __DIR__ . '/../../../../Okay/Modules/Sviat/PersonalData';

        private const string TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

        private const array REVIEW = [
            'item_id' => '12',
            'name' => 'Ірина Т.',
            'text' => 'Плата підійшла, працює.',
            'rating' => '4',
            'form_token' => self::TOKEN,
        ];

        private const array CONFIRMED = ['sviat__personal_data__confirm' => '1'];

        private const array TYPED = ['name' => 'Ірина Т.', 'text' => 'Плата підійшла, працює.', 'rating' => 4];

        protected function setUp(): void
        {
            $this->resetLedgerState(['sviat__personal_data__form_review_request' => 1]);
            $this->records(CommentsEntity::class, [1, 2]);
            $this->shown('review_request');
            $_SESSION['lang_id'] = 1;
        }

        protected function tearDown(): void
        {
            $this->cleanLedgerState();
            parent::tearDown();
        }

        public function testRefusalKeepsItemIdAndTypedData(): void
        {
            $noName = ['name' => ''] + self::REVIEW;
            $flash = $this->submit($noName, $this->consent($noName));
            self::assertSame('empty_name', $flash['error'] ?? null, 'власна перевірка полів іде першою');
            self::assertFalse($this->requestState->isRefused('review_request'), 'до згоди не дійшло');

            $flash = $this->submit(self::REVIEW, $this->consent(self::REVIEW));

            self::assertSame(['error' => 'personal_data', 'item_id' => 12, 'data' => self::TYPED], $flash);
            self::assertTrue($this->requestState->isRefused('review_request'));
            self::assertSame([1, 2], $this->commentIds(), 'без згоди відгук не створюється');
            self::assertSame([], $this->consentRows());
        }

        public function testFormTokenNotSpentOnRefusal(): void
        {
            $this->submit(self::REVIEW, $this->consent(self::REVIEW));
            self::assertArrayNotHasKey(self::TOKEN, $_SESSION[FormToken::SESSION_KEY][ReviewRequestController::FORM] ?? [], 'відмова не витрачає токен форми');

            $confirmed = self::CONFIRMED + self::REVIEW;
            $flash = $this->submit($confirmed, $this->consent($confirmed));
            self::assertSame(['saved' => 12], $flash, 'та сама форма з відміткою проходить');
            self::assertSame([1, 2, 3], $this->commentIds());

            // Контроль: успішна відправка токен витрачає, і повтор тієї ж форми — дубль.
            $flash = $this->submit($confirmed, $this->consent($confirmed));
            self::assertSame('duplicate', $flash['error'] ?? null);
            self::assertSame([1, 2, 3], $this->commentIds());
        }

        public function testLinkOnlyWhenCommentCreated(): void
        {
            $confirmed = self::CONFIRMED + self::REVIEW;

            $flash = $this->submit($confirmed, $this->consent($confirmed), failingCreate: true);
            self::assertSame('not_saved', $flash['error'] ?? null);
            self::assertSame([], $this->consentRows(), 'без id відгуку згода ні до чого не прив\'язується');
            self::assertArrayNotHasKey(self::TOKEN, $_SESSION[FormToken::SESSION_KEY][ReviewRequestController::FORM] ?? []);

            $flash = $this->submit($confirmed, $this->consent($confirmed));
            self::assertSame(['saved' => 12], $flash);
            self::assertSame([3], array_map(static fn(object $row) => $row->record_id, $this->consentRows()), 'згода прив\'язана до створеного відгуку');
            self::assertSame(['review_request'], array_values(array_unique(array_map(static fn(object $row) => $row->form, $this->consentRows()))));
        }

        /** Модуль PersonalData прибрали з диска, а рядок у списку модулів лишився ввімкненим. */
        public function testSafeDefaults(): void
        {
            $vendorDir = dirname(new \ReflectionClass(\Composer\Autoload\ClassLoader::class)->getFileName(), 2);
            $script = '$loader = require ' . var_export($vendorDir . '/autoload.php', true) . ';'
                . '$loader->unregister();'
                . 'spl_autoload_register(static function (string $class) use ($loader): void {'
                . '    if (!str_starts_with($class, "Okay\\\\Modules\\\\Sviat\\\\PersonalData\\\\")) { $loader->loadClass($class); }'
                . '});'
                . '$modules = new class extends \Okay\Core\Modules\Modules {'
                . '    public function __construct() {}'
                . '    public function isActiveModule($vendor, $moduleName) { return true; }'
                . '};'
                . '$consent = new \Okay\Modules\Sviat\ReviewRequests\Integration\PersonalDataConsent($modules);'
                . 'echo var_export(class_exists(\Okay\Modules\Sviat\PersonalData\Integration\Consent::class), true), "|";'
                . 'echo var_export($consent->check(), true), "|";'
                . '$consent->link(5);'
                . 'echo "done";';

            exec(escapeshellarg(PHP_BINARY) . ' -d opcache.enable_cli=0 -r ' . escapeshellarg($script) . ' 2>&1', $output, $exitCode);

            self::assertSame(0, $exitCode, implode("\n", $output));
            self::assertSame('false|NULL|done', implode("\n", $output));

            // Встановлений, але вимкнений модуль: сервісів у контейнері немає, тож фасад не береться взагалі.
            $disabled = new PersonalDataConsent($this->modules(false), static fn(): never => throw new \LogicException('фасад вимкненого модуля не будується'));
            self::assertNull($disabled->check());
            $disabled->link(3);

            self::assertSame(['saved' => 12], $this->submit(self::REVIEW, $disabled), 'без PersonalData відгук іде як раніше, без відмітки');
            self::assertSame([1, 2, 3], $this->commentIds());
            self::assertSame([], $this->consentRows());
        }

        public function testEachItemFormHasOwnCheckboxId(): void
        {
            $forms = $this->forms($this->renderPage([]));

            self::assertSame([11, 12], array_keys($forms));
            foreach ($forms as $itemId => $form) {
                $checkboxes = $this->xpath($form)->query('.//input[@name="sviat__personal_data__confirm"]', $form);
                self::assertSame(1, $checkboxes->length, "форма позиції $itemId має свій чекбокс");
                self::assertSame("sviat-pd-review_request-$itemId", $checkboxes->item(0)->getAttribute('id'));

                $order = $this->xpath($form)->query('.//input[@name="sviat__personal_data__confirm"] | .//input[@type="submit"]', $form);
                self::assertSame('checkbox', $order->item(0)->getAttribute('type'), 'чекбокс стоїть перед кнопкою');
            }
        }

        /** Після відмови маркер стоїть лише у формі тієї позиції, і JS шукає чекбокс за id цієї ж позиції. */
        public function testRefusalFlashMarksOwnCheckboxInvalid(): void
        {
            $forms = $this->forms($this->renderPage(['error' => 'personal_data', 'item_id' => 12, 'data' => self::TYPED]));

            $markers = [];
            foreach ($forms as $itemId => $form) {
                foreach ($this->xpath($form)->query('.//*[@data-rr-consent-refused]', $form) as $marker) {
                    $markers[$itemId] = $marker->getAttribute('data-rr-consent-refused');
                    self::assertSame(
                        1,
                        $this->xpath($form)->query('.//input[@id="sviat-pd-review_request-' . $markers[$itemId] . '"]', $form)->length,
                        'чекбокс, на який вказує маркер, стоїть у тій самій формі',
                    );
                    self::assertSame('Потрібна ваша згода', trim($marker->textContent));
                }
            }
            self::assertSame([12 => '12'], $markers);

            $form = $forms[12];
            self::assertSame(self::TYPED['name'], $this->xpath($form)->query('.//input[@name="name"]', $form)->item(0)->getAttribute('value'));
            self::assertSame(self::TYPED['text'], $this->xpath($form)->query('.//textarea[@name="text"]', $form)->item(0)->textContent);
            self::assertSame('4', $this->xpath($form)->query('.//input[@name="rating"][@checked]', $form)->item(0)->getAttribute('value'));

            $js = (string) file_get_contents(self::MODULE . '/design/js/review_request.js');
            self::assertStringContainsString("'[data-rr-consent-refused]'", $js);
            self::assertStringContainsString("'#sviat-pd-review_request-' + marker.getAttribute('data-rr-consent-refused')", $js);
            self::assertStringContainsString("closest('form')", $js, 'чекбокс шукається лише у формі з маркером');
            self::assertStringContainsString("getAttribute('aria-describedby')", $js);
            self::assertStringContainsString("setAttribute('aria-invalid', 'true')", $js);
            self::assertStringContainsString('.focus()', $js);
            self::assertStringContainsString('textContent', $js);
            self::assertStringNotContainsString('innerHTML', $js);

            $assets = require self::MODULE . '/design/js.php';
            self::assertSame(['review_request.js'], array_map(static fn($js) => $js->getFilename(), $assets));
        }

        /**
         * Обробка відправки без CSRF і редіректу: їх робить submit(), а редірект завершується exit.
         *
         * @param array<string, string> $post
         * @return array<string, mixed> флеш, який піде в сесію
         */
        private function submit(array $post, PersonalDataConsent $consent, bool $failingCreate = false): array
        {
            $controller = new \ReflectionClass(ReviewRequestController::class)->newInstanceWithoutConstructor();
            $this->writePrivate($controller, 'request', new RequestStub($post));
            $controller->language = (object) ['id' => 1];

            $order = (object) ['id' => 7, 'email' => '', 'user_id' => 0];

            return $this->callPrivate($controller, 'process', $this->reviews($failingCreate), $order, $this->items(), $consent);
        }

        /** @return array<int, object> */
        private function items(): array
        {
            $items = [];
            foreach ([11 => 'Плата керування', 12 => 'Датчик температури'] as $id => $name) {
                $items[$id] = (object) ['id' => $id, 'product_id' => $id + 100, 'comment_id' => null, 'reviewed_at' => null, 'product' => (object) ['name' => $name]];
            }

            return $items;
        }

        /** Відгуки пишуться в ту саму таблицю коментарів, яку бачить PersonalData. */
        private function reviews(bool $failingCreate): ReviewRequestReviews
        {
            $table = $this->records[CommentsEntity::class];
            $create = fn(int $id, array $fields) => $this->recordFields(CommentsEntity::class, $id, $fields);

            return new class ($table, $create, $failingCreate, $this->createStub(EntityFactory::class), $this->createStub(QueryFactory::class), $this->createStub(Settings::class)) extends ReviewRequestReviews {
                public function __construct(private readonly object $store, private readonly \Closure $create, private readonly bool $failing, EntityFactory $entityFactory, QueryFactory $queryFactory, Settings $settings)
                {
                    parent::__construct($entityFactory, $queryFactory, $settings);
                }

                public function create(object $item, object $order, array $data, int $langId, string $ip, ?int $userId): ?int
                {
                    if ($this->failing) {
                        return null;
                    }
                    $id = max([0, ...array_keys($this->store->records)]) + 1;
                    ($this->create)($id, ['type' => 'product', 'object_id' => $item->product_id] + $data);

                    return $id;
                }
            };
        }

        /** @return list<int> */
        private function commentIds(): array
        {
            return array_keys($this->records[CommentsEntity::class]->records);
        }

        /** @param array<string, string> $post */
        private function consent(array $post): PersonalDataConsent
        {
            $ledger = $this->ledger();

            return new PersonalDataConsent($this->modules(true), fn(): Consent => $this->facade($ledger, $post));
        }

        /** @param array<string, string> $post */
        private function facade(ConsentLedger $ledger, array $post): Consent
        {
            return new Consent(
                $this->schemaState($this->settings),
                $this->registry(),
                $this->consentGate($this->settings, $post, sink: $ledger, modulesActive: true),
                new ConsentRecorder($ledger, $this->registry(), $this->entityFactory(), $this->logger(), new RequestStub($post)),
                $this->logger(),
            );
        }

        private function modules(bool $personalDataActive): Modules
        {
            $modules = $this->createStub(Modules::class);
            $modules->method('isActiveModule')->willReturnCallback(static fn(string $vendor, string $name): bool => $vendor === 'Sviat' && $name === 'PersonalData' && $personalDataActive);

            return $modules;
        }

        /**
         * Сторінка з двома позиціями; блок дизайну малює справжній плагін PersonalData, як get_design_block:
         * vars стають змінними дизайну на час рендеру блока.
         *
         * @param array<string, mixed> $flash
         */
        private function renderPage(array $flash): string
        {
            $_SESSION = array_diff_key($_SESSION, ['sviat__personal_data' => true]);
            $lang = [];
            foreach ([self::MODULE, self::PERSONAL_DATA] as $module) {
                $lang += (static function (string $file): array {
                    $lang = [];
                    require $file;

                    return $lang;
                })($module . '/design/lang/ua.php');
            }

            $languages = $this->createStub(Languages::class);
            $languages->method('getLangId')->willReturn(1);
            $languages->method('getMainLanguage')->willReturn((object) ['id' => 1, 'label' => 'ua']);
            $languages->method('getLangLabel')->willReturn('ua');
            $translations = $this->createStub(FrontTranslations::class);
            $translations->method('getTranslation')->willReturnCallback(static fn(string $key) => $lang[$key] ?? null);

            $checkbox = $this->smarty(self::PERSONAL_DATA . '/design/html', 'checkbox');
            $vars = [];
            $design = $this->createStub(Design::class);
            $design->method('assign')->willReturnCallback(function ($name, $value) use (&$vars) {
                $vars[$name] = $value;

                return null;
            });
            $design->method('getVar')->willReturnCallback(static fn($name) => $vars[$name] ?? null);
            $design->method('fetch')->willReturnCallback(static function (string $template) use ($checkbox, &$vars) {
                $checkbox->clearAllAssign();
                $checkbox->assign($vars);

                return $checkbox->fetch($template);
            });

            $plugin = new ConfirmPersonalDataProcessingPlugin(
                $design,
                $this->schemaState($this->settings),
                $this->registry(),
                new FormSettings($this->settings),
                $languages,
                $translations,
                new RenderProof(),
                $this->requestState,
                new ErrorText($translations),
            );

            $block = $this->smarty(self::PERSONAL_DATA . '/design/html', 'block');
            $block->registerPlugin(Smarty::PLUGIN_FUNCTION, 'confirm_personal_data_processing', [$plugin, 'run']);

            $page = $this->smarty(self::MODULE . '/design/html', 'page');
            $page->registerPlugin(Smarty::PLUGIN_FUNCTION, 'get_design_block', static function (array $params) use ($block): string {
                if (($params['block'] ?? '') !== 'sviat_personal_data_review_request') {
                    return '';
                }
                $block->clearAllAssign();
                $block->assign($params['vars'] ?? []);

                return $block->fetch('consent_review_request.tpl');
            });
            $page->assign([
                'lang' => (object) $lang,
                'rr_items' => $this->items(),
                'rr_form_url' => 'https://shop.example/review',
                'rr_csrf' => 'csrf',
                'rr_form_tokens' => [11 => 'token-11', 12 => 'token-12'],
                'rr_default_name' => 'Ірина Т.',
                'rr_flash' => $flash,
                'rr_invalid' => false,
            ]);

            return $page->fetch('review_request.tpl');
        }

        private function smarty(string $templateDir, string $compileDir): Smarty
        {
            $smarty = new Smarty();
            $smarty->setTemplateDir($templateDir);
            $smarty->setCompileDir($this->consentRoot . '/compiled-' . $compileDir);
            // Як Design на вітрині: флеш без ключа saved чи error — звичайний стан сторінки, не попередження.
            $smarty->setErrorReporting(E_ALL & ~E_NOTICE & ~E_WARNING);
            $smarty->muteUndefinedOrNullWarnings();

            return $smarty;
        }

        /** @return array<int, \DOMElement> ключ — item_id форми */
        private function forms(string $html): array
        {
            $document = new \DOMDocument();
            libxml_use_internal_errors(true);
            $document->loadHTML('<?xml encoding="utf-8"?>' . $html);
            libxml_clear_errors();

            $forms = [];
            foreach (new \DOMXPath($document)->query('//form') as $form) {
                $itemId = new \DOMXPath($document)->query('.//input[@name="item_id"]', $form)->item(0)?->getAttribute('value');
                $forms[(int) $itemId] = $form;
            }

            return $forms;
        }

        private function xpath(\DOMElement $element): \DOMXPath
        {
            return new \DOMXPath($element->ownerDocument);
        }
    }

} else {
    class PersonalDataConsentTest extends TestCase
    {
        public function testNeedsPersonalData(): void
        {
            self::markTestSkipped('модуля Sviat/PersonalData з тестами немає: зв\'язку зі згодою перевіряти нічим');
        }
    }
}
