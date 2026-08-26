<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Backup;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Backup\ReadableDocumentArchiveLayout;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\Document\JournalAttachmentStorage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class ReadableDocumentArchiveLayoutTest extends TestCase
{
    private Connection $db;
    private int $supplierId = 0;
    private bool $inTransaction = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->db = Bootstrap::buildApp()->getContainer()->get(Connection::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $countryId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($currencyId === 0 || $vatRateId === 0 || $countryId === 0) {
            $this->markTestSkipped('Chybí základní data.');
        }

        $pdo->beginTransaction();
        $this->inTransaction = true;
        $stmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovaci 1", "Praha", "11000", ?, ?, ?, ?)'
        );
        $stmt->execute(['Čitelný ZIP s.r.o.', $countryId, 'readable-zip@example.com', $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $this->removeDir(DocumentStorage::baseDir($this->supplierId));
        $this->removeDir(JournalAttachmentStorage::baseDir($this->supplierId));
    }

    protected function tearDown(): void
    {
        if ($this->supplierId > 0) {
            $this->removeDir(DocumentStorage::baseDir($this->supplierId));
            $this->removeDir(JournalAttachmentStorage::baseDir($this->supplierId));
        }
        if (isset($this->db) && $this->inTransaction) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testDmsFileUsesFolderAndOriginalNameInsteadOfHash(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO document_folders (supplier_id, name) VALUES (?, ?)')
            ->execute([$this->supplierId, 'Smlouvy 2026']);
        $folderId = (int) $pdo->lastInsertId();

        $bytes = 'synthetic document bytes';
        $sha = hash('sha256', $bytes);
        $dir = DocumentStorage::baseDir($this->supplierId) . '/' . substr($sha, 0, 2);
        self::assertTrue(@mkdir($dir, 0775, true) || is_dir($dir));
        file_put_contents($dir . '/' . $sha, $bytes);

        $pdo->prepare(
            'INSERT INTO documents
                (supplier_id, folder_id, title, original_name, filename, sha256, mime_type, size_bytes, doc_type)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $folderId,
            'Rámcová smlouva',
            'Rámcová smlouva 2026.pdf',
            $sha,
            $sha,
            'application/pdf',
            strlen($bytes),
            'pdf',
        ]);

        $items = (new ReadableDocumentArchiveLayout($pdo))->forSupplier(
            $this->supplierId,
            'prilohy/dokumenty',
            'prilohy/denik',
        );

        self::assertCount(1, $items);
        self::assertSame(
            'prilohy/dokumenty/Smlouvy_2026/Ramcova_smlouva_2026.pdf',
            $items[0]['entry'],
        );
        self::assertSame($sha, $items[0]['sha256']);
        self::assertStringNotContainsString('/' . $sha, $items[0]['entry']);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
