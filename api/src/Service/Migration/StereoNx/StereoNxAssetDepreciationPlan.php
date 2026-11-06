<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Migration\Shared\MigratedDepreciation;

/** Ověřený měsíční plán zdroje; označení M samo o sobě neurčuje cílovou daňovou metodu. */
final class StereoNxAssetDepreciationPlan
{
    /** @param array<string,mixed> $card
     * @param list<array<string,mixed>> $taxRows
     * @param list<array<string,mixed>> $accountingRows
     * @return array{future:array<string,mixed>,source:array<string,mixed>,opening_months:int,opening_years:int,life_months:int} */
    public static function build(array $card, array $taxRows, array $accountingRows, FiscalCalendar $calendar, bool $hasImprovements): array
    {
        $acquired = self::date($card['DatumPorizeni'] ?? null);
        $inUse = self::date($card['DatumZarazeni'] ?? null);
        if (($card['Typ'] ?? null) !== 'N' || ($card['ZpusobDanOdepisovani'] ?? null) !== 'M'
            || ($card['ZpusobUcOdepisovani'] ?? null) !== 'R' || $acquired < '2021-01-01'
            || $inUse < $acquired || $hasImprovements || !in_array($card['ZvyseniSazProc'] ?? null, [0, 0.0], true)) {
            throw new StereoNxException('asset_tax_method_unsupported',
                'Zdrojový způsob M lze převzít pouze u nehmotného majetku pořízeného od roku 2021 s doloženým účetním plánem R, bez technického zhodnocení a zvýšení odpisu.');
        }
        $number = trim((string) ($card['InvCislo'] ?? ''));
        $price = self::cents($card['CenaPorizovaci'] ?? null);
        $taxOpening = self::cents($card['DanoveOdepsano'] ?? null);
        $accOpening = self::cents($card['UcetneOdepsano'] ?? null);
        if ($number === '' || $price <= 0 || $taxOpening !== $accOpening || $accOpening > $price
            || $taxRows === [] || $accountingRows === []) self::reject('Chybí úplný plán nebo se liší počáteční daňové a účetní oprávky.');

        $monthly = [];
        foreach ($accountingRows as $row) {
            if (trim((string) ($row['InvCislo'] ?? '')) !== $number) self::reject('Řádek odpisu nemá jednoznačnou vazbu na kartu.');
            $date = self::date($row['Datum'] ?? null);
            $month = substr($date, 0, 7);
            if ($date !== (new \DateTimeImmutable($date))->modify('last day of this month')->format('Y-m-d')
                || isset($monthly[$month])) self::reject('Účetní plán obsahuje duplicitní měsíc nebo datum mimo konec měsíce.');
            $monthly[$month] = ['month' => $month, 'amount_cents' => self::cents($row['Castka'] ?? null),
                'applied_on' => self::appliedOn($row)];
        }
        ksort($monthly);
        $annual = [];
        $annualApplied = [];
        $futureMonths = [];
        $openingMonths = 0;
        $appliedSum = $total = 0;
        $unappliedSeen = false;
        foreach (array_values($monthly) as $index => $row) {
            if (MigratedDepreciation::monthsBetween($inUse, $row['month']) !== $index + 1) {
                self::reject('Účetní plán musí navazovat po jednotlivých měsících od měsíce následujícího po zařazení.');
            }
            $year = $calendar->fiscalYearOfDate($row['month'] . '-01');
            $annual[$year] = ($annual[$year] ?? 0) + $row['amount_cents'];
            $total += $row['amount_cents'];
            if ($total > $price) self::reject('Součet účetního plánu převyšuje vstupní cenu.');
            $applied = $row['applied_on'] !== null;
            if ($applied && $unappliedSeen) self::reject('Uplatněné účetní odpisy netvoří souvislý začátek plánu.');
            $annualApplied[$year][] = $applied;
            if ($applied) {
                $openingMonths++;
                $appliedSum += $row['amount_cents'];
            } else {
                $unappliedSeen = true;
                $futureMonths[] = ['month' => $row['month'], 'amount_cents' => $row['amount_cents']];
            }
        }
        if ($total !== $price || $appliedSum !== $accOpening) self::reject('Součet účetního plánu nebo uplatněné odpisy nesouhlasí s kartou.');

        $tax = [];
        foreach ($taxRows as $row) {
            if (trim((string) ($row['InvCislo'] ?? '')) !== $number) self::reject('Řádek daňového odpisu nemá jednoznačnou vazbu na kartu.');
            $date = self::date($row['Datum'] ?? null);
            $year = $calendar->fiscalYearOfDate($date);
            if ($date !== $calendar->periodEnd($year) || isset($tax[$year])) {
                self::reject('Daňový plán obsahuje duplicitní období nebo datum mimo konec období firmy.');
            }
            $tax[$year] = ['fiscal_year' => $year, 'amount_cents' => self::cents($row['Castka'] ?? null),
                'applied_on' => self::appliedOn($row)];
        }
        ksort($tax);
        if (array_keys($tax) !== array_keys($annual)) self::reject('Daňový a účetní plán nepokrývají stejná účetní období.');
        $futureTax = [];
        $openingYears = 0;
        $taxAppliedSum = 0;
        $unappliedSeen = false;
        foreach ($tax as $year => $row) {
            if ($row['amount_cents'] !== $annual[$year]) self::reject('Roční daňový odpis neodpovídá součtu zdrojových měsíčních účetních odpisů.');
            $applied = $row['applied_on'] !== null;
            if (($applied && $unappliedSeen) || in_array(!$applied, $annualApplied[$year], true)) {
                self::reject('Uplatněné daňové období se neshoduje s uplatněnými měsíci účetního plánu.');
            }
            if ($applied) {
                $openingYears++;
                $taxAppliedSum += $row['amount_cents'];
            } else {
                $unappliedSeen = true;
                $futureTax[] = ['fiscal_year' => $year, 'amount_cents' => $row['amount_cents']];
            }
        }
        if ($taxAppliedSum !== $taxOpening) self::reject('Uplatněné daňové odpisy nesouhlasí s počátečními oprávkami.');
        $metadata = ['version' => 1, 'source_program' => 'Stereo NX',
            'source_tax_method' => 'M', 'source_accounting_method' => 'R'];
        return ['future' => $metadata + ['accounting' => $futureMonths, 'tax' => $futureTax],
            'source' => $metadata + ['accounting' => array_values($monthly), 'tax' => array_values($tax)],
            'opening_months' => $openingMonths, 'opening_years' => $openingYears, 'life_months' => count($monthly)];
    }

    private static function cents(mixed $value): int
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0 || $value > 1.0e12) {
            self::reject('Odpisový plán obsahuje chybějící nebo nepodporovanou částku.');
        }
        $cents = (int) round($value * 100, 0, PHP_ROUND_HALF_UP);
        if (abs($value * 100 - $cents) > 0.00001) self::reject('Částka odpisu není vyjádřena v celých haléřích.');
        return $cents;
    }

    private static function date(mixed $value): string
    {
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) self::reject('Odpisový plán nemá platné datum.');
        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function appliedOn(array $row): ?string
    {
        if (!array_key_exists('DatumUplatneni', $row)) self::reject('Chybí příznak uplatnění odpisu.');
        return $row['DatumUplatneni'] === null || $row['DatumUplatneni'] === '' ? null : self::date($row['DatumUplatneni']);
    }

    private static function reject(string $message): never
    {
        throw new StereoNxException('asset_exact_depreciation_plan_unsupported', $message);
    }
}
