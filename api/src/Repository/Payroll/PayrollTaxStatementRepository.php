<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Roční podklad pro vyúčtování daně ze závislé činnosti (DPZVD6) a daně
 * vybírané srážkou (DPSVD2).
 *
 * Čte JEN skalární souhrny ze zmrazených zákonných výsledků aktuálně
 * schválených revizí — stejná hranice jako {@see PayrollAnnualReportRepository}
 * a {@see PayrollTaxBonusClaimRepository}. Jediná výjimka jsou počty osob
 * podle místa výkonu práce, které příloha č. 1 vyžaduje jménem obce: tam se
 * čte evidence vztahů, ale ven jde zase jen počet, nikdy identita.
 */
final class PayrollTaxStatementRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Měsíční úhrny daně za rok napříč všemi běhy měsíce.
     *
     * Zálohová daň je součet daně po slevách (`advance_tax_minor_units`) —
     * tedy to, co MĚLO být sraženo, nikoli to, co po započtení bonusů zbylo
     * odvést. Zápočet bonusu patří do jiného sloupce tiskopisu.
     *
     * @return list<array{
     *   period_start:string,
     *   headcount:mixed,
     *   advance_tax_minor:mixed,
     *   monthly_bonus_minor:mixed,
     *   withholding_tax_minor:mixed
     * }>
     */
    public function monthlyTaxTotals(int $supplierId, int $year): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT run.period_start,
                    JSON_LENGTH(revision.result_snapshot_json, "$.people") AS headcount,
                    JSON_VALUE(tax.result_snapshot_json, "$.advance_tax_minor_units")
                        AS advance_tax_minor,
                    JSON_VALUE(tax.result_snapshot_json, "$.tax_bonus_minor_units")
                        AS monthly_bonus_minor,
                    JSON_VALUE(tax.result_snapshot_json, "$.withholding_tax_minor_units")
                        AS withholding_tax_minor
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
              WHERE run.supplier_id = ?
                AND run.period_start >= ?
                AND run.period_start < ?
                AND revision.status = "approved"
              ORDER BY run.period_start, run.id',
        );
        $statement->execute([
            $supplierId,
            sprintf('%04d-01-01', $year),
            sprintf('%04d-01-01', $year + 1),
        ]);

        /** @var list<array{period_start:string,headcount:mixed,advance_tax_minor:mixed,monthly_bonus_minor:mixed,withholding_tax_minor:mixed}> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * Výsledky ročního zúčtování za PŘEDCHOZÍ zdaňovací období, vyplacené
     * v měsících vykazovaného roku.
     *
     * Rozdíl na dani a rozdíl na bonusu jsou dva různé sloupce tiskopisu
     * (přeplatek z ročního zúčtování vs. doplatek na daňovém bonusu), proto se
     * vracejí odděleně — přesně proto je migrace 1399 ukládá zvlášť. Berou se
     * jen kladné části: nedoplatek se podle § 38ch odst. 5 nesráží.
     *
     * @return list<array{
     *   payout_period_start:string,
     *   tax_overpayment_minor:mixed,
     *   bonus_topup_minor:mixed
     * }>
     */
    public function annualSettlementPayouts(int $supplierId, int $year): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT outcome.payout_period_start,
                    SUM(GREATEST(outcome.tax_difference_minor, 0))
                        AS tax_overpayment_minor,
                    SUM(GREATEST(outcome.bonus_difference_minor, 0))
                        AS bonus_topup_minor
               FROM payroll_annual_settlement_outcomes outcome
              WHERE outcome.supplier_id = ?
                AND outcome.tax_year = ?
                AND outcome.payable_minor > 0
                AND outcome.payout_period_start IS NOT NULL
                AND outcome.payout_period_start >= ?
                AND outcome.payout_period_start < ?
              GROUP BY outcome.payout_period_start
              ORDER BY outcome.payout_period_start',
        );
        $statement->execute([
            $supplierId,
            $year - 1,
            sprintf('%04d-01-01', $year),
            sprintf('%04d-01-01', $year + 1),
        ]);

        /** @var list<array{payout_period_start:string,tax_overpayment_minor:mixed,bonus_topup_minor:mixed}> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * Skutečně odvedeno na účet finančního úřadu, po měsících mzdového období
     * a po druhu daně.
     *
     * Řadí se podle OBDOBÍ závazku, ne podle data platby: tiskopis se ptá, co
     * bylo odvedeno „za uvedené zdaňovací období", takže lednová záloha
     * zaplacená v únoru patří na řádek ledna. `payroll_payment_matches` nese
     * částky se znaménkem (`reversed` je záporné), takže prostý součet dává
     * čistou uhrazenou částku.
     *
     * @return list<array{
     *   period_start:string,
     *   liability_kind:string,
     *   settled_minor:mixed,
     *   last_payment_date:?string
     * }>
     */
    public function remittedTaxTotals(int $supplierId, int $year): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT run.period_start,
                    liability.liability_kind,
                    SUM(pmatch.amount_minor) AS settled_minor,
                    MAX(pmatch.actual_payment_date) AS last_payment_date
               FROM payroll_payment_matches pmatch
               JOIN payroll_payment_liabilities liability
                 ON liability.supplier_id = pmatch.supplier_id
                AND liability.id = pmatch.liability_id
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = liability.supplier_id
                AND revision.id = liability.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE pmatch.supplier_id = ?
                AND liability.liability_kind IN ("advance_tax", "withholding_tax")
                AND run.period_start >= ?
                AND run.period_start < ?
              GROUP BY run.period_start, liability.liability_kind
              ORDER BY run.period_start, liability.liability_kind',
        );
        $statement->execute([
            $supplierId,
            sprintf('%04d-01-01', $year),
            sprintf('%04d-01-01', $year + 1),
        ]);

        /** @var list<array{period_start:string,liability_kind:string,settled_minor:mixed,last_payment_date:?string}> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * Příloha č. 1 — počet zaměstnanců podle místa výkonu práce k rozhodnému dni.
     *
     * Rozhodný den je 1. prosinec vykazovaného období. Počítají se vztahy, které
     * ten den trvaly, podle podmínek platných k témuž dni; obec je kód ZÚJ
     * z evidence JMHZ. Vztahy bez vyplněné obce se vracejí pod prázdným kódem,
     * aby se dalo poznat, že příloha není úplná — tichý výpadek by v tiskopisu
     * znamenal chybějící obec, kterou nikdo nehledá.
     *
     * @return list<array{
     *   municipality_code:?string,
     *   municipality_name:?string,
     *   headcount:mixed
     * }>
     */
    public function workplaceHeadcount(int $supplierId, string $onDate): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT terms.jmhz_workplace_municipality_code AS municipality_code,
                    MAX(terms.jmhz_workplace_municipality_name) AS municipality_name,
                    COUNT(DISTINCT employment.employee_id) AS headcount
               FROM payroll_employments employment
               JOIN payroll_employment_terms terms
                 ON terms.supplier_id = employment.supplier_id
                AND terms.employment_id = employment.id
                AND terms.effective_from <= ?
                AND (terms.effective_to IS NULL OR terms.effective_to >= ?)
              WHERE employment.supplier_id = ?
                AND employment.archived_at IS NULL
                AND employment.start_date <= ?
                AND (employment.end_date IS NULL OR employment.end_date >= ?)
              GROUP BY terms.jmhz_workplace_municipality_code
              ORDER BY terms.jmhz_workplace_municipality_code',
        );
        $statement->execute([$onDate, $onDate, $supplierId, $onDate, $onDate]);

        /** @var list<array{municipality_code:?string,municipality_name:?string,headcount:mixed}> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * Kolik zaměstnanců bylo ve vykazovaném roce vedeno jako daňový nerezident.
     *
     * Vrací se POČET, ne osoby: aplikace nemá údaje, které příloha č. 2
     * vyžaduje (číslo dokladu totožnosti, typ dokladu, typ zahraničního
     * daňového identifikátoru), takže jediné, co s tím jde udělat, je říct
     * uživateli, že přílohu musí doplnit ručně.
     */
    public function nonResidentEmployeeCount(int $supplierId, int $year): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(DISTINCT residence.employee_id)
               FROM payroll_person_tax_residences residence
              WHERE residence.supplier_id = ?
                AND residence.residence = "non-resident"
                AND residence.effective_from < ?
                AND (residence.effective_to IS NULL OR residence.effective_to >= ?)',
        );
        $statement->execute([
            $supplierId,
            sprintf('%04d-01-01', $year + 1),
            sprintf('%04d-01-01', $year),
        ]);

        return (int) $statement->fetchColumn();
    }
}
