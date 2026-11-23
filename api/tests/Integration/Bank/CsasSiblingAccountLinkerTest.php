<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankConnectionRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Bank\Connector\CsasApiClient;
use MyInvoice\Service\Bank\Connector\CsasCredentialVault;
use MyInvoice\Service\Bank\Connector\CsasSiblingAccountLinker;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Propojení dalších účtů souhlasu ČS nad skutečnou databází: výběr účtů firmy u ČS,
 * uložení šifrované kopie přístupu vázané na nové napojení a respektování účtu, který
 * už má vlastní napojení nebo patří jiné bance či firmě.
 */
#[Group('integration')]
final class CsasSiblingAccountLinkerTest extends TestCase
{
    use IsolatedSupplierTrait;

    private ContainerInterface $container;
    private Connection $db;
    private PDO $pdo;
    private int $supplierId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $this->container = Bootstrap::buildContainer();
            $this->db = $this->container->get(Connection::class);
            $this->pdo = $this->db->pdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        if ($this->container->get(SecretEncryption::class)->validateKey() !== null) {
            $this->markTestSkipped('Chybí šifrovací klíč aplikace.');
        }
        $source = (int) ($this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0) $this->markTestSkipped('Testovací DB nemá firmu.');
        $this->pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $source);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->db)) $this->db->close();
    }

    public function testLinksOtherAccountsOfTheCompanyAtTheSameBankAndStoresTheirOwnCredential(): void
    {
        $primaryCurrency = $this->account('CZK', '1000000005', '0800');
        $eurCurrency = $this->account('EUR', '2000000018', '0800');
        $otherBank = $this->account('USD', '3000000004', '0100');
        $connections = $this->container->get(BankConnectionRepository::class);
        $vault = $this->container->get(CsasCredentialVault::class);
        $secrets = $this->container->get(SecretEncryption::class);
        $primaryConnection = $connections->ensure($this->supplierId, $primaryCurrency, 'csas');
        $primary = ['version' => 1, 'supplier_id' => $this->supplierId, 'connection_id' => $primaryConnection, 'api_key' => 'synthetic-key',
            'client_id' => 'synthetic-client', 'client_secret' => 'synthetic-secret', 'redirect_uri' => 'https://example.invalid/callback',
            'access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh', 'access_expires_at' => time() + 300,
            'account_id' => 'remote-czk', 'account_iban' => 'CZ7508000000001000000005', 'account_currency' => 'CZK',
            'call_guard_key' => str_repeat('a', 64), 'environment' => $this->container->get(CsasApiClient::class)->environment()];
        $connections->saveValidated($this->supplierId, $primaryConnection, 'csas',
            $secrets->encryptFor($vault->encode($primary), CsasCredentialVault::context($this->supplierId, $primaryConnection)), true,
            'CZ7508000000001000000005', '0800', 'CZK');
        $remote = [
            ['id' => 'remote-czk', 'identification' => ['iban' => 'CZ7508000000001000000005'], 'currency' => 'CZK'],
            ['id' => 'remote-eur', 'identification' => ['iban' => 'CZ6708000000002000000018'], 'currency' => 'EUR', 'suitableScope' => ['AISP' => '']],
        ];

        $results = $this->container->get(CsasSiblingAccountLinker::class)->link($primaryCurrency, $primary, $remote);

        self::assertCount(1, $results, 'Účet jiné banky se nenabízí.');
        self::assertSame([$eurCurrency, 'linked'], [$results[0]['currency_id'], $results[0]['status']]);
        $linked = $connections->findWithCredentialByCurrency($this->supplierId, $eurCurrency);
        self::assertNotNull($linked);
        self::assertSame(['csas', true, 'CZ6708000000002000000018', '0800', 'EUR'],
            [$linked['provider'], $linked['enabled'], $linked['verified_account_number'], $linked['verified_bank_code'], $linked['verified_currency']]);
        $stored = $vault->decode($secrets->decryptFor((string) $linked['token_ciphertext'], CsasCredentialVault::context($this->supplierId, $linked['id'])));
        self::assertSame([$linked['id'], 'remote-eur', 'synthetic-refresh'], [$stored['connection_id'], $stored['account_id'], $stored['refresh_token']]);
        self::assertNull($connections->findWithCredentialByCurrency($this->supplierId, $otherBank));

        $again = $this->container->get(CsasSiblingAccountLinker::class)->link($primaryCurrency, $primary, $remote);
        self::assertSame([['currency_id' => $eurCurrency, 'status' => 'already_linked']], $again, 'Opakované propojení nic nemění.');
    }

    private function account(string $code, string $number, string $bank): int
    {
        $this->pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default,
                account_number, bank_code) VALUES (?, ?, ?, 'X', 'Test', 'Test', 2, 1, 0, ?, ?)")
            ->execute([$this->supplierId, $code, $code . ' test', $number, $bank]);
        return (int) $this->pdo->lastInsertId();
    }
}
