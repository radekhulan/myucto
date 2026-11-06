<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Action\Property\Payments\PaymentPublicAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Property\Payments\PaymentOperationsReadRepository;
use MyInvoice\Repository\Property\Receivables\PartyEventRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Property\Receivables\PropertyReconciliation;
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use MyInvoice\Tests\Support\PaymentCorrectionFixture;
use MyInvoice\Tests\Support\PaymentReadCorruptionSandbox;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PaymentPublicApiTest extends TestCase
{
    use ChargeIssuanceFixture, PaymentCorrectionFixture, PaymentReadCorruptionSandbox;
    private Connection $db;
    private array $f;

    protected function setUp(): void
    {
        $this->db = $this->paymentReadDatabase(['unit_payment_operations_immutable_update']);
        $this->db->pdo()->beginTransaction(); $this->f = $this->issuanceFixture($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public function testActualSerializationSharesFinancialProjectionAndPreservesTenancyPagingAndCorruptProofFailure(): void
    {
        $base = $this->settledPaymentFixture('600', '400');
        $supplier = $this->f['supplier']; $unit = $base['charge']['unit_id'];
        $container = $this->paymentFixtureContainer(); $action = $container->get(PaymentPublicAction::class);
        $reader = $container->get(PaymentOperationsReadRepository::class);
        $http = (new ServerRequestFactory())->createServerRequest('GET', '/api/property/catalog/payments/card')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
            ->withAttribute('auth.effective_role', new EffectiveRole(1, 'Synthetic reader', 'staff', true, ['property' => 1]))
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplier);
        $now = PartyEventRepository::now(); $q = ['unit_id' => $unit, 'as_of' => $now, 'known_at' => $now];
        $response = $action->card($http->withQueryParams($q), new Response());
        self::assertSame(200, $response->getStatusCode());
        $card = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $original = $reader->card($supplier, $now, $now, ['unit_id' => $unit]);
        self::assertSame($original['totals'], $card['totals']); self::assertSame($original['coverage'], $card['coverage']);
        self::assertSame($original['snapshot_hash'], $card['source_snapshot_hash']);
        self::assertSame('400', $card['totals']['gross_credit_cents']); self::assertFalse($card['coverage']['complete']);
        self::assertNotEmpty($card['claims'][0]['groups']);
        self::assertArrayNotHasKey('events', $card['claims'][0]); self::assertArrayNotHasKey('current_party_heads', $card);
        self::assertArrayNotHasKey('evidence_ref', $card['claims'][0]['groups'][0]);
        self::assertArrayNotHasKey('legal_reason', $card['claims'][0]['groups'][0]);
        $firstResponse = $action->operations($http->withQueryParams(['unit_id' => $unit, 'limit' => 1]), new Response());
        self::assertSame(200, $firstResponse->getStatusCode());
        $first = json_decode((string) $firstResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $first['items']); self::assertNotNull($first['next_cursor']);
        $second = json_decode((string) $action->operations($http->withQueryParams(['unit_id' => $unit, 'after_id' => $first['next_cursor'], 'limit' => 1]), new Response())->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertGreaterThan($first['items'][0]['id'], $second['items'][0]['id']);
        self::assertArrayNotHasKey('request', $first['items'][0]); self::assertSame('600', $first['items'][0]['amount_cents']);
        self::assertSame('Synthetic payment', $first['items'][0]['reason']);
        $reconciliation = $action->reconciliation($http->withQueryParams(['unit_id' => $unit, 'date' => '2026-02-02']), new Response());
        self::assertSame(200, $reconciliation->getStatusCode());
        $actual = json_decode((string) $reconciliation->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $expectedReconciliation = (new PropertyReconciliation($this->db))->at($supplier, '2026-02-02', $unit);
        unset($expectedReconciliation['opening']['states']);
        self::assertSame($expectedReconciliation, $actual);
        self::assertArrayNotHasKey('states', $actual['opening']);
        self::assertSame('current_ledger', $actual['knowledge_basis']);
        $foreignSupplier = $this->createIsolatedSupplier($this->db->pdo(), $supplier);
        $foreign = $http->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $foreignSupplier);
        self::assertSame(404, $action->card($foreign->withQueryParams($q), new Response())->getStatusCode());
        self::assertSame(404, $action->operations($foreign->withQueryParams(['unit_id' => $unit]), new Response())->getStatusCode());
        self::assertSame([], json_decode((string) $action->operations($foreign, new Response())->getBody(), true)['items']);
        self::assertSame([], json_decode((string) $action->history($foreign, new Response())->getBody(), true)['items']);
        $this->db->pdo()->prepare("UPDATE unit_payment_operations SET snapshot_hash=? WHERE supplier_id=? AND id=?")
            ->execute([str_repeat('0', 64), $supplier, $base['operation']['id']]);
        self::assertSame(409, $action->operations($http, new Response())->getStatusCode());
        self::assertSame(409, $action->card($http->withQueryParams($q), new Response())->getStatusCode());
        self::assertSame(404, $action->card($foreign->withQueryParams($q), new Response())->getStatusCode());
    }
}
