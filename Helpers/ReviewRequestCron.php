<?php

namespace Okay\Modules\Sviat\ReviewRequests\Helpers;

use DateTimeImmutable;
use Okay\Core\Modules\Extender\ExtenderFacade;
use Okay\Modules\Sviat\ReviewRequests\Entities\ReviewRequestsEntity;
use Psr\Log\LoggerInterface;

/**
 * Задача планувальника: виявити нові виконані замовлення, вирішити по кожному
 * запиту в черзі й надіслати те, що можна. У тестовому режимі рішення
 * приймаються й записуються так само, але жоден лист не йде і SEND лишається
 * в черзі — адмін бачить, що поїхало б.
 */
class ReviewRequestCron
{
    /** Скільки запитів черги розглядати за прогін: з запасом над добовим лімітом. */
    private const REVIEW_BATCH = 200;

    private const SENT = 'sent';
    private const FAILED = 'failed';
    private const TAKEN = 'taken';

    private ReviewRequestQueue $queue;
    private ReviewRequestRules $rules;
    private ReviewRequestMailer $mailer;
    private ReviewRequestSettings $settings;
    private LoggerInterface $logger;

    public function __construct(
        ReviewRequestQueue $queue,
        ReviewRequestRules $rules,
        ReviewRequestMailer $mailer,
        ReviewRequestSettings $settings,
        LoggerInterface $logger
    ) {
        $this->queue = $queue;
        $this->rules = $rules;
        $this->mailer = $mailer;
        $this->settings = $settings;
        $this->logger = $logger;
    }

    /**
     * @return array{detected: int, sent: int, failed: int, closed: int, waiting: int}
     */
    public function run(?DateTimeImmutable $now = null): array
    {
        $now = $now ?? new DateTimeImmutable();
        $stats = ['detected' => 0, 'sent' => 0, 'failed' => 0, 'closed' => 0, 'waiting' => 0];

        if (!$this->queue->hasBaseline()) {
            $this->logger->warning('ReviewRequests: базової лінії не було, записано ' . $this->queue->baseline($now) . ' виконаних замовлень як baseline');
            return ExtenderFacade::execute(__METHOD__, $stats, func_get_args());
        }

        $stats['detected'] = $this->queue->detect($now);

        $sentToday = $this->queue->sentSince($now->setTime(0, 0));

        // Без адреси сайту лист неробочий: не відправляємо зовсім і не витрачаємо
        // спроб, інакше помилка налаштування закрила б чергу статусом error.
        $canSend = $this->settings->isLive();
        if ($canSend && $this->settings->rootUrl() === '') {
            $canSend = false;
            $this->logger->warning('ReviewRequests: не налаштовано адресу сайту, листи не надсилаються');
        }

        foreach ($this->queue->pending(self::REVIEW_BATCH) as $request) {
            $order = $this->queue->findOrder((int) $request->order_id);
            $purchases = $order === null ? [] : $this->queue->reviewablePurchases((int) $order->id);

            $email = $order === null ? null : ReviewRequestRules::normalizeEmail($order->email);
            $emailHash = $email === null ? $request->email_hash : ReviewRequestRules::emailHash($email);
            if ($emailHash !== $request->email_hash) {
                $this->queue->updateEmailHash((int) $request->id, $emailHash);
                $request->email_hash = $emailHash;
            }

            [$decision, $reason] = $this->rules->onSend($order, [
                'detected_at' => new DateTimeImmutable((string) $request->detected_at),
                'reviewable_items' => count($purchases),
                'opted_out' => $this->queue->isOptedOut($emailHash),
                'last_sent_to_email' => $this->queue->lastSentToEmail($emailHash, (int) $request->id),
                'sent_today' => $sentToday,
                'retry_at' => empty($request->retry_at) ? null : new DateTimeImmutable((string) $request->retry_at),
            ], $now);

            if ($decision === ReviewRequestRules::CANCEL || $decision === ReviewRequestRules::SKIP) {
                $status = $decision === ReviewRequestRules::CANCEL ? ReviewRequestsEntity::STATUS_CANCELLED : ReviewRequestsEntity::STATUS_SKIPPED;
                $this->queue->close((int) $request->id, $status, $reason);
                $stats['closed']++;
                continue;
            }

            if ($decision === ReviewRequestRules::WAIT || !$canSend) {
                $stats['waiting']++;
                continue;
            }

            $result = $this->sendOne($request, $order, $purchases, $now);
            if ($result === self::SENT) {
                $stats['sent']++;
                $sentToday++;
            } elseif ($result === self::FAILED) {
                $stats['failed']++;
                // Збій пошти спільний для всіх листів: решту не пробуємо до наступного прогону.
                $canSend = false;
            }
        }

        return ExtenderFacade::execute(__METHOD__, $stats, func_get_args());
    }

    /**
     * @param object[] $purchases
     * @return string SENT, FAILED або TAKEN — запит уже забрав інший прогін
     */
    private function sendOne(object $request, object $order, array $purchases, DateTimeImmutable $now): string
    {
        $email = ReviewRequestRules::normalizeEmail($order->email);
        $token = ReviewRequestToken::generate();
        $unsubscribeToken = ReviewRequestToken::generate();

        // Запит позначається надісланим до відправки: якщо процес обірветься
        // після листа, наступний прогін не надішле його вдруге. Ціна — лист,
        // який міг і не піти, замість повторного листа покупцю.
        if (!$this->queue->markSent((int) $request->id, ReviewRequestToken::hash($token), ReviewRequestToken::hash($unsubscribeToken), $now)) {
            return self::TAKEN;
        }
        $this->queue->storeItems((int) $request->id, $purchases);

        try {
            [$sent, $error] = $this->mailer->send($order, $purchases, $token, $unsubscribeToken, (string) $email);
        } catch (\Throwable $e) {
            [$sent, $error] = [false, get_class($e) . ': ' . $e->getMessage()];
        }

        if ($sent) {
            return self::SENT;
        }

        $this->queue->markFailed($request, $error, $now);
        $this->logger->warning('ReviewRequests: лист для замовлення ' . (int) $order->id . ' не надіслано: ' . $error);

        return self::FAILED;
    }
}
