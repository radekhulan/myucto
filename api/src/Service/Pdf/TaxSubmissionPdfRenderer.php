<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * PDF odeslaného podání DPH, KH nebo SH včetně údajů o doručení. Data připravuje
 * {@see \MyInvoice\Service\Report\TaxSubmissionPrintout} z uloženého XML snapshotu.
 */
final class TaxSubmissionPdfRenderer extends ReportPdfRendererBase
{
    /** @param array<string,mixed> $data výstup TaxSubmissionPrintout::build() */
    public function render(array $data): string
    {
        $html = $this->renderTemplate('tax_submission.twig', ['r' => $data]);
        $mpdf = $this->mpdf(['format' => 'A4', 'orientation' => 'P']);
        $mpdf->SetTitle(trim(($data['title'] ?? '') . ' ' . ($data['period_label'] ?? '')));
        $this->withPageNumbers($mpdf, (string) ($data['form_code'] ?? '') . ' ' . (string) ($data['period_label'] ?? ''));
        ChunkedHtmlWriter::write($mpdf, $html);
        return $mpdf->Output('', 'S');
    }
}
