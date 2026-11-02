<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Dimension;

/**
 * Filtr sestav na hodnotu dimenze včetně celé její větve (podřízené hodnoty).
 *
 * Filtruje ŘÁDKY deníku, ne celé zápisy — výsledovka po projektu má obsahovat jen
 * náklady a výnosy projektu, ne protistranu 321/311 téhož zápisu. Předvaha s filtrem
 * proto záměrně nemusí být vyvážená.
 *
 * U typu Středisko se počítají i řádky, které hodnotu dimenze nemají, ale nesou
 * textový kód navázaného střediska (`journal_entry_lines.cost_center` — mzdy, starší
 * ruční zápisy). Řádek s dimenzí téhož typu rozhoduje dimenzí, ne textem, aby se
 * jeden řádek nezapočítal pod dvě hodnoty.
 *
 * Poddotazy se vážou jen přes id řádku (primární klíč vazby); firmu omezuje vnější
 * dotaz. Podmínka na supplier_id navíc svádí optimalizátor k indexu přes celou firmu.
 *
 * Filtr může nést další podmínky jiných typů (`$also`, sestavuje je
 * {@see DimensionService::filters()}): řádek pak musí patřit do všech zároveň, např.
 * Projekt = akce A a zároveň Středisko = dotace B. Vlastnosti `typeId`, `valueIds`,
 * `costCenterCodes` a `label` popisují vždy jen první podmínku; všechny vrací {@see criteria()}.
 */
final class DimensionFilter
{
    /**
     * Nejvýš tolik typů v jednom filtru. Součin dílů (DECIMAL(15,2)) má u čtyř typů
     * 60 číslic, víc by přeteklo limit 65 číslic DECIMAL v MariaDB.
     */
    public const MAX_TYPES = 4;

    /**
     * @param list<int> $valueIds hodnota a její podřízené
     * @param list<string> $costCenterCodes kódy středisek navázaných na tyto hodnoty
     * @param string|null $label popis pro hlavičku sestavy („Středisko: 100 Výroba")
     * @param list<self> $also další podmínky jiných typů, viz {@see all()}
     */
    public function __construct(
        public readonly int $typeId,
        public readonly int $valueId,
        public readonly array $valueIds,
        public readonly array $costCenterCodes = [],
        public readonly ?string $label = null,
        public readonly array $also = [],
    ) {}

    /**
     * Podmínky filtru po typech (každá bez dalších), první je tento filtr.
     *
     * @return list<self>
     */
    public function criteria(): array
    {
        if ($this->also === []) {
            return [$this];
        }
        return [new self($this->typeId, $this->valueId, $this->valueIds, $this->costCenterCodes, $this->label), ...$this->also];
    }

    /** Podmínka na daný typ, nebo null, filtruje-li se jen podle jiných typů. */
    public function criterion(int $typeId): ?self
    {
        foreach ($this->criteria() as $criterion) {
            if ($criterion->typeId === $typeId) return $criterion;
        }
        return null;
    }

    /** Filtr bez podmínky na daný typ (null, když jiná nezbude). */
    public function without(int $typeId): ?self
    {
        $rest = array_values(array_filter($this->criteria(), static fn (self $c): bool => $c->typeId !== $typeId));
        return $rest === [] ? null : self::all($rest);
    }

    /**
     * Spojí podmínky různých typů do jednoho filtru (řádek musí splnit všechny).
     *
     * @param non-empty-list<self> $criteria každá bez dalších podmínek
     */
    public static function all(array $criteria): self
    {
        $first = array_shift($criteria);
        if ($criteria === []) {
            return $first;
        }
        return new self($first->typeId, $first->valueId, $first->valueIds, $first->costCenterCodes, $first->label, $criteria);
    }

