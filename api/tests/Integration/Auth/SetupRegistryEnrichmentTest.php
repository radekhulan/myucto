<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Auth;

use MyInvoice\Action\Auth\SetupAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Vat\VatStatusService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Co si zřízení dotáhne z veřejných registrů samo.
 *
 * Bezobslužné zřízení pošle jen to, co provozovatel ví z objednávky: jméno,
 * adresu, IČ a DIČ. Právní formu ani plátcovství DPH neposílá, protože je
 * nezná — a výchozí hodnoty jsou ta opatrnější varianta (fyzická osoba,
 * neplátce, daňová evidence). U s.r.o., které si koupilo účetnictví, je to ale
 * špatně v každém bodě: firma naběhla v daňové evidenci, jako neplátce DPH
 * a s prázdným bankovním spojením. Přesně tohle potkalo první zaplacenou
 * hostovanou instalaci.
 *
 * ⚠️ Registr se v testu NEVOLÁ po síti. `CrpDphClient` má cache v databázi,
 * takže se předplní odpovědí a projde se skutečný kód, ne dvojník.
 */
final class SetupRegistryEnrichmentTest extends TestCase
{
    /**
     * Syntetické DIČ. Registr se v testu nevolá po síti — odpověď se předplní
     * do cache klienta — ale DIČ skutečné firmy by při promáznuté cache poslalo
     * dotaz ven a test by měřil realitu, ne scénář.
     */
    private const DIC = 'CZ00000019';

    /** Klíčem cache je DIČ bez písmen ({@see CrpDphClient::normalizeDic()}). */
    private const DIC_KEY = '00000019';

    private ContainerInterface $container;
    private Connection $db;
    private SetupAction $action;

