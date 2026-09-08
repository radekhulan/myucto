<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Renderer PDF sestavy „Stav zásob" (Epic SKLAD) — A4 landscape, vzor
 * {@see TrialBalancePdfRenderer}. Data = výstup StockReportService::status().
 */
final class StockStatusPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $body = $this->renderTemplate('stock_status.twig', $data);
        $mpdf = $this->mpdf();
        $mpdf->SetTitle('Stav zásob');
        $this->withPageNumbers($mpdf, 'Stav zásob');
        $mpdf->WriteHTML($body);
        return $mpdf->Output('', 'S');
    }
}
