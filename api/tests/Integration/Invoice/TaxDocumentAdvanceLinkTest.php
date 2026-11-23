<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\{GetInvoiceAction, LinkAdvanceAction, TaxDocumentAdvanceLinkAction};
use MyInvoice\Middleware\{AuthMiddleware, SupplierScopeMiddleware};
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Invoice\{AdvancePartialDrawdown, FinalFromProformaCreator};
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\TestWith;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class TaxDocumentAdvanceLinkTest extends BankPostingTestCase
{
    use IsolatedSupplierTrait;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clientId = $this->client('Syntetický odběratel DDKP');
    }

    public function testLinksUniquePaymentAndLogsBothDocumentsThenUnlinks(): void
    {
        $advance = $this->invoice('proforma');
        $td = $this->invoice('tax_document');
        $payment = $this->payment($advance, 1210);
        $repo = $this->container->get(InvoiceRepository::class);
        $before = $repo->find($td);

        $linked = $this->call('link', $td, ['advance_id' => $advance]);
        self::assertSame($payment, $linked['payment_id']);
        $after = $repo->find($td);
        self::assertSame($advance, (int) $after['parent_invoice_id']);
        foreach (['totals', 'items', 'booked_at', 'status', 'tax_date', 'paid_total'] as $field) {
            self::assertSame($before[$field], $after[$field], $field);
        }
        self::assertSame($td, $this->paymentTaxDocument($payment));
        self::assertSame([$td], array_column($this->detail($advance)['advance_tax_documents'], 'id'));
        self::assertTrue($this->detail($td)['can_unlink_tax_document_advance']);
        $this->assertActivity('invoice.tax_document_advance_linked', [$advance, $td]);

        $this->call('unlink', $td);
        self::assertNull($repo->find($td)['parent_invoice_id']);
        self::assertNull($this->paymentTaxDocument($payment));
        self::assertSame([], $this->detail($advance)['advance_tax_documents']);
        $this->assertActivity('invoice.tax_document_advance_unlinked', [$advance, $td]);
    }

    public function testAmbiguousPaymentIsLeftUnlinkedAndCannotExceedCapacity(): void
    {
        $advance = $this->invoice('proforma');
        $td = $this->invoice('tax_document', 700);
        $first = $this->payment($advance, 700);
        $second = $this->payment($advance, 700);
        $this->payment($advance, 511, $td);
        $other = $this->invoice('proforma');
        $this->call('link', $td, ['advance_id' => $other], 409);
        self::assertSame([$advance], array_column($this->call('candidates', $td)['candidates'], 'id'));

        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE invoice_payments SET tax_document_invoice_id = NULL WHERE supplier_id = ? AND tax_document_invoice_id = ?')
            ->execute([$this->supplierId, $td]);
        self::assertNull($this->call('link', $td, ['advance_id' => $advance])['payment_id']);
        self::assertNull($this->paymentTaxDocument($first));
        self::assertNull($this->paymentTaxDocument($second));

        $within = $this->invoice('tax_document', 511);
        $over = $this->invoice('tax_document', 511.01);
        self::assertNotContains($over, array_column($this->call('candidates', $advance)['candidates'], 'id'));
        $this->call('link', $over, ['advance_id' => $advance], 409);
        $this->call('link', $within, ['advance_id' => $advance]);
        $this->call('link', $within, ['advance_id' => $other], 409);
    }

    public function testCandidatesInBothDirectionsAndIneligibleDocuments(): void
    {
        $advance = $this->invoice('proforma');
        $td = $this->invoice('tax_document');
        $foreign = $this->createIsolatedSupplier($this->db->pdo(), $this->supplierId);
        $fx = $this->currencyRow($this->supplierId, 'EUR');
        $otherClient = $this->client('Jiný syntetický odběratel');
        $bad = [
            [$this->invoice('proforma', supplier: $foreign), $td, 404],
            [$advance, $this->invoice('tax_document', supplier: $foreign), 404],
            [$this->invoice('proforma', client: $otherClient), $td, 409],
            [$advance, $this->invoice('tax_document', client: $otherClient), 409],
            [$this->invoice('proforma', currency: $fx), $td, 409],
            [$advance, $this->invoice('tax_document', currency: $fx), 409],
            [$this->invoice('proforma', status: 'cancelled'), $td, 409],
            [$advance, $this->invoice('tax_document', status: 'cancelled'), 409],
            [$advance, $this->invoice('tax_document', status: 'draft'), 409],
            [$advance, $this->invoice('invoice'), 409],
        ];
        self::assertSame([$td], array_column($this->call('candidates', $advance)['candidates'], 'id'));
        self::assertSame([$advance], array_column($this->call('candidates', $td)['candidates'], 'id'));
        foreach ($bad as [$proforma, $taxDoc, $status]) {
            $this->call('link', $taxDoc, ['advance_id' => $proforma], $status);
        }
        $this->call('link', $td, ['advance_id' => [1]], 400);
        $this->call('link', $td, ['advance_id' => true], 400);
        $this->call('candidates', $bad[0][0], status: 404);
    }

    public function testPaymentAndParentLinksCountEachTaxDocumentOnlyOnce(): void
    {
        $advance = $this->invoice('proforma');
        $td = $this->invoice('tax_document', 700);
        $payment = $this->payment($advance, 700, $td);
        $tooLarge = $this->invoice('tax_document', 511.01);
        $remainder = $this->invoice('tax_document', 511);

        $this->call('link', $tooLarge, ['advance_id' => $advance], 409);
        self::assertSame($payment, $this->call('link', $td, ['advance_id' => $advance])['payment_id']);
        self::assertSame([$td], array_column($this->detail($advance)['advance_tax_documents'], 'id'));
        self::assertSame([$remainder], array_column($this->call('candidates', $advance)['candidates'], 'id'));
        $this->call('link', $remainder, ['advance_id' => $advance]);
    }

    public function testParentOnlyLinkPreventsManualFinalPairing(): void
    {
        $advance = $this->invoice('proforma', status: 'paid');
        $td = $this->invoice('tax_document');
        $draft = $this->invoice('invoice', status: 'draft');
        self::assertNull($this->call('link', $td, ['advance_id' => $advance])['payment_id']);
        $request = $this->request('POST', $draft, ['advance_id' => $advance]);
        $response = ($this->container->get(LinkAdvanceAction::class))($request, new Response(), ['id' => $draft]);
        $error = $this->data($response, 409);
        self::assertStringContainsString('ruční propojení by daň zdvojilo', $error['error']['message']);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testParentOnlyLinkSuppliesVatForFinalAndDrawdown(bool $existingDraft): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")->execute([$this->supplierId]);
        $advance = $this->invoice('proforma', status: 'paid');
        $td = $this->invoice('tax_document');
        $this->addVatItem($advance);
        $this->addVatItem($td);
        self::assertNull($this->call('link', $td, ['advance_id' => $advance])['payment_id']);

        if ($existingDraft) {
            $finalId = $this->invoice('invoice', status: 'draft');
            $this->addVatItem($finalId);
            $drawdown = $this->container->get(AdvancePartialDrawdown::class);
            self::assertSame([$advance], array_column($drawdown->candidates($this->supplierId, $finalId), 'id'));
            $drawdown->apply($this->supplierId, $finalId, $advance, 1210);
        } else {
            $finalId = $this->container->get(FinalFromProformaCreator::class)->create(
                $advance, $this->userId, '2099-07-01', '2099-07-15',
            );
        }
        $final = $this->container->get(InvoiceRepository::class)->find($finalId);
        self::assertSame(0.0, (float) $final['total_vat']);
        self::assertSame(0.0, (float) $final['amount_to_pay']);
        $negativeVat = array_filter($final['items'], static fn (array $item): bool => (float) $item['total_vat'] < 0);
        self::assertSame([-210.0], array_values(array_map(static fn (array $item): float => (float) $item['total_vat'], $negativeVat)));
        self::assertFalse($this->detail($td)['can_unlink_tax_document_advance']);
        $error = $this->call('unlink', $td, status: 409);
        self::assertStringContainsString('zálohu už čerpá konečná faktura', $error['error']['message']);
    }

    #[TestWith(['row'])]
    #[TestWith(['saldo'])]
    public function testUnlinkRejectsDrawWithoutParentOnFinal(string $source): void
    {
        $advance = $this->invoice('proforma');
        $td = $this->invoice('tax_document');
        $final = $this->invoice('invoice');
        $this->call('link', $td, ['advance_id' => $advance]);
        if ($source === 'saldo') {
            $this->db->pdo()->prepare('INSERT INTO invoice_advance_deductions
                (supplier_id, final_invoice_id, advance_invoice_id, amount) VALUES (?, ?, ?, 1210)')
                ->execute([$this->supplierId, $final, $advance]);
        } else {
            $this->addVatItem($final, $advance);
        }
        self::assertFalse($this->detail($td)['can_unlink_tax_document_advance']);
        $this->call('unlink', $td, status: 409);
        self::assertSame($advance, $this->detail($td)['parent_invoice_id']);
    }

    public function testLinkIsAllowedAndReportsFiledVatAndClosedAccountingPeriod(): void
    {
        $advance = $this->invoice('proforma');
        $td = $this->invoice('tax_document');
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE accounting_periods SET status = "closed" WHERE id = ?')->execute([$this->periodId]);
        $pdo->prepare('INSERT INTO tax_submissions
            (supplier_id, form_code, period_year, period_month, status, xml_content, xml_sha256, xml_size_bytes)
            VALUES (?, "dphdp3", 2099, 6, "submitted", ?, ?, ?)')
            ->execute([$this->supplierId, '<test/>', hash('sha256', '<test/>'), strlen('<test/>')]);

        $result = $this->call('link', $td, ['advance_id' => $advance]);
        self::assertSame('přiznání k DPH za 6/2099', $result['filed_vat_period']);
        self::assertTrue($result['accounting_period_locked']);
    }

    private function invoice(string $type, float $gross = 1210, string $status = 'issued', ?int $client = null, ?int $currency = null, ?int $supplier = null): int
    {
        $pdo = $this->db->pdo();
        $base = round($gross / 1.21, 2);
        $pdo->prepare('INSERT INTO invoices
            (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
             currency_id, status, total_without_vat, total_vat, total_with_vat, created_by)
            VALUES (?, ?, ?, ?, "2099-06-10", "2099-06-10", "2099-06-24", ?, ?, ?, ?, ?, ?)')
            ->execute([$supplier ?? $this->supplierId, bin2hex(random_bytes(8)), $type, $client ?? $this->clientId,
                $currency ?? $this->currencyId, $status, $base, round($gross - $base, 2), $gross, $this->userId]);
        return (int) $pdo->lastInsertId();
    }

    private function addVatItem(int $id, ?int $advanceId = null): void
    {
        $pdo = $this->db->pdo();
        $vat = (int) $pdo->query("SELECT id FROM vat_rates WHERE country = 'CZ' AND rate_percent = 21 AND is_reverse_charge = 0 AND valid_to IS NULL ORDER BY id LIMIT 1")->fetchColumn();
        self::assertGreaterThan(0, $vat);
        $sign = $advanceId === null ? 1 : -1;
        $pdo->prepare('INSERT INTO invoice_items
            (invoice_id, advance_invoice_id, description, quantity, unit, unit_price_without_vat,
             vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat)
            VALUES (?, ?, "Syntetická položka", 1, "ks", ?, ?, 21, ?, ?, ?)')
            ->execute([$id, $advanceId, $sign * 1000, $vat, $sign * 1000, $sign * 210, $sign * 1210]);
    }

    private function payment(int $advance, float $amount, ?int $td = null): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO invoice_payments (supplier_id, invoice_id, amount, currency, paid_on, tax_document_invoice_id)
            VALUES (?, ?, ?, "CZK", "2099-06-10", ?)')->execute([$this->supplierId, $advance, $amount, $td]);
        return (int) $pdo->lastInsertId();
    }

    private function paymentTaxDocument(int $payment): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT tax_document_invoice_id FROM invoice_payments WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$this->supplierId, $payment]);
        return (int) $stmt->fetchColumn() ?: null;
    }

    private function assertActivity(string $action, array $ids): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT entity_id FROM activity_log
            WHERE supplier_id = ? AND action = ? AND entity_type = "invoice" AND entity_id IN (?, ?) ORDER BY entity_id');
        $stmt->execute([$this->supplierId, $action, ...$ids]);
        self::assertSame($ids, array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    private function request(string $method, int $id, array $body = []): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, "/api/invoices/$id")
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId])
            ->withParsedBody($body);
    }

    private function call(string $action, int $id, array $body = [], int $status = 200): array
    {
        $method = ['candidates' => 'GET', 'link' => 'POST', 'unlink' => 'DELETE'][$action];
        $response = $this->container->get(TaxDocumentAdvanceLinkAction::class)
            ->$action($this->request($method, $id, $body), new Response(), ['id' => $id]);
        return $this->data($response, $status);
    }

    private function detail(int $id): array
    {
        $response = ($this->container->get(GetInvoiceAction::class))($this->request('GET', $id), new Response(), ['id' => $id]);
        return $this->data($response, 200);
    }

    private function data(ResponseInterface $response, int $status): array
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getBody());
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
