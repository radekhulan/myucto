<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

/**
 * Faktury PREMIER (`FA_OUT` + `POLOZKY`, `FA_IN` + `POLOZ_IN`) složené s jejich zápisy
 * v deníku a úhradami - bez databáze, aby šla skladba dokladu testovat samostatně.
 *
 * **Částky dokladu v Kč se berou z deníku, ne z položek.** Faktura i položky jsou
 * v PREMIER v měně dokladu (`MENA`, `KURS` za `M_KURS` jednotek), deník v Kč - a z deníku
 * (řádky `P` základ a `D` daň po kódech DPH) PREMIER sestavuje i přiznání. Položky dávají
 * text, množství a rozpad; když na deník sedí (po kódech DPH do 1 Kč), převezmou se
 * a haléřový rozdíl kurzu se dorovná na největší položce, jinak se položky složí z deníku
 * po kódech a sazbách.
 *
 * Řádek deníku patří faktuře přes sborník (`SB_KOD` = řada faktury, `SBORNIK` = `INTER`).
 * Částky se čtou jako pohyb na účtu partnera dokladu (311… u vydané, 321… u přijaté -
 * účet, na který účtuje většina řádků dokladu). Řádek, který účet partnera nemá (třeba
 * samovyměření MD 343 / D 343, pokud ho účetní účtuje), do částek dokladu nevstupuje.
 *
 * **Úhrady** vede PREMIER vazbou řádku deníku na fakturu (`VAZBY`: `UD` ↔ `VF`/`PF`).
 * Uhrazeno = pohyb navázaných řádků na účtu partnera (banka, pokladna, zápočet, haléřový
 * a kurzový rozdíl).
 *
 * **Sborník a vazby nesou kód agendy, ne řadu.** Deník (`SB_KOD`) i `VAZBY` označují fakturu
 * kódem agendy (`VF` vydané, `PF` přijaté) a jejím `INTER`; řada faktury (`DOKLAD` v `FA_OUT`,
 * třeba `VFA`, `VFP`) se od kódu agendy liší, vede-li firma víc číselných řad. `INTER` je
 * v tabulce faktur jedinečný, takže kód agendy s `INTER` určí fakturu stejně přesně jako řada
 * ({@see directionOf()}).
 */
final class PremierDocuments
{
    public const ISSUED = 'issued';
    public const PURCHASE = 'purchase';

    /** Kód agendy faktur ve sborníku deníku a ve vazbách (`SB_KOD`, `VAZBY.KOD_*`). */
    private const AGENDA = [self::ISSUED => 'VF', self::PURCHASE => 'PF'];

    /** Rozdíl položek proti deníku, který se ještě bere jako kurzové zaokrouhlení. */
    private const ITEMS_TOLERANCE = 1.0;

    /** @var array<string,list<array<string,mixed>>> „směr|INTER" → položky */
    private array $items = [];

    /** @var array<string,list<int>> „směr|INTER faktury" → INTER navázaných řádků deníku */
    private array $payments = [];

    /** @var array<string,array{direction:string,from:int,to:int}> vazby faktura ↔ faktura téhož směru (dobropis k faktuře) */
    private array $documentLinks = [];

    /** @var array<string,string> řada faktury (`VF`, `ZVF`…) → směr */
    private array $series = [];

    /** @var array<string,string> řada → druh (`invoice` / `advance`) podle číselníku řad */
    private array $seriesKind = [];

    /** Sloupce hlavičky faktury, které převod čte ({@see build()}, {@see PartnerImporter::documentSnapshot()}). */
    private const HEADER_COLUMNS = [
        'INTER', 'DOKLAD', 'CISLO', 'DATUM_VYS', 'DATUM_USK', 'DATUM_SPL', 'DATUM_DPH', 'DATUM_KVY', 'MENA', 'M_KURS', 'KURS',
        'POPIS', 'POZNAMKA', 'VS', 'VARIABL', 'CISLO_PF', 'K_SYMBOL', 'UCET_ODB', 'FORMA', 'STORNO_FA',
        'CISLO_ODB', 'NAZEV_ODB', 'NAZEV_OD2', 'DIC_ODB', 'DIC_EU', 'ID_PAR', 'ICO_ODB', 'ULICE_ODB', 'MESTO_ODB', 'PSC_ODB', 'STAT_ODB',
    ];

