<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin\Import;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PurchaseInvoiceSubmissionRepository;
use MyInvoice\Security\CrossSupplierAuthorization;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Import\AiPdfExtractor;
use MyInvoice\Service\Import\ClientAiExtractionPolicy;
use MyInvoice\Service\Import\ClientAiExtractionQuota;
use MyInvoice\Service\Import\DocumentAddresseeResolver;
use MyInvoice\Service\Import\ImageToPdfConverter;
use MyInvoice\Service\Import\IsdocxExtractor;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionUploadService;
use MyInvoice\Support\CompanyIdNormalizer;
use MyInvoice\Support\PdfBytes;
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
 * ?without_items=1 — doklad bez rozpisu položek, jeden řádek na sazbu DPH.
 * Staff-only ?model=<id z whitelistu provideru> (override per request) — whitelist drží
 * LlmProviderCapabilities (ANTHROPIC_MODELS / OPENAI_MODELS / GEMINI_MODELS).
 */
final class AiExtractPdfAction
{
    private const MAX_PDF_BYTES = 32 * 1024 * 1024;

    public function __construct(
        private readonly AiPdfExtractor $extractor,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly CrossSupplierAuthorization $crossSupplier,
        private readonly DocumentAddresseeResolver $addressees,
        private readonly PurchaseInvoiceSubmissionUploadService $submissions,
        private readonly PurchaseInvoiceSubmissionRepository $submissionRows,
        private readonly ClientAiExtractionPolicy $clientPolicy,
        private readonly ClientAiExtractionQuota $clientQuota,
        private readonly ImageToPdfConverter $images,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!ClientAiExtractionPolicy::hasDocumentPermissions($request)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění vytěžovat doklady.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }

