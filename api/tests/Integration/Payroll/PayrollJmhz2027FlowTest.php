<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollAnnualSettlementRepository;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzEmployerAnnualEvidenceService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1DocumentService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecVersion;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;
use MyInvoice\Service\Payroll\Submission\Jmhz\Xml2027\Jmhz2027DocumentContract;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Měsíční hlášení za leden 2027 celým tokem: mzdový běh, příprava, datová
 * věta 2027 (XSD 1.5.0.3) a kontroly katalogu 1.5.0.4.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollJmhz2027FlowTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2027-01';
    private const PERIOD_START = '2027-01-01';
    private const PAYDAY = '2027-02-12';

    private int $officeId;
    private int $baseComponentId;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('JMHZ27', 'Syntetická účtárna 2027', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->configureIncomeTaxOutput();
        $this->baseComponentId = $this->createComponent('MZDA_2027_FLOW', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $mappings);
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testJanuary2027ReachesTheDataSentence2027(): void
    {
        $person = $this->createEmployment(
            $this->officeId,
            'Syntetická Lednová',
            1,
            'hpp',
            'employment',
            40,
            10_000,
            true,
            self::PERIOD_START,
        );
        $this->completeJmhzEmployment($person, null, [
            'first_name' => 'Syntetická',
            'last_name' => 'Lednová',
            'birth_date' => '1985-04-04',
            'sex' => 'female',
            'birth_number' => self::syntheticBirthNumber('1985-04-04', 'female', 1),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic(1), sprintf('5%020d', 1));
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms
                SET jmhz_workplace_street = "Zkušební", jmhz_workplace_house_number = "12",
                    jmhz_workplace_postal_code = "11000"
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$this->supplierId, $person['employment_id']]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_person_tax_declarations SET signed_on = "2026-01-02"
              WHERE supplier_id = ? AND employee_id = ?',
        )->execute([$this->supplierId, $person['employee_id']]);
        $this->openYear($person['employee_id']);
        // Lednové hlášení nese žádost o roční zúčtování za rok 2026 (10319).
        $settlements = $this->container->get(PayrollAnnualSettlementRepository::class);
        self::assertInstanceOf(PayrollAnnualSettlementRepository::class, $settlements);
        $settlements->saveRequest($this->supplierId, $person['employee_id'], 2026, [
            'request_status' => 'not_requested',
            'requested_on' => null,
            'request_evidence_reference' => null,
            'prior_employers' => 'none',
            'prior_documents_received_on' => null,
            'filing_obligation' => 'none',
            'filing_obligation_reason' => null,
            'annual_claims' => 'none',
            'annual_claims_note' => null,
            'note' => null,
        ], null, $this->actors[0]);
        $annual = $this->container->get(JmhzEmployerAnnualEvidenceService::class);
        self::assertInstanceOf(JmhzEmployerAnnualEvidenceService::class, $annual);
        $annual->save($this->supplierId, 2027, [
            'collective_agreement_types' => ['0'],
            'ownership_form' => '1',
            'average_headcount' => '1',
            'average_disabled_headcount' => '0',
        ], $this->actors[0]);

        $workdays = self::workdays(self::PERIOD);
        $this->publishShifts($person['employment_id'], $workdays);
        $this->approveTimeMonth($person['employment_id'], self::PERIOD, $workdays);
        $this->createApprovedInput($person, $this->baseComponentId, 4_000_000, 'base-2027', self::PERIOD_START);

        $this->approvedAverage2027($person['employment_id']);

        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, 'jmhz-2027');
        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet mzdy. ' . CanonicalJson::encode($run['blockers']));
        self::assertNotNull($run['approved']);
        $revisionId = (int) $run['approved']->revision['id'];

        $preparation = $this->prepareJmhz($revisionId, 'jmhz-2027');
        self::assertSame(201, $preparation['status'], 'Zaseknutí: příprava. ' . CanonicalJson::encode($preparation['body']));
        /*
         * Jediná zbývající mezera toku: číselníky obcí a států s účinností
         * pro rok 2027 nejsou připnuté (overlay vyhlášky č. 145/2026 Sb. je
         * ověřený do 31. 12. 2026), takže místo výkonu práce nejde za období
         * 2027 ověřit a příprava stojí fail-closed. Den podpisu prohlášení,
         * adresa pracoviště, typy kolektivních smluv, úhrn bonusů za 2026
         * i lhůta 2027 už podklad mají a nálezy nedávají.
         */
        self::assertSame('blocked', $preparation['body']['readiness_status']);
        self::assertSame(
            ['jmhz_workplace_codebooks_unverified'],
            array_values(array_unique(array_column($preparation['body']['issues'] ?? [], 'code'))),
            CanonicalJson::encode($preparation['body']['issues'] ?? []),
        );

        // Dokument 2027 nad toutéž přípravou: kromě nálezu číselníků nic
        // nechybí a zmrazit ho nejde ani bez něj (pojistka verze 2027).
        $documents = $this->container->get(JmhzScenario1DocumentService::class);
        self::assertInstanceOf(JmhzScenario1DocumentService::class, $documents);
        $resolution = $documents->resolve($this->supplierId, 'test', (int) $preparation['body']['id'], $this->officeId);
        self::assertSame(
            ['jmhz_workplace_codebooks_unverified'],
            array_values(array_unique(array_map(
                static fn ($blocker): string => $blocker->code,
                $resolution->blockers,
            ))),
            CanonicalJson::encode(array_map(
                static fn ($blocker): array => [$blocker->code, $blocker->entityType, $blocker->attributeIds],
                $resolution->blockers,
            )),
        );
        $document = $resolution->candidate?->payload ?? [];
        self::assertSame('2027', $document['spec_version'] ?? null);
        Jmhz2027DocumentContract::assertComplete($document);
        self::assertSame(['0'], $document['employer'][Jmhz2027DocumentContract::EMPLOYER_COLLECTIVE_AGREEMENTS]);
        self::assertSame('2026-01-02', $document['people'][0]['summary'][Jmhz2027DocumentContract::SUMMARY_DECLARATION_SIGNED_ON]);
        self::assertSame(0, $document['people'][0]['summary']['annual'][Jmhz2027DocumentContract::ANNUAL_MONTHLY_BONUS_TOTAL]);
        try {
            JmhzSpecPackageCatalog::requireBuildable(JmhzSpecVersion::V2027);
            self::fail('Verze 2027 zůstává do připnutí číselníků 2027 nesestavitelná.');
        } catch (JmhzXmlException $exception) {
            self::assertSame(JmhzSpecPackageCatalog::UNAVAILABLE_CODE, $exception->validationCode);
        }
    }

    /**
     * DPP bez prohlášení pod 12 500 Kč: od 2027 záloha ze samostatného
     * základu (§ 6 odst. 4 ZDP). Příjem vztahu jde do 10555, 10535 je 0
     * (kontroly 304 a 380); souhrn 10307/10309 nese samostatný základ
     * a zálohu z výsledku daně.
     */
    public function testAgreementWithoutDeclarationReportsParagraph6Income(): void
    {
        $agreement = $this->hire('Syntetický Dohodář', 2, 'dpp', 10, 2_500, '1990-05-06', 'male');
        $this->createApprovedInput($agreement, $this->baseComponentId, 1_000_000, 'dpp-2027', self::PERIOD_START);

        $document = $this->document2027('jmhz-2027-dpp');

        $employment = $this->employmentOf($document, $agreement['employment_id']);
        self::assertSame(0, $employment['taxable_income_czk']);
        self::assertSame(10_000, $employment[Jmhz2027DocumentContract::EMPLOYMENT_PARAGRAPH6_INCOME]);
        self::assertSame(
            ['base' => 10_000, 'withheld' => 1_500],
            $document['people'][0]['summary'][Jmhz2027DocumentContract::SUMMARY_PARAGRAPH6_TAX],
        );
    }

    /**
     * Souběh pracovního poměru a DPP téže osoby bez prohlášení: HPP nad
     * rozhodným příjmem je v záloze (10535 kladné, 10555 = 0), DPP pod
     * 12 500 Kč v samostatném základu (10535 = 0, 10555 kladné).
     */
    public function testConcurrentEmploymentAndAgreementSplitIncomeAttributes(): void
    {
        $person = $this->hire('Syntetická Souběžná', 3, 'employment', 40, 10_000, '1988-07-08', 'female');
        $this->createApprovedInput($person, $this->baseComponentId, 4_000_000, 'hpp-2027', self::PERIOD_START);
        $agreement = $this->createEmployment(
            $this->officeId,
            $person['name'],
            4,
            'dpp',
            'dpp',
            10,
            2_500,
            false,
            self::PERIOD_START,
            existingEmployeeId: $person['employee_id'],
        );
        $this->completeJmhzEmployment($agreement, withIdentity: false);
        $this->assignJmhzIdentity($agreement, null, sprintf('5%020d', 4));
        $this->workplaceAddress($agreement['employment_id']);
        $workdays = self::workdays(self::PERIOD);
        $this->publishShifts($agreement['employment_id'], $workdays);
        $this->approveTimeMonth($agreement['employment_id'], self::PERIOD, $workdays);
        $this->approvedAverage2027($agreement['employment_id']);
        $this->createApprovedInput($agreement, $this->baseComponentId, 500_000, 'dpp-souběh-2027', self::PERIOD_START);

        $document = $this->document2027('jmhz-2027-soubeh');

        $employment = $this->employmentOf($document, $person['employment_id']);
        self::assertSame(40_000, $employment['taxable_income_czk']);
        self::assertSame(0, $employment[Jmhz2027DocumentContract::EMPLOYMENT_PARAGRAPH6_INCOME]);
        $dpp = $this->employmentOf($document, $agreement['employment_id']);
        self::assertSame(0, $dpp['taxable_income_czk']);
        self::assertSame(5_000, $dpp[Jmhz2027DocumentContract::EMPLOYMENT_PARAGRAPH6_INCOME]);
        self::assertSame(
            ['base' => 5_000, 'withheld' => 750],
            $document['people'][0]['summary'][Jmhz2027DocumentContract::SUMMARY_PARAGRAPH6_TAX],
        );
    }

    /**
     * Osoba bez prohlášení s jedním vztahem a podklady lednového hlášení 2027.
     *
     * @return array{employee_id:int,employment_id:int,name:string}
     */
    private function hire(
        string $name,
        int $sequence,
        string $relationType,
        int $weeklyHours,
        int $workload,
        string $birthDate,
        string $sex,
    ): array {
        $person = $this->createEmployment(
            $this->officeId,
            $name,
            $sequence,
            $relationType === 'employment' ? 'hpp' : $relationType,
            $relationType,
            $weeklyHours,
            $workload,
            false,
            self::PERIOD_START,
        );
        [$firstName, $lastName] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, null, [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => $birthDate,
            'sex' => $sex,
            'birth_number' => self::syntheticBirthNumber($birthDate, $sex, $sequence),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), sprintf('5%020d', $sequence));
        $this->workplaceAddress($person['employment_id']);
        $this->openYear($person['employee_id']);
        $settlements = $this->container->get(PayrollAnnualSettlementRepository::class);
        self::assertInstanceOf(PayrollAnnualSettlementRepository::class, $settlements);
        $settlements->saveRequest($this->supplierId, $person['employee_id'], 2026, [
            'request_status' => 'not_requested',
            'requested_on' => null,
            'request_evidence_reference' => null,
            'prior_employers' => 'none',
            'prior_documents_received_on' => null,
            'filing_obligation' => 'none',
            'filing_obligation_reason' => null,
            'annual_claims' => 'none',
            'annual_claims_note' => null,
            'note' => null,
        ], null, $this->actors[0]);
        $annual = $this->container->get(JmhzEmployerAnnualEvidenceService::class);
        self::assertInstanceOf(JmhzEmployerAnnualEvidenceService::class, $annual);
        $annual->save($this->supplierId, 2027, [
            'collective_agreement_types' => ['0'],
            'ownership_form' => '1',
            'average_headcount' => '1',
            'average_disabled_headcount' => '0',
        ], $this->actors[0]);
        $workdays = self::workdays(self::PERIOD);
        $this->publishShifts($person['employment_id'], $workdays);
        $this->approveTimeMonth($person['employment_id'], self::PERIOD, $workdays);
        $this->approvedAverage2027($person['employment_id']);

        return $person;
    }

    private function workplaceAddress(int $employmentId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms
                SET jmhz_workplace_street = "Zkušební", jmhz_workplace_house_number = "12",
                    jmhz_workplace_postal_code = "11000"
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$this->supplierId, $employmentId]);
    }

    /**
     * Mzdový běh, příprava a dokument 2027. Jediný povolený nález je
     * nepřipnutý číselník obcí a států 2027 (viz test lednového toku).
     *
     * @return array<string,mixed>
     */
    private function document2027(string $key): array
    {
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, $key);
        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet mzdy. ' . CanonicalJson::encode($run['blockers']));
        self::assertNotNull($run['approved']);
        $preparation = $this->prepareJmhz((int) $run['approved']->revision['id'], $key);
        self::assertSame(201, $preparation['status'], 'Zaseknutí: příprava. ' . CanonicalJson::encode($preparation['body']));
        $documents = $this->container->get(JmhzScenario1DocumentService::class);
        self::assertInstanceOf(JmhzScenario1DocumentService::class, $documents);
        $resolution = $documents->resolve($this->supplierId, 'test', (int) $preparation['body']['id'], $this->officeId);
        self::assertSame(
            ['jmhz_workplace_codebooks_unverified'],
            array_values(array_unique(array_map(
                static fn ($blocker): string => $blocker->code,
                $resolution->blockers,
            ))),
            CanonicalJson::encode(array_map(
                static fn ($blocker): array => [$blocker->code, $blocker->entityType, $blocker->entityId, $blocker->attributeIds],
                $resolution->blockers,
            )),
        );
        $document = $resolution->candidate?->payload ?? [];
        self::assertSame('2027', $document['spec_version'] ?? null);
        Jmhz2027DocumentContract::assertComplete($document);

        return $document;
    }

    /**
     * @param array<string,mixed> $document
     * @return array<string,mixed>
     */
    private function employmentOf(array $document, int $employmentId): array
    {
        foreach ($document['people'][0]['employments'] as $employment) {
            if ($employment['employment_id'] === $employmentId) {
                return $employment;
            }
        }
        self::fail("Vztah {$employmentId} v dokumentu chybí.");
    }

    /** Schválený průměrný výdělek pro 1. čtvrtletí 2027 (rozhodné období 4. čtvrtletí 2026). */
    private function approvedAverage2027(int $employmentId): void
    {
        $created = $this->absences->createAverage(
            $this->request('POST', '/api/payroll/absences/average')->withParsedBody([
                'employment_id' => $employmentId,
                'applicable_year' => 2027,
                'applicable_quarter' => 1,
                'decisive_from' => '2026-10-01',
                'decisive_to' => '2026-12-31',
                'gross_earnings_minor' => 12_000_000,
                'longer_period_allocated_minor' => 0,
                'worked_minutes' => 9_600,
                'worked_days' => 60,
                'probable_hourly_minor' => null,
                'rationale' => null,
            ]),
            new Response(),
        );
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $average = $this->json($created)['snapshot'];
        $approved = $this->absences->approveAverage(
            $this->request('POST', '/api/payroll/absences/average/approve')->withParsedBody([
                'row_version' => $average['row_version'],
            ]),
            new Response(),
            ['id' => (string) $average['id']],
        );
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
    }

    /** Počáteční stavy roku 2027 (převzaté nuly), jak je zakládá průvodce. */
    private function openYear(int $employeeId): void
    {
        $repository = $this->container->get(PayrollStatutoryAccumulatorRepository::class);
        self::assertInstanceOf(PayrollStatutoryAccumulatorRepository::class, $repository);
        $repository->appendOpeningBalance(
            $this->supplierId,
            $employeeId,
            2027,
            'social_insurance',
            ['assessment_base_minor_units' => 0],
            'synthetic:2027-social-opening',
            ['verified_zero' => true],
            "flow-2027-social-{$employeeId}",
            actorUserId: $this->actors[0],
        );
        $repository->appendOpeningBalance(
            $this->supplierId,
            $employeeId,
            2027,
            'income_tax',
            [
                'completed_months' => 0,
                'advance_base_minor_units' => 0,
                'withholding_base_minor_units' => 0,
                'advance_tax_minor_units' => 0,
                'withholding_tax_minor_units' => 0,
                'applied_non_refundable_credits_minor_units' => 0,
                'applied_child_credit_minor_units' => 0,
                'tax_bonus_minor_units' => 0,
                'bonus_qualifying_income_minor_units' => 0,
            ],
            'synthetic:2027-tax-opening',
            ['verified_zero' => true],
            "flow-2027-tax-{$employeeId}",
            actorUserId: $this->actors[0],
        );
    }
}
