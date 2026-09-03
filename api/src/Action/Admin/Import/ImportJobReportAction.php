<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin\Import;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/admin/imports/{id}/report
 *
 * Report doběhlého importu na pozadí ({@see \MyInvoice\Service\Import\FileImportJobService}).
 * Stejný tvar, jaký vrací synchronní `POST /api/admin/import`, takže ho frontend
 * vykresluje týmž panelem — jen se stahuje zvlášť, jednou po doběhnutí, místo aby
 * jezdil s každým pollingem stavu (u tisíců dokladů má megabajty).
 */
final class ImportJobReportAction
{
    public function __construct(
        private readonly ImportJobRepository $jobs,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'utilities.import', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }

        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'invalid_id', 'Neplatné ID', 400);
        }

        $supplierId = SupplierGuard::currentId($request);
        $job = $this->jobs->find($id, $supplierId);
        if ($job === null || ($job['source'] ?? '') !== 'file_import') {
            return Json::error($response, 'not_found', 'Import job nenalezen.', 404);
        }
        if (empty($job['result_path'])) {
            return Json::error($response, 'no_report', 'Report zatím není k dispozici.', 409);
        }

        // Path-traversal guard: result_path je systémový, ale musí zůstat pod úložištěm
        // jobů daného tenanta. Porovnání case-insensitive — realpath() vrací na Windows
        // nekonzistentní casing.
        $base = RuntimePaths::storage('import-jobs/' . $supplierId);
        $abs = RuntimePaths::storage('') . '/' . ltrim((string) $job['result_path'], '/\\');
        $realAbs = realpath($abs);
        $realBase = realpath($base);
        if ($realAbs === false || $realBase === false
            || !str_starts_with(strtolower($realAbs), strtolower($realBase) . DIRECTORY_SEPARATOR)) {
            return Json::error($response, 'no_report', 'Report už není k dispozici.', 404);
        }

        $raw = @file_get_contents($realAbs);
        if ($raw === false) {
            return Json::error($response, 'no_report', 'Report už není k dispozici.', 404);
        }
        $report = json_decode($raw, true);
        if (!is_array($report)) {
            return Json::error($response, 'no_report', 'Report je poškozený.', 500);
        }

        return Json::ok($response, $report);
    }
}
