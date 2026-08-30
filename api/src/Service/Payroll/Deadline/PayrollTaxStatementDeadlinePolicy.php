<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Deadline;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\TaxStatement\TaxStatementService;
use MyInvoice\Service\Report\CzechWorkingDays;

/**
 * Zákonné lhůty ročních vyúčtování daně ze závislé činnosti a srážkové daně.
 *
 * ## Proč to vzniklo
 *
 * Obě vyúčtování ({@see TaxStatementService}) uměla aplikace sestavit, ověřit
 * proti XSD i odeslat na EPO — ale lhůtu znal jen komentář v kódu a jedna věta
 * v panelu. Termín, který nikde nespadne do hlídače, není hlídaný: účetní se
 * o něm dozví, až když jí přijde výzva správce daně.
 *
 * ## Jak se lhůta počítá
 *
 * **Vyúčtování zálohové daně (DPZVD6, tiskopis 25 5459).** § 38j odst. 5 ZDP:
 * „Plátce daně je povinen podat správci daně vyúčtování daně z příjmů ze
 * závislé činnosti do dvou měsíců po uplynutí kalendářního roku; pokud plátce
 * daně podá toto vyúčtování elektronicky, je lhůta pro podání do 20. března."
 * Lhůtu podle § 38j odst. 7 ZDP nelze prodloužit.
 *
 * **Vyúčtování daně vybírané srážkou (DPSVD2, tiskopis 25 5466).** Zvláštní
 * lhůtu ZDP nemá, platí obecná § 137 odst. 2 daňového řádu: „Vyúčtování se
 * podává do 3 měsíců po uplynutí kalendářního roku." Ani tu podle § 137
 * odst. 3 DŘ prodloužit nelze — proto se elektronická varianta neuplatní.
 *
 * **Počátek a konec běhu.** § 33 odst. 1 DŘ: lhůta určená podle měsíců počíná
 * běžet dnem následujícím po rozhodné události (konec kalendářního roku), tedy
 * 1. ledna následujícího roku, a končí dnem téhož číselného označení. Dva
 * měsíce od 1. ledna proto končí 1. BŘEZNA, tři měsíce 1. DUBNA — ne posledním
 * dnem února, jak by svádělo „do dvou měsíců" číst.
 *
 * **Víkend a svátek.** § 33 odst. 4 DŘ: připadne-li poslední den lhůty na
 * sobotu, neděli nebo svátek, je posledním dnem nejblíže následující pracovní
 * den. Posun dělá {@see CzechWorkingDays}, tedy tatáž tabulka svátků jako
 * u DPH — dva nezávislé kalendáře by se dřív nebo později rozešly.
 *
 * ## Proč hlídač počítá s elektronickou lhůtou
 *
 * Aplikace jinou než elektronickou cestu nenabízí: {@see TaxStatementService}
 * staví XML pro EPO a nic jiného z ní nevyleze. Hlídat papírový 1. březen by
 * tedy znamenalo tři týdny hlásit prodlení tomu, kdo žádné nemá. Papírová
 * lhůta ve výsledku ZŮSTÁVÁ (`statutoryDueOn`), aby si ji mohl přečíst ten,
 * kdo tiskopis přece jen podá na podatelně.
 */
final class PayrollTaxStatementDeadlinePolicy
{
    /** Rok, od kterého lhůty v tomhle tvaru platí (daňový řád je účinný od 2011). */
    public const SUPPORTED_FROM_YEAR = 2011;

    /** Horní mez je tatáž jako u tiskopisů, ať se meze nerozejdou. */
    public const SUPPORTED_TO_YEAR = 2199;

    private const RULESET_ID = 'cz-payroll-tax-statement-deadline-2011-01.v1';

    private const SOURCES = [
        'dependent_activity' => '586/1992 Sb. § 38j odst. 5 a 7',
        'withholding_tax' => '280/2009 Sb. § 137 odst. 2 a 3',
        'counting' => '280/2009 Sb. § 33 odst. 1 a 4',
        'holidays' => '245/2000 Sb.',
    ];

    /**
     * Lhůta jednoho tiskopisu za jedno zdaňovací období.
     *
     * @param string $formCode {@see TaxStatementService::FORMS}
     * @param int $year zdaňovací období, ZA které se vyúčtování podává
     */
    public function forYear(
        string $formCode,
        int $year,
    ): PayrollTaxStatementDeadlineWindow {
        if (!in_array($formCode, TaxStatementService::FORMS, true)) {
            throw new \InvalidArgumentException(
                'Vyúčtování musí být dpzvd6 (závislá činnost) nebo dpsvd2 (srážková daň).',
            );
        }
        if ($year < self::SUPPORTED_FROM_YEAR || $year > self::SUPPORTED_TO_YEAR) {
            throw new \InvalidArgumentException(
                'Zdaňovací období vyúčtování musí být v rozsahu '
                . self::SUPPORTED_FROM_YEAR . ' až ' . self::SUPPORTED_TO_YEAR . '.',
            );
        }

        // Den následující po konci zdaňovacího období — tím lhůta začíná běžet
        // (§ 33 odst. 1 DŘ) a dřív se vyúčtování za celý rok podat nedá.
        $start = new \DateTimeImmutable(
            sprintf('%04d-01-01', $year + 1),
            new \DateTimeZone('Europe/Prague'),
        );

        $isDependentActivity =
            $formCode === TaxStatementService::FORM_DEPENDENT_ACTIVITY;
        $months = $isDependentActivity ? 2 : 3;

        $statutoryDueOn = CzechWorkingDays::shiftToWorkingDay(
            $start->modify('+' . $months . ' months'),
        )->format('Y-m-d');

        // 20. březen je v zákoně napsaný jako DATUM, ne jako počet měsíců —
        // proto se skládá z data, ne přičítáním dnů k začátku běhu lhůty.
        $electronicDueOn = $isDependentActivity
            ? CzechWorkingDays::deadline($year + 1, 3, 20)
            : null;

        return new PayrollTaxStatementDeadlineWindow(
            $formCode,
            $year,
            $start->format('Y-m-d'),
            $electronicDueOn ?? $statutoryDueOn,
            $statutoryDueOn,
            $electronicDueOn,
            false,
            $isDependentActivity
                ? self::SOURCES['dependent_activity']
                : self::SOURCES['withholding_tax'],
            'czech_working_days',
            self::RULESET_ID,
            $this->rulesetHash(),
        );
    }

    private function rulesetHash(): string
    {
        return hash('sha256', CanonicalJson::encode([
            'schema_reference' => 'payroll-tax-statement-deadline-policy.v1',
            'ruleset_id' => self::RULESET_ID,
            'effective_from_year' => self::SUPPORTED_FROM_YEAR,
            'dependent_activity_months' => 2,
            'dependent_activity_electronic_month_day' => '03-20',
            'withholding_tax_months' => 3,
            'counting_starts_day_after_period_end' => true,
            'extendable' => false,
            'sources' => self::SOURCES,
        ]));
    }
}