    /** Sloupce položky faktury, které převod čte ({@see prepareItems()}). */
    private const ITEM_COLUMNS = [
        'FAKTURA', 'POL_SORT', 'PORDER', 'CENA', 'CENA_DPH', 'TEXT', 'TEXT_2', 'MNOZSTVI', 'MJ', 'SAZBA_DPH', 'KOD_DPH', 'UC_S', 'UC_SA', 'UC_D', 'UC_DA',
    ];

    /**
     * @param list<array<string,mixed>> $issued `FA_OUT`
     * @param list<array<string,mixed>> $purchases `FA_IN`
     * @param iterable<array<string,mixed>> $issuedItems `POLOZKY`
     * @param iterable<array<string,mixed>> $purchaseItems `POLOZ_IN`
     * @param iterable<array<string,mixed>> $links `VAZBY`
     * @param iterable<array<string,mixed>> $seriesRows `DOKLAD` (číselník řad s typem `TOK`)
     * @param array<string,string>|null $series řady všech faktur zálohy → směr, když `$issued`/`$purchases` nejsou úplné
     */
    public function __construct(
        private readonly array $issued,
        private readonly array $purchases,
        iterable $issuedItems,
        iterable $purchaseItems,
        iterable $links,
        iterable $seriesRows,
        private readonly PremierJournal $journal,
        private readonly PremierVat $vat,
        ?array $series = null,
    ) {
        foreach ([[self::ISSUED, $issuedItems], [self::PURCHASE, $purchaseItems]] as [$dir, $rows]) {
            foreach ($rows as $r) {
                $this->items[$dir . '|' . (int) ($r['FAKTURA'] ?? 0)][] = $r;
            }
        }
        foreach ($this->items as &$list) {
            usort($list, static fn (array $a, array $b): int => ((int) ($a['POL_SORT'] ?? 0) <=> (int) ($b['POL_SORT'] ?? 0))
                ?: ((int) ($a['PORDER'] ?? 0) <=> (int) ($b['PORDER'] ?? 0)));
        }
        unset($list);
        $this->series = $series ?? self::seriesOf($issued, $purchases);
        foreach ($seriesRows as $s) {
            $code = strtoupper(trim((string) ($s['DOKLAD'] ?? '')));
            // Typ řady 10 / 11 = přijaté / vydané zálohové listy (výzvy k platbě).
            $this->seriesKind[$code] = in_array((int) ($s['TOK'] ?? 0), [10, 11], true) ? 'advance' : 'invoice';
        }
        foreach ($links as $l) {
            $from = strtoupper(trim((string) ($l['KOD_ZDR'] ?? '')));
            $to = strtoupper(trim((string) ($l['KOD_TER'] ?? '')));
            $fromDir = $this->directionOf($from);
            $toDir = $this->directionOf($to);
            if ($from === 'UD' && $toDir !== null) {
                $this->payments[$toDir . '|' . (int) $l['INT_TER']][] = (int) $l['INT_ZDR'];
            } elseif ($to === 'UD' && $fromDir !== null) {
                $this->payments[$fromDir . '|' . (int) $l['INT_ZDR']][] = (int) $l['INT_TER'];
            } elseif ($fromDir !== null && $fromDir === $toDir && (int) $l['INT_ZDR'] !== (int) $l['INT_TER']) {
                $pair = [(int) $l['INT_ZDR'], (int) $l['INT_TER']];
                sort($pair);
                $this->documentLinks[$fromDir . '|' . $pair[0] . '|' . $pair[1]] = ['direction' => $fromDir, 'from' => $pair[0], 'to' => $pair[1]];
            }
        }
        foreach ($this->payments as $k => $ids) {
            $this->payments[$k] = array_values(array_unique($ids));
        }
    }

