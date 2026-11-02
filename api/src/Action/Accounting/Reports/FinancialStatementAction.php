<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Reports;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\StatementDefinitionRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Accounting\Reports\StatementMapResolver;
use MyInvoice\Service\Accounting\Reports\ReportXlsxExporter;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\AccountViewPdfRenderer;
use MyInvoice\Service\Pdf\BalanceSheetPdfRenderer;
use MyInvoice\Service\Pdf\IncomeStatementPdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Účetní výkazy (Epic F2) — rozvaha a výsledovka dle vyhl. 500/2002 Sb.,
 * verzované mapování, rozsah full/small/micro (R11/R12), minulé období (R13).
 *
 *   GET /api/accounting/reports/balance-sheet             — rozvaha
 *   GET /api/accounting/reports/balance-sheet/export      — PDF / XLSX
 *   GET /api/accounting/reports/income-statement          — výsledovka
 *   GET /api/accounting/reports/income-statement/export   — PDF / XLSX
 *   GET /api/accounting/reports/statement-accounts        — rozvaha a výsledovka po účtech
 *   GET /api/accounting/reports/statement-accounts/export — PDF / XLSX (?part=balance|profit_loss)
 */
final class FinancialStatementAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;
    use DimensionFilterParam;

    public function __construct(
        private readonly FinancialStatementService $statements,
        private readonly AccountingPeriodRepository $periods,
        private readonly BalanceSheetPdfRenderer $balanceSheetPdf,
        private readonly IncomeStatementPdfRenderer $incomeStatementPdf,
        private readonly ReportXlsxExporter $xlsx,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly LoggerInterface $log,
        private readonly Connection $db,
        private readonly StatementDefinitionRepository $definitions,
        private readonly StatementMapResolver $maps,
        private readonly DimensionService $dimensions,
        private readonly AccountViewPdfRenderer $accountViewPdf,
    ) {}

    public function balanceSheet(Request $request, Response $response): Response
    {
        return $this->view($request, $response, 'balance_sheet');
    }

    public function incomeStatement(Request $request, Response $response): Response
    {
        return $this->view($request, $response, 'income_statement');
    }

    /**
     * VZZ v účelovém členění (vyhl. 500/2002 Sb., př. 2 část II, § 39b). Samostatný
     * endpoint, ne parametr `?variant=` — jde o jiný výkaz s jinými řádky, takže by se
     * volajícímu pod stejnou adresou měnila struktura odpovědi.
     */
    public function incomeStatementByFunction(Request $request, Response $response): Response
    {
        return $this->view($request, $response, FinancialStatementService::TYPE_PURPOSE);
    }

    /**
     * Mapa funkcí — přiřazení nákladových účtů řádkům A./B./C. účelového VZZ.
     * Vrací i účty s obratem, kterým přiřazení CHYBÍ, aby účetní viděla, co doplnit
     * (bez úplné mapy se výkaz nesestaví).
     */
    public function functionMap(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $stmt = $this->db->pdo()->prepare(
            'SELECT account_prefix, function_code, note, updated_at
               FROM statement_function_map
              WHERE supplier_id = ?
              ORDER BY account_prefix'
        );
        $stmt->execute([$supplierId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return Json::ok($response, [
            'functions'  => array_keys(StatementDefinitionRepository::FUNCTION_ROWS),
            'rows'       => $rows,
            // Bez tohohle výčtu by uživatel jen dostal chybu „výkaz nelze sestavit"
            // a musel dohledávat sám, které účty chybí. `period_id` je volitelné —
            // bez něj se vrátí prázdný seznam, ne chyba.
            'unassigned' => $this->unassignedExpenseAccounts($supplierId, $request, $rows),
        ]);
    }

    /**
     * Nákladové účty s obratem v období, které NEPOKRÝVÁ ani globální mapa účelové verze,
     * ani per-firma mapa funkcí — tedy přesně ty, kvůli kterým se výkaz nesestaví.
     *
     * Globální mapa se musí započítat: ostatní provozní náklady, celá finanční část, daň
     * i převod podílu tam přiřazené JSOU a funkci se nepřiřazují. Bez nich by seznam
     * uživatele posílal přiřazovat účty, které přiřazovat nemá.
     *
     * Pokrytí se posuzuje PREFIXEM, ne rovností — mapa umí přiřazovat na úrovni analytik
     * (511.100 odbyt, 511.900 správa) a stejné pravidlo používá i mapper výkazu.
     *
     * @param list<array<string,mixed>> $mappings
     * @return list<array{account_code:string, name:string, turnover:float}>
     */
    private function unassignedExpenseAccounts(int $supplierId, Request $request, array $mappings): array
    {
        $periodId = (int) ($request->getQueryParams()['period_id'] ?? 0);
        if ($periodId <= 0) {
            return [];
        }
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            return [];
        }

        $stmt = $this->db->pdo()->prepare(
            "SELECT a.account_code, a.name,
                    ROUND(COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END), 0), 2) AS turnover
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND e.entry_date BETWEEN ? AND ?
                AND a.account_type = 'expense'
              GROUP BY a.account_code, a.name
             HAVING turnover <> 0
              ORDER BY turnover DESC"
        );
        $stmt->execute([$supplierId, (string) $period['starts_on'], (string) $period['ends_on']]);

        $prefixes = array_map(static fn (array $m): string => (string) $m['account_prefix'], $mappings);
        $version = $this->definitions->findVersion(
            FinancialStatementService::TYPE_PURPOSE,
            (string) $period['ends_on'],
        );
        if ($version !== null) {
            // Táž sloučená mapa jako výkaz (globální + funkce + výjimky firmy) — účet, který
            // firma zařadila výjimkou, přiřazení funkci nepotřebuje.
            foreach ($this->maps->accountMap($version, $supplierId, (int) $period['fiscal_year']) as $m) {
                $prefixes[] = (string) $m['account_prefix'];
            }
        }

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $code = (string) $row['account_code'];
            foreach ($prefixes as $prefix) {
                if (str_starts_with($code, $prefix)) {
                    continue 2;
                }
            }
            $out[] = [
                'account_code' => $code,
                'name'         => (string) $row['name'],
                'turnover'     => (float) $row['turnover'],
            ];
        }

        return $out;
    }

    /** Nastaví/změní přiřazení účtu funkci. Prázdná funkce přiřazení zruší. */
    public function setFunctionMapping(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $body = (array) ($request->getParsedBody() ?? []);
        $prefix = trim((string) ($body['account_prefix'] ?? ''));
        $function = trim((string) ($body['function_code'] ?? ''));

        if ($prefix === '' || !preg_match('/^\d{3}(\.\d{1,6})?$/', $prefix)) {
            return Json::error($response, 'validation_failed',
                'account_prefix musí být kód syntetiky nebo analytiky (např. 518 nebo 518.100).', 422);
        }
        if ($function === '') {
            $this->definitions->deleteFunctionMapping($supplierId, $prefix);
            return Json::ok($response, ['account_prefix' => $prefix, 'function_code' => null]);
        }
        if (!isset(StatementDefinitionRepository::FUNCTION_ROWS[$function])) {
            return Json::error($response, 'validation_failed', sprintf(
                'function_code musí být jedno z: %s.',
                implode(', ', array_keys(StatementDefinitionRepository::FUNCTION_ROWS)),
            ), 422);
        }

        $this->definitions->setFunctionMapping($supplierId, $prefix, $function, $this->userId($request));

        return Json::ok($response, ['account_prefix' => $prefix, 'function_code' => $function]);
    }

    public function exportBalanceSheet(Request $request, Response $response): Response
    {
        return $this->export($request, $response, 'balance_sheet');
    }

    public function exportIncomeStatementByFunction(Request $request, Response $response): Response
    {
        return $this->export($request, $response, FinancialStatementService::TYPE_PURPOSE);
    }

    public function exportIncomeStatement(Request $request, Response $response): Response
    {
        return $this->export($request, $response, 'income_statement');
    }

    /**
     * Rozvaha a výsledovka po účtech s hospodářským výsledkem (obě části naráz, aby VH
     * z rozvahové i výsledkové strany pocházel z jednoho běhu).
     */
    public function accountView(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $params = $this->validateParams($request, $response, $supplierId, $err);
        if ($params === null) return $err;

        $columns = (string) ($request->getQueryParams()['columns'] ?? '') === '1' ? $this->columnOptions($request) : null;

        try {
            $data = $this->statements->accountView($supplierId, $params['period_id'], $params['as_of'], $params['dimension'] ?? null, $columns);
        } catch (ReportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            $this->log->error('Účetní sestavu se nepodařilo sestavit: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'build_failed', 'Sestavu se nepodařilo vytvořit.', 500);
        }

        return Json::ok($response, $data);
    }

    /**
     * Export jedné části pohledu po účtech (`part=balance|profit_loss`). Nejde o zákonný
     * výkaz, proto jednotku (`unit`) respektuje i PDF.
     */
    public function exportAccountView(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $params = $this->validateParams($request, $response, $supplierId, $err);
        if ($params === null) return $err;

        $q = $request->getQueryParams();
        $format = strtolower(trim((string) ($q['format'] ?? '')));
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            return Json::error($response, 'validation_failed', "format musí být 'pdf' nebo 'xlsx'.", 422);
        }
        $part = trim((string) ($q['part'] ?? 'balance'));
        if (!in_array($part, ['balance', 'profit_loss'], true)) {
            return Json::error($response, 'validation_failed', "part musí být 'balance' nebo 'profit_loss'.", 422);
        }
        $unit = strtolower(trim((string) ($q['unit'] ?? 'czk')));
        if ($unit === '') {
            $unit = 'czk';
        }
        if (!in_array($unit, ['czk', 'thousands'], true)) {
            return Json::error($response, 'validation_failed', "unit musí být 'czk' nebo 'thousands'.", 422);
        }

        try {
            $data = $this->statements->accountView($supplierId, $params['period_id'], $params['as_of'], $params['dimension'] ?? null, $format === 'xlsx' ? $this->columnOptions($request) : null);
            $out = $format === 'pdf'
                ? [
                    'bytes'    => $this->accountViewPdf->render($data + ['part' => $part, 'unit' => $unit]),
                    'filename' => sprintf('%s-po-uctech-%s.pdf', $part === 'balance' ? 'rozvaha' : 'vysledovka', (string) $data['as_of']),
                    'mime'     => 'application/pdf',
                ]
                : $this->xlsx->accountView($data, $part, $unit);
        } catch (ReportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            $this->log->error('Účetní sestavu se nepodařilo sestavit: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'build_failed', 'Sestavu se nepodařilo vytvořit.', 500);
        }

        $this->logger->log('report.accounting_export', $this->userId($request), 'report', null,
            ['report' => 'account_view_' . $part, 'format' => $format],
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
     * Přepínače sloupcového pohledu, stejné pro obrazovku i XLSX.
     *
     * @return array{hide_zero:bool, analytics:bool}
     */
    private function columnOptions(Request $request): array
    {
        $q = $request->getQueryParams();
        return [
            'hide_zero' => (string) ($q['hide_zero'] ?? '1') !== '0',
            'analytics' => (string) ($q['analytics'] ?? '1') !== '0',
        ];
    }

    /** @param 'balance_sheet'|'income_statement'|'income_statement_purpose' $type */
    private function view(Request $request, Response $response, string $type): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $params = $this->validateParams($request, $response, $supplierId, $err);
        if ($params === null) return $err;

        try {
            $data = $this->buildStatement($supplierId, $type, $params);
        } catch (ReportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            $this->log->error('Účetní sestavu se nepodařilo sestavit: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'build_failed', 'Sestavu se nepodařilo vytvořit.', 500);
        }

        return Json::ok($response, $data);
    }

    /** @param 'balance_sheet'|'income_statement'|'income_statement_purpose' $type */
    private function export(Request $request, Response $response, string $type): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;
        $params = $this->validateParams($request, $response, $supplierId, $err);
        if ($params === null) return $err;

        $format = strtolower(trim((string) ($request->getQueryParams()['format'] ?? '')));
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            return Json::error($response, 'validation_failed', "format musí být 'pdf' nebo 'xlsx'.", 422);
        }

        // Jednotka výkazu (F4 R17): PDF je oficiální výstup — VŽDY celé tisíce Kč
        // (řeší renderer); XLSX je pracovní formát — volitelně ?unit=czk|thousands.
        $unit = strtolower(trim((string) ($request->getQueryParams()['unit'] ?? 'czk')));
        if ($unit === '') {
            $unit = 'czk';
        }
        if (!in_array($unit, ['czk', 'thousands'], true)) {
            return Json::error($response, 'validation_failed', "unit musí být 'czk' nebo 'thousands'.", 422);
        }

        try {
            $data = $this->buildStatement($supplierId, $type, $params);
            if ($format === 'pdf') {
                $renderer = $type === 'balance_sheet' ? $this->balanceSheetPdf : $this->incomeStatementPdf;
                $basename = match ($type) {
                    'balance_sheet' => 'rozvaha',
                    FinancialStatementService::TYPE_PURPOSE => 'vysledovka-ucelova',
                    default => 'vysledovka',
                };
                $out = [
                    'bytes'    => $renderer->render($data),
                    'filename' => sprintf('%s-%s.pdf', $basename, (string) ($data['as_of'] ?? '')),
                    'mime'     => 'application/pdf',
                ];
            } else {
                $out = $type === 'balance_sheet' ? $this->xlsx->balanceSheet($data, $unit) : $this->xlsx->incomeStatement($data, $unit);
            }
        } catch (ReportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            $this->log->error('Účetní sestavu se nepodařilo sestavit: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'build_failed', 'Sestavu se nepodařilo vytvořit.', 500);
        }

        $this->logger->log('report.accounting_export', $this->userId($request), 'report', null,
            ['report' => $type, 'format' => $format] + (($params['accrual_spread'] ?? false) ? ['accrual_spread' => true] : []),
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
     * @param 'balance_sheet'|'income_statement'|'income_statement_purpose' $type
     * @param array{period_id:int, as_of:?string, scope:string, dimension:?\MyInvoice\Service\Accounting\Dimension\DimensionFilter, accrual_spread?:bool} $params
     */
    private function buildStatement(int $supplierId, string $type, array $params): array
    {
        $spread = $params['accrual_spread'] ?? false;
        return match ($type) {
            'balance_sheet' => $this->statements->balanceSheet($supplierId, $params['period_id'], $params['as_of'], $params['scope'], $params['dimension'] ?? null, $spread),
            FinancialStatementService::TYPE_PURPOSE => $this->statements
                ->incomeStatementByFunction($supplierId, $params['period_id'], $params['as_of'], $params['scope'], $params['dimension'] ?? null, $spread),
            default => $this->statements->incomeStatement($supplierId, $params['period_id'], $params['as_of'], $params['scope'], $params['dimension'] ?? null, $spread),
        };
    }

    /**
     * @return array{period_id:int, as_of:?string, scope:string}|null
     */
    private function validateParams(Request $request, Response $response, int $supplierId, ?Response &$err): ?array
    {
        $q = $request->getQueryParams();

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

        $asOf = trim((string) ($q['as_of'] ?? ''));
        if ($asOf !== '') {
            if (!$this->isDate($asOf)) {
                $err = Json::error($response, 'validation_failed', 'as_of musí být datum (YYYY-MM-DD).', 422);
                return null;
            }
            // D4 (audit 2026-07): rozvahový den musí ležet uvnitř zvoleného období
            // (stejný vzor jako GeneralLedgerAction pro from/to) — jinak by výkaz
            // sečetl zůstatky k datu mimo hranice období.
            if ($asOf < (string) $period['starts_on'] || $asOf > (string) $period['ends_on']) {
                $err = Json::error($response, 'validation_failed', 'as_of musí ležet uvnitř zvoleného období.', 422);
                return null;
            }
        }

        $scope = trim((string) ($q['scope'] ?? 'auto'));
        if ($scope === '') $scope = 'auto';
        if (!in_array($scope, ['auto', 'full', 'small', 'micro'], true)) {
            $err = Json::error($response, 'validation_failed', "scope musí být 'auto', 'full', 'small' nebo 'micro'.", 422);
            return null;
        }

        // Filtr na dimenzi (Firma → Dimenze): výsledovky i rozvaha. Rozvaha za hodnotu má smysl
        // u projektu nebo zakázky, jejíž doklady nesou hodnotu na všech řádcích (hlavička dokladu).
        $dimension = $this->dimensionFilterParam($this->dimensions, $request, $response, $supplierId, $err);
        if ($dimension === false) return null;

        // Průběžné časové rozlišení mimo deník: jen pohled výkazu, výchozí vypnutý.
        $spread = trim((string) ($q['accrual_spread'] ?? '0'));
        if (!in_array($spread, ['', '0', '1'], true)) {
            $err = Json::error($response, 'validation_failed', "accrual_spread musí být 0 nebo 1.", 422);
            return null;
        }

        $err = null;
        return [
            'period_id' => $periodId,
            'as_of'     => $asOf === '' ? null : $asOf,
            'scope'     => $scope,
            'dimension' => $dimension,
            'accrual_spread' => $spread === '1',
        ];
    }

    private function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
