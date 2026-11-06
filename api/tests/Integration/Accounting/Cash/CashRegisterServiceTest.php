<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Cash;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Service\Accounting\Cash\CashDocumentService;
use MyInvoice\Service\Accounting\Cash\CashException;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integrační testy CashRegisterService (mini-epic POKLADNA #14, §7.1). CRUD pokladen,
 * validace analytiky 211, exkluzivní výchozí pokladna, zámek analytiky po postu,
 * CZK-only, mazání jen bez dokladů, tenant izolace. Vše v transakci → rollback.
 */
#[Group('integration')]
final class CashRegisterServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2099;

    private Connection $db;
    private CashRegisterService $service;
    private CashDocumentService $documents;
    private ChartOfAccountsRepository $accounts;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db        = $container->get(Connection::class);
            $this->service   = $container->get(CashRegisterService::class);
            $this->documents = $container->get(CashDocumentService::class);
            $this->accounts  = $container->get(ChartOfAccountsRepository::class);
            $this->periods   = $container->get(AccountingPeriodRepository::class);
            $seeder          = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0) {
            $this->markTestSkipped('Chybí supplier v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $seeder->seedForSupplier($this->supplierId);
        $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testCrudAndDefaultExclusivity(): void
    {
        $this->seedAnalytic('211100');
        $this->seedAnalytic('211200');

        $aId = $this->service->create($this->supplierId, ['name' => 'Hlavní pokladna', 'account_code' => '211100', 'is_default' => true]);
        $bId = $this->service->create($this->supplierId, ['name' => 'Vedlejší pokladna', 'account_code' => '211200', 'is_default' => true]);

        $list = $this->service->list($this->supplierId);
        self::assertCount(2, $list);

        // Druhá výchozí shodila příznak u první (exkluzivita v transakci).
        $a = $this->service->get($this->supplierId, $aId);
        $b = $this->service->get($this->supplierId, $bId);
        self::assertFalse($a['is_default'], 'Nastavením B jako výchozí ztratila A příznak.');
        self::assertTrue($b['is_default']);
        self::assertSame('211100', $a['account_code']);
        self::assertSame(0, $a['documents_count']);

        // Update názvu + přehození výchozí zpět na A.
        $this->service->update($this->supplierId, $aId, ['name' => 'Přejmenovaná', 'is_default' => true]);
        $a = $this->service->get($this->supplierId, $aId);
        $b = $this->service->get($this->supplierId, $bId);
        self::assertSame('Přejmenovaná', $a['name']);
        self::assertTrue($a['is_default']);
        self::assertFalse($b['is_default']);
    }

    public function testDuplicateAccountRejectedEvenAgainstInactive(): void
    {
        $this->seedAnalytic('211100');
        $id = $this->service->create($this->supplierId, ['name' => 'A', 'account_code' => '211100']);

        // Deaktivace neuvolní analytiku (B5).
        $this->service->update($this->supplierId, $id, ['is_active' => false]);

        try {
            $this->service->create($this->supplierId, ['name' => 'B', 'account_code' => '211100']);
            self::fail('Duplicitní analytika (i proti neaktivní pokladně) musí selhat.');
        } catch (CashException $e) {
            self::assertSame('account_taken', $e->errorCode);
        }
    }

    public function testDuplicateNameRejected(): void
    {
        $this->seedAnalytic('211100');
        $this->seedAnalytic('211200');
        $this->service->create($this->supplierId, ['name' => 'Pokladna', 'account_code' => '211100']);

        $this->expectException(CashException::class);
        $this->service->create($this->supplierId, ['name' => 'Pokladna', 'account_code' => '211200']);
    }

    public function testNon211OrMissingAccountRejected(): void
    {
        // Účet mimo skupinu 211.
        try {
            $this->service->create($this->supplierId, ['name' => 'X', 'account_code' => '311']);
            self::fail('Účet mimo 211 musí selhat.');
        } catch (CashException $e) {
            self::assertSame('account_invalid', $e->errorCode);
        }
        // 211-prefix, ale neexistuje v osnově.
        try {
            $this->service->create($this->supplierId, ['name' => 'Y', 'account_code' => '211999']);
            self::fail('Neexistující analytika musí selhat.');
        } catch (CashException $e) {
            self::assertSame('account_invalid', $e->errorCode);
        }
    }

    public function testAccountLockedAfterPostedDocument(): void
    {
        $this->seedAnalytic('211100');
        $this->seedAnalytic('211200');
        $id = $this->service->create($this->supplierId, ['name' => 'A', 'account_code' => '211100']);
        $this->postSimpleDocument($id);

        $this->expectException(CashException::class);
        $this->expectExceptionMessageMatches('/nelze změnit/u');
        $this->service->update($this->supplierId, $id, ['account_code' => '211200']);
    }

    public function testForeignCurrencyRegisterWithExplicitAnalytic(): void
    {
        // Valutová pokladna s explicitní analytikou — §11. account_code nemusí předem
        // existovat v osnově, service ji dohraje (jako u valutové banky #35).
        $id = $this->service->create($this->supplierId, ['name' => 'EUR pokladna', 'account_code' => '211500', 'currency_code' => 'eur']);
        $detail = $this->service->get($this->supplierId, $id);
        self::assertSame('EUR', $detail['currency_code'], 'Měna se uloží velkými písmeny.');
        self::assertSame('211500', $detail['account_code']);
        self::assertNotNull($detail['account_id'], 'Analytika 211500 byla dohrána do osnovy.');
        // Duální zůstatek: valutová pokladna vrací i cizoměnový zůstatek (zatím 0).
        self::assertArrayHasKey('balance_foreign', $detail);
        self::assertEqualsWithDelta(0.0, (float) $detail['balance_foreign'], 0.001);
    }

    public function testForeignOpeningBalanceEntersForeignBalance(): void
    {
        // Peníze v pokladně před prvním dokladem v MyÚčtu: počáteční stav ve valutě k datu.
        $id = $this->service->create($this->supplierId, ['name' => 'EUR pokladna', 'account_code' => '211500', 'currency_code' => 'EUR']);
        $this->service->update($this->supplierId, $id, ['opening_foreign_amount' => '2858,02', 'opening_foreign_date' => '2019-01-01']);
        $detail = $this->service->get($this->supplierId, $id);
        self::assertSame(2858.02, $detail['opening_foreign_amount']);
        self::assertSame('2019-01-01', $detail['opening_foreign_date']);
        self::assertEqualsWithDelta(2858.02, (float) $detail['balance_foreign'], 0.001);
        self::assertEqualsWithDelta(0.0, $this->service->documentsSignedForeignTotal($this->supplierId, $id, '2018-12-31'), 0.001,
            'Před dnem počátečního stavu se nezapočítá.');

        try {
            $this->service->update($this->supplierId, $id, ['opening_foreign_amount' => 10, 'opening_foreign_date' => '']);
            self::fail('Počáteční stav bez data musí selhat.');
        } catch (CashException $e) {
            self::assertSame('validation', $e->errorCode);
        }

        $this->service->update($this->supplierId, $id, ['opening_foreign_amount' => '', 'opening_foreign_date' => '']);
        $cleared = $this->service->get($this->supplierId, $id);
        self::assertNull($cleared['opening_foreign_amount']);
        self::assertEqualsWithDelta(0.0, (float) $cleared['balance_foreign'], 0.001);

        $this->seedAnalytic('211100');
        $czk = $this->service->create($this->supplierId, ['name' => 'Kč', 'account_code' => '211100']);
        try {
            $this->service->update($this->supplierId, $czk, ['opening_foreign_amount' => 5, 'opening_foreign_date' => '2026-01-01']);
            self::fail('Korunová pokladna valutový počáteční stav nemá.');
        } catch (CashException $e) {
            self::assertSame('validation', $e->errorCode);
        }
    }

    public function testForeignCurrencyRegisterAutoAnalytic(): void
    {
        // Bez zadané analytiky se přidělí první volná 211.xxx automaticky (tečkovaný tvar).
        $id = $this->service->create($this->supplierId, ['name' => 'USD pokladna', 'currency_code' => 'USD']);
        $detail = $this->service->get($this->supplierId, $id);
        self::assertSame('USD', $detail['currency_code']);
        self::assertMatchesRegularExpression('/^211[.]\d{3}$/', (string) $detail['account_code'], 'Auto-přidělená analytika 211.NNN.');
        self::assertNotNull($detail['account_id']);
    }

    public function testForeignRegisterRejectsBare211(): void
    {
        try {
            $this->service->create($this->supplierId, ['name' => 'EUR', 'account_code' => '211', 'currency_code' => 'EUR']);
            self::fail('Holé 211 pro valutovou pokladnu musí selhat.');
        } catch (CashException $e) {
            self::assertSame('account_invalid', $e->errorCode);
        }
    }

    public function testDeleteBlockedWithDocuments(): void
    {
        $this->seedAnalytic('211100');
        $this->seedAnalytic('211200');
        $withDocs = $this->service->create($this->supplierId, ['name' => 'S doklady', 'account_code' => '211100']);
        $empty    = $this->service->create($this->supplierId, ['name' => 'Prázdná', 'account_code' => '211200']);
        $this->postSimpleDocument($withDocs);

        try {
            $this->service->delete($this->supplierId, $withDocs);
            self::fail('Mazání pokladny s doklady musí selhat.');
        } catch (CashException $e) {
            self::assertSame('register_has_documents', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }

        // Bez dokladů OK.
        $this->service->delete($this->supplierId, $empty);
        self::assertNull($this->accountRegister($this->supplierId, $empty));
    }

    public function testTenantIsolation(): void
    {
        $this->seedAnalytic('211100');
        $id = $this->service->create($this->supplierId, ['name' => 'A', 'account_code' => '211100']);
        $other = $this->supplierId + 99999;

        try {
            $this->service->get($other, $id);
            self::fail('Registr cizí firmy nesmí být viditelný.');
        } catch (CashException $e) {
            self::assertSame('register_not_found', $e->errorCode);
        }
        try {
            $this->service->update($other, $id, ['name' => 'Hack']);
            self::fail('Registr cizí firmy nesmí být editovatelný.');
        } catch (CashException $e) {
            self::assertSame('register_not_found', $e->errorCode);
        }
    }

    public function testForeignAutoAnalyticPrefersCoaFreeCode(): void
    {
        // 211001 už existuje v osnově (bez ledgeru) → auto-přidělení ho PŘESKOČÍ a vezme
        // první kód BEZ účtu v osnově, aby neadoptovalo cizí analytiku. Zároveň se tím
        // ověřuje, že obsazenost čísla se hlídá i na BEZTEČKOVÉM tvaru z doby před
        // migrací 1322 — nový kód se skládá tečkovaně (211.002).
        $this->seedAnalytic('211001');
        $id = $this->service->create($this->supplierId, ['name' => 'EUR auto', 'currency_code' => 'EUR']);
        $detail = $this->service->get($this->supplierId, $id);
        self::assertNotSame('211001', $detail['account_code'], 'Auto-přidělení nesmí adoptovat existující analytiku.');
        self::assertSame('211.002', $detail['account_code']);
    }

    public function testForeignRegisterRejectsAccountWithLedgerHistory(): void
    {
        // Analytika 211700 existuje a MÁ ledgerový zápis (ale žádná pokladna ji nedrží) →
        // valutová pokladna ji nesmí adoptovat (kontaminovaný zůstatek).
        $accId = $this->seedAnalytic('211700');
        $this->seedLedgerLine($accId);
        try {
            $this->service->create($this->supplierId, ['name' => 'EUR', 'account_code' => '211700', 'currency_code' => 'EUR']);
            self::fail('Adopce účtu s ledgerovou historií musí selhat.');
        } catch (CashException $e) {
            self::assertSame('account_taken', $e->errorCode);
            self::assertStringContainsString('zápisy', $e->getMessage());
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function seedLedgerLine(int $accountId): void
    {
        $pdo = $this->db->pdo();
        $periodId = (int) $pdo->query(
            "SELECT id FROM accounting_periods WHERE supplier_id = {$this->supplierId} ORDER BY id LIMIT 1"
        )->fetchColumn();
        $pdo->prepare(
            'INSERT INTO journal_entries (supplier_id, period_id, entry_date, description, source_type, posted_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        )->execute([$this->supplierId, $periodId, self::YEAR . '-06-15', 'seed', 'manual']);
        $entryId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount) VALUES (?, ?, ?, ?, ?)'
        )->execute([$entryId, $this->supplierId, $accountId, 'debit', 100.00]);
    }

    /**
     * L-9: osnova doplní tečku sama (`211200` → `211.200`), formulář pokladny ne —
     * kdo zadal totéž číslo na obou místech, dostal `account_invalid` u účtu,
     * který v osnově existuje.
     */
    public function testAccountCodeWithoutDotResolvesToDottedAnalytic(): void
    {
        $this->seedAnalytic('211.200');

        $id = $this->service->create($this->supplierId, ['name' => 'Bez tečky', 'account_code' => '211200']);

        self::assertSame('211.200', $this->service->get($this->supplierId, $id)['account_code']);
    }

    /** Legacy netečkovaný účet v osnově zůstává platný — normalizace ho nepřepíše. */
    public function testLegacyUndottedAccountStillWins(): void
    {
        $this->seedAnalytic('211300');

        $id = $this->service->create($this->supplierId, ['name' => 'Legacy', 'account_code' => '211300']);

        self::assertSame('211300', $this->service->get($this->supplierId, $id)['account_code']);
    }

    /**
     * L-2: `ensureOwnSeries()` běží při KAŽDÉM výdeji čísla. Přes
     * `updateSeries(next_number => 1)` by existující čítač vrátila na jedničku
     * (`ON DUPLICATE KEY UPDATE`), takže by dvě souběžná vystavení vydala totéž číslo.
     */
    public function testEnsureOwnSeriesNeverResetsExistingCounter(): void
    {
        $this->seedAnalytic('211100');
        $id = $this->service->create($this->supplierId, [
            'name' => 'Vlastní řada', 'account_code' => '211100', 'own_series' => true,
        ]);

        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE accounting_document_series SET next_number = 42
              WHERE supplier_id = ? AND register_id = ? AND series_code = ? AND fiscal_year = ?'
        )->execute([$this->supplierId, $id, 'cash_in', (int) date('Y')]);

        $this->service->ensureOwnSeries($this->supplierId, $id, (int) date('Y'));

        $stmt = $pdo->prepare(
            'SELECT next_number FROM accounting_document_series
              WHERE supplier_id = ? AND register_id = ? AND series_code = ? AND fiscal_year = ?'
        );
        $stmt->execute([$this->supplierId, $id, 'cash_in', (int) date('Y')]);
        self::assertSame(42, (int) $stmt->fetchColumn());
    }

    /** L-3: přes přelom roku se dědí prefix i šablona čísla, ne jen prefix. */
    public function testOwnSeriesInheritsNumberFormatAcrossYears(): void
    {
        $this->seedAnalytic('211100');
        $id = $this->service->create($this->supplierId, [
            'name' => 'Převzatá řada', 'account_code' => '211100', 'own_series' => true,
        ]);

        $year = (int) date('Y');
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "UPDATE accounting_document_series SET prefix = 'HP', number_format = '{YY}{PREFIX}{CCCCC}'
              WHERE supplier_id = ? AND register_id = ? AND fiscal_year = ? AND series_code = 'cash_in'"
        )->execute([$this->supplierId, $id, $year]);

        $this->service->ensureOwnSeries($this->supplierId, $id, $year + 1);

        $stmt = $pdo->prepare(
            'SELECT prefix, number_format FROM accounting_document_series
              WHERE supplier_id = ? AND register_id = ? AND series_code = ? AND fiscal_year = ?'
        );
        $stmt->execute([$this->supplierId, $id, 'cash_in', $year + 1]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('HP', (string) $row['prefix']);
        self::assertSame('{YY}{PREFIX}{CCCCC}', (string) $row['number_format']);
    }

    private function seedAnalytic(string $code): int
    {
        $parent = $this->accounts->findByCode($this->supplierId, '211');
        return $this->accounts->insert($this->supplierId, [
            'code'        => $code,
            'account_code' => $code,
            'name'        => 'Pokladna ' . $code,
            'account_type' => 'asset',
            'normal_side' => 'debit',
            'is_synthetic' => false,
            'parent_id'   => $parent !== null ? (int) $parent['id'] : null,
            'is_active'   => true,
        ]);
    }

    private function postSimpleDocument(int $registerId): void
    {
        $this->documents->create($this->supplierId, [
            'register_id'          => $registerId,
            'doc_type'             => 'in',
            'purpose'              => 'other',
            'issue_date'           => self::YEAR . '-06-15',
            'description'          => 'Přebytek pokladny',
            'total_amount'         => 500.00,
            'counter_account_code' => '668',
            'post'                 => true,
        ], $this->userId);
    }

    private function accountRegister(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM cash_registers WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}
