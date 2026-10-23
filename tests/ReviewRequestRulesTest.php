<?php

namespace Modules\Sviat\ReviewRequests;

use DateTimeImmutable;
use Okay\Core\Settings;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestRules;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Захист від потоку листів: кожен запобіжник має
 * випадок, де він спрацьовує, і контроль, де ні.
 */
class ReviewRequestRulesTest extends TestCase
{
    private const NOW = '2026-11-20 12:00:00';

    private function rules(array $settings = []): ReviewRequestRules
    {
        $settings += ['statuses' => '4'];
        $stub = $this->createStub(Settings::class);
        $stub->method('get')->willReturnCallback(
            fn($name) => $settings[substr($name, strlen(ReviewRequestSettings::PREFIX))] ?? null
        );

        return new ReviewRequestRules(new ReviewRequestSettings($stub));
    }

    private static function order(array $overrides = []): object
    {
        return (object) ($overrides + ['status_id' => 4, 'email' => 'Buyer@Example.com ', 'date' => '2026-11-01 10:00:00']);
    }

    private static function context(array $overrides = []): array
    {
        return $overrides + [
            'detected_at' => new DateTimeImmutable('2026-11-10 09:00:00'),
            'reviewable_items' => 1,
            'opted_out' => false,
            'last_sent_to_email' => null,
            'sent_today' => 0,
        ];
    }

    private function decide(array $order = [], array $context = [], array $settings = [], string $now = self::NOW): array
    {
        return $this->rules($settings)->onSend(self::order($order), self::context($context), new DateTimeImmutable($now));
    }

    public function testOrdinaryCompletedOrderIsSent(): void
    {
        $this->assertSame([ReviewRequestRules::SEND, null], $this->decide());
    }

    /** @return array<string, array{0: array, 1: array, 2: array, 3: string, 4: array}> */
    public static function guardProvider(): array
    {
        return [
            'замовлення вже не виконане' => [['status_id' => 5], [], [], self::NOW, [ReviewRequestRules::CANCEL, 'not_completed']],
            'інший набір виконаних статусів' => [[], [], ['statuses' => '7,8'], self::NOW, [ReviewRequestRules::CANCEL, 'not_completed']],
            'без email' => [['email' => ''], [], [], self::NOW, [ReviewRequestRules::SKIP, 'no_email']],
            'невалідний email' => [['email' => 'gmail'], [], [], self::NOW, [ReviewRequestRules::SKIP, 'invalid_email']],
            'відписався' => [[], ['opted_out' => true], [], self::NOW, [ReviewRequestRules::SKIP, 'opted_out']],
            'виключена адреса' => [[], [], ['excluded' => "buyer@example.com"], self::NOW, [ReviewRequestRules::SKIP, 'excluded']],
            'виключений домен' => [[], [], ['excluded' => "f1center.ua, example.com"], self::NOW, [ReviewRequestRules::SKIP, 'excluded']],
            'замовлення старше 45 днів' => [['date' => '2026-10-01 10:00:00'], [], [], self::NOW, [ReviewRequestRules::SKIP, 'too_old']],
            'немає товарів, які можна оцінити' => [[], ['reviewable_items' => 0], [], self::NOW, [ReviewRequestRules::SKIP, 'no_products']],
            'цій адресі писали 30 днів тому' => [[], ['last_sent_to_email' => new DateTimeImmutable('2026-10-21 12:00:00')], [], self::NOW, [ReviewRequestRules::SKIP, 'recently_asked']],
            'ще не минуло 7 днів після виконання' => [[], ['detected_at' => new DateTimeImmutable('2026-11-15 09:00:00')], [], self::NOW, [ReviewRequestRules::WAIT, 'delay']],
            'ніч' => [[], [], [], '2026-11-20 23:30:00', [ReviewRequestRules::WAIT, 'window']],
            'рано вранці' => [[], [], [], '2026-11-20 09:59:00', [ReviewRequestRules::WAIT, 'window']],
            'о 19:00 вже не надсилаємо' => [[], [], [], '2026-11-20 19:00:00', [ReviewRequestRules::WAIT, 'window']],
            'добовий ліміт вичерпано' => [[], ['sent_today' => 40], [], self::NOW, [ReviewRequestRules::WAIT, 'daily_cap']],
            'пауза після невдалої спроби' => [[], ['retry_at' => new DateTimeImmutable('2026-11-20 12:30:00')], [], self::NOW, [ReviewRequestRules::WAIT, 'retry']],
        ];
    }

    #[DataProvider('guardProvider')]
    public function testGuard(array $order, array $context, array $settings, string $now, array $expected): void
    {
        $this->assertSame($expected, $this->decide($order, $context, $settings, $now));
    }

