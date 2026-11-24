<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Webhook;

use MyInvoice\Action\Admin\WebhookAction;
use MyInvoice\Action\Invoice\PublicLinkAction;
use MyInvoice\Infrastructure\Cache\EntityCache;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Http\OutboundResponse;
use MyInvoice\Service\Http\OutboundUrlGuard;
use MyInvoice\Service\Integration\InvoiceWebhookEvents;
use MyInvoice\Service\Integration\WebhookDeliveryWorker;
use MyInvoice\Service\Integration\WebhookEndpoints;
use MyInvoice\Service\Integration\WebhookEvents;
use MyInvoice\Service\Integration\WebhookPolicy;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\License\LicenseClient;
use MyInvoice\Service\License\LicenseConsistency;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\License\LicenseTokenVerifier;
use MyInvoice\Tests\Integration\Stock\StockTestCase;
use MyInvoice\Tests\Support\LicenseTokenTrait;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class OutgoingWebhooksTest extends StockTestCase
{
    use LicenseTokenTrait;

    private const URL = 'https://receiver.example.test/myucto';

    public function testInvoicePaymentsRollBackAndRespectTemplatesAndCompany(): void
    {
        $supplierId = $this->createSupplier();
        $otherSupplierId = $this->createSupplier();
        $clientId = $this->client($supplierId);
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE supplier SET proforma_payment_document = 'manual' WHERE id = ?")->execute([$supplierId]);
        $pdo->prepare(
            "INSERT INTO recurring_invoice_templates (supplier_id,client_id,name,frequency,anchor_date,next_run_date,currency_id,created_by)
             VALUES (?,?,'Test','monthly','2099-06-01','2099-07-01',?,?)"
        )->execute([$supplierId, $clientId, $this->currencyIdFor($supplierId), $this->userId]);
        $templateId = (int) $pdo->lastInsertId();

        $events = [InvoiceWebhookEvents::PAID, InvoiceWebhookEvents::PAYMENT_REVERSED];
        $all = $this->register($supplierId, $events);
        $specific = $this->register($supplierId, $events, $templateId, true);
        $foreign = $this->register($otherSupplierId, $events);
        $invoiceId = $this->invoiceDraft($supplierId, $clientId, 'proforma');
        $pdo->prepare("UPDATE invoices SET status = 'issued', total_with_vat = 100, recurring_template_id = ? WHERE id = ?")
            ->execute([$templateId, $invoiceId]);

        $payments = $this->container->get(InvoicePaymentService::class);
        $pdo->beginTransaction();
        $payments->recordPayment($invoiceId, 25.0, '2099-06-12');
        self::assertSame([], $this->events($all['id']));
        $payments->recordPayment($invoiceId, 75.0, '2099-06-12');
        self::assertCount(1, $this->events($specific['id']));
        $pdo->rollBack();
        self::assertSame([], $this->events($all['id']));

        $payment = $payments->recordPayment($invoiceId, 100.0, '2099-06-12');
        $payments->recompute($invoiceId);
        $payments->deletePayment($payment['payment_id']);
        $pdo->beginTransaction();
        $final = $this->invoiceDraft($supplierId, $clientId, 'invoice', ['parent_invoice_id' => $invoiceId]);
        InvoiceWebhookEvents::publish($pdo, $final, InvoiceWebhookEvents::PAID, InvoiceWebhookEvents::ADVANCE);
        $pdo->commit();

        $allEvents = $this->events($all['id']);
        $specificEvents = $this->events($specific['id']);
        self::assertSame($events, array_column($allEvents, 'type'));
        self::assertSame([...$events, InvoiceWebhookEvents::PAID], array_column($specificEvents, 'type'));
        self::assertSame($allEvents[0]['event_id'], $specificEvents[0]['event_id']);
        self::assertSame($templateId, $specificEvents[2]['recurring_template_id']);
        self::assertSame($final, $specificEvents[2]['invoice_id']);
        self::assertSame(InvoiceWebhookEvents::ADVANCE, $specificEvents[2]['reason']);
        self::assertSame([], $this->events($foreign['id']));
        $pdo->prepare('DELETE FROM recurring_invoice_templates WHERE supplier_id = ? AND id = ?')->execute([$supplierId, $templateId]);
    }

    public function testExplicitPublicationEmitsOnlyOnceAndOmitsTheShareToken(): void
    {
        $supplierId = $this->createSupplier();
        $invoiceId = $this->invoiceDraft($supplierId, $this->client($supplierId));
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([$invoiceId]);
        $endpoint = $this->register($supplierId, [InvoiceWebhookEvents::PUBLISHED]);
        $request = $this->request($supplierId, 'POST');
        $action = $this->container->get(PublicLinkAction::class);
        $args = ['id' => $invoiceId];

        $initial = $this->json($action->ensure($request, new Response(), $args));
        self::assertNull($initial['published_at']);
        self::assertSame([], $this->events($endpoint['id']));

        $published = $this->json($action->publish($request, new Response(), $args));
        self::assertSame($initial['token'], $published['token']);
        self::assertNotEmpty($published['published_at']);
        self::assertSame($published, $this->json($action->publish($request, new Response(), $args)));
        $regenerated = $this->json($action->regenerate($request, new Response(), $args));
        self::assertNotSame($published['token'], $regenerated['token']);
        self::assertSame($published['published_at'], $regenerated['published_at']);
        $events = $this->events($endpoint['id']);
        self::assertCount(1, $events);
        self::assertSame($invoiceId, $events[0]['invoice_id']);
        self::assertArrayNotHasKey('token', $events[0]);
        self::assertArrayNotHasKey('url', $events[0]);
    }

    public function testStockPostAndReverseAreSingleEvents(): void
    {
        $supplierId = $this->createSupplier();
        $warehouseId = $this->warehouse($supplierId);
        $itemId = $this->item($supplierId, 'WEBHOOK-STOCK');
        $endpoint = $this->register($supplierId, [WebhookEvents::STOCK_POSTED, WebhookEvents::STOCK_REVERSED]);
        $receipt = $this->receiveStock($supplierId, $warehouseId, $itemId, '3.000', 15.0);
        $receiptId = (int) $receipt['id'];
        $this->documents->post($supplierId, $receiptId, $this->userId);
        $result = $this->documents->reverse($supplierId, $receiptId, [], $this->userId);
        $events = $this->events($endpoint['id']);

        self::assertSame([WebhookEvents::STOCK_POSTED, WebhookEvents::STOCK_REVERSED], array_column($events, 'type'));
        self::assertSame([$receiptId, $receiptId], array_column($events, 'stock_document_id'));
        self::assertSame((int) $result['reversal']['id'], $events[1]['reversal_stock_document_id']);
    }

    public function testManagementAndWorkerRequireVerifiedPaidLicenseAndRespectLockdown(): void
    {
        $supplierId = $this->createSupplier();
        $otherSupplierId = $this->createSupplier();
        $pdo = $this->db->pdo();
        $saved = $pdo->query('SELECT * FROM license WHERE id = 1')->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($saved);
        $config = new Config(['license' => ['public_key' => $this->licensePublicKeyBase64()]]);
        $policy = $this->policy($config);
        $endpoints = $this->container->get(WebhookEndpoints::class);
        $audit = $this->container->get(ActivityLogger::class);
        $action = new WebhookAction($this->db, $endpoints, $policy, $audit);
        $request = $this->request($supplierId, 'POST');
        $input = ['name' => 'Test', 'url' => self::URL, 'event_types' => [InvoiceWebhookEvents::PAID]];
        try {
            $pdo->exec("UPDATE license SET license_key = NULL, token = NULL, token_payload = NULL, trial_started_at = NOW() WHERE id = 1");
            self::assertFalse($this->json($action($request->withMethod('GET'), new Response(), []))['available']);
            self::assertSame(403, $action($request->withParsedBody($input), new Response(), [])->getStatusCode());
            $this->activateFixture($saved['instance_id'], false);
            self::assertFalse($this->policy($config)->licensed());
            $this->activateFixture($saved['instance_id'], true);
            $policy = $this->policy($config);
            $action = new WebhookAction($this->db, $endpoints, $policy, $audit);
            self::assertTrue($policy->licensed());
            self::assertSame(403, $action($request->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer'), new Response(), [])->getStatusCode());
            $created = $action($request->withParsedBody($input), new Response(), []);
            self::assertSame(201, $created->getStatusCode());
            $endpoint = $this->json($created);
            self::assertArrayNotHasKey('secret', $endpoints->list($supplierId)[0]);
            self::assertSame(404, $action($this->request($otherSupplierId, 'GET'), new Response(), ['id' => $endpoint['id']])->getStatusCode());

            $test = $action($request, new Response(), ['id' => $endpoint['id'], 'operation' => 'test']);
            self::assertSame(202, $test->getStatusCode());
            self::assertSame(429, $action($request, new Response(), ['id' => $endpoint['id'], 'operation' => 'test'])->getStatusCode());
            $eventId = $this->json($test)['event_id'];
            self::assertSame(true, $this->events($endpoint['id'])[0]['test']);
            $http = $this->createMock(OutboundUrlGuard::class);
            $codes = [503, 204];
            $key = base64_decode(substr($endpoint['secret'], 6), true);
            $http->expects(self::exactly(2))->method('request')->willReturnCallback(
                static function (string $method, string $url, array $headers, string $body) use ($key, $eventId, &$codes): OutboundResponse {
                    self::assertSame(['POST', self::URL, $eventId], [$method, $url, $headers['Webhook-Id']]);
                    $expected = base64_encode(hash_hmac('sha256', $eventId . '.' . $headers['Webhook-Timestamp'] . '.' . $body, $key, true));
                    self::assertSame('v1,' . $expected, $headers['Webhook-Signature']);
                    return new OutboundResponse(array_shift($codes), '', '');
                },
            );
            $encryption = $this->container->get(SecretEncryption::class);
            $this->activateFixture($saved['instance_id'], false);
            self::assertSame(0, (new WebhookDeliveryWorker($this->db, $this->policy($config), $encryption, $http))->run());
            $this->activateFixture($saved['instance_id'], true);
            $worker = new WebhookDeliveryWorker($this->db, $this->policy($config), $encryption, $http);

            $disabledPolicy = $this->policy(new Config([
                'license' => ['public_key' => $this->licensePublicKeyBase64()],
                'webhooks' => ['mode' => 'disabled'],
            ]));
            self::assertSame(0, (new WebhookDeliveryWorker($this->db, $disabledPolicy, $encryption, $http))->run());
            self::assertSame(1, $worker->run());
            self::assertSame(0, $worker->run());
            $pdo->prepare('UPDATE webhook_deliveries SET available_at = NOW(6) WHERE event_id = ?')->execute([$eventId]);
            self::assertSame(1, $worker->run());
            $history = $endpoints->history($supplierId, $endpoint['id'], PHP_INT_MAX);
            self::assertSame(['delivered', 2, 204], [$history[0]['status'], $history[0]['attempts'], $history[0]['http_status']]);
        } finally {
            $pdo->prepare('UPDATE license SET license_key = ?, token = ?, token_payload = ?, trial_started_at = ?, fingerprint = ? WHERE id = 1')
                ->execute([$saved['license_key'], $saved['token'], $saved['token_payload'], $saved['trial_started_at'], $saved['fingerprint']]);
        }
    }

    private function policy(Config $config): WebhookPolicy
    {
        $consistency = new LicenseConsistency($this->db, $config, EntityCache::disabled(), new NullLogger());
        $license = new LicenseService($this->db, $config, new LicenseTokenVerifier(), $this->createStub(LicenseClient::class),
            consistency: $consistency);
        return new WebhookPolicy($config, $license, $consistency);
    }

    private function activateFixture(string $instanceId, bool $commercial): void
    {
        $token = $this->signLicenseToken(['lic' => 1, 'iid' => $instanceId, 'tier' => 'single', 'users' => 3,
            'valid_until' => time() + 86400, 'status' => 'ok', 'nonce' => 'test', 'commercial' => $commercial]);
        $this->db->pdo()->prepare("UPDATE license SET license_key = 'MYU-TEST-0001-AAAA', token = ?, token_payload = NULL WHERE id = 1")
            ->execute([$token]);
    }

    private function register(int $supplierId, array $events, ?int $templateId = null, bool $includeAdvance = false): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $result = $this->container->get(WebhookEndpoints::class)->create($supplierId, [
            'name' => 'Test', 'url' => self::URL, 'event_types' => $events,
            'template_id' => $templateId, 'include_advance' => $includeAdvance,
        ]);
        $pdo->commit();
        return $result;
    }

    private function request(int $supplierId, string $method): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, '/api/admin/webhooks')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute('auth.effective_role', new EffectiveRole(1, 'Admin', 'staff', true, [], 'admin'));
    }

    private function events(int $endpointId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT body_json FROM webhook_deliveries WHERE endpoint_id = ? ORDER BY id');
        $stmt->execute([$endpointId]);
        return array_map(static fn (string $json): array => json_decode($json, true, flags: JSON_THROW_ON_ERROR), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function json(Response $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
