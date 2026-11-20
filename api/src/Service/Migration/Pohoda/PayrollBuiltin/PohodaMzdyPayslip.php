<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda\PayrollBuiltin;

use MyInvoice\Service\Migration\Pohoda\PohodaXml;

/**
 * Jedna mzda (`MZ`) z agendy Mzdy vedené přímo v POHODĚ rozložená do tvaru, který čte
 * převod mezd PAMICA: mzdové složky (`MZslozky`), nepřítomnosti (`MZneprit`) a sloupce
 * mzdy, které POHODA vede jinak než PAMICA.
 *
 * POHODA nemá položky mzdy. Částky a hodiny jsou sloupce mzdy, doby nepřítomnosti s daty
 * jsou na kartě zaměstnance (`ZAMpDov`) a náhrada mzdy při nemoci v `MZnahr`. Význam
 * sloupců je ověřený na úhrnech reálných mezd (měsíční mzda, dovolená, svátky, nemoc,
 * stravenkový paušál):
 *  - `KcHrubaM` = `KcZaklM` + `KcDov` + `KcSvatN`,
 *  - `KcZaklM` je u měsíční mzdy (`RelDruhM` 1) sjednaná mzda `KcSzM` krácená podílem
 *    pracovních dnů bez svátků (`DnyPrac` / (`DnyPrac` + `DnyStSv`)),
 *  - `KcSvatN` je náhrada za svátek z průměru: `DnySvat` × `DUvazek` × `KcPrum`,
 *  - `KcDov` je náhrada za dovolenou za `HodDov` hodin, doby nese `ZAMpDov` s druhem 35,
 *  - `HodNem` jsou hodiny pracovní neschopnosti (`ZAMpDov` s druhem 4), náhradu mzdy
 *    `KcNahrNem` (mimo hrubou mzdu) rozpisuje `MZnahr`,
 *  - `KcCistaM` = `KcHrubaM` - `KcSoc` - `KcZdr` - `KcZalDan` + `KcPreplDanRZ` (přeplatek
 *    daně z ročního zúčtování),
 *  - `KcDanPrS` je záloha před slevami, `KcDanZal` po slevě na poplatníka, `KcZalDan`
 *    po slevě na dítě a `KcDanBon` daňový bonus,
 *  - `KcVyuct` = `KcCistaM` + `KcNahrNem` + `KcDanBon` + `KcStrav` + `KcDoplDanBonRZ`
 *    (doplatek daňového bonusu z ročního zúčtování) - `KcSrazky`; `KcDanUpr` je součet
 *    obou úprav z ročního zúčtování,
 *  - `HodOdpra` jsou odpracované hodiny BEZ svátků (PAMICA je u měsíční mzdy počítá do
 *    odpracovaných), `StanovenyFond` = (`DnyPrac` + `DnyStSv`) × 8.
 *
 * Co z toho ověřit nejde, převod nehádá: nenulový sloupec, jehož význam ověřený není,
 * vrací jako nepřevedenou položku ({@see self::UNCONVERTED_WAGE_COLUMNS} a další).
 */
final class PohodaMzdyPayslip
{
    /** Číslo složky a nepřítomnosti PAMICA, pod kterým převod sloupec POHODY předá. */
    public const MONTHLY_WAGE = 'M01';
    public const HOURLY_WAGE = 'C01';
    public const MEAL_ALLOWANCE = 'Z21';
    public const VACATION = 'V01';
    public const HOLIDAY = 'V02';
    public const SICKNESS = 'H01';

    /**
     * Druh doby na kartě zaměstnance (`ZAMpDov.RelDrDov`) => nepřítomnost PAMICA. Ověřeno
     * na mzdách: 35 = dovolená (hodiny druhu sedí na `HodDov`), 4 = pracovní neschopnost
     * (hodiny sedí na `HodNem`, náhrada `MZnahr` míří na tutéž dobu). Ostatní druhy převod
     * nezná a vrací je k ručnímu doplnění.
     */
    public const ABSENCE_KINDS = ['35' => self::VACATION, '4' => self::SICKNESS];

