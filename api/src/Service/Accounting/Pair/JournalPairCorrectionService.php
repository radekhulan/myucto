<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Pair;

/**
 * Storno a přeúčtování vybraných dvojic jednoduchého zápisu.
 *
 * Kontrakt (implementace navazuje):
 *
 *  - {@see reversePairs()} založí opravný zápis se zrcadlem vybraných dvojic
 *    (JournalLineAmount::mirrorLines(), dimenze a zakázka se přenášejí), dvojice
 *    opravného zápisu číslované 1..k, vazby do `journal_pair_reversals`. Originál
 *    zůstává jako celek nestornovaný (`reversed_by` NULL). Datum a zámky období jako
 *    storno celého zápisu. Čisté storno dvojice jen u ručních a interních zápisů;
 *    u dokladových zápisů (faktura, přijatá faktura, banka, pokladna) jen přeúčtování,
 *    jinak by zápis přestal sedět na doklad (409 `pair_reversal_document_forbidden`).
 *  - {@see repostPair()} přeúčtuje jednu dvojici: v otevřeném nezamčeném období výměnou
 *    dvojice na místě (stejné `pair_no`, historii drží systémové verzování), při zámku
 *    k datu s daňově neutrální změnou přes TaxNeutralReclassification, jinak opravným
 *    zápisem = červené storno dvojice + nová dvojice (`replacement_pair_no`).
 *  - Mzdové zápisy: 409 `payroll_reversal_forbidden` jako u storna celého zápisu.
 *
 * Chyby hlásí přes {@see JournalPairException} (kód + HTTP status), Action je jen
 * překládá na odpověď.
 */
final class JournalPairCorrectionService
{
    /**
     * Storno vybraných dvojic opravným zápisem.
     *
     * @param list<int> $pairNos čísla dvojic originálu
     * @param array{entry_date?:?string, description?:?string, user_id?:?int, ip?:?string, user_agent?:?string} $meta
     * @return int id opravného zápisu
     * @throws JournalPairException
     */
    public function reversePairs(int $supplierId, int $entryId, array $pairNos, array $meta): int
    {
        throw self::notImplemented();
    }

    /**
     * Přeúčtování jedné dvojice na nové účty (částka zůstává).
     *
     * @param array{debit_account_code:string, credit_account_code:string, line_text?:?string} $newPair
     * @param array{entry_date?:?string, description?:?string, user_id?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array{entry_id:int, mode:'in_place'|'tax_neutral'|'correction_entry', pair_no:int}
     *         zápis, ve kterém je nová dvojice, a způsob přeúčtování
     * @throws JournalPairException
     */
    public function repostPair(int $supplierId, int $entryId, int $pairNo, array $newPair, array $meta): array
    {
        throw self::notImplemented();
    }

    private static function notImplemented(): JournalPairException
    {
        return new JournalPairException('not_implemented', 'Storno a přeúčtování dvojic zatím není k dispozici.', 501);
    }
}
