<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Reports;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Reports\CashFlowStatementService;
use MyInvoice\Service\Accounting\Reports\EntityCategoryService;
use MyInvoice\Service\Accounting\Reports\EquityChangesStatementService;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Accounting\Reports\ReportXlsxExporter;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\CashFlowPdfRenderer;
use MyInvoice\Service\Pdf\EquityChangesPdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Přehled o peněžních tocích a o změnách vlastního kapitálu — § 18 odst. 2 ZoÚ,
 * § 40 až § 44 vyhl. 500/2002 Sb.
 *
 *   GET /api/accounting/reports/section18-statements?period_id=
 *
 * Oba výkazy jedním endpointem záměrně: povinnost je společná (velká a střední účetní
 * jednotka a každá s povinným auditem je má oba), sestavují se ze stejného období a
 * účetní je posuzuje spolu. Dva požadavky by jen zdvojily načtení období.
 *
 * Součástí odpovědi je i `category` — bez ní by uživatel nevěděl, zda se ho povinnost
 * TÝKÁ. Výkazy se sestaví každé firmě (jsou to platné sestavy i pro malou ÚJ, jen
 * nepovinné); povinnost je informace navíc, ne podmínka sestavení.
 */
final class Section18StatementsAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly CashFlowStatementService $cashFlow,
        private readonly EquityChangesStatementService $equity,
        private readonly EntityCategoryService $categories,
        private readonly AccountingPeriodRepository $periods,
        private readonly LoggerInterface $log,
        private readonly Connection $db,
        private readonly FinancialStatementService $statements,
        private readonly CashFlowPdfRenderer $cashFlowPdf,
        private readonly EquityChangesPdfRenderer $equityPdf,
        private readonly ReportXlsxExporter $xlsx,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly \MyInvoice\Service\Accounting\Reports\StatementSupplementPolicy $frameworks,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $periodId = (int) ($request->getQueryParams()['period_id'] ?? 0);
        if ($periodId <= 0) {
            return Json::error($response, 'validation_failed', 'period_id je povinný.', 422);
        }
        if ($this->periods->findById($supplierId, $periodId) === null) {
            return Json::error($response, 'not_found', 'Účetní období nenalezeno.', 404);
        }

        try {
            if ($this->frameworks->framework($supplierId, $periodId) === 'cz_504') {
                return Json::ok($response, ['accounting_framework' => 'cz_504', 'available' => false,
                    'reason' => 'statement_supplement_unsupported', 'required' => false, 'cash_flow' => null, 'equity' => null]);
            }
            $cashFlow = $this->cashFlow->build($supplierId, $periodId);
            $equity   = $this->equity->build($supplierId, $periodId);
            $category = $this->categories->evaluate($supplierId, $periodId);
        } catch (ReportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            $this->log->error('Přehledy podle § 18/2 se nepodařilo sestavit: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'build_failed', 'Sestavu se nepodařilo vytvořit.', 500);
        }

        // Stejné kritérium jako v kontrole závěrky (`section18_statements_required`) —
        // rozejít se nesmí, jinak by uzávěrka a tahle stránka tvrdily každá něco jiného.
        $required = in_array((string) $category['category'], ['large', 'medium'], true)
            || ($category['scope_override'] === null && (string) $category['scope'] === 'full');

        return Json::ok($response, [
            'cash_flow' => $cashFlow,
            'equity'    => $equity,
            'category'  => $category['category'],
            'required'  => $required,
        ]);
    }

    /**
     * Export jednoho z obou přehledů.
     *   GET /api/accounting/reports/section18-statements/export?period_id=&statement=cash_flow|equity&format=pdf|xlsx
     *
     * Až do teď se oba výkazy uměly spočítat a zobrazit, ale nešly dostat ven. Přitom je
     * to sestava, kterou účetní přikládá k závěrce — nemožnost ji vytisknout znamenala, že
     * povinnost podle § 18/2 systém nedokázal splnit a uzávěrka na to jen upozorňovala
     * varováním „přiložte ručně".
     *
     * Výkazy se exportují KAŽDÝ ZVLÁŠŤ (na rozdíl od čtení, kde chodí spolu): jsou to dvě
     * samostatné přílohy závěrky se dvěma různými strukturami a sloučit je do jednoho
     * souboru by znamenalo, že si je účetní musí zase rozdělit.
     */
    public function export(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->requireDoubleEntry($this->db, $supplierId, $response, $err)) return $err;

        $q = $request->getQueryParams();
        $periodId = (int) ($q['period_id'] ?? 0);
        if ($periodId <= 0) {
            return Json::error($response, 'validation_failed', 'period_id je povinný.', 422);
        }
        if ($this->periods->findById($supplierId, $periodId) === null) {
            return Json::error($response, 'not_found', 'Účetní období nenalezeno.', 404);
        }

        $statement = strtolower(trim((string) ($q['statement'] ?? '')));
        if (!in_array($statement, ['cash_flow', 'equity'], true)) {
            return Json::error($response, 'validation_failed', "statement musí být 'cash_flow' nebo 'equity'.", 422);
        }
        $format = strtolower(trim((string) ($q['format'] ?? '')));
        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            return Json::error($response, 'validation_failed', "format musí být 'pdf' nebo 'xlsx'.", 422);
        }

        try {
            $data = $statement === 'cash_flow'
                ? $this->cashFlow->build($supplierId, $periodId)
                : $this->equity->build($supplierId, $periodId);
            $data['entity'] = $this->statements->entityHeader($supplierId);

            if ($format === 'pdf') {
                $renderer = $statement === 'cash_flow' ? $this->cashFlowPdf : $this->equityPdf;
                $basename = $statement === 'cash_flow' ? 'penezni-toky' : 'zmeny-vlastniho-kapitalu';
                $out = [
                    'bytes'    => $renderer->render($data),
                    'filename' => sprintf('%s-%s.pdf', $basename, (string) ($data['period']['starts_on'] ?? '')),
                    'mime'     => 'application/pdf',
                ];
            } else {
                $out = $statement === 'cash_flow'
                    ? $this->xlsx->cashFlow($data)
                    : $this->xlsx->equityChanges($data);
            }
        } catch (ReportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            $this->log->error('Přehled podle § 18/2 se nepodařilo vyexportovat: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'build_failed', 'Sestavu se nepodařilo vytvořit.', 500);
        }

        $this->logger->log('report.accounting_export', $this->userId($request), 'report', null,
            ['report' => 'section18_' . $statement, 'format' => $format],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), $supplierId);

        $response->getBody()->write($out['bytes']);

        return $response
            ->withHeader('Content-Type', $out['mime'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $out['filename'] . '"')
            ->withHeader('Content-Length', (string) strlen($out['bytes']))
            ->withHeader('Cache-Control', 'private, no-store');
    }
}
