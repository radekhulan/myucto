<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Uhrazená záloha, kterou konečná přijatá faktura odečítá (`advance_paid_amount`,
 * z něj generovaný `amount_to_pay`).
 *
 * Částka se bere z evidovaných úhrad zálohy (zaúčtované bankovní párování a pokladna),
 * u zálohy uhrazené ve stavu „uhrazeno" bez evidované úhrady z celé částky zálohy.
 * Nejvýš do výše konečné faktury.
 *
 * Doplňuje se při propojení faktury se zálohou a znovu, když se záloha uhradí až
 * po propojení ({@see fillFinalsOfAdvance()}). Jinak by konečná faktura dál chtěla
 * doplatit i zálohu, přestože je účetně vyrovnaná (issue #313). Nenulová částka se
 * nepřepisuje: může jít o ruční korekci z úpravy faktury.
 */
final class LinkedAdvanceAmount
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Částka zálohy pro konečnou fakturu.
     *
     * @param array<string,mixed> $advance řádek zálohy (id, status, total_with_vat, paid_amount_invoice_ccy)
     */
    public function forFinal(int $supplierId, array $advance, float $finalTotal): float
    {
        $paid = $this->paidAdvanceAmount((int) $advance['id'], $supplierId);
        $paidInInvoiceCurrency = (float) ($advance['paid_amount_invoice_ccy'] ?? 0);
        $base = $paidInInvoiceCurrency > 0.0
            ? $paidInInvoiceCurrency
            : ($paid > 0.0
                ? $paid
                : (($advance['status'] ?? '') === 'paid' ? (float) $advance['total_with_vat'] : 0.0));
        return min($base, $finalTotal);
    }

    /**
     * Doplní uhrazenou zálohu konečným fakturám zálohy, které ji zatím nemají (0).
     * Vrací ID upravených faktur.
     *
     * @return list<int>
     */
    public function fillFinalsOfAdvance(int $supplierId, int $advanceId): array
    {
        $pdo = $this->db->pdo();
        $advanceStmt = $pdo->prepare(
            "SELECT id, status, total_with_vat, paid_amount_invoice_ccy FROM purchase_invoices
              WHERE id = ? AND supplier_id = ? AND document_kind IN ('advance', 'tax_document') AND status <> 'cancelled'"
        );
        $advanceStmt->execute([$advanceId, $supplierId]);
        $advance = $advanceStmt->fetch(PDO::FETCH_ASSOC);
        if ($advance === false) {
            return [];
        }
        $finals = $pdo->prepare(
            "SELECT id, total_with_vat FROM purchase_invoices
              WHERE supplier_id = ? AND advance_purchase_invoice_id = ? AND document_kind = 'invoice'
                AND status IN ('received', 'booked', 'paid') AND advance_paid_amount = 0
              ORDER BY id"
        );
        $finals->execute([$supplierId, $advanceId]);
        $update = $pdo->prepare(
            'UPDATE purchase_invoices SET advance_paid_amount = ? WHERE id = ? AND supplier_id = ? AND advance_paid_amount = 0'
        );
        $filled = [];
        foreach ($finals->fetchAll(PDO::FETCH_ASSOC) ?: [] as $final) {
            $amount = round($this->forFinal($supplierId, $advance, (float) $final['total_with_vat']), 2);
            if ($amount <= 0.0) {
                continue;
            }
            $update->execute([$amount, (int) $final['id'], $supplierId]);
            if ($update->rowCount() > 0) {
                $filled[] = (int) $final['id'];
            }
        }
        return $filled;
    }

    /**
     * Konečné faktury s nulovou uhrazenou zálohou, ačkoli jejich záloha už uhrazená je
     * (záloha spárovaná dřív, než dopočet při změně úhrady existoval). Převzaté doklady
     * se nemění. Pro auto-backfill v migrate.php.
     */
    public function pending(): int
    {
        return count($this->candidates(null));
    }

    /**
     * Doplní uhrazenou zálohu existujícím propojením. Dry-run nic nezapíše. Stav „uhrazeno"
     * konečných faktur pak srovná {@see AdvanceCoveredPaidStatus::syncFinalsOfAdvance()}.
     *
     * @return list<array{supplier_id:int, final_id:int, advance_id:int, amount:float}>
     */
    public function backfill(?int $supplierId, bool $apply): array
    {
        $rows = [];
        $status = new AdvanceCoveredPaidStatus($this->db);
        foreach ($this->candidates($supplierId) as $candidate) {
            $rows[] = $candidate;
            if (!$apply) continue;
            $this->fillFinalsOfAdvance($candidate['supplier_id'], $candidate['advance_id']);
            $status->syncFinalsOfAdvance($candidate['supplier_id'], $candidate['advance_id'], allowRevert: false);
        }
        return $rows;
    }

    /** @return list<array{supplier_id:int, final_id:int, advance_id:int, amount:float}> */
    private function candidates(?int $supplierId): array
    {
        $taken = new \MyInvoice\Service\Accounting\TakenOverRecord($this->db);
        $stmt = $this->db->pdo()->prepare(
            "SELECT f.supplier_id, f.id AS final_id, f.total_with_vat,
                    a.id, a.status, a.total_with_vat AS advance_total, a.paid_amount_invoice_ccy
               FROM purchase_invoices f
               JOIN purchase_invoices a ON a.id = f.advance_purchase_invoice_id AND a.supplier_id = f.supplier_id
              WHERE f.document_kind = 'invoice' AND f.status IN ('received', 'booked', 'paid')
                AND f.advance_paid_amount = 0
                AND a.document_kind IN ('advance', 'tax_document') AND a.status <> 'cancelled'"
            . ($supplierId !== null ? ' AND f.supplier_id = ?' : '')
            . ' ORDER BY f.supplier_id, f.id'
        );
        $stmt->execute($supplierId !== null ? [$supplierId] : []);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $sid = (int) $r['supplier_id'];
            if ($taken->isDocument($sid, 'purchase_invoice', (int) $r['final_id'])) continue;
            $amount = round($this->forFinal($sid, [
                'id' => (int) $r['id'], 'status' => $r['status'], 'total_with_vat' => $r['advance_total'],
                'paid_amount_invoice_ccy' => $r['paid_amount_invoice_ccy'],
            ], (float) $r['total_with_vat']), 2);
            if ($amount <= 0.0) continue;
            $out[] = ['supplier_id' => $sid, 'final_id' => (int) $r['final_id'], 'advance_id' => (int) $r['id'], 'amount' => $amount];
        }
        return $out;
    }

    /** Zaúčtované úhrady zálohy z banky a pokladny v měně zálohy. */
    public function paidAdvanceAmount(int $advanceId, int $supplierId): float
    {
        $bank = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(pm.amount), 0)
               FROM payment_matches pm
               JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
               JOIN bank_statements bs ON bs.id = bt.statement_id
               JOIN purchase_invoices pi ON pi.id = pm.purchase_invoice_id
               JOIN currencies cur ON cur.id = pi.currency_id
               JOIN journal_entries je
                 ON je.supplier_id = pm.supplier_id AND je.source_type = 'bank'
                AND je.source_id = pm.bank_transaction_id AND je.reversed_by IS NULL
              WHERE pm.supplier_id = ? AND pm.purchase_invoice_id = ?
                AND UPPER(COALESCE(bt.currency, bs.currency, 'CZK')) = cur.code"
        );
        $bank->execute([$supplierId, $advanceId]);

        $cash = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(cd.total_amount), 0)
               FROM cash_documents cd
               JOIN purchase_invoices pi ON pi.id = cd.purchase_invoice_id
               JOIN currencies cur ON cur.id = pi.currency_id
               JOIN journal_entries je
                 ON je.supplier_id = cd.supplier_id AND je.source_type = 'cash'
                AND je.source_id = cd.id AND je.reversed_by IS NULL
              WHERE cd.supplier_id = ? AND cd.purchase_invoice_id = ?
                AND UPPER(cd.currency_code) = cur.code"
        );
        $cash->execute([$supplierId, $advanceId]);

        return round((float) $bank->fetchColumn() + (float) $cash->fetchColumn(), 2);
    }
}
