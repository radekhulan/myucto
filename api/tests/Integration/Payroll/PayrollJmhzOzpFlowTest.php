<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Repository\Payroll\PayrollDeductionAgreementRepository;
use MyInvoice\Repository\Payroll\PayrollRegzelRepository;
use MyInvoice\Service\Payroll\Net\DeductionAgreementStatus;
use MyInvoice\Service\Payroll\Net\DeductionAgreementTerms;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzOrdinaryEvidenceService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Zaměstnanec s podporou zaměstnávání OZP u zaměstnavatele uznaného na
 * chráněném trhu práce (IN28) a srážka ve prospěch zaměstnavatele (IN24).
 *
 * Pokyny MPSV k MH 1.4.14, kap. 2.4.14 a 2.4.15: 10347 až 10349 jsou povinné
 * v rámci IN28, 10350 až 10353 v rámci IN24 (10349 = ANO). Datum úhrady 10347
 * zadává účetní až po úhradě (FAQ MPSV k webináři 11. 5. 2026, dotaz 12),
 * srážky se odvodí ze schválené revize podle zařazení dohody. Dřív příznak
 * vztahu přípravu jen zastavil (`monthly_exception_required`).
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollJmhzOzpFlowTest extends TestCase
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
        $this->officeId = $this->createOffice('JMHZOZP', 'Syntetická registrace JMHZ', '1100004322');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->baseComponentId = $this->createComponent('MZDA_OZP_FLOW', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $mappings);
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
        $regzel = $this->container->get(PayrollRegzelRepository::class);
        self::assertInstanceOf(PayrollRegzelRepository::class, $regzel);
        $regzel->saveProfile($this->supplierId, false, false, true, '2000', '2001', null, $this->actors[0], 0);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testOzpWageAndEmployerDeductionReachTheMonthlyReport(): void
    {
        $person = $this->createEmployment(
            $this->officeId,
            'Olga Chráněná',
            1,
            'hpp',
            'employment',
            40,
            10_000,
            true,
            self::PERIOD_START,
        );
        $this->completeJmhzEmployment($person, null, [
            'first_name' => 'Olga',
            'last_name' => 'Chráněná',
            'birth_date' => '1990-05-05',
            'sex' => 'female',
            'birth_number' => self::syntheticBirthNumber('1990-05-05', 'female', 1),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic(1), sprintf('2%020d', 1));
        $this->publishShifts($person['employment_id'], self::workdays(self::PERIOD));
        $this->createApprovedAverage($person['employment_id'], 3);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms SET jmhz_ozp_employment_support_applies = 1
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$this->supplierId, $person['employment_id']]);
        $agreements = $this->container->get(PayrollDeductionAgreementRepository::class);
        self::assertInstanceOf(PayrollDeductionAgreementRepository::class, $agreements);
        $agreements->create(
            $this->supplierId,
            $person['employee_id'],
            DeductionAgreementTerms::fromRequest([
                'title' => 'Syntetické obědy v kantýně',
                'deduction_kind' => 'meal',
                'priority_no' => 100,
                'requested_minor' => 30_000,
                'valid_from' => '2026-01-01',
                'delivered_on' => '2026-01-01',
                'creditor_is_employer' => true,
                'jmhz_target' => '10352',
            ]),
            DeductionAgreementStatus::Active,
            $this->actors[0],
        );
        $response = $this->approveTimeMonth($person['employment_id'], self::PERIOD, self::workdays(self::PERIOD));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->createApprovedInput($person, $this->baseComponentId, 4_000_000, 'ozp-' . $person['employment_id'], self::PERIOD_START);

        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, 'ozp-flow');
        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet mzdy. ' . CanonicalJson::encode($run['blockers']));
        self::assertNotNull($run['approved']);
        $revisionId = (int) $run['approved']->revision['id'];

        // Bez data úhrady se výdělek OZP nesestaví: příprava zůstane blokovaná
        // pojmenovaným nálezem na vztahu, datum se nedomýšlí.
        $blocked = $this->prepareJmhz($revisionId, 'ozp-flow-blocked');
        self::assertSame('blocked', $blocked['body']['readiness_status'], CanonicalJson::encode($blocked['body']));
        self::assertContains(
            'jmhz_ordinary_evidence_ozp_wage_required',
            array_column($blocked['body']['issues'] ?? [], 'code'),
        );

        $evidence = $this->container->get(JmhzOrdinaryEvidenceService::class);
        self::assertInstanceOf(JmhzOrdinaryEvidenceService::class, $evidence);
        $confirmed = $evidence->confirm(
            $this->supplierId,
            $revisionId,
            $person['employment_id'],
            [
                'reportable_wage_deductions_recorded' => false,
                'employee_social_discount_claimed' => false,
                'specific_legal_fact_occurred' => false,
                'ozp_employment_support_claimed' => true,
                'deep_mining_work_occurred' => false,
            ],
            'ozp-flow-confirm',
            $this->actors[0],
            null,
            null,
            'explicit_confirmation',
            ['payment_date' => self::PAYDAY, 'cash_paid' => false],
        );
        self::assertSame([10352 => 30_000], $confirmed['ozp_wage']['employer_deductions_minor']);

        $preparation = $this->prepareJmhz($revisionId, 'ozp-flow-ready');
        self::assertSame(201, $preparation['status'], CanonicalJson::encode($preparation['body']));
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
        $xml = (string) preg_replace('/>\s+</', '><', (string) $tested['body']['xml']);

        self::assertStringContainsString(
            '<form:vydelekOZP><form:mzdaOzpUhradaDatum>2026-08-14</form:mzdaOzpUhradaDatum>'
                . '<form:mzdaOzpHotovost>false</form:mzdaOzpHotovost>'
                . '<form:mzdaOzpSrazka>true</form:mzdaOzpSrazka></form:vydelekOZP>'
                . '<form:srazky><form:srazkaStrava>300</form:srazkaStrava></form:srazky>',
            $xml,
        );
    }
}
