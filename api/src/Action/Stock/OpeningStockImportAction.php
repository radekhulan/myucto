<?php

declare(strict_types=1);

namespace MyInvoice\Action\Stock;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\Eshop\Import\CatalogImportReader;
use MyInvoice\Service\Eshop\Import\CatalogImportSourceStore;
use MyInvoice\Service\Stock\OpeningStockImportProfile;
use MyInvoice\Service\Stock\OpeningStockImportService;
use MyInvoice\Service\Stock\StockException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

final class OpeningStockImportAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly CatalogImportSourceStore $sources,
        private readonly CatalogImportReader $reader,
        private readonly OpeningStockImportService $imports,
    ) {}

    public function upload(Request $request, Response $response): Response
    {
        return $this->run($request, $response, function (int $supplierId) use ($request): array {
            $file = $request->getUploadedFiles()['file'] ?? null;
            if (!$file instanceof UploadedFileInterface) {
                throw new \InvalidArgumentException('import_file_required');
            }
            return $this->sources->upload($supplierId, $file, $this->userId($request));
        }, 201);
    }

    public function sample(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $supplierId) use ($request, $args): array {
            $source = $this->sources->get($supplierId, (int) $args['id']);
            $query = $request->getQueryParams();
            $options = array_intersect_key($query, array_flip(['encoding', 'delimiter', 'sheet']));
            if (isset($options['sheet'])) {
                if (!is_string($options['sheet']) || !preg_match('/^\d{1,4}$/D', $options['sheet'])) {
                    throw new \InvalidArgumentException('opening_import_sheet_invalid');
                }
                $options['sheet'] = (int) $options['sheet'];
            }
            $sample = [];
            foreach ($this->reader->rows($this->sources->path($supplierId, $source['id']), $source['format'], $options) as $row) {
                $sample[] = $row;
                if (count($sample) === 6) {
                    break;
                }
            }
            return [
                'source' => $source,
                'header' => array_shift($sample) ?? [],
                'rows' => $sample,
                'fields' => OpeningStockImportProfile::FIELDS,
            ];
        });
    }

    public function preview(Request $request, Response $response): Response
    {
        return $this->run($request, $response, function (int $supplierId) use ($request): array {
            $body = $request->getParsedBody();
            if (!is_array($body) || !is_int($body['source_id'] ?? null) || !is_array($body['config'] ?? null)) {
                throw new \InvalidArgumentException('opening_import_profile_invalid');
            }
            $job = $this->imports->preview($supplierId, $body['source_id'], $body['config'], $this->userId($request));
            unset($job['input']);
            return $job;
        }, 202);
    }

    public function apply(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $supplierId) use ($request, $args): array {
            $job = $this->imports->apply($supplierId, (int) $args['id'], $this->userId($request));
            unset($job['input']);
            return $job;
        }, 202);
    }

    private function run(Request $request, Response $response, callable $handler, int $status = 200): Response
    {
        if (!$this->requirePermission($request, $response, 'stock.documents.write', AccessLevel::WRITE, $error)) {
            return $error;
        }
        if ($request->getMethod() !== 'GET' && !RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response);
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $error)) {
            return $error;
        }
        try {
            return Json::ok($response, $handler($supplierId), $status);
        } catch (EshopException|StockException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
    }
}
