<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Action\Stock\GuardsStockEnabled;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\Eshop\Import\CatalogImportProfile;
use MyInvoice\Service\Eshop\Import\CatalogImportProfileStore;
use MyInvoice\Service\Eshop\Import\CatalogImportPresets;
use MyInvoice\Service\Eshop\Import\CatalogImportReader;
use MyInvoice\Service\Eshop\Import\CatalogImportService;
use MyInvoice\Service\Eshop\Import\CatalogImportSourceStore;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

final class CatalogImportAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly CatalogImportSourceStore $sources,
        private readonly CatalogImportProfileStore $profiles,
        private readonly CatalogImportPresets $presets,
        private readonly CatalogImportReader $reader,
        private readonly CatalogImportService $imports,
    ) {}

    public function upload(Request $request, Response $response): Response
    {
        return $this->run($request, $response, function (int $sid) use ($request): array {
            $file = $request->getUploadedFiles()['file'] ?? null;
            if (!$file instanceof UploadedFileInterface) {
                throw new \InvalidArgumentException('import_file_required');
            }
            return $this->sources->upload($sid, $file, $this->userId($request));
        }, 201);
    }

    public function sample(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $sid) use ($request, $args): array {
            $source = $this->sources->get($sid, (int) $args['id']);
            $query = $request->getQueryParams();
            $options = array_intersect_key($query, array_flip(['encoding', 'delimiter', 'sheet']));
            if (isset($options['sheet'])) {
                if (!is_string($options['sheet']) || !preg_match('/^\d{1,4}$/D', $options['sheet'])) {
                    throw new \InvalidArgumentException('import_sheet_invalid');
                }
                $options['sheet'] = (int) $options['sheet'];
            }
            $sample = [];
            foreach ($this->reader->rows($this->sources->path($sid, $source['id']), $source['format'], $options) as $row) {
                $sample[] = $row;
                if (count($sample) === 6) {
                    break;
                }
            }
            return ['source' => $source, 'header' => array_shift($sample) ?? [], 'rows' => $sample, 'fields' => CatalogImportProfile::FIELDS];
        });
    }

    public function profiles(Request $request, Response $response): Response
    {
        return $this->run($request, $response, fn (int $sid): array => [
            'items' => $this->profiles->list($sid),
            'presets' => $this->presets->all(),
        ]);
    }

    public function saveProfile(Request $request, Response $response, array $args = []): Response
    {
        return $this->run($request, $response, function (int $sid) use ($request, $args): array {
            $body = $request->getParsedBody();
            if (!is_array($body) || !is_string($body['name'] ?? null) || !is_array($body['config'] ?? null)
                || (isset($args['id']) && !is_int($body['version'] ?? null))) {
                throw new \InvalidArgumentException('import_profile_invalid');
            }
            return $this->profiles->save($sid, isset($args['id']) ? (int) $args['id'] : null,
                $body['version'] ?? null, $body['name'], $body['config']);
        }, isset($args['id']) ? 200 : 201);
    }

    public function preview(Request $request, Response $response): Response
    {
        return $this->run($request, $response, function (int $sid) use ($request): array {
            $body = $request->getParsedBody();
            if (!is_array($body) || !is_int($body['source_id'] ?? null) || !is_array($body['config'] ?? null)) {
                throw new \InvalidArgumentException('import_profile_invalid');
            }
            $job = $this->imports->preview($sid, $body['source_id'], $body['config'], $this->userId($request));
            unset($job['input']);
            return $job;
        }, 202);
    }

    public function apply(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, function (int $sid) use ($request, $args): array {
            $job = $this->imports->apply($sid, (int) $args['id'], $this->userId($request));
            unset($job['input']);
            return $job;
        }, 202);
    }

    private function run(Request $request, Response $response, callable $handler, int $status = 200): Response
    {
        if (!$this->requirePermission($request, $response, 'eshop.write', AccessLevel::WRITE, $error)
            || !$this->requirePermission($request, $response, 'stock.items.write', AccessLevel::WRITE, $error)) {
            return $error;
        }
        if ($request->getMethod() !== 'GET' && !RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response);
        }
        $sid = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $sid, $response, $error)) {
            return $error;
        }
        try {
            return Json::ok($response, $handler($sid), $status);
        } catch (EshopException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return Json::error($response, 'import_profile_name_taken', 'Profil s tímto názvem již existuje.', 409);
            }
            throw $e;
        }
    }
}
