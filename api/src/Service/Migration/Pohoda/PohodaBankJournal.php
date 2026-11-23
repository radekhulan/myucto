<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Service\Migration\Shared\ReconciliationTolerance;

/**
 * Které řádky deníku POHODY patří kterému bankovnímu pohybu. Deník zná jen číslo dokladu
 * a datum, a POHODA dává jedno číslo i celému výpisu (všechny pohyby `Fio026` téhož dne).
 * Zaúčtované pohyby mají v deníku řádky, pohyby s předkontací „Nevím" žádné, a převod
 * z nich slučuje jeden zápis. Bez rozlišení by se zápis navázal i na nezaúčtované pohyby
 * a ty by zmizely z pohybů k zaúčtování.
 *
 * Datový soubor (MDB) nese u řádku id bankovního dokladu (`pUD.RelAgID`, {@see PohodaMdbAccounting}),
 * rozhoduje tedy id. Oficiální XML ho nemá: pohyb se páruje s nepoužitým řádkem, který
 * na účtu 221 dá právě jeho částku. Co nejde rozhodnout jednoznačně, zůstává jako dřív
 * (zápis čísla patří všem pohybům toho čísla a dne).
 *
 * Jediné místo toho rozhodnutí: řídí se jím vazby pohybů na zápis a jejich vyřízení
 * ({@see DocumentLinker}) i vysvětlení rozdílu banky proti deníku ({@see PohodaReconciler}).
 */
final class PohodaBankJournal
{
    /**
     * Řádek deníku POHODY ze zdroje Banka: datum, id bankovního dokladu (jen MDB), čistý
     * účinek na účet 221 (MD kladně, Dal záporně) a účinky jednotlivých stran 221, se kterými
     * se páruje částka pohybu. `null` = řádek bez účinku.
     *
     * @return array{date:string,source_id:string,net:float,candidates:list<float>}|null
     */
    public static function row(array $item): ?array
    {
        $effect = PohodaJournal::effect($item);
        if ($effect === null) {
            return null;
        }
        $candidates = [];
        if (str_starts_with($effect['debit'], '221')) {
            $candidates[] = $effect['amount'];
        }
        if (str_starts_with($effect['credit'], '221')) {
            $candidates[] = -$effect['amount'];
        }
        return [
            'date' => PohodaXml::date($item, 'date') ?? '',
            'source_id' => trim(PohodaXml::text($item, 'sourceId')),
            'net' => round(array_sum($candidates), 2),
            'candidates' => $candidates,
        ];
    }

    /**
     * Řádky deníku po pohybech převodu ({@see split()}).
     *
     * @return array<int,list<array{date:string,source_id:string,net:float,candidates:list<float>}>>
     */
    public static function byMovement(PohodaContext $ctx): array
    {
        return self::split($ctx->bankTransactions, $ctx->bankJournalRows, $ctx->bankByPohodaId, self::paying($ctx));
    }

    /**
     * Řádky deníku po pohybech: bank_transactions.id => řádky pohybu (prázdný seznam = pohyb
     * v deníku POHODY zaúčtovaný není). Pohyb, u kterého to nejde rozhodnout, ve výsledku chybí.
     *
     * @param array<string,list<array{id:int,date:string,amount?:float}>> $movementsByNumber číslo dokladu => pohyby
     * @param array<string,list<array{date:string,source_id:string,net:float,candidates:list<float>}>> $rowsByNumber číslo dokladu => řádky deníku
     * @param array<string,int> $bankByPohodaId id bankovního dokladu v POHODĚ => bank_transactions.id
     * @param array<int,true> $paying pohyby, kterými POHODA hradí doklad
     * @return array<int,list<array{date:string,source_id:string,net:float,candidates:list<float>}>>
     */
    public static function split(array $movementsByNumber, array $rowsByNumber, array $bankByPohodaId, array $paying = []): array
    {
        $pohodaIds = [];
        foreach ($bankByPohodaId as $pohodaId => $txId) {
            $pohodaIds[(int) $txId] = (string) $pohodaId;
        }
        $out = [];
        foreach ($movementsByNumber as $number => $movements) {
            $rows = $rowsByNumber[(string) $number] ?? [];
            if ($rows === []) {
                continue;
            }
            $byId = self::byId($movements, $rows, $pohodaIds);
            if ($byId !== null) {
                $out += $byId;
                continue;
            }
            // Pohyb, kterým POHODA hradí doklad, má řádek jistě: při shodné částce dostane řádek první.
            usort($movements, static fn (array $a, array $b): int => isset($paying[$b['id']]) <=> isset($paying[$a['id']]));
            $byDate = [];
            foreach ($movements as $m) {
                $byDate[$m['date']][] = $m;
            }
            foreach ($byDate as $date => $sameDay) {
                $dayRows = array_values(array_filter($rows, static fn (array $r): bool => $r['date'] === (string) $date));
                if ($dayRows === []) {
                    continue;
                }
                $out += count($sameDay) === 1 ? [(int) $sameDay[0]['id'] => $dayRows] : (self::byAmount($sameDay, $dayRows) ?? []);
            }
        }
        return $out;
    }

