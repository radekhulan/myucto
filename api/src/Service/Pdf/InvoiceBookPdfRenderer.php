<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use MyInvoice\Service\Invoice\Book\InvoiceBook;

/**
 * PDF knihy vystavených / přijatých faktur, A4 na šířku. Data = ['book' => InvoiceBook].
 */
final class InvoiceBookPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $book = $data['book'] ?? null;
        if (!$book instanceof InvoiceBook) {
            throw new \InvalidArgumentException('Chybí kniha faktur.');
        }
        $meta = $book->meta;
        $body = $this->renderTemplate('invoice_book.twig', [
            'book' => $book,
            'meta' => $meta,
            'partner_label' => $book->kind === InvoiceBook::ISSUED ? 'Odběratel' : 'Dodavatel',
        ]);

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
