<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\EmailNotice;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankEmailNoticeRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeScanner;
use MyInvoice\Service\Bank\EmailNotice\ImapMailboxClientInterface;
use MyInvoice\Service\Bank\EmailNotice\Parser\BankEmailNoticeParserRepository;
use MyInvoice\Service\Bank\EmailNotice\Parser\FioBankEmailNoticeParser;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\Cron\CronPreflight;
use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

final class BankEmailNoticeScanStatusTest extends TestCase
{
    private PDO $pdo;
    private Connection $db;

    protected function setUp(): void
    {
        $this->pdo = new Sqlite('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->createFunction('NOW', static fn (): string => '2026-10-21 12:00:00');
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $this->pdo->exec("CREATE TABLE bank_email_imap_settings (
            id INTEGER PRIMARY KEY, supplier_id INTEGER NOT NULL, name TEXT NOT NULL,
            enabled INTEGER NOT NULL DEFAULT 1, host TEXT DEFAULT 'imap.example.test',
            username TEXT DEFAULT 'notices@example.test', password_enc TEXT DEFAULT 'synthetic-password',
            port INTEGER DEFAULT 993, validate_cert INTEGER DEFAULT 1,
            max_messages_per_run INTEGER DEFAULT 50, retry_failed INTEGER DEFAULT 0,
            max_attempts INTEGER DEFAULT 3, distribute_user_id INTEGER,
            last_scan_at TEXT DEFAULT '2026-10-14 12:00:00', last_scan_status TEXT,
            last_scan_message TEXT
        )");
        $this->pdo->exec("INSERT INTO bank_email_imap_settings (id, supplier_id, name, enabled)
            VALUES (10, 1, 'Vypnutá schránka', 0), (20, 2, 'Schránka druhé firmy', 1)");
        $this->db = new Connection(new Config([]));
        (new ReflectionProperty(Connection::class, 'pdo'))->setValue($this->db, $this->pdo);
    }

    public function testEmptyMailboxUpdatesScanTimeForItsActualSupplier(): void
    {
        $imap = $this->createMock(ImapMailboxClientInterface::class);
        $imap->expects(self::once())->method('latest')->with(
            self::callback(static fn (array $settings): bool => $settings['supplier_id'] === 2),
            50,
        )->willReturn([]);
        $scanner = $this->scanner($imap);

        foreach (CronPreflight::bankEmailNoticeSupplierIds($this->pdo) as $supplierId) {
            $summary = $scanner->scanSupplier($supplierId);
            self::assertSame(0, $summary['errors']);
            self::assertSame(0, $summary['fetched']);
            self::assertCount(1, $summary['accounts']);
        }

        self::assertSame('2026-10-21 12:00:00', $this->scanStatus(20)['last_scan_at']);
        self::assertSame('ok', $this->scanStatus(20)['last_scan_status']);
        self::assertSame('2026-10-14 12:00:00', $this->scanStatus(10)['last_scan_at']);
    }

    public function testFailedMailboxIsCountedAsErrorAndNextMailboxStillRuns(): void
    {
        $this->pdo->exec("INSERT INTO bank_email_imap_settings (id, supplier_id, name) VALUES (21, 2, 'Zdravá schránka')");
        $imap = $this->createMock(ImapMailboxClientInterface::class);
        $imap->expects(self::exactly(2))->method('latest')->willReturnCallback(
            static function (array $settings): array {
                if ($settings['id'] === 20) {
                    throw new RuntimeException('Syntetická chyba spojení');
                }
                return [];
            },
        );

        $summary = $this->scanner($imap)->scanSupplier(2);

        self::assertSame('error', $this->scanStatus(20)['last_scan_status']);
        self::assertSame('ok', $this->scanStatus(21)['last_scan_status']);
        self::assertCount(2, $summary['accounts']);
        self::assertSame(1, $summary['errors'], 'Cron rozhoduje o výsledku podle součtu errors.');
    }

    /** @return array<string,mixed> */
    private function scanStatus(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT last_scan_at, last_scan_status FROM bank_email_imap_settings WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function scanner(ImapMailboxClientInterface $imap): BankEmailNoticeScanner
    {
        // Prázdné schránky ani chyba připojení matcher nepoužijí; žádná úhrada
        // ani odeslání pošty v těchto testech nesmí vzniknout.
        $matcher = (new ReflectionClass(StatementMatcher::class))->newInstanceWithoutConstructor();
        return new BankEmailNoticeScanner(
            new BankEmailNoticeRepository($this->db, new SecretEncryption(new Config([]))),
            new BankEmailNoticeParserRepository($this->db, [new FioBankEmailNoticeParser()]),
            $imap,
            $matcher,
        );
    }
}
