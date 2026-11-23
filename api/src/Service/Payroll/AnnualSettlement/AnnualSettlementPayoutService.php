<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAnnualSettlementRepository;

/**
 * Vrácení doplatku ze zúčtování ve mzdovém běhu (§ 38ch odst. 5, § 35d odst. 8).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Proč to není mzdový vstup
 * ─────────────────────────────────────────────────────────────────────────────
 * Doplatek ze zúčtování je vrácená vlastní záloha na daň, ne příjem. Kdyby se
 * do běhu dostal jako složka mzdy, sečetl by se do úhrnu zúčtovaných mezd
 * (§ 38j odst. 2 písm. f bod 1), zvýšil by vyměřovací základy pojistného
 * a objevil by se v jednotném měsíčním hlášení. Proto jde vlastní cestou
 * a připočítává se až k výplatě, za srážkami.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Okno výplaty
 * ─────────────────────────────────────────────────────────────────────────────
 * § 35d odst. 8: „vyplatí plátce daně poplatníkovi nejpozději při zúčtování
 * mzdy za březen po uplynutí zdaňovacího období." Dřív než v měsíci, ve kterém
 * se zúčtování provedlo, vyplácet není co. Modul proto vyplácí v prvním běhu,
 * který do okna spadne, a po březnu už doplatek do žádného běhu nevstoupí —
 * pozdější výplata není opožděné plnění téhle povinnosti, ale oprava, kterou
 * modul nedělá sám.
 */
final class AnnualSettlementPayoutService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollAnnualSettlementRepository $settlements,
    ) {}

    /**
     * Kolik komu v tomhle období náleží.
     *
     * @return array<int,int> employee_id => částka v haléřích
     */
    public function payoutsForRevision(
        int $supplierId,
        int $revisionId,
        string $periodStart,
    ): array {
        $runId = $this->runId($supplierId, $revisionId);
        if ($runId === null) {
            return [];
        }

        return $this->settlements->payableOutcomesForPeriod(
            $supplierId,
            $runId,
            $periodStart,
        );
    }

    public function pendingPayoutsForPeriod(int $supplierId, string $periodStart): array
    {
        return $this->settlements->payableOutcomesForPeriod($supplierId, 0, $periodStart);
    }

    /**
     * Zapíše, čím se doplatek vyplatil.
     *
     * @param array<int,int> $payouts employee_id => částka v haléřích
     */
    public function recordPayouts(
        int $supplierId,
        int $revisionId,
        string $periodStart,
        array $payouts,
    ): void {
        $runId = $this->runId($supplierId, $revisionId);
        if ($runId === null) {
            return;
        }
        $this->settlements->linkPayout(
            $supplierId,
            $runId,
            $revisionId,
            $periodStart,
            $payouts,
        );
    }

    private function runId(int $supplierId, int $revisionId): ?int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT run_id FROM payroll_run_revisions
              WHERE supplier_id = ? AND id = ?'
        );
        $statement->execute([$supplierId, $revisionId]);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (int) $value;
    }
}
