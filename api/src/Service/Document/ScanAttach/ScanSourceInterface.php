<?php

declare(strict_types=1);

namespace MyInvoice\Service\Document\ScanAttach;

/**
 * Odkud dávka bere soubory skenů. Dnes nahraný ZIP nebo víc souborů
 * ({@see UploadedScanSource}); napojení na úložiště dokumentů (např. sdílená
 * složka přes Microsoft Graph) je další implementace téhož rozhraní.
 *
 * Dávka se po pádu spouští znovu od začátku a hotové soubory přeskočí podle
 * sha256 — zdroj proto musí umět soubory vydat opakovaně, dokud se nezavolá
 * {@see cleanup()}.
 */
interface ScanSourceInterface
{
    /** Počet souborů, je-li znám předem (průběh v UI); null = neznámý. */
    public function count(): ?int;

    /**
     * Soubory ke zpracování. Cesta je dočasná kopie, kterou volající smí přesunout
     * nebo smazat; soubor, který nešel přečíst, má vyplněnou chybu a prázdnou cestu.
     *
     * @return iterable<ScanSourceFile>
     */
    public function files(): iterable;

    /**
     * Má zdroj ještě soubory k vydání? Po úklidu dokončené dávky ne — opakovaný
     * běh pak pracuje jen s tím, co už je uložené v Dokumentech.
     */
    public function hasFiles(): bool;

    /** Úklid po úspěšně dokončené dávce. */
    public function cleanup(): void;
}
