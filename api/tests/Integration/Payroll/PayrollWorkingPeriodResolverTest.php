<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Service\Payroll\PayrollWorkingPeriodResolver;
use MyInvoice\Tests\Support\PayrollDeletionFixturesTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Rozpracovaný mzdový měsíc firmy jako výchozí období mzdových obrazovek.
 *
 * Firma, která v listopadu teprve zpracovává únor, se z menu otevírala na
 * říjnu. Výchozí je první měsíc od začátku vedení mezd bez schválené mzdy.
 */
#[Group('integration')]
final class PayrollWorkingPeriodResolverTest extends TestCase
{
    use PayrollDeletionFixturesTrait;

    private const TODAY = '2026-11-11';

    private PayrollWorkingPeriodResolver $resolver;

    protected function setUp(): void
    {
        $container = $this->bootPayrollFixtures();
        $this->resolver = $container->get(PayrollWorkingPeriodResolver::class);
    }

    protected function tearDown(): void
    {
        $this->shutdownPayrollFixtures();
    }

    public function testCompanyWithoutPayrollStartHasNoWorkingPeriod(): void
    {
        self::assertSame(
            ['first_open' => null, 'last_processed' => null],
            $this->resolver->resolve($this->supplierId, self::TODAY),
        );
    }

    public function testFirstOpenIsTheFirstMonthWithoutApprovedPayroll(): void
    {
        $this->moduleStart('2026-01-01');
        // Bez jediného běhu se pracuje na prvním měsíci vedení mezd.
        self::assertSame(
            ['first_open' => '2026-01', 'last_processed' => null],
            $this->resolver->resolve($this->supplierId, self::TODAY),
        );

        // Leden schválený, únor rozpracovaný (spočítaný, neschválený).
        $this->insertRunRow('2026-01-01', 'approved');
        $february = $this->insertRunRow('2026-02-01', 'calculated');
        self::assertSame(
            ['first_open' => '2026-02', 'last_processed' => '2026-01'],
            $this->resolver->resolve($this->supplierId, self::TODAY),
        );

        // Zrušený běh měsíc nezpracoval; měsíc bez běhu je také otevřený.
        $this->setRunRowStatus($february, 'cancelled');
        self::assertSame('2026-02', $this->resolver->resolve($this->supplierId, self::TODAY)['first_open']);

        foreach (['approved', 'posted', 'payment_ready', 'paid', 'closed'] as $status) {
            $this->setRunRowStatus($february, $status);
            self::assertSame(
                ['first_open' => '2026-03', 'last_processed' => '2026-02'],
                $this->resolver->resolve($this->supplierId, self::TODAY),
                $status,
            );
        }

        // Běh otevřený k opravě měsíc znovu otevírá.
        foreach (['correction_pending', 'reopened'] as $status) {
            $this->setRunRowStatus($february, $status);
            self::assertSame('2026-02', $this->resolver->resolve($this->supplierId, self::TODAY)['first_open'], $status);
        }
    }

    public function testTakenOverMonthsCountAsProcessed(): void
    {
        $this->moduleStart('2026-01-01');
        $this->insertRunRow('2026-01-01', 'draft', 'takeover');
        $this->insertRunRow('2026-02-01', 'draft', 'takeover');

        self::assertSame(
            ['first_open' => '2026-03', 'last_processed' => '2026-02'],
            $this->resolver->resolve($this->supplierId, self::TODAY),
        );
    }

    public function testEveryRunOfTheMonthMustBeApproved(): void
    {
        $this->moduleStart('2026-01-01');
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO payroll_offices (supplier_id, code, name, is_active) VALUES (?, "A", "Syntetická účtárna A", 1)')
            ->execute([$this->supplierId]);
        $officeA = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payroll_offices (supplier_id, code, name, is_active) VALUES (?, "B", "Syntetická účtárna B", 1)')
            ->execute([$this->supplierId]);
        $officeB = (int) $pdo->lastInsertId();
        $this->insertRunRow('2026-01-01', 'approved', 'calculated', $officeA);
        $second = $this->insertRunRow('2026-01-01', 'calculated', 'calculated', $officeB);

        // Jedna účtárna schválená, druhá ne: leden je pořád rozpracovaný.
        self::assertSame('2026-01', $this->resolver->resolve($this->supplierId, self::TODAY)['first_open']);

        $this->setRunRowStatus($second, 'approved');
        self::assertSame('2026-02', $this->resolver->resolve($this->supplierId, self::TODAY)['first_open']);
    }

    public function testFullyProcessedCompanyWorksOnTheCurrentOrNextMonthAtMost(): void
    {
        $this->moduleStart('2026-09-01');
        $this->insertRunRow('2026-09-01', 'closed');
        $this->insertRunRow('2026-10-01', 'approved');
        // Říjen schválený: v listopadu se pracuje na listopadu.
        self::assertSame(
            ['first_open' => '2026-11', 'last_processed' => '2026-10'],
            $this->resolver->resolve($this->supplierId, self::TODAY),
        );

        // I mzdy vyplacené předem neposunou výchozí období dál než na příští měsíc.
        $this->insertRunRow('2026-11-01', 'approved');
        $this->insertRunRow('2026-12-01', 'approved');
        $this->insertRunRow('2027-01-01', 'approved');
        self::assertSame(
            ['first_open' => '2026-12', 'last_processed' => '2027-01'],
            $this->resolver->resolve($this->supplierId, self::TODAY),
        );
    }

    public function testPayrollStartingLaterOpensOnItsFirstMonthAndForeignRunsDoNotCount(): void
    {
        $this->moduleStart('2027-03-01');
        // Běhy cizí firmy se nepočítají.
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date, status) VALUES (?, "2027-03-01", "2027-04-10", "approved")'
        )->execute([$this->otherSupplierId]);

        self::assertSame(
            ['first_open' => '2027-03', 'last_processed' => null],
            $this->resolver->resolve($this->supplierId, self::TODAY),
        );
    }

    private function moduleStart(string $startPeriod): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_module_state (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "setup", ?, ?, NOW())'
        )->execute([$this->supplierId, $startPeriod, $this->userId]);
    }

    private function insertRunRow(string $periodStart, string $status, string $kind = 'calculated', ?int $officeId = null): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_runs (supplier_id, office_id, period_start, payment_date, status, run_kind)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $officeId,
            $periodStart,
            (new \DateTimeImmutable($periodStart))->modify('+40 days')->format('Y-m-d'),
            $status,
            $kind,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function setRunRowStatus(int $runId, string $status): void
    {
        $this->db->pdo()->prepare('UPDATE payroll_runs SET status = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$status, $this->supplierId, $runId]);
    }
}
