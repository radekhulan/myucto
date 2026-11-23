<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Service\Accounting\FiscalCalendar;

/**
 * Pravidla čtení účetního deníku Money (`UcDenik.DAT`) na jednom místě — používá je
 * náhled agendy, import deníku i rekonciliace. Kdyby si každý z nich určoval rok
 * nebo zdroj dokladu po svém, rekonciliace by kontrolovala sama sebe.
 */
final class Ms3Journal
{
    /** Zdroj řádku deníku, kterým Money označuje počáteční stavy. */
    public const OPENING_SOURCE = 'XP';

    /** Zdroj řádku deníku, kterým Money označuje uzávěrkové zápisy roku (převod na 702/710). */
    public const YEAR_END_CLOSING_SOURCE = 'XZ';

    /**
     * Pole deníku, se kterými pracují pravidla této třídy, převod deníku, dimenze
     * a rekonciliace. Deník se čte jen s nimi ({@see Ms3Table::rows()}); kdo potřebuje
     * další pole, musí ho sem doplnit.
     */
    public const FIELDS = ['Zdroj', 'Doklad', 'Datum', 'DatPlnDPH', 'Popis', 'Castka', 'UcMD', 'UcD', 'Stred', 'Zakazka'];

    /** Valuta řádku deníku (měna, kurz, částka v měně) - čte jen převod deníku. */
    public const CURRENCY_FIELDS = ['Mena', 'Kurz', 'CastkaCM', 'PocetJedn'];

    /** @param array<string,mixed> $row */
    public static function isOpening(array $row): bool
    {
        return trim((string) ($row['Zdroj'] ?? '')) === self::OPENING_SOURCE;
    }

    /**
     * Uzávěrka roku v Money: konečné stavy rozvahových účtů na 702 a výsledkové účty přes
     * 710. Převod ji nepřebírá — rok uzavře průvodce uzávěrkou MyÚčta ({@see HistoricalYearCloser})
     * proti počátečním stavům dalšího roku z Money. Převzatá by konečné stavy vynulovala
     * a uzávěrka MyÚčta by pak nesouhlasila s počátečními stavy ani nešla provést.
     *
     * @param array<string,mixed> $row
     */
    public static function isYearEndClosing(array $row): bool
    {
        return strtoupper(trim((string) ($row['Zdroj'] ?? ''))) === self::YEAR_END_CLOSING_SOURCE;
    }

    /**
     * Klíč dokladu v deníku: skupina řádků se stejným zdrojem, číslem a datem.
     * Počáteční stavy roku tvoří jediný otevírací zápis.
     *
     * @param array<string,mixed> $row
     */
    public static function groupKey(array $row): string
    {
        if (self::isOpening($row)) {
            return self::OPENING_SOURCE;
        }
        return trim((string) ($row['Zdroj'] ?? '')) . '|' . trim((string) ($row['Doklad'] ?? '')) . '|' . (string) ($row['Datum'] ?? '');
    }

    /**
     * Středisko řádku (`Stred`) — v MyÚčtu kód střediska na řádku deníku.
     *
     * @param array<string,mixed> $row
     */
    public static function costCenter(array $row): ?string
    {
        return mb_substr(trim((string) ($row['Stred'] ?? '')), 0, 50) ?: null;
    }

    /**
     * Zakázka řádku (`Zakazka`) — v MyÚčtu zakázka (`projects`). Registrační značka
     * vozidla, kterou účetní do zakázky píšou v různých tvarech (`5E6 7890`, `5E67890`,
     * `5E6 7890.`), se sjednotí na jeden tvar, ať z ní nevzniknou tři zakázky.
     *
     * @param array<string,mixed> $row
     */
    public static function jobCode(array $row): ?string
    {
        $code = trim((string) ($row['Zakazka'] ?? ''));
        if ($code === '') {
            return null;
        }
        return self::vehiclePlate($code) ?? mb_substr($code, 0, 50);
    }

