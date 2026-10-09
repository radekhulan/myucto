<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\KhEvidencePdfRenderer;
use MyInvoice\Service\Report\KhEvidenceService;
use MyInvoice\Service\Report\KhEvidenceXlsxExporter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Evidence pro kontrolní hlášení (issue #142) — soupis dokladů oddílu KH z aktuálních dat.
 *
 *   GET /api/reports/kh-evidence/preview?year=2026&month=4&period=monthly&section=A.4 → JSON
 *   GET /api/reports/kh-evidence?…&format=pdf|xlsx                                    → soubor
 */
final class KhEvidenceAction
{
    public function __construct(
        private readonly KhEvidenceService $service,
        private readonly KhEvidencePdfRenderer $pdf,
        private readonly KhEvidenceXlsxExporter $xlsx,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function preview(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'reports', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $params = self::parse($request);
        if (is_string($params)) {
            return Json::error($response, 'validation_failed', $params, 400);
        }
        try {
            $report = $this->service->current(SupplierGuard::currentId($request), ...$params);
        } catch (\Throwable $e) {
            return Json::error($response, 'build_failed', $e->getMessage(), 500);
        }
        return Json::ok($response, $report);
    }

    public function download(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'reports.export', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $params = self::parse($request);
        if (is_string($params)) {
            return Json::error($response, 'validation_failed', $params, 400);
        }
        $format = (string) ($request->getQueryParams()['format'] ?? 'pdf');
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            return Json::error($response, 'validation_failed', 'Neplatný formát (pdf, xlsx).', 400);
        }
        try {
            $report = $this->service->current(SupplierGuard::currentId($request), ...$params);
        } catch (\Throwable $e) {
            return Json::error($response, 'build_failed', $e->getMessage(), 500);
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $this->logger->log('report.kh_evidence_downloaded', (int) ($user['id'] ?? 0), null, null, [
            'period' => $report['period']['label'], 'section' => $report['section'], 'format' => $format,
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'));

        return self::file($response, $this->pdf, $this->xlsx, $report, self::filename($report, 'aktualni'), $format);
    }

    /**
     * GET /api/reports/submissions/{id}/kh-evidence?section=A.4 — soupis podle PODANÉHO KH
     * porovnaný po dokladech s aktuálními daty.
     */
    public function submittedPreview(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'reports', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $report = $this->submittedReport($request, $response, (int) $args['id']);
        return $report instanceof Response ? $report : Json::ok($response, $report);
    }

    /** GET /api/reports/submissions/{id}/kh-evidence/export?section=A.4&format=pdf|xlsx */
    public function submittedDownload(Request $request, Response $response, array $args): Response
    {
        if (!RequestAuthorization::allows($request, 'reports.export', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $format = (string) ($request->getQueryParams()['format'] ?? 'pdf');
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            return Json::error($response, 'validation_failed', 'Neplatný formát (pdf, xlsx).', 400);
        }
        $report = $this->submittedReport($request, $response, (int) $args['id']);
        if ($report instanceof Response) {
            return $report;
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $this->logger->log('report.kh_evidence_downloaded', (int) ($user['id'] ?? 0), null, null, [
            'period' => $report['period']['label'], 'section' => $report['section'], 'format' => $format,
            'submission_id' => (int) $args['id'],
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'));

        return self::file($response, $this->pdf, $this->xlsx, $report, self::filename($report, 'podane'), $format);
    }

    /** @return array<string,mixed>|Response */
    private function submittedReport(Request $request, Response $response, int $id): array|Response
    {
        $section = KhEvidenceService::normalizeSection(isset($request->getQueryParams()['section']) ? (string) $request->getQueryParams()['section'] : null);
        if ($section === null || $section === 'none') {
            return Json::error($response, 'validation_failed', 'Neplatný oddíl kontrolního hlášení.', 400);
        }
        try {
            return $this->service->submitted(SupplierGuard::currentId($request), $id, $section);
        } catch (\DomainException $e) {
            return Json::error($response, 'kh_evidence_unavailable', $e->getMessage(), 422);
        } catch (\Throwable $e) {
            return Json::error($response, 'build_failed', $e->getMessage(), 500);
        }
    }

    /**
     * @param array<string,mixed> $report
     */
    public static function file(Response $response, KhEvidencePdfRenderer $pdf, KhEvidenceXlsxExporter $xlsx, array $report, string $base, string $format): Response
    {
        if ($format === 'xlsx') {
            $out = $xlsx->export($report, $base . '.xlsx');
            $bytes = $out['bytes'];
            $mime = $out['mime'];
        } else {
            $bytes = $pdf->render($report);
            $mime = 'application/pdf';
        }
        $response->getBody()->write($bytes);
        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $base . '.' . $format . '"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string,mixed> $report */
    public static function filename(array $report, string $suffix): string
    {
        $p = $report['period'];
        $period = $p['quarter'] !== null ? sprintf('%04d-Q%d', $p['year'], $p['quarter']) : sprintf('%04d-%02d', $p['year'], $p['month']);
        $section = $report['section'] === 'all' ? 'vse' : str_replace('.', '', strtolower((string) $report['section']));
        return sprintf('soupis-kh-%s-%s-%s', $period, $section, $suffix);
    }

    /**
     * @return array{0:int, 1:int, 2:string, 3:string}|string pole parametrů, nebo chybová hláška
     */
    private static function parse(Request $request): array|string
    {
        $q = $request->getQueryParams();
        $year = (int) ($q['year'] ?? date('Y'));
        $month = (int) ($q['month'] ?? date('n'));
        if ($month < 1 || $month > 12 || $year < 2020 || $year > 2050) {
            return 'Neplatný rok/měsíc.';
        }
        $period = (string) ($q['period'] ?? 'monthly');
        if (!in_array($period, ['monthly', 'quarterly'], true)) {
            $period = 'monthly';
        }
        $section = KhEvidenceService::normalizeSection(isset($q['section']) ? (string) $q['section'] : null);
        if ($section === null) {
            return 'Neplatný oddíl kontrolního hlášení.';
        }
        return [$year, $month, $period, $section];
    }
}
