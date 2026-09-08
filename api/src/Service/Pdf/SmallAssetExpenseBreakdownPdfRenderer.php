<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Renderer PDF rozpisu účtu 501 dle druhu výdaje (A4 landscape).
 * Data = výstup SmallAssetReportService::expenseBreakdown() (§DM Sestavy 3) —
 * porovnatelné s analytikami účetní 501.100 (materiál) × 501.200 (drobný majetek).
 */
final class SmallAssetExpenseBreakdownPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $body = $this->renderTemplate('small_asset_expense_breakdown.twig', $data);
        $mpdf = $this->mpdf();
        $mpdf->SetTitle('Rozpis 501 dle druhu výdaje');
        $this->withPageNumbers($mpdf, 'Rozpis 501 dle druhu výdaje');
        $mpdf->WriteHTML($body);
        return $mpdf->Output('', 'S');
    }
}
