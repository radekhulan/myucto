<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Měsíční snapshoty mzdového rozpadu PO ZAMĚSTNANCI — podklad pro mzdový list (§38j ZDP).
 *
 * Zapisuje se výhradně z {@see \MyInvoice\Service\Accounting\Payroll\PayrollPostingService::post()},
 * když je zaúčtování svázané s konkrétním zaměstnancem (`employee_id` v požadavku).
 * Idempotence shodná s journal_entries: opakované zaúčtování téhož měsíce řádek přepíše
 * (`ON DUPLICATE KEY UPDATE`), nezaloží druhý (unikát `uq_pmr_employee_period`, 1105).
 *
 * ── Odložený řádek (`retired_at`, migrace 1718) ─────────────────────────────
 * Měsíc, který od ruční rekapitulace PŘEVZAL modul Mzdy, se tady nemaže — smazat
 * evidenci podle § 38j ZDP nejde — ale přestane platit. Všechna ČTENÍ proto
 * odložené řádky vynechávají: jinak by mzdový list ukazoval měsíc dvakrát
 * (jednou z rekapitulace, jednou z modulu) a kumulovaný vyměřovací základ
 * sociálního pojištění by se počítal ze dvou zdrojů najednou. Zápis odložení
 * dělá {@see retireForPeriod()}, zrušit ho umí jen nové zaúčtování téhož měsíce.
 */
final class PayrollMonthlyRecordRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @param array<string,int> $breakdown výstup PayrollCalculator::compute()
     * @param array{taxpayer:int,children:int,total:int} $credits
     */
    public function upsert(
        int $supplierId,
        int $employeeId,
        int $year,
        int $month,
        array $breakdown,
        array $credits,
        int $advanceTaxFinal,
        int $netFinal,
        ?int $journalEntryId,
    ): int {
        $pdo = $this->db->pdo();
        $json = (string) json_encode($breakdown, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $pdo->prepare(
            'INSERT INTO payroll_monthly_records
                (supplier_id, employee_id, year, month, gross, breakdown,
                 tax_credit_taxpayer, tax_credit_children, advance_tax_final, net_final, journal_entry_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                gross = VALUES(gross),
                breakdown = VALUES(breakdown),
                tax_credit_taxpayer = VALUES(tax_credit_taxpayer),
                tax_credit_children = VALUES(tax_credit_children),
                advance_tax_final = VALUES(advance_tax_final),
                net_final = VALUES(net_final),
                journal_entry_id = VALUES(journal_entry_id),
                -- Za řádkem zase stojí živé zaúčtování, takže odložení padá.
                retired_at = NULL,
                retired_by = NULL,
                retired_reason = NULL'
        )->execute([
            $supplierId,
            $employeeId,
            $year,
            $month,
            $breakdown['gross'],
            $json,
            $credits['taxpayer'],
            $credits['children'],
            $advanceTaxFinal,
            $netFinal,
            $journalEntryId,
        ]);

        $stmt = $pdo->prepare(
            'SELECT id FROM payroll_monthly_records WHERE employee_id = ? AND year = ? AND month = ?'
        );
        $stmt->execute([$employeeId, $year, $month]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Odloží mzdový list za období, které od ruční rekapitulace přebírá modul.
     *
     * Řádek se NEMAŽE — evidence podle § 38j ZDP zůstává i s tím, kdo a proč ji
     * odložil. Jen přestane platit: čtení ({@see listForYear()},
     * {@see socialBaseYearToDate()}, {@see grossForMonth()}) ho vynechávají,
     * takže se měsíc nepočítá dvakrát, jednou z rekapitulace a jednou z modulu.
     *
     * Volá se výhradně z
     * {@see \MyInvoice\Service\Payroll\PayrollLegacyRecapitulationService}, a to
     * až POTÉ, co je účetní zápis rekapitulace stornovaný — dokud stojí živé
     * zaúčtování, mzdový list k němu patří.
     *
     * @return int počet odložených řádků
     */
    public function retireForPeriod(
        int $supplierId,
        int $year,
        int $month,
        ?int $userId,
        string $reason,
    ): int {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new \InvalidArgumentException(
                'Odložení mzdového listu vyžaduje důvod (max. 500 znaků).',
            );
        }
        $stmt = $this->db->pdo()->prepare(
            'UPDATE payroll_monthly_records
                SET retired_at = NOW(), retired_by = ?, retired_reason = ?
              WHERE supplier_id = ? AND year = ? AND month = ?
                AND retired_at IS NULL'
        );
        $stmt->execute([$userId, $reason, $supplierId, $year, $month]);

        return $stmt->rowCount();
    }

    /**
     * Vyměřovací základ sociálního pojištění, který zaměstnanec v daném roce vyčerpal
     * PŘED zadaným měsícem — vstup pro strop § 15a z. 589/1992 (48× průměrná mzda).
     *
     * Bere `social_base` z uloženého rozpadu, ne hrubou mzdu: u měsíce, kde už se
     * krátilo, je základ nižší než hrubá a sčítat hrubé by strop posunulo. Starší
     * záznamy klíč nemají (vznikly před zavedením stropu), proto fallback na `gross`.
     */
    public function socialBaseYearToDate(int $supplierId, int $employeeId, int $year, int $beforeMonth): float
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT gross, breakdown
               FROM payroll_monthly_records
              WHERE supplier_id = ? AND employee_id = ? AND year = ? AND month < ?
                AND retired_at IS NULL'
        );
        $stmt->execute([$supplierId, $employeeId, $year, $beforeMonth]);

        $total = 0.0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $breakdown = json_decode((string) $r['breakdown'], true);
            $total += is_array($breakdown) && isset($breakdown['social_base'])
                ? (float) $breakdown['social_base']
                : (float) $r['gross'];
        }

        return $total;
    }

    /**
     * Hrubá mzda už zaevidovaná za daný měsíc, nebo null, když záznam neexistuje.
     *
     * Klíč `uq_pmr_employee_period` drží JEDEN záznam na zaměstnance a měsíc, takže
     * `upsert()` další zaúčtování téhož měsíce nepřičte, ale PŘEPÍŠE. U dohody
     * o provedení práce to není detail: § 6 odst. 4 ZDP testuje ÚHRN odměn od téhož
     * plátce za kalendářní měsíc, a kdo zaúčtuje druhou dohodu zvlášť, dostane
     * srážkovou daň z části místo zálohové z celku.
     */
    public function grossForMonth(int $supplierId, int $employeeId, int $year, int $month): ?float
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT gross FROM payroll_monthly_records
              WHERE supplier_id = ? AND employee_id = ? AND year = ? AND month = ?
                AND retired_at IS NULL'
        );
        $stmt->execute([$supplierId, $employeeId, $year, $month]);
        $gross = $stmt->fetchColumn();

        return $gross === false ? null : (float) $gross;
    }

    /**
     * Záznamy zaměstnance za rok, keyed měsícem (1–12).
     * @return array<int,array<string,mixed>>
     */
    public function listForYear(int $supplierId, int $employeeId, int $year): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT month, gross, breakdown, tax_credit_taxpayer, tax_credit_children,
                    advance_tax_final, net_final, journal_entry_id
               FROM payroll_monthly_records
              WHERE supplier_id = ? AND employee_id = ? AND year = ?
                AND retired_at IS NULL
              ORDER BY month ASC'
        );
        $stmt->execute([$supplierId, $employeeId, $year]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $month = (int) $r['month'];
            $out[$month] = [
                'month'               => $month,
                'gross'               => (int) $r['gross'],
                'breakdown'           => json_decode((string) $r['breakdown'], true) ?? [],
                'tax_credit_taxpayer' => (int) $r['tax_credit_taxpayer'],
                'tax_credit_children' => (int) $r['tax_credit_children'],
                'advance_tax_final'   => (int) $r['advance_tax_final'],
                'net_final'           => (int) $r['net_final'],
                'journal_entry_id'    => $r['journal_entry_id'] === null ? null : (int) $r['journal_entry_id'],
            ];
        }
        return $out;
    }
}
