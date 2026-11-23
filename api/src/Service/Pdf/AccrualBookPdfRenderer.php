<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * PDF rozpisu časového rozlišení k datu, A4 na šířku.
 * Data = výstup {@see \MyInvoice\Service\Accounting\Accrual\AccrualBookService::build()}.
 */
final class AccrualBookPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $meta = $data['meta'] ?? throw new \InvalidArgumentException('Chybí rozpis časového rozlišení.');
        $body = $this->renderTemplate('accrual_book.twig', $data);
        $mpdf = $this->mpdf();
        $mpdf->SetTitle((string) $meta['title'] . ' ' . (string) $meta['as_of']);
        $printedAt = (new \DateTimeImmutable((string) $meta['printed_at']))->format('d.m.Y H:i');
        $mpdf->SetFooter('Vytiskl: ' . str_replace('|', '/', (string) $meta['printed_by']) . ', ' . $printedAt . '|Strana {PAGENO} / {nbpg}|MyÚčto.cz');
        ChunkedHtmlWriter::write($mpdf, $body);
        return $mpdf->Output('', 'S');
    }
}
