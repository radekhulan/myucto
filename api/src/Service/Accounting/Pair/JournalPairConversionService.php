<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Pair;

/**
 * Převod starších zaúčtovaných zápisů na dvojice, s náhledem nanečisto.
 *
 * Kontrakt (implementace navazuje):
 *
 *  - běh = řádek `journal_pair_conversion_runs` (mode preview | apply), zpracování na
 *    pozadí po dávkách 500 zápisů, nikdy celý rok v jednom PHP poli;
 *  - klasifikace každého zaúčtovaného zápisu bez `pair_no`: `paired` (už dvojice),
 *    `auto` (JournalPairer vrátí dvojice), `composite_mn`, `ineligible` s důvodem
 *    (vazby `journal_settlement_allocations` / `journal_line_pairing_items` na řádek,
 *    mzdový zápis, typovaný vlastník SVJ/NNO/504, období closing/closed, datum
 *    <= locked_until, stornovaný zápis nebo protizápis);
 *  - `apply` fyzicky rozdělí jen `auto` zápisy v otevřeném nezamčeném období přes
 *    JournalEntryRepository::splitIntoPairs() (jedna transakce na zápis, žádné DELETE
 *    řádků), ostatní zapíše do `journal_entry_pair_status`;
 *  - náhled nic v deníku nemění; jeho id jde do auditu změny formy
 *    (`conversion_preview_id`).
 *
 * Chyby hlásí přes {@see JournalPairException} (kód + HTTP status).
 */
final class JournalPairConversionService
{
    /**
     * Založí běh náhledu nanečisto pro okno dat (NULL = bez omezení) a spustí ho na pozadí.
     *
     * @return int id běhu
     * @throws JournalPairException
     */
    public function preview(int $supplierId, ?string $from, ?string $to, int $actor): int
    {
        throw self::notImplemented();
    }

    /**
     * Založí běh převodu (fyzické rozdělení způsobilých zápisů) a spustí ho na pozadí.
     *
     * @return int id běhu
     * @throws JournalPairException
     */
    public function apply(int $supplierId, ?string $from, ?string $to, int $actor): int
    {
        throw self::notImplemented();
    }

    /**
     * Stav jednoho běhu pro polling; null = běh firmě nepatří nebo neexistuje.
     *
     * @return array{id:int, mode:string, status:string, date_from:?string, date_to:?string,
     *               counts:?array<string,mixed>, sample:?array<int,mixed>, error:?string,
     *               created_by:?int, created_at:string, finished_at:?string}|null
     */
    public function run(int $supplierId, int $runId): ?array
    {
        throw self::notImplemented();
    }

    /**
     * Poslední běhy firmy (nejnovější první).
     *
     * @return list<array<string,mixed>> tvar jako {@see run()}
     */
    public function runs(int $supplierId, int $limit = 10): array
    {
        throw self::notImplemented();
    }

    /**
     * Zruší čekající nebo běžící běh. Už rozdělené zápisy zůstávají (každý zápis má
     * vlastní transakci).
     *
     * @return bool false = běh už skončil
     * @throws JournalPairException
     */
    public function cancel(int $supplierId, int $runId, int $actor): bool
    {
        throw self::notImplemented();
    }

    private static function notImplemented(): JournalPairException
    {
        return new JournalPairException('not_implemented', 'Převod zápisů na dvojice zatím není k dispozici.', 501);
    }
}
