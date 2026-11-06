<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Dimension;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DimensionAccountMapRepository;
use MyInvoice\Service\Accounting\JournalLineAmount;

/**
 * Účtotvorná dimenze: hodnota dimenze určí analytický účet (Firma → Dimenze, migrace 1952).
 *
 * Řádek zápisu na výsledkovém účtu (maska typu, výchozí `5, 6`), jehož účet je syntetika
 * z mapy a jehož hodnota účtotvorného typu má pro tu syntetiku mapování, jde na cílovou
 * analytiku. Řádek s rozpadem účtotvorného typu (60 % FVE, 40 % Kancelář) se rozdělí na
 * víc řádků po analytikách, haléřově přesně ({@see DimensionStamper::distributeCents()}),
 * a každý díl nese jen svou hodnotu typu. Ostatní dimenze a rozpady jiných typů zůstávají.
 *
 * Volá ho {@see \MyInvoice\Service\Accounting\PostingService::postDocument()} po razítkování
 * dimenzí — platí tedy pro každý zdroj zápisu (doklady, banka, pokladna, mzdy s výslovnými
 * dimenzemi řádků, ruční zápis). Účet, který builder nebo uživatel zvolil jako analytiku,
 * se nemění: mapa přepisuje jen syntetiku.
 *
 * Pořadí vůči přesměru na jedinou analytiku (PostingService::singleAnalyticMap): přesměr
 * běží dřív (v resolveLines), proto řádek, který přesměr poslal ze syntetiky S na její
 * jedinou analytiku, se tu bere jako řádek na S — a explicitní mapa dimenze má přednost.
 * Prakticky se obě pravidla střetnou jen tehdy, když má S jedinou daňovou analytiku a ta
 * je zároveň cílem mapy; výsledek je pak tentýž účet.
 *
 * Bez účtotvorného typu nebo bez platných řádků mapy se nic nemění (žádný dotaz navíc
 * kromě zjištění typu).
 *
 * Importy převzatých dat (převod z Money S3, POHODY, PREMIERu, Stereo NX) mapu ZÁMĚRNĚ
 * obcházejí: zapisují deník tak, jak ho zaúčtoval zdrojový program, a postDocument
 * nevolají — převzatý zápis se nesmí rozejít se zdrojem. Kontrola uzávěrky je proto
 * nehlásí ({@see \MyInvoice\Repository\DimensionAccountMapRepository::unmappedSyntheticLines()}).
 */
final class DimensionAccountRouter
{
    /** @var array<string,?array{type_id:int, mask:DimensionAccountMask, map:array<int,array<int,int>>, accounts:array<int,array<string,mixed>>}> */
    private array $contextCache = [];

    public function __construct(private readonly Connection $db) {}

    /**
     * Kontext firmy k datu, nebo null, když se nic mapovat nemá. Cache jen v rámci
     * jednoho objektu (zápis, přerazítkování) — mapa se mezi požadavky mění.
     *
     * @return array{type_id:int, mask:DimensionAccountMask, map:array<int,array<int,int>>, accounts:array<int,array<string,mixed>>}|null
     */
    public function context(int $supplierId, string $date, bool $currentRead = false): ?array
    {
        $key = $supplierId . '|' . $date;
        if (!$currentRead && array_key_exists($key, $this->contextCache)) {
            return $this->contextCache[$key];
        }
        $repo = new DimensionAccountMapRepository($this->db);
        $type = $repo->drivingType($supplierId, $currentRead);
        if ($type === null) {
            return $currentRead ? null : ($this->contextCache[$key] = null);
        }
        $map = $repo->activeMap($supplierId, $type['id'], $date, $currentRead);
        if ($map === []) {
            return $currentRead ? null : ($this->contextCache[$key] = null);
        }
        try {
            $mask = DimensionAccountMask::parse($type['mask']);
        } catch (DimensionException) {
            $mask = DimensionAccountMask::parse(DimensionAccountMapService::DEFAULT_MASK);
        }
        $context = [
            'type_id' => $type['id'],
            'mask' => $mask,
            'map' => $map,
            'accounts' => $repo->accounts($supplierId, $date, $currentRead),
        ];
        return $currentRead ? $context : ($this->contextCache[$key] = $context);
    }

    public function forget(): void
    {
        $this->contextCache = [];
    }

