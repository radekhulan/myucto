<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\DeleteInvoiceAction;
use MyInvoice\Action\Invoice\UpdateInvoiceAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\RecurringTemplateRepository;
use MyInvoice\Service\Invoice\AutoIssueAndSendService;
use MyInvoice\Service\Invoice\RecurringInvoiceGenerator;
use MyInvoice\Service\Invoice\VarsymbolGenerator;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Číslo z řady se čerpá jen skutečným vystavením. Cesta, která si číslo vezme
 * a doklad pak nevystaví nebo ho zahodí, ho musí vrátit přes
 * {@see VarsymbolGenerator::releaseIfLatest()}. Jinak další doklad v řadě
 * dostane číslo o díru výš a řada přestane navazovat.
 *
 * Každý test si zakládá vlastního dodavatele (StockTestCase) s explicitní
 * šablonou čísla a pracuje v období 2099, takže nesahá na žádnou existující řadu.
 */
#[Group('integration')]
final class InvoiceNumberReleaseTest extends StockTestCase
{
    private const INVOICE_TPL  = 'T{YY}{MM}{CCC}';
    private const PROFORMA_TPL = 'Z{YY}{MM}{CCC}';
    private const DATE         = '2099-06-10';

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            foreach ($this->supplierIds as $sid) {
                $pdo->prepare('DELETE FROM invoices WHERE supplier_id = ?')->execute([$sid]);
                $pdo->prepare('DELETE FROM recurring_invoice_templates WHERE supplier_id = ?')->execute([$sid]);
                $pdo->prepare('DELETE FROM invoice_counters WHERE supplier_id = ?')->execute([$sid]);
            }
        }
        parent::tearDown();
    }

    public function testDeletingDraftWithAllocatedNumberReturnsItToSeries(): void
    {
        $sid = $this->numberedSupplier();
        $draftId = $this->invoiceDraft($sid, $this->client($sid));
        $this->invoiceItem($draftId, null, null);

        // Žádost o schválení výkazu přidělí číslo už konceptu.
        $draft = $this->container->get(AutoIssueAndSendService::class)->allocateVarsymbolAndSnapshots($draftId);
        self::assertSame('T9906001', $draft['varsymbol']);
        self::assertSame(1, $this->counter($sid, 'invoice'));

        $resp = $this->deleteInvoice($sid, $draftId);
        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getBody());
        self::assertSame(
            0,
            $this->counter($sid, 'invoice'),
            'Smazaný koncept s přiděleným číslem musí číslo vrátit do řady.',
        );
    }

    public function testRejectedTypeChangeKeepsBothSeriesIntact(): void
    {
        $sid = $this->numberedSupplier();
        $clientId = $this->client($sid);
        $date = new \DateTimeImmutable(self::DATE);
        $gen = $this->container->get(VarsymbolGenerator::class);

        $vs = $gen->render(self::PROFORMA_TPL, $date, 1);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_with_vat, created_by)
             VALUES ('proforma', ?, ?, ?, ?, NULL, '2099-06-24', ?, 'issued', 100, 121, ?)"
        )->execute([$vs, $clientId, $sid, self::DATE, $this->currencyIdFor($sid), $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $gen->syncCounter($sid, 'proforma', $date);
        self::assertSame(1, $this->counter($sid, 'proforma'));

        // Přečíslování proforma → faktura, které neprojde validací.
        $resp = $this->forcePut($sid, $id, [
            'invoice_type'   => 'invoice',
            'client_id'      => $clientId,
            'currency_id'    => $this->currencyIdFor($sid),
            'issue_date'     => self::DATE,
            'due_date'       => '2099-06-24',
            'tax_date'       => self::DATE,
            'payment_method' => 'neexistujici_zpusob',
            'items'          => [[
                'description'            => 'Položka',
                'quantity'               => 1,
                'unit'                   => 'ks',
                'unit_price_without_vat' => 100,
                'vat_rate_id'            => $this->vatRateId,
            ]],
        ]);
        self::assertSame(400, $resp->getStatusCode(), (string) $resp->getBody());

        $row = $pdo->query("SELECT invoice_type, varsymbol FROM invoices WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('proforma', $row['invoice_type']);
        self::assertSame($vs, (string) $row['varsymbol']);
        self::assertSame(
            1,
            $this->counter($sid, 'proforma'),
            'Odmítnutá změna typu nesmí uvolnit číslo, které doklad dál drží.',
        );
        self::assertSame(
            0,
            $this->counter($sid, 'invoice'),
            'Odmítnutá změna typu nesmí propálit číslo v řadě faktur.',
        );
    }

    public function testRecurringIssueBlockedByStockReturnsNumber(): void
    {
        $sid = $this->numberedSupplier(stockEnabled: true);
        $warehouseId = $this->warehouse($sid);
        $itemId = $this->item($sid, 'REC-BEZ-ZASOBY');
        $clientId = $this->client($sid);

        $repo = $this->container->get(RecurringTemplateRepository::class);
        $tplId = $repo->create([
            'supplier_id'      => $sid,
            'client_id'        => $clientId,
            'name'             => 'Test opakované fakturace bez zásoby',
            'frequency'        => 'monthly',
            'end_of_month'     => false,
            'anchor_date'      => self::DATE,
            'next_run_date'    => self::DATE,
            'invoice_type'     => 'invoice',
            'currency_id'      => $this->currencyIdFor($sid),
            'payment_due_days' => 14,
            'auto_issue'       => true,
            'auto_send_email'  => false,
            'status'           => 'active',
        ], $this->userId);
        $repo->replaceItems($tplId, [[
            'description'            => 'Zboží',
            'quantity'               => 1.0,
            'unit'                   => 'ks',
            'unit_price_without_vat' => 100.0,
            'vat_rate_id'            => $this->vatRateId,
            'order_index'            => 0,
        ]]);
        $this->db->pdo()->prepare(
            'UPDATE recurring_invoice_template_items SET stock_item_id = ?, warehouse_id = ? WHERE template_id = ?'
        )->execute([$itemId, $warehouseId, $tplId]);

        try {
            $this->container->get(RecurringInvoiceGenerator::class)
                ->generate($tplId, null, $this->userId, '127.0.0.1', 'phpunit');
            self::fail('Vystavení bez zásoby mělo skončit chybou skladu.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('sklad', $e->getMessage());
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT status, varsymbol FROM invoices WHERE supplier_id = ? AND recurring_template_id = ?'
        );
        $stmt->execute([$sid, $tplId]);
        $invoice = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('draft', $invoice['status']);
        self::assertNull($invoice['varsymbol']);
        self::assertSame(
            0,
            $this->counter($sid, 'invoice'),
            'Vystavení zablokované skladem nesmí propálit číslo řady.',
        );
    }

    private function numberedSupplier(bool $stockEnabled = false): int
    {
        $sid = $this->createSupplier('tax_evidence', $stockEnabled, true);
        $this->db->pdo()->prepare(
            "UPDATE supplier
                SET invoice_number_format = ?, proforma_number_format = ?, invoice_number_period = 'month'
              WHERE id = ?"
        )->execute([self::INVOICE_TPL, self::PROFORMA_TPL, $sid]);
        return $sid;
    }

    private function counter(int $supplierId, string $type): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(last_number), 0) FROM invoice_counters WHERE supplier_id = ? AND invoice_type = ?'
        );
        $stmt->execute([$supplierId, $type]);
        return (int) $stmt->fetchColumn();
    }

    private function deleteInvoice(int $supplierId, int $invoiceId): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('DELETE', '/api/invoices/' . $invoiceId)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        return ($this->container->get(DeleteInvoiceAction::class))($request, new Psr7Response(), ['id' => (string) $invoiceId]);
    }

    /** @param array<string,mixed> $body */
    private function forcePut(int $supplierId, int $invoiceId, array $body): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/invoices/' . $invoiceId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withQueryParams(['force' => '1'])
            ->withParsedBody($body);
        return ($this->container->get(UpdateInvoiceAction::class))($request, new Psr7Response(), ['id' => (string) $invoiceId]);
    }
}
