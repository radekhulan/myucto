<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzAttributeProjection;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlContext;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlOutcome;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlVerdict;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1ControlEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Porušení vykonávacích kontrol katalogu MH 1.4.2.10, které dosud zkoušel jen
 * kladný průchod základního vzorku. Každá kontrola má dvojici: podání, které
 * pravidlo porušuje a musí skončit „Failed", a nejbližší správné podání, které
 * projde. Bez té dvojice by zelená kontrola nic nedokazovala — prošla by
 * i tehdy, kdyby vyhodnocení podmínku vůbec nečetlo.
 *
 * Hodnoty jsou smyšlené; vychází se z `JmhzXmlSample::minimal()` (červenec
 * 2026, základ 1 000 Kč, fond 184 hodin).
 */
final class JmhzScenario1ControlViolationTest extends TestCase
{
    private const EVALUATED_ON = '2026-08-14';

    /** Kontroly 20, 23, 57, 144 a 145: hodiny proti sobě a proti fondu. */
    public function testHoursMustNotExceedTheirCoveringTotals(): void
    {
        $this->assertFails(20, self::withHours('<form:rozpad><form:prescas>184.500</form:prescas></form:rozpad>'));
        $this->assertPasses(20, self::withHours('<form:rozpad><form:prescas>184.000</form:prescas></form:rozpad>'));

        $risky = static fn (string $hours): string => self::withHours(
            "<form:rozpad><form:riziko><form:hodinyOdpracovanePocet>{$hours}</form:hodinyOdpracovanePocet>"
                . '<form:kategorizaceRizika>1</form:kategorizaceRizika></form:riziko></form:rozpad>',
        );
        $this->assertFails(57, $risky('185'));
        $this->assertPasses(57, $risky('184'));
        // Desetinné 10268 se pro kontrolu 57 zaokrouhluje nahoru.
        $this->assertPasses(57, str_replace(
            '<form:pocet>184.000</form:pocet>',
            '<form:pocet>184.250</form:pocet>',
            $risky('185'),
        ));

        $unworked = static fn (string $paid, string $vacation): string => self::withProgress(
            '<form:neodpracovaneHodiny>'
                . '<form:hodinyNeodpracCelkem>16.000</form:hodinyNeodpracCelkem>'
                . "<form:hodinyNeodpracNahrada>{$paid}</form:hodinyNeodpracNahrada>"
                . "<form:hodinyNeodpracDovol>{$vacation}</form:hodinyNeodpracDovol>"
                . '</form:neodpracovaneHodiny>',
        );
        $this->assertFails(23, $unworked('8.000', '16.000'));
        $this->assertPasses(23, $unworked('16.000', '16.000'));

        foreach ([144 => 'prekazkaZamestnanec', 145 => 'prekazkaZamestnavatel'] as $controlId => $element) {
            $obstacle = static fn (string $hours): string => self::withProgress(
                "<form:prekazkyVPraci><form:{$element}>{$hours}</form:{$element}></form:prekazkyVPraci>",
            );
            $this->assertFails($controlId, $obstacle('184.500'));
            $this->assertPasses($controlId, $obstacle('184.000'));
        }
    }

    /** Kontroly 94, 95 a 96: fondy pracovní doby nejsou záporné. */
    public function testWorkingTimeFundsMustNotBeNegative(): void
    {
        foreach ([
            94 => ['<form:stanovenyFond>184.000</form:stanovenyFond>', '<form:stanovenyFond>-1.000</form:stanovenyFond>'],
            95 => ['<form:sjednanyFond>184.000</form:sjednanyFond>', '<form:sjednanyFond>-1.000</form:sjednanyFond>'],
            96 => ['<form:stanovenaTydenniDoba>40.00</form:stanovenaTydenniDoba>', '<form:stanovenaTydenniDoba>-0.50</form:stanovenaTydenniDoba>'],
        ] as $controlId => [$valid, $negative]) {
            $this->assertPasses($controlId, JmhzXmlSample::minimal());
            $this->assertFails($controlId, str_replace($valid, $negative, JmhzXmlSample::minimal()));
            $this->assertPasses($controlId, str_replace($valid, str_replace('40.00', '0.00', str_replace('184.000', '0.000', $valid)), JmhzXmlSample::minimal()));
        }
    }

