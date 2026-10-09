<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\HealthInsuranceCalculator;
use MyInvoice\Service\Tax\Return\SocialInsuranceCalculator;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;

/**
 * Jednotkové testy pojistného OSVČ (Epic DP, issue #18). Hodnoty ručně dopočtené
 * proti konstantám 2025 (soc. min hlavní 195 540, vedlejší 61 476, rozhodná 111 736;
 * zdrav. min 279 342; sazby 29,2 % / 13,5 % / nemocenské 2,7 %).
 */
final class InsuranceCalculatorTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $c;
    private SocialInsuranceCalculator $social;
    private HealthInsuranceCalculator $health;

    protected function setUp(): void
    {
        $this->c = TaxConstants::forYear(2025);
        $this->social = new SocialInsuranceCalculator();
        $this->health = new HealthInsuranceCalculator();
    }

    public function testSocialMainAboveMinimum(): void
    {
        // Zisk 500k → VZ 55 % = 275 000 (nad min). Pojistné 275 000 × 0,292 = 80 300.
        $r = $this->social->compute(500000, false, 0, false, null, $this->c);
        self::assertSame(275000.0, $r['assessment_base']);
        self::assertSame(80300.0, $r['insurance']);
        self::assertSame(80300.0, $r['balance_due']);
        self::assertTrue($r['participates']);
        // Nová měsíční záloha = ceil(80300/12) = 6692 (nad min. zálohou 4759).
        self::assertSame(6692.0, $r['monthly_advance']);
    }

    public function testSocialMainUsesMinimumBase(): void
    {
        // Nízký zisk 100k → VZ = min 195 540 (55 % = 55 000 je méně).
        // REGRESE (Fáze E nález E6): pojistné se zaokrouhluje na celé Kč NAHORU (ceil),
        // stejně jako CsszPrehledXmlBuilder → 195 540 × 0,292 = 57 097,68 → 57 098 (dřív 57 097,68).
        $r = $this->social->compute(100000, false, 0, false, null, $this->c);
        self::assertSame(195540.0, $r['assessment_base']);
        self::assertSame(57098.0, $r['insurance']);
    }

    /**
     * Zákon 90/2026 Sb. vrátil nejnižší měsíční VZ hlavní činnosti na 35 % průměrné mzdy
     * a čl. II bod 5 ho použil i pro roční minimum celého roku 2026. Přehled za 2026
     * proto nesmí počítat s původními 40 % (235 044 Kč, záloha 5 720 Kč).
     */
    public function testSocialMainMinimum2026FollowsAct90Of2026(): void
    {
        $r = $this->social->compute(0, false, 0, false, null, TaxConstants::forYear(2026));
        self::assertSame(205668.0, $r['assessment_base']);   // 17 139 × 12
        self::assertSame(60056.0, $r['insurance']);          // ceil(205 668 × 0,292)
        self::assertSame(5005.0, $r['monthly_advance']);     // ceil(17 139 × 0,292)
    }

    public function testSocialAssessmentBaseIsCappedAtFortyEightAverageWages(): void
    {
        foreach ([2025 => 2234736.0, 2026 => 2350416.0] as $year => $maximum) {
            $constants = TaxConstants::forYear($year);
            $result = $this->social->compute(10000000, false, 0, false, null, $constants);
            self::assertSame($maximum, $result['assessment_base']);
            self::assertSame(ceil($maximum * 0.292), $result['insurance']);
            self::assertSame($maximum, $result['max_base']);
        }
    }

    public function testSocialSecondaryBelowThresholdIsZero(): void
    {
        // Vedlejší, zisk 50k < rozhodná 111 736 → pojistné 0, bez účasti.
        $r = $this->social->compute(50000, true, 0, false, null, $this->c);
        self::assertFalse($r['participates']);
        self::assertSame(0.0, $r['insurance']);
        self::assertSame(0.0, $r['monthly_advance']);
        self::assertNotSame('', $r['note']);
    }

    public function testSocialSecondaryAboveThreshold(): void
    {
        // Vedlejší, zisk 150k ≥ 111 736 → VZ 82 500, pojistné 24 090.
        $r = $this->social->compute(150000, true, 0, false, null, $this->c);
        self::assertTrue($r['participates']);
        self::assertSame(82500.0, $r['assessment_base']);
        self::assertSame(24090.0, $r['insurance']);
    }

    public function testSicknessInsurance(): void
    {
        // Nemocenské z minimálního VZ 9 000 (2025+) → 9 000 × 2,7 % = 243 Kč/měs.
        $r = $this->social->compute(500000, false, 0, true, null, $this->c);
        self::assertTrue($r['sickness']['insured']);
        self::assertSame(9000.0, $r['sickness']['monthly_base']);
        self::assertSame(243.0, $r['sickness']['monthly_premium']);
        self::assertSame(2916.0, $r['sickness']['annual']);

        // Zvolený vyšší VZ 15 000 → 405 Kč/měs.
        $r2 = $this->social->compute(500000, false, 0, true, 15000, $this->c);
        self::assertSame(405.0, $r2['sickness']['monthly_premium']);
    }

    /**
     * Regrese H12 (audit 2026-07): min. měsíční VZ dobrovolného nemocenského OSVČ je
     * od 2025 9 000 Kč (2× rozhodný příjem 4 500), min. pojistné 9 000 × 2,7 % = 243 Kč/měs.
     * Platí pro 2025 i 2026 (rozhodný příjem se nezměnil).
     */
    public function testSicknessMinimumBasePerYear(): void
    {
        foreach ([2025, 2026] as $year) {
            $c = TaxConstants::forYear($year);
            self::assertSame(9000, $c['sickness_min_monthly_base'], "min VZ $year");

            // Bez zvoleného vyššího VZ se použije zákonné minimum.
            $r = $this->social->compute(500000, false, 0, true, null, $c);
            self::assertSame(9000.0, $r['sickness']['monthly_base'], "monthly_base $year");
            self::assertSame(243.0, $r['sickness']['monthly_premium'], "monthly_premium $year");
            self::assertSame(2916.0, $r['sickness']['annual'], "annual $year");

            // Podkročený zvolený VZ (5 000) se zdvihne na zákonné minimum 9 000.
            $rLow = $this->social->compute(500000, false, 0, true, 5000, $c);
            self::assertSame(9000.0, $rLow['sickness']['monthly_base'], "floor $year");
            self::assertSame(243.0, $rLow['sickness']['monthly_premium'], "floor premium $year");
        }
    }

    /**
     * E6 (audit 2026-07): kalkulátor pojistného (PDF přehled) a CsszPrehledXmlBuilder (ČSSZ XML)
     * musí dávat STEJNÉ číslo — oba počítají VZ i pojistné ceil na celé Kč z celokorunového
     * daňového základu §7. Ověřeno replikací derivace XML builderu.
     */
    public function testSocialInsuranceCeilMatchesCsszXmlDerivation(): void
    {
        $taxBase = 100000; // 55 % = 55 000 < min 195 540 → VZ = min
        $r = $this->social->compute($taxBase, false, 0, false, null, $this->c);
        // Derivace CsszPrehledXmlBuilder::socialInts:
        $pri = (int) round($taxBase);
        $vvz = (int) ceil($pri * 0.55);
        $mvz = (int) ceil(195540.0);
        $uvz = max($vvz, $mvz);
        $poj = (int) ceil($uvz * 0.292);
        self::assertSame((float) $uvz, $r['assessment_base'], 'Určený VZ shodný s XML.');
        self::assertSame((float) $poj, $r['insurance'], 'Pojistné shodné s XML (PDF == ČSSZ XML).');
    }

    public function testSocialAdvancesReducesBalance(): void
    {
        $r = $this->social->compute(500000, false, 60000, false, null, $this->c);
        self::assertSame(80300.0 - 60000.0, $r['balance_due']);
    }

    public function testHealthMainUsesMinimum(): void
    {
        // Zisk 500k → 50 % = 250 000 < min 279 342 → VZ = 279 342.
        // REGRESE (Fáze E nález E6): pojistné ceil na celé Kč → 279 342 × 0,135 = 37 711,17 → 37 712.
        $r = $this->health->compute(500000, false, 0, $this->c);
        self::assertSame(279342.0, $r['assessment_base']);
        self::assertSame(37712.0, $r['insurance']);
        self::assertTrue($r['min_applies']);
        // Min. měsíční záloha ceil(279342/12 × 0,135) = 3143.
        self::assertSame(3143.0, $r['monthly_advance']);
    }

    public function testHealthMainAboveMinimum(): void
    {
        // Zisk 800k → 50 % = 400 000 > min → VZ 400 000, pojistné 54 000.
        $r = $this->health->compute(800000, false, 0, $this->c);
        self::assertSame(400000.0, $r['assessment_base']);
        self::assertSame(54000.0, $r['insurance']);
    }

    public function testHealthSecondaryNoMinimum(): void
    {
        // Vedlejší (souběh se zaměstnáním) → min se neuplatní, VZ = 50 000, pojistné 6 750.
        $r = $this->health->compute(100000, true, 0, $this->c);
        self::assertFalse($r['min_applies']);
        self::assertSame(50000.0, $r['assessment_base']);
        self::assertSame(6750.0, $r['insurance']);
    }
}
