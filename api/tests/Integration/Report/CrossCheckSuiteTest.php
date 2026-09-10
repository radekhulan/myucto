<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Report\CrossCheckSuite;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Křížové kontroly (L4) — totéž číslo dvěma nezávislými cestami.
 *
 * Testuje se KONTRAKT sady, ne konkrétní čísla: ta závisí na obsahu databáze
 * a ověřuje je `api/bin/cross-check.php` nad reálnými daty. Tady jde o to, aby
 * sada nemohla tiše zezelenat.
 *
 * Motivace je konkrétní: první verze `profitFromIncomeStatement()` hledala kódy
 * řádku `***`/`**` (konvence jiných systémů), v tomhle výkazu je ale `VH`.
 * Nenašla nic, vrátila 0,00 — a kontrola pak nad ostrými daty hlásila rozdíl
 * v plné výši výsledku hospodaření (4 601 489,45 Kč) jako by šlo o účetní nález.
 * Tichá nula je u křížové kontroly nejhorší možná odpověď, proto je dnes chybějící
 * řádek tvrdá chyba.
 */
#[Group('integration')]
final class CrossCheckSuiteTest extends TestCase
{
    private Connection $db;
    private CrossCheckSuite $suite;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->suite = $container->get(CrossCheckSuite::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    /** Neexistující období nesmí spadnout — vrátí přeskočenou kontrolu s důvodem. */
    public function testMissingPeriodIsReportedAsSkippedNotCrash(): void
    {
        $results = $this->suite->run(1, 1999);

        self::assertNotEmpty($results, 'Sada musí vrátit aspoň záznam o tom, že nebylo co kontrolovat.');
        self::assertTrue((bool) $results[0]['skipped']);
        self::assertNotNull($results[0]['note'], 'Přeskočená kontrola musí říct proč.');
    }

    /** Každý výsledek musí být čitelný sám o sobě — bez toho je hlášení nepoužitelné. */
    public function testEveryResultIsSelfDescribing(): void
    {
        $years = $this->suite->closedYears(1);
        if ($years === []) {
            self::markTestSkipped('Žádné uzavřené období — kontrakt se ověří nad DB s obsahem.');
        }

        foreach ($this->suite->run(1, $years[0]) as $r) {
            self::assertNotSame('', trim((string) $r['check']), 'Kontrola bez identifikátoru.');
            self::assertNotSame('', trim((string) $r['label']), $r['check'] . ': chybí popis.');
            self::assertIsBool($r['ok']);
            self::assertIsBool($r['skipped']);
            if (!$r['ok'] && !$r['skipped']) {
                self::assertNotNull(
                    $r['difference'] ?? $r['note'],
                    $r['check'] . ': nesoulad musí nést rozdíl nebo vysvětlení.',
                );
            }
        }
    }

    /**
     * `closedYears()` je vstupem CI brány — kdyby vracelo prázdno tam, kde uzavřená
     * období jsou, brána by nekontrolovala nic a tvářila se zeleně.
     */
    public function testClosedYearsMatchesPeriodTable(): void
    {
        $expected = array_map('intval', $this->db->pdo()->query(
            "SELECT fiscal_year FROM accounting_periods
              WHERE supplier_id = 1 AND status IN ('closed','reviewed','approved') ORDER BY fiscal_year"
        )->fetchAll(\PDO::FETCH_COLUMN));

        self::assertSame($expected, $this->suite->closedYears(1));
    }
}
