<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Cesty ven z mzdového běhu, ve kterém se účetní zasekla (audit záseků
 * 12. 11. 2026): vyřazení osoby v ruční kontrole, změna data výplaty,
 * vzetí žádosti o opravu zpět, odemčení vstupů vypadlého vztahu, převzetí
 * výjimek do nové revize a zrušený převzatý běh.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollRunStuckExitTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD_START = '2026-06-01';
    private const PAYDAY = '2026-07-14';

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /** @return array{office:int,ok:array<string,mixed>,blocked:array<string,mixed>} */
    private function twoPeopleOneBlocked(): array
    {
        $officeId = $this->createOffice();
        $this->configureSocialInsuranceOutput($officeId);
        $this->configureHealthInsuranceOutput();
        $this->configureIncomeTaxOutput();
        $componentId = $this->createComponent('MZDA_STUCK', 'base_wage', 'regular');
        $ok = $this->createEmployment($officeId, 'Testovací Spočítaná', 11, 'hpp', 'employment', 40, 10_000);
        $blocked = $this->createEmployment($officeId, 'Testovací Bez Evidence', 12, 'hpp', 'employment', 40, 10_000);
        foreach ([$ok, $blocked] as $i => $person) {
            $this->createApprovedTimeMonth($person['employment_id'], '2026-06');
            $this->createApprovedInput($person, $componentId, 4_000_000, "stuck-wage-{$i}");
        }
        $this->db->pdo()->prepare(
            'DELETE FROM payroll_employment_terms WHERE supplier_id = ? AND employment_id = ?',
        )->execute([$this->supplierId, $blocked['employment_id']]);

        return ['office' => $officeId, 'ok' => $ok, 'blocked' => $blocked];
    }

    public function testExcludedPersonLetsRunBeApprovedAndCorrectionIncludesThemAgain(): void
    {
        $setup = $this->twoPeopleOneBlocked();
        $result = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $setup['office'], 'excl');
        self::assertNull($result['approved']);
        $calculated = $result['calculated'];
        $runId = (int) $calculated->run['id'];
        $blockedEmployee = (int) $setup['blocked']['employee_id'];

        try {
            $this->runs->excludeEmployee($this->supplierId, $runId, (int) $calculated->run['row_version'], 'excl-short', $this->actors[0], $blockedEmployee, 'chybí');
            self::fail('Vyřazení bez důvodu nesmí projít.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('důvod', $e->getMessage());
        }

        $excluded = $this->runs->excludeEmployee(
            $this->supplierId,
            $runId,
            (int) $calculated->run['row_version'],
            'excl-exclude',
            $this->actors[0],
            $blockedEmployee,
            'Chybí podmínky vztahu, doplní se příští týden.',
        );
        self::assertSame('inputs_locked', $excluded->to->value);
        $revisionId = (int) $excluded->revision['id'];
        self::assertSame(0, (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_run_persons WHERE supplier_id = ? AND revision_id = ? AND employee_id = ?',
            [$this->supplierId, $revisionId, $blockedEmployee],
        ));
        self::assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_run_persons WHERE supplier_id = ? AND revision_id = ? AND employee_id = ?',
            [$this->supplierId, $revisionId, (int) $setup['ok']['employee_id']],
        ));
        self::assertSame('approved', $this->scalar(
            'SELECT status FROM payroll_inputs WHERE supplier_id = ? AND employment_id = ?',
            [$this->supplierId, (int) $setup['blocked']['employment_id']],
        ), 'Vstupy vyřazené osoby se uvolní pro opravnou revizi.');
        self::assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_run_exclusions WHERE supplier_id = ? AND run_id = ? AND employee_id = ? AND active_key = 1',
            [$this->supplierId, $runId, $blockedEmployee],
        ));

        $recalculated = $this->runs->calculate($this->supplierId, $runId, (int) $excluded->run['row_version'], 'excl-calc', $this->actors[0]);
        self::assertSame([], $this->blockingValidations((int) $recalculated->revision['id']));
        $approved = $this->runs->approve($this->supplierId, $runId, (int) $recalculated->run['row_version'], 'excl-approve', $this->actors[0]);
        self::assertSame('approved', $approved->to->value);
        self::assertSame((string) $revisionId, (string) $this->scalar(
            'SELECT settled_revision_id FROM payroll_run_exclusions WHERE supplier_id = ? AND run_id = ? AND employee_id = ?',
            [$this->supplierId, $runId, $blockedEmployee],
        ));

        $requested = $this->runs->requestCorrection($this->supplierId, $runId, (int) $approved->run['row_version'], 'excl-request', $this->actors[0], 'Doplatek vyřazené osoby.');
        $reopened = $this->runs->reopen($this->supplierId, $runId, (int) $requested->run['row_version'], 'excl-reopen', $this->actors[0], 'Doplatek vyřazené osoby.');
        self::assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_run_persons WHERE supplier_id = ? AND revision_id = ? AND employee_id = ?',
            [$this->supplierId, (int) $reopened->revision['id'], $blockedEmployee],
        ), 'Opravná revize vyřazenou osobu zase obsahuje.');

        try {
            $this->runs->excludeEmployee($this->supplierId, $runId, (int) $reopened->run['row_version'], 'excl-again-ok', $this->actors[0], (int) $setup['ok']['employee_id'], 'Osoba ve schválené revizi, test.');
            self::fail('Osobu ze schválené revize nejde z opravy vyřadit.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('schválené revizi', $e->getMessage());
        }
    }

    public function testIncludeReturnsExcludedPerson(): void
    {
        $setup = $this->twoPeopleOneBlocked();
        $result = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $setup['office'], 'incl');
        $runId = (int) $result['calculated']->run['id'];
        $blockedEmployee = (int) $setup['blocked']['employee_id'];
        $excluded = $this->runs->excludeEmployee($this->supplierId, $runId, (int) $result['calculated']->run['row_version'], 'incl-exclude', $this->actors[0], $blockedEmployee, 'Omylem vyřazená osoba, test.');
        $included = $this->runs->includeEmployee($this->supplierId, $runId, (int) $excluded->run['row_version'], 'incl-include', $this->actors[0], $blockedEmployee);

        self::assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_run_persons WHERE supplier_id = ? AND revision_id = ? AND employee_id = ?',
            [$this->supplierId, (int) $included->revision['id'], $blockedEmployee],
        ));
        self::assertSame('included', $this->scalar(
            'SELECT lift_reason FROM payroll_run_exclusions WHERE supplier_id = ? AND run_id = ? AND employee_id = ?',
            [$this->supplierId, $runId, $blockedEmployee],
        ));
        self::assertSame('locked', $this->scalar(
            'SELECT status FROM payroll_inputs WHERE supplier_id = ? AND employment_id = ?',
            [$this->supplierId, (int) $setup['blocked']['employment_id']],
        ));
    }

    public function testPaymentDateChangesWithNewRevisionBeforeApproval(): void
    {
        $setup = $this->twoPeopleOneBlocked();
        $result = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $setup['office'], 'date');
        $runId = (int) $result['calculated']->run['id'];
        $previousRevision = (int) $result['calculated']->revision['id'];

        $changed = $this->runs->changePaymentDate($this->supplierId, $runId, (int) $result['calculated']->run['row_version'], 'date-change', $this->actors[0], '2026-07-10');
        self::assertSame('inputs_locked', $changed->to->value);
        self::assertSame('2026-07-10', (string) $changed->run['payment_date']);
        self::assertNotSame($previousRevision, (int) $changed->revision['id']);
        self::assertSame('2026-07-10', $changed->revision['input_snapshot']['payment_date']);

        try {
            $this->runs->changePaymentDate($this->supplierId, $runId, (int) $changed->run['row_version'], 'date-late', $this->actors[0], '2026-09-01');
            self::fail('Datum po § 141 nesmí projít.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('§ 141', $e->getMessage());
        }

        $cancelled = $this->runs->cancel($this->supplierId, $runId, (int) $changed->run['row_version'], 'date-cancel', $this->actors[0], '');
        $again = $this->runs->changePaymentDate($this->supplierId, $runId, (int) $cancelled->run['row_version'], 'date-cancelled', $this->actors[0], '2026-07-15');
        self::assertSame('cancelled', $again->to->value);
        self::assertSame('2026-07-15', (string) $again->run['payment_date']);

        try {
            $this->runs->createRun($this->supplierId, self::PERIOD_START, '2026-07-20', $setup['office'], $this->actors[0]);
            self::fail('Druhý běh s jiným datem nevzniká.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('Změnit datum výplaty', $e->getMessage());
            self::assertStringContainsString((string) $runId, $e->getMessage());
        }
    }

    public function testCorrectionRequestCanBeWithdrawnAndApprovedDateStaysFrozen(): void
    {
        $setup = $this->twoPeopleOneBlocked();
        $result = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $setup['office'], 'wdraw');
        $runId = (int) $result['calculated']->run['id'];
        $excluded = $this->runs->excludeEmployee($this->supplierId, $runId, (int) $result['calculated']->run['row_version'], 'wdraw-exclude', $this->actors[0], (int) $setup['blocked']['employee_id'], 'Chybí podmínky vztahu, test.');
        $calc = $this->runs->calculate($this->supplierId, $runId, (int) $excluded->run['row_version'], 'wdraw-calc', $this->actors[0]);
        $approved = $this->runs->approve($this->supplierId, $runId, (int) $calc->run['row_version'], 'wdraw-approve', $this->actors[0]);
        $approvedRevision = (int) $approved->revision['id'];

        try {
            $this->runs->changePaymentDate($this->supplierId, $runId, (int) $approved->run['row_version'], 'wdraw-date', $this->actors[0], '2026-07-10');
            self::fail('Datum schváleného běhu se nemění.');
        } catch (\DomainException) {
        }

        $requested = $this->runs->requestCorrection($this->supplierId, $runId, (int) $approved->run['row_version'], 'wdraw-request', $this->actors[0], 'Omyl.');
        self::assertSame('correction_pending', $requested->to->value);
        $withdrawn = $this->runs->withdrawCorrection($this->supplierId, $runId, (int) $requested->run['row_version'], 'wdraw-withdraw', $this->actors[0]);
        self::assertSame('approved', $withdrawn->to->value);
        self::assertSame('approved', (string) $withdrawn->run['status']);
        self::assertSame($approvedRevision, (int) $withdrawn->revision['id']);
    }

    public function testRefreshUnlocksInputsOfEmploymentThatLeftTheRun(): void
    {
        $officeId = $this->createOffice();
        $componentId = $this->createComponent('PRIPLATEK_LEFT', 'bonus', 'one_off');
        $stay = $this->createEmployment($officeId, 'Testovací Zůstává', 21, 'dpp', 'dpp', 10, 2_500);
        $left = $this->createEmployment($officeId, 'Testovací Nenastoupil', 22, 'dpp', 'dpp', 10, 2_500);
        $this->createApprovedInput($stay, $componentId, 10_000, 'left-stay');
        $this->createApprovedInput($left, $componentId, 10_000, 'left-left');
        $run = $this->runs->createRun($this->supplierId, self::PERIOD_START, self::PAYDAY, $officeId, $this->actors[0]);
        $locked = $this->runs->lockInputs($this->supplierId, (int) $run['id'], (int) $run['row_version'], 'left-lock', $this->actors[0]);
        self::assertSame('locked', $this->scalar('SELECT status FROM payroll_inputs WHERE supplier_id = ? AND external_id = ?', [$this->supplierId, 'left-left']));

        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET status = "planned", start_date = "2026-08-01", actual_start_date = NULL WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $left['employment_id']]);
        $refreshed = $this->runs->refreshInputs($this->supplierId, (int) $run['id'], (int) $locked->run['row_version'], 'left-refresh', $this->actors[0]);

        self::assertSame(0, (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_run_employments WHERE supplier_id = ? AND revision_id = ? AND employment_id = ?',
            [$this->supplierId, (int) $refreshed->revision['id'], $left['employment_id']],
        ));
        self::assertSame('approved', $this->scalar('SELECT status FROM payroll_inputs WHERE supplier_id = ? AND external_id = ?', [$this->supplierId, 'left-left']));
        self::assertSame('locked', $this->scalar('SELECT status FROM payroll_inputs WHERE supplier_id = ? AND external_id = ?', [$this->supplierId, 'left-stay']));
    }

    public function testOverridesWithSameCodeEntityAndTextAreCarriedOver(): void
    {
        $officeId = $this->createOffice();
        $this->createEmployment($officeId, 'Testovací Výjimka', 31, 'dpp', 'dpp', 10, 2_500);
        $run = $this->runs->createRun($this->supplierId, self::PERIOD_START, self::PAYDAY, $officeId, $this->actors[0]);
        $first = $this->runs->lockInputs($this->supplierId, (int) $run['id'], (int) $run['row_version'], 'carry-lock', $this->actors[0]);
        $second = $this->runs->refreshInputs($this->supplierId, (int) $run['id'], (int) $first->run['row_version'], 'carry-refresh', $this->actors[0]);
        $source = (int) $first->revision['id'];
        $target = (int) $second->revision['id'];
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_run_validations
                (supplier_id, revision_id, severity, code, entity_type, entity_id, message,
                 requires_override, override_reason, overridden_by, overridden_at)
             VALUES (?, ?, "warning", ?, "employment", 7, ?, 1, ?, ?, ?)',
        );
        $insert->execute([$this->supplierId, $source, 'stuck_carry', 'Stejný text.', 'Vědomě vyplácíme, ověřeno s klientem.', $this->actors[0], '2026-06-30 10:00:00']);
        $insert->execute([$this->supplierId, $source, 'stuck_other', 'Původní text.', 'Vědomě vyplácíme, ověřeno s klientem.', $this->actors[0], '2026-06-30 10:00:00']);
        $insert->execute([$this->supplierId, $target, 'stuck_carry', 'Stejný text.', null, null, null]);
        $insert->execute([$this->supplierId, $target, 'stuck_other', 'Změněný text.', null, null, null]);

        $carried = (new \MyInvoice\Repository\Payroll\PayrollRunRepository($this->db))->carryOverOverrides($this->supplierId, $target, $source);

        self::assertCount(1, $carried);
        self::assertSame('stuck_carry', $carried[0]['code']);
        self::assertSame('Vědomě vyplácíme, ověřeno s klientem.', $this->scalar(
            'SELECT override_reason FROM payroll_run_validations WHERE supplier_id = ? AND revision_id = ? AND code = "stuck_carry"',
            [$this->supplierId, $target],
        ));
        self::assertNull($this->scalar(
            'SELECT overridden_at FROM payroll_run_validations WHERE supplier_id = ? AND revision_id = ? AND code = "stuck_other"',
            [$this->supplierId, $target],
        ));
    }

    public function testCancelledTakeoverRunIsNotReusedOnCreate(): void
    {
        $officeId = null;
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_runs (supplier_id, office_id, period_start, payment_date, status, run_kind, created_by, updated_by)
             VALUES (?, NULL, ?, ?, 'cancelled', 'takeover', ?, ?)",
        )->execute([$this->supplierId, self::PERIOD_START, self::PAYDAY, $this->actors[0], $this->actors[0]]);
        $takeoverId = (int) $this->db->pdo()->lastInsertId();

        $run = $this->runs->createRun($this->supplierId, self::PERIOD_START, self::PAYDAY, $officeId, $this->actors[0]);

        self::assertNotSame($takeoverId, (int) $run['id']);
        self::assertSame('calculated', (string) $run['run_kind']);
        self::assertSame('draft', (string) $run['status']);
        self::assertArrayNotHasKey('period_slot', $run);
    }
}
