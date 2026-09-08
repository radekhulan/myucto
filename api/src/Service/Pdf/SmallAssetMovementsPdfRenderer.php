<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Renderer PDF přírůstků a úbytků drobného majetku za období (A4 landscape).
 * Data = výstup SmallAssetReportService::movements() (§DM Sestavy 2).
 */
final class SmallAssetMovementsPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $body = $this->renderTemplate('small_asset_movements.twig', $data);
        $mpdf = $this->mpdf();
        $mpdf->SetTitle('Přírůstky a úbytky drobného majetku');
        $this->withPageNumbers($mpdf, 'Přírůstky a úbytky drobného majetku');
        $mpdf->WriteHTML($body);
        return $mpdf->Output('', 'S');
    }
}
