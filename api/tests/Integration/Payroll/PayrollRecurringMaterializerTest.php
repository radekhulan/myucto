<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Service\Payroll\Component\PayrollRecurringMaterializer;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Materializace opakovaných složek je třetí vstupní brána mzdových dat
 * (nález C-19) a jediná, která data do měsíce zakládá SAMA, bez souboru a bez
 * kliknutí. O to důležitější je, aby se dalo poznat, co udělala a co vědomě
 * neudělala: každý přeskočený předpis musí přijít s důvodem (`reason`), jinak
 * účetní na chybějící složku přijde až z výplatní pásky.
 *
 * Druhá polovina testů drží idempotenci — spuštění dvakrát nesmí založit dvě
 * částky. Vše běží v transakci, kterou tearDown vrací zpět.
 */
#[Group('integration')]
final class PayrollRecurringMaterializerTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = '2026-06';

    private Connection $db;
    private ContainerInterface $container;
    private PayrollRecurringMaterializer $materializer;
    private int $supplierId;
    private int $userId;
    private int $employeeId;
    private int $employmentId;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $db = $this->container->get(Connection::class);
        $materializer = $this->container->get(PayrollRecurringMaterializer::class);
        if (!$db instanceof Connection
            || !$materializer instanceof PayrollRecurringMaterializer
        ) {
            throw new \RuntimeException('Materializace opakovaných složek není dostupná.');
        }
        $this->db = $db;
        $this->materializer = $materializer;
        foreach ([
            'payroll_employees',
            'payroll_employments',
            'payroll_component_definitions',
            'payroll_recurring_components',
            'payroll_inputs',
        ] as $table) {
            if (!$db->hasTable($table)) {
                self::markTestSkipped("Chybí tabulka {$table}.");
            }
        }

        $pdo = $db->pdo();
        $sourceSupplierId = $this->firstId($pdo, 'supplier');
        $this->userId = $this->firstId($pdo, 'users');
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
        [$this->employeeId, $this->employmentId] = $this->createEmployment('REK-1');
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

    /**
     * Pevná měsíční částka na celý měsíc je nejjednodušší předpis, jaký může
     * existovat — a zároveň měřítko toho, že se vůbec něco zakládá. Vstup musí
     * vzniknout se zdrojem `recurring` a s odkazem na předpis, ze kterého vznikl.
     */
    public function testFixedMonthlyPrescriptionIsMaterialized(): void
    {
        $componentId = $this->createComponent('PRISPEVEK_REK');
        $recurringId = $this->createRecurring($componentId, amountMinor: 150_000);

        $result = $this->materializer->materialize(
            $this->supplierId,
            self::PERIOD,
            $this->userId,
        );

        self::assertSame('2026-06', $result['period']);
        self::assertSame(1, $result['created_count']);
        self::assertSame(0, $result['replayed_count']);
        self::assertSame(0, $result['manual_review_count']);
        $created = PayrollTimeValue::rows($result['created'], 'created')[0];
        self::assertSame($recurringId, $created['recurring_component_id']);
        self::assertSame(150_000, $created['amount_minor']);

        $input = $this->fetchInput("recurring:{$recurringId}");
        self::assertSame('recurring', $input['source_kind']);
        self::assertSame(150_000, (int) $input['amount_minor']);
        self::assertSame('2026-06-01', $input['period_start']);
        self::assertSame($recurringId, (int) $input['recurring_component_id']);
        self::assertSame($this->employeeId, (int) $input['employee_id']);
        // Snapshot předpisu je součást auditní stopy: bez něj by po pozdější
        // změně předpisu nešlo doložit, z čeho částka vznikla.
        self::assertIsString($input['source_snapshot_json']);
        self::assertNotSame('', $input['source_snapshot_json']);
    }

    /**
     * Opakované spuštění (například po přepočtu měsíce) nesmí založit druhou
     * částku. Předpis se v měsíci pozná podle `external_id`, takže druhý běh
     * jen zopakuje, co už existuje.
     */
    public function testSecondRunReplaysInsteadOfDuplicating(): void
    {
        $componentId = $this->createComponent('PRISPEVEK_REK');
        $recurringId = $this->createRecurring($componentId, amountMinor: 150_000);

        $first = $this->materializer->materialize(
            $this->supplierId,
            self::PERIOD,
            $this->userId,
        );
        $second = $this->materializer->materialize(
            $this->supplierId,
            self::PERIOD,
            $this->userId,
        );

        self::assertSame(1, $first['created_count']);
        self::assertSame(0, $second['created_count']);
        self::assertSame(1, $second['replayed_count']);
        self::assertSame(
            PayrollTimeValue::rows($first['created'], 'created')[0]['input_id'],
            PayrollTimeValue::rows($second['replayed'], 'replayed')[0]['input_id'],
        );
        self::assertSame(1, $this->countInputs());
        self::assertSame($recurringId, (int) $this->fetchInput("recurring:{$recurringId}")['recurring_component_id']);
    }

    /**
     * Předpis označený k ručnímu posouzení se NESMÍ dopočítat. Vrací se v
     * `manual_review` s důvodem — právě ten důvod je jediné, co účetní v UI
     * uvidí, takže se testuje doslova.
     */
    public function testManualReviewPrescriptionIsSkippedWithReason(): void
    {
        $componentId = $this->createComponent('RUCNI_REK');
        $recurringId = $this->createRecurring(
            $componentId,
            calculationKind: 'manual_review',
            amountMinor: null,
        );

        $result = $this->materializer->materialize(
            $this->supplierId,
            self::PERIOD,
            $this->userId,
        );

        self::assertSame(0, $result['created_count']);
        self::assertSame(1, $result['manual_review_count']);
        $blocked = PayrollTimeValue::rows($result['manual_review'], 'manual_review')[0];
        self::assertSame($recurringId, $blocked['recurring_component_id']);
        self::assertSame($this->employmentId, $blocked['employment_id']);
        self::assertSame($componentId, $blocked['component_id']);
        self::assertSame('Předpis vyžaduje ruční určení částky.', $blocked['reason']);
        self::assertSame(0, $this->countInputs());
    }

    /**
     * Deaktivovaná mzdová složka je jediný důvod k přeskočení, který
     * materializátor rozhoduje sám (nikoliv kalkulátor). Předpis na ni může
     * zůstat aktivní — tichým dopočtem by se ale do měsíce dostala složka,
     * kterou firma vypnula.
     */
    public function testInactiveComponentIsSkippedWithReason(): void
    {
        $componentId = $this->createComponent('VYPNUTA_REK', isActive: false);
        $this->createRecurring($componentId, amountMinor: 150_000);

        $result = $this->materializer->materialize(
            $this->supplierId,
            self::PERIOD,
            $this->userId,
        );

        self::assertSame(0, $result['created_count']);
        self::assertSame(1, $result['manual_review_count']);
        self::assertSame(
            'Mzdová složka není aktivní.',
            PayrollTimeValue::rows($result['manual_review'], 'manual_review')[0]['reason'],
        );
        self::assertSame(0, $this->countInputs());
    }

    /**
     * Rozpočítání podle pracovních dnů nebo hodin potřebuje potvrzenou docházku,
     * kterou materializátor nemá — takže se nehádá a pošle to k ruce.
     */
    public function testTimeBasedAllocationRequiresManualReview(): void
    {
        $componentId = $this->createComponent('HODINOVA_REK');
        $this->createRecurring(
            $componentId,
            amountMinor: 150_000,
            allocationRule: 'working_days',
        );

        $result = $this->materializer->materialize(
            $this->supplierId,
            self::PERIOD,
            $this->userId,
        );

        self::assertSame(1, $result['manual_review_count']);
        self::assertStringContainsString(
            'pracovních dnů nebo hodin',
            PayrollTimeValue::rows($result['manual_review'], 'manual_review')[0]['reason'],
        );
    }

    /**
     * Procentní předpis se počítá z pravidelné hrubé částky vztahu. Když ji
     * vztah nemá sjednanou, není z čeho počítat — a hádat nulu by znamenalo
     * tiše nevyplatit složku.
     */
    public function testPercentagePrescriptionWithoutGrossGoesToManualReview(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE payroll_employments SET monthly_gross_minor = NULL
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employmentId]);
        $componentId = $this->createComponent('PROCENTNI_REK');
        $this->createRecurring(
            $componentId,
            calculationKind: 'employment_gross_basis_points',
            amountMinor: null,
            rateBasisPoints: 1_000,
        );

        $result = $this->materializer->materialize(
            $this->supplierId,
            self::PERIOD,
            $this->userId,
        );

        self::assertSame(0, $result['created_count']);
        self::assertSame(
            'Pracovní vztah nemá sjednanou pravidelnou hrubou částku.',
            PayrollTimeValue::rows($result['manual_review'], 'manual_review')[0]['reason'],
        );
    }

    /**
     * Procentní předpis se sjednanou hrubou částkou: 10 % ze 42 000 Kč je
     * 4 200 Kč. Kontroluje se konkrétní číslo, ne jen „něco vzniklo" — chyba
     * v zaokrouhlení nebo v převodu basis points je přesně to, co se v součtu
     * mzdy ztratí.
     */
    public function testPercentagePrescriptionUsesEmploymentGross(): void
    {
        $componentId = $this->createComponent('PROCENTNI_REK');
        $this->createRecurring(
            $componentId,
            calculationKind: 'employment_gross_basis_points',
            amountMinor: null,
            rateBasisPoints: 1_000,
        );

        $result = $this->materializer->materialize(
            $this->supplierId,
            self::PERIOD,
            $this->userId,
        );

        self::assertSame(1, $result['created_count']);
        self::assertSame(
            420_000,
            PayrollTimeValue::rows($result['created'], 'created')[0]['amount_minor'],
        );
    }

    /**
     * Kalendářní rozpočítání u předpisu, který v měsíci začíná: od 16. 6. do
     * konce června je 15 z 30 dnů, tedy polovina. Tenhle test hlídá, že se
     * poměrná část opravdu počítá — ne že se pošle plná měsíční částka.
     */
    public function testCalendarDaysAllocationProratesPartialMonth(): void
    {
        $componentId = $this->createComponent('POMERNA_REK');
        $this->createRecurring(
            $componentId,
            amountMinor: 300_000,
            allocationRule: 'calendar_days',
            validFrom: '2026-06-16',
        );

        $result = $this->materializer->materialize(
            $this->supplierId,
            self::PERIOD,
            $this->userId,
        );

        self::assertSame(1, $result['created_count']);
        self::assertSame(
            150_000,
            PayrollTimeValue::rows($result['created'], 'created')[0]['amount_minor'],
        );
    }

    /**
     * Neaktivní předpis a předpis mimo účinnost se do měsíce nesmí dostat vůbec
     * — ani jako přeskočený. Kdyby se objevily v `manual_review`, účetní by
     * řešila složky, které firma dávno zrušila.
     */
    public function testInactiveAndOutOfRangePrescriptionsAreNotConsidered(): void
    {
        $expired = $this->createComponent('UKONCENA_REK');
        $this->createRecurring(
            $expired,
            amountMinor: 150_000,
            validFrom: '2026-01-01',
            validTo: '2026-05-31',
        );
        $disabled = $this->createComponent('NEAKTIVNI_REK');
        $this->createRecurring($disabled, amountMinor: 150_000, isActive: false);

        $result = $this->materializer->materialize(
            $this->supplierId,
            self::PERIOD,
            $this->userId,
        );

        self::assertSame(0, $result['created_count']);
        self::assertSame(0, $result['replayed_count']);
        self::assertSame(0, $result['manual_review_count']);
        self::assertSame(0, $this->countInputs());
    }

    public function testRejectsPeriodThatIsNotMonth(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Období musí být měsíc YYYY-MM.');
        $this->materializer->materialize($this->supplierId, '2026-06-01', $this->userId);
    }

    /**
     * Předpisy cizí firmy se v našem měsíci nesmí objevit. Filtr na supplier_id
     * je v dotazu, ale je to hranice mezi klienty účtárny — patří pod test.
     */
    public function testPrescriptionsOfAnotherSupplierAreNotMaterialized(): void
    {
        $pdo = $this->db->pdo();
        $foreignSupplierId = $this->createIsolatedSupplier($pdo, $this->firstId($pdo, 'supplier'));
        $previous = $this->supplierId;
        $this->supplierId = $foreignSupplierId;
        [, $foreignEmploymentId] = $this->createEmployment('CIZI-REK-1');
        $foreignComponentId = $this->createComponent('CIZI_REK');
        $this->createRecurring(
            $foreignComponentId,
            amountMinor: 150_000,
            employmentId: $foreignEmploymentId,
        );
        $this->supplierId = $previous;

        $result = $this->materializer->materialize(
            $this->supplierId,
            self::PERIOD,
            $this->userId,
        );

        self::assertSame(0, $result['created_count']);
        self::assertSame(0, $result['manual_review_count']);
    }

    private function createRecurring(
        int $componentId,
        ?int $amountMinor = null,
        string $calculationKind = 'fixed_amount',
        ?int $rateBasisPoints = null,
        string $allocationRule = 'full_month',
        string $validFrom = '2026-01-01',
        ?string $validTo = null,
        bool $isActive = true,
        ?int $employmentId = null,
    ): int {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_recurring_components
                (supplier_id, employment_id, component_id, calculation_kind,
                 amount_minor, rate_basis_points, valid_from, valid_to,
                 allocation_rule, maximum_amount_minor, note, is_active,
                 created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $employmentId ?? $this->employmentId,
            $componentId,
            $calculationKind,
            $amountMinor,
            $rateBasisPoints,
            $validFrom,
            $validTo,
            $allocationRule,
            $isActive ? 1 : 0,
            $this->userId,
            $this->userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array{0:int,1:int} */
    private function createEmployment(string $code): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 42000, 0, 1)'
        )->execute([$this->supplierId, "Opakovaná osoba {$code}"]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor,
                 is_legacy_projection)
             VALUES (?, ?, ?, "employment", "active",
                     "2026-01-01", "2026-01-01", 4200000, 0)'
        )->execute([$this->supplierId, $employeeId, $code]);
        return [$employeeId, (int) $pdo->lastInsertId()];
    }

    private function createComponent(string $code, bool $isActive = true): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_component_definitions
                (supplier_id, code, name, component_kind, value_kind,
                 frequency_kind, tax_treatment,
                 social_participation_treatment, social_treatment,
                 health_participation_treatment, health_treatment,
                 average_earning_treatment, enforcement_treatment,
                 jmhz_treatment, statistics_treatment,
                 accounting_debit_code, accounting_credit_code,
                 valid_from, is_active)
             VALUES (?, ?, ?, "bonus", "monetary", "regular", "included",
                     "included", "included", "included", "included",
                     "included", "included", "included", "included",
                     "521", "331", "2026-01-01", ?)'
        )->execute([
            $this->supplierId,
            $code,
            "Opakovaná {$code}",
            $isActive ? 1 : 0,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function fetchInput(string $externalId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_inputs
              WHERE supplier_id = ? AND external_id = ? AND source_kind = "recurring"'
        );
        $stmt->execute([$this->supplierId, $externalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row, "Vstup {$externalId} nebyl založen.");
        return $row;
    }

    private function countInputs(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_inputs WHERE supplier_id = ?'
        );
        $stmt->execute([$this->supplierId]);
        return (int) $stmt->fetchColumn();
    }

    private function firstId(PDO $pdo, string $table): int
    {
        if (!in_array($table, ['supplier', 'users'], true)) {
            throw new \InvalidArgumentException('Nepodporovaná testovací tabulka.');
        }
        $stmt = $pdo->query("SELECT id FROM {$table} ORDER BY id LIMIT 1");
        if ($stmt === false) {
            throw new \RuntimeException("Tabulku {$table} nelze načíst.");
        }
        $value = $stmt->fetchColumn();
        return $value === false ? 0 : PayrollTimeValue::int($value, "{$table}.id");
    }
}
