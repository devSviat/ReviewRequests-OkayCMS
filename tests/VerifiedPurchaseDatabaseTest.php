<?php

namespace Modules\Sviat\ReviewRequests;

use Aura\Sql\ExtendedPdo;
use DateTimeImmutable;
use Okay\Core\Config;
use Okay\Core\EntityFactory;
use Okay\Core\QueryFactory;
use Okay\Core\ServiceLocator;
use Okay\Core\Settings;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestReviews;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestSettings;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestVerifiedBackfill;
use PHPUnit\Framework\TestCase;

/**
 * «Перевірена покупка» на живій MariaDB: три докази (кабінет, посилання з листа,
 * разова дія для старих відгуків) і межі разової дії. Усе в транзакції, що
 * відкочується; без бази чи таблиць модуля 1.0.6 тест пропускається.
 */
class VerifiedPurchaseDatabaseTest extends TestCase
{
    private const COMPLETED = 4;
    private const OTHER_STATUS = 2;
    private const PRODUCT = 990_000_001;
    private const OTHER_PRODUCT = 990_000_002;

    private ExtendedPdo $pdo;
    private string $prefix;
    private bool $inTransaction = false;
    /** @var array<string, mixed> */
    private array $stored = [];
    private ReviewRequestReviews $reviews;
    private ReviewRequestVerifiedBackfill $backfill;

    protected function setUp(): void
    {
        try {
            $this->pdo = ServiceLocator::getInstance()->getService(ExtendedPdo::class);
            $this->prefix = (string) ServiceLocator::getInstance()->getService(Config::class)->get('db_prefix');
            $this->pdo->query("SELECT 1 FROM {$this->prefix}sviat__review_request_verified LIMIT 1");
        } catch (\PDOException $e) {
            $this->markTestSkipped('немає бази або таблиць ReviewRequests 1.0.6: ' . $e->getMessage());
        }
        $this->assertMatchesRegularExpression('/^[a-z0-9_]*$/i', $this->prefix);
        $this->assertSame(0, (int) $this->pdo->fetchValue(
            "SELECT COUNT(*) FROM {$this->prefix}purchases WHERE product_id IN (" . self::PRODUCT . ',' . self::OTHER_PRODUCT . ')'
        ), 'id товарів фікстури зайняті справжніми покупками');

        $this->pdo->beginTransaction();
        $this->inTransaction = true;

        // Налаштування — у пам'яті: Settings ядра кешує значення в процесі, і запис
        // разової дії пережив би відкат транзакції.
        $this->stored = [ReviewRequestSettings::PREFIX . 'statuses' => (string) self::COMPLETED];
        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnCallback(fn($name) => $this->stored[$name] ?? null);
        $settings->method('set')->willReturnCallback(function ($name, $value) {
            $this->stored[$name] = $value;
        });

        $locator = ServiceLocator::getInstance();
        $queryFactory = $locator->getService(QueryFactory::class);
        $this->reviews = new ReviewRequestReviews($locator->getService(EntityFactory::class), $queryFactory, $settings);
        $this->backfill = new ReviewRequestVerifiedBackfill(
            $queryFactory,
            new ReviewRequestSettings($settings),
            $this->reviews,
            $locator->getService(Config::class)
        );
    }

    protected function tearDown(): void
    {
        if ($this->inTransaction) {
            $this->pdo->rollBack();
        }
    }

    public function testCabinetBuyerIsStillVerified(): void
    {
        $order = $this->order('cab@example.com', self::COMPLETED, '2026-01-01 10:00:00', 777001);
        $this->purchase($order, self::PRODUCT);
        $own = $this->comment('cab@example.com', '2026-01-05 10:00:00', 777001);
        $guest = $this->comment('cab@example.com', '2026-01-05 10:00:00');

        $this->assertSame([$own], $this->verified([$own, $guest]));
    }

