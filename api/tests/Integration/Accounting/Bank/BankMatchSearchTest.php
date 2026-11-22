<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Action\Bank\BankPaymentCandidatesAction;
use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Bank\BankMatchSearch;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class BankMatchSearchTest extends BankPostingTestCase
{
    private function request(array $query = [], string $role = 'admin')
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/api/bank-transactions/payment-candidates')
            ->withQueryParams($query)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role]);
    }

    public function testExplicitSearchFindsOldPurchaseBySupplierAndCzechAmount(): void
    {
        $vendor = $this->client('Syntetický dodavatel hledání');
        $purchase = $this->purchaseInvoice('SYN-SEARCH-OLD', $vendor, 1234.50);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET issue_date = '2098-01-01', due_date = '2098-01-15' WHERE id = ?")->execute([$purchase]);
        $tx = $this->transaction($this->statement(), -500.00);
        $action = $this->container->get(BankStatementAction::class);
        foreach (['Syntetický dodavatel hledání', '1 234,50', 'SYN-SEARCH-OLD'] as $search) {
            $response = $action->matchCandidates($this->request(['search' => $search]), new Response(), ['id' => $tx]);
            self::assertSame(200, $response->getStatusCode());
            $body = json_decode((string) $response->getBody(), true);
            self::assertSame([$purchase], array_column($body['candidates'], 'id'));
            self::assertSame('purchase_invoice', $body['candidates'][0]['type']);
        }
        $response = $action->matchCandidates($this->request(), new Response(), ['id' => $tx]);
        self::assertSame([], json_decode((string) $response->getBody(), true)['candidates']);
    }

    public function testSearchRespectsDirectionDocumentStateAndTenant(): void
    {
        $party = $this->client('Syntetický společný partner');
        $sale = $this->saleInvoice('209987001', $party, 1200);
        $refund = $this->saleInvoice('209987002', $party, -1200, 'credit_note');
        $purchase = $this->purchaseInvoice('SYN-SEARCH-IN', $party, 1200);
        $purchaseRefund = $this->purchaseInvoice('SYN-SEARCH-REF', $party, -1200, 'credit_note');
        $draft = $this->saleInvoice('209987003', $party, 1200, 'invoice', 'draft');
        $foreign = $this->saleInvoice('209987004', $party, 1200);
        $this->db->pdo()->prepare('UPDATE invoices SET supplier_id = ? WHERE id = ?')->execute([$this->otherSupplierId(), $foreign]);
        $search = new BankMatchSearch($this->db);
        // Vydané a přijaté doklady mají vlastní řadu id, v čisté DB se čísla potkají.
        $incoming = self::documentKeys($search->searchDocuments($this->supplierId, 1200, 'CZK', 'Syntetický společný partner', ['invoice', 'purchase_invoice']));
        self::assertEqualsCanonicalizing(["invoice:$sale", "purchase_invoice:$purchaseRefund"], $incoming);
        $outgoing = self::documentKeys($search->searchDocuments($this->supplierId, -1200, 'CZK', '1 200,00', ['invoice', 'purchase_invoice']));
        self::assertEqualsCanonicalizing(["invoice:$refund", "purchase_invoice:$purchase"], $outgoing);
        self::assertNotContains("invoice:$draft", $incoming);
        self::assertNotContains("invoice:$foreign", $incoming);
    }

    /** @return list<string> */
    private static function documentKeys(array $rows): array
    {
        return array_map(static fn (array $row): string => $row['type'] . ':' . $row['id'], $rows);
    }

    public function testPaymentCandidatesExcludeUsedForeignAndWrongDirectionMovements(): void
    {
        $sale = $this->saleInvoice('209987005', $this->client('Syntetický klient výběru'), 1234.50);
        $statement = $this->statement();
        $wanted = $this->transaction($statement, 1234.50, ['counterparty_name' => 'Syntetický plátce']);
        $this->transaction($statement, -1234.50);
        $this->transaction($statement, 1234.50, ['match_status' => 'ignored']);
        $this->transaction($statement, 1234.50, ['match_status' => 'manual', 'matched_invoice_id' => $sale]);
        $this->transaction($this->statement('1000000005', '0100', $this->otherSupplierId()), 1234.50);
        $used = $this->transaction($statement, 1234.50);
        $this->db->pdo()->prepare("INSERT INTO payment_matches (supplier_id, bank_transaction_id, invoice_id, amount, match_type) VALUES (?, ?, ?, 1234.50, 'manual')")->execute([$this->supplierId, $used, $sale]);
        $action = $this->container->get(BankPaymentCandidatesAction::class);
        $response = $action->list($this->request(['invoice_id' => $sale, 'search' => '1 234,50']), new Response());
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame([$wanted], array_column($body['items'], 'id'));
        self::assertSame(1, $body['total']);
        self::assertSame(1234.50, $body['items'][0]['amount']);
    }

    public function testReverseMatchReusesPurchaseAllocationAndPreservesOuterTransaction(): void
    {
        $purchase = $this->purchaseInvoice('SYN-REVERSE-PARTIAL', $this->client('Syntetický dodavatel úhrady'), 1200);
        $tx = $this->transaction($this->statement(), -500);
        $action = $this->container->get(BankPaymentCandidatesAction::class);
        $result = $this->callAction($action, 'match', 'POST', 'admin', ['purchase_invoice_id' => $purchase], ['id' => $tx]);
        self::assertSame(200, $result['status'], json_encode($result['body']));
        self::assertTrue($this->db->pdo()->inTransaction());
        self::assertTrue($result['body']['partial_payment']);
        self::assertSame(700.0, (float) $result['body']['remaining']);
        $query = $this->db->pdo()->prepare('SELECT amount FROM payment_matches WHERE bank_transaction_id = ? AND purchase_invoice_id = ?');
        $query->execute([$tx, $purchase]);
        self::assertSame(500.0, (float) $query->fetchColumn());
        $again = $this->callAction($action, 'match', 'POST', 'admin', ['purchase_invoice_id' => $purchase], ['id' => $tx]);
        self::assertSame(409, $again['status']);
        self::assertTrue($this->db->pdo()->inTransaction());
    }

    public function testReverseMatchAndCandidateListingEnforcePermissions(): void
    {
        $sale = $this->saleInvoice('209987006', $this->client('Syntetický klient oprávnění'), 1200);
        $tx = $this->transaction($this->statement(), 1200);
        $action = $this->container->get(BankPaymentCandidatesAction::class);
        self::assertSame(403, $action->list($this->request(['invoice_id' => $sale], 'client'), new Response())->getStatusCode());
        self::assertSame(403, $this->callAction($action, 'match', 'POST', 'readonly', ['invoice_id' => $sale], ['id' => $tx])['status']);
        $query = $this->db->pdo()->prepare('SELECT match_status FROM bank_transactions WHERE id = ?');
        $query->execute([$tx]);
        self::assertSame('unmatched', $query->fetchColumn());
    }

    public function testReverseMatchRecordsIssuedPaymentAndRejectsForeignMovement(): void
    {
        $sale = $this->saleInvoice('209987007', $this->client('Syntetický klient částečné úhrady'), 1200);
        $tx = $this->transaction($this->statement(), 500);
        $action = $this->container->get(BankPaymentCandidatesAction::class);
        $foreign = $this->transaction($this->statement('1000000005', '0100', $this->otherSupplierId()), 500);
        self::assertSame(404, $this->callAction($action, 'match', 'POST', 'admin', ['invoice_id' => $sale], ['id' => $foreign])['status']);
        $result = $this->callAction($action, 'match', 'POST', 'admin', ['invoice_id' => $sale], ['id' => $tx]);
        self::assertSame(200, $result['status'], json_encode($result['body']));
        self::assertTrue($result['body']['partial_payment']);
        $query = $this->db->pdo()->prepare('SELECT amount FROM invoice_payments WHERE bank_transaction_id = ? AND invoice_id = ?');
        $query->execute([$tx, $sale]);
        self::assertSame(500.0, (float) $query->fetchColumn());
        self::assertTrue($this->db->pdo()->inTransaction());
    }

    public function testBatchMatchesExactSelectionAndKeepsExistingPostingBehavior(): void
    {
        $invoice = $this->saleInvoice('209987010', $this->client('Syntetický odběratel dávky'), 5000);
        $statement = $this->statement();
        $first = $this->transaction($statement, 2000, ['posted_at' => self::YEAR . '-06-12']);
        $second = $this->transaction($statement, 1500, ['posted_at' => self::YEAR . '-06-13']);
        $third = $this->transaction($statement, 1500, ['posted_at' => self::YEAR . '-06-14']);
        $extra = $this->transaction($statement, 1500, ['posted_at' => self::YEAR . '-06-15']);
        $action = $this->container->get(BankPaymentCandidatesAction::class);
        $body = ['invoice_id' => $invoice, 'transaction_ids' => [$third, $first, $second]];

        $preview = $this->callAction($action, 'batch', 'POST', 'admin', $body);
        self::assertSame(200, $preview['status'], json_encode($preview['body']));
        self::assertSame(500000, $preview['body']['selected_cents']);
        self::assertSame(500000, $preview['body']['remaining_cents']);
        self::assertSame(0, $preview['body']['paid_cents']);

        $confirmed = $this->callAction($action, 'batch', 'POST', 'admin', $body + ['confirm_version' => $preview['body']['version']]);
        self::assertSame(200, $confirmed['status'], json_encode($confirmed['body']));
        self::assertSame([$first, $second, $third], array_column($confirmed['body']['items'], 'id'));
        foreach ($confirmed['body']['items'] as $item) {
            self::assertIsArray($item['posting']);
            self::assertNotSame('not_double_entry', $item['posting']['reason'] ?? null);
        }
        $payments = $this->db->pdo()->prepare('SELECT bank_transaction_id, paid_on, amount FROM invoice_payments WHERE invoice_id = ? ORDER BY paid_on');
        $payments->execute([$invoice]);
        $rows = $payments->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame([$first, $second, $third], array_map('intval', array_column($rows, 'bank_transaction_id')));
        self::assertSame([self::YEAR . '-06-12', self::YEAR . '-06-13', self::YEAR . '-06-14'], array_column($rows, 'paid_on'));
        self::assertSame([2000.0, 1500.0, 1500.0], array_map('floatval', array_column($rows, 'amount')));
        $status = $this->db->pdo()->prepare('SELECT match_status FROM bank_transactions WHERE id = ?');
        $status->execute([$extra]);
        self::assertSame('unmatched', $status->fetchColumn());
        self::assertSame(409, $this->callAction($action, 'batch', 'POST', 'admin',
            $body + ['confirm_version' => $preview['body']['version']])['status']);

        $bank = $this->container->get(BankStatementAction::class);
        self::assertSame(200, $this->callAction($bank, 'unmatch', 'POST', 'admin', [], ['id' => (string) $second])['status']);
        $document = $this->container->get(BankMatchSearch::class)->document($this->supplierId, 'invoice', $invoice);
        self::assertSame(1500.0, (float) $document['remaining']);
        self::assertTrue($this->db->pdo()->inTransaction());
    }

    public function testBatchRejectsStalePreviewAndInvalidSelectionsBeforeWriting(): void
    {
        $invoice = $this->purchaseInvoice('SYN-BATCH-STALE', $this->client('Syntetický dodavatel dávky'), 5000);
        $statement = $this->statement();
        $first = $this->transaction($statement, -2000);
        $second = $this->transaction($statement, -1500);
        $foreign = $this->transaction($this->statement(supplierId: $this->otherSupplierId()), -1000);
        $opposite = $this->transaction($statement, 100);
        $currency = $this->transaction($statement, -100, ['currency' => 'EUR']);
        $excess = $this->transaction($statement, -4000);
        $action = $this->container->get(BankPaymentCandidatesAction::class);
        $call = fn (array $ids, array $extra = [], string $role = 'admin') => $this->callAction($action, 'batch', 'POST', $role,
            ['purchase_invoice_id' => $invoice, 'transaction_ids' => $ids] + $extra);

        self::assertSame(403, $call([$first, $second], role: 'readonly')['status']);
        self::assertSame(422, $call([$first, $first])['status']);
        self::assertSame(409, $call([$first, $foreign])['status']);
        self::assertSame(422, $call([$first, $opposite])['status']);
        self::assertSame('currency', $call([$first, $currency])['body']['blocked']);
        self::assertSame('overpayment', $call([$first, $excess])['body']['blocked']);

        $preview = $call([$first, $second]);
        self::assertSame(200, $preview['status'], json_encode($preview['body']));
        self::assertSame(200, $this->callAction($action, 'match', 'POST', 'admin',
            ['purchase_invoice_id' => $invoice], ['id' => $excess])['status']);
        self::assertSame(409, $call([$first, $second], ['confirm_version' => $preview['body']['version']])['status']);
        $matches = $this->db->pdo()->prepare('SELECT bank_transaction_id FROM payment_matches WHERE purchase_invoice_id = ?');
        $matches->execute([$invoice]);
        self::assertSame([$excess], array_map('intval', $matches->fetchAll(\PDO::FETCH_COLUMN)));
        self::assertTrue($this->db->pdo()->inTransaction());
    }

    public function testBatchRollsBackEarlierPaymentsWhenLaterMovementBelongsToAdvance(): void
    {
        $client = $this->client('Syntetický klient zálohy');
        $advance = $this->saleInvoice('209987011', $client, 1500, 'proforma');
        $final = $this->saleInvoice('209987012', $client, 6000);
        $this->db->pdo()->prepare('UPDATE invoices SET parent_invoice_id = ? WHERE id = ?')->execute([$advance, $final]);
        $statement = $this->statement();
        $first = $this->transaction($statement, 2000, ['posted_at' => self::YEAR . '-06-12']);
        $second = $this->transaction($statement, 1500, ['posted_at' => self::YEAR . '-06-13']);
        $action = $this->container->get(BankPaymentCandidatesAction::class);
        $body = ['invoice_id' => $final, 'transaction_ids' => [$first, $second]];
        $preview = $this->callAction($action, 'batch', 'POST', 'admin', $body);
        self::assertSame(200, $preview['status'], json_encode($preview['body']));
        $confirmed = $this->callAction($action, 'batch', 'POST', 'admin', $body + ['confirm_version' => $preview['body']['version']]);
        self::assertSame(409, $confirmed['status'], json_encode($confirmed['body']));
        self::assertSame('advance_payment_belongs_to_advance', $confirmed['body']['error']['code']);
        self::assertSame($second, $confirmed['body']['error']['transaction_id']);
        $status = $this->db->pdo()->prepare('SELECT match_status FROM bank_transactions WHERE id IN (?, ?) ORDER BY id');
        $status->execute([$first, $second]);
        self::assertSame(['unmatched', 'unmatched'], $status->fetchAll(\PDO::FETCH_COLUMN));
        $document = $this->container->get(BankMatchSearch::class)->document($this->supplierId, 'invoice', $final);
        self::assertSame(6000.0, (float) $document['remaining']);
        self::assertTrue($this->db->pdo()->inTransaction());
    }
}
