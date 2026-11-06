<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Ares;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientBankAccountRepository;
use MyInvoice\Service\Ares\ClientBankAccountRegistrySynchronizer;
use MyInvoice\Service\Ares\CrpDphClient;
use MyInvoice\Service\Payment\IbanValidator;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ClientBankAccountRegistrySynchronizerTest extends TestCase
{
    private array $registryQueries = [];
    private array $accountQueries = [];
    private array $accountWrites = [];

    private function synchronizer(): ClientBankAccountRegistrySynchronizer
    {
        $config = new Config(['crpdph' => ['endpoint' => '', 'cache_ttl' => 86400]]);
        $registryPdo = $this->createStub(PDO::class);
        $registryPdo->method('prepare')->willReturnCallback(function (string $sql): PDOStatement {
            $this->registryQueries[] = $sql;
            $stmt = $this->createStub(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $stmt->method('fetchColumn')->willReturn(json_encode([
                'found' => true,
                'accounts' => [[
                    'prefix' => '', 'number' => '1000000005', 'bank_code' => '0100', 'iban' => null,
                ]],
            ]));
            return $stmt;
        });
        $accountPdo = $this->createStub(PDO::class);
        $accountPdo->method('prepare')->willReturnCallback(function (string $sql): PDOStatement {
            $this->accountQueries[] = $sql;
            $stmt = $this->createStub(PDOStatement::class);
            $stmt->method('execute')->willReturnCallback(function (array $params) use ($sql): bool {
                if (str_starts_with($sql, 'INSERT')) {
                    $this->accountWrites[] = $params;
                }
                return true;
            });
            $stmt->method('fetchColumn')->willReturn(str_starts_with($sql, 'SELECT 1 FROM clients') ? 1 : false);
            return $stmt;
        });
        $accountPdo->method('lastInsertId')->willReturn('1');
        $registryDb = new Connection($config);
        $accountsDb = new Connection($config);
        $pdoProperty = new \ReflectionProperty(Connection::class, 'pdo');
        $pdoProperty->setValue($registryDb, $registryPdo);
        $pdoProperty->setValue($accountsDb, $accountPdo);
        return new ClientBankAccountRegistrySynchronizer(
            new CrpDphClient($config, $registryDb, new NullLogger()),
            new ClientBankAccountRepository($accountsDb, new IbanValidator()),
        );
    }

    public static function groupDics(): array
    {
        return [
            's prefixem' => ['CZ699000000'],
            'bez prefixu' => ['699000000'],
            'formátované' => [' cz 699 000 000 '],
        ];
    }

    #[DataProvider('groupDics')]
    public function testGroupAccountsAreNeverAssignedToIndividualMember(string $dic): void
    {
        $result = $this->synchronizer()->sync(1, 2, $dic);

        self::assertSame(['found' => false, 'source' => 'group_registration', 'synced' => 0], $result);
        self::assertSame([], $this->registryQueries, 'Skupinové účty se nemají ani načítat.');
        self::assertSame([], $this->accountQueries, 'Existující účty člena se nesmějí přepsat ani smazat.');
        self::assertSame([], $this->accountWrites);
    }

    public static function individualDics(): array
    {
        return [
            'běžné IČO' => ['CZ00000000'],
            'kmen 699 s osmi číslicemi' => ['CZ69900000'],
            'kmen 699 s deseti číslicemi' => ['CZ6990000000'],
        ];
    }

    #[DataProvider('individualDics')]
    public function testIndividualRegistrationStillSynchronizesPublishedAccounts(string $dic): void
    {
        $result = $this->synchronizer()->sync(1, 2, $dic);

        self::assertSame(['found' => true, 'source' => 'cache', 'synced' => 1], $result);
        self::assertCount(1, $this->registryQueries);
        self::assertCount(1, $this->accountWrites);
        self::assertSame([2, 1, '1000000005', '0100'], array_slice($this->accountWrites[0], 0, 4));
        self::assertSame(1, $this->accountWrites[0][8], 'Účet má zachovat zdroj registru DPH.');
    }

    public function testExistingInputGuardsStillPrecedeGroupDetection(): void
    {
        $sync = $this->synchronizer();
        self::assertSame(['found' => false, 'source' => 'unsupported', 'synced' => 0], $sync->sync(1, 2, 'SK699000000'));
        self::assertSame(['found' => false, 'source' => 'invalid', 'synced' => 0], $sync->sync(1, 2, 'CZ699000'));
        self::assertSame([], $this->registryQueries);
        self::assertSame([], $this->accountQueries);
    }
}
