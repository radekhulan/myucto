<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollDeferredIncomeAction;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

/**
 * Odložený příjem typu 4 (roční zúčtování po skončení zaměstnaneckého poměru)
 * cestou účetní: potvrzení na kartě vztahu, mzdový běh za leden, příprava
 * hlášení a testovací sestavení XML s XSD a katalogem kontrol.
 *
 * Předloha je vzorové hlášení MPSV `TEST_odložený příjem_1.1.2026.xlsx`,
 * list SLOVNÍK, sloupec O: vztah skončil v roce 2025, zaměstnanec v tom roce
 * nepodepsal prohlášení, takže o roční zúčtování požádat nemohl; hlášení za
 * leden 2026 nese typ 4, období 1/2026 s 10356 = 0, 10476 = 0, 10319 = NE
 * a 10320 = NE. Pravidla podání JMHZ 1.4.5, kap. 6 bod 3: kód ELDP se
 * neuvádí, 10356, 10476, 10477 a 10286 jsou vždy 0.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollJmhzDeferredAnnualSettlementFlowTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-01';
    private const PERIOD_START = '2026-01-01';
    private const PAYDAY = '2026-02-13';

    private int $officeId;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('JMHZ', 'Syntetická registrace JMHZ', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testAnnualSettlementAfterTerminationIsReportedAsDeferredTypeFour(): void
    {
        $person = $this->endedInPreviousYear('Oldřich Loňský', '1979-04-12', 'male', 1);
        $this->annualRequest($person['employee_id'], 'not_requested');
        $this->confirmDeferred($person['employment_id'], '4');

        $xml = $this->submission('annual-after-termination');

        self::assertSame(1, preg_match('#<form:odlozenyPrijem[^>]*><form:typ>4</form:typ>#', $xml), $xml);
        self::assertStringContainsString(
            '<form:eldpObdobi><form:obdobi><form:mesic>1</form:mesic><form:rok>2026</form:rok>'
                . '<form:eldpSeznam><form:eldp><form:pocetDnu>0</form:pocetDnu></form:eldp></form:eldpSeznam>'
                . '</form:obdobi></form:eldpObdobi>',
            $xml,
        );
        self::assertStringNotContainsString('<form:kod>', $xml);
        self::assertStringContainsString(
            '<form:vymerovaciZaklad><form:castkaOdvodPojistneho>0</form:castkaOdvodPojistneho>'
                . '<form:prijemNepojistenaCinnost>0</form:prijemNepojistenaCinnost></form:vymerovaciZaklad>',
            $xml,
        );
        self::assertStringContainsString('<form:zuctovanoCelkem>0</form:zuctovanoCelkem>', $xml);
        self::assertStringContainsString(
            '<form:rocniUhrny><form:rocniZuctovaniZadost>false</form:rocniZuctovaniZadost>'
                . '<form:rocniZuctovaniProvedeno>false</form:rocniZuctovaniProvedeno></form:rocniUhrny>',
            $xml,
        );
    }

    /**
     * Typ 5 = příjem po skončení (znak 1) + roční zúčtování po skončení
     * (znak 4), číselník 10548. Doplatek zúčtovaný v lednu 2026 po skončení
     * v roce 2025: ELDP jako u typu 1 (kód s „P", 0 dnů, vyměřovací základ
     * doplatku, Pravidla podání JMHZ 1.4.5, kap. 6 bod 1 c), pojistné za
     * měsíc zúčtování a k tomu roční údaje jako u typu 4 (bod 3).
     */
    public function testIncomeWithAnnualSettlementAfterTerminationIsReportedAsDeferredTypeFive(): void
    {
        $componentId = $this->createComponent('ODMENA_PO_SKONCENI', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $mappings);
        $mappings->put($this->supplierId, $componentId, '10329', null, $this->actors[0]);
        $person = $this->endedInPreviousYear('Pavel Doplatkový', '1976-11-08', 'male', 4);
        $this->annualRequest($person['employee_id'], 'not_requested');
        $this->confirmDeferred($person['employment_id'], '5');
        $this->createApprovedInput($person, $componentId, 1_200_000, 'bonus-' . $person['employment_id'], self::PERIOD_START);

        $xml = $this->submission('income-and-annual-after-termination');

        self::assertSame(1, preg_match('#<form:odlozenyPrijem[^>]*><form:typ>5</form:typ>#', $xml), $xml);
        self::assertStringContainsString(
            '<form:eldpObdobi><form:obdobi><form:mesic>1</form:mesic><form:rok>2026</form:rok>'
                . '<form:eldpSeznam><form:eldp><form:kod>1P+</form:kod><form:platnostOd>2026-01-01</form:platnostOd>'
                . '<form:platnostDo>2026-01-31</form:platnostDo><form:pocetDnu>0</form:pocetDnu>'
                . '<form:vymerovaciZaklad>12000</form:vymerovaciZaklad></form:eldp></form:eldpSeznam>',
            $xml,
        );
        self::assertStringContainsString('<form:castkaOdvodPojistneho>12000</form:castkaOdvodPojistneho>', $xml);
        self::assertStringContainsString('<form:zuctovanoCelkem>12000</form:zuctovanoCelkem>', $xml);
        self::assertStringContainsString(
            '<form:rocniUhrny><form:rocniZuctovaniZadost>false</form:rocniZuctovaniZadost>'
                . '<form:rocniZuctovaniProvedeno>false</form:rocniZuctovaniProvedeno></form:rocniUhrny>',
            $xml,
        );
    }

    /**
     * Náhrada mzdy při neplatném skončení (typy 2, 3 a 6) zůstává na ePortálu
     * ČSSZ: ELDP po měsících s náhradou a 10476 po měsících (Pravidla podání
     * JMHZ 1.4.5, kap. 6 bod 2) aplikace doložitelně nesestaví a XSD 1.4.3.6
     * nemá pro 10476 v `eldpObdobi` místo. Potvrzení se odmítne s vysvětlením
     * a nic se neuloží.
     */
    public function testInvalidTerminationCompensationIsLeftToManualFiling(): void
    {
        $person = $this->endedInPreviousYear('Rudolf Neplatný', '1972-02-14', 'male', 5);

        foreach (['2', '3', '6'] as $type) {
            $response = $this->saveDeferred($person['employment_id'], $type, self::PERIOD);
            self::assertSame(422, $response->getStatusCode(), "typ {$type}");
            self::assertStringContainsString('10537/10538', (string) $response->getBody(), "typ {$type}");
            self::assertStringContainsString('ePortál ČSSZ', (string) $response->getBody(), "typ {$type}");
        }
        self::assertSame(0, (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_employment_deferred_incomes WHERE supplier_id = ?',
            [$this->supplierId],
        ));
    }

    /**
     * Typ 4 se týká všech druhů činnosti kromě 10 (Pravidla podání JMHZ
     * 1.4.5, kap. 6), tedy i dohody. Zdravotní pojistné z příjmu po skončení
     * dohody ale patří do měsíce jejího konce a měsíc bez příjmu výpočet
     * nespočítá, takže aplikace dohodu s typem 4 odmítne už při potvrzení
     * (fail-closed) a v běhu ji zastaví vlastním pokynem.
     */
    public function testAnnualSettlementAfterTerminatedAgreementIsLeftToManualFiling(): void
    {
        $person = $this->endedInPreviousYear('Dobroslav Dohodář', '1984-09-21', 'male', 3, 'dpp', 'dpp');

        $response = $this->saveDeferred($person['employment_id'], '4', self::PERIOD);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('ePortál ČSSZ', (string) $response->getBody());

        // Potvrzení vzniklé mimo tuto cestu (import, starší data) zastaví běh.
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_deferred_incomes
                (supplier_id, employment_id, period_start, deferred_type, note, created_by, updated_by)
             VALUES (?, ?, ?, "4", NULL, ?, ?)',
        )->execute([$this->supplierId, $person['employment_id'], self::PERIOD_START, $this->actors[0], $this->actors[0]]);
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, 'deferred-agreement');
        self::assertNull($run['approved']);
        self::assertStringContainsString(
            'Potvrzení typu 4 u dohody zrušte',
            CanonicalJson::encode($run['blockers']),
        );
    }

    /**
     * Pravidla podání JMHZ 1.4.5, kap. 6 bod 3 b): roční zúčtování po
     * skončení se hlásí za rozhodné období leden až březen.
     */
    public function testAnnualSettlementAfterTerminationIsConfirmedOnlyForJanuaryToMarch(): void
    {
        $person = $this->endedInPreviousYear('Olga Pozdní', '1981-06-03', 'female', 2);

        $response = $this->saveDeferred($person['employment_id'], '4', '2026-04');

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('leden, únor nebo březen', (string) $response->getBody());
        self::assertSame(422, $this->saveDeferred($person['employment_id'], '5', '2026-05')->getStatusCode());
        self::assertSame(200, $this->saveDeferred($person['employment_id'], '4', '2026-03')->getStatusCode());
        self::assertSame(200, $this->saveDeferred($person['employment_id'], '5', '2026-02')->getStatusCode());
    }

    /**
     * Pracovní poměr od února do října 2025, v lednu 2026 už netrvá.
     *
     * @return array{employee_id:int,employment_id:int,name:string}
     */
    private function endedInPreviousYear(
        string $name,
        string $birthDate,
        string $sex,
        int $sequence,
        string $employmentType = 'hpp',
        string $relationType = 'employment',
    ): array {
        $person = $this->createEmployment(
            $this->officeId,
            $name,
            $sequence,
            $employmentType,
            $relationType,
            $relationType === 'dpp' ? 10 : 40,
            $relationType === 'dpp' ? 2_500 : 10_000,
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
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), sprintf('2%020d', $sequence));
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE payroll_employments
                SET start_date = "2025-02-01", actual_start_date = "2025-02-01",
                    status = "ended", end_date = "2025-10-31"
              WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $person['employment_id']]);
        $pdo->prepare(
            'UPDATE payroll_employment_terms
                SET effective_from = "2025-02-01", planned_start_on = "2025-02-01", actual_start_on = "2025-02-01"
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$this->supplierId, $person['employment_id']]);
        // Identita se ověřuje ke dni skončení vztahu, platí tedy od nástupu.
        foreach ([
            'UPDATE payroll_person_identity_history SET effective_from = "2025-02-01" WHERE supplier_id = ? AND employee_id = ?',
            'UPDATE payroll_person_external_ids SET valid_from = "2025-02-01" WHERE supplier_id = ? AND employee_id = ?',
        ] as $sql) {
            $pdo->prepare($sql)->execute([$this->supplierId, $person['employee_id']]);
        }
        $pdo->prepare(
            'UPDATE payroll_employment_external_ids SET valid_from = "2025-02-01" WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$this->supplierId, $person['employment_id']]);

        return $person;
    }

    private function annualRequest(int $employeeId, string $status): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_annual_settlement_requests
                (supplier_id, employee_id, tax_year, request_status,
                 requested_on, request_evidence_reference, prior_employers,
                 filing_obligation, filing_obligation_reason, annual_claims)
             VALUES (?, ?, 2025, ?, NULL, "synthetic-request", "unknown",
                     "unknown", NULL, "unknown")',
        )->execute([$this->supplierId, $employeeId, $status]);
    }

    private function confirmDeferred(int $employmentId, string $type): void
    {
        $response = $this->saveDeferred($employmentId, $type, self::PERIOD);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    private function saveDeferred(int $employmentId, string $type, string $period): ResponseInterface
    {
        $action = $this->container->get(PayrollDeferredIncomeAction::class);
        self::assertInstanceOf(PayrollDeferredIncomeAction::class, $action);

        return $action->save(
            $this->request('PUT', "/api/payroll/employments/{$employmentId}/deferred-income/{$period}")
                ->withParsedBody(['deferred_type' => $type, 'note' => 'Syntetické roční zúčtování po skončení.']),
            new Response(),
            ['id' => (string) $employmentId, 'period' => $period],
        );
    }

    /**
     * Běh, příprava a dry-run. Vrací XML; při zastavení selže s krokem a
     * důvodem.
     */
    private function submission(string $scenario): string
    {
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, "deferred-{$scenario}");
        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet mzdy. ' . CanonicalJson::encode($run['blockers']));
        self::assertSame([], $run['warnings'], 'Zaseknutí: varování před schválením běhu. ' . CanonicalJson::encode($run['warnings']));
        self::assertNotNull($run['approved']);
        $revisionId = (int) $run['approved']->revision['id'];

        $preparation = $this->prepareJmhz($revisionId, "deferred-{$scenario}");
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
        self::assertTrue(
            $tested['body']['controls']['submittable'],
            'Zaseknutí: kontroly katalogu. ' . CanonicalJson::encode($tested['body']['controls']),
        );

        return (string) preg_replace('/>\s+</', '><', (string) ($tested['body']['xml'] ?? ''));
    }
}
