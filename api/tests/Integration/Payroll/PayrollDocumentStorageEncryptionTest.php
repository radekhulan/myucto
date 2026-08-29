<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Document\PayrollDocumentKeyDestroyedException;
use MyInvoice\Service\Payroll\Document\PayrollDocumentKeyRing;
use MyInvoice\Service\Payroll\Document\PayrollDocumentStorage;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * W30 / C-05 + C-06 — šifrované úložiště mzdových dokumentů a krypto-výmaz.
 *
 * Test dřív běžel jako čistě jednotkový nad `new PayrollDocumentStorage()`.
 * Od zavedení datových klíčů je úložiště závislé na databázi (klíče se v ní
 * evidují a zahazují), takže patří mezi integrační testy — to je cena za to,
 * že se klíč dá zahodit auditovaně a ne jen smazáním souboru.
 */
#[Group('integration')]
final class PayrollDocumentStorageEncryptionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollDocumentStorage $storage;
    private PayrollDocumentKeyRing $keys;
    private int $supplierId;
    private string|false $previousDataDir;
    private string $dataDir;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $pdo = $this->db->pdo();
        $tables = $pdo->query(
            "SHOW TABLES LIKE 'payroll_document_data_keys'",
        );
        if ($tables === false || $tables->fetchColumn() === false) {
            self::markTestSkipped('Tabulka datových klíčů zatím neexistuje.');
        }
        $this->previousDataDir = getenv('MYINVOICE_DATA_DIR');
        $this->dataDir = sys_get_temp_dir()
            . '/myucto-payroll-doc-' . bin2hex(random_bytes(6));
        putenv('MYINVOICE_DATA_DIR=' . $this->dataDir);

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, 1);
        $this->keys = new PayrollDocumentKeyRing(
            $this->db,
            $container->get(\MyInvoice\Service\Auth\SecretEncryption::class),
        );
        $this->storage = new PayrollDocumentStorage($this->keys);
    }

    protected function tearDown(): void
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $this->removeDirectory($this->dataDir);
        $this->previousDataDir === false
            ? putenv('MYINVOICE_DATA_DIR')
            : putenv('MYINVOICE_DATA_DIR=' . $this->previousDataDir);
    }

    /**
     * Adresování obsahem drží dál: název souboru je sha256 PLAINTEXTU, takže
     * `storage_key = file_sha256` (CHECK v migraci 1230) platí i po zašifrování
     * a druhý zápis téhož obsahu nezaloží druhý soubor.
     */
    public function testStoresContentAddressedAndReadsOnlyVerifiedBytes(): void
    {
        $bytes = '%PDF-1.4 synthetic';

        $first = $this->storage->store($this->supplierId, $bytes, null, 4242);
        $second = $this->storage->store($this->supplierId, $bytes, null, 4242);

        self::assertSame(hash('sha256', $bytes), $first['storage_key']);
        self::assertSame($first, $second);
        self::assertSame(
            $bytes,
            $this->storage->readVerified(
                $this->supplierId,
                $first['storage_key'],
                4242,
            ),
        );
        self::assertStringNotContainsString(
            'synthetic',
            basename($first['path']),
        );
    }

    /** Na disku nesmí ležet čitelné PDF — to byl celý nález C-05. */
    public function testStoredFileIsNotReadablePlaintext(): void
    {
        $bytes = '%PDF-1.4 rodne cislo 7001010009';
        $stored = $this->storage->store($this->supplierId, $bytes, null, 77);

        $raw = (string) file_get_contents($stored['path']);
        self::assertStringNotContainsString('7001010009', $raw);
        self::assertStringNotContainsString('%PDF', $raw);
        self::assertNotSame(
            hash('sha256', $raw),
            $stored['file_sha256'],
            'Soubor na disku nesmí být totožný s plaintextem.',
        );
    }

    /**
     * Krypto-výmaz (C-06): soubor i evidence zůstanou, obsah se stane
     * nečitelným. Tím se čl. 17 GDPR splní, aniž by se sáhlo na append-only
     * archiv podle migrace 1231.
     */
    public function testCryptoErasureLeavesArchiveIntactButUnreadable(): void
    {
        $bytes = '%PDF-1.4 payslip';
        $stored = $this->storage->store($this->supplierId, $bytes, null, 99);
        self::assertFileExists($stored['path']);

        self::assertSame(1, $this->keys->destroy(
            $this->supplierId,
            99,
            null,
            'test',
        ));
        // Idempotence: druhé zahození už nic nemění.
        self::assertSame(0, $this->keys->destroy(
            $this->supplierId,
            99,
            null,
            'test',
        ));

        self::assertFileExists(
            $stored['path'],
            'Krypto-výmaz nesmí mazat soubor — archiv je neměnný.',
        );
        $this->expectException(PayrollDocumentKeyDestroyedException::class);
        $this->storage->readVerified($this->supplierId, $stored['storage_key'], 99);
    }

    /** Do znečitelněného archivu se nesmí přidávat nový čitelný dokument. */
    public function testWriteAfterErasureIsRefused(): void
    {
        $this->storage->store($this->supplierId, '%PDF-1.4 a', null, 5);
        $this->keys->destroy($this->supplierId, 5, null, 'test');

        $this->expectException(PayrollDocumentKeyDestroyedException::class);
        $this->storage->store($this->supplierId, '%PDF-1.4 b', null, 5);
    }

    /** Klíč jedné osoby nesmí otevřít dokumenty jiné — AAD to váže. */
    public function testSubjectsAreCryptographicallySeparated(): void
    {
        $bytes = '%PDF-1.4 shared bytes';
        $first = $this->storage->store($this->supplierId, $bytes, null, 11);
        $this->storage->store($this->supplierId, $bytes, null, 22);

        $this->keys->destroy($this->supplierId, 11, null, 'test');

        // Druhý subjekt má vlastní kopii i vlastní klíč, výmaz prvního se ho
        // nedotkne.
        self::assertSame(
            $bytes,
            $this->storage->readVerified($this->supplierId, $first['storage_key'], 22),
        );
    }

    /** Klíč firemních dokumentů zahodit nejde — nejsou to údaje jedné osoby. */
    public function testCompanyKeyCannotBeDestroyed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->keys->destroy(
            $this->supplierId,
            PayrollDocumentKeyRing::COMPANY_SUBJECT_ID,
            null,
            'test',
        );
    }

    /**
     * Dokumenty uložené před zavedením šifrování leží v plaintextu na staré
     * cestě. Čtení je musí najít, jinak by se archiv stal nedostupným.
     */
    public function testLegacyPlaintextDocumentStaysReadable(): void
    {
        $bytes = '%PDF-1.4 legacy';
        $hash = hash('sha256', $bytes);
        $dir = PayrollDocumentStorage::baseDir($this->supplierId)
            . '/' . substr($hash, 0, 2);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents($dir . '/' . $hash, $bytes);

        self::assertSame(
            $bytes,
            $this->storage->readVerified($this->supplierId, $hash, 1234),
        );
    }

    /**
     * Na plaintext z doby před šifrováním krypto-výmaz nedosáhne — není čím
     * zahodit klíč. Vydat se proto nesmí ani tak; jinak by výmaz platil na
     * nové dokumenty a na staré ne.
     */
    public function testLegacyPlaintextIsNotServedAfterErasure(): void
    {
        // Klíč subjektu vznikne uložením libovolného dokumentu…
        $this->storage->store($this->supplierId, '%PDF-1.4 new', null, 55);
        // …a vedle něj leží starý nešifrovaný soubor téže osoby.
        $bytes = '%PDF-1.4 legacy of 55';
        $hash = hash('sha256', $bytes);
        $dir = PayrollDocumentStorage::baseDir($this->supplierId)
            . '/' . substr($hash, 0, 2);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        file_put_contents($dir . '/' . $hash, $bytes);
        self::assertSame(
            $bytes,
            $this->storage->readVerified($this->supplierId, $hash, 55),
        );

        $this->keys->destroy($this->supplierId, 55, null, 'test');

        $this->expectException(PayrollDocumentKeyDestroyedException::class);
        $this->storage->readVerified($this->supplierId, $hash, 55);
    }

    /**
     * Měsíční balíček je odvozený agregát pod firemním klíčem, takže by v něm
     * páska vymazané osoby zůstala čitelná. Krypto-výmaz proto vedle klíče
     * uklidí i SOUBORY balíčků, ve kterých osoba figurovala — řádky evidence
     * zůstávají, balíček jde vyrobit znovu z jednotlivých dokumentů.
     *
     * Tady se ověřuje smlouva a to, že dotaz nad `payroll_generated_documents`
     * skutečně proběhne; scénář s vydaným balíčkem má vlastní fixture ve
     * `PayrollRetentionServiceTest`.
     */
    public function testCryptoErasureReportsKeyAndBundleCounts(): void
    {
        $erasure = new \MyInvoice\Service\Payroll\Document\PayrollDocumentCryptoErasure(
            $this->db,
            $this->keys,
            $this->storage,
        );
        $this->storage->store($this->supplierId, '%PDF-1.4 x', null, 31);

        self::assertSame(
            [
                'keys_destroyed' => 1,
                'bundles_purged' => 0,
                'legacy_plaintext_purged' => 0,
            ],
            $erasure->erase($this->supplierId, 31, null, 'test'),
        );
        self::assertSame(
            [
                'keys_destroyed' => 0,
                'bundles_purged' => 0,
                'legacy_plaintext_purged' => 0,
            ],
            $erasure->erase($this->supplierId, 31, null, 'test'),
        );
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
