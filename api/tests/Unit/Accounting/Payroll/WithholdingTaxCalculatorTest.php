<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Payroll;

use MyInvoice\Service\Accounting\Payroll\WithholdingTaxCalculator as W;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;

/**
 * Srážková daň ze samostatného základu — § 6 odst. 4, § 7 odst. 6 a § 36 ZDP.
 *
 * Matice daní z příjmů to vedla mezi vysokými riziky: „firmy na DPP musí mzdy počítat
 * mimo systém". Mzdový modul znal jen zálohovou daň ze závislé činnosti.
 *
 * Dvě věci, které testy hlídají především:
 *   1. Překročení limitu není „o kolik víc" — daní se běžným režimem CELÁ částka,
 *      ne jen část nad limitem ({@see testOverLimitTaxesWholeAmountNormally()}).
 *   2. Podepsané prohlášení srážku VYLUČUJE i pod limitem. Bez té podmínky by se
 *      zaměstnanci s prohlášením upřely slevy na dani.
 */
final class WithholdingTaxCalculatorTest extends TestCase
{
    /**
     * Sada roku 2024: § 6 odst. 4 písm. a) ZDP tehdy zněl „NEPŘESÁHNE … 10 000 Kč",
     * hranice je tedy VČETNĚ. Od 1. 1. 2025 (zák. č. 470/2024 Sb.) zní „nedosáhne
     * rozhodné částky" a hranice je ostrá — pro ni je fixtura {@see self::EXCLUSIVE}.
     *
     * @var array<string,mixed>
     */
    private array $c = [
        'withholding_rate' => 0.15,
        'dpp_withholding_limit' => 10000,
        'dpp_withholding_limit_inclusive' => true,
        'author_fee_withholding_limit' => 10000,
    ];

    /**
     * Sada podle znění účinného od 1. 1. 2025 — táž čísla, ostrá hranice.
     *
     * @var array<string,mixed>
     */
    private const EXCLUSIVE = [
        'withholding_rate' => 0.15,
        'dpp_withholding_limit' => 10000,
        'dpp_withholding_limit_inclusive' => false,
        'author_fee_withholding_limit' => 10000,
    ];

    /** DPP do limitu bez prohlášení → srážka 15 %. */
    public function testDppUnderLimitWithoutDeclarationIsWithheld(): void
    {
        self::assertTrue(W::applies(W::REASON_DPP, 9000.0, $this->c));

        $r = W::compute(W::REASON_DPP, 9000.0, $this->c);

        self::assertSame(9000, $r['base']);
        self::assertSame(1350, $r['tax'], '15 % z 9 000.');
        self::assertSame(7650, $r['net']);
        self::assertFalse($r['insurance_applies'], 'Z DPP do limitu se pojistné neodvádí.');
    }

    /**
     * Od roku 2027 je DPP pod rozhodnou částkou bez prohlášení samostatným
     * základem ZÁLOHY (§ 6 odst. 4 a 18 ZDP) — zaokrouhlení nahoru, odvod se
     * zálohami, roční zúčtování. Zjednodušená rekapitulace to neumí, takže rok
     * 2027 odmítne, místo aby ho tiše spočítala jako srážku dolů.
     */
    public function testDppBelowThresholdFrom2027IsRefusedInTheSimplifiedRecap(): void
    {
        $c2027 = ['year' => 2027, 'dpp_withholding_limit' => 12500] + self::EXCLUSIVE;
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Od roku 2027 se z DPP pod rozhodnou částkou nesráží srážková daň');

        W::applies(W::REASON_DPP, 12499.0, $c2027);
    }

    /** Rok 2026 a nad rozhodnou částkou 2027 zůstává chování beze změny. */
    public function testYear2026AndOverLimit2027AreUnchanged(): void
    {
        $c2026 = ['year' => 2026, 'dpp_withholding_limit' => 12000] + self::EXCLUSIVE;
        self::assertTrue(W::applies(W::REASON_DPP, 11999.0, $c2026));
        self::assertSame(1799, W::compute(W::REASON_DPP, 11999.0, $c2026)['tax']);

        $c2027 = ['year' => 2027, 'dpp_withholding_limit' => 12500] + self::EXCLUSIVE;
        self::assertFalse(W::applies(W::REASON_DPP, 12500.0, $c2027));
        self::assertFalse(W::applies(W::REASON_DPP, 9000.0, $c2027, true));
    }

    /**
     * Do 31. 12. 2024 byla hranice „do" — odměna přesně na ní se ještě srazila.
     * § 6 odst. 4 písm. a) ZDP tehdy zněl „nepřesáhne … 10 000 Kč".
     */
    public function testExactlyAtLimitStillWithheldUnderThe2024Wording(): void
    {
        self::assertTrue(W::applies(W::REASON_DPP, 10000.0, $this->c));
    }

