<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Stats\StatsRecomputer;
use MyInvoice\Support\Sql\PurchaseSettledExpr;
use PDO;

/**
 * Zápočet dobropisu proti opravované faktuře (issue #140, migrace 1988).
 *
 * Dobropis navázaný na fakturu sníží její „Zbývá uhradit" o svou plnou výši. Odběratel
 * (resp. my dodavateli) pak platí jen rozdíl a úhradu jde spárovat s bankou.
 *
 *   - vydaná strana: platba na faktuře se zdrojem `credit_note` (paid_total, párování banky
 *     i upomínky pracují se zbytkem), dobropis přejde na `paid`;
 *   - přijatá strana: řádek zápočtu čte {@see PurchaseSettledExpr}, dobropis přejde na
 *     `paid` a faktura také, když ji zápočet s dřívějšími úhradami pokryje celou.
 *
 * Zápočet se dělá jen v PLNÉ výši dobropisu a jen tehdy, když ho zbytek faktury pokryje.
 * Dobropis k už zaplacené faktuře se vrací penězi jako dosud.
 *
 * Peněžní deník zápočet nezná: dobropis jen snižuje tutéž pohledávku, žádné peníze ani
 * jiná pohledávka se nevyrovnávají. Příjem/výdaj vzniká až úhradou zbytku. DPH se
 * nemění, dobropis je v evidenci DPH sám za sebe.
 */
final class CreditNoteOffsetService
{
    public const TOLERANCE = 0.05;

    public function __construct(
        private readonly Connection $db,
        private readonly InvoicePaymentService $payments,
        private readonly PurchaseInvoiceRepository $purchases,
        private readonly InvoicePdfRenderer $pdf,
        private readonly StatsRecomputer $stats,
    ) {}