    /**
     * Pohyby, kterými POHODA hradí doklad (úhrada nese id bankovního dokladu).
     *
     * @return array<int,true> bank_transactions.id
     */
    public static function paying(PohodaContext $ctx): array
    {
        $out = [];
        foreach ($ctx->liquidations as $l) {
            if ($l['agenda'] === 'bank' && ($l['source_id'] ?? '') !== '' && isset($ctx->bankByPohodaId[$l['source_id']])) {
                $out[$ctx->bankByPohodaId[$l['source_id']]] = true;
            }
        }
        return $out;
    }

    /**
     * Rozdělení podle id bankovního dokladu u řádků (MDB). Jen když id nese každý řádek čísla
     * a každé patří některému z jeho pohybů, jinak `null`.
     *
     * @param list<array{id:int,date:string,amount?:float}> $movements
     * @param list<array{date:string,source_id:string,net:float,candidates:list<float>}> $rows
     * @param array<int,string> $pohodaIds
     * @return array<int,list<array{date:string,source_id:string,net:float,candidates:list<float>}>>|null
     */
    private static function byId(array $movements, array $rows, array $pohodaIds): ?array
    {
        $out = [];
        $known = [];
        foreach ($movements as $m) {
            $out[(int) $m['id']] = [];
            if (isset($pohodaIds[(int) $m['id']])) {
                $known[$pohodaIds[(int) $m['id']]] = (int) $m['id'];
            }
        }
        foreach ($rows as $r) {
            $txId = $known[$r['source_id']] ?? null;
            if ($r['source_id'] === '' || $txId === null) {
                return null;
            }
            $out[$txId][] = $r;
        }
        return $out;
    }

    /**
     * Rozdělení podle částky (XML bez id dokladu u řádku): pohyb dostane nepoužitý řádek,
     * který na 221 dá jeho částku. Zbylé řádky dostane jediný nespárovaný pohyb, jehož
     * částku dají dohromady (pohyb rozúčtovaný do víc řádků). Zbydou-li řádky, které nejde
     * přiřadit, nebo nesedí žádný řádek, rozhodnutí není (`null`).
     *
     * @param list<array{id:int,date:string,amount?:float}> $movements
     * @param list<array{date:string,source_id:string,net:float,candidates:list<float>}> $rows
     * @return array<int,list<array{date:string,source_id:string,net:float,candidates:list<float>}>>|null
     */
    private static function byAmount(array $movements, array $rows): ?array
    {
        $out = [];
        $used = [];
        $matched = 0;
        foreach ($movements as $m) {
            $out[(int) $m['id']] = [];
            if (!isset($m['amount'])) {
                return null;
            }
            foreach ($rows as $i => $r) {
                if (isset($used[$i])) {
                    continue;
                }
                foreach ($r['candidates'] as $candidate) {
                    if (ReconciliationTolerance::sameCent($candidate, (float) $m['amount'])) {
                        $out[(int) $m['id']] = [$r];
                        $used[$i] = true;
                        $matched++;
                        continue 3;
                    }
                }
            }
        }
        if ($matched === 0) {
            return null;
        }
        $left = array_values(array_diff_key($rows, $used));
        if ($left === []) {
            return $out;
        }
        $open = array_values(array_filter($movements, static fn (array $m): bool => $out[(int) $m['id']] === []));
        if (count($open) === 1 && ReconciliationTolerance::sameCent(array_sum(array_column($left, 'net')), (float) $open[0]['amount'])) {
            $out[(int) $open[0]['id']] = $left;
            return $out;
        }
        return null;
    }
}
