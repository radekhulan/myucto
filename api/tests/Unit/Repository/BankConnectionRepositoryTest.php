<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankConnectionRepository;
use MyInvoice\Repository\BankPaymentOrderSubmissionRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class BankConnectionRepositoryTest extends TestCase
{
    private PDO $pdo;
    private Connection $db;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE currencies (
                id INTEGER PRIMARY KEY, supplier_id INTEGER NOT NULL, code TEXT NOT NULL,
                label TEXT NOT NULL, is_active INTEGER NOT NULL, account_number TEXT,
                bank_code TEXT, iban TEXT
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE bank_connections (
                id INTEGER PRIMARY KEY AUTOINCREMENT, supplier_id INTEGER NOT NULL,
                currency_id INTEGER NOT NULL, provider TEXT NOT NULL, token_ciphertext TEXT,
                enabled INTEGER NOT NULL, verified_account_number TEXT,
                verified_bank_code TEXT, verified_currency TEXT, validated_at TEXT,
                sync_watermark_date TEXT, last_sync_at TEXT, last_sync_status TEXT,
                last_sync_error_code TEXT, disconnected_at TEXT, created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL, UNIQUE (supplier_id, currency_id)
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE bank_payment_order_submissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT, supplier_id INTEGER NOT NULL,
                payment_order_id INTEGER, payroll_batch_id INTEGER, connection_id INTEGER, provider TEXT NOT NULL,
                payload_hash TEXT NOT NULL, submitted_by_user_id INTEGER, status TEXT NOT NULL,
                provider_reference TEXT, error_code TEXT, accepted_count INTEGER,
                rejected_count INTEGER, remote_http_status INTEGER, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                submitted_at TEXT, UNIQUE (supplier_id, payment_order_id), UNIQUE (supplier_id, payroll_batch_id)
            )'
        );

        $this->db = $this->createStub(Connection::class);
        $this->db->method('pdo')->willReturn($this->pdo);
    }

    public function testPublicConnectionReadIsTenantScopedAndOmitsCredential(): void
    {
        $this->pdo->exec("INSERT INTO currencies VALUES
            (11, 1, 'CZK', 'Fio CZK', 1, '1000000005', '2010', NULL),
            (22, 2, 'CZK', 'Foreign Fio', 1, '1000000005', '2010', NULL)");
        $this->pdo->exec("INSERT INTO bank_connections
            (id, supplier_id, currency_id, provider, token_ciphertext, enabled,
             verified_account_number, verified_bank_code, verified_currency,
             validated_at, created_at, updated_at)
            VALUES
            (101, 1, 11, 'fio', 'enc:v2:synthetic-secret-one', 1, '1000000005', '2010', 'CZK', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
            (202, 2, 22, 'fio', 'enc:v2:synthetic-secret-two', 1, '1000000005', '2010', 'CZK', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");

        $repository = new BankConnectionRepository($this->db);
        $connections = $repository->listPublic(1);

        self::assertCount(1, $connections);
        self::assertSame(101, $connections[0]['id']);
        self::assertTrue($connections[0]['has_token']);
        self::assertArrayNotHasKey('token_ciphertext', $connections[0]);
        self::assertStringNotContainsString('synthetic-secret', json_encode($connections, JSON_THROW_ON_ERROR));
        self::assertNull($repository->findPublicByCurrency(1, 22));
        self::assertNull($repository->findWithCredentialById(1, 202));
    }

    public function testDuplicateAndAmbiguousSubmissionRemainTerminalAndTenantScoped(): void
    {
        $repository = new BankPaymentOrderSubmissionRepository($this->db);
        $first = $repository->begin(1, 501, 101, 'fio', str_repeat('a', 64), 7);
        self::assertTrue($first['created']);

        $repository->fail(1, (int) $first['submission']['id'], 'unknown', 'fio_partial_result', 1, 1, 200);
        $duplicate = $repository->begin(1, 501, 101, 'fio', str_repeat('b', 64), 8);

        self::assertFalse($duplicate['created']);
        self::assertSame('unknown', $duplicate['submission']['status']);
        self::assertSame(1, $duplicate['submission']['accepted_count']);
        self::assertSame(1, $duplicate['submission']['rejected_count']);
        self::assertArrayNotHasKey('payload_hash', $duplicate['submission']);
        self::assertArrayNotHasKey('submitted_by_user_id', $duplicate['submission']);
        self::assertNull($repository->find(2, 501));
    }

    public function testInvoiceAndPayrollIdsHaveIndependentIdempotency(): void
    {
        $repository = new BankPaymentOrderSubmissionRepository($this->db);
        $invoice = $repository->begin(1, 501, 101, 'fio', str_repeat('a', 64), 7);
        $payroll = $repository->begin(1, 501, 101, 'fio', str_repeat('b', 64), 7, 'payroll');
        self::assertTrue($invoice['created']);
        self::assertTrue($payroll['created']);
        self::assertNotSame($invoice['submission']['id'], $payroll['submission']['id']);
        self::assertSame(501, $payroll['submission']['payroll_batch_id']);
        self::assertArrayNotHasKey('payment_order_id', $payroll['submission']);
        self::assertFalse($repository->begin(1, 501, 101, 'fio', str_repeat('b', 64), 7, 'payroll')['created']);
        self::assertNull($repository->find(2, 501, 'payroll'));
    }

    public function testDisconnectRemovesUnusedConnectionButPreservesSubmissionHistory(): void
    {
        $this->pdo->exec("INSERT INTO currencies VALUES (11, 1, 'CZK', 'Synthetic', 1, '1000000005', '2010', NULL)");
        $insert = "INSERT INTO bank_connections (id, supplier_id, currency_id, provider, token_ciphertext, enabled, created_at, updated_at)
            VALUES (101, 1, 11, 'fio', 'enc:v2:synthetic', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)";
        $this->pdo->exec($insert);
        $connections = new BankConnectionRepository($this->db);
        self::assertTrue($connections->disconnect(1, 11));
        self::assertNull($connections->findPublicByCurrency(1, 11));

        $this->pdo->exec($insert);
        $submissions = new BankPaymentOrderSubmissionRepository($this->db);
        $submissions->begin(1, 501, 101, 'fio', str_repeat('a', 64), null);
        self::assertTrue($connections->disconnect(1, 11));
        $saved = $connections->findPublicByCurrency(1, 11);
        self::assertNotNull($saved);
        self::assertFalse($saved['has_token']);
        self::assertFalse($saved['enabled']);
        self::assertNotNull($submissions->find(1, 501));
    }
}
