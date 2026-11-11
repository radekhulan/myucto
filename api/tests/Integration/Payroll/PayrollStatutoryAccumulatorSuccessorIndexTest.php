<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tests\Support\PayrollTestContainer;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Hledání nástupce příspěvku v roční kumulaci musí jít po indexu.
 *
 * Kumulace se čte „bez nahrazených příspěvků": ke každému příspěvku se přes
 * `replaces_entry_id` hledá nástupce, a to při každém výpočtu (čtení kumulace
 * za osobu) i při schválení opravné revize za každou osobu a druh výpočtu.
 * Jediný index se `replaces_entry_id` ho měl až na šestém místě, takže dotaz
 * `WHERE supplier_id = ? AND replaces_entry_id = ?` procházel všechny příspěvky
 * firmy (EXPLAIN `rows 900` z 908) a oprava měsíce rostla kvadraticky.
 *
 * Test hlídá existenci indexu, ne plán: nad téměř prázdnou testovací tabulkou
 * si optimalizátor vybírá index libovolně, takže EXPLAIN by tu nic nedokazoval.
 */
#[Group('integration')]
final class PayrollStatutoryAccumulatorSuccessorIndexTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = PayrollTestContainer::build()->get(Connection::class);
        if (!$this->db->hasTable('payroll_statutory_accumulator_entries')) {
            $this->markTestSkipped('Chybí tabulka payroll_statutory_accumulator_entries.');
        }
    }

    public function testSuccessorLookupHasAnIndexLedBySupplierAndReplacedEntry(): void
    {
        $statement = $this->db->pdo()->query(
            "SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns_list
               FROM information_schema.statistics
              WHERE table_schema = DATABASE()
                AND table_name = 'payroll_statutory_accumulator_entries'
              GROUP BY index_name",
        );
        $leading = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns = explode(',', (string) ($row['columns_list'] ?? $row['COLUMNS_LIST'] ?? ''));
            $leading[] = implode(',', array_slice($columns, 0, 2));
        }

        self::assertContains(
            'supplier_id,replaces_entry_id',
            $leading,
            'Chybí index začínající (supplier_id, replaces_entry_id).',
        );
    }
}