    /**
     * Faktury zálohy jen se sloupci, které převod čte. S rokem převodu se vynechají doklady
     * účtované až v dalších letech ({@see accountingYear()}) - {@see forYear()} je nevrátí;
     * jejich řady ale zůstávají (vazby úhrad z roku převodu na ně vedou).
     */
    public static function fromBackup(PremierBackup $backup, PremierJournal $journal, PremierVat $vat, ?int $year = null): self
    {
        $columns = array_flip(self::HEADER_COLUMNS);
        $headers = [self::ISSUED => [], self::PURCHASE => []];
        $kept = [self::ISSUED => [], self::PURCHASE => []];
        $series = [];
        foreach ([self::ISSUED => 'FA_OUT', self::PURCHASE => 'FA_IN'] as $dir => $table) {
            foreach ($backup->rows($table) as $h) {
                $series[strtoupper(trim((string) ($h['DOKLAD'] ?? '')))] = $dir;
                if ($year !== null) {
                    $docYear = self::accountingYear($h, $journal, $dir);
                    if ($docYear === null || $docYear > $year) {
                        continue;
                    }
                }
                $headers[$dir][] = array_intersect_key($h, $columns);
                $kept[$dir][(int) ($h['INTER'] ?? 0)] = true;
            }
        }
        unset($series['']);
        $items = static function (string $table, string $dir) use ($backup, $kept, $year): \Generator {
            $columns = array_flip(self::ITEM_COLUMNS);
            foreach ($backup->rows($table) as $r) {
                if ($year === null || isset($kept[$dir][(int) ($r['FAKTURA'] ?? 0)])) {
                    yield array_intersect_key($r, $columns);
                }
            }
        };
        return new self(
            $headers[self::ISSUED],
            $headers[self::PURCHASE],
            $items('POLOZKY', self::ISSUED),
            $items('POLOZ_IN', self::PURCHASE),
            $backup->rows('VAZBY'),
            $backup->rows('DOKLAD'),
            $journal,
            $vat,
            $series,
        );
    }

    /**
     * Rok, do kterého doklad patří, stejně jako v {@see build()}: datum zápisu v deníku,
     * nezaúčtovaný podle data plnění. `null` = doklad, který {@see build()} nesloží.
     *
     * @param array<string,mixed> $h
     */
    private static function accountingYear(array $h, PremierJournal $journal, string $direction): ?int
    {
        $inter = (int) ($h['INTER'] ?? 0);
        $series = strtoupper(trim((string) ($h['DOKLAD'] ?? '')));
        if ($inter <= 0 || $series === '' || trim((string) ($h['CISLO'] ?? '')) === '') {
            return null;
        }
        $issue = self::date($h['DATUM_VYS'] ?? null) ?? self::date($h['DATUM_USK'] ?? null);
        if ($issue === null) {
            return null;
        }
        $linked = array_filter([$journal->firstLinkedDate($series, $inter), $journal->firstLinkedDate(self::AGENDA[$direction], $inter)]);
        $accounting = ($linked !== [] ? min($linked) : null) ?? self::date($h['DATUM_USK'] ?? null) ?? $issue;
        return $journal->yearOf($accounting);
    }

    /**
     * Řádky deníku faktury: ve sborníku řady i kódu agendy ({@see AGENDA}). U řady shodné
     * s kódem agendy je to tentýž seznam.
     *
     * @return list<array<string,mixed>>
     */
    private function linkedRows(string $direction, string $series, int $inter): array
    {
        $agenda = self::AGENDA[$direction];
        $rows = $this->journal->linkedRows($series, $inter);
        if ($agenda === $series) {
            return $rows;
        }
        $seen = array_fill_keys(array_column($rows, 'inter'), true);
        foreach ($this->journal->linkedRows($agenda, $inter) as $r) {
            if (!isset($seen[$r['inter']])) {
                $rows[] = $r;
            }
        }
        return $rows;
    }

    /**
     * Směr faktur podle kódu ze sborníku deníku nebo z vazeb: řada faktury, nebo kód agendy
     * směru, který v záloze nějakou fakturu má. `null` = kód nepatří fakturám.
     */
    public function directionOf(string $code): ?string
    {
        if (isset($this->series[$code])) {
            return $this->series[$code];
        }
        $direction = array_search($code, self::AGENDA, true);
        return $direction !== false && in_array($direction, $this->series, true) ? $direction : null;
    }

    /**
     * @param list<array<string,mixed>> $issued
     * @param list<array<string,mixed>> $purchases
     * @return array<string,string>
     */
    private static function seriesOf(array $issued, array $purchases): array
    {
        $out = [];
        foreach ($issued as $h) {
            $out[strtoupper(trim((string) ($h['DOKLAD'] ?? '')))] = self::ISSUED;
        }
        foreach ($purchases as $h) {
            $out[strtoupper(trim((string) ($h['DOKLAD'] ?? '')))] = self::PURCHASE;
        }
        unset($out['']);
        return $out;
    }

