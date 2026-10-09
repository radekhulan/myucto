<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use MyInvoice\Action\Accounting\Reports\SmallAssetReportAction;
use MyInvoice\Action\Accounting\SmallAssetAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceReceiver;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Drobný majetek v daňové evidenci jako samotná evidence věcí: karta vznikne z přijaté
 * faktury, jde ji spravovat a dát do soupisu k inventuře. Nic se neúčtuje a výdaj nese
 * peněžní deník při úhradě, proto rozpis účtu 501 zůstává jen podvojnému účetnictví.
 */
#[Group('integration')]
final class SmallAssetTaxEvidenceTest extends CashJournalTestCase
{
    public function testCardFromReceivedInvoiceIsManagedAndInventoried(): void
    {
        $id = $this->purchaseInvoice($this->supplierId, ['without' => 25000.0, 'status' => 'draft', 'issue_date' => self::YEAR . '-03-15']);
        $vatRateId = (int) $this->db->pdo()->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $this->db->pdo()->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                 total_without_vat, total_vat, total_with_vat, order_index, expense_kind)
             VALUES (?, 'Notebook pro účetní', 1, 25000, ?, 0, 25000, 0, 25000, 0, 'small_asset')"
        )->execute([$id, $vatRateId]);

        $this->container->get(PurchaseInvoiceReceiver::class)->receiveDraft($this->supplierId, $id, $this->userId);

        $list = $this->call(SmallAssetAction::class, 'list', '/api/accounting/small-assets', []);
        self::assertSame(200, $list->getStatusCode());
        $body = json_decode((string) $list->getBody(), true);
        $cards = $body['data']['items'] ?? $body['items'] ?? [];
        self::assertCount(1, $cards);
        self::assertSame(['Notebook pro účetní', 25000.0], [$cards[0]['name'], (float) $cards[0]['price']]);
        self::assertSame(0, (int) $this->db->pdo()->query("SELECT COUNT(*) FROM journal_entries WHERE supplier_id = {$this->supplierId}")->fetchColumn());

        $inventory = $this->call(SmallAssetReportAction::class, 'inventory', '/api/accounting/reports/small-assets/inventory', ['as_of' => self::YEAR . '-12-31']);
        self::assertSame(200, $inventory->getStatusCode());
        self::assertStringContainsString('Notebook pro účetní', (string) $inventory->getBody());

        $breakdown = $this->call(SmallAssetReportAction::class, 'expenseBreakdown', '/api/accounting/reports/small-assets/expense-breakdown',
            ['from' => self::YEAR . '-01-01', 'to' => self::YEAR . '-12-31']);
        self::assertSame(403, $breakdown->getStatusCode(), 'Rozpis účtu 501 daňová evidence nemá.');
    }

    /** @param array<string,string> $query */
    private function call(string $action, string $method, string $path, array $query): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path)
            ->withQueryParams($query)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        return $this->container->get($action)->{$method}($request, (new ResponseFactory())->createResponse());
    }
}
