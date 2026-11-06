<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Fixtures\StereoNx;

use MyInvoice\Service\Accounting\FiscalCalendar;

/** Výhradně syntetický nehmotný majetek s přesným dvouletým odpisovým plánem. */
final class SyntheticStereoNxIntangibleTables
{
    /** @return array<string,list<array<string,mixed>>> */
    public static function tables(?FiscalCalendar $calendar = null): array
    {
        $calendar ??= FiscalCalendar::calendar();
        $inUse = $calendar->isCalendar() ? '2024-04-30' : '2024-11-30';
        $asset = ['InvCislo' => 'SYN-INT-EXACT', 'Nazev' => 'Syntetická licence', 'Typ' => 'N',
            'CenaPorizovaci' => 24_000.07, 'DatumPorizeni' => substr($inUse, 0, 8) . '10',
            'DatumZarazeni' => $inUse, 'DatumVyrazeni' => null,
            'UcetMDZarazeni' => '013', 'UcetDalZarazeni' => '041', 'UcetDalOdpisu' => '073',
            'ZpusobDanOdepisovani' => 'M', 'ZpusobUcOdepisovani' => 'R', 'OdpisovaSkupina' => '1',
            'ZvyseniSazProc' => 0, 'DanoveOdepsano' => 20_000.0, 'UcetneOdepsano' => 20_000.0,
            'Poznamka' => 'Syntetický plán', 'VyrobniCislo' => ''];
        $monthly = [];
        $annual = [];
        $first = (new \DateTimeImmutable($inUse))->modify('first day of next month');
        for ($index = 0; $index < 24; $index++) {
            $date = $first->modify('+' . $index . ' months')->modify('last day of this month')->format('Y-m-d');
            $amount = $index === 23 ? 1_000.07 : 1_000.0;
            $applied = $index < 20;
            $monthly[] = ['InvCislo' => $asset['InvCislo'], 'Datum' => $date, 'Castka' => $amount,
                'DatumUplatneni' => $applied ? $date : null];
            $year = $calendar->fiscalYearOfDate($date);
            $annual[$year]['amount'] = round(($annual[$year]['amount'] ?? 0.0) + $amount, 2);
            $annual[$year]['applied'] = ($annual[$year]['applied'] ?? true) && $applied;
        }
        $tax = [];
        foreach ($annual as $year => $row) {
            $date = $calendar->periodEnd($year);
            $tax[] = ['InvCislo' => $asset['InvCislo'], 'Datum' => $date, 'Castka' => $row['amount'],
                'DatumUplatneni' => $row['applied'] ? $date : null];
        }
        return ['JMajetek' => [$asset], 'JDrobMaj' => [], 'JDanOdpisy' => $tax,
            'JUcOdpisy' => $monthly, 'JTechZhod' => []];
    }
}