    /**
     * Uplatní mapu na řádky zápisu před zápisem.
     *
     * @param list<array<string,mixed>> $lines řádky s `account_id`, `side`, `amount`, `dimensions`, `dimension_splits`
     * @param array<string,string> $singleAnalytics syntetika => jediná analytika (přesměr z resolveLines)
     * @return list<array<string,mixed>>
     */
    public function apply(int $supplierId, array $lines, string $entryDate, array $singleAnalytics = [], bool $currentRead = false): array
    {
        $context = $this->context($supplierId, $entryDate, $currentRead);
        if ($context === null) {
            return $lines;
        }
        return self::route($lines, $context['type_id'], $context['mask'], $context['accounts'], $context['map'], self::origins($context['accounts'], $singleAnalytics));
    }

    /**
     * Čisté jádro (jednotkově testovatelné).
     *
     * @param list<array<string,mixed>> $lines
     * @param array<int,array<string,mixed>> $accounts id => {code, parent_id, account_type}
     * @param array<int,array<int,int>> $map syntetika => hodnota => analytika
     * @param array<int,int> $origins analytika => syntetika, ze které ji udělal přesměr na jedinou analytiku
     * @return list<array<string,mixed>>
     */
    public static function route(array $lines, int $typeId, DimensionAccountMask $mask, array $accounts, array $map, array $origins = []): array
    {
        $out = [];
        foreach ($lines as $line) {
            $synthetic = self::routedSynthetic((int) $line['account_id'], $mask, $accounts, $map, $origins, false);
            if ($synthetic === null) {
                $out[] = $line;
                continue;
            }
            $groups = self::groups($line, $typeId, $map[$synthetic]);
            if ($groups === null) {
                $out[] = $line;
                continue;
            }
            if (count($groups) === 1) {
                $line['account_id'] = array_key_first($groups);
                $out[] = $line;
                continue;
            }
            // Cizoměnovou stopu nese jen saldokonto, výsledkový řádek ji mít nemá; kdyby
            // přesto měl, dělit ho nejde (částka v měně by se rozešla) — zůstane na syntetice.
            if (($line['currency_code'] ?? null) !== null) {
                $out[] = $line;
                continue;
            }
            array_push($out, ...self::splitLine($line, $typeId, $groups));
        }
        return $out;
    }

    /**
     * Cílové účty jednoho řádku a jejich podíly (bez dělení na haléře) — pro časové
     * rozlišení, které musí odložit náklad z téže analytiky, na kterou ho zaúčtoval doklad.
     *
     * @param array<string,mixed> $line
     * @return array<int,float> id účtu => podíl (součet 1)
     */
    public static function targets(array $line, int $typeId, DimensionAccountMask $mask, array $accounts, array $map, array $origins = []): array
    {
        $accountId = (int) $line['account_id'];
        $synthetic = self::routedSynthetic($accountId, $mask, $accounts, $map, $origins, false);
        $groups = $synthetic === null ? null : self::groups($line, $typeId, $map[$synthetic]);
        if ($groups === null) {
            return [$accountId => 1.0];
        }
        $total = 0.0;
        foreach ($groups as $shares) {
            $total += array_sum($shares);
        }
        $out = [];
        foreach ($groups as $target => $shares) {
            $out[$target] = $total > 0.0 ? array_sum($shares) / $total : 1.0 / count($groups);
        }
        return $out;
    }

