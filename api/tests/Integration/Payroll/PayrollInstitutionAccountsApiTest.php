<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollInstitutionAccountsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Payroll\Security\PayrollRevealPurpose;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollInstitutionAccountsApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollInstitutionAccountsAction $action;
    private PayrollSensitiveData $sensitiveData;
    private int $userId;
    private int $supplierId;
    private int $otherSupplierId;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }

        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(PayrollInstitutionAccountsAction::class);
            $this->sensitiveData = $container->get(PayrollSensitiveData::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        if (!$this->db->hasTable('payroll_institutions')
            || !$this->db->hasTable('payroll_institution_accounts')) {
            $this->markTestSkipped('Migrace 1190 neproběhla.');
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            'UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)'
        )->execute([$this->supplierId, $this->otherSupplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    /**
     * Účet instituce je veřejný údaj (ČSSZ, FÚ, zdravotní pojišťovna), takže
     * čitelné číslo v DTO je záměr — bez něj si uživatel nemůže ověřit, kam
     * posílá odvody. Šifrování v úložišti a nezveřejňování ciphertextu ani
     * lookup hashe platí dál.
     */
    public function testCreateStoresEncryptedAccountAndReturnsReadableNumber(): void
    {
        $response = $this->create($this->supplierId, $this->payload());
        self::assertSame(201, $response->getStatusCode());
        $account = $this->json($response)['account'];
        self::assertSame(1, $account['row_version']);
        self::assertArrayHasKey('bank_account_masked', $account);
        self::assertSame('1000000005/0100', $account['bank_account']);
        self::assertArrayNotHasKey('bank_account_ciphertext', $account);
        self::assertArrayNotHasKey('bank_account_hash', $account);
        self::assertNotSame('1000000005/0100', $account['bank_account_masked']);

        $stored = $this->db->pdo()->prepare(
            'SELECT bank_account_ciphertext, bank_account_hash, bank_account_masked
               FROM payroll_institution_accounts
              WHERE supplier_id = ? AND id = ?'
        );
        $stored->execute([$this->supplierId, $account['id']]);
        $row = $stored->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertStringStartsWith('enc:v2:', (string) $row['bank_account_ciphertext']);
        self::assertSame(32, strlen((string) $row['bank_account_hash']));
        self::assertSame($account['bank_account_masked'], $row['bank_account_masked']);
        self::assertSame(
            '1000000005/0100',
            $this->sensitiveData->reveal(
                (string) $row['bank_account_ciphertext'],
                PayrollSensitiveField::BANK_ACCOUNT,
                $this->supplierId,
                (int) $account['id'],
                PayrollRevealPurpose::PAYMENT_INSTITUTION_ACCOUNT,
            ),
        );

        $serialized = json_encode($this->json($response), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('enc:v2:', $serialized);
    }

    public function testIntervalsCannotOverlapAndEffectiveFilterKeepsHistory(): void
    {
        $firstPayload = $this->payload();
        $firstPayload['valid_to'] = '2026-06-30';
        $firstResponse = $this->create($this->supplierId, $firstPayload);
        self::assertSame(201, $firstResponse->getStatusCode());
        $first = $this->json($firstResponse)['account'];

        $overlap = $this->payload();
        $overlap['valid_from'] = '2026-06-30';
        $overlap['valid_to'] = '2026-12-31';
        $conflict = $this->create($this->supplierId, $overlap);
        self::assertSame(409, $conflict->getStatusCode());
        self::assertSame(
            'institution_account_interval_overlap',
            $this->json($conflict)['error']['code'],
        );

        $second = $this->payload();
        $second['valid_from'] = '2026-07-01';
        $second['valid_to'] = null;
        $second['bank_account'] = '1000000005/0300';
        self::assertSame(201, $this->create($this->supplierId, $second)->getStatusCode());

        $overlappingUpdate = $this->updatePayload(1);
        $overlappingUpdate['valid_to'] = '2026-07-01';
        $updateConflict = $this->action->update(
            $this->request('PUT', $this->supplierId)->withParsedBody($overlappingUpdate),
            new Response(),
            ['id' => (string) $first['id']],
        );
        self::assertSame(409, $updateConflict->getStatusCode());
        self::assertSame(
            'institution_account_interval_overlap',
            $this->json($updateConflict)['error']['code'],
        );

        $all = $this->action->list(
            $this->request('GET', $this->supplierId),
            new Response(),
        );
        self::assertCount(2, $this->json($all)['accounts']);

        $june = $this->action->list(
            $this->request('GET', $this->supplierId, query: ['effective_on' => '2026-06-30']),
            new Response(),
        );
        self::assertCount(1, $this->json($june)['accounts']);

        $july = $this->action->list(
            $this->request('GET', $this->supplierId, query: ['effective_on' => '2026-07-01']),
            new Response(),
        );
        self::assertCount(1, $this->json($july)['accounts']);
    }

    public function testTenantIsolationAndCompositeForeignKeyRejectCrossTenantParent(): void
    {
        $created = $this->json($this->create($this->supplierId, $this->payload()))['account'];
        $otherCreated = $this->json(
            $this->create($this->otherSupplierId, $this->payload())
        )['account'];

        $foreignDetail = $this->action->detail(
            $this->request('GET', $this->otherSupplierId),
            new Response(),
            ['id' => (string) $created['id']],
        );
        self::assertSame(404, $foreignDetail->getStatusCode());

        $hashes = $this->db->pdo()->prepare(
            'SELECT bank_account_hash
               FROM payroll_institution_accounts
              WHERE (supplier_id = ? AND id = ?)
                 OR (supplier_id = ? AND id = ?)
              ORDER BY supplier_id'
        );
        $hashes->execute([
            $this->supplierId,
            $created['id'],
            $this->otherSupplierId,
            $otherCreated['id'],
        ]);
        $tenantHashes = $hashes->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(2, $tenantHashes);
        self::assertNotSame($tenantHashes[0], $tenantHashes[1]);

        $this->expectException(\PDOException::class);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_institution_accounts
                (supplier_id, institution_id, institution_name,
                 bank_account_ciphertext, bank_account_hash, bank_account_masked,
                 valid_from, source_kind, source_reference, verified_on)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->otherSupplierId,
            $created['institution_id'],
            'Cizí syntetická instituce',
            'enc:v2:synthetic',
            random_bytes(32),
            '••••0005',
            '2026-01-01',
            'user_verified',
            'SYNTHETIC-CROSS-TENANT',
            '2026-01-01',
        ]);
    }

    public function testOptimisticLockAndImmutableBankHistory(): void
    {
        $created = $this->json($this->create($this->supplierId, $this->payload()))['account'];
        $updatePayload = $this->updatePayload(1);
        $updated = $this->action->update(
            $this->request('PUT', $this->supplierId)->withParsedBody($updatePayload),
            new Response(),
            ['id' => (string) $created['id']],
        );
        self::assertSame(200, $updated->getStatusCode());
        self::assertSame(2, $this->json($updated)['account']['row_version']);

        $stale = $this->action->update(
            $this->request('PUT', $this->supplierId)->withParsedBody($updatePayload),
            new Response(),
            ['id' => (string) $created['id']],
        );
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame(2, $this->json($stale)['error']['current_row_version']);

        $rewrite = $this->updatePayload(2);
        $rewrite['bank_account'] = '1000000005/0300';
        $immutable = $this->action->update(
            $this->request('PUT', $this->supplierId)->withParsedBody($rewrite),
            new Response(),
            ['id' => (string) $created['id']],
        );
        self::assertSame(422, $immutable->getStatusCode());
        self::assertSame('validation_failed', $this->json($immutable)['error']['code']);
    }

    public function testBearerInsufficientRoleAndDisabledPayrollAreRejected(): void
    {
        $bearer = $this->action->list(
            $this->request('GET', $this->supplierId, authMethod: 'bearer'),
            new Response(),
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame('session_required', $this->json($bearer)['error']['code']);

        $readonly = $this->create(
            $this->supplierId,
            $this->payload(),
            role: 'readonly',
        );
        self::assertSame(403, $readonly->getStatusCode());
        self::assertSame('forbidden', $this->json($readonly)['error']['code']);

        $this->db->pdo()->prepare(
            'UPDATE supplier SET payroll_enabled = 0 WHERE id = ?'
        )->execute([$this->supplierId]);
        $disabled = $this->action->list(
            $this->request('GET', $this->supplierId),
            new Response(),
        );
        self::assertSame(403, $disabled->getStatusCode());
        self::assertSame('payroll_disabled', $this->json($disabled)['error']['code']);
    }

    public function testDeletingSupplierCascadesInstitutionAndAccountHistory(): void
    {
        $created = $this->json($this->create($this->supplierId, $this->payload()))['account'];
        $institutionId = (int) $created['institution_id'];
        $accountId = (int) $created['id'];

        $this->db->pdo()->prepare(
            'DELETE FROM supplier WHERE id = ?'
        )->execute([$this->supplierId]);

        $institution = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_institutions WHERE supplier_id = ? AND id = ?'
        );
        $institution->execute([$this->supplierId, $institutionId]);
        self::assertSame(0, (int) $institution->fetchColumn());

        $account = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM payroll_institution_accounts
              WHERE supplier_id = ? AND id = ?'
        );
        $account->execute([$this->supplierId, $accountId]);
        self::assertSame(0, (int) $account->fetchColumn());
    }

    public function testSourceReferenceIsOptional(): void
    {
        $payload = $this->payload();
        $payload['source_reference'] = '   ';
        $created = $this->create($this->supplierId, $payload);
        self::assertSame(201, $created->getStatusCode());
        self::assertSame('', $this->json($created)['account']['source_reference']);

        $missing = $this->payload();
        $missing['institution_code'] = 'SYNTH-112';
        unset($missing['source_reference']);
        $second = $this->create($this->supplierId, $missing);
        self::assertSame(201, $second->getStatusCode());
        self::assertSame('', $this->json($second)['account']['source_reference']);
    }

    public function testSourceReferenceStillRejectsOverlongValue(): void
    {
        $payload = $this->payload();
        $payload['source_reference'] = str_repeat('X', 501);
        self::assertSame(422, $this->create($this->supplierId, $payload)->getStatusCode());
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'institution_type' => 'health_insurer',
            'institution_code' => 'SYNTH-111',
            'institution_name' => 'Syntetická zdravotní pojišťovna',
            'bank_account' => '1000000005/0100',
            'currency_code' => 'CZK',
            'variable_symbol' => '12345678',
            'specific_symbol' => null,
            'constant_symbol' => '0558',
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'source_kind' => 'official_document',
            'source_reference' => 'SYNTHETIC-DOCUMENT-001',
            'verified_on' => '2026-01-01',
        ];
    }

    /** @return array<string,mixed> */
    private function updatePayload(int $version): array
    {
        return [
            'row_version' => $version,
            'institution_name' => 'Syntetická zdravotní pojišťovna — opravený název',
            'variable_symbol' => '12345678',
            'specific_symbol' => null,
            'constant_symbol' => '0558',
            'valid_to' => '2026-12-31',
            'source_kind' => 'user_verified',
            'source_reference' => 'SYNTHETIC-CHECK-002',
            'verified_on' => '2026-02-01',
        ];
    }

    /** @param array<string,mixed> $payload */
    private function create(
        int $supplierId,
        array $payload,
        string $role = 'admin',
    ): Response {
        return $this->action->create(
            $this->request('POST', $supplierId, $role)->withParsedBody($payload),
            new Response(),
        );
    }

    /** @param array<string,string> $query */
    private function request(
        string $method,
        int $supplierId,
        string $role = 'admin',
        string $authMethod = 'session',
        array $query = [],
    ): \Psr\Http\Message\ServerRequestInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, '/api/payroll/settings/institution-accounts')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod);
        return $query === [] ? $request : $request->withQueryParams($query);
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);
        return $decoded;
    }
}
