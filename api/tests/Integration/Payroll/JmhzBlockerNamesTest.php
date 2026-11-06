<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollPeopleRepository;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzBlockerExplainer;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1Blocker;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Hláška o blokaci zmrazení JMHZ jmenuje dotčené osoby. Dřív uváděla jen
 * počet („Dotčeno: 1 zaměstnanec") a účetní hledala toho jednoho ručně.
 */
#[Group('integration')]
final class JmhzBlockerNamesTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollPeopleRepository $people;
    private int $supplierId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        if ($container === null) {
            throw new \RuntimeException('Aplikační kontejner není dostupný.');
        }
        $this->db = $container->get(Connection::class);
        $this->people = $container->get(PayrollPeopleRepository::class);

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0) {
            self::markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    /** Bez výslovného předání v kontejneru by most zůstal u holého počtu. */
    public function testBridgeIsWiredWithPeopleRepository(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        self::assertNotNull($container);
        $bridge = $container->get(JmhzSubmissionBridgeService::class);

        self::assertInstanceOf(
            PayrollPeopleRepository::class,
            (new \ReflectionProperty($bridge, 'people'))->getValue($bridge),
        );
    }

    public function testFreezeMessageNamesThePersonWithUnresolvedAnnualRequest(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická Brigádnice", "employee", 1)',
        )->execute([$this->supplierId]);
        $employeeId = (int) $this->db->pdo()->lastInsertId();

        $message = JmhzBlockerExplainer::describeNamed(
            [new JmhzScenario1Blocker('jmhz_annual_request_source_missing', 'person', $employeeId, ['10319'])],
            $this->people,
            $this->supplierId,
        );

        self::assertStringContainsString('Dotčeno: 1 zaměstnanec (Syntetická Brigádnice).', $message);
        self::assertStringContainsString('Mzdy → Roční zúčtování', $message);
    }
}
