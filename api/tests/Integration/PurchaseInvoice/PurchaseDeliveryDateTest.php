<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Audit VAT klasifikací 2026-08, nález M-7 — datum dodání (§ 25 vstup) na přijaté faktuře.
 *
 * Dokud sloupec neexistoval (migrace 1790), ukládal ho AI extraktor do `tax_date` a hned
 * ho přepisoval dopočteným DUZP; ostatní cesty ho zahodily. Test drží, že datum dodání
 * dojde do DB a zpět OBĚMA zápisovými cestami — INSERT i UPDATE v repozitáři jsou
 * poziční, takže špatně přidaný sloupec by tiše posunul VŠECHNA data dokladu.
 */
#[Group('integration')]
final class PurchaseDeliveryDateTest extends TestCase
{
    private Connection $db;
    private PurchaseInvoiceRepository $repo;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $vendorId = 0;

    /** @var int[] */
    private array $piIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container  = Bootstrap::buildApp()->getContainer();
            $this->db   = $container->get(Connection::class);
            $this->repo = $container->get(PurchaseInvoiceRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->hasColumn('purchase_invoices', 'delivery_date')) {
            $this->markTestSkipped('Migrace 1790 na téhle DB neproběhla.');
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
        $this->vendorId = $this->vendor();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        foreach ($this->piIds as $id) {
            $pdo->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        if ($this->vendorId > 0) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->vendorId]);
        }
        $this->db->close();
    }

    public function testDeliveryDateSurvivesCreateAndUpdateWithoutShiftingOtherDates(): void
    {
        $payload = [
            'vendor_id'             => $this->vendorId,
            'vendor_invoice_number' => 'M7-DELIVERY',
            'document_kind'         => 'invoice',
            'issue_date'            => '2099-06-04',
            'tax_date'              => '2099-05-15',
            'delivery_date'         => '2099-04-23',
            'due_date'              => '2099-06-18',
            'received_at'           => '2099-06-05',
            'currency_id'           => $this->currencyId,
            'reverse_charge'        => true,
        ];
        $id = $this->repo->createDraft($payload, $this->userId, $this->supplierId);
        $this->piIds[] = $id;

        $row = $this->repo->find($id, $this->supplierId);
        self::assertSame('2099-04-23', substr((string) $row['delivery_date'], 0, 10));
        // Poziční INSERT: kdyby se sloupec přidal na špatné místo, posunula by se ostatní data.
        self::assertSame('2099-06-04', substr((string) $row['issue_date'], 0, 10));
        self::assertSame('2099-05-15', substr((string) $row['tax_date'], 0, 10));
        self::assertSame('2099-06-18', substr((string) $row['due_date'], 0, 10));
        self::assertSame('2099-06-05', substr((string) $row['received_at'], 0, 10));

        $this->repo->updateDraft($id, ['delivery_date' => '2099-04-30'] + $payload, $this->supplierId);
        $updated = $this->repo->find($id, $this->supplierId);
        self::assertSame('2099-04-30', substr((string) $updated['delivery_date'], 0, 10));
        self::assertSame('2099-06-04', substr((string) $updated['issue_date'], 0, 10));
        self::assertSame('2099-05-15', substr((string) $updated['tax_date'], 0, 10));
        self::assertSame('2099-06-18', substr((string) $updated['due_date'], 0, 10));

        // Prázdné datum dodání se ukládá jako NULL, ne jako dnešek nebo datum vystavení.
        $this->repo->updateDraft($id, ['delivery_date' => null] + $payload, $this->supplierId);
        self::assertNull($this->repo->find($id, $this->supplierId)['delivery_date']);
    }

    private function vendor(): int
    {
        $czId = (int) ($this->db->pdo()->query("SELECT id FROM countries WHERE iso2='DE' LIMIT 1")->fetchColumn() ?: 0);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic,
                                  main_email, language, currency_default_id, is_customer, is_vendor,
                                  is_vat_payer)
             VALUES (?, ?, "Test 1", "Berlin", "10115", ?, "DE111222333", "v@example.com", "cs", ?, 0, 1, 1)'
        );
        $stmt->execute([$this->supplierId, 'Dodavatel datum dodání', $czId ?: null, $this->currencyId]);

        return (int) $this->db->pdo()->lastInsertId();
    }
}
