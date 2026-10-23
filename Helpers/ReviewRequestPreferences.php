<?php

namespace Okay\Modules\Sviat\ReviewRequests\Helpers;

use DateTimeImmutable;
use Okay\Core\EntityFactory;
use Okay\Core\Modules\Extender\ExtenderFacade;
use Okay\Modules\Sviat\ReviewRequests\Entities\ReviewRequestOptoutsEntity;

/**
 * Відмова від листів із проханням про відгук: за посиланням із листа, в один
 * клік із поштового клієнта або в кабінеті покупця.
 */
class ReviewRequestPreferences
{
    private EntityFactory $entityFactory;

    public function __construct(EntityFactory $entityFactory)
    {
        $this->entityFactory = $entityFactory;
    }

    /**
     * @param int|null $userId хто відмовився в кабінеті; null — з листа
     */
    public function optOut(string $emailHash, ?int $userId): void
    {
        $optouts = $this->entityFactory->get(ReviewRequestOptoutsEntity::class);
        $existing = $optouts->findOne(['email_hash' => $emailHash]);
        if (!$existing) {
            $optouts->add([
                'email_hash' => $emailHash,
                'user_id' => $userId,
                'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
            return;
        }

        // Відмова з листа доводить доступ до пошти й перемагає кабінетну: адресу
        // акаунта можна тимчасово змінити на чужу, відмовитись, а потім скасувати
        // відмову, яку справжній власник пошти тим часом підтвердив із листа.
        if ($userId === null && $existing->user_id !== null) {
            $optouts->update((int) $existing->id, ['user_id' => null]);
        }
    }

    /**
     * Стан для кабінету. Відмову за посиланням із листа кабінет не скасовує:
     * адреса акаунта не підтверджена, і будь-хто, зареєструвавшись на чужу
     * пошту, повернув би їй листи, від яких власник відмовився.
     *
     * @return array{email: string, subscribed: bool, locked: bool}|null null — в акаунта немає придатної адреси
     */
    public function stateFor(object $user): ?array
    {
        $email = ReviewRequestRules::normalizeEmail($user->email ?? null);
        if ($email === null) {
            return null;
        }

        $optout = $this->entityFactory->get(ReviewRequestOptoutsEntity::class)
            ->findOne(['email_hash' => ReviewRequestRules::emailHash($email)]);

        return ExtenderFacade::execute(__METHOD__, [
            'email' => $email,
            'subscribed' => !$optout,
            'locked' => $optout && (int) ($optout->user_id ?? 0) !== (int) $user->id,
        ], func_get_args());
    }

    /** Менеджер повертає адресу до листів на прохання покупця. @return bool чи була адреса в стоп-листі */
    public function removeOptOut(string $normalizedEmail): bool
    {
        $optouts = $this->entityFactory->get(ReviewRequestOptoutsEntity::class);
        $optout = $optouts->findOne(['email_hash' => ReviewRequestRules::emailHash($normalizedEmail)]);
        if (!$optout) {
            return false;
        }
        $optouts->delete((int) $optout->id);

        return true;
    }

    /** @return bool чи змінено стан */
    public function setFromCabinet(object $user, bool $subscribed): bool
    {
        $state = $this->stateFor($user);
        if ($state === null || $state['locked'] || $state['subscribed'] === $subscribed) {
            return false;
        }

        $hash = ReviewRequestRules::emailHash($state['email']);
        if ($subscribed) {
            $this->entityFactory->get(ReviewRequestOptoutsEntity::class)
                ->deleteByHashAndUser($hash, (int) $user->id);
        } else {
            $this->optOut($hash, (int) $user->id);
        }

        return true;
    }
}