    /**
     * Kódy faktur ve sborníku deníku → směr: řady faktur (`VF`, `VFA`, zálohové listy…)
     * a kódy agend směrů, které v záloze faktury mají ({@see directionOf()}).
     *
     * @return array<string,string>
     */
    public function invoiceSeries(): array
    {
        $out = $this->series;
        foreach (self::AGENDA as $direction => $code) {
            if (!isset($out[$code]) && in_array($direction, $this->series, true)) {
                $out[$code] = $direction;
            }
        }
        return $out;
    }

    /**
     * Řádky deníku, kterými byla faktura uhrazena (INTER řádku → faktura), podle vazeb `VAZBY`.
     *
     * @return array<int,list<array{direction:string,inter:int}>>
     */
    public function paymentLinks(): array
    {
        $out = [];
        foreach ($this->payments as $key => $rows) {
            [$direction, $inter] = explode('|', $key);
            foreach ($rows as $row) {
                $out[$row][] = ['direction' => $direction, 'inter' => (int) $inter];
            }
        }
        return $out;
    }

    /**
     * Vazby faktura ↔ faktura téhož směru z `VAZBY` (dobropis k opravované faktuře), každá
     * dvojice jednou.
     *
     * @return list<array{direction:string,from:int,to:int}>
     */
    public function documentLinks(): array
    {
        return array_values($this->documentLinks);
    }

    /**
     * Doklady směru pro rok převodu: zaúčtované v roce (podle data zápisu v deníku),
     * nezaúčtované (zálohové listy) podle data vystavení. `previous` = doklad účtovaný
     * v minulých letech, který k začátku roku není uhrazený (patří do salda).
     *
     * @return list<array<string,mixed>>
     */
    public function forYear(string $direction, int $year): array
    {
        $out = [];
        $start = $this->journal->calendar()->periodStart($year);
        foreach ($direction === self::ISSUED ? $this->issued : $this->purchases as $h) {
            $doc = $this->build($direction, $h);
            if ($doc === null || (!$doc['booked'] && $doc['items'] === [])) {
                continue; // rozpracovaný prázdný doklad bez položek i zápisu
            }
            if ($doc['year'] === $year) {
                $out[] = $doc;
            } elseif ($doc['year'] < $year && $doc['booked'] && $this->openAt($doc, $start)) {
                $out[] = ['previous' => true] + $doc;
            }
        }
        return $out;
    }

    /**
     * Složený doklad.
     *
     * @param array<string,mixed> $h řádek `FA_OUT` / `FA_IN`
     * @return array<string,mixed>|null
     */
    public function build(string $direction, array $h): ?array
    {
        $inter = (int) ($h['INTER'] ?? 0);
        $series = strtoupper(trim((string) ($h['DOKLAD'] ?? '')));
        $number = trim((string) ($h['CISLO'] ?? ''));
        if ($inter <= 0 || $series === '' || $number === '') {
            return null;
        }
        $rows = $this->linkedRows($direction, $series, $inter);
        $issue = self::date($h['DATUM_VYS'] ?? null) ?? self::date($h['DATUM_USK'] ?? null);
        $supply = self::date($h['DATUM_USK'] ?? null) ?? $issue;
        if ($issue === null) {
            return null;
        }
        $accounting = $rows !== [] ? min(array_column($rows, 'date')) : $supply;
        $currency = strtoupper(trim((string) ($h['MENA'] ?? '')));
        $currency = preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : '';
        $units = max(1, (int) ($h['M_KURS'] ?? 1));
        $rate = (float) ($h['KURS'] ?? 0);
        $factor = $currency !== '' && $currency !== 'CZK' && $rate > 0 ? $rate / $units : 1.0;

        $doc = [
            'direction' => $direction,
            'inter' => $inter,
            'series' => $series,
            'number' => $number,
            'kind' => $this->seriesKind[$series] ?? 'invoice',
            'issue' => $issue,
            'supply' => $supply,
            'accounting' => $accounting,
            'year' => $this->journal->yearOf($accounting),
            'due' => self::date($h['DATUM_SPL'] ?? null) ?? $issue,
            'vat_date' => self::date($h['DATUM_DPH'] ?? null),
            'kh_date' => self::date($h['DATUM_KVY'] ?? null),
            'currency' => $currency !== '' ? $currency : 'CZK',
            'factor' => $factor,
            'text' => trim((string) ($h['POPIS'] ?? '')),
            'note' => trim((string) ($h['POZNAMKA'] ?? '')),
            'variable_symbol' => trim((string) ($direction === self::ISSUED ? ($h['VS'] ?? '') : ($h['VARIABL'] ?? ''))),
            'vendor_number' => trim((string) ($h['CISLO_PF'] ?? '')) ?: trim((string) ($h['VARIABL'] ?? '')),
            'constant_symbol' => trim((string) ($h['K_SYMBOL'] ?? '')),
            'account_no' => trim((string) ($h['UCET_ODB'] ?? '')),
            'payment_form' => mb_strtolower(trim((string) ($h['FORMA'] ?? ''))),
            'storno' => (bool) ($h['STORNO_FA'] ?? false),
            'booked' => $rows !== [],
            'header' => $h,
            'rows' => $rows,
            'reasons' => [],
            'previous' => false,
        ];
        $this->amounts($doc, $this->items[$direction . '|' . $inter] ?? []);
        $this->settlement($doc);
        return $doc;
    }