    /**
     * Zápočty, kterých se doklad účastní (jako dobropis i jako faktura), a u dobropisu
     * bez zápočtu důvod, proč ho (ne)lze započíst.
     *
     * @return array{offsets: list<array<string,mixed>>, can_offset: bool, reason: ?string}
     */
    public function forDocument(int $supplierId, string $docType, int $docId): array
    {
        $docType = self::docType($docType);
        $table = $docType === 'invoice' ? 'invoices' : 'purchase_invoices';
        $number = $docType === 'invoice' ? 'varsymbol' : 'vendor_invoice_number';
        $stmt = $this->db->pdo()->prepare(
            "SELECT o.id, o.doc_type, o.invoice_id, o.credit_note_id, o.amount, o.offset_on,
                    inv.{$number} AS invoice_number, cn.{$number} AS credit_note_number
               FROM credit_note_offsets o
               LEFT JOIN {$table} inv ON inv.id = o.invoice_id AND inv.supplier_id = o.supplier_id
               LEFT JOIN {$table} cn  ON cn.id = o.credit_note_id AND cn.supplier_id = o.supplier_id
              WHERE o.supplier_id = ? AND o.doc_type = ? AND (o.invoice_id = ? OR o.credit_note_id = ?)
              ORDER BY o.offset_on, o.id"
        );
        $stmt->execute([$supplierId, $docType, $docId, $docId]);
        $offsets = array_map(static fn (array $r): array => [
            'id'                 => (int) $r['id'],
            'doc_type'           => (string) $r['doc_type'],
            'invoice_id'         => (int) $r['invoice_id'],
            'credit_note_id'     => (int) $r['credit_note_id'],
            'amount'             => (float) $r['amount'],
            'offset_on'          => (string) $r['offset_on'],
            'invoice_number'     => $r['invoice_number'] !== null ? (string) $r['invoice_number'] : null,
            'credit_note_number' => $r['credit_note_number'] !== null ? (string) $r['credit_note_number'] : null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

        $reason = null;
        $isOffsetCreditNote = false;
        foreach ($offsets as $o) {
            if ($o['credit_note_id'] === $docId) {
                $isOffsetCreditNote = true;
            }
        }
        if (!$isOffsetCreditNote) {
            $candidate = $docType === 'invoice'
                ? $this->invoiceCandidate($this->db->pdo(), $supplierId, $docId, false)
                : $this->purchaseCandidate($this->db->pdo(), $supplierId, $docId, false);
            $reason = $candidate['reason'];
        }

        return [
            'offsets'    => $offsets,
            'can_offset' => !$isOffsetCreditNote && $reason === null,
            'reason'     => $isOffsetCreditNote ? 'already_offset' : $reason,
        ];
    }

    /**
     * Započte vystavený dobropis proti faktuře. Vrací ID zápočtu, nebo null s důvodem,
     * když zápočet nejde (nevadí — dobropis pak zůstává k vrácení penězi).
     *
     * @return array{offset_id: ?int, reason: ?string}
     */
    public function applyForInvoice(int $supplierId, int $creditNoteId, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $c = $this->invoiceCandidate($pdo, $supplierId, $creditNoteId, true);
            if ($c['reason'] !== null) {
                if ($own) {
                    $pdo->rollBack();
                }
                return ['offset_id' => null, 'reason' => $c['reason']];
            }
            $payment = $this->payments->recordPayment($c['invoice_id'], $c['amount'], $c['offset_on'], [
                'source'     => 'credit_note',
                'note'       => 'Zápočet dobropisu ' . $c['credit_note_number'],
                'created_by' => $userId,
            ]);
            $pdo->prepare(
                "UPDATE invoices SET status = 'paid', paid_at = ?
                  WHERE id = ? AND supplier_id = ? AND invoice_type = 'credit_note'"
            )->execute([$c['offset_on'], $creditNoteId, $supplierId]);
            $offsetId = $this->insertOffset($pdo, $supplierId, 'invoice', $c, $payment['payment_id'], $userId);
            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if ($own) {
            $this->afterInvoiceChange([$c['invoice_id'], $creditNoteId]);
        }
        return ['offset_id' => $offsetId, 'reason' => null];
    }

    /**
     * Započte přijatý dobropis proti přijaté faktuře.
     *
     * @return array{offset_id: ?int, reason: ?string}
     */
    public function applyForPurchase(int $supplierId, int $creditNoteId, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $c = $this->purchaseCandidate($pdo, $supplierId, $creditNoteId, true);
            if ($c['reason'] !== null) {
                if ($own) {
                    $pdo->rollBack();
                }
                return ['offset_id' => null, 'reason' => $c['reason']];
            }
            $offsetId = $this->insertOffset($pdo, $supplierId, 'purchase_invoice', $c, null, $userId);
            $this->purchases->setStatus($creditNoteId, 'paid', $supplierId, $c['offset_on']);
            if ($this->purchaseRemaining($pdo, $c['invoice_id']) <= self::TOLERANCE) {
                $this->purchases->setStatus($c['invoice_id'], 'paid', $supplierId, $c['offset_on']);
            }
            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['offset_id' => $offsetId, 'reason' => null];
    }

    /**
     * Zruší zápočty, kterých se doklad účastní (jako dobropis i jako faktura). Faktura se
     * vrací na nezaplacenou, jen když bez zápočtu přestane být krytá; dobropis na vystavený.
     *
     * @return int počet zrušených zápočtů
     */
    public function revertForDocument(int $supplierId, string $docType, int $docId): int
    {
        $docType = self::docType($docType);
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        $touched = [];
        try {
            $stmt = $pdo->prepare(
                'SELECT id, invoice_id, credit_note_id, invoice_payment_id
                   FROM credit_note_offsets
                  WHERE supplier_id = ? AND doc_type = ? AND (invoice_id = ? OR credit_note_id = ?)
                  FOR UPDATE'
            );
            $stmt->execute([$supplierId, $docType, $docId, $docId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $invoiceId = (int) $row['invoice_id'];
                $creditNoteId = (int) $row['credit_note_id'];
                $touched[] = $invoiceId;
                $touched[] = $creditNoteId;
                if ($docType === 'invoice') {
                    if ($row['invoice_payment_id'] !== null) {
                        // Smazání platby vrátí dobropis do vystaveného stavu a zápočet
                        // odstraní kaskádou (InvoicePaymentService::releaseCreditNotes).
                        $this->payments->deletePayment((int) $row['invoice_payment_id'], skipBankGuard: true);
                    } else {
                        $pdo->prepare('DELETE FROM credit_note_offsets WHERE id = ?')->execute([(int) $row['id']]);
                    }
                    continue;
                }
                $pdo->prepare('DELETE FROM credit_note_offsets WHERE id = ?')->execute([(int) $row['id']]);
                self::reopenPurchase($pdo, $supplierId, $creditNoteId);
                if ($this->purchaseRemaining($pdo, $invoiceId) > self::TOLERANCE) {
                    self::reopenPurchase($pdo, $supplierId, $invoiceId);
                }
            }
            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if ($own && $docType === 'invoice' && $touched !== []) {
            $this->afterInvoiceChange(array_values(array_unique($touched)));
        }
        return count($rows);
    }

    /**
     * @return array{reason: ?string, invoice_id: int, amount: float, offset_on: string, credit_note_number: string}
     */
    private function invoiceCandidate(PDO $pdo, int $supplierId, int $creditNoteId, bool $lock): array
    {
        $none = static fn (string $reason): array => [
            'reason' => $reason, 'invoice_id' => 0, 'amount' => 0.0, 'offset_on' => '', 'credit_note_number' => '',
        ];
        $stmt = $pdo->prepare(
            'SELECT invoice_type, status, amount_to_pay, parent_invoice_id, currency_id, issue_date, varsymbol
               FROM invoices WHERE id = ? AND supplier_id = ?' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$creditNoteId, $supplierId]);
        $cn = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($cn === false || (string) $cn['invoice_type'] !== 'credit_note') {
            return $none('not_credit_note');
        }
        if ((int) ($cn['parent_invoice_id'] ?? 0) <= 0) {
            return $none('no_parent');
        }
        if (!in_array((string) $cn['status'], ['issued', 'sent', 'reminded'], true)) {
            return $none('credit_note_not_open');
        }
        $amount = round(-(float) $cn['amount_to_pay'], 2);
        if ($amount <= 0) {
            return $none('credit_note_not_negative');
        }
        if ($this->hasOffset($pdo, 'invoice', $creditNoteId)) {
            return $none('already_offset');
        }

        $stmt = $pdo->prepare(
            'SELECT invoice_type, status, amount_to_pay, paid_total, currency_id
               FROM invoices WHERE id = ? AND supplier_id = ?' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([(int) $cn['parent_invoice_id'], $supplierId]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($inv === false || (string) $inv['invoice_type'] !== 'invoice') {
            return $none('parent_not_invoice');
        }
        if (!in_array((string) $inv['status'], ['issued', 'sent', 'reminded'], true)) {
            return $none('parent_not_open');
        }
        if ((int) $inv['currency_id'] !== (int) $cn['currency_id']) {
            return $none('currency_mismatch');
        }
        $remaining = round((float) $inv['amount_to_pay'] - (float) $inv['paid_total'], 2);
        if ($remaining < $amount - self::TOLERANCE) {
            return $none('parent_remaining_too_low');
        }

        return [
            'reason'             => null,
            'credit_note_id'     => $creditNoteId,
            'invoice_id'         => (int) $cn['parent_invoice_id'],
            'amount'             => $amount,
            'offset_on'          => (string) $cn['issue_date'],
            'credit_note_number' => (string) ($cn['varsymbol'] ?? ''),
        ];
    }

    /**
     * @return array{reason: ?string, invoice_id: int, amount: float, offset_on: string, credit_note_number: string}
     */
    private function purchaseCandidate(PDO $pdo, int $supplierId, int $creditNoteId, bool $lock): array
    {
        $none = static fn (string $reason): array => [
            'reason' => $reason, 'invoice_id' => 0, 'amount' => 0.0, 'offset_on' => '', 'credit_note_number' => '',
        ];
        $stmt = $pdo->prepare(
            'SELECT document_kind, status, amount_to_pay, parent_purchase_invoice_id, currency_id, issue_date,
                    vendor_invoice_number
               FROM purchase_invoices WHERE id = ? AND supplier_id = ?' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$creditNoteId, $supplierId]);
        $cn = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($cn === false || (string) $cn['document_kind'] !== 'credit_note') {
            return $none('not_credit_note');
        }
        if ((int) ($cn['parent_purchase_invoice_id'] ?? 0) <= 0) {
            return $none('no_parent');
        }
        if (!in_array((string) $cn['status'], ['received', 'booked'], true)) {
            return $none('credit_note_not_open');
        }
        $amount = round(-(float) $cn['amount_to_pay'], 2);
        if ($amount <= 0) {
            return $none('credit_note_not_negative');
        }
        if ($this->hasOffset($pdo, 'purchase_invoice', $creditNoteId)) {
            return $none('already_offset');
        }
        // Dobropis s vlastní evidovanou úhradou (vratka bankou/pokladnou) už je vyřízený penězi.
        if (abs($this->purchaseSettledRaw($pdo, $creditNoteId)) > self::TOLERANCE) {
            return $none('credit_note_not_open');
        }

        $parentId = (int) $cn['parent_purchase_invoice_id'];
        $stmt = $pdo->prepare(
            'SELECT document_kind, status, currency_id
               FROM purchase_invoices WHERE id = ? AND supplier_id = ?' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$parentId, $supplierId]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($inv === false || !in_array((string) $inv['document_kind'], ['invoice', 'receipt'], true)) {
            return $none('parent_not_invoice');
        }
        if (!in_array((string) $inv['status'], ['received', 'booked'], true)) {
            return $none('parent_not_open');
        }
        if ((int) $inv['currency_id'] !== (int) $cn['currency_id']) {
            return $none('currency_mismatch');
        }
        if ($this->purchaseRemaining($pdo, $parentId) < $amount - self::TOLERANCE) {
            return $none('parent_remaining_too_low');
        }

        return [
            'reason'             => null,
            'credit_note_id'     => $creditNoteId,
            'invoice_id'         => $parentId,
            'amount'             => $amount,
            'offset_on'          => (string) $cn['issue_date'],
            'credit_note_number' => (string) ($cn['vendor_invoice_number'] ?? ''),
        ];
    }

    private function hasOffset(PDO $pdo, string $docType, int $creditNoteId): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM credit_note_offsets WHERE doc_type = ? AND credit_note_id = ?');
        $stmt->execute([$docType, $creditNoteId]);
        return $stmt->fetchColumn() !== false;
    }

    /** @param array{credit_note_id:int, invoice_id:int, amount:float, offset_on:string} $c */
    private function insertOffset(PDO $pdo, int $supplierId, string $docType, array $c, ?int $paymentId, ?int $userId): int
    {
        $pdo->prepare(
            'INSERT INTO credit_note_offsets
               (supplier_id, doc_type, invoice_id, credit_note_id, amount, offset_on, invoice_payment_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            $docType,
            $c['invoice_id'],
            $c['credit_note_id'],
            $c['amount'],
            $c['offset_on'],
            $paymentId,
            $userId !== null && $userId > 0 ? $userId : null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function purchaseRemaining(PDO $pdo, int $purchaseInvoiceId): float
    {
        $stmt = $pdo->prepare('SELECT ' . PurchaseSettledExpr::remaining('pi') . ' FROM purchase_invoices pi WHERE pi.id = ?');
        $stmt->execute([$purchaseInvoiceId]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    private function purchaseSettledRaw(PDO $pdo, int $purchaseInvoiceId): float
    {
        $stmt = $pdo->prepare('SELECT ' . PurchaseSettledExpr::settled('pi') . ' FROM purchase_invoices pi WHERE pi.id = ?');
        $stmt->execute([$purchaseInvoiceId]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    private static function reopenPurchase(PDO $pdo, int $supplierId, int $id): void
    {
        $pdo->prepare(
            "UPDATE purchase_invoices
                SET status = IF(booked_at IS NOT NULL, 'booked', 'received'), paid_at = NULL
              WHERE id = ? AND supplier_id = ? AND status = 'paid'"
        )->execute([$id, $supplierId]);
    }

    /** @param list<int> $invoiceIds */
    private function afterInvoiceChange(array $invoiceIds): void
    {
        foreach ($invoiceIds as $id) {
            $this->pdf->invalidate($id, 'invalidate_payment_change');
            $this->stats->recomputeForInvoiceId($id);
        }
    }

    private static function docType(string $docType): string
    {
        return $docType === 'purchase_invoice' ? 'purchase_invoice' : 'invoice';
    }
}
