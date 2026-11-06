<?php

declare(strict_types=1);

namespace MyInvoice\Action\Stock;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\Eshop\Import\CatalogImportReader;
use MyInvoice\Service\Eshop\Import\CatalogImportSourceStore;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockHistoryImportProfile;
use MyInvoice\Service\Stock\StockHistoryImportService;
use MyInvoice\Service\Stock\StockReportXlsxExporter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Factory\StreamFactory;

final class StockHistoryImportAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly CatalogImportSourceStore $sources,
        private readonly CatalogImportReader $reader,
        private readonly StockHistoryImportService $imports,
        private readonly StockReportXlsxExporter $xlsx,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** Stav převzaté historie firmy: datum zámku a poslední aplikace importu. */
    public function status(Request $request, Response $response): Response
    {
        return $this->run($request, $response, function (int $supplierId): array {
            $stmt = $this->db->pdo()->prepare("SELECT s.stock_history_locked_until,
                    (SELECT MIN(d.doc_date) FROM stock_documents d WHERE d.supplier_id = s.id
                        AND d.status IN ('posted','reversed') AND d.history_import_job_id IS NULL) AS first_document_date
                FROM supplier s WHERE s.id = ?");
            $stmt->execute([$supplierId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            $active = $this->imports->active($supplierId);
            unset($active['input']['source_sha256']);
            return [
                'locked_until' => $row['stock_history_locked_until'] ?? null,
                // Navržené datum přechodu: první skladový doklad vzniklý v MyÚčtu.
                'first_document_date' => $row['first_document_date'] ?? null,
                'active' => $active,
                'orphan_job_id' => $this->imports->orphan($supplierId),
                'max_rows' => StockHistoryImportProfile::MAX_ROWS,
            ];
        });
    }

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
                    throw new \InvalidArgumentException('history_import_sheet_invalid');
                }
                $options['sheet'] = (int) $options['sheet'];
            }
            $sample = [];
            foreach ($this->reader->rows($this->sources->path($supplierId, $source['id']), $source['format'], $options, 0, StockHistoryImportProfile::MAX_ROWS) as $row) {
                $sample[] = $row;
                if (count($sample) === 6) {
                    break;
                }
            }
            return [
                'source' => $source,
                'header' => array_shift($sample) ?? [],
                'rows' => $sample,
                'fields' => StockHistoryImportProfile::REQUIRED,
                'optional_fields' => StockHistoryImportProfile::OPTIONAL,
            ];
        });
    }

    public function preview(Request $request, Response $response): Response
    {
        return $this->run($request, $response, function (int $supplierId) use ($request): array {
            $body = $request->getParsedBody();
            if (!is_array($body) || !is_int($body['source_id'] ?? null) || !is_array($body['config'] ?? null)) {
                throw new \InvalidArgumentException('history_import_profile_invalid');
            }
            $job = $this->imports->preview($supplierId, $body['source_id'], $body['config'], $this->userId($request));
            unset($job['input']);
            return $job;
        }, 202);
    }

    public function apply(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $supplierId) use ($request, $args): array {
            $body = $request->getParsedBody();
            $confirm = is_array($body) && ($body['confirm_closed_periods'] ?? false) === true;
            $job = $this->imports->apply($supplierId, (int) $args['id'], $this->userId($request), $confirm);
            $this->log($request, 'stock.history_import_applied', $job['id'], [
                'preview_id' => (int) $args['id'],
                'source_key' => $job['input']['profile']['source_key'],
                'cutover_date' => $job['input']['profile']['cutover_date'],
                'lines' => $job['total'],
                'confirm_closed_periods' => $confirm,
            ]);
            unset($job['input']);
            return $job;
        }, 202);
    }

    /** Koncový stav historie z náhledu proti počátečnímu stavu v MyÚčtu, JSON nebo XLSX (?format=xlsx). */
    public function endState(Request $request, Response $response, array $args): Response
    {
        $format = $request->getQueryParams()['format'] ?? 'json';
        if (!in_array($format, ['json', 'xlsx'], true)) {
            return Json::error($response, 'validation_failed', "format musí být 'json' nebo 'xlsx'.", 422);
        }
        $data = null;
        $result = $this->run($request, $response, function (int $supplierId) use ($args, &$data): array {
            return $data = $this->imports->endState($supplierId, (int) $args['id']);
        });
        if ($format === 'json' || $data === null) {
            return $result;
        }
        $out = $this->xlsx->historyEndState($data);
        return $result
            ->withBody((new StreamFactory())->createStream($out['bytes']))
            ->withHeader('Content-Type', $out['mime'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $out['filename'] . '"')
            ->withHeader('Content-Length', (string) strlen($out['bytes']))
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /** Vrácení aktivního importu: jen správce firmy, zapíše se do auditního logu. */
    public function rollback(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::canRollbackStockHistoryImport($request)) {
            return Json::error($response, 'forbidden', 'Import historie může vrátit jen správce firmy.', 403);
        }
        return $this->run($request, $response, function (int $supplierId) use ($request, $args): array {
            $body = $request->getParsedBody();
            $confirm = is_array($body) && ($body['confirm_closed_periods'] ?? false) === true;
            $job = $this->imports->rollback($supplierId, (int) $args['id'], $this->userId($request), $confirm);
            $this->log($request, 'stock.history_import_rolled_back', $job['id'], [
                'apply_job_id' => (int) $args['id'],
                'cutover_date' => $job['input']['cutover_date'],
                'documents' => $job['total'],
                'orphan' => $job['input']['orphan'],
                'confirm_closed_periods' => $confirm,
            ]);
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

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'catalog_job',
            $id,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}
