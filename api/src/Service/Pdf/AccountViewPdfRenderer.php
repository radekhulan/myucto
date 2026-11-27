<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Renderer PDF rozvahy nebo výsledovky po účtech (A4 landscape).
 *
 * Data = výstup FinancialStatementService::accountView() se sloupci (`columns`) doplněný
 * o `part` (balance|profit_loss) a `unit` (czk|thousands). Řádky `columns.*.lines`
 * z {@see \MyInvoice\Service\Accounting\Reports\StatementAccountColumns} kreslí beze změny,
 * stejně jako obrazovka a XLSX. Volitelný klíč `estimate` (odhad do konce roku,
 * {@see \MyInvoice\Service\Accounting\Reports\YearEndTaxEstimateService::estimate()}) přidá pod tabulku
 * blok označený „Odhad, nezaúčtováno"; přidává ho jen export otevřeného období.
 */
final class AccountViewPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        if (\MyInvoice\Service\Accounting\Reports\Statement504Presentation::accepts($data)) {
            return (new Statement504PdfRenderer())->render($data);
        }
        $title = self::title($data);
        $body = $this->html($data);
        $mpdf = $this->mpdf(['format' => 'A4-L', 'orientation' => 'L']);
        $mpdf->SetTitle($title . ' ' . (string) ($data['period']['fiscal_year'] ?? ''));
        $this->withPageNumbers($mpdf, $title);
        ChunkedHtmlWriter::write($mpdf, $body);
        return $mpdf->Output('', 'S');
    }

    /** HTML šablona PDF (bez mPDF), sdílené s testy obsahu. */
    public function html(array $data): string
    {
        \MyInvoice\Service\Accounting\Reports\StatementExportPolicy::assertSupported($data);
        if (!isset($data['columns'])) {
            throw new \LogicException('Export po účtech potřebuje sloupcový pohled.');
        }
        return $this->renderTemplate('account_view.twig', $data + ['title' => self::title($data)]);
    }

    private static function title(array $data): string
    {
        return ($data['part'] ?? 'balance') === 'profit_loss' ? 'Výsledovka po účtech' : 'Rozvaha po účtech';
    }
}