    /** Druh mzdy (`RelDruhM`) => základní složka; hodinová mzda jen s doloženým součinem hodin a sazby. */
    public const WAGE_KINDS = ['1' => self::MONTHLY_WAGE, '2' => self::HOURLY_WAGE];

    /**
     * Sloupce mzdy POHODY, které podle názvu patří do hrubé mzdy, ale jejich význam na
     * reálných mzdách ověřený není (přesčasy, příplatky, prémie, osobní ohodnocení, náhrady
     * za překážky). Nenulová částka se nepřevede do složek, vrátí se jako nepřevedená
     * a kontrola hrubé mzdy ji započte, aby rozdíl ukázal jen sloupec, který převod nezná vůbec.
     * Dvojice částka a hodiny jdou za sebou.
     */
    public const UNCONVERTED_WAGE_COLUMNS = [
        'KcPres', 'HodPres', 'KcPresZM', '', 'KcSvat', 'HodSvat', 'KcSvatZM', '', 'KcSoNe', 'HodSoNe',
        'KcNoc', 'HodNoc', 'KcPohot', 'HodPohot', 'KcProst', 'HodProst', 'KcJproc', 'HodJproc',
        'KcJproc2', 'HodJproc2', 'KcJproc3', 'HodJproc3', 'KcJkor', 'HodJkor', 'KcJkor2', 'HodJkor2',
        'KcJkor3', 'HodJkor3', 'KcOsOhoM', '', 'KcPremM', '', 'KcPremDl', '', 'KcDoplat', '', 'KcNahrZ', '',
        'KcPlacV', 'HodPlacV', 'KcPlacAkceDeti', 'HodPlacAkceDeti', 'KcProstoje', 'HodProstoje',
        'KcVojCv', 'HodVojCv', 'KcVojZS', '', 'KcZtratV', '', 'KcOdstup', '', 'KcProplacDovol', 'HodProplDov',
    ];

    /**
     * Ostatní sloupce mzdy s částkou mimo hrubou mzdu, které převod nepřevádí: benefity,
     * pojistné a spoření hrazené zaměstnavatelem, práce na dálku, srážky a záloha, doplatky
     * z ročního zúčtování a opravy daně. Nenulová hodnota jde do nepřevedených položek.
     * Příspěvek na penzijní produkty (`KcPripoj`) a DIP (`KcDIP`) nese mzda POHODY pod týmž
     * jménem jako PAMICA a převod PAMICA ho čte sám.
     */
    public const UNCONVERTED_OTHER_COLUMNS = [
        'KcStravNad', '', 'KcPracDalka', 'HodPracDalka', 'KcPracDalkaNad', '', 'KcZamBenefit', '',
        'KcZamBenefitNad', '', 'KcZdrBenefit', '', 'KcZdrBenefitNad', '', 'KcZivPoj', '', 'KcSporStari', '',
        'KcPripojPov', '', 'KcPjDloPc', '', 'KcNatPj', '', 'KcNatBP', '',
        'KcZvZkPj', '', 'KcZvZkBP', '', 'KcOsvobP', '', 'KcOsvobP2', '', 'KcSrazky', '', 'KcZaloha', '',
        'KcDanUpr', '', 'KcPreplDanRZ', '', 'KcDoplDanBonRZ', '', 'KcNahrN', '', 'KcNahrN2', '',
        'KcDavNem', '', 'KcDavOCR', '', 'KcDavPPM', '', 'KcDavVyr', '', 'KcPrispKarantena', '',
    ];

    /** Nástup a skončení vztahu, které PAMICA nese i ve mzdě; POHODA jen na kartě zaměstnance. */
    private const RELATION_DATE_COLUMNS = ['DatNast', 'DatVstup', 'DatOdch'];

