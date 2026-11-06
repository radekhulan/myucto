<?php

declare(strict_types=1);

namespace MyInvoice\Action\Logbook;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Service\Logbook\LogbookSummaryExportService;
use MyInvoice\Service\Logbook\LogbookSummaryService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Daňové/účetní souhrny knihy jízd:
 *   GET /api/logbook/summary?year=YYYY            — přehled per vozidlo + totals + dostupné roky
 *   GET /api/logbook/summary/export?year=&format= — XLSX / PDF souhrnu
 */
final class SummaryAction
{
    public function __construct(
        private readonly LogbookSummaryService $summary,
        private readonly LogbookSummaryExportService $exporter,
    ) {}

    public function view(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $years = $this->summary->availableYears($supplierId);
        $year = $this->resolveYear($request, $years);
        $data = $this->summary->periodSummary($supplierId, "$year-01-01", "$year-12-31");

        return Json::ok($response, [
            'year' => $year,
            'available_years' => $years,
            'vehicles' => $data['vehicles'],
            'totals' => $data['totals'],
            'monthly' => $this->summary->monthlyKm($supplierId, $year),
        ]);
    }

    public function export(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0) return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);

        $q = $request->getQueryParams();
        $format = strtolower((string) ($q['format'] ?? 'xlsx'));
        if (!in_array($format, ['xlsx', 'pdf'], true)) {
            return Json::error($response, 'invalid_format', 'Formát musí být xlsx nebo pdf.', 400);
        }
        $years = $this->summary->availableYears($supplierId);
        $year = $this->resolveYear($request, $years);
        $data = $this->summary->periodSummary($supplierId, "$year-01-01", "$year-12-31");

        try {
            $out = $this->exporter->export($supplierId, $format, $year, $data);
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

    /** @param list<int> $years */
    private function resolveYear(Request $request, array $years): int
    {
        $y = (int) ($request->getQueryParams()['year'] ?? 0);
        if ($y >= 2000 && $y <= 2100) return $y;
        return $years[0] ?? (int) date('Y');
    }
}
