<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Součty výpisu ostatních položek: zbývá uhradit za celý výběr po straně a měně,
 * jen potvrzené a zaúčtované položky, se stejnými filtry jako řádky výpisu.
 */
#[Group('integration')]
final class OtherItemListTotalsTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PDO $pdo;
    private OtherItemService $service;
    private int $supplierId;
    private int $registerId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        $this->service = $container->get(OtherItemService::class);
        $this->pdo->beginTransaction();
        $source = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $source);
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $source);
        $this->pdo->prepare(
            "INSERT INTO cash_registers (supplier_id, name, currency_code, account_code)
             VALUES (?, 'Syntetická pokladna', 'CZK', '211')"
        )->execute([$this->supplierId]);
        $this->registerId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->db)) $this->db->close();
    }

    public function testTotalsSplitBySideAndCurrencyAndSkipDraftsAndReversals(): void
    {
        $paid = $this->item('receivable', 'CZK', 1000, 'posted', settledOutside: 100);
        $this->allocate($paid, 300);
        $this->allocate($paid, 250, reversed: true);
        $this->item('receivable', 'CZK', 500, 'confirmed');
        // Přeplacená položka (zůstatek -100) nesmí snížit dluh ostatních.
        $this->allocate($this->item('receivable', 'CZK', 300, 'posted'), 400);
        $this->item('receivable', 'EUR', 200, 'posted');
        $this->item('payable', 'CZK', 700, 'posted');
        $this->item('receivable', 'CZK', 999, 'draft');
        $this->item('receivable', 'CZK', 888, 'reversed');
        $this->item('payable', 'CZK', 777, 'cancelled');

        $result = $this->service->list($this->supplierId, ['status' => 'all'], 1, 2);

        self::assertSame(8, $result['total']);
        self::assertSame([
            ['side' => 'receivable', 'currency' => 'CZK', 'remaining_amount' => 1100.0],
            ['side' => 'receivable', 'currency' => 'EUR', 'remaining_amount' => 200.0],
            ['side' => 'payable', 'currency' => 'CZK', 'remaining_amount' => 700.0],
        ], $result['totals'], 'Za celý výběr, ne za načtenou stránku; storno úhrady se nepočítá.');
    }

    public function testTotalsFollowListFiltersAndComeOnlyWithFirstPage(): void
    {
        $this->item('receivable', 'CZK', 400, 'posted', title: 'Nájem kanceláře');
        $this->item('receivable', 'CZK', 600, 'posted', title: 'Pojistné plnění');
        $this->item('payable', 'CZK', 900, 'posted', title: 'Nájem skladu');

        $filtered = $this->service->list($this->supplierId, ['status' => 'all', 'side' => 'receivable', 'q' => 'Nájem'], 1, 50);
        self::assertSame([['side' => 'receivable', 'currency' => 'CZK', 'remaining_amount' => 400.0]], $filtered['totals']);

        self::assertArrayNotHasKey('totals', $this->service->list($this->supplierId, ['status' => 'all'], 2, 1));

        // Výchozí filtr výpisu (otevřené): uhrazená položka zmizí z řádků i ze součtu.
        $paid = $this->item('receivable', 'CZK', 250, 'posted', title: 'Uhrazený nájem');
        $this->allocate($paid, 250);
        $open = $this->service->list($this->supplierId, ['status' => 'open'], 1, 50);
        self::assertSame(3, $open['total']);
        self::assertSame([
            ['side' => 'receivable', 'currency' => 'CZK', 'remaining_amount' => 1000.0],
            ['side' => 'payable', 'currency' => 'CZK', 'remaining_amount' => 900.0],
        ], $open['totals']);
    }

    public function testListCountsAttachedDocumentsPerItem(): void
    {
        $withDocs = $this->item('payable', 'CZK', 500, 'posted', title: 'S dokumenty');
        $without = $this->item('payable', 'CZK', 400, 'posted', title: 'Bez dokumentu');
        foreach (['smlouva', 'dodatek'] as $name) $this->attach($this->supplierId, $withDocs, $name);
        // Odkaz jiného typu se stejným id se nepočítá. Odkaz cizí firmy na položku nepustí trigger (migrace 1885).
        $this->attach($this->supplierId, $without, 'zapis', 'journal_entry');

        $items = array_column($this->service->list($this->supplierId, ['status' => 'all'], 1, 50)['items'], null, 'id');
        self::assertSame(2, $items[$withDocs]['document_count']);
        self::assertSame(0, $items[$without]['document_count']);
    }

    private function attach(int $supplierId, int $entityId, string $name, string $entityType = 'other_item'): void
    {
        $this->pdo->prepare(
            "INSERT INTO documents (supplier_id, title, original_name, filename, sha256, mime_type, size_bytes, doc_type)
             VALUES (?, ?, 'sken.pdf', 'sken.pdf', ?, 'application/pdf', 100, 'pdf')"
        )->execute([$supplierId, 'Syntetický ' . $name, hash('sha256', $name . bin2hex(random_bytes(6)))]);
        $this->pdo->prepare('INSERT INTO document_links (document_id, supplier_id, entity_type, entity_id) VALUES (?, ?, ?, ?)')
            ->execute([(int) $this->pdo->lastInsertId(), $supplierId, $entityType, $entityId]);
    }

    private function item(string $side, string $currency, float $amount, string $status,
        float $settledOutside = 0.0, string $title = 'Syntetická položka'): int
    {
        $this->pdo->prepare(
            'INSERT INTO other_items (supplier_id, side, title, issued_on, accounting_on, due_on, currency, amount,
                                      amount_czk, settled_outside_amount, status)
             VALUES (?, ?, ?, "2099-01-10", "2099-01-10", "2099-02-10", ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, $side, $title, $currency, $amount, $amount, $settledOutside, $status]);
        return (int) $this->pdo->lastInsertId();
    }

    private function allocate(int $itemId, float $amount, bool $reversed = false): void
    {
        $this->pdo->prepare(
            "INSERT INTO cash_documents (supplier_id, register_id, doc_type, purpose, doc_number,
                                         issue_date, description, total_amount, currency_code, status)
             VALUES (?, ?, 'in', 'other', ?, '2099-01-21', 'Syntetická platba', ?, 'CZK', 'posted')"
        )->execute([$this->supplierId, $this->registerId, 'SYNTH-' . bin2hex(random_bytes(4)), $amount]);
        $cashId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO other_item_allocations (supplier_id, other_item_id, cash_document_id, amount, payment_on, reversed_on)
             VALUES (?, ?, ?, ?, "2099-01-21", ?)'
        )->execute([$this->supplierId, $itemId, $cashId, $amount, $reversed ? '2099-01-22' : null]);
    }
}
