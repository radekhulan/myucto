<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Reports;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Reports\GeneralLedgerService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Accounting\Reports\ReportXlsxExporter;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\GeneralLedgerPdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Hlavní kniha (Epic F2) — PS, měsíční obraty a KS per účet, jen posted zápisy (R1).
 *
 *   GET /api/accounting/reports/general-ledger        — data sestavy
 *   GET /api/accounting/reports/general-ledger/export — PDF / XLSX (?format=pdf|xlsx)
 */
final class GeneralLedgerAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly GeneralLedgerService $ledger,
        private readonly AccountingPeriodRepository $periods,
        private readonly GeneralLedgerPdfRenderer $pdf,
        private readonly ReportXlsxExporter $xlsx,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly LoggerInterface $log,
        private readonly Connection $db,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $params = $this->validateParams($request, $response, $supplierId, $err);
        if ($params === null) return $err;

        try {
            $data = $params['all_periods']
                ? $this->ledger->buildAllPeriods($supplierId, $params['from'], $params['to'], $params['analytics'], $params['filters'], $params['after_closing'])
                : $this->ledger->build($supplierId, $params['period_id'], $params['from'], $params['to'], $params['analytics'], $params['filters'], $params['after_closing']);
        } catch (ReportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            $this->log->error('Účetní sestavu se nepodařilo sestavit: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'build_failed', 'Sestavu se nepodařilo vytvořit.', 500);
        }

        return Json::ok($response, $data);
    }

    public function export(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $params = $this->validateParams($request, $response, $supplierId, $err);
        if ($params === null) return $err;

        $format = strtolower(trim((string) ($request->getQueryParams()['format'] ?? '')));
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            return Json::error($response, 'validation_failed', "format musí být 'pdf' nebo 'xlsx'.", 422);
        }

        try {
            $data = $params['all_periods']
                ? $this->ledger->buildAllPeriods($supplierId, $params['from'], $params['to'], $params['analytics'], $params['filters'], $params['after_closing'])
                : $this->ledger->build($supplierId, $params['period_id'], $params['from'], $params['to'], $params['analytics'], $params['filters'], $params['after_closing']);
            $periodLabel = $params['all_periods'] ? 'vse' : (string) ($data['period']['fiscal_year'] ?? '');
            $out = $format === 'pdf'
                ? [
                    'bytes'    => $this->pdf->render($data),
                    'filename' => sprintf('hlavni-kniha-%s.pdf', $periodLabel),
                    'mime'     => 'application/pdf',
                ]
                : $this->xlsx->generalLedger($data);
        } catch (ReportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            $this->log->error('Účetní sestavu se nepodařilo sestavit: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'build_failed', 'Sestavu se nepodařilo vytvořit.', 500);
        }

        $this->logger->log('report.accounting_export', $this->userId($request), 'report', null,
            ['report' => 'general_ledger', 'format' => $format],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $supplierId);

        $response->getBody()->write($out['bytes']);
        return $response
            ->withHeader('Content-Type', $out['mime'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $out['filename'] . '"')
            ->withHeader('Content-Length', (string) strlen($out['bytes']))
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * @return array{period_id:?int, all_periods:bool, from:?string, to:?string, analytics:bool, after_closing:bool, filters:array{vendor?:string, client?:string, item?:string}}|null
     */
    private function validateParams(Request $request, Response $response, int $supplierId, ?Response &$err): ?array
    {
        $q = $request->getQueryParams();

        $allPeriods = (string) ($q['all_periods'] ?? '') === '1';
        $periodId = null;
        if ($allPeriods) {
            $periods = $this->periods->listForTenant($supplierId);
            if ($periods === []) {
                $err = Json::error($response, 'not_found', 'Firma nemá žádné účetní období.', 404);
                return null;
            }
            $rangeStart = (string) min(array_column($periods, 'starts_on'));
            $rangeEnd = (string) max(array_column($periods, 'ends_on'));
        } else {
            $periodId = (int) ($q['period_id'] ?? 0);
            if ($periodId <= 0) {
                $err = Json::error($response, 'validation_failed', 'period_id je povinný.', 422);
                return null;
            }
            $period = $this->periods->findById($supplierId, $periodId);
            if ($period === null) {
                $err = Json::error($response, 'not_found', 'Účetní období nenalezeno.', 404);
                return null;
            }
            $rangeStart = (string) $period['starts_on'];
            $rangeEnd = (string) $period['ends_on'];
        }

        $range = [];
        foreach (['from', 'to'] as $key) {
            $range[$key] = null;
            $v = trim((string) ($q[$key] ?? ''));
            if ($v === '') continue;
            if (!$this->isDate($v)) {
                $err = Json::error($response, 'validation_failed', "{$key} musí být datum (YYYY-MM-DD).", 422);
                return null;
            }
            if ($v < $rangeStart || $v > $rangeEnd) {
                $scope = $allPeriods ? 'rozsahu účetních období' : 'zvoleného období';
                $err = Json::error($response, 'validation_failed', "{$key} musí ležet uvnitř {$scope}.", 422);
                return null;
            }
            $range[$key] = $v;
        }
        if ($range['from'] !== null && $range['to'] !== null && $range['from'] > $range['to']) {
            $err = Json::error($response, 'validation_failed', 'from nesmí být větší než to.', 422);
            return null;
        }

        // Hledání dle protistrany/položky zdrojového dokladu (dodavatel/odběratel/text
        // položky) — viz LedgerReportRepository::counterpartyFilter(). Délkově omezené
        // stejně jako `q` v deníku (Featura D).
        $filters = [];
        if (!empty($q['vendor'])) $filters['vendor'] = mb_substr(trim((string) $q['vendor']), 0, 100);
        if (!empty($q['client'])) $filters['client'] = mb_substr(trim((string) $q['client']), 0, 100);
        if (!empty($q['item']))   $filters['item']   = mb_substr(trim((string) $q['item']), 0, 100);

        $err = null;
        return [
            'period_id' => $periodId,
            'all_periods' => $allPeriods,
            'from'      => $range['from'],
            'to'        => $range['to'],
            'analytics' => (string) ($q['analytics'] ?? '') === '1',
            // Výchozí je stav PŘED uzavřením knih — po uzavření jsou rozvahové účty
            // vynulované a účetní by k rozvahovému dni neviděla žádné zůstatky.
            'after_closing' => (string) ($q['after_closing'] ?? '') === '1',
            'filters'   => $filters,
        ];
    }

    private function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
