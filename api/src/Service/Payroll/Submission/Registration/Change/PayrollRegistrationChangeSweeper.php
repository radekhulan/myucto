<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration\Change;

/**
 * Hromadné porovnání hlásitelných údajů za jednu firmu.
 *
 * Rozhraní existuje kvůli jedinému konzumentovi —
 * {@see PayrollRegistrationChangeSweepRunner} — a kvůli tomu, aby šel
 * otestovat bez databáze. Implementaci drží
 * {@see PayrollRegistrationChangeDetectionService}, kterou volá i karta
 * zaměstnance a tlačítko ve frontě podání; druhá implementace vzniknout
 * nesmí, jinak by se detekce rozešla podle toho, kdo ji spustil.
 */
interface PayrollRegistrationChangeSweeper
{
    /**
     * @return array{scanned:int,changed:int,skipped:int,created:int}
     */
    public function sweep(int $supplierId, string $environment, int $limit): array;
}
