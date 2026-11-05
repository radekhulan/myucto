<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollEmploymentSurchargePolicyService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Odměna za pracovní pohotovost (§ 140 ZP) od směny po měsíční hlášení.
 *
 * Účetní zadá u dvou směn čtyři hodiny pohotovosti, schválí docházku a spustí
 * mzdu. Ze schválení vznikne mzdový vstup ODMENA_POHOTOVOST a měsíční hlášení
 * ho ukáže v bloku `odmeny/pohotovost` (10343). XML musí projít XSD
 * i katalogem kontrol.
 *
 * Průměr je 120 000 Kč / 160 h = 750 Kč/h; 8 h pohotovosti × 750 × 10 % = 600 Kč.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollStandbyFullFlowTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-07';
    private const PERIOD_START = '2026-07-01';
    private const PAYDAY = '2026-08-14';

    private int $officeId;
    private int $baseComponentId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('JMHZ', 'Syntetická registrace JMHZ', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->baseComponentId = $this->createComponent('MZDA_MESICNI_POHOTOVOST', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        if (!$mappings instanceof PayrollComponentJmhzMappingRepository) {
            throw new \RuntimeException('Mapování mzdových složek JMHZ není dostupné.');
        }
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testManualSummaryDoesNotPayArchivedStandbyThroughDirectService(): void
    {
        $person = $this->hire('Syntetická Pohotovost', 'female', '1988-03-14');
        $this->standby($person['employment_id'], ['2026-07-07', '2026-07-09'], 240);
        $this->db->pdo()->prepare('INSERT INTO payroll_time_months (supplier_id, employment_id, period_start, work_source, status, approved_at)
            VALUES (?, ?, ?, "manual_summary", "approved", NOW())
            ON DUPLICATE KEY UPDATE work_source = "manual_summary", status = "approved", approved_at = NOW()')
            ->execute([$this->supplierId, $person['employment_id'], self::PERIOD_START]);
        $service = $this->container->get(\MyInvoice\Service\Payroll\Time\Surcharge\PayrollStandbyInputMaterializer::class);
        $result = $service->materialize($this->supplierId, $person['employment_id'], self::PERIOD_START, $this->actors[0]);
        self::assertFalse($result['written']);
        self::assertSame('manual_summary', $result['skipped_reason']);
        self::assertTrue($result['requires_manual_review']);
        self::assertNull($result['minutes']);
        self::assertNull($result['amount_minor']);
        self::assertSame(0, $this->standbyTotal($person['employment_id']));
        try {
            $service->calculate($this->supplierId, $person['employment_id'], self::PERIOD_START);
            self::fail('Ruční souhrn neprokazuje nulovou ani kladnou pohotovost.');
        } catch (\MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeException $exception) {
            self::assertSame('manual_summary_standby_unavailable', $exception->reason);
        }
    }

    public function testStandbyMinutesReachPayAndJmhz10343(): void
    {
        $person = $this->hire('Pavla Pohotová', 'female', '1988-03-14');
        $this->standby($person['employment_id'], ['2026-07-07', '2026-07-09'], 240);

        $this->approveMonth($person['employment_id']);
        self::assertSame(60_000, $this->standbyTotal($person['employment_id']));

        // Znovu otevřený a beze změny schválený měsíc nesmí odměnu zdvojit.
        $this->reopenMonth($person['employment_id']);
        $this->approveMonth($person['employment_id'], []);
        self::assertSame(60_000, $this->standbyTotal($person['employment_id']));

        $this->pay($person, 4_500_000);
        $xml = $this->submission('statutory');

        self::assertStringContainsString('<form:odmeny><form:pohotovost>600</form:pohotovost></form:odmeny>', $xml);
    }

    public function testAgreedHigherRateAppliesAndLowerIsRejected(): void
    {
        $person = $this->hire('Petr Pohotový', 'male', '1984-11-02');
        $policies = $this->container->get(PayrollEmploymentSurchargePolicyService::class);
        self::assertInstanceOf(PayrollEmploymentSurchargePolicyService::class, $policies);
        try {
            $policies->save($this->supplierId, $person['employment_id'], $this->policyInput(500), $this->actors[0]);
            self::fail('Sazba pod 10 % průměrného výdělku musí být odmítnuta.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('§ 140', $exception->getMessage());
        }
        $policies->save($this->supplierId, $person['employment_id'], $this->policyInput(2_000), $this->actors[0]);

        $this->standby($person['employment_id'], ['2026-07-07', '2026-07-09'], 240);
        $this->approveMonth($person['employment_id']);

        // 8 h × 750 Kč × 20 % = 1 200 Kč.
        self::assertSame(120_000, $this->standbyTotal($person['employment_id']));
    }

    /** @return array<string,mixed> */
    private function policyInput(int $standbyBasisPoints): array
    {
        return [
            'valid_from' => self::PERIOD_START,
            'overtime_mode' => 'surcharge',
            'holiday_mode' => 'surcharge',
            'standby_rate_bp' => $standbyBasisPoints,
        ];
    }

    /** @param list<string> $dates */
    private function standby(int $employmentId, array $dates, int $minutes): void
    {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_shifts SET standby_minutes = ?
              WHERE supplier_id = ? AND employment_id = ? AND series_key = ?'
        );
        foreach ($dates as $date) {
            $statement->execute([$minutes, $this->supplierId, $employmentId, "flow-{$employmentId}-{$date}"]);
            self::assertSame(1, $statement->rowCount());
        }
    }

    private function standbyTotal(int $employmentId): int
    {
        return (int) $this->scalar(
            'SELECT COALESCE(SUM(input.amount_minor), 0)
               FROM payroll_inputs input
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id AND component.id = input.component_id
              WHERE input.supplier_id = ? AND input.employment_id = ?
                AND component.code = "ODMENA_POHOTOVOST" AND input.status IN ("approved", "locked")',
            [$this->supplierId, $employmentId],
        );
    }

    private function reopenMonth(int $employmentId): void
    {
        $response = $this->time->reopen(
            $this->request('POST', '/api/payroll/time/months/' . self::PERIOD . '/reopen')->withParsedBody([
                'employment_id' => $employmentId,
                'row_version' => $this->timeMonthRowVersion($employmentId, self::PERIOD),
                'reason' => 'Syntetická kontrola opakovaného schválení.',
            ]),
            new \Slim\Psr7\Response(),
            ['period' => self::PERIOD],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    /** @return array{employee_id:int,employment_id:int,name:string,average_id:?int,sequence:int} */
    private function hire(string $name, string $sex, string $birthDate): array
    {
        $sequence = ++$this->sequence;
        $person = $this->createEmployment(
            $this->officeId,
            $name,
            $sequence,
            'hpp',
            'employment',
            40,
            10_000,
            true,
            self::PERIOD_START,
        );
        [$firstName, $lastName] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => $birthDate,
            'sex' => $sex,
            'birth_number' => self::syntheticBirthNumber($birthDate, $sex, $sequence),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), sprintf('2%020d', $sequence));
        $this->publishShifts($person['employment_id'], self::workdays(self::PERIOD));
        $average = $this->createApprovedAverage($person['employment_id'], 3);

        return $person + ['average_id' => (int) $average['id'], 'sequence' => $sequence];
    }

    /** @param list<string>|null $workedDates */
    private function approveMonth(int $employmentId, ?array $workedDates = null): void
    {
        $response = $this->approveTimeMonth(
            $employmentId,
            self::PERIOD,
            $workedDates ?? self::workdays(self::PERIOD),
        );
        self::assertSame(200, $response->getStatusCode(), 'Zaseknutí: schválení docházky. ' . (string) $response->getBody());
    }

    /** @param array{employee_id:int,employment_id:int,name:string} $person */
    private function pay(array $person, int $amountMinor): void
    {
        $this->createApprovedInput($person, $this->baseComponentId, $amountMinor, 'base-' . $person['employment_id'], self::PERIOD_START);
    }

    private function submission(string $scenario): string
    {
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, "standby-{$scenario}");
        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet mzdy. ' . CanonicalJson::encode($run['blockers']));
        self::assertNotNull($run['approved']);
        $revisionId = (int) $run['approved']->revision['id'];

        $preparation = $this->prepareJmhz($revisionId, "standby-{$scenario}");
        self::assertSame(201, $preparation['status'], 'Zaseknutí: příprava hlášení. ' . CanonicalJson::encode($preparation['body']));
        self::assertSame(
            'source_ready',
            $preparation['body']['readiness_status'],
            'Zaseknutí: příprava hlášení. ' . CanonicalJson::encode($preparation['body']['issues'] ?? []),
        );
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame(200, $tested['status'], 'Zaseknutí: sestavení XML. ' . CanonicalJson::encode($tested['body']));
        self::assertSame(
            'dry_run_valid',
            $tested['body']['status'],
            'Zaseknutí: XSD nebo kontroly. ' . CanonicalJson::encode($tested['body']['controls'] ?? $tested['body']),
        );

        return (string) preg_replace('/>\s+</', '><', (string) $tested['body']['xml']);
    }
}
