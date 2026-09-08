<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Renderer PDF peněžního deníku daňové evidence (A4 landscape, Epic DE A3).
 *
 * Data = výstup CashJournalService::build() — řádky (datum/doklad/protistrana/popis/
 * příjem/výdaj/běžný zůstatek/klasifikace), totály per §7b kbelík a BLOKUJÍCÍ
 * varování „nezařazeno / orphaned bank" (R10), která šablona vysází prominentně nahoře.
 */
final class TaxEvidenceCashJournalPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $body = $this->renderTemplate('cash_journal.twig', $data);
        $mpdf = $this->mpdf();
        $mpdf->SetTitle('Peněžní deník ' . (string) ($data['year'] ?? ''));
        $this->withPageNumbers($mpdf, 'Peněžní deník');
        $mpdf->WriteHTML($body);
        return $mpdf->Output('', 'S');
    }
}
