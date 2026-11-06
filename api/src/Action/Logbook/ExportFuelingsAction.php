<?php

declare(strict_types=1);

namespace MyInvoice\Action\Logbook;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Service\Logbook\FuelingExportService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Export tankování do XLSX / PDF za zvolené období.
 *   GET /api/logbook/fuelings/export?format=xlsx|pdf&date_from=&date_to=&car_id=&source=
 */
final class ExportFuelingsAction
{
    public function __construct(private readonly FuelingExportService $exporter) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0) return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);

        $q = $request->getQueryParams();
        $format = strtolower((string) ($q['format'] ?? 'xlsx'));
        if (!in_array($format, ['xlsx', 'pdf'], true)) {
            return Json::error($response, 'invalid_format', 'Formát musí být xlsx nebo pdf.', 400);
        }
        $filters = array_intersect_key($q, array_flip(['car_id', 'date_from', 'date_to', 'source', 'vendor_id']));

        try {
            $out = $this->exporter->export($supplierId, $format, $filters);
        } catch (\Throwable $e) {
            return Json::internalError($response, 'export_failed', $e->getMessage(), 500);
        }

        $response->getBody()->write($out['bytes']);
        return $response
            ->withHeader('Content-Type', $out['mime'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $out['filename'] . '"')
            ->withHeader('Content-Length', (string) strlen($out['bytes']))
            ->withHeader('Cache-Control', 'private, no-store');
    }
}
