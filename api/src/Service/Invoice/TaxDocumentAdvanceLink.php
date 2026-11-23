<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AdvanceDeductionRepository;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Vat\FiledVatPeriodLookup;
use PDO;

final class TaxDocumentAdvanceLink
{
    public function __construct(
        private readonly Connection $db,
        private readonly AdvanceCycleLock $cycleLock,
        private readonly AdvanceDeductionRepository $deductions,
        private readonly DocumentLockService $locks,
        private readonly FiledVatPeriodLookup $filedPeriods,
        private readonly ActivityLogger $logger,
    ) {}

    /** @return list<array<string,mixed>> */
    public function candidates(int $supplierId, int $invoiceId): array
    {
        $source = $this->invoice($supplierId, $invoiceId);
        if (!in_array($source['invoice_type'], ['proforma', 'tax_document'], true)
            || $source['status'] === 'cancelled'
            || (int) $source['client_id'] <= 0
            || ($source['invoice_type'] === 'tax_document' && !$this->isLinkableTaxDocument($source))) {
            return [];
        }

        $fromAdvance = $source['invoice_type'] === 'proforma';
        $candidate = $fromAdvance ? 'td' : 'a';
        $stmt = $this->db->pdo()->prepare(
            "SELECT {$candidate}.id, {$candidate}.varsymbol, {$candidate}.invoice_type, {$candidate}.status,
                    {$candidate}.issue_date, {$candidate}.total_with_vat, cur.code AS currency
               FROM invoices {$candidate}
               JOIN invoices " . ($fromAdvance ? 'a' : 'td') . " ON a.supplier_id = td.supplier_id
                    AND " . ($fromAdvance ? 'a.id' : 'td.id') . " = ?
               JOIN currencies cur ON cur.id = a.currency_id
               LEFT JOIN invoice_payments tp ON tp.tax_document_invoice_id = td.id
              WHERE {$candidate}.supplier_id = ? AND {$candidate}.client_id = ? AND {$candidate}.currency_id = ?
                AND a.invoice_type = 'proforma' AND a.status <> 'cancelled'
                AND td.invoice_type = 'tax_document' AND td.status NOT IN ('draft', 'cancelled')
                AND td.parent_invoice_id IS NULL AND td.total_with_vat > 0
                AND (tp.id IS NULL OR (tp.supplier_id = a.supplier_id AND tp.invoice_id = a.id))
                AND " . self::linkedTotalSql('a.supplier_id', 'a.id', 'td.id') . "
                    + td.total_with_vat <= a.total_with_vat + " . self::toleranceSql('cur.code') . "
              ORDER BY {$candidate}.issue_date DESC, {$candidate}.id DESC LIMIT 50"
        );
        $stmt->execute([$invoiceId, $supplierId, $source['client_id'], $source['currency_id']]);
        return array_map(static fn (array $row): array => array_replace($row, [
            'id' => (int) $row['id'],
            'total_with_vat' => (float) $row['total_with_vat'],
        ]), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string,mixed> $invoice */
    public function withDetail(int $supplierId, array $invoice): array
    {
        if ($invoice['invoice_type'] === 'proforma') {
            $stmt = $this->db->pdo()->prepare(
                "SELECT td.id, td.varsymbol, td.status
                   FROM (
                       SELECT id FROM invoices
                        WHERE supplier_id = ? AND parent_invoice_id = ? AND invoice_type = 'tax_document'
                       UNION
                       SELECT tax_document_invoice_id FROM invoice_payments
                        WHERE supplier_id = ? AND invoice_id = ? AND tax_document_invoice_id IS NOT NULL
                   ) links
                   JOIN invoices td ON td.id = links.id
                  WHERE td.supplier_id = ? AND td.invoice_type = 'tax_document' AND td.status <> 'cancelled'
                  ORDER BY td.id"
            );
            $stmt->execute([$supplierId, $invoice['id'], $supplierId, $invoice['id'], $supplierId]);
            $invoice['advance_tax_documents'] = array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'varsymbol' => $row['varsymbol'],
                'status' => $row['status'],
            ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        }
        if ($invoice['invoice_type'] === 'tax_document') {
            $parent = $invoice['parent_invoice'] ?? null;
            $invoice['can_unlink_tax_document_advance'] = ($parent['invoice_type'] ?? null) === 'proforma'
                && !$this->isDrawn($supplierId, (int) $parent['id']);
        }
        return $invoice;
    }

    /**
     * Vazba může vzniknout i v podaném či uzavřeném období: mění jen odkaz na zálohu
     * a případně na platbu. Částky, období DPH a účetní zápisy DDKP se nemění.
     * Informaci o období vracíme, aby ji uživatel při navázání viděl.
     *
     * @return array{advance_id:int,payment_id:?int,filed_vat_period:?string,accounting_period_locked:bool}
     */
    public function link(int $supplierId, int $taxDocumentId, int $advanceId, ?int $userId, string $ip, string $userAgent): array
    {
        $this->invoice($supplierId, $advanceId);
        return $this->change($advanceId, function () use ($supplierId, $taxDocumentId, $advanceId, $userId, $ip, $userAgent): array {
            $this->deductions->lockDocuments($supplierId, 'invoice', [$taxDocumentId, $advanceId]);
            $td = $this->invoice($supplierId, $taxDocumentId);
            $advance = $this->invoice($supplierId, $advanceId);
            if (!$this->isLinkableTaxDocument($td)) {
                throw new \DomainException('Navázat lze jen vystavený daňový doklad k přijaté platbě s kladnou částkou a bez vazby na jiný doklad.');
            }
            if ($advance['invoice_type'] !== 'proforma' || $advance['status'] === 'cancelled') {
                throw new \DomainException('Vyberte nestornovanou zálohovou fakturu.');
            }
            if ((int) $td['client_id'] <= 0 || (int) $td['client_id'] !== (int) $advance['client_id']
                || (int) $td['currency_id'] !== (int) $advance['currency_id']) {
                throw new \DomainException('Daňový doklad a zálohová faktura musí mít stejného odběratele a stejnou měnu.');
            }

            $pdo = $this->db->pdo();
            $capacity = $pdo->prepare(
                'SELECT ' . self::linkedTotalSql('a.supplier_id', 'a.id', '?') . '
                        + CAST(? AS DECIMAL(14,2)) <= a.total_with_vat + ' . self::toleranceSql('cur.code') . '
                   FROM invoices a JOIN currencies cur ON cur.id = a.currency_id
                  WHERE a.supplier_id = ? AND a.id = ?'
            );
            $capacity->execute([$taxDocumentId, $taxDocumentId, $td['total_with_vat'], $supplierId, $advanceId]);
            if (!(bool) $capacity->fetchColumn()) {
                throw new \DomainException('Součet navázaných daňových dokladů by překročil částku zálohové faktury.');
            }

            $paymentId = $this->linkPayment($supplierId, $advanceId, $td);
            $pdo->prepare('UPDATE invoices SET parent_invoice_id = ? WHERE supplier_id = ? AND id = ?')
                ->execute([$advanceId, $supplierId, $taxDocumentId]);
            $this->log('invoice.tax_document_advance_linked', $supplierId, $taxDocumentId, $advanceId, $paymentId, $userId, $ip, $userAgent);

            $date = DocumentLockService::invoiceRefDate($td);
            $period = $date === null ? null : $this->locks->forDate($supplierId, $date);
            $oss = $pdo->prepare('SELECT 1 FROM invoice_items WHERE invoice_id = ? AND oss_applicable = 1 LIMIT 1');
            $oss->execute([$taxDocumentId]);

            return [
                'advance_id' => $advanceId,
                'payment_id' => $paymentId,
                'filed_vat_period' => $date === null ? null : $this->filedPeriods->describe($supplierId, $date, $oss->fetchColumn() !== false),
                'accounting_period_locked' => $period !== null && ($period->inClosedPeriod || $period->inClosingPeriod || $period->dateLocked),
            ];
        });
    }

    /** @return array{advance_id:int} */
    public function unlink(int $supplierId, int $taxDocumentId, ?int $userId, string $ip, string $userAgent): array
    {
        $td = $this->invoice($supplierId, $taxDocumentId);
        $advanceId = (int) $td['parent_invoice_id'];
        if ($td['invoice_type'] !== 'tax_document' || $advanceId <= 0) {
            throw new \DomainException('Doklad nemá vazbu na zálohovou fakturu.');
        }
        $this->invoice($supplierId, $advanceId);

        return $this->change($advanceId, function () use ($supplierId, $taxDocumentId, $advanceId, $userId, $ip, $userAgent): array {
            $this->deductions->lockDocuments($supplierId, 'invoice', [$taxDocumentId, $advanceId]);
            $td = $this->invoice($supplierId, $taxDocumentId);
            $advance = $this->invoice($supplierId, $advanceId);
            if ((int) $td['parent_invoice_id'] !== $advanceId || $advance['invoice_type'] !== 'proforma') {
                throw new \DomainException('Doklad nemá vazbu na tuto zálohovou fakturu.');
            }
            if ($this->isDrawn($supplierId, $advanceId)) {
                throw new \DomainException('Vazbu nelze zrušit, zálohu už čerpá konečná faktura. Nejdřív odeberte její čerpání.');
            }

            $pdo = $this->db->pdo();
            $stmt = $pdo->prepare(
                'SELECT id FROM invoice_payments
                  WHERE supplier_id = ? AND invoice_id = ? AND tax_document_invoice_id = ? FOR UPDATE'
            );
            $stmt->execute([$supplierId, $advanceId, $taxDocumentId]);
            $paymentId = (int) $stmt->fetchColumn() ?: null;
            if ($paymentId !== null) {
                $pdo->prepare('UPDATE invoice_payments SET tax_document_invoice_id = NULL WHERE id = ?')
                    ->execute([$paymentId]);
            }
            $pdo->prepare('UPDATE invoices SET parent_invoice_id = NULL WHERE supplier_id = ? AND id = ?')
                ->execute([$supplierId, $taxDocumentId]);
            $this->log('invoice.tax_document_advance_unlinked', $supplierId, $taxDocumentId, $advanceId, $paymentId, $userId, $ip, $userAgent);
            return ['advance_id' => $advanceId];
        });
    }

    private function isDrawn(int $supplierId, int $advanceId): bool
    {
        $pdo = $this->db->pdo();
        // Odpočet § 37a drží proformu, ne jednotlivý DDKP. I koncept může už nést jeho daň.
        return AdvanceDrawLinks::firstFinalOf($pdo, $supplierId, $advanceId, ['draft', 'issued', 'sent', 'reminded', 'paid']) !== null
            || AdvanceDeductionRepository::advanceHasDeductions($pdo, 'invoice', $advanceId);
    }

    private function invoice(int $supplierId, int $id): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.id, i.invoice_type, i.status, i.parent_invoice_id, i.client_id, i.currency_id,
                    i.issue_date, i.effective_tax_date, i.tax_date, i.total_with_vat, cur.code AS currency
               FROM invoices i JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ? AND i.id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new \OutOfBoundsException('Faktura nenalezena.');
    }

    private function isLinkableTaxDocument(array $invoice): bool
    {
        return $invoice['invoice_type'] === 'tax_document' && !in_array($invoice['status'], ['draft', 'cancelled'], true)
            && $invoice['parent_invoice_id'] === null && (float) $invoice['total_with_vat'] > 0;
    }

    /** Započte i historickou vazbu přes platbu. Každý nestornovaný doklad právě jednou. */
    private static function linkedTotalSql(string $supplier, string $advance, string $exclude): string
    {
        return "(COALESCE((SELECT SUM(d.total_with_vat) FROM invoices d FORCE INDEX (idx_inv_supplier_parent)
                            WHERE d.supplier_id = {$supplier} AND d.parent_invoice_id = {$advance}
                              AND d.invoice_type = 'tax_document' AND d.status <> 'cancelled' AND d.id <> {$exclude}), 0)
               + COALESCE((SELECT SUM(d.total_with_vat) FROM invoice_payments p FORCE INDEX (idx_invpay_invoice)
                             JOIN invoices d ON d.id = p.tax_document_invoice_id AND d.supplier_id = p.supplier_id
                            WHERE p.supplier_id = {$supplier} AND p.invoice_id = {$advance}
                              AND d.invoice_type = 'tax_document' AND d.status <> 'cancelled' AND d.id <> {$exclude}
                              AND (d.parent_invoice_id IS NULL OR d.parent_invoice_id <> p.invoice_id)), 0))";
    }

    private static function toleranceSql(string $currency): string
    {
        return "CASE WHEN {$currency} = 'CZK' THEN 1.00 ELSE 0.01 END";
    }

    private function linkPayment(int $supplierId, int $advanceId, array $td): ?int
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT id, invoice_id, supplier_id FROM invoice_payments WHERE tax_document_invoice_id = ? FOR UPDATE');
        $stmt->execute([$td['id']]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing !== false) {
            if ((int) $existing['invoice_id'] !== $advanceId || (int) $existing['supplier_id'] !== $supplierId) {
                throw new \DomainException('Daňový doklad je už navázaný na platbu jiné zálohy.');
            }
            return (int) $existing['id'];
        }

        $stmt = $pdo->prepare(
            'SELECT id FROM invoice_payments
              WHERE supplier_id = ? AND invoice_id = ? AND currency = ? AND amount = ?
                AND tax_document_invoice_id IS NULL
              ORDER BY id LIMIT 2 FOR UPDATE'
        );
        $stmt->execute([$supplierId, $advanceId, $td['currency'], $td['total_with_vat']]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) !== 1) {
            return null;
        }
        $pdo->prepare('UPDATE invoice_payments SET tax_document_invoice_id = ? WHERE id = ?')->execute([$td['id'], $ids[0]]);
        return (int) $ids[0];
    }

    private function log(string $action, int $supplierId, int $taxDocumentId, int $advanceId, ?int $paymentId, ?int $userId, string $ip, string $userAgent): void
    {
        $payload = ['tax_document_id' => $taxDocumentId, 'advance_id' => $advanceId, 'payment_id' => $paymentId];
        $this->logger->log($action, $userId, 'invoice', $taxDocumentId, $payload, $ip, $userAgent, $supplierId);
        $this->logger->log($action, $userId, 'invoice', $advanceId, $payload, $ip, $userAgent, $supplierId);
    }

    private function change(int $advanceId, callable $change): array
    {
        return $this->cycleLock->synchronized($advanceId, function () use ($change): array {
            $pdo = $this->db->pdo();
            $ownsTransaction = !$pdo->inTransaction();
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            }
            try {
                $result = $change();
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return $result;
            } catch (\Throwable $e) {
                if ($ownsTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        });
    }
}