    /**
     * Od 1. 1. 2025 je hranice OSTRÁ: zák. č. 470/2024 Sb. změnil § 6 odst. 4
     * písm. a) ZDP na „NEDOSÁHNE částky rozhodné pro účast … na nemocenském
     * pojištění". Odměna přesně na rozhodné částce už podle § 7a z. č. 187/2006 Sb.
     * („aspoň ve výši rozhodné částky") zakládá účast na nemocenském pojištění,
     * daní se tedy zálohou a odvádí se z ní SP i ZP.
     *
     * Tohle je celý rozdíl mezi 12 000 a 11 999 — jiná daň, jiné odvody.
     */
    public function testExactlyAtThresholdIsNotWithheldFrom2025(): void
    {
        self::assertFalse(W::applies(W::REASON_DPP, 10000.0, self::EXCLUSIVE));
        self::assertTrue(W::applies(W::REASON_DPP, 9999.99, self::EXCLUSIVE));

        $msg = W::overLimitReason(W::REASON_DPP, 10000.0, self::EXCLUSIVE);
        self::assertNotNull($msg, 'Odměna na rozhodné částce musí dostat vysvětlení, ne mlčení.');
        self::assertStringContainsString('dosahuje rozhodné částky', $msg);
    }

    /**
     * Haléře jsou přesně to místo, kde se stará zkratka „limit je o korunu níž"
     * rozcházela se zákonem: 9 999,50 Kč rozhodné částky NEDOSÁHNE, takže srážkou
     * jde — se zápisem `<= 9 999` by šla zálohou.
     */
    public function testAmountWithHellersBelowThresholdIsStillWithheld(): void
    {
        self::assertTrue(W::applies(W::REASON_DPP, 9999.50, self::EXCLUSIVE));
        self::assertNull(W::overLimitReason(W::REASON_DPP, 9999.50, self::EXCLUSIVE));
    }

    /** Chybějící příznak = přísnější (novější) výklad, ne tichý návrat ke starému. */
    public function testMissingInclusivityFlagDefaultsToTheStrictWording(): void
    {
        $c = ['withholding_rate' => 0.15, 'dpp_withholding_limit' => 10000];

        self::assertFalse(W::applies(W::REASON_DPP, 10000.0, $c));
    }

    /**
     * Nad limitem se srážka NEUPLATNÍ VŮBEC — ani na část do limitu. Kdyby se srazilo
     * z prvních 10 000, poplatník by odvedl špatnou daň a systém by o tom mlčel.
     */
    public function testOverLimitTaxesWholeAmountNormally(): void
    {
        self::assertFalse(W::applies(W::REASON_DPP, 10001.0, $this->c));

        $msg = W::overLimitReason(W::REASON_DPP, 10001.0, $this->c);
        self::assertNotNull($msg);
        self::assertStringContainsString('celá částka', $msg);
    }

