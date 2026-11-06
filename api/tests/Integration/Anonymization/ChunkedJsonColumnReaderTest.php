<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Anonymization;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Anonymization\Streaming\ChunkedJsonColumnReader;
use MyInvoice\Service\Anonymization\Streaming\FinancialArchiveJsonColumn;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class ChunkedJsonColumnReaderTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private string $json;

    protected function setUp(): void
    {
        $this->db = Connection::withoutSharedTestConnection(static fn (): Connection => Bootstrap::buildContainer()->get(Connection::class));
        $this->pdo = $this->db->pdo();
        $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        self::assertStringEndsWith('_test', $database);
        self::assertSame((string) getenv('MYINVOICE_DB_NAME'), $database);
        $this->pdo->exec('DROP TEMPORARY TABLE IF EXISTS nonprofit_tax_assessments');
        $this->pdo->exec('CREATE TEMPORARY TABLE nonprofit_tax_assessments (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            supplier_id BIGINT UNSIGNED NOT NULL,
            snapshot_json LONGTEXT NOT NULL,
            assessment_json LONGTEXT NOT NULL,
            result_json LONGTEXT NOT NULL,
            UNIQUE KEY uq_test_tenant (supplier_id,id)
        ) ENGINE=InnoDB');
        $this->json = json_encode(['rows' => [str_repeat('a', 1_400_000), str_repeat('b', 1_200_000)]], JSON_THROW_ON_ERROR);
        $statement = $this->pdo->prepare('INSERT INTO nonprofit_tax_assessments VALUES (1,11,?,?,?)');
        $statement->execute([$this->json, '{}', '[]']);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->pdo)) $this->pdo->exec('DROP TEMPORARY TABLE IF EXISTS nonprofit_tax_assessments');
        if (isset($this->db)) $this->db->close();
    }

    public function testReadsExactTenantRowInBoundedChunks(): void
    {
        $this->pdo->beginTransaction();
        $chunks = iterator_to_array((new ChunkedJsonColumnReader($this->pdo))->read(FinancialArchiveJsonColumn::ASSESSMENT_SNAPSHOT, 11, 1), false);

        self::assertSame($this->json, implode('', $chunks));
        self::assertGreaterThan(2, count($chunks));
        self::assertLessThanOrEqual(ChunkedJsonColumnReader::CHUNK_BYTES, max(array_map(strlen(...), $chunks)));
        self::assertTrue($this->pdo->inTransaction());
    }

    public function testReadsUtf8BytesAcrossChunkBoundaryWithoutCharacterOffsetDrift(): void
    {
        $this->json = json_encode(['text' => str_repeat('Příliš žluťoučký kůň ', 40_000)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $statement = $this->pdo->prepare('UPDATE nonprofit_tax_assessments SET snapshot_json=? WHERE id=1 AND supplier_id=11');
        $statement->execute([$this->json]);
        $this->pdo->beginTransaction();

        $chunks = iterator_to_array((new ChunkedJsonColumnReader($this->pdo))->read(FinancialArchiveJsonColumn::ASSESSMENT_SNAPSHOT, 11, 1), false);

        self::assertSame($this->json, implode('', $chunks));
        self::assertGreaterThan(1, count($chunks));
        self::assertLessThanOrEqual(ChunkedJsonColumnReader::CHUNK_BYTES, max(array_map(strlen(...), $chunks)));
    }

    public function testRejectsForeignTenantAndMissingTransaction(): void
    {
        $reader = new ChunkedJsonColumnReader($this->pdo);
        try {
            iterator_to_array($reader->read(FinancialArchiveJsonColumn::ASSESSMENT_SNAPSHOT, 11, 1));
            self::fail('Reader accepted a missing transaction.');
        } catch (\LogicException $exception) {
            self::assertSame('Čtení archivního JSON vyžaduje vlastní transakci.', $exception->getMessage());
        }

        $this->pdo->beginTransaction();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Archivní JSON nebyl nalezen.');
        iterator_to_array($reader->read(FinancialArchiveJsonColumn::ASSESSMENT_SNAPSHOT, 12, 1));
    }

    public function testCommitAndBeginOnSamePdoCannotReviveReader(): void
    {
        $this->pdo->beginTransaction();
        $iterator = (new ChunkedJsonColumnReader($this->pdo))->read(FinancialArchiveJsonColumn::ASSESSMENT_SNAPSHOT, 11, 1);
        $iterator->rewind();
        self::assertSame(ChunkedJsonColumnReader::CHUNK_BYTES, strlen($iterator->current()));
        $this->pdo->commit();
        $this->pdo->beginTransaction();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Transakce archivního JSON byla nahrazena.');
        $iterator->next();
    }

    public function testAbandonedReaderDoesNotReleaseNewerReaderSavepoint(): void
    {
        $this->pdo->beginTransaction();
        $reader = new ChunkedJsonColumnReader($this->pdo);
        $abandoned = $reader->read(FinancialArchiveJsonColumn::ASSESSMENT_SNAPSHOT, 11, 1);
        $abandoned->rewind();
        $active = $reader->read(FinancialArchiveJsonColumn::ASSESSMENT_SNAPSHOT, 11, 1);
        $active->rewind();
        $chunks = [$active->current()];
        unset($abandoned);
        for ($active->next(); $active->valid(); $active->next()) $chunks[] = $active->current();

        self::assertSame($this->json, implode('', $chunks));
        self::assertTrue($this->pdo->inTransaction());
    }

    public function testMutationAfterFirstChunkRejectsFinalProof(): void
    {
        $this->pdo->beginTransaction();
        $iterator = (new ChunkedJsonColumnReader($this->pdo))->read(FinancialArchiveJsonColumn::ASSESSMENT_SNAPSHOT, 11, 1);
        $iterator->rewind();
        self::assertNotSame('', $iterator->current());
        $changed = str_replace('b', 'c', $this->json);
        $statement = $this->pdo->prepare('UPDATE nonprofit_tax_assessments SET snapshot_json=? WHERE id=1 AND supplier_id=11');
        $statement->execute([$changed]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Archivní JSON se během čtení změnil.');
        while ($iterator->valid()) $iterator->next();
    }
}
