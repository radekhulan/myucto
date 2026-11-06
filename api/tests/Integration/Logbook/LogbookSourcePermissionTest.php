<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Logbook;

use MyInvoice\Action\Logbook\FuelingsAction;
use MyInvoice\Action\Logbook\FuelInvoicesAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\FuelingRepository;
use MyInvoice\Security\EffectiveRole;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class LogbookSourcePermissionTest extends TestCase
{
    use LogbookFixtures;

    protected function setUp(): void { $this->bootLogbook(); }
    protected function tearDown(): void { $this->shutdownLogbook(); }

    public function testPurchaseInvoiceEndpointsRequireSeparateReadGrant(): void
    {
        $action = $this->container->get(FuelInvoicesAction::class);
        $request = $this->request();
        foreach (['list', 'items', 'assign', 'backfill'] as $method) {
            self::assertSame(403, $action->$method($request, new Response(), ['id' => 1])->getStatusCode(), $method);
        }
        $allowed = $action->list($this->request(['purchase_invoices' => 1]), new Response());
        self::assertSame(200, $allowed->getStatusCode());
        self::assertSame(403, $action->list($request, new Response())->getStatusCode(), 'Odebrání práva se projeví hned.');
    }

    public function testCandidatesAndWritesRequireMatchingSourcePermission(): void
    {
        $action = $this->container->get(FuelingsAction::class);
        $body = ['fueled_date' => '2099-03-05', 'amount_with_vat' => 1000];
        foreach (['cash_document' => 'cash', 'bank_transaction' => 'bank', 'journal_entry' => 'accounting'] as $type => $permission) {
            $query = ['type' => $type, 'date' => '2099-03-05'];
            self::assertSame(403, $action->linkCandidates($this->request()->withQueryParams($query), new Response())->getStatusCode());
            self::assertSame(200, $action->linkCandidates($this->request([$permission => 1], $permission === 'bank' ? 'client' : 'staff')->withQueryParams($query), new Response())->getStatusCode());
        }
        foreach (['source_cash_document_id', 'source_bank_transaction_id', 'source_journal_entry_id', 'source_purchase_invoice_id', 'source_item_id'] as $key) {
            self::assertSame(403, $action->create($this->request()->withParsedBody($body + [$key => 1]), new Response())->getStatusCode(), $key);
        }
        self::assertSame(0, $this->fuelingCount($this->supplierA));
    }

    /**
     * `cash` a `accounting` jsou v katalogu práv jen pro zaměstnanecké role. Klientská role,
     * která je má uložené, je přes RequestAuthorization::allows nedostane, ani u kandidátů
     * zdroje knihy jízd.
     */
    public function testClientRoleWithStaffOnlyGrantIsRejected(): void
    {
        $action = $this->container->get(FuelingsAction::class);
        foreach (['cash_document' => 'cash', 'journal_entry' => 'accounting'] as $type => $permission) {
            $query = ['type' => $type, 'date' => '2099-03-05'];
            $client = $this->request([$permission => 1], 'client')->withQueryParams($query);
            self::assertSame(403, $action->linkCandidates($client, new Response())->getStatusCode(), $permission);
            $staff = $this->request([$permission => 1], 'staff')->withQueryParams($query);
            self::assertSame(200, $action->linkCandidates($staff, new Response())->getStatusCode(), $permission);
        }
    }

    public function testGrantedCashLinkStillRequiresOwnTenantAndRevocationPreservesStoredLink(): void
    {
        $own = $this->cashDocument($this->supplierA, 'Syntetická účtenka', 1000);
        $foreign = $this->cashDocument($this->supplierB, 'Cizí syntetická účtenka', 1000);
        $action = $this->container->get(FuelingsAction::class);
        $body = ['fueled_date' => '2099-03-05', 'amount_with_vat' => 1000];
        $allowed = $this->request(['cash' => 1], 'staff');
        self::assertSame(400, $action->create($allowed->withParsedBody($body + ['source_cash_document_id' => $foreign]), new Response())->getStatusCode());
        $created = $action->create($allowed->withParsedBody($body + ['source_cash_document_id' => $own]), new Response());
        self::assertSame(201, $created->getStatusCode());
        $id = $this->json($created)['id'];
        self::assertSame($own, $this->json($created)['source_cash_document_id']);

        foreach ([$action->get($this->request(), new Response(), ['id' => $id]), $action->update($this->request()->withParsedBody(['note' => 'Nová poznámka']), new Response(), ['id' => $id])] as $response) {
            self::assertSame(200, $response->getStatusCode());
            self::assertNull($this->json($response)['source_cash_document_id']);
            self::assertNull($this->json($response)['source_cash_document_number']);
        }
        $stored = $this->container->get(FuelingRepository::class)->find($id, $this->supplierA);
        self::assertSame($own, $stored['source_cash_document_id']);
        self::assertSame('Nová poznámka', $stored['note']);
        self::assertSame(403, $action->update($this->request()->withParsedBody(['source_cash_document_id' => null]), new Response(), ['id' => $id])->getStatusCode());
        self::assertSame(404, $action->get($allowed->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierB), new Response(), ['id' => $id])->getStatusCode());
        $list = $this->json($action->list($this->request(), new Response()));
        self::assertNull($list['data'][0]['source_cash_document_number']);
    }

    private function request(array $extra = [], string $roleType = 'client'): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/api/logbook/fuelings')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierA)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $roleType === 'client' ? 'client' : 'accountant'])
            ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Delegovaná role', $roleType, true,
                $extra + ['logbook' => 1, 'logbook.write' => 2], 'custom'));
    }

    private function json(Response $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        return $decoded;
    }
}
