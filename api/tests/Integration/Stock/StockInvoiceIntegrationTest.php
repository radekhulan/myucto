<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Action\Invoice\DeleteInvoiceAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Stock\StockException;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Epic SKLAD, plán §8.2 — scénáře 4 (duplicitní varsymbol), 5 (idempotence
 * auto-výdeje), 6 (proforma/tax_document no-op, issue finálu → výdejka),
 * 7 (dobropis vratka + fallback + interní storno), 15 (replaceItems round-trip),
 * 18 (deaktivovaná karta při issue k FV nepřekáží, jen warning).
 */
#[Group('integration')]
final class StockInvoiceIntegrationTest extends StockTestCase
{
    public function testOrderCreditNoteLeavesPhysicalReturnToFulfillment(): void
    {
        $supplierId = $this->createSupplier();
        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'ORDER-CREDIT');
        $this->receiveStock($supplierId, $warehouseId, $itemId, '1.000', 10.0);
        $clientId = $this->client($supplierId);
        $parentId = $this->invoiceDraft($supplierId, $clientId);
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO sales_orders (order_uuid, supplier_id, client_id, order_number, currency_id, currency_code, customer_snapshot) VALUES (UUID(), ?, ?, ?, ?, ?, ?)')
            ->execute([$supplierId, $clientId, 'TEST-ORDER-CREDIT', $this->currencyIdFor($supplierId), 'CZK', '{}']);
        $orderId = (int) $pdo->lastInsertId();
        try {
            $pdo->prepare('INSERT INTO sales_order_invoice_links (supplier_id, order_id, invoice_id) VALUES (?, ?, ?)')->execute([$supplierId, $orderId, $parentId]);
            $creditId = $this->invoiceDraft($supplierId, $clientId, 'credit_note', ['parent_invoice_id' => $parentId]);
            $this->invoiceItem($creditId, $itemId, $warehouseId, '1.000');
            $this->callIssueForInvoice($supplierId, $this->invoiceRow($creditId, $supplierId, 'credit_note'));
            self::assertSame([], $this->docsRepo->listByInvoice($supplierId, $creditId));
            self::assertSame(1000, $this->level($supplierId, $warehouseId, $itemId)['qtyT']);
        } finally {
            $pdo->prepare('DELETE FROM sales_orders WHERE supplier_id = ? AND id = ?')->execute([$supplierId, $orderId]);
        }
    }

    // ── 6) issue finálu → výdejka ────────────────────────────────────────────

    public function testIssueForRegularInvoiceCreatesPostedIssueAndDecrementsLevel(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'INV-1');
        $this->receiveStock($supplierId, $whId, $itemId, '10.000', 10.0);

        $clientId = $this->client($supplierId);
        $invoiceId = $this->invoiceDraft($supplierId, $clientId);
        $this->invoiceItem($invoiceId, $itemId, $whId, '3.000');

        $this->callIssueForInvoice($supplierId, $this->invoiceRow($invoiceId, $supplierId, 'invoice'));

        $docs = $this->docsRepo->listByInvoice($supplierId, $invoiceId);
        self::assertCount(1, $docs);
        self::assertSame('issue', $docs[0]['doc_type']);
        self::assertSame('posted', $docs[0]['status']);
        self::assertSame('invoice', $docs[0]['origin']);

        $level = $this->level($supplierId, $whId, $itemId);
        self::assertSame(7000, $level['qtyT']);
    }

    // ── 5) idempotence auto-výdeje (B4) ──────────────────────────────────────

    public function testDoubleIssueForInvoiceCallIsIdempotent(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'INV-2');
        $this->receiveStock($supplierId, $whId, $itemId, '10.000', 10.0);

        $clientId = $this->client($supplierId);
        $invoiceId = $this->invoiceDraft($supplierId, $clientId);
        $this->invoiceItem($invoiceId, $itemId, $whId, '3.000');
        $invoiceRow = $this->invoiceRow($invoiceId, $supplierId, 'invoice');

        $this->callIssueForInvoice($supplierId, $invoiceRow);
        $this->callIssueForInvoice($supplierId, $invoiceRow); // druhé volání = no-op (B4)

        $docs = $this->docsRepo->listByInvoice($supplierId, $invoiceId);
        self::assertCount(1, $docs, 'dvojí volání issueForInvoice smí vytvořit nejvýš jednu posted výdejku.');

        $level = $this->level($supplierId, $whId, $itemId);
        self::assertSame(7000, $level['qtyT'], 'stav se nesmí vydat dvakrát.');
    }

    // ── 6) proforma / tax_document → no-op ───────────────────────────────────

    public function testProformaAndTaxDocumentIssueDoNotMoveStock(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'INV-3');
        $this->receiveStock($supplierId, $whId, $itemId, '10.000', 10.0);
        $clientId = $this->client($supplierId);

        foreach (['proforma', 'tax_document'] as $type) {
            $invoiceId = $this->invoiceDraft($supplierId, $clientId, $type);
            $this->invoiceItem($invoiceId, $itemId, $whId, '2.000');
            $this->callIssueForInvoice($supplierId, $this->invoiceRow($invoiceId, $supplierId, $type));

            self::assertSame([], $this->docsRepo->listByInvoice($supplierId, $invoiceId), "$type nesmí hýbat skladem.");
        }

        $level = $this->level($supplierId, $whId, $itemId);
        self::assertSame(10000, $level['qtyT'], 'proforma/tax_document nesmí ovlivnit stav.');
    }

    // ── 18) B10: deaktivovaná karta neblokuje starý draft ────────────────────

    public function testInactiveCardIssueOfOldDraftPassesWithWarning(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'INV-4');
        $this->receiveStock($supplierId, $whId, $itemId, '10.000', 10.0);

        $clientId = $this->client($supplierId);
        $invoiceId = $this->invoiceDraft($supplierId, $clientId);
        $this->invoiceItem($invoiceId, $itemId, $whId, '2.000');

        // Karta se deaktivuje AŽ PO založení řádku FV (starý draft).
        $this->itemsRepo->deactivate($supplierId, $itemId);

        $this->callIssueForInvoice($supplierId, $this->invoiceRow($invoiceId, $supplierId, 'invoice'));

        $docs = $this->docsRepo->listByInvoice($supplierId, $invoiceId);
        self::assertCount(1, $docs);
        self::assertSame('posted', $docs[0]['status'], 'B10: neaktivní karta nesmí blokovat auto-výdej starého draftu.');
    }

    // ── 7) dobropis: vratka v původní ceně / fallback / interní storno ──────

    public function testCreditNoteReturnsAtParentOriginalIssueCost(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'INV-5');
        $this->receiveStock($supplierId, $whId, $itemId, '20.000', 10.0);

        $clientId = $this->client($supplierId);
        $parentId = $this->invoiceDraft($supplierId, $clientId);
        $this->invoiceItem($parentId, $itemId, $whId, '5.000');
        $this->callIssueForInvoice($supplierId, $this->invoiceRow($parentId, $supplierId, 'invoice'));
        // Parent vydal 5 ks @ avg 10 = 50 Kč. Přijmi další zásobu za jinou cenu,
        // aby se AKTUÁLNÍ průměr lišil od ceny PŮVODNÍHO výdeje (ověří, že vratka
        // použije historickou, ne aktuální cenu).
        $this->receiveStock($supplierId, $whId, $itemId, '10.000', 20.0, '2099-06-15');

        $creditNoteId = $this->invoiceDraft($supplierId, $clientId, 'credit_note', ['parent_invoice_id' => $parentId]);
        $this->invoiceItem($creditNoteId, $itemId, $whId, '5.000');

        $this->callReturnForCreditNote($supplierId, [
            'id'                 => $creditNoteId,
            'parent_invoice_id'  => $parentId,
            'issue_date'         => '2099-06-20',
            'varsymbol'          => '2099777',
        ]);

        $docs = $this->docsRepo->listByInvoice($supplierId, $creditNoteId);
        self::assertCount(1, $docs);
        self::assertSame('receipt', $docs[0]['doc_type']);
        self::assertSame('credit_note', $docs[0]['origin']);
        $lines = $this->docsRepo->lines($supplierId, (int) $docs[0]['id']);
        self::assertSame('10.000000', $lines[0]['unit_cost'], 'vratka musí použít PŮVODNÍ cenu výdeje parenta, ne aktuální průměr.');
        self::assertSame('50.00', $lines[0]['value_total']);
    }

    public function testCreditNoteFallsBackToCurrentAverageWithoutOriginalIssue(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'INV-6');
        $this->receiveStock($supplierId, $whId, $itemId, '10.000', 12.0);

        $clientId = $this->client($supplierId);
        // Dobropis BEZ dohledatelného výdeje parenta (parent_invoice_id = 0).
        $creditNoteId = $this->invoiceDraft($supplierId, $clientId, 'credit_note');
        $this->invoiceItem($creditNoteId, $itemId, $whId, '2.000');

        $this->callReturnForCreditNote($supplierId, [
            'id'                => $creditNoteId,
            'parent_invoice_id' => 0,
            'issue_date'        => '2099-06-20',
            'varsymbol'         => '2099778',
        ]);

        $docs = $this->docsRepo->listByInvoice($supplierId, $creditNoteId);
        self::assertCount(1, $docs);
        $lines = $this->docsRepo->lines($supplierId, (int) $docs[0]['id']);
        self::assertSame('12.000000', $lines[0]['unit_cost'], 'bez dohledatelného výdeje → fallback na AKTUÁLNÍ průměrnou cenu.');
        self::assertStringContainsString('aktuální', (string) $lines[0]['note']);
    }

    public function testInternalCancelReversesAutoIssuedDocument(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'INV-7');
        $this->receiveStock($supplierId, $whId, $itemId, '10.000', 10.0);

        $clientId = $this->client($supplierId);
        $invoiceId = $this->invoiceDraft($supplierId, $clientId);
        $this->invoiceItem($invoiceId, $itemId, $whId, '4.000');
        $this->callIssueForInvoice($supplierId, $this->invoiceRow($invoiceId, $supplierId, 'invoice'));

        $levelAfterIssue = $this->level($supplierId, $whId, $itemId);
        self::assertSame(6000, $levelAfterIssue['qtyT']);

        $pdo = $this->db->pdo();
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();
        $this->issue->reverseForInvoice($supplierId, $invoiceId, $this->userId);
        $pdo->commit();

        $docs = $this->docsRepo->listByInvoice($supplierId, $invoiceId);
        self::assertCount(2, $docs, 'původní výdejka + protidoklad.');
        $byStatus = array_column($docs, 'status', 'id');
        self::assertContains('reversed', $byStatus);
        self::assertContains('posted', $byStatus);

        $levelAfterCancel = $this->level($supplierId, $whId, $itemId);
        self::assertSame(10000, $levelAfterCancel['qtyT'], 'interní storno musí vrátit stav na původní hodnotu.');
    }

    // ── 4) duplicitní varsymbol při zapnutém skladu ─────────────────────────

    /**
     * Plán §8.2 scénář 4: duplicitní varsymbol při zapnutém skladu musí
     * odmítnout databázový invariant dřív, než může vzniknout skladový doklad.
     */
    public function testDuplicateVarsymbolCannotCoexistWithStockEnabled(): void
    {
        $supplierId = $this->createSupplier();
        $clientId = $this->client($supplierId);
        $existingId = $this->invoiceDraft($supplierId, $clientId, 'invoice', ['varsymbol' => 'DUP-001']);
        $this->db->pdo()->prepare('UPDATE invoices SET status = "issued" WHERE id = ?')->execute([$existingId]);

        try {
            $this->invoiceDraft($supplierId, $clientId, 'invoice', ['varsymbol' => 'DUP-001']);
            self::fail('DB by měla UNIQUE (supplier_id, varsymbol) porušení odmítnout — pokud neodmítla, scénář JE dynamicky testovatelný, doplň skutečný test.');
        } catch (\PDOException $e) {
            self::assertSame('23000', $e->getCode(), 'očekávaná duplicate-key výjimka potvrzuje, že stav ze scénáře 4 nejde v DB vytvořit dvěma sekvenčními zápisy.');
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM invoices WHERE supplier_id = ? AND varsymbol = ?'
        );
        $stmt->execute([$supplierId, 'DUP-001']);
        self::assertSame(1, (int) $stmt->fetchColumn(), 'po odmítnuté duplicitě musí zůstat právě původní faktura.');
        self::assertSame([], $this->docsRepo->listByInvoice($supplierId, $existingId), 'odmítnutá duplicita nesmí vytvořit skladový doklad.');
    }

    // ── 15) replaceItems round-trip zachová vazbu na kartu/sklad (B5) ────────

    public function testReplaceItemsRoundTripPreservesStockLinks(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'INV-9');
        $clientId = $this->client($supplierId);
        $invoiceId = $this->invoiceDraft($supplierId, $clientId);
        $this->invoiceItem($invoiceId, $itemId, $whId, '1.000');

        /** @var InvoiceRepository $repo */
        $repo = $this->container->get(InvoiceRepository::class);
        $repo->replaceItems($invoiceId, [[
            'description'            => 'Přepsaná položka',
            'quantity'               => 2,
            'unit_price_without_vat' => 150,
            'vat_rate_id'            => $this->vatRateId,
            'stock_item_id'          => $itemId,
            'warehouse_id'           => $whId,
            'order_index'            => 0,
        ]]);

        $stmt = $this->db->pdo()->prepare('SELECT stock_item_id, warehouse_id FROM invoice_items WHERE invoice_id = ?');
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertSame($itemId, (int) $row['stock_item_id'], 'replaceItems (DELETE+INSERT) musí zachovat stock_item_id.');
        self::assertSame($whId, (int) $row['warehouse_id'], 'replaceItems (DELETE+INSERT) musí zachovat warehouse_id.');
    }

    public function testAutomaticIssueUsesTaxDate(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'INV-DUZP');
        $this->receiveStock($supplierId, $whId, $itemId, '5.000', 10.0, '2098-12-01');
        $invoiceId = $this->invoiceDraft($supplierId, $this->client($supplierId), 'invoice', [
            'issue_date' => '2099-01-10',
            'tax_date' => '2098-12-31',
        ]);
        $this->invoiceItem($invoiceId, $itemId, $whId, '1.000');

        $this->callIssueForInvoice($supplierId, $this->invoiceRow($invoiceId, $supplierId, 'invoice'));
        $docs = $this->docsRepo->listByInvoice($supplierId, $invoiceId);
        self::assertSame('2098-12-31', $docs[0]['doc_date']);
    }

    public function testNegativeRegularInvoiceLineCreatesReceipt(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'INV-RETURN');
        $this->receiveStock($supplierId, $whId, $itemId, '3.000', 10.0);
        $invoiceId = $this->invoiceDraft($supplierId, $this->client($supplierId));
        $this->invoiceItem($invoiceId, $itemId, $whId, '3.000');
        $this->invoiceItem($invoiceId, $itemId, $whId, '-1.000');

        $this->callIssueForInvoice($supplierId, $this->invoiceRow($invoiceId, $supplierId, 'invoice'));
        $docs = $this->docsRepo->listByInvoice($supplierId, $invoiceId);
        self::assertCount(2, $docs);
        self::assertEqualsCanonicalizing(['issue', 'receipt'], array_column($docs, 'doc_type'));
        self::assertSame(1000, $this->level($supplierId, $whId, $itemId)['qtyT']);
        $receipt = array_values(array_filter($docs, static fn (array $doc): bool => $doc['doc_type'] === 'receipt'))[0];
        self::assertSame('10.000000', $this->docsRepo->lines($supplierId, (int) $receipt['id'])[0]['unit_cost']);
    }

    public function testAdminForceDeleteReversesPostedStockDocument(): void
    {
        $supplierId = $this->createSupplier();
        $whId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'INV-DELETE');
        $this->receiveStock($supplierId, $whId, $itemId, '5.000', 10.0);
        $invoiceId = $this->invoiceDraft($supplierId, $this->client($supplierId));
        $this->invoiceItem($invoiceId, $itemId, $whId, '2.000');
        $this->callIssueForInvoice($supplierId, $this->invoiceRow($invoiceId, $supplierId, 'invoice'));
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([$invoiceId]);

        /** @var DeleteInvoiceAction $action */
        $action = $this->container->get(DeleteInvoiceAction::class);
        $request = (new ServerRequestFactory())
            ->createServerRequest('DELETE', '/api/invoices/' . $invoiceId)
            ->withQueryParams(['force' => '1'])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        $response = $action($request, new Psr7Response(), ['id' => (string) $invoiceId]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(5000, $this->level($supplierId, $whId, $itemId)['qtyT']);

        $stmt = $this->db->pdo()->prepare(
            "SELECT payload FROM activity_log
              WHERE action = 'invoice.force_deleted' AND entity_id = ?
           ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$invoiceId]);
        $payload = json_decode((string) $stmt->fetchColumn(), true);
        self::assertNull(
            $payload['retention_override'] ?? null,
            'Nezaúčtovaná faktura nepotřebuje retenční override ani při reverzi skladové výdejky.',
        );

        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM stock_documents WHERE supplier_id = ? AND status = "reversed"');
        $stmt->execute([$supplierId]);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testAdminForceDeleteUnpostedInvoiceSkipsRetentionPeriod(): void
    {
        $supplierId = $this->createSupplier();
        $invoiceId = $this->invoiceDraft($supplierId, $this->client($supplierId), 'invoice', [
            'varsymbol' => 'FV-2099-UNPOSTED',
        ]);
        $this->db->pdo()->prepare(
            "UPDATE invoices SET status = 'issued', booked_at = NULL WHERE id = ? AND supplier_id = ?"
        )->execute([$invoiceId, $supplierId]);

        /** @var DeleteInvoiceAction $action */
        $action = $this->container->get(DeleteInvoiceAction::class);
        $request = (new ServerRequestFactory())
            ->createServerRequest('DELETE', '/api/invoices/' . $invoiceId)
            ->withQueryParams(['force' => '1'])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        $response = $action($request, new Psr7Response(), ['id' => (string) $invoiceId]);

        self::assertSame(
            200,
            $response->getStatusCode(),
            'Admin force-delete musí smazat nezaúčtovanou fakturu bez ack_retention.',
        );
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM invoices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$invoiceId, $supplierId]);
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    // ── pomocníci ────────────────────────────────────────────────────────────

    /** @return array<string,mixed> minimální řádek faktury dle kontraktu StockIssueService */
    private function invoiceRow(int $invoiceId, int $supplierId, string $type): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT issue_date, tax_date, parent_invoice_id, varsymbol FROM invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return [
            'id'                 => $invoiceId,
            'supplier_id'        => $supplierId,
            'invoice_type'       => $type,
            'parent_invoice_id'  => $row['parent_invoice_id'] !== null ? (int) $row['parent_invoice_id'] : null,
            'issue_date'         => (string) $row['issue_date'],
            'tax_date'           => $row['tax_date'] !== null ? (string) $row['tax_date'] : null,
            'varsymbol'          => (string) ($row['varsymbol'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $invoiceRow */
    private function callIssueForInvoice(int $supplierId, array $invoiceRow): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();
        try {
            $this->issue->issueForInvoice($supplierId, $invoiceRow, $this->userId);
            $pdo->commit();
        } catch (StockException $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** @param array<string,mixed> $creditNoteRow */
    private function callReturnForCreditNote(int $supplierId, array $creditNoteRow): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();
        try {
            $this->issue->returnForCreditNote($supplierId, $creditNoteRow, $this->userId);
            $pdo->commit();
        } catch (StockException $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
