<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Ares;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Ares\AresClient;
use MyInvoice\Service\Ares\SupplierRegistryEnricher;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class SupplierRegistryLegalFormEnrichmentTest extends TestCase
{
    private const IC = '00000043';

    private Connection $db;
    private SupplierRegistryEnricher $enricher;
    private int $supplierId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->enricher = $container->get(SupplierRegistryEnricher::class);
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        self::assertGreaterThan(0, $this->supplierId, 'Testovací databáze musí obsahovat syntetického dodavatele.');
        $pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testAresLegalFormFillsEmptySupplierValue(): void
    {
        $this->setLegalForm(null);
        $this->seedAresLegalForm('706');

        $this->enricher->enrich($this->supplierId, self::IC, null);

        self::assertSame('706', $this->legalForm());
    }

    public function testAresLegalFormDoesNotOverwriteExistingValue(): void
    {
        $this->setLegalForm('145');
        $this->seedAresLegalForm('706');

        $this->enricher->enrich($this->supplierId, self::IC, null);

        self::assertSame('145', $this->legalForm());
    }

    public function testMalformedAresLegalFormIsNotPersisted(): void
    {
        $this->setLegalForm(null);
        $this->seedAresLegalForm('7060');

        $this->enricher->enrich($this->supplierId, self::IC, null);

        self::assertNull($this->legalForm());
    }

    private function seedAresLegalForm(string $legalForm): void
    {
        $payload = json_encode([
            'normalization_version' => AresClient::NORMALIZATION_VERSION,
            'found' => true,
            'data' => ['legal_form' => $legalForm],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->db->pdo()->prepare(
            'INSERT INTO ares_cache (ic, payload) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = NOW()'
        )->execute([self::IC, $payload]);
    }

    private function setLegalForm(?string $legalForm): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET legal_form = ? WHERE id = ?')
            ->execute([$legalForm, $this->supplierId]);
    }

    private function legalForm(): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT legal_form FROM supplier WHERE id = ?');
        $stmt->execute([$this->supplierId]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }
}
