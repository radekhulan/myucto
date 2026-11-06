<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollLeaveRepository;
use MyInvoice\Service\Payroll\Component\PayrollComponentJmhzMappingDefaults;
use MyInvoice\Service\Payroll\Termination\PayrollEmploymentTerminationService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Skončení vztahu: jediný zdroj důvodu, vyrovnání dovolené, odstupné a úmrtí.
 *
 * Syntetický vztah 1. 4. 2022 – 31. 7. 2026, 40 h týdně, schválený průměr
 * 250 Kč/h za 3. čtvrtletí 2026. Průměrný měsíční výdělek § 356 odst. 2:
 * 250 × 40 × 4,348 = 43 480 Kč.
 *
 * Dřív náhradu za nevyčerpanou dovolenou nešlo zadat vůbec (složka
 * NAHRADA_MZDY_DOVOLENA je ručně zakázaná) a obchvat přes obecnou náhradu
 * mzdy šel v JMHZ do 10337 místo 10338; odstupné se počítalo ručně.
 */
#[Group('integration')]
final class PayrollEmploymentTerminationServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const MONTHLY_AVERAGE = 4_348_000;

    private Connection $db;
    private PayrollEmploymentTerminationService $service;
    private PayrollLeaveRepository $leave;
    private int $supplierId;
    private int $employeeId;
    private int $employmentId;
    private int $userId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $db = $container->get(Connection::class);
        if (!$db instanceof Connection) {
            throw new \RuntimeException('Databázové spojení není dostupné.');
        }
        $this->db = $db;
        if (!$db->hasTable('payroll_employment_terminations') || !$db->hasTable('payroll_employment_survivors')) {
            self::markTestSkipped('Migrace 1909/1910 neproběhly.');
        }
        $service = $container->get(PayrollEmploymentTerminationService::class);
        $leave = $container->get(PayrollLeaveRepository::class);
        if (!$service instanceof PayrollEmploymentTerminationService || !$leave instanceof PayrollLeaveRepository) {
            throw new \RuntimeException('Služby skončení nejsou dostupné.');
        }
        $this->service = $service;
        $this->leave = $leave;
        $pdo = $db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')?->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')?->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_module_state (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "setup", "2026-01-01", ?, NOW())',
        )->execute([$this->supplierId, $this->userId]);
        [$this->employeeId, $this->employmentId] = $this->employment('SKON-1', '2022-04-01', '2026-07-31');
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testOneRecordFeedsA2AndSeverance(): void
    {
        $this->averageFor($this->employmentId);

        $overview = $this->service->save($this->supplierId, $this->employmentId, [
            'termination_method' => 'agreement',
            'legal_ground' => 'organizational',
        ], $this->userId);

        self::assertSame('4', $overview['derived']['regzec_reason_code']);
        self::assertSame('organizational', $overview['derived']['unemployment_office_kind']);
        self::assertSame(self::MONTHLY_AVERAGE, $overview['average']['monthly_gross_minor']);
        self::assertSame('ready', $overview['severance']['state']);
        self::assertSame(3, $overview['severance']['multiple'], 'Poměr trval přes dva roky.');
        self::assertSame(3 * self::MONTHLY_AVERAGE, $overview['severance']['amount_minor']);
        $a2 = $overview['a2_prefill']['unemployment'];
        self::assertSame('4', $a2['termination_reason']);
        self::assertTrue($a2['entitlement']);
        self::assertSame('golden_handshake', $a2['settlement_kind']);
        self::assertSame('130440', $a2['settlement_amount']);
    }

    /**
     * 10378 „odstupné náleží" plyne z důvodu skončení, ne z toho, jestli už
     * jde spočítat částka. Bez schváleného průměru dřív předvyplnění tvrdilo
     * „nenáleží"; do 10531 má jít zúčtovaná částka ze vstupu běhu.
     */
    public function testA2PrefillKeepsEntitlementWithoutAverageAndUsesBookedAmount(): void
    {
        $overview = $this->service->save($this->supplierId, $this->employmentId, [
            'termination_method' => 'employer_notice',
            'legal_ground' => 'organizational',
        ], $this->userId);

        $a2 = $overview['a2_prefill']['unemployment'];
        self::assertSame('4', $a2['termination_reason']);
        self::assertTrue($a2['entitlement']);
        self::assertSame('golden_handshake', $a2['settlement_kind']);
        self::assertArrayNotHasKey('settlement_amount', $a2);

        $this->averageFor($this->employmentId);
        $this->service->createSeveranceInput($this->supplierId, $this->employmentId, $this->userId);
        $this->db->pdo()->prepare(
            'UPDATE payroll_inputs SET amount_minor = 14000000
              WHERE supplier_id = ? AND employment_id = ? AND external_id = ?',
        )->execute([
            $this->supplierId,
            $this->employmentId,
            PayrollEmploymentTerminationService::severanceExternalId($this->employmentId),
        ]);
        $booked = $this->service->overview($this->supplierId, $this->employmentId)['a2_prefill']['unemployment'];
        self::assertSame('140000', $booked['settlement_amount']);
    }

    /** Výpověď zaměstnance odstupné nezakládá — A2 u ní 10378 vůbec nenese. */
    public function testA2PrefillOmitsSettlementForEmployeeNotice(): void
    {
        $overview = $this->service->save($this->supplierId, $this->employmentId, [
            'termination_method' => 'employee_notice',
        ], $this->userId);

        self::assertArrayNotHasKey('entitlement', $overview['a2_prefill']['unemployment']);
    }

    public function testSeveranceBecomesApprovedInputOfTheLastRun(): void
    {
        $this->averageFor($this->employmentId);
        $this->service->save($this->supplierId, $this->employmentId, [
            'termination_method' => 'employer_notice',
            'legal_ground' => 'organizational',
        ], $this->userId);

        $overview = $this->service->createSeveranceInput($this->supplierId, $this->employmentId, $this->userId);
        $again = $this->service->createSeveranceInput($this->supplierId, $this->employmentId, $this->userId);

        self::assertSame('created', $overview['severance']['state']);
        self::assertSame($overview['severance']['input']['id'], $again['severance']['input']['id']);
        $inputs = $this->inputs('ODSTUPNE');
        self::assertCount(1, $inputs);
        self::assertSame(['2026-07-01', 3 * self::MONTHLY_AVERAGE, 'approved'], [
            $inputs[0]['period_start'],
            (int) $inputs[0]['amount_minor'],
            $inputs[0]['status'],
        ]);
    }

    /**
     * § 299 odst. 4 o. s. ř.: srážky z odstupného se počítají zvlášť z každého
     * násobku průměrného výdělku. Počet násobků zadává účetní při založení
     * (předvyplněný návrhem § 67 ZP) a nese ho množství vstupu.
     */
    public function testSeveranceCarriesTheGarnishmentMultipleEnteredByTheAccountant(): void
    {
        $this->averageFor($this->employmentId);
        $proposal = $this->service->save($this->supplierId, $this->employmentId, [
            'termination_method' => 'employer_notice',
            'legal_ground' => 'organizational',
        ], $this->userId);
        self::assertSame(3, $proposal['severance']['garnishment_multiple'], 'Předvyplněno z § 67 ZP.');
        self::assertSame('2026-10-31', $proposal['severance']['garnishment_period_to']);

        $overview = $this->service->createSeveranceInput($this->supplierId, $this->employmentId, $this->userId, 4);

        self::assertSame(4_000, (int) $this->inputs('ODSTUPNE')[0]['quantity_milliunits']);
        self::assertSame(4, $overview['severance']['garnishment_multiple']);
        self::assertSame('2026-11-30', $overview['severance']['garnishment_period_to']);
    }

    public function testGarnishmentMultipleOutsideTheRangeIsRejected(): void
    {
        $this->averageFor($this->employmentId);
        $this->service->save($this->supplierId, $this->employmentId, [
            'termination_method' => 'employer_notice',
            'legal_ground' => 'organizational',
        ], $this->userId);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->createSeveranceInput($this->supplierId, $this->employmentId, $this->userId, 0);
    }

    /** § 299 odst. 4 věta druhá o. s. ř. — nástup k jinému plátci v době poskytování odstupného. */
    public function testOtherIncomeDuringSeverancePeriodIsRecordedOnTheTermination(): void
    {
        $overview = $this->service->save($this->supplierId, $this->employmentId, [
            'termination_method' => 'agreement',
            'legal_ground' => 'organizational',
            'other_income_from' => '2026-09-01',
            'other_payer_applies_protected_amount' => true,
        ], $this->userId);

        self::assertSame('2026-09-01', $overview['termination']['other_income_from']);
        self::assertTrue($overview['termination']['other_payer_applies_protected_amount']);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->save($this->supplierId, $this->employmentId, [
            'termination_method' => 'agreement',
            'legal_ground' => 'organizational',
            'other_payer_applies_protected_amount' => true,
            'row_version' => $overview['termination']['row_version'],
        ], $this->userId);
    }

    /**
     * § 271ca ZP — pojišťovna vyplácí přímo: mzdový vstup nevzniká (zdvojil by
     * výplatu), zůstane záznam pro A2.
     */
    public function testWorkInjuryCompensationPaidByInsurerCreatesNoInput(): void
    {
        $this->averageFor($this->employmentId);
        $this->service->save($this->supplierId, $this->employmentId, [
            'termination_method' => 'employer_notice',
            'legal_ground' => 'health_work_injury',
        ], $this->userId);

        $overview = $this->service->createWorkInjuryCompensation($this->supplierId, $this->employmentId, [
            'payer' => 'insurer',
            'paid_on' => '2026-08-20',
        ], $this->userId);

        self::assertSame('insurer', $overview['severance']['state']);
        self::assertSame([], $this->inputs('NAHRADA_271CA'));
        self::assertSame('replacement', $overview['a2_prefill']['unemployment']['settlement_kind']);
    }

    public function testWorkInjuryCompensationPaidOnTheLastDayGoesToTheLastMonth(): void
    {
        $this->averageFor($this->employmentId);
        $this->service->save($this->supplierId, $this->employmentId, [
            'termination_method' => 'agreement',
            'legal_ground' => 'health_work_injury',
        ], $this->userId);

        $overview = $this->service->createWorkInjuryCompensation($this->supplierId, $this->employmentId, [
            'payer' => 'employer',
            'paid_on' => '2026-07-31',
        ], $this->userId);

        self::assertSame('created', $overview['severance']['state']);
        $inputs = $this->inputs('NAHRADA_271CA');
        self::assertSame(['2026-07-01', 12 * self::MONTHLY_AVERAGE, 12_000], [
            $inputs[0]['period_start'],
            (int) $inputs[0]['amount_minor'],
            (int) $inputs[0]['quantity_milliunits'],
        ]);
        $component = $this->db->pdo()->prepare(
            'SELECT component_kind, tax_treatment, social_treatment, health_treatment, enforcement_treatment
               FROM payroll_component_definitions WHERE supplier_id = ? AND code = "NAHRADA_271CA"',
        );
        $component->execute([$this->supplierId]);
        self::assertSame(
            ['severance', 'included', 'excluded', 'excluded', 'included'],
            array_values($component->fetch(PDO::FETCH_ASSOC) ?: []),
            'Daň ano, pojistné ne (§ 5 odst. 2 písm. a) z. č. 589/1992 Sb.), srážkám podléhá.',
        );
    }

    /** Výplata až v některém z dalších měsíců = odložený příjem typu 1 (JMHZ scénář 8). */
    public function testWorkInjuryCompensationPaidLaterIsDeferredIncome(): void
    {
        $this->averageFor($this->employmentId);
        $this->service->save($this->supplierId, $this->employmentId, [
            'termination_method' => 'agreement',
            'legal_ground' => 'health_work_injury',
        ], $this->userId);

        $this->service->createWorkInjuryCompensation($this->supplierId, $this->employmentId, [
            'payer' => 'employer',
            'paid_on' => '2026-09-15',
        ], $this->userId);

        self::assertSame('2026-09-01', $this->inputs('NAHRADA_271CA')[0]['period_start']);
        $deferred = $this->db->pdo()->prepare(
            'SELECT deferred_type FROM payroll_employment_deferred_incomes
              WHERE supplier_id = ? AND employment_id = ? AND period_start = "2026-09-01"',
        );
        $deferred->execute([$this->supplierId, $this->employmentId]);
        self::assertSame('1', $deferred->fetchColumn());
    }

    public function testWorkInjuryCompensationCannotBePaidBeforeTermination(): void
    {
        $this->averageFor($this->employmentId);
        $this->service->save($this->supplierId, $this->employmentId, [
            'termination_method' => 'agreement',
            'legal_ground' => 'health_work_injury',
        ], $this->userId);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->createWorkInjuryCompensation($this->supplierId, $this->employmentId, [
            'payer' => 'employer',
            'paid_on' => '2026-07-15',
        ], $this->userId);
    }

    public function testCollectiveAgreementCanOnlyRaiseTheMultiple(): void
    {
        $this->averageFor($this->employmentId);
        try {
            $this->service->save($this->supplierId, $this->employmentId, [
                'termination_method' => 'employer_notice',
                'legal_ground' => 'organizational',
                'severance_multiple_override' => 2,
                'severance_override_reason' => 'Syntetická kolektivní smlouva',
            ], $this->userId);
            self::fail('Přepis pod zákonné minimum prošel.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('zákonné minimum', $exception->getMessage());
        }

        $overview = $this->service->save($this->supplierId, $this->employmentId, [
            'termination_method' => 'employer_notice',
            'legal_ground' => 'organizational',
            'severance_multiple_override' => 5,
            'severance_override_reason' => 'Syntetická kolektivní smlouva',
        ], $this->userId);

        self::assertSame(3, $overview['severance']['statutory_multiple']);
        self::assertSame(5, $overview['severance']['multiple']);
        self::assertSame(5 * self::MONTHLY_AVERAGE, $overview['severance']['amount_minor']);
    }

    /**
     * 160 h nárok − 80 h čerpání = 80 h × 250 Kč = 20 000 Kč na složce
     * NAHRADA_MZDY_DOVOLENA (JMHZ 10338) za měsíc skončení; kniha dovolené
     * dostane položku proplacení a zůstatek je nula.
     */
    public function testHourlyUnusedLeaveUsesCurrentStatutoryAverageFloor(): void
    {
        $this->db->pdo()->prepare('UPDATE payroll_employment_terms SET remuneration_type = "hourly", hourly_rate_minor = 13000, monthly_gross_minor = NULL, remuneration_basis_kind = "contract", remuneration_basis = "Syntetický výměr", weekly_hours = 37.5, workload_basis_points = 10000 WHERE supplier_id = ? AND employment_id = ?')->execute([$this->supplierId, $this->employmentId]);
        $this->averageFor($this->employmentId, 13440);
        $this->ledger($this->employmentId, 4801, -1);
        $this->service->save($this->supplierId, $this->employmentId, ['termination_method' => 'agreement', 'legal_ground' => 'organizational'], $this->userId);
        $plan = $this->service->overview($this->supplierId, $this->employmentId)['leave_settlement'];
        self::assertSame(14340, $plan['average_hourly_minor']);
        self::assertSame(1147200, $plan['amount_minor']);
        $this->service->settleLeave($this->supplierId, $this->employmentId, $this->userId);
        $input = $this->inputs('NAHRADA_MZDY_DOVOLENA')[0];
        $proof = json_decode((string) $input['source_snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(14340, $proof['hourly_average_floor'][0]['minimum_hourly_minor']);
        self::assertSame(13440, $proof['hourly_average_floor'][0]['raw_average_hourly_minor']);
        $overview = $this->service->overview($this->supplierId, $this->employmentId);
        self::assertSame(14340, $overview['average']['hourly_minor']);
        self::assertSame(intdiv(3 * $overview['average']['monthly_gross_minor'] + 99, 100) * 100, $overview['severance']['amount_minor']);
        $created = $this->service->createSeveranceInput($this->supplierId, $this->employmentId, $this->userId);
        $stmt = $this->db->pdo()->prepare('SELECT source_snapshot_json FROM payroll_termination_floor_proofs WHERE supplier_id = ? AND input_id = ?');
        $stmt->execute([$this->supplierId, $created['severance']['input']['id']]);
        $severanceProof = json_decode((string) $stmt->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(14340, $severanceProof['hourly_average_floor']['average_hourly_minor']);
    }

    public function testUnusedLeaveIsPaidOutOnTheLeaveComponent(): void
    {
        $this->averageFor($this->employmentId);
        $this->ledger($this->employmentId, 9_600, -4_800);
        $this->service->save($this->supplierId, $this->employmentId, [
            'termination_method' => 'employee_notice',
        ], $this->userId);

        $before = $this->service->overview($this->supplierId, $this->employmentId)['leave_settlement'];
        self::assertSame(['payout', 4_800, 2_000_000], [$before['state'], $before['minutes'], $before['amount_minor']]);

        $after = $this->service->settleLeave($this->supplierId, $this->employmentId, $this->userId);
        $this->service->settleLeave($this->supplierId, $this->employmentId, $this->userId);

        self::assertSame('settled', $after['leave_settlement']['state']);
        $inputs = $this->inputs('NAHRADA_MZDY_DOVOLENA');
        self::assertCount(1, $inputs, 'Opakované proplacení nic nezdvojí.');
        self::assertSame(['2026-07-01', 2_000_000, 80_000, 'approved'], [
            $inputs[0]['period_start'],
            (int) $inputs[0]['amount_minor'],
            (int) $inputs[0]['quantity_milliunits'],
            $inputs[0]['status'],
        ]);
        self::assertSame('10338', PayrollComponentJmhzMappingDefaults::targetForCode('NAHRADA_MZDY_DOVOLENA'));
        self::assertSame(0, $this->leave->balance($this->supplierId, $this->employmentId, 2026));
    }

    /**
     * Přečerpaná dovolená: náhrada, na kterou právo nevzniklo, se srazí
     * (§ 147 odst. 1 písm. e) ZP) záporným vstupem téže složky.
     */
    public function testOverdrawnLeaveIsDeducted(): void
    {
        $this->averageFor($this->employmentId);
        $this->ledger($this->employmentId, 4_800, -6_000);
        $this->service->save($this->supplierId, $this->employmentId, [
            'termination_method' => 'employee_notice',
        ], $this->userId);

        $overview = $this->service->settleLeave($this->supplierId, $this->employmentId, $this->userId);

        self::assertSame('overdraft', $overview['leave_settlement']['settlement']);
        $inputs = $this->inputs('NAHRADA_MZDY_DOVOLENA');
        self::assertSame([-500_000, -20_000, 'correction'], [
            (int) $inputs[0]['amount_minor'],
            (int) $inputs[0]['quantity_milliunits'],
            $inputs[0]['source_kind'],
        ]);
    }

    /** § 328 odst. 2 ZP: peněžitá práva zaměstnavatele smrtí zaměstnance zanikají. */
    public function testOverdraftAfterDeathIsNotDeducted(): void
    {
        $this->averageFor($this->employmentId);
        $this->ledger($this->employmentId, 4_800, -6_000);
        $overview = $this->service->save($this->supplierId, $this->employmentId, [
            'termination_method' => 'death',
        ], $this->userId);

        self::assertSame('not_recoverable', $overview['leave_settlement']['state']);
        $this->expectException(\DomainException::class);
        $this->service->settleLeave($this->supplierId, $this->employmentId, $this->userId);
    }

    public function testSettlementCanBeReversedAndSettledAgain(): void
    {
        $this->averageFor($this->employmentId);
        $this->ledger($this->employmentId, 9_600, -4_800);
        $this->service->save($this->supplierId, $this->employmentId, ['termination_method' => 'employee_notice'], $this->userId);
        $this->service->settleLeave($this->supplierId, $this->employmentId, $this->userId);

        $reversed = $this->service->reverseLeaveSettlement($this->supplierId, $this->employmentId, $this->userId);
        self::assertSame('payout', $reversed['leave_settlement']['state']);
        self::assertSame(4_800, $this->leave->balance($this->supplierId, $this->employmentId, 2026));

        $again = $this->service->settleLeave($this->supplierId, $this->employmentId, $this->userId);
        self::assertSame('settled', $again['leave_settlement']['state']);
        self::assertSame(2_000_000, array_sum(array_map(
            static fn (array $row): int => (int) $row['amount_minor'],
            $this->inputs('NAHRADA_MZDY_DOVOLENA'),
        )), 'Proplacení, jeho korekce a nové proplacení dají dohromady jednu náhradu.');
    }

    public function testLeaveWithoutApprovedAverageIsBlockedWithRemedy(): void
    {
        $this->ledger($this->employmentId, 9_600, -4_800);
        $overview = $this->service->save($this->supplierId, $this->employmentId, ['termination_method' => 'employee_notice'], $this->userId);

        self::assertSame('blocked', $overview['leave_settlement']['state']);
        $codes = array_column($overview['issues'], 'code');
        self::assertContains('average_missing', $codes);
        // Q15-26/C-16: přehled, dovolená i odstupné hlásí chybějící průměr
        // každý sám, souhrn ho smí ukázat jen jednou.
        self::assertCount(1, array_keys($codes, 'average_missing', true));
        $issue = $overview['issues'][array_search('average_missing', $codes, true)];
        self::assertSame(['year' => 2026, 'quarter' => 3], $issue['params']);
    }

    /**
     * § 328 odst. 1 ZP: nárok nabude první skupina v pořadí manžel/partner,
     * děti, rodiče — jen kdo žil ve společné domácnosti. Limit je trojnásobek
     * průměrného měsíčního výdělku. Zdanění se posuzuje ručně.
     */
    public function testDeathEntitlesTheFirstHouseholdGroupUpToThreeMonthlyAverages(): void
    {
        $this->averageFor($this->employmentId);
        $this->service->save($this->supplierId, $this->employmentId, ['termination_method' => 'death'], $this->userId);
        foreach ([
            ['Syntetický rodič', 'parent', true],
            ['Syntetické dítě A', 'child', true],
            ['Syntetické dítě B', 'child', true],
            ['Syntetický manžel', 'spouse_partner', false],
        ] as [$name, $relationship, $household]) {
            $overview = $this->service->addSurvivor($this->supplierId, $this->employmentId, [
                'full_name' => $name,
                'relationship' => $relationship,
                'shared_household' => $household,
            ], $this->userId);
        }

        $death = $overview['death'];
        self::assertSame(3 * self::MONTHLY_AVERAGE, $death['limit_minor']);
        self::assertSame('child', $death['entitled_group'], 'Manžel mimo domácnost nárok nenabývá, děti předcházejí rodičům.');
        $entitled = array_values(array_filter($death['survivors'], static fn (array $row): bool => $row['entitled']));
        self::assertCount(2, $entitled);
        self::assertSame(5_000, $entitled[0]['share_basis_points']);
        self::assertContains('death_tax_assessment_missing', array_column($overview['issues'], 'code'));
        self::assertTrue($overview['a2_prefill']['ended_by_death']);

        $assessed = $this->service->assessDeathTax($this->supplierId, $this->employmentId, [
            'assessment' => 'Syntetické posouzení účetní.',
        ], $this->userId);
        self::assertNotContains('death_tax_assessment_missing', array_column($assessed['issues'], 'code'));
    }

    public function testSurvivorsOnlyForDeath(): void
    {
        $this->service->save($this->supplierId, $this->employmentId, ['termination_method' => 'employee_notice'], $this->userId);

        $this->expectException(\DomainException::class);
        $this->service->addSurvivor($this->supplierId, $this->employmentId, [
            'full_name' => 'Syntetická osoba',
            'relationship' => 'child',
            'shared_household' => true,
        ], $this->userId);
    }

    /**
     * Panel Skončení vztahu načítá přehled čtecím GET bez transakce. Se
     * schváleným průměrem dřív přepočet zamykal smluvní podmínky a padal na
     * „vyžaduje aktivní transakci" (HTTP 500). Ostatní testy běží celé
     * v transakci, proto tady data výjimečně commitneme a uklidíme.
     */
    public function testOverviewWithApprovedAverageWorksOutsideTransaction(): void
    {
        $pdo = $this->db->pdo();
        $pdo->commit();
        try {
            $this->averageFor($this->employmentId);
            self::assertFalse($pdo->inTransaction());

            $overview = $this->service->overview($this->supplierId, $this->employmentId);

            self::assertSame(self::MONTHLY_AVERAGE, $overview['average']['monthly_gross_minor']);
            self::assertFalse($pdo->inTransaction());
        } finally {
            foreach ([
                'payroll_average_earning_snapshots',
                'payroll_employment_terms',
                'payroll_employments',
                'payroll_person_tax_declarations',
                'payroll_employees',
                'payroll_module_state',
                'supplier_vat_status_history',
            ] as $table) {
                $pdo->prepare("DELETE FROM {$table} WHERE supplier_id = ?")->execute([$this->supplierId]);
            }
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierId]);
        }
    }

    public function testActiveEmploymentHasNoTermination(): void
    {
        [, $active] = $this->employment('SKON-2', '2026-01-01', null);

        $this->expectException(\DomainException::class);
        $this->service->overview($this->supplierId, $active);
    }

    /** @return array{0:int,1:int} */
    private function employment(string $code, string $start, ?string $end): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, birth_date, taxpayer_type, is_active)
             VALUES (?, ?, "1990-05-17", "employee", 1)',
        )->execute([$this->supplierId, "Syntetická osoba {$code}"]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_person_tax_declarations
                (supplier_id, employee_id, status, effective_from, evidence_reference)
             VALUES (?, ?, "signed", ?, "synthetic-declaration")',
        )->execute([$this->supplierId, $employeeId, $start]);
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, end_date, is_legacy_projection)
             VALUES (?, ?, ?, "employment", ?, ?, ?, ?, 0)',
        )->execute([$this->supplierId, $employeeId, $code, $end === null ? 'active' : 'ended', $start, $start, $end]);
        $employmentId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, effective_from, planned_start_on, actual_start_on,
                 weekly_hours, workload_basis_points, social_insurance_participation,
                 health_insurance_participation, tax_regime, risky_work,
                 tax_declaration_signed, is_primary, change_reason)
             VALUES (?, ?, ?, ?, ?, 40.00, 10000, "automatic", "automatic", "advance",
                     0, 1, 0, "Syntetický podklad")',
        )->execute([$this->supplierId, $employmentId, $start, $start, $start]);

        return [$employeeId, $employmentId];
    }

    private function averageFor(int $employmentId, int $hourly = 25000): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_average_earning_snapshots
                (supplier_id, employment_id, applicable_year, applicable_quarter,
                 revision_no, source_kind, decisive_from, decisive_to,
                 gross_earnings_minor, longer_period_allocated_minor,
                 worked_minutes, worked_days, average_hourly_minor,
                 support_status, status, ruleset_id, ruleset_hash,
                 input_hash, input_trace, approved_by, approved_at)
             VALUES (?, ?, 2026, 3, 1, "actual", "2026-04-01", "2026-06-30",
                     6000000, 0, 60000, 63, ?,
                     "supported", "approved", "cz-2026-average-earning", ?,
                     UNHEX(SHA2("synthetic", 256)), ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            $employmentId,
            $hourly,
            str_repeat('b', 64),
            json_encode(['rule' => 'synthetic-test-fixture'], JSON_THROW_ON_ERROR),
            $this->userId,
        ]);
    }

    private function ledger(int $employmentId, int $entitlement, int $taken): void
    {
        foreach ([['entitlement', $entitlement, '2026-01-01'], ['adjustment', $taken, '2026-05-15']] as [$type, $minutes, $on]) {
            $this->db->pdo()->prepare(
                'INSERT INTO payroll_leave_ledger
                    (supplier_id, employment_id, leave_year, effective_date, entry_type,
                     minutes_delta, reason, support_status, source_hash, created_by)
                 VALUES (?, ?, 2026, ?, ?, ?, "Syntetický podklad", "supported", ?, ?)',
            )->execute([$this->supplierId, $employmentId, $on, $type, $minutes, random_bytes(32), $this->userId]);
        }
    }

    /** @return list<array<string,mixed>> */
    private function inputs(string $code): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT input.* FROM payroll_inputs input
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id AND component.id = input.component_id
              WHERE input.supplier_id = ? AND input.employment_id = ? AND component.code = ?
                AND input.status <> "cancelled"
              ORDER BY input.id',
        );
        $stmt->execute([$this->supplierId, $this->employmentId, $code]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
