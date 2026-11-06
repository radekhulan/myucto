<?php

declare(strict_types=1);

namespace MyInvoice\Service\TaxEvidence;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockReportService;
use MyInvoice\Repository\TaxConstantsRepository;

/**
 * Podklady pro přechodový můstek §7b → §24 ZDP (příloha č. 3 zákona o daních z
 * příjmů) při přepnutí `accounting_mode` z `tax_evidence` na `double_entry`
 * (Epic DE, audit 2026-07 G7). READ-ONLY: dodá seznam neuhrazených vydaných
 * (pohledávky) a přijatých (závazky) faktur k danému datu a jejich součty v Kč
 * + orientační hodnotu skladu, pokud má firma modul Sklad zapnutý.
 *
 * Vlastní úpravu základu daně a její zanesení do přiznání dělá účetní ručně —
 * systém sestavu nezaúčtovává ani sám nijak neuplatňuje.
 *
 * Predikáty vycházejí z {@see \MyInvoice\Service\Crm\CrmAggregationService}
 * (agingReceivables/agingPayables), ale na rozdíl od nich rekonstruují stav
 * ÚHRADY K DATU `$asOf` (z `invoice_payments.paid_on` / `purchase_invoices.paid_at`),
 * ne aktuální `status`/`paid_total` — jinak by sestava spuštěná až po přechodu
 * vynechávala doklady uhrazené mezitím.
 */
final class TransitionReportService
{
    public function __construct(
        private readonly Connection $db,
        private readonly StockReportService $stock,
        private readonly TaxConstantsRepository $constants,
    ) {}

    /**
     * @return array{
     *   as_of: string,
     *   receivables: list<array{id:int,doc_no:string,partner:string,currency:string,amount:float,amount_czk:float,issue_date:string,due_date:string}>,
     *   payables: list<array{id:int,doc_no:string,partner:string,currency:string,amount:float,amount_czk:float,issue_date:string,due_date:string}>,
     *   totals: array{receivables_czk:float, payables_czk:float, net_adjustment_czk:float},
     *   inventory: array{enabled:bool, value_czk:?float, note:string}
     * }
     */
    public function build(int $supplierId, string $asOf, string $direction = 'tax_to_accounting'): array
    {
        $receivables = $this->unpaidReceivables($supplierId, $asOf);
        $payables    = $this->unpaidPayables($supplierId, $asOf);
        $advancesPaid = $this->advancesPaid($supplierId, $asOf);
        $advancesReceived = $this->advancesReceived($supplierId, $asOf);
        $inventory = $this->inventory($supplierId, $asOf, $direction);

        $receivablesCzk = round((float) array_sum(array_column($receivables, 'amount_czk')), 2);
        $payablesCzk    = round((float) array_sum(array_column($payables, 'amount_czk')), 2);
        $advancesPaidCzk = round((float) array_sum(array_column($advancesPaid, 'amount_czk')), 2);
        $advancesReceivedCzk = round((float) array_sum(array_column($advancesReceived, 'amount_czk')), 2);
        $inventoryCzk = round((float) ($inventory['value_czk'] ?? 0), 2);
        $maxYears = (int) ($this->constants->forYear((int) substr($asOf, 0, 4))['transition_receivables_max_years'] ?? 9);

        $toAccounting = $direction === 'tax_to_accounting';
        $netAdjustment = $toAccounting
            ? $receivablesCzk + $inventoryCzk + $advancesPaidCzk - $payablesCzk - $advancesReceivedCzk
            : $payablesCzk + $advancesReceivedCzk - $receivablesCzk - $advancesPaidCzk - $inventoryCzk;

        $result = [
            'as_of'       => $asOf,
            'direction'   => $direction,
            'legal_basis' => $toAccounting ? 'Příloha č. 3 ZDP' : 'Příloha č. 2 ZDP',
            'receivables' => $receivables,
            'payables'    => $payables,
            'advances_paid' => $advancesPaid,
            'advances_received' => $advancesReceived,
            'totals'      => [
                'receivables_czk'    => $receivablesCzk,
                'payables_czk'       => $payablesCzk,
                'advances_paid_czk' => $advancesPaidCzk,
                'advances_received_czk' => $advancesReceivedCzk,
                'inventory_czk' => $inventoryCzk,
                'net_adjustment_czk' => round($netAdjustment, 2),
            ],
            'inventory' => $inventory,
            'valuables' => ['value_czk' => null, 'note' => 'Ceniny k datu přechodu doplňte ručně.'],
        ];

        if ($toAccounting) {
            $result['receivables_spread'] = [
                'max_years' => $maxYears,
                'annual_czk' => $maxYears > 0 ? round($receivablesCzk / $maxYears, 2) : $receivablesCzk,
                'note' => 'Pohledávky lze podle § 23 odst. 14 ZDP rozložit nejvýše do uvedeného počtu zdaňovacích období.',
            ];
        }

        return $result;
    }

