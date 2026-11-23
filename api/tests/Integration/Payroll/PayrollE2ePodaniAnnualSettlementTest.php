<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollDependantAction;
use MyInvoice\Repository\Payroll\PayrollAnnualSettlementRepository;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementOutcome;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementStatute;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualTaxSettlementService;
use MyInvoice\Service\Payroll\Document\AnnualSettlementSnapshotBuilder;
use MyInvoice\Service\Payroll\PayrollOpeningBalanceService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandService;
use MyInvoice\Service\Payroll\Run\PayrollRunReadinessService;
use MyInvoice\Service\Payroll\TaxStatement\TaxStatementService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Slim\Psr7\Response;

/**
 * Roční zúčtování záloh za 2026 (§ 38ch ZDP ve znění do 31. 12. 2026) cestou
 * účetní: dvanáct schválených běhů 2026, žádost zaměstnance, kontrola před
 * během za leden 2027, provedení zúčtování v únoru 2027 a výplata přeplatku
 * ve mzdě za únor 2027 podle sady 2027.
 *
 * Ruční přepočet (sada 2026, {@see \MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026}):
 *
 *  E: HPP s prohlášením, jedno dítě (1. pořadí) celý rok.
 *     Leden až říjen 40 000 Kč, listopad a prosinec 10 000 Kč (kratší úvazek).
 *     Měsíčně leden–říjen: 15 % z 40 000 = 6 000 − 2 570 − 1 267 = 2 163 Kč.
 *     Listopad, prosinec: 15 % z 10 000 = 1 500 − 2 570 → 0; dítě nevyčerpáno
 *     a měsíční bonus nenáleží, příjem je pod polovinou minimální mzdy
 *     11 200 Kč (§ 35d odst. 4 ZDP).
 *     Zálohy celkem 10 × 2 163 = 21 630 Kč.
 *     Ročně: základ 420 000 (§ 16, zaokrouhlení na stovky dolů beze změny),
 *     daň 15 % = 63 000, sleva na poplatníka 30 840 (§ 35ba odst. 1 písm. a)
 *     → 32 160, zvýhodnění 12 × 1 267 = 15 204 (§ 35c) → 16 956 Kč.
 *     Přeplatek 21 630 − 16 956 = 4 674 Kč (§ 38ch odst. 4 ZDP).
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2ePodaniAnnualSettlementTest extends TestCase
{
    private const YEAR = 2026;

    use PayrollFullFlowTrait;

    /** @var object{today:string} */
    private object $calendar;
    private int $officeId;
    private int $baseComponentId;
    private int $sequence = 0;
    private int $lastRevisionId = 0;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->calendar = new class {
            public string $today = '2026-11-11';
        };
        $calendar = $this->calendar;
        $clock = new class ($calendar) implements ClockInterface {
            /** @param object{today:string} $calendar */
            public function __construct(private readonly object $calendar) {}

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable($this->calendar->today . ' 12:00:00', new \DateTimeZone('Europe/Prague'));
            }
        };
        if (!$this->container instanceof \DI\Container) {
            self::markTestSkipped('Kontejner nejde přenastavit na zmrazené hodiny.');
        }
        $this->container->set(ClockInterface::class, $clock);
        $commands = $this->container->get(PayrollRunCommandService::class);
        self::assertInstanceOf(PayrollRunCommandService::class, $commands);
        $this->runs = $commands;

        $this->officeId = $this->createOffice('RZ', 'Syntetická účtárna RZ', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->configureIncomeTaxOutput();
        $this->baseComponentId = $this->createComponent('MZDA_RZ', 'base_wage', 'regular');
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testRequestedSettlementIsComputedAndOverpaymentPaidOutsideTheNextYearBase(): void
    {
        $person = $this->hire('Syntetická Zúčtovaná', 'hpp', 'employment', 40, true);
        $this->child($person['employee_id'], 'Syntetické Dítě', '2019-04-04', 1);
        foreach (range(1, 12) as $month) {
            $period = sprintf('2026-%02d', $month);
            $tax = $this->runMonth($period, [$person + ['gross' => $month <= 10 ? 40_000 : 10_000]]);
            self::assertSame($month <= 10 ? 216_300 : 0, $tax[0]['advance_tax'], "záloha {$period}");
            self::assertSame(0, $tax[0]['bonus'], "bonus {$period}");
        }

        // Žádost do 15. 2. 2027 (§ 38ch odst. 1); lednová mzda 2027 se zpracuje dřív,
        // než se zúčtování provede, a přeplatek nenese.
        $this->calendar->today = '2027-02-05';
        $this->saveRequest($person['employee_id'], 'requested', '2027-02-05');
        $wage2027 = $person + ['gross' => 40_000];
        $january = $this->runMonth('2027-01', [$wage2027]);
        self::assertSame(0, $january[0]['annual_settlement']);

        $service = $this->container->get(AnnualTaxSettlementService::class);
        self::assertInstanceOf(AnnualTaxSettlementService::class, $service);
        $this->calendar->today = '2027-02-20';
        $preview = $service->preview($this->supplierId, $person['employee_id'], self::YEAR, new \DateTimeImmutable('2027-02-20'));
        $result = $preview['result'];
        self::assertSame([], $result->blockerCodes(), CanonicalJson::encode($result->blockerCodes()));
        self::assertTrue($result->performed);
        self::assertSame(AnnualSettlementOutcome::Overpayment, $result->outcome);
        self::assertSame(42_000_000, $result->roundedTaxBaseMinorUnits);
        self::assertSame(6_300_000, $result->taxBeforeCreditsMinorUnits);
        self::assertSame(3_084_000, $result->appliedCreditsMinorUnits);
        self::assertSame(1_520_400, $result->childCreditMinorUnits);
        self::assertSame(0, $result->annualTaxBonusMinorUnits);
        self::assertSame(1_695_600, $result->taxAfterAllCreditsMinorUnits);
        self::assertSame(467_400, $result->taxDifferenceMinorUnits);
        self::assertSame(0, $result->bonusDifferenceMinorUnits);
        self::assertSame(467_400, $result->payableMinorUnits);
        $this->freezeSettlement($person['employee_id'], $preview, '2027-02-20');

        // Únorová mzda 2027 (sada 2027): přeplatek se vyplatí navíc, do základu
        // daně ani pojistného 2027 nevstupuje — není to příjem, ale vrácená
        // záloha (§ 38ch odst. 5 ZDP). Záloha 2027: 15 % ze 40 000 = 6 000
        // − 2 570 − 1 267 = 2 163; SP 7,1 % = 2 840; ZP 4,5 % = 1 800.
        $february = $this->runMonth('2027-02', [$wage2027]);
        self::assertSame(467_400, $february[0]['annual_settlement']);
        self::assertSame(4_000_000, $february[0]['base']);
        self::assertSame(216_300, $february[0]['advance_tax']);
        self::assertSame(4_000_000 - 284_000 - 180_000 - 216_300 + 467_400, $february[0]['net']);
        self::assertSame(8_000_000, $february[0]['annual_advance_base'], 'Roční základ 2027 po únoru: jen dvě mzdy po 40 000 Kč.');
        // JMHZ za únor 2027 nese výsledek zúčtování (10320 až 10323), ale za
        // období 2027 se zatím nesestaví: číselníky obcí a států 2027 nejsou
        // vyhlášené. Příprava se zastaví na této blokaci (fail-closed).
        $preparation = $this->prepareJmhz($this->lastRevisionId, 'rz-jmhz-2027-02');
        self::assertSame(201, $preparation['status'], CanonicalJson::encode($preparation['body']));
        self::assertSame('blocked', $preparation['body']['readiness_status']);
        self::assertContains(
            'jmhz_workplace_codebooks_not_published',
            array_column($preparation['body']['issues'] ?? [], 'code'),
            CanonicalJson::encode(array_column($preparation['body']['issues'] ?? [], 'code')),
        );
        self::assertSame('2027-02-01', substr((string) $this->scalar(
            'SELECT payout_period_start FROM payroll_annual_settlement_outcomes WHERE supplier_id = ? AND employee_id = ? AND tax_year = 2026',
            [$this->supplierId, $person['employee_id']],
        ), 0, 10));

        // Březen: zúčtování je hotové a vyplacené, kontrola před během mlčí
        // a březnový běh už přeplatek znovu nevyplatí.
        $this->calendar->today = '2027-03-20';
        $readiness = $this->container->get(PayrollRunReadinessService::class);
        self::assertInstanceOf(PayrollRunReadinessService::class, $readiness);
        $march = $this->findings($readiness->inspect($this->supplierId, '2027-03-01', $this->payday('2027-03-01'), $this->officeId));
        self::assertArrayNotHasKey('annual_settlement_pending_last_payout', $march);
        self::assertSame(0, $this->runMonth('2027-03', [$wage2027])[0]['annual_settlement']);

        // Vyúčtování za 2026 přeplatek z ročního zúčtování 2026 nenese (vrací se
        // až v roce 2027, § 38j odst. 4 ZDP sl. 4 patří přeplatkům za PŘEDCHOZÍ
        // rok). Za 2027 se vyúčtování nepodává vůbec (zák. č. 360/2025 Sb.).
        $statements = $this->container->get(TaxStatementService::class);
        self::assertInstanceOf(TaxStatementService::class, $statements);
        $dpz = $statements->preview($this->supplierId, 2026)['dpzvd6'];
        self::assertSame(0, $dpz['annual_overpayment_total']);
        self::assertSame(21_630, $dpz['total']['advance_due']);
        try {
            $statements->preview($this->supplierId, 2027);
            self::fail('Vyúčtování za 2027 se nepodává.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('360/2025 Sb.', $exception->getMessage());
        }
    }

    /**
     * Zmrazení výsledku zúčtování tak, jak ho dělá
     * {@see AnnualTaxSettlementService::settle()} (ta si otevírá vlastní
     * transakci, test běží v jedné; vzor {@see AnnualSettlementIntegrationTest}).
     *
     * @param array<string,mixed> $preview
     */
    private function freezeSettlement(int $employeeId, array $preview, string $settledOn): void
    {
        $result = $preview['result'];
        $snapshots = $this->container->get(AnnualSettlementSnapshotBuilder::class);
        self::assertInstanceOf(AnnualSettlementSnapshotBuilder::class, $snapshots);
        $prepared = $snapshots->build(
            $this->supplierId,
            $employeeId,
            self::YEAR,
            $result,
            $settledOn,
            $preview['credit_rows'],
            $preview['child_rows'],
            $this->actors[0],
        );
        $settlements = $this->container->get(PayrollAnnualSettlementRepository::class);
        self::assertInstanceOf(PayrollAnnualSettlementRepository::class, $settlements);
        $stored = $settlements->insertOutcome($this->supplierId, $employeeId, self::YEAR, [
            'annual_revision_id' => (int) $prepared['revision']['id'],
            'outcome' => $result->outcome->value,
            'tax_difference_minor' => $result->taxDifferenceMinorUnits,
            'bonus_difference_minor' => $result->bonusDifferenceMinorUnits,
            'settlement_difference_minor' => $result->settlementDifferenceMinorUnits,
            'payable_minor' => $result->payableMinorUnits,
            'payout_threshold_minor' => AnnualSettlementStatute::PAYOUT_THRESHOLD_MINOR_UNITS,
            'settled_on' => $settledOn,
        ], $this->actors[0]);
        self::assertTrue($stored['created']);
    }

    /**
     * Žádost o roční zúčtování za 2026 podaná včas (§ 38ch odst. 1 ZDP: do
     * 15. 2. 2027), ale zúčtování ještě neproběhlo. Plátce ho musí provést do
     * 31. 3. 2027 (§ 38ch odst. 4) a přeplatek vrátit nejpozději ve mzdě za
     * březen (§ 38ch odst. 5); modul ho po březnu do běhu nepustí
     * ({@see \MyInvoice\Repository\Payroll\PayrollAnnualSettlementRepository::payableOutcomesForPeriod()}).
     *
     * Kontrola před během za leden až březen 2027 to musí říct jménem
     * a s odkazem na stránku Roční zúčtování za rok 2026; v březnu jako
     * varování, protože po schválení běhu jde přeplatek vyplatit už jen
     * opravnou revizí. Po provedení zúčtování nález zmizí. Mzdu ostatních
     * nález nezastaví.
     */
    public function testPendingRequestIsNamedBeforeRunsUntilMarch(): void
    {
        $person = $this->hire('Syntetická Čekající', 'hpp', 'employment', 40, true);
        foreach (range(1, 12) as $month) {
            $this->runMonth(sprintf('2026-%02d', $month), [$person + ['gross' => 40_000]]);
        }
        $this->calendar->today = '2027-01-20';
        $this->saveRequest($person['employee_id'], 'requested', '2027-01-15');
        $readiness = $this->container->get(PayrollRunReadinessService::class);
        self::assertInstanceOf(PayrollRunReadinessService::class, $readiness);
        $expectedPath = '/payroll/annual-settlement?year=2026&state=unsettled';

        foreach (['2027-01-01' => 'info', '2027-02-01' => 'info', '2027-03-01' => 'warning'] as $period => $severity) {
            $findings = $this->findings($readiness->inspect($this->supplierId, $period, $this->payday($period), $this->officeId));
            $code = $severity === 'warning' ? 'annual_settlement_pending_last_payout' : 'annual_settlement_pending';
            self::assertArrayHasKey($code, $findings, $period . ': ' . CanonicalJson::encode(array_keys($findings)));
            $finding = $findings[$code];
            self::assertSame($severity, $finding['severity'], $period);
            self::assertSame($expectedPath, $finding['remediation_path'], $period);
            self::assertStringContainsString('Syntetická Čekající', $finding['message']);
            self::assertStringContainsString('31. 3. 2027', $finding['message']);
            self::assertStringContainsString('březen', $finding['message']);
            self::assertSame(
                '/payroll/annual-settlement?year=2026&person=' . $person['employee_id'],
                $finding['entities'][0]['remediation_path'] ?? null,
            );
        }
        // Duben: okno výplaty skončilo, roční zúčtování za 2026 se běhu netýká.
        $april = $this->findings($readiness->inspect($this->supplierId, '2027-04-01', $this->payday('2027-04-01'), $this->officeId));
        self::assertArrayNotHasKey('annual_settlement_pending', $april);
        self::assertArrayNotHasKey('annual_settlement_pending_last_payout', $april);

        // Leden se spočítá a schválí i s nevyřízenou žádostí.
        $january = $this->runMonth('2027-01', [$person + ['gross' => 40_000]]);
        self::assertSame(0, $january[0]['annual_settlement']);

        // Nepožádal: zúčtování se neprovádí a nález nevzniká.
        $this->saveRequest($person['employee_id'], 'not_requested', null);
        $february = $this->findings($readiness->inspect($this->supplierId, '2027-02-01', $this->payday('2027-02-01'), $this->officeId));
        self::assertArrayNotHasKey('annual_settlement_pending', $february);
    }

    /** @return array<string,array<string,mixed>> */
    private function findings(array $inspection): array
    {
        $byCode = [];
        foreach ($inspection['findings'] as $finding) {
            $byCode[$finding['code']] = $finding;
        }

        return $byCode;
    }

    private function payday(string $periodStart): string
    {
        return (new \DateTimeImmutable($periodStart))->modify('+1 month')->format('Y-m-12');
    }

    private function saveRequest(int $employeeId, string $status, ?string $requestedOn): void
    {
        $settlements = $this->container->get(PayrollAnnualSettlementRepository::class);
        self::assertInstanceOf(PayrollAnnualSettlementRepository::class, $settlements);
        $settlements->saveRequest($this->supplierId, $employeeId, self::YEAR, [
            'request_status' => $status,
            'requested_on' => $requestedOn,
            'request_evidence_reference' => 'document:synthetic-annual-request-2026',
            'prior_employers' => 'none',
            'filing_obligation' => 'none',
            'annual_claims' => 'none',
            'prior_documents_received_on' => null,
            'filing_obligation_reason' => null,
            'annual_claims_note' => null,
            'note' => null,
            // Dítě uplatňuje jen zaměstnankyně (JMHZ 10453 „jiná osoba" nevyplněna).
            'other_household_caregiver_status' => 'none',
            'other_household_caregivers' => [],
        ], null, $this->actors[0]);
    }

    /** @return array{employee_id:int,employment_id:int,name:string} */
    private function hire(string $name, string $type, string $relation, int $weekly, bool $declaration): array
    {
        $sequence = ++$this->sequence;
        $person = $this->createEmployment(
            $this->officeId,
            $name,
            $sequence,
            $type,
            $relation,
            $weekly,
            intdiv($weekly * 10_000, 40),
            $declaration,
            '2026-01-01',
            healthTopUpResponsibility: 'employee',
        );
        [$first, $last] = explode(' ', $name, 2);
        $birthDate = sprintf('19%02d-0%d-1%d', 80 + $sequence, $sequence, $sequence);
        $this->completeJmhzEmployment($person, null, [
            'first_name' => $first,
            'last_name' => $last,
            'birth_date' => $birthDate,
            'sex' => 'female',
            'birth_number' => self::syntheticBirthNumber($birthDate, 'female', $sequence),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), sprintf('2%020d', $sequence));
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_addresses
                (supplier_id, employee_id, address_type, street_line, city, postal_code, country_code, effective_from)
             VALUES (?, ?, "residence", "Modelová 2", "Brno", "602 00", "CZ", "2026-01-01")',
        )->execute([$this->supplierId, $person['employee_id']]);

        return $person;
    }

    /**
     * @param list<array<string,mixed>> $people
     * @return list<array{advance_tax:?int,bonus:?int,base:?int,annual_settlement:?int,net:?int}>
     */
    private function runMonth(string $period, array $people, bool $approve = true): array
    {
        $periodStart = "{$period}-01";
        foreach ($people as $person) {
            $this->db->pdo()->prepare(
                'INSERT IGNORE INTO payroll_enforcement_person_month_evidence
                    (supplier_id, employee_id, period_start,
                     claim_register_evidence_complete, dependants_evidence_complete,
                     spouse_evidence_complete, pension_evidence, updated_by)
                 VALUES (?, ?, ?, 1, 1, 1, "none", ?)',
            )->execute([$this->supplierId, $person['employee_id'], $periodStart, $this->actors[0]]);
            $this->createApprovedInput($person, $this->baseComponentId, $person['gross'] * 100, "rz-{$person['employment_id']}-{$period}", $periodStart);
        }
        // Totéž, co po založení běhu udělá akce běhů: prokazatelně nulové
        // počáteční stavy nového roku (PayrollRunsAction::create).
        $openings = $this->container->get(PayrollOpeningBalanceService::class);
        self::assertInstanceOf(PayrollOpeningBalanceService::class, $openings);
        $openings->seedProvableZeroOpenings($this->supplierId, $periodStart, [], $this->actors[0]);
        $payday = $this->payday($periodStart);
        $run = $this->runPayrollMonth($periodStart, $payday, $this->officeId, "rz-{$period}");
        self::assertSame([], $run['blockers'], "{$period}: " . CanonicalJson::encode($run['blockers']));
        self::assertSame([], $run['warnings'], "{$period}: " . CanonicalJson::encode($run['warnings']));
        self::assertNotNull($run['approved']);
        $this->lastRevisionId = (int) $run['approved']->revision['id'];
        $results = [];
        foreach ((array) $run['approved']->revision['result_snapshot']['statutory']['people'] as $row) {
            $results[] = [
                'advance_tax' => $row['income_tax']['advance_tax']['tax_after_credits_minor_units'] ?? null,
                'bonus' => $row['income_tax']['advance_tax']['tax_bonus_minor_units'] ?? null,
                'base' => $row['income_tax']['advance_tax']['taxable_income_minor_units'] ?? null,
                'annual_settlement' => $row['net_pay']['annual_settlement_minor_units'] ?? null,
                'net' => $row['net_payable_minor_units'] ?? null,
                'annual_advance_base' => $row['income_tax']['annual_accumulator']['advance_base_minor_units'] ?? null,
            ];
        }

        return $results;
    }

    private function child(int $employeeId, string $name, string $birthDate, int $order): void
    {
        $dependants = $this->container->get(PayrollDependantAction::class);
        self::assertInstanceOf(PayrollDependantAction::class, $dependants);
        $birthNumber = self::syntheticBirthNumber($birthDate, 'male', 200 + $order);
        [$given, $family] = explode(' ', $name, 2);
        $created = $dependants->create(
            $this->request('POST', "/api/payroll/people/{$employeeId}/dependants")->withParsedBody([
                'relation' => 'child_own',
                'full_name' => $name,
                'given_name' => $given,
                'family_name' => $family,
                'birth_date' => $birthDate,
                'birth_number' => substr($birthNumber, 0, 6) . '/' . substr($birthNumber, 6),
                'ztp_p' => false,
                'student' => false,
                'existence_from' => $birthDate,
                'existence_to' => null,
                'note' => null,
            ]),
            new Response(),
            ['id' => (string) $employeeId],
        );
        self::assertSame(200, $created->getStatusCode(), (string) $created->getBody());
        $dependantId = (int) $this->json($created)['dependants'][0]['id'];
        $claim = $dependants->createClaim(
            $this->request('POST', "/api/payroll/people/{$employeeId}/dependants/{$dependantId}/claims")->withParsedBody([
                'child_order' => $order,
                'claim_reason' => 'own_household',
                'evidence_status' => 'verified',
                'evidence_reference' => 'document:child-claim',
                'shared_household_confirmed' => true,
                'other_claimant_excluded' => true,
                'other_household_caregiver_status' => 'none',
                'ztp_p' => false,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ]),
            new Response(),
            ['id' => (string) $employeeId, 'dependantId' => (string) $dependantId],
        );
        self::assertSame(200, $claim->getStatusCode(), (string) $claim->getBody());
    }
}
