<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Renderer PDF hlavní knihy (A4 landscape).
 *
 * Data = výstup GeneralLedgerService::build() (spec F2 §2.4). Šablona přepíná
 * layout: do 6 měsíců v rozsahu tiskne měsíční obraty MD/D, nad 6 měsíců jen
 * souhrn obratů.
 */
final class GeneralLedgerPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $body = $this->renderTemplate('general_ledger.twig', $data);
        $mpdf = $this->mpdf();
        $label = !empty($data['all_periods']) ? 'všechna období' : (string) ($data['period']['fiscal_year'] ?? '');
        $mpdf->SetTitle('Hlavní kniha ' . $label);
        $mpdf->WriteHTML($body);
        return $mpdf->Output('', 'S');
    }
}
