<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Action\Stock\GuardsStockEnabled;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Eshop\CatalogExportService;
use MyInvoice\Service\Eshop\EshopException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

final class CatalogExportAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(private readonly Connection $db, private readonly CatalogExportService $exports) {}

    public function create(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'eshop', AccessLevel::READ, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $body = $request->getParsedBody();
        if (!is_array($body) || array_diff(array_keys($body), ['selection', 'projection']) !== []
            || !is_array($body['selection'] ?? null) || !is_array($body['projection'] ?? [])) {
            return Json::error($response, 'validation_failed', 'Neplatný výběr nebo projekce exportu.', 400);
        }
        try {
            $job = $this->exports->enqueue($supplierId, $body['selection'], $body['projection'] ?? [], $this->userId($request));
            unset($job['input']);
            return Json::ok($response, $job, 202);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        } catch (EshopException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    public function download(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'eshop', AccessLevel::READ, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $id = (int) ($args['id'] ?? 0);
        try {
            return $response->withBody(new Stream($this->exports->download($supplierId, $id)))
                ->withHeader('Content-Type', 'application/x-ndjson; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="catalog-' . $id . '.jsonl"')
                ->withHeader('Cache-Control', 'private, no-store');
        } catch (EshopException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }
}
