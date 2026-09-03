<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Payroll\PayrollAutoPostService;
use MyInvoice\Service\Accounting\Payroll\PayrollPostingService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Automatické měsíční zaúčtování mezd (migrace 1175) — logika `cron-payroll-post`.
 *
 * Cron účtuje bez dohledu, takže platí obráceně než u ručního zaúčtování: chyba se
 * nikdo nedozví hned, a co se zaúčtuje špatně, tiše zůstane v deníku. Testuje se proto
 * hlavně to, co automat NESMÍ udělat — zaúčtovat někoho, koho nemá, zdvojit už
 * zaúčtovaný měsíc, přepsat cizí zápis nebo spadnout na jednom zaměstnanci celý.
 */
#[Group('integration')]
final class PayrollAutoPostTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2026;
    private const MONTH = 6;
    private const GROSS = 30_000;

    private Connection $db;
    private PayrollAutoPostService $autoPost;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db       = $c->get(Connection::class);
            $this->autoPost = $c->get(PayrollAutoPostService::class);
            $this->periods  = $c->get(AccountingPeriodRepository::class);
            $seeder         = $c->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->hasColumn('payroll_employees', 'auto_post')) {
            $this->markTestSkipped('Migrace 1175 neproběhla.');
        }

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0) {
            $this->markTestSkipped('Chybí supplier.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")
            ->execute([$this->supplierId]);
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create(
            $this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31'
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    /** Účtuje se jen ten, kdo má OBOJÍ — zapnutý automat i vyplněnou pravidelnou mzdu. */
    public function testPostsOnlyEmployeesWithAutoPostAndGross(): void
    {
        $auto     = $this->employee('S automatem', autoPost: true, monthlyGross: self::GROSS);
        $manual   = $this->employee('Bez automatu', autoPost: false, monthlyGross: self::GROSS);
        $noAmount = $this->employee('Automat bez částky', autoPost: true, monthlyGross: null);

        $r = $this->autoPost->runForSupplier($this->supplierId, self::YEAR, self::MONTH);

        self::assertSame(1, $r['candidates'], 'Kandidátem je jen zaměstnanec s automatem A částkou.');
        self::assertSame(1, $r['posted']);
        self::assertSame(0, $r['errors']);
        self::assertSame($auto, $r['items'][0]['employee_id']);

        self::assertSame(1, $this->recordCount($auto));
        self::assertSame(0, $this->recordCount($manual));
        self::assertSame(0, $this->recordCount($noAmount));
    }

    /** Neaktivní zaměstnanec se neúčtuje ani se zapnutým automatem. */
    public function testInactiveEmployeeIsNotPosted(): void
    {
        $this->employee('Odešel', autoPost: true, monthlyGross: self::GROSS, active: false);

        $r = $this->autoPost->runForSupplier($this->supplierId, self::YEAR, self::MONTH);

        self::assertSame(0, $r['candidates']);
        self::assertSame(0, $r['posted']);
    }

    /**
     * Druhý běh téhož měsíce nesmí nic zdvojit ani přepsat — jen ohlásit, že už bylo.
     * Rozlišení „nově zaúčtováno" × „už bylo" je jediné, podle čeho se v reportu pozná
     * skutečný výsledek běhu.
     */
    public function testSecondRunReportsAlreadyPostedAndCreatesNothingNew(): void
    {
        $employeeId = $this->employee('Jednatel', autoPost: true, monthlyGross: self::GROSS);

        $first = $this->autoPost->runForSupplier($this->supplierId, self::YEAR, self::MONTH);
        self::assertSame(1, $first['posted']);
        self::assertSame(0, $first['already']);

        $second = $this->autoPost->runForSupplier($this->supplierId, self::YEAR, self::MONTH);

        self::assertSame(0, $second['posted'], 'Druhý běh už nic neúčtuje.');
        self::assertSame(1, $second['already']);
        self::assertSame(PayrollAutoPostService::STATUS_ALREADY, $second['items'][0]['status']);

        self::assertSame(1, $this->recordCount($employeeId), 'Mzdový záznam zůstal jeden.');
        self::assertSame(1, $this->entryCount(), 'Účetní zápis zůstal jeden.');
    }

    /**
     * Uzavřené období zaměstnance přeskočí a NEZHAVARUJE — chyba jednoho zaměstnance
     * nesmí shodit celý běh, protože ostatní firmy v témže běhu za to nemůžou.
     */
    public function testClosedPeriodIsReportedPerEmployeeWithoutFailingTheRun(): void
    {
        $employeeId = $this->employee('Jednatel', autoPost: true, monthlyGross: self::GROSS);
        $this->periods->setStatus($this->periodId, $this->supplierId, 'closed');

        $r = $this->autoPost->runForSupplier($this->supplierId, self::YEAR, self::MONTH);

        self::assertSame(1, $r['errors']);
        self::assertSame(0, $r['posted']);
        self::assertSame(PayrollAutoPostService::STATUS_ERROR, $r['items'][0]['status']);
        self::assertNotSame('', (string) $r['items'][0]['message']);
        self::assertSame(0, $this->recordCount($employeeId));
    }

    /**
     * Chybějící období NENÍ stejný případ jako uzavřené: uzavřené je rozhodnutí účetní
     * (§35 ZoÚ, viz test výše), kdežto chybějící je jen díra v evidenci — ta se doplní
     * ({@see \MyInvoice\Service\Accounting\AccountingPeriodProvisioner}) a mzda se
     * zaúčtuje. Dřív tady mzdový běh hlásil chybu, takže mzda za měsíc v roce, pro
     * který nikdo nezaložil období, propadla bez zaúčtování.
     *
     * Že období vzniklo automaticky, je vidět na `created_reason` — účetní tak v seznamu
     * období pozná rok, který sama nezakládala.
     */
    public function testMissingPeriodIsOpenedAutomaticallyAndPayrollPosts(): void
    {
        $this->employee('Jednatel', autoPost: true, monthlyGross: self::GROSS);

        $r = $this->autoPost->runForSupplier($this->supplierId, self::YEAR - 1, self::MONTH);

        self::assertSame(0, $r['errors'], 'Chybějící období už není chyba běhu.');
        self::assertSame(1, $r['posted']);

        $opened = $this->periods->findByYear($this->supplierId, self::YEAR - 1);
        self::assertNotNull($opened, 'Období pro rok mzdy se mělo doplnit.');
        self::assertSame('open', (string) $opened['status']);
        self::assertSame('posting', (string) $opened['created_reason']);
    }

    /** Daňová evidence deník nevede — takový dodavatel se do běhu vůbec nedostane. */
    public function testTaxEvidenceSupplierIsNotACandidate(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")
            ->execute([$this->supplierId]);

        self::assertNotContains($this->supplierId, $this->autoPost->doubleEntrySupplierIds());
    }

    /**
     * Dva zaměstnanci s automatem v jednom měsíci: rekapitulace drží JEDEN zápis na
     * dodavatele a měsíc (`uq_je_supplier_source`), takže druhé zaúčtování by to první
     * přepsalo a náklad firmy by byl podhodnocený o celou první mzdu. Automat proto
     * druhého ohlásí jako konflikt a nechá ho na ruční zaúčtování.
     */
    public function testSecondEmployeeInSameMonthIsReportedAsConflictInsteadOfOverwriting(): void
    {
        $first  = $this->employee('První', autoPost: true, monthlyGross: self::GROSS);
        $second = $this->employee('Druhý', autoPost: true, monthlyGross: 20_000);

        $r = $this->autoPost->runForSupplier($this->supplierId, self::YEAR, self::MONTH);

        self::assertSame(2, $r['candidates']);
        self::assertSame(1, $r['posted']);
        self::assertSame(1, $r['conflicts']);
        self::assertSame(PayrollAutoPostService::STATUS_CONFLICT, $r['items'][1]['status']);

        self::assertSame(1, $this->recordCount($first), 'První mzda zůstala zaúčtovaná.');
        self::assertSame(0, $this->recordCount($second));
        self::assertSame(1, $this->entryCount());
    }

    /** `--dry-run` nesmí zapsat nic — ani zápis, ani mzdový záznam. */
    public function testDryRunPostsNothing(): void
    {
        $employeeId = $this->employee('Jednatel', autoPost: true, monthlyGross: self::GROSS);

        $r = $this->autoPost->runForSupplier($this->supplierId, self::YEAR, self::MONTH, dryRun: true);

        self::assertSame(0, $r['posted']);
        self::assertSame(PayrollAutoPostService::STATUS_DRY_RUN, $r['items'][0]['status']);
        self::assertSame(0, $this->recordCount($employeeId));
        self::assertSame(0, $this->entryCount());
    }

    /**
     * Kontace se řídí typem poplatníka Z KARTY — u jednatele-společníka 522/366,
     * ne 521/331. Cron žádný typ nedostává, takže tohle je jediný zdroj.
     */
    public function testManagingPartnerCardDrivesTheAccounts(): void
    {
        $this->employee('Jednatel-společník', autoPost: true, monthlyGross: self::GROSS,
            taxpayerType: 'managing_partner');

        $this->autoPost->runForSupplier($this->supplierId, self::YEAR, self::MONTH);

        self::assertContains('522', $this->entryAccountCodes());
        self::assertContains('366', $this->entryAccountCodes());
        self::assertNotContains('521', $this->entryAccountCodes());
    }

    /**
     * Běh 1. dne účtuje měsíc předchozí. Přes přelom roku i z 31. dne — `-1 month`
     * nad 31. březnem by bez zarovnání na první den měsíce přeteklo na 3. březen
     * a účtoval by se týž měsíc znovu.
     */
    public function testPeriodForAlwaysReturnsThePreviousMonth(): void
    {
        self::assertSame([2026, 6], PayrollAutoPostService::periodFor(new \DateTimeImmutable('2026-07-01')));
        self::assertSame([2025, 12], PayrollAutoPostService::periodFor(new \DateTimeImmutable('2026-01-01')));
        self::assertSame([2026, 3], PayrollAutoPostService::periodFor(new \DateTimeImmutable('2026-04-30')));
        self::assertSame([2026, 2], PayrollAutoPostService::periodFor(new \DateTimeImmutable('2026-03-31')));
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function employee(
        string $name,
        bool $autoPost,
        ?int $monthlyGross,
        bool $active = true,
        string $taxpayerType = 'employee',
    ): int {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, ?, "hpp", 1, 1, 0, ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $name,
            $taxpayerType,
            $monthlyGross,
            $autoPost ? 1 : 0,
            $active ? 1 : 0,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function recordCount(int $employeeId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_monthly_records WHERE employee_id = ? AND year = ? AND month = ?'
        );
        $stmt->execute([$employeeId, self::YEAR, self::MONTH]);
        return (int) $stmt->fetchColumn();
    }

    private function entryCount(): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM journal_entries
              WHERE supplier_id = ? AND source_type = 'manual' AND source_id = ?"
        );
        $stmt->execute([$this->supplierId, PayrollPostingService::sourceId(self::YEAR, self::MONTH)]);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<string> */
    private function entryAccountCodes(): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.account_code
               FROM journal_entries e
               JOIN journal_entry_lines l ON l.entry_id = e.id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE e.supplier_id = ? AND e.source_type = 'manual' AND e.source_id = ?"
        );
        $stmt->execute([$this->supplierId, PayrollPostingService::sourceId(self::YEAR, self::MONTH)]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
