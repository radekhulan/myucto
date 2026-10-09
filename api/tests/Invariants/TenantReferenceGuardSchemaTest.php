<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Invariants;

use MyInvoice\Bootstrap;
use MyInvoice\Http\TenantReferenceGuard;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Vrstva L3 — mapa `TenantReferenceGuard::SCOPES` proti SKUTEČNÉMU schématu.
 *
 * Mapu nejde odvodit z názvů sloupců, takže je psaná ručně — a ručně psaná mapa
 * se od schématu dřív nebo později rozejde. Dvě věci, které tenhle test hlídá,
 * jsou přesně ty, na kterých by se to podělalo:
 *
 *   - `vendor_id` míří na `clients`, ne na tabulku `vendors` (ta v DB není);
 *   - `projects` NEMÁ `supplier_id` a scope-uje se přes `clients.supplier_id`
 *     (FK `fk_proj_client`), takže musí zůstat ve větvi VIA_CLIENT.
 *
 * Rozejití mapy se schématem není kosmetika: guard by kontroloval vlastnictví
 * v jiné tabulce, než do které FK reálně míří, a tiše by pouštěl cizí záznamy.
 */
#[Group('invariants')]
final class TenantReferenceGuardSchemaTest extends TestCase
{
    /**
     * Sloupce, které v tomhle schématu deklarovaný FK NEMAJÍ — žijí jen v mapě.
     * Seznam je uzavřený: přibude-li FK, test to ohlásí (a naopak).
     *
     * @var list<string>
     */
    private const WITHOUT_DECLARED_FK = ['revenue_category_id', 'expense_category_id'];

    /**
     * Sloupce, jejichž jméno v různých tabulkách míří na RŮZNÉ cíle. Guard se
     * proto nikdy neptá „co je to za sloupec", ale dostává výčet od volajícího
     * (viz docblock TenantReferenceGuard). Hodnota = všechny cíle, které smí mít.
     *
     * @var array<string, list<string>>
     */
    private const AMBIGUOUS_TARGETS = [
        'client_id' => ['clients', 'mcp_oauth_clients'],
        // `trips.category_id` → trip_categories (to je ta větev, kterou guard obsluhuje),
        // ale `stock_category_i18n.category_id` a `stock_item_categories.category_id`
        // → stock_categories. Právě kvůli tomuhle je seznam sloupců na volajícím.
        'category_id' => ['trip_categories', 'stock_categories', 'submission_inbox_categories'],
    ];

    private Connection $db;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 3) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — schema test vyžaduje DB.');
        }
        try {
            $this->db = Bootstrap::buildContainer()->get(Connection::class);
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

    /** Cíl v mapě musí sedět s REFERENCED_TABLE_NAME u sloupců, které FK mají. */
    public function testMappedTargetsMatchDeclaredForeignKeys(): void
    {
        $declared = $this->declaredForeignKeyTargets();
        $problems = [];

        foreach (TenantReferenceGuard::SCOPES as $column => [$table, $via]) {
            $targets = $declared[$column] ?? [];

            if ($targets === []) {
                if (!in_array($column, self::WITHOUT_DECLARED_FK, true)) {
                    $problems[] = "'{$column}' nemá v schématu žádný FK, ale není ve WITHOUT_DECLARED_FK";
                }
                continue;
            }

            if (in_array($column, self::WITHOUT_DECLARED_FK, true)) {
                $problems[] = "'{$column}' už FK MÁ (" . implode(', ', $targets)
                    . ') — vyřaď ho z WITHOUT_DECLARED_FK';
                continue;
            }

            if (!in_array($table, $targets, true)) {
                $problems[] = "'{$column}' je v mapě na '{$table}', ale FK míří na "
                    . implode(', ', $targets);
                continue;
            }

            $allowed = self::AMBIGUOUS_TARGETS[$column] ?? [$table];
            $unexpected = array_values(array_diff($targets, $allowed));
            if ($unexpected !== []) {
                $problems[] = "'{$column}' míří i na " . implode(', ', $unexpected)
                    . ' — sloupec je nejednoznačný, doplň ho do AMBIGUOUS_TARGETS'
                    . ' a ověř, že ho žádná Action nepředává guardu v jiném významu';
            }

            unset($via);
        }

        self::assertSame(
            [],
            $problems,
            "TenantReferenceGuard::SCOPES se rozešla se schématem:\n  " . implode("\n  ", $problems),
        );
    }

    /** VIA_SUPPLIER tabulka musí mít `supplier_id`, VIA_CLIENT ho mít NESMÍ. */
    public function testScopingStrategyMatchesTableColumns(): void
    {
        $problems = [];

        foreach (TenantReferenceGuard::SCOPES as $column => [$table, $via]) {
            $columns = $this->columnsOf($table);
            if ($columns === []) {
                $problems[] = "tabulka '{$table}' (z '{$column}') v schématu neexistuje";
                continue;
            }

            $hasSupplier = in_array('supplier_id', $columns, true);

            if ($via === TenantReferenceGuard::VIA_SUPPLIER && !$hasSupplier) {
                $problems[] = "'{$column}' → '{$table}' je VIA_SUPPLIER, ale tabulka supplier_id nemá";
            }
            if ($via === TenantReferenceGuard::VIA_CLIENT) {
                if ($hasSupplier) {
                    $problems[] = "'{$column}' → '{$table}' je VIA_CLIENT, ale tabulka už supplier_id MÁ"
                        . ' — přepni ho na VIA_SUPPLIER (přímý predikát je levnější i přesnější)';
                }
                if (!in_array('client_id', $columns, true)) {
                    $problems[] = "'{$column}' → '{$table}' je VIA_CLIENT, ale nemá client_id, přes které se scope-uje";
                }
            }
        }

        self::assertSame(
            [],
            $problems,
            "Způsob scope-ování v SCOPES neodpovídá schématu:\n  " . implode("\n  ", $problems),
        );
    }

    /**
     * Sloupec → seznam tabulek, na které v tomhle schématu míří deklarovaným FK.
     *
     * @return array<string, list<string>>
     */
    private function declaredForeignKeyTargets(): array
    {
        $stmt = $this->db->pdo()->query(
            'SELECT COLUMN_NAME, REFERENCED_TABLE_NAME
               FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL'
        );

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $column = (string) $row['COLUMN_NAME'];
            $target = (string) $row['REFERENCED_TABLE_NAME'];
            if (!isset(TenantReferenceGuard::SCOPES[$column])) {
                continue;
            }
            $out[$column][$target] = true;
        }

        return array_map(
            static fn (array $targets): array => array_keys($targets),
            $out,
        );
    }

    /** @return list<string> */
    private function columnsOf(string $table): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
