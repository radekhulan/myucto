<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Automation;

use MyInvoice\Repository\ExpenseClassificationRuleRepository;
use MyInvoice\Service\Automation\AutomationRecommendationService;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class AutomationRecommendationServiceTest extends BankPostingTestCase
{
    private AutomationRecommendationService $recommendations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recommendations = $this->container->get(AutomationRecommendationService::class);
    }

    public function testSnapshotGroupsActionableExpenseAndBankRulesWithoutWriting(): void
    {
        $vendor = $this->client('__TEST Opakovaný dodavatel');
        $firstPurchase = $this->purchaseWithItem('__TEST-REC-EXP-1', $vendor, 'Kancelářský papír A4');
        $secondPurchase = $this->purchaseWithItem('__TEST-REC-EXP-2', $vendor, 'Kancelářský papír A4');
        $this->postPredpis('purchase_invoice', $firstPurchase, '501', '321', 121.0);
        $this->postPredpis('purchase_invoice', $secondPurchase, '501', '321', 121.0);
        $this->markPurchaseBooked($firstPurchase);
        $this->markPurchaseBooked($secondPurchase);

        $statementId = $this->statement();
        $firstTransaction = $this->transaction($statementId, -740.0, [
            'counterparty_account' => '3000000004',
            'counterparty_bank' => '0100',
            'counterparty_name' => '__TEST Opakovaná služba',
            'description' => '__TEST pravidelná služba leden',
        ]);
        $secondTransaction = $this->transaction($statementId, -760.0, [
            'counterparty_account' => '3000000004',
            'counterparty_bank' => '0100',
            'counterparty_name' => '__TEST Opakovaná služba',
            'description' => '__TEST pravidelná služba únor',
        ]);
        $this->postPredpis('bank', $firstTransaction, '518', '221', 740.0);
        $this->postPredpis('bank', $secondTransaction, '518', '221', 760.0);

        $before = $this->financialCounts();
        $snapshot = $this->recommendations->snapshotForSupplier($this->supplierId);
        self::assertSame($before, $this->financialCounts(), 'Generátor doporučení nesmí zapisovat účetní data ani pravidla.');

        $expense = $this->onlyItemOfType($snapshot['items'], 'classify_purchase');
        self::assertStringStartsWith('expense_rule:', $expense['id']);
        self::assertSame('create_expense_rule', $expense['action']);
        self::assertSame(2, $expense['occurrence_count']);
        self::assertSame($vendor, $expense['vendor_id']);
        self::assertContains($expense['document_id'], [$firstPurchase, $secondPurchase]);
        self::assertSame('suggest', $expense['rule_payload']['application_mode']);
        self::assertSame('material', $expense['rule_payload']['expense_kind']);
        self::assertNotSame('', trim((string) $expense['rule_payload']['description_contains']));
        self::assertCount(2, $expense['samples']);

        $bank = $this->onlyItemOfType($snapshot['items'], 'bank_rule');
        self::assertStringStartsWith('bank_rule:', $bank['id']);
        self::assertSame('create_bank_rule', $bank['action']);
        self::assertSame(2, $bank['occurrence_count']);
        self::assertSame('suggest', $bank['rule_payload']['mode']);
        self::assertArrayNotHasKey('tx_ids', $bank['rule_payload']);
        self::assertArrayNotHasKey('own_transfer', $bank['rule_payload']);
        self::assertSame($statementId, $bank['statement_id']);
        self::assertContains($bank['transaction_id'], [$firstTransaction, $secondTransaction]);
        self::assertSame($statementId, $bank['samples'][0]['statement_id']);
    }

    public function testExistingSuggestionRuleIsReviewedWithoutCreatingDuplicatesOrUnsafeOpportunities(): void
    {
        $vendor = $this->client('__TEST Pokrytý dodavatel');
        $first = $this->purchaseWithItem('__TEST-REC-COVERED-1', $vendor, 'Kancelářský papír A4');
        $second = $this->purchaseWithItem('__TEST-REC-COVERED-2', $vendor, 'Kancelářský papír A4');

        $ruleId = $this->container->get(ExpenseClassificationRuleRepository::class)->insert($this->supplierId, [
            'name' => '__TEST Pokrývající pravidlo',
            'vendor_client_id' => $vendor,
            'description_contains' => 'Kancelářský papír A4',
            'expense_kind' => 'material',
            'target_account_code' => '501',
            'application_mode' => 'suggest',
            'priority' => 100,
            'is_active' => true,
        ], $this->userId);

        $allocated = $this->purchaseWithItem('__TEST-REC-ALLOC', $vendor, 'Toner do tiskárny');
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoice_vat_allocations
                (supplier_id,purchase_invoice_id,description,vat_rate,base_amount,vat_amount,total_amount,account_code)
             VALUES (?, ?, "__TEST alokace", 21, 100, 21, 121, "518")'
        )->execute([$this->supplierId, $allocated]);
        $this->purchaseWithItem('__TEST-REC-ALLOC-PAIR', $vendor, 'Toner do tiskárny');

        $items = $this->recommendations->snapshotForSupplier($this->supplierId)['items'];
        $review = $this->onlyItemOfType($items, 'classify_purchase');
        self::assertSame('edit_expense_rule', $review['action']);
        self::assertSame($ruleId, $review['rule_id']);
        self::assertSame(2, $review['occurrence_count']);
        self::assertSame('suggest', $review['rule_payload']['application_mode']);
        $action = $this->container->get(\MyInvoice\Action\Accounting\ExpenseClassificationRuleAction::class);
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/api/accounting/expense-rules/' . $ruleId)
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId);
        self::assertSame(200, $action->get($request, new \Slim\Psr7\Response(), ['id' => $ruleId])->getStatusCode());
        $this->container->get(ExpenseClassificationRuleRepository::class)->update($this->supplierId, $ruleId, ['application_mode' => 'auto']);
        $fresh = json_decode((string) $action->get($request, new \Slim\Psr7\Response(), ['id' => $ruleId])->getBody(), true);
        self::assertSame('auto', $fresh['rule']['application_mode']);
        self::assertSame(404, $action->get($request, new \Slim\Psr7\Response(), ['id' => 0])->getStatusCode());
        self::assertSame([], array_values(array_filter($this->recommendations->snapshotForSupplier($this->supplierId)['items'], static fn (array $item): bool => $item['type'] === 'classify_purchase')));
        self::assertNotSame($first, $second);
    }

    /** @return array{journal:int,journal_lines:int,expense_rules:int,bank_rules:int} */
    private function financialCounts(): array
    {
        return [
            'journal' => (int) $this->db->pdo()->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn(),
            'journal_lines' => (int) $this->db->pdo()->query('SELECT COUNT(*) FROM journal_entry_lines')->fetchColumn(),
            'expense_rules' => (int) $this->db->pdo()->query('SELECT COUNT(*) FROM expense_classification_rules')->fetchColumn(),
            'bank_rules' => (int) $this->db->pdo()->query('SELECT COUNT(*) FROM bank_posting_rules')->fetchColumn(),
        ];
    }

    /** @param list<array<string,mixed>> $items @return array<string,mixed> */
    private function onlyItemOfType(array $items, string $type): array
    {
        $matching = array_values(array_filter($items, static fn (array $item): bool => $item['type'] === $type));
        self::assertCount(1, $matching);
        return $matching[0];
    }

    private function purchaseWithItem(string $number, int $vendorId, string $description): int
    {
        $purchaseId = $this->purchaseInvoice($number, $vendorId, 121.0);
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices SET total_without_vat=100,total_vat=21,total_with_vat=121 WHERE id=?'
        )->execute([$purchaseId]);
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id,description,quantity,unit,unit_price_without_vat,vat_rate_id,vat_rate_snapshot,
                 total_without_vat,total_vat,total_with_vat,order_index,vat_classification_code)
             VALUES (?, ?, 1, "ks", 100, ?, 21, 100, 21, 121, 0, "40")'
        )->execute([$purchaseId, $description, $this->vatRateId()]);
        return $purchaseId;
    }

    private function vatRateId(): int
    {
        return (int) $this->db->pdo()->query('SELECT id FROM vat_rates WHERE rate_percent=21 ORDER BY id LIMIT 1')->fetchColumn();
    }

    private function markPurchaseBooked(int $purchaseId): void
    {
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET status="booked",booked_at=NOW() WHERE id=?')->execute([$purchaseId]);
    }
}