    /**
     * Pohledávky NEUHRAZENÉ K DATU `$asOf` (ne k dnešku) — zůstatek dopočten
     * z `invoice_payments.paid_on <= $asOf`, ne z aktuálního `i.paid_total`/`i.status`,
     * aby sestava spuštěná měsíce po přechodu neztratila fakturu uhrazenou až PO
     * `$asOf` (viz review Fáze G — status-based filtr by ji dnes vynechal jako
     * "paid" a podhodnotil by úpravu základu daně).
     *
     * @return list<array{id:int,doc_no:string,partner:string,currency:string,amount:float,amount_czk:float,issue_date:string,due_date:string}>
     */
    private function unpaidReceivables(int $supplierId, string $asOf): array
    {
        // paid_before: faktury s aspoň jedním řádkem invoice_payments (běžný
        // případ, vč. backfillu migrace 0108) počítají zaplaceno k $asOf ze
        // součtu plateb do toho data; faktury BEZ žádného řádku (legacy okrajový
        // případ) padnou na fallback dle status='paid'+paid_at, ať se nikdy
        // netiše nezapočtou jako neuhrazené, když ve skutečnosti zaplacené jsou.
        $paidBefore = "
            IF(EXISTS (SELECT 1 FROM invoice_payments ip WHERE ip.invoice_id = i.id),
               COALESCE((SELECT SUM(ip.amount) FROM invoice_payments ip
                          WHERE ip.invoice_id = i.id AND ip.paid_on <= ?), 0),
               IF(i.status = 'paid' AND i.paid_at IS NOT NULL AND i.paid_at <= ?, i.amount_to_pay, 0))
        ";
        $sql = "
            SELECT t.id, t.doc_no, t.partner, t.currency, t.exchange_rate, t.issue_date, t.due_date,
                   (t.amount_to_pay - t.paid_before) AS amount
              FROM (
                SELECT i.id, COALESCE(i.varsymbol, '') AS doc_no, COALESCE(cl.company_name, '') AS partner,
                       COALESCE(cur.code, 'CZK') AS currency,
                       COALESCE(i.amount_to_pay, 0) AS amount_to_pay,
                       {$paidBefore} AS paid_before,
                       i.exchange_rate,
                       i.issue_date, i.due_date, i.invoice_type
                  FROM invoices i
             LEFT JOIN currencies cur ON cur.id = i.currency_id
             LEFT JOIN clients cl    ON cl.id = i.client_id
                 WHERE i.supplier_id = ?
                   AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                   AND i.invoice_type NOT IN ('proforma','rent_calendar','advance_calendar')
                   AND i.issue_date <= ?
              ) t
             WHERE t.invoice_type NOT IN ('invoice', 'proforma', 'tax_document')
                OR t.amount_to_pay - t.paid_before > 0
          ORDER BY t.issue_date, t.id
        ";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$asOf, $asOf, $supplierId, $asOf]);
        return $this->mapRows($stmt->fetchAll(\PDO::FETCH_ASSOC), $asOf);
    }

