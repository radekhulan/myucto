<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Ledger §74b ZDPH — korekce odpočtu u neuhrazených závazků dlužníka (audit §2.5).
 *
 * Drží jednotlivé pohyby (snížení/obnovení) per dotčené přijaté plnění a období.
 * "Čistá korekce" plnění = Σ reduction − Σ restoration; z ní {@see Section74bService}
 * počítá deltu potřebnou v běžném období (netting zvládne částečné úhrady/splátky/zápočty).
 */
final class Section74bCorrectionRepository
{
    /** Kolik id se vejde do jednoho `IN (…)` — viz {@see Section46CorrectionRepository::IN_CHUNK}. */
    private const IN_CHUNK = 1000;

    public function __construct(private readonly Connection $db) {}

    /**
     * Čistá dosud zaevidovaná korekce (Σ reduction − Σ restoration) per purchase_invoice_id
     * pro daného tenanta. Kladná hodnota = odpočet je aktuálně snížen o tuto částku.
     *
     * @param list<int> $invoiceIds
     * @return array<int,float> invoice_id => net corrected VAT
     */
    public function netCorrectedByInvoice(int $supplierId, array $invoiceIds): array
    {
        $invoiceIds = array_values(array_unique(array_map('intval', $invoiceIds)));
        if ($invoiceIds === []) {
            return [];
        }
        $out = [];
        // Po dávkách — jeden placeholder na doklad naráží na tvrdý strop MariaDB
        // (65 535 parametrů na příkaz), viz {@see Section46CorrectionRepository::IN_CHUNK}.
        foreach (array_chunk($invoiceIds, self::IN_CHUNK) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $sql =
                "SELECT purchase_invoice_id,
                        SUM(CASE WHEN movement = 'reduction' THEN vat_amount ELSE -vat_amount END) AS net_corrected
                   FROM vat_s74b_corrections
                  WHERE supplier_id = ? AND purchase_invoice_id IN ({$ph})
               GROUP BY purchase_invoice_id";
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute(array_merge([$supplierId], $chunk));
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $out[(int) $r['purchase_invoice_id']] = round((float) $r['net_corrected'], 2);
            }
        }
        return $out;
    }

    /**
     * Zapíše jeden pohyb korekce §74b (idempotence řeší volající — píše se jen nenulová delta).
     */
    public function recordMovement(
        int $supplierId,
        int $purchaseInvoiceId,
        int $year,
        int $month,
        string $movement,
        float $vatAmount,
        float $claimedDeductionVat,
        float $unpaidRatio,
        string $state,
        ?string $note,
        ?int $createdBy,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO vat_s74b_corrections
                (supplier_id, purchase_invoice_id, period_year, period_month, movement,
                 vat_amount, claimed_deduction_vat, unpaid_ratio, state, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $supplierId, $purchaseInvoiceId, $year, $month, $movement,
            round($vatAmount, 2), round($claimedDeductionVat, 2), round($unpaidRatio, 6),
            $state, $note, $createdBy,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Pohyby zaevidované v daném období (pro report / auditní náhled).
     *
     * @return list<array<string,mixed>>
     */
    public function movementsForPeriod(int $supplierId, int $year, int $month): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM vat_s74b_corrections
              WHERE supplier_id = ? AND period_year = ? AND period_month = ?
           ORDER BY purchase_invoice_id, id"
        );
        $stmt->execute([$supplierId, $year, $month]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
