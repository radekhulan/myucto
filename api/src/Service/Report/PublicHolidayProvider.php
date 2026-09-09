<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

/**
 * Zdroj pravidel českých svátků pro {@see CzechWorkingDays}.
 *
 * Rozhraní existuje ze dvou důvodů: `CzechWorkingDays` je statický helper volaný
 * z desítek míst bez DI, takže mu zdroj předává až Bootstrap; a testům dovoluje
 * podstrčit číselník bez databáze.
 *
 * Vrácená pravidla NEJSOU data k datu — jsou to předpisy, ze kterých se konkrétní
 * den v roce teprve spočítá (pevné `MM-DD`, nebo posun ode dne Velikonoční
 * neděle). Platnost je datová, takže helper si sám vyhodnotí, jestli pravidlo na
 * dotazovaný rok dopadá.
 */
interface PublicHolidayProvider
{
    /**
     * Všechna pravidla svátků včetně jejich platnosti, v libovolném pořadí.
     *
     * @return list<array{
     *     code:string,
     *     name:string,
     *     rule_type:'fixed'|'easter',
     *     month_day:?string,
     *     easter_offset:?int,
     *     valid_from:string,
     *     valid_to:?string
     * }>
     */
    public function holidayRules(): array;
}
