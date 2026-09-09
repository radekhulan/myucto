<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Deletion\BankStatementDeletionGuard;
use MyInvoice\Repository\Deletion\CashDocumentDeletionGuard;
use MyInvoice\Repository\Deletion\DocumentDeletionGuard;
use MyInvoice\Repository\Deletion\ForeignKeyDeletionGuard;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Registr blokujících vazeb musí odpovídat SKUTEČNÉMU grafu cizích klíčů.
 *
 * Mzdový modul přidal ke zbytku aplikace cizí klíče RESTRICT a mazací routy,
 * které o nich nevěděly, začaly padat na HTTP 500 se syrovou hláškou databáze.
 * Kontrola v kódu tenhle druh regrese sama neuhlídá — proto se seznam porovnává
 * s `information_schema`: nová tabulka s blokujícím cizím klíčem shodí tenhle
 * test, ne produkci.
 *
 * Vzor převzat ze schématové pojistky u
 * {@see \MyInvoice\Repository\Payroll\PayrollEmployeeDeletionRepository}.
 */
final class DeletionGuardRegistryTest extends TestCase
{
    private Connection $db;

    /** @return list<array{0:class-string<ForeignKeyDeletionGuard>}> */
    public static function guards(): array
    {
        return [
            [BankStatementDeletionGuard::class],
            [CashDocumentDeletionGuard::class],
            [DocumentDeletionGuard::class],
        ];
    }

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 3) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — schema guard vyžaduje DB.');
        }
        try {
            $this->db = Bootstrap::buildApp()->getContainer()->get(Connection::class);
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

    /**
     * @param class-string<ForeignKeyDeletionGuard> $guard
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('guards')]
    public function testEveryBlockingForeignKeyIsRegistered(string $guard): void
    {
        $parents = $guard::parentTables();
        self::assertNotSame([], $parents);

        $placeholders = implode(',', array_fill(0, count($parents), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT rc.TABLE_NAME
               FROM information_schema.REFERENTIAL_CONSTRAINTS rc
              WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
                AND rc.DELETE_RULE IN ("RESTRICT", "NO ACTION")
                AND rc.REFERENCED_TABLE_NAME IN (' . $placeholders . ')
                AND rc.TABLE_NAME <> rc.REFERENCED_TABLE_NAME'
        );
        $stmt->execute($parents);
        $blockingChildren = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

        self::assertSame(
            [],
            array_values(array_diff($blockingChildren, $guard::blockingTables())),
            "Tabulka s blokujícím cizím klíčem chybí v registru {$guard} — "
            . 'mazací routa na ni spadne jako HTTP 500 se syrovou hláškou databáze.',
        );
    }

    /**
     * Opačný směr: zapsaná dvojice tabulka+sloupec musí v cizím klíči SKUTEČNĚ
     * existovat. Přejmenování sloupce by jinak kontrolu tiše vypnulo — registr by
     * počítal nesmysl, případně by dotaz spadl až v produkci.
     *
     * @param class-string<ForeignKeyDeletionGuard> $guard
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('guards')]
    public function testEveryRegisteredReferenceReallyPointsAtTheParent(string $guard): void
    {
        $parents = $guard::parentTables();
        $placeholders = implode(',', array_fill(0, count($parents), '?'));

        foreach ($guard::blockingReferences() as $reference) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT COUNT(*)
                   FROM information_schema.KEY_COLUMN_USAGE
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND COLUMN_NAME = ?
                    AND REFERENCED_TABLE_NAME IN (' . $placeholders . ')'
            );
            $stmt->execute(array_merge([$reference['table'], $reference['column']], $parents));

            self::assertGreaterThan(
                0,
                (int) $stmt->fetchColumn(),
                "{$guard} eviduje {$reference['table']}.{$reference['column']}, "
                . 'ale takový cizí klíč na rodičovskou tabulku neexistuje.',
            );
        }
    }

    /**
     * Registr scopuje počítání na tenanta (`supplier_id`). Tabulka bez toho
     * sloupce by dotaz shodila — chytit to musí test, ne uživatel.
     *
     * @param class-string<ForeignKeyDeletionGuard> $guard
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('guards')]
    public function testEveryBlockingTableCarriesTheTenantKey(string $guard): void
    {
        foreach ($guard::blockingTables() as $table) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'supplier_id'"
            );
            $stmt->execute([$table]);

            self::assertGreaterThan(
                0,
                (int) $stmt->fetchColumn(),
                "{$table} nemá supplier_id, takže ji {$guard} neumí scopovat na tenanta.",
            );
        }
    }

    /**
     * `tax_submission_artifacts` blokujeme ZÁMĚRNĚ, i když databáze kaskáduje —
     * jinak by vysypání koše mlčky smazalo důkaz o podání na finanční správu.
     * Kdyby se pravidlo v DB změnilo na RESTRICT, tenhle test to připomene, aby
     * komentář o „tiché kaskádě" nezůstal viset jako nepravda.
     */
    public function testDeliberateCascadeBlockersAreStillCascades(): void
    {
        foreach (DocumentDeletionGuard::deliberateCascadeBlockers() as $table) {
            self::assertContains($table, DocumentDeletionGuard::blockingTables());

            $stmt = $this->db->pdo()->prepare(
                'SELECT rc.DELETE_RULE
                   FROM information_schema.REFERENTIAL_CONSTRAINTS rc
                  WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
                    AND rc.TABLE_NAME = ?
                    AND rc.REFERENCED_TABLE_NAME = "documents"'
            );
            $stmt->execute([$table]);

            self::assertSame(
                'CASCADE',
                (string) $stmt->fetchColumn(),
                "{$table} už na documents nekaskáduje — přepiš komentář o tiché kaskádě.",
            );
        }
    }
}
