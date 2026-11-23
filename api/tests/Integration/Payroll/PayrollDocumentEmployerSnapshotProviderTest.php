<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Document\PayrollDocumentEmployerSnapshotProvider;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollDocumentEmployerSnapshotProviderTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollDocumentEmployerSnapshotProvider $provider;
    private int $supplierId;
    private string $countryName;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $this->db = \MyInvoice\Tests\Support\PayrollTestContainer::build()->get(Connection::class);
        } catch (\Throwable $exception) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $exception->getMessage());
        }
        if (!$this->db->hasTable('payroll_employer_settings')
            || !$this->db->hasTable('payroll_offices')
        ) {
            $this->markTestSkipped('Mzdová nastavení nejsou nainstalovaná.');
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0) {
            $this->markTestSkipped('Chybí zdrojová firma pro syntetický test.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->provider = new PayrollDocumentEmployerSnapshotProvider($this->db);
        $this->configureSupplier();
        $this->insertEmployerSettings(
            'Syntetická mzdová účetní',
            'mzdy@example.invalid',
            '+420 777 000 001',
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testFreezesValidatedEmployerAndPayrollContact(): void
    {
        $snapshot = ($this->provider)($this->supplierId);

        self::assertSame([
            'name' => 'Syntetický zaměstnavatel',
            'identification_number' => '12345678',
            'tax_identification_number' => 'CZ12345678',
            'address' => [
                'street_line' => 'Testovací 12',
                'city' => 'Testov',
                'postal_code' => '10000',
                'country_code' => 'CZ',
                'country_name' => $this->countryName,
            ],
            'issuer' => [
                'name' => 'Syntetická mzdová účetní',
                'email' => 'mzdy@example.invalid',
                'phone' => '+420 777 000 001',
            ],
        ], $snapshot->toArray());
    }

    public function testFallsBackExplicitlyToSupplierIssuerContact(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employer_settings
                SET payroll_contact_name = NULL,
                    payroll_contact_email = NULL,
                    payroll_contact_phone = NULL
              WHERE supplier_id = ?'
        )->execute([$this->supplierId]);

        $snapshot = ($this->provider)($this->supplierId);

        self::assertSame('Syntetický zaměstnavatel', $snapshot->issuerName);
        self::assertSame('firma@example.invalid', $snapshot->issuerEmail);
        self::assertSame('+420 222 000 001', $snapshot->issuerPhone);
    }

    public function testRequiresEmployerSettingsAndCompleteTaxIdentity(): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM payroll_employer_settings WHERE supplier_id = ?'
        )->execute([$this->supplierId]);

        try {
            ($this->provider)($this->supplierId);
            self::fail('Chybějící mzdové nastavení nesmí vytvořit snapshot.');
        } catch (\DomainException $exception) {
            self::assertSame(
                'Pro mzdový dokument chybí nastavení zaměstnavatele.',
                $exception->getMessage(),
            );
        }

        $this->insertEmployerSettings(null, null, null);
        $this->db->pdo()->prepare(
            'UPDATE supplier SET dic = NULL WHERE id = ?'
        )->execute([$this->supplierId]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('tax_identification_number');
        ($this->provider)($this->supplierId);
    }

    public function testRequiresTransaction(): void
    {
        $this->db->pdo()->rollBack();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'Snapshot zaměstnavatele vyžaduje aktivní transakci.',
        );
        ($this->provider)($this->supplierId);
    }

    public function testRejectsInvalidResolvedContact(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employer_settings
                SET payroll_contact_email = "neplatny-email"
              WHERE supplier_id = ?'
        )->execute([$this->supplierId]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('E-mail vystavitele');
        ($this->provider)($this->supplierId);
    }

    public function testRejectsMalformedEmployerIdentifier(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE supplier SET ic = "<neplatne>" WHERE id = ?'
        )->execute([$this->supplierId]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('IČ zaměstnavatele');
        ($this->provider)($this->supplierId);
    }

    private function configureSupplier(): void
    {
        $country = $this->db->pdo()->query(
            'SELECT id, name_cs FROM countries WHERE iso2 = "CZ" LIMIT 1'
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($country);
        $countryId = (int) $country['id'];
        $this->countryName = (string) $country['name_cs'];
        self::assertGreaterThan(0, $countryId);
        $this->db->pdo()->prepare(
            'UPDATE supplier
                SET company_name = "Syntetická společnost",
                    display_name = "Syntetický zaměstnavatel",
                    ic = "12345678",
                    dic = "CZ12345678",
                    street = "Testovací 12",
                    city = "Testov",
                    zip = "10000",
                    country_id = ?,
                    email = "firma@example.invalid",
                    phone = "+420 222 000 001"
              WHERE id = ?'
        )->execute([$countryId, $this->supplierId]);
    }

    private function insertEmployerSettings(
        ?string $name,
        ?string $email,
        ?string $phone,
    ): void {
        $office = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_offices
              WHERE supplier_id = ? AND code = "SNAPSHOT"'
        );
        $office->execute([$this->supplierId]);
        $officeId = (int) ($office->fetchColumn() ?: 0);
        if ($officeId === 0) {
            $this->db->pdo()->prepare(
                'INSERT INTO payroll_offices
                    (supplier_id, code, name, is_active)
                 VALUES (?, "SNAPSHOT", "Syntetická účtárna", 1)'
            )->execute([$this->supplierId]);
            $officeId = (int) $this->db->pdo()->lastInsertId();
        }
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employer_settings
                (supplier_id, default_office_id, payroll_contact_name,
                 payroll_contact_email, payroll_contact_phone)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, $officeId, $name, $email, $phone]);
    }
}
