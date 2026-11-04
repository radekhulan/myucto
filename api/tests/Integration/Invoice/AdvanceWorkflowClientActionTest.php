<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\{AdvanceDrawdownAction, CreatePaymentTaxDocumentAction, IssueFinalFromProformaAction, IssueInvoiceAction, LinkAdvanceAction, UnlinkAdvanceAction};
use MyInvoice\Action\PurchaseInvoice\{LinkAdvancePurchaseInvoiceAction, UnlinkAdvancePurchaseInvoiceAction};
use MyInvoice\Middleware\{AuthMiddleware, SupplierScopeMiddleware};
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\{DataProvider, TestWith};
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/** Skutečné serverové akce pod klientskou rolí; všechna data se vracejí rollbackem. */
final class AdvanceWorkflowClientActionTest extends BankPostingTestCase
{
    use IsolatedSupplierTrait;

    #[TestWith([false])]
    #[TestWith([true])]
    public function testManualPaymentDocumentAndFinalAreDraftsAndIdempotent(bool $grossPrices): void
    {
        $f = $this->fixture($grossPrices);
        $tax = $this->data($this->invoke(CreatePaymentTaxDocumentAction::class, '__invoke', $f['proforma'], [], $f['payment']));
        $taxId = $tax['tax_document_id'];
        $repo = $this->container->get(InvoiceRepository::class);
        self::assertSame('draft', $repo->find($taxId)['status']);
        self::assertSame($taxId, $this->data($this->invoke(CreatePaymentTaxDocumentAction::class, '__invoke',
            $f['proforma'], [], $f['payment']))['tax_document_id']);
        $this->data($this->invoke(IssueInvoiceAction::class, '__invoke', $taxId));

        $body = ['tax_date' => '2099-06-10', 'due_date' => '2099-06-24', 'final_total' => $grossPrices ? 2420 : 2000];
        $final = $this->data($this->invoke(IssueFinalFromProformaAction::class, '__invoke', $f['proforma'], $body), 201);
        self::assertSame('draft', $final['invoice']['status']);
        self::assertSame($grossPrices, $final['invoice']['prices_include_vat']);
        self::assertEqualsWithDelta(1210, (float) $final['invoice']['amount_to_pay'], 0.01);
        self::assertEqualsWithDelta(210, (float) $final['invoice']['total_vat'], 0.01);
        $again = $this->data($this->invoke(IssueFinalFromProformaAction::class, '__invoke', $f['proforma'], $body), 201);
        self::assertSame($final['final_invoice_id'], $again['final_invoice_id']);
        self::assertSame(2, $this->childCount($f['proforma']));
    }

    public function testDrawdownCanBeReplacedButCannotBeOverdrawnAndCanBeRemoved(): void
    {
        $f = $this->fixture();
        $tax = $this->data($this->invoke(CreatePaymentTaxDocumentAction::class, '__invoke', $f['proforma'], [], $f['payment']));
        $this->data($this->invoke(IssueInvoiceAction::class, '__invoke', $tax['tax_document_id']));
        $second = $this->makeInvoice($f['client'], 'invoice', 'draft');
        $body = ['proforma_id' => $f['proforma'], 'amount' => 600];
        $first = $this->data($this->invoke(AdvanceDrawdownAction::class, 'apply', $f['invoice'], $body));
        self::assertEqualsWithDelta(610, $first['drawdown']['remaining'], 0.01);
        $repeat = $this->data($this->invoke(AdvanceDrawdownAction::class, 'apply', $f['invoice'], $body));
        self::assertEqualsWithDelta(610, $repeat['drawdown']['remaining'], 0.01);
        self::assertSame(409, $this->invoke(AdvanceDrawdownAction::class, 'apply', $second,
            ['proforma_id' => $f['proforma'], 'amount' => 700])->getStatusCode());
        $rest = $this->data($this->invoke(AdvanceDrawdownAction::class, 'apply', $second,
            ['proforma_id' => $f['proforma'], 'amount' => 610]));
        self::assertEqualsWithDelta(0, $rest['drawdown']['remaining'], 0.01);
        $this->data($this->invoke(AdvanceDrawdownAction::class, 'remove', $f['invoice']));
        $invoice = $this->container->get(InvoiceRepository::class)->find($f['invoice']);
        self::assertNull($invoice['parent_invoice_id']);
        self::assertEqualsWithDelta(1210, $invoice['amount_to_pay'], 0.01);
    }

    public function testLinkAndUnlinkPreserveDeductedAmountOnBothSides(): void
    {
        $f = $this->fixture();
        $linked = $this->data($this->invoke(LinkAdvanceAction::class, '__invoke', $f['invoice'], ['advance_id' => $f['proforma']]));
        self::assertSame($f['proforma'], $linked['parent_invoice_id']);
        self::assertEqualsWithDelta(1210, $linked['advance_paid_amount'], 0.01);
        $unlinked = $this->data($this->invoke(UnlinkAdvanceAction::class, '__invoke', $f['invoice']));
        self::assertNull($unlinked['parent_invoice_id']);
        self::assertEqualsWithDelta(1210, $unlinked['advance_paid_amount'], 0.01);

        $this->data($this->invoke(LinkAdvancePurchaseInvoiceAction::class, '__invoke', $f['purchase'], ['advance_id' => $f['purchase_advance']]));
        $this->data($this->invoke(UnlinkAdvancePurchaseInvoiceAction::class, '__invoke', $f['purchase']));
        $row = $this->db->pdo()->query('SELECT advance_purchase_invoice_id, advance_paid_amount FROM purchase_invoices WHERE id = ' . $f['purchase'])->fetch(\PDO::FETCH_ASSOC);
        self::assertNull($row['advance_purchase_invoice_id']);
        self::assertEqualsWithDelta(500, (float) $row['advance_paid_amount'], 0.01);
    }