        $client = RequestAuthorization::isClientType($request);
        if ($client) {
            $state = $this->clientPolicy->supplierState($supplierId);
            if (!$state['client_ai_extraction_enabled']) {
                return Json::error($response, 'client_ai_extraction_disabled', 'AI vytěžení pro klienty není povoleno.', 403);
            }
            if (!$state['client_ai_extraction_available']) {
                return Json::error($response, 'ai_not_configured', 'Firma nemá nastaveného AI poskytovatele.', 422);
            }
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
        if ($client && (strlen($bytes) !== $size || (!PdfBytes::isPdf($bytes)
            && !IsdocxExtractor::isZip($bytes) && $this->images->detectImageMime($bytes) === null))) {
            return Json::error($response, 'invalid_file', 'Nahrajte PDF, ISDOCX nebo obrázek dokladu.', 400);
        }
        $originalName = $file->getClientFilename() ?: null;
        $current = $supplierId;

        // Doklad jiné firmy uživatele se vytěží rovnou v ní. Firma se určí bez AI
        // (strojový ISDOC, IČO/DIČ v textu PDF) a jen mezi firmami, kde smí uživatel
        // vytěžovat; AI pak běží s nastavením té firmy.
        $others = ClientAiExtractionPolicy::canRouteAcrossSuppliers($request) ? array_values(array_filter(
            $this->crossSupplier->suppliersAllowing($request, ['purchase_invoices.extract']),
            static fn (int $id): bool => $id !== $current,
        )) : [];
        if ($others !== []) {
            $extension = strtolower(pathinfo((string) $originalName, PATHINFO_EXTENSION));
            try {
                $routed = $this->addressees->resolve($bytes, $extension !== '' ? $extension : null, [$current, ...$others])['supplier_id'];
            } catch (\Throwable) {
                $routed = null;
            }
            if ($routed !== null) {
                $supplierId = $routed;
            }
        }

        // Klient vždy používá výchozí model firmy. Staff ?model override validuj
        // proti whitelistu aktivního providera (jinak by
        // libovolný string protekl do klienta). Neznámý model → 400.
        $modelOverride = $client ? null : ((string) ($request->getQueryParams()['model'] ?? '') ?: null);
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
        if ($client && $this->clientQuota->reserve($supplierId) === null) {
            return Json::error($response, 'client_ai_extraction_limit_reached',
                'Měsíční limit pokusů o AI vytěžení této firmy je vyčerpán. Obraťte se na správce firmy.', 429,
                ['client_ai_extraction_usage' => $this->clientQuota->usage($supplierId)],
            );
        }
        $result = $this->extractor->extractAndCreate($supplierId, $userId, $bytes, $modelOverride, $originalName, $importBatchId,
            requireReview: $client, allowModelUpgrade: !$client,
            withoutItems: self::withoutItemsRequested($request->getQueryParams()));

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
            ] + ($supplierId !== $current ? ['uploaded_in_supplier_id' => $current] : []),
            $ip, $request->getHeaderLine('User-Agent'), $supplierId,
        );

        // AI přečetla odběratele jiné firmy uživatele. Druhé AI volání se nedělá:
        // doklad jde do příchozích dokladů té firmy i s odběratelem a vytěží se tam.
        if (!$result['ok'] && ($result['source'] ?? null) === 'wrong_tenant' && ClientAiExtractionPolicy::canRouteAcrossSuppliers($request)) {
            $handed = $this->handToOwnerInbox($request, $supplierId, $bytes, (string) ($originalName ?? 'doklad.pdf'), (array) ($result['ai_data'] ?? []), $userId);
            if ($handed !== null) {
                return Json::ok($response, $handed);
            }
        }
        if ($result['ok'] && $supplierId !== $current) {
            $result += $this->targetInfo($supplierId);
        }

        if (!$result['ok'] && ($result['source'] ?? null) === 'ai_not_configured') {
            return Json::error($response, 'ai_not_configured',
                'AI poskytovatel nemá nastavený API klíč. Nastavte ho v Firma → AI nastavení.',
                422,
                ['source' => 'ai_not_configured'],
            );
        }
        if (!$result['ok']) {
            return Json::error($response, 'extraction_failed',
                $result['error'] ?? 'Extrakce selhala',
                422,
                ['ai_data' => $result['ai_data'] ?? null, 'source' => $result['source'] ?? null],
            );
        }
        return Json::ok($response, $result, 201);
    }

    /**
     * @param array<string,mixed> $aiData
     * @return array<string,mixed>|null NULL = odběratel není jednoznačně jiná firma uživatele
     */
    private function handToOwnerInbox(Request $request, int $supplierId, string $bytes, string $name, array $aiData, int $userId): ?array
    {
        $customer = (array) ($aiData['customer'] ?? []);
        $ic = CompanyIdNormalizer::ic(isset($customer['ic']) ? (string) $customer['ic'] : null);
        $dic = CompanyIdNormalizer::dic(isset($customer['dic']) ? (string) $customer['dic'] : null);
        if ($ic === null && $dic === null) {
            return null;
        }
        $inbox = array_values(array_filter(
            $this->crossSupplier->suppliersAllowing($request, ['documents.inbox']),
            static fn (int $id): bool => $id !== $supplierId,
        ));
        $target = $inbox !== [] ? $this->addressees->byBuyer($ic, $dic, $inbox)['supplier_id'] : null;
        if ($target === null) {
            return null;
        }
        try {
            $created = $this->submissions->submitBytes($bytes, $name, $target, $userId > 0 ? $userId : null, 'staff');
        } catch (\Throwable) {
            return null;
        }
        $submissionId = (int) $created['submission']['id'];
        if (empty($created['duplicate'])) {
            $this->submissionRows->recordDetectedBuyer($submissionId, $target, $ic, $dic);
        }
        return [
            'ok' => true,
            'source' => 'handed_to_inbox',
            'submission_id' => $submissionId,
            'duplicate' => !empty($created['duplicate']),
        ] + $this->targetInfo($target);
    }

    /**
     * Jednorázová volba „Načíst bez položek" (`?without_items=1`): doklad dostane jeden
     * řádek na sazbu DPH místo rozpisu položek.
     *
     * @param array<string,mixed> $params
     */
    public static function withoutItemsRequested(array $params): bool
    {
        return in_array(strtolower(trim((string) ($params['without_items'] ?? ''))), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array{target_supplier_id:int,target_supplier_name:?string} */
    private function targetInfo(int $supplierId): array
    {
        $firm = $this->crossSupplier->describe([$supplierId])[0] ?? null;
        return ['target_supplier_id' => $supplierId, 'target_supplier_name' => $firm['name'] ?? null];
    }
}
