<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\OtherItemScheduleService;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Accounting\PostingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Měsíční kontrola ostatních pohledávek a závazků: splátky po splatnosti bez úhrady
 * a zůstatek účtů proti otevřeným položkám. Vlastní dodavatel, transakce s rollbackem.
 */
#[Group('integration')]
final class OtherItemMonthlyCheckTest extends TestCase
{
    private const YEAR = 2096;

    private Connection $db;
    private ClosingService $closing;
    private OtherItemService $items;
    private OtherItemScheduleService $schedules;
    private PostingService $posting;
    private int $supplierId = 0;
    private int $periodId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje.');
        }
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->closing = $container->get(ClosingService::class);
        $this->items = $container->get(OtherItemService::class);
        $this->schedules = $container->get(OtherItemScheduleService::class);
        $this->posting = $container->get(PostingService::class);
        $pdo = $this->db->pdo();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' LIMIT 1")->fetchColumn();
        $vatRateId = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $czId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        if (!$this->userId || !$currencyId || !$vatRateId || !$czId) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
        $pdo->beginTransaction();
        $this->inTx = true;
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES ("Ostatní položky kontrola s.r.o.", "Testovací 1", "Praha", "11000", ?, "oi-check@example.com", ?, ?, "double_entry")'
        )->execute([$czId, $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $this->periodId = $container->get(AccountingPeriodRepository::class)
            ->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) $pdo->rollBack();
            $this->db->close();
        }
    }

    public function testOverdueInstallmentIsFoundPerItemAndPaidOneIsNot(): void
    {
        $open = $this->item('Nezaplacený úvěr', [['01-10', 1000], ['02-10', 1000], ['09-10', 1000]]);
        $paid = $this->item('Zaplacený úvěr', [['01-10', 250], ['01-20', 250]]);
        $this->db->pdo()->prepare('UPDATE other_items SET settled_outside_amount = 500 WHERE id = ?')->execute([$paid]);

        $check = $this->check('other_item_installments_overdue', '03-31');
        self::assertFalse($check['ok']);
        self::assertSame('warning', $check['severity']);
        self::assertSame(1, $check['value']['count']);
        $finding = $check['value']['findings'][0];
        self::assertSame('other_item', $finding['doc_type']);
        self::assertSame($open, $finding['doc_id']);
        self::assertSame(2000.0, $finding['amount']);

        $this->db->pdo()->prepare('UPDATE other_items SET settled_outside_amount = 1000 WHERE id = ?')->execute([$open]);
        $check = $this->check('other_item_installments_overdue', '03-31');
        self::assertSame(1000.0, $check['value']['findings'][0]['amount']);

        self::assertTrue($this->check('other_item_installments_overdue', '01-05')['ok']);
    }

    public function testBalanceMatchingOpenItemsIsOkAndLedgerDifferenceWarns(): void
    {
        $this->item('Úvěr', [['06-10', 1500], ['07-10', 1500]]);
        $ok = $this->check('other_item_account_balance', '03-31');
        self::assertTrue($ok['ok'], json_encode($ok));

        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '325', 'side' => 'debit', 'amount' => 1000],
            ['account_code' => '221', 'side' => 'credit', 'amount' => 1000],
        ], ['entry_date' => self::YEAR . '-03-20', 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        $warn = $this->check('other_item_account_balance', '03-31');
        self::assertFalse($warn['ok']);
        self::assertSame('warning', $warn['severity']);
        self::assertSame('325', $warn['value']['findings'][0]['account_code']);
        self::assertSame(1000.0, $warn['value']['findings'][0]['amount']);
    }

    public function testBalanceCheckIsSkippedForTaxEvidence(): void
    {
        $this->item('Úvěr', [['06-10', 1500], ['07-10', 1500]]);
        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")->execute([$this->supplierId]);
        $keys = array_column($this->closing->monthlyCheck($this->supplierId, $this->periodId, self::YEAR . '-01-01', self::YEAR . '-03-31')['checks'], 'key');
        self::assertNotContains('other_item_account_balance', $keys);
        self::assertContains('other_item_installments_overdue', $keys);
    }

    /** @param list<array{0:string,1:float|int}> $installments MM-DD v roce testu */
    private function item(string $title, array $installments): int
    {
        $total = array_sum(array_column($installments, 1));
        $draft = $this->items->create($this->supplierId, [
            'side' => 'payable', 'kind' => 'loan', 'title' => $title,
            'issued_on' => self::YEAR . '-01-02', 'accounting_on' => self::YEAR . '-01-02',
            'due_on' => self::YEAR . '-12-31', 'currency' => 'CZK', 'amount' => $total,
            'counter_account_code' => '221',
        ], $this->userId);
        $id = (int) $draft['id'];
        $this->schedules->setInstallments($this->supplierId, $id, array_map(
            static fn (array $r): array => ['due_on' => self::YEAR . '-' . $r[0], 'amount' => $r[1]], $installments));
        $this->items->post($this->supplierId, $id, $this->userId);
        return $id;
    }

    /** @return array<string,mixed> */
    private function check(string $key, string $to): array
    {
        $checks = $this->closing->monthlyCheck($this->supplierId, $this->periodId, self::YEAR . '-01-01', self::YEAR . '-' . $to)['checks'];
        foreach ($checks as $check) {
            if ($check['key'] === $key) return $check;
        }
        self::fail('Kontrola ' . $key . ' chybí.');
    }
}