    /**
     * Registrační značka v jednotném tvaru (`1AB 2345`, `EL 456 CD`), nebo null, když
     * kód značkou není. Běžná značka je číslice, písmeno, znak a čtyři číslice, starší
     * šestimístná číslice, písmeno a čtyři číslice, elektromobil EL, tři číslice a dvě písmena.
     */
    public static function vehiclePlate(string $code): ?string
    {
        $plain = strtoupper((string) preg_replace('/[\s.\-]+/u', '', $code));
        if (preg_match('/^(\d[A-Z][A-Z0-9]?)(\d{4})$/', $plain, $m) === 1) {
            return $m[1] . ' ' . $m[2];
        }
        if (preg_match('/^EL(\d{3})([A-Z]{2})$/', $plain, $m) === 1) {
            return 'EL ' . $m[1] . ' ' . $m[2];
        }
        return null;
    }

    /**
     * Zkratka agendy do popisu zápisu v deníku. Zrcadlí zkratky, kterými doklady
     * pojmenovává {@see \MyInvoice\Service\Accounting\JournalDescriptionBuilder}, ať
     * se převzatý zápis čte stejně jako zápis vzniklý v MyÚčtu. Neznámý zdroj si
     * ponechá svůj dvoupísmenný kód z Money (ID, KZ, KP…).
     */
    public static function shortLabel(string $moneySource): string
    {
        $code = strtoupper(trim($moneySource));

        return match ($code) {
            'FV' => 'FV',
            'FP' => 'PF',
            'BK' => 'Banka',
            'PK' => 'Pokladna',
            default => $code,
        };
    }

    /** Zdroj dokladu v Money → `journal_entries.source_type`. */
    public static function sourceType(string $moneySource): string
    {
        return match (strtoupper(trim($moneySource))) {
            'BK' => 'bank',
            'PK' => 'cash',
            'FP' => 'purchase_invoice',
            'FV' => 'invoice',
            default => 'manual', // ID interní doklady, KZ závazky, KP pohledávky, ostatní
        };
    }

    /**
     * Účetní rok adresáře ROK.nnn (label účetního období firmy). Nejčastější rok mezi
     * reálnými zápisy, ne první nalezený: jediný netypický řádek by jinak posunul celé
     * období. Rok, který má jen počáteční stavy, se vezme z jejich popisu („Počáteční stav
     * roku 2026").
     *
     * @param list<array<string,mixed>> $rows
     */
    public static function fiscalYear(array $rows, ?FiscalCalendar $calendar = null): ?int
    {
        return self::summarize($rows, $calendar)['fiscal_year'];
    }

