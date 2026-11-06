<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Historie revizí politiky zaměstnavatele a seznam ručních mzdových vstupů
 * nesmí načíst všechno naráz.
 *
 * Obojí byl dotaz bez `LIMIT`: politika se verzuje při každé změně výplatního
 * termínu i automatizace, takže historie roste po celou dobu provozu firmy,
 * a ručních vstupů je za měsíc tolik, kolik má firma zaměstnanců krát počet
 * složek, které se zadávají ručně.
 *
 * Test hlídá čtyři věci: strop nejde zvednout parametrem, `total` počítá
 * všechny řádky (ne velikost stránky), `offset` stránku skutečně posune
 * a poslední stránka nepřeteče.
 */
#[Group('integration')]
final class PayrollSettingsListPaginationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD_START = '2026-06-01';

    private Connection $db;
    private PayrollEmployerPolicyRepository $policies;
    private PayrollInputRepository $inputs;
    private int $supplierId;
    private int $employeeId;
    private int $employmentId;
    private int $componentId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
            $this->db = $container->get(Connection::class);
            $this->policies = $container->get(PayrollEmployerPolicyRepository::class);
            $this->inputs = $container->get(PayrollInputRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        foreach ([
            'payroll_employer_policies',
            'payroll_inputs',
            'payroll_component_definitions',
            'payroll_employments',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Chybí integrační tabulka {$table}.");
            }
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')
            ->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->employeeId = $this->seedEmployee();
        $this->employmentId = $this->seedEmployment();
        $this->componentId = $this->seedComponent();
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

    /** Strop historie politik je tvrdý — nesmí ho zvednout parametr. */
    public function testPolicyCapCannotBeLiftedByAParameter(): void
    {
        $seeded = 6;
        $this->seedPolicies($seeded);

        $page = $this->policies->list($this->supplierId, 10_000, 0);

        self::assertCount($seeded, $page['items']);
        self::assertSame($seeded, $page['total']);

        $capped = $this->policies->list($this->supplierId, 3, 0);
        self::assertCount(3, $capped['items'], 'Limit musí stránku skutečně omezit.');
        self::assertSame(
            $seeded,
            $capped['total'],
            'Total je počet všech revizí, ne velikost stránky.',
        );
    }

    /** Druhá stránka historie politik musí vrátit jiná data než první. */
    public function testPolicyOffsetShiftsThePage(): void
    {
        $this->seedPolicies(5);

        $first = $this->policies->list($this->supplierId, 2, 0);
        $second = $this->policies->list($this->supplierId, 2, 2);
        $last = $this->policies->list($this->supplierId, 2, 4);

        self::assertCount(2, $first['items']);
        self::assertCount(2, $second['items']);
        self::assertCount(1, $last['items'], 'Poslední stránka nesmí přetéct.');
        self::assertSame(5, $second['total'], 'Total se posunem stránky nemění.');
        self::assertSame(
            [],
            array_intersect($this->ids($first['items']), $this->ids($second['items'])),
            'Stránky se nesmí překrývat.',
        );
        self::assertSame(
            [],
            $this->policies->list($this->supplierId, 2, 5)['items'],
            'Za koncem seznamu je prázdno, ne zopakovaná poslední stránka.',
        );
    }

    /** Strop seznamu ručních vstupů je tvrdý — nesmí ho zvednout parametr. */
    public function testInputCapCannotBeLiftedByAParameter(): void
    {
        $seeded = 6;
        $this->seedInputs($seeded);

        $page = $this->inputs->list($this->supplierId, self::PERIOD_START, 2, 0);

        self::assertCount(2, $page['items']);
        self::assertSame(
            $seeded,
            $page['total'],
            'Total je počet všech vstupů měsíce, ne velikost stránky.',
        );
    }

    /** Druhá stránka vstupů musí vrátit jiná data a zrušené se nepočítají. */
    public function testInputOffsetShiftsThePageAndCancelledStayOut(): void
    {
        $this->seedInputs(5);
        $cancelled = $this->seedInputs(1, 'cancelled');

        $first = $this->inputs->list($this->supplierId, self::PERIOD_START, 2, 0);
        $second = $this->inputs->list($this->supplierId, self::PERIOD_START, 2, 2);
        $last = $this->inputs->list($this->supplierId, self::PERIOD_START, 2, 4);

        self::assertCount(2, $first['items']);
        self::assertCount(2, $second['items']);
        self::assertCount(1, $last['items'], 'Poslední stránka nesmí přetéct.');
        self::assertSame(5, $first['total'], 'Zrušený vstup se do počtu nepočítá.');
        self::assertSame(5, $second['total'], 'Total se posunem stránky nemění.');
        self::assertSame(
            [],
            array_intersect($this->ids($first['items']), $this->ids($second['items'])),
            'Stránky se nesmí překrývat.',
        );
        self::assertNotContains(
            $cancelled,
            array_merge(
                $this->ids($first['items']),
                $this->ids($second['items']),
                $this->ids($last['items']),
            ),
            'Zrušený vstup se nevypisuje ani na žádné stránce.',
        );
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<int>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    private function seedPolicies(int $count): void
    {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_employer_policies
                (supplier_id, valid_from, valid_to, payday_day, payday_month_offset,
                 payday_business_day_rule, balance_rounding_mode, home_office_policy,
                 travel_expense_policy, delivery_channel, source_kind)
             VALUES (?, ?, ?, 10, 1, "previous_business_day", "exact_minor_units",
                     "not_used", "not_used", "disabled", "manual")'
        );
        for ($i = 0; $i < $count; ++$i) {
            // Revize se nesmí překrývat (databázový trigger), takže každá dostane
            // vlastní uzavřený rok.
            $statement->execute([
                $this->supplierId,
                sprintf('20%02d-01-01', 30 + $i),
                sprintf('20%02d-12-31', 30 + $i),
            ]);
        }
    }

    /** @return int id posledního založeného vstupu */
    private function seedInputs(int $count, string $status = 'draft'): int
    {
        $pdo = $this->db->pdo();
        $statement = $pdo->prepare(
            'INSERT INTO payroll_inputs
                (supplier_id, employee_id, employment_id, component_id, period_start,
                 amount_minor, source_kind, status)
             VALUES (?, ?, ?, ?, ?, ?, "manual", ?)'
        );
        $lastId = 0;
        for ($i = 0; $i < $count; ++$i) {
            $ordinal = ++$this->sequence;
            $statement->execute([
                $this->supplierId,
                $this->employeeId,
                $this->employmentId,
                $this->componentId,
                self::PERIOD_START,
                1000 + $ordinal,
                $status,
            ]);
            $lastId = (int) $pdo->lastInsertId();
        }

        return $lastId;
    }

    private function seedEmployee(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Synteticka Osoba", "employee", "hpp", 1, 1, 0, 30000, 0, 1)'
        )->execute([$this->supplierId]);

        return (int) $pdo->lastInsertId();
    }

    private function seedEmployment(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date)
             VALUES (?, ?, "ZAM-1", "employment", "active", "2026-01-01", "2026-01-01")'
        )->execute([$this->supplierId, $this->employeeId]);

        return (int) $pdo->lastInsertId();
    }

    private function seedComponent(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_component_definitions
                (supplier_id, code, name, component_kind, value_kind, frequency_kind,
                 tax_treatment, social_participation_treatment, social_treatment,
                 health_participation_treatment, health_treatment,
                 average_earning_treatment, enforcement_treatment, jmhz_treatment,
                 statistics_treatment, valid_from)
             VALUES (?, "PREMIE", "Prémie", "bonus", "monetary", "one_off",
                     "included", "included", "included", "included", "included",
                     "included", "included", "included", "included", "2026-01-01")'
        )->execute([$this->supplierId]);

        return (int) $pdo->lastInsertId();
    }
}