    /**
     * Částky dokladu v Kč a jeho položky.
     *
     * @param array<string,mixed> $doc MĚNÍ SE
     * @param list<array<string,mixed>> $items
     */
    private function amounts(array &$doc, array $items): void
    {
        $normal = $doc['direction'] === self::ISSUED ? 1 : -1;
        $account = self::partnerAccount($doc['rows'], $doc['direction']);
        $doc['partner_account'] = $account;

        // Deník po kódech DPH: základ, daň, sazba z poměru daně a základu.
        $buckets = [];
        $rounding = 0.0;
        $total = 0.0;
        $advance = 0.0;
        foreach ($doc['rows'] as $r) {
            if ($account === null) {
                break;
            }
            $sign = ($r['md'] === $account ? 1 : 0) - ($r['dal'] === $account ? 1 : 0);
            if ($sign === 0) {
                continue;
            }
            $value = round($normal * $sign * $r['amount'], 2);
            $kind = $r['line_kind'];
            $code = $r['vat_code'];
            if ($kind === 'Z' && $code === '') {
                // Vypořádání zálohy (MD 324 / D 311): úhrada dokladu přijatou zálohou, ne plnění -
                // do položek ani do DPH nepatří, doklad ji nese jako odpočet zálohy.
                $advance -= $value;
                continue;
            }
            $total += $value;
            $isVatLine = $kind === 'D' || ($kind === '' && $code !== '' && str_starts_with(self::otherSide($r, $account), '343'));
            if ($kind === 'O' || ($code === '' && !$isVatLine && self::isRoundingAccount(self::otherSide($r, $account)) && abs($value) < 1.0)) {
                $rounding += $value;
                continue;
            }
            $buckets[$code] ??= ['code' => $code, 'base' => 0.0, 'vat' => 0.0, 'rate' => $r['vat_rate'], 'account' => ''];
            if ($isVatLine) {
                $buckets[$code]['vat'] += $value;
            } else {
                $buckets[$code]['base'] += $value;
                if ($buckets[$code]['account'] === '') {
                    $buckets[$code]['account'] = self::otherSide($r, $account);
                }
            }
            if ($r['vat_rate'] > 0) {
                $buckets[$code]['rate'] = $r['vat_rate'];
            }
        }

        $prepared = self::prepareItems($items, $doc['factor'], $doc['text'] ?: ('Doklad ' . $doc['series'] . ' ' . $doc['number']));
        if (!$doc['booked']) {
            // Nezaúčtovaný doklad (zálohový list): částky jen z položek.
            foreach ($prepared as $it) {
                $buckets[$it['code']] ??= ['code' => $it['code'], 'base' => 0.0, 'vat' => 0.0, 'rate' => $it['rate'], 'account' => $it['account']];
                $buckets[$it['code']]['base'] += $it['base'];
                $buckets[$it['code']]['vat'] += $it['vat'];
            }
            $total = array_sum(array_map(static fn (array $b): float => $b['base'] + $b['vat'], $buckets));
        }
        foreach ($buckets as $code => $b) {
            $buckets[$code]['base'] = round($b['base'], 2);
            $buckets[$code]['vat'] = round($b['vat'], 2);
            $buckets[$code]['rate'] = $this->bucketRate((string) $code, $buckets[$code], $prepared, $doc['vat_date'] ?? $doc['supply']);
        }

        $doc['items'] = $this->distribute($buckets, $prepared, $doc);
        $doc['rounding'] = round($rounding, 2);
        $doc['base'] = round(array_sum(array_column($doc['items'], 'base')), 2);
        $doc['vat'] = round(array_sum(array_column($doc['items'], 'vat')), 2);
        $doc['total'] = round($total, 2);
        $doc['advance_deducted'] = round($advance, 2);
        if ($doc['booked'] && abs($doc['base'] + $doc['vat'] + $doc['rounding'] - $doc['total']) >= 0.005) {
            $doc['rounding'] = round($doc['total'] - $doc['base'] - $doc['vat'], 2);
        }
        if ($doc['booked'] && $account === null) {
            $doc['reasons'][] = 'zápisy dokladu v deníku nemají společný účet partnera (311/321…), částky nejde určit';
        }
    }

