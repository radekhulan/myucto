<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\IssueFinalFromProformaAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\TestWith;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/** Neplatné datum nesmí dojít do tvorby dokladu a skončit databázovou chybou. */
final class IssueFinalFromProformaValidationTest extends BankPostingTestCase
{
    #[TestWith(['tax_date', '2099-02-31'])]
    #[TestWith(['due_date', '2099-04-31'])]
    #[TestWith(['due_date', 'neplatné datum'])]
    #[TestWith(['tax_date', ['2099-03-10']])]
    public function testInvalidDateReturns400WithoutCreatingFinal(string $field, mixed $value): void
    {
        $id = $this->proforma();
        $response = $this->invoke($id, [$field => $value]);
        self::assertSame(400, $response->getStatusCode(), (string) $response->getBody());
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('invalid_date', $payload['error']['code']);
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM invoices WHERE supplier_id = ? AND parent_invoice_id = ?');
        $stmt->execute([$this->supplierId, $id]);
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    #[TestWith(['2096-02-29', '2096-03-15'])]
    #[TestWith([null, ''])]
    public function testValidAndDefaultDatesStillCreateOneFinal(?string $taxDate, string $dueDate): void
    {
        $id = $this->proforma();
        $response = $this->invoke($id, ['tax_date' => $taxDate, 'due_date' => $dueDate]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $invoice = $payload['invoice'];
        self::assertSame('draft', $invoice['status']);
        self::assertSame($taxDate ?? date('Y-m-d'), $invoice['tax_date']);
        self::assertSame($dueDate === '' ? date('Y-m-d') : $dueDate, $invoice['due_date']);
        self::assertSame(1000.0, (float) $invoice['total_with_vat']);
        self::assertSame(0.0, (float) $invoice['amount_to_pay']);
    }

    private function proforma(): int
    {
        $clientId = $this->client('Syntetický odběratel validace finálu');
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO invoices
            (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
             currency_id, status, total_without_vat, total_with_vat, paid_total, created_by)
            VALUES (?, ?, "proforma", ?, "2099-03-01", "2099-03-01", "2099-03-15", ?, "paid", 1000, 1000, 1000, ?)')
            ->execute([$this->supplierId, (string) random_int(700000000, 799999999), $clientId, $this->currencyId, $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $vatId = (int) $pdo->query('SELECT id FROM vat_rates WHERE rate_percent = 0 AND is_reverse_charge = 0 ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $vatId);
        $pdo->prepare('INSERT INTO invoice_items
            (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
             vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
            VALUES (?, "Syntetické plnění", 1, "ks", 1000, ?, 0, 1000, 0, 1000, 0)')->execute([$id, $vatId]);
        return $id;
    }

    private function invoke(int $id, array $body): \Psr\Http\Message\ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', "/api/invoices/$id/issue-final")
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId])
            ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Syntetický klient', 'client', true, ['invoices.issue' => 2]))
            ->withParsedBody($body);
        return ($this->container->get(IssueFinalFromProformaAction::class))($request, new Response(), ['id' => $id]);
    }
}
