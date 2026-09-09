<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

use MyInvoice\Service\Accounting\FiscalCalendar;

/**
 * Tvar zdaňovacího období podle § 21a ZDP a odvození atributu `typ_zo` (DPPDP9).
 *
 * SSOT — dřív žila stejná úvaha jako `private` větvení uvnitř
 * {@see TaxReturnService::periodMeta()} a končila tichým fallbackem na „A"
 * (kalendářní rok) i pro období, které kalendářnímu roku vůbec neodpovídá.
 * Přiznání tak o zdaňovacím období tvrdilo nepravdu a nikdo se to nedozvěděl.
 * Klasifikace je teď zavolatelná zvenčí, takže ji vedle mety pro builder používá
 * i {@see UnsupportedCaseDetector} k tomu, aby atypické období zastavilo finalizaci.
 *
 * Dokumentace atributu `typ_zo` v `api/xsd/dppdp9_epo2.xsd` nese kritickou kontrolu
 * „pokud je hodnota A nebo B, nesmí být ZO delší než 1 rok".
 */
final class TaxPeriodShape
{
    /** Kalendářní rok (i zkrácený první rok končící 31. 12.) — § 21a písm. a). */
    public const CALENDAR = 'calendar';
    /** Hospodářský rok — § 21a písm. b). */
    public const FISCAL = 'fiscal';
    /** Období delší než dvanáct měsíců — § 21a písm. d). */
    public const LONG = 'long';
    /** Zkrácené období, které neodpovídá ani kalendářnímu, ani hospodářskému roku. */
    public const ATYPICAL = 'atypical';
    /** Účetní období nebylo předáno — přiznání spadne na kalendářní rok. */
    public const MISSING = 'missing';

    /** Tvar období → hodnota atributu `typ_zo`. */
    private const TYP_ZO = [
        self::CALENDAR => 'A',
        self::FISCAL => 'B',
        self::LONG => 'D',
        // Atypické zkrácené období žádné písmeno § 21a nevystihuje. Posílá se
        // nejbezpečnější „A", ale NIKDY tiše — {@see UnsupportedCaseDetector}
        // z téhož tvaru dělá blokující nález.
        self::ATYPICAL => 'A',
        self::MISSING => 'A',
    ];

    public static function classify(?string $startsOn, ?string $endsOn): string
    {
        $startsOn = substr(trim((string) $startsOn), 0, 10);
        $endsOn = substr(trim((string) $endsOn), 0, 10);
        if ($startsOn === '' || $endsOn === '') {
            return self::MISSING;
        }
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $startsOn);
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $endsOn);
        if ($start === false || $end === false || $end < $start) {
            return self::MISSING;
        }
        $days = (int) $start->diff($end)->format('%a');
        if ($days > 380) {
            return self::LONG;
        }
        if (substr($endsOn, 5) === '12-31') {
            return self::CALENDAR;
        }
        if (FiscalCalendar::isFiscalYearShape($startsOn, $endsOn)) {
            return self::FISCAL;
        }

        return self::ATYPICAL;
    }

    public static function typZo(string $shape): string
    {
        return self::TYP_ZO[$shape] ?? 'A';
    }
}
