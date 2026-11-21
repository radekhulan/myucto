<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use MyInvoice\Service\Accounting\OtherItemBook\OtherItemBook;

/**
 * PDF knihy ostatních pohledávek a závazků, A4 na šířku. Data = ['book' => OtherItemBook].
 */
final class OtherItemBookPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $book = $data['book'] ?? null;
        if (!$book instanceof OtherItemBook) {
            throw new \InvalidArgumentException('Chybí kniha ostatních pohledávek a závazků.');
        }
        $meta = $book->meta;
        $body = $this->renderTemplate('other_item_book.twig', ['book' => $book, 'meta' => $meta]);

        $mpdf = $this->mpdf();
        $mpdf->SetTitle((string) $meta['title'] . ' ' . (string) $meta['period_label']);
        $printedAt = (new \DateTimeImmutable((string) $meta['printed_at']))->format('d.m.Y H:i');
        $mpdf->SetFooter(
            'Vytiskl: ' . str_replace('|', '/', (string) $meta['printed_by']) . ', ' . $printedAt
            . '|Strana {PAGENO} / {nbpg}|'
            . 'MyÚčto.cz'
        );
        ChunkedHtmlWriter::write($mpdf, $body);
        return $mpdf->Output('', 'S');
    }
}
