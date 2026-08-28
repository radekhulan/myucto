<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Run\PayrollRunStatus;

/**
 * Odpověď na jedinou otázku: má už firma ve mzdách ostrá data?
 *
 * Why: průvodce prvním nastavením mezd se má ukazovat jen do chvíle, než firma
 * poprvé projede mzdový měsíc. Rozhoduje se podle SCHVÁLENÉHO běhu, ne podle
 * jakéhokoliv: rozpracovaný nebo zrušený běh je pořád fáze zkoušení, do které
 * průvodce patří.
 *
 * Vlastní služba (a ne metoda v {@see \MyInvoice\Repository\Payroll\PayrollRunRepository})
 * schválně: je to jeden `EXISTS` pro UI, ne součást pracovního postupu běhů.
 */
final class PayrollOnboardingStatusService
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Stavy, po kterých už mzdy „běží": schválením vzniká doklad, kterým se
     * platí a podává. Zpět se z nich dá jen opravnou revizí, takže i
     * `reopened` / `correction_pending` znamená, že data existují.
     *
     * @var list<PayrollRunStatus>
     */
    private const SETTLED_STATUSES = [
        PayrollRunStatus::APPROVED,
        PayrollRunStatus::POSTED,
        PayrollRunStatus::PAYMENT_READY,
        PayrollRunStatus::PAID,
        PayrollRunStatus::CLOSED,
        PayrollRunStatus::CORRECTION_PENDING,
        PayrollRunStatus::REOPENED,
    ];

    public function hasSettledPayroll(int $supplierId): bool
    {
        $statuses = array_map(
            static fn (PayrollRunStatus $status): string => $status->value,
            self::SETTLED_STATUSES,
        );
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));

        $stmt = $this->db->pdo()->prepare(
            'SELECT EXISTS(
                SELECT 1 FROM payroll_runs
                 WHERE supplier_id = ?
                   AND status IN (' . $placeholders . ')
             )',
        );
        $stmt->execute([$supplierId, ...$statuses]);

        return (bool) $stmt->fetchColumn();
    }
}
