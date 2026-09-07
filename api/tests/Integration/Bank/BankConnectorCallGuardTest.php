<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\NamedLockName;
use MyInvoice\Service\Bank\Connector\BankConnectorCallGuard;
use MyInvoice\Service\Bank\Connector\BankConnectorOperationException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class BankConnectorCallGuardTest extends TestCase
{
    public function testTokenOnlyCredentialDoesNotRequireCertificateBeforeCallback(): void
    {
        $db = Bootstrap::buildContainer()->get(Connection::class);
        $key = random_bytes(32);
        $credential = json_encode(['bearer_token' => bin2hex(random_bytes(32)), 'account_id' => 'synthetic', 'certificate' => '', 'password' => ''], JSON_THROW_ON_ERROR);
        $config = $this->createStub(Config::class);
        $config->method('get')->willReturn(base64_encode($key));
        try {
            self::assertSame('synthetic-ok', (new BankConnectorCallGuard($db, $config))->call($credential, static fn (): string => 'synthetic-ok'));
        } finally {
            $db->pdo()->prepare('DELETE FROM bank_connector_cooldowns WHERE credential_hash = ?')->execute([hash_hmac('sha256', $credential, $key)]);
        }
    }
    public function testConnectionLockUsesDatabaseScopeAndIsReleasedAfterFailure(): void
    {
        $db = Bootstrap::buildContainer()->get(Connection::class);
        $config = $this->createStub(Config::class);
        $guard = new BankConnectorCallGuard($db, $config);
        $supplierId = random_int(100000, 999999);
        $name = NamedLockName::for($db, 'bank-connection', $supplierId . ':17');
        try {
            $guard->withConnectionLock($supplierId, 17, function () use ($db, $name): void {
                $probe = $db->pdo()->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
                $probe->execute([$name]);
                self::assertSame(1, (int) $probe->fetchColumn());
                throw new \RuntimeException('synthetic failure');
            });
            self::fail('Callback failure must propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('synthetic failure', $e->getMessage());
        }
        $probe = $db->pdo()->prepare('SELECT IS_FREE_LOCK(?)');
        $probe->execute([$name]);
        self::assertSame(1, (int) $probe->fetchColumn());
    }

    public function testCooldownUsesDatabaseClockAndDoesNotInvokeBlockedCallback(): void
    {
        $db = Bootstrap::buildContainer()->get(Connection::class);
        $key = random_bytes(32);
        $token = bin2hex(random_bytes(32));
        $hash = hash_hmac('sha256', $token, $key);
        $config = $this->createStub(Config::class);
        $config->method('get')->willReturn(base64_encode($key));
        $guard = new BankConnectorCallGuard($db, $config);
        $calls = 0;
        $callback = static function () use (&$calls): int { return ++$calls; };
        $previousZone = date_default_timezone_get();
        date_default_timezone_set('Pacific/Honolulu');
        try {
            self::assertSame(1, $guard->call($token, $callback));
            try {
                $guard->call($token, $callback);
                self::fail('Immediate retry must be blocked.');
            } catch (BankConnectorOperationException $e) {
                self::assertSame('bank_rate_limited', $e->errorCode);
            }
            self::assertSame(1, $calls);
            $db->pdo()->prepare('UPDATE bank_connector_cooldowns SET last_called_at = CURRENT_TIMESTAMP(6) - INTERVAL 31 SECOND WHERE credential_hash = ?')->execute([$hash]);
            self::assertSame(2, $guard->call($token, $callback));
        } finally {
            date_default_timezone_set($previousZone);
            $db->pdo()->prepare('DELETE FROM bank_connector_cooldowns WHERE credential_hash = ?')->execute([$hash]);
        }
    }
}
