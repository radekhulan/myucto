<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\PurchaseInvoice\PurchaseInvoiceEmailStatusAction;
use MyInvoice\Action\PurchaseInvoice\PurchaseInvoiceSubmissionAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\PurchaseInvoice\PurchaseInvoiceSubmissionUploadService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[Group('integration')]
final class PurchaseInvoiceEmailStatusTest extends TestCase
{
    private Connection $db;
    private PurchaseInvoiceEmailStatusAction $action;
    private PurchaseInvoiceSubmissionAction $inbox;
    private PurchaseInvoiceSubmissionUploadService $upload;
    private DocumentStorage $storage;
    private int $supplierA = 0;
    private int $supplierB = 0;
    private int $userId = 0;
    private bool $inTx = false;
    /** @var list<string> */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 3) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(PurchaseInvoiceEmailStatusAction::class);
            $this->inbox = $container->get(PurchaseInvoiceSubmissionAction::class);
            $this->upload = $container->get(PurchaseInvoiceSubmissionUploadService::class);
            $this->storage = $container->get(DocumentStorage::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $baseSupplier = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($baseSupplier === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $mk = static function (string $name) use ($pdo, $baseSupplier): int {
            $stmt = $pdo->prepare(
                'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
                 SELECT ?, "Testovací", "Praha", "11000", country_id, ?, default_currency_id, default_vat_rate_id
                   FROM supplier WHERE id = ?'
            );
            $stmt->execute([$name, strtolower(str_replace(' ', '', $name)) . '@example.com', $baseSupplier]);
            return (int) $pdo->lastInsertId();
        };
        $this->supplierA = $mk('Mail Firma A');
        $this->supplierB = $mk('Mail Firma B');
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
        foreach (array_unique($this->createdFiles) as $path) {
            if (is_file($path)) @unlink($path);
            @rmdir(dirname($path));
            @rmdir(dirname(dirname($path)));
        }
    }

    public function testStatusCountsOnlyEmailDocumentsOfCurrentCompanyWithoutSecrets(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO bank_email_imap_settings
                (supplier_id, name, enabled, host, username, password_enc, ingest_pdf_invoices,
                 last_scan_at, last_scan_status, last_scan_message)
             VALUES (?, 'Faktury', 1, 'imap.example.com', 'faktury@example.com', 'tajne', 1,
                     NOW(), 'error', 'Přihlášení ke schránce selhalo.')"
        )->execute([$this->supplierA]);

        $this->submission($this->supplierA, 'email');
        $old = $this->submission($this->supplierA, 'email');
        $pdo->prepare('UPDATE purchase_invoice_submissions SET created_at = NOW() - INTERVAL 45 DAY WHERE id = ?')
            ->execute([$old['id']]);
        $this->submission($this->supplierA, 'portal');
        $this->submission($this->supplierB, 'email');

        $data = $this->fetchStatus($this->request($this->supplierA));

        self::assertTrue($data['active']);
        self::assertSame(30, $data['days']);
        self::assertSame(1, $data['received']);
        self::assertSame(2, $data['waiting']);
        self::assertCount(1, $data['accounts']);
        $account = $data['accounts'][0];
        self::assertSame('Faktury', $account['name']);
        self::assertSame('error', $account['last_scan_status']);
        self::assertSame('Přihlášení ke schránce selhalo.', $account['last_scan_message']);
        foreach (['password', 'password_enc', 'host', 'username', 'oauth_client_secret_enc'] as $secret) {
            self::assertArrayNotHasKey($secret, $account);
        }

        $other = $this->fetchStatus($this->request($this->supplierB));
        self::assertFalse($other['active']);
        self::assertSame([], $other['accounts']);
        self::assertSame(1, $other['received']);
    }

    public function testInboxListFiltersEmailSource(): void
    {
        $email = $this->submission($this->supplierA, 'email');
        $this->submission($this->supplierA, 'staff');

        $request = $this->request($this->supplierA)->withQueryParams(['via' => 'email']);
        $response = $this->inbox->list($request, (new ResponseFactory())->createResponse());
        self::assertSame(200, $response->getStatusCode());
        $page = json_decode((string) $response->getBody(), true);
        self::assertSame(1, $page['total']);
        self::assertSame((int) $email['id'], (int) $page['items'][0]['id']);

        $all = $this->inbox->list(
            $this->request($this->supplierA)->withQueryParams(['via' => 'neznamy']),
            (new ResponseFactory())->createResponse(),
        );
        self::assertSame(2, json_decode((string) $all->getBody(), true)['total']);
    }

    public function testClientAndRoleWithoutInboxAreRejected(): void
    {
        $client = $this->request($this->supplierA)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'client'])
            ->withAttribute('auth.effective_role', new EffectiveRole(1, 'client', 'client', true, ['documents.submit' => AccessLevel::WRITE->value], 'client'));
        self::assertSame(403, ($this->action)($client, (new ResponseFactory())->createResponse())->getStatusCode());

        $staff = $this->request($this->supplierA)
            ->withAttribute('auth.effective_role', new EffectiveRole(1, 'staff', 'staff', true, ['purchase_invoices' => AccessLevel::WRITE->value], 'accountant'));
        self::assertSame(403, ($this->action)($staff, (new ResponseFactory())->createResponse())->getStatusCode());

        $reader = $this->request($this->supplierA)
            ->withAttribute('auth.effective_role', new EffectiveRole(1, 'staff', 'staff', true, ['documents.inbox' => AccessLevel::READ->value], 'readonly'));
        self::assertSame(200, ($this->action)($reader, (new ResponseFactory())->createResponse())->getStatusCode());
    }

    /** @return array<string,mixed> */
    private function fetchStatus(ServerRequestInterface $request): array
    {
        $response = ($this->action)($request, (new ResponseFactory())->createResponse());
        self::assertSame(200, $response->getStatusCode());
        return json_decode((string) $response->getBody(), true);
    }

    private function request(int $supplierId): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/purchase-invoice-submissions/email-status')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
    }

    /** @return array<string,mixed> */
    private function submission(int $supplierId, string $via): array
    {
        $row = $this->upload->submitBytes(
            "%PDF-1.4\n% synthetic email intake " . bin2hex(random_bytes(8)),
            'doklad.pdf',
            $supplierId,
            null,
            $via,
        )['submission'];
        $this->createdFiles[] = $this->storage->pathFor(
            (int) $row['supplier_id'],
            (string) $row['document_sha256'],
            (string) $row['document_filename'],
        );
        return $row;
    }
}