    /** Nad limitem výpočet odmítne počítat, místo aby vrátil tiše špatné číslo. */
    public function testComputeRefusesOverLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        W::compute(W::REASON_DPP, 15000.0, $this->c);
    }

    /**
     * Podepsané prohlášení srážku vylučuje i pod limitem — jinak by se zaměstnanci
     * upřely slevy na dani, na které má nárok.
     */
    public function testSignedDeclarationExcludesWithholding(): void
    {
        self::assertFalse(W::applies(W::REASON_DPP, 9000.0, $this->c, taxDeclarationSigned: true));
    }

    /**
     * Typ pracovněprávního vztahu → důvod srážky, kterou rekapitulace umí
     * SPOČÍTAT. Je to jen DPP (§ 6 odst. 4 písm. a) ZDP): u ní pod rozhodnou
     * částkou nevzniká pojistné, takže srážková větev bez pojistného sedí.
     * Písmeno b) dopadá i na ostatní vztahy, ale vedle pojistného řízeného
     * sjednanou částkou — ten výpočet rekapitulace nedělá, jen upozorní
     * ({@see testLetterBNoticeCoversEveryNonDppRelationship()}).
     */
    public function testWithholdingReasonIsWhitelistedPerEmploymentType(): void
    {
        self::assertSame(W::REASON_DPP, W::reasonForEmploymentType('dpp'));
        self::assertNull(W::reasonForEmploymentType('hpp'));
        self::assertNull(W::reasonForEmploymentType('dpc'));
        self::assertNull(W::reasonForEmploymentType('statutory_body'));
        self::assertNull(
            W::reasonForEmploymentType('cokoliv_noveho'),
            'Neznámý typ nesmí do srážkové větve DPP spadnout sám od sebe.',
        );
    }

    /**
     * § 6 odst. 4 písm. b) ZDP (znění od 1. 1. 2025): bez prohlášení a pod
     * rozhodnou částkou pro účast na nemocenském pojištění se sráží u KAŽDÉHO
     * druhu vztahu. Rekapitulace to nepočítá, ale nesmí o tom mlčet — dřív tu
     * pracovní poměr i odměna jednatele šly tiše zálohou.
     */
    public function testLetterBNoticeCoversEveryNonDppRelationship(): void
    {
        $c = self::EXCLUSIVE + ['sickness_participation_threshold' => 4500];

        foreach (['hpp', 'dpc', 'statutory_body'] as $type) {
            $notice = W::letterBNotice($type, 4499.99, $c, false);
            self::assertNotNull($notice, $type);
            self::assertStringContainsString('§ 6 odst. 4 písm. b)', $notice);
        }
        // Na rozhodné částce už ne („nedosahující“ je ostře menší).
        self::assertNull(W::letterBNotice('hpp', 4500.0, $c, false));
        // Podepsané prohlášení srážku vylučuje.
        self::assertNull(W::letterBNotice('hpp', 3000.0, $c, true));
        // DPP má vlastní písmeno a) a srážku rekapitulace spočítá sama.
        self::assertNull(W::letterBNotice('dpp', 3000.0, $c, false));
        // Starší znění § 6 odst. 4 (do 2024) tahle oprava neověřovala a nemění.
        self::assertNull(W::letterBNotice(
            'hpp',
            3000.0,
            $this->c + ['sickness_participation_threshold' => 4000],
            false,
        ));
    }

    /**
     * Legacy cesta měří písmeno b) ročními konstantami, mzdový běh rulesetem.
     * Obě čísla musí být táž rozhodná částka, jinak dostane tatáž odměna jiný
     * režim podle toho, kudy se počítala.
     */
    public function testLetterBThresholdMatchesThePayrollRuleset(): void
    {
        foreach ([2025, 2026] as $year) {
            $ruleset = \MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets::provider()
                ->forDate(PayrollRulesetDomain::IncomeTax, $year . '-01-01');
            $threshold = $ruleset->parameters['other.withholding.threshold']->value;
            self::assertIsInt($threshold);
            self::assertSame(
                $threshold,
                (int) round(((float) TaxConstants::forYear($year)['sickness_participation_threshold']) * 100),
                "Rozhodná částka § 6 odst. 4 písm. b) pro rok {$year}.",
            );
        }
    }

    /** Autorský honorář do limitu (§ 7/6) — prohlášení tu roli nehraje. */
    public function testAuthorFeeUnderLimitIsWithheld(): void
    {
        $r = W::compute(W::REASON_AUTHOR_FEE, 8000.0, $this->c, taxDeclarationSigned: true);

        self::assertSame(1200, $r['tax']);
    }

    /** Nerezident (§ 36) nemá limit — rozhoduje rezidence, ne výše příjmu. */
    public function testNonResidentHasNoLimit(): void
    {
        self::assertTrue(W::applies(W::REASON_NON_RESIDENT, 500000.0, $this->c));
        self::assertNull(W::overLimitReason(W::REASON_NON_RESIDENT, 500000.0, $this->c));
    }

    /**
     * U nerezidenta se HLÁSÍ, že sazbu může měnit smlouva o zamezení dvojího zdanění.
     * Systém smlouvy nezná, takže mlčet by budilo dojem, že je sazba ověřená.
     */
    public function testNonResidentWarnsAboutTreaty(): void
    {
        $r = W::compute(W::REASON_NON_RESIDENT, 100000.0, $this->c);

        self::assertStringContainsString('dvojího zdanění', implode("\n", $r['warnings']));
    }

    /**
     * Varování u DPP musí zmínit OBĚ mezery, ne jen tu, kterou systém zavřít nemůže.
     *
     * Původní text upozorňoval jen na souběh u JINÝCH zaměstnavatelů, které systém
     * nevidí, a mlčel o tom, že § 6 odst. 4 ZDP počítá ÚHRN odměn u TÉHOŽ plátce —
     * což systém vidí, ale drží jen jeden mzdový záznam na měsíc, takže úhrn musí
     * zadat uživatel. Uživatel byl tím pádem uklidněn ohledně jediné mezery, kterou
     * zavřít lze.
     */
    public function testDppWarningCoversBothAggregationRisks(): void
    {
        $w = implode("\n", W::compute(W::REASON_DPP, 9000.0, $this->c)['warnings']);

        self::assertStringContainsString('ÚHRN', $w, 'Souběh dohod u TÉHOŽ plátce.');
        self::assertStringContainsString('JINÉHO zaměstnavatele', $w, 'Souběh u jiného plátce.');
    }

    /** Základ i daň se zaokrouhlují dolů na celé koruny (§ 36 odst. 3). */
    public function testRoundsDownToWholeCrowns(): void
    {
        $r = W::compute(W::REASON_DPP, 9999.90, $this->c);

        self::assertSame(9999, $r['base']);
        self::assertSame(1499, $r['tax'], '15 % z 9 999 = 1 499,85 → dolů.');
        self::assertSame(8500, $r['net']);
    }

    /** Nulová a záporná odměna se nedaní. */
    public function testZeroOrNegativeDoesNotApply(): void
    {
        self::assertFalse(W::applies(W::REASON_DPP, 0.0, $this->c));
        self::assertFalse(W::applies(W::REASON_DPP, -100.0, $this->c));
    }

    /** Neznámý důvod se odmítne — tichý default by zdanil něco, co se zdanit nemá. */
    public function testUnknownReasonIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        W::applies('vymyslene', 5000.0, $this->c);
    }

    /** Chybějící limit v konstantách se ohlásí, místo aby se dosadila nula. */
    public function testMissingLimitInConstantsIsReported(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        W::applies(W::REASON_DPP, 5000.0, ['withholding_rate' => 0.15]);
    }

    /**
     * Limit DPP NENÍ pevných 10 000 Kč. § 6 odst. 4 ZDP odkazuje na rozhodnou částku
     * pro účast na nemocenském pojištění, a ta je podle § 7a z. 187/2006 (ve znění
     * 163/2024 Sb.) 25 % průměrné mzdy zaokrouhlených DOLŮ na celých 500 Kč.
     *
     * Konstanta byla zadrátovaná na 10 000 i pro roky, kde už neplatí — odměna
     * 11 000 Kč v roce 2025 by spadla do zálohové daně a do odvodů SP+ZP, přestože
     * se má zdanit srážkou 15 % a pojistné se z ní neodvádí.
     */
    public function testDppLimitFollowsSicknessParticipationThreshold(): void
    {
        // `inclusive` = znění § 6 odst. 4 písm. a) ZDP platné pro daný rok:
        // 2024 „nepřesáhne 10 000 Kč" (hranice včetně), od 2025 „nedosáhne
        // rozhodné částky" (hranice ostrá, zák. č. 470/2024 Sb.).
        $expected = [
            2024 => ['limit' => 10_000, 'inclusive' => true],
            2025 => ['limit' => 11_500, 'inclusive' => false],
            2026 => ['limit' => 12_000, 'inclusive' => false],
        ];

        foreach ($expected as $year => $case) {
            $limit = $case['limit'];
            $c = TaxConstants::forYear($year);
            self::assertSame($limit, (int) $c['dpp_withholding_limit'], "Rozhodná částka DPP pro rok {$year}.");
            self::assertSame(
                $case['inclusive'],
                W::applies(W::REASON_DPP, (float) $limit, $c),
                "Částka přesně na hranici — režim podle znění platného v roce {$year}.",
            );
            self::assertTrue(
                W::applies(W::REASON_DPP, $limit - 1.0, $c),
                "Koruna pod hranicí je srážková vždy ({$year}).",
            );
            self::assertFalse(
                W::applies(W::REASON_DPP, $limit + 1.0, $c),
                "Nad hranicí srážková není ({$year}).",
            );
        }
    }

    /** Odměna 11 000 Kč je od roku 2025 srážková; při starém limitu 10 000 nebyla. */
    public function testAmountBetweenOldAndNewLimitIsWithheldFrom2025(): void
    {
        self::assertFalse(W::applies(W::REASON_DPP, 11_000.0, TaxConstants::forYear(2024)));
        self::assertTrue(W::applies(W::REASON_DPP, 11_000.0, TaxConstants::forYear(2025)));
        self::assertTrue(W::applies(W::REASON_DPP, 11_000.0, TaxConstants::forYear(2026)));
    }

    /**
     * Roční konstanty si hranici pro rok 2026 už NEDRŽÍ — zrcadlí mzdový ruleset.
     * Kdyby se sem někdo vrátil s literálem, rozejdou se zase obě cesty výpočtu.
     */
    public function testTaxConstantsMirrorTheRulesetInsteadOfCopyingIt(): void
    {
        $threshold = CzechPayrollRulesets2026::provider()
            ->forDate(PayrollRulesetDomain::IncomeTax, '2026-01-01')
            ->parameters['dpp.withholding.threshold'];

        self::assertSame(
            $threshold->value,
            (int) round(((float) TaxConstants::forYear(2026)['dpp_withholding_limit']) * 100),
            'Roční konstanta musí být tatáž hodnota jako parametr rulesetu, v haléřích.',
        );
        self::assertFalse(TaxConstants::forYear(2026)['dpp_withholding_limit_inclusive']);
    }
}
