<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Logbook;

use MyInvoice\Action\Document\LinkSearchAction;
use MyInvoice\Action\Logbook\FuelCashDocumentsAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\DocumentLinkRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Pokladní doklady smí číst jen ten, kdo má právo číst pokladnu — ani kniha jízd,
 * ani dokumenty nejsou obchvat kolem práva `cash`.
 */
#[Group('integration')]
final class LogbookCashPermissionTest extends TestCase
{
    use LogbookFixtures;

    protected function setUp(): void
    {
        $this->bootLogbook();
        $this->car($this->supplierA, '1AB 2345');
    }

    protected function tearDown(): void
    {
        $this->shutdownLogbook();
    }

    public function testFuelCashDocumentsRequireCashRead(): void
    {
        $doc = $this->cashDocument($this->supplierA, 'Nafta 40 l', 1500.00);
        $action = $this->container->get(FuelCashDocumentsAction::class);

        $withoutCash = $this->request(['logbook' => AccessLevel::WRITE->value, 'logbook.write' => AccessLevel::WRITE->value]);
        self::assertSame(403, $action->list($withoutCash, new Response())->getStatusCode());
        self::assertSame(403, $action->assign($withoutCash, new Response(), ['id' => (string) $doc])->getStatusCode());
        self::assertSame(403, $action->backfill($withoutCash, new Response())->getStatusCode());
        self::assertSame(0, $this->fuelingCount($this->supplierA), 'Bez práva pokladny se nic nevytěží.');

        $withCash = $this->request([
            'logbook' => AccessLevel::WRITE->value, 'logbook.write' => AccessLevel::WRITE->value,
            'cash' => AccessLevel::READ->value,
        ]);
        $list = $action->list($withCash, new Response());
        self::assertSame(200, $list->getStatusCode());
        self::assertContains($doc, array_column($this->json($list)['documents'], 'id'));
    }

    public function testLinkSearchHidesCashDocumentsWithoutCashRead(): void
    {
        $this->cashDocument($this->supplierA, 'Nafta 40 l', 1500.00, ['doc_number' => 'VPD-PERM-77']);
        $action = $this->container->get(LinkSearchAction::class);
        $query = ['q' => 'VPD-PERM', 'types' => 'cash_document'];

        $without = $this->json($action($this->request(['documents' => AccessLevel::READ->value])->withQueryParams($query), new Response()));
        self::assertSame([], $without['results']);

        $with = $this->json($action($this->request([
            'documents' => AccessLevel::READ->value, 'cash' => AccessLevel::READ->value,
        ])->withQueryParams($query), new Response()));
        self::assertSame(['cash_document'], array_values(array_unique(array_column($with['results'], 'entity_type'))));
    }

    public function testCashDocumentLinkLabelCanBeRedacted(): void
    {
        $cash = $this->cashDocument($this->supplierA, 'Nafta 40 l', 1500.00, ['doc_number' => 'VPD-PERM-88']);
        $this->pdo->prepare(
            "INSERT INTO documents (supplier_id, title, original_name, filename, sha256, mime_type, size_bytes, doc_type)
             VALUES (?, 'Testovací účtenka', 'uctenka.pdf', 'uctenka.pdf', ?, 'application/pdf', 1024, 'pdf')"
        )->execute([$this->supplierA, str_repeat('d', 64)]);
        $documentId = (int) $this->pdo->lastInsertId();
        $links = $this->container->get(DocumentLinkRepository::class);
        $links->attach($this->supplierA, $documentId, 'cash_document', $cash);

        $visible = $links->linksForDocument($documentId, $this->supplierA);
        $redacted = $links->linksForDocument($documentId, $this->supplierA, ['cash_document']);

        self::assertStringContainsString('VPD-PERM-88', $visible[0]['label']);
        self::assertSame('#' . $cash, $redacted[0]['label']);
        self::assertSame($cash, $redacted[0]['entity_id'], 'Vazba zůstává, skrývá se jen popisek.');
    }

    /** @param array<string,string> $permissions */
    private function request(array $permissions): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/logbook/fuel-cash-documents')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierA)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withAttribute('auth.effective_role', new EffectiveRole(0, 'Vlastní role', 'staff', true, $permissions, 'custom'))
            ->withParsedBody([]);
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);
        return $decoded['data'] ?? $decoded;
    }
}
