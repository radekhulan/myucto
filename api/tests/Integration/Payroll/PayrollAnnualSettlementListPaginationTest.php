<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAnnualSettlementRepository;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Přehled ročního zúčtování nesmí vysypat všechny lidi firmy najednou.
 *
 * Obrazovka `/payroll/annual-settlement` nestránkovala vůbec: server vrátil
 * všechny zaměstnance firmy a prohlížeč je všechny vykreslil. U firmy se
 * stovkami lidí je to jedna odpověď o stovkách řádků a seznam, ve kterém
 * konkrétního člověka nikdo nenajde.
 *
 * Test hlídá, že strop platí i v repozitáři (ne jen na HTTP hranici), že
 * `total` počítá celé zúžení a ne velikost stránky, a že zúžení podle jména
 * i podle stavu sahá přes celý seznam, ne jen přes načtenou stránku.
 */
#[Group('integration')]
final class PayrollAnnualSettlementListPaginationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2026;

    private Connection $db;
    private PayrollAnnualSettlementRepository $repository;
    private int $supplierId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
            $this->db = $container->get(Connection::class);
            $this->repository = $container->get(PayrollAnnualSettlementRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        foreach (['payroll_employees', 'payroll_annual_settlement_requests'] as $table) {
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

    public function testPageIsBoundedAndTotalCountsTheWholeList(): void
    {
        for ($i = 0; $i < 6; ++$i) {
            $this->seedEmployee();
        }

        $page = $this->repository->listForYear($this->supplierId, self::YEAR, 2, 0);

        self::assertCount(2, $page['items'], 'Limit musí přehled skutečně omezit.');
        self::assertSame(6, $page['total'], 'Total je počet všech lidí, ne velikost stránky.');

        $overLimit = $this->repository->listForYear($this->supplierId, self::YEAR, 10_000, 0);
        self::assertCount(6, $overLimit['items']);
        self::assertLessThanOrEqual(
            PayrollAnnualSettlementRepository::LIST_MAX_LIMIT,
            count($overLimit['items']),
            'Strop nejde obejít vyšším limitem.',
        );
    }

    public function testOffsetShiftsThePageWithoutOverlap(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->seedEmployee();
        }

        $first = $this->repository->listForYear($this->supplierId, self::YEAR, 2, 0);
        $second = $this->repository->listForYear($this->supplierId, self::YEAR, 2, 2);
        $last = $this->repository->listForYear($this->supplierId, self::YEAR, 2, 4);

        self::assertCount(2, $first['items']);
        self::assertCount(2, $second['items']);
        self::assertCount(1, $last['items'], 'Poslední stránka nesmí přetéct.');
        self::assertSame(
            [],
            array_intersect($this->ids($first), $this->ids($second)),
            'Stránky se nesmí překrývat.',
        );
        self::assertSame(
            [],
            $this->repository->listForYear($this->supplierId, self::YEAR, 2, 5)['items'],
            'Za koncem seznamu je prázdno.',
        );
    }

    /**
     * Zúžení podle jména musí najít i člověka, který na první stránce není —
     * jinak by hledání jen prohledávalo, co je zrovna na obrazovce.
     */
    public function testSearchReachesBeyondTheFirstPage(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->seedEmployee();
        }
        $needleId = $this->seedEmployee('Zzz Posledni Osoba');

        $firstPage = $this->repository->listForYear($this->supplierId, self::YEAR, 2, 0);
        self::assertNotContains(
            $needleId,
            $this->ids($firstPage),
            'Předpoklad testu: hledaný člověk na první stránce být nesmí.',
        );

        $found = $this->repository->listForYear(
            $this->supplierId,
            self::YEAR,
            2,
            0,
            'Posledni Osoba',
        );

        self::assertSame([$needleId], $this->ids($found));
        self::assertSame(1, $found['total'], 'Total musí být zúžený stejně jako stránka.');
    }

    /** Zúžení podle stavu je pojmenované, ne dopočítané ze stránky. */
    public function testStateNarrowsTheWholeList(): void
    {
        $requestedIds = [];
        for ($i = 0; $i < 3; ++$i) {
            $requestedIds[] = $this->seedEmployee(null, true);
        }
        for ($i = 0; $i < 4; ++$i) {
            $this->seedEmployee();
        }

        $all = $this->repository->listForYear($this->supplierId, self::YEAR, 100, 0);
        self::assertSame(7, $all['total']);

        $requested = $this->repository->listForYear(
            $this->supplierId,
            self::YEAR,
            100,
            0,
            '',
            'requested',
        );
        self::assertSame(3, $requested['total']);
        sort($requestedIds);
        $foundIds = $this->ids($requested);
        sort($foundIds);
        self::assertSame($requestedIds, $foundIds);

        $unsettled = $this->repository->listForYear(
            $this->supplierId,
            self::YEAR,
            100,
            0,
            '',
            'unsettled',
        );
        self::assertSame(7, $unsettled['total'], 'Bez výsledku je zatím každý.');

        $settled = $this->repository->listForYear(
            $this->supplierId,
            self::YEAR,
            100,
            0,
            '',
            'settled',
        );
        self::assertSame(0, $settled['total']);
    }

    public function testUnknownStateIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repository->listForYear($this->supplierId, self::YEAR, 25, 0, '', 'hotovo');
    }

    /**
     * @param array{items:list<array<string,mixed>>,total:int} $page
     * @return list<int>
     */
    private function ids(array $page): array
    {
        $ids = [];
        foreach ($page['items'] as $item) {
            $ids[] = (int) $item['employee_id'];
        }

        return $ids;
    }

    private function seedEmployee(?string $name = null, bool $requested = false): int
    {
        $pdo = $this->db->pdo();
        $ordinal = ++$this->sequence;
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 30000, 0, 1)'
        )->execute([
            $this->supplierId,
            $name ?? sprintf('Synteticka Osoba %02d', $ordinal),
        ]);
        $employeeId = (int) $pdo->lastInsertId();

        if ($requested) {
            $pdo->prepare(
                'INSERT INTO payroll_annual_settlement_requests
                    (supplier_id, employee_id, tax_year, request_status,
                     requested_on, request_evidence_reference)
                 VALUES (?, ?, ?, "requested", ?, "SYN-ZADOST")'
            )->execute([
                $this->supplierId,
                $employeeId,
                self::YEAR,
                sprintf('%04d-02-10', self::YEAR + 1),
            ]);
        }

        return $employeeId;
    }
}
