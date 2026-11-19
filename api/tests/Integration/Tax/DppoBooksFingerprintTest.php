<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\TaxReturnRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Tax\Return\TaxReturnService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Finalizované DPPO nese otisk účetnictví období. Průvodce uzávěrkou ho jako známou daň
 * použije, jen dokud účetnictví otisku odpovídá; zaúčtování samotné daně z příjmů (591/341)
 * otisk nemění. Přiznání finalizované před zavedením otisku se bere jako dosud.
 */
#[Group('integration')]
final class DppoBooksFingerprintTest extends TestCase
{
    private const YEAR = 2048;

    private Connection $db;
    private TaxReturnService $returns;
    private TaxReturnRepository $repo;
    private ClosingService $closing;
    private int $supplierId = 0;
    private int $periodId = 0;
    private int $userId = 0;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->returns = $container->get(TaxReturnService::class);
        $this->repo = $container->get(TaxReturnRepository::class);
        $this->closing = $container->get(ClosingService::class);
        $pdo = $this->db->pdo();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $this->userId);

        $pdo->beginTransaction();
        $constants = \MyInvoice\Service\Tax\TaxConstants::forYear(2026);
        $constants['year'] = self::YEAR;
        $pdo->prepare('INSERT INTO tax_constants (year, data) VALUES (?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)')
            ->execute([self::YEAR, json_encode($constants, JSON_UNESCAPED_UNICODE)]);
        $pdo->exec("INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id,
                taxpayer_type, ic, dic, accounting_mode)
            SELECT 'Otisk DPPO test s.r.o.', 'Zkušební 1', 'Vzorov', '10000', (SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1),
                'otisk-dppo@example.com', (SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1),
                (SELECT id FROM vat_rates ORDER BY id LIMIT 1), 'po', '12345678', 'CZ12345678', 'double_entry'");
        $this->supplierId = (int) $pdo->lastInsertId();
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $this->periodId = $container->get(AccountingPeriodRepository::class)
            ->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $this->post(self::YEAR . '-03-15', [['311', 'debit', 1000000], ['602', 'credit', 1000000]]);
        $this->post(self::YEAR . '-04-10', [['518', 'debit', 300000], ['321', 'credit', 300000]]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testChangedBooksMakeFinalizedReturnStaleButIncomeTaxEntryDoesNot(): void
    {
        $tax = $this->finalize();
        self::assertSame(147000.0, $tax);
        self::assertTrue($this->current());
        self::assertSame($tax, $this->repo->findLastKnownTax($this->supplierId, self::YEAR, 'po'));
        $preview = $this->closing->incomeTaxPreview($this->supplierId, $this->periodId);
        self::assertSame('finalized_return', $preview['suggested_source']);

        // Zaúčtování daně z finalizovaného přiznání otisk nemění.
        $this->post(self::YEAR . '-12-31', [['591', 'debit', 147000], ['341', 'credit', 147000]], 'income_tax');
        self::assertTrue($this->current());
        self::assertSame($tax, $this->repo->findLastKnownTax($this->supplierId, self::YEAR, 'po'));

        // Výnos zaúčtovaný po finalizaci: přiznání už účetnictví neodpovídá.
        $this->post(self::YEAR . '-11-20', [['311', 'debit', 50000], ['602', 'credit', 50000]]);
        self::assertFalse($this->current());
        self::assertNull($this->repo->findLastKnownTax($this->supplierId, self::YEAR, 'po'));
        $preview = $this->closing->incomeTaxPreview($this->supplierId, $this->periodId);
        self::assertFalse($preview['has_finalized_return']);
        self::assertNotSame('finalized_return', $preview['suggested_source']);
        // Dodatečné přiznání (§ 141 DŘ) potřebuje naposledy stanovenou daň i po změně účetnictví.
        self::assertSame($tax, $this->repo->findLastKnownTax($this->supplierId, self::YEAR, 'po', false));
    }

    public function testReclassificationBetweenRevenueAccountsMakesReturnStale(): void
    {
        $this->finalize();
        $this->post(self::YEAR . '-06-30', [['602', 'debit', 1000], ['604', 'credit', 1000]]);
        self::assertFalse($this->current());
    }

    public function testLegacyReturnWithoutFingerprintKeepsBeingUsed(): void
    {
        $tax = $this->finalize();
        $this->db->pdo()->prepare('UPDATE income_tax_returns SET books_fingerprint = NULL WHERE supplier_id = ?')->execute([$this->supplierId]);
        $this->post(self::YEAR . '-11-20', [['311', 'debit', 50000], ['602', 'credit', 50000]]);
        self::assertNull($this->current());
        self::assertSame($tax, $this->repo->findLastKnownTax($this->supplierId, self::YEAR, 'po'));
        self::assertSame('finalized_return', $this->closing->incomeTaxPreview($this->supplierId, $this->periodId)['suggested_source']);
    }

    public function testReopenClearsFingerprint(): void
    {
        $this->finalize();
        $row = $this->repo->find($this->supplierId, self::YEAR, 'po');
        self::assertNotNull($row['books_fingerprint']);
        $this->returns->reopen($this->supplierId, self::YEAR, 'po', (int) $row['row_version'], $this->userId);
        self::assertNull($this->repo->find($this->supplierId, self::YEAR, 'po')['books_fingerprint']);
        self::assertNull($this->returns->getReturn($this->supplierId, self::YEAR, 'po', $this->userId)['books_current']);
    }

    private function finalize(): float
    {
        $draft = $this->returns->getReturn($this->supplierId, self::YEAR, 'po', $this->userId);
        self::assertNull($draft['books_current'], 'Koncept se proti účetnictví neposuzuje.');
        $final = $this->returns->finalize($this->supplierId, self::YEAR, 'po', (int) $draft['return']['row_version'], $this->userId);
        self::assertSame('final', $final['return']['status']);
        return (float) $final['computed']['summary']['total_tax'];
    }

    private function current(): ?bool
    {
        return $this->returns->getReturn($this->supplierId, self::YEAR, 'po', $this->userId)['books_current'];
    }

    /** @param list<array{0:string,1:string,2:float|int}> $lines */
    private function post(string $date, array $lines, string $sourceType = 'manual'): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO journal_entries (supplier_id, period_id, entry_date, posted_at, source_type) VALUES (?, ?, ?, NOW(), ?)')
            ->execute([$this->supplierId, $this->periodId, $date, $sourceType]);
        $entryId = (int) $pdo->lastInsertId();
        $ids = $pdo->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
        $ins = $pdo->prepare('INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount) VALUES (?, ?, ?, ?, ?)');
        foreach ($lines as [$code, $side, $amount]) {
            $ids->execute([$this->supplierId, $code]);
            $ins->execute([$entryId, $this->supplierId, (int) $ids->fetchColumn(), $side, $amount]);
        }
    }
}
