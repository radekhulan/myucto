<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Submission;

use MyInvoice\Action\Submission\SubmissionInboxStorageSettingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class SubmissionInboxStorageSettingsActionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private SubmissionInboxStorageSettingsAction $action;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->action = $container->get(SubmissionInboxStorageSettingsAction::class);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $templateSupplierId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $templateSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $templateSupplierId);
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testListAndSaveAreTenantScoped(): void
    {
        $ownFolderId = $this->createFolder($this->supplierId, 'Vlastní');
        $this->createFolder($this->otherSupplierId, 'Cizí');

        $saved = $this->callSave([
            'base_folder_id' => $ownFolderId,
            'row_version' => 0,
        ]);
        self::assertSame(200, $saved['status']);
        self::assertSame($ownFolderId, $saved['body']['item']['base_folder_id']);

        $request = $this->request('GET', '/api/settings/databox/inbox-storage');
        $response = $this->action->list($request, new Response());
        $body = $this->decode($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $body['items']);
        self::assertSame(['Vlastní'], array_column($body['folders'], 'name'));
    }

    public function testForeignFolderAndStaleVersionAreRejected(): void
    {
        $foreignFolderId = $this->createFolder($this->otherSupplierId, 'Cizí');
        $foreign = $this->callSave(['base_folder_id' => $foreignFolderId, 'row_version' => 0]);
        self::assertSame(404, $foreign['status']);
        self::assertSame('isds_inbox_archive_folder_not_found', $foreign['body']['error']['code']);

        $ownFolderId = $this->createFolder($this->supplierId, 'Vlastní');
        self::assertSame(200, $this->callSave(['base_folder_id' => $ownFolderId, 'row_version' => 0])['status']);
        $stale = $this->callSave(['base_folder_id' => $ownFolderId, 'row_version' => 0]);
        self::assertSame(409, $stale['status']);
        self::assertSame('isds_inbox_archive_settings_conflict', $stale['body']['error']['code']);
    }

    public function testBearerTokenCannotManageStorageSetting(): void
    {
        $folderId = $this->createFolder($this->supplierId, 'Vlastní');
        $request = $this->request('PUT', '/api/settings/databox/inbox-storage/test', 'bearer')
            ->withParsedBody(['base_folder_id' => $folderId, 'row_version' => 0]);
        $response = $this->action->save($request, new Response(), ['environment' => 'test']);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden_via_token', $this->decode($response)['error']['code']);
    }

    public function testMissingFolderFieldCannotAccidentallyClearSetting(): void
    {
        $folderId = $this->createFolder($this->supplierId, 'Vlastní');
        $saved = $this->callSave(['base_folder_id' => $folderId, 'row_version' => 0]);
        self::assertSame(200, $saved['status']);

        $missing = $this->callSave(['row_version' => $saved['body']['item']['row_version']]);
        self::assertSame(400, $missing['status']);
        self::assertSame('base_folder_id_required', $missing['body']['error']['code']);
    }

    /** @param array<string,mixed> $body @return array{status:int,body:array<string,mixed>} */
    private function callSave(array $body): array
    {
        $request = $this->request('PUT', '/api/settings/databox/inbox-storage/test')->withParsedBody($body);
        $response = $this->action->save($request, new Response(), ['environment' => 'test']);
        return ['status' => $response->getStatusCode(), 'body' => $this->decode($response)];
    }

    private function request(string $method, string $uri, string $authMethod = 'session'): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod);
    }

    /** @return array<string,mixed> */
    private function decode(\Psr\Http\Message\ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);
        return $decoded;
    }

    private function createFolder(int $supplierId, string $name): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO document_folders (supplier_id, parent_id, name, created_by) VALUES (?, NULL, ?, ?)'
        );
        $stmt->execute([$supplierId, $name, $this->userId]);
        return (int) $this->db->pdo()->lastInsertId();
    }
}
