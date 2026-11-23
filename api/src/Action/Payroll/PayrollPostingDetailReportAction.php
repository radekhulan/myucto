<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Repository\Payroll\PayrollPostingDetailReportRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payroll\Export\PayrollPostingDetailExporter;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\Report\PayrollPostingDetailReportService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Sestava podrobného zaúčtování mezd za měsíc a její export.
 *
 *   GET /api/payroll/reports/posting-detail?period=YYYY-MM
 *   GET /api/payroll/reports/posting-detail/export.{xlsx|csv}?period=YYYY-MM
 *
 * Sestava nese jména a částky mezd, proto ji čte jen právo na mzdové sestavy
 * v přihlášené relaci (stejně jako report nákladů na zaměstnance) a stažení
 * exportu se eviduje.
 */
final class PayrollPostingDetailReportAction
{
    use PayrollActionSupport;

    public function __construct(
        private readonly PayrollPostingDetailReportService $reports,
        private readonly PayrollPostingDetailExporter $exporter,
        private readonly PayrollPostingDetailReportRepository $repository,
        private readonly PayrollModuleAccess $access,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function show(Request $request, Response $response): Response
    {
        $error = $this->authorize($request, $response);
        if ($error !== null) {
            return $error;
        }
        try {
            return Json::ok($response, [
                'report' => $this->reports->report(
                    $this->currentSupplierId($request),
                    (string) ($request->getQueryParams()['period'] ?? ''),
                ),
            ]);
        } catch (\InvalidArgumentException $exception) {
            return Json::error($response, 'validation_failed', $exception->getMessage(), 422);
        }
    }

    /** @param array<string,string> $args */
    public function export(Request $request, Response $response, array $args): Response
    {
        $error = $this->authorize($request, $response);
        if ($error !== null) {
            return $error;
        }
        $format = $args['format'] ?? '';
        if (!in_array($format, ['xlsx', 'csv'], true)) {
            return Json::error($response, 'validation_failed', 'Formát exportu musí být xlsx nebo csv.', 422);
        }
        $supplierId = $this->currentSupplierId($request);
        try {
            $report = $this->reports->report(
                $supplierId,
                (string) ($request->getQueryParams()['period'] ?? ''),
            );
        } catch (\InvalidArgumentException $exception) {
            return Json::error($response, 'validation_failed', $exception->getMessage(), 422);
        }
        $out = $format === 'csv'
            ? $this->exporter->csv($report)
            : $this->exporter->xlsx($report, $this->repository->companyName($supplierId));

        $this->logger->log(
            'payroll.posting_detail.exported',
            $this->userId($request),
            'payroll_run',
            null,
            ['format' => $format, 'period' => $report['period'], 'row_count' => count($report['rows'])],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );

        $response->getBody()->write($out['bytes']);

        return $response
            ->withHeader('Content-Type', $out['mime'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $out['filename'] . '"')
            ->withHeader('Content-Length', (string) strlen($out['bytes']))
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    private function authorize(Request $request, Response $response): ?Response
    {
        if (!RequestAuthorization::actsAsSession($request)) {
            return Json::sessionRequired($response, 'Tento endpoint je dostupný pouze z přihlášené webové session.');
        }
        $error = null;
        if (!$this->requirePermission($request, $response, 'payroll.reports', AccessLevel::READ, $error)
            || !$this->requirePayrollEnabled($request, $response, $this->access, $error)
        ) {
            return $error ?? Json::error($response, 'forbidden', 'Pro tuto akci nemáte oprávnění.', 403);
        }

        return null;
    }
}