    /**
     * Kontroly 282, 283, 286, 328 a 329: je-li úhrn nulový, rozpad se
     * nevykazuje. Prázdný rozpad u nulového úhrnu projde.
     */
    public function testBreakdownMustBeEmptyWhenItsTotalIsZero(): void
    {
        $noWork = str_replace('<form:pocet>184.000</form:pocet>', '<form:pocet>0.000</form:pocet>', JmhzXmlSample::minimal());
        $this->assertPasses(282, $noWork);
        $this->assertFails(282, str_replace(
            '<form:pocet>0.000</form:pocet>',
            '<form:pocet>0.000</form:pocet><form:rozpad><form:prescas>0.000</form:prescas></form:rozpad>',
            $noWork,
        ));

        $noIncome = str_replace(
            '<form:zuctovanoCelkem>1000</form:zuctovanoCelkem>',
            '<form:zuctovanoCelkem>0</form:zuctovanoCelkem>',
            JmhzXmlSample::minimal(),
        );
        $this->assertPasses(283, $noIncome);
        $this->assertFails(283, str_replace(
            '<form:zuctovanoCelkem>0</form:zuctovanoCelkem>',
            '<form:zuctovanoCelkem>0</form:zuctovanoCelkem><form:osvobozenoCelkem>0</form:osvobozenoCelkem>',
            $noIncome,
        ));

        $this->assertPasses(286, self::withProgress(
            '<form:neodpracovaneHodiny><form:hodinyNeodpracCelkem>0.000</form:hodinyNeodpracCelkem></form:neodpracovaneHodiny>',
        ));
        $this->assertFails(286, self::withProgress(
            '<form:neodpracovaneHodiny><form:hodinyNeodpracCelkem>0.000</form:hodinyNeodpracCelkem>'
                . '<form:hodinyNeodpracNahrada>8.000</form:hodinyNeodpracNahrada></form:neodpracovaneHodiny>',
        ));

        $this->assertPasses(328, self::withEldpDetail('<form:odecitaneDny><form:odecitaneDobyCelkem>0</form:odecitaneDobyCelkem></form:odecitaneDny>'));
        $this->assertFails(328, self::withEldpDetail(
            '<form:odecitaneDny><form:odecitaneDobyCelkem>0</form:odecitaneDobyCelkem>'
                . '<form:pracovniNeschopnost>2</form:pracovniNeschopnost></form:odecitaneDny>',
        ));

        $this->assertPasses(329, self::withEldpDetail('<form:vylouceneDny><form:vylouceneDobyCelkem>0</form:vylouceneDobyCelkem></form:vylouceneDny>'));
        $this->assertFails(329, self::withEldpDetail(
            '<form:vylouceneDny><form:vylouceneDobyCelkem>0</form:vylouceneDobyCelkem>'
                . '<form:docasNeschopnost>3</form:docasNeschopnost></form:vylouceneDny>',
        ));
    }

    /** Kontroly 121 a 98: úhrn vyloučených dob a denní atributy ELDP. */
    public function testExcludedPeriodTotalsMatchTheirPartsAndFitTheMonth(): void
    {
        $excluded = static fn (string $parts, int $total = 5): string => self::withEldpDetail(
            "<form:vylouceneDny><form:vylouceneDobyCelkem>{$total}</form:vylouceneDobyCelkem>{$parts}</form:vylouceneDny>",
        );
        $this->assertFails(121, $excluded('<form:docasNeschopnost>3</form:docasNeschopnost>'));
        $this->assertPasses(121, $excluded(
            '<form:docasNeschopnost>3</form:docasNeschopnost><form:penezitaPomocMaterstvi>2</form:penezitaPomocMaterstvi>',
        ));

        $this->assertFails(98, $excluded('<form:docasNeschopnost>32</form:docasNeschopnost>', 32));
        $this->assertPasses(98, $excluded('<form:docasNeschopnost>31</form:docasNeschopnost>', 31));
    }

