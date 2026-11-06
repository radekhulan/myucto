<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Reports;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\ReadOnlySnapshot;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\AccountCodeRange;
use MyInvoice\Service\Accounting\AccountCodeRangeException;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService;
use MyInvoice\Service\Accounting\Reports\GeneralLedgerService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Accounting\Reports\ReportXlsxExporter;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\GeneralLedgerDetailPdfRenderer;
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
    use DimensionFilterParam;

    public function __construct(
        private readonly GeneralLedgerService $ledger,
        private readonly AccountingPeriodRepository $periods,
        private readonly GeneralLedgerPdfRenderer $pdf,
        private readonly ReportXlsxExporter $xlsx,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly LoggerInterface $log,
        private readonly Connection $db,
        private readonly DimensionService $dimensions,
        private readonly GeneralLedgerDetailService $details,
        private readonly GeneralLedgerDetailPdfRenderer $detailPdf,
        private readonly ReadOnlySnapshot $snapshot,
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
            $data = GeneralLedgerDetailService::selectAccounts($data, $params['account_from'], $params['account_to']);
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

        $includeDocuments = $request->getQueryParams()['include_documents'] ?? '0';
        if (!is_string($includeDocuments)) {
            return Json::error($response, 'validation_failed', 'include_documents musí být jedna hodnota.', 422);
        }
        $includeDocuments = trim($includeDocuments);
        if (!in_array($includeDocuments, ['0', '1'], true)) {
            return Json::error($response, 'validation_failed', 'include_documents musí být 0 nebo 1.', 422);
        }
        // Rozpis do XLSX se zatím nenabízí: řádky PS a součtů účtu v jedné tabulce s doklady by
        // rozbily součty a kontingenční tabulky; podobu XLSX je potřeba domluvit s maintainerem.
        if ($includeDocuments === '1' && $format !== 'pdf') {
            return Json::error($response, 'validation_failed', 'Rozpis včetně dokladů je zatím jen v PDF.', 422);
        }
        try {
            // Souhrn a dávky dokladů musí číst tentýž snímek i při souběžném účtování
            // a i na serveru, jehož výchozí izolace je READ COMMITTED.
            [$data, $out] = $this->snapshot->read(function () use ($params, $supplierId, $includeDocuments, $format): array {
                $forDetail = $includeDocuments === '1';
                $data = $params['all_periods']
                    ? $this->ledger->buildAllPeriods($supplierId, $params['from'], $params['to'], $params['analytics'], $params['filters'], $params['after_closing'], $forDetail)
                    : $this->ledger->build($supplierId, $params['period_id'], $params['from'], $params['to'], $params['analytics'], $params['filters'], $params['after_closing'], $forDetail);
                $periodLabel = $params['all_periods'] ? 'vse' : (string) ($data['period']['fiscal_year'] ?? '');
                $data = GeneralLedgerDetailService::selectAccounts($data, $params['account_from'], $params['account_to']);
                if ($includeDocuments === '1') {
                    $this->details->checkExportSize($data);
                    $supplier = $this->details->supplierIdentity($supplierId);
                    $data['supplier_name'] = $supplier['name'];
                    $data['supplier_ic'] = $supplier['ic'];
                    $data['after_closing'] = $params['after_closing'];
                    // Ve snímku se řádky jen načtou (a ověří proti souhrnu) do dočasného souboru.
                    // Sazba PDF trvá desítky sekund a nesmí po tu dobu držet transakci se zámkem
                    // metadat deníku: migrace za provozu by na ni čekala a za ní celá aplikace.
                    $spool = new \SplTempFileObject(2 * 1024 * 1024);
                    foreach ($this->details->events($supplierId, $data, $params['filters'], $params['after_closing']) as $event) {
                        $spool->fwrite(json_encode($event, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) . "\n");
                    }
                    return [$data, ['spool' => $spool, 'filename' => 'hlavni-kniha-doklady-' . $periodLabel . '.pdf', 'mime' => 'application/pdf']];
                }
                return [$data, $format === 'pdf'
                    ? [
                        'bytes'    => $this->pdf->render($data),
                        'filename' => sprintf('hlavni-kniha-%s.pdf', $periodLabel),
                        'mime'     => 'application/pdf',
                    ]
                    : $this->xlsx->generalLedger($data)];
            });
            if (isset($out['spool'])) {
                $data['events'] = self::replayEvents($out['spool']);
                $out['bytes'] = $this->detailPdf->render($data);
                unset($out['spool']);
            }
        } catch (ReportException $e) {
            if ($e->errorCode === 'ledger_detail_mismatch') {
                $this->log->error('Rozpis hlavní knihy nesouhlasí se souhrnem.', ['supplier_id' => $supplierId] + $e->context);
            }
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            $this->log->error('Účetní sestavu se nepodařilo sestavit: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'build_failed', 'Sestavu se nepodařilo vytvořit.', 500);
        }

        $this->logger->log('report.accounting_export', $this->userId($request), 'report', null,
            ['report' => 'general_ledger', 'format' => $format, 'include_documents' => $includeDocuments === '1', 'account_from' => $data['account_from'], 'account_to' => $data['account_to']],
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
     * Události rozpisu zpět z dočasného souboru, po jedné, mimo transakci snímku.
     *
     * @return \Generator<int,array<string,mixed>>
     */
    private static function replayEvents(\SplTempFileObject $spool): \Generator
    {
        $spool->rewind();
        while (!$spool->eof()) {
            $line = $spool->fgets();
            if ($line === '' || $line === "\n") continue;
            yield json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        }
    }

    /**
     * @return array{period_id:?int, all_periods:bool, from:?string, to:?string, analytics:bool, after_closing:bool, filters:array{vendor?:string, client?:string, item?:string}, account_from:string, account_to:string}|null
     */
    private function validateParams(Request $request, Response $response, int $supplierId, ?Response &$err): ?array
    {
        $q = $request->getQueryParams();

        // Rozsah účtů se ověří před jakýmkoli dotazem: neplatná mez nesmí spustit agregaci celé knihy.
        $from = $q['account_from'] ?? '';
        $to = $q['account_to'] ?? '';
        if (!is_string($from) || !is_string($to)) {
            $err = Json::error($response, 'validation_failed', 'account_from a account_to musí být jedna hodnota.', 422);
            return null;
        }
        try {
            $accounts = AccountCodeRange::parse($from, $to);
        } catch (AccountCodeRangeException $e) {
            $err = Json::error($response, 'validation_failed', $e->getMessage(), 422);
            return null;
        }

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
        $dimension = $this->dimensionFilterParam($this->dimensions, $request, $response, $supplierId, $err);
        if ($dimension === false) return null;
        if ($dimension !== null) $filters['dimension'] = $dimension;

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
            // Rozsah účtů (prefixový včetně krajů, AccountCodeRange) filtruje knihu i export.
            'account_from' => $accounts->from,
            'account_to'   => $accounts->to,
        ];
    }

    private function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
