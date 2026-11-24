<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Admin\InvoicePaidWebhookAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Http\OutboundRequestException;
use MyInvoice\Service\Http\OutboundResponse;
use MyInvoice\Service\Http\OutboundUrlGuard;
use MyInvoice\Service\Integration\InvoicePaidWebhooks;
use MyInvoice\Service\Integration\InvoicePaidWebhookWorker;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class InvoicePaidWebhookTest extends StockTestCase
{
    private const URL = 'https://receiver.example.test/payments';

    public function testRegistrationRequiresAdminSessionAndProtectsOtherCompanies(): void
    {
        $sid = $this->createSupplier();
        $other = $this->createSupplier();
        $action = $this->container->get(InvoicePaidWebhookAction::class);
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/admin/invoice-webhooks')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $sid)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute('auth.effective_role', new EffectiveRole(1, 'Test', 'staff', true, [], 'admin'))
            ->withParsedBody(['url' => self::URL]);
        self::assertSame(403, $action($request->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer'), new Response(), [])->getStatusCode());
        self::assertSame(403, $action($request->withAttribute('auth.effective_role', EffectiveRole::denied()), new Response(), [])->getStatusCode());
        self::assertSame(422, $action($request->withParsedBody(['url' => 'http://receiver.example.test']), new Response(), [])->getStatusCode());

        $created = $action($request, new Response(), []);
        self::assertSame(201, $created->getStatusCode());
        $data = json_decode((string) $created->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $list = $action($request->withMethod('GET'), new Response(), []);
        $rows = json_decode((string) $list->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['id', 'url', 'include_advance'], array_keys($rows[0]));
        self::assertSame(404, $action($request->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $other)
            ->withMethod('DELETE'), new Response(), ['id' => $data['id']])->getStatusCode());
        $rotated = $action($request, new Response(), ['id' => $data['id']]);
        self::assertSame(200, $rotated->getStatusCode());
        self::assertNotSame($data['secret'], json_decode((string) $rotated->getBody(), true)['secret']);
        self::assertSame(200, $action($request->withMethod('DELETE'), new Response(), ['id' => $data['id']])->getStatusCode());
    }

    public function testPaymentTransitionAndTemplateScopeAreAtomic(): void
    {
        $sid = $this->createSupplier();
        $client = $this->client($sid);
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE supplier SET proforma_payment_document='manual' WHERE id=?")->execute([$sid]);
        $pdo->prepare("INSERT INTO recurring_invoice_templates (supplier_id,client_id,name,frequency,
            anchor_date,next_run_date,currency_id,created_by) VALUES (?,?,'Test','monthly',CURDATE(),CURDATE(),?,?)")
            ->execute([$sid, $client, $this->currencyIdFor($sid), $this->userId]);
        $template = (int) $pdo->lastInsertId();
        try {
            $hooks = $this->container->get(InvoicePaidWebhooks::class);
            $all = $hooks->register($sid, null, self::URL, false)['id'];
            $specific = $hooks->register($sid, $template, self::URL, true)['id'];
            $other = $this->createSupplier();
            $foreign = $hooks->register($other, null, self::URL, true)['id'];
            $id = $this->invoiceDraft($sid, $client, 'proforma');
            $pdo->prepare("UPDATE invoices SET status='issued', total_with_vat=100, recurring_template_id=? WHERE id=?")
                ->execute([$template, $id]);
            $payments = $this->container->get(InvoicePaymentService::class);
            $pdo->beginTransaction();
            $payments->recordPayment($id, 25.0, '2099-06-10');
            self::assertSame([], $hooks->history($sid, $all, PHP_INT_MAX));
            $payments->recordPayment($id, 75.0, '2099-06-11');
            self::assertCount(1, $hooks->history($sid, $all, PHP_INT_MAX));
            self::assertCount(1, $hooks->history($sid, $specific, PHP_INT_MAX));
            $pdo->rollBack();
            self::assertSame([], $hooks->history($sid, $specific, PHP_INT_MAX));
            $payments->recordPayment($id, 100.0, '2099-06-11');
            $payments->recompute($id);
            self::assertCount(1, $hooks->history($sid, $all, PHP_INT_MAX));
            $final = $this->invoiceDraft($sid, $client, 'invoice', ['parent_invoice_id' => $id]);
            $pdo->prepare("UPDATE invoices SET status='paid', total_with_vat=100, advance_paid_amount=100
                WHERE id=?")->execute([$final]);
            self::assertCount(1, $hooks->history($sid, $all, PHP_INT_MAX));
            self::assertCount(2, $hooks->history($sid, $specific, PHP_INT_MAX));
            self::assertSame([], $hooks->history($other, $foreign, PHP_INT_MAX));
            $plain = $this->invoiceDraft($sid, $client);
            $pdo->prepare("UPDATE invoices SET status='paid',total_with_vat=20 WHERE id=?")->execute([$plain]);
            self::assertCount(2, $hooks->history($sid, $specific, PHP_INT_MAX));
            self::assertCount(2, $hooks->history($sid, $all, PHP_INT_MAX));
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $pdo->prepare('DELETE FROM recurring_invoice_templates WHERE id=?')->execute([$template]);
        }
    }

    public function testOnly200SucceedsWithFiveRetriesAndStableSignedEvent(): void
    {
        $sid = $this->createSupplier();
        $hooks = $this->container->get(InvoicePaidWebhooks::class);
        $registration = $hooks->register($sid, null, self::URL, false);
        $id = $registration['id'];
        $key = base64_decode(substr($registration['secret'], 6), true);
        $invoice = $this->invoiceDraft($sid, $this->client($sid));
        $this->db->pdo()->prepare("UPDATE invoices SET total_with_vat=100, status='paid' WHERE id=?")->execute([$invoice]);
        $first = $hooks->history($sid, $id, PHP_INT_MAX)[0];
        $http = $this->createMock(OutboundUrlGuard::class);
        $codes = [201, 202, 204, 302, 500, null, 200];
        $bodies = [];
        $http->expects(self::exactly(7))->method('request')->willReturnCallback(
            function (string $method, string $url, array $headers, string $body) use ($key, $first, &$codes, &$bodies): OutboundResponse {
                self::assertFalse($this->db->pdo()->inTransaction());
                self::assertSame('POST', $method);
                self::assertSame(self::URL, $url);
                self::assertSame($first['event_id'], $headers['Webhook-Id']);
                $signed = $first['event_id'] . '.' . $headers['Webhook-Timestamp'] . '.' . $body;
                self::assertSame('v1,' . base64_encode(hash_hmac('sha256', $signed, $key, true)), $headers['Webhook-Signature']);
                $bodies[] = $body;
                $code = array_shift($codes);
                if ($code === null) throw new OutboundRequestException('Timeout');
                return new OutboundResponse($code, '', '');
            });
        $worker = new InvoicePaidWebhookWorker($this->db, $this->container->get(SecretEncryption::class), $http);
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            self::assertSame(1, $worker->run());
            $row = $hooks->history($sid, $id, PHP_INT_MAX)[0];
            self::assertSame($attempt, (int) $row['attempts']);
            self::assertSame($attempt === 6 ? 'failed' : 'pending', $row['status']);
        }
        self::assertSame(0, $worker->run());
        self::assertTrue($hooks->retry($sid, $id, $first['id']));
        self::assertSame(1, $worker->run());
        self::assertSame('delivered', $hooks->history($sid, $id, PHP_INT_MAX)[0]['status']);
        self::assertCount(1, array_unique($bodies));
    }
}