    /**
     * Souhrn deníku roku v jednom průchodu - deník se tak nemusí držet v paměti celý:
     * počet řádků a počátečních stavů, účetní rok ({@see fiscalYear()}), zda deník leží
     * v jednom účetním roce firmy (`single_year`), data pro kontrolu tvaru roku
     * (`source_dates`, {@see \MyInvoice\Service\Migration\Shared\MigrationPeriods::assertSourceYear()}), první a poslední datum
     * mimo počáteční stavy a první datum zápisu v účetním roce.
     *
     * Rok se počítá v kalendáři účetního roku FIRMY (bez něj kalendářní rok): adresář ROK
     * je jeden účetní rok Money a odpovídá jednomu účetnímu období firmy, jehož label je
     * rok začátku období (agenda srpen 2024 – červenec 2025 u firmy s rokem od srpna = 2024).
     *
     * @param iterable<array<string,mixed>> $rows
     * @return array{rows:int,opening_rows:int,fiscal_year:?int,single_year:bool,source_dates:list<string>,first_date:?string,last_date:?string,first_entry:?string}
     */
    public static function summarize(iterable $rows, ?FiscalCalendar $calendar = null): array
    {
        $calendar ??= FiscalCalendar::calendar();
        $count = 0;
        $opening = 0;
        $years = [];
        $fromText = null;
        $firstAnyDate = null;
        $dated = 0;
        $datedByYear = [];
        $minByYear = [];
        $maxByYear = [];
        $openingDate = null;
        $firstDate = null;
        $lastDate = null;
        foreach ($rows as $r) {
            $count++;
            $isOpening = self::isOpening($r);
            $date = (string) ($r['Datum'] ?? '');
            if ($isOpening) {
                $opening++;
                if ($date !== '' && ($openingDate === null || $date < $openingDate)) {
                    $openingDate = $date;
                }
            } else {
                if ($date !== '') {
                    $y = $calendar->fiscalYearOfDate($date);
                    if ($y >= 1990 && $y <= 2100) {
                        $years[$y] = ($years[$y] ?? 0) + 1;
                    }
                    $dated++;
                    $datedByYear[$y] = ($datedByYear[$y] ?? 0) + 1;
                    if (!isset($minByYear[$y]) || $date < $minByYear[$y]) {
                        $minByYear[$y] = $date;
                    }
                    if (!isset($maxByYear[$y]) || $date > $maxByYear[$y]) {
                        $maxByYear[$y] = $date;
                    }
                }
                if (($r['Datum'] ?? null) !== null) {
                    $raw = (string) $r['Datum'];
                    if ($firstDate === null || $raw < $firstDate) {
                        $firstDate = $raw;
                    }
                    if ($lastDate === null || $raw > $lastDate) {
                        $lastDate = $raw;
                    }
                }
            }
            if ($fromText === null && $isOpening && preg_match('/\b(19|20)(\d{2})\b/', (string) ($r['Popis'] ?? ''), $m) === 1) {
                $fromText = (int) ($m[1] . $m[2]);
            }
            if ($firstAnyDate === null && $date !== '') {
                $firstAnyDate = $date;
            }
        }
        if ($years !== []) {
            arsort($years);
            $year = (int) array_key_first($years);
        } elseif (!$calendar->isCalendar() && $openingDate !== null) {
            // Popis počátečních stavů nese kalendářní rok, u hospodářského roku nejednoznačně.
            $year = $calendar->fiscalYearOfDate($openingDate);
        } elseif ($fromText !== null) {
            $year = $fromText;
        } else {
            $year = $firstAnyDate !== null ? $calendar->fiscalYearOfDate($firstAnyDate) : null;
        }
        $single = $year === null || self::singleYearShare($dated, $dated - ($datedByYear[$year] ?? 0));
        // Pár zápisů mimo rok je běžná chyba dokladu (faktura z 31. 12. zaúčtovaná v novém
        // roce): převod je zaúčtuje k hranici období a ohlásí po jednom. Do kontroly tvaru
        // roku proto jdou jen data hlavního roku; leží-li mimo něj velká část deníku, jdou
        // všechna a kontrola rok zastaví. Datum počátečních stavů (Money je datuje prvním
        // dnem svého účetního roku) jde do kontroly jen tehdy - zpřesní navržený měsíc,
        // ale samo rok nezastaví (Money je někdy datuje i posledním dnem roku předchozího).
        $sourceDates = [];
        if ($single && $year !== null && isset($minByYear[$year])) {
            $sourceDates = [$minByYear[$year], $maxByYear[$year]];
        } elseif (!$single && $minByYear !== []) {
            $opensMonth = $openingDate !== null && substr($openingDate, 8, 2) === '01';
            $sourceDates = array_merge($opensMonth ? [$openingDate] : [], [min($minByYear), max($maxByYear)]);
        }
        return [
            'rows' => $count,
            'opening_rows' => $opening,
            'fiscal_year' => $year,
            'single_year' => $single,
            'source_dates' => $sourceDates,
            'first_date' => $firstDate,
            'last_date' => $lastDate,
            'first_entry' => $year !== null ? ($minByYear[$year] ?? null) : null,
        ];
    }

    /** Podíl zápisů mimo hlavní účetní rok, nad kterým deník neleží v jednom roce firmy. */
    private const OUTSIDE_YEAR_SHARE = 0.1;

    private static function singleYearShare(int $total, int $outside): bool
    {
        return $total === 0 || $outside / $total <= self::OUTSIDE_YEAR_SHARE;
    }

    /**
     * Čistý účetní účinek řádku deníku: kladná částka jde na MD `UcMD` a D `UcD`,
     * záporná obráceně. `null` = řádek nemá účinek (nulová částka, chybějící účet
     * nebo stejný účet na obou stranách — Money je v počátečních stavech běžně má).
     *
     * @param array<string,mixed> $row
     * @return array{debit:string,credit:string,amount:float}|null
     */
    public static function effect(array $row): ?array
    {
        $amount = round((float) ($row['Castka'] ?? 0), 2);
        $md = trim((string) ($row['UcMD'] ?? ''));
        $d = trim((string) ($row['UcD'] ?? ''));
        if ($amount === 0.0 || $md === '' || $d === '' || $md === $d) {
            return null;
        }
        if ($amount < 0) {
            return ['debit' => $d, 'credit' => $md, 'amount' => -$amount];
        }
        return ['debit' => $md, 'credit' => $d, 'amount' => $amount];
    }
}
