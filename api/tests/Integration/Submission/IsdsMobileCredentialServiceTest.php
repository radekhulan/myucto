<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Submission;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Submission\IsdsMobileCredentialRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use MyInvoice\Service\Submission\IsdsMobileCredentialService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class IsdsMobileCredentialServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private IsdsMobileCredentialRepository $repository;
    private IsdsMobileCredentialService $service;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->repository = new IsdsMobileCredentialRepository($this->db);
        if (!$this->repository->isAvailable()) {
            $this->markTestSkipped('Migrace 1534 neproběhla.');
        }
        $crypto = $container->get(SecretEncryption::class);
        if ($crypto->validateKey() !== null) {
            $this->markTestSkipped('Šifrovací klíč není nastaven.');
        }
        $this->service = new IsdsMobileCredentialService($this->repository, $crypto);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $sourceSupplierId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testProfileIsEncryptedAndScopedByCompanyAndUser(): void
    {
        $saved = $this->service->save($this->supplierId, $this->userId, 'test', 'synthetic-user', 'synthetic-code');
        self::assertTrue($saved['saved']);
        self::assertSame('synthetic-user', $saved['username']);
        self::assertArrayNotHasKey('communication_code', $saved);

        $raw = $this->repository->findWithSecrets($this->supplierId, $this->userId, 'test');
        self::assertNotNull($raw);
        self::assertStringStartsWith('enc:v2:', (string) $raw['username_ciphertext']);
        self::assertStringStartsWith('enc:v2:', (string) $raw['communication_code_ciphertext']);
        self::assertStringNotContainsString('synthetic-code', (string) $raw['communication_code_ciphertext']);

        $credentials = $this->service->unlock($this->supplierId, $this->userId, 'test');
        self::assertSame('synthetic-user', $credentials->username?->reveal());
        self::assertSame('synthetic-code', $credentials->password?->reveal());
        self::assertFalse($this->service->profile($this->otherSupplierId, $this->userId, 'test')['saved']);
    }

    public function testMissingProfileCannotBeUnlocked(): void
    {
        $this->expectException(SubmissionChannelException::class);
        $this->expectExceptionMessage('není přihlášení Mobilním klíčem uložené');
        $this->service->unlock($this->supplierId, $this->userId, 'test');
    }
}
