<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

/**
 * Kompatibilní adaptér starých automatických aktivačních spouští.
 *
 * MZ-27-W10 zakazuje, aby čtení setup-checku nebo první approve přepnuly firmu
 * do ostrého provozu. Metody proto vědomě nic nemění; jediným zapisovatelem
 * stavu `active` je PayrollProductionQualificationService.
 */
final class PayrollModuleActivationService
{
    /**
     * @return null automatická spoušť je od MZ-27-W10 zakázaná
     */
    public function activateWhenSetupComplete(
        int $supplierId,
        ?int $userId,
    ): null {
        return null;
    }

    /**
     * @return null automatická spoušť je od MZ-27-W10 zakázaná
     */
    public function activateAfterApprovedRun(
        int $supplierId,
        ?int $userId,
    ): null {
        return null;
    }
}