    /** Відгук за посиланням без кабінету: доказом є позиція надісланого запиту. */
    public function testReviewThroughEmailLinkIsVerifiedWhileOrderIsCompleted(): void
    {
        $order = $this->order('link@example.com', self::COMPLETED, '2026-01-01 10:00:00');
        $purchase = $this->purchase($order, self::PRODUCT);
        $viaLink = $this->comment('link@example.com', '2026-01-10 10:00:00');
        $this->requestItem($order, $purchase, self::PRODUCT, $viaLink);
        $plain = $this->comment('link@example.com', '2026-01-10 10:00:00');
        $reply = $this->comment('link@example.com', '2026-01-11 10:00:00', null, $viaLink);

        $this->assertSame([$viaLink], $this->verified([$viaLink, $plain, $reply]));

        $this->pdo->perform("UPDATE {$this->prefix}orders SET status_id = :s WHERE id = :id", ['s' => self::OTHER_STATUS, 'id' => $order]);
        $this->assertSame([], $this->verified([$viaLink]), 'повернене замовлення знімає позначку');
    }

    public function testBackfillMatchesOnlyOlderReviewsOfTheBoughtProduct(): void
    {
        $at = new DateTimeImmutable('2026-03-01 00:00:00');
        $buyer = $this->order('Buyer@Example.com ', self::COMPLETED, '2026-01-01 10:00:00');
        $this->purchase($buyer, self::PRODUCT);
        $notDone = $this->order('pending@example.com', self::OTHER_STATUS, '2026-01-01 10:00:00');
        $this->purchase($notDone, self::PRODUCT);
        $later = $this->order('late@example.com', self::COMPLETED, '2026-02-01 10:00:00');
        $this->purchase($later, self::PRODUCT);

        $match = $this->comment(' buyer@example.COM', '2026-01-20 10:00:00');
        $expected = [
            'збіг без регістру й пробілів' => $match,
        ];
        $rejected = [
            'інший товар' => $this->comment('buyer@example.com', '2026-01-20 10:00:00', null, 0, self::OTHER_PRODUCT),
            'замовлення не виконане' => $this->comment('pending@example.com', '2026-01-20 10:00:00'),
            'замовлення після відгуку' => $this->comment('late@example.com', '2026-01-20 10:00:00'),
            'відповідь у гілці' => $this->comment('buyer@example.com', '2026-01-21 10:00:00', null, $match),
            'без email' => $this->comment('', '2026-01-20 10:00:00'),
            'написаний після натискання' => $this->comment('buyer@example.com', '2026-03-02 10:00:00'),
        ];

        $found = array_map(fn($r) => (int) $r->comment_id, $this->backfill->preview($at)['rows']);
        foreach ($expected as $why => $id) {
            $this->assertContains($id, $found, $why);
        }
        foreach ($rejected as $why => $id) {
            $this->assertNotContains($id, $found, $why);
        }
        $row = $this->rowFor($this->backfill->preview($at)['rows'], $match);
        $this->assertSame($buyer, (int) $row->order_id);
    }

    public function testBackfillRunsOnceAndMarksThroughItsOrder(): void
    {
        $at = new DateTimeImmutable('2026-03-01 00:00:00');
        $order = $this->order('once@example.com', self::COMPLETED, '2026-01-01 10:00:00');
        $this->purchase($order, self::PRODUCT);
        $old = $this->comment('once@example.com', '2026-01-20 10:00:00');

        $this->assertSame([], $this->verified([$old]), 'до дії позначки немає');
        $this->assertNull($this->backfill->doneAt());

        $count = $this->backfill->apply($at, 5);
        $this->assertGreaterThanOrEqual(1, $count);
        $this->assertSame('2026-03-01 00:00:00', $this->backfill->doneAt());
        $this->assertSame($count, $this->backfill->doneCount());
        $this->assertSame([$old], $this->verified([$old]));
        $this->assertSame(
            ['order_id' => $order, 'manager_id' => 5],
            array_map('intval', $this->pdo->fetchOne(
                "SELECT order_id, manager_id FROM {$this->prefix}sviat__review_request_verified WHERE comment_id = :c",
                ['c' => $old]
            ))
        );

        $new = $this->comment('once@example.com', '2026-02-01 10:00:00');
        $this->assertNull($this->backfill->apply(new DateTimeImmutable('2026-04-01 00:00:00'), 5), 'друге натискання нічого не робить');
        $this->assertSame([], $this->verified([$new]), 'відгук після дії позначки за email не отримує');

        $this->pdo->perform("UPDATE {$this->prefix}orders SET status_id = :s WHERE id = :id", ['s' => self::OTHER_STATUS, 'id' => $order]);
        $this->assertSame([], $this->verified([$old]), 'повернене замовлення знімає позначку');
    }

