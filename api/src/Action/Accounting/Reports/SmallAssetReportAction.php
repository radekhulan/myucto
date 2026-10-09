<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Reports;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\GuardsAccountingMode;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Accounting\Reports\ReportXlsxExporter;
use MyInvoice\Service\Accounting\Reports\SmallAssetReportService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\SmallAssetExpenseBreakdownPdfRenderer;
use MyInvoice\Service\Pdf\SmallAssetInventoryPdfRenderer;
use MyInvoice\Service\Pdf\SmallAssetMovementsPdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Sestavy drobného majetku (§DM „Sestavy") — vzor {@see TrialBalanceAction}.
 *
 *   GET /api/accounting/reports/small-assets/inventory[/export]         — soupis k datu (?as_of=)
 *   GET /api/accounting/reports/small-assets/movements[/export]         — přírůstky a úbytky (?from=&to=)
 *   GET /api/accounting/reports/small-assets/expense-breakdown[/export] — rozpis 501 dle druhu (?from=&to=)
 *
 * BEZ period_id, na rozdíl od sestav F2: soupis se váže k rozhodnému DNI inventarizace a
 * ostatní dvě k volnému rozsahu — nic se tu nepřenáší mezi obdobími, takže by period_id
 * byl jen povinný parametr navíc (viz SmallAssetReportService).
 */
final class SmallAssetReportAction
{
    use AccountingActionSupport;
    use GuardsAccountingMode;

    public function __construct(
        private readonly SmallAssetReportService $reports,
        private readonly SmallAssetInventoryPdfRenderer $inventoryPdf,
        private readonly SmallAssetMovementsPdfRenderer $movementsPdf,
        private readonly SmallAssetExpenseBreakdownPdfRenderer $breakdownPdf,
        private readonly ReportXlsxExporter $xlsx,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly LoggerInterface $log,
        private readonly Connection $db,
    ) {}

    // ── soupis k datu ───────────────────────────────────────────────────────────

    public function inventory(Request $request, Response $response): Response
    {
        return $this->serve($request, $response, 'inventory', false);
    }

    public function exportInventory(Request $request, Response $response): Response
    {
        return $this->serve($request, $response, 'inventory', true);
    }

    // ── přírůstky a úbytky ──────────────────────────────────────────────────────

    public function movements(Request $request, Response $response): Response
    {
        return $this->serve($request, $response, 'movements', false);
    }

    public function exportMovements(Request $request, Response $response): Response
    {
        return $this->serve($request, $response, 'movements', true);
    }

    // ── rozpis 501 dle druhu výdaje ─────────────────────────────────────────────

    public function expenseBreakdown(Request $request, Response $response): Response
    {
        return $this->serve($request, $response, 'expense_breakdown', false);
    }

    public function exportExpenseBreakdown(Request $request, Response $response): Response
    {
        return $this->serve($request, $response, 'expense_breakdown', true);
    }

    /**
     * Společné tělo všech šesti endpointů. Sestavy se liší jen parametry, buildem a
     * exportérem — tři kopie TrialBalanceAction vedle sebe by se rozešly při první opravě.
     */
    private function serve(Request $request, Response $response, string $report, bool $export): Response
    {
        $supplierId = $this->currentSupplierId($request);
        // Soupis a pohyby jsou evidence věcí (i v daňové evidenci); rozpis 501 je rozpad
        // účetního nákladu podle data plnění, který daňová evidence (výdaj při úhradě) nemá.
        $guarded = $report === 'expense_breakdown'
            ? $this->requireDoubleEntry($this->db, $supplierId, $response, $err)
            : $this->requireAccountingMode($this->db, $supplierId, $response, $err);
        if (!$guarded) return $err;

        $format = null;
        if ($export) {
            $format = strtolower(trim((string) ($request->getQueryParams()['format'] ?? '')));
            if (!in_array($format, ['pdf', 'xlsx'], true)) {
                return Json::error($response, 'validation_failed', "format musí být 'pdf' nebo 'xlsx'.", 422);
            }
        }

        try {
            $data = $this->build($request, $supplierId, $report);
        } catch (ReportException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        } catch (\Throwable $e) {
            $this->log->error('Sestavu drobného majetku se nepodařilo sestavit: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'build_failed', 'Sestavu se nepodařilo vytvořit.', 500);
        }

        if (!$export) {
            return Json::ok($response, $data);
        }

        try {
            $out = $format === 'pdf' ? $this->renderPdf($report, $data) : $this->renderXlsx($report, $data);
        } catch (\Throwable $e) {
            $this->log->error('Export sestavy drobného majetku selhal: ' . $e->getMessage(), ['exception' => $e]);
            return Json::error($response, 'build_failed', 'Sestavu se nepodařilo vytvořit.', 500);
        }

        $this->logger->log('report.accounting_export', $this->userId($request), 'report', null,
            ['report' => 'small_assets_' . $report, 'format' => $format],
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
     * @return array<string,mixed>
     */
    private function build(Request $request, int $supplierId, string $report): array
    {
        if ($report === 'inventory') {
            return $this->reports->inventory($supplierId, $this->asOf($request));
        }
        [$from, $to] = $this->range($request);
        return $report === 'movements'
            ? $this->reports->movements($supplierId, $from, $to)
            : $this->reports->expenseBreakdown($supplierId, $from, $to);
    }

    /**
     * @param array<string,mixed> $data
     * @return array{bytes:string, filename:string, mime:string}
     */
    private function renderPdf(string $report, array $data): array
    {
        return match ($report) {
            'inventory' => [
                'bytes' => $this->inventoryPdf->render($data),
                'filename' => sprintf('soupis-drobneho-majetku-%s.pdf', (string) ($data['as_of'] ?? '')),
                'mime' => 'application/pdf',
            ],
            'movements' => [
                'bytes' => $this->movementsPdf->render($data),
                'filename' => sprintf('drobny-majetek-pohyby-%s_%s.pdf', (string) ($data['from'] ?? ''), (string) ($data['to'] ?? '')),
                'mime' => 'application/pdf',
            ],
            default => [
                'bytes' => $this->breakdownPdf->render($data),
                'filename' => sprintf('rozpis-501-%s_%s.pdf', (string) ($data['from'] ?? ''), (string) ($data['to'] ?? '')),
                'mime' => 'application/pdf',
            ],
        };
    }

    /**
     * @param array<string,mixed> $data
     * @return array{bytes:string, filename:string, mime:string}
     */
    private function renderXlsx(string $report, array $data): array
    {
        return match ($report) {
            'inventory' => $this->xlsx->smallAssetInventory($data),
            'movements' => $this->xlsx->smallAssetMovements($data),
            default => $this->xlsx->smallAssetExpenseBreakdown($data),
        };
    }

    /** Rozhodný den soupisu; default dnešek — nejčastější případ je „ukaž mi to teď". */
    private function asOf(Request $request): string
    {
        $v = trim((string) ($request->getQueryParams()['as_of'] ?? ''));
        if ($v === '') {
            return (new \DateTimeImmutable())->format('Y-m-d');
        }
        if (!$this->isDate($v)) {
            throw new ReportException('validation_failed', 'as_of musí být datum (YYYY-MM-DD).');
        }
        return $v;
    }

    /** @return array{0:string,1:string} */
    private function range(Request $request): array
    {
        $q = $request->getQueryParams();
        $out = [];
        foreach (['from', 'to'] as $key) {
            $v = trim((string) ($q[$key] ?? ''));
            if ($v === '') {
                throw new ReportException('validation_failed', "{$key} je povinný.");
            }
            if (!$this->isDate($v)) {
                throw new ReportException('validation_failed', "{$key} musí být datum (YYYY-MM-DD).");
            }
            $out[] = $v;
        }
        if ($out[0] > $out[1]) {
            throw new ReportException('validation_failed', 'from nesmí být větší než to.');
        }
        return [$out[0], $out[1]];
    }

    private function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
