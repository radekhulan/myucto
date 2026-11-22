<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\SalesQuote;

use MyInvoice\Action\SalesQuote\QuoteAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Mail\RecipientResolver;
use MyInvoice\Service\Mail\SupplierEmailContext;
use MyInvoice\Service\SalesQuote\QuoteDelivery;
use MyInvoice\Service\SalesQuote\QuoteDeliveryRecorder;
use MyInvoice\Service\SalesQuote\QuoteException;
use MyInvoice\Service\SalesQuote\QuoteFileStorage;
use MyInvoice\Service\SalesQuote\QuoteInvoiceService;
use MyInvoice\Service\SalesQuote\QuoteMailer;
use MyInvoice\Service\SalesQuote\QuoteOrderService;
use MyInvoice\Service\SalesQuote\QuotePdfRenderer;
use MyInvoice\Service\SalesQuote\QuoteRepository;
use MyInvoice\Service\SalesQuote\QuoteWorkflow;
use MyInvoice\Service\Stock\SalesOrderInvoiceService;
use MyInvoice\Service\Stock\SalesOrderService;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class QuoteWorkflowTest extends StockTestCase
{
    protected function tearDown(): void
    {
        if (isset($this->db)) {
            foreach ($this->supplierIds as $sid) {
                $pdo = $this->db->pdo();
                $archives = $pdo->prepare('SELECT quote_id, pdf_key FROM sales_quote_events WHERE supplier_id = ? AND pdf_key IS NOT NULL');
                $archives->execute([$sid]);
                foreach ($archives->fetchAll(\PDO::FETCH_ASSOC) as $archive) {
                    $this->container->get(QuoteFileStorage::class)->delete($sid, (int) $archive['quote_id'], $archive['pdf_key']);
                }
                $pdo->prepare('DELETE FROM sales_quotes WHERE supplier_id = ?')->execute([$sid]);
                $pdo->prepare('DELETE FROM sales_orders WHERE supplier_id = ?')->execute([$sid]);
                $pdo->prepare('DELETE p FROM projects p JOIN clients c ON c.id = p.client_id WHERE c.supplier_id = ?')->execute([$sid]);
            }
        }
        parent::tearDown();
    }

    public function testSendFreezesExactPdfAndVersionAndAcceptsSelectedOptionalScope(): void
    {
        $sid = $this->createSupplier(stockEnabled: false);
        $action = $this->container->get(QuoteAction::class);
        $body = $this->body($sid);
        $body['project_id'] = $this->container->get(\MyInvoice\Repository\ProjectRepository::class)->create([
            'client_id' => $body['client_id'], 'name' => 'Syntetická zakázka', 'currency_id' => $body['currency_id'],
        ]);
        $created = $action->create($this->request($sid, $body), new Response());
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $initial = $this->json($created);
        $id = $initial['id'];
        $args = ['id' => $id];
        self::assertMatchesRegularExpression('/^NAB-\d{4}-0001$/', $initial['number']);
        self::assertSame(1000.0, (float) $initial['totals']['without_vat']);
        self::assertSame('Syntetická zakázka', $initial['project_name']);
        self::assertStringStartsWith('%PDF-', (string) $action->pdf($this->request($sid), new Response(), $args)->getBody());
        $foreign = $this->request($this->createSupplier());
        self::assertSame(404, $action->pdf($foreign, new Response(), $args)->getStatusCode());

        $transport = $this->createMock(Mailer::class);
        $transport->expects(self::exactly(2))->method('sendTemplateDetailed')->willReturn([
            'smtp_response' => 'synthetic accepted',
            'imap_append' => ['status' => 'skipped'],
        ]);
        $delivery = new QuoteDelivery(
            $this->db,
            $this->container->get(QuoteRepository::class),
            $this->container->get(QuoteFileStorage::class),
            $this->container->get(QuotePdfRenderer::class),
            new QuoteMailer($transport, $this->container->get(RecipientResolver::class), $this->container->get(SupplierEmailContext::class)),
            $this->container->get(QuoteDeliveryRecorder::class),
            new NullLogger(),
        );
        $message = ['to' => 'test@example.test', 'subject' => 'Cenová nabídka', 'message' => 'Zkušební doručení', 'idempotency_key' => 'send-1'];
        $delivery->send($sid, $id, $this->userId, $message);
        $delivery->send($sid, $id, $this->userId, $message);
        $sent = $this->json($action->get($this->request($sid), new Response(), $args));
        self::assertSame('sent', $sent['status']);
        self::assertSame('sent', $sent['history'][0]['delivery_status']);
        $archive = $sent['history'][0]['id'];
        $original = (string) $action->pdf($this->request($sid), new Response(), $args + ['eventId' => $archive])->getBody();
        self::assertStringStartsWith('%PDF-', $original);
        self::assertSame(409, $action->update($this->request($sid, $body + ['row_version' => $sent['row_version']]), new Response(), $args)->getStatusCode());

        $revised = $this->json($action->revise($this->request($sid), new Response(), $args));
        self::assertSame(2, $revised['revision']);
        self::assertSame('draft', $revised['status']);
        $delivery->send($sid, $id, $this->userId, array_replace($message, ['idempotency_key' => 'send-2']));
        $state = ['status' => 'accepted', 'optional_section_ids' => [$revised['sections'][1]['id']]];
        $accepted = $this->json($action->state($this->request($sid, $state), new Response(), $args));
        self::assertSame('accepted', $accepted['status']);
        self::assertSame(1500.0, (float) $accepted['totals']['without_vat']);
        self::assertSame($original, (string) $action->pdf($this->request($sid), new Response(), $args + ['eventId' => $archive])->getBody());
        $list = $this->json($action->list($this->request($sid)->withQueryParams(['status' => 'accepted']), new Response()));
        self::assertSame(1, $list['total']);
        self::assertSame('Syntetická zakázka', $list['items'][0]['project_name']);
        self::assertSame(1500.0, (float) $list['items'][0]['billing']['remaining']);
    }

    public function testQuoteOrderCarriesQuoteRowsToInvoice(): void
    {
        $sid = $this->createSupplier();
        $quoteWorkflow = $this->container->get(QuoteWorkflow::class);
        $orderConverter = $this->container->get(QuoteOrderService::class);
        $body = $this->body($sid);
        $id = $quoteWorkflow->create($sid, $this->userId, $body);
        $this->db->pdo()->prepare("UPDATE sales_quotes SET status = 'sent' WHERE supplier_id = ? AND id = ?")->execute([$sid, $id]);
        $quoteWorkflow->state($sid, $id, $this->userId, ['status' => 'accepted']);
        $orderId = $orderConverter->convert($sid, $id, $this->userId, ['idempotency_key' => 'first-order']);
        self::assertSame($orderId, $orderConverter->convert($sid, $id, $this->userId, ['idempotency_key' => 'first-order']));
        $invoiceConverter = $this->container->get(QuoteInvoiceService::class);
        try {
            $invoiceConverter->convert($sid, $this->userId, $id, ['idempotency_key' => 'same-scope', 'type' => 'invoice']);
            self::fail('Současně se zakládá doklad z objednávky.');
        } catch (QuoteException $e) {
            self::assertSame(409, $e->httpStatus);
        }
        $this->container->get(SalesOrderService::class)->confirm($sid, $orderId, 'confirm-order');
        $invoice = $this->container->get(SalesOrderInvoiceService::class)->createDraft($sid, $orderId, $this->userId, 'bill-order');
        $quote = $quoteWorkflow->detail($sid, $id, true);
        self::assertSame((int) $invoice['id'], $quote['documents'][0]['invoice_id']);
        self::assertSame(1000.0, $quote['billing']['draft']);
        self::assertSame(0.0, $quote['billing']['remaining']);
    }

    public function testForeignProjectDoesNotPassQuoteValidation(): void
    {
        $sid = $this->createSupplier();
        $foreign = $this->createSupplier();
        $body = $this->body($sid);
        $body['project_id'] = $this->container->get(\MyInvoice\Repository\ProjectRepository::class)->create([
            'client_id' => $this->client($foreign), 'name' => 'Cizí syntetická zakázka',
            'currency_id' => $this->currencyIdFor($foreign),
        ]);
        $action = $this->container->get(QuoteAction::class);
        $result = $action->create($this->request($sid, $body), new Response());
        self::assertSame(422, $result->getStatusCode());
        self::assertSame('quote_project', $this->json($result)['error']['code']);
    }

    private function body(int $sid): array
    {
        $row = [
            'kind' => 'work', 'description' => 'Příprava', 'quantity' => 2, 'unit' => 'h',
            'unit_price' => 500, 'vat_rate_id' => $this->vatRateId, 'vat_rate' => (float) $this->vatRatePercent,
        ];
        return [
            'title' => 'Nová nabídka', 'client_id' => $this->client($sid),
            'currency_id' => $this->currencyIdFor($sid), 'language' => 'cs', 'valid_until' => date('Y-m-d', strtotime('+5 days')),
            'sections' => [
                ['title' => 'Základ', 'kind' => 'required', 'markdown' => 'Popis **práce**', 'items' => [$row]],
                ['title' => 'Rozšíření', 'kind' => 'optional', 'items' => [array_replace($row, ['quantity' => 1])]],
            ],
        ];
    }

    private function request(int $sid, array $body = []): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/sales-quotes')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $sid)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId])
            ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Obchod', 'staff', true, [
                'sales_quotes' => 1, 'sales_quotes.write' => 2, 'sales_quotes.send' => 2, 'clients' => 1, 'projects' => 1, 'invoices' => 1,
            ], 'custom'))
            ->withParsedBody($body);
    }

    private function json(Response $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
