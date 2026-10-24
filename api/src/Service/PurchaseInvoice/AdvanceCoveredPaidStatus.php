<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\DocumentPostingGroup;
use MyInvoice\Service\Accounting\TakenOverRecord;
use MyInvoice\Service\Bank\FxPaymentSettlement;
use MyInvoice\Support\Sql\PurchaseSettledExpr;
use PDO;

/**
 * Stav „uhrazeno" přijatých dokladů, který plyne z evidovaných úhrad a ze zálohy.
 *
 * Týká se JEN záloh (`document_kind = 'advance'`) a jejich konečných faktur navázaných
 * přes `advance_purchase_invoice_id`. Běžná faktura se nemění: ruční „uhrazeno" i ruční
 * datum úhrady zůstávají a zrušení párování je nevrací.
 *
 * Dvě pravidla, obě odvozená od téže evidence úhrad ({@see PurchaseSettledExpr}):
 *
 *   1. Záloha ve stavu `paid`, kterou evidované úhrady (banka, pokladna, zápočty) kryjí
 *      celou, má `paid_at` = datum POSLEDNÍ z nich. Ruční „Označit jako uhrazené" s jiným
 *      datem se spárováním platby přepíše skutečností; zrušení párování pak zálohu podle
 *      data úhrady pozná a vrátí ji mezi otevřené (BankTransactionReleaseService).
 *   2. Konečná faktura, kterou záloha kryje CELOU (`amount_to_pay` = 0 po odečtení zálohy)
 *      a která nemá vlastní úhradu, je uhrazená právě tehdy, když je uhrazená její záloha,
 *      a to k datu úhrady zálohy. Přestane-li být záloha uhrazená (zrušené párování,
 *      storno pokladny), vrací se konečná faktura do stavu před úhradou (zaúčtovaná →
 *      `booked`, jinak `received`), stejně jako po zrušení vlastní úhrady.
 *
 * Volá se ze stejných míst, která mění úhradu zálohy a dorovnávají zúčtování
 * ({@see \MyInvoice\Service\Accounting\AdvanceSettlementSync}): bankovní párování a jeho
 * zrušení, pokladní úhrada, její storno a smazání. Běží v transakci volajícího.
 *
 * TODO: zápočty zálohy (OffsetService, InvoiceSettlementService) stav konečné faktury
 * zatím nespouštějí.
 * TODO: vydaná strana — vyúčtovací faktura krytá proformou je `paid` od vystavení
 * a přepočet z plateb ji záměrně nerevertuje (InvoicePaymentService::recomputeLocked),
 * takže po zrušení platby proformy zůstane uhrazená.
 */
