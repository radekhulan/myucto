<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Assets;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AssetRepository;
use MyInvoice\Repository\DepreciationEntryRepository;
use MyInvoice\Service\Accounting\Assets\AssetException;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\Reports\AssetDepreciationCardReportService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Export\ExportFilename;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\AssetDepreciationCardPdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Karty dlouhodobého majetku (Epic F3) — CRUD + kandidáti z přijatých faktur.
 *
 *   GET    /api/accounting/assets                     — seznam (filtr status, fulltext, stránkování)
 *   POST   /api/accounting/assets                     — nová karta — účetní|admin
 *   GET    /api/accounting/assets/purchase-candidates — PF s příznakem majetku (podklad pro kartu)
 *   GET    /api/accounting/assets/{id}                — detail karty (vč. TZ a locked flagů R13)
 *   PUT    /api/accounting/assets/{id}                — úprava karty — účetní|admin
 *   DELETE /api/accounting/assets/{id}                — smazání konceptu/chybného zařazení — účetní|admin
 *   GET    /api/accounting/assets/{id}/depreciation-card — inventární karta majetku (PDF, #49)
 *
 * Hloubkovou validaci karty (matice druh × daňová metoda, zámky R13) dělá AssetService;
 * AssetException se mapuje na errorCode/httpStatus, Throwable na neutrální 500 (poučení F2 §8).
 */
final class AssetAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    private const MAX_PER_PAGE = 200;

    public function __construct(
        private readonly AssetService $service,
        private readonly AssetRepository $assets,
        private readonly DepreciationEntryRepository $entries,
        private readonly AssetDepreciationCardReportService $depreciationCardReport,
        private readonly AssetDepreciationCardPdfRenderer $depreciationCardPdf,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly LoggerInterface $log,
        private readonly Connection $db,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        $q = $request->getQueryParams();

        $status = trim((string) ($q['status'] ?? ''));
        if ($status !== '' && !in_array($status, ['draft', 'in_use', 'disposed'], true)) {
            return Json::error($response, 'validation_failed', "status musí být 'draft', 'in_use' nebo 'disposed'.", 422);
        }

        $search = trim((string) ($q['q'] ?? ''));
        $filters = [
            'status'   => $status !== '' ? $status : null,
            'q'        => $search !== '' ? $search : null,
            'page'     => max(1, (int) ($q['page'] ?? 1)),
            'per_page' => max(1, min(self::MAX_PER_PAGE, (int) ($q['per_page'] ?? 50))),
        ];

        try {
            return Json::ok($response, $this->assets->list($supplierId, $filters));
        } catch (\Throwable $e) {
            return $this->serverError($response, $e, 'Seznam majetku se nepodařilo načíst');
        }
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        $body = (array) ($request->getParsedBody() ?? []);
        if ($body === []) {
            return Json::error($response, 'validation_failed', 'Tělo požadavku je prázdné.', 422);
        }

        try {
            $result = $this->service->create($supplierId, $body, $this->auditMeta($request));
        } catch (AssetException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            return $this->serverError($response, $e, 'Kartu majetku se nepodařilo založit');
        }

        $id = (int) ($result['asset']['id'] ?? 0);
        $this->logEvent($request, 'asset.created', $id > 0 ? $id : null, [
            'inventory_number' => (string) ($result['asset']['inventory_number'] ?? ($body['inventory_number'] ?? '')),
        ]);
        return Json::ok($response, $result, 201);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        $id = (int) ($args['id'] ?? 0);

        try {
            $asset = $this->assets->find($supplierId, $id);
            if ($asset === null) {
                return Json::error($response, 'not_found', 'Majetek nenalezen.', 404);
            }
            // Zámky editace (R13): daňové parametry + VC po prvním potvrzeném daňovém
            // řádku; pořizovací pole po zařazení do užívání.
            $asset['improvements'] = $this->assets->improvements($id);
            $asset['locked'] = [
                'tax_params'  => $this->entries->existsAnyTax($id),
                'acquisition' => (string) ($asset['status'] ?? '') !== 'draft',
            ];
            return Json::ok($response, $asset);
        } catch (\Throwable $e) {
            return $this->serverError($response, $e, 'Detail majetku se nepodařilo načíst');
        }
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        $id = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        if ($body === []) {
            return Json::error($response, 'validation_failed', 'Tělo požadavku je prázdné.', 422);
        }

        try {
            $result = $this->service->update($supplierId, $id, $body);
        } catch (AssetException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (PostingException $e) {
            return $this->mapPostingError($response, $e);
        } catch (\Throwable $e) {
            return $this->serverError($response, $e, 'Kartu majetku se nepodařilo upravit');
        }

        $this->logEvent($request, 'asset.updated', $id, ['fields' => array_keys($body)]);
        return Json::ok($response, $result);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        $id = (int) ($args['id'] ?? 0);

        try {
            $deleted = $this->service->delete($supplierId, $id);
        } catch (AssetException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            return $this->serverError($response, $e, 'Kartu majetku se nepodařilo smazat');
        }

        $this->logEvent($request, 'asset.deleted', $id, $deleted);
        return Json::ok($response, ['deleted' => true, 'activation_entry_deleted' => $deleted['activation_entry_id'] !== null]);
    }

    public function purchaseCandidates(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);

        try {
            return Json::ok($response, $this->assets->purchaseCandidates($supplierId));
        } catch (\Throwable $e) {
            return $this->serverError($response, $e, 'Kandidáty z přijatých faktur se nepodařilo načíst');
        }
    }

    /**
     * Inventární karta majetku (§29–30 ZoÚ, #49) — tlačítko stažení v detailu karty.
     * Aktuální stav (dnešek), celý daňový odpisový plán {@see AssetDepreciationCardReportService}.
     */
    public function depreciationCard(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        $id = (int) ($args['id'] ?? 0);

        try {
            $data = $this->depreciationCardReport->buildForAsset($supplierId, $id);
            $bytes = $this->depreciationCardPdf->render($data);
        } catch (ReportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            return $this->serverError($response, $e, 'Inventární kartu majetku se nepodařilo vytvořit');
        }

        $card = $data['cards'][0];
        $this->logEvent($request, 'asset.depreciation_card_downloaded', $id, [
            'inventory_number' => $card['inventory_number'],
        ]);

        $filename = sprintf(
            'inventarni-karta-%s.pdf',
            ExportFilename::sanitize((string) ($card['inventory_number'] ?? $id), (string) $id),
        );
        $response->getBody()->write($bytes);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string) strlen($bytes))
            ->withHeader('Cache-Control', 'private, no-store');
    }

    private function serverError(Response $response, \Throwable $e, string $logPrefix): Response
    {
        $this->log->error($logPrefix . ': ' . $e->getMessage(), ['exception' => $e]);
        return Json::error($response, 'operation_failed', 'Operaci se nepodařilo dokončit.', 500);
    }

    private function logEvent(Request $request, string $action, ?int $entityId, array $payload): void
    {
        $this->logger->log($action, $this->userId($request), 'asset', $entityId, $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $this->currentSupplierId($request));
    }
}