    /**
     * Nepřítomnosti, které POHODA vede jen součtem ve mzdě (dny a hodiny) a jejichž doby
     * převod z karty nezná: neplacené volno, neomluvená absence, ošetřovné, mateřská,
     * otcovská. Bez dat od a do je evidence nepřítomností nezapíše; jdou k ručnímu doplnění.
     */
    public const UNCONVERTED_ABSENCE_COLUMNS = ['HodNepl', 'HodNeoml', 'HodOCR', 'HodOCRdlo', 'HodMater', 'HodOPP'];

    /**
     * @param array<string,mixed> $mz řádek `MZ`
     * @param list<array<string,mixed>> $periods doby z karty (`ZAMpDov`) osoby, které leží v měsíci mzdy
     * @param array<string,float> $compensations `ZAMpDov.ID` => náhrada mzdy při nemoci z `MZnahr` této mzdy
     * @param ?array<string,mixed> $person řádek `ZAM`
     * @return array{
     *   components:list<array{number:string,amount:float,hours:float,rate:float,units:float}>,
     *   absences:list<array{number:string,id:string,from:?string,to:?string,hours:float,days:float,calendar_days:float,amount:float}>,
     *   mz:array<string,string>,
     *   unconverted:array<string,array{code:string,name:string,kind:string,inputs:int,amount:float,hours:float}>,
     *   gross:array{source:float,converted:float,unconverted:float}
     * }
     */
    public static function map(array $mz, array $periods, array $compensations, ?array $person): array
    {
        $daily = PohodaXml::num($mz, 'DUvazek') > 0 ? PohodaXml::num($mz, 'DUvazek') : (PohodaXml::num($mz, 'TUvazek') > 0 ? PohodaXml::num($mz, 'TUvazek') / 5 : 8.0);
        $components = [];
        $absences = [];
        $unconverted = [];
        $skip = static function (string $kind, string $code, string $name, float $amount, float $hours) use (&$unconverted): void {
            if (round($amount, 2) == 0.0 && round($hours, 2) == 0.0) {
                return;
            }
            $key = $kind . ':' . $code;
            $unconverted[$key] ??= ['code' => $code, 'name' => $name, 'kind' => $kind, 'inputs' => 0, 'amount' => 0.0, 'hours' => 0.0];
            $unconverted[$key]['inputs']++;
            $unconverted[$key]['amount'] = round($unconverted[$key]['amount'] + $amount, 2);
            $unconverted[$key]['hours'] = round($unconverted[$key]['hours'] + $hours, 2);
        };

        // Základní mzda podle druhu mzdy. Hodinová mzda jen tehdy, když ji mzda sama doloží
        // součinem odpracovaných hodin a sazby; jinak by se z ní stala sazba, kterou POHODA neměla.
        $base = PohodaXml::num($mz, 'KcZaklM');
        $wageKind = trim(PohodaXml::text($mz, 'RelDruhM'));
        $converted = 0.0;
        $unconvertedGross = 0.0;
        $number = self::WAGE_KINDS[$wageKind] ?? null;
        if ($number === self::HOURLY_WAGE && abs($base - PohodaXml::num($mz, 'HodOdpra') * PohodaXml::num($mz, 'KcSzM')) > 1.0) {
            $number = null;
        }
        if (round($base, 2) != 0.0) {
            if ($number === null) {
                $skip('component', 'KcZaklM', "základní mzda, druh mzdy {$wageKind}", $base, PohodaXml::num($mz, 'HodOdpra'));
                $unconvertedGross += $base;
            } else {
                $components[] = ['number' => $number, 'amount' => $base, 'hours' => PohodaXml::num($mz, 'HodOdpra'), 'rate' => PohodaXml::num($mz, 'KcSzM'), 'units' => 0.0];
                $converted += $base;
            }
        }
        if (round(PohodaXml::num($mz, 'KcStrav'), 2) != 0.0) {
            // Stravenkový paušál je mimo hrubou mzdu; počet směn s nárokem nese `DnyStrav`.
            $components[] = ['number' => self::MEAL_ALLOWANCE, 'amount' => PohodaXml::num($mz, 'KcStrav'), 'hours' => 0.0, 'rate' => 0.0, 'units' => PohodaXml::num($mz, 'DnyStrav')];
        }

        // Náhrada za svátek z průměru: jen ta je ve mzdě vidět, svátek odpracovaný nebo
        // v době nemoci náhradu nemá (`DnySvat` je pak menší než `DnyStSv`).
        if (round(PohodaXml::num($mz, 'KcSvatN'), 2) != 0.0 || PohodaXml::num($mz, 'DnySvat') > 0) {
            $absences[] = ['number' => self::HOLIDAY, 'id' => 'svatek-' . PohodaXml::text($mz, 'ID'), 'from' => null, 'to' => null,
                'hours' => round(PohodaXml::num($mz, 'DnySvat') * $daily, 3), 'days' => PohodaXml::num($mz, 'DnySvat'), 'calendar_days' => 0.0, 'amount' => PohodaXml::num($mz, 'KcSvatN')];
            $converted += PohodaXml::num($mz, 'KcSvatN');
        }

        $vacation = [];
        $sickHours = 0.0;
        foreach ($periods as $period) {
            $kind = trim(PohodaXml::text($period, 'RelDrDov'));
            $absence = self::ABSENCE_KINDS[$kind] ?? null;
            $hours = round((float) $period['_days'] * $daily, 3);
            if ($absence === null) {
                $skip('absence', 'RelDrDov ' . $kind, 'doba na kartě zaměstnance', 0.0, $hours);
                continue;
            }
            $row = ['number' => $absence, 'id' => (string) $period['_id'], 'from' => (string) $period['_from'], 'to' => (string) $period['_to'],
                'hours' => $hours, 'days' => (float) $period['_days'], 'calendar_days' => (float) $period['_calendar_days'],
                'amount' => $absence === self::SICKNESS ? (float) ($compensations[PohodaXml::text($period, 'ID')] ?? 0.0) : 0.0];
            if ($absence === self::VACATION) {
                $vacation[] = $row;
                continue;
            }
            $sickHours += $hours;
            $absences[] = $row;
        }
        // Dovolená: hodiny z karty, náhrada `KcDov` rozdělená podle hodin. Hodiny mzdy, které
        // karta nepokrývá, jdou jako dovolená bez dat (souhrn měsíce je s daty nevyžaduje).
        $vacationHours = array_sum(array_column($vacation, 'hours'));
        $rest = round(PohodaXml::num($mz, 'HodDov') - $vacationHours, 3);
        if ($rest > 0.0 || ($vacation === [] && round(PohodaXml::num($mz, 'KcDov'), 2) != 0.0)) {
            $vacation[] = ['number' => self::VACATION, 'id' => 'dovolena-' . PohodaXml::text($mz, 'ID'), 'from' => null, 'to' => null,
                'hours' => max(0.0, $rest), 'days' => round(max(0.0, $rest) / $daily, 2), 'calendar_days' => 0.0, 'amount' => 0.0];
            $vacationHours += max(0.0, $rest);
        }
        $left = PohodaXml::num($mz, 'KcDov');
        foreach ($vacation as $index => $row) {
            $share = $index === array_key_last($vacation) || $vacationHours <= 0.0
                ? $left
                : round(PohodaXml::num($mz, 'KcDov') * $row['hours'] / $vacationHours, 2);
            $vacation[$index]['amount'] = $share;
            $left = round($left - $share, 2);
        }
        array_push($absences, ...$vacation);
        $converted += PohodaXml::num($mz, 'KcDov');
        // Hodiny nemoci, ke kterým karta nemá dobu: bez dat je evidence nezapíše.
        $skip('absence', 'HodNem', 'pracovní neschopnost bez doby na kartě', 0.0, max(0.0, round(PohodaXml::num($mz, 'HodNem') - $sickHours, 3)));

        foreach (array_chunk(self::UNCONVERTED_WAGE_COLUMNS, 2) as [$amountColumn, $hoursColumn]) {
            $amount = PohodaXml::num($mz, $amountColumn);
            $unconvertedGross += $amount;
            $skip('component', $amountColumn, '', $amount, $hoursColumn === '' ? 0.0 : PohodaXml::num($mz, $hoursColumn));
        }
        foreach (array_chunk(self::UNCONVERTED_OTHER_COLUMNS, 2) as [$amountColumn, $hoursColumn]) {
            $skip('component', $amountColumn, '', PohodaXml::num($mz, $amountColumn), $hoursColumn === '' ? 0.0 : PohodaXml::num($mz, $hoursColumn));
        }
        foreach (self::UNCONVERTED_ABSENCE_COLUMNS as $hoursColumn) {
            $skip('absence', $hoursColumn, '', 0.0, PohodaXml::num($mz, $hoursColumn));
        }

        return [
            'components' => $components,
            'absences' => $absences,
            'mz' => self::columns($mz, $person),
            'unconverted' => $unconverted,
            'gross' => ['source' => PohodaXml::num($mz, 'KcHrubaM'), 'converted' => round($converted, 2), 'unconverted' => round($unconvertedGross, 2)],
        ];
    }

