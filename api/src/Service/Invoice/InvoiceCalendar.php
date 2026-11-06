<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/**
 * Typy kalendářů podle ZDPH a pravidla, která z nich plynou.
 *
 * Splátkový kalendář (§ 31) patří k nájemní smlouvě: každá splátka je dílčí plnění
 * s vlastním dnem uskutečnění podle smlouvy (§ 21 odst. 7).
 * Platební kalendář (§ 31a) je rozpis úplat placených před plněním: DUZP na něm není
 * (§ 31a odst. 2) a daň vzniká až přijetím úplaty (§ 20a odst. 2).
 *
 * Vystavený kalendář je jeden doklad pro odběratele, ale nenese pohledávku, daň ani
 * zápis v deníku. Ty nesou interní doklady splátek (`invoices.calendar_id`), viz
 * {@see CalendarInstalmentService}. `payment_calendar` je původní společný typ: už
 * vystavené doklady zůstávají, jak byly, nový koncept tímto typem vystavit nejde.
 */
final class InvoiceCalendar
{
    public const RENT = 'rent_calendar';
    public const ADVANCE = 'advance_calendar';
    public const LEGACY = 'payment_calendar';

    /** @var list<string> */
    public const TYPES = [self::RENT, self::ADVANCE];

    /** Hodnoty pro `invoice_type NOT IN (...)` tam, kde se součty berou ze splátek. */
    public const SQL_TYPES = "'rent_calendar','advance_calendar'";

    public static function isCalendar(?string $invoiceType): bool
    {
        return in_array($invoiceType, self::TYPES, true);
    }

    /** Kalendář s rozpisem plateb, včetně původního společného typu. */
    public static function hasSchedule(?string $invoiceType): bool
    {
        return self::isCalendar($invoiceType) || $invoiceType === self::LEGACY;
    }

    /** Typ interního dokladu splátky: nájem je plnění, záloha čeká na přijetí úplaty. */
    public static function instalmentType(string $calendarType): string
    {
        return $calendarType === self::RENT ? 'invoice' : 'proforma';
    }

    /**
     * Rozpočte položky kalendáře do splátek tak, aby každá splátka seděla na svůj řádek
     * rozpisu a každá položka na svůj součet. Haléřové zbytky drží poslední položka
     * splátky a poslední splátka položky, takže u jediné sazby vyjde přesně rozpis.
     *
     * @param list<array{total_without_vat:float|string, total_vat:float|string}> $items
     * @param list<array{base_amount:float|string, vat_amount:float|string}> $rows
     * @return list<list<array{base:float, vat:float}>> [splátka][položka]
     */
    public static function allocate(array $items, array $rows): array
    {
        $itemBase = array_map(static fn (array $i): int => self::cents($i['total_without_vat']), $items);
        $itemVat = array_map(static fn (array $i): int => self::cents($i['total_vat']), $items);
        $rowBase = array_map(static fn (array $r): int => self::cents($r['base_amount']), $rows);
        $rowVat = array_map(static fn (array $r): int => self::cents($r['vat_amount']), $rows);

        $bases = self::allocateColumn($itemBase, $rowBase);
        $vats = self::allocateColumn($itemVat, $rowVat);

        $out = [];
        foreach ($rows as $r => $_) {
            foreach ($items as $i => $__) {
                $out[$r][$i] = ['base' => $bases[$r][$i] / 100, 'vat' => $vats[$r][$i] / 100];
            }
        }

        return $out;
    }

    /**
     * @param list<int> $itemTotals
     * @param list<int> $rowTotals
     * @return list<list<int>>
     */
    private static function allocateColumn(array $itemTotals, array $rowTotals): array
    {
        $grand = array_sum($rowTotals);
        $lastRow = count($rowTotals) - 1;
        $lastItem = count($itemTotals) - 1;
        $used = array_fill(0, count($itemTotals), 0);
        $out = [];
        foreach ($rowTotals as $r => $rowTotal) {
            $rowUsed = 0;
            foreach ($itemTotals as $i => $itemTotal) {
                if ($r === $lastRow) {
                    $cell = $itemTotal - $used[$i];
                } elseif ($i === $lastItem) {
                    $cell = $rowTotal - $rowUsed;
                } else {
                    $cell = $grand === 0 ? 0 : (int) round($itemTotal * $rowTotal / $grand);
                }
                $out[$r][$i] = $cell;
                $used[$i] += $cell;
                $rowUsed += $cell;
            }
        }

        return $out;
    }

    private static function cents(float|string $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
