<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice;

/**
 * Příloha e-mailu načtená z IMAP schránky.
 *
 * Vzniká jen u účtů s `ingest_pdf_invoices` — obsah přílohy se drží v paměti,
 * takže se nefetchuje, dokud si o něj funkce „načíst PDF faktury" neřekne.
 */
final class EmailAttachment
{
    public function __construct(
        public readonly string $filename,
        public readonly string $mimeType,
        public readonly string $content,
    ) {}

    public function size(): int
    {
        return strlen($this->content);
    }

    public function sha256(): string
    {
        return hash('sha256', $this->content);
    }

    /**
     * PDF poznáváme podle magic bajtů, ne podle přípony ani MIME typu — obojí
     * si odesílatel určuje sám (`application/octet-stream` je běžný).
     */
    public function isPdf(): bool
    {
        return str_starts_with($this->content, '%PDF');
    }
}
