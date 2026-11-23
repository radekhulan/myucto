<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Dimension;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankPostingRuleRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\DimensionRepository;
use PDO;

/**
 * Dimenze z pravidla účtování bankovních pohybů. Zapisují se k pohybu
 * (`document_dimensions`, typ `bank_transaction`) až při skutečném použití pravidla,
 * tedy při schválení návrhu nebo automatickém zaúčtování, s původem {@see ORIGIN}
 * a odkazem `bank_posting_rule:<id>`. Zápis je záznamem stavu v okamžiku zaúčtování,
 * pozdější změna pravidla na pohybu nic nemění.
 *
 * Na rozdíl od ručně zadaných dimenzí pohybu nepatří na všechny řádky zápisu: dostane je
 * jen řádek banky a u pohybu, který nehradí doklad, protiúčet, stejně jako výchozí
 * dimenze vlastního účtu ({@see DimensionStamper::fillBankAccount()}). Typ po typu stojí
 * pod ručními dimenzemi pohybu a pod hrazenými doklady, nad výchozí hodnotou pravidla
 * dimenzí podle účtu a nad výchozími dimenzemi účtu. Proto se hodnoty s tímto původem
 * při zaúčtování neberou jako vlastní dimenze pohybu ({@see ownOnly()}), ale jako
 * samostatná vrstva ({@see onTransaction()}, {@see DimensionDefaults::sourceDimensions()}).
 */
final class BankRuleDimensions
{
    public const ORIGIN = 'bank_rule_default';

    public function __construct(private readonly Connection $db) {}

    public static function ref(int $ruleId): string
    {
        return 'bank_posting_rule:' . $ruleId;
    }

    /**
     * Zapíše k pohybu dimenze pravidla. Hodnoty z dřívějšího pravidla se nejdřív odeberou,
     * typ, který má pohyb zadaný ručně (hodnotou nebo rozpadem), zůstane beze změny.
     * Uzavřená hodnota a neaktivní typ se přeskočí stejně jako u výchozích dimenzí účtu
     * (ruční zadání by je odmítlo). Bez zapnutých dimenzí nedělá nic.
     */
    public function applyToTransaction(int $supplierId, int $txId, int $ruleId): void
    {
        if (!(new DimensionRepository($this->db))->enabled($supplierId)) {
            return;
        }
        $this->clearFromTransaction($supplierId, $txId);
        $dimensions = $this->activeOnly($supplierId, (new BankPostingRuleRepository($this->db))->dimensions($supplierId, $ruleId));
        if ($dimensions === []) {
            return;
        }
        $assignments = new DimensionAssignmentRepository($this->db);
        $own = $assignments->documentDimensions($supplierId, 'bank_transaction', $txId, true)['header'];
        $own += $assignments->documentSplits($supplierId, 'bank_transaction', $txId, true)[0] ?? [];
        $rows = [];
        $params = [];
        foreach (array_diff_key($dimensions, $own) as $typeId => $valueId) {
            $rows[] = '(?, ?, ?, 0, ?, ?, ?, ?)';
            array_push($params, $supplierId, 'bank_transaction', $txId, (int) $typeId, (int) $valueId, self::ORIGIN, self::ref($ruleId));
        }
        if ($rows === []) {
            return;
        }
        $this->db->pdo()->prepare(
            'INSERT INTO document_dimensions
                (supplier_id, doc_type, doc_id, item_no, dimension_type_id, dimension_value_id, assignment_origin, assignment_origin_ref)
             VALUES ' . implode(',', $rows)
        )->execute($params);
    }

    /**
     * @param array<int,int> $dimensions typ => hodnota
     * @return array<int,int>
     */
    private function activeOnly(int $supplierId, array $dimensions): array
    {
        if ($dimensions === []) {
            return [];
        }
        $repo = new DimensionRepository($this->db);
        $types = [];
        foreach ($repo->listTypes($supplierId, false) as $type) {
            $types[(int) $type['id']] = true;
        }
        $values = $repo->valuesByIds($supplierId, array_values($dimensions));
        return array_filter(
            $dimensions,
            static fn (int $valueId, int $typeId): bool => isset($types[$typeId])
                && !empty($values[$valueId]['is_active'])
                && (int) $values[$valueId]['type_id'] === $typeId,
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /** Odebere z pohybu hodnoty zapsané pravidlem (odúčtování, použití jiného pravidla). */
    public function clearFromTransaction(int $supplierId, int $txId): void
    {
        $this->db->pdo()->prepare(
            "DELETE FROM document_dimensions
              WHERE supplier_id = ? AND doc_type = 'bank_transaction' AND doc_id = ? AND item_no = 0
                AND assignment_origin = ?"
        )->execute([$supplierId, $txId, self::ORIGIN]);
    }

    /**
     * Hodnoty pohybu zapsané pravidlem s původem (samostatná vrstva, viz popis třídy).
     *
     * @return array{header:array<int,int>, origins:array<int,array{origin:string,ref:?string}>}
     */
    public function onTransaction(int $supplierId, int $txId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT dimension_type_id, dimension_value_id, assignment_origin_ref
               FROM document_dimensions
              WHERE supplier_id = ? AND doc_type = 'bank_transaction' AND doc_id = ? AND item_no = 0
                AND assignment_origin = ?
           ORDER BY dimension_type_id"
        );
        $stmt->execute([$supplierId, $txId, self::ORIGIN]);
        $out = ['header' => [], 'origins' => []];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $typeId = (int) $row['dimension_type_id'];
            $out['header'][$typeId] = (int) $row['dimension_value_id'];
            $out['origins'][$typeId] = ['origin' => self::ORIGIN, 'ref' => $row['assignment_origin_ref'] === null ? null : (string) $row['assignment_origin_ref']];
        }
        return $out;
    }

    /**
     * Typy dimenzí, které pohybům zapsalo pravidlo (pro rozlišení od ručně zadaných
     * v seznamu pohybů, např. při zakládání pravidla z pohybu).
     *
     * @param list<int> $txIds
     * @return array<int,list<int>> pohyb => typy
     */
    public function typesFor(int $supplierId, array $txIds): array
    {
        $txIds = array_values(array_unique(array_map('intval', $txIds)));
        if ($txIds === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT doc_id, dimension_type_id
               FROM document_dimensions
              WHERE supplier_id = ? AND doc_type = 'bank_transaction' AND item_no = 0
                AND doc_id IN (" . implode(',', array_fill(0, count($txIds), '?')) . ")
                AND assignment_origin = ?
           ORDER BY doc_id, dimension_type_id"
        );
        $stmt->execute([$supplierId, ...$txIds, self::ORIGIN]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['doc_id']][] = (int) $row['dimension_type_id'];
        }
        return $out;
    }

    /**
     * Vlastní dimenze pohybu bez hodnot zapsaných pravidlem.
     *
     * @param array<int,int> $header typ => hodnota
     * @param array<int,array<string,mixed>> $origins typ => původ
     * @return array<int,int>
     */
    public static function ownOnly(array $header, array $origins): array
    {
        return array_filter(
            $header,
            static fn (int $typeId): bool => ($origins[$typeId]['origin'] ?? null) !== self::ORIGIN,
            ARRAY_FILTER_USE_KEY,
        );
    }
}
