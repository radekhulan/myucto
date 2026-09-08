<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Renderer PDF sestavy „Ocenění zásob k datu" (Epic SKLAD §6, B8) — A4 landscape,
 * vzor {@see TrialBalancePdfRenderer}. Data = výstup StockReportService::valuation().
 */
final class StockValuationPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $body = $this->renderTemplate('stock_valuation.twig', $data);
        $mpdf = $this->mpdf();
        $mpdf->SetTitle('Ocenění zásob k ' . (string) ($data['date'] ?? ''));
        $this->withPageNumbers($mpdf, 'Ocenění zásob');
        $mpdf->WriteHTML($body);
        return $mpdf->Output('', 'S');
    }
}
