<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin\Import;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Migration\Myucto\MyuctoImportException;
use MyInvoice\Service\Migration\Myucto\MyuctoImportWorkflow;
use MyInvoice\Service\Migration\Myucto\MyuctoImportJobService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/** Browser-only native import into the selected company through the shared worker. */
final class MyuctoMigrationAction
{
    public function __construct(
        private readonly MyuctoImportWorkflow $workflow,
        private readonly MyuctoImportJobService $jobs,
    ) {
    }

    public function initChunked(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, function (int $supplier, int $actor) use ($request): array {
            $body = (array) $request->getParsedBody();
            return $this->workflow->init($supplier, $actor, is_string($body['file_name'] ?? null) ? $body['file_name'] : '', $body['size'] ?? null);
        });
    }

    public function chunk(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, function (int $supplier, int $actor) use ($request, $args): array {
            $body = (array) $request->getParsedBody();
            $offset = filter_var($body['offset'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            $file = $request->getUploadedFiles()['chunk'] ?? null;
            if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK || !is_int($offset)) {
                throw new MyuctoImportException('invalid_chunk', 'Nahrajte část exportu s platnou pozicí.');
            }
            return $this->workflow->chunk($supplier, $actor, (string) $args['token'], $offset, $file->getStream());
        });
    }

    public function complete(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, fn (int $supplier, int $actor): array => $this->workflow->complete($supplier, $actor, (string) $args['token']));
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, fn (int $supplier, int $actor): array => $this->workflow->show($supplier, $actor, (string) $args['token']));
    }

    public function run(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, function (int $supplier, int $actor) use ($request, $args): array {
            $body = (array) $request->getParsedBody();
            $mode = $body['mode'] ?? null;
            if (!in_array($mode, ['dry_run', 'import'], true) || !is_string($body['source'] ?? null)
                || (isset($body['password']) && (!is_string($body['password']) || strlen($body['password']) > 1024))) {
                throw new MyuctoImportException('invalid_options', 'Vyplňte zdroj a platný režim importu.');
            }
            $started = $this->jobs->start(
                $supplier,
                $actor,
                (string) $args['token'],
                $body['source'],
                $body['password'] ?? null,
                $mode === 'import',
                ($body['confirmed'] ?? false) === true
            );
            $this->jobs->launch((int) $started['job_id']);
            return $started;
        }, 202);
    }

    public function runs(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, fn (int $supplier, int $actor): array => $this->jobs->history($supplier, $actor));
    }

    public function status(Request $request, Response $response, array $args): Response
    {
        return $this->handle($request, $response, fn (int $supplier, int $actor): array => $this->jobs->status($supplier, $actor, (int) $args['id']));
    }

    private function handle(Request $request, Response $response, \Closure $operation, int $status = 200): Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::sessionRequired($response);
        }
        foreach (['utilities.import', 'accounting.journal.write', 'settings.company.write'] as $permission) {
            if (!RequestAuthorization::allows($request, $permission, AccessLevel::WRITE)) {
                return Json::error($response, 'forbidden', 'K převodu firmy potřebujete právo importu, účetního deníku a nastavení firmy.', 403);
            }
        }
        $supplier = SupplierGuard::currentId($request);
        $actor = (int) (((array) $request->getAttribute(AuthMiddleware::ATTR_USER, []))['id'] ?? 0);
        if ($supplier <= 0 || $actor <= 0) {
            return Json::error($response, 'no_supplier', 'Vyberte cílovou firmu.', 400);
        }
        try {
            return Json::ok($response, $operation($supplier, $actor), $status);
        } catch (MyuctoImportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->getCode(), $e->context);
        } catch (\JsonException $e) {
            return Json::error($response, 'invalid_export', 'Export obsahuje neplatná JSON data.', 422);
        } catch (\PDOException $e) {
            error_log('MyÚčto import: databázová operace selhala.');
            return Json::error($response, 'import_failed', 'Import selhal při práci s databází. Data zkontrolujte opakováním zkoušky.', 500);
        } catch (\RuntimeException $e) {
            return Json::error($response, 'import_rejected', $e->getMessage(), 422);
        }
    }
}