    /** Kontroly 72, 304, 50, 109 a 74: příjmy, základy a daňový bonus. */
    public function testIncomeTaxBaseAndBonusRespectTheirBounds(): void
    {
        $minimal = JmhzXmlSample::minimal();

        $this->assertPasses(72, $minimal);
        $this->assertFails(72, str_replace('<form:zuctovanoCelkem>1000</form:zuctovanoCelkem>', '<form:zuctovanoCelkem>-1</form:zuctovanoCelkem>', $minimal));

        $taxBase = static fn (string $value): string => (string) preg_replace(
            '~(<form:dan>\s*<form:zakladDane>)1000(</form:zakladDane>)~',
            '${1}' . $value . '${2}',
            JmhzXmlSample::minimal(),
        );
        $this->assertPasses(304, $minimal);
        $this->assertPasses(304, $taxBase('0'));
        $this->assertFails(304, $taxBase('-1'));

        $this->assertPasses(50, $minimal);
        $this->assertFails(50, str_replace('<form:vymerovaciZaklad>1000</form:vymerovaciZaklad>', '<form:vymerovaciZaklad>-1</form:vymerovaciZaklad>', $minimal));

        $nonResident = static fn (int $amount): string => str_replace(
            '<form:zuctovanoCelkem>1000</form:zuctovanoCelkem>',
            "<form:zuctovanoCelkem>1000</form:zuctovanoCelkem><form:odmenyNerezident>{$amount}</form:odmenyNerezident>",
            JmhzXmlSample::minimal(),
        );
        $this->assertFails(109, $nonResident(1001));
        $this->assertPasses(109, $nonResident(1000));

        $bonus = static fn (int $amount): string => str_replace(
            '<form:danZalohaPoSleve>150</form:danZalohaPoSleve>',
            "<form:danZalohaPoSleve>150</form:danZalohaPoSleve><form:danBonus>{$amount}</form:danBonus>",
            JmhzXmlSample::minimal(),
        );
        $this->assertFails(74, $bonus(49));
        $this->assertFails(74, $bonus(-1));
        $this->assertPasses(74, $bonus(50));
        $this->assertPasses(74, $bonus(0));
    }

    /** Kontroly 1, 3 a 137: sleva zaměstnavatele podle § 7a ZPSZ. */
    public function testEmployerDiscountHeadcountAmountAndReason(): void
    {
        $form = JmhzXmlSample::form(
            '1000000001',
            '2000000000000000000001',
            discount: JmhzXmlSample::discountBlock(),
        );
        $this->assertPasses(1, JmhzXmlSample::withEmployerDiscount());
        $this->assertFails(1, JmhzXmlSample::document($form, pvpoj: JmhzXmlSample::discountPvpoj(headcount: 2)));

        $this->assertPasses(3, JmhzXmlSample::withEmployerDiscount());
        $this->assertFails(3, JmhzXmlSample::document($form, pvpoj: JmhzXmlSample::discountPvpoj(discount: 49)));
        // 5 % z 1 001 Kč je 50,05 Kč, zaokrouhleno nahoru 51 Kč.
        $this->assertFails(3, JmhzXmlSample::document($form, pvpoj: JmhzXmlSample::discountPvpoj(base: 1_001, discount: 50)));
        $this->assertPasses(3, JmhzXmlSample::document($form, pvpoj: JmhzXmlSample::discountPvpoj(base: 1_001, discount: 51)));

        $this->assertPasses(137, JmhzXmlSample::withEmployerDiscount());
        $this->assertFails(137, str_replace(
            '<form:duvodUplatneni>A</form:duvodUplatneni>',
            '',
            JmhzXmlSample::withEmployerDiscount(),
        ));
    }