    /**
     * Přerazítkování zaúčtovaných řádků: nové dimenze se promítnou jen tam, kde nemění
     * účet. Řádky, které na syntetiku (nebo na analytiku z mapy) poslala tatáž syntetika,
     * se seskupí podle strany a nových dimenzí; očekávané rozdělení skupiny podle nových
     * dimenzí se porovná se skutečnými účty a částkami. Shoda → řádek dostane dimenze
     * svého dílu (u řádku rozděleného mapou jen svou hodnotu typu). Neshoda → `conflict`
     * (dimenze by změnila účet nebo částky, to smí jen přeúčtování).
     *
     * Neshoda je konflikt jen tehdy, když se mění hodnoty účtotvorného typu (stará vs.
     * nová hodnota nebo rozpad řádku). Zápis z doby před mapou (518 se střediskem FVE,
     * mapa FVE → 518.100 vznikla až potom) se změnou jiné dimenze (zakázka) nedostane
     * do konfliktu: účet by se nezměnil, protože hodnota typu je pořád stejná a zápis
     * se nepřeúčtovává — nové dimenze se přijmou tak, jak jsou.
     *
     * Analytika, která je cílem mapy, se bere jako výsledek mapy, i když ji builder zvolil
     * výslovně — změna hodnoty typu pak raději vyžádá přeúčtování, než aby tiše nechala
     * řádek na analytice cizí hodnoty.
     *
     * @param list<array<string,mixed>> $lines zaúčtované řádky (`id`, `account_id`, `side`, `amount`)
     *                                        s NOVÝMI `dimensions` / `dimension_splits`
     * @param array<int,array{dimensions?:array<int,int>, dimension_splits?:array<int,array<int,float>>}> $old
     *        id řádku => DOSAVADNÍ dimenze a rozpady (řádek bez záznamu se bere jako změněný)
     * @return array{lines:list<array<string,mixed>>, conflicts:list<int>} řádky s promítnutými dimenzemi, id řádků v konfliktu
     */
    public static function project(array $lines, int $typeId, DimensionAccountMask $mask, array $accounts, array $map, array $origins = [], array $old = []): array
    {
        $redirectOf = array_flip($origins);
        $groups = [];
        foreach ($lines as $i => $line) {
            $synthetic = self::routedSynthetic((int) $line['account_id'], $mask, $accounts, $map, $origins, true);
            if ($synthetic === null) {
                continue;
            }
            $key = implode('|', [
                $synthetic,
                (string) ($line['side'] ?? ''),
                !empty($line['is_red_storno']) ? 'r' : '',
                (string) ($line['currency_code'] ?? ''),
                json_encode(self::sorted((array) ($line['dimensions'] ?? [])), JSON_THROW_ON_ERROR),
                json_encode(self::sortedSplits((array) ($line['dimension_splits'] ?? [])), JSON_THROW_ON_ERROR),
            ]);
            $groups[$key]['synthetic'] = $synthetic;
            $groups[$key]['idx'][] = $i;
        }
        $conflicts = [];
        foreach ($groups as $group) {
            $idx = $group['idx'];
            $total = 0;
            $actual = [];
            foreach ($idx as $i) {
                $cents = self::cents($lines[$i]['amount']);
                $total += $cents;
                $acc = (int) $lines[$i]['account_id'];
                $actual[$acc] = ($actual[$acc] ?? 0) + $cents;
            }
            $proto = $lines[$idx[0]];
            $proto['account_id'] = $group['synthetic'];
            $proto['amount'] = $total / 100;
            $parts = self::route([$proto], $typeId, $mask, $accounts, $map, $origins);
            $expected = [];
            $byAccount = [];
            foreach ($parts as $part) {
                $acc = (int) $part['account_id'];
                // Díl, který mapa nechala na syntetice, by při zaúčtování poslal přesměr na
                // její jedinou analytiku (resolveLines běží před mapou).
                $acc = $acc === $group['synthetic'] ? ($redirectOf[$acc] ?? $acc) : $acc;
                $part['account_id'] = $acc;
                $expected[$acc] = ($expected[$acc] ?? 0) + self::cents($part['amount']);
                $byAccount[$acc] = $part;
            }
            ksort($actual);
            ksort($expected);
            if ($actual !== $expected) {
                $drivingChanged = false;
                foreach ($idx as $i) {
                    $id = (int) ($lines[$i]['id'] ?? 0);
                    if (!isset($old[$id]) || self::drivingKey($old[$id], $typeId) !== self::drivingKey($lines[$i], $typeId)) {
                        $drivingChanged = true;
                        break;
                    }
                }
                if (!$drivingChanged) {
                    continue;
                }
                foreach ($idx as $i) {
                    $conflicts[] = (int) ($lines[$i]['id'] ?? 0);
                }
                continue;
            }
            foreach ($idx as $i) {
                $part = $byAccount[(int) $lines[$i]['account_id']];
                unset($lines[$i]['dimensions'], $lines[$i]['dimension_splits']);
                if (!empty($part['dimensions'])) {
                    $lines[$i]['dimensions'] = $part['dimensions'];
                }
                if (!empty($part['dimension_splits'])) {
                    $lines[$i]['dimension_splits'] = $part['dimension_splits'];
                }
            }
        }
        return ['lines' => array_values($lines), 'conflicts' => $conflicts];
    }

