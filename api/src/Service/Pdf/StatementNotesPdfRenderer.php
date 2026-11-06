<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Renderer PDF „Příloha k účetní závěrce" (§ 18/1/c ZoÚ, § 39/39a/39b vyhl. 500/2002).
 * Data = {@see \MyInvoice\Service\Accounting\Reports\StatementNotesService::build()}
 * + entity hlavička a rozsah období (od–do) doplněné volajícím.
 */
final class StatementNotesPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $body = $this->renderTemplate(($data['notes']['accounting_framework'] ?? '') === 'cz_504' ? 'statement_notes_504.twig' : 'statement_notes.twig', $data);
        $mpdf = $this->mpdf([
            'format' => 'A4',
            'orientation' => 'P',
            'margin_left' => 14,
            'margin_right' => 14,
            'margin_top' => 14,
            'margin_bottom' => 16,
        ]);
        $mpdf->SetTitle('Příloha k účetní závěrce — ' . (string) ($data['notes']['fiscal_year'] ?? ''));
        $this->withPageNumbers($mpdf, 'Příloha k účetní závěrce ' . (string) ($data['notes']['fiscal_year'] ?? ''));
        $mpdf->WriteHTML($body);
        return $mpdf->Output('', 'S');
    }
}
