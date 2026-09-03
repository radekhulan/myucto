<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Import\InvoiceImportService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Běh importu na pozadí: hlášení průběhu a zastavení uprostřed dávky.
 *
 * Nález, který si tenhle test vynutil: uživateli utekl import 3 144 dokladů do timeoutu.
 * Doklady se založily, ale závěrečné kroky `importBundle()` — dorovnání číselných řad
 * a přepočet `client_revenue_cache` — jsou až ZA smyčkou, takže neproběhly: seznam
 * klientů ukazoval stará čísla a další vystavená faktura by dostala číslo, které
 * v importu už bylo.
 *
 * Zastavení proto NENÍ výjimka utíkající ven ze smyčky. Smyčka se ukončí a závěrečné
 * kroky doběhnou nad tím, co se stihlo — což je to jediné, co tenhle test hlídá
 * kromě samotných čísel průběhu.
 *
 * Data syntetická (fiktivní IČO, rok 2093), izolovaný supplier, uklizeno v tearDown.
 */
#[Group('integration')]
final class ImportBundleProgressCancelTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const TAX_DATE = '2093-06-10';
    private const DUE_DATE = '2093-06-24';
    private const SUPPLIER_IC = '12345678';
    private const CUSTOMER = 'Testovací odběratel průběhu s.r.o.';
    private const CUSTOMER_IC = '25596641';

    private Connection $db;
    private InvoiceImportService $import;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $czkId = 0;
    private int $clientId = 0;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db     = $c->get(Connection::class);
            $this->import = $c->get(InvoiceImportService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier / users).');
        }

        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare('UPDATE supplier SET ic = ? WHERE id = ?')->execute([self::SUPPLIER_IC, $this->supplierId]);
        $this->czkId = $this->currency();
        $this->clientId = $this->client();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db) || $this->supplierId === 0) {
            return;
        }
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM invoices WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM clients WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM currencies WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierId]);
        $this->db->close();
    }

    /**
     * Průběh se počítá v DOKLADECH, ne v souborech: jeden dataPack z Pohody jich nese
     * tisíce, takže „1 ze 1" by u běhu na pozadí neřeklo vůbec nic.
     */
    public function testProgressCountsDocumentsNotFiles(): void
    {
        $seen = [];
        $out = $this->import->importBundle(
            [['name' => 'davka.xml', 'content' => $this->pohodaBatch(3)]],
            $this->supplierId,
            $this->userId,
            'issued',
            function (int $processed, int $total, array $counts) use (&$seen): void {
                $seen[] = [$processed, $total, $counts['created']];
            },
        );

        self::assertNotSame([], $seen, 'Callback průběhu se musí volat.');
        foreach ($seen as [$processed, $total]) {
            self::assertSame(3, $total, 'Jmenovatel je počet DOKLADŮ v dávce, ne souborů.');
        }

        $last = end($seen);
        self::assertSame(3, $last[0], 'Poslední hlášení musí sedět na celkový počet dokladů.');
        self::assertSame(3, $last[2], 'Čítač vytvořených v průběhu musí sedět na report.');
        self::assertSame(3, (int) $out['summary']['created']);
        self::assertFalse($out['cancelled']);
        self::assertSame(0, $out['not_processed']);

        // Průběh musí růst monotónně — jinak by ukazatel v UI skákal zpátky.
        $prev = -1;
        foreach ($seen as [$processed]) {
            self::assertGreaterThanOrEqual($prev, $processed);
            $prev = $processed;
        }
    }

    /**
     * JÁDRO NÁLEZU: po zastavení uprostřed dávky doběhne přepočet statistik klienta.
     *
     * Právě tenhle krok při utnutém běhu chyběl — doklady v systému byly, ale seznam
     * klientů o nich nevěděl.
     */
    public function testCancelStopsLoopButFinishesTailSteps(): void
    {
        $processedDocs = 0;
        $out = $this->import->importBundle(
            [['name' => 'davka.xml', 'content' => $this->pohodaBatch(4)]],
            $this->supplierId,
            $this->userId,
            'issued',
            function (int $processed) use (&$processedDocs): void {
                $processedDocs = $processed;
            },
            // Zastav hned po prvním založeném dokladu.
            function () use (&$processedDocs): bool {
                return $processedDocs >= 1;
            },
        );

        self::assertTrue($out['cancelled'], 'Report musí přiznat, že běh byl zastavený.');
        self::assertSame(1, (int) $out['summary']['created'], 'Rozepsaný doklad se dopíše, další už nezačne.');
        self::assertSame(3, $out['not_processed'], 'Zbytek dávky musí být v reportu vyčíslený.');
        self::assertCount(1, $out['results'], 'Report nese jen doklady, ke kterým se běh dostal.');

        $row = $this->cacheRow();
        self::assertNotNull($row, 'Přepočet statistik klienta musí doběhnout i po zastavení.');
        self::assertSame(1, (int) $row['invoice_count']);
    }

    // ── pomůcky ──────────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    private function cacheRow(): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM client_revenue_cache WHERE client_id = ? AND currency_id = ?'
        );
        $stmt->execute([$this->clientId, $this->czkId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function currency(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, "CZK", "CZK", "Kč", "CZK", "CZK", 2, 1, 1)'
        )->execute([$this->supplierId]);

        return (int) $pdo->lastInsertId();
    }

    private function client(): int
    {
        $pdo = $this->db->pdo();
        $countryId = (int) ($pdo->query("SELECT id FROM countries WHERE UPPER(iso2) = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($countryId === 0) {
            self::markTestSkipped('Stát CZ není v číselníku zemí.');
        }

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, ic, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, ?, "Testovací 1", "Praha", "11000", ?, "odberatel@example.test", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, self::CUSTOMER, self::CUSTOMER_IC, $countryId, $this->czkId]);

        return (int) $pdo->lastInsertId();
    }

    /** Jeden dataPack s `$count` běžnými tuzemskými fakturami — tvar dávky z Pohody. */
    private function pohodaBatch(int $count): string
    {
        $items = '';
        for ($i = 1; $i <= $count; $i++) {
            $items .= $this->invoiceItem(
                sprintf('2093%06d', $i),
                sprintf('93610%04d', $i),
                (string) (1000 * $i),
                (string) (210 * $i),
            );
        }
        $supplierIc = self::SUPPLIER_IC;

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <dat:dataPack xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd"
                          xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd"
                          xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd"
                          version="2.0" ico="{$supplierIc}">
            {$items}
            </dat:dataPack>
            XML;
    }

    private function invoiceItem(string $number, string $symVar, string $base, string $vat): string
    {
        $taxDate    = self::TAX_DATE;
        $dueDate    = self::DUE_DATE;
        $customer   = self::CUSTOMER;
        $customerIc = self::CUSTOMER_IC;

        return <<<XML
              <dat:dataPackItem version="2.0">
                <inv:invoice version="2.0">
                  <inv:invoiceHeader>
                    <inv:invoiceType>issuedInvoice</inv:invoiceType>
                    <inv:number><typ:numberRequested>{$number}</typ:numberRequested></inv:number>
                    <inv:symVar>{$symVar}</inv:symVar>
                    <inv:date>{$taxDate}</inv:date>
                    <inv:dateTax>{$taxDate}</inv:dateTax>
                    <inv:dateDue>{$dueDate}</inv:dateDue>
                    <inv:text>Tuzemské plnění</inv:text>
                    <inv:partnerIdentity>
                      <typ:address>
                        <typ:company>{$customer}</typ:company>
                        <typ:ico>{$customerIc}</typ:ico>
                        <typ:street>Testovací 1</typ:street>
                        <typ:city>Praha</typ:city>
                        <typ:zip>11000</typ:zip>
                        <typ:country><typ:ids>CZ</typ:ids></typ:country>
                      </typ:address>
                    </inv:partnerIdentity>
                  </inv:invoiceHeader>
                  <inv:invoiceDetail>
                    <inv:invoiceItem>
                      <inv:text>Konzultace</inv:text>
                      <inv:quantity>1</inv:quantity>
                      <inv:unit>ks</inv:unit>
                      <inv:rateVAT>high</inv:rateVAT>
                      <inv:homeCurrency><typ:unitPrice>{$base}</typ:unitPrice></inv:homeCurrency>
                    </inv:invoiceItem>
                  </inv:invoiceDetail>
                  <inv:invoiceSummary>
                    <inv:homeCurrency>
                      <typ:priceHigh>{$base}</typ:priceHigh>
                      <typ:priceHighVAT rate="21">{$vat}</typ:priceHighVAT>
                    </inv:homeCurrency>
                  </inv:invoiceSummary>
                </inv:invoice>
              </dat:dataPackItem>
            XML;
    }
}