    /**
     * Zdroj řádků deníku pro sestavu filtrovanou na hodnotu: odvozená tabulka se
     * sloupci `journal_entry_lines` (id, entry_id, supplier_id, account_id, side,
     * amount, signed_amount, cost_center, line_no) pod aliasem `$alias`, kterou volající dosadí místo
     * `journal_entry_lines {$alias}`. Obsahuje
     *   • řádky s jedinou hodnotou z větve v plné výši,
     *   • u Střediska řádky bez dimenze typu s textovým kódem navázaného střediska,
     *   • řádky s ROZPADEM mezi víc hodnot typu jen v dílu připadajícím na hodnoty
     *     z větve ({@see DimensionSplitAllocation}) — řádek 60 % A / 40 % B se do
     *     sestavy za A započte šedesáti procenty, ne celý ani vůbec.
     *
     * Díky tomu sedí součet sestav přes všechny hodnoty a „bez hodnoty" na sestavu
     * firmy na haléř a řádek hodnoty ve výsledovce po dimenzi na výsledovku
     * filtrovanou na tutéž hodnotu.
     *
     * Odvozená tabulka s UNION ALL se materializuje celá a datum zápisu z vnějšího
     * dotazu se do ní nepropíše. Volající proto předá interval dat zápisu, který jeho
     * sestava čte (`$from` null = od počátku historie, `$to` včetně), a každá větev jde
     * po indexu (supplier_id, entry_date) zápisu místo celého deníku firmy.
     *
     * U filtru s více podmínkami projde jen řádek, který patří do všech, v částce
     * součinu jejich dílů: řádek s rozpadem 60 % na akci A a celý na středisko B
     * dá 60 % (tedy přesně díl A), rozpad v obou typech 60 % × 50 % = 30 %
     * zaokrouhlených na haléře. U rozpadu v obou typech proto součet přes všechny
     * kombinace hodnot nemusí na řádek sedět na haléř.
     *
     * @return array{0:string,1:list<int|string>}
     */
    public function lineSource(int $supplierId, string $alias, ?string $from, string $to): array
    {
        if ($this->also === []) {
            return $this->singleLineSource($supplierId, $alias, $from, $to);
        }
        $cols = 'fl.id, fl.entry_id, fl.supplier_id, fl.account_id, fl.side, %1$s AS amount, (CASE WHEN fl.is_red_storno = 1 THEN -(%1$s) ELSE %1$s END) AS signed_amount, fl.cost_center, fl.line_no';
        $joins = '';
        $params = [];
        $parts = [];
        // Zdroj jedné podmínky má pro řádek nejvýš jeden záznam: hodnota typu, rozpad typu
        // (sečtený po řádku) a textové středisko se vylučují, viz singleLineSource().
        foreach ($this->criteria() as $i => $criterion) {
            [$source, $sourceParams] = $criterion->singleLineSource($supplierId, "c{$i}", $from, $to);
            $joins .= " JOIN {$source} ON c{$i}.id = fl.id";
            array_push($params, ...$sourceParams);
            $parts[] = "c{$i}.amount";
        }
        // Součin dílů vydělený (n-1)-tou mocninou částky řádku: v DECIMAL přesně, dělí se jen jednou.
        $amount = 'CASE WHEN fl.amount = 0 THEN 0 ELSE ROUND(' . implode(' * ', $parts) . ' / ('
            . implode(' * ', array_fill(0, count($parts) - 1, 'fl.amount')) . '), 2) END';
        $sql = 'SELECT ' . sprintf($cols, $amount) . "
                  FROM journal_entry_lines fl{$joins}
                 WHERE fl.supplier_id = ?";
        $params[] = $supplierId;
        return ["({$sql}) {$alias}", $params];
    }

    /** @return array{0:string,1:list<int|string>} */
    private function singleLineSource(int $supplierId, string $alias, ?string $from, string $to): array
    {
        $cols = 'fl.id, fl.entry_id, fl.supplier_id, fl.account_id, fl.side, %1$s AS amount, (CASE WHEN fl.is_red_storno = 1 THEN -(%1$s) ELSE %1$s END) AS signed_amount, fl.cost_center, fl.line_no';
        $entryJoin = ' JOIN journal_entries fe ON fe.supplier_id = fl.supplier_id AND fe.id = fl.entry_id';
        $entrySql = ' AND fe.supplier_id = ?' . ($from !== null ? ' AND fe.entry_date >= ?' : '') . ' AND fe.entry_date <= ?';
        $entryParams = $from !== null ? [$supplierId, $from, $to] : [$supplierId, $to];
        $marks = implode(',', array_fill(0, count($this->valueIds), '?'));
        $sql = 'SELECT ' . sprintf($cols, 'fl.amount') . "
                  FROM journal_entry_line_dimensions fd
                  JOIN journal_entry_lines fl ON fl.id = fd.line_id{$entryJoin}
                 WHERE fd.dimension_type_id = ? AND fd.dimension_value_id IN ({$marks}) AND fl.supplier_id = ?{$entrySql}";
        $params = [$this->typeId, ...$this->valueIds, $supplierId, ...$entryParams];
        if ($this->costCenterCodes !== []) {
            [$ccSql, $ccParams] = $this->costCenterCondition('fl');
            $sql .= ' UNION ALL SELECT ' . sprintf($cols, 'fl.amount') . "
                  FROM journal_entry_lines fl{$entryJoin}
                 WHERE fl.supplier_id = ?{$entrySql} AND {$ccSql}";
            array_push($params, $supplierId, ...$entryParams, ...$ccParams);
        }
        [$shareSql, $shareParams] = DimensionSplitAllocation::lineShareSql($this->typeId, $this->valueIds, $supplierId, [$from, $to]);
        $sql .= ' UNION ALL SELECT ' . sprintf($cols, 'fs.amount') . "
                  FROM ({$shareSql}) fs
                  JOIN journal_entry_lines fl ON fl.id = fs.line_id
                 WHERE fl.supplier_id = ?";
        array_push($params, ...$shareParams);
        $params[] = $supplierId;
        return ["({$sql}) {$alias}", $params];
    }

