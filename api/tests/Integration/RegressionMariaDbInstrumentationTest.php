<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Tests\Unit\RegressionInstrumentationTest;
use MyUctoRegression\Safety;
use PDO;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Unit/RegressionInstrumentationTest.php';

final class RegressionMariaDbInstrumentationTest extends TestCase
{
    public function testReadOnlyPreservesOriginalMariaDbSessionAndRejectsCaughtWrite(): void
    {
        if (getenv('MYINVOICE_REGRESSION_SYNTHETIC_DB') !== '1') {
            self::markTestSkipped('Explicit opt-in creates and drops an owned synthetic test database.');
        }
        $config = Config::load(dirname(__DIR__, 3));
        $host = (string) $config->get('db.host', '127.0.0.1');
        Safety::host($host, null);
        $name = 'myucto_regression_' . bin2hex(random_bytes(8)) . '_test';
        Safety::database($name, (string) $config->get('db.name'));
        $server = new PDO('mysql:host=' . $host . ';port=' . (int) $config->get('db.port', 3306) . ';charset=utf8mb4', (string) $config->get('db.user'), (string) $config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $owned = false;
        try {
            $server->exec('CREATE DATABASE `' . $name . '`');
            $owned = true;
            $server->exec('CREATE TABLE `' . $name . '`.regression_probe (id INT PRIMARY KEY, amount DECIMAL(30,10), nullable DECIMAL(30,10) NULL) ENGINE=InnoDB');
            $server->exec("INSERT INTO `" . $name . "`.regression_probe VALUES (1, 12345678901234567890.1234567890, NULL), (2, 0.0000000000, NULL)");
            $original = RegressionInstrumentationTest::probe('maria-original', $name);
            $guarded = RegressionInstrumentationTest::probe('maria-guarded', $name);
            self::assertSame([1, 1], $guarded['read_only']);
            self::assertSame(['sql_write_or_unsafe_statement', 'database_error'], $guarded['violations']);
            unset($guarded['read_only'], $guarded['violations']);
            self::assertSame($original, $guarded);
            self::assertTrue($guarded['same_instance']);
            self::assertTrue($guarded['shared_instance']);
            self::assertTrue($guarded['separate_instance']);
            self::assertTrue($guarded['transaction']);
            self::assertFalse($guarded['after_rollback']);
            $savepointOriginal = RegressionInstrumentationTest::probe('maria-savepoint-original', $name);
            $savepointGuarded = RegressionInstrumentationTest::probe('maria-savepoint-guarded', $name);
            self::assertSame([1, 1], $savepointGuarded['read_only']);
            self::assertSame(['sql_write_or_unsafe_statement'], $savepointGuarded['violations']);
            self::assertTrue($savepointGuarded['forged_caller_rejected']);
            self::assertTrue($savepointGuarded['dml_rejected']);
            unset($savepointGuarded['read_only'], $savepointGuarded['violations'], $savepointGuarded['forged_caller_rejected'], $savepointGuarded['dml_rejected']);
            self::assertSame($savepointOriginal, $savepointGuarded);
            self::assertSame(['nested' => true, 'rollback_executed' => true, 'data_unchanged' => true,
                'transaction_active' => true, 'after_rollback' => false], $savepointOriginal);
            self::assertSame('12345678901234567890.1234567890', $server->query('SELECT amount FROM `' . $name . '`.regression_probe WHERE id = 1')->fetchColumn());
        } finally {
            if ($owned) {
                Safety::database($name, (string) $config->get('db.name'));
                $server->exec('DROP DATABASE `' . $name . '`');
                $absent = $server->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?');
                $absent->execute([$name]);
                self::assertSame(0, (int) $absent->fetchColumn());
            }
        }
    }
}
