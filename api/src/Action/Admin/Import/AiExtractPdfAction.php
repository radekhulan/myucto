<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin\Import;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Import\AiPdfExtractor;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * POST /api/admin/imports/ai-extract-pdf
 *
 * Multipart upload jednoho PDF → AI extraction (ISDOC priorita, Claude fallback) →
 * vytvoří purchase_invoice draft. Synchronní endpoint (ne background job —
 * single-shot operace trvá 10-30s, OK pro user-blocking UX).
 *
 * Body: multipart form-data, field "pdf" = soubor.
 * Optional ?model=<id z whitelistu provideru> (override per request) — whitelist drží
 * LlmProviderCapabilities (ANTHROPIC_MODELS / OPENAI_MODELS / GEMINI_MODELS).
 */
final class AiExtractPdfAction
{
    private const MAX_PDF_BYTES = 32 * 1024 * 1024;

    public function __construct(
        private readonly AiPdfExtractor $extractor,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'purchase_invoices.scan', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }

        $uploads = $request->getUploadedFiles();
        $file = $uploads['pdf'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'no_file', 'Nahrajte PDF v poli "pdf".', 400);
        }
        $size = (int) $file->getSize();
        if ($size <= 0 || $size > self::MAX_PDF_BYTES) {
            return Json::error($response, 'file_too_large', 'PDF musí být <= ' . self::MAX_PDF_BYTES . ' B.', 413);
        }
        $bytes = (string) $file->getStream()->getContents();

        // ?model override — validuj proti whitelistu aktivního providera (jinak by
        // libovolný string protekl do klienta). Neznámý model → 400.
        $modelOverride = (string) ($request->getQueryParams()['model'] ?? '') ?: null;
        if ($modelOverride !== null) {
            $allowedModels = $this->extractor->capabilities($supplierId)->models;
            if (!in_array($modelOverride, $allowedModels, true)) {
                return Json::error($response, 'validation_failed', 'Neplatný model.', 400);
            }
        }

        // ?import_batch_id — označení dávky hromadného importu (#232), ať jde později
        // dohledat co/kam se naimportovalo. Sanitizace na bezpečný krátký token.
        $rawBatch = (string) ($request->getQueryParams()['import_batch_id'] ?? '');
        $importBatchId = preg_replace('/[^A-Za-z0-9_-]/', '', $rawBatch);
        $importBatchId = ($importBatchId !== '') ? substr($importBatchId, 0, 32) : null;

        $userId = (int) ($user['id'] ?? 0);
        $originalName = $file->getClientFilename() ?: null;
        $result = $this->extractor->extractAndCreate($supplierId, $userId, $bytes, $modelOverride, $originalName, $importBatchId);

        // Provenance (F7 §3.7): data_residency_ok=false jen když router odmítl kvůli
        // EU rezidenci (residency_conflict); ISDOC i úspěšná AI cesta = true.
        $dataResidencyOk = (($result['error'] ?? '') !== 'residency_conflict');

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('import.ai_extract', $userId, 'purchase_invoice',
            $result['purchase_invoice_id'] ?? null,
            [
                'source'            => $result['source'] ?? null,
                'ok'                => $result['ok'],
                'provider'          => $result['provider'] ?? null,
                'model'             => $result['model'] ?? null,
                'region'            => $result['region'] ?? null,
                'data_residency_ok' => $dataResidencyOk,
                'usage'             => $result['usage'] ?? null,
                'pdf_size'          => $size,
                'pdf_name'          => $file->getClientFilename(),
            ],
            $ip, $request->getHeaderLine('User-Agent'),
        );

        if (!$result['ok']) {
            return Json::error($response, 'extraction_failed',
                $result['error'] ?? 'Extrakce selhala',
                422,
                ['ai_data' => $result['ai_data'] ?? null, 'source' => $result['source'] ?? null],
            );
        }
        return Json::ok($response, $result, 201);
    }
}
