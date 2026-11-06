<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlContext;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlOutcome;
use MyInvoice\Tests\Support\JmhzControlValidatorFactory;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Odečítané doby po dovršení důchodového věku (JMHZ 10375 a 10462–10469,
 * interakce IN04) celým tokem: mzdový běh, příprava, XML a kontroly.
 *
 * Pokyny MH 1.4.14, kap. 3.6.4: pojištěnec, který po 31. 12. 2024 nepobírá
 * starobní důchod a je výdělečně činný po dovršení důchodového věku, vykazuje
 * dny neplaceného volna a neomluvené absence jako odečítané doby a dny
 * pojištění (10356) jsou interval minus odečtené doby (kontrola 309).
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollJmhzDeductedDaysFlowTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-07';
    private const PERIOD_START = '2026-07-01';
    private const PAYDAY = '2026-08-14';

    private int $officeId;
    private int $baseComponentId;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('ODEC', 'Syntetická účtárna odečítaných dob', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->configureIncomeTaxOutput();
        $this->baseComponentId = $this->createComponent('MZDA_ODECITANE_FLOW', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $mappings);
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * Pracující senior bez důchodu: neplacené volno 8.–10. 7. (3 dny)
     * a neomluvená absence 21. 7. (1 den). 10375 = 4, 10468 = 3, 10469 = 1,
     * 10356 = 31 − 4 = 27; hlášení projde XSD i kontrolami 59, 98, 135, 307,
     * 309 a 328.
     */
    public function testWorkingSeniorWithUnpaidLeaveAndUnexcusedAbsenceReportsDeductedDays(): void
    {
        $sequence = 1;
        $birthDate = '1958-03-14';
        $person = $this->createEmployment(
            $this->officeId,
            'Syntetický Senior',
            $sequence,
            'hpp',
            'employment',
            40,
            10_000,
            true,
            self::PERIOD_START,
            healthTopUpResponsibility: 'employee',
        );
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => 'Syntetický',
            'last_name' => 'Senior',
            'birth_date' => $birthDate,
            'sex' => 'male',
            'birth_number' => self::syntheticBirthNumber($birthDate, 'male', $sequence),
        ]);
        $this->db->pdo()->prepare('UPDATE payroll_employees SET birth_date = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$birthDate, $this->supplierId, $person['employee_id']]);
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), sprintf('5%020d', $sequence));
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_pension_age (supplier_id, employee_id, basis, effective_from)
             VALUES (?, ?, "statutory_table", "2024-09-14")',
        )->execute([$this->supplierId, $person['employee_id']]);

        $workdays = self::workdays(self::PERIOD);
        $this->publishShifts($person['employment_id'], $workdays);
        $averageId = (int) $this->createApprovedAverage($person['employment_id'], 3)['id'];
        $this->createApprovedAbsence($person['employment_id'], 'unpaid_leave', '2026-07-08', '2026-07-10', $averageId);
        $this->createApprovedAbsence($person['employment_id'], 'unexcused', '2026-07-21', '2026-07-21', $averageId);
        $absent = ['2026-07-08', '2026-07-09', '2026-07-10', '2026-07-21'];
        $time = $this->approveTimeMonth(
            $person['employment_id'],
            self::PERIOD,
            array_values(array_filter($workdays, static fn (string $day): bool => !in_array($day, $absent, true))),
        );
        self::assertSame(200, $time->getStatusCode(), (string) $time->getBody());
        $this->createApprovedInput($person, $this->baseComponentId, 4_000_000, 'base-' . $person['employment_id'], self::PERIOD_START);

        $xml = $this->submission('senior');

        self::assertStringContainsString('<form:kod>1D+</form:kod>', $xml);
        self::assertStringContainsString('<form:pocetDnu>27</form:pocetDnu>', $xml);
        self::assertStringContainsString(
            '<form:odecitaneDny><form:odecitaneDobyCelkem>4</form:odecitaneDobyCelkem>'
                . '<form:neplaceneVolno>3</form:neplaceneVolno>'
                . '<form:neomluveneAbsence>1</form:neomluveneAbsence></form:odecitaneDny>',
            $xml,
        );
        $report = JmhzControlValidatorFactory::create()
            ->validate($xml, new JmhzControlContext('2026-08-14', schemaValidated: true));
        $evaluated = [];
        $failed = [];
        foreach ($report->findings as $finding) {
            if (!in_array($finding->controlId, [59, 98, 135, 307, 309, 328], true)) {
                continue;
            }
            $evaluated[$finding->controlId] = true;
            if ($finding->outcome === JmhzControlOutcome::Failed) {
                $failed[] = $finding->controlId;
            }
        }
        ksort($evaluated);
        self::assertSame([59, 98, 135, 307, 309, 328], array_keys($evaluated));
        self::assertSame([], $failed);
    }

    private function submission(string $scenario): string
    {
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, "odec-{$scenario}");
        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet mzdy. ' . CanonicalJson::encode($run['blockers']));
        self::assertSame([], $run['warnings'], 'Zaseknutí: varování běhu. ' . CanonicalJson::encode($run['warnings']));
        self::assertNotNull($run['approved']);
        $revisionId = (int) $run['approved']->revision['id'];

        $preparation = $this->prepareJmhz($revisionId, "odec-{$scenario}");
        self::assertSame(201, $preparation['status'], 'Zaseknutí: příprava. ' . CanonicalJson::encode($preparation['body']));
        self::assertSame(
            'source_ready',
            $preparation['body']['readiness_status'],
            'Zaseknutí: příprava. ' . CanonicalJson::encode($preparation['body']['issues'] ?? []),
        );
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame(200, $tested['status'], CanonicalJson::encode($tested['body']));
        self::assertSame(
            'dry_run_valid',
            $tested['body']['status'],
            'Zaseknutí: XSD nebo kontroly. ' . CanonicalJson::encode($tested['body']['controls'] ?? $tested['body']),
        );

        return (string) preg_replace('/>\s+</', '><', (string) $tested['body']['xml']);
    }
}
