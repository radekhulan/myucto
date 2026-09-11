<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Bank;

use MyInvoice\Action\Bank\BankStatementAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Deletion\BankStatementDeletionGuard;
use MyInvoice\Service\Invoice\InvoiceAlreadySettledException;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Dvojí zaúčtování úhrady po smazání výpisu.
 *
 * Incident: import výpisu → automatické párování založilo platbu a bankovní zápis
 * 221/311 → uživatel výpis smazal → pohyb zmizel kaskádou, platba zůstala bez vazby
 * a zápis v deníku jako sirotek → reimport založil nový pohyb → uživatel ho
 * odpároval a legacy heuristika vrátila fakturu do „vystavená", i když ji kryla
 * osiřelá platba → ruční párování založilo druhou platbu na plnou částku a druhý
 * zápis. Každý test tu hlídá jeden článek toho řetězu.
 */
#[Group('integration')]
final class BankTransactionReleaseTest extends BankPostingTestCase
{
    private const DAY = self::YEAR . '-06-15';

    private BankStatementAction $action;
    private InvoicePaymentService $payments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = $this->container->get(BankStatementAction::class);
        $this->payments = $this->container->get(InvoicePaymentService::class);
    }

    /** @return array{invoice:int, statement:int, tx:int, entry:int} */
    private function postedMatchedPayment(string $vs, float $amount = 1000.00): array
    {
        $invoice = $this->saleInvoice('FV-' . $vs, $this->client('Odběratel ' . $vs), $amount);
        $this->postPredpis('invoice', $invoice, '311', '602', $amount);
        $statement = $this->statement();
        $tx = $this->transaction($statement, $amount, ['match_status' => 'auto_exact', 'matched_invoice_id' => $invoice]);
        $this->payments->recordPayment($invoice, $amount, self::DAY, ['source' => 'bank', 'bank_transaction_id' => $tx]);
        $res = $this->service->handleTransaction($tx, $this->userId);
        self::assertSame('posted', $res['action'], json_encode($res));

        return ['invoice' => $invoice, 'statement' => $statement, 'tx' => $tx, 'entry' => (int) $res['entry_id']];
    }

    // ── smazání výpisu ───────────────────────────────────────────────────────

    public function testDeletingStatementReversesPostingAndReleasesPayment(): void
    {
        ['invoice' => $invoice, 'statement' => $statement, 'tx' => $tx, 'entry' => $entry] = $this->postedMatchedPayment('DEL1');
        self::assertSame('paid', $this->invoiceStatus($invoice));

        $res = $this->deleteStatement($statement);

        self::assertSame(200, $res['status'], json_encode($res['body']));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM bank_statements WHERE id = {$statement}"));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = {$invoice}"),
            'Platba z párování smazaného pohybu nesmí zůstat viset bez vazby.');
        self::assertSame('issued', $this->invoiceStatus($invoice));
        self::assertSame(0, $this->scalar(
            "SELECT COUNT(*) FROM journal_entries WHERE supplier_id = {$this->supplierId}
               AND source_type = 'bank' AND source_id = {$tx} AND reversed_by IS NULL"
        ), 'Bankovní zápis smazaného pohybu nesmí zůstat živý (sirotek v deníku).');
        $original = $this->journal->find($entry, $this->supplierId);
        self::assertNotNull($original['reversed_by'], 'Bankovní zápis musí být stornovaný.');
        self::assertNull($original['source_id'], 'Stornovaný zápis se od pohybu odpojí.');
        self::assertSame($this->supplierId, (int) $this->scalar(
            "SELECT supplier_id FROM activity_log
              WHERE action = 'bank.statement_deleted' AND entity_id = {$statement} ORDER BY id DESC LIMIT 1"
        ), 'Smazání výpisu se v auditní stopě musí dát dohledat u firmy.');
    }

    public function testDeletingStatementPostedInClosedPeriodIsRefused(): void
    {
        ['invoice' => $invoice, 'statement' => $statement, 'tx' => $tx, 'entry' => $entry] = $this->postedMatchedPayment('DEL2');
        $this->periods->setStatus($this->periodId, $this->supplierId, 'closed');

        $conflict = $this->container->get(BankStatementDeletionGuard::class)->conflict($this->supplierId, $statement);
        self::assertNotNull($conflict, 'Guard musí pohyb v uzavřeném období nahlásit předem.');
        self::assertSame('transactions_not_releasable', $conflict->code);
        self::assertSame(['closed_period' => 1], $conflict->counts);

        $res = $this->deleteStatement($statement);

        self::assertSame(409, $res['status'], json_encode($res['body']));
        self::assertSame('transactions_not_releasable', $res['body']['error']['code'] ?? null);
        self::assertStringContainsString('uzavřeném', (string) ($res['body']['error']['message'] ?? ''));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM bank_statements WHERE id = {$statement}"));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM invoice_payments WHERE bank_transaction_id = {$tx}"));
        $live = $this->journal->findBySource($this->supplierId, 'bank', $tx);
        self::assertSame($entry, (int) ($live['id'] ?? 0));
        self::assertNull($live['reversed_by'] ?? null);
        self::assertSame('paid', $this->invoiceStatus($invoice));
    }

    // ── zrušení párování ─────────────────────────────────────────────────────

    public function testUnmatchOfAlreadyPaidLinkKeepsInvoiceCoveredByOtherPaymentPaid(): void
    {
        $invoice = $this->saleInvoice('FV-UNM1', $this->client('Odběratel UNM1'), 1000.00);
        // Platba, která po smazání výpisu ztratila vazbu na pohyb (SET NULL).
        $this->payments->recordPayment($invoice, 1000.00, self::DAY, ['source' => 'bank']);
        self::assertSame('paid', $this->invoiceStatus($invoice));
        $statement = $this->statement();
        // Reimport: matcher pohyb jen navázal na už zaplacenou fakturu (already_paid).
        $tx = $this->transaction($statement, 1000.00, ['match_status' => 'auto_exact', 'matched_invoice_id' => $invoice]);

        $res = $this->callTx('unmatch', $tx);

        self::assertSame(200, $res['status'], json_encode($res['body']));
        self::assertSame('paid', $this->invoiceStatus($invoice),
            'Fakturu kryje jiná platba — zrušení párování ji nesmí vrátit do nezaplacených.');
        self::assertSame('unmatched', $this->txStatus($tx));
        self::assertSame($this->supplierId, (int) $this->scalar(
            "SELECT supplier_id FROM activity_log
              WHERE action = 'bank.tx_unmatch' AND entity_id = {$tx} ORDER BY id DESC LIMIT 1"
        ));
    }

    // ── ruční párování a pravidlo pro bankovní platbu ────────────────────────

    public function testManualMatchToInvoiceSettledByOrphanPaymentIsRefused(): void
    {
        $invoice = $this->saleInvoice('FV-MAN1', $this->client('Odběratel MAN1'), 1000.00);
        $this->payments->recordPayment($invoice, 1000.00, self::DAY, ['source' => 'bank']);
        // Stav po staré heuristice zrušení párování: „vystavená", ale plně zaplacená.
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued', paid_at = NULL WHERE id = ?")->execute([$invoice]);
        $statement = $this->statement();
        $tx = $this->transaction($statement, 1000.00);

        $res = $this->callTx('manualMatch', $tx, ['invoice_id' => $invoice]);

        self::assertSame(409, $res['status'], json_encode($res['body']));
        self::assertSame('invoice_already_settled', $res['body']['error']['code'] ?? null);
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = {$invoice}"),
            'Druhá platba na plně uhrazenou fakturu nesmí vzniknout.');
        self::assertSame(0, $this->entryCountForTx($tx));
        self::assertSame('unmatched', $this->txStatus($tx), 'Odmítnuté párování nesmí pohyb označit.');
    }

    public function testBankPaymentOnSettledInvoiceThrows(): void
    {
        $invoice = $this->saleInvoice('FV-REC1', $this->client('Odběratel REC1'), 1000.00);
        $this->payments->recordPayment($invoice, 1000.00, self::DAY, ['source' => 'manual']);

        $this->expectException(InvoiceAlreadySettledException::class);
        $this->payments->recordPayment($invoice, 1000.00, self::DAY, ['source' => 'bank']);
    }

    public function testBankPaymentIsCappedAtRemainingAmount(): void
    {
        $invoice = $this->saleInvoice('FV-REC2', $this->client('Odběratel REC2'), 1000.00);
        $this->payments->recordPayment($invoice, 400.00, self::DAY, ['source' => 'manual']);

        $recorded = $this->payments->recordPayment($invoice, 1000.00, self::DAY, ['source' => 'bank']);

        self::assertSame(600.00, $recorded['amount']);
        self::assertEqualsWithDelta(1000.00, (float) $this->scalar("SELECT paid_total FROM invoices WHERE id = {$invoice}"), 0.001);
        self::assertSame('paid', $this->invoiceStatus($invoice));
    }

    // ── pojistka v zaúčtování ────────────────────────────────────────────────

    public function testIncomingPaymentOnOverpaidInvoiceGoesToVerification(): void
    {
        $invoice = $this->saleInvoice('FV-OVR1', $this->client('Odběratel OVR1'), 1000.00);
        $this->postPredpis('invoice', $invoice, '311', '602', 1000.00);
        // Historická data: faktura zaplacená ručně a k tomu bankovní platba mimo pravidlo.
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source)
             VALUES (?, ?, ?, 1000.00, "CZK", "manual")'
        )->execute([$this->supplierId, $invoice, self::DAY]);
        $statement = $this->statement();
        $tx = $this->transaction($statement, 1000.00, ['match_status' => 'manual', 'matched_invoice_id' => $invoice]);
        $this->invoicePayment($invoice, $tx, 1000.00);

        $res = $this->service->handleTransaction($tx, $this->userId);

        self::assertSame('suggested', $res['action'], json_encode($res));
        self::assertSame('overpaid_verify', $res['reason'] ?? null);
        self::assertSame(0, $this->entryCountForTx($tx), 'Druhý zápis 221/311 na přeplacenou fakturu nesmí vzniknout.');
        $suggestion = $this->suggestionRow((int) $res['suggestion_id']);
        self::assertSame('needs_input', $suggestion['status']);
    }

    // ── pomocné ──────────────────────────────────────────────────────────────

    /** @return array{status:int, body:array<string,mixed>} */
    private function deleteStatement(int $statementId): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('DELETE', '/api/bank-statements/' . $statementId)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');

        return $this->decode($this->action->delete($req, new Psr7Response(), ['id' => (string) $statementId]));
    }

    /**
     * @param array<string,mixed> $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function callTx(string $method, int $txId, array $body = []): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/bank-transactions/' . $txId . '/' . $method)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withParsedBody($body);

        return $this->decode($this->action->{$method}($req, new Psr7Response(), ['id' => (string) $txId]));
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function decode(\Psr\Http\Message\ResponseInterface $resp): array
    {
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);

        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    private function invoiceStatus(int $invoiceId): string
    {
        return (string) $this->db->pdo()->query("SELECT status FROM invoices WHERE id = {$invoiceId}")->fetchColumn();
    }

    private function txStatus(int $txId): string
    {
        return (string) $this->db->pdo()->query("SELECT match_status FROM bank_transactions WHERE id = {$txId}")->fetchColumn();
    }

    private function scalar(string $sql): float|int
    {
        $value = $this->db->pdo()->query($sql)->fetchColumn();

        return is_numeric($value) ? $value + 0 : 0;
    }
}