    /** Kontroly 168 a 170: tolerance pojistného a slev proti úhrnu základů. */
    public function testEmployeeInsuranceAndDiscountTotalsStayWithinTolerance(): void
    {
        $this->assertPasses(168, JmhzXmlSample::minimal());
        $this->assertFails(168, str_replace(
            '<pvpoj:pojistneZamestnance>71</pvpoj:pojistneZamestnance>',
            '<pvpoj:pojistneZamestnance>172</pvpoj:pojistneZamestnance>',
            JmhzXmlSample::minimal(),
        ));
        // Absolutní tolerance 100 Kč: 171 Kč proti 71 Kč ještě projde.
        $this->assertPasses(168, str_replace(
            '<pvpoj:pojistneZamestnance>71</pvpoj:pojistneZamestnance>',
            '<pvpoj:pojistneZamestnance>171</pvpoj:pojistneZamestnance>',
            JmhzXmlSample::minimal(),
        ));

        $form = JmhzXmlSample::form(
            '1000000001',
            '2000000000000000000001',
            employeeDiscount: JmhzXmlSample::employeeDiscountBlock(),
        );
        $this->assertPasses(170, JmhzXmlSample::withEmployeeDiscount());
        $this->assertFails(170, JmhzXmlSample::document($form, pvpoj: JmhzXmlSample::employeeDiscountPvpoj(discount: 166)));
        $this->assertFails(170, JmhzXmlSample::document($form, pvpoj: JmhzXmlSample::employeeDiscountPvpoj(base: 0, discount: 65)));
    }

    /** Kontroly 271, 272, 273 a 296: sleva zaměstnance v ovocnářství. */
    public function testOrchardEmployeeDiscountConditions(): void
    {
        $orchard = static fn (string $flag, ?int $amount, string $activity = 'T', int $base = 1000): string => self::orchardForm(
            $flag,
            $amount,
            $activity,
            $base,
        );

        $this->assertPasses(273, $orchard('true', 71));
        $this->assertFails(273, $orchard('true', 70));
        $this->assertFails(273, $orchard('true', null));

        $this->assertPasses(272, $orchard('true', 71));
        $this->assertFails(272, $orchard('false', 71));
        // Částka bez elementu příznaku: dřív kontrola součást tiše přeskočila.
        $this->assertFails(272, str_replace(
            '<form:slevaZamestnanceOvoZelEvidovana>true</form:slevaZamestnanceOvoZelEvidovana>',
            '',
            $orchard('true', 71),
        ));

        $this->assertPasses(296, $orchard('true', 71, 'T'));
        $this->assertPasses(296, $orchard('true', 71, 'ZC'));
        $this->assertFails(296, $orchard('true', 71, '1'));
        $this->assertPasses(296, $orchard('false', null, '1'));

        // Mez je parametr katalogu (source_row_16), ne literál v testu: hledá
        // se první základ, při kterém sleva přestane projít.
        $this->assertPasses(271, $orchard('true', 71, 'T', 1000));
        $this->assertFails(271, $orchard('true', 71, 'T', 10_000_000));
        $this->assertPasses(271, $orchard('false', null, 'T', 10_000_000));
    }

    /** Kontroly 129, 93, 240 a 88: metadatová hlavička podání. */
    public function testSubmissionHeaderMetadata(): void
    {
        $minimal = JmhzXmlSample::minimal();

        $this->assertPasses(129, $minimal);
        $this->assertFails(129, str_replace('<mesic>7</mesic>', '<mesic>13</mesic>', $minimal));
        $this->assertFails(129, str_replace('<mesic>7</mesic>', '<mesic>0</mesic>', $minimal));

        $this->assertPasses(93, $minimal);
        $this->assertFails(93, str_replace(
            '<formularePocetCelkem>3</formularePocetCelkem>',
            '<formularePocetCelkem>2</formularePocetCelkem>',
            $minimal,
        ));

        $this->assertPasses(240, $minimal);
        $this->assertFails(240, str_replace('<balikPoradi>1</balikPoradi>', '', $minimal));
        $this->assertFails(240, str_replace('<formularePocetCelkem>3</formularePocetCelkem>', '', $minimal));

        $this->assertPasses(88, $minimal);
        $this->assertPasses(88, str_replace('2026-08-05T09:30:00Z', self::EVALUATED_ON . 'T23:59:00Z', $minimal));
        $this->assertFails(88, str_replace('2026-08-05T09:30:00Z', '2026-08-15T00:00:00Z', $minimal));
    }

