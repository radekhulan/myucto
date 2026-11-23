<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use PDO;
use RuntimeException;

trait IsolatedSupplierTrait
{
    /**
     * Zakládá kopii zdrojového dodavatele přes AUTO_INCREMENT, ne hledáním volného
     * id v rozsahu 1–255. Ten strop tu byl jen kvůli sloupcům `supplier_id`, které
     * ještě byly TINYINT UNSIGNED; migrace 0115 (a 1305 pro tři regresní tabulky)
     * je rozšířila na INT UNSIGNED, takže omezovat rozsah nemá důvod. Naopak škodil:
     * spadlý běh testů nechá klony po sobě, pool se vyčerpá a další běh padá stovkami
     * chyb, které s testovaným kódem nesouvisejí.
     */
    protected function createIsolatedSupplier(PDO $pdo, int $sourceSupplierId): int
    {
        // Schéma se v rámci jednoho běhu nemění, ale tenhle dotaz stojí ~2,5 ms a volá se
        // při KAŽDÉM založení izolovaného dodavatele (336 testů) — zbytečně ~0,8 s na sadu.
        // Cache je statická, tedy per proces; migrace testovací DB proběhnou v bootstrapu
        // dřív, než se sem kdokoli dostane.
        static $columns = null;
        $columns ??= $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier'
                AND COLUMN_NAME <> 'id' AND EXTRA NOT LIKE '%auto_increment%'
                AND (GENERATION_EXPRESSION IS NULL OR GENERATION_EXPRESSION = '')
              ORDER BY ORDINAL_POSITION"
        )->fetchAll(PDO::FETCH_COLUMN);
        $columnList = implode(', ', array_map(static fn (string $column): string => "`{$column}`", $columns));

        $stmt = $pdo->prepare(
            "INSERT INTO supplier ({$columnList})
             SELECT {$columnList} FROM supplier WHERE id = ?"
        );
        $stmt->execute([$sourceSupplierId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Zdrojový supplier pro izolovaný test neexistuje.');
        }
        $supplierId = (int) $pdo->lastInsertId();
        if ($supplierId === 0) {
            throw new RuntimeException('Izolovaný supplier se nepodařilo založit — prázdné lastInsertId().');
        }

        $pdo->prepare(
            "INSERT IGNORE INTO supplier_vat_status_history (supplier_id, effective_from, is_vat_payer, is_identified)
             SELECT ?, '1900-01-01', is_vat_payer, is_identified FROM supplier WHERE id = ?"
        )->execute([$supplierId, $supplierId]);

        // Varianta testu `…InSingleEntryForm` projde týž scénář ve firmě s jednoduchou
        // (párovou) formou zápisu od počátku, tedy už od fixture.
        if (method_exists($this, 'name') && str_ends_with($this->name(), 'InSingleEntryForm')) {
            $pdo->prepare(
                "INSERT INTO accounting_entry_form_history (supplier_id, valid_from, entry_form, note)
                 VALUES (?, '1900-01-01', 'single', 'test')"
            )->execute([$supplierId]);
            \MyInvoice\Service\Accounting\EntryForm\EntryFormService::forgetShared($supplierId);
        }

        return $supplierId;
    }

    /**
     * Nastaví plátcovství DPH izolovaného supplieru k datu — zapíše řádek historie
     * a synchronizuje živou cache supplier.is_vat_payer/is_identified, pokud je
     * účinnost <= dnes. Testy NESMÍ přepínat plátcovství holým UPDATE supplier:
     * reporty čtou historii (vč. is_identified — migrace 1181).
     */
    protected function setVatPayerAt(PDO $pdo, int $supplierId, string $effectiveFrom, bool $isVatPayer, bool $isIdentified = false): void
    {
        $pdo->prepare(
            'INSERT INTO supplier_vat_status_history (supplier_id, effective_from, is_vat_payer, is_identified)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE is_vat_payer = VALUES(is_vat_payer), is_identified = VALUES(is_identified)'
        )->execute([$supplierId, $effectiveFrom, $isVatPayer ? 1 : 0, (!$isVatPayer && $isIdentified) ? 1 : 0]);

        $pdo->prepare(
            'UPDATE supplier s
               JOIN supplier_vat_status_history h ON h.supplier_id = s.id
                AND h.id = (
                    SELECT h2.id FROM supplier_vat_status_history h2
                     WHERE h2.supplier_id = s.id AND h2.effective_from <= CURRENT_DATE
                     ORDER BY h2.effective_from DESC, h2.id DESC LIMIT 1
                )
                SET s.is_vat_payer = h.is_vat_payer,
                    s.is_identified = h.is_identified
              WHERE s.id = ?'
        )->execute([$supplierId]);
    }
}
