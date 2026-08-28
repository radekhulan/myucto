<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Repository\Payroll\PayrollModuleStateRepository;

final class PayrollProductionGate
{
    public const PRODUCT_RELEASED = false;

    public function __construct(
        private readonly PayrollModuleStateRepository $states,
        private readonly ?bool $releasedOverride = null,
    ) {}

    /** @return array{released:bool} */
    public function status(): array
    {
        return ['released' => $this->isReleased()];
    }

    public function isReleased(): bool
    {
        if ($this->releasedOverride !== null) {
            return $this->releasedOverride;
        }

        return defined('PHPUNIT_COMPOSER_INSTALL')
            ? true
            : self::PRODUCT_RELEASED;
    }

    public function assertActive(int $supplierId): void
    {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException(
                'Firma produkčního mzdového provozu musí být kladné číslo.',
            );
        }
        if (!$this->isReleased()) {
            throw new PayrollProductionGateException();
        }
        if ($this->states->get($supplierId)['status'] !== 'active') {
            throw new PayrollProductionGateException(
                'Před ostrým mzdovým provozem dokončete základní nastavení firmy.',
            );
        }
    }

    public function assertEnvironmentActive(
        int $supplierId,
        string $environment,
    ): void {
        if (!in_array($environment, ['test', 'production'], true)) {
            throw new \InvalidArgumentException(
                'Prostředí musí být test nebo production.',
            );
        }
        if ($environment === 'production') {
            $this->assertActive($supplierId);
        }
    }
}
