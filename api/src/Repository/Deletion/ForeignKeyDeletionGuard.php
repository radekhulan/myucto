<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Deletion;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Společný základ pro kontroly „co brání smazat tenhle řádek".
 *
 * ── Proč vůbec ────────────────────────────────────────────────────────────────
 * Mazací routy dřív mazaly naslepo a spoléhaly na to, že pod nimi všechno
 * kaskáduje. Mzdový modul ale připojil ke zbytku aplikace cizí klíče typu
 * RESTRICT (`payroll_payment_matches` na výpis, transakci i pokladní doklad;
 * mzdové a exekuční doklady na DMS), takže tytéž routy začaly padat na syrovou
 * databázovou hlášku — HTTP 500 s textem o `foreign key constraint`.
 *
 * ── Dvě půlky, obě povinné ────────────────────────────────────────────────────
 * Vzor drží {@see \MyInvoice\Action\Settings\SettingsAction::deleteCurrency()}:
 *  1. KONTROLA PŘEDEM ({@see countBlockers()}) — dá srozumitelnou hlášku, která
 *     jmenuje, kolik vazeb a jakého druhu mazání blokuje.
 *  2. ODCHYT VÝJIMKY (SQLSTATE 23000, {@see isForeignKeyViolation()}) — pokryje
 *     souběh (vazba vznikne mezi kontrolou a DELETE) i tabulku, na kterou se
 *     v registru zapomnělo.
 * Samotná kontrola nestačí, samotný odchyt taky ne.
 *
 * ── Registr místo natvrdo psaného seznamu v routě ─────────────────────────────
 * Seznam vazeb je deklarativní a VEŘEJNĚ ČITELNÝ ({@see blockingTables()},
 * {@see parentTables()}), aby proti němu mohl strukturální test z
 * `information_schema` ověřit, že žádná tabulka s blokujícím cizím klíčem
 * nechybí. Nová tabulka pak shodí test, ne produkci.
 */
abstract class ForeignKeyDeletionGuard
{
    public function __construct(protected readonly Connection $db) {}

    /**
     * Vazby, které mazání BLOKUJÍ. Klíč = strojový kód do odpovědi, `message` =
     * věta pro uživatele s `%d` na počet vazeb.
     *
     * @return array<string,array{message:string,references:list<array{table:string,column:string,via?:array{table:string,column:string,parent_column:string,parent_table:string}}>}>
     */
    abstract protected static function blockers(): array;

    /**
     * Tabulky, jejichž cizí klíče registr popisuje. Strukturální test se jich ptá
     * `information_schema`, které děti na ně ukazují.
     *
     * @return list<string>
     */
    abstract public static function parentTables(): array;