    /**
     * Hodnota účtotvorného typu na řádku jako porovnatelný klíč (hodnota, nebo rozpad).
     *
     * @param array<string,mixed> $line
     */
    private static function drivingKey(array $line, int $typeId): string
    {
        $splits = $line['dimension_splits'][$typeId] ?? null;
        if (is_array($splits) && $splits !== []) {
            $row = [];
            foreach ($splits as $v => $share) {
                $row[(int) $v] = round((float) $share, 6);
            }
            ksort($row);
            return 's' . json_encode($row, JSON_THROW_ON_ERROR);
        }
        $value = (int) ($line['dimensions'][$typeId] ?? 0);
        return $value > 0 ? 'v' . $value : '';
    }

    /**
     * Přerazítkování podle aktuální mapy firmy: viz {@see project()}. Bez mapy beze změny.
     *
     * @param list<array<string,mixed>> $lines
     * @param array<string,string> $singleAnalytics
     * @param array<int,array<string,mixed>> $old id řádku => dosavadní dimenze a rozpady
     * @return array{lines:list<array<string,mixed>>, conflicts:list<int>}
     */
    public function projectForEntry(int $supplierId, string $entryDate, array $lines, array $singleAnalytics = [], array $old = [], bool $currentRead = false): array
    {
        $context = $this->context($supplierId, $entryDate, $currentRead);
        if ($context === null) {
            return ['lines' => $lines, 'conflicts' => []];
        }
        return self::project($lines, $context['type_id'], $context['mask'], $context['accounts'], $context['map'], self::origins($context['accounts'], $singleAnalytics), $old);
    }

    /**
     * Analytika => syntetika pro řádky, které na analytiku poslala mapa nebo přesměr na
     * jedinou analytiku. Přerazítkování podle toho pozná řádky rozdělené při zaúčtování.
     *
     * @param array<string,string> $singleAnalytics
     * @return array<int,int>
     */
    public function siblingAccounts(int $supplierId, string $date, array $singleAnalytics = [], bool $currentRead = false): array
    {
        $context = $this->context($supplierId, $date, $currentRead);
        if ($context === null) {
            return [];
        }
        $out = [];
        foreach (self::origins($context['accounts'], $singleAnalytics) as $analytic => $synthetic) {
            if (isset($context['map'][$synthetic])) {
                $out[$analytic] = $synthetic;
            }
        }
        foreach ($context['map'] as $synthetic => $values) {
            foreach ($values as $analytic) {
                $out[$analytic] = $synthetic;
            }
        }
        return $out;
    }

    /**
     * Syntetika, ze které se účet řádku odvozuje: syntetika s mapou sama, analytika,
     * na kterou ji poslal přesměr na jedinou analytiku, a při přerazítkování (`$posted`)
     * i analytika, která je cílem mapy. Jen výsledkové účty v masce typu.
     *
     * @param array<int,array<string,mixed>> $accounts
     * @param array<int,array<int,int>> $map
     * @param array<int,int> $origins
     */
    private static function routedSynthetic(int $accountId, DimensionAccountMask $mask, array $accounts, array $map, array $origins, bool $posted): ?int
    {
        $synthetic = null;
        if (isset($map[$accountId])) {
            $synthetic = $accountId;
        } elseif (isset($origins[$accountId]) && isset($map[$origins[$accountId]])) {
            $synthetic = $origins[$accountId];
        } elseif ($posted) {
            $parent = $accounts[$accountId]['parent_id'] ?? null;
            if ($parent !== null && isset($map[$parent]) && in_array($accountId, $map[$parent], true)) {
                $synthetic = (int) $parent;
            }
        }
        if ($synthetic === null) {
            return null;
        }
        $account = $accounts[$synthetic] ?? null;
        if ($account === null || !in_array($account['account_type'], ['expense', 'revenue'], true)
            || !$mask->matches((string) $account['code'])) {
            return null;
        }
        return $synthetic;
    }

