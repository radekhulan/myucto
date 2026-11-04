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
 * FR 2 (vendor bugreport 2026-08-06) — duplicitní karty dodavatele vznikaly,
 * když stejné IČO/DIČ přišlo v jiném zápisu (IČO bez úvodní nuly, DIČ s mezerou navíc):
 * ClientResolver::resolveAny() ho porovnávalo přesně jako řetězec, nenašlo shodu a
 * založilo druhou kartu. Doklady jednoho dodavatele se pak rozdělily mezi dvě karty.
 *
 * Test simuluje kartu založenou (nebo naimportovanou) PŘED opravou s „nekanonickým"
 * zápisem IČO/DIČ (vloženou přímo přes ClientRepository, mimo ClientResolver — tak,
 * jak taková karta reálně vznikla) a ověřuje, že nový import se stejným dodavatelem v
 * kanonickém tvaru najde TUTÉŽ kartu (created=false), místo aby založil duplicitu.
 *
 * ClientResolver se skládá RUČNĚ (ne z DI kontejneru) s AresClient/ViesClient nad
 * prázdným `ares.api`/`vies.rest_api`/`crpdph.endpoint` — kontejner by zapojil reálné
 * URL (baselineDefaults()) a resolveAny()/resolveVendor() by při zakládání NOVÉ karty
 * udeřily na skutečný ARES přes síť, což testy nesmí (mockuj klienta). Cache tabulky
 * (ares_cache/vies_cache/crpdph_cache) jedou nad reálným test-DB spojením — jen bez
 * nakonfigurovaného endpointu se offline (cache miss → 'error'/null, bez I/O).
 *
 * Data jsou syntetická, běží v transakci rollbacknuté v tearDown.
 */
