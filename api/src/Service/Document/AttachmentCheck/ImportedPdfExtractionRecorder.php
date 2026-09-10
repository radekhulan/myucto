<?php

declare(strict_types=1);

namespace MyInvoice\Service\Document\AttachmentCheck;

use MyInvoice\Repository\DocumentExtractionRepository;
use MyInvoice\Service\Document\ScanAttach\ScanExtractionNormalizer;
use Psr\Log\LoggerInterface;

/**
 * Uloží vytěžení PDF, ze kterého AI import založil přijatou fakturu, stejným způsobem
 * jako připojení skenů ({@see \MyInvoice\Service\Document\ScanAttach\ScanExtractionService}):
 * normalizované sloupce + úplná odpověď modelu, klíčem je obsah (sha256). Bez toho by
 * kontrola dokladů proti přílohám měla data jen u skenů.
 *
 * Import je vždy přijatý doklad, takže firma je na něm odběratel — pokud model roli
 * nevrátil, doplní se. Selhání nesmí shodit import: doklad už je založený.
 */
final class ImportedPdfExtractionRecorder
{
    public function __construct(
        private readonly DocumentExtractionRepository $extractions,
        private readonly AttachmentCheckService $checks,
        private readonly LoggerInterface $logger,
    ) {}

    /** @param array<string,mixed> $data vytěžení v orientaci dodavatel → firma (po případném prohození stran) */
    public function record(int $supplierId, int $purchaseInvoiceId, string $sha256, array $data, ?string $provider, ?string $model): void
    {
        try {
            $fields = ScanExtractionNormalizer::normalize($data);
            $fields['company_role'] ??= 'buyer';
            $this->extractions->save(
                $supplierId, null, $sha256, ScanExtractionNormalizer::SCHEMA_VERSION, 'ok', $fields, $data, $provider, $model, null,
            );
            $this->checks->recheckEntity($supplierId, 'purchase_invoice', $purchaseInvoiceId);
        } catch (\Throwable $e) {
            $this->logger->warning('Uložení vytěžení importovaného PDF selhalo', [
                'supplier_id' => $supplierId,
                'purchase_invoice_id' => $purchaseInvoiceId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