    /**
     * Registr tabulek, které mazání blokují — jediný zdroj pravdy pro rozhodnutí
     * i pro strukturální test.
     *
     * @return list<string>
     */
    final public static function blockingTables(): array
    {
        $tables = [];
        foreach (static::blockers() as $blocker) {
            foreach ($blocker['references'] as $reference) {
                $tables[] = $reference['table'];
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * Dvojice tabulka+sloupec, kterými registr na mazaný řádek ukazuje. Strukturální
     * test proti nim ověřuje, že sloupec v cizím klíči SKUTEČNĚ existuje — přejmenování
     * sloupce by jinak kontrolu tiše vypnulo (dotaz by spadl až v produkci).
     *
     * @return list<array{table:string,column:string,via?:array{table:string,column:string,parent_column:string,parent_table:string}}>
     */
    final public static function blockingReferences(): array
    {
        $references = [];
        foreach (static::blockers() as $blocker) {
            foreach ($blocker['references'] as $reference) {
                $references[$reference['table'] . '.' . $reference['column']] = $reference;
            }
        }

        return array_values($references);
    }

    /**
     * SQLSTATE 23000 = porušení integritního omezení (u InnoDB mj. 1451 „cannot
     * delete a parent row"). Jediné místo, kde se ta hodnota v mazacích cestách
     * čte — vzor převzat z `SettingsAction::deleteCurrency()`.
     */
    final public static function isForeignKeyViolation(\PDOException $e): bool
    {
        return (string) $e->getCode() === '23000';
    }

    /**
     * Kolik blokujících vazeb na řádku visí — po skupinách, jen nenulové.
     *
     * Dotaz je scopovaný na tenanta záměrně: kdyby nebyl, cizí tenant by z rozdílu
     * mezi 409 a 404 vyčetl, že cizí id existuje a je na něco navázané. Tenantní
     * predikát tak drží stejnou hranici jako čtecí cesta (viz `TenantPredicateTest`).
     *
     * @return array<string,int> kód blokátoru => počet
     */
    final protected function countBlockers(int $supplierId, int $parentId): array
    {
        $blockers = static::blockers();
        if ($blockers === []) {
            return [];
        }

        $columns = [];
        $params = [];
        foreach (array_keys($blockers) as $index => $code) {
            $parts = [];
            foreach ($blockers[$code]['references'] as $reference) {
                $query = self::referenceQueryParts($reference);
                $parts[] = '(SELECT COUNT(*) FROM ' . $query['from']
                    . ' WHERE ' . $query['supplier'] . ' = ? AND ' . $query['parent'] . ' = ?)';
                $params[] = $supplierId;
                $params[] = $parentId;
            }
            $columns[] = implode(' + ', $parts) . " AS blocker{$index}";
        }

        $stmt = $this->db->pdo()->prepare('SELECT ' . implode(', ', $columns));
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return [];
        }

        $counts = [];
        foreach (array_keys($blockers) as $index => $code) {
            $count = (int) $row["blocker{$index}"];
            if ($count > 0) {
                $counts[$code] = $count;
            }
        }

        return $counts;
    }

    /**
     * Táž otázka pro celou dávku najednou — hromadné mazání (koš) se nesmí ptát
     * po řádcích, jinak z toho je N+1 nad tisícem dokladů.
     *
     * @param list<int> $parentIds
     * @return array<int,array<string,int>> id => (kód blokátoru => počet)
     */
    final protected function countBlockersForIds(int $supplierId, array $parentIds): array
    {
        $parentIds = array_values(array_unique(array_map('intval', $parentIds)));
        if ($parentIds === [] || static::blockers() === []) {
            return [];
        }

        $result = [];
        foreach (array_chunk($parentIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            foreach (static::blockers() as $code => $blocker) {
                foreach ($blocker['references'] as $reference) {
                    $query = self::referenceQueryParts($reference);
                    $stmt = $this->db->pdo()->prepare(
                        'SELECT ' . $query['parent'] . ' AS parent_id, COUNT(*) AS cnt'
                        . ' FROM ' . $query['from']
                        . ' WHERE ' . $query['supplier'] . ' = ? AND ' . $query['parent'] . " IN ({$placeholders})"
                        . ' GROUP BY ' . $query['parent']
                    );
                    $stmt->execute(array_merge([$supplierId], $chunk));
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                        $id = (int) $row['parent_id'];
                        $result[$id][$code] = ($result[$id][$code] ?? 0) + (int) $row['cnt'];
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @param array{table:string,column:string,via?:array{table:string,column:string,parent_column:string,parent_table:string}} $reference
     * @return array{from:string,supplier:string,parent:string}
     */
    final protected static function referenceQueryParts(array $reference): array
    {
        $from = $reference['table'] . ' blocker_ref';
        $parent = 'blocker_ref.' . $reference['column'];
        if (isset($reference['via'])) {
            $via = $reference['via'];
            $from .= ' JOIN ' . $via['table'] . ' blocker_via ON blocker_via.' . $via['column'] . ' = ' . $parent
                . ' JOIN ' . $via['parent_table'] . ' blocker_parent ON blocker_parent.id = blocker_via.' . $via['parent_column']
                . ' AND blocker_parent.supplier_id = blocker_ref.supplier_id';
            $parent = 'blocker_via.' . $via['parent_column'];
        }

        return ['from' => $from, 'supplier' => 'blocker_ref.supplier_id', 'parent' => $parent];
    }

    /**
     * Věta pro uživatele složená ze všech skupin, které mazání brání.
     *
     * @param array<string,int> $counts
     */
    final protected static function describe(array $counts): string
    {
        $blockers = static::blockers();
        $sentences = [];
        foreach ($counts as $code => $count) {
            if (isset($blockers[$code])) {
                $sentences[] = sprintf($blockers[$code]['message'], $count);
            }
        }

        return implode(' ', $sentences);
    }
}
