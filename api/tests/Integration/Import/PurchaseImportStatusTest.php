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
 * Stav přijatého dokladu založeného importem ze strukturovaného souboru.
 *
 * Nález: dávka 409 přijatých faktur z Pohody skončila jako 409 KONCEPTŮ. Koncept se
 * nezapočítává do nákladů, závazků ani do výkazů ({@see \MyInvoice\Repository\PurchaseInvoiceRepository}
 * — filtry `status <> 'draft'`), takže po migraci z jiného systému vypadala firma, jako
 * by neměla žádné náklady, a jediná cesta ven bylo otevřít stovky dokladů jeden po druhém.
 *
 * Doklad z ISDOC / Pohoda XML je přitom úplný — dodavatel, datumy, řádky i rekapitulace
 * DPH přišly ze souboru. Proto je výchozí stav `received` a koncept se dá vyžádat.
 *
 * Data syntetická (fiktivní IČO, rok 2093), izolovaný supplier, uklizeno v tearDown.
 */
#[Group('integration')]
final class PurchaseImportStatusTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const TENANT_IC = '12345678';
    private const VENDOR = 'Testovací dodavatel s.r.o.';
    private const VENDOR_IC = '25596641';

    private Connection $db;
    private InvoiceImportService $import;

    private int $supplierId = 0;
    private int $userId = 0;

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
        $pdo->prepare('UPDATE supplier SET ic = ? WHERE id = ?')->execute([self::TENANT_IC, $this->supplierId]);
        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, "CZK", "CZK", "Kč", "CZK", "CZK", 2, 1, 1)'
        )->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db) || $this->supplierId === 0) {
            return;
        }
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'DELETE FROM purchase_invoice_items WHERE purchase_invoice_id IN
                (SELECT id FROM purchase_invoices WHERE supplier_id = ?)'
        )->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM purchase_invoices WHERE supplier_id = ?')->execute([$this->supplierId]);
        // Číselnou řadu založí až přidělení varsymbolu při importu přijatého dokladu —
        // bez úklidu drží FK na supplier a tearDown spadne.
        $pdo->prepare('DELETE FROM purchase_invoice_counters WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM clients WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM currencies WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierId]);
        $this->db->close();
    }

    /** JÁDRO NÁLEZU: bez další volby vznikne PŘIJATÝ doklad, ne koncept. */
    public function testStructuredImportCreatesReceivedPurchaseInvoiceByDefault(): void
    {
        $out = $this->importOne('prijata-1.xml', $this->pohodaReceived('93700001', '2093-07-14'), 'received');
        self::assertSame('created', $out['status'], (string) ($out['reason'] ?? ''));

        self::assertSame('received', $this->statusOf((int) $out['purchase_invoice_id']));
    }

    /** Koncept se dá vyžádat — dávku, kterou chce účetní ještě projít, nikdo nebere. */
    public function testDraftStatusStillAvailableOnRequest(): void
    {
        $out = $this->importOne('prijata-2.xml', $this->pohodaReceived('93700002', '2093-07-15'), 'draft');
        self::assertSame('created', $out['status'], (string) ($out['reason'] ?? ''));

        self::assertSame('draft', $this->statusOf((int) $out['purchase_invoice_id']));
    }

    /**
     * NÁVAZNÝ NÁLEZ: doklad založený rovnou jako přijatý nedostával NAŠE INTERNÍ ČÍSLO.
     *
     * Varsymbol se generuje v přechodu draft→received
     * ({@see \MyInvoice\Action\PurchaseInvoice\TransitionPurchaseInvoiceStatusAction}),
     * který import z definice přeskočí — celá dávka pak v seznamu svítí jako „#id“
     * a účetní nemá doklad pod čím najít. Pravidlo proto drží zakládání dokladu.
     */
    public function testReceivedImportAssignsOurInternalNumber(): void
    {
        $out = $this->importOne('prijata-3.xml', $this->pohodaReceived('93700003', '2093-07-16'), 'received');
        self::assertSame('created', $out['status'], (string) ($out['reason'] ?? ''));

        $varsymbol = $this->varsymbolOf((int) $out['purchase_invoice_id']);
        self::assertNotSame('', $varsymbol, 'importovaný přijatý doklad musí mít naše interní číslo');
        // Tvar nese šablona firmy (default {PP}{YY}{MM}{CCC}), takhle se testuje jen to,
        // co je na šabloně nezávislé: daňový prefix a období dokladu, ne číslo dodavatele.
        self::assertStringStartsWith('PF', $varsymbol, 'naše řada, ne číslo dodavatele');
        self::assertStringContainsString('9307', $varsymbol, 'období z data vystavení dokladu');
        self::assertStringNotContainsString('93700003', $varsymbol, 'nesmí to být symVar dodavatele');
    }

    /**
     * Koncept číslo ZÁMĚRNĚ nedostává: řada by se proděravěla o doklady, které účetní
     * ještě může zahodit. Číslo přijde až při přijetí dokladu.
     */
    public function testDraftImportLeavesInternalNumberEmpty(): void
    {
        $out = $this->importOne('prijata-4.xml', $this->pohodaReceived('93700004', '2093-07-17'), 'draft');
        self::assertSame('created', $out['status'], (string) ($out['reason'] ?? ''));

        self::assertSame('', $this->varsymbolOf((int) $out['purchase_invoice_id']));
    }


    // ── pomůcky ──────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function importOne(string $name, string $content, string $purchaseStatus): array
    {
        $out = $this->import->importBundle(
            [['name' => $name, 'content' => $content]],
            $this->supplierId,
            $this->userId,
            'purchase',
            null,
            null,
            $purchaseStatus,
        );
        self::assertCount(1, $out['results']);

        return $out['results'][0];
    }

    private function varsymbolOf(int $purchaseInvoiceId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT varsymbol FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$purchaseInvoiceId]);

        return (string) $stmt->fetchColumn();
    }


    private function statusOf(int $purchaseInvoiceId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT status FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$purchaseInvoiceId]);

        return (string) $stmt->fetchColumn();
    }

    /** Přijatá faktura v uživatelském exportu z Pohody (`rsp:responsePack` → `lst:invoice`). */
    private function pohodaReceived(string $symVar, string $date): string
    {
        $tenantIc = self::TENANT_IC;
        $vendor   = self::VENDOR;
        $vendorIc = self::VENDOR_IC;

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rsp:responsePack xmlns:rsp="http://www.stormware.cz/schema/version_2/response.xsd"
                              xmlns:lst="http://www.stormware.cz/schema/version_2/list.xsd"
                              xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd"
                              xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd"
                              version="2.0" ico="{$tenantIc}">
              <rsp:responsePackItem version="2.0">
                <lst:listInvoice version="2.0">
                  <lst:invoice version="2.0">
                    <inv:invoiceHeader>
                      <inv:invoiceType>receivedInvoice</inv:invoiceType>
                      <inv:number><typ:numberRequested>{$symVar}</typ:numberRequested></inv:number>
                      <inv:symVar>{$symVar}</inv:symVar>
                      <inv:date>{$date}</inv:date>
                      <inv:dateTax>{$date}</inv:dateTax>
                      <inv:dateDue>{$date}</inv:dateDue>
                      <inv:text>Kancelářské potřeby</inv:text>
                      <inv:partnerIdentity>
                        <typ:address>
                          <typ:company>{$vendor}</typ:company>
                          <typ:ico>{$vendorIc}</typ:ico>
                          <typ:street>Dodavatelská 5</typ:street>
                          <typ:city>Brno</typ:city>
                          <typ:zip>60200</typ:zip>
                          <typ:country><typ:ids>CZ</typ:ids></typ:country>
                        </typ:address>
                      </inv:partnerIdentity>
                      <inv:myIdentity>
                        <typ:address>
                          <typ:company>Tenant a.s.</typ:company>
                          <typ:ico>{$tenantIc}</typ:ico>
                          <typ:street>Testovací 1</typ:street>
                          <typ:city>Praha</typ:city>
                          <typ:zip>11000</typ:zip>
                          <typ:country><typ:ids>CZ</typ:ids></typ:country>
                        </typ:address>
                      </inv:myIdentity>
                    </inv:invoiceHeader>
                    <inv:invoiceDetail>
                      <inv:invoiceItem>
                        <inv:text>Papír A4</inv:text>
                        <inv:quantity>1</inv:quantity>
                        <inv:unit>ks</inv:unit>
                        <inv:rateVAT>high</inv:rateVAT>
                        <inv:homeCurrency><typ:unitPrice>1000</typ:unitPrice></inv:homeCurrency>
                      </inv:invoiceItem>
                    </inv:invoiceDetail>
                    <inv:invoiceSummary>
                      <inv:homeCurrency>
                        <typ:priceHigh>1000</typ:priceHigh>
                        <typ:priceHighVAT rate="21">210</typ:priceHighVAT>
                      </inv:homeCurrency>
                    </inv:invoiceSummary>
                  </lst:invoice>
                </lst:listInvoice>
              </rsp:responsePackItem>
            </rsp:responsePack>
            XML;
    }
}