    /** Kontroly 43, 44, 56, 58 a 99: data a dny proti hlášenému měsíci. */
    public function testDatesAndDayCountsAgainstPeriodAndFilling(): void
    {
        $minimal = JmhzXmlSample::minimal();
        foreach ([43, 44] as $controlId) {
            $this->assertPasses($controlId, $minimal);
            $this->assertFails($controlId, str_replace(
                '<form:pojisteniOd>2026-07-01</form:pojisteniOd>',
                '<form:pojisteniOd>2026-08-01</form:pojisteniOd>',
                $minimal,
            ));
            $this->assertFails($controlId, str_replace(
                '<form:pojisteniDo>2026-07-31</form:pojisteniDo>',
                '<form:pojisteniDo>2026-08-06</form:pojisteniDo>',
                $minimal,
            ));
        }

        $exposure = static fn (string $date): string => self::withHours(
            "<form:rozpad><form:hornictvi><form:expoziceNpeDosazeniDatum>{$date}</form:expoziceNpeDosazeniDatum></form:hornictvi></form:rozpad>",
        );
        $this->assertPasses(56, $exposure('2026-08-05'));
        $this->assertFails(56, $exposure('2026-08-06'));

        $this->assertPasses(58, $minimal);
        $this->assertFails(58, str_replace('<form:pocetDnu>31</form:pocetDnu>', '<form:pocetDnu>32</form:pocetDnu>', $minimal));
        $february = JmhzXmlSample::document(
            str_replace(
                ['2026-07-01', '2026-07-31', '<form:pocetDnu>31</form:pocetDnu>'],
                ['2026-02-01', '2026-02-28', '<form:pocetDnu>29</form:pocetDnu>'],
                JmhzXmlSample::form('1000000001', '2000000000000000000001'),
            ),
            month: '2',
        );
        $this->assertFails(58, $february);

        $this->assertPasses(99, $minimal);
        $this->assertFails(99, str_replace(
            '<form:platnostOd>2026-07-01</form:platnostOd>',
            '<form:platnostOd>2026-06-30</form:platnostOd>',
            $minimal,
        ));
    }

    /** Kontroly 191, 192, 193 a 194: údaje vázané na určitý měsíc roku. */
    public function testAnnualAttributesOnlyInTheirMonths(): void
    {
        $annual = static fn (string $month, string $content): string => JmhzXmlSample::document(
            str_replace(
                '<form:prohlaseniPoplatnika>false</form:prohlaseniPoplatnika>',
                "<form:rocniUhrny>{$content}</form:rocniUhrny>"
                    . '<form:prohlaseniPoplatnika>false</form:prohlaseniPoplatnika>',
                JmhzXmlSample::form('1000000001', '2000000000000000000001'),
            ),
            month: $month,
        );

        // Správná součást v lednu až březnu skončí jako „nedopadá", ne „prošlo":
        // vyhodnocení se tam ptá jen na přítomnost atributu. Podstatné je, že ji
        // kontrola nezamítne a že roční údaj v jiném měsíci zamítne.
        $done = '<form:rocniZuctovaniProvedeno>false</form:rocniZuctovaniProvedeno>';
        $this->assertNotFailed(191, $annual('3', $done));
        $this->assertFails(191, $annual('4', $done));
        $this->assertFails(191, $annual('12', '<form:rocniZuctovaniZadost>false</form:rocniZuctovaniZadost>' . $done));

        $request = '<form:rocniZuctovaniZadost>false</form:rocniZuctovaniZadost>';
        $this->assertNotFailed(192, $annual('2', $request));
        $this->assertNotFailed(192, $annual('7', ''));
        $this->assertFails(192, $annual('3', $request));

        $totals = '<form:prijemZdanitelnyCelkem>12000</form:prijemZdanitelnyCelkem>';
        $this->assertPasses(193, $annual('1', $totals));
        $this->assertFails(193, $annual('2', $totals));

        $employerAnnual = static fn (string $month): string => str_replace(
            '<so:souhrn>',
            '<so:souhrn><so:zamestnavatelUdajeRok><so:formaVlastnictvi>1</so:formaVlastnictvi></so:zamestnavatelUdajeRok>',
            JmhzXmlSample::document(JmhzXmlSample::form('1000000001', '2000000000000000000001'), month: $month),
        );
        $this->assertPasses(194, $employerAnnual('12'));
        $this->assertFails(194, $employerAnnual('11'));
    }

