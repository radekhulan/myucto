<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionValueLabel;
use MyInvoice\Service\Accounting\TakenOverRecord;
use PDO;

/**
 * Účtotvorná dimenze (migrace 1952): typ s `drives_accounts` a mapa hodnota dimenze
 * × syntetika → analytika firmy.
 *
 * Mapa patří firmě i u skupinové hodnoty — účtový rozvrh je per firma. Typ firma vidí
 * vlastní nebo skupinový (stejný predikát jako {@see DimensionRepository::visibleSql()}).
 */
final class DimensionAccountMapRepository
{
    /** Analytika (alias `a`) leží přímo pod syntetikou (`s`) a má stejný druh i daňovou uznatelnost. */
    private const VALID_PAIR_SQL = '(a.parent_id = s.id AND a.tax_deductibility = s.tax_deductibility AND a.account_type = s.account_type)';

    public function __construct(private readonly Connection $db) {}

    /**
     * Aktivní účtotvorný typ, který firma vidí. Víc jich být nemá (unikátní index
     * v rámci vlastníka, souběh firmy a skupiny hlídá DimensionAccountMapService);
     * kdyby přesto byly, platí firemní před skupinovým a pak nižší id — deterministicky.
     *
     * @return array{id:int, mask:string}|null
     */
    public function drivingType(int $supplierId, bool $currentRead = false): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT t.id, t.drives_accounts_mask
               FROM dimension_types t
               JOIN supplier s ON s.id = ?
              WHERE t.drives_accounts = 1 AND t.is_active = 1
                AND (t.supplier_id = s.id OR (s.supplier_group_id IS NOT NULL AND t.supplier_group_id = s.supplier_group_id))
              ORDER BY t.supplier_id IS NULL, t.id
              LIMIT 1' . ($currentRead ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : ['id' => (int) $row['id'], 'mask' => (string) $row['drives_accounts_mask']];
    }

    /**
     * Účtotvorné typy, které firma vidí (i neaktivní) — pro kontrolu „nejvýš jeden".
     *
     * @return list<int>
     */
    public function drivingTypeIds(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT t.id
               FROM dimension_types t
               JOIN supplier s ON s.id = ?
              WHERE t.drives_accounts = 1
                AND (t.supplier_id = s.id OR (s.supplier_group_id IS NOT NULL AND t.supplier_group_id = s.supplier_group_id))
              ORDER BY t.id'
        );
        $stmt->execute([$supplierId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Platná mapa k datu: syntetika => hodnota => analytika. Neaktivní analytika
     * ani syntetika se neuplatní — zápis by na ni stejně neprošel. Řádek, jehož
     * analytika mezitím přestala odpovídat (jiný rodič, jiná daňová uznatelnost nebo
     * druh — např. po importu účtového rozvrhu), se neuplatní taky; ukáže ho kontrola
     * uzávěrky ({@see invalidRows()}).
     *
     * @return array<int,array<int,int>>
     */
    public function activeMap(int $supplierId, int $typeId, string $date, bool $currentRead = false): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT m.synthetic_account_id, m.dimension_value_id, m.analytic_account_id
               FROM dimension_account_map m
               JOIN " . AccountDefinitionRepository::relation($date, $supplierId, $currentRead) . " s ON s.id = m.synthetic_account_id AND s.supplier_id = m.supplier_id
               JOIN " . AccountDefinitionRepository::relation($date, $supplierId, $currentRead) . " a ON a.id = m.analytic_account_id AND a.supplier_id = m.supplier_id
              WHERE m.supplier_id = ? AND m.dimension_type_id = ?
                AND (m.valid_from IS NULL OR m.valid_from <= ?)
                AND (m.valid_to IS NULL OR m.valid_to >= ?)
                AND s.is_active = 1 AND a.is_active = 1
                AND " . self::VALID_PAIR_SQL . "
              ORDER BY m.valid_from IS NULL, m.valid_from DESC, m.id" . ($currentRead ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId, $typeId, $date, $date]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['synthetic_account_id']][(int) $r['dimension_value_id']] ??= (int) $r['analytic_account_id'];
        }
        return $out;
    }

    /**
     * Všechny cílové analytiky mapy firmy bez ohledu na typ a platnost:
     * syntetika => seznam analytik. Podle nich časové rozlišení pozná, že zápis
     * dokladu nese analytiku z mapy.
     *
     * @return array<int,list<int>>
     */
    public function allTargets(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT synthetic_account_id, analytic_account_id FROM dimension_account_map WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['synthetic_account_id']][] = (int) $r['analytic_account_id'];
        }
        return $out;
    }

    /**
     * Řádky mapy, jejichž analytika už neodpovídá syntetice (rodič, druh, daňová
     * uznatelnost) — zaúčtování je přeskakuje, kontrola uzávěrky je hlásí.
     *
     * @return list<array{account_id:int, account_code:string, name:string}>
     */
    public function invalidRows(int $supplierId, int $typeId, ?string $date = null, bool $currentRead = false): array
    {
        $date = AccountDefinitionRepository::date($date ?? date('Y-m-d'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT DISTINCT m.analytic_account_id AS account_id, identity_a.account_code, identity_s.account_code AS synthetic_code, v.code AS value_code, v.name AS value_name, v.semantic_key AS value_semantic_key
               FROM dimension_account_map m
               JOIN chart_of_accounts identity_s ON identity_s.id=m.synthetic_account_id AND identity_s.supplier_id=m.supplier_id
               JOIN chart_of_accounts identity_a ON identity_a.id=m.analytic_account_id AND identity_a.supplier_id=m.supplier_id
          LEFT JOIN " . AccountDefinitionRepository::relation($date, $supplierId, $currentRead) . " s ON s.id = m.synthetic_account_id AND s.supplier_id = m.supplier_id
          LEFT JOIN " . AccountDefinitionRepository::relation($date, $supplierId, $currentRead) . " a ON a.id = m.analytic_account_id AND a.supplier_id = m.supplier_id
               JOIN dimension_values v ON v.id = m.dimension_value_id
              WHERE m.supplier_id = ? AND m.dimension_type_id = ?
                AND (m.valid_from IS NULL OR m.valid_from <= ?)
                AND (m.valid_to IS NULL OR m.valid_to >= ?)
                AND (s.id IS NULL OR a.id IS NULL OR s.is_active=0 OR a.is_active=0 OR NOT " . self::VALID_PAIR_SQL . ")
              ORDER BY identity_a.account_code" . ($currentRead ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId, $typeId, $date, $date]);
        return array_map(static fn (array $r): array => [
            'account_id' => (int) $r['account_id'],
            'account_code' => (string) $r['account_code'],
            'name' => DimensionValueLabel::short(['code' => $r['value_code'], 'name' => $r['value_name'], 'semantic_key' => $r['value_semantic_key']])
                . ': ' . $r['synthetic_code'] . ' → ' . $r['account_code'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Má firma pro typ vůbec nějaký řádek mapy (bez ohledu na platnost)? */
    public function hasRows(int $supplierId, int $typeId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM dimension_account_map WHERE supplier_id = ? AND dimension_type_id = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $typeId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Řádky mapy firmy (volitelně jedné hodnoty) i s kódy a názvy účtů.
     *
     * @return list<array{id:int, dimension_type_id:int, dimension_value_id:int, synthetic_account_id:int,
     *                    synthetic_code:string, synthetic_name:string, analytic_account_id:int,
     *                    analytic_code:string, analytic_name:string, valid_from:?string, valid_to:?string}>
     */
    public function listForSupplier(int $supplierId, ?int $valueId = null): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT m.id, m.dimension_type_id, m.dimension_value_id, m.synthetic_account_id,
                    identity_s.account_code AS synthetic_code, COALESCE(s.name,identity_s.account_code) AS synthetic_name,
                    m.analytic_account_id, identity_a.account_code AS analytic_code, COALESCE(a.name,identity_a.account_code) AS analytic_name,
                    m.valid_from, m.valid_to
               FROM dimension_account_map m
               JOIN chart_of_accounts identity_s ON identity_s.id = m.synthetic_account_id AND identity_s.supplier_id = m.supplier_id
               JOIN chart_of_accounts identity_a ON identity_a.id = m.analytic_account_id AND identity_a.supplier_id = m.supplier_id
          LEFT JOIN ' . AccountDefinitionRepository::relation(date('Y-m-d'), $supplierId) . ' s ON s.id = m.synthetic_account_id AND s.supplier_id = m.supplier_id
          LEFT JOIN ' . AccountDefinitionRepository::relation(date('Y-m-d'), $supplierId) . ' a ON a.id = m.analytic_account_id AND a.supplier_id = m.supplier_id
              WHERE m.supplier_id = ?' . ($valueId !== null ? ' AND m.dimension_value_id = ?' : '') . '
              ORDER BY m.dimension_value_id, identity_s.account_code, m.valid_from IS NULL DESC, m.valid_from, m.id'
        );
        $stmt->execute($valueId !== null ? [$supplierId, $valueId] : [$supplierId]);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'dimension_type_id' => (int) $r['dimension_type_id'],
            'dimension_value_id' => (int) $r['dimension_value_id'],
            'synthetic_account_id' => (int) $r['synthetic_account_id'],
            'synthetic_code' => (string) $r['synthetic_code'],
            'synthetic_name' => (string) $r['synthetic_name'],
            'analytic_account_id' => (int) $r['analytic_account_id'],
            'analytic_code' => (string) $r['analytic_code'],
            'analytic_name' => (string) $r['analytic_name'],
            'valid_from' => $r['valid_from'] === null ? null : (string) $r['valid_from'],
            'valid_to' => $r['valid_to'] === null ? null : (string) $r['valid_to'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Nahradí mapu jedné hodnoty dimenze ve firmě (jedno společné Uložit).
     *
     * @param list<array{synthetic_account_id:int, analytic_account_id:int, valid_from:?string, valid_to:?string}> $rows
     */
    public function replaceForValue(int $supplierId, int $typeId, int $valueId, array $rows, ?int $userId): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM dimension_account_map WHERE supplier_id = ? AND dimension_value_id = ?')
            ->execute([$supplierId, $valueId]);
        if ($rows === []) {
            return;
        }
        $insert = $pdo->prepare(
            'INSERT INTO dimension_account_map
                (supplier_id, dimension_type_id, dimension_value_id, synthetic_account_id, analytic_account_id,
                 valid_from, valid_to, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($rows as $r) {
            $insert->execute([
                $supplierId, $typeId, $valueId, $r['synthetic_account_id'], $r['analytic_account_id'],
                $r['valid_from'], $r['valid_to'], $userId,
            ]);
        }
    }

    /** Smaže mapu typu ve firmě (typ přestal být účtotvorný). */
    public function deleteForType(int $supplierId, int $typeId): int
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM dimension_account_map WHERE supplier_id = ? AND dimension_type_id = ?');
        $stmt->execute([$supplierId, $typeId]);
        return $stmt->rowCount();
    }

    /**
     * Zaúčtované výsledkové řádky, které zůstaly na syntetice s mapou účtotvorné dimenze
     * (řádku chyběla hodnota typu, nebo hodnota bez mapování). Nepočítají se:
     *  - uzávěrkové a otevírací zápisy (uzavírají zůstatek syntetiky jako celek),
     *  - stornované zápisy a storna (source_id NULL) — pár se vyruší,
     *  - zápisy bez zdrojového dokladu a převzaté z jiného programu (mapu obcházejí
     *    záměrně, {@see \MyInvoice\Service\Accounting\TakenOverRecord}),
     *  - cizoměnové řádky (mapa je nedělí a nechává na syntetice),
     *  - řádky mimo platnost mapy k datu účetního případu.
     *
     * @return list<array{account_id:int, account_code:string, name:string, line_count:int, amount:float}>
     */
    public function unmappedSyntheticLines(int $supplierId, int $typeId, string $from, string $to): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.id AS account_id, a.account_code, a.name, COUNT(*) AS line_count, ROUND(SUM(l.signed_amount), 2) AS amount
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               " . AccountDefinitionRepository::journalJoin($supplierId) . "
              WHERE l.supplier_id = ?
                AND e.entry_date BETWEEN ? AND ?
                AND e.posted_at IS NOT NULL
                AND e.source_type NOT IN ('closing', 'opening')
                AND e.reversed_by IS NULL
                AND e.source_id IS NOT NULL
                AND NOT " . TakenOverRecord::journalEntrySql('e') . "
                AND l.currency_code IS NULL
                AND EXISTS (SELECT 1 FROM dimension_account_map m
                             WHERE m.supplier_id = l.supplier_id AND m.dimension_type_id = ?
                               AND m.synthetic_account_id = l.account_id
                               AND (m.valid_from IS NULL OR m.valid_from <= e.entry_date)
                               AND (m.valid_to IS NULL OR m.valid_to >= e.entry_date))
              GROUP BY a.id, a.account_code, a.name
              ORDER BY a.account_code"
        );
        $stmt->execute([$supplierId, $from, $to, $typeId]);
        return array_map(static fn (array $r): array => [
            'account_id' => (int) $r['account_id'],
            'account_code' => (string) $r['account_code'],
            'name' => (string) $r['name'],
            'line_count' => (int) $r['line_count'],
            'amount' => (float) $r['amount'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Účty rozvrhu firmy, které mapa potřebuje: kód, rodič, druh a daňová uznatelnost.
     *
     * @return array<int,array{code:string, name:string, parent_id:?int, account_type:string, tax_deductibility:string, is_active:bool}>
     */
    public function accounts(int $supplierId, ?string $date = null, bool $currentRead = false): array
    {
        $date = AccountDefinitionRepository::date($date ?? date('Y-m-d'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, account_code, name, parent_id, account_type, tax_deductibility, is_active
               FROM ' . AccountDefinitionRepository::relation($date, $supplierId, $currentRead) . ' a WHERE supplier_id = ?' . ($currentRead ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = [
                'code' => (string) $r['account_code'],
                'name' => (string) $r['name'],
                'parent_id' => $r['parent_id'] === null ? null : (int) $r['parent_id'],
                'account_type' => (string) $r['account_type'],
                'tax_deductibility' => (string) $r['tax_deductibility'],
                'is_active' => (bool) $r['is_active'],
            ];
        }
        return $out;
    }
}