    /**
     * Samostatná podmínka (bez vedoucího AND) nad aliasem ZÁPISU deníku: zápis projde,
     * nese-li hodnotu (nebo podřízenou) aspoň jeden jeho řádek, ať jako jedinou hodnotu,
     * nebo v rozpadu. Stejná sémantika řádku jako {@see lineSource()}, jen povýšená na zápis, protože seznam deníku ukazuje celé zápisy.
     *
     * Hodnoty jdou přes nekorelovaný IN poddotaz: optimalizátor ho zmaterializuje
     * jednou z indexu idx_jeld_type_value a pro každý zápis firmy pak jen hledá v
     * dočasné tabulce, místo aby pro každý zápis procházel jeho řádky.
     *
     * U filtru s více podmínkami musí všechny splnit TENTÝŽ řádek zápisu.
     *
     * @return array{0:string,1:list<int|string>}
     */
    public function entrySql(string $entryAlias): array
    {
        if ($this->also === []) {
            return $this->singleEntrySql($entryAlias);
        }
        // Řádky se hledají od podmínky bez textových středisek: její hodnoty a rozpady
        // jdou po indexu (typ, hodnota), ostatní podmínky se ověří jen na nalezených
        // řádcích. Korelovaný EXISTS přes řádky zápisu optimalizátor převede na
        // průchod celou tabulkou řádků.
        $criteria = $this->criteria();
        $driverIndex = null;
        foreach ($criteria as $i => $criterion) {
            if ($criterion->costCenterCodes === []) {
                $driverIndex = $i;
                break;
            }
        }
        if ($driverIndex === null) {
            // Všechny podmínky nesou textová střediska (filtr přes dva typy druhu
            // „středisko"): ta nemají index, řádky se ověří jen v zápisech, které
            // projdou podmínkami jednotlivě.
            $where = [];
            $params = [];
            $lineConditions = [];
            $lineParams = [];
            foreach ($criteria as $criterion) {
                [$sql, $sqlParams] = $criterion->singleEntrySql($entryAlias);
                $where[] = $sql;
                array_push($params, ...$sqlParams);
                [$sql, $sqlParams] = $criterion->lineCondition('jel_m');
                $lineConditions[] = $sql;
                array_push($lineParams, ...$sqlParams);
            }
            $where[] = "EXISTS (SELECT 1 FROM journal_entry_lines jel_m
                                 WHERE jel_m.supplier_id = {$entryAlias}.supplier_id AND jel_m.entry_id = {$entryAlias}.id AND " . implode(' AND ', $lineConditions) . ')';
            return ['(' . implode(' AND ', $where) . ')', [...$params, ...$lineParams]];
        }
        $driver = $criteria[$driverIndex];
        $marks = implode(',', array_fill(0, count($driver->valueIds), '?'));
        $params = [$driver->typeId, ...$driver->valueIds, $driver->typeId, ...$driver->valueIds];
        $driverSql = "SELECT dim_md.line_id FROM journal_entry_line_dimensions dim_md
                       WHERE dim_md.dimension_type_id = ? AND dim_md.dimension_value_id IN ({$marks})
                      UNION SELECT dim_mds.line_id FROM journal_entry_line_dimension_splits dim_mds
                       WHERE dim_mds.dimension_type_id = ? AND dim_mds.dimension_value_id IN ({$marks})";
        $lineConditions = [];
        foreach ($criteria as $i => $criterion) {
            if ($i === $driverIndex) continue;
            [$sql, $sqlParams] = $criterion->lineCondition('jel_m');
            $lineConditions[] = $sql;
            array_push($params, ...$sqlParams);
        }
        return ["{$entryAlias}.id IN (SELECT jel_m.entry_id
                          FROM ({$driverSql}) dim_m
                          JOIN journal_entry_lines jel_m ON jel_m.id = dim_m.line_id
                         WHERE jel_m.supplier_id = {$entryAlias}.supplier_id AND " . implode(' AND ', $lineConditions) . ')', $params];
    }

    /**
     * Podmínka nad aliasem ŘÁDKU deníku: řádek nese hodnotu z větve jako jedinou
     * hodnotu nebo v rozpadu, případně (u Střediska) textový kód navázaného střediska.
     *
     * @return array{0:string,1:list<int|string>}
     */
    private function lineCondition(string $lineAlias): array
    {
        $marks = implode(',', array_fill(0, count($this->valueIds), '?'));
        $sql = "(EXISTS (SELECT 1 FROM journal_entry_line_dimensions dim_ml
                          WHERE dim_ml.line_id = {$lineAlias}.id AND dim_ml.dimension_type_id = ?
                            AND dim_ml.dimension_value_id IN ({$marks}))
                 OR EXISTS (SELECT 1 FROM journal_entry_line_dimension_splits dim_ms
                          WHERE dim_ms.line_id = {$lineAlias}.id AND dim_ms.dimension_type_id = ?
                            AND dim_ms.dimension_value_id IN ({$marks}))";
        $params = [$this->typeId, ...$this->valueIds, $this->typeId, ...$this->valueIds];
        if ($this->costCenterCodes !== []) {
            [$ccSql, $ccParams] = $this->costCenterCondition($lineAlias);
            $sql .= " OR ({$ccSql})";
            array_push($params, ...$ccParams);
        }
        return [$sql . ')', $params];
    }

