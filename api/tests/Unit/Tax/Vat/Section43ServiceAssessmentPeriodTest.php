<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Vat;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Tax\TaxConstants;
use MyInvoice\Service\Tax\Vat\Section43Service;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * § 148 DŘ — lhůta pro stanovení daně, po jejímž uplynutí nelze opravu podle § 43 ZDPH
 * provést. Ověřuje, že se počet let čte z roční sady konstant pro rok PŮVODNÍHO plnění,
 * ne z natvrdo zapsané trojky.
 */
final class Section43ServiceAssessmentPeriodTest extends TestCase
{
    private PDO $pdo;
    private Section43Service $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE tax_constants (year INTEGER PRIMARY KEY, data TEXT NOT NULL)');

        $config = $this->createStub(\MyInvoice\Infrastructure\Config\Config::class);
        $conn = new Connection($config);
        $prop = (new \ReflectionClass($conn))->getProperty('pdo');
        $prop->setValue($conn, $this->pdo);

        $this->service = new Section43Service($conn, new TaxConstantsRepository($conn));
    }

    public function testDefaultThreeYearPeriod(): void
    {
        // Zdaňovací období 2025-01 (měsíční): podání do 25. 2. 2025 + 3 roky = 25. 2. 2028.
        self::assertFalse($this->service->isTimeBarred(2025, 1, '2028-02-25'));
        self::assertTrue($this->service->isTimeBarred(2025, 1, '2028-02-26'));
    }

    public function testPeriodComesFromTaxConstantsNotLiteral(): void
    {
        $override = TaxConstants::forYear(2025);
        $override['assessment_period_years'] = 1;
        $this->pdo->prepare('INSERT INTO tax_constants (year, data) VALUES (?, ?)')
            ->execute([2025, json_encode($override)]);

        // Se zkrácenou lhůtou na 1 rok je oprava po 25. 2. 2026 už prekludovaná.
        self::assertFalse($this->service->isTimeBarred(2025, 1, '2026-02-25'));
        self::assertTrue($this->service->isTimeBarred(2025, 1, '2026-02-26'));
    }
}
