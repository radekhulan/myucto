<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Backup;

use MyInvoice\Service\Backup\ReadableDocumentArchiveLayout;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class ReadableDocumentArchiveWarningsTest extends TestCase
{
    private string $temp;
    private string|false $previousDataDir;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->temp = sys_get_temp_dir() . '/archive-warnings-' . bin2hex(random_bytes(8));
        mkdir($this->temp, 0700, true);
        $this->previousDataDir = getenv('MYINVOICE_DATA_DIR');
        putenv('MYINVOICE_DATA_DIR=' . $this->temp);
        $this->pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE document_folders (id INTEGER, supplier_id INTEGER, parent_id INTEGER, name TEXT, deleted_at TEXT)');
        $this->pdo->exec('CREATE TABLE documents (id INTEGER, supplier_id INTEGER, folder_id INTEGER, title TEXT, original_name TEXT, filename TEXT, sha256 TEXT, deleted_at TEXT)');
        $this->pdo->exec('CREATE TABLE document_files (id INTEGER, document_id INTEGER, supplier_id INTEGER, role TEXT, original_name TEXT, filename TEXT, sha256 TEXT, deleted_at TEXT, sort_order INTEGER)');
        $this->pdo->exec('CREATE TABLE journal_entry_attachments (id INTEGER, supplier_id INTEGER, entry_id INTEGER, sha256 TEXT, filename TEXT, original_name TEXT)');
    }

    protected function tearDown(): void
    {
        putenv($this->previousDataDir === false ? 'MYINVOICE_DATA_DIR' : 'MYINVOICE_DATA_DIR=' . $this->previousDataDir);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->temp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->temp);
    }

    public function testReportsMissingSourcesOncePerReferenceWithoutLeakingForeignTenantOrFileNames(): void
    {
        $sha = hash('sha256', 'synthetic-existing-document');
        $stmt = $this->pdo->prepare('INSERT INTO documents VALUES (?, ?, NULL, ?, ?, ?, ?, NULL)');
        $stmt->execute([11, 7, 'Synthetic missing', 'original-sensitive-name.pdf', 'missing.pdf', str_repeat('a', 64)]);
        $stmt->execute([21, 8, 'Foreign missing', 'foreign-name.pdf', 'foreign.pdf', str_repeat('b', 64)]);
        $stmt = $this->pdo->prepare('INSERT INTO document_files VALUES (?, 11, 7, ?, ?, ?, ?, NULL, ?)');
        $stmt->execute([31, 'attachment', 'extra-name.pdf', 'extra.pdf', str_repeat('c', 64), 1]);
        $stmt->execute([32, 'attachment', 'existing.pdf', 'existing.pdf', $sha, 2]);
        $stmt = $this->pdo->prepare('INSERT INTO journal_entry_attachments VALUES (?, ?, 10, ?, ?, ?)');
        $stmt->execute([81, 7, str_repeat('d', 64), 'missing.pdf', 'journal-sensitive-name.pdf']);
        $stmt->execute([82, 7, '', 'invalid.pdf', 'invalid-name.pdf']);
        $stmt->execute([83, 7, $sha, 'existing.pdf', 'existing.pdf']);
        $stmt->execute([91, 8, str_repeat('e', 64), 'foreign.pdf', 'foreign-name.pdf']);
        foreach (['documents', 'journal'] as $kind) {
            $directory = $this->temp . '/storage/' . $kind . '/sup-7/' . substr($sha, 0, 2);
            mkdir($directory, 0700, true);
            file_put_contents($directory . '/existing.pdf', 'synthetic-existing-document');
        }
        $warnings = [];
        $layout = new ReadableDocumentArchiveLayout($this->pdo);
        $files = $layout->forSupplier(7, 'documents', 'journal', static function (array $warning) use (&$warnings): void {
            $warnings[] = $warning;
        });
        self::assertCount(2, $files);
        self::assertSame([
            ['table' => 'documents', 'id' => 11, 'reason' => 'missing_source_file'],
            ['table' => 'document_files', 'id' => 31, 'reason' => 'missing_source_file'],
            ['table' => 'journal_entry_attachments', 'id' => 81, 'reason' => 'missing_source_file'],
            ['table' => 'journal_entry_attachments', 'id' => 82, 'reason' => 'incomplete_source_metadata'],
        ], $warnings);
        self::assertSame($files, $layout->forSupplier(7, 'documents', 'journal'));
    }

    public function testReportsUnreadableMetadataInsteadOfSilentlyReturningAnEmptyExport(): void
    {
        $this->pdo->exec('DROP TABLE document_files');
        $this->pdo->exec('DROP TABLE journal_entry_attachments');
        $warnings = [];
        $files = (new ReadableDocumentArchiveLayout($this->pdo))->forSupplier(7, 'documents', 'journal', static function (array $warning) use (&$warnings): void {
            $warnings[] = $warning;
        });
        self::assertSame([], $files);
        self::assertSame([
            ['table' => 'documents', 'id' => null, 'reason' => 'source_query_failed'],
            ['table' => 'journal_entry_attachments', 'id' => null, 'reason' => 'source_query_failed'],
        ], $warnings);
    }
}
