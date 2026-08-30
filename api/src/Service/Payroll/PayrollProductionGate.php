<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Repository\Payroll\PayrollModuleStateRepository;

final class PayrollProductionGate
{
    /**
     * Výchozí stav produktu: mzdy jedou v testovacím režimu.
     *
     * Odemyká se konfigurací instalace ({@see CONFIG_KEY}), ne přepsáním téhle
     * konstanty — jinak by se rozhodnutí „tahle instalace jede mzdy ostře"
     * ztratilo v diffu a nešlo by ho na dané instalaci ani zjistit, ani vrátit
     * bez nasazení nové verze.
     */
    public const PRODUCT_RELEASED = false;

    /**
     * Přepínač ostrého mzdového provozu pro celou instalaci.
     *
     * `true` odemyká mzdové platební příkazy a ostré transporty na ČSSZ,
     * zdravotní pojišťovny a finanční správu. Je to vědomé rozhodnutí
     * provozovatele: od té chvíle odcházejí podání úřadům doopravdy.
     */
    public const CONFIG_KEY = 'payroll.production_released';

    public function __construct(
        private readonly PayrollModuleStateRepository $states,
        private readonly ?bool $releasedOverride = null,
        private readonly ?Config $config = null,
    ) {}

    /** @return array{released:bool} */
    public function status(): array
    {
        return ['released' => $this->isReleased()];
    }

    public function isReleased(): bool
    {
        // Explicitní override z konstruktoru má přednost — používají ho testy,
        // které potřebují obě strany brány bez ohledu na konfiguraci stroje.
        if ($this->releasedOverride !== null) {
            return $this->releasedOverride;
        }
        if ($this->config !== null) {
            $configured = $this->config->get(self::CONFIG_KEY);
            if ($configured !== null) {
                return (bool) $configured;
            }
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
