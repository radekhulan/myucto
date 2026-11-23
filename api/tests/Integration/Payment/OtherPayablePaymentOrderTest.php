<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payment;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PaymentOrderRepository;
use MyInvoice\Service\Payment\PaymentOrderService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Ostatní závazky v platebních příkazech (migrace 2240): kandidát podle nejbližší
 * otevřené splátky nebo celého zbytku, účet protistrany a VS z položky, stav „v příkazu"
 * odvozený z položky příkazu a izolace firem. Syntetická data, účty 1000000005/0100
 * a 19-1000000005/0100 (projdou mod 11), splatnosti v roce 2099.
 */
#[Group('integration')]
final class OtherPayablePaymentOrderTest extends TestCase
{
    private const TITLE = 'Syntetický závazek B3';

    private Connection $db;
    private PDO $pdo;
    private PaymentOrderService $service;
    private PaymentOrderRepository $orders;
    private int $supplierId = 0;
    private int $foreignSupplierId = 0;
    private int $currencyId = 0;
    private int $installmentItem = 0;
    /** @var list<int> */
    private array $installments = [];
    private int $wholeItem = 0;
    private int $noAccountItem = 0;
    private int $foreignItem = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje, test vyžaduje DB.');
        }
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->service = $container->get(PaymentOrderService::class);
        $this->orders = $container->get(PaymentOrderRepository::class);
        $this->pdo = $this->db->pdo();

        $account = $this->pdo->query(
            "SELECT id, supplier_id FROM currencies
              WHERE code = 'CZK' AND is_active = 1 AND account_number IS NOT NULL AND account_number <> ''
                AND bank_code IS NOT NULL AND bank_code <> ''
              ORDER BY id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($account, 'Testovací DB potřebuje CZK měnu s bankovním účtem (ci-seed).');
        $this->supplierId = (int) $account['supplier_id'];
        $this->currencyId = (int) $account['id'];
        $foreign = $this->pdo->prepare('SELECT id FROM supplier WHERE id <> ? ORDER BY id LIMIT 1');
        $foreign->execute([$this->supplierId]);
        $this->foreignSupplierId = (int) $foreign->fetchColumn();
        self::assertGreaterThan(0, $this->foreignSupplierId, 'Izolace firem potřebuje druhou firmu (ci-seed).');

        $this->cleanup();

        $this->installmentItem = $this->item($this->supplierId, 3000, '2099-03-31', '2099001', '1000000005', '0100');
        foreach ([['2099-01-31', 1000], ['2099-02-28', 1000], ['2099-03-31', 1000]] as $position => [$due, $amount]) {
            $this->pdo->prepare('INSERT INTO other_item_installments (supplier_id, other_item_id, position, due_on, amount)
                VALUES (?, ?, ?, ?, ?)')->execute([$this->supplierId, $this->installmentItem, $position + 1, $due, $amount]);
            $this->installments[] = (int) $this->pdo->lastInsertId();
        }
        $this->wholeItem = $this->item($this->supplierId, 1500, '2099-05-15', 'VS-2099-002', '19-1000000005', '0100', 500);
        $this->noAccountItem = $this->item($this->supplierId, 700, '2099-06-30', '2099003', null, null);
        $this->item($this->supplierId, 900, '2099-06-30', '2099004', '1000000005', '0100', 0, 'EUR');
        $this->item($this->supplierId, 900, '2099-06-30', '2099005', '1000000005', '0100', 0, 'CZK', 'draft');
        $this->item($this->supplierId, 900, '2099-06-30', '2099006', '1000000005', '0100', 0, 'CZK', 'confirmed', 'receivable');
        $this->foreignItem = $this->item($this->foreignSupplierId, 800, '2099-06-30', '2099007', '1000000005', '0100');
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    public function testCandidatesOfferNextInstallmentOrWholeRemainderWithAccountAndVariableSymbol(): void
    {
        $rows = $this->candidates();

        self::assertSame(['other:' . $this->installmentItem . ':' . $this->installments[0], 'other:' . $this->wholeItem,
            'other:' . $this->noAccountItem], array_keys($rows), 'Jen potvrzené korunové závazky vlastní firmy, splátka jen nejbližší.');

        $installment = $rows['other:' . $this->installmentItem . ':' . $this->installments[0]];
        self::assertSame(1000.0, $installment['amount']);
        self::assertSame([1, 3], [$installment['installment_position'], $installment['installment_count']]);
        self::assertSame('2099-01-31', $installment['due_date']);
        self::assertSame(['1000000005', '0100', null], [$installment['account_number'], $installment['bank_code'], $installment['iban']]);
        self::assertSame('2099001', $installment['variable_symbol']);
        self::assertTrue($installment['payable']);
        self::assertTrue($installment['abo_eligible']);
        self::assertSame(['payable', 'loan'], [$installment['side'], $installment['kind']]);

        $whole = $rows['other:' . $this->wholeItem];
        self::assertSame(1000.0, $whole['amount'], 'Bez kalendáře se platí celý zbytek (1500 - 500 vyrovnáno mimo).');
        self::assertNull($whole['installment_id']);
        self::assertSame('2099-05-15', $whole['due_date']);
        self::assertSame('2099002', $whole['variable_symbol'], 'VS do příkazu jen z číslic.');

        $noAccount = $rows['other:' . $this->noAccountItem];
        self::assertFalse($noAccount['has_account']);
        self::assertFalse($noAccount['payable']);
        self::assertSame('no_account', $noAccount['reason']);

        self::assertSame([], $this->service->candidates($this->supplierId, 'EUR')['other_candidates']);
    }

    public function testOrderExportsInstallmentAndRemainderAndMarksThemOrderedUntilDeleted(): void
    {
        $created = $this->service->create($this->supplierId, [
            'other_items'       => [
                ['id' => $this->installmentItem, 'installment_id' => $this->installments[0]],
                $this->wholeItem,
                $this->noAccountItem,
                $this->foreignItem,
            ],
            'payer_currency_id' => $this->currencyId,
            'payment_date'      => date('Y-m-d'),
            'mark_paid'         => true,
        ], null);
        $orderId = $created['order_id'];

        self::assertSame([
            ['id' => $this->noAccountItem, 'reason' => 'no_account', 'document' => 'other_item'],
            ['id' => $this->foreignItem, 'reason' => 'nothing_to_pay', 'document' => 'other_item'],
        ], $created['skipped'], 'Závazek jiné firmy se do příkazu nedostane.');
        self::assertSame(2000.0, $created['view']['total_amount']);
        $items = $created['view']['items'];
        self::assertSame([$this->installmentItem, $this->installments[0]], [$items[0]['other_item_id'], $items[0]['other_item_installment_id']]);
        self::assertSame($this->wholeItem, $items[1]['other_item_id']);
        self::assertArrayNotHasKey('other_item_installment_id', $items[1]);
        self::assertNull($items[0]['purchase_invoice_id']);
        self::assertStringContainsString('1/3', (string) $items[0]['message']);

        $abo = (string) $this->service->download($orderId, $this->supplierId, 'abo')['bytes'];
        self::assertStringContainsString('000000-1000000005 000000100000 2099001 0100', $abo);
        self::assertStringContainsString('000019-1000000005 000000100000 2099002 0100', $abo);
        $csv = (string) $this->service->download($orderId, $this->supplierId, 'csv')['bytes'];
        self::assertStringContainsString('2099001', $csv);
        self::assertStringContainsString('19-1000000005', $csv);
        self::assertTrue($this->orders->allItemsStillPayable($orderId, $this->supplierId, 'CZK'));

        $status = $this->pdo->prepare('SELECT status FROM other_items WHERE id = ?');
        $status->execute([$this->wholeItem]);
        self::assertSame('confirmed', $status->fetchColumn(), 'Uhrazení zapisuje až párování výpisu, mark_paid se neuplatní.');

        $rows = $this->candidates();
        $ordered = $rows['other:' . $this->installmentItem . ':' . $this->installments[0]];
        self::assertNotNull($ordered['ordered_at']);
        self::assertFalse($ordered['payable']);
        self::assertSame('already_ordered', $ordered['reason']);
        $next = $rows['other:' . $this->installmentItem . ':' . $this->installments[1]] ?? null;
        self::assertNotNull($next, 'Po zařazení splátky 1 se nabízí splátka 2.');
        self::assertTrue($next['payable']);
        self::assertNotNull($rows['other:' . $this->wholeItem]['ordered_at']);

        try {
            $this->service->create($this->supplierId, [
                'other_items'       => [['id' => $this->installmentItem, 'installment_id' => $this->installments[0]], $this->wholeItem],
                'payer_currency_id' => $this->currencyId,
            ], null);
            self::fail('Zařazená splátka ani zbytek se podruhé do příkazu nedostanou.');
        } catch (\InvalidArgumentException) {
        }
        $again = $this->service->create($this->supplierId, [
            'other_items'       => [$this->installmentItem, $this->wholeItem],
            'payer_currency_id' => $this->currencyId,
        ], null);
        self::assertSame([['id' => $this->wholeItem, 'reason' => 'already_ordered', 'document' => 'other_item']], $again['skipped']);
        self::assertSame($this->installments[1], $again['view']['items'][0]['other_item_installment_id'],
            'Samotné ID závazku zařadí nejbližší splátku, která ještě v příkazu není.');
        self::assertSame('deleted', $this->service->delete($again['order_id'], $this->supplierId));

        self::assertSame('deleted', $this->service->delete($orderId, $this->supplierId));
        $rows = $this->candidates();
        self::assertNull($rows['other:' . $this->installmentItem . ':' . $this->installments[0]]['ordered_at']);
        self::assertTrue($rows['other:' . $this->installmentItem . ':' . $this->installments[0]]['payable']);
        self::assertArrayNotHasKey('other:' . $this->installmentItem . ':' . $this->installments[1], $rows);
        self::assertNull($rows['other:' . $this->wholeItem]['ordered_at']);
    }

    public function testArchivedOrderReleasesItemAndSettledInstallmentBlocksBankSubmission(): void
    {
        $created = $this->service->create($this->supplierId, [
            'other_items'       => [['id' => $this->installmentItem, 'installment_id' => $this->installments[0]]],
            'payer_currency_id' => $this->currencyId,
        ], null);
        $orderId = $created['order_id'];
        self::assertTrue($this->orders->allItemsStillPayable($orderId, $this->supplierId, 'CZK'));
        self::assertFalse($this->orders->allItemsStillPayable($orderId, $this->foreignSupplierId, 'CZK'));

        // Splátku 1 mezitím vyrovnala jiná cesta: zbytek položky (2000) by stačil, splátka už ne.
        $this->pdo->prepare('UPDATE other_items SET settled_outside_amount = 1000 WHERE id = ?')->execute([$this->installmentItem]);
        self::assertFalse($this->orders->allItemsStillPayable($orderId, $this->supplierId, 'CZK'));
        self::assertSame('other:' . $this->installmentItem . ':' . $this->installments[1],
            array_keys($this->candidates())[0], 'Vyrovnaná splátka z kandidátů zmizí.');

        $this->pdo->prepare('UPDATE payment_orders SET archived_at = NOW() WHERE id = ?')->execute([$orderId]);
        $this->pdo->prepare('UPDATE other_items SET settled_outside_amount = 0 WHERE id = ?')->execute([$this->installmentItem]);
        $row = $this->candidates()['other:' . $this->installmentItem . ':' . $this->installments[0]];
        self::assertNull($row['ordered_at'], 'Archivovaný (v bance zrušený) příkaz splátku uvolní.');
    }

    /** @return array<string, array<string,mixed>> */
    private function candidates(): array
    {
        $out = [];
        foreach ($this->service->candidates($this->supplierId, 'CZK')['other_candidates'] as $row) {
            if (str_starts_with((string) $row['title'], self::TITLE)) {
                $out[$row['key']] = $row;
            }
        }
        return $out;
    }

    private function item(int $supplierId, float $amount, string $dueOn, ?string $vs, ?string $account, ?string $bank,
        float $settledOutside = 0, string $currency = 'CZK', string $status = 'confirmed', string $side = 'payable'): int
    {
        $this->pdo->prepare(
            "INSERT INTO other_items (supplier_id, side, kind, title, partner_name, issued_on, due_on, currency, amount,
                                      variable_symbol, counterparty_account, counterparty_bank_code, status, settled_outside_amount)
             VALUES (?, ?, 'loan', ?, 'Syntetický věřitel', '2099-01-01', ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([$supplierId, $side, self::TITLE . ' ' . $dueOn, $dueOn, $currency, $amount, $vs, $account, $bank, $status, $settledOutside]);
        return (int) $this->pdo->lastInsertId();
    }

    private function cleanup(): void
    {
        $ids = $this->pdo->prepare('SELECT id FROM other_items WHERE title LIKE ? AND supplier_id IN (?, ?)');
        $ids->execute([self::TITLE . '%', $this->supplierId, $this->foreignSupplierId]);
        $itemIds = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($itemIds === []) {
            return;
        }
        $in = implode(',', array_fill(0, count($itemIds), '?'));
        $orders = $this->pdo->prepare("SELECT DISTINCT payment_order_id FROM payment_order_items WHERE other_item_id IN ({$in})");
        $orders->execute($itemIds);
        foreach (array_map('intval', $orders->fetchAll(PDO::FETCH_COLUMN) ?: []) as $orderId) {
            $this->pdo->prepare('DELETE FROM payment_order_items WHERE payment_order_id = ?')->execute([$orderId]);
            $this->pdo->prepare('DELETE FROM payment_orders WHERE id = ?')->execute([$orderId]);
        }
        $this->pdo->prepare("DELETE FROM other_item_installments WHERE other_item_id IN ({$in})")->execute($itemIds);
        $this->pdo->prepare("DELETE FROM other_items WHERE id IN ({$in})")->execute($itemIds);
    }
}
