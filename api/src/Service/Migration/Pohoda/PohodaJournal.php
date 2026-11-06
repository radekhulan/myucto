<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Service\Migration\MoneyS3\AccountCode;

/**
 * Pravidla čtení účetního deníku Pohody (`01_ucetni_denik.xml`, `act:accountingItem`)
 * na jednom místě - používá je import deníku, vazby dokladů i rekonciliace.
 *
 * **Strany zápisu jsou v Pohodě pojmenované obráceně:** `act:credit` je strana MD
 * a `act:debit` strana Dal (accountancy.xsd: credit = „MD.", debit = „DAL."). Přijatá
 * faktura má tedy `credit` 518 a `debit` 321, bankovní příjem od odběratele `credit` 221
 * a `debit` 311.
 *
 * Zdroj zápisu (`act:source`) je v exportu český název agendy.
 */
final class PohodaJournal
{
    public const OPENING = 'Počáteční stavy účtů';
    public const ISSUED = 'Vydané faktury';
    public const RECEIVED = 'Přijaté faktury';
    public const RECEIVABLE = 'Ostatní pohledávky';
    public const COMMITMENT = 'Ostatní závazky';
    public const BANK = 'Banka';
    public const CASH = 'Pokladna';
    public const INTERNAL = 'Interní doklady';
    public const ASSETS = 'Dlouhodobý majetek';
    public const ACCRUALS = 'Časové rozlišení';
    /** Agenda POHODY, kterou převod nezná jménem (MDB/SQL `RelUdAg`); převádí se jako ruční zápis. */
    public const UNKNOWN_AGENDA_PREFIX = 'Agenda POHODA č. ';

    /** Klíč otevíracího zápisu roku (počáteční stavy tvoří jediný zápis). */
    public const OPENING_KEY = 'PS';

    public static function source(array $item): string
    {
        return PohodaXml::text($item, 'source');
    }

    public static function number(array $item): string
    {
        return PohodaXml::text($item, 'number/numberRequested');
    }

    public static function isOpening(array $item): bool
    {
        return self::source($item) === self::OPENING;
    }

    /**
     * Uzávěrkové zápisy roku (konečné stavy na 702, převod výsledku přes 710). Převod je
     * nepřebírá - rok uzavírá průvodce uzávěrkou MyÚčta, stejně jako u Money S3.
     */
    public static function isYearEndClosing(array $item): bool
    {
        $source = mb_strtolower(self::source($item));
        return str_contains($source, 'konečné stavy') || str_contains($source, 'uzávěr') || str_contains($source, 'závěrk');
    }

    /**
     * Rok po roce agendy, do kterého řádek deníku padá (agenda POHODY vede doklady i po
     * 31. 12.). `null` = řádek roku agendy, počáteční stavy nebo uzávěrka. Kalendářní rok
     * pro náhled exportu (firma ještě není známá); převod do firmy počítá pozdější období
     * podle jejího kalendáře ({@see PohodaAgendaYear::laterYear()}).
     */
    public static function laterYear(array $item, int $agendaYear): ?int
    {
        if (self::isOpening($item) || self::isYearEndClosing($item)) {
            return null;
        }
        $year = (int) substr(PohodaXml::date($item, 'date') ?? '', 0, 4);
        return $year > $agendaYear ? $year : null;
    }

    /** Doklad v deníku: řádky se stejným zdrojem, číslem a datem. */
    public static function groupKey(array $item): string
    {
        if (self::isOpening($item)) {
            return self::OPENING_KEY;
        }
        return self::source($item) . '|' . self::number($item) . '|' . (PohodaXml::date($item, 'date') ?? '');
    }

    /**
     * Partner řádku deníku: `act:address` v XML (accountancy.xsd), v datovém souboru `pUD.Firma`,
     * `Jmeno`, `ParICO` a navíc vazba na adresář `RefAD`.
     *
     * @return array{pohoda_id:string,name:string,ico:string,dic:string,street:string,city:string,zip:string,country:string}
     */
    public static function partner(array $item): array
    {
        return PartnerImporter::snapshot(['id' => PohodaXml::text($item, 'addressbookId'), 'address' => PohodaXml::get($item, 'address')]);
    }

