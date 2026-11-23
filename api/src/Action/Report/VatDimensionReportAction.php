<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\VatDimensionReportPdfRenderer;
use MyInvoice\Service\Report\VatDimensionReportService;
use MyInvoice\Service\Report\VatDimensionReportXlsxExporter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * DPH podle dimenze — evidence DPH za období po hodnotách typu dimenze s kontrolou
 * na přiznání a na obrat účtu 343 ({@see VatDimensionReportService}).
 *
 *   GET /api/reports/vat-by-dimension/preview?year=2026&month=10&period=monthly&type_id=11 → JSON
 *   GET /api/reports/vat-by-dimension?…&format=pdf|xlsx                                     → soubor
 *
 * Práva jako Kniha DPH: náhled `reports`, export `reports.export`.
 */
final class VatDimensionReportAction
{
    public function __construct(
        private readonly VatDimensionReportService $service,
        private readonly VatDimensionReportPdfRenderer $pdf,
        private readonly VatDimensionReportXlsxExporter $xlsx,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function preview(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'reports', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $report = $this->report($request, $response);
        return $report instanceof Response ? $report : Json::ok($response, $report);
    }

    public function download(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'reports.export', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $format = (string) ($request->getQueryParams()['format'] ?? 'pdf');
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            return Json::error($response, 'validation_failed', 'Neplatný formát (pdf, xlsx).', 400);
        }
        $report = $this->report($request, $response);
        if ($report instanceof Response) {
            return $report;
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $this->logger->log('report.vat_by_dimension_downloaded', (int) ($user['id'] ?? 0), null, null, [
            'period' => $report['period']['label'], 'dimension_type' => $report['dimension_type']['name'], 'format' => $format,
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'));

        $period = $report['period'];
        $base = sprintf('dph-podle-dimenze-%04d-', $period['year'])
            . ($period['quarter'] !== null ? 'Q' . $period['quarter'] : sprintf('%02d', $period['month']));
        try {
            if ($format === 'xlsx') {
                $bytes = $this->xlsx->export($report);
                $mime = VatDimensionReportXlsxExporter::MIME;
            } else {
                $bytes = $this->pdf->render($report);
                $mime = 'application/pdf';
            }
        } catch (\Throwable $e) {
            return Json::internalError($response, 'build_failed', $e->getMessage(), 500);
        }
        $response->getBody()->write($bytes);
        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $base . '.' . $format . '"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /** @return array<string,mixed>|Response */
    private function report(Request $request, Response $response): array|Response
    {
        $q = $request->getQueryParams();
        $year = (int) ($q['year'] ?? date('Y'));
        $month = (int) ($q['month'] ?? date('n'));
        if ($month < 1 || $month > 12 || $year < 2020 || $year > 2050) {
            return Json::error($response, 'validation_failed', 'Neplatný rok/měsíc.', 400);
        }
        $period = (string) ($q['period'] ?? 'monthly');
        if (!in_array($period, ['monthly', 'quarterly'], true)) {
            return Json::error($response, 'validation_failed', 'Neplatné období (monthly, quarterly).', 400);
        }
        $typeId = is_scalar($q['type_id'] ?? null) ? (string) $q['type_id'] : '';
        if (preg_match('/^[1-9][0-9]{0,9}$/', $typeId) !== 1) {
            return Json::error($response, 'validation_failed', 'Zadejte typ dimenze (type_id).', 400);
        }
        try {
            return $this->service->build(SupplierGuard::currentId($request), $year, $month, $period, (int) $typeId);
        } catch (DimensionException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            return Json::internalError($response, 'build_failed', $e->getMessage(), 500);
        }
    }
}