    /** Контролі до запобіжників вище: межа не спрацьовує раніше, ніж треба. */
    public function testBoundariesLetTheEmailThrough(): void
    {
        $this->assertSame(ReviewRequestRules::SEND, $this->decide(['date' => '2026-10-07 12:00:01'])[0], '44 дні — ще не старе');
        $this->assertSame(ReviewRequestRules::SEND, $this->decide([], ['last_sent_to_email' => new DateTimeImmutable('2026-09-20 11:59:00')])[0], '61 день тому — можна знову');
        $this->assertSame(ReviewRequestRules::SEND, $this->decide([], ['detected_at' => new DateTimeImmutable('2026-11-13 12:00:00')])[0], 'рівно 7 днів');
        $this->assertSame(ReviewRequestRules::SEND, $this->decide([], [], [], '2026-11-20 10:00:00')[0], '10:00 — уже можна');
        $this->assertSame(ReviewRequestRules::SEND, $this->decide([], ['sent_today' => 39])[0], '39-й лист за день');
        $this->assertSame(ReviewRequestRules::SEND, $this->decide([], [], ['excluded' => 'other.com, mail@example.com'])[0], 'чужий домен і чужа адреса');
        $this->assertSame(ReviewRequestRules::SEND, $this->decide([], ['retry_at' => new DateTimeImmutable('2026-11-20 11:00:00')])[0], 'пауза минула');
    }

    /** Ідентифікатори статусів у кожного магазину свої: без вибору адміна нічого не виконане. */
    public function testWithoutChosenStatusesNothingIsCompleted(): void
    {
        $stub = $this->createStub(Settings::class);
        $settings = new ReviewRequestSettings($stub);

        $this->assertSame([], $settings->completedStatuses());
        $this->assertSame(
            [ReviewRequestRules::CANCEL, 'not_completed'],
            (new ReviewRequestRules($settings))->onSend(self::order(), self::context(), new DateTimeImmutable(self::NOW))
        );
    }

    public function testDeletedOrderIsCancelled(): void
    {
        $decision = $this->rules()->onSend(null, self::context(), new DateTimeImmutable(self::NOW));

        $this->assertSame([ReviewRequestRules::CANCEL, 'order_deleted'], $decision);
    }

    public function testDetectionSkipsWhatCanNeverBeSent(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $rules = $this->rules();

        $this->assertSame(['pending', null], $rules->onDetect(self::order(), $now));
        $this->assertSame(['skipped', 'no_email'], $rules->onDetect(self::order(['email' => null]), $now));
        $this->assertSame(['skipped', 'invalid_email'], $rules->onDetect(self::order(['email' => 'a@b']), $now));
        $this->assertSame(['skipped', 'too_old'], $rules->onDetect(self::order(['date' => '2025-01-01']), $now));
    }

    /** FILTER_VALIDATE_EMAIL пропускає «"a,b@c"@d», а Notify::SMTP() ділить адресата комою. */
    public function testEmailWithCommaOrQuotesIsRejected(): void
    {
        $this->assertTrue((bool) filter_var('"a,victim@x.com,b"@y.com', FILTER_VALIDATE_EMAIL), 'передумова: PHP таку адресу приймає');
        $this->assertNull(ReviewRequestRules::normalizeEmail('"a,victim@x.com,b"@y.com'));
        $this->assertNull(ReviewRequestRules::normalizeEmail('"quoted"@y.com'));
        $this->assertSame('a.b+c@y.com', ReviewRequestRules::normalizeEmail('A.B+c@Y.com'));
    }

    public function testEmailIsNormalisedBeforeHashing(): void
    {
        $this->assertSame('buyer@example.com', ReviewRequestRules::normalizeEmail('  Buyer@Example.COM '));
        $this->assertSame(
            ReviewRequestRules::emailHash('buyer@example.com'),
            ReviewRequestRules::emailHash((string) ReviewRequestRules::normalizeEmail('BUYER@example.com'))
        );
    }

    /** Вікно через північ (наприклад, 22–6) теж має працювати. */
    public function testWindowAcrossMidnight(): void
    {
        $rules = $this->rules(['window_from' => 22, 'window_to' => 6]);

        $this->assertTrue($rules->inSendWindow(new DateTimeImmutable('2026-11-20 23:00:00')));
        $this->assertTrue($rules->inSendWindow(new DateTimeImmutable('2026-11-20 05:59:00')));
        $this->assertFalse($rules->inSendWindow(new DateTimeImmutable('2026-11-20 12:00:00')));
    }
}
