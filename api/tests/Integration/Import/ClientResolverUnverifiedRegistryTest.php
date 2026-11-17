<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Service\Ares\AresClient;
use MyInvoice\Service\Ares\CrpDphClient;
use MyInvoice\Service\Ares\VendorVatPayerResolver;
use MyInvoice\Service\Ares\ViesClient;
use MyInvoice\Service\Import\ClientResolver;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Protistrana, kterou registr nepotvrdí (IČO není v ARES, DIČ neprošlo VIES), je
 * typicky špatně přečtený doklad. Import pak nesmí:
 *   - vymyslet adresu („—", „00000") a uložit ji do adresáře,
 *   - založit další variantu firmy, která už v adresáři je pod trochu jiným názvem,
 *   - prohlásit firmu za neplátce jen proto, že neprošlo špatně přečtené DIČ.
 *
 * Registry běží OFFLINE z předplněných cache tabulek (prázdné endpointy), data jsou
 * syntetická a transakce se v tearDown rollbackuje.
 */
#[Group('integration')]
final class ClientResolverUnverifiedRegistryTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const MISREAD_IC  = '99900013';
    private const MISREAD_DIC = 'CZ99900013';
    private const CARD_IC     = '99900021';

    private Connection $db;
    private ClientResolver $resolver;
    private ClientRepository $clients;
    private VendorVatPayerResolver $vatPayer;

    private int $supplierId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db      = $c->get(Connection::class);
            $this->clients = $c->get(ClientRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $offlineConfig = new Config([
            'ares'   => ['api' => '', 'timeout' => 5, 'cache_ttl' => 86400],
            'vies'   => ['rest_api' => '', 'wsdl' => '', 'timeout' => 8, 'cache_ttl' => 86400],
            'crpdph' => ['endpoint' => '', 'timeout' => 8, 'cache_ttl' => 86400],
        ]);
        $ares   = new AresClient($offlineConfig, $this->db, new NullLogger());
        $crpdph = new CrpDphClient($offlineConfig, $this->db, new NullLogger());
        $vies   = new ViesClient($offlineConfig, $this->db, new NullLogger(), $ares, $crpdph);
        $this->vatPayer = new VendorVatPayerResolver($ares, $vies, $this->clients);
        $this->resolver = new ClientResolver($this->db, $this->clients, $ares, $vies, $this->vatPayer);

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0) {
            $this->markTestSkipped('Chybí základní data (supplier).');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, "CZK", "CZK", "Kč", "CZK", "CZK", 2, 1, 1)'
        )->execute([$this->supplierId]);

        // ARES IČO nezná (špatně přečtené) a VIES DIČ odmítá.
        $pdo->prepare(
            "INSERT INTO ares_cache (ic, payload, fetched_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = NOW()"
        )->execute([self::MISREAD_IC, json_encode(['found' => false])]);
        $this->seedViesNegative(self::MISREAD_DIC);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testUnverifiedVendorCardGetsNoInventedAddress(): void
    {
        $result = $this->resolver->resolveVendor([
            'company_name' => 'Syntetická Dodávka s.r.o.',
            'ic'           => self::MISREAD_IC,
            'dic'          => self::MISREAD_DIC,
        ], $this->supplierId);

        self::assertTrue($result['created']);
        $card = $this->card($result['id']);
        self::assertSame('Syntetická Dodávka s.r.o.', $card['company_name']);
        self::assertSame('', $card['street'], 'Chybějící ulice nesmí dostat náhradu „—".');
        self::assertSame('', $card['city'], 'Chybějící město nesmí dostat náhradu „—".');
        self::assertSame('', $card['zip'], 'Chybějící PSČ nesmí dostat náhradu „00000".');
        self::assertSame(ClientResolver::UNVERIFIED_NOTE, $card['note'], 'Neověřená karta musí nést poznámku ke kontrole.');
    }

    public function testUnverifiedCustomerKeepsAddressReadFromDocument(): void
    {
        $result = $this->resolver->resolve([
            'company_name' => 'Syntetický Odběratel s.r.o.',
            'ic'           => self::MISREAD_IC,
            'street'       => 'Testovací 1',
            'city'         => 'Vzorov',
        ], $this->supplierId);

        $card = $this->card($result['id']);
        self::assertSame('Testovací 1', $card['street']);
        self::assertSame('Vzorov', $card['city']);
        self::assertSame('', $card['zip']);
    }

    public function testUnverifiedVendorIsMatchedToExistingCardByNormalizedName(): void
    {
        $existingId = $this->clients->create([
            'company_name' => 'Syntetická Dodávka, spol. s r.o.',
            'ic'           => self::CARD_IC,
            'dic'          => 'CZ' . self::CARD_IC,
            'street'       => 'Testovací 1',
            'city'         => 'Vzorov',
            'zip'          => '11000',
            'is_vendor'    => true,
            'is_customer'  => false,
        ], $this->supplierId);
        $this->clients->setVatPayer($existingId, true);

        $result = $this->resolver->resolveVendor([
            'company_name' => 'SYNTETICKA DODAVKA s.r.o.',
            'ic'           => self::MISREAD_IC,
            'dic'          => self::MISREAD_DIC,
        ], $this->supplierId);

        self::assertFalse($result['created'], 'Varianta názvu téže firmy nesmí založit novou kartu.');
        self::assertSame($existingId, $result['id']);
        self::assertNull($result['is_vat_payer']);
        self::assertSame('1', (string) $this->card($existingId)['is_vat_payer'], 'Neověřené DIČ nesmí kartu přepnout na neplátce.');
        self::assertSame(1, $this->countCards());
    }

    public function testViesNegativeForIcoMissingInAresIsUnknownNotNonPayer(): void
    {
        $res = $this->vatPayer->resolve(self::MISREAD_IC, self::MISREAD_DIC);

        self::assertNull($res['is_vat_payer'], 'Špatně přečtené IČO i DIČ nesmí udělat z dodavatele neplátce.');
        self::assertSame('unknown', $res['source']);
    }

    public function testViesNegativeWithoutIcoStaysNonPayer(): void
    {
        $this->seedViesNegative('DE999000013');

        $res = $this->vatPayer->resolve(null, 'DE999000013');

        self::assertFalse($res['is_vat_payer']);
        self::assertSame('vies', $res['source']);
    }

    private function seedViesNegative(string $vatId): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO vies_cache (vat_id, is_valid, payload, fetched_at) VALUES (?, 0, ?, NOW())
             ON DUPLICATE KEY UPDATE is_valid = 0, payload = VALUES(payload), fetched_at = NOW()"
        )->execute([$vatId, json_encode(['valid' => false, 'source' => 'rest', 'vat_number' => $vatId])]);
    }

    /** @return array<string,mixed> */
    private function card(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT company_name, street, city, zip, note, is_vat_payer FROM clients WHERE id = ?');
        $stmt->execute([$id]);
        return (array) $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    private function countCards(): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM clients WHERE supplier_id = ?');
        $stmt->execute([$this->supplierId]);
        return (int) $stmt->fetchColumn();
    }
}