#[Group('integration')]
final class ClientResolverDuplicateNormalizationTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private ClientResolver $resolver;
    private ClientRepository $clients;

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
        $ares = new AresClient($offlineConfig, $this->db, new NullLogger());
        $crpdph = new CrpDphClient($offlineConfig, $this->db, new NullLogger());
        $vies = new ViesClient($offlineConfig, $this->db, new NullLogger(), $ares, $crpdph);
        $vatPayer = new VendorVatPayerResolver($ares, $vies, $this->clients);
        $this->resolver = new ClientResolver($this->db, $this->clients, $ares, $vies, $vatPayer);

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0) {
            $this->markTestSkipped('Chybí základní data (supplier).');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);

        // Izolovaný dodavatel je klon jen řádku `supplier` — měny jsou per tenant
        // (ClientRepository::create() dohledává currency_default_id přes CZK kód).
        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, "CZK", "CZK", "Kč", "CZK", "CZK", 2, 1, 1)'
        )->execute([$this->supplierId]);
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

    /**
     * Karta založená se 7místným IČO bez úvodní nuly (tak, jak ho AI extrakce/starší
     * import mohly uložit PŘED opravou BUG 2). Import stejného dodavatele s kanonickým
     * 8místným IČO musí najít TUTO kartu, ne založit druhou.
     */
    public function testLeadingZeroIcMatchesExistingCardInCanonicalForm(): void
    {
        $existingId = $this->clients->create([
            'company_name' => 'Testovací dodavatel s nulou s.r.o.',
            'ic'            => '1234567', // chybně bez úvodní nuly (7 číslic)
            'street'        => 'Ulice 1',
            'city'          => 'Praha',
            'zip'           => '11000',
        ], $this->supplierId);

        $result = $this->resolver->resolve([
            'company_name' => 'Testovací dodavatel s nulou s.r.o.',
            'ic'            => '01234567', // kanonický tvar z nového importu
        ], $this->supplierId);

        self::assertFalse($result['created'], 'Musí napárovat existující kartu, ne založit duplicitu.');
        self::assertSame($existingId, $result['id']);
        self::assertSame(1, $this->countClientsByIc('01234567'), 'V DB smí být jen jedna karta tohoto dodavatele.');
    }

    /**
     * Zahraniční identifikátor delší než 8 číslic: SQL `LPAD(…, 8, '0')` ho ořízl na
     * prvních 8 znaků, takže se s normalizovaným vstupem nikdy neshodl a každý další
     * doklad téhož dodavatele založil novou kartu.
     */
    public function testIcLongerThanEightDigitsMatchesExistingCard(): void
    {
        $existingId = $this->clients->create([
            'company_name' => 'Testovací zahraniční dodavatel Kft.',
            'ic'            => '12345678901',
            'street'        => 'Utca 1',
            'city'          => 'Budapest',
            'zip'           => '1011',
            'country_iso2'  => 'HU',
        ], $this->supplierId);

        $vendor = [
            'company_name' => 'Testovací zahraniční dodavatel Kft.',
            'ic'            => '12345678901',
            'country_iso2'  => 'HU',
        ];
        $first = $this->resolver->resolve($vendor, $this->supplierId);
        $second = $this->resolver->resolve($vendor, $this->supplierId);

        self::assertFalse($first['created'], 'Dlouhé IČO musí napárovat existující kartu.');
        self::assertSame($existingId, $first['id']);
        self::assertSame($existingId, $second['id']);
        self::assertSame(1, $this->countClientsByIc('12345678901'), 'V DB smí být jen jedna karta tohoto dodavatele.');
    }

    /**
     * Dvě různá dlouhá IČO se shodnými prvními 8 číslicemi zůstanou dvěma kartami.
     */
    public function testLongIcsSharingEightDigitPrefixStayDistinct(): void
    {
        $this->clients->create([
            'company_name' => 'Prefixový dodavatel A Kft.',
            'ic'            => '12345678111',
            'street'        => 'Utca 2',
            'city'          => 'Budapest',
            'zip'           => '1011',
        ], $this->supplierId);

        $result = $this->resolver->resolve([
            'company_name' => 'Prefixový dodavatel B Kft.',
            'ic'            => '12345678222',
        ], $this->supplierId);

        self::assertTrue($result['created'], 'Jiné dlouhé IČO se stejným prefixem se nesmí napárovat na cizí kartu.');
    }

    /**
     * Karta založená s DIČ obsahujícím mezeru navíc. Import stejného DIČ bez mezery
     * (a bez IČO — typický zahraniční dodavatel) musí najít tutéž kartu.
     */
    public function testDicWithSpaceMatchesExistingCardInCanonicalForm(): void
    {
        $existingId = $this->clients->create([
            'company_name' => 'Testovací dodavatel s mezerou s.r.o.',
            'dic'           => 'CZ 87654321', // mezera navíc
            'street'        => 'Ulice 2',
            'city'          => 'Brno',
            'zip'           => '60200',
        ], $this->supplierId);

        $result = $this->resolver->resolve([
            'company_name' => 'Testovací dodavatel s mezerou s.r.o.',
            'dic'           => 'CZ87654321', // bez mezery
        ], $this->supplierId);

        self::assertFalse($result['created'], 'Musí napárovat existující kartu podle normalizovaného DIČ.');
        self::assertSame($existingId, $result['id']);
    }

    /**
     * Sanity: skutečně jiný dodavatel (jiné IČO) se pořád založí jako nová karta —
     * oprava nesmí slévat různé subjekty dohromady.
     */
    public function testGenuinelyDifferentIcStillCreatesNewCard(): void
    {
        $this->clients->create([
            'company_name' => 'Dodavatel A s.r.o.',
            'ic'            => '11111111',
            'street'        => 'Ulice 3',
            'city'          => 'Ostrava',
            'zip'           => '70200',
        ], $this->supplierId);

        $result = $this->resolver->resolve([
            'company_name' => 'Dodavatel B s.r.o.',
            'ic'            => '22222222',
        ], $this->supplierId);

        self::assertTrue($result['created'], 'Jiné IČO musí založit novou kartu, ne se napárovat na cizí.');
    }

    private function countClientsByIc(string $ic): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM clients WHERE supplier_id = ? AND ' . \MyInvoice\Support\CompanyIdNormalizer::icSql('ic') . ' = ?'
        );
        $stmt->execute([$this->supplierId, $ic]);
        return (int) $stmt->fetchColumn();
    }
}
