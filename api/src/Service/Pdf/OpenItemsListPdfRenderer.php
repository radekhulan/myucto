<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Renderer PDF soupisu otevřených položek k inventarizaci.
 *
 * Data = výstup OpenItemsService::listing().
 */
final class OpenItemsListPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $title = 'Soupis otevřených položek';
        $body = $this->renderTemplate('open_items_list.twig', $data);
        $mpdf = $this->mpdf();
        $mpdf->SetTitle($title . ' ' . (string) ($data['as_of'] ?? ''));
        $this->withPageNumbers($mpdf, $title);
        ChunkedHtmlWriter::write($mpdf, $body);
        return $mpdf->Output('', 'S');
    }
}