    /** Kontroly 150 a 151: číselníky ročních údajů zaměstnavatele. */
    public function testEmployerAnnualCodebooks(): void
    {
        $summary = static fn (string $content): string => str_replace(
            '<so:souhrn>',
            "<so:souhrn><so:zamestnavatelUdajeRok>{$content}</so:zamestnavatelUdajeRok>",
            JmhzXmlSample::document(JmhzXmlSample::form('1000000001', '2000000000000000000001'), month: '12'),
        );
        $this->assertPasses(151, $summary('<so:formaVlastnictvi>4</so:formaVlastnictvi>'));
        $this->assertFails(151, $summary('<so:formaVlastnictvi>5</so:formaVlastnictvi>'));

        $agreements = static fn (string ...$types): string => $summary(
            '<so:kolektivniSmlouvy>' . implode('', array_map(
                static fn (string $type): string => "<so:kolektivniSmlouva><so:typKolektSmlouvy>{$type}</so:typKolektSmlouvy></so:kolektivniSmlouva>",
                $types,
            )) . '</so:kolektivniSmlouvy>',
        );
        $this->assertPasses(150, $agreements('1', '2'));
        $this->assertPasses(150, $agreements('0'));
        $this->assertFails(150, $agreements('6'));
        $this->assertFails(150, $agreements('0', '1'));
    }

    /** Kontroly 236, 211, 303, 255, 332 a 307: struktura součástí. */
    public function testFormStructureControls(): void
    {
        $minimal = JmhzXmlSample::minimal();

        $this->assertPasses(236, $minimal);
        $this->assertFails(236, str_replace('<typFormulare>R</typFormulare>', '<typFormulare>O</typFormulare>', $minimal));

        $twoForms = JmhzXmlSample::twoForms();
        $cancelSecond = (string) preg_replace(
            '~<typFormulare>R</typFormulare>(?!.*<typFormulare>)~s',
            '<typFormulare>S</typFormulare>',
            $twoForms,
        );
        $this->assertPasses(211, $cancelSecond);
        $this->assertFails(211, str_replace('<typFormulare>R</typFormulare>', '<typFormulare>S</typFormulare>', $minimal));

        $this->assertPasses(303, $minimal);
        $this->assertFails(303, str_replace('</form:bezPriznaku>', '</form:bezPriznaku><form:vezen></form:vezen>', $minimal));
        $this->assertFails(303, (string) preg_replace('~<form:bezPriznaku>.*</form:bezPriznaku>~s', '', $minimal));
        // Stornující součást nese jen hlavičku; tělo formuláře mít nesmí.
        $this->assertPasses(303, JmhzXmlSample::document(
            JmhzXmlSample::form('1000000001', '2000000000000000000001')
                . '<formularOsoby><hlavicka><idFormulare>0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E11</idFormulare>'
                . '<typFormulare>S</typFormulare></hlavicka></formularOsoby>',
            formCount: 4,
        ));

        // Dva vztahy téže osoby: jeden primární stačí, žádný nestačí.
        $samePerson = str_replace('1000000012', '1000000001', $twoForms);
        $this->assertPasses(255, $samePerson);
        $this->assertFails(255, str_replace('<primarniPpv>true</primarniPpv>', '<primarniPpv>false</primarniPpv>', $samePerson));
        $this->assertFails(255, $twoForms);

        $this->assertPasses(332, $minimal);
        $this->assertFails(332, str_replace('<primarniPpv>true</primarniPpv>', '', $minimal));

        $noCode = static fn (string $detail): string => JmhzXmlSample::document(JmhzXmlSample::form(
            '1000000001',
            '2000000000000000000001',
            eldp: "<form:eldp><form:pocetDnu>0</form:pocetDnu>{$detail}</form:eldp>",
        ));
        $this->assertPasses(307, $minimal);
        $this->assertFails(307, $noCode('<form:vymerovaciZaklad>1000</form:vymerovaciZaklad>'));
    }

