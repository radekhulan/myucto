<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice;

use MyInvoice\Repository\DocumentLinkRepository;
use MyInvoice\Repository\DocumentRequestRepository;
use MyInvoice\Repository\PurchaseInvoiceSubmissionRepository;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\Import\ImageToPdfConverter;
use MyInvoice\Service\Import\InvoiceExtractionRouter;
use MyInvoice\Service\Import\PurchaseInvoicePdfArchiver;

/** Jediné místo, které ze staging podání udělá vazbu na výslednou fakturu. */
final class PurchaseInvoiceSubmissionCompletionService
{
    public function __construct(
        private readonly PurchaseInvoiceSubmissionRepository $submissions,
        private readonly DocumentLinkRepository $links,
        private readonly DocumentRequestRepository $requests,
        private readonly DocumentStorage $storage,
        private readonly PurchaseInvoicePdfArchiver $archiver,
        private readonly ImageToPdfConverter $images,
        private readonly InvoiceExtractionRouter $router,
    ) {}

    public function complete(
        int $submissionId,
        int $supplierId,
        int $purchaseInvoiceId,
        int $processedBy,
        string $source,
    ): void {
        $submission = $this->submissions->find($submissionId, $supplierId);
        if ($submission === null || (string) $submission['status'] !== 'processing') {
            throw new PurchaseInvoiceSubmissionException(
                'submission_not_claimed',
                'Podání není převzaté ke zpracování.',
                409,
            );
        }
        if (!$this->submissions->complete(
            $submissionId,
            $supplierId,
            $purchaseInvoiceId,
            $processedBy,
            $source,
        )) {
            throw new PurchaseInvoiceSubmissionException(
                'submission_state_changed',
                'Stav podání se mezitím změnil.',
                409,
            );
        }

        $documentId = (int) $submission['document_id'];
        $this->links->attach($supplierId, $documentId, 'purchase_invoice', $purchaseInvoiceId);
        $this->requests->markProcessedBySubmission($submissionId, $supplierId, $purchaseInvoiceId);
        $this->archiveOrigin($submission, $supplierId, $purchaseInvoiceId);
    }

    /** @param array<string,mixed> $submission */
    private function archiveOrigin(array $submission, int $supplierId, int $purchaseInvoiceId): void
    {
        $path = $this->storage->pathFor(
            $supplierId,
            (string) $submission['document_sha256'],
            (string) $submission['document_filename'],
        );
        $bytes = is_file($path) ? @file_get_contents($path) : false;
        if (!is_string($bytes) || $bytes === '') return;

        $name = (string) ($submission['original_name'] ?? 'doklad');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === 'pdf') {
            $this->archiver->archiveBytes($purchaseInvoiceId, $supplierId, $bytes, $name);
            try {
                $decision = $this->router->decide($bytes, 'pdf');
                if ($decision->isdocXml !== null) {
                    $sourceName = preg_replace('/\.pdf$/i', '.isdoc', $name) ?: 'source.isdoc';
                    $this->archiver->archiveSourceBytes(
                        $purchaseInvoiceId,
                        $supplierId,
                        $decision->isdocXml,
                        $sourceName,
                        'isdoc',
                    );
                }
            } catch (\Throwable) {
            }
            return;
        }
        if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            $mime = $this->images->detectImageMime($bytes);
            if ($mime !== null) {
                try {
                    $pdf = $this->images->convert($bytes, $mime);
                    $pdfName = preg_replace('/\.[^.]+$/', '.pdf', $name) ?: ($name . '.pdf');
                    $this->archiver->archiveBytes($purchaseInvoiceId, $supplierId, $pdf, $pdfName);
                } catch (\Throwable) {
                }
            }
            return;
        }
        if ($ext === 'isdocx') {
            $this->archiver->archiveSourceBytes(
                $purchaseInvoiceId,
                $supplierId,
                $bytes,
                $name,
                'isdocx',
            );
            try {
                $decision = $this->router->decide($bytes, 'isdocx');
                $pdf = $decision->isdocxPackage['pdf'] ?? null;
                if (is_string($pdf)) {
                    $pdfName = $decision->isdocxPackage['pdf_name']
                        ?? preg_replace('/\.isdocx$/i', '.pdf', $name)
                        ?? 'doklad.pdf';
                    $this->archiver->archiveBytes($purchaseInvoiceId, $supplierId, $pdf, (string) $pdfName);
                }
            } catch (\Throwable) {
            }
            return;
        }
        if (in_array($ext, ['isdoc', 'xml'], true)) {
            $this->archiver->archiveSourceBytes($purchaseInvoiceId, $supplierId, $bytes, $name, 'isdoc');
        }
    }
}
