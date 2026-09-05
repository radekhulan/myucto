<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Automation;

use MyInvoice\Action\Automation\AutomationRecommendationAction;
use MyInvoice\Service\Automation\AutomationRecommendationCache;
use MyInvoice\Service\Automation\AutomationRecommendationService;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class AutomationRecommendationPostingTest extends BankPostingTestCase
{
    public function testOffersLivePostingOnlyForOpenValidUnpostedDocuments(): void
    {
        [$sale, $purchase] = $this->documents();
        $items = $this->postingItems();
        self::assertCount(2, $items);
        self::assertEqualsCanonicalizing([$sale, $purchase], array_column($items, 'document_id'));
        foreach ($items as $item) {
            self::assertSame('post_document', $item['action']);
            self::assertNotEmpty($item['lines']);
            self::assertFalse($item['period_closed']);
            self::assertFalse($item['booked']);
        }
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status='closed' WHERE id=? AND supplier_id=?")
            ->execute([$this->periodId, $this->supplierId]);
        self::assertSame([], $this->postingItems());
    }

    public function testSoftLockAndMissingPreviewNeverBecomePostingTasks(): void
    {
        [$sale, $purchase] = $this->documents();
        self::assertCount(2, $this->postingItems());
        $this->db->pdo()->prepare('INSERT INTO accounting_supplier_settings (supplier_id,locked_until) VALUES (?,?) ON DUPLICATE KEY UPDATE locked_until=VALUES(locked_until)')
            ->execute([$this->supplierId, self::YEAR . '-12-31']);
        self::assertSame([], $this->postingItems());
        $this->db->pdo()->prepare('UPDATE accounting_supplier_settings SET locked_until=NULL WHERE supplier_id=?')->execute([$this->supplierId]);
        $this->db->pdo()->prepare('DELETE FROM invoice_items WHERE invoice_id=?')->execute([$sale]);
        $this->db->pdo()->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id=?')->execute([$purchase]);
        self::assertSame([], $this->postingItems());
    }

    public function testCompanyMembershipAndInvalidQueriesRemainGuarded(): void
    {
        $this->documents();
        $generator = $this->container->get(AutomationRecommendationService::class);
        self::assertSame([], $generator->recommendations(0, false, ['suppliers' => [$this->supplierId]])['items']);
        $action = new AutomationRecommendationAction($this->container->get(AutomationRecommendationCache::class));
        foreach (['type=unknown', 'suppliers=abc', 'from=2099-02-30', 'page=-1'] as $query) {
            $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/automation/recommendations?' . $query);
            self::assertSame(422, $action->recommendations($request, new Response())->getStatusCode());
        }
    }

    private function postingItems(): array
    {
        return array_values(array_filter(
            $this->container->get(AutomationRecommendationService::class)->snapshotForSupplier($this->supplierId)['items'],
            static fn (array $item): bool => in_array($item['type'], ['post_invoice', 'post_purchase'], true)
                && str_starts_with((string) $item['document_no'], '__TEST-ACT-'),
        ));
    }

    private function documents(): array
    {
        $client = $this->client('__TEST Actionable company');
        $sale = $this->saleInvoice('__TEST-ACT-SALE', $client, 121.0);
        $purchase = $this->purchaseInvoice('__TEST-ACT-PURCH', $client, 121.0);
        $vat = (int) $this->db->pdo()->query('SELECT id FROM vat_rates WHERE rate_percent=21 ORDER BY id LIMIT 1')->fetchColumn();
        foreach ([['invoices', 'invoice_items', 'invoice_id', $sale, '1'], ['purchase_invoices', 'purchase_invoice_items', 'purchase_invoice_id', $purchase, '40']] as [$table, $items, $column, $id, $classification]) {
            $this->db->pdo()->prepare("UPDATE {$table} SET total_without_vat=100,total_vat=21,total_with_vat=121 WHERE id=? AND supplier_id=?")
                ->execute([$id, $this->supplierId]);
            $this->db->pdo()->prepare("INSERT INTO {$items} ({$column},description,quantity,unit,unit_price_without_vat,vat_rate_id,vat_rate_snapshot,total_without_vat,total_vat,total_with_vat,order_index,vat_classification_code) VALUES (?, '__TEST konzultace', 1, 'ks', 100, ?, 21, 100, 21, 121, 0, ?)")
                ->execute([$id, $vat, $classification]);
        }
        return [$sale, $purchase];
    }
}