    /**
     * Položky dokladu: z rozpisu PREMIER, sedí-li po kódech DPH na deník, jinak z deníku.
     * Součet položek kódu je vždy přesně částka deníku (daň se rozpočítá podle základu).
     *
     * @param array<string,array{code:string,base:float,vat:float,rate:float}> $buckets
     * @param list<array<string,mixed>> $prepared
     * @param array<string,mixed> $doc
     * @return list<array<string,mixed>>
     */
    private function distribute(array $buckets, array $prepared, array &$doc): array
    {
        $out = [];
        $source = 'detail';
        foreach ($buckets as $code => $b) {
            $mine = array_values(array_filter($prepared, static fn (array $i): bool => $i['code'] === (string) $code && abs($i['base']) >= 0.005));
            if (abs($b['base']) < 0.005 && abs($b['vat']) < 0.005 && $mine === []) {
                continue;
            }
            $sum = round(array_sum(array_column($mine, 'base')), 2);
            $fits = $mine !== [] && abs($sum - $b['base']) <= max(self::ITEMS_TOLERANCE, 0.001 * abs($b['base']));
            if (!$fits && abs($b['base']) < 0.005 && abs($b['vat']) < 0.005) {
                continue;
            }
            if (!$fits) {
                $source = 'journal';
                $mine = [[
                    'description' => $prepared[0]['description'] ?? ($doc['text'] ?: ('Doklad ' . $doc['series'] . ' ' . $doc['number'])),
                    'quantity' => 1.0, 'unit' => null, 'unit_price' => $b['base'],
                    'base' => $b['base'], 'vat' => 0.0, 'rate' => $b['rate'], 'code' => (string) $code,
                    'account' => (string) ($b['account'] ?? ''),
                ]];
                $sum = $b['base'];
            }
            // Haléřový rozdíl kurzu na největší položku, daň podle podílu na základu.
            $largest = 0;
            foreach ($mine as $i => $it) {
                if (abs($it['base']) > abs($mine[$largest]['base'])) {
                    $largest = $i;
                }
            }
            $mine[$largest]['base'] = round($mine[$largest]['base'] + ($b['base'] - $sum), 2);
            // Daň položek PREMIER platí, sedí-li jejich součet na deník (odpočet zálohy nese
            // vlastní zápornou daň); jinak se daň deníku rozpočítá podle základu.
            $ownVat = round(array_sum(array_map(static fn (array $i): float => (float) $i['vat'], $mine)), 2);
            $keepOwn = $fits && abs($ownVat - $b['vat']) <= max(self::ITEMS_TOLERANCE, 0.001 * abs($b['vat']));
            $vatLeft = $b['vat'];
            $last = count($mine) - 1;
            foreach ($mine as $i => $it) {
                $share = $keepOwn ? (float) $it['vat'] : (abs($b['base']) >= 0.005 ? $b['vat'] * $it['base'] / $b['base'] : 0.0);
                $vat = $i === $last ? round($vatLeft, 2) : round($share, 2);
                $vatLeft -= $vat;
                $qty = (float) $it['quantity'];
                $out[] = [
                    'description' => $it['description'],
                    'quantity' => $qty,
                    'unit' => $it['unit'],
                    'unit_price' => $qty != 0.0 ? round($it['base'] / $qty, 6) : $it['base'],
                    'base' => $it['base'],
                    'vat' => $vat,
                    'rate' => $b['rate'],
                    'code' => (string) $code,
                    // Nákladový / výnosový účet položky v PREMIER (`518100`) - rozlišuje drobný majetek.
                    // Položka z rozpisu nese vlastní účet (i prázdný - odpočet zálohy na 314),
                    // účet dokladu z deníku dostane jen položka složená z deníku.
                    'account' => (string) ($it['account'] ?? ($b['account'] ?? '')),
                ] + (isset($it['foreign_base'], $it['foreign_vat'])
                    // Základ a daň v měně dokladu, jak jsou na položce - čte je OSS větev vydaného
                    // dokladu ({@see \MyInvoice\Service\Migration\OssMigrationPolicy::returnAmounts()})
                    // a převzetí dokladu v měně ({@see \MyInvoice\Service\Migration\Shared\ForeignCurrencyTakeover}).
                    ? ['foreign_base' => $it['foreign_base'], 'foreign_vat' => $it['foreign_vat']] : []);
            }
        }
        $doc['items_source'] = $source;
        return $out;
    }