final class AdvanceCoveredPaidStatus
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Úhrady daných přijatých dokladů se změnily: srovná `paid_at` podle úhrad a stav
     * konečných faktur jejich záloh (případně stav dokladu samého, je-li konečnou fakturou).
     *
     * @param list<int> $purchaseInvoiceIds
     */
    public function afterPaymentsChanged(int $supplierId, array $purchaseInvoiceIds): void
    {
        foreach (array_values(array_unique(array_map('intval', $purchaseInvoiceIds))) as $id) {
            if ($id <= 0) {
                continue;
            }
            $this->alignPaidAtToPayments($supplierId, $id);
            $doc = $this->document($supplierId, $id);
            if ($doc === null) {
                continue;
            }
            if ((string) $doc['document_kind'] === 'advance') {
                $this->syncFinalsOfAdvance($supplierId, $id);
            } elseif ($doc['advance_purchase_invoice_id'] !== null) {
                $this->syncFinal($supplierId, $id, true);
            }
        }
    }

    /**
     * Pravidlo 1. Vrací původní a nové datum, když se měnilo, jinak null.
     *
     * @return array{from:?string, to:string}|null
     */
    public function alignPaidAtToPayments(int $supplierId, int $id, bool $apply = true): ?array
    {
        $settled = PurchaseSettledExpr::settled('pi');
        $stmt = $this->db->pdo()->prepare(
            "SELECT pi.status, pi.paid_at, pi.document_kind, pi.amount_to_pay, ({$settled}) AS settled
               FROM purchase_invoices pi
              WHERE pi.id = ? AND pi.supplier_id = ?"
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false
            || (string) $row['status'] !== 'paid'
            || (string) $row['document_kind'] !== 'advance'
            || (float) $row['amount_to_pay'] <= 0.005
            || (float) $row['amount_to_pay'] - (float) $row['settled'] > FxPaymentSettlement::AMOUNT_TOLERANCE) {
            return null;
        }
        $last = $this->lastPaymentDate($supplierId, $id);
        $current = $row['paid_at'] !== null ? substr((string) $row['paid_at'], 0, 10) : null;
        if ($last === null || $last === $current) {
            return null;
        }
        if ($apply) {
            $this->db->pdo()->prepare('UPDATE purchase_invoices SET paid_at = ? WHERE id = ? AND supplier_id = ?')
                ->execute([$last, $id, $supplierId]);
        }
        return ['from' => $current, 'to' => $last];
    }

    /**
     * Pravidlo 2 pro všechny konečné faktury zálohy.
     *
     * @return list<array{final_id:int, from_status:string, to_status:string, from_paid_at:?string, to_paid_at:?string}>
     */
    public function syncFinalsOfAdvance(int $supplierId, int $advanceId, bool $allowRevert = true, bool $apply = true): array
    {
        $changes = [];
        foreach ($this->finalIds($supplierId, $advanceId) as $finalId) {
            $change = $this->syncFinal($supplierId, $finalId, $allowRevert, $apply);
            if ($change !== null) {
                $changes[] = $change;
            }
        }
        return $changes;
    }

    /**
     * Pravidlo 2 pro jednu konečnou fakturu. Vrací změnu, nebo null.
     *
     * @return array{final_id:int, from_status:string, to_status:string, from_paid_at:?string, to_paid_at:?string}|null
     */
    public function syncFinal(
        int $supplierId,
        int $finalId,
        bool $allowRevert = true,
        bool $apply = true,
        ?string $advancePaidAtOverride = null,
        bool $currentRead = false,
    ): ?array {
        $settled = PurchaseSettledExpr::settled('f', currentRead: $currentRead);
        $stmt = $this->db->pdo()->prepare(
            "SELECT f.status, f.paid_at, f.amount_to_pay, f.total_with_vat, ({$settled}) AS own_settled,
                    a.status AS advance_status, a.paid_at AS advance_paid_at
               FROM purchase_invoices f
               JOIN purchase_invoices a
                 ON a.id = f.advance_purchase_invoice_id AND a.supplier_id = f.supplier_id
                AND a.document_kind = 'advance'
              WHERE f.id = ? AND f.supplier_id = ? AND f.document_kind = 'invoice'
                AND f.status IN ('received', 'booked', 'paid')" . ($currentRead ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$finalId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        // Jen faktura krytá zálohou CELÁ a bez vlastní úhrady: zbytek k doplacení nebo
        // úhrada přímo na faktuře o stavu rozhodují samy (párování, pokladna).
        if ($row === false
            || abs((float) $row['amount_to_pay']) > 0.005
            || (float) $row['total_with_vat'] <= 0.0
            || abs((float) $row['own_settled']) >= 0.005) {
            return null;
        }

        $status = (string) $row['status'];
        $paidAt = $row['paid_at'] !== null ? substr((string) $row['paid_at'], 0, 10) : null;
        $advancePaidAt = $advancePaidAtOverride
            ?? ($row['advance_paid_at'] !== null ? substr((string) $row['advance_paid_at'], 0, 10) : null);

        if ((string) $row['advance_status'] === 'paid' && $advancePaidAt !== null) {
            if ($status === 'paid' && $paidAt === $advancePaidAt) {
                return null;
            }
            if ($apply) {
                $this->db->pdo()->prepare(
                    "UPDATE purchase_invoices SET status = 'paid', paid_at = ? WHERE id = ? AND supplier_id = ?"
                )->execute([$advancePaidAt, $finalId, $supplierId]);
            }
            return ['final_id' => $finalId, 'from_status' => $status, 'to_status' => 'paid',
                'from_paid_at' => $paidAt, 'to_paid_at' => $advancePaidAt];
        }

        if (!$allowRevert || $status !== 'paid') {
            return null;
        }
        $restored = $this->hasLivePosting($supplierId, $finalId) ? 'booked' : 'received';
        if ($apply) {
            $this->db->pdo()->prepare(
                "UPDATE purchase_invoices SET status = ?, paid_at = NULL WHERE id = ? AND supplier_id = ? AND status = 'paid'"
            )->execute([$restored, $finalId, $supplierId]);
        }
        return ['final_id' => $finalId, 'from_status' => $status, 'to_status' => $restored,
            'from_paid_at' => $paidAt, 'to_paid_at' => null];
    }

    /**
     * Srovnání existujících dat (CLI). Jen směr k „uhrazeno": zálohy s ručním datem úhrady
     * podle plateb a konečné faktury kryté uhrazenou zálohou. Konečné faktury `paid` při
     * neuhrazené záloze se jen vypíšou — mohou být převzaté nebo ručně potvrzené a vrátit je
     * do otevřených má jen skutečná změna úhrady zálohy. Převzaté doklady se nemění.
     *
     * @return list<array<string,mixed>>
     */
    public function backfill(?int $supplierId, bool $apply): array
    {
        $taken = new TakenOverRecord($this->db);
        $rows = [];
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.supplier_id, a.id FROM purchase_invoices a
              WHERE a.document_kind = 'advance' AND a.status <> 'cancelled'
                AND EXISTS (SELECT 1 FROM purchase_invoices f
                             WHERE f.supplier_id = a.supplier_id AND f.advance_purchase_invoice_id = a.id
                               AND f.document_kind = 'invoice' AND f.status IN ('received', 'booked', 'paid'))"
            . ($supplierId !== null ? ' AND a.supplier_id = ?' : '')
            . ' ORDER BY a.supplier_id, a.id'
        );
        $stmt->execute($supplierId !== null ? [$supplierId] : []);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $a) {
            $sid = (int) $a['supplier_id'];
            $advanceId = (int) $a['id'];
            if ($taken->isDocument($sid, 'purchase_invoice', $advanceId)) {
                continue;
            }
            $aligned = $this->alignPaidAtToPayments($sid, $advanceId, $apply);
            if ($aligned !== null) {
                $rows[] = ['kind' => 'advance_paid_at', 'supplier_id' => $sid, 'id' => $advanceId] + $aligned;
            }
            foreach ($this->finalIds($sid, $advanceId) as $finalId) {
                if ($taken->isDocument($sid, 'purchase_invoice', $finalId)) {
                    continue;
                }
                // Dry-run nové datum zálohy nezapsal — konečná faktura ho musí dostat i tak.
                $change = $this->syncFinal($sid, $finalId, false, $apply, $apply ? null : ($aligned['to'] ?? null));
                if ($change !== null) {
                    $rows[] = ['kind' => 'final_paid', 'supplier_id' => $sid, 'id' => $finalId, 'advance_id' => $advanceId] + $change;
                    continue;
                }
                $revert = $this->syncFinal($sid, $finalId, true, false);
                if ($revert !== null) {
                    $rows[] = ['kind' => 'final_paid_unpaid_advance', 'supplier_id' => $sid, 'id' => $finalId, 'advance_id' => $advanceId] + $revert;
                }
            }
        }
        return $rows;
    }

    /** Datum poslední evidované úhrady přijatého dokladu ze všech kanálů PurchaseSettledExpr. */
    public function lastPaymentDate(int $supplierId, int $id): ?string
    {
        return $this->lastPaymentDates($supplierId, [$id])[$id] ?? null;
    }

    /**
     * Stejný zdroj data pro jednotlivý doklad i rozdělenou bankovní úhradu. Datum úhrady
     * kladného dokladu nese jen odchozí platba: příchozí vratka úhradu snižuje, stejně
     * jako pokladní příjmový doklad, který se tu nepočítá.
     *
     * @param list<int> $ids
     * @return array<int,string>
     */
    public function lastPaymentDates(int $supplierId, array $ids, bool $currentRead = false): array
    {
        $dates = [];
        $lock = $currentRead ? ' FOR UPDATE' : '';
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        foreach (array_chunk($ids, 200) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT doc_id, MAX(d) AS paid_on FROM (
                    (SELECT pm.purchase_invoice_id AS doc_id, DATE(bt.posted_at) AS d
                      FROM payment_matches pm
                      JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id AND bt.amount < 0
                     WHERE pm.supplier_id = ? AND pm.purchase_invoice_id IN ($placeholders){$lock})
                    UNION ALL
                    (SELECT cd.purchase_invoice_id, cd.issue_date FROM cash_documents cd
                     WHERE cd.supplier_id = ? AND cd.purchase_invoice_id IN ($placeholders)
                       AND cd.status = 'posted' AND cd.doc_type = 'out'{$lock})
                    UNION ALL
                    (SELECT oi.doc_id, oa.agreement_date FROM offset_agreement_items oi
                      JOIN offset_agreements oa ON oa.id = oi.agreement_id AND oa.status = 'confirmed'
                     WHERE oi.supplier_id = ? AND oi.doc_type = 'purchase_invoice' AND oi.doc_id IN ($placeholders){$lock})
                    UNION ALL
                    (SELECT s.doc_id, s.settled_on FROM invoice_settlements s
                     WHERE s.supplier_id = ? AND s.doc_type = 'purchase_invoice' AND s.doc_id IN ($placeholders)
                       AND s.status = 'confirmed'{$lock})
                    UNION ALL
                    (SELECT cno.invoice_id, cno.offset_on FROM credit_note_offsets cno
                     WHERE cno.supplier_id = ? AND cno.doc_type = 'purchase_invoice' AND cno.invoice_id IN ($placeholders){$lock})
                 ) x GROUP BY doc_id"
            );
            $params = [];
            for ($i = 0; $i < 5; $i++) array_push($params, $supplierId, ...$chunk);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['paid_on'] !== null) $dates[(int) $row['doc_id']] = substr((string) $row['paid_on'], 0, 10);
            }
        }
        return $dates;
    }

    /** @return array{document_kind:string, advance_purchase_invoice_id:?int}|null */
    private function document(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT document_kind, advance_purchase_invoice_id FROM purchase_invoices WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'document_kind' => (string) $row['document_kind'],
            'advance_purchase_invoice_id' => $row['advance_purchase_invoice_id'] !== null ? (int) $row['advance_purchase_invoice_id'] : null,
        ];
    }

    /** @return list<int> */
    private function finalIds(int $supplierId, int $advanceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM purchase_invoices
              WHERE supplier_id = ? AND advance_purchase_invoice_id = ? AND document_kind = 'invoice'
                AND status IN ('received', 'booked', 'paid')
              ORDER BY id"
        );
        $stmt->execute([$supplierId, $advanceId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    private function hasLivePosting(int $supplierId, int $id): bool
    {
        return (new DocumentPostingGroup($this->db))->hasActive($supplierId, 'purchase_invoice', $id);
    }
}
