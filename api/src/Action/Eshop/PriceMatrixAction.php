<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Eshop\Pricing\PriceMatrixCsv;
use MyInvoice\Service\Eshop\Pricing\PriceMatrixService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PriceMatrixAction
{
    use CatalogPricingActionSupport;

    public function __construct(
        private readonly Connection $db,
        private readonly PriceMatrixService $matrix,
        private readonly PriceMatrixCsv $csv,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function preview(Request $request, Response $response): Response
    {
        if (($error = $this->requirePricingAccess($request, $response)) !== null) {
            return $error;
        }
        $body = $request->getParsedBody();
        if (!is_array($body) || !is_array($body['selection'] ?? null) || !is_array($body['options'] ?? null)) {
            return Json::error($response, 'validation_failed', 'Výběr a volby cenové matice musí být objekty.', 400);
        }
        try {
            $job = $this->matrix->preview($this->currentSupplierId($request), $body['selection'], $body['options'], $this->userId($request));
            $this->logPricing($request, 'eshop.price_matrix_preview', (int) $job['id'], []);
            return Json::ok($response, $this->matrix->present($job), 202);
        } catch (\Throwable $error) {
            return $this->pricingError($response, $error);
        }
    }

    public function apply(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->requirePricingAccess($request, $response)) !== null) {
            return $error;
        }
        try {
            $job = $this->matrix->apply($this->currentSupplierId($request), (int) ($args['id'] ?? 0), $this->userId($request));
            $this->logPricing($request, 'eshop.price_matrix_apply', (int) $job['id'], ['preview_id' => (int) $args['id']]);
            return Json::ok($response, $this->matrix->present($job), 202);
        } catch (\Throwable $error) {
            return $this->pricingError($response, $error);
        }
    }

    public function items(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->requirePricingAccess($request, $response)) !== null) {
            return $error;
        }
        $query = $request->getQueryParams();
        try {
            return Json::ok($response, $this->matrix->items(
                $this->currentSupplierId($request),
                (int) ($args['id'] ?? 0),
                (int) ($query['page'] ?? 1),
                (int) ($query['limit'] ?? 50),
                isset($query['status']) && is_string($query['status']) ? $query['status'] : null,
                isset($query['currency']) && is_string($query['currency']) ? $query['currency'] : null,
                isset($query['issue']) && is_string($query['issue']) ? $query['issue'] : null,
            ));
        } catch (\Throwable $error) {
            return $this->pricingError($response, $error);
        }
    }

    public function export(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->requirePricingAccess($request, $response)) !== null) {
            return $error;
        }
        try {
            $supplierId = $this->currentSupplierId($request);
            $job = $this->matrix->requireJob($supplierId, (int) ($args['id'] ?? 0));
            if ($job['status'] !== 'completed') {
                throw new \InvalidArgumentException('CSV lze exportovat až po dokončení úlohy.');
            }
            foreach ($this->csv->export($supplierId, $job['id'], (string) ($request->getQueryParams()['view'] ?? 'after')) as $chunk) {
                $response->getBody()->write($chunk);
            }
            return $response
                ->withHeader('Content-Type', 'text/csv; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="price-matrix-' . $job['id'] . '.csv"')
                ->withHeader('X-Content-Type-Options', 'nosniff');
        } catch (\Throwable $error) {
            return $this->pricingError($response, $error);
        }
    }

    public function importPreview(Request $request, Response $response): Response
    {
        if (($error = $this->requirePricingAccess($request, $response)) !== null) {
            return $error;
        }
        try {
            $file = $request->getUploadedFiles()['file'] ?? null;
            if ($file === null || $file->getError() !== UPLOAD_ERR_OK || ($file->getSize() ?? 0) > PriceMatrixCsv::MAX_BYTES) {
                throw new \InvalidArgumentException('Nahrajte platný CSV soubor do 50 MB.');
            }
            $parsed = $this->csv->import($file->getStream()->getContents());
            $job = $this->matrix->preview(
                $this->currentSupplierId($request),
                $parsed['selection'],
                $parsed['options'],
                $this->userId($request),
            );
            $this->logPricing($request, 'eshop.price_matrix_import_preview', (int) $job['id'], []);
            return Json::ok($response, $this->matrix->present($job), 202);
        } catch (\Throwable $error) {
            return $this->pricingError($response, $error);
        }
    }
}
