<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Benefity se zákonným limitem v haléřích a jejich vykázání v měsíčním
 * hlášení (dříve otevřené nálezy C3 až C6 scénářů HPP).
 *
 * Pravidla vykázání drží {@see \MyInvoice\Service\Payroll\Component\PayrollWholeCrowns}:
 * osvobozená část 10289 dolů na koruny, základy 10297, 10535, 10476 a čistý
 * příjem 10344 nahoru (§ 38h odst. 1 ZDP, § 5d zák. č. 589/1992 Sb.), úhrn
 * 10286 = zdanitelná část + vykázaná osvobozená část.
 *
 * Ruční přepočty (sada 2026): SP zaměstnanec 7,1 % z vyměřovacího základu
 * zaokrouhleného nahoru (§ 5d), zaměstnavatel 24,8 %; ZP 13,5 % nahoru,
 * zaměstnanec třetina nahoru; záloha 15 % ze základu na stokoruny nahoru,
 * sleva na poplatníka 2 570 Kč. Duben 2026 má 20 směn.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eFix4BenefitTest extends TestCase
{
    use PayrollE2eHpp2ComponentFlowTrait;

    protected function setUp(): void
    {
        $this->bootComponentFlow();
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * Stravenkový paušál 150 Kč × 20 směn = 3 000 Kč, osvobozeno 20 × 129,50
     * = 2 590 Kč, 410 Kč zdaněno. Osvobozené příjmy 10289 jsou jen osvobozená
     * část (pokyny 1.4.13: „úhrn osvobozených příjmů“): základ zálohy 10297
     * = 40 410 = 10286 (43 000) − 10289 (2 590). Dřív 10289 = 3 000.
     */
    public function testMealAllowanceExcessIsNotReportedAsExemptIncome(): void
    {
        $person = $this->hire('Syntetická Strávnice', 'female', '1992-06-21');
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->input($person, 'MZDA_MESICNI', 4_000_000, 'base');
        $this->input($person, 'PRISPEVEK_STRAVOVANI', 300_000, 'stravne');

        $run = $this->approvedRun('fix4-meal');
        $xml = $this->dryRunXml($run['revision_id'], 'fix4-meal');

        self::assertStringContainsString('<form:zuctovanoCelkem>43000</form:zuctovanoCelkem>', $xml);
        self::assertStringContainsString('<form:zakladDane>40410</form:zakladDane>', $xml);
        self::assertStringContainsString('<form:osvobozenoCelkem>2590</form:osvobozenoCelkem>', $xml);
    }
}