    /**
     * Hodnoty účtotvorného typu na řádku seskupené podle cílového účtu. Hodnota bez
     * mapování zůstává na účtu řádku. Null = řádek hodnotu typu nemá.
     *
     * @param array<string,mixed> $line
     * @param array<int,int> $valueMap hodnota => analytika
     * @return array<int,array<int,float>>|null účet => hodnota => podíl
     */
    private static function groups(array $line, int $typeId, array $valueMap): ?array
    {
        $splits = $line['dimension_splits'][$typeId] ?? null;
        if (is_array($splits) && $splits !== []) {
            $groups = [];
            foreach ($splits as $valueId => $share) {
                $target = $valueMap[(int) $valueId] ?? (int) $line['account_id'];
                $groups[$target][(int) $valueId] = (float) $share;
            }
            return $groups;
        }
        $valueId = $line['dimensions'][$typeId] ?? null;
        if ($valueId === null || (int) $valueId <= 0) {
            return null;
        }
        return [$valueMap[(int) $valueId] ?? (int) $line['account_id'] => [(int) $valueId => 1.0]];
    }

    /**
     * Rozdělí řádek s rozpadem účtotvorného typu na díly po cílových účtech.
     *
     * @param array<string,mixed> $line
     * @param array<int,array<int,float>> $groups
     * @return list<array<string,mixed>>
     */
    private static function splitLine(array $line, int $typeId, array $groups): array
    {
        $weights = array_map(static fn (array $shares): float => array_sum($shares), array_values($groups));
        $cents = DimensionStamper::distributeCents(self::cents($line['amount']), $weights);
        $out = [];
        foreach (array_keys($groups) as $i => $accountId) {
            if ($cents[$i] === 0) {
                continue;
            }
            $part = $line;
            $part['account_id'] = $accountId;
            $part['amount'] = $cents[$i] / 100;
            $splits = (array) ($part['dimension_splits'] ?? []);
            unset($splits[$typeId], $part['dimension_splits']);
            $values = $groups[$accountId];
            if (count($values) === 1) {
                $dims = (array) ($part['dimensions'] ?? []);
                $dims[$typeId] = (int) array_key_first($values);
                ksort($dims);
                $part['dimensions'] = $dims;
            } else {
                $splits[$typeId] = self::normalizeShares($values);
                ksort($splits);
            }
            if ($splits !== []) {
                $part['dimension_splits'] = $splits;
            }
            $out[] = $part;
        }
        return $out;
    }

    /**
     * Podíly zbylých hodnot dílu přepočtené na 1 (deset desetinných míst, zbytek na poslední).
     *
     * @param array<int,float> $shares
     * @return array<int,float>
     */
    private static function normalizeShares(array $shares): array
    {
        ksort($shares);
        $sum = array_sum($shares);
        $out = [];
        $assigned = 0.0;
        $last = array_key_last($shares);
        foreach ($shares as $valueId => $share) {
            $value = $valueId === $last ? round(1.0 - $assigned, 10) : round($share / $sum, 10);
            $assigned += $value;
            $out[$valueId] = $value;
        }
        return $out;
    }

    /**
     * Analytika => syntetika pro přesměr na jedinou analytiku (kódy z PostingService).
     *
     * @param array<int,array<string,mixed>> $accounts
     * @param array<string,string> $singleAnalytics
     * @return array<int,int>
     */
    private static function origins(array $accounts, array $singleAnalytics): array
    {
        if ($singleAnalytics === []) {
            return [];
        }
        $ids = [];
        foreach ($accounts as $id => $a) {
            $ids[(string) $a['code']] = (int) $id;
        }
        $out = [];
        foreach ($singleAnalytics as $synthetic => $analytic) {
            if (isset($ids[$analytic], $ids[$synthetic])) {
                $out[$ids[$analytic]] = $ids[$synthetic];
            }
        }
        return $out;
    }

    /**
     * @param array<int|string,mixed> $dims
     * @return array<int,int>
     */
    private static function sorted(array $dims): array
    {
        $out = [];
        foreach ($dims as $t => $v) {
            $out[(int) $t] = (int) $v;
        }
        ksort($out);
        return $out;
    }

    /**
     * @param array<int|string,mixed> $splits
     * @return array<int,array<int,float>>
     */
    private static function sortedSplits(array $splits): array
    {
        $out = [];
        foreach ($splits as $t => $shares) {
            $row = [];
            foreach ((array) $shares as $v => $s) {
                $row[(int) $v] = round((float) $s, 10);
            }
            ksort($row);
            $out[(int) $t] = $row;
        }
        ksort($out);
        return $out;
    }

    private static function cents(mixed $amount): int
    {
        return JournalLineAmount::cents($amount);
    }
}