    /** Kontroly 159, 162, 113, 112, 124, 310 a 60: podmíněně povinné údaje. */
    public function testConditionallyRequiredAndForbiddenAttributes(): void
    {
        $minimal = JmhzXmlSample::minimal();

        $apz = static fn (string $instrument): string => str_replace(
            '<form:uplatnujiPrispevekApz>false</form:uplatnujiPrispevekApz>',
            "<form:uplatnujiPrispevekApz>true</form:uplatnujiPrispevekApz>{$instrument}",
            JmhzXmlSample::minimal(),
        );
        $this->assertFails(159, $apz(''));
        $this->assertPasses(159, $apz('<form:nastrojApzKod>1</form:nastrojApzKod>'));

        $this->assertPasses(162, $minimal);
        $this->assertFails(162, str_replace(
            '<pvpoj:zakladZamestnavateleA>1000</pvpoj:zakladZamestnavateleA>',
            '',
            $minimal,
        ));
        $this->assertFails(162, str_replace(
            '<pvpoj:zakladZamestnavateleA>1000</pvpoj:zakladZamestnavateleA>',
            '<pvpoj:zakladZamestnavateleA>-1</pvpoj:zakladZamestnavateleA>',
            $minimal,
        ));

        $caregiver = static fn (string $birth): string => str_replace(
            '<form:prohlaseniPoplatnika>false</form:prohlaseniPoplatnika>',
            '<form:prohlaseniPoplatnika>true</form:prohlaseniPoplatnika>'
                . '<form:prohlaseniPoplatnikaDane><form:zvyhodneniDetiMesic><form:jineOsoby><form:jinaOsoba>'
                . "<form:jmeno>Eva</form:jmeno><form:prijmeni>Nová</form:prijmeni>{$birth}"
                . '</form:jinaOsoba></form:jineOsoby></form:zvyhodneniDetiMesic></form:prohlaseniPoplatnikaDane>',
            JmhzXmlSample::minimal(),
        );
        $this->assertFails(113, $caregiver(''));
        $this->assertPasses(113, $caregiver('<form:datumNarozeni>1990-01-01</form:datumNarozeni>'));

        $result = static fn (string $performed, string $content): string => JmhzXmlSample::document(
            str_replace(
                '<form:prohlaseniPoplatnika>false</form:prohlaseniPoplatnika>',
                "<form:rocniUhrny><form:rocniZuctovaniProvedeno>{$performed}</form:rocniZuctovaniProvedeno>"
                    . "<form:vysledekRocnihoZuctovani>{$content}</form:vysledekRocnihoZuctovani></form:rocniUhrny>"
                    . '<form:prohlaseniPoplatnika>false</form:prohlaseniPoplatnika>',
                JmhzXmlSample::form('1000000001', '2000000000000000000001'),
            ),
            month: '2',
        );
        $child = static fn (string $order): string => '<form:uplatnenoZvyhodneniNaDeti>true</form:uplatnenoZvyhodneniNaDeti>'
            . '<form:zvyhodneniNaDeti><form:vyzivovaneDeti><form:vyzivovaneDite><form:dite>'
            . '<form:jmeno>Jan</form:jmeno><form:prijmeni>Novák</form:prijmeni>'
            . '<form:datumNarozeni>2015-04-11</form:datumNarozeni></form:dite>'
            . $order . '</form:vyzivovaneDite></form:vyzivovaneDeti></form:zvyhodneniNaDeti>';
        $this->assertPasses(112, $result('true', $child('<form:poradi>1</form:poradi>')));
        $this->assertFails(112, $result('true', $child('')));

        $spouse = static fn (string $months): string => '<form:uplatnenaSlevaNaPartnera>true</form:uplatnenaSlevaNaPartnera>'
            . '<form:slevaNaPartnera><form:partner><form:partnerUdaje>'
            . '<form:jmeno>Eva</form:jmeno><form:prijmeni>Nováková</form:prijmeni>'
            . '<form:datumNarozeni>1990-01-01</form:datumNarozeni></form:partnerUdaje>'
            . "<form:prukazZtpp>false</form:prukazZtpp>{$months}</form:partner></form:slevaNaPartnera>";
        $this->assertPasses(124, $result('true', $spouse('<form:slevaPocetMesicu>12</form:slevaPocetMesicu>')));
        $this->assertFails(124, $result('true', $spouse('')));

        $this->assertPasses(310, $result('false', ''));
        $this->assertFails(310, $result('false', '<form:preplatekRok>100</form:preplatekRok>'));

        $legalFact = static fn (string $date): string => str_replace(
            '<so:souhrn>',
            "<so:souhrn><so:specifickaSkutecnost><so:datum>{$date}</so:datum></so:specifickaSkutecnost>",
            JmhzXmlSample::minimal(),
        );
        $this->assertPasses(60, $legalFact('2026-08-04'));
        $this->assertFails(60, $legalFact('2026-08-05'));
    }