    /** @return array{0:string,1:list<int|string>} */
    private function singleEntrySql(string $entryAlias): array
    {
        $marks = implode(',', array_fill(0, count($this->valueIds), '?'));
        $sql = "({$entryAlias}.id IN (SELECT jel_f.entry_id
                          FROM journal_entry_line_dimensions dim_f
                          JOIN journal_entry_lines jel_f ON jel_f.id = dim_f.line_id
                         WHERE dim_f.dimension_type_id = ? AND dim_f.dimension_value_id IN ({$marks}))
                  OR {$entryAlias}.id IN (SELECT jel_s.entry_id
                          FROM journal_entry_line_dimension_splits dim_s
                          JOIN journal_entry_lines jel_s ON jel_s.id = dim_s.line_id
                         WHERE dim_s.dimension_type_id = ? AND dim_s.dimension_value_id IN ({$marks}))";
        $params = [$this->typeId, ...$this->valueIds, $this->typeId, ...$this->valueIds];
        if ($this->costCenterCodes !== []) {
            [$lineSql, $lineParams] = $this->costCenterCondition('jel_c');
            $sql .= " OR EXISTS (SELECT 1 FROM journal_entry_lines jel_c
                                  WHERE jel_c.entry_id = {$entryAlias}.id AND {$lineSql})";
            array_push($params, ...$lineParams);
        }
        return [$sql . ')', $params];
    }

    /**
     * Řádek bez dimenze tohoto typu (ani jediné hodnoty, ani rozpadu), který nese
     * textový kód navázaného střediska.
     *
     * @return array{0:string,1:list<int|string>}
     */
    private function costCenterCondition(string $lineAlias): array
    {
        $ccMarks = implode(',', array_fill(0, count($this->costCenterCodes), '?'));
        return [
            "{$lineAlias}.cost_center IN ({$ccMarks})
              AND NOT EXISTS (SELECT 1 FROM journal_entry_line_dimensions dim_c
                   WHERE dim_c.line_id = {$lineAlias}.id
                     AND dim_c.dimension_type_id = ?)
              AND NOT EXISTS (SELECT 1 FROM journal_entry_line_dimension_splits dim_cs
                   WHERE dim_cs.line_id = {$lineAlias}.id
                     AND dim_cs.dimension_type_id = ?)",
            [...$this->costCenterCodes, $this->typeId, $this->typeId],
        ];
    }

    /**
     * `type_id`, `value_id` a `value_ids` popisují první podmínku, `criteria` všechny;
     * `label` je popis celého filtru pro hlavičku sestavy.
     *
     * @return array{type_id:int, value_id:int, value_ids:list<int>, label:?string, criteria:list<array{type_id:int, value_id:int, value_ids:list<int>, label:?string}>}
     */
    public function toArray(): array
    {
        $labels = array_filter(array_map(static fn (self $c): ?string => $c->label, $this->criteria()));
        return ['type_id' => $this->typeId, 'value_id' => $this->valueId, 'value_ids' => $this->valueIds,
            'label' => $labels === [] ? null : implode(' · ', $labels),
            'criteria' => array_map(static fn (self $c): array => [
                'type_id' => $c->typeId, 'value_id' => $c->valueId, 'value_ids' => $c->valueIds, 'label' => $c->label,
            ], $this->criteria())];
    }
}
