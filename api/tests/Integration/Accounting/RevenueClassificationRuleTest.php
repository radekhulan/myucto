<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\RevenueClassificationRuleAction;
use MyInvoice\Repository\RevenueClassificationRuleRepository;
use MyInvoice\Service\Import\InvoiceImportService;
use MyInvoice\Service\Import\IsdocParser;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pravidla výnosů (migrace 2217): účet položky vydané faktury podle kódu položky,
 * textu nebo sazby DPH. Import účet uloží na položku, zaúčtování (a tím Doúčtovat
 * doklady) ho doplní položkám bez účtu. Účet položky i produktu má přednost.
 */
#[Group('integration')]
final class RevenueClassificationRuleTest extends BankPostingTestCase
{
    private const ISDOC = __DIR__ . '/../../Fixtures/Shoptet/isdoc-faktura.isdoc';

    private RevenueClassificationRuleRepository $repo;
    private int $vatRateId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = $this->container->get(RevenueClassificationRuleRepository::class);
        $this->vatRateId = (int) ($this->db->pdo()->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->vatRateId === 0) {
            self::markTestSkipped('Chybí vat_rates v DB.');
        }
        // Pravidla firmy z pracovní DB by zkreslila výsledek, test pracuje jen se svými.
        $this->db->pdo()->prepare('DELETE FROM revenue_classification_rules WHERE supplier_id = ?')->execute([$this->supplierId]);
    }

    public function testPostingSplitsUnassignedItemsByRules(): void
    {
        $this->revenueRule(['name' => 'Kód zboží', 'item_code' => 'tri-m', 'target_account_code' => '604', 'priority' => 10]);
        $this->revenueRule(['name' => 'Snížená sazba', 'vat_rate' => 12, 'target_account_code' => '601']);
        $this->revenueRule(['name' => 'Přefakturace dopravy', 'description_contains' => 'doprava', 'target_account_code' => '378']);
        $product = $this->product('RR-1', '641');

        $invoiceId = $this->saleInvoice('RR-POST-1', $this->client('Odběratel pravidel'), 0.0);
        $this->item($invoiceId, 'Tričko – M', 600.00, 21.0, 'TRI-M');
        $this->item($invoiceId, 'Hrnek', 200.00, 12.0);
        $this->item($invoiceId, 'Doprava: Výdejní místo', 65.29, 21.0);
        $this->item($invoiceId, 'Platba: Dobírka', 24.79, 21.0);
        $this->item($invoiceId, 'Tričko s vlastním účtem', 100.00, 21.0, 'TRI-M', '602');
        $this->item($invoiceId, 'Tričko z karty', 50.00, 21.0, 'TRI-M', null, $product);
        $this->db->pdo()->prepare(
            'UPDATE invoices i JOIN (SELECT invoice_id, SUM(total_without_vat) b, SUM(total_vat) v, SUM(total_with_vat) w
                                     FROM invoice_items WHERE invoice_id = ? GROUP BY invoice_id) s ON s.invoice_id = i.id
                SET i.total_without_vat = s.b, i.total_vat = s.v, i.total_with_vat = s.w'
        )->execute([$invoiceId]);

        $credit = [];
        foreach ($this->posting->buildFromInvoice($this->supplierId, $invoiceId) as $line) {
            if ($line['side'] === 'credit' && !str_starts_with((string) $line['account_code'], '343')) {
                $credit[(string) $line['account_code']] = round(($credit[(string) $line['account_code']] ?? 0.0) + (float) $line['amount'], 2);
            }
        }
        ksort($credit);

        self::assertSame(['378' => 65.29, '601' => 200.00, '602' => 124.79, '604' => 600.00, '641' => 50.00], $credit,
            'Kód (bez ohledu na velikost písmen), sazba i text určí účet; účet položky a produktu mají přednost.');
    }

    public function testImportStoresItemCodeAndRuleAccount(): void
    {
        $ruleId = $this->revenueRule(['name' => 'Trička', 'item_code' => 'TRI-M', 'target_account_code' => '604']);
        $this->revenueRule(['name' => 'Doprava', 'description_contains' => 'doprava', 'vat_rate' => 21, 'target_account_code' => '602']);

        $xml = str_replace(
            '<Item><Description>Tričko – M</Description></Item>',
            '<Item><Description>Tričko – M</Description><SellersItemIdentification><ID>TRI-M</ID></SellersItemIdentification></Item>',
            (string) file_get_contents(self::ISDOC),
        );
        $parsed = (new IsdocParser())->parse($xml)['invoices'][0];
        self::assertSame('TRI-M', $parsed['items'][0]['seller_item_code']);
        self::assertNull($parsed['items'][1]['seller_item_code']);

        $parsed['varsymbol'] = 'RR-IMP-' . bin2hex(random_bytes(3));
        // Zakázka z objednávky se zakládá ve vlastní transakci, test běží v rollbackované.
        $parsed['project_number'] = null;
        $service = $this->container->get(InvoiceImportService::class);
        $result = (new \ReflectionMethod($service, 'processOne'))->invoke($service, $parsed, $this->supplierId, $this->userId, []);
        self::assertSame('created', $result['status'], json_encode($result, JSON_UNESCAPED_UNICODE) ?: '');

        $stmt = $this->db->pdo()->prepare(
            'SELECT description, seller_item_code, revenue_account_code FROM invoice_items WHERE invoice_id = ? ORDER BY order_index'
        );
        $stmt->execute([(int) $result['invoice_id']]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame(['TRI-M', '604'], [$rows[0]['seller_item_code'], $rows[0]['revenue_account_code']]);
        self::assertNull($rows[1]['revenue_account_code'], 'Hrnek žádné pravidlo nemá.');
        self::assertSame('602', $rows[2]['revenue_account_code'], 'Doprava se sazbou 21 %.');
        self::assertSame(1, $this->repo->find($this->supplierId, $ruleId)['hit_count']);
    }

    public function testApiValidatesCriteriaAndTargetAccount(): void
    {
        $action = $this->container->get(RevenueClassificationRuleAction::class);

        $missing = $this->callAction($action, 'create', 'POST', 'accountant', ['name' => 'Bez kritéria', 'target_account_code' => '604']);
        self::assertSame(422, $missing['status']);
        self::assertSame('rule_criteria_missing', $missing['body']['error']['code'] ?? null);

        $settlement = $this->callAction($action, 'create', 'POST', 'accountant', ['name' => 'Saldokonto', 'vat_rate' => 21, 'target_account_code' => '311']);
        self::assertSame(422, $settlement['status']);
        self::assertSame('invalid_target_account', $settlement['body']['error']['code'] ?? null);

        $ok = $this->callAction($action, 'create', 'POST', 'accountant', ['name' => 'Přefakturace', 'description_contains' => 'přefakturace', 'target_account_code' => '378']);
        self::assertSame(201, $ok['status']);
        $id = (int) $ok['body']['rule']['id'];

        $cleared = $this->callAction($action, 'update', 'PUT', 'accountant', ['description_contains' => ''], ['id' => (string) $id]);
        self::assertSame(422, $cleared['status'], 'Vynulování jediného kritéria je validační chyba, ne pád na CHECKu.');

        $foreign = $this->revenueRule(['name' => 'Cizí', 'vat_rate' => 21, 'target_account_code' => '604'], $this->otherSupplierId());
        $hidden = $this->callAction($action, 'update', 'PUT', 'accountant', ['name' => 'Přepis'], ['id' => (string) $foreign]);
        self::assertSame(404, $hidden['status']);
        $denied = $this->callAction($action, 'create', 'POST', 'client', ['name' => 'X', 'vat_rate' => 21, 'target_account_code' => '604']);
        self::assertSame(403, $denied['status']);
    }

    /** @param array<string,mixed> $data */
    private function revenueRule(array $data, ?int $supplierId = null): int
    {
        return $this->repo->insert($supplierId ?? $this->supplierId, $data + ['name' => 'Pravidlo'], $this->userId);
    }

    private function product(string $sku, string $revenueAccount): int
    {
        $this->db->pdo()->prepare('INSERT INTO stock_items (supplier_id, sku, name, revenue_account_code) VALUES (?, ?, ?, ?)')
            ->execute([$this->supplierId, $sku, 'Produkt ' . $sku, $revenueAccount]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function item(int $invoiceId, string $description, float $net, float $rate, ?string $code = null, ?string $account = null, ?int $stockItemId = null): void
    {
        $vat = round($net * $rate / 100, 2);
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                 total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code,
                 seller_item_code, revenue_account_code, stock_item_id)
             VALUES (?, ?, 1, "ks", ?, ?, ?, ?, ?, ?, 0, "1", ?, ?, ?)'
        )->execute([$invoiceId, $description, $net, $this->vatRateId, $rate, $net, $vat, $net + $vat, $code, $account, $stockItemId]);
    }
}
