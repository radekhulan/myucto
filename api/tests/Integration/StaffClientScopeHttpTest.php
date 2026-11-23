<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceSubmissionRepository;
use MyInvoice\Service\Auth\DatabaseSecurityClock;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Tests\Support\OpensslConfigTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

/**
 * Interní role s rozsahem klienta: předání dokladu účetní řídí právo
 * `documents.submit`, ne typ role. Celá cesta přes skutečný HTTP stack.
 */
#[Group('integration')]
final class StaffClientScopeHttpTest extends TestCase
{
    use OpensslConfigTrait;

    private Connection $db;
    private Config $config;
    private SessionManager $sessions;
    private ?App $app = null;
    private int $supplierId = 0;
    /** @var list<int> */
    private array $userIds = [];
    /** @var list<int> */
    private array $roleIds = [];
    /** @var list<int> */
    private array $requestIds = [];
    /** @var list<string> */
    private array $sessionTokens = [];
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) $this->markTestSkipped('cfg.php missing');
        try {
            $this->config = Config::load($rootDir);
            $this->db = new Connection($this->config);
            $this->sessions = new SessionManager($this->db, $this->config, new DatabaseSecurityClock());
            $this->supplierId = (int) $this->db->pdo()->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        } catch (\Exception $e) {
            $this->markTestSkipped('DB unavailable: ' . $e->getMessage());
        }
        if ($this->supplierId <= 0 || $this->db->pdo()->query("SHOW TABLES LIKE 'purchase_invoice_submissions'")->fetchColumn() === false) {
            $this->markTestSkipped('Chybí firma nebo fronta předaných dokladů.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) @unlink($path);
        }
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        foreach ($this->sessionTokens as $token) {
            try { $this->sessions->destroy($token); } catch (\Throwable) {}
        }
        if ($this->userIds !== []) {
            $in = implode(',', array_fill(0, count($this->userIds), '?'));
            $rows = $pdo->prepare("SELECT id, supplier_id, document_id FROM purchase_invoice_submissions WHERE submitted_by IN ($in)");
            $rows->execute($this->userIds);
            $container = ($this->app ??= Bootstrap::buildApp())->getContainer();
            $submissions = $container->get(PurchaseInvoiceSubmissionRepository::class);
            $storage = $container->get(DocumentStorage::class);
            foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $found = $submissions->find((int) $row['id'], (int) $row['supplier_id']);
                if ($this->requestIds !== []) {
                    $pdo->prepare('UPDATE document_requests SET submission_id = NULL WHERE submission_id = ?')->execute([(int) $row['id']]);
                }
                $pdo->prepare('DELETE FROM purchase_invoice_submissions WHERE id = ?')->execute([(int) $row['id']]);
                try {
                    $pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([(int) $row['document_id']]);
                } catch (\Throwable) {}
                if ($found !== null) {
                    $path = $storage->pathFor((int) $row['supplier_id'], (string) $found['document_sha256'], (string) $found['document_filename']);
                    if (is_file($path)) @unlink($path);
                }
            }
            foreach ($this->requestIds as $id) {
                $pdo->prepare('DELETE FROM document_requests WHERE id = ?')->execute([$id]);
            }
            $pdo->prepare("DELETE FROM activity_log WHERE user_id IN ($in)")->execute($this->userIds);
            $pdo->prepare("DELETE FROM user_suppliers WHERE user_id IN ($in)")->execute($this->userIds);
            $pdo->prepare("DELETE FROM users WHERE id IN ($in)")->execute($this->userIds);
        }
        if ($this->roleIds !== []) {
            $in = implode(',', array_fill(0, count($this->roleIds), '?'));
            $pdo->prepare("DELETE FROM role_permissions WHERE role_id IN ($in)")->execute($this->roleIds);
            $pdo->prepare("DELETE FROM roles WHERE id IN ($in)")->execute($this->roleIds);
        }
        $this->db->close();
    }

    public function testStaffRoleWithSubmitPermissionHandsDocumentOverToAccountant(): void
    {
        $session = $this->createSession($this->createStaffUser(['documents.submit' => 2]));

        $upload = $this->request('POST', '/api/portal/purchase-invoice-submissions', $session, ['note' => 'Synthetic staff handover'], [
            'file' => $this->pdf('staff-handover.pdf'),
        ]);
        self::assertSame(201, $upload->getStatusCode(), (string) $upload->getBody());
        $item = $this->json($upload)['items'][0] ?? [];
        self::assertSame('submitted', $item['status'] ?? null);
        self::assertSame('portal', $item['submitted_via'] ?? null);
        self::assertArrayNotHasKey('extraction_error', $item);
        self::assertTrue((bool) ($item['can_delete'] ?? false));

        $list = $this->request('GET', '/api/portal/purchase-invoice-submissions', $session);
        self::assertSame(200, $list->getStatusCode());
        self::assertContains((int) $item['id'], array_map('intval', array_column($this->json($list)['items'] ?? [], 'id')));

        $requestId = $this->createDocumentRequest();
        $requests = $this->request('GET', '/api/portal/document-requests', $session);
        self::assertSame(200, $requests->getStatusCode());
        self::assertContains($requestId, array_map('intval', array_column($this->json($requests)['items'] ?? [], 'id')));
        $answer = $this->request('POST', '/api/portal/document-requests/' . $requestId . '/upload', $session, [], [
            'file' => $this->pdf('staff-requested.pdf'),
        ]);
        self::assertSame(201, $answer->getStatusCode(), (string) $answer->getBody());

        // Předání neotevírá příchozí frontu účetní ani interní požadavky.
        self::assertSame(403, $this->request('GET', '/api/purchase-invoice-submissions', $session)->getStatusCode());
        self::assertSame(403, $this->request('GET', '/api/document-requests', $session)->getStatusCode());
        $audit = $this->db->pdo()->prepare("SELECT COUNT(*) FROM activity_log WHERE user_id = ? AND action = 'purchase_invoice_submission.created'");
        $audit->execute([end($this->userIds)]);
        self::assertSame(2, (int) $audit->fetchColumn());
    }

    public function testStaffRoleWithoutSubmitPermissionIsForbidden(): void
    {
        foreach ([[], ['documents.submit' => 1], ['documents' => 2, 'documents.inbox' => 2, 'documents.requests' => 2]] as $permissions) {
            $session = $this->createSession($this->createStaffUser($permissions));
            $label = json_encode($permissions, JSON_THROW_ON_ERROR);
            $upload = $this->request('POST', '/api/portal/purchase-invoice-submissions', $session, [], [
                'file' => $this->pdf('staff-denied.pdf'),
            ]);
            self::assertSame(403, $upload->getStatusCode(), $label . ' ' . (string) $upload->getBody());
            self::assertSame(isset($permissions['documents.submit']) ? 200 : 403,
                $this->request('GET', '/api/portal/purchase-invoice-submissions', $session)->getStatusCode(), $label);
        }
        $count = $this->db->pdo()->prepare('SELECT COUNT(*) FROM purchase_invoice_submissions WHERE submitted_by = ?');
        foreach ($this->userIds as $userId) {
            $count->execute([$userId]);
            self::assertSame(0, (int) $count->fetchColumn());
        }
    }

    /** @param array<string,int> $permissions */
    private function createStaffUser(array $permissions): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO roles (name, role_type, is_active) VALUES (?, ?, 1)')
            ->execute(['__TEST Fakturace a dokumenty ' . bin2hex(random_bytes(3)), 'staff']);
        $roleId = (int) $pdo->lastInsertId();
        $this->roleIds[] = $roleId;
        $insert = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_key, access_level) VALUES (?, ?, ?)');
        foreach (['profile' => 2] + $permissions as $key => $level) $insert->execute([$roleId, $key, $level]);

        $pdo->prepare('INSERT INTO users (email, password_hash, name, role, role_id, locale, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)')
            ->execute([
                '__test_staff_scope_' . bin2hex(random_bytes(6)) . '@example.test',
                '$2y$12$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234',
                '__TEST staff client scope',
                'readonly',
                $roleId,
                'cs',
            ]);
        $userId = (int) $pdo->lastInsertId();
        $this->userIds[] = $userId;
        $pdo->prepare('INSERT INTO user_suppliers (user_id, supplier_id) VALUES (?, ?)')->execute([$userId, $this->supplierId]);
        return $userId;
    }

    private function createDocumentRequest(): int
    {
        $this->db->pdo()->prepare("INSERT INTO document_requests (supplier_id, description, status, created_by) VALUES (?, ?, 'requested', ?)")
            ->execute([$this->supplierId, 'Synthetic missing receipt', end($this->userIds)]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->requestIds[] = $id;
        return $id;
    }

    /** @return array{token:string,csrf_token:string} */
    private function createSession(int $userId): array
    {
        $session = $this->sessions->create($userId, '127.0.0.1', '__test_staff_scope');
        $this->sessionTokens[] = (string) $session['token'];
        return ['token' => (string) $session['token'], 'csrf_token' => (string) $session['csrf_token']];
    }

    private function pdf(string $name): UploadedFile
    {
        $tmpFile = (string) tempnam(sys_get_temp_dir(), 'staffscope');
        $this->temporaryFiles[] = $tmpFile;
        file_put_contents($tmpFile, "%PDF-1.4\n% synthetic " . $name . ' ' . bin2hex(random_bytes(8)) . "\n%%EOF\n");
        return new UploadedFile($tmpFile, $name, 'application/pdf', (int) filesize($tmpFile), UPLOAD_ERR_OK);
    }

    private function request(string $method, string $path, array $session, ?array $body = null, array $uploads = []): ResponseInterface
    {
        if ($this->app === null) {
            $this->app = Bootstrap::buildApp();
            $this->app->getContainer()->set(Mailer::class, $this->createMock(Mailer::class));
        }
        $cookieName = (string) $this->config->get('session.cookie_name', '__Host-myinvoice_session');
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1'])
            ->withCookieParams([$cookieName => $session['token']])
            ->withHeader('Accept', 'application/json')
            ->withHeader('Origin', rtrim((string) $this->config->get('app.url', ''), '/'))
            ->withHeader('X-CSRF-Token', $session['csrf_token'])
            ->withHeader('X-Supplier-Id', (string) $this->supplierId);
        if ($uploads !== []) {
            $request = $request->withUploadedFiles($uploads)->withParsedBody($body ?? []);
        } elseif ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }
        return $this->app->handle($request);
    }

    private function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
