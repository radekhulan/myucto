<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Renderer PDF saldokonta / inventarizačního protokolu (A4 landscape).
 *
 * Data = výstup SaldoService::build() (audit 2026-07, D6/1) — per účet blok
 * konfrontace (zůstatek HK vs. Σ otevřených položek + rozdíl) a rozpad
 * otevřených položek per partner.
 */
final class SaldoPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $title = (string) ($data['report_title'] ?? 'Saldokonto');
        $body = $this->renderTemplate('saldo.twig', $data);
        $mpdf = $this->mpdf();
        $mpdf->SetTitle($title . ' k ' . (string) ($data['as_of'] ?? ''));
        $this->withPageNumbers($mpdf, 'Saldokonto');
        $mpdf->WriteHTML($body);
        return $mpdf->Output('', 'S');
    }
}
