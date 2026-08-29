<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Repository\Payroll\PayrollDocumentRepository;
use MyInvoice\Service\Payroll\Document\AnnualPayrollSheetService;
use MyInvoice\Service\Payroll\Document\PayrollDocumentBatchQueueService;
use MyInvoice\Service\Payroll\Document\PayrollDocumentKeyDestroyedException;
use MyInvoice\Service\Payroll\Document\PayrollDocumentDeliveryLedgerService;
use MyInvoice\Service\Payroll\Document\PayrollDocumentService;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

final class PayrollDocumentAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollDocumentService $documents,
        private readonly PayrollDocumentRepository $documentRepository,
        private readonly PayrollDocumentDeliveryLedgerService $deliveryLedger,
        private readonly AnnualPayrollSheetService $annualPayrollSheets,
        private readonly PayrollDocumentBatchQueueService $batch,
        private readonly PayrollModuleAccess $moduleAccess,
        private readonly ActivityLogger $activity,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** @param array<string,string> $args */
    public function list(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.documents',
            AccessLevel::READ,
            $error,
        ) || !$this->requirePayrollEnabled($request, $response, $this->moduleAccess, $error)) {
            return $error ?? Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        $query = $request->getQueryParams();
        $period = trim((string) ($query['period'] ?? ''));
        // Strop je tvrdý, ne jen výchozí: seznam dokumentů roste s počtem lidí
        // i s počtem období, takže „limit=99999" z URL nesmí projít.
        $limit = self::pageLimit($query);
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $employeeId = self::narrowingId($query, 'employee_id');
        try {
            $result = $this->documents->listForPeriod(
                $this->currentSupplierId($request),
                $period,
                $limit,
                $offset,
                $employeeId,
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error($response, 'validation_failed', $exception->getMessage(), 422);
        }

        // Klíč `items` zůstává, aby stávající volající nespadli; `total`/`limit`/
        // `offset` přibyly vedle něj, protože seznam už nemusí být úplný.
        // `employee_id` hlásí uplatněné zúžení: bez něj vypadá zúžené prázdno
        // stejně jako prázdný měsíc.
        return Json::ok($response, [
            'period' => $period,
            'revisions' => $result['revisions'],
            'items' => $this->publicDocuments($this->currentSupplierId($request), $result['items']),
            'total' => $result['total'],
            'limit' => $limit,
            'offset' => $offset,
            'employee_id' => $employeeId,
        ]);
    }

    /** @param array<string,string> $args */
    public function listAnnual(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.documents',
            AccessLevel::READ,
            $error,
        ) || !$this->requirePayrollEnabled($request, $response, $this->moduleAccess, $error)) {
            return $error ?? Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        $query = $request->getQueryParams();
        $year = (int) ($query['year'] ?? 0);
        if ($year < 2000 || $year > 2199) {
            return Json::error($response, 'validation_failed', 'Rok dokumentů není platný.', 422);
        }
        $limit = self::pageLimit($query);
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $employeeId = self::narrowingId($query, 'employee_id');
        try {
            $page = $this->documentRepository->listAnnualDocuments(
                $this->currentSupplierId($request),
                $year,
                $limit,
                $offset,
                $employeeId,
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error($response, 'validation_failed', $exception->getMessage(), 422);
        }

        return Json::ok($response, [
            'year' => $year,
            'items' => $this->publicDocuments($this->currentSupplierId($request), $page['items']),
            'total' => $page['total'],
            'limit' => $limit,
            'offset' => $offset,
            'employee_id' => $employeeId,
        ]);
    }

    /** @param array<array-key,mixed> $query */
    private static function pageLimit(array $query): int
    {
        return max(1, min(
            PayrollDocumentRepository::LIST_MAX_LIMIT,
            (int) ($query['limit'] ?? PayrollDocumentRepository::LIST_DEFAULT_LIMIT),
        ));
    }

    /** @param array<string,string> $args */
    public function generatePayrollSheet(
        Request $request,
        Response $response,
        array $args,
    ): Response {
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.documents',
            AccessLevel::WRITE,
            $error,
        ) || !$this->requirePayrollEnabled($request, $response, $this->moduleAccess, $error)) {
            return $error ?? Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        $supplierId = $this->currentSupplierId($request);
        $userId = $this->userId($request);
        $employeeId = (int) ($args['employeeId'] ?? 0);
        $year = (int) ($args['year'] ?? 0);
        if ($userId === null || $employeeId <= 0 || $year < 2000 || $year > 2199) {
            return Json::error($response, 'validation_failed', 'Požadavek na mzdový list není platný.', 422);
        }
        try {
            $document = $this->annualPayrollSheets->generate(
                $supplierId,
                $employeeId,
                $year,
                $userId,
            );
        } catch (\DomainException|\InvalidArgumentException $exception) {
            return Json::error($response, 'payroll_sheet_incomplete', $exception->getMessage(), 422);
        } catch (\Throwable) {
            return Json::error(
                $response,
                'payroll_sheet_failed',
                'Mzdový list se nepodařilo vytvořit. Zkontrolujte úplnost schválených mezd a profilu zaměstnance.',
                409,
            );
        }
        $this->activity->log(
            'payroll.annual_sheet_generated',
            $userId,
            'payroll_document',
            (int) $document['id'],
            [
                'document_kind' => $document['document_kind'],
                'annual_revision_id' => $document['annual_revision_id'],
                'file_sha256' => $document['file_sha256'],
            ],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
        return Json::ok($response, self::publicDocument($document), 201);
    }

    /** @param array<string,string> $args */
    public function generateBundle(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.documents',
            AccessLevel::WRITE,
            $error,
        ) || !$this->requirePayrollEnabled($request, $response, $this->moduleAccess, $error)) {
            return $error ?? Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        $supplierId = $this->currentSupplierId($request);
        $userId = $this->userId($request);
        $runId = (int) ($args['runId'] ?? 0);
        $revisionId = (int) ($args['revisionId'] ?? 0);
        $idempotencyKey = trim($request->getHeaderLine('Idempotency-Key'));
        if ($userId === null || $runId <= 0 || $revisionId <= 0 || $idempotencyKey === '') {
            return Json::error($response, 'validation_failed', 'Požadavek na mzdový balíček je neplatný.', 422);
        }
        try {
            $batch = $this->batch->forRevision($supplierId, $revisionId);
            if ($batch === null
                || (int) $batch['run_id'] !== $runId
                || (string) $batch['status'] !== 'completed'
            ) {
                throw new \DomainException(
                    'Měsíční ZIP vznikne až po úspěšném dokončení všech osob ve frontě.',
                );
            }
            $document = $this->documents->generateMonthlyBundle(
                $supplierId,
                $runId,
                $revisionId,
                $idempotencyKey,
                $userId,
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error($response, 'validation_failed', $exception->getMessage(), 422);
        } catch (\DomainException $exception) {
            return Json::error($response, 'bundle_not_ready', $exception->getMessage(), 409);
        } catch (\Throwable) {
            return Json::error($response, 'bundle_failed', 'Mzdový balíček nelze vytvořit.', 409);
        }
        $this->activity->log(
            'payroll.monthly_bundle_generated',
            $userId,
            'payroll_document',
            (int) $document['id'],
            [
                'document_kind' => $document['document_kind'],
                'revision_id' => $document['revision_id'],
                'annual_revision_id' => $document['annual_revision_id'],
                'file_sha256' => $document['file_sha256'],
            ],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
        return Json::ok($response, self::publicDocument($document), 201);
    }

    /**
     * Dávková orchestrace rendererů nad schválenou revizí. Odpověď je zpráva
     * o dokumentační úplnosti měsíce, ne jen seznam vytvořených PDF.
     *
     * @param array<string,string> $args
     */
    public function generateBatch(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.documents',
            AccessLevel::WRITE,
            $error,
        ) || !$this->requirePayrollEnabled($request, $response, $this->moduleAccess, $error)) {
            return $error ?? Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        $supplierId = $this->currentSupplierId($request);
        $userId = $this->userId($request);
        $runId = (int) ($args['runId'] ?? 0);
        $revisionId = (int) ($args['revisionId'] ?? 0);
        if ($userId === null || $runId <= 0 || $revisionId <= 0) {
            return Json::error(
                $response,
                'validation_failed',
                'Požadavek na dávku dokumentů je neplatný.',
                422,
            );
        }
        try {
            $report = $this->batch->enqueueApprovedRevision(
                $supplierId,
                $runId,
                $revisionId,
                $userId,
                $request->getHeaderLine('Idempotency-Key'),
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error($response, 'validation_failed', $exception->getMessage(), 422);
        } catch (\Throwable) {
            return Json::error(
                $response,
                'document_batch_failed',
                'Dávku výstupních dokumentů nelze bezpečně dokončit.',
                409,
            );
        }
        $this->activity->log(
            'payroll.document_batch_queued',
            $userId,
            'payroll_run_revision',
            $revisionId,
            [
                'run_id' => $runId,
                'item_count' => $report['item_count'],
                'status' => $report['status'],
            ],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return Json::ok($response, ['batch' => $report], 202)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /** @param array<string,string> $args */
    public function batchDetail(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.documents',
            AccessLevel::READ,
            $error,
        ) || !$this->requirePayrollEnabled($request, $response, $this->moduleAccess, $error)) {
            return $error ?? Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        $batch = $this->batch->detail(
            $this->currentSupplierId($request),
            (int) ($args['batchId'] ?? 0),
        );
        if ($batch === null) {
            return Json::error($response, 'not_found', 'Dávka dokumentů nebyla nalezena.', 404);
        }
        return Json::ok($response, ['batch' => $batch])
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /** @param array<string,string> $args */
    public function batchItems(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.documents',
            AccessLevel::READ,
            $error,
        ) || !$this->requirePayrollEnabled($request, $response, $this->moduleAccess, $error)) {
            return $error ?? Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        $query = $request->getQueryParams();
        try {
            $items = $this->batch->items(
                $this->currentSupplierId($request),
                (int) ($args['batchId'] ?? 0),
                max(1, min(100, (int) ($query['limit'] ?? 50))),
                max(0, (int) ($query['offset'] ?? 0)),
            );
        } catch (\OutOfBoundsException) {
            return Json::error($response, 'not_found', 'Dávka dokumentů nebyla nalezena.', 404);
        }
        return Json::ok($response, $items)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /** @param array<string,string> $args */
    public function retryBatchItem(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.documents',
            AccessLevel::WRITE,
            $error,
        ) || !$this->requirePayrollEnabled($request, $response, $this->moduleAccess, $error)) {
            return $error ?? Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        try {
            $item = $this->batch->retry(
                $this->currentSupplierId($request),
                (int) ($args['batchId'] ?? 0),
                (int) ($args['itemId'] ?? 0),
            );
        } catch (\DomainException $exception) {
            return Json::error(
                $response,
                'document_batch_retry_invalid',
                $exception->getMessage(),
                409,
            );
        }
        return Json::ok($response, ['item' => $item], 202)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /** @param array<string,string> $args */
    public function grant(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.documents',
            AccessLevel::READ,
            $error,
        ) || !$this->requirePayrollEnabled($request, $response, $this->moduleAccess, $error)) {
            return $error ?? Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        $supplierId = $this->currentSupplierId($request);
        $userId = $this->userId($request);
        $documentId = (int) ($args['documentId'] ?? 0);
        if ($userId === null || $documentId <= 0) {
            return Json::error($response, 'not_found', 'Mzdový dokument nebyl nalezen.', 404);
        }
        try {
            $grant = $this->documents->issueDownloadGrant(
                $supplierId,
                $documentId,
                $userId,
            );
        } catch (\Throwable) {
            return Json::error($response, 'not_found', 'Mzdový dokument nebyl nalezen.', 404);
        }
        return Json::ok($response, $grant)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /** @param array<string,string> $args */
    public function download(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.documents',
            AccessLevel::READ,
            $error,
        ) || !$this->requirePayrollEnabled($request, $response, $this->moduleAccess, $error)) {
            return $error ?? Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        $supplierId = $this->currentSupplierId($request);
        $userId = $this->userId($request);
        $documentId = (int) ($args['documentId'] ?? 0);
        $token = trim($request->getHeaderLine('X-Payroll-Download-Token'));
        if ($userId === null || $documentId <= 0) {
            return Json::error($response, 'not_found', 'Mzdový dokument nebyl nalezen.', 404);
        }
        try {
            $download = $this->documents->consumeDownload(
                $supplierId,
                $documentId,
                $userId,
                $token,
            );
            $this->deliveryLedger->recordViewedIfPersonalDocument(
                $supplierId,
                $documentId,
                $userId,
            );
        } catch (PayrollDocumentKeyDestroyedException) {
            // Krypto-výmaz (W30 / C-06) není chybějící dokument ani chyba
            // serveru: řádek i soubor existují, jen je jejich obsah po výmazu
            // osobních údajů nevratně nečitelný. 410 to říká přesně a účetní
            // se nemusí ptát, kam se páska poděla.
            return Json::error(
                $response,
                'payroll_document_erased',
                'Dokument je po výmazu osobních údajů nečitelný. Evidence '
                    . 'o jeho vydání zůstává, obsah už obnovit nelze.',
                410,
            );
        } catch (\Throwable) {
            return Json::error($response, 'not_found', 'Mzdový dokument nebyl nalezen.', 404);
        }
        $document = $download['document'];
        $bytes = $download['bytes'];
        $handle = fopen('php://temp', 'w+b');
        if ($handle === false || fwrite($handle, $bytes) !== strlen($bytes)) {
            return Json::error($response, 'download_failed', 'Dokument nelze stáhnout.', 500);
        }
        rewind($handle);

        $this->activity->log(
            'payroll.document_downloaded',
            $userId,
            'payroll_document',
            $documentId,
            [
                'document_kind' => $document['document_kind'],
                'revision_id' => $document['revision_id'],
                'file_sha256' => $document['file_sha256'],
            ],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        return $response
            ->withHeader('Content-Type', (string) $document['mime_type'])
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="' . (string) $document['suggested_filename'] . '"',
            )
            ->withHeader('Content-Length', (string) strlen($bytes))
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->withBody(new Stream($handle));
    }

    /** @param array<string,string> $args */
    public function deliveryEvents(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.documents',
            AccessLevel::READ,
            $error,
        ) || !$this->requirePayrollEnabled($request, $response, $this->moduleAccess, $error)) {
            return $error ?? Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        try {
            $events = $this->deliveryLedger->forDocument(
                $this->currentSupplierId($request),
                (int) ($args['documentId'] ?? 0),
            );
        } catch (\DomainException) {
            return Json::error($response, 'not_found', 'Mzdový dokument nebyl nalezen.', 404);
        }
        return Json::ok($response, ['events' => $events])
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /** @param array<string,string> $args */
    public function recordDeliveryEvent(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission(
            $request,
            $response,
            'payroll.documents',
            AccessLevel::WRITE,
            $error,
        ) || !$this->requirePayrollEnabled($request, $response, $this->moduleAccess, $error)) {
            return $error ?? Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        $actorUserId = $this->userId($request);
        $body = (array) $request->getParsedBody();
        $eventType = trim((string) ($body['event_type'] ?? ''));
        if ($actorUserId === null || !in_array($eventType, ['handover', 'external_notification'], true)) {
            return Json::error($response, 'validation_failed', 'Doručovací událost není platná.', 422);
        }
        try {
            $event = $this->deliveryLedger->record(
                $this->currentSupplierId($request),
                (int) ($args['documentId'] ?? 0),
                $actorUserId,
                $eventType,
            );
        } catch (\DomainException) {
            return Json::error($response, 'not_found', 'Mzdový dokument nebyl nalezen.', 404);
        }
        return Json::ok($response, ['event' => $event], 201)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /**
     * @param list<array<string,mixed>> $documents
     * @return list<array<string,mixed>>
     */
    private function publicDocuments(int $supplierId, array $documents): array
    {
        $summaries = $this->deliveryLedger->summaries(
            $supplierId,
            array_map(static fn (array $document): int => (int) $document['id'], $documents),
        );
        return array_map(
            static function (array $document) use ($summaries): array {
                $public = self::publicDocument($document);
                $summary = $summaries[(int) $document['id']] ?? null;
                if ($summary !== null) {
                    $public['delivery'] = $summary;
                }
                return $public;
            },
            $documents,
        );
    }

    /**
     * @param array<string,mixed> $document
     * @return array<string,mixed>
     */
    private static function publicDocument(array $document): array
    {
        $keys = [
            'id', 'run_id', 'revision_id', 'revision_no', 'revision_status',
            'annual_revision_id', 'annual_revision_no', 'tax_year', 'purpose',
            'office_id', 'office_name',
            'employee_id', 'employee_name', 'document_kind', 'document_revision_no',
            'supersedes_document_id', 'file_sha256', 'size_bytes', 'mime_type',
            'suggested_filename', 'created_at',
        ];
        $result = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $document)) {
                $result[$key] = $document[$key];
            }
        }
        return $result;
    }
}
