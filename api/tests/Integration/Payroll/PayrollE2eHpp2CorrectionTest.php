<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenPayloadReader;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Oprava schválené mzdy za duben 2026 poté, co je měsíční hlášení už
 * zmrazené: schválený běh, žádost o opravu, otevření, změna vstupů, přepočet,
 * schválení a opravné hlášení. Ruční přepočty podle sady 2026 (SP zaměstnanec
 * 7,1 %, ZP 13,5 % a třetina zaměstnanec, záloha 15 %, sleva na poplatníka
 * 2 570 Kč).
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eHpp2CorrectionTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-04';
    private const PERIOD_START = '2026-04-01';
    private const PAYDAY = '2026-05-14';

    private int $officeId;
    private int $baseComponentId;
    private int $bonusComponentId;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('OPR', 'Syntetická účtárna oprav', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->baseComponentId = $this->createComponent('MZDA_MESICNI_OPR', 'base_wage', 'regular');
        $this->bonusComponentId = $this->createComponent('ODMENA_OPR', 'bonus', 'one_off');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $mappings);
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
        $mappings->put($this->supplierId, $this->bonusComponentId, '10331', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * HPP 45 000 Kč, duben schválen a řádné hlášení zmrazeno, ale neodesláno.
     * Pak se doplní zapomenutá odměna 2 000 Kč.
     *
     * Ruční přepočet původní mzdy: SP 3 195; ZP 6 075, zaměstnanec 2 025;
     * záloha 6 750 − 2 570 = 4 180; čistá 45 000 − 3 195 − 2 025 − 4 180
     * = 35 600 Kč.
     * Po opravě 47 000 Kč: SP 3 337; ZP 6 345, zaměstnanec 2 115,
     * zaměstnavatel 4 230; záloha 7 050 − 2 570 = 4 480; čistá 47 000
     * − 3 337 − 2 115 − 4 480 = 37 068 Kč.
     *
     * Podání: schválení opravy zruší neodeslané řádné hlášení ze starší
     * revize, takže nové řádné hlášení s opravenými částkami jde zmrazit
     * hned a opravné hlášení není potřeba.
     */
    public function testCorrectionAfterFrozenUnsentReportEndsAsCorrectiveReport(): void
    {
        $person = $this->hire();
        $this->createApprovedInput($person, $this->baseComponentId, 4_500_000, 'base', self::PERIOD_START);
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, 'opr');
        self::assertSame([], $run['blockers'], CanonicalJson::encode($run['blockers']));
        self::assertNotNull($run['approved']);
        $originalRevisionId = (int) $run['approved']->revision['id'];
        self::assertSame(3_560_000, $run['approved']->revision['result_snapshot']['statutory']['people'][0]['net_payable_minor_units']);
        $originalHash = $this->revisionHash($originalRevisionId);

        $preparation = $this->prepareJmhz($originalRevisionId, 'opr');
        self::assertSame('source_ready', $preparation['body']['readiness_status'], CanonicalJson::encode($preparation['body']));
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame('dry_run_valid', $tested['body']['status'], CanonicalJson::encode($tested['body']));
        $frozen = $this->freezeJmhzSubmission((int) $preparation['body']['id'], $this->officeId);
        self::assertSame(201, $frozen['status'], CanonicalJson::encode($frozen['body']));
        self::assertSame('ready', $frozen['body']['status']);
        $regularId = (int) $frozen['body']['submission_id'];

        // Požádat o opravu, Otevřít, změna vstupů, Obnovit podklady, přepočet, schválení.
        $runId = (int) $run['approved']->run['id'];
        $requested = $this->runs->requestCorrection($this->supplierId, $runId, (int) $run['approved']->run['row_version'], 'oprava-request', $this->actors[0], 'Zapomenutá odměna za duben.');
        self::assertSame('correction_pending', $requested->run['status']);
        $reopened = $this->runs->reopen($this->supplierId, $runId, (int) $requested->run['row_version'], 'oprava-reopen', $this->actors[0], 'Zapomenutá odměna za duben.');
        self::assertSame('reopened', $reopened->run['status']);
        $this->createApprovedInput($person, $this->bonusComponentId, 200_000, 'bonus', self::PERIOD_START);
        // Přepočet bez obnovení podkladů počítá se vstupy zamčenými při otevření:
        // nový vstup v něm není a schválení se zastaví s návodem, ne tiše.
        $stale = $this->runs->calculate($this->supplierId, $runId, (int) $reopened->run['row_version'], 'oprava-calculate-stale', $this->actors[0]);
        self::assertSame(
            3_560_000,
            $stale->revision['result_snapshot']['statutory']['people'][0]['net_payable_minor_units'],
        );
        try {
            $this->runs->approve($this->supplierId, $runId, (int) $stale->run['row_version'], 'oprava-approve-stale', $this->actors[0]);
            self::fail('Revize bez nového vstupu se nesmí schválit.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('Obnovit podklady', $exception->getMessage());
        }
        $refreshed = $this->runs->refreshInputs($this->supplierId, $runId, (int) $stale->run['row_version'], 'oprava-refresh', $this->actors[0]);
        $calculated = $this->runs->calculate($this->supplierId, $runId, (int) $refreshed->run['row_version'], 'oprava-calculate', $this->actors[0]);
        self::assertSame([], $this->blockingValidations((int) $calculated->revision['id']), 'Zaseknutí: přepočet opravy.');
        $corrected = $this->runs->approve($this->supplierId, $runId, (int) $calculated->run['row_version'], 'oprava-approve', $this->actors[0]);
        self::assertSame('approved', $corrected->run['status']);
        $correctedRevisionId = (int) $corrected->revision['id'];
        self::assertNotSame($originalRevisionId, $correctedRevisionId, 'Oprava je nová revize.');
        self::assertSame($originalHash, $this->revisionHash($originalRevisionId), 'Původní schválená revize se nemění.');
        $result = $corrected->revision['result_snapshot']['statutory']['people'][0];
        self::assertSame(
            [4_700_000, 333_700, 211_500, 423_000, 448_000, 3_706_800],
            [
                $result['income_tax']['advance_tax']['taxable_income_minor_units'],
                $result['social_insurance']['employee_contribution_minor_units'],
                $result['health_insurance']['employee_contribution_minor_units'],
                $result['health_insurance']['employer_contribution_minor_units'],
                $result['income_tax']['advance_tax']['tax_after_credits_minor_units'],
                $result['net_payable_minor_units'],
            ],
            'Opravená mzda nesouhlasí s ručním přepočtem.',
        );

        // Schválení opravy zrušilo neodeslané řádné hlášení ze starší revize,
        // nové řádné hlášení z opravené revize proto jde zmrazit hned.
        self::assertSame('cancelled_in_time', (string) $this->scalar(
            'SELECT status FROM payroll_submissions WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $regularId],
        ));
        $repared = $this->prepareJmhz($correctedRevisionId, 'opr-fix');
        self::assertSame('source_ready', $repared['body']['readiness_status'], CanonicalJson::encode($repared['body']));
        $refrozen = $this->freezeJmhzSubmission((int) $repared['body']['id'], $this->officeId);
        self::assertSame(201, $refrozen['status'], CanonicalJson::encode($refrozen['body']));
        $newId = (int) $refrozen['body']['submission_id'];
        self::assertNotSame($regularId, $newId);

        $reader = $this->container->get(JmhzFrozenPayloadReader::class);
        self::assertInstanceOf(JmhzFrozenPayloadReader::class, $reader);
        $original = (string) preg_replace('/>\s+</', '><', $reader->bytes($this->supplierId, 'test', $regularId));
        $replacement = (string) preg_replace('/>\s+</', '><', $reader->bytes($this->supplierId, 'test', $newId));
        self::assertStringContainsString('<form:zuctovanoCelkem>45000</form:zuctovanoCelkem>', $original, 'Zrušené hlášení zůstává v historii beze změny.');
        foreach ([
            '<typPodani>R</typPodani>',
            '<form:zuctovanoCelkem>47000</form:zuctovanoCelkem>',
            '<form:odmenyNepravidelne>2000</form:odmenyNepravidelne>',
            '<form:danZalohaPoSleve>4480</form:danZalohaPoSleve>',
            '<form:mzdaCista>37068</form:mzdaCista>',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $replacement);
        }
    }

    private function revisionHash(int $revisionId): string
    {
        return (string) $this->scalar(
            'SELECT CONCAT(HEX(input_snapshot_hash), ":", HEX(result_snapshot_hash)) FROM payroll_run_revisions WHERE supplier_id = ? AND id = ?',
            [$this->supplierId, $revisionId],
        );
    }

    /** @return array{employee_id:int,employment_id:int,name:string} */
    private function hire(): array
    {
        $person = $this->createEmployment($this->officeId, 'Syntetická Opravená', 1, 'hpp', 'employment', 40, 10_000, true, self::PERIOD_START);
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => 'Syntetická',
            'last_name' => 'Opravená',
            'birth_date' => '1986-06-06',
            'sex' => 'female',
            'birth_number' => self::syntheticBirthNumber('1986-06-06', 'female', 1),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic(1), sprintf('2%020d', 1));
        $workdays = self::workdays(self::PERIOD);
        $this->publishShifts($person['employment_id'], $workdays);
        $this->createApprovedAverage($person['employment_id'], 2);
        self::assertSame(200, $this->approveTimeMonth($person['employment_id'], self::PERIOD, $workdays)->getStatusCode());

        return $person;
    }
}
