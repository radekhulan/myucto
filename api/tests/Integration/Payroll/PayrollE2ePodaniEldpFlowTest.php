<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEldpAction;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Service\Payroll\Absence\AverageEarningBatchService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpStatementService;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpValidationException;
use MyInvoice\Service\Payroll\Termination\PayrollEmploymentTerminationService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Evidenční list důchodového pojištění za rok 2026 celou cestou účetní:
 * založení vztahů, docházka, nepřítomnosti, mzdové běhy za leden až duben
 * a teprve potom příprava ELDP (nic se neodesílá).
 *
 * Od roku 2026 sestavuje evidenční list ČSSZ z měsíčního hlášení (§ 38 odst. 1
 * a 2 zákona č. 582/1991 Sb. ve znění zák. č. 360/2025 Sb.). Zaměstnavatel
 * vyhotoví samostatný list jen na výzvu ČSSZ/ÚSSZ (§ 38a odst. 2 a 3) nebo
 * u zaměstnání skončeného před 1. 4. 2026 (čl. V bod 1 zák. č. 360/2025 Sb.,
 * ČSSZ: Řešení částečně zrušených tiskopisů). Scénáře proto ověřují obě
 * přípustné cesty i to, že trvající nebo později skončený vztah dostane
 * srozumitelné odmítnutí s nápravou ve formuláři.
 *
 * Očekávané dny, vyloučené doby a vyměřovací základy jsou spočtené ručně
 * (rozpis v docblocku každého scénáře), ne opsané z výstupu.
 *
 * Syntetická data, transakci vrací tearDown.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2ePodaniEldpFlowTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const MONTHS = ['2026-01', '2026-02', '2026-03', '2026-04'];
    private const NO_PENSION = [
        'pension_age_reached_on' => null,
        'early_pension_from' => null,
        'full_pension_paid_from' => null,
        'foreign_insurance' => false,
    ];

    private int $officeId;
    private int $baseComponentId;
    private int $sequence = 0;
    /** @var array<string,array<string,mixed>> */
    private array $people = [];

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('E2EL', 'Syntetická účtárna ELDP', '1100004321');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->configureIncomeTaxOutput();
        $this->db->pdo()->prepare('UPDATE payroll_employer_settings SET social_security_office_code = "115" WHERE supplier_id = ?')
            ->execute([$this->supplierId]);
        $this->baseComponentId = $this->createComponent('MZDA_E2E_ELDP', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $mappings);
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * (a) HPP celé období leden až duben 2026, mzda 40 000 Kč měsíčně.
     *
     * Bez výzvy: zaměstnání trvá, přechodné ustanovení na něj nedopadá, list
     * se nesestaví a důvod jmenuje, že vztah trvá (náprava je ve formuláři,
     * kind `not_applicable`, žádný proklik do jiné agendy).
     *
     * Na výzvu doručenou 4. 5. 2026: lhůta 8 dnů (§ 39 odst. 3 ve znění do
     * 31. 12. 2025) = 12. 5. 2026 (úterý). List se uzavírá posledním zúčtovaným
     * měsícem: 1. 1. až 30. 4. 2026 = 31 + 28 + 31 + 30 = 120 dnů, vyměřovací
     * základ 4 × 40 000 = 160 000 Kč, vyloučené doby 0.
     */
    public function testContinuingEmploymentIsRefusedWithoutRequestAndCoversAllSettledMonthsOnRequest(): void
    {
        $this->employ('stala', 'Syntetická Stálá', '1985-05-12', 'female', 'hpp', 40,
            gross: static fn (string $p): int => 40_000);
        $this->processMonths(self::MONTHS);
        $employmentId = $this->people['stala']['person']['employment_id'];

        $refused = $this->prepareThroughAction($employmentId, 'e2e-eldp-stala-plain', requested: false);
        self::assertSame(422, $refused['status'], CanonicalJson::encode($refused['body']));
        self::assertSame('eldp_standalone_statement_not_applicable', $refused['body']['error']['code'] ?? $refused['body']['code'] ?? null, CanonicalJson::encode($refused['body']));
        $message = (string) ($refused['body']['error']['message'] ?? $refused['body']['message'] ?? '');
        self::assertStringContainsString('trvá', $message);
        self::assertStringContainsString('na výzvu ČSSZ', $message);
        self::assertSame('not_applicable', $this->frontendRemediationKind('eldp_standalone_statement_not_applicable'));

        $eligibility = $this->eldpService()->eligibility($this->supplierId, $employmentId, 2026);
        self::assertFalse($eligibility['allowed'] ?? null, CanonicalJson::encode($eligibility));

        $prepared = $this->prepareThroughAction($employmentId, 'e2e-eldp-stala-request', requested: true, receivedOn: '2026-05-04');
        self::assertSame(200, $prepared['status'], CanonicalJson::encode($prepared['body']));
        self::assertSame('2026-05-12', $prepared['body']['statement']['due_on']);
        self::assertSame(120, $prepared['body']['statement']['insurance_days']);

        $sections = $this->sections($employmentId);
        self::assertCount(1, $sections, CanonicalJson::encode($sections));
        self::assertSame('2026-01-01', $sections[0]['valid_from']);
        self::assertSame('2026-04-30', $sections[0]['valid_to']);
        self::assertSame(120, $sections[0]['insurance_days']);
        self::assertSame(160_000, $sections[0]['assessment_base_czk']);
        self::assertSame(0, $sections[0]['excluded_days_total']);
        self::assertSame($this->activityCode($employmentId) . '++', $sections[0]['code']);
        self::assertSame(160_000, $this->socialBases($employmentId));
    }

    /**
     * (b) Skončení HPP 15. 3. 2026, tedy před 1. 4. 2026: list vyhotoví
     * zaměstnavatel sám, bez výzvy, se lhůtou při skončení účasti
     * (§ 38 odst. 4 ve znění do 31. 12. 2025): do 1 měsíce po konečném
     * vyúčtování příjmů, nejpozději 31. 1. 2027. Konečné vyúčtování = březen
     * (poslední zúčtovaný měsíc, konec 31. 3.) → lhůta 30. 4. 2026.
     *
     * Ruční přepočet: 1. 1. až 15. 3. = 31 + 28 + 15 = 74 dnů pojištění,
     * vyměřovací základ 36 000 + 36 000 + 18 000 (mzda za půl března)
     * = 90 000 Kč. Typ listu 02 (skončení činnosti v roce).
     */
    public function testTerminationBeforeAprilIsPreparedWithoutRequestWithTerminationDeadline(): void
    {
        $this->employ('odchod', 'Syntetický Odchodový', '1990-01-15', 'male', 'hpp', 40, end: '2026-03-15',
            gross: static fn (string $p): ?int => match ($p) { '2026-01', '2026-02' => 36_000, '2026-03' => 18_000, default => null });
        $this->processMonths(['2026-01', '2026-02', '2026-03']);
        $employmentId = $this->people['odchod']['person']['employment_id'];

        $eligibility = $this->eldpService()->eligibility($this->supplierId, $employmentId, 2026);
        self::assertTrue($eligibility['allowed'] ?? null, CanonicalJson::encode($eligibility));

        $prepared = $this->prepareThroughAction($employmentId, 'e2e-eldp-odchod', requested: false);
        self::assertSame(200, $prepared['status'], CanonicalJson::encode($prepared['body']));
        $statement = $prepared['body']['statement'];
        // Typ 02: činnost ve vykazovaném roce skončila (Všeobecné zásady ELDP, Typ ELDP).
        self::assertSame('02', $statement['eldp_type']);
        self::assertSame(74, $statement['insurance_days']);
        self::assertSame('2026-04-30', $statement['due_on']);

        $sections = $this->sections($employmentId);
        self::assertCount(1, $sections, CanonicalJson::encode($sections));
        self::assertSame('2026-01-01', $sections[0]['valid_from']);
        self::assertSame('2026-03-15', $sections[0]['valid_to']);
        self::assertSame(74, $sections[0]['insurance_days']);
        self::assertSame(90_000, $sections[0]['assessment_base_czk']);
        self::assertSame(90_000, $this->socialBases($employmentId));
    }

    /**
     * (b') Skončení 15. 4. 2026, tedy až po 31. 3. 2026: na vztah přechodné
     * ustanovení nedopadá, údaje jdou měsíčním hlášením a list bez výzvy
     * nevznikne. Důvod musí jmenovat konkrétní den skončení, ne jen výčet
     * pravidel.
     */
    public function testTerminationAfterMarchNamesTheEndDateAndPointsToMonthlyReport(): void
    {
        $this->employ('pozdni', 'Syntetický Pozdní', '1992-02-02', 'male', 'hpp', 40, end: '2026-04-15',
            gross: static fn (string $p): int => $p === '2026-04' ? 20_000 : 40_000);
        $this->processMonths(self::MONTHS);
        $employmentId = $this->people['pozdni']['person']['employment_id'];

        $refused = $this->prepareThroughAction($employmentId, 'e2e-eldp-pozdni', requested: false);
        self::assertSame(422, $refused['status'], CanonicalJson::encode($refused['body']));
        $message = (string) ($refused['body']['error']['message'] ?? $refused['body']['message'] ?? '');
        self::assertStringContainsString('skončilo 15. 4. 2026', $message, $message);
        self::assertStringContainsString('opravným měsíčním hlášením', $message);
    }

    /**
     * (c) DPP s účastí jen v některých měsících, skončená 31. 3. 2026.
     *
     * Rozhodný příjem DPP 2026 = 12 000 Kč (ruleset `participation.dpp.minimum`;
     * § 7a zákona č. 187/2006 Sb.: účast v měsíci, kdy zúčtovaný příjem
     * dosáhne alespoň rozhodného příjmu). Leden 15 000 (účast), únor 8 000
     * (bez účasti, „X"), březen 12 000 (účast, právě na hranici).
     *
     * Ruční přepočet: doba pojištění jen leden a březen = 31 + 31 = 62 dnů,
     * vyměřovací základ 15 000 + 12 000 = 27 000 Kč, únor bez dnů i základu
     * a vyznačený jako měsíc bez pojištění. Vyloučené doby 0.
     */
    public function testAgreementParticipatesOnlyInMonthsReachingTheThreshold(): void
    {
        $this->employ('dpp', 'Syntetický Dohodář', '2001-04-30', 'male', 'dpp', 10, end: '2026-03-31',
            gross: static fn (string $p): ?int => ['2026-01' => 15_000, '2026-02' => 8_000, '2026-03' => 12_000][$p] ?? null,
            daily: 240,
            worked: static fn (string $p): array => array_slice(self::workdays($p), 0, 3));
        $this->processMonths(['2026-01', '2026-02', '2026-03']);
        $employmentId = $this->people['dpp']['person']['employment_id'];

        $prepared = $this->prepareThroughAction($employmentId, 'e2e-eldp-dpp', requested: false);
        self::assertSame(200, $prepared['status'], CanonicalJson::encode($prepared['body']));
        self::assertSame(62, $prepared['body']['statement']['insurance_days']);

        $sections = $this->sections($employmentId);
        self::assertSame(62, array_sum(array_column($sections, 'insurance_days')), CanonicalJson::encode($sections));
        self::assertSame(27_000, array_sum(array_column($sections, 'assessment_base_czk')));
        self::assertSame(0, array_sum(array_column($sections, 'excluded_days_total')));
        self::assertSame('2026-01-01', $sections[0]['valid_from']);
        self::assertSame('2026-03-31', $sections[count($sections) - 1]['valid_to']);
        $without = array_merge(...array_map(static fn (array $s): array => $s['months_without_insurance'] ?? [], $sections));
        self::assertContains(2, array_map(static fn (mixed $m): int => is_array($m) ? (int) ($m['month'] ?? 0) : (int) $m, $without), CanonicalJson::encode($sections));
    }

    /**
     * (d) Vyloučené doby: HPP 40 000 Kč, neplacené volno 16.–20. 3. a DPN
     * 8.–18. 4. 2026 (11 kalendářních dnů), list na výzvu za leden až duben.
     *
     * § 16 odst. 4 písm. a) zákona č. 155/1995 Sb.: vyloučenou dobou je doba
     * dočasné pracovní neschopnosti (celá, i dny s náhradou mzdy), pokud se
     * nekryje s celým měsícem s příjmem (Metodická pomůcka ČSSZ k ELDP,
     * př. 20) → 11 dnů. Neplacené volno vyloučenou dobou není a měsíc
     * s příjmem zůstává celý dobou pojištění (§ 11 odst. 2 zákona č. 155/1995 Sb.
     * krátí jen měsíc BEZ příjmu).
     *
     * Ruční přepočet: dny pojištění 31 + 28 + 31 + 30 = 120, vyloučené dny 11,
     * vyměřovací základ 40 000 + 40 000 + 30 909 (březen krácený o 5 dnů
     * neplaceného volna: 40 000 × 17/22, zaokrouhleno na celé Kč dolů účetní)
     * + 26 000 (duben za odpracované dny; náhrada mzdy za DPN do základu
     * nevstupuje) = 136 909 Kč.
     */
    public function testSicknessIsExcludedPeriodAndUnpaidLeaveKeepsTheMonth(): void
    {
        $this->employ('nemoc', 'Syntetická Nemocná', '1987-09-09', 'female', 'hpp', 40,
            gross: static fn (string $p): int => ['2026-03' => 30_909, '2026-04' => 26_000][$p] ?? 40_000);
        $this->absence('nemoc', 'unpaid_leave', '2026-03-16', '2026-03-20');
        $this->absence('nemoc', 'dpn', '2026-04-08', '2026-04-18');
        $this->processMonths(['2026-01', '2026-02', '2026-03']);
        $this->quarterAverages(2);
        $this->processMonths(['2026-04']);
        $employmentId = $this->people['nemoc']['person']['employment_id'];

        $prepared = $this->prepareThroughAction($employmentId, 'e2e-eldp-nemoc', requested: true, receivedOn: '2026-05-04');
        self::assertSame(200, $prepared['status'], CanonicalJson::encode($prepared['body']));

        $sections = $this->sections($employmentId);
        self::assertSame(120, array_sum(array_column($sections, 'insurance_days')), CanonicalJson::encode($sections));
        self::assertSame(11, array_sum(array_column($sections, 'excluded_days_total')), CanonicalJson::encode($sections));
        self::assertSame(136_909, array_sum(array_column($sections, 'assessment_base_czk')));
        self::assertSame(136_909, $this->socialBases($employmentId));
        $excluded = [];
        foreach ($sections as $section) {
            foreach (($section['excluded_days'] ?? []) as $kind => $days) {
                $excluded[$kind] = ($excluded[$kind] ?? 0) + (int) $days;
            }
        }
        self::assertSame(11, $excluded['docasNeschopnost'] ?? null, CanonicalJson::encode($excluded));
        self::assertSame(11, array_sum($excluded));
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function prepareThroughAction(int $employmentId, string $key, bool $requested, ?string $receivedOn = null): array
    {
        $action = $this->container->get(PayrollEldpAction::class);
        self::assertInstanceOf(PayrollEldpAction::class, $action);
        $response = $action->prepare(
            $this->request('POST', '/api/payroll/submissions/eldp')->withParsedBody([
                'employment_id' => $employmentId,
                'year' => 2026,
                'environment' => 'test',
                'excluded_days_confirmed' => true,
                'death_on' => null,
                'requested_by_authority' => $requested,
                'authority_request_received_on' => $receivedOn,
                'pension_status' => self::NO_PENSION,
                'note' => '',
                'idempotency_key' => $key,
            ]),
            new Response(),
        );
        self::assertNotSame(500, $response->getStatusCode(), (string) $response->getBody());

        return ['status' => $response->getStatusCode(), 'body' => $this->json($response)];
    }

    private function eldpService(): EldpStatementService
    {
        $service = $this->container->get(EldpStatementService::class);
        self::assertInstanceOf(EldpStatementService::class, $service);

        return $service;
    }

    /** @return list<array<string,mixed>> */
    private function sections(int $employmentId): array
    {
        try {
            $statement = $this->eldpService()->statement($this->supplierId, 'test', $employmentId, 2026);
        } catch (EldpValidationException $exception) {
            self::fail("ELDP nečitelný ({$exception->validationCode}): " . $exception->getMessage());
        }
        self::assertIsArray($statement);

        return $statement['payload']['eldp_sections'] ?? [];
    }

    private function activityCode(int $employmentId): string
    {
        return (string) $this->scalar(
            'SELECT activity_code FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ? ORDER BY effective_from DESC LIMIT 1',
            [$this->supplierId, $employmentId],
        );
    }

    /** Součet vyměřovacích základů sociálního pojištění vztahu ze schválených revizí (Kč). */
    private function socialBases(int $employmentId): int
    {
        $sum = 0;
        $stmt = $this->db->pdo()->prepare(
            'SELECT relationship.result_snapshot_json
               FROM payroll_statutory_relationship_results relationship
               JOIN payroll_run_revisions revision ON revision.supplier_id = relationship.supplier_id AND revision.id = relationship.revision_id
              WHERE relationship.supplier_id = ? AND relationship.employment_id = ?
                AND relationship.calculation_kind = "social_insurance" AND revision.status = "approved"',
        );
        $stmt->execute([$this->supplierId, $employmentId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $json) {
            $row = json_decode((string) $json, true);
            $sum += (int) ($row['assessment_base_minor_units'] ?? 0);
        }

        return intdiv($sum, 100);
    }

    /** Druh nápravy, kterým frontend kód blokace ELDP zobrazí (payrollRemediation.ts). */
    private function frontendRemediationKind(string $code): ?string
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/web/src/pages/payroll/payrollRemediation.ts');

        return preg_match('/\b' . preg_quote($code, '/') . ":\s*'([a-z_]+)'/", $source, $m) === 1 ? $m[1] : null;
    }

    /**
     * @param \Closure(string):?int $gross
     * @param (\Closure(string):list<string>)|null $worked
     */
    private function employ(
        string $key,
        string $name,
        string $birthDate,
        string $sex,
        string $relation,
        int $weekly,
        \Closure $gross,
        ?string $end = null,
        ?int $daily = null,
        ?\Closure $worked = null,
    ): void {
        $sequence = ++$this->sequence;
        [$employmentType, $relationType] = match ($relation) {
            'hpp' => ['hpp', 'employment'],
            'dpp' => ['dpp', 'dpp'],
        };
        $person = $this->createEmployment(
            $this->officeId,
            $name,
            $sequence,
            $employmentType,
            $relationType,
            $weekly,
            intdiv($weekly * 10_000, 40),
            true,
            '2026-01-01',
            healthTopUpResponsibility: 'employee',
        );
        [$first, $last] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, null, [
            'first_name' => $first,
            'last_name' => $last,
            'birth_date' => $birthDate,
            'sex' => $sex,
            'birth_number' => self::syntheticBirthNumber($birthDate, $sex, $sequence),
        ]);
        $this->db->pdo()->prepare('UPDATE payroll_employees SET birth_date = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$birthDate, $this->supplierId, $person['employee_id']]);
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), sprintf('5%020d', $sequence));
        $this->startOn($person['employment_id'], '2026-01-01');
        $daily ??= intdiv($weekly * 60, 5);
        foreach (self::MONTHS as $period) {
            $this->publishShiftMinutes($person['employment_id'], self::workdays($period), $daily);
        }
        if ($end !== null) {
            $this->endOn($person['employment_id'], $end);
        }
        $this->ensureRegularCalendar($person['employment_id'], $daily);
        $this->people[$key] = [
            'person' => $person,
            'end' => $end,
            'gross' => $gross,
            'daily' => $daily,
            'worked' => $worked ?? static fn (string $p): array => self::workdays($p),
            'absences' => [],
        ];
    }

    private function absence(string $key, string $type, string $from, string $to): void
    {
        $this->people[$key]['absences'][] = [
            'type' => $type,
            'from' => $from,
            'to' => $to,
            'decision' => $type === 'dpn' ? [
                'first_day_fully_worked' => false,
                'insurance_eligibility_confirmed' => true,
                'conflicting_benefit_excluded' => true,
            ] : [],
        ];
    }

    /** @param list<string> $periods */
    private function processMonths(array $periods): void
    {
        foreach ($periods as $period) {
            $periodStart = "{$period}-01";
            foreach ($this->people as $key => &$p) {
                $gross = ($p['gross'])($period);
                if ($gross === null) {
                    continue;
                }
                $absent = [];
                foreach ($p['absences'] as $index => $absence) {
                    if (substr($absence['from'], 0, 7) === $period && !isset($absence['id'])) {
                        $average = $absence['type'] === 'dpn' ? $this->latestAverageId($p['person']['employment_id']) : null;
                        $created = $this->requestAbsence($p['person']['employment_id'], $absence['type'], $absence['from'], $absence['to'], $average);
                        self::assertSame(201, $created->getStatusCode(), "Zaseknutí {$period} {$key}: nepřítomnost " . (string) $created->getBody());
                        $row = $this->json($created)['absence'];
                        $decision = $this->absences->decision(
                            $this->request('POST', '/api/payroll/absences/decision')->withParsedBody($absence['decision'] + [
                                'row_version' => $row['row_version'],
                                'decision' => 'approved',
                            ]),
                            new Response(),
                            ['id' => (string) $row['id']],
                        );
                        self::assertSame(200, $decision->getStatusCode(), "Zaseknutí {$period} {$key}: schválení nepřítomnosti " . (string) $decision->getBody());
                        $p['absences'][$index]['id'] = (int) $row['id'];
                    }
                    foreach (self::dateRange($absence['from'], $absence['to']) as $day) {
                        $absent[] = $day;
                    }
                }
                $this->db->pdo()->prepare(
                    'INSERT IGNORE INTO payroll_enforcement_person_month_evidence
                        (supplier_id, employee_id, period_start, claim_register_evidence_complete,
                         dependants_evidence_complete, spouse_evidence_complete, pension_evidence, updated_by)
                     VALUES (?, ?, ?, 1, 1, 1, "none", ?)',
                )->execute([$this->supplierId, $p['person']['employee_id'], $periodStart, $this->actors[0]]);
                $end = $p['end'];
                $worked = array_values(array_filter(
                    ($p['worked'])($period),
                    static fn (string $d): bool => ($end === null || $d <= $end) && !in_array($d, $absent, true),
                ));
                $time = $this->approveTimeMonth($p['person']['employment_id'], $period, $worked, dailyMinutes: $p['daily']);
                self::assertSame(200, $time->getStatusCode(), "Zaseknutí {$period} {$key}: docházka " . (string) $time->getBody());
                $this->createApprovedInput($p['person'], $this->baseComponentId, $gross * 100, "e2e-eldp-{$key}-{$period}", $periodStart);
            }
            unset($p);
            $payday = (new \DateTimeImmutable($periodStart))->modify('+1 month')->format('Y-m-10');
            $run = $this->runPayrollMonth($periodStart, $payday, $this->officeId, "e2e-eldp-{$period}");
            self::assertSame([], $run['blockers'], "Zaseknutí {$period}: výpočet " . CanonicalJson::encode($run['blockers']));
            self::assertSame([], $run['warnings'], "Zaseknutí {$period}: varování " . CanonicalJson::encode($run['warnings']));
            self::assertNotNull($run['approved'], "Zaseknutí {$period}: běh neschválen");
        }
    }

    private function quarterAverages(int $quarter): void
    {
        $batch = $this->container->get(AverageEarningBatchService::class);
        self::assertInstanceOf(AverageEarningBatchService::class, $batch);
        $page = $batch->page($this->supplierId, 2026, $quarter, 100);
        $ready = [];
        foreach ($page['items'] as $item) {
            if ($item['existing'] !== null) {
                continue;
            }
            self::assertTrue($item['ready'], "Průměr Q{$quarter} nejde navrhnout: " . CanonicalJson::encode($item['blockers']));
            $ready[] = ['employment_id' => (int) $item['employment_id'], 'input_version' => (string) $item['input_version']];
        }
        self::assertNotSame([], $ready);
        $batch->createBatch($this->supplierId, 2026, $quarter, $ready, $this->actors[0]);
    }

    private function latestAverageId(int $employmentId): ?int
    {
        $id = $this->scalar(
            'SELECT id FROM payroll_average_earning_snapshots WHERE supplier_id = ? AND employment_id = ? AND status = "approved"
              ORDER BY applicable_year DESC, applicable_quarter DESC, id DESC LIMIT 1',
            [$this->supplierId, $employmentId],
        );

        return $id === false || $id === null ? null : (int) $id;
    }

    /** @param list<string> $dates */
    private function publishShiftMinutes(int $employmentId, array $dates, int $minutes): void
    {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_shifts
                (supplier_id, employment_id, series_key, starts_at_utc, ends_at_utc, timezone_name,
                 break_minutes, status, published_by, published_at)
             VALUES (?, ?, ?, ?, ?, "Europe/Prague", 0, "published", ?, NOW())',
        );
        foreach ($dates as $date) {
            $start = new \DateTimeImmutable("{$date} 06:00:00");
            $statement->execute([$this->supplierId, $employmentId, "e2e-eldp-{$employmentId}-{$date}",
                $start->format('Y-m-d H:i:s'), $start->modify("+{$minutes} minutes")->format('Y-m-d H:i:s'), $this->actors[0]]);
        }
    }

    private function startOn(int $employmentId, string $start): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE payroll_employments SET start_date = ?, actual_start_date = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$start, $start, $this->supplierId, $employmentId]);
        $pdo->prepare(
            'UPDATE payroll_employment_terms SET effective_from = ?, planned_start_on = ?, actual_start_on = ?
              WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$start, $start, $start, $this->supplierId, $employmentId]);
        $pdo->prepare(
            'INSERT INTO payroll_employment_events
                (supplier_id, employment_id, event_type, from_status, to_status, effective_on, created_by)
             VALUES (?, ?, "created", NULL, "active", ?, ?)',
        )->execute([$this->supplierId, $employmentId, $start, $this->actors[0]]);
    }

    private function endOn(int $employmentId, string $end): void
    {
        $employments = $this->container->get(PayrollEmploymentRepository::class);
        self::assertInstanceOf(PayrollEmploymentRepository::class, $employments);
        $version = (int) $this->scalar('SELECT row_version FROM payroll_employments WHERE supplier_id = ? AND id = ?', [$this->supplierId, $employmentId]);
        $employments->transition($this->supplierId, $employmentId, 'ended', $version, $end, null, $this->actors[0], null, null);
        $termination = $this->container->get(PayrollEmploymentTerminationService::class);
        self::assertInstanceOf(PayrollEmploymentTerminationService::class, $termination);
        $termination->save($this->supplierId, $employmentId, ['termination_method' => 'employee_notice', 'legal_ground' => 'none'], $this->actors[0]);
    }
}
