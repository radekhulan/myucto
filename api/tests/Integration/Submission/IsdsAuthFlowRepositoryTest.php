<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Submission;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Submission\IsdsAuthFlowRepository;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class IsdsAuthFlowRepositoryTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private IsdsAuthFlowRepository $repository;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        if (!$this->db->hasTable('submission_isds_auth_flows')) {
            $this->markTestSkipped('Migrace 1542 neproběhla.');
        }
        $this->repository = new IsdsAuthFlowRepository($this->db);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $source = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $source);
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testFlowIsScopedAtomicallyClaimedAndErasedAfterConsumption(): void
    {
        $tokenHash = hash('sha256', 'synthetic-opaque-token');
        $this->repository->create(
            $tokenHash,
            $this->supplierId,
            $this->userId,
            'test',
            'sms',
            'enc:v2:synthetic-payload',
            300,
            2,
        );

        self::assertNull($this->repository->claim($tokenHash, $this->otherSupplierId, $this->userId, 'test', 'sms'));
        $first = $this->repository->claim($tokenHash, $this->supplierId, $this->userId, 'test', 'sms');
        self::assertNotNull($first);
        self::assertSame(1, $first['attempts']);
        self::assertNull(
            $this->repository->claim($tokenHash, $this->supplierId, $this->userId, 'test', 'sms'),
            'Souběžný claim nesmí získat stejný flow podruhé.',
        );

        $this->repository->release($first['id']);
        $second = $this->repository->claim($tokenHash, $this->supplierId, $this->userId, 'test', 'sms');
        self::assertNotNull($second);
        self::assertSame(2, $second['attempts']);
        self::assertTrue($this->repository->consume($second['id']));
        self::assertNull($this->repository->claim($tokenHash, $this->supplierId, $this->userId, 'test', 'sms'));

        $statement = $this->db->pdo()->prepare(
            'SELECT status, payload_ciphertext, consumed_at
               FROM submission_isds_auth_flows WHERE token_hash = ?'
        );
        $statement->execute([$tokenHash]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('consumed', $row['status']);
        self::assertNull($row['payload_ciphertext']);
        self::assertNotNull($row['consumed_at']);
    }
}
