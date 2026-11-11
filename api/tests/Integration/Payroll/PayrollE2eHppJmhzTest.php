<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunReadinessService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzContentCorrectionSubmissionService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzEmployerAnnualEvidenceService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenPayloadReader;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Slim\Psr7\Response;

/**
 * HPP v prosinci 2026 až po zmrazené měsíční hlášení, oprava schválené mzdy
 * s opravným hlášením a leden 2027, který se zastaví na nevyhlášených
 * číselnících 2027.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eHppJmhzTest extends TestCase
{
    use PayrollFullFlowTrait;

    public static string $now = '2027-01-12 12:00:00';

    private int $officeId;
    private int $baseComponentId;

    protected function setUp(): void
    {
        self::$now = '2027-01-12 12:00:00';
        $this->bootPayrollFullFlow();
        $this->container->set(ClockInterface::class, new class () implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable(PayrollE2eHppJmhzTest::$now, new \DateTimeZone('Europe/Prague'));
            }
        });
        $this->officeId = $this->createOffice('DEC', 'Syntetická účtárna prosinec', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->baseComponentId = $this->createComponent('MZDA_MESICNI_DEC', 'base_wage', 'regular');
        $this->mappings()->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testDecemberWithCommissionReachesFrozenSubmission(): void
    {
        $person = $this->hireDecember();
        $commission = $this->createComponent('PROVIZE_DEC', 'bonus', 'one_off');
        $this->createApprovedInput($person, $this->baseComponentId, 4_500_000, 'dec-base', '2026-12-01');
        $this->createApprovedInput($person, $commission, 300_000, 'dec-commission', '2026-12-01');

        $readiness = $this->container->get(PayrollRunReadinessService::class)->inspect($this->supplierId, '2026-12-01', '2027-01-14');
        $findings = array_column($readiness['findings'], null, 'code');
        // Prosincové roční údaje zaměstnavatele a provize bez zařazení JMHZ
        // ohlásí kontrola před během, ne až příprava hlášení po schválení.
        foreach (['jmhz_employer_annual_collective_agreement_missing', 'jmhz_employer_annual_ownership_missing', 'jmhz_employer_annual_ozp_summary_missing'] as $code) {
            self::assertArrayHasKey($code, $findings, CanonicalJson::encode($readiness['findings']));
            self::assertSame('/payroll/settings?tab=submissions#jmhz-employer-annual-evidence', $findings[$code]['remediation_path']);
            self::assertStringContainsString('Roční údaje zaměstnavatele pro JMHZ', $findings[$code]['message']);
        }
        self::assertArrayHasKey('component_jmhz_mapping_missing', $findings);
        self::assertContains(
            "/payroll/components?tab=catalog&component={$commission}&panel=jmhz",
            array_column($findings['component_jmhz_mapping_missing']['entities'], 'remediation_path'),
        );

        $run = $this->runPayrollMonth('2026-12-01', '2027-01-14', $this->officeId, 'dec');
        self::assertNotNull($run['approved'], CanonicalJson::encode($run['blockers']) . CanonicalJson::encode($run['warnings']));
        $revisionId = (int) $run['approved']->revision['id'];
        $blocked = $this->prepareJmhz($revisionId, 'dec-unmapped');
        self::assertSame('blocked', $blocked['body']['readiness_status']);
        self::assertContains('component_jmhz_mapping_missing', array_column($blocked['body']['issues'], 'code'));

        // Náprava podle odkazů: provize jako odměna (10330), roční údaje.
        $this->mappings()->put($this->supplierId, $commission, '10330', null, $this->actors[0]);
        $this->employerAnnualEvidence();
        $preparation = $this->prepareJmhz($revisionId, 'dec-mapped');
        self::assertSame('source_ready', $preparation['body']['readiness_status'], CanonicalJson::encode($preparation['body']));
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame('dry_run_valid', $tested['body']['status'], CanonicalJson::encode($tested['body']['controls'] ?? $tested['body']));
        self::assertTrue($tested['body']['controls']['submittable']);
        $xml = (string) preg_replace('/>\s+</', '><', (string) $tested['body']['xml']);
        // Ruční přepočet: hrubá 45 000 + provize 3 000 = 48 000 (10286,
        // 10328); SP 48 000 × 7,1 % = 3 408; ZP 6 480 / 3 = 2 160 (zaměstnavatel
        // 4 320); záloha 7 200 − 2 570 = 4 630; čistá 37 802. Průměr
        // 135 000 Kč / 504 h = 267,857 → 267,86 Kč/h (10345).
        foreach ([
            '<form:zuctovanoCelkem>48000</form:zuctovanoCelkem>',
            '<form:mzdaZuctovana>48000</form:mzdaZuctovana>',
            '<form:tarif>45000</form:tarif>',
            '<form:odmenyPravidelne>3000</form:odmenyPravidelne>',
            '<form:odmenyNepravidelne>0</form:odmenyNepravidelne>',
            '<form:vydelekPrumernyHod>267.86</form:vydelekPrumernyHod>',
            '<form:vypoctenaZaloha>7200</form:vypoctenaZaloha>',
            '<form:danZalohaPoSleve>4630</form:danZalohaPoSleve>',
            '<form:mzdaCista>37802</form:mzdaCista>',
            '<form:zdravotniPojisteni>4320</form:zdravotniPojisteni>',
            '<form:zdravotniPojisteni>2160</form:zdravotniPojisteni>',
            '<typPodani>R</typPodani>',
            '<mesic>12</mesic>',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $xml);
        }

        $frozen = $this->freezeJmhzSubmission((int) $preparation['body']['id'], $this->officeId);
        self::assertSame(201, $frozen['status'], CanonicalJson::encode($frozen['body']));
        $regularId = (int) $frozen['body']['submission_id'];
        $this->acceptJmhzSubmission($regularId);

        // Oprava schválené mzdy: dodatečná nepravidelná odměna 2 000 Kč.
        $bonus = $this->createComponent('ODMENA_MIMORADNA_DEC', 'bonus', 'one_off');
        $this->mappings()->put($this->supplierId, $bonus, '10331', null, $this->actors[0]);
        $this->createApprovedInput($person, $bonus, 200_000, 'dec-bonus', '2026-12-01');
        $corrected = $this->correctPayrollRun($run['approved']->run, 'dec-fix', 'Dodatečná odměna za prosinec.');
        self::assertSame('approved', $corrected->run['status']);
        $correctedPerson = $corrected->revision['result_snapshot']['statutory']['people'][0];
        // 50 000: SP 3 550; ZP 6 750 / 3 = 2 250; záloha 7 500 − 2 570 = 4 930;
        // čistá 50 000 − 3 550 − 2 250 − 4 930 = 39 270.
        self::assertSame(3_927_000, $correctedPerson['net_payable_minor_units']);
        $repared = $this->prepareJmhz((int) $corrected->revision['id'], 'dec-fix');
        self::assertSame('source_ready', $repared['body']['readiness_status'], CanonicalJson::encode($repared['body']));

        $corrections = $this->container->get(JmhzContentCorrectionSubmissionService::class);
        self::assertInstanceOf(JmhzContentCorrectionSubmissionService::class, $corrections);
        $candidates = $corrections->candidates($this->supplierId, 'test', $regularId, (int) $repared['body']['id'], $this->officeId);
        self::assertCount(1, $candidates['forms'], CanonicalJson::encode($candidates));
        $correction = $corrections->freeze(
            $this->supplierId,
            'test',
            $regularId,
            (int) $repared['body']['id'],
            [(string) $candidates['forms'][0]['employment_external_identifier']],
            $this->actors[0],
            $this->officeId,
        );
        self::assertSame('correction', $correction['submission_kind']);
        self::assertSame($regularId, $correction['corrects_submission_id']);
        $reader = $this->container->get(JmhzFrozenPayloadReader::class);
        self::assertInstanceOf(JmhzFrozenPayloadReader::class, $reader);
        $original = (string) preg_replace('/>\s+</', '><', $reader->bytes($this->supplierId, 'test', $regularId));
        $correctionXml = (string) preg_replace('/>\s+</', '><', $reader->bytes($this->supplierId, 'test', (int) $correction['submission_id']));
        self::assertStringContainsString('<typPodani>O</typPodani>', $correctionXml);
        self::assertStringContainsString('<typFormulare>O</typFormulare>', $correctionXml);
        self::assertSame(self::formGuid($original), self::formGuid($correctionXml), 'Opravný formulář navazuje na GUID původního.');
        self::assertStringContainsString('<form:odmenyNepravidelne>2000</form:odmenyNepravidelne>', $correctionXml);
        self::assertStringContainsString('<form:mzdaCista>39270</form:mzdaCista>', $correctionXml);
    }

    /**
     * Leden 2027: číselníky obcí a států 2027 nejsou vyhlášené. Kontrola
     * před během nesmí posílat doplňovat pracoviště, příprava se zastaví
     * a blokace musí říct proč a že to nejde opravit v údajích osob.
     */
    public function testJanuary2027StopsWithUnderstandableBlocker(): void
    {
        self::$now = '2027-02-05 12:00:00';
        $person = $this->createEmployment($this->officeId, 'Syntetický Lednový', 2, 'hpp', 'employment', 40, 10_000, true, '2027-01-01');
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => 'Syntetický',
            'last_name' => 'Lednový',
            'birth_date' => '1983-03-03',
            'sex' => 'male',
            'birth_number' => self::syntheticBirthNumber('1983-03-03', 'male', 2),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic(2), sprintf('2%020d', 2));
        $workdays = self::workdays('2027-01');
        $this->publishShifts($person['employment_id'], $workdays);
        self::assertSame(200, $this->approveTimeMonth($person['employment_id'], '2027-01', $workdays)->getStatusCode());
        $this->createApprovedInput($person, $this->baseComponentId, 4_500_000, 'jan-base', '2027-01-01');

        $readiness = $this->container->get(PayrollRunReadinessService::class)->inspect($this->supplierId, '2027-01-01', '2027-02-12');
        $codes = array_column($readiness['findings'], 'code');
        self::assertNotContains('jmhz_workplace_codebooks_unverified', $codes, 'Pracoviště je vyplněné, chybí jen číselníky 2027.');
        // Počáteční stavy roku 2027 chybí: kontrola to předpoví jako blokaci
        // schválení s odkazem na kartu vztahu (výpočet osobu jinak vyřadí).
        $statutory = array_column($readiness['findings'], null, 'code')['statutory_calculation_manual_review'] ?? null;
        self::assertNotNull($statutory, CanonicalJson::encode($readiness['findings']));
        self::assertSame("/payroll/people?person={$person['employee_id']}&panel=opening_balances", $statutory['entities'][0]['remediation_path']);
        self::assertStringContainsString('Počáteční stavy', $statutory['entities'][0]['message']);
    }

    private static function formGuid(string $xml): string
    {
        self::assertSame(1, preg_match('#<formularOsoby[^>]*><hlavicka><idFormulare>([^<]+)</idFormulare>#', $xml, $match), 'Formulář nemá GUID.');

        return $match[1];
    }

    /** @return array{employee_id:int,employment_id:int,name:string} */
    private function hireDecember(): array
    {
        $person = $this->createEmployment($this->officeId, 'Syntetická Prosincová', 1, 'hpp', 'employment', 40, 10_000, true, '2026-12-01');
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => 'Syntetická',
            'last_name' => 'Prosincová',
            'birth_date' => '1986-06-06',
            'sex' => 'female',
            'birth_number' => self::syntheticBirthNumber('1986-06-06', 'female', 1),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic(1), sprintf('2%020d', 1));
        $workdays = self::workdays('2026-12');
        $this->publishShifts($person['employment_id'], $workdays);
        self::assertSame(200, $this->approveTimeMonth($person['employment_id'], '2026-12', $workdays)->getStatusCode());
        $this->fourthQuarterAverage($person['employment_id']);

        return $person;
    }

    private function fourthQuarterAverage(int $employmentId): void
    {
        $created = $this->absences->createAverage(
            $this->request('POST', '/api/payroll/absences/average')->withParsedBody([
                'employment_id' => $employmentId,
                'applicable_year' => 2026,
                'applicable_quarter' => 4,
                'decisive_from' => '2026-07-01',
                'decisive_to' => '2026-09-30',
                'gross_earnings_minor' => 13_500_000,
                'longer_period_allocated_minor' => 0,
                'worked_minutes' => 30_240,
                'worked_days' => 63,
                'probable_hourly_minor' => null,
                'rationale' => null,
            ]),
            new Response(),
        );
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $average = $this->json($created)['snapshot'];
        $approved = $this->absences->approveAverage(
            $this->request('POST', '/api/payroll/absences/average/approve')->withParsedBody(['row_version' => $average['row_version']]),
            new Response(),
            ['id' => (string) $average['id']],
        );
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
    }

    private function employerAnnualEvidence(): void
    {
        $annual = $this->container->get(JmhzEmployerAnnualEvidenceService::class);
        self::assertInstanceOf(JmhzEmployerAnnualEvidenceService::class, $annual);
        $annual->save($this->supplierId, 2026, [
            'collective_agreement_types' => ['0'],
            'ownership_form' => '1',
            'average_headcount' => '1',
            'average_disabled_headcount' => '0',
        ], $this->actors[0]);
    }

    private function mappings(): PayrollComponentJmhzMappingRepository
    {
        $repository = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $repository);

        return $repository;
    }
}
