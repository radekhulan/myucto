<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceSubmissionRepository;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\Import\AiPdfExtractor;
use MyInvoice\Service\Import\InvoiceExtractionRouter;
use MyInvoice\Service\Import\InvoiceImportService;
use MyInvoice\Support\CompanyIdNormalizer;

/** ISDOC-first zpracování staging originálu; při chybě se originál ani fronta neztratí. */
final class PurchaseInvoiceSubmissionProcessingService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PurchaseInvoiceSubmissionRepository $submissions,
        private readonly DocumentStorage $storage,
        private readonly InvoiceExtractionRouter $router,
        private readonly InvoiceImportService $importer,
        private readonly AiPdfExtractor $ai,
        private readonly PurchaseInvoiceSubmissionCompletionService $completion,
    ) {}

    /**
     * @param bool $withoutItems AI vytěžení bez rozpisu položek (jeden řádek na sazbu DPH);
     *        strukturovaný ISDOC se zakládá vždy z vlastních položek.
     * @return array{purchase_invoice_id:int,source:string,duplicate:bool}
     */
    public function extract(int $id, int $supplierId, int $userId, bool $allowAi, bool $withoutItems = false): array
    {
        $before = $this->submissions->find($id, $supplierId);
        if ($before === null) {
            throw new PurchaseInvoiceSubmissionException('not_found', 'Podání nebylo nalezeno.', 404);
        }
        if (!$this->submissions->claimForExtraction($id, $supplierId)) {
            if ((string) $before['status'] === 'processed' && (int) ($before['purchase_invoice_id'] ?? 0) > 0) {
                return [
                    'purchase_invoice_id' => (int) $before['purchase_invoice_id'],
                    'source' => (string) ($before['extraction_source'] ?? 'existing'),
                    'duplicate' => true,
                ];
            }
            throw new PurchaseInvoiceSubmissionException(
                'invalid_status',
                'Podání v tomto stavu nelze zpracovat.',
                409,
            );
        }

        $source = 'unknown';
        $buyer = null;
        try {
            $submission = $this->submissions->find($id, $supplierId);
            if ($submission === null) throw new \RuntimeException('Převzaté podání nebylo nalezeno.');
            $path = $this->storage->pathFor(
                $supplierId,
                (string) $submission['document_sha256'],
                (string) $submission['document_filename'],
            );
            $bytes = is_file($path) ? @file_get_contents($path) : false;
            if (!is_string($bytes) || $bytes === '') {
                throw new PurchaseInvoiceSubmissionException(
                    'source_missing',
                    'Originální soubor není v úložišti dostupný.',
                    500,
                );
            }
            $name = (string) ($submission['original_name'] ?? 'doklad');
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $decision = $this->router->decide($bytes, $extension);

            $duplicate = false;
            if (!$decision->useLlm) {
                $invoices = (array) (($decision->parsed ?? [])['invoices'] ?? []);
                if (count($invoices) !== 1) {
                    throw new PurchaseInvoiceSubmissionException(
                        'multiple_invoices',
                        'Jeden soubor musí obsahovat právě jeden účetní doklad.',
                        422,
                    );
                }
                $buyer = (array) ($invoices[0]['client'] ?? []);
                $report = $this->importer->importBundle(
                    [['name' => $name, 'content' => $bytes]],
                    $supplierId,
                    $userId,
                    'purchase',
                );
                $row = null;
                foreach ((array) ($report['results'] ?? []) as $candidate) {
                    if (is_array($candidate) && (int) ($candidate['purchase_invoice_id'] ?? 0) > 0) {
                        $row = $candidate;
                        break;
                    }
                }
                if ($row === null) {
                    $first = (array) (($report['results'] ?? [])[0] ?? []);
                    throw new PurchaseInvoiceSubmissionException(
                        'structured_import_failed',
                        (string) ($first['reason'] ?? 'Strukturovaný doklad se nepodařilo importovat.'),
                        422,
                    );
                }
                $purchaseInvoiceId = (int) $row['purchase_invoice_id'];
                $source = $decision->source;
                $duplicate = !empty($row['duplicate']);
            } else {
                if (in_array($extension, ['isdoc', 'xml'], true)) {
                    throw new PurchaseInvoiceSubmissionException(
                        'invalid_isdoc',
                        $decision->parseError !== null
                            ? 'ISDOC se nepodařilo načíst: ' . $decision->parseError
                            : 'Soubor neobsahuje platný ISDOC.',
                        422,
                    );
                }
                if (!$allowAi) {
                    throw new PurchaseInvoiceSubmissionException(
                        'ai_permission_required',
                        'Doklad nemá použitelný ISDOC; pro AI vytěžení chybí oprávnění.',
                        403,
                    );
                }
                $result = $this->ai->extractAndCreate(
                    $supplierId, $userId, $bytes, null, $name,
                    withoutItems: $withoutItems,
                    uploadedOn: substr((string) $submission['created_at'], 0, 10),
                );
                $buyer = (array) (($result['ai_data'] ?? [])['customer'] ?? []);
                if (!$result['ok'] || (int) ($result['purchase_invoice_id'] ?? 0) <= 0) {
                    throw new PurchaseInvoiceSubmissionException(
                        'extraction_failed',
                        (string) ($result['error'] ?? 'Vytěžení dokladu selhalo.'),
                        422,
                    );
                }
                $purchaseInvoiceId = (int) $result['purchase_invoice_id'];
                $source = (string) ($result['source'] ?? 'ai');
                $duplicate = !empty($result['duplicate']);
            }

            $pdo = $this->db->pdo();
            $ownTransaction = !$pdo->inTransaction();
            if ($ownTransaction) $pdo->beginTransaction();
            try {
                $this->completion->complete(
                    $id,
                    $supplierId,
                    $purchaseInvoiceId,
                    $userId,
                    $source,
                );
                if ($ownTransaction) $pdo->commit();
            } catch (\Throwable $e) {
                if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            return [
                'purchase_invoice_id' => $purchaseInvoiceId,
                'source' => $source,
                'duplicate' => $duplicate,
            ];
        } catch (\Throwable $e) {
            $this->submissions->extractionFailed($id, $supplierId, $source, $e->getMessage());
            // Doklad jiné firmy poznáme až z vytěžených dat. Odběratel zůstane u podání,
            // aby fronta nabídla přesun do firmy, které doklad patří.
            $buyerIc = CompanyIdNormalizer::ic(isset($buyer['ic']) ? (string) $buyer['ic'] : null);
            $buyerDic = CompanyIdNormalizer::dic(isset($buyer['dic']) ? (string) $buyer['dic'] : null);
            if ($buyerIc !== null || $buyerDic !== null) {
                $this->submissions->recordDetectedBuyer($id, $supplierId, $buyerIc, $buyerDic);
            }
            if ($e instanceof PurchaseInvoiceSubmissionException) throw $e;
            throw new PurchaseInvoiceSubmissionException(
                'extraction_failed',
                'Zpracování dokladu selhalo: ' . $e->getMessage(),
                422,
            );
        }
    }
}
