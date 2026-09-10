<?php

declare(strict_types=1);

namespace MyInvoice\Service\System\Schema;

use PDO;

/**
 * Doplní SYSTEM VERSIONING deníku, pokud chybí (§ 12 ZoÚ, průkaznost účetních záznamů).
 *
 * Migrace 1029 versioning zapíná a 1167 ho jednou doplňuje, jenže obě se evidují podle
 * názvu souboru. Import dumpu přenese záznam v `migrations` spolu s daty, tabulky ale
 * vzniknou bez versioningu — a žádná z migrací se už nespustí. Proto tahle kontrola běží
 * po každém `migrate.php`, ne jako další migrace.
 *
 * Na už verzované tabulce i mimo MariaDB nedělá nic.
 */
final class JournalVersioningSelfHeal
{
    public const TABLES = ['journal_entries', 'journal_entry_lines'];

    public function __construct(private readonly PDO $pdo) {}

    /**
     * @param list<string> $tables
     * @return list<string> tabulky, kterým se versioning doplnil
     */
    public function heal(array $tables = self::TABLES): array
    {
        $version = (string) $this->pdo->query('SELECT VERSION()')->fetchColumn();
        if (stripos($version, 'mariadb') === false) {
            return [];
        }

        $baseTable = $this->pdo->prepare(
            "SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND TABLE_TYPE = 'BASE TABLE'"
        );

        $healed = [];
        foreach ($tables as $table) {
            $baseTable->execute([$table]);
            $isBase = $baseTable->fetchColumn() !== false;
            $baseTable->closeCursor();
            if (!$isBase) {
                continue;
            }
            // Bez toho by MariaDB odmítla každý pozdější ALTER verzované tabulky (4119).
            $this->pdo->exec('SET @@system_versioning_alter_history = 1');
            $this->pdo->exec('ALTER TABLE `' . str_replace('`', '``', $table) . '` ADD SYSTEM VERSIONING');
            $healed[] = $table;
        }

        return $healed;
    }
}