    /** @var list<int> */
    private array $createdSuppliers = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DI kontejner.');
        }
        try {
            $this->container = Bootstrap::buildApp()->getContainer();
            $this->db = $this->container->get(Connection::class);
            $this->action = $this->container->get(SetupAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        // ⚠️ Cache registru se maže VŽDY, i když úklid firmy spadne. Kdyby
        // zůstala, přelila by se odpověď do dalšího testu a ten by ověřoval
        // úplně jiný scénář, než si myslí.
        try {
            foreach ($this->createdSuppliers as $id) {
                // ⚠️ Dodavatel se maže až se ZAPNUTOU kontrolou cizích klíčů.
                // S vypnutou by neproběhla kaskáda a v databázi by po každém
                // běhu zůstaly osiřelé řádky (vzor SettingsAction::deleteSupplier).
                // Vypnutá je jen kvůli měnám, na které `supplier` sám ukazuje.
                $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
                $pdo->prepare('DELETE FROM supplier_vat_status_history WHERE supplier_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM chart_of_accounts WHERE supplier_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM currencies WHERE supplier_id = ?')->execute([$id]);
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

                $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$id]);
            }
        } finally {
            $this->createdSuppliers = [];
            $pdo->prepare('DELETE FROM crpdph_cache WHERE dic = ?')->execute([self::DIC_KEY]);
        }
    }

    /** Firma tak, jak ji založí bezobslužné zřízení: opatrné výchozí hodnoty. */
    private function makeSupplier(string $taxpayerType = 'fo', string $mode = 'tax_evidence'): int
    {
        $pdo = $this->db->pdo();
        $countryId = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        // `default_currency_id` a `currencies.supplier_id` se odkazují navzájem,
        // proto stejný dvoukrokový bootstrap jako v setupu: firma, měny, doplnění.
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, ic, dic, is_vat_payer, email, taxpayer_type, accounting_mode, default_currency_id, default_vat_rate_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, 0, 0)'
        )->execute(['Zkouška s.r.o.', 'Testovací 1', 'Plzeň', '30100', $countryId, '00000019', self::DIC, 'zkouska@example.test', $taxpayerType, $mode]);
        $id = (int) $pdo->lastInsertId();
        $this->createdSuppliers[] = $id;

        VatStatusService::seedInitialStatus($pdo, $id, false);
        $currencyId = 0;
        foreach (['CZK', 'EUR'] as $code) {
            $pdo->prepare(
                'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
                 VALUES (?, ?, ?, ?, ?, ?, 2, 1, 1)'
            )->execute([$id, $code, $code, $code, $code, $code]);
            if ($currencyId === 0) {
                $currencyId = (int) $pdo->lastInsertId();
            }
        }
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([$currencyId, $id]);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        return $id;
    }

    /** Odpověď registru plátců DPH, aby se nechodilo po síti. */
    private function seedRegistry(bool $found, array $accounts = []): void
    {
        $payload = ['found' => $found, 'unreliable' => false, 'accounts' => $accounts, 'fu_code' => '454'];
        $this->db->pdo()->prepare(
            'INSERT INTO crpdph_cache (dic, payload) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = NOW()'
        )->execute([self::DIC_KEY, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    }

    /** @param array<string,mixed> $supplier */
    private function applyRegistry(int $supplierId, array $supplier): void
    {
        $m = new \ReflectionMethod(SetupAction::class, 'applyVatRegistryData');
        $m->invoke($this->action, $supplierId, $supplier);
    }

    /** @param array<string,mixed> $supplier */
    private function alignMode(int $supplierId, array $supplier): void
    {
        $m = new \ReflectionMethod(SetupAction::class, 'alignAccountingModeWithLegalForm');
        $m->invoke($this->action, $supplierId, $supplier);
    }

    /** @return array<string,mixed> */
    private function supplierRow(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM supplier WHERE id = ?');
        $stmt->execute([$id]);

        return (array) $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    // ---- Plátcovství DPH ---------------------------------------------------

    public function testCompanyInVatRegistryBecomesVatPayer(): void
    {
        $id = $this->makeSupplier();
        $this->seedRegistry(true);

        $this->applyRegistry($id, ['dic' => self::DIC]);

        self::assertSame(1, (int) $this->supplierRow($id)['is_vat_payer'], 'plátce zapsaný v registru nesmí naběhnout jako neplátce');
        self::assertTrue(
            $this->container->get(VatStatusService::class)->isVatPayerAt($id, date('Y-m-d')),
            'plátcovství se eviduje v historii, ne jen v živé cache'
        );
    }

    public function testCompanyOutsideRegistryStaysNonPayer(): void
    {
        $id = $this->makeSupplier();
        $this->seedRegistry(false);

        $this->applyRegistry($id, ['dic' => self::DIC]);

        self::assertSame(0, (int) $this->supplierRow($id)['is_vat_payer']);
    }

    public function testExplicitChoiceIsNotOverwrittenByRegistry(): void
    {
        // Kdo plátcovství poslal ve zřizovacím požadavku, rozhodl — třeba proto,
        // že registrace teprve běží. Registr jeho volbu přebít nesmí.
        $id = $this->makeSupplier();
        $this->seedRegistry(true);

        $this->applyRegistry($id, ['dic' => self::DIC, 'is_vat_payer' => false]);

        self::assertSame(0, (int) $this->supplierRow($id)['is_vat_payer']);
    }

    // ---- Bankovní účet -----------------------------------------------------

    public function testPublishedAccountFillsEmptyCzkCurrency(): void
    {
        $id = $this->makeSupplier();
        $this->seedRegistry(true, [['prefix' => '', 'number' => '1000000005', 'bank_code' => '0100', 'iban' => null, 'display' => '']]);

        $this->applyRegistry($id, ['dic' => self::DIC]);

        $stmt = $this->db->pdo()->prepare("SELECT account_number, bank_code FROM currencies WHERE supplier_id = ? AND code = 'CZK'");
        $stmt->execute([$id]);
        $row = (array) $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('1000000005', (string) $row['account_number']);
        self::assertSame('0100', (string) $row['bank_code']);
    }

    public function testOwnAccountFromRequestWins(): void
    {
        $id = $this->makeSupplier();
        $this->seedRegistry(true, [['prefix' => '', 'number' => '1000000005', 'bank_code' => '0100', 'iban' => null, 'display' => '']]);

        $this->applyRegistry($id, [
            'dic' => self::DIC,
            'bank_account' => ['account_number' => '9999999999', 'bank_code' => '0800'],
        ]);

        $stmt = $this->db->pdo()->prepare("SELECT account_number FROM currencies WHERE supplier_id = ? AND code = 'CZK'");
        $stmt->execute([$id]);
        self::assertSame('', (string) ($stmt->fetchColumn() ?: ''), 'vlastní účet zapisuje insertSupplier, registr do něj nesahá');
        self::assertSame(1, (int) $this->supplierRow($id)['is_vat_payer'], 'plátcovství se ale zjistit muselo');
    }

    // ---- Účetní režim ------------------------------------------------------

    public function testLegalEntityIsMovedToDoubleEntry(): void
    {
        // ARES doplní `taxpayer_type` až PO vložení řádku, takže režim zůstal
        // na opatrné daňové evidenci — a s.r.o. přišlo o účetnictví.
        $id = $this->makeSupplier('po', 'tax_evidence');

        $this->alignMode($id, ['dic' => self::DIC]);

        self::assertSame('double_entry', (string) $this->supplierRow($id)['accounting_mode']);
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM chart_of_accounts WHERE supplier_id = ?');
        $stmt->execute([$id]);
        self::assertGreaterThan(0, (int) $stmt->fetchColumn(), 'podvojné účetnictví bez směrné osnovy je rozbitý stav');
    }

    public function testNaturalPersonStaysInTaxEvidence(): void
    {
        $id = $this->makeSupplier('fo', 'tax_evidence');

        $this->alignMode($id, ['dic' => self::DIC]);

        self::assertSame('tax_evidence', (string) $this->supplierRow($id)['accounting_mode']);
    }

    public function testExplicitTaxpayerTypeIsRespected(): void
    {
        $id = $this->makeSupplier('po', 'tax_evidence');

        // Volající typ poplatníka poslal — jeho rozhodnutí registr nepřepisuje.
        $this->alignMode($id, ['dic' => self::DIC, 'taxpayer_type' => 'fo']);

        self::assertSame('tax_evidence', (string) $this->supplierRow($id)['accounting_mode']);
    }
}
