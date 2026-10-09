<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use MyInvoice\Action\Accounting\ExpenseClassificationRuleAction;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ExpenseClassificationRuleRepository;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceReceiver;
use MyInvoice\Service\TaxEvidence\TaxEvidenceExpenseRules;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Pravidla klasifikace výdajů v daňové evidenci: při přijetí dokladu doplní druh výdaje
 * a nastaví daňovou uznatelnost dokladu, kterou peněžní deník čte. Uznatelnost se mění jen
 * tehdy, když automatické pravidlo zachytí všechny položky dokladu a pravidla se shodují.
 */
#[Group('integration')]
final class ExpenseRulesTaxEvidenceTest extends CashJournalTestCase
{
    private int $ruleId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ruleId = $this->container->get(ExpenseClassificationRuleRepository::class)->insert($this->supplierId, [
            'name' => 'Smluvní pokuty', 'vendor_client_id' => $this->vendorId, 'description_contains' => 'pokuta',
            'expense_kind' => 'service', 'tax_deductible' => false, 'application_mode' => 'auto', 'priority' => 10,
        ], $this->userId);
    }

    public function testRuleMarksWholeInvoiceNonDeductibleOnReceive(): void
    {
        $id = $this->draftWithItems(['Smluvní pokuta za prodlení']);

        self::assertTrue($this->container->get(PurchaseInvoiceReceiver::class)->receiveDraft($this->supplierId, $id, $this->userId));

        self::assertSame(0, $this->headerDeductible($id));
        $item = $this->items($id)[0];
        self::assertSame(['service', $this->ruleId, 'rule'], [$item['expense_kind'], (int) $item['expense_rule_id'], $item['expense_classification_source']]);

        $journal = $this->fullYear($this->supplierId, false);
        self::assertSame(0.0, (float) ($journal['totals']['vydaj_danovy'] ?? 0));
    }

    public function testPartlyMatchedInvoiceKeepsDeductibility(): void
    {
        $id = $this->draftWithItems(['Smluvní pokuta', 'Oprava vozidla']);

        $this->container->get(PurchaseInvoiceReceiver::class)->receiveDraft($this->supplierId, $id, $this->userId);

        self::assertSame(1, $this->headerDeductible($id), 'Uznatelnost je vlastnost celého dokladu, rozdělit ji smí jen uživatel.');
    }

    public function testDoubleEntryIgnoresDeductibilityRule(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplierId]);
        $id = $this->draftWithItems(['Smluvní pokuta']);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'received' WHERE id = ?")->execute([$id]);

        $result = $this->container->get(TaxEvidenceExpenseRules::class)->applyOnReceive($this->supplierId, $id, $this->userId);

        self::assertFalse($result['applied']);
        self::assertSame(1, $this->headerDeductible($id));
    }

    public function testTaxEvidenceRuleCannotTargetAccount(): void
    {
        $response = $this->callAction('create', [
            'name' => 'Nájem', 'description_contains' => 'nájem', 'expense_kind' => 'service', 'target_account_code' => '518',
        ]);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('target_account_tax_evidence', (string) $response->getBody());

        $ok = $this->callAction('create', [
            'name' => 'Nájem', 'description_contains' => 'nájem', 'expense_kind' => 'service', 'tax_deductible' => true,
        ]);
        self::assertSame(201, $ok->getStatusCode());
        self::assertStringContainsString('"tax_deductible":true', (string) $ok->getBody());
    }

    /** @param list<string> $descriptions */
    private function draftWithItems(array $descriptions): int
    {
        $total = 1000.0 * count($descriptions);
        $id = $this->purchaseInvoice($this->supplierId, ['without' => $total, 'status' => 'draft']);
        $vatRateId = (int) $this->db->pdo()->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                 total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, ?, 1, 1000, ?, 0, 1000, 0, 1000, ?)'
        );
        foreach ($descriptions as $i => $description) {
            $stmt->execute([$id, $description, $vatRateId, $i]);
        }
        return $id;
    }

    private function headerDeductible(int $id): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT tax_deductible FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    private function items(int $id): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT expense_kind, expense_rule_id, expense_classification_source FROM purchase_invoice_items
              WHERE purchase_invoice_id = ? ORDER BY order_index'
        );
        $stmt->execute([$id]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @param array<string,mixed> $body */
    private function callAction(string $method, array $body): \Psr\Http\Message\ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/accounting/expense-rules')
            ->withParsedBody($body)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        return $this->container->get(ExpenseClassificationRuleAction::class)->{$method}($request, (new ResponseFactory())->createResponse());
    }
}
