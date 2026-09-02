<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Payment\PayrollPaydayResolver;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Výplatní termín se bere ze sjednané mzdové politiky, ne z natvrdo zadaného
 * patnáctého.
 *
 * Zakládací formulář mzdového běhu dřív nabízel 15. následujícího měsíce a
 * `payroll_employer_policies` vůbec nečetl. Datum výplaty přitom nese
 * splatnost odvodů, lhůty hlášení, sadu nezabavitelných částek podle § 4 nař.
 * vlády č. 595/2006 Sb. i mez podle § 141 odst. 1 zákoníku práce.
 */
#[Group('integration')]
final class PayrollPaydayResolverTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollPaydayResolver $resolver;
    private int $supplierId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $connection = $container->get(Connection::class);
        $resolver = $container->get(PayrollPaydayResolver::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(PayrollPaydayResolver::class, $resolver);
        $this->db = $connection;
        $this->resolver = $resolver;

        $pdo = $connection->pdo();
        $supplierQuery = $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1');
        self::assertInstanceOf(\PDOStatement::class, $supplierQuery);
        $sourceSupplierId = (int) $supplierQuery->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testWithoutPolicyFallsBackToFifteenthOfNextMonth(): void
    {
        self::assertSame(
            '2026-11-15',
            $this->resolver->suggest($this->supplierId, '2026-10-01'),
        );
    }

    public function testUsesPolicyDayOffsetAndBusinessDayRule(): void
    {
        $this->insertPolicy(10, 1, 'previous_business_day');

        // 10. 11. 2026 je úterý — pravidlo nemá co posouvat.
        self::assertSame(
            '2026-11-10',
            $this->resolver->suggest($this->supplierId, '2026-10-01'),
        );
        // 10. 10. 2026 je sobota — termín padá na pátek 9. 10.
        self::assertSame(
            '2026-10-09',
            $this->resolver->suggest($this->supplierId, '2026-09-01'),
        );
        // Prosincová mzda se vyplácí v lednu: 10. 1. 2027 je neděle.
        self::assertSame(
            '2027-01-08',
            $this->resolver->suggest($this->supplierId, '2026-12-01'),
        );
    }

    /**
     * Posun na pracovní den musí znát STÁTNÍ SVÁTKY, ne jen víkend — proto se
     * návrh počítá na serveru. 1. 1. je svátek, 2. 1. 2027 sobota, takže
     * nejbližší předchozí pracovní den je 31. 12. 2026.
     */
    public function testBusinessDayRuleSkipsPublicHolidays(): void
    {
        $this->insertPolicy(1, 1, 'previous_business_day');

        self::assertSame(
            '2026-12-31',
            $this->resolver->suggest($this->supplierId, '2026-12-01'),
        );
    }

    /**
     * § 141 odst. 1 zákoníku práce: mzda je splatná nejpozději v měsíci
     * následujícím po mzdovém období. Politika, která by mez překročila,
     * nesmí nabídnout datum, se kterým by běh vůbec nešlo založit.
     */
    public function testNeverSuggestsBeyondTheStatutoryDeadline(): void
    {
        $this->insertPolicy(31, 1, 'none');

        self::assertSame(
            '2026-11-30',
            $this->resolver->suggest($this->supplierId, '2026-10-01'),
        );
    }

    public function testSameMonthPolicyPaysInsidePeriod(): void
    {
        $this->insertPolicy(25, 0, 'none');

        self::assertSame(
            '2026-10-25',
            $this->resolver->suggest($this->supplierId, '2026-10-01'),
        );
    }

    private function insertPolicy(
        int $paydayDay,
        int $offset,
        string $rule,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employer_policies
                (supplier_id, valid_from, valid_to, payday_day,
                 payday_month_offset, payday_business_day_rule,
                 balance_rounding_mode, home_office_policy, travel_expense_policy,
                 leave_entitlement_weeks, automatic_posting_enabled,
                 delivery_channel, source_kind)
             VALUES (?, "2026-01-01", NULL, ?, ?, ?,
                     "exact_minor_units", "not_used", "not_used",
                     4, 0, "disabled", "manual")',
        )->execute([$this->supplierId, $paydayDay, $offset, $rule]);
    }
}