    /**
     * Sazba kódu: z poměru daně a základu v deníku (přichycená k platné sazbě), jinak
     * z položky, jinak z třídy sazby kódu k datu plnění.
     *
     * @param array{code:string,base:float,vat:float,rate:float} $b
     * @param list<array<string,mixed>> $prepared
     */
    private function bucketRate(string $code, array $b, array $prepared, string $date): float
    {
        if (abs($b['base']) >= 1.0 && abs($b['vat']) >= 0.005) {
            $ratio = 100 * $b['vat'] / $b['base'];
            foreach ([21.0, 15.0, 12.0, 10.0, 20.0, 14.0, 19.0, 9.0, 5.0] as $known) {
                if (abs($ratio - $known) <= 0.6) {
                    return $known;
                }
            }
        }
        if ($b['rate'] > 0) {
            return (float) $b['rate'];
        }
        foreach ($prepared as $it) {
            if ($it['code'] === $code && $it['rate'] > 0) {
                return (float) $it['rate'];
            }
        }
        $other = $this->vat->otherRate($code);
        if ($other > 0) {
            return $other;
        }
        $class = $this->vat->rateClass($code);
        if ($class !== null && ($this->vat->lines($code) !== [] || abs($b['vat']) >= 0.005)) {
            return PremierVat::rateFor($class, $date);
        }
        return 0.0;
    }

    /**
     * Stav úhrady z navázaných řádků deníku.
     *
     * @param array<string,mixed> $doc MĚNÍ SE: paid, paid_at, settled, payment_rows
     */
    private function settlement(array &$doc): void
    {
        $account = $doc['partner_account'] ?? null;
        $normal = $doc['direction'] === self::ISSUED ? 1 : -1;
        $own = array_fill_keys(array_column($doc['rows'], 'inter'), true);
        $paid = 0.0;
        $paidAt = null;
        $rows = [];
        foreach ($this->payments[$doc['direction'] . '|' . $doc['inter']] ?? [] as $inter) {
            $r = $this->journal->row($inter);
            if ($r === null || isset($own[$inter])) {
                continue;
            }
            $acct = $account ?? ($doc['direction'] === self::ISSUED ? '311' : '321');
            $movement = PremierJournal::movement($r, $acct);
            if (abs($movement) < 0.005) {
                continue;
            }
            $paid += -$normal * $movement;
            $paidAt = max($paidAt ?? $r['date'], $r['date']);
            $rows[] = ['inter' => $inter, 'date' => $r['date'], 'amount' => round(-$normal * $movement, 2)];
        }
        $doc['paid'] = round($paid, 2);
        $doc['paid_at'] = $paidAt;
        $doc['payment_rows'] = $rows;
        if (abs($doc['total']) < 0.005) {
            // Nic k úhradě (konečná faktura plně krytá zálohou) - navázaný řádek je odpočet zálohy.
            $doc['paid'] = 0.0;
            $doc['payment_rows'] = [];
        }
        $doc['settled'] = abs($doc['total'] - ($doc['advance_deducted'] ?? 0.0) - $doc['paid']) < 0.01;
    }

    /** @param array<string,mixed> $doc */
    private function openAt(array $doc, string $date): bool
    {
        $paid = (float) ($doc['advance_deducted'] ?? 0.0);
        foreach ($doc['payment_rows'] as $p) {
            if ($p['date'] < $date) {
                $paid += $p['amount'];
            }
        }
        return abs($doc['total'] - $paid) >= 0.01;
    }