    public static function writes(): array
    {
        return [
            'propojit vydanou' => [LinkAdvanceAction::class, '__invoke', 'invoice'],
            'odpojit vydanou' => [UnlinkAdvanceAction::class, '__invoke', 'invoice'],
            'čerpat' => [AdvanceDrawdownAction::class, 'apply', 'invoice'],
            'odebrat čerpání' => [AdvanceDrawdownAction::class, 'remove', 'invoice'],
            'finál' => [IssueFinalFromProformaAction::class, '__invoke', 'proforma'],
            'doklad k platbě' => [CreatePaymentTaxDocumentAction::class, '__invoke', 'proforma'],
            'propojit přijatou' => [LinkAdvancePurchaseInvoiceAction::class, '__invoke', 'purchase'],
            'odpojit přijatou' => [UnlinkAdvancePurchaseInvoiceAction::class, '__invoke', 'purchase'],
        ];
    }

    #[DataProvider('writes')]
    public function testForeignCompanyAndClosedPeriodCannotBeBypassed(string $class, string $method, string $source): void
    {
        $f = $this->fixture();
        $body = ['advance_id' => $source === 'purchase' ? $f['purchase_advance'] : $f['proforma'],
            'proforma_id' => $f['proforma'], 'amount' => 250, 'tax_date' => '2099-06-10'];
        $other = $this->createIsolatedSupplier($this->db->pdo(), $this->supplierId);
        $foreign = $this->invoke($class, $method, $f[$source], $body, $f['payment'], $other);
        self::assertSame(404, $foreign->getStatusCode(), (string) $foreign->getBody());
        $this->db->pdo()->prepare('UPDATE accounting_periods SET status = "closed" WHERE id = ?')->execute([$this->periodId]);
        $locked = $this->invoke($class, $method, $f[$source], $body, $f['payment'], force: true);
        self::assertSame(403, $locked->getStatusCode(), (string) $locked->getBody());
        self::assertSame('document_locked', json_decode((string) $locked->getBody(), true)['error']['code']);
        self::assertSame(0, $this->childCount($f['proforma']));
    }

    private function fixture(bool $gross = false): array
    {
        $this->setVatPayerAt($this->db->pdo(), $this->supplierId, '2099-01-01', true);
        $this->db->pdo()->prepare('UPDATE supplier SET proforma_payment_document = "manual", stock_enabled = 0 WHERE id = ?')->execute([$this->supplierId]);
        $client = $this->client('Syntetický klient zálohového cyklu');
        $proforma = $this->makeInvoice($client, 'proforma', 'issued', $gross);
        $payment = $this->container->get(InvoicePaymentService::class)->recordPayment($proforma, 1210, '2099-06-10');
        self::assertSame(0, $this->childCount($proforma), 'Ruční režim nesmí sám vytvořit doklad.');
        $purchaseAdvance = $this->purchaseInvoice('SYNTHETIC-ADVANCE', $client, 500, 'advance');
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET status = "paid" WHERE id = ?')->execute([$purchaseAdvance]);
        return ['client' => $client, 'proforma' => $proforma, 'payment' => $payment['payment_id'],
            'invoice' => $this->makeInvoice($client, 'invoice', 'draft'), 'purchase_advance' => $purchaseAdvance,
            'purchase' => $this->purchaseInvoice('SYNTHETIC-FINAL', $client, 2000)];
    }

    private function makeInvoice(int $client, string $type, string $status, bool $gross = false): int
    {
        $pdo = $this->db->pdo();
        $vat = (int) $pdo->query("SELECT id FROM vat_rates WHERE country = 'CZ' AND rate_percent = 21 AND is_reverse_charge = 0 AND valid_to IS NULL ORDER BY id LIMIT 1")->fetchColumn();
        self::assertGreaterThan(0, $vat);
        $pdo->prepare('INSERT INTO invoices (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
            currency_id, prices_include_vat, total_without_vat, total_vat, total_with_vat, paid_total, status, created_by)
            VALUES (?, ?, ?, ?, "2099-06-10", "2099-06-10", "2099-06-24", ?, ?, 1000, 210, 1210, 0, ?, ?)')
            ->execute([$this->supplierId, (string) random_int(700000000, 799999999), $type, $client, $this->currencyId, $gross ? 1 : 0, $status, $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit, unit_price_without_vat,
            vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
            VALUES (?, "Syntetické plnění", 1, "ks", ?, ?, 21, 1000, 210, 1210, 0)')->execute([$id, $gross ? 1210 : 1000, $vat]);
        return $id;
    }

    private function childCount(int $id): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM invoices WHERE supplier_id = ? AND parent_invoice_id = ?');
        $stmt->execute([$this->supplierId, $id]);
        return (int) $stmt->fetchColumn();
    }

    private function invoke(string $class, string $method, int $id, array $body = [], ?int $payment = null, ?int $supplier = null, bool $force = false): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/invoices/' . $id)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplier ?? $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId])
            ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Syntetický klient', 'client', true,
                ['invoices' => 2, 'invoices.issue' => 2, 'invoices.mark_paid' => 2, 'purchase_invoices' => 2]))
            ->withQueryParams($force ? ['force' => 1] : [])->withParsedBody($body);
        return $this->container->get($class)->$method($request, new Response(), ['id' => $id, 'paymentId' => $payment]);
    }

    private function data(ResponseInterface $response, int $status = 200): array
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getBody());
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
