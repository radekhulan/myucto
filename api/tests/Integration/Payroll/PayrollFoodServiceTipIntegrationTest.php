<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Service\Payroll\Component\PayrollBenefitExemptionBasket;
use MyInvoice\Service\Payroll\Component\PayrollFoodServiceTipService;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Spropitné ze stravovacích služeb (§ 6 odst. 9 písm. g) ZDP od 2027) nad
 * databází: evidence měsíce, strop zaměstnance a rozpad vstupu tak, jak se
 * zmrazí při schválení. Částky v haléřích.
 */
#[Group('integration')]
final class PayrollFoodServiceTipIntegrationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private \Psr\Container\ContainerInterface $container;

    /**
     * Tržby 100 000 Kč, spropitné dostali 2 zaměstnanci → strop 7 000 / 2 = 3 500 Kč.
     * Spropitné 4 000 Kč: 3 500 osvobozeno, 500 nad limit (daň i pojistné).
     */
    public function testPerCapitaCapSplitsTheTip(): void
    {
        $this->inTransaction(function (PDO $pdo, int $supplierId, int $employeeId): void {
            $tips = $this->tips();
            $tips->save($supplierId, '2027-03-01', [
                'revenue_minor' => 10_000_000,
                'allocation_mode' => 'per_capita',
                'recipients_count' => 2,
                'evidence_reference' => 'tržby-03-2027',
            ], null);

            self::assertSame(350_000, $tips->limitMinor($supplierId, $employeeId, '2027-03-01'));

            $split = $this->inputs()->basketSplit(
                $supplierId,
                $employeeId,
                PayrollBenefitExemptionBasket::FoodServiceTips,
                '2027-03-01',
                400_000,
            );
            self::assertSame(350_000, $split->limitMinor);
            self::assertSame(350_000, $split->exemptMinor);
            self::assertSame(50_000, $split->taxableMinor);

            $exact = $this->inputs()->basketSplit(
                $supplierId,
                $employeeId,
                PayrollBenefitExemptionBasket::FoodServiceTips,
                '2027-03-01',
                350_000,
            );
            self::assertSame(0, $exact->taxableMinor);

            $view = $tips->view($supplierId, '2027-03-01');
            self::assertSame(700_000, $view['month']['employer_cap_minor']);
            self::assertFalse($view['locked']);
        });
    }

    /** Určení zaměstnavatelem: úhrn stropů nejvýš 7 % tržeb (neostře). */
    public function testEmployerDeterminedCapsMustFitSevenPercent(): void
    {
        $this->inTransaction(function (PDO $pdo, int $supplierId, int $employeeId): void {
            $tips = $this->tips();
            $tips->save($supplierId, '2027-04-01', [
                'revenue_minor' => 10_000_000,
                'allocation_mode' => 'employer_determined',
                'allocations' => [['employee_id' => $employeeId, 'exempt_cap_minor' => 700_000]],
            ], null);
            self::assertSame(700_000, $tips->limitMinor($supplierId, $employeeId, '2027-04-01'));
            // Zaměstnanec bez určeného stropu nemá osvobozeno nic.
            self::assertSame(0, $tips->limitMinor($supplierId, $employeeId + 1_000_000, '2027-04-01'));

            $this->expectException(\InvalidArgumentException::class);
            $tips->save($supplierId, '2027-04-01', [
                'revenue_minor' => 10_000_000,
                'allocation_mode' => 'employer_determined',
                'allocations' => [['employee_id' => $employeeId, 'exempt_cap_minor' => 700_001]],
            ], null);
        });
    }

    /** Bez evidence tržeb se strop netvrdí — schválení spadne fail-closed. */
    public function testMissingEvidenceFailsClosed(): void
    {
        $this->inTransaction(function (PDO $pdo, int $supplierId, int $employeeId): void {
            $this->expectException(PayrollRulesetException::class);
            $this->tips()->limitMinor($supplierId, $employeeId, '2027-05-01');
        });
    }

    /** Před rokem 2027 osvobození není: strop nula a evidenci nelze založit. */
    public function testYear2026HasNoExemption(): void
    {
        $this->inTransaction(function (PDO $pdo, int $supplierId, int $employeeId): void {
            self::assertSame(0, $this->tips()->limitMinor($supplierId, $employeeId, '2026-12-01'));

            $this->expectException(\InvalidArgumentException::class);
            $this->tips()->save($supplierId, '2026-12-01', [
                'revenue_minor' => 100,
                'allocation_mode' => 'per_capita',
                'recipients_count' => 1,
            ], null);
        });
    }

    private function inTransaction(callable $test): void
    {
        $this->container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $connection = $this->container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $pdo = $connection->pdo();
        $sourceSupplierId = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->beginTransaction();
        try {
            $supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
            $pdo->prepare(
                'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
                 VALUES (?, "Syntetický číšník", "employee", 1)',
            )->execute([$supplierId]);
            $test($pdo, $supplierId, (int) $pdo->lastInsertId());
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    private function tips(): PayrollFoodServiceTipService
    {
        $service = $this->container->get(PayrollFoodServiceTipService::class);
        self::assertInstanceOf(PayrollFoodServiceTipService::class, $service);

        return $service;
    }

    private function inputs(): PayrollInputRepository
    {
        $repository = $this->container->get(PayrollInputRepository::class);
        self::assertInstanceOf(PayrollInputRepository::class, $repository);

        return $repository;
    }
}
