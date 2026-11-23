<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessInsuredContactReader;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * K2: telefon a e-mail pojištěnce do `pojistenec/kontakt` se čtou z karty
 * osoby (šifrované kontakty). Nehádá se: bez primárního kontaktu a při více
 * kandidátech se kontakt vynechá. Všechna data jsou syntetická.
 */
#[Group('integration')]
final class PayrollSicknessInsuredContactTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private ContainerInterface $container;
    private int $supplierId;

    protected function setUp(): void
    {
        $this->container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $connection = $this->container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->db = $connection;
        $pdo = $connection->pdo();
        $source = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testReaderReturnsPrimaryContactsAndSkipsAmbiguousOnes(): void
    {
        $employeeId = $this->employee();
        $this->contact($employeeId, 'email', 'jan.testovaci@example.test', true);
        $this->contact($employeeId, 'phone', '+420 600 000 001', false);
        $this->contact($employeeId, 'phone', '+420 600 000 002', false);

        $reader = $this->container->get(SicknessInsuredContactReader::class);
        self::assertInstanceOf(SicknessInsuredContactReader::class, $reader);
        $contact = $reader->forEmployee($this->supplierId, $employeeId);

        self::assertSame('jan.testovaci@example.test', $contact['email']);
        // Dva neprimární telefony: nehádá se, který je správný.
        self::assertNull($contact['phone']);

        $this->contact($employeeId, 'phone', '+420 600 000 003', true);
        self::assertSame('+420 600 000 003', $reader->forEmployee($this->supplierId, $employeeId)['phone']);
    }

    public function testReaderIgnoresInactiveContactsAndPeopleWithoutContacts(): void
    {
        $employeeId = $this->employee();
        $this->contact($employeeId, 'email', 'stary@example.test', true, false);
        $reader = $this->container->get(SicknessInsuredContactReader::class);
        self::assertInstanceOf(SicknessInsuredContactReader::class, $reader);

        self::assertSame(['phone' => null, 'email' => null], $reader->forEmployee($this->supplierId, $employeeId));
    }

    private function employee(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Jan Testovací", "employee", "hpp", 0, 0, 0, NULL, 0, 1)'
        )->execute([$this->supplierId]);

        return (int) $pdo->lastInsertId();
    }

    private function contact(
        int $employeeId,
        string $type,
        string $value,
        bool $primary,
        bool $active = true,
    ): void {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO payroll_person_contacts
                (supplier_id, employee_id, contact_type, contact_value_ciphertext,
                 contact_value_hash, contact_value_masked, is_primary, is_active)
             VALUES (?, ?, ?, '', ?, '', ?, ?)"
        )->execute([
            $this->supplierId,
            $employeeId,
            $type,
            random_bytes(32),
            $primary ? 1 : 0,
            $active ? 1 : 0,
        ]);
        $id = (int) $pdo->lastInsertId();
        $sensitive = $this->container->get(PayrollSensitiveData::class);
        self::assertInstanceOf(PayrollSensitiveData::class, $sensitive);
        $sealed = $sensitive->seal(
            $value,
            $type === 'email' ? PayrollSensitiveField::CONTACT_EMAIL : PayrollSensitiveField::CONTACT_PHONE,
            $this->supplierId,
            $id,
        );
        $pdo->prepare(
            'UPDATE payroll_person_contacts
                SET contact_value_ciphertext = ?, contact_value_hash = ?, contact_value_masked = ?
              WHERE supplier_id = ? AND id = ?'
        )->execute([$sealed->ciphertext, $sealed->lookupHash, $sealed->masked, $this->supplierId, $id]);
    }
}
