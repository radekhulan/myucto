<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Mail;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Mail\SupplierEmailContext;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** forSupplier() musí fungovat proti skutečnému schématu (supplier nemá email_footer ani email_profile_id). */
#[Group('integration')]
final class SupplierEmailContextTest extends TestCase
{
    private Connection $db;
    private int $supplierId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje.');
        }
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        $pdo = $this->db->pdo();
        $cur = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' LIMIT 1")->fetchColumn();
        $vat = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $cty = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        if (!$cur || !$vat || !$cty) {
            $this->markTestSkipped('Chybí základní data.');
        }
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
                       VALUES ("Kontext s.r.o.", "Testovaci 1", "Praha", "11000", ?, "k@example.com", ?, ?)')->execute([$cty, $cur, $vat]);
        $this->supplierId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testWithoutProfile(): void
    {
        $row = (new SupplierEmailContext($this->db))->forSupplier($this->supplierId);
        $this->assertSame('Kontext s.r.o.', $row['company_name']);
        $this->assertNull($row['email_footer']);
        $this->assertNull($row['email_profile_id']);
        $this->assertArrayHasKey('accent_soft', $row);
    }

    public function testDefaultProfileOverlayKeepsFooterAndEmailProfile(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO branding_profiles (supplier_id, name, display_name, email_footer, accent_color, branding_enabled, is_active)
                       VALUES (?, "Výchozí", "Značka", "Patička profilu", "#112233", 1, 1)')->execute([$this->supplierId]);
        $pid = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE supplier SET branding_profiles_enabled = 1, default_branding_profile_id = ? WHERE id = ?')->execute([$pid, $this->supplierId]);

        $row = (new SupplierEmailContext($this->db))->forSupplier($this->supplierId);
        $this->assertSame('Patička profilu', $row['email_footer']);
        $this->assertSame('Značka', $row['display_name']);
        $this->assertSame('#112233', $row['email_accent_color']);
        $this->assertSame($pid, $row['branding_profile_id']);
    }

    public function testUnknownSupplier(): void
    {
        $this->assertNull((new SupplierEmailContext($this->db))->forSupplier(0));
        $this->assertNull((new SupplierEmailContext($this->db))->forSupplier(999999999));
    }
}
