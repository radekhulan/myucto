<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Ai;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Ai\AnomalyDetector;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnomalyDetectorTest extends TestCase
{
    #[DataProvider('sources')]
    public function testDuplicateDetectionDistinguishesNoticesFromStatements(string $source, bool $duplicate): void
    {
        $pdo = PDO::connect('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->createFunction('REGEXP_REPLACE', static fn ($value, $pattern, $replacement) => preg_replace('/' . $pattern . '/', $replacement, $value));
        $pdo->createFunction('SIGN', static fn ($value) => $value <=> 0);
        $pdo->createFunction('DATEDIFF', static fn ($a, $b) => (strtotime($a) - strtotime($b)) / 86400);
        $pdo->exec('CREATE TABLE bank_statements (id INTEGER PRIMARY KEY, supplier_id INTEGER, source TEXT, account_number TEXT, bank_code TEXT)');
        $pdo->exec('CREATE TABLE bank_transactions (id INTEGER PRIMARY KEY, statement_id INTEGER, variable_symbol TEXT, amount REAL, posted_at TEXT)');
        $pdo->prepare('INSERT INTO bank_statements VALUES (1, 1, ?, ?, ?)')->execute([$source, '1000000005', '0100']);
        $pdo->exec("INSERT INTO bank_transactions VALUES (1, 1, '123', 100, '2026-09-01')");
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $detector = new AnomalyDetector($db);
        $tx = ['id' => 2, 'statement_supplier_id' => 1, 'recipient_account' => '1000000005',
            'recipient_bank' => '0100', 'variable_symbol' => '123', 'amount' => 100, 'posted_at' => '2026-09-02'];
        self::assertSame($duplicate ? ['duplicate_payment'] : [], array_column($detector->checkBankTx(1, $tx), 'code'));
        self::assertSame([], $detector->checkBankTx(2, $tx));
        $tx['amount'] = -100;
        self::assertSame([], $detector->checkBankTx(1, $tx));
    }

    public static function sources(): iterable
    {
        yield 'email notice is not another payment' => ['email_notice', false];
        yield 'API statement' => ['bank_api', true];
        yield 'ABO GPC statement' => ['gpc', true];
        yield 'PDF statement' => ['pdf', true];
    }
}
