<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Absence\ProbableEarningBulkService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Čtyři brigádníci na DPP za duben 2026, výsledky přepočtené ručně podle
 * zákona a sady 2026 ({@see \MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026}):
 *
 *  - rozhodný příjem DPP 12 000 Kč (§ 7 odst. 1 písm. d ZNP), pod ním bez
 *    pojistného; bez prohlášení pod ním srážková daň 15 % (§ 6 odst. 4,
 *    § 36 odst. 2 písm. l ZDP), srážka se zaokrouhluje na celé koruny dolů
 *    (§ 36 odst. 3 ZDP),
 *  - nad ním SP zaměstnance 7,1 %, ZP 13,5 % (zaměstnanec třetina, celé
 *    koruny nahoru), záloha 15 % ze základu zaokrouhleného na celé koruny
 *    nahoru (§ 38h odst. 1 ZDP), sleva na poplatníka 2 570 Kč měsíčně jen
 *    s podepsaným prohlášením (§ 35ba, § 35d ZDP),
 *  - průměr pro JMHZ 10345 = pravděpodobný výdělek nastavený hromadně
 *    (nahrazuje dřív schválený průměr).
 *
 * Očekávané hodnoty v testu jsou z ručního přepočtu, ne opsané z výstupu.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eNavDppManualCalculationTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-04';
    private const PERIOD_START = '2026-04-01';
    private const PAYDAY = '2026-05-15';
    private const WORKED = ['2026-04-04', '2026-04-11', '2026-04-18'];

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testFourAgreementsMatchManualCalculationAndProbableEarningReachesJmhz10345(): void
    {
        $officeId = $this->createOffice('NAVC', 'Syntetická účtárna přepočtu', '1100001237');
        $this->configureSocialInsuranceOutput($officeId);
        $this->configureHealthInsuranceOutput();
        $this->configureIncomeTaxOutput();
        $componentId = $this->createComponent('ODMENA_DPP_NAVC', 'base_wage', 'regular');
        $this->container->get(PayrollComponentJmhzMappingRepository::class)
            ->put($this->supplierId, $componentId, '10329', null, $this->actors[0]);

        $people = [
            'A' => $this->hire($officeId, $componentId, 1, false, 360_100),
            'B' => $this->hire($officeId, $componentId, 2, true, 360_000),
            'C' => $this->hire($officeId, $componentId, 3, false, 1_500_000),
            'D' => $this->hire($officeId, $componentId, 4, true, 1_500_000),
        ];
        $ids = array_column($people, 'employment_id');
        $probable = $this->container->get(ProbableEarningBulkService::class);
        // Nejdřív 120 Kč, pak oprava na 150 Kč: nová revize průměru nahradí schválenou.
        // 120 Kč je pod minimální mzdou 134,40 Kč/h (2026): průměr se podle
        // § 357 odst. 1 ZP zvýší na ni a účetní se to musí dozvědět.
        $first = $probable->apply($this->supplierId, 2026, 2, 12_000, 'Původní sazba 120 Kč za hodinu.', $ids, $this->actors[0], null, null);
        self::assertSame(4, $first['counts']['applied']);
        foreach ($first['applied'] as $row) {
            self::assertContains('raised_to_minimum_wage', array_column($row['warnings'], 'code'), json_encode($row, JSON_UNESCAPED_UNICODE));
        }
        // Opakované odeslání téže částky nic nezakládá, i když se průměr zvýšil.
        $again = $probable->apply($this->supplierId, 2026, 2, 12_000, 'Původní sazba 120 Kč za hodinu.', $ids, $this->actors[0], null, null);
        self::assertSame(['applied' => 0, 'unchanged' => 4, 'failed' => 0], $again['counts']);
        $replaced = $probable->apply($this->supplierId, 2026, 2, 15_000, 'Sjednaná sazba 150 Kč za hodinu.', $ids, $this->actors[0], null, null);
        self::assertSame(4, $replaced['counts']['applied']);
        self::assertSame([13_440], array_values(array_unique(array_column($replaced['applied'], 'superseded_average_hourly_minor'))));

        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $officeId, 'navc');
        self::assertSame([], $run['blockers']);
        self::assertSame([], $run['warnings']);
        $revisionId = (int) $run['approved']->revision['id'];

        // [hrubá, SP zaměstnanec, ZP zaměstnanec, záloha, srážka, k výplatě] v haléřích
        $expected = [
            // A: 3 601 Kč < 12 000, bez prohlášení: srážka 15 % z 3 601 = 540,15 → 540 (dolů). Čistá 3 061.
            'A' => [360_100, 0, 0, 0, 54_000, 306_100],
            // B: 3 600 Kč < 12 000, prohlášení: záloha 15 % z 3 600 = 540 − sleva 2 570 → 0. Čistá 3 600.
            'B' => [360_000, 0, 0, 0, 0, 360_000],
            // C: 15 000 Kč ≥ 12 000: SP 7,1 % = 1 065; ZP 13,5 % = 2 025, zaměstnanec třetina 675;
            //    záloha 15 % z 15 000 = 2 250, bez slevy. Čistá 15 000 − 1 065 − 675 − 2 250 = 11 010.
            'C' => [1_500_000, 106_500, 67_500, 225_000, 0, 1_101_000],
            // D: jako C, sleva 2 570 > záloha 2 250 → 0 (DPP bez bonusu). Čistá 15 000 − 1 065 − 675 = 13 260.
            'D' => [1_500_000, 106_500, 67_500, 0, 0, 1_326_000],
        ];
        foreach ($expected as $key => [$gross, $social, $health, $advance, $withholding, $net]) {
            $result = $this->netResult($revisionId, $people[$key]['employee_id']);
            self::assertSame(
                [$gross, $social, $health, $advance, $withholding, $net],
                [
                    (int) ($result['cash_income_minor_units'] ?? -1),
                    (int) ($result['employee_social_minor_units'] ?? -1),
                    (int) ($result['employee_health_minor_units'] ?? -1),
                    (int) ($result['advance_tax_minor_units'] ?? -1),
                    (int) ($result['withholding_tax_minor_units'] ?? -1),
                    (int) ($result['net_payable_minor_units'] ?? -1),
                ],
                "Osoba {$key} se liší od ručního přepočtu.",
            );
        }

        $preparation = $this->prepareJmhz($revisionId, 'navc-prepare');
        self::assertSame('source_ready', $preparation['body']['readiness_status'] ?? null, json_encode($preparation['body']['issues'] ?? null));
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $officeId);
        self::assertSame('dry_run_valid', $tested['body']['status'] ?? null, json_encode($tested['body']['blockers'] ?? null));
        self::assertSame(0, $tested['body']['controls']['counts']['failed']);
        $xml = (string) preg_replace('/>\s+</', '><', (string) $tested['body']['xml']);
        // 10345 = schválený pravděpodobný výdělek po nahrazení, u všech čtyř.
        self::assertSame(4, substr_count($xml, '<form:vydelekPrumernyHod>150.00</form:vydelekPrumernyHod>'));
        self::assertStringNotContainsString('<form:vydelekPrumernyHod>120.00</form:vydelekPrumernyHod>', $xml);
        // Srážková daň osoby A: základ 3 601, sražená daň 540.
        self::assertStringContainsString('<form:zvlastniSazbaDane><form:zakladDane>3601</form:zakladDane><form:srazenaDan>540</form:srazenaDan>', $xml);
    }

    /** @return array<string,mixed> */
    private function netResult(int $revisionId, int $employeeId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT person.result_snapshot_json
               FROM payroll_statutory_person_results person
               JOIN payroll_statutory_results result
                 ON result.supplier_id = person.supplier_id AND result.id = person.statutory_result_id
              WHERE result.supplier_id = ? AND result.revision_id = ? AND result.calculation_kind = "net_pay"',
        );
        $statement->execute([$this->supplierId, $revisionId]);
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $json) {
            $row = json_decode((string) $json, true);
            if (is_array($row) && in_array("employee:{$employeeId}", [$row['person_reference'] ?? null, $row['person_id'] ?? null], true)) {
                return $row;
            }
        }
        self::fail("Chybí výsledek čisté mzdy osoby {$employeeId}.");
    }

    /** @return array{employee_id:int,employment_id:int,name:string} */
    private function hire(int $officeId, int $componentId, int $seq, bool $declaration, int $amountMinor): array
    {
        $female = $seq % 2 === 0;
        $name = sprintf('%s Přepočtený%s %02d', $female ? 'Syntetická' : 'Syntetický', $female ? 'á' : '', $seq);
        $person = $this->createEmployment($officeId, $name, $seq, 'dpp', 'dpp', 10, 2_500, $declaration, self::PERIOD_START);
        $birthDate = sprintf('19%02d-%02d-%02d', 85 + $seq, 2 + $seq, 3 + $seq);
        [$first, $last] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, null, [
            'first_name' => $first,
            'last_name' => $last,
            'birth_date' => $birthDate,
            'sex' => $female ? 'female' : 'male',
            'birth_number' => self::syntheticBirthNumber($birthDate, $female ? 'female' : 'male', $seq),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic($seq), sprintf('2%020d', $seq));
        $this->publishShifts($person['employment_id'], self::WORKED);
        $time = $this->approveTimeMonth($person['employment_id'], self::PERIOD, self::WORKED, dailyMinutes: 240);
        self::assertSame(200, $time->getStatusCode(), (string) $time->getBody());
        $this->createApprovedInput($person, $componentId, $amountMinor, 'navc-' . $person['employment_id'], self::PERIOD_START);

        return $person;
    }
}
