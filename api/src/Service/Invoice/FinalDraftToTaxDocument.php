<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Pdf\PdfArchiveService;
use PDO;

/**
 * Koncept vyúčtovací faktury k zálohové faktuře → daňový doklad k přijaté platbě.
 *
 * Firma s režimem „vyúčtovací faktura po doplacení zálohy" dostane po úhradě koncept
 * faktury. Když plnění ještě nenastalo (záloha na zakázku), patří k platbě daňový
 * doklad podle § 28 a koncept faktury je navíc. Bez téhle cesty musel uživatel
 * uhodnout, že má koncept smazat a pak na zálohové faktuře vystavit daňový doklad.
 *
 * Daňový doklad nevzniká přepisem konceptu: zakládá ho {@see PaymentTaxDocumentCreator}
 * stejně jako u každé jiné platby (DUZP = den platby, rozpad podle sazeb, vazba
 * platby). Koncept faktury se pak odstraní ve stejné transakci.
 *
 * Jen pro KONCEPT: vystavená faktura už zdanila plnění a její náhrada je storno.
 */
final class FinalDraftToTaxDocument
{
    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceRepository $repo,
        private readonly PaymentTaxDocumentCreator $taxDocCreator,
        private readonly AdvanceCycleLock $cycleLock,
        private readonly InvoicePdfRenderer $pdf,
        private readonly PdfArchiveService $pdfArchive,
        private readonly VarsymbolGenerator $varsymbol,
    ) {}

    /**
     * Platby zálohové faktury, ke kterým zatím není platný daňový doklad.
     *
     * @return list<array{id:int, paid_on:string, amount:float}>
     */
    public function pendingPayments(int $proformaId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT p.id, p.paid_on, p.amount
               FROM invoice_payments p
               LEFT JOIN invoices td ON td.id = p.tax_document_invoice_id
              WHERE p.invoice_id = ? AND p.amount > 0
                AND (p.tax_document_invoice_id IS NULL OR td.id IS NULL OR td.status = 'cancelled')
              ORDER BY p.paid_on, p.id"
        );
        $stmt->execute([$proformaId]);

        return array_map(static fn (array $r): array => [
            'id'      => (int) $r['id'],
            'paid_on' => substr((string) $r['paid_on'], 0, 10),
            'amount'  => (float) $r['amount'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @return array{proforma_id:int, tax_document_ids:list<int>}
     * @throws \RuntimeException při nesplnění podmínek (zpráva pro UI)
     */
    public function convert(int $draftId, int $userId): array
    {
        $draft = $this->repo->find($draftId);
        if ($draft === null) {
            throw new \RuntimeException('Faktura nenalezena.');
        }
        $proformaId = (int) ($draft['parent_invoice_id'] ?? 0);

        return $this->cycleLock->synchronized(
            $proformaId,
            fn (): array => $this->convertUnlocked($draftId, $userId),
        );
    }

    /** @return array{proforma_id:int, tax_document_ids:list<int>} */
    private function convertUnlocked(int $draftId, int $userId): array
    {
        $pdo = $this->db->pdo();
        // Stav znovu až pod zámkem zálohového cyklu — mezitím mohl koncept někdo vystavit.
        $draft = $this->repo->find($draftId);
        if ($draft === null) {
            throw new \RuntimeException('Faktura nenalezena.');
        }
        if (($draft['invoice_type'] ?? '') !== 'invoice' || ($draft['status'] ?? '') !== 'draft') {
            throw new \RuntimeException('Na daňový doklad k platbě lze změnit jen koncept faktury.');
        }
        $proformaId = (int) ($draft['parent_invoice_id'] ?? 0);
        $proforma = $proformaId > 0 ? $this->repo->find($proformaId) : null;
        if ($proforma === null || ($proforma['invoice_type'] ?? '') !== 'proforma'
            || (int) $proforma['supplier_id'] !== (int) $draft['supplier_id']) {
            throw new \RuntimeException('Koncept nevznikl ze zálohové faktury.');
        }
        $attachments = $pdo->prepare('SELECT COUNT(*) FROM invoice_attachments WHERE invoice_id = ?');
        $attachments->execute([$draftId]);
        if ((int) $attachments->fetchColumn() > 0) {
            throw new \RuntimeException('Koncept má přílohy. Přesuňte je jinam, jinak by se změnou ztratily.');
        }
        $payments = $this->pendingPayments($proformaId);
        if ($payments === []) {
            throw new \RuntimeException('Zálohová faktura nemá přijatou platbu bez daňového dokladu.');
        }

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $taxDocIds = [];
            foreach ($payments as $payment) {
                $taxDocIds[] = $this->taxDocCreator->createForPayment($payment['id'], $userId);
            }

            $this->pdf->invalidate($draftId, 'invalidate_manual');
            $this->pdfArchive->purgeFilesForInvoice($draftId);
            $draftVs = (string) ($draft['varsymbol'] ?? '');
            if ($draftVs !== '') {
                $this->varsymbol->releaseIfLatest(
                    (int) $draft['supplier_id'],
                    VarsymbolGenerator::seriesTypeFor($draft),
                    $draftVs,
                    !empty($draft['issue_date']) ? new \DateTimeImmutable((string) $draft['issue_date']) : null,
                    (int) ($draft['client_id'] ?? 0),
                    (int) ($draft['revenue_category_id'] ?? 0),
                );
            }
            $this->repo->delete($draftId);

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if (!TransactionFailure::rollbackIfAborted($pdo, $e) && $ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['proforma_id' => $proformaId, 'tax_document_ids' => $taxDocIds];
    }
}