    /**
     * Sloupce mzdy v tvaru PAMICA: vazba na vztah, účast na pojištění, fond, výplata,
     * srážková daň a údaje vztahu, které PAMICA nese i ve mzdě.
     *
     * @param array<string,mixed> $mz
     * @param ?array<string,mixed> $person
     * @return array<string,string> sloupec => nová hodnota
     */
    public static function columns(array $mz, ?array $person): array
    {
        $out = [
            'RefPomer' => PohodaXml::text($mz, 'RefZAM'),
            // Účast na nemocenském pojištění: příznak zaměstnance bez výjimky „bez sociálního".
            // Ověřeno na mzdách: s `JeSocZAM` 1 je pojistné sražené, s `BezSoc` 1 nulové.
            'JeSocPP' => trim(PohodaXml::text($mz, 'JeSocZAM')) === '1' && trim(PohodaXml::text($mz, 'BezSoc')) !== '1' ? '1' : '0',
            // Fond měsíce včetně svátků (PAMICA `DnyFond2`) a plného úvazku.
            'DnyFond2' => self::number(PohodaXml::num($mz, 'DnyPrac') + PohodaXml::num($mz, 'DnyStSv')),
            'HodFond' => self::number(PohodaXml::num($mz, 'StanovenyFond')),
            // POHODA svátek do odpracovaných hodin nepočítá, platí ho náhradou ({@see self::HOLIDAY}).
            'DnyStSv' => '0',
            'KcVyplat' => self::number(PohodaXml::num($mz, 'KcVyuct')),
        ];
        // Zvláštní sazba daně (`ZvlSazD`): daň vybraná srážkou, ne záloha. Ověřeno na dohodě bez
        // prohlášení: daň zaokrouhlená dolů na celé koruny, záloha před slevami i po nich stejná.
        if (trim(PohodaXml::text($mz, 'ZvlSazD')) === '1') {
            $out += [
                'KcSraDan' => self::number(PohodaXml::num($mz, 'KcZalDan')),
                'KcSraDanZak' => self::number(PohodaXml::num($mz, 'KcZdaM')),
                'KcZalDan' => '0',
                'KcZdaM' => '0',
                'KcDanPrS' => '0',
                'KcDanZal' => '0',
            ];
        }
        foreach (self::RELATION_DATE_COLUMNS as $column) {
            $value = $person === null ? '' : PohodaXml::text($person, $column);
            if ($value !== '' && PohodaXml::text($mz, $column) === '') {
                $out[$column] = $value;
            }
        }

        return $out;
    }

    /**
     * Pracovní dny (pondělí až pátek včetně svátků) doby od a do: tak počítá POHODA `Dnu`
     * u doby rozdělené do víc měsíců.
     */
    public static function weekdays(string $from, string $to): int
    {
        $days = 0;
        $day = new \DateTimeImmutable($from);
        $end = new \DateTimeImmutable($to);
        while ($day <= $end) {
            if ((int) $day->format('N') <= 5) {
                $days++;
            }
            $day = $day->modify('+1 day');
        }
        return $days;
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
    }
}