    /**
     * Závazky NEUHRAZENÉ K DATU `$asOf` — PF nemá historii dílčích úhrad (na
     * rozdíl od faktur), ale má `paid_at` datum úhrady celé PF, takže stav k
     * `$asOf` lze rekonstruovat: `status='paid'` se počítá jako neuhrazený
     * zpětně k `$asOf`, pokud k úhradě došlo AŽ PO `$asOf`.
     *
     * @return list<array{id:int,doc_no:string,partner:string,currency:string,amount:float,amount_czk:float,issue_date:string,due_date:string}>
     */
    private function unpaidPayables(int $supplierId, string $asOf): array
    {
        $sql = "
            SELECT pi.id, COALESCE(pi.vendor_invoice_number, '') AS doc_no, COALESCE(v.company_name, '') AS partner,
                   COALESCE(cur.code, 'CZK') AS currency,
                   GREATEST(COALESCE(pi.amount_to_pay, pi.total_with_vat, 0) - pi.settled_outside_amount -
                     COALESCE((SELECT SUM(pm.amount) FROM payment_matches pm
                                JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
                               WHERE pm.purchase_invoice_id = pi.id AND bt.posted_at <= ?), 0), 0) AS amount,
                   pi.exchange_rate,
                   pi.issue_date, pi.due_date
              FROM purchase_invoices pi
         LEFT JOIN currencies cur ON cur.id = pi.currency_id
         LEFT JOIN clients v     ON v.id = pi.vendor_id
             WHERE pi.supplier_id = ?
               AND pi.status IN ('received', 'booked', 'paid')
               AND pi.document_kind NOT IN ('advance', 'tax_document')
               AND (pi.status != 'paid' OR pi.paid_at IS NULL OR pi.paid_at > ?)
               AND pi.issue_date <= ?
          ORDER BY pi.issue_date, pi.id
        ";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$asOf, $supplierId, $asOf, $asOf]);
        return $this->mapRows($stmt->fetchAll(\PDO::FETCH_ASSOC), $asOf);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array{id:int,doc_no:string,partner:string,currency:string,amount:float,amount_czk:float,issue_date:string,due_date:string}>
     */
    private function mapRows(array $rows, string $asOf): array
    {
        return array_map(function (array $r) use ($asOf): array {
            $currency = (string) $r['currency'];
            $amount   = round((float) $r['amount'], 2);
            $fallback = $r['exchange_rate'] === null ? null : (float) $r['exchange_rate'];
            $rate     = $currency === 'CZK' ? 1.0 : $this->rateAt($currency, $asOf, $fallback);
            return [
                'id'         => (int) $r['id'],
                'doc_no'     => (string) $r['doc_no'],
                'partner'    => (string) $r['partner'],
                'currency'   => $currency,
                'amount'     => $amount,
                'amount_czk' => round($amount * $rate, 2),
                'issue_date' => (string) $r['issue_date'],
                'due_date'   => (string) $r['due_date'],
            ];
        }, $rows);
    }

    private function rateAt(string $currency, string $asOf, ?float $fallback): float
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT rate FROM exchange_rates WHERE currency_code = ? AND rate_date <= ? ORDER BY rate_date DESC LIMIT 1'
        );
        $stmt->execute([$currency, $asOf]);
        $rate = $stmt->fetchColumn();
        $resolved = $rate === false ? $fallback : (float) $rate;
        if ($resolved === null || $resolved <= 0.0) {
            throw new \RuntimeException('Pro cizoměnovou položku přechodové sestavy chybí kurz.');
        }
        return $resolved;
    }

    private function advancesPaid(int $supplierId, string $asOf): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT pi.id, COALESCE(pi.vendor_invoice_number,'') doc_no, COALESCE(v.company_name,'') partner,
                    COALESCE(c.code,'CZK') currency, pi.exchange_rate,
                    pi.issue_date, pi.due_date,
                    CASE WHEN COUNT(bt.id) > 0 THEN SUM(CASE WHEN bt.id IS NOT NULL THEN pm.amount ELSE 0 END)
                         WHEN pi.paid_at IS NOT NULL AND pi.paid_at <= ?
                         THEN COALESCE(pi.amount_to_pay, pi.total_with_vat, 0)
                         ELSE 0 END amount
               FROM purchase_invoices pi
          LEFT JOIN clients v ON v.id=pi.vendor_id LEFT JOIN currencies c ON c.id=pi.currency_id
          LEFT JOIN payment_matches pm ON pm.purchase_invoice_id=pi.id
          LEFT JOIN bank_transactions bt ON bt.id=pm.bank_transaction_id AND bt.posted_at <= ?
              WHERE pi.supplier_id=? AND pi.document_kind='advance' AND pi.is_fixed_asset=0
                AND pi.issue_date<=? AND NOT EXISTS (SELECT 1 FROM purchase_invoices f WHERE f.advance_purchase_invoice_id=pi.id)
           GROUP BY pi.id HAVING amount > 0"
        );
        $stmt->execute([$asOf, $asOf, $supplierId, $asOf]);
        return $this->mapRows($stmt->fetchAll(\PDO::FETCH_ASSOC), $asOf);
    }

    private function advancesReceived(int $supplierId, string $asOf): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT i.id, COALESCE(i.varsymbol,'') doc_no, COALESCE(cl.company_name,'') partner,
                    COALESCE(c.code,'CZK') currency, i.exchange_rate,
                    i.issue_date, i.due_date, COALESCE(SUM(ip.amount),0) amount
               FROM invoices i JOIN invoice_payments ip ON ip.invoice_id=i.id AND ip.paid_on<=?
          LEFT JOIN clients cl ON cl.id=i.client_id LEFT JOIN currencies c ON c.id=i.currency_id
              WHERE i.supplier_id=? AND i.invoice_type='proforma' AND i.issue_date<=?
                AND NOT EXISTS (SELECT 1 FROM invoices f WHERE f.parent_invoice_id=i.id AND f.invoice_type='invoice')
           GROUP BY i.id HAVING amount > 0"
        );
        $stmt->execute([$asOf, $supplierId, $asOf]);
        return $this->mapRows($stmt->fetchAll(\PDO::FETCH_ASSOC), $asOf);
    }

    /**
     * @return array{enabled:bool, value_czk:?float, note:string}
     */
    private function inventory(int $supplierId, string $asOf, string $direction): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT stock_enabled FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $enabled = (bool) $stmt->fetchColumn();

        if (!$enabled) {
            return [
                'enabled'   => false,
                'value_czk' => null,
                'note'      => 'Modul Sklad není pro tuto firmu zapnutý — pokud evidujete zásoby ('
                    . ($direction === 'tax_to_accounting' ? 'příloha č. 3 ZDP' : 'příloha č. 2 ZDP')
                    . '), doplňte jejich hodnotu k datu přechodu ručně.',
            ];
        }

        try {
            $valuation = $this->stock->valuation($supplierId, $asOf, []);
        } catch (StockException $e) {
            return [
                'enabled'   => true,
                'value_czk' => null,
                'note'      => 'Ocenění zásob k datu přechodu se nepodařilo sestavit (' . $e->getMessage() . ') — doplňte hodnotu ručně.',
            ];
        }

        return [
            'enabled'   => true,
            'value_czk' => (float) ($valuation['totals']['value_total'] ?? 0.0),
            'note'      => 'Automatické ocenění skladu k datu přechodu metodou váženého průměru ('
                . ($direction === 'tax_to_accounting' ? 'příloha č. 3 ZDP' : 'příloha č. 2 ZDP')
                . ') — porovnejte s fyzickou inventurou.',
        ];
    }
}
