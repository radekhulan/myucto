<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunCommandService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Zahození opravy vrací běh na schválenou revizi i pro navazující agendy:
 * měsíční hlášení JMHZ se připraví zase ze schválené revize (triggery
 * migrací 1543 a 1544 chtějí AKTUÁLNÍ schválenou revizi), zaúčtování
 * a platby jedou dál z ní.
 *
 * HPP 45 000 Kč, duben 2026; v opravě přibude omylem odměna 2 000 Kč, kterou
 * účetní zahodí spolu s opravou.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollE2eDiscardCorrectionTest extends TestCase
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
        $this->officeId = $this->createOffice('ZAH', 'Syntetická účtárna zahození', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->configureIncomeTaxOutput();
        $this->baseComponentId = $this->createComponent('MZDA_MESICNI_ZAH', 'base_wage', 'regular');
        $this->bonusComponentId = $this->createComponent('ODMENA_ZAH', 'bonus', 'one_off');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $mappings);
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
        $mappings->put($this->supplierId, $this->bonusComponentId, '10331', null, $this->actors[0]);
        // Mzdový můstek do účetnictví (post) potřebuje režim, období a osnovu.
        $modes = $this->container->get(AccountingModeRepository::class);
        self::assertInstanceOf(AccountingModeRepository::class, $modes);
        $modes->record($this->supplierId, '2026-01-01', 'double_entry');
        $this->db->pdo()->prepare(
            'INSERT INTO accounting_periods (supplier_id, fiscal_year, starts_on, ends_on, status)
             VALUES (?, 2026, "2026-01-01", "2026-12-31", "open")',
        )->execute([$this->supplierId]);
        $chart = $this->container->get(ChartOfAccountsSeeder::class);
        self::assertInstanceOf(ChartOfAccountsSeeder::class, $chart);
        $chart->seedForSupplier($this->supplierId);
        $commands = $this->container->get(PayrollRunCommandService::class);
        self::assertInstanceOf(PayrollRunCommandService::class, $commands);
        $this->runs = $commands;
        $this->db->pdo()->prepare(
            'UPDATE payroll_module_state SET status = "active", activated_by = ?, activated_at = NOW() WHERE supplier_id = ?',
        )->execute([$this->actors[0], $this->supplierId]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testDiscardedCorrectionHandsJmhzPostingAndPaymentsBackToApprovedRevision(): void
    {
        $person = $this->hire();
        $this->createApprovedInput($person, $this->baseComponentId, 4_500_000, 'base', self::PERIOD_START);
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, 'zah');
        self::assertSame([], $run['blockers'], CanonicalJson::encode($run['blockers']));
        self::assertNotNull($run['approved']);
        $runId = (int) $run['approved']->run['id'];
        $approvedRevisionId = (int) $run['approved']->revision['id'];
        $postingBatches = (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_posting_batches WHERE supplier_id = ? AND revision_id = ?',
            [$this->supplierId, $approvedRevisionId],
        );

        $requested = $this->runs->requestCorrection($this->supplierId, $runId, (int) $run['approved']->run['row_version'], 'zah-oprava-request', $this->actors[0], 'Odměna za duben.');
        $reopened = $this->runs->reopen($this->supplierId, $runId, (int) $requested->run['row_version'], 'zah-oprava-reopen', $this->actors[0], 'Odměna za duben.');
        $this->createApprovedInput($person, $this->bonusComponentId, 200_000, 'bonus', self::PERIOD_START);
        $refreshed = $this->runs->refreshInputs($this->supplierId, $runId, (int) $reopened->run['row_version'], 'zah-oprava-refresh', $this->actors[0]);
        $calculated = $this->runs->calculate($this->supplierId, $runId, (int) $refreshed->run['row_version'], 'zah-oprava-calculate', $this->actors[0]);

        // Otevřená oprava drží ukazatel na sobě: hlášení ze schválené revize nejde.
        $blocked = $this->prepareJmhz($approvedRevisionId, 'zah-oprava-prepare-blocked');
        self::assertNotSame('source_ready', $blocked['body']['readiness_status'] ?? null, CanonicalJson::encode($blocked['body']));

        $discarded = $this->runs->discardCorrection($this->supplierId, $runId, (int) $calculated->run['row_version'], 'zah-oprava-discard', $this->actors[0]);
        self::assertSame('approved', $discarded->to->value);
        self::assertSame($approvedRevisionId, (int) $discarded->revision['id']);
        self::assertSame('correction_discarded', $discarded->outcome?->outcome);
        self::assertSame(1, $discarded->outcome->details['source_changes_since_approval']);

        // JMHZ: příprava ze schválené revize zase projde triggery 1543/1544.
        $preparation = $this->prepareJmhz($approvedRevisionId, 'zah-oprava-prepare');
        self::assertSame('source_ready', $preparation['body']['readiness_status'], CanonicalJson::encode($preparation['body']));
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame('dry_run_valid', $tested['body']['status'], CanonicalJson::encode(array_diff_key($tested['body'], ['xml' => true])));
        $xml = (string) preg_replace('/>\s+</', '><', (string) $tested['body']['xml']);
        self::assertStringContainsString('<form:zuctovanoCelkem>45000</form:zuctovanoCelkem>', $xml);

        // Zaúčtování ani schválená výplata se nezměnily.
        self::assertSame($postingBatches, (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_posting_batches WHERE supplier_id = ? AND revision_id = ?',
            [$this->supplierId, $approvedRevisionId],
        ));
        self::assertSame(0, (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_posting_batches batch
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = batch.supplier_id AND revision.id = batch.revision_id
              WHERE batch.supplier_id = ? AND batch.run_id = ? AND revision.status = "abandoned"',
            [$this->supplierId, $runId],
        ));

        // Běh pokračuje ze schválené revize: zaúčtovat a připravit platby.
        $posted = $this->runs->post($this->supplierId, $runId, (int) $discarded->run['row_version'], 'zah-oprava-post', $this->actors[0]);
        self::assertSame('posted', $posted->to->value);
        self::assertSame($approvedRevisionId, (int) $posted->revision['id']);
        $payments = $this->runs->preparePayments($this->supplierId, $runId, (int) $posted->run['row_version'], 'zah-oprava-payments', $this->actors[0]);
        self::assertSame('payment_ready', $payments->to->value);
        self::assertSame(0, (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_payment_liabilities liability
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = liability.supplier_id AND revision.id = liability.revision_id
              WHERE liability.supplier_id = ? AND revision.run_id = ? AND revision.id <> ?',
            [$this->supplierId, $runId, $approvedRevisionId],
        ));
    }

    /** @return array<string,mixed> */
    private function hire(): array
    {
        $person = $this->createEmployment($this->officeId, 'Syntetická Zahozená', 1, 'hpp', 'employment', 40, 10_000, true, self::PERIOD_START);
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => 'Syntetická',
            'last_name' => 'Zahozená',
            'birth_date' => '1986-06-06',
            'sex' => 'female',
            'birth_number' => self::syntheticBirthNumber('1986-06-06', 'female', 1),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic(1), sprintf('2%020d', 1));
        $workdays = self::workdays(self::PERIOD);
        $this->publishShifts($person['employment_id'], $workdays);
        $this->createApprovedAverage($person['employment_id'], 2);
        self::assertSame(200, $this->approveTimeMonth($person['employment_id'], self::PERIOD, $workdays)->getStatusCode());
        // Výplata na účet, ať jdou připravit platby (vzor PayrollE2ePodaniTaxStatementTest).
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_person_accounts
                (supplier_id, employee_id, label, bank_account_ciphertext, bank_account_hash, bank_account_masked,
                 allocation_basis_points, effective_from, is_active, row_version, verification_source, verified_on, verified_by)
             VALUES (?, ?, "Syntetický účet", "enc:v2:synthetic-account", UNHEX(?), "••••0005", 10000, "2026-01-01", 1, 1,
                     "user_verified", "2026-01-05", ?)',
        )->execute([$this->supplierId, $person['employee_id'], hash('sha256', "zah-account:{$this->supplierId}"), $this->actors[0]]);
        $pdo->prepare(
            'INSERT INTO payroll_payout_rules
                (supplier_id, employee_id, allocation_reference, destination_kind, destination_reference, allocation_kind, priority_no, is_active)
             VALUES (?, ?, "ZAH-REMAINDER", "bank", ?, "remainder", 100, 1)',
        )->execute([$this->supplierId, $person['employee_id'], 'account:' . (int) $pdo->lastInsertId()]);

        return $person;
    }
}
