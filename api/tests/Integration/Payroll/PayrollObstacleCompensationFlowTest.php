<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Placené překážky v práci od zápisu nepřítomnosti po měsíční hlášení.
 *
 * Účetní zapíše v absencích návštěvu lékaře, prostoj (§ 207 písm. a) ZP,
 * 80 %) a jinou překážku na straně zaměstnavatele (§ 208 ZP, 100 %),
 * schválí je, potvrdí docházku a spustí mzdu. Ze schválení vznikne náhrada
 * mzdy z průměrného výdělku a měsíční hlášení ji ukáže v kolonkách náhrad
 * za překážky (10340, 10341) a hodiny v 10471/10472 i v placených
 * neodpracovaných hodinách 10276. XML musí projít XSD i katalogem kontrol.
 *
 * Průměr je 120 000 Kč / 160 h = 750 Kč/h.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollObstacleCompensationFlowTest extends TestCase
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
        $this->baseComponentId = $this->createComponent('MZDA_MESICNI_PREKAZKY', 'base_wage', 'regular');
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

    public function testHourlyObstacleUsesCurrentFloorAndRunAcceptsTrustedProof(): void
    {
        $person = $this->hire('Syntetická Překážka', 'female', '1987-05-12');
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE payroll_employment_terms SET remuneration_type = "hourly", monthly_gross_minor = NULL, hourly_rate_minor = 13000, remuneration_basis_kind = "contract", remuneration_basis = "Syntetické podmínky", weekly_hours = 37.5, workload_basis_points = 10000 WHERE supplier_id = ? AND employment_id = ?')->execute([$this->supplierId, $person['employment_id']]);
        $pdo->prepare('UPDATE payroll_average_earning_snapshots SET average_hourly_minor = 13440 WHERE supplier_id = ? AND id = ?')->execute([$this->supplierId, $person['average_id']]);
        $this->obstacle($person, 'employer_obstacle', 'downtime', '2026-07-13', '2026-07-13');
        $stmt = $pdo->prepare('SELECT input.amount_minor, input.source_snapshot_json FROM payroll_inputs input JOIN payroll_component_definitions component ON component.supplier_id = input.supplier_id AND component.id = input.component_id WHERE input.supplier_id = ? AND input.employment_id = ? AND component.code = "NAHRADA_MZDY_PREKAZKY_ZAMESTNAVATEL"');
        $stmt->execute([$this->supplierId, $person['employment_id']]);
        $input = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(91800, (int) $input['amount_minor']);
        $trace = json_decode((string) $input['source_snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(14340, $trace['hourly_average_floor'][0]['average_hourly_minor']);
        $snapshot = $this->container->get(\MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder::class)->build($this->supplierId, '2026-07-01', '2026-08-14');
        self::assertNotContains('hourly_average_minimum_evidence_missing', array_map(static fn($validation): string => $validation->code, $snapshot->validations));
    }

    public function testDoctorDowntimeAndOtherEmployerObstacleReachPayAndSubmission(): void
    {
        $person = $this->hire('Olga Překážková', 'female', '1987-05-12');
        $doctor = $this->obstacle($person, 'employee_obstacle', 'medical_examination', '2026-07-08', '2026-07-08');
        $downtime = $this->obstacle($person, 'employer_obstacle', 'downtime', '2026-07-13', '2026-07-14');
        $other = $this->obstacle($person, 'employer_obstacle', 'other_employer_obstacle', '2026-07-20', '2026-07-20');
        self::assertSame(8_000, $downtime['compensation_rate_basis_points']);
        self::assertSame(10_000, $other['compensation_rate_basis_points']);
        self::assertSame(10_000, $doctor['compensation_rate_basis_points']);

        $this->approveMonth(
            $person['employment_id'],
            self::workdays(self::PERIOD, ['2026-07-08', '2026-07-13', '2026-07-14', '2026-07-20']),
        );
        $this->pay($person, 4_500_000);

        [$xml, $revisionId] = $this->submission('obstacles');

        // Náhrady: lékař 8 h × 750 = 6 000 Kč (strana zaměstnance), prostoj
        // 16 h × 750 × 80 % = 9 600 Kč a § 208 8 h × 750 = 6 000 Kč (strana
        // zaměstnavatele, úhrnem 15 600 Kč). Úhrn náhrad 10337 = 21 600 Kč.
        self::assertStringContainsString('<form:mzdyZuctovane>21600</form:mzdyZuctovane>', $xml);
        self::assertStringContainsString('<form:prekazkyZamestnavatel>15600</form:prekazkyZamestnavatel>', $xml);
        self::assertStringContainsString('<form:prekazkyZamestnanec>6000</form:prekazkyZamestnanec>', $xml);
        // Hodiny: 32 h překážek + svátek 6. 7. v neodpracovaných s náhradou.
        self::assertStringContainsString('<form:hodinyNeodpracCelkem>40.000</form:hodinyNeodpracCelkem>', $xml);
        self::assertStringContainsString('<form:hodinyNeodpracNahrada>40.000</form:hodinyNeodpracNahrada>', $xml);
        self::assertStringContainsString(
            '<form:prekazkyVPraci><form:prekazkaZamestnanec>8.000</form:prekazkaZamestnanec>'
                . '<form:prekazkaZamestnavatel>24.000</form:prekazkaZamestnavatel></form:prekazkyVPraci>',
            $xml,
        );
        // Překážky s náhradou mzdy nejsou vyloučenou dobou ELDP ani vyloučenými dny § 18.
        self::assertStringContainsString(
            '<form:pocetDnu>31</form:pocetDnu><form:vymerovaciZaklad>66600</form:vymerovaciZaklad>'
                . '<form:vylouceneDny><form:vylouceneDobyCelkem>0</form:vylouceneDobyCelkem>'
                . '<form:vyloucenePar18>0</form:vyloucenePar18><form:omluvenaNepritomnost>0</form:omluvenaNepritomnost>',
            $xml,
        );

        // Výplata: náhrady jsou na pásce vlastními řádky vedle mzdy.
        $inputs = $this->db->pdo()->prepare(
            'SELECT component.code, SUM(input.amount_minor)
               FROM payroll_inputs input
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id AND component.id = input.component_id
              WHERE input.supplier_id = ? AND input.employment_id = ? AND input.status IN ("approved", "locked")
              GROUP BY component.code ORDER BY component.code'
        );
        $inputs->execute([$this->supplierId, $person['employment_id']]);
        self::assertSame([
            'MZDA_MESICNI_PREKAZKY' => 4_500_000,
            'NAHRADA_MZDY_PREKAZKY_ZAMESTNANEC' => 600_000,
            'NAHRADA_MZDY_PREKAZKY_ZAMESTNAVATEL' => 1_560_000,
        ], array_map('intval', $inputs->fetchAll(\PDO::FETCH_KEY_PAIR)));
        $health = $this->healthResultSnapshot($revisionId);
        self::assertSame(6_660_000, $health['assessment_base_minor_units'] ?? null, CanonicalJson::encode($health));
    }

    /**
     * Nízká mzda a prostoj: vyměřovací základ klesne pod minimum kvůli
     * překážce na straně zaměstnavatele. Bez prohlášení v měsíční evidenci
     * doplatek hradí zaměstnavatel (§ 3 odst. 10 zák. č. 592/1992 Sb.),
     * zaměstnanec platí jen svých 4,5 %.
     *
     * Základ 10 000 + 9 600 = 19 600 Kč, minimum 22 400 Kč, rozdíl 2 800 Kč.
     */
    public function testDowntimeBelowTheHealthMinimumIsToppedUpByTheEmployer(): void
    {
        $person = $this->hire('Petr Prostojový', 'male', '1983-09-09', withHealthMonthEvidence: false);
        $downtime = $this->obstacle($person, 'employer_obstacle', 'downtime', '2026-07-13', '2026-07-14');
        $this->approveMonth(
            $person['employment_id'],
            self::workdays(self::PERIOD, ['2026-07-13', '2026-07-14']),
        );
        $this->pay($person, 1_000_000);

        [$xml, $revisionId] = $this->submission('downtime-minimum');

        $health = $this->healthResultSnapshot($revisionId);
        self::assertSame(1_960_000, $health['assessment_base_minor_units'] ?? null, CanonicalJson::encode($health));
        self::assertSame(0, $health['employee_minimum_top_up_minor_units'] ?? null);
        self::assertGreaterThan(0, $health['employer_minimum_top_up_minor_units'] ?? 0);
        self::assertSame('employer_obstacle_verified', $health['top_up_responsibility'] ?? null);
        self::assertSame('derived_employer_obstacle', $health['top_up_responsibility_source'] ?? null);
        self::assertSame("absence:{$downtime['id']}", $health['top_up_responsibility_evidence_reference'] ?? null);
        // Zaměstnanec 4,5 % z 19 600 Kč = 882 Kč, doplatek do minima nese zaměstnavatel.
        self::assertStringContainsString(
            '<form:zdravPojZamestnanec><form:zdravotniPojisteni>882</form:zdravotniPojisteni></form:zdravPojZamestnanec>',
            $xml,
        );
    }

    /**
     * @param array{employee_id:int,employment_id:int,name:string,average_id:?int,sequence:int} $person
     * @return array<string,mixed>
     */
    private function obstacle(array $person, string $type, string $kind, string $from, string $to): array
    {
        $created = $this->requestAbsence(
            $person['employment_id'],
            $type,
            $from,
            $to,
            $person['average_id'],
            ['obstacle_kind' => $kind],
        );
        self::assertSame(201, $created->getStatusCode(), 'Zaseknutí: zápis překážky. ' . (string) $created->getBody());
        $absence = $this->json($created)['absence'];
        $decision = $this->absences->decision(
            $this->request('POST', '/api/payroll/absences/decision')->withParsedBody([
                'row_version' => $absence['row_version'],
                'decision' => 'approved',
            ]),
            new \Slim\Psr7\Response(),
            ['id' => (string) $absence['id']],
        );
        self::assertSame(200, $decision->getStatusCode(), 'Zaseknutí: schválení překážky. ' . (string) $decision->getBody());
        $body = $this->json($decision);
        self::assertNotEmpty($body['calculation']['created'] ?? [], 'Schválení překážky nezaložilo náhradu mzdy.');

        return $body['absence'];
    }

    /** @return array{employee_id:int,employment_id:int,name:string,average_id:?int,sequence:int} */
    private function hire(string $name, string $sex, string $birthDate, bool $withHealthMonthEvidence = true): array
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
            withHealthMonthEvidence: $withHealthMonthEvidence,
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

    /** @param list<string> $workedDates */
    private function approveMonth(int $employmentId, array $workedDates): void
    {
        $response = $this->approveTimeMonth($employmentId, self::PERIOD, $workedDates);
        self::assertSame(200, $response->getStatusCode(), 'Zaseknutí: schválení docházky. ' . (string) $response->getBody());
    }

    /** @param array{employee_id:int,employment_id:int,name:string} $person */
    private function pay(array $person, int $amountMinor): void
    {
        $this->createApprovedInput($person, $this->baseComponentId, $amountMinor, 'base-' . $person['employment_id'], self::PERIOD_START);
    }

    /** @return array{0:string,1:int} XML bez mezer mezi značkami a id revize */
    private function submission(string $scenario): array
    {
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, "obstacle-{$scenario}");
        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet mzdy. ' . CanonicalJson::encode($run['blockers']));
        self::assertSame([], $run['warnings'], 'Zaseknutí: varování běhu. ' . CanonicalJson::encode($run['warnings']));
        self::assertNotNull($run['approved']);
        $revisionId = (int) $run['approved']->revision['id'];

        $preparation = $this->prepareJmhz($revisionId, "obstacle-{$scenario}");
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

        return [(string) preg_replace('/>\s+</', '><', (string) $tested['body']['xml']), $revisionId];
    }
}