    /** Відгук, який уже має позначку, у перегляді не рахується: число — це нові позначки. */
    public function testAlreadyVerifiedReviewsAreNotOffered(): void
    {
        $order = $this->order('both@example.com', self::COMPLETED, '2026-01-01 10:00:00');
        $purchase = $this->purchase($order, self::PRODUCT);
        $viaLink = $this->comment('both@example.com', '2026-01-20 10:00:00');
        $this->requestItem($order, $purchase, self::PRODUCT, $viaLink);

        $found = array_map(fn($r) => (int) $r->comment_id, $this->backfill->preview(new DateTimeImmutable('2026-03-01'))['rows']);
        $this->assertNotContains($viaLink, $found);
    }

    /** @return int[] */
    private function verified(array $ids): array
    {
        $result = array_keys($this->reviews->verifiedCommentIds($ids, [self::COMPLETED]));
        sort($result);

        return $result;
    }

    private function rowFor(array $rows, int $commentId): object
    {
        foreach ($rows as $row) {
            if ((int) $row->comment_id === $commentId) {
                return $row;
            }
        }
        $this->fail("відгуку {$commentId} немає в перегляді");
    }

    private function order(string $email, int $status, string $date, int $userId = 0): int
    {
        $this->pdo->perform(
            "INSERT INTO {$this->prefix}orders (email, status_id, date, user_id, analytics_sent) VALUES (:e, :s, :d, :u, 0)",
            ['e' => $email, 's' => $status, 'd' => $date, 'u' => $userId]
        );

        return (int) $this->pdo->lastInsertId();
    }

    private function purchase(int $orderId, int $productId): int
    {
        $this->pdo->perform(
            "INSERT INTO {$this->prefix}purchases (order_id, product_id, product_name, amount) VALUES (:o, :p, 'Test product', 1)",
            ['o' => $orderId, 'p' => $productId]
        );

        return (int) $this->pdo->lastInsertId();
    }

    private function comment(string $email, string $date, ?int $userId = null, int $parentId = 0, int $productId = self::PRODUCT): int
    {
        $this->pdo->perform(
            "INSERT INTO {$this->prefix}comments (object_id, type, parent_id, name, email, text, date, user_id, approved)
             VALUES (:p, 'product', :parent, 'Test', :e, 'text', :d, :u, 1)",
            ['p' => $productId, 'parent' => $parentId, 'e' => $email, 'd' => $date, 'u' => $userId]
        );

        return (int) $this->pdo->lastInsertId();
    }

    private function requestItem(int $orderId, int $purchaseId, int $productId, int $commentId): void
    {
        $this->pdo->perform(
            "INSERT INTO {$this->prefix}sviat__review_requests (order_id, status) VALUES (:o, 'sent')",
            ['o' => $orderId]
        );
        $requestId = (int) $this->pdo->lastInsertId();
        $this->pdo->perform(
            "INSERT INTO {$this->prefix}sviat__review_request_items (request_id, purchase_id, product_id, comment_id, reviewed_at)
             VALUES (:r, :pu, :p, :c, NOW())",
            ['r' => $requestId, 'pu' => $purchaseId, 'p' => $productId, 'c' => $commentId]
        );
    }
}
