<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollPersonAnonymizationRepository;
use MyInvoice\Service\Payroll\Retention\PayrollRetentionCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Retenční katalog proti SKUTEČNÉMU schématu.
 *
 * Regrese, kterou to chytá: někdo přejmenuje nebo zruší tabulku, katalog o ní
 * neví a kategorie tiše přestane platit. Osoba, kterou dosud držela evidence
 * v té tabulce, se rázem začne navrhovat k výmazu — a nic v code review to
 * neukáže, protože se změnila migrace, ne katalog.
 *
 * Druhá regrese: anonymizace maže tabulku, která mezitím dostala sloupce
 * s částkou. Proto se kontroluje i to, že mazané „identitní" tabulky jsou
 * skutečně vázané na osobu a nefigurují zároveň jako nosič účetního záznamu.
 */
final class PayrollRetentionRegistryTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 3) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — schema guard vyžaduje DB.');
        }
        try {
            $db = Bootstrap::buildContainer()?->get(Connection::class);
            self::assertInstanceOf(Connection::class, $db);
            $this->db = $db;
            $this->db->pdo();
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

    public function testEveryTrackedTableExists(): void
    {
        foreach (PayrollRetentionCatalog::trackedTables() as $table) {
            self::assertTrue(
                $this->db->hasTable($table),
                "Retenční katalog sleduje tabulku {$table}, která ve schématu není. "
                . 'Kategorie by tím tiše přestala platit a osoby, které drží, by se '
                . 'začaly navrhovat k výmazu.',
            );
        }
    }

    public function testEveryTrackedTableCarriesTheTenantKeyAndAPersonLink(): void
    {
        foreach (PayrollRetentionCatalog::rules() as $rule) {
            foreach ($rule->employeeTables as $table) {
                $this->assertColumns($table, ['supplier_id', 'employee_id'], $rule->category);
            }
            foreach ($rule->employmentTables as $table) {
                $this->assertColumns($table, ['supplier_id', 'employment_id'], $rule->category);
            }
        }
    }

    public function testAnonymizedIdentityTablesExistAndAreScopedToThePerson(): void
    {
        foreach (PayrollPersonAnonymizationRepository::identityTables() as $table) {
            self::assertTrue($this->db->hasTable($table), "Anonymizace maže neexistující {$table}.");
            $this->assertColumns($table, ['supplier_id', 'employee_id'], 'anonymizace');
        }
    }

    /**
     * Anonymizace nesmí sáhnout na nosič účetní částky. Kontroluje se to
     * strukturálně — tabulka určená ke smazání nesmí mít sloupec s částkou,
     * protože takový řádek je účetní záznam, ne identifikační údaj.
     */
    public function testIdentityTablesHoldNoMonetaryColumns(): void
    {
        foreach (PayrollPersonAnonymizationRepository::identityTables() as $table) {
            foreach ($this->columns($table) as $column) {
                self::assertDoesNotMatchRegularExpression(
                    '/(_minor|amount|gross|net|_sum|_total)$/',
                    $column,
                    "Tabulka {$table} má sloupec {$column}, který vypadá jako částka. "
                    . 'Anonymizace ji maže CELOU — účetní záznam by tím zmizel.',
                );
            }
        }
    }

    public function testResidueTablesExistSoTheGapStaysVisible(): void
    {
        foreach (PayrollPersonAnonymizationRepository::residueTables() as $table) {
            self::assertTrue(
                $this->db->hasTable($table),
                "Zbytková tabulka {$table} neexistuje — sonda by přestala hlásit, co po "
                . 'anonymizaci zůstává, a mezera by se stala neviditelnou.',
            );
            // Odvozené zbytky (běhy, archivy) nesou osobu jen v obsahu a počítají
            // se přes payroll_run_persons; i ty ale musí jít omezit na tenanta.
            $derived = in_array($table, PayrollPersonAnonymizationRepository::derivedResidueTables(), true);
            $this->assertColumns($table, $derived ? ['supplier_id'] : ['supplier_id', 'employee_id'], 'zbytky');
        }
    }

    /** @param list<string> $required */
    private function assertColumns(string $table, array $required, string $context): void
    {
        $columns = $this->columns($table);
        foreach ($required as $column) {
            self::assertContains(
                $column,
                $columns,
                "Tabulka {$table} ({$context}) nemá sloupec {$column}, takže ji nejde "
                . 'bezpečně omezit na tenanta ani na osobu.',
            );
        }
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);

        return array_map(strval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
