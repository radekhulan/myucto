<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Renderer PDF přehledu o peněžních tocích (§ 18/2 ZoÚ, § 40–43 vyhl. 500/2002 Sb.).
 *
 * Data = výstup CashFlowStatementService::build() doplněný o `entity` (hlavička účetní
 * jednotky) — sestava se přikládá k závěrce, takže bez názvu a IČ by nebyla k ničemu.
 *
 * Na výšku, ne na šířku jako ostatní sestavy: rozpis má tři sloupce, takže na A4-L by
 * zbývaly dvě třetiny stránky prázdné.
 */
final class CashFlowPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $body = $this->renderTemplate('cash_flow.twig', $data);
        $mpdf = $this->mpdf(['format' => 'A4', 'orientation' => 'P']);
        $mpdf->SetTitle('Přehled o peněžních tocích ' . (string) ($data['period']['starts_on'] ?? ''));
        $this->withPageNumbers($mpdf, 'Přehled o peněžních tocích');
        $mpdf->WriteHTML($body);

        return $mpdf->Output('', 'S');
    }
}
