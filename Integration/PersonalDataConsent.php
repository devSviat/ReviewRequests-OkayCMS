<?php

namespace Okay\Modules\Sviat\ReviewRequests\Integration;

use Closure;
use Okay\Core\Modules\Modules;
use Okay\Core\ServiceLocator;
use Okay\Modules\Sviat\PersonalData\Integration\Consent;

/**
 * Згода на обробку персональних даних через модуль Sviat/PersonalData. Без нього, коли він вимкнений
 * чи прибраний з диска, згоди не питає й нічого не записує.
 */
final class PersonalDataConsent
{
    private const string FORM = 'review_request';

    private readonly Closure $consent;

    private ?bool $available = null;

    /** @param ?Closure(): Consent $consent фасад PersonalData; береться лише коли модуль увімкнений */
    public function __construct(private readonly Modules $modules, ?Closure $consent = null)
    {
        $this->consent = $consent ?? static fn(): Consent => ServiceLocator::getInstance()->getService(Consent::class);
    }

    /** @return string|null текст відмови; null — відгук можна зберігати */
    public function check(): ?string
    {
        return $this->available() ? ($this->consent)()->check(self::FORM) : null;
    }

    /** @param int $recordId id відгуку з create(), а не з POST */
    public function link(int $recordId): void
    {
        if ($this->available()) {
            ($this->consent)()->link(self::FORM, $recordId);
        }
    }

    // Вимкнений модуль не реєструє сервісів, а прибраний з диска не має й класу.
    private function available(): bool
    {
        return $this->available ??= class_exists(Consent::class) && $this->isPersonalDataActive();
    }

    /**
     * Стан із запущених на старті запиту модулів, без запиту до бази: isActiveModule() після старту
     * модулів щоразу йде в базу. Модуля, якого в цьому стані немає (CLI, інсталятор), питаємо в базі.
     */
    private function isPersonalDataActive(): bool
    {
        $running = $this->modules->getRunningModules();
        if (is_array($running) && isset($running['Sviat/PersonalData'])) {
            return (int) $running['Sviat/PersonalData']['is_active'] === 1;
        }

        return (bool) $this->modules->isActiveModule('Sviat', 'PersonalData');
    }
}
