<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Report\VatRegistrationService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * § 94 odst. 1 ZDPH — lhůta pro podání přihlášky k registraci. Ověřuje, že se počet
 * pracovních dnů čte z roční sady konstant ({@see TaxConstantsRepository::forYear()}),
 * ne z natvrdo zapsané desítky.
 */
final class VatRegistrationServiceApplicationDeadlineTest extends TestCase
{
    private PDO $pdo;
    private VatRegistrationService $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE tax_constants (year INTEGER PRIMARY KEY, data TEXT NOT NULL)');

        $config = $this->createStub(\MyInvoice\Infrastructure\Config\Config::class);
        $conn = new Connection($config);
        $prop = (new \ReflectionClass($conn))->getProperty('pdo');
        $prop->setValue($conn, $this->pdo);

        $this->service = new VatRegistrationService($conn, new TaxConstantsRepository($conn));
    }

    public function testDefaultDeadlineIsTenWorkingDays(): void
    {
        // 2025-01-02 (čtvrtek) + 10 pracovních dnů → 2025-01-16.
        $d = $this->service->applicationDeadline('2025-01-02', null);
        self::assertNotNull($d);
        self::assertSame('2025-01-16', $d['deadline']);
        self::assertSame('statutory', $d['basis']);
    }

    public function testDeadlineComesFromTaxConstantsNotLiteral(): void
    {
        // Uprav konstanty pro rok 2025 přes DB override, ne přes literál v kódu.
        $override = \MyInvoice\Service\Tax\TaxConstants::forYear(2025);
        $override['vat_registration_application_deadline_working_days'] = 3;
        $this->pdo->prepare('INSERT INTO tax_constants (year, data) VALUES (?, ?)')
            ->execute([2025, json_encode($override)]);

        $d = $this->service->applicationDeadline('2025-01-02', null);
        self::assertNotNull($d);
        // 2025-01-02 + 3 pracovní dny → 2025-01-07.
        self::assertSame('2025-01-07', $d['deadline']);
    }

    public function testInformativeDeadlineWithoutCrossedDay(): void
    {
        $d = $this->service->applicationDeadline(null, '2026-01-01');
        self::assertSame(['deadline' => '2026-01-01', 'basis' => 'informative'], $d);
    }
}
