<?php

namespace Okay\Modules\Sviat\ReviewRequests\Extenders;

use Okay\Core\Modules\Extender\ExtensionInterface;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestMailer;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestReviews;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestSettings;

class FrontExtender implements ExtensionInterface
{
    private ReviewRequestReviews $reviews;
    private ReviewRequestSettings $settings;

    public function __construct(ReviewRequestReviews $reviews, ReviewRequestSettings $settings)
    {
        $this->reviews = $reviews;
        $this->settings = $settings;
    }

    /**
     * CommentsHelper::getList: позначка «Перевірена покупка» одним запитом на
     * сторінку відгуків, а не на кожен відгук.
     */
    public function markVerifiedPurchases($comments)
    {
        if (empty($comments) || !is_array($comments)) {
            return $comments;
        }

        $verified = $this->reviews->verifiedCommentIds(
            array_map(fn($c) => $c->id ?? 0, $comments),
            $this->settings->completedStatuses()
        );
        foreach ($comments as $comment) {
            $comment->sviat__verified_purchase = isset($verified[(int) ($comment->id ?? 0)]);
        }

        return $comments;
    }

    /**
     * Notify::SMTP: List-Unsubscribe (і List-Unsubscribe-Post, якщо ввімкнено
     * відписку в один клік) лише в нашому листі.
     *
     * PHPMailer один на процес, і заголовки між листами не очищуються:
     * Notify::SMTP() щоразу дописує «MIME-Version», а наш List-Unsubscribe
     * поїхав би й у наступні листи магазину. Тому заголовки збираються наново
     * для кожного листа: з List-Unsubscribe — лише коли відправляємо ми.
     * «MIME-Version» PHPMailer ставить сам, тож власний — дубль, його прибираємо.
     */
    public function addUnsubscribeHeader($mailer)
    {
        if (!is_object($mailer) || !method_exists($mailer, 'getCustomHeaders')) {
            return $mailer;
        }

        $headers = [];
        foreach ($mailer->getCustomHeaders() as [$name, $value]) {
            $name = trim((string) $name);
            if (strcasecmp($name, 'List-Unsubscribe') === 0 || strcasecmp($name, 'List-Unsubscribe-Post') === 0
                || stripos($name, 'MIME-Version') === 0) {
                continue;
            }
            $headers[strtolower($name)] = [$name, trim((string) $value)];
        }

        $url = ReviewRequestMailer::pendingUnsubscribeUrl();
        if ($url !== null) {
            $headers['list-unsubscribe'] = ['List-Unsubscribe', '<' . $url . '>'];
            if ($this->settings->oneClickUnsubscribe()) {
                $headers['list-unsubscribe-post'] = ['List-Unsubscribe-Post', 'List-Unsubscribe=One-Click'];
            }
        }

        $mailer->clearCustomHeaders();
        foreach ($headers as [$name, $value]) {
            $mailer->addCustomHeader($name, $value);
        }

        return $mailer;
    }
}