    /**
     * Položky PREMIER přepočtené do Kč (bez dorovnání - to dělá {@see distribute()}).
     *
     * @param list<array<string,mixed>> $items
     * @return list<array{description:string,quantity:float,unit:?string,base:float,vat:float,rate:float,code:string}>
     */
    private static function prepareItems(array $items, float $factor, string $fallbackText): array
    {
        $out = [];
        foreach ($items as $it) {
            $foreign = [round((float) ($it['CENA'] ?? 0), 2), round((float) ($it['CENA_DPH'] ?? 0), 2)];
            $base = round((float) ($it['CENA'] ?? 0) * $factor, 2);
            $vat = round((float) ($it['CENA_DPH'] ?? 0) * $factor, 2);
            $text = trim(trim((string) ($it['TEXT'] ?? '')) . ' ' . trim((string) ($it['TEXT_2'] ?? '')));
            $qty = (float) ($it['MNOZSTVI'] ?? 0);
            $out[] = [
                'description' => mb_substr($text !== '' ? $text : $fallbackText, 0, 1000),
                'quantity' => $qty != 0.0 ? round($qty, 3) : 1.0,
                'unit' => mb_substr(trim((string) ($it['MJ'] ?? '')), 0, 20) ?: null,
                'base' => $base,
                'vat' => $vat,
                'rate' => (float) ($it['SAZBA_DPH'] ?? 0),
                'code' => trim((string) ($it['KOD_DPH'] ?? '')),
                // Účet položky: syntetika + analytika (`UC_S` + `UC_SA`, vydaná `UC_D` + `UC_DA`).
                'account' => self::itemAccount($it),
            ] + ($factor !== 1.0 ? ['foreign_base' => $foreign[0], 'foreign_vat' => $foreign[1]] : []);
        }
        return $out;
    }

    /**
     * Účet partnera dokladu: účet, který se v řádcích dokladu opakuje nejčastěji na straně
     * pohledávky (MD u vydané) / závazku (Dal u přijaté); při shodě má přednost třída 3.
     * Rozhoduje sloupec, ne znaménko - dobropis PREMIER účtuje zápornou částkou na tytéž
     * strany jako fakturu. Řádek samovyměření MD 343 / D 343 nehlasuje - jinak by při
     * shodě hlasů a pořadí v deníku vyhrál účet 343 a doklad by měl částku daně místo základu.
     *
     * @param list<array<string,mixed>> $rows
     */
    private static function partnerAccount(array $rows, string $direction): ?string
    {
        $votes = [];
        $side = $direction === self::ISSUED ? 'md' : 'dal';
        foreach ($rows as $r) {
            if (str_starts_with($r['md'], '343') && str_starts_with($r['dal'], '343')) {
                continue; // samovyměření MD 343 / D 343 - účet partnera nenese (stejně jako VatDocumentImporter)
            }
            $code = $r[$side];
            if ($code !== '') {
                $votes[$code] = ($votes[$code] ?? 0) + 1 + (str_starts_with($code, '3') ? 0.5 : 0);
            }
        }
        if ($votes === []) {
            return null;
        }
        arsort($votes);
        return (string) array_key_first($votes);
    }

    /**
     * Nákladový (přijatá) nebo výnosový (vydaná) účet položky. PREMIER vede na položce MD
     * (`UC_S`/`UC_SA`) i Dal (`UC_D`/`UC_DA`); protiúčet partnera a DPH (3xx) se přeskakuje.
     *
     * @param array<string,mixed> $it
     */
    private static function itemAccount(array $it): string
    {
        foreach ([['UC_S', 'UC_SA'], ['UC_D', 'UC_DA']] as [$syn, $analytic]) {
            $code = trim((string) ($it[$syn] ?? '')) . trim((string) ($it[$analytic] ?? ''));
            if (ctype_digit($code) && strlen($code) >= 3 && !str_starts_with($code, '3')) {
                return $code;
            }
        }
        return '';
    }

    /** @param array<string,mixed> $r */
    private static function otherSide(array $r, string $account): string
    {
        return $r['md'] === $account ? $r['dal'] : $r['md'];
    }

    private static function isRoundingAccount(string $code): bool
    {
        return in_array(substr($code, 0, 3), ['548', '648', '568', '668'], true);
    }

    private static function date(mixed $value): ?string
    {
        $v = (string) ($value ?? '');
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null;
    }
}
