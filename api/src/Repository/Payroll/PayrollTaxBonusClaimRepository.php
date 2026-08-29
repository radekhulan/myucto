<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Měsíční úhrny daně a bonusů za firmu pro žádosti podle § 35d odst. 5 a 9 ZDP.
 *
 * Čte JEN skalární souhrny ze zmrazených zákonných výsledků aktuálně
 * schválených revizí — stejný přístup jako {@see PayrollAnnualReportRepository}.
 * Osobní snapshoty se ven nevynášejí; z `net_pay` se agreguje přímo v SQL,
 * protože kořenový snapshot čisté mzdy nese jen `net_payable_minor_units`
 * a úhrn doplatků ze zúčtování v něm neexistuje.
 *
 * Období může mít VÍC běhů (unikát je `supplier_id + period_start +
 * office_scope_id`), takže se sčítá napříč všemi. Žádost jde jednomu správci
 * daně za celou firmu, ne za mzdové středisko.
 */
final class PayrollTaxBonusClaimRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Úhrny za jeden kalendářní měsíc.
     *
     * @return list<array{
     *   revision_id:int,
     *   period_start:string,
     *   payment_date:?string,
     *   advance_tax_minor:mixed,
     *   monthly_bonus_minor:mixed,
     *   annual_settlement_minor:mixed
     * }>
     */
    public function approvedMonthlyTotals(int $supplierId, int $year, int $month): array
    {
        $periodStart = sprintf('%04d-%02d-01', $year, $month);
        $statement = $this->db->pdo()->prepare(
            'SELECT revision.id AS revision_id,
                    run.period_start,
                    run.payment_date,
                    JSON_VALUE(tax.result_snapshot_json, "$.advance_tax_minor_units")
                        AS advance_tax_minor,
                    JSON_VALUE(tax.result_snapshot_json, "$.tax_bonus_minor_units")
                        AS monthly_bonus_minor,
                    (SELECT COALESCE(SUM(CAST(JSON_VALUE(
                                person.result_snapshot_json,
                                "$.annual_settlement_minor_units"
                            ) AS SIGNED)), 0)
                       FROM payroll_statutory_person_results person
                      WHERE person.supplier_id = net.supplier_id
                        AND person.statutory_result_id = net.id
                        AND person.result_status = "calculated"
                    ) AS annual_settlement_minor
               FROM payroll_runs run
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = run.supplier_id
                AND revision.run_id = run.id
                AND revision.revision_no = run.current_revision_no
               JOIN payroll_statutory_results tax
                 ON tax.supplier_id = revision.supplier_id
                AND tax.revision_id = revision.id
                AND tax.calculation_kind = "income_tax"
                AND tax.result_status = "calculated"
               JOIN payroll_statutory_results net
                 ON net.supplier_id = revision.supplier_id
                AND net.revision_id = revision.id
                AND net.calculation_kind = "net_pay"
                AND net.result_status = "calculated"
              WHERE run.supplier_id = ?
                AND run.period_start = ?
                AND revision.status = "approved"
              ORDER BY run.id',
        );
        $statement->execute([$supplierId, $periodStart]);

        /** @var list<array{revision_id:int,period_start:string,payment_date:?string,advance_tax_minor:mixed,monthly_bonus_minor:mixed,annual_settlement_minor:mixed}> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }
}
