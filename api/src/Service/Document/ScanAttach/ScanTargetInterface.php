<?php

declare(strict_types=1);

namespace MyInvoice\Service\Document\ScanAttach;

/**
 * Typ dokladu, ke kterému se připojují skeny (přijatá faktura, vydaná faktura,
 * pokladní doklad). Párování i dávka s ním pracují jen přes toto rozhraní, takže
 * nový typ = nová implementace v {@see ScanTargetRegistry}.
 */
interface ScanTargetInterface
{
    /** Typ dokladu = `document_links.entity_type`. */
    public function type(): string;

    /** Lze k tomuto typu dokladu teď připojovat (existuje vazba v sekci Dokumenty)? */
    public function isAvailable(): bool;

    /** Oprávnění, které uživatel potřebuje k připojení skenu k tomuto typu dokladu. */
    public function permission(): string;

    /**
     * Doklady firmy, na které se páruje (volitelně podle data vystavení).
     *
     * @return list<array{id:int,direction:string,doc_numbers:list<string>,barcode:?string,counterparty_ico:?string,counterparty_doc_no:?string,vs:?string,total:float,date:?string,tax_date:?string}>
     */
    public function candidates(int $supplierId, ?string $from, ?string $to): array;

    /**
     * Popisky dokladů pro přehled dávky.
     *
     * @param list<int> $ids
     * @return array<int, array{label:string,counterparty:?string,date:?string,total:?float,currency:?string}>
     */
    public function describe(int $supplierId, array $ids): array;

    /**
     * Doklady v rozsahu, ke kterým není připojená žádná příloha.
     *
     * @return array{total:int, rows:list<array{id:int,label:string,counterparty:?string,date:?string,total:?float,currency:?string}>}
     */
    public function withoutScan(int $supplierId, ?string $from, ?string $to, int $limit): array;

    /**
     * Připojí uložený dokument k dokladu. Typ, který má vlastní slot pro PDF
     * (přijatá faktura), do něj dá první sken, pokud tam ještě nic není.
     */
    public function attach(int $supplierId, int $targetId, int $documentId, string $absPath, string $fileName): void;
}
