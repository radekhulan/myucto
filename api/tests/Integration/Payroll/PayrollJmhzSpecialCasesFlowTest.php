<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollDeferredIncomeAction;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Zvláštní případy měsíčního hlášení JMHZ cestou účetní, od založení vztahu
 * po testovací sestavení XML s XSD a katalogem kontrol: člen orgánu bez
 * pracovní doby, měsíc s porodem a neplaceným volnem, čerpání náhradního
 * volna za přesčas.
 *
 * Docházka se schvaluje s návrhy náhledu tak, jak je dialog předvyplní;
 * kde se tok zastaví, test vypíše krok a důvod.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollJmhzSpecialCasesFlowTest extends TestCase
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
        $this->baseComponentId = $this->createComponent('MZDA_MESICNI_FLOW', 'base_wage', 'regular');
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
     * Člen představenstva (druh činnosti Q) bez sjednané pracovní doby.
     *
     * Formulář `cinnostKS` (scénář 3). Pokyny MPSV k 10259 i 10260: „V případě
     * zaměstnanců s druhem činnosti K–S … se uvede nulová hodnota." Dialog
     * docházky navrhoval stanovený fond plného úvazku (184 h v červenci).
     */
    public function testBoardMemberHasNoWorkingTimeFund(): void
    {
        $person = $this->hire(
            'Radim Představenstvo',
            'male',
            '1975-05-15',
            activityCode: 'Q',
            employmentType: 'statutory_body',
            relationType: 'statutory_body',
            taxpayerType: 'managing_partner',
        );
        $preview = $this->timeMonthPreview($person['employment_id'], self::PERIOD);
        self::assertSame('0', $preview['suggestions']['standard_fund_hours']);
        self::assertSame('0', $preview['suggestions']['agreed_fund_hours']);
        $this->approveMonth($person['employment_id'], []);
        $this->pay($person, 3_000_000);

        $xml = $this->submission('board-member');

        self::assertStringContainsString('<form:cinnostKS ', $xml);
        self::assertStringContainsString(
            '<form:fondPracovniDoby><form:stanovenyFond>0.000</form:stanovenyFond>'
                . '<form:sjednanyFond>0.000</form:sjednanyFond>'
                . '<form:stanovenaTydenniDoba>99.00</form:stanovenaTydenniDoba></form:fondPracovniDoby>',
            $xml,
        );
    }

    /**
     * Prokurista (P), člen družstva (O), likvidátor (R) a společník (K) jdou
     * stejným scénářem 3 (`cinnostKS`) jako jednatel; ELDP nese jejich druh
     * činnosti. Dřív příprava podporovala jen S/1.
     *
     * @return iterable<string,array{string}>
     */
    public static function corporateBodyActivities(): iterable
    {
        yield 'prokurista' => ['P'];
        yield 'člen družstva' => ['O'];
        yield 'likvidátor' => ['R'];
        yield 'společník' => ['K'];
    }

    #[DataProvider('corporateBodyActivities')]
    public function testCorporateBodyActivityReachesScenarioThreeSubmission(string $activity): void
    {
        $person = $this->hire(
            'Radka Orgánová',
            'female',
            '1978-08-18',
            activityCode: $activity,
            employmentType: 'statutory_body',
            relationType: 'statutory_body',
            taxpayerType: 'managing_partner',
        );
        $this->approveMonth($person['employment_id'], []);
        $this->pay($person, 2_500_000);

        $xml = $this->submission('corporate-body-' . $activity);

        self::assertStringContainsString('<form:cinnostKS ', $xml);
        self::assertStringContainsString("<form:kod>{$activity}++</form:kod>", $xml);
        self::assertStringContainsString('<form:stanovenyFond>0.000</form:stanovenyFond>', $xml);
    }

    /**
     * Měsíc porodu bez příjmu, který začíná neplaceným volnem (1.–5. 7.),
     * pak peněžitá pomoc v mateřství (od 6. 7., porod 21. 7.).
     *
     * Omluvný důvod (doba před porodem) stačí k tomu, aby byl celý měsíc
     * dobou pojištění (Metodická pomůcka ČSSZ k ELDP, př. 5); vyloučenou
     * dobou 10359 jsou jen dny PPM před porodem (6.–20. 7.), neplacené volno
     * je vyloučeným dnem § 18 odst. 7 bez náhrady příjmu (10473). Dřív
     * souběh zastavil hlášení `jmhz_eldp_insurance_month_without_income`.
     */
    public function testChildbirthMonthStartingWithUnpaidLeaveIsInsured(): void
    {
        $person = $this->hire('Petra Porodní', 'female', '1992-09-09', healthTopUpResponsibility: 'employee');
        $this->createApprovedAbsence($person['employment_id'], 'unpaid_leave', '2026-07-01', '2026-07-05');
        $this->createApprovedAbsence(
            $person['employment_id'],
            'ppm',
            '2026-07-06',
            '2026-12-31',
            extra: ['expected_childbirth_date' => '2026-07-21', 'childbirth_date' => '2026-07-21'],
        );
        $this->approveMonth($person['employment_id'], []);

        $xml = $this->submission('childbirth-unpaid-leave');

        self::assertStringContainsString('<form:kod>1++</form:kod>', $xml);
        self::assertStringContainsString('<form:pocetDnu>31</form:pocetDnu>', $xml);
        self::assertStringContainsString(
            '<form:vylouceneDobyCelkem>15</form:vylouceneDobyCelkem><form:docasNeschopnost>0</form:docasNeschopnost>'
                . '<form:penezitaPomocMaterstvi>15</form:penezitaPomocMaterstvi>',
            $xml,
        );
        self::assertStringContainsString(
            '<form:vyloucenePar18>31</form:vyloucenePar18><form:omluvenaNepritomnost>5</form:omluvenaNepritomnost>'
                . '<form:pracovniNeschopnost>0</form:pracovniNeschopnost><form:vyplaceniDavek>26</form:vyplaceniDavek>',
            $xml,
        );
    }

    /**
     * Doplatek odměny zúčtovaný v červenci po skončení pracovního poměru
     * v červnu (odložený příjem typu 1, scénář 8). Prohlášení bylo učiněno
     * na zdaňovací období, takže záloha zůstává zálohou a 10419 = ANO; měsíční
     * slevu na poplatníka za měsíc, kdy už u plátce nepracuje, ale plátce
     * neposkytne (§ 38k odst. 3 a 4 písm. b) ZDP). Dřív se sleva 2 570 Kč
     * odečetla a u nového zaměstnavatele vznikla za týž měsíc podruhé.
     */
    public function testDeferredIncomeAfterTerminationGetsNoMonthlyTaxCredit(): void
    {
        $person = $this->hire('Bohdan Odešlý', 'male', '1975-01-20');
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_tax_credit_claims
                (supplier_id, employee_id, credit_kind, evidence_status, effective_from, effective_to, evidence_reference)
             VALUES (?, ?, "taxpayer", "verified", "2026-01-01", NULL, "document:synthetic-credit")',
        )->execute([$this->supplierId, $person['employee_id']]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET status = "ended", end_date = "2026-06-30" WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $person['employment_id']]);
        $action = $this->container->get(PayrollDeferredIncomeAction::class);
        self::assertInstanceOf(PayrollDeferredIncomeAction::class, $action);
        $response = $action->save(
            $this->request('PUT', "/api/payroll/employments/{$person['employment_id']}/deferred-income/" . self::PERIOD)
                ->withParsedBody(['deferred_type' => '1', 'note' => 'Syntetický doplatek odměny.']),
            new Response(),
            ['id' => (string) $person['employment_id'], 'period' => self::PERIOD],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->pay($person, 1_500_000);

        $xml = $this->submission('deferred-declaration');

        self::assertSame(1, preg_match('#<form:odlozenyPrijem[^>]*><form:typ>1</form:typ>#', $xml));
        self::assertStringContainsString(
            '<form:zalohaNaDan><form:zakladDane>15000</form:zakladDane><form:vypoctenaZaloha>2250</form:vypoctenaZaloha>'
                . '<form:danZalohaPoSleve>2250</form:danZalohaPoSleve>',
            $xml,
        );
        self::assertStringContainsString('<form:prohlaseniPoplatnika>true</form:prohlaseniPoplatnika>', $xml);
        self::assertStringNotContainsString('<form:zakladniSleva>', $xml);
    }

    /**
     * Přesčas 8 h v sobotu 11. 7. a náhradní volno 8 h 24. 7. v témž měsíci.
     *
     * Pokyny MPSV k 10268/10269: v měsíci čerpání se od přesčasu i od
     * odpracovaných hodin odečtou hodiny, za které bylo poskytnuto náhradní
     * volno (záporné → 0); hodiny volna zůstávají v úhrnu 10275. Dřív šel
     * přesčas 8 h i plné odpracované hodiny, takže se čas volna započítal
     * dvakrát (jednou jako odpracovaný, jednou jako neodpracovaný).
     */
    public function testCompensatoryTimeOffReducesReportedOvertime(): void
    {
        $person = $this->hire('Hynek Přesčas', 'male', '1985-05-05');
        $this->createApprovedAbsence($person['employment_id'], 'compensatory_time_off', '2026-07-24', '2026-07-24');
        $workdays = self::workdays(self::PERIOD, ['2026-07-24']);
        $this->ensureRegularCalendar($person['employment_id']);
        $version = $this->recordWorkedDays($person['employment_id'], $workdays);
        $overtime = $this->time->entry(
            $this->request('POST', '/api/payroll/time/entries')->withParsedBody([
                'employment_id' => $person['employment_id'],
                'starts_at' => '2026-07-11T08:00:00+02:00',
                'ends_at' => '2026-07-11T16:30:00+02:00',
                'timezone' => 'Europe/Prague',
                'category' => 'overtime',
                'break_minutes' => 30,
                'row_version' => 0,
                'month_row_version' => $version,
                'supersedes_id' => null,
            ]),
            new Response(),
        );
        self::assertSame(201, $overtime->getStatusCode(), (string) $overtime->getBody());
        // Dosažená mzda za přesčas (§ 114 odst. 1 ZP) se zakládá z docházky jen ze
        // sjednané měsíční mzdy v podmínkách vztahu; bez ní kontrola běhu varuje.
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms SET monthly_gross_minor = ? WHERE supplier_id = ? AND employment_id = ?'
        )->execute([4_200_000, $this->supplierId, $person['employment_id']]);
        $this->approveMonth($person['employment_id'], []);
        $this->pay($person, 4_200_000);

        $xml = $this->submission('compensatory-overtime');

        $worked = count($workdays) * 8;
        // Odpracováno 21 dnů po 8 h a 8 h přesčasu; volno 8 h se odečte.
        self::assertStringContainsString(
            '<form:odpracovaneHodiny><form:pocet>' . $worked . '.000</form:pocet>'
                . '<form:rozpad><form:prescas>0.000</form:prescas></form:rozpad></form:odpracovaneHodiny>',
            $xml,
        );
        // Úhrn neodpracovaných: volno 8 h a svátek 6. 7. 8 h.
        self::assertStringContainsString('<form:hodinyNeodpracCelkem>16.000</form:hodinyNeodpracCelkem>', $xml);
    }

    /**
     * Osoba s úplnou evidencí pro JMHZ, zveřejněnými směnami na pracovní dny
     * měsíce a schváleným průměrem za 3. čtvrtletí.
     *
     * @return array{employee_id:int,employment_id:int,name:string,average_id:?int,sequence:int}
     */
    private function hire(
        string $name,
        string $sex,
        string $birthDate,
        ?string $activityCode = null,
        string $employmentType = 'hpp',
        string $relationType = 'employment',
        int $weeklyHours = 40,
        int $workload = 10_000,
        string $taxpayerType = 'employee',
        string $healthTopUpResponsibility = 'employer_obstacle_verified',
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
            true,
            self::PERIOD_START,
            taxpayerType: $taxpayerType,
            healthTopUpResponsibility: $healthTopUpResponsibility,
        );
        [$firstName, $lastName] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, $activityCode, [
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

    /**
     * @param list<string> $workedDates
     * @param array<string,mixed> $overrides
     */
    private function approveMonth(
        int $employmentId,
        array $workedDates,
        array $overrides = [],
        string $period = self::PERIOD,
    ): void {
        $response = $this->approveTimeMonth($employmentId, $period, $workedDates, $overrides);
        self::assertSame(
            200,
            $response->getStatusCode(),
            'Zaseknutí: schválení docházky. ' . (string) $response->getBody(),
        );
    }

    /** @param array{employee_id:int,employment_id:int,name:string} $person */
    private function pay(array $person, int $amountMinor, string $periodStart = self::PERIOD_START): void
    {
        $this->createApprovedInput(
            $person,
            $this->baseComponentId,
            $amountMinor,
            'base-' . $person['employment_id'] . '-' . $periodStart,
            $periodStart,
        );
    }

    /**
     * Běh, příprava a dry-run. Vrací XML; při zastavení selže s krokem a
     * důvodem.
     */
    private function submission(
        string $scenario,
        string $periodStart = self::PERIOD_START,
        string $payday = self::PAYDAY,
    ): string {
        $run = $this->runPayrollMonth($periodStart, $payday, $this->officeId, "special-{$scenario}");
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

        $preparation = $this->prepareJmhz($revisionId, "special-{$scenario}");
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
