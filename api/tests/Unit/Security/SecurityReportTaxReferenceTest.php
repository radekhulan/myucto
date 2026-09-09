<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Security;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Tax\Vat\Section43Service;
use PDO;
use PHPUnit\Framework\TestCase;

final class SecurityReportTaxReferenceTest extends TestCase
{
    public function testForeignCorrectionSourceCannotBeRegistered(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE supplier (id INTEGER PRIMARY KEY, vat_period TEXT)');
        $pdo->exec("INSERT INTO supplier VALUES (1, 'monthly')");
        $pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, supplier_id INTEGER)');
        $pdo->exec('INSERT INTO invoices VALUES (2, 2)');
        $pdo->exec('CREATE TABLE tax_constants (year INTEGER PRIMARY KEY, data TEXT)');
        $pdo->exec('CREATE TABLE vat_s43_corrections (id INTEGER PRIMARY KEY, supplier_id INTEGER, source_type TEXT, source_id INTEGER, period_year INTEGER, period_month INTEGER, rate_kind TEXT, base_delta REAL, vat_delta REAL, delivered_on TEXT, reason TEXT, corrective_doc_number TEXT, created_by INTEGER)');
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $service = new Section43Service($db, new TaxConstantsRepository($db));
        try {
            $service->register(1, 'invoice', 2, 2099, 1, 'basic', 100, 21, '2099-02-01', 'Syntetická oprava');
            self::fail('Cizí zdroj opravy musí být odmítnut.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM vat_s43_corrections')->fetchColumn());
        }
    }
}
