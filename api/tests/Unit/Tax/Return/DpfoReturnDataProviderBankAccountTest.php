<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tax\Return\DpfoReturnDataProvider;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * `DpfoReturnDataProvider::bankAccount()` — jediný podklad, odkud VetaN (žádost o
 * vrácení přeplatku DPFO) může vzít bankovní spojení; chyběl úplně (private/DANE-PLAN.md
 * §4/§7 nález 2). Stejný zdroj/dotaz jako u DPPO ({@see DppoReturnDataProviderNewFieldsTest}).
 *
 * Instance se staví přes `newInstanceWithoutConstructor()` a testuje se přes reflexi —
 * `bankAccount()` je čistě `$this->db` dotaz, ostatní závislosti (CashJournalService,
 * NonDeductibleCostsService…) mají SVOJI vlastní hlubokou závislostní strukturu
 * (CashJournalRepository, CnbExchangeRateClient, LoggerInterface…) a jsou `final`, takže
 * je nejde ani mockovat — skládat je jen kvůli téhle metodě by test zbytečně zatížilo.
 */
final class DpfoReturnDataProviderBankAccountTest extends TestCase
{
    private PDO $pdo;
    private DpfoReturnDataProvider $provider;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE currencies (id INTEGER PRIMARY KEY, supplier_id INTEGER, code TEXT, account_number TEXT, bank_code TEXT, bank_name TEXT, iban TEXT, is_default INTEGER, is_active INTEGER)');

        $config = $this->createStub(\MyInvoice\Infrastructure\Config\Config::class);
        $db = new Connection($config);
        (new \ReflectionClass($db))->getProperty('pdo')->setValue($db, $this->pdo);

        $this->provider = (new \ReflectionClass(DpfoReturnDataProvider::class))->newInstanceWithoutConstructor();
        (new \ReflectionClass($this->provider))->getProperty('db')->setValue($this->provider, $db);
    }

    private function bankAccount(int $supplierId): ?array
    {
        $method = (new \ReflectionClass($this->provider))->getMethod('bankAccount');
        return $method->invoke($this->provider, $supplierId);
    }

    private function currency(int $id, int $supplierId, string $code, ?string $accountNumber, ?string $bankCode, ?string $bankName, ?string $iban, int $isDefault, int $isActive): void
    {
        $this->pdo->prepare(
            'INSERT INTO currencies (id, supplier_id, code, account_number, bank_code, bank_name, iban, is_default, is_active) VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([$id, $supplierId, $code, $accountNumber, $bankCode, $bankName, $iban, $isDefault, $isActive]);
    }

    public function testReturnsDefaultCzkAccount(): void
    {
        $this->currency(1, 1, 'CZK', '19-2000145399', '0800', 'Česká spořitelna', null, 0, 1);
        $this->currency(2, 1, 'CZK', '2000145399', '0100', 'Komerční banka', null, 1, 1);
        $this->currency(3, 1, 'EUR', null, null, 'Fio banka', 'CZ0000000000001234567890', 1, 1);

        $account = $this->bankAccount(1);
        self::assertNotNull($account);
        self::assertSame('2000145399', $account['account_number']);
        self::assertSame('0100', $account['bank_code']);
    }

    public function testNullWhenNoCzkCurrency(): void
    {
        $this->currency(1, 1, 'EUR', null, null, 'Fio banka', 'CZ0000000000001234567890', 1, 1);

        self::assertNull($this->bankAccount(1));
    }

    public function testIgnoresInactiveCzkAccount(): void
    {
        $this->currency(1, 1, 'CZK', '2000145399', '0100', 'Komerční banka', null, 1, 0);

        self::assertNull($this->bankAccount(1));
    }
}
