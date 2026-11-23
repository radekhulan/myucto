<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\Document\PayrollArchiveDiagnostics;
use MyInvoice\Service\System\DiagnosticsBundleOptions;
use MyInvoice\Service\System\DiagnosticsBundleService;
use MyInvoice\Service\System\DiagnosticsLogReader;
use MyInvoice\Service\System\EnvironmentCheckService;
use MyInvoice\Service\System\ManagedModeGuard;
use MyInvoice\Service\System\ImportedPostingDiagnostics;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/**
 * Admin endpointy sekce Systém → Diagnostika:
 *   GET  /api/admin/diagnostics                   — audit prostředí s verdiktem
 *   GET  /api/admin/diagnostics/bundle/preview    — co přesně bude v balíčku
 *   POST /api/admin/diagnostics/bundle            — sestavit ZIP
 *   GET  /api/admin/diagnostics/bundle/download   — stáhnout sestavený ZIP
 *   GET  /api/admin/diagnostics/logs              — stránkovaný náhled výřezu logu
 *
 * Balíček se nikam neodesílá. Zákazník si ho stáhne a k incidentu ho na portálu
 * podpory přiloží sám — proto tu není žádný odchozí klient.
 */
final class DiagnosticsAction
{
    public function __construct(
        private readonly EnvironmentCheckService $environment,
        private readonly DiagnosticsBundleService $bundle,
        private readonly DiagnosticsLogReader $logs,
        private readonly ActivityLogger $activity,
        private readonly PayrollArchiveDiagnostics $payrollArchive,
        private readonly ImportedPostingDiagnostics $importedPostings,
        private readonly ManagedModeGuard $managed,
    ) {}

    /** GET /api/admin/diagnostics */
    public function report(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request, $response, $err)) {
            return $err;
        }

        $environment = $this->environment->report();
        if ($this->managed->isManaged()) {
            $environment = EnvironmentCheckService::withoutDiskSpace($environment);
        }
        $report = $this->payrollArchive->appendTo($environment);
        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $report['imported_posting_ambiguities'] = !empty($report['facts']['database']['available']) ? $this->importedPostings->report(
            $supplierId,
            max(0, (int) ($request->getQueryParams()['posting_after'] ?? 0)),
        ) : ImportedPostingDiagnostics::unavailable($supplierId);
        return Json::ok($response, $report);
    }

    /** GET /api/admin/diagnostics/bundle/preview */
    public function preview(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request, $response, $err)) {
            return $err;
        }
        $options = DiagnosticsBundleOptions::fromArray($request->getQueryParams());

        return Json::ok($response, $this->bundle->preview($options));
    }

    /** GET /api/admin/diagnostics/logs */
    public function logPreview(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request, $response, $err)) {
            return $err;
        }
        $query = $request->getQueryParams();
        $days  = max(1, min(DiagnosticsLogReader::MAX_DAYS, (int) ($query['days'] ?? DiagnosticsLogReader::DEFAULT_DAYS)));
        $level = strtoupper(trim((string) ($query['level'] ?? DiagnosticsLogReader::DEFAULT_LEVEL)));
        if (!in_array($level, DiagnosticsLogReader::levels(), true)) {
            $level = DiagnosticsLogReader::DEFAULT_LEVEL;
        }

        $available = $this->logs->daysInWindow($days);
        $day       = (string) ($query['day'] ?? ($available[0] ?? ''));
        if ($day === '' || !in_array($day, $available, true)) {
            return Json::ok($response, [
                'day'       => null,
                'days'      => $available,
                'page'      => 1,
                'per_page'  => 100,
                'total'     => 0,
                'lines'     => [],
                'truncated' => false,
            ]);
        }

        $preview = $this->logs->preview(
            $day,
            $level,
            (int) ($query['page'] ?? 1),
            (int) ($query['per_page'] ?? 100)
        );
        $preview['days'] = $available;

        return Json::ok($response, $preview);
    }

    /** POST /api/admin/diagnostics/bundle */
    public function create(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request, $response, $err)) {
            return $err;
        }

        $body    = (array) ($request->getParsedBody() ?? []);
        $options = DiagnosticsBundleOptions::fromArray($body);
        $result  = $this->bundle->build($options);

        if (empty($result['ok'])) {
            return Json::error($response, (string) $result['error'], 'Diagnostický balíček se nepodařilo vytvořit.', 422);
        }

        // Zákazník musí mít vlastní doklad o tom, co a kdy odeslal — proto se
        // rozsah i otisk balíčku zapisují do auditní stopy.
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $this->activity->log('system.diagnostics.bundle', (int) ($user['id'] ?? 0), null, null, [
            'filename'     => $result['filename'],
            'bytes'        => $result['bytes'],
            'sha256'       => $result['sha256'],
            'include_logs' => $options->includeLogs,
            'days'         => $options->days,
            'log_level'    => $options->logLevel,
        ]);

        return Json::ok($response, $result);
    }

    /** GET /api/admin/diagnostics/bundle/download?file=… */
    public function download(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request, $response, $err)) {
            return $err;
        }

        $filename = (string) ($request->getQueryParams()['file'] ?? '');
        $path     = $this->bundle->resolvePath($filename);
        if ($path === null) {
            return Json::error($response, 'not_found', 'Balíček neexistuje nebo už byl smazán.', 404);
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return Json::error($response, 'not_readable', 'Balíček se nepodařilo otevřít.', 500);
        }

        return $response
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', 'attachment; filename="' . basename($path) . '"')
            ->withHeader('Content-Length', (string) @filesize($path))
            ->withHeader('Cache-Control', 'no-store')
            ->withBody(new Stream($handle));
    }

    private function isAdmin(Request $request, Response $response, ?Response &$err): bool
    {
        if (!RequestAuthorization::isSuperadmin($request)) {
            $err = Json::error($response, 'forbidden', 'Pouze admin.', 403);

            return false;
        }
        $err = null;

        return true;
    }
}
