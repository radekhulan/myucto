<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollDependantAction;
use MyInvoice\Action\Payroll\PayrollMonthlyChecklistPrepareAction;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunReadinessImpact;
use MyInvoice\Service\Payroll\Run\PayrollRunReadinessService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationGate;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollMonthlyChecklistService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Slim\Psr7\Response;

/**
 * Příprava měsíčního hlášení z měsíčního přehledu podání („Jen připravit"):
 * blokace musí říct, co opravit a zda oprava stojí opravnou revizi běhu,
 * neúspěšná příprava nesmí změnit stav řádku a oprava údaje čteného až při
 * přípravě se musí projevit hned při dalším pokusu.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eFixJmhzPreparationTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-07';
    private const PERIOD_START = '2026-07-01';
    private const PAYDAY = '2026-08-14';

    public static string $now = '2026-08-10 12:00:00';

    private int $baseComponentId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        self::$now = '2026-08-10 12:00:00';
        $this->bootPayrollFullFlow();
        $this->container->set(ClockInterface::class, new class () implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable(PayrollE2eFixJmhzPreparationTest::$now, new \DateTimeZone('Europe/Prague'));
            }
        });
        $this->configureHealthInsuranceOutput();
        $this->baseComponentId = $this->createComponent('MZDA_MESICNI_FIX', 'base_wage', 'regular');
        $this->mappings()->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * B1 + B5: neplatný variabilní symbol účtárny. Kontrola před během ho
     * předpoví, blokace přípravy řekne přesný důvod, kde se opravuje a že po
     * opravě je potřeba opravná revize; po opravě v nastavení rozliší
     * „už opraveno, zbývá revize".
     */
    public function testInvalidOfficeVariableSymbolIsPredictedExplainedAndNeedsRevision(): void
    {
        // 1100000012 neprojde Luhnovým součtem (kontrolní číslice).
        $officeId = $this->createOffice('FIX', 'Syntetická účtárna', '1100000012');
        $this->configureSocialInsuranceOutput($officeId);
        $person = $this->hire($officeId, 'Syntetická Červencová', 'female', '1988-04-12');
        $this->createApprovedInput($person, $this->baseComponentId, 4_500_000, 'fix-base', self::PERIOD_START);

        $finding = $this->readinessFinding('jmhz_office_variable_symbol_invalid');
        self::assertNotNull($finding, 'Kontrola před během neplatný variabilní symbol nepředpověděla.');
        self::assertSame(PayrollRunReadinessImpact::IMPACT_REVISION, $finding['impact']);
        self::assertSame('/payroll/settings?tab=employer#payroll-employer-offices', $finding['remediation_path']);
        self::assertStringContainsString('1100000012', $finding['message']);
        self::assertStringContainsString('kontrolní číslice', $finding['message']);
        self::assertStringContainsString('Syntetická účtárna (FIX)', $finding['message']);
        self::assertStringContainsString('Spravovat účinné registrace', $finding['message']);

        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $officeId, 'fix-vs');
        self::assertNotNull($run['approved'], CanonicalJson::encode($run['blockers']) . CanonicalJson::encode($run['warnings']));

        $blocked = $this->prepareFromChecklist();
        self::assertSame(409, $blocked['status'], CanonicalJson::encode($blocked['body']));
        $message = (string) $blocked['body']['error']['message'];
        self::assertStringContainsString('je vyplněný, ale neplatný', $message);
        self::assertStringContainsString('kontrolní číslice', $message);
        self::assertStringContainsString('Syntetická účtárna (FIX)', $message);
        self::assertStringContainsString('Spravovat účinné registrace', $message);
        self::assertStringContainsString('„Vyžádat opravu" a „Otevřít opravu"', $message);
        self::assertStringNotContainsString('Chybí zákonný údaj', $message);
        self::assertStringNotContainsString('zvýrazněná pole', $message);
        $blocker = $blocked['body']['error']['details']['blockers'][0];
        self::assertSame('jmhz_office_variable_symbol_invalid', $blocker['code']);
        self::assertTrue($blocker['needs_revision']);
        self::assertFalse($blocker['fixed_in_source']);
        self::assertSame('/payroll/runs?period=2026-07', $blocker['revision_path']);
        self::assertSame('/payroll/settings?tab=employer#payroll-employer-offices', $blocker['remediation_path']);
        self::assertSame('Syntetická účtárna (FIX)', $blocker['label']);
        self::assertStringNotContainsString('už opravený', $message);

        // Oprava v nastavení (účinná registrace účtárny): blokace trvá, protože
        // hlášení čte zmrazený snímek, ale hláška už ví, že zbývá jen revize.
        // Registrace účtárny jsou připisované: opravený symbol je nová účinná verze.
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_office_registration_versions
                (supplier_id, office_id, effective_from, social_security_variable_symbol, source_reference)
             VALUES (?, ?, "2026-02-01", "1100001237", "synthetic:fix")',
        )->execute([$this->supplierId, $officeId]);
        $still = $this->prepareFromChecklist();
        self::assertSame(409, $still['status'], CanonicalJson::encode($still['body']));
        self::assertTrue($still['body']['error']['details']['blockers'][0]['fixed_in_source']);
        self::assertStringContainsString('už opravený', (string) $still['body']['error']['message']);
        self::assertStringContainsString('zbývá jen opravná revize', (string) $still['body']['error']['message']);
        self::assertNull($this->readinessFinding('jmhz_office_variable_symbol_invalid'));

        $corrected = $this->correctPayrollRun($run['approved']->run, 'fix-vs-corr', 'Opravený variabilní symbol účtárny.');
        self::assertSame('approved', $corrected->run['status']);
        $prepared = $this->prepareFromChecklist();
        self::assertSame(201, $prepared['status'], CanonicalJson::encode($prepared['body']));
    }

    /**
     * B1 + B5: nezodpovězená otázka, zda tytéž děti vyživuje i jiná osoba.
     * Kontrola před během ji předpoví s dopadem „opravná revize", blokace
     * přípravy to řekne a po zodpovězení ví, že zbývá revize.
     */
    public function testUnansweredCaregiverQuestionIsPredictedAndNeedsRevision(): void
    {
        $officeId = $this->createOffice('FIX', 'Syntetická účtárna', '1100001237');
        $this->configureSocialInsuranceOutput($officeId);
        $person = $this->hire($officeId, 'Syntetická Rodičovská', 'female', '1987-10-10');
        $child = $this->createChild($person['employee_id'], 'Syntetické Dítě', '2018-03-03', 1);
        $this->claimChild($person['employee_id'], $child, 1, ['other_household_caregiver_status' => 'unknown']);
        $this->createApprovedInput($person, $this->baseComponentId, 4_500_000, 'fix-child', self::PERIOD_START);

        $finding = $this->readinessFinding('jmhz_scenario1_child_credit_caregiver_unknown');
        self::assertNotNull($finding, 'Kontrola před během nezodpovězenou otázku k dětem nepředpověděla.');
        self::assertSame(PayrollRunReadinessImpact::IMPACT_REVISION, $finding['impact']);
        self::assertSame(
            "/payroll/people?person={$person['employee_id']}&panel=dependants",
            $finding['entities'][0]['remediation_path'],
        );
        self::assertStringContainsString('Syntetická Rodičovská', $finding['message']);
        self::assertStringContainsString('opravnou revizi', $finding['message']);

        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $officeId, 'fix-child');
        self::assertNotNull($run['approved'], CanonicalJson::encode($run['blockers']) . CanonicalJson::encode($run['warnings']));
        $blocked = $this->prepareFromChecklist();
        self::assertSame(409, $blocked['status'], CanonicalJson::encode($blocked['body']));
        $blocker = $blocked['body']['error']['details']['blockers'][0];
        self::assertSame('jmhz_scenario1_child_credit_caregiver_unknown', $blocker['code']);
        self::assertTrue($blocker['needs_revision']);
        self::assertFalse($blocker['fixed_in_source']);
        self::assertStringContainsString('„Vyžádat opravu" a „Otevřít opravu"', (string) $blocked['body']['error']['message']);

        $this->db->pdo()->prepare(
            'UPDATE payroll_person_tax_child_claims SET other_household_caregiver_status = "none"
              WHERE supplier_id = ? AND employee_id = ?',
        )->execute([$this->supplierId, $person['employee_id']]);
        $still = $this->prepareFromChecklist();
        self::assertSame(409, $still['status'], CanonicalJson::encode($still['body']));
        self::assertTrue($still['body']['error']['details']['blockers'][0]['fixed_in_source']);
        self::assertNull($this->readinessFinding('jmhz_scenario1_child_credit_caregiver_unknown'));

        $corrected = $this->correctPayrollRun($run['approved']->run, 'fix-child-corr', 'Zodpovězená otázka k dětem.');
        self::assertSame('approved', $corrected->run['status']);
        self::assertSame(201, $this->prepareFromChecklist()['status']);
    }

    /**
     * B1: údaj, který se čte až při přípravě hlášení (zařazení složky), se po
     * opravě projeví hned při dalším pokusu. Dřív opakovaná příprava
     * z přehledu vracela tutéž zastavenou přípravu a blokace trvala.
     */
    public function testFixOfLiveSourceIsPickedUpByTheNextPreparation(): void
    {
        $officeId = $this->createOffice('FIX', 'Syntetická účtárna', '1100001237');
        $this->configureSocialInsuranceOutput($officeId);
        $person = $this->hire($officeId, 'Syntetická Provizní', 'female', '1988-04-12');
        $bonus = $this->createComponent('PROVIZE_FIX', 'bonus', 'one_off');
        $this->createApprovedInput($person, $this->baseComponentId, 4_500_000, 'fix-base', self::PERIOD_START);
        $this->createApprovedInput($person, $bonus, 300_000, 'fix-bonus', self::PERIOD_START);
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $officeId, 'fix-map');
        self::assertNotNull($run['approved'], CanonicalJson::encode($run['blockers']) . CanonicalJson::encode($run['warnings']));

        $blocked = $this->prepareFromChecklist();
        self::assertSame(409, $blocked['status'], CanonicalJson::encode($blocked['body']));
        $blocker = $blocked['body']['error']['details']['blockers'][0];
        self::assertSame('component_jmhz_mapping_missing', $blocker['code']);
        // Zařazení složky se čte až při přípravě: opravnou revizi nestojí.
        self::assertFalse($blocker['needs_revision']);
        self::assertNull($blocker['revision_path']);
        self::assertStringNotContainsString('Vyžádat opravu', (string) $blocked['body']['error']['message']);

        $this->mappings()->put($this->supplierId, $bonus, '10330', null, $this->actors[0]);
        $prepared = $this->prepareFromChecklist();
        self::assertSame(201, $prepared['status'], CanonicalJson::encode($prepared['body']));
    }

    /**
     * B2: příprava před koncem měsíce. Řádek přehledu má akci zneaktivněnou
     * s datem, příprava skončí jednou větou (ne výčtem kontrol 43, 44, 90),
     * stav řádku se nezmění a po opravné revizi jde hlášení připravit.
     */
    public function testPreparationBeforePeriodEndSaysWhenAndKeepsTheRow(): void
    {
        self::$now = '2026-07-20 12:00:00';
        $officeId = $this->createOffice('FIX', 'Syntetická účtárna', '1100001237');
        $this->configureSocialInsuranceOutput($officeId);
        $person = $this->hire($officeId, 'Syntetická Červencová', 'female', '1988-04-12');
        $this->createApprovedInput($person, $this->baseComponentId, 4_500_000, 'fix-base', self::PERIOD_START);
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $officeId, 'fix-early');
        self::assertNotNull($run['approved'], CanonicalJson::encode($run['blockers']) . CanonicalJson::encode($run['warnings']));

        $before = $this->checklistRows();
        self::assertCount(1, $before);
        self::assertSame('not_prepared', $before[0]['status']);
        self::assertSame(JmhzPreparationGate::PERIOD_NOT_FINISHED_CODE, $before[0]['action']['prepare_blocked']['code']);
        self::assertSame('2026-08-01', $before[0]['action']['prepare_blocked']['available_from']);
        self::assertStringContainsString('připravit ho půjde od 1. 8. 2026', $before[0]['action']['reason']);

        $early = $this->prepareFromChecklist();
        self::assertSame(409, $early['status'], CanonicalJson::encode($early['body']));
        $message = (string) $early['body']['error']['message'];
        self::assertStringContainsString('Období 07/2026 ještě neskončilo', $message);
        self::assertStringContainsString('od 1. 8. 2026', $message);
        self::assertStringNotContainsString('kontrola 43', $message);
        self::assertStringNotContainsString('kontrola 90', $message);

        // Neúspěšný pokus nic nezaložil: řádek je tentýž a pořád „Nepřipraveno".
        $after = $this->checklistRows();
        self::assertSame($before[0]['key'], $after[0]['key']);
        self::assertSame('not_prepared', $after[0]['status']);
        self::assertSame(0, (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_obligations WHERE supplier_id = ? AND agenda_code = "JMHZ25"',
            [$this->supplierId],
        ));

        self::$now = '2026-08-10 12:00:00';
        $open = $this->checklistRows();
        self::assertNull($open[0]['action']['prepare_blocked']);
        self::assertSame(201, $this->prepareFromChecklist()['status']);
    }

    /**
     * B2: povinnost zaevidovaná přípravou, která se nezmrazila. Řádek zůstává
     * „Nepřipraveno" s akcí přípravy (dřív „Otevřeno" s odkazem na záložku,
     * která vedla zpět) a příprava z opravené revize ji převezme (dřív
     * „už je evidované řádné hlášení", přestože se nic nepodalo).
     */
    public function testObligationWithoutSubmissionStaysPreparable(): void
    {
        $officeId = $this->createOffice('FIX', 'Syntetická účtárna', '1100001237');
        $this->configureSocialInsuranceOutput($officeId);
        $person = $this->hire($officeId, 'Syntetická Červencová', 'female', '1988-04-12');
        $this->createApprovedInput($person, $this->baseComponentId, 4_500_000, 'fix-base', self::PERIOD_START);
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $officeId, 'fix-orphan');
        self::assertNotNull($run['approved'], CanonicalJson::encode($run['blockers']) . CanonicalJson::encode($run['warnings']));
        // Povinnost bez podání, jakou zanechá příprava zastavená katalogem kontrol.
        $window = $this->container->get(JmhzDeadlinePolicy::class)->forPeriod(self::PERIOD_START);
        $obligations = $this->container->get(PayrollObligationService::class);
        self::assertInstanceOf(PayrollObligationService::class, $obligations);
        $obligationId = (int) $obligations->register(
            $this->supplierId,
            JmhzSubmissionBridgeService::AGENDA_CODE,
            'payroll_run',
            JmhzSubmissionBridgeService::runReference((int) $run['approved']->run['id'], $officeId),
            self::PERIOD_START,
            '2026-07-31',
            'regular',
            'vrep_apep',
            JmhzSubmissionBridgeService::SOURCE_EVENT_TYPE,
            'jmhz_preparation:0',
            str_repeat('a', 64),
            $window->earliestSubmissionOn,
            $window->dueOn,
            $window->calendarBasis,
            $window->rulesetId,
            $window->rulesetHash,
            'fix-orphan-obligation',
            $this->actors[0],
            $this->actors[0],
            null,
            'test',
        )['id'];

        $rows = $this->checklistRows();
        self::assertCount(1, $rows, CanonicalJson::encode($rows));
        self::assertSame('not_prepared', $rows[0]['status']);
        self::assertSame(
            ['agenda_code' => 'JMHZ25', 'period' => '2026-07', 'insurer_code' => null],
            $rows[0]['action']['prepare'],
        );

        $prepared = $this->prepareFromChecklist();
        self::assertSame(201, $prepared['status'], CanonicalJson::encode($prepared['body']));
        self::assertSame($obligationId, (int) $this->scalar(
            'SELECT obligation_id FROM payroll_submissions WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $prepared['body']['submission_ids'][0]],
        ));
    }

    /**
     * M6: test hlášení před koncem měsíce. Kontroly 43, 44 a 90 selžou jen
     * kvůli dnešnímu datu; výsledek to řekne (obsah je v pořádku, čeká se na
     * konec období) a nese větu s datem, od kdy půjde hlášení připravit.
     */
    public function testDryRunBeforePeriodEndReportsWaitingNotDefects(): void
    {
        self::$now = '2026-07-20 12:00:00';
        $officeId = $this->createOffice('FIX', 'Syntetická účtárna', '1100001237');
        $this->configureSocialInsuranceOutput($officeId);
        $person = $this->hire($officeId, 'Syntetická Červencová', 'female', '1988-04-12');
        $this->createApprovedInput($person, $this->baseComponentId, 4_500_000, 'fix-base', self::PERIOD_START);
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $officeId, 'fix-dry');
        self::assertNotNull($run['approved'], CanonicalJson::encode($run['blockers']) . CanonicalJson::encode($run['warnings']));
        $preparation = $this->prepareJmhz((int) $run['approved']->revision['id'], 'fix-dry');
        self::assertSame('source_ready', $preparation['body']['readiness_status'], CanonicalJson::encode($preparation['body']));

        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $officeId)['body'];
        self::assertSame('dry_run_incomplete', $tested['status']);
        self::assertTrue($tested['waiting_for_period_end']);
        self::assertSame(JmhzPreparationGate::PERIOD_NOT_FINISHED_CODE, $tested['preparation_gate']['code']);
        self::assertSame('2026-08-01', $tested['preparation_gate']['available_from']);
        self::assertStringContainsString('připravit ho půjde od 1. 8. 2026', $tested['preparation_gate']['message']);
        self::assertSame(JmhzPreparationGate::DATE_ONLY_CONTROL_IDS, $tested['date_only_control_ids']);
        // Všechny nepropustné vady jsou jen ty z data; nic jiného nechybí.
        self::assertNotSame([], $tested['controls']['blocking']);
        self::assertSame([], array_values(array_diff(
            array_column($tested['controls']['blocking'], 'control_id'),
            JmhzPreparationGate::DATE_ONLY_CONTROL_IDS,
        )));

        // Po skončení období je tentýž obsah bez závory a jde odeslat.
        self::$now = '2026-08-10 12:00:00';
        $later = $this->dryRunJmhz((int) $preparation['body']['id'], $officeId)['body'];
        self::assertSame('dry_run_valid', $later['status'], CanonicalJson::encode($later['controls'] ?? $later));
        self::assertNull($later['preparation_gate']);
        self::assertFalse($later['waiting_for_period_end']);
    }

    /**
     * B3: leden 2027, číselníky obcí a států pro rok nejsou vyhlášené.
     * Přehled podání má přípravu zneaktivněnou s důvodem, radou (nic
     * nevyplňovat) a termínem; test hlášení důvod nese jednou a nenabízí
     * kroky, které nepomůžou (nález číselníků u každého vztahu, odložení,
     * druhý nález k typům kolektivních smluv na mzdovém běhu).
     */
    public function testJanuary2027SaysWhyWhatToDoAndWhen(): void
    {
        self::$now = '2027-02-05 12:00:00';
        $officeId = $this->createOffice('FIX', 'Syntetická účtárna', '1100001237');
        $this->configureSocialInsuranceOutput($officeId);
        $this->configureIncomeTaxOutput();
        $person = $this->createEmployment($officeId, 'Syntetický Lednový', 1, 'hpp', 'employment', 40, 10_000, true, '2027-01-01');
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => 'Syntetický',
            'last_name' => 'Lednový',
            'birth_date' => '1983-03-03',
            'sex' => 'male',
            'birth_number' => self::syntheticBirthNumber('1983-03-03', 'male', 1),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic(1), sprintf('2%020d', 1));
        $workdays = self::workdays('2027-01');
        $this->publishShifts($person['employment_id'], $workdays);
        self::assertSame(200, $this->approveTimeMonth($person['employment_id'], '2027-01', $workdays)->getStatusCode());
        $this->createApprovedInput($person, $this->baseComponentId, 4_500_000, 'jan-base', '2027-01-01');
        $this->openYear2027($person['employee_id']);
        $run = $this->runPayrollMonth('2027-01-01', '2027-02-12', $officeId, 'jan');
        self::assertNotNull($run['approved'], CanonicalJson::encode($run['blockers']) . CanonicalJson::encode($run['warnings']));

        $rows = $this->checklistRows('2027-01');
        self::assertCount(1, $rows);
        $gate = $rows[0]['action']['prepare_blocked'];
        self::assertSame(JmhzSpecPackageCatalog::UNAVAILABLE_CODE, $gate['code']);
        self::assertNull($gate['available_from']);
        foreach ([
            'Číselník obcí a států pro tento rok ještě nebyl vyhlášen',
            'údaje pracoviště neměňte ani nedoplňujte',
            'po vyhlášení číselníku a aktualizaci aplikace',
            'Termín podání za 01/2027 je 22. 2. 2027',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $gate['message']);
            self::assertStringContainsString($fragment, $rows[0]['action']['reason']);
        }
        self::assertStringNotContainsString('schéma je připnuté', $gate['message']);
        self::assertStringNotContainsString('datové věty', $gate['message']);

        $blocked = $this->prepareFromChecklist('2027-01');
        self::assertSame(409, $blocked['status']);
        self::assertSame($gate['message'], $blocked['body']['error']['message']);
        self::assertSame(0, (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_obligations WHERE supplier_id = ? AND agenda_code = "JMHZ25"',
            [$this->supplierId],
        ));

        $preparation = $this->prepareJmhz((int) $run['approved']->revision['id'], 'jan-dry');
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $officeId)['body'];
        self::assertSame('blocked', $tested['status']);
        self::assertSame(JmhzSpecPackageCatalog::UNAVAILABLE_CODE, $tested['preparation_gate']['code']);
        $codes = array_column($tested['blockers'], 'code');
        // Důvod nese závora jednou za hlášení a nic se neodkládá: odložení
        // vztahu nepomůže, dokud nejde zmrazit ani hlášení za ostatní.
        self::assertSame([], array_filter($tested['blockers'], static fn (array $blocker): bool => $blocker['deferrable'] === true));
        // Typy kolektivních smluv: jeden nález s místem opravy, ne druhý na mzdovém běhu.
        self::assertContains('jmhz_employer_annual_collective_agreement_missing', $codes);
        foreach ($tested['blockers'] as $blocker) {
            self::assertFalse(
                $blocker['code'] === 'jmhz_2027_source_missing' && in_array('10214', $blocker['attribute_ids'], true),
                'Chybějící typy kolektivních smluv se hlásí dvakrát.',
            );
            self::assertStringNotContainsString('zvýrazněn', $blocker['action'], $blocker['code']);
            self::assertNotSame('Chybí zákonný údaj potřebný pro měsíční hlášení.', $blocker['reason'], $blocker['code']);
        }
        $address = array_values(array_filter(
            $tested['blockers'],
            static fn (array $blocker): bool => in_array('10550', $blocker['attribute_ids'], true),
        ));
        self::assertCount(1, $address);
        self::assertTrue($address[0]['reason_specific']);
        self::assertStringContainsString('adresa místa výkonu práce', $address[0]['reason']);
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function prepareFromChecklist(string $period = self::PERIOD): array
    {
        $action = $this->container->get(PayrollMonthlyChecklistPrepareAction::class);
        self::assertInstanceOf(PayrollMonthlyChecklistPrepareAction::class, $action);
        $response = $action(
            $this->request('POST', '/api/payroll/submissions/monthly-checklist/prepare')->withParsedBody([
                'environment' => 'test',
                'period' => $period,
                'agenda_code' => 'JMHZ25',
                'insurer_code' => null,
            ]),
            new Response(),
        );

        return ['status' => $response->getStatusCode(), 'body' => $this->json($response)];
    }

    /** @return list<array<string,mixed>> */
    private function checklistRows(string $period = self::PERIOD): array
    {
        $service = $this->container->get(PayrollMonthlyChecklistService::class);
        self::assertInstanceOf(PayrollMonthlyChecklistService::class, $service);

        return array_values(array_filter(
            $service->checklist($this->supplierId, 'test', $period, $this->actors[0])['items'],
            static fn (array $item): bool => $item['agenda_code'] === 'JMHZ25',
        ));
    }

    /** @return array{employee_id:int,employment_id:int,name:string} */
    private function hire(int $officeId, string $name, string $sex, string $birthDate): array
    {
        $sequence = ++$this->sequence;
        $person = $this->createEmployment($officeId, $name, $sequence, 'hpp', 'employment', 40, 10_000, true, self::PERIOD_START);
        [$firstName, $lastName] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => $birthDate,
            'sex' => $sex,
            'birth_number' => self::syntheticBirthNumber($birthDate, $sex, $sequence),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), sprintf('2%020d', $sequence));
        $workdays = self::workdays(self::PERIOD);
        $this->publishShifts($person['employment_id'], $workdays);
        self::assertSame(200, $this->approveTimeMonth($person['employment_id'], self::PERIOD, $workdays)->getStatusCode());
        $this->createApprovedAverage($person['employment_id'], 3);

        return $person;
    }

    /** @return array<string,mixed>|null */
    private function readinessFinding(string $code, string $periodStart = self::PERIOD_START, string $payday = self::PAYDAY): ?array
    {
        $readiness = $this->container->get(PayrollRunReadinessService::class);
        self::assertInstanceOf(PayrollRunReadinessService::class, $readiness);

        return array_column($readiness->inspect($this->supplierId, $periodStart, $payday)['findings'], null, 'code')[$code] ?? null;
    }

    private function createChild(int $employeeId, string $name, string $birthDate, int $sequence): int
    {
        $birthNumber = self::syntheticBirthNumber($birthDate, 'male', 100 + $sequence);
        [$givenName, $familyName] = explode(' ', $name, 2);
        $response = $this->dependants()->create(
            $this->request('POST', "/api/payroll/people/{$employeeId}/dependants")->withParsedBody([
                'relation' => 'child_own',
                'full_name' => $name,
                'given_name' => $givenName,
                'family_name' => $familyName,
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
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        foreach ($this->json($response)['dependants'] as $dependant) {
            if ($dependant['full_name'] === $name) {
                return (int) $dependant['id'];
            }
        }
        self::fail("Vyživovaná osoba {$name} chybí.");
    }

    /** @param array<string,mixed> $overrides */
    private function claimChild(int $employeeId, int $dependantId, int $order, array $overrides = []): void
    {
        $response = $this->dependants()->createClaim(
            $this->request('POST', "/api/payroll/people/{$employeeId}/dependants/{$dependantId}/claims")
                ->withParsedBody($overrides + [
                    'child_order' => $order,
                    'claim_reason' => 'own_household',
                    'evidence_status' => 'verified',
                    'evidence_reference' => 'document:child-claim',
                    'shared_household_confirmed' => true,
                    'other_claimant_excluded' => true,
                    'ztp_p' => false,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                ]),
            new Response(),
            ['id' => (string) $employeeId, 'dependantId' => (string) $dependantId],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    private function dependants(): PayrollDependantAction
    {
        $action = $this->container->get(PayrollDependantAction::class);
        self::assertInstanceOf(PayrollDependantAction::class, $action);

        return $action;
    }

    /** Počáteční stavy roku 2027 (převzaté nuly), jak je zakládá průvodce. */
    private function openYear2027(int $employeeId): void
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
            "fix-2027-social-{$employeeId}",
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
            "fix-2027-tax-{$employeeId}",
            actorUserId: $this->actors[0],
        );
    }

    private function mappings(): PayrollComponentJmhzMappingRepository
    {
        $repository = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $repository);

        return $repository;
    }
}
