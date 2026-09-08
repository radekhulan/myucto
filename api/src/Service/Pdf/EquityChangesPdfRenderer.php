<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Renderer PDF přehledu o změnách vlastního kapitálu (§ 18/2 ZoÚ, § 44 vyhl. 500/2002 Sb.).
 *
 * Data = výstup EquityChangesStatementService::build() doplněný o `entity`.
 */
final class EquityChangesPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $body = $this->renderTemplate('equity_changes.twig', $data);
        $mpdf = $this->mpdf();
        $mpdf->SetTitle('Přehled o změnách vlastního kapitálu ' . (string) ($data['period']['starts_on'] ?? ''));
        $this->withPageNumbers($mpdf, 'Přehled o změnách vlastního kapitálu');
        $mpdf->WriteHTML($body);

        return $mpdf->Output('', 'S');
    }
}
