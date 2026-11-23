<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollDeferredIncomeAction;
use MyInvoice\Action\Payroll\PayrollEmploymentAction;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Service\Payroll\Insurance\PayrollInsuranceBreakdownQueryService;
use MyInvoice\Service\Payroll\PayrollEmploymentTermsBody;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

/**
 * Souběh účastných vztahů, riziková práce, dočasné přidělení a odložený příjem
 * v měsíčním hlášení JMHZ, celou cestou účetní od evidence přes běh až po
 * testovací sestavení XML s XSD a kontrolami katalogu.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollJmhzConcurrencyFlowTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-07';
    private const PERIOD_START = '2026-07-01';
    private const PAYDAY = '2026-08-14';

    private int $officeId;
    private int $baseComponentId;
    private int $sequence = 0;
    private int $lastRevisionId = 0;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('SOUB', 'Syntetická registrace souběhu', '1100004322');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->baseComponentId = $this->createComponent('MZDA_SOUBEH_FLOW', 'base_wage', 'regular');
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

    /**
     * Pracovní poměr a DPČ nad rozhodným příjmem téže osoby: oba vztahy jsou
     * účastné. Kontrola 118 chce na KAŽDÉM formuláři 10370 = 7,1 % z 10477
     * zaokrouhleno nahoru, kontrola 12 součet 10370 rovný pojistnému za
     * zaměstnance v pojistné části. Obojí sedí jen tehdy, když se pojistné
     * zaokrouhluje po vztazích.
     */
    public function testTwoParticipatingRelationshipsReportContributionsPerRelationship(): void
    {
        $person = $this->hire('Filip Souběžný', 'male', '1979-09-30');
        $agreement = $this->hireAgreement($person, 'dpc');
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->approveMonth($agreement['employment_id'], ['2026-07-04', '2026-07-11'], dailyMinutes: 240);
        $this->pay($person, 4_000_100);
        $this->pay($agreement, 500_100);

        $xml = $this->submission('two-participating');

        self::assertSame(2, substr_count($xml, '</formularOsoby>'));
        self::assertSame(2, substr_count($xml, '<form:pojisteniZamestnanec>'));
        // 7,1 % z 40 001 Kč = 2 840,071 → 2 841; z 5 001 Kč = 355,071 → 356.
        self::assertStringContainsString('<form:castkaOdvodPojistneho>40001</form:castkaOdvodPojistneho>', $xml);
        self::assertStringContainsString('<form:castkaOdvodPojistneho>5001</form:castkaOdvodPojistneho>', $xml);
        self::assertStringContainsString(
            '<form:pojisteniZamestnanec><form:socialniPojisteni>2841</form:socialniPojisteni></form:pojisteniZamestnanec>',
            $xml,
        );
        self::assertStringContainsString(
            '<form:pojisteniZamestnanec><form:socialniPojisteni>356</form:socialniPojisteni></form:pojisteniZamestnanec>',
            $xml,
        );
        // 24,8 % z 40 001 Kč = 9 920,248 → 9 921; z 5 001 Kč = 1 240,248 → 1 241.
        self::assertStringContainsString(
            '<form:pojisteniZamestnavatel><form:socialniPojisteni>9921</form:socialniPojisteni></form:pojisteniZamestnavatel>',
            $xml,
        );
        self::assertStringContainsString(
            '<form:pojisteniZamestnavatel><form:socialniPojisteni>1241</form:socialniPojisteni></form:pojisteniZamestnavatel>',
            $xml,
        );
        // Kontrola 12: pojistné za zaměstnance = součet 10370 formulářů.
        self::assertStringContainsString('<pvpoj:pojistneZamestnance>3197</pvpoj:pojistneZamestnance>', $xml);

        // Rozklad pojistného na kartě osoby musí dát tutéž částku po vztazích.
        $breakdown = $this->container->get(PayrollInsuranceBreakdownQueryService::class);
        self::assertInstanceOf(PayrollInsuranceBreakdownQueryService::class, $breakdown);
        $social = $breakdown->breakdown($this->supplierId, $this->lastRevisionId, $person['employee_id'])['social'];
        self::assertSame(319_700, $social['employee']['before_discount_minor']);
        self::assertNull($social['employee']['contribution_step']);
        self::assertSame(
            [284_100, 35_600],
            array_map(
                static fn (array $row): int => $row['before_discount_minor'],
                $social['employee']['relationships'],
            ),
        );
    }

    /**
     * Rizikové zaměstnání (§ 5a odst. 1 písm. c) ZPSZ) zadané na kartě
     * vztahu: hlášení vykáže základ pod písm. c), hodiny rizikové práce
     * (10273) a kategorizaci rizika 1 (10274) bez dalšího zadávání.
     */
    public function testRiskyEmploymentReportsRiskHoursAndCategorization(): void
    {
        $person = $this->hire('Radek Rizikový', 'male', '1980-03-14');
        $this->correctTerms($person['employment_id'], [
            'social_employer_rate_category' => 'risk_employment',
            'social_employer_rate_category_evidence' => 'document:synthetic-risk-category-4',
        ]);
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->pay($person, 4_000_000);

        $xml = $this->submission('risky');

        self::assertStringContainsString('<form:pismenoC>40000</form:pismenoC>', $xml);
        self::assertSame(1, preg_match('#<form:odpracovaneHodiny><form:pocet>([0-9.]+)</form:pocet>#', $xml, $worked));
        // 10273 je v celých hodinách se zbytkem nahoru (Pokyny MH 1.4.14 revize).
        $riskHours = (int) ceil((float) $worked[1]);
        self::assertStringContainsString(
            "<form:riziko><form:hodinyOdpracovanePocet>{$riskHours}</form:hodinyOdpracovanePocet>"
                . '<form:kategorizaceRizika>1</form:kategorizaceRizika></form:riziko>',
            $xml,
        );
    }

    /**
     * Záchranář / HZS podniku (písm. b) potřebuje u 10274 rozlišit 6 a 7.
     * Bez volby hlášení zastaví nález s odkazem na kartu vztahu; po volbě
     * v podmínkách vztahu se kód promítne.
     */
    public function testRescueWorkerRequiresCategorizationChoice(): void
    {
        $person = $this->hire('Hana Hasičská', 'female', '1985-08-08');
        $this->correctTerms($person['employment_id'], [
            'social_employer_rate_category' => 'rescue_and_company_fire_service',
            'social_employer_rate_category_evidence' => 'document:synthetic-company-fire-unit',
        ]);
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->pay($person, 4_000_000);

        $blocked = $this->preparationIssues('rescue-missing');
        self::assertContains('jmhz_risk_categorization_missing', $blocked);
    }

    public function testRescueWorkerWithCompanyFireUnitCode(): void
    {
        $person = $this->hire('Petr Hasič', 'male', '1983-02-02');
        $this->correctTerms($person['employment_id'], [
            'social_employer_rate_category' => 'rescue_and_company_fire_service',
            'social_employer_rate_category_evidence' => 'document:synthetic-company-fire-unit',
            'jmhz_risk_categorization_code' => '7',
        ]);
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->pay($person, 4_000_000);

        $xml = $this->submission('rescue');

        self::assertStringContainsString('<form:pismenoB>40000</form:pismenoB>', $xml);
        self::assertStringContainsString('<form:kategorizaceRizika>7</form:kategorizaceRizika>', $xml);
    }

    /**
     * Agentura práce: dočasné přidělení k uživateli s IČO (10251 = ANO,
     * 10252). Kontrola 103 chce u přidělení identifikaci uživatele.
     */
    public function testTemporaryAssignmentReportsUserIdentification(): void
    {
        $person = $this->hire('Agáta Přidělená', 'female', '1992-12-12');
        $this->correctTerms($person['employment_id'], [
            'jmhz_temporary_assignment_status' => 'yes',
            'jmhz_assignment_user_kind' => 'ico',
            'jmhz_assignment_user_ico' => '00000027',
        ]);
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->pay($person, 4_000_000);

        $xml = $this->submission('temporary-assignment');

        self::assertStringContainsString(
            '<form:docasnePrideleniEvidovano>true</form:docasnePrideleniEvidovano>'
                . '<form:docasnePrideleni><form:uzivatel><form:ico>00000027</form:ico></form:uzivatel></form:docasnePrideleni>',
            $xml,
        );
    }

    public function testTemporaryAssignmentToForeignUser(): void
    {
        $person = $this->hire('Olga Zahraniční', 'female', '1990-05-05');
        $this->correctTerms($person['employment_id'], [
            'jmhz_temporary_assignment_status' => 'yes',
            'jmhz_assignment_user_kind' => 'foreign',
            'jmhz_assignment_user_country_code' => 'DE',
            'jmhz_assignment_user_foreign_id' => '12345678',
            'jmhz_assignment_user_name' => 'Synthetische Nutzer GmbH',
        ]);
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->pay($person, 4_000_000);

        $xml = $this->submission('temporary-assignment-foreign');

        self::assertStringContainsString(
            '<form:uzivatel><form:zahranicniOsoba><form:kodStatu>DE</form:kodStatu>'
                . '<form:identifikace>12345678</form:identifikace>'
                . '<form:nazev>Synthetische Nutzer GmbH</form:nazev></form:zahranicniOsoba></form:uzivatel>',
            $xml,
        );
    }

    public function testTemporaryAssignmentWithoutUserStopsWithGuidance(): void
    {
        $person = $this->hire('Bedřich Nepřiřazený', 'male', '1987-07-07');
        $this->correctTerms($person['employment_id'], [
            'jmhz_temporary_assignment_status' => 'yes',
        ]);
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->pay($person, 4_000_000);

        self::assertContains(
            'jmhz_temporary_assignment_user_missing',
            $this->preparationIssues('temporary-assignment-missing'),
        );
    }

    /**
     * Odložený příjem typu 1 (scénář 8): pracovní poměr skončil v červnu,
     * v červenci se zúčtuje doplatek odměny. Pojistné se platí za měsíc
     * zúčtování, ELDP má 0 dnů a kód s „P" na druhé pozici, formulář je
     * `odlozenyPrijem` s typem 1 a obdobím hlášeného měsíce.
     */
    public function testPostTerminationBonusIsReportedAsDeferredIncome(): void
    {
        $person = $this->hire('Bohdan Odešlý', 'male', '1975-01-20');
        $this->endEmployment($person['employment_id'], '2026-06-30');
        $this->declareDeferredIncome($person['employment_id'], '1');
        $this->pay($person, 1_500_000);

        $xml = $this->submission('deferred-bonus');

        self::assertSame(1, substr_count($xml, '</formularOsoby>'));
        self::assertSame(1, preg_match('#<form:odlozenyPrijem[^>]*><form:typ>1</form:typ><form:identifikace>#', $xml));
        self::assertStringContainsString(
            '<form:eldpObdobi><form:obdobi><form:mesic>7</form:mesic><form:rok>2026</form:rok>'
                . '<form:eldpSeznam><form:eldp><form:kod>1P+</form:kod>',
            $xml,
        );
        self::assertStringContainsString('<form:pocetDnu>0</form:pocetDnu><form:vymerovaciZaklad>15000</form:vymerovaciZaklad>', $xml);
        self::assertStringContainsString('<form:castkaOdvodPojistneho>15000</form:castkaOdvodPojistneho>', $xml);
        // 7,1 % z 15 000 Kč = 1 065 Kč.
        self::assertStringContainsString(
            '<form:pojisteniZamestnanec><form:socialniPojisteni>1065</form:socialniPojisteni></form:pojisteniZamestnanec>',
            $xml,
        );
        self::assertStringNotContainsString('<form:prubehZamestnani>', $xml);
    }

    /**
     * Bez potvrzení druhu odloženého příjmu zůstává původní cesta: běh příjem
     * po skončení vztahu odmítne a pošle účetní na kartu vztahu.
     */
    public function testPostTerminationBonusWithoutDeclarationStopsTheRun(): void
    {
        $person = $this->hire('Blahoslav Nepotvrzený', 'male', '1976-02-21');
        $this->endEmployment($person['employment_id'], '2026-06-30');
        $this->pay($person, 1_500_000);

        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, 'concurrency-deferred-missing');

        self::assertNull($run['approved']);
        $blockers = array_column($run['blockers'], null, 'code');
        self::assertSame(
            ['input_after_employment_end', 'statutory_calculation_manual_review'],
            array_column($run['blockers'], 'code'),
        );
        self::assertStringContainsString('v části Odložený příjem', (string) $blockers['statutory_calculation_manual_review']['message']);
        // Kontrola snímku jmenuje měsíc vstupu a den konce vztahu.
        self::assertStringContainsString(
            'vstup za 07/2026 je na vztahu ukončeném 30. 6. 2026',
            (string) $blockers['input_after_employment_end']['message'],
        );
    }

    /**
     * Odložený příjem typu 1 u dohody (účast stojí na výši příjmu): pravidla
     * podání JMHZ 1.4.5, kap. 6 bod 1 žádají opravu hlášení za poslední měsíc
     * výkonu (důchodové údaje, když součet založí účast, a 10476 vždy). Tu
     * aplikace nesestaví, takže běh zastaví s pokynem podat hlášení ručně,
     * ne s výzvou potvrdit odložený příjem, který už potvrzený je.
     */
    public function testDeferredIncomeOnAgreementStopsTheRunWithManualFilingGuidance(): void
    {
        $person = $this->hire(
            'Dobromil Dohodář',
            'male',
            '1974-05-19',
            employmentType: 'dpc',
            relationType: 'dpc',
            weeklyHours: 10,
            workload: 2_500,
        );
        $this->endEmployment($person['employment_id'], '2026-06-30');
        $this->declareDeferredIncome($person['employment_id'], '1');
        $this->pay($person, 300_000);

        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, 'concurrency-deferred-agreement');

        self::assertNull($run['approved']);
        $social = array_values(array_filter(
            $run['blockers'],
            static fn (array $blocker): bool => str_contains((string) $blocker['message'], 'zpětně založit účast'),
        ));
        self::assertCount(1, $social, CanonicalJson::encode($run['blockers']));
        self::assertSame('statutory_calculation_manual_review', $social[0]['code']);
        self::assertStringContainsString('ePortál ČSSZ', (string) $social[0]['message']);
    }

    public function testDeferredIncomeIsRefusedForRunningEmploymentAndUnsupportedType(): void
    {
        $running = $this->hire('Bořek Trvající', 'male', '1977-03-22');
        self::assertSame(422, $this->saveDeferredIncome($running['employment_id'], '1')->getStatusCode());

        $ended = $this->hire('Bronislav Skončený', 'male', '1978-04-23');
        $this->endEmployment($ended['employment_id'], '2026-06-30');
        self::assertSame(422, $this->saveDeferredIncome($ended['employment_id'], '2')->getStatusCode());
        self::assertSame(0, (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_employment_deferred_incomes WHERE supplier_id = ?',
            [$this->supplierId],
        ));
    }

    private function endEmployment(int $employmentId, string $endDate): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET status = "ended", end_date = ?
              WHERE supplier_id = ? AND id = ?',
        )->execute([$endDate, $this->supplierId, $employmentId]);
    }

    private function declareDeferredIncome(int $employmentId, string $type): void
    {
        $response = $this->saveDeferredIncome($employmentId, $type);
        self::assertSame(200, $response->getStatusCode(), 'Zaseknutí: odložený příjem. ' . (string) $response->getBody());
    }

    private function saveDeferredIncome(int $employmentId, string $type): ResponseInterface
    {
        $action = $this->container->get(PayrollDeferredIncomeAction::class);
        self::assertInstanceOf(PayrollDeferredIncomeAction::class, $action);

        return $action->save(
            $this->request('PUT', "/api/payroll/employments/{$employmentId}/deferred-income/" . self::PERIOD)
                ->withParsedBody(['deferred_type' => $type, 'note' => 'Syntetický doplatek odměny.']),
            new Response(),
            ['id' => (string) $employmentId, 'period' => self::PERIOD],
        );
    }

    /**
     * Oprava platné verze podmínek tak, jak ji posílá karta vztahu: celá
     * verze z evidence a změněná pole.
     *
     * @param array<string,mixed> $changes
     */
    private function correctTerms(int $employmentId, array $changes): void
    {
        $repository = $this->container->get(PayrollEmploymentRepository::class);
        $action = $this->container->get(PayrollEmploymentAction::class);
        self::assertInstanceOf(PayrollEmploymentRepository::class, $repository);
        self::assertInstanceOf(PayrollEmploymentAction::class, $action);
        $current = $repository->currentTerms($this->supplierId, $employmentId);
        self::assertIsArray($current);
        $rowVersion = (int) $this->scalar(
            'SELECT row_version FROM payroll_employments WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $employmentId],
        );
        $body = $changes
            + PayrollEmploymentTermsBody::fromCurrent($current, 'Syntetická oprava podmínek')
            + ['row_version' => $rowVersion];
        // Karta vztahu `risky_work` neposílá, odvodí se ze sazbové kategorie.
        unset($body['risky_work']);
        $response = $action->correctTerms(
            $this->request('PATCH', "/api/payroll/employments/{$employmentId}/terms/current")
                ->withParsedBody($body),
            new Response(),
            ['id' => (string) $employmentId],
        );
        self::assertSame(200, $response->getStatusCode(), 'Zaseknutí: karta vztahu. ' . (string) $response->getBody());
    }

    /**
     * Běh a příprava hlášení; vrací kódy nálezů přípravy (kde se účetní
     * zastaví a kam ji hlášení pošle).
     *
     * @return list<string>
     */
    private function preparationIssues(string $scenario): array
    {
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, "concurrency-{$scenario}");
        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet mzdy. ' . CanonicalJson::encode($run['blockers']));
        self::assertNotNull($run['approved']);
        $preparation = $this->prepareJmhz((int) $run['approved']->revision['id'], "concurrency-{$scenario}");
        self::assertSame(201, $preparation['status'], CanonicalJson::encode($preparation['body']));

        return array_values(array_map(
            static fn (array $issue): string => (string) ($issue['code'] ?? ''),
            $preparation['body']['issues'] ?? [],
        ));
    }

    /**
     * Osoba s úplnou evidencí pro JMHZ, zveřejněnými směnami na pracovní dny
     * měsíce a schváleným průměrem za 3. čtvrtletí.
     *
     * @return array{employee_id:int,employment_id:int,name:string,average_id:?int}
     */
    private function hire(
        string $name,
        string $sex,
        string $birthDate,
        string $employmentType = 'hpp',
        string $relationType = 'employment',
        int $weeklyHours = 40,
        int $workload = 10_000,
        bool $taxDeclarationSigned = true,
        string $socialDiscountStatus = 'not_claimed',
    ): array {
        $sequence = ++$this->sequence;
        $person = $this->createEmployment(
            $this->officeId,
            $name,
            $sequence,
            $employmentType,
            $relationType,
            $weeklyHours,
            $workload,
            $taxDeclarationSigned,
            self::PERIOD_START,
            socialDiscountStatus: $socialDiscountStatus,
        );
        [$firstName, $lastName] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => $birthDate,
            'sex' => $sex,
            'birth_number' => self::syntheticBirthNumber($birthDate, $sex, $sequence),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), self::syntheticPpv($sequence));
        $this->publishShifts($person['employment_id'], self::workdays(self::PERIOD));
        $average = $this->createApprovedAverage($person['employment_id'], 3);

        return $person + ['average_id' => (int) $average['id']];
    }

    /**
     * Další vztah téže osoby (souběh).
     *
     * @param array{employee_id:int,employment_id:int,name:string} $person
     * @return array{employee_id:int,employment_id:int,name:string}
     */
    private function hireAgreement(array $person, string $relationType): array
    {
        $sequence = ++$this->sequence;
        $agreement = $this->createEmployment(
            $this->officeId,
            $person['name'],
            $sequence,
            $relationType,
            $relationType,
            10,
            2_500,
            true,
            self::PERIOD_START,
            existingEmployeeId: $person['employee_id'],
        );
        $this->completeJmhzEmployment($agreement, withIdentity: false);
        $this->assignJmhzIdentity($agreement, null, self::syntheticPpv($sequence));
        $this->createApprovedAverage($agreement['employment_id'], 3);

        return $agreement;
    }

    private static function syntheticPpv(int $sequence): string
    {
        return sprintf('3%020d', $sequence);
    }

    /** @param list<string> $workedDates */
    private function approveMonth(int $employmentId, array $workedDates, int $dailyMinutes = 480): void
    {
        $response = $this->approveTimeMonth($employmentId, self::PERIOD, $workedDates, dailyMinutes: $dailyMinutes);
        self::assertSame(
            200,
            $response->getStatusCode(),
            'Zaseknutí: schválení docházky. ' . (string) $response->getBody(),
        );
    }

    /** @param array{employee_id:int,employment_id:int,name:string} $person */
    private function pay(array $person, int $amountMinor): void
    {
        $this->createApprovedInput(
            $person,
            $this->baseComponentId,
            $amountMinor,
            'base-' . $person['employment_id'],
            self::PERIOD_START,
        );
    }

    /**
     * Běh, příprava a dry-run. Vrací XML; při zastavení selže s krokem a
     * důvodem.
     */
    private function submission(string $scenario): string
    {
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, "concurrency-{$scenario}");
        self::assertSame(
            [],
            $run['blockers'],
            'Zaseknutí: výpočet mzdy. ' . CanonicalJson::encode($run['blockers']),
        );
        self::assertSame(
            [],
            $run['warnings'],
            'Zaseknutí: varování před schválením běhu. ' . CanonicalJson::encode($run['warnings']),
        );
        self::assertNotNull($run['approved']);
        $revisionId = (int) $run['approved']->revision['id'];
        $this->lastRevisionId = $revisionId;

        $preparation = $this->prepareJmhz($revisionId, "concurrency-{$scenario}");
        self::assertSame(201, $preparation['status'], 'Zaseknutí: příprava hlášení. ' . CanonicalJson::encode($preparation['body']));
        self::assertSame(
            'source_ready',
            $preparation['body']['readiness_status'],
            'Zaseknutí: příprava hlášení. ' . CanonicalJson::encode($preparation['body']['issues'] ?? []),
        );

        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame(200, $tested['status'], 'Zaseknutí: sestavení XML. ' . CanonicalJson::encode($tested['body']));
        $xml = (string) ($tested['body']['xml'] ?? '');
        self::assertSame(
            'dry_run_valid',
            $tested['body']['status'],
            'Zaseknutí: XSD nebo kontroly. ' . CanonicalJson::encode($tested['body']['controls'] ?? $tested['body']),
        );
        self::assertTrue(
            $tested['body']['controls']['submittable'],
            'Zaseknutí: kontroly katalogu. ' . CanonicalJson::encode($tested['body']['controls']),
        );

        return (string) preg_replace('/>\s+</', '><', $xml);
    }
}
