<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Renderer PDF opisu účtu (A4 landscape).
 *
 * Data = výstup AccountStatementService::build() (spec F2 §2.6) — hlavička účtu,
 * počáteční zůstatek, položky s běžícím zůstatkem, obraty a konečný zůstatek.
 */
final class AccountStatementPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $body = $this->renderTemplate('account_statement.twig', $data);
        $mpdf = $this->mpdf();
        $mpdf->SetTitle('Opis účtu ' . (string) ($data['account']['code'] ?? ''));
        $this->withPageNumbers($mpdf, 'Opis účtu');
        $mpdf->WriteHTML($body);
        return $mpdf->Output('', 'S');
    }
}
