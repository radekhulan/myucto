<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

use MyInvoice\Service\Payroll\IncomeTax\EvidenceInterval;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidence;

/**
 * Prohlášení k dani a daňové rezidentství ZA OBDOBÍ, ne k jednomu dni.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Proč ne k 31. 12.
 * ─────────────────────────────────────────────────────────────────────────────
 * Číst účinnou evidenci k poslednímu dni roku vypadá logicky jen u zaměstnance,
 * který u plátce trval celý rok. Kdo v červnu odešel, nemá k 31. 12. účinné ani
 * prohlášení, ani rezidenci — a zúčtování mu padlo na `declaration_unverified`
 * a `non_resident`, přestože obojí po celou dobu trvání vztahu doložené bylo.
 * § 38ch odst. 4 přitom mluví o úhrnu mezd „za uplynulé zdaňovací období",
 * tedy o měsících, které do zúčtování vstupují, ne o jednom dni.
 *
 * Vyhodnocuje se proto po měsících — a v každém měsíci týmž testem, jaký
 * používá měsíční větev ({@see EvidenceInterval::includesMonthStart}): rozhodný
 * je PRVNÍ DEN měsíce. Kdyby se tady testoval jiný den, roční a měsíční větev by
 * si o témž měsíci mohly myslet každá něco jiného.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Jak se z měsíců dělá jeden stav
 * ─────────────────────────────────────────────────────────────────────────────
 *  - Měsíc BEZ ŘÁDKU se přeskakuje. Prohlášení podepsané od března je legitimní
 *    (do února ho poplatník mohl mít u jiného plátce) a leden bez řádku není
 *    „nedoložený leden", ale „v lednu se u tohoto plátce k dani nepřihlíželo".
 *  - Explicitní `unverified` v kterémkoli měsíci rozhoduje — nedoložený stav
 *    nepřebije doložený jinde. Fail-closed zůstává fail-closed.
 *  - Až potom platí `signed` / `czech-resident` z kteréhokoli měsíce.
 *  - Žádný řádek v celém rozsahu = nevíme nic → `Unverified`, tedy překážka.
 *    Prázdná evidence se nikdy nečte jako „nepodepsal".
 *
 * U rezidence má přednost explicitní `non-resident`: změna rezidentství během
 * roku je důvod k přiznání (§ 38g odst. 2), ne k zúčtování u plátce.
 */
final class AnnualSettlementEvidenceMonths
{
    /**
     * @param list<array<string,mixed>> $declarationRows řádky payroll_person_tax_declarations
     * @param list<array<string,mixed>> $residenceRows řádky payroll_person_tax_residences
     * @param list<int> $months měsíce roku, které do zúčtování vstupují
     * @return array{declaration:TaxDeclarationStatus,residence:TaxResidence}
     */
    public function evaluate(
        array $declarationRows,
        array $residenceRows,
        int $taxYear,
        array $months,
    ): array {
        $months = self::normalizeMonths($months);

        return [
            'declaration' => self::declaration(
                self::statusesInMonths($declarationRows, 'status', $taxYear, $months),
            ),
            'residence' => self::residence(
                self::statusesInMonths($residenceRows, 'residence', $taxYear, $months),
            ),
        ];
    }

    /**
     * Měsíce, ve kterých u plátce trval pracovní vztah, plus pojistka pro
     * prázdný vstup.
     *
     * Prázdný seznam znamená „o trvání vztahu nic nevíme" — typicky legacy
     * evidence bez záznamu vztahu. Zúžit rozsah na nic by znamenalo zablokovat
     * i zaměstnance, kterému nic nechybí, takže se v takovém případě posuzuje
     * celý rok. Fail-closed to neruší: bez jediného řádku evidence vyjde
     * `Unverified` a zúčtování se stejně zastaví.
     *
     * @param list<int> $months
     * @return list<int>
     */
    private static function normalizeMonths(array $months): array
    {
        $valid = [];
        foreach ($months as $month) {
            if ($month >= 1 && $month <= AnnualTaxRates::MONTHS_IN_YEAR) {
                $valid[$month] = true;
            }
        }
        if ($valid === []) {
            return range(1, AnnualTaxRates::MONTHS_IN_YEAR);
        }
        ksort($valid);

        return array_map('intval', array_keys($valid));
    }

    /**
     * Hodnoty účinné na počátku některého z posuzovaných měsíců.
     *
     * @param list<array<string,mixed>> $rows
     * @param list<int> $months
     * @return list<string>
     */
    private static function statusesInMonths(
        array $rows,
        string $column,
        int $taxYear,
        array $months,
    ): array {
        $found = [];
        foreach ($rows as $row) {
            $from = (string) ($row['effective_from'] ?? '');
            $to = $row['effective_to'] ?? null;
            $to = is_string($to) && $to !== '' ? $to : null;
            $value = (string) ($row[$column] ?? '');
            if ($from === '' || $value === '') {
                continue;
            }
            foreach ($months as $month) {
                if (self::covers($from, $to, $taxYear, $month)) {
                    $found[$value] = true;
                    break;
                }
            }
        }

        return array_map('strval', array_keys($found));
    }

    private static function covers(string $from, ?string $to, int $taxYear, int $month): bool
    {
        try {
            return EvidenceInterval::includesMonthStart(
                $from,
                $to,
                sprintf('%04d-%02d-01', $taxYear, $month),
            );
        } catch (\InvalidArgumentException) {
            // Rozbité datum v evidenci není „nekryje" — je to neznámý stav.
            // Řádek se proto nepočítá jako pokrytí a chybějící pokrytí končí
            // na `Unverified`.
            return false;
        }
    }

    /** @param list<string> $statuses */
    private static function declaration(array $statuses): TaxDeclarationStatus
    {
        $known = array_values(array_filter(array_map(
            static fn (string $value): ?TaxDeclarationStatus
                => TaxDeclarationStatus::tryFrom($value),
            $statuses,
        )));
        if ($known === []) {
            return TaxDeclarationStatus::Unverified;
        }
        if (in_array(TaxDeclarationStatus::Unverified, $known, true)) {
            return TaxDeclarationStatus::Unverified;
        }
        if (in_array(TaxDeclarationStatus::Signed, $known, true)) {
            return TaxDeclarationStatus::Signed;
        }

        return TaxDeclarationStatus::NotSigned;
    }

    /** @param list<string> $statuses */
    private static function residence(array $statuses): TaxResidence
    {
        $known = array_values(array_filter(array_map(
            static fn (string $value): ?TaxResidence => TaxResidence::tryFrom($value),
            $statuses,
        )));
        if ($known === []) {
            return TaxResidence::Unverified;
        }
        foreach ($known as $residence) {
            if ($residence !== TaxResidence::CzechResident) {
                return $residence;
            }
        }

        return TaxResidence::CzechResident;
    }
}
