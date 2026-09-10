<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook;

/**
 * Doklad, ke kterému tankování patří: přijatá faktura / účtenka, pokladní doklad,
 * bankovní pohyb, účetní zápis, nebo jen dokument (sken bez účetního dokladu).
 *
 * Z odkazu se odvozuje i otisk `dedup_hash` — jeden doklad dá nejvýš jedno tankování
 * z vytěžené účtenky. Pokladní doklad má otisk shodný s {@see CashFuelingService},
 * takže vytěžení skenu a vytěžení popisu téhož dokladu skončí v jednom záznamu.
 */
final class FuelingDocumentRef
{
    /** typ → sloupec vazby ve `fuelings` (dokument bez účetního dokladu vazbu nemá) */
    private const LINK_COLUMNS = [
        'purchase_invoice' => 'source_purchase_invoice_id',
        'cash_document'    => 'source_cash_document_id',
        'bank_transaction' => 'source_bank_transaction_id',
        'journal_entry'    => 'source_journal_entry_id',
        'document'         => null,
    ];

    private function __construct(public readonly string $type, public readonly int $id) {}

    public static function of(string $type, int $id): self
    {
        if (!array_key_exists($type, self::LINK_COLUMNS) || $id <= 0) {
            throw new \InvalidArgumentException('Neplatný odkaz na doklad.');
        }
        return new self($type, $id);
    }

    public static function purchaseInvoice(int $id): self { return self::of('purchase_invoice', $id); }
    public static function cashDocument(int $id): self { return self::of('cash_document', $id); }
    public static function bankTransaction(int $id): self { return self::of('bank_transaction', $id); }
    public static function journalEntry(int $id): self { return self::of('journal_entry', $id); }
    public static function document(int $id): self { return self::of('document', $id); }

    /** Sloupec vazby ve `fuelings`, nebo null (dokument bez účetního dokladu). */
    public function linkColumn(): ?string
    {
        return self::LINK_COLUMNS[$this->type];
    }

    /** Hodnota `fuelings.source` pro tankování založené z tohoto dokladu. */
    public function fuelingSource(): string
    {
        return match ($this->type) {
            'cash_document'    => 'cash',
            'purchase_invoice' => 'invoice',
            default            => 'import',
        };
    }

    /** Deterministický otisk: stejný doklad téže firmy = stejný otisk. */
    public function dedupHash(int $supplierId): string
    {
        if ($this->type === 'cash_document') {
            return hash('sha256', 'cash|' . $supplierId . '|' . $this->id);
        }
        return hash('sha256', 'extraction|' . $this->type . '|' . $supplierId . '|' . $this->id);
    }
}