    private static function withHours(string $breakdown): string
    {
        return str_replace(
            '<form:pocet>184.000</form:pocet>',
            "<form:pocet>184.000</form:pocet>{$breakdown}",
            JmhzXmlSample::minimal(),
        );
    }

    private static function withProgress(string $block): string
    {
        return str_replace(
            '</form:odpracovaneHodiny>',
            "</form:odpracovaneHodiny>{$block}",
            JmhzXmlSample::minimal(),
        );
    }

    private static function withEldpDetail(string $detail): string
    {
        $xml = str_replace(
            '<form:vymerovaciZaklad>1000</form:vymerovaciZaklad>',
            "<form:vymerovaciZaklad>1000</form:vymerovaciZaklad>{$detail}",
            JmhzXmlSample::minimal(),
        );
        self::assertStringContainsString($detail, $xml);

        return $xml;
    }

    private static function orchardForm(string $flag, ?int $amount, string $activity, int $base): string
    {
        $amountXml = $amount === null
            ? ''
            : "<form:slevaZamestnanceOvoZel><form:vyseSlevy>{$amount}</form:vyseSlevy></form:slevaZamestnanceOvoZel>";
        $block = '<form:slevaZamestnance><form:slevaZamestnanceEvidovana>false</form:slevaZamestnanceEvidovana>'
            . "<form:slevaZamestnanceOvoZelEvidovana>{$flag}</form:slevaZamestnanceOvoZelEvidovana>{$amountXml}"
            . '</form:slevaZamestnance>';
        $form = JmhzXmlSample::form('1000000001', '2000000000000000000001', employeeDiscount: $block);
        $form = str_replace(
            '<form:idPpv>2000000000000000000001</form:idPpv>',
            "<form:idPpv>2000000000000000000001</form:idPpv><form:druhCinnosti>{$activity}</form:druhCinnosti>",
            $form,
        );
        $form = str_replace(
            '<form:castkaOdvodPojistneho>1000</form:castkaOdvodPojistneho>',
            "<form:castkaOdvodPojistneho>{$base}</form:castkaOdvodPojistneho>",
            $form,
        );

        return JmhzXmlSample::document($form);
    }

    private function assertFails(int $controlId, string $xml): void
    {
        $outcomes = $this->outcomes($controlId, $xml);
        self::assertContains(
            JmhzControlOutcome::Failed,
            $outcomes,
            "Kontrola {$controlId} měla porušení odhalit, vrátila "
                . implode(', ', array_map(static fn (JmhzControlOutcome $o): string => $o->value, $outcomes)) . '.',
        );
    }

    private function assertNotFailed(int $controlId, string $xml): void
    {
        self::assertNotContains(
            JmhzControlOutcome::Failed,
            $this->outcomes($controlId, $xml),
            "Kontrola {$controlId} nesmí správné podání zamítnout.",
        );
    }

    private function assertPasses(int $controlId, string $xml): void
    {
        self::assertSame(
            [JmhzControlOutcome::Passed],
            $this->outcomes($controlId, $xml),
            "Kontrola {$controlId} měla správné podání pustit.",
        );
    }

    /** @return list<JmhzControlOutcome> */
    private function outcomes(int $controlId, string $xml): array
    {
        $catalog = JmhzControlSourceCatalog::load();
        $verdicts = (new JmhzScenario1ControlEvaluator(
            $catalog->parameters(),
            new JmhzDeadlinePolicy(CzechPayrollRulesets2026::provider()),
        ))->evaluate($controlId, JmhzAttributeProjection::fromXml($xml), new JmhzControlContext(self::EVALUATED_ON));

        return array_map(static fn (JmhzControlVerdict $verdict): JmhzControlOutcome => $verdict->outcome, $verdicts);
    }
}