    /**
     * Zkratka agendy do popisu zápisu v deníku. Zrcadlí zkratky, kterými doklady
     * pojmenovává {@see \MyInvoice\Service\Accounting\JournalDescriptionBuilder}, ať
     * se převzatý zápis čte stejně jako zápis vzniklý v MyÚčtu. Neznámá agenda si
     * ponechá svůj český název z exportu (interní doklady, ostatní pohledávky…).
     */
    public static function shortLabel(string $source): string
    {
        return match ($source) {
            self::ISSUED   => 'FV',
            self::RECEIVED => 'PF',
            self::BANK     => 'Banka',
            self::CASH     => 'Pokladna',
            default        => $source,
        };
    }

    /** Zdroj zápisu → `journal_entries.source_type`. */
    public static function sourceType(string $source): string
    {
        return match ($source) {
            self::ISSUED => 'invoice',
            self::RECEIVED => 'purchase_invoice',
            self::BANK => 'bank',
            self::CASH => 'cash',
            default => 'manual', // interní doklady, ostatní pohledávky a závazky, majetek
        };
    }

    /**
     * Párovací symbol strany řádku na saldokontním účtu. Saldo POHODY páruje podle `ParSym`,
     * u zápočtu zálohy nese účet zálohy druhý symbol `ParSym2` (MD 321 / D 314: symbol faktury
     * na 321, symbol zálohy na 314). Na ostatních účtech je `null`.
     */
    public static function pairingSymbol(array $item, string $account): ?string
    {
        $synthetic = substr($account, 0, 3);
        if (!in_array($synthetic, ['311', '314', '315', '321', '324', '325'], true)) {
            return null;
        }
        $advance = PohodaXml::text($item, 'pairing/advance');
        $symbol = in_array($synthetic, ['314', '324'], true) && $advance !== '' ? $advance : PohodaXml::text($item, 'pairing/symbol');
        $symbol = mb_substr(trim($symbol), 0, 40);
        return $symbol === '' ? null : $symbol;
    }

    /**
     * Čistý účetní účinek řádku: kladná částka jde MD `credit` / D `debit`, záporná
     * obráceně. `null` = bez účinku (nulová částka, chybějící účet, stejný účet na obou
     * stranách - Pohoda tak vede třeba vyměření DPH v cizí měně na 349/349).
     *
     * @return array{debit:string,credit:string,amount:float}|null
     */
    public static function effect(array $item): ?array
    {
        $amount = round(PohodaXml::num($item, 'homeCurrency/priceSum'), 2);
        $md = PohodaXml::text($item, 'accounting/credit');
        $d = PohodaXml::text($item, 'accounting/debit');
        if ($amount === 0.0 || $md === '' || $d === '' || $md === $d) {
            return null;
        }
        if ($amount < 0) {
            return ['debit' => $d, 'credit' => $md, 'amount' => -$amount];
        }
        return ['debit' => $md, 'credit' => $d, 'amount' => $amount];
    }

    /**
     * Předvaha přímo z deníku Pohody po syntetických účtech: netto PS (počáteční stavy),
     * obrat (ostatní řádky bez uzávěrky) a KS. `$skipsDate` vynechá řádky s datem v roce,
     * který se nepřevádí ({@see PohodaContext::skipsDate()}).
     *
     * @param (callable(?string):bool)|null $skipsDate
     * @return array<string,array{0:float,1:float,2:float}>
     */
    public static function trialBalance(PohodaExport $export, ?callable $skipsDate = null): array
    {
        $out = [];
        foreach ($export->records('journal', 'accountingItem') as $item) {
            if (self::isYearEndClosing($item)) {
                continue;
            }
            if ($skipsDate !== null && !self::isOpening($item) && $skipsDate(PohodaXml::date($item, 'date'))) {
                continue;
            }
            $effect = self::effect($item);
            if ($effect === null) {
                continue;
            }
            $slot = self::isOpening($item) ? 0 : 1;
            foreach ([[$effect['debit'], 1], [$effect['credit'], -1]] as [$code, $sign]) {
                $syn = AccountCode::synthetic($code);
                if ($syn === null) {
                    continue;
                }
                $out[$syn] ??= [0.0, 0.0, 0.0];
                $out[$syn][$slot] += $sign * $effect['amount'];
            }
        }
        foreach ($out as $syn => $v) {
            $out[$syn] = [round($v[0], 2), round($v[1], 2), round($v[0] + $v[1], 2)];
        }
        ksort($out, SORT_STRING);
        return $out;
    }
}
