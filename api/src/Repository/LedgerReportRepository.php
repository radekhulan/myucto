<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Accounting\Dimension\DimensionFilter;
use MyInvoice\Service\Tax\Return\JournalTaxOrigin;
use PDO;

/**
 * Repository pro účetní sestavy (Epic F2) — agregace nad deníkem (journal_entries
 * + journal_entry_lines). Sestavy čtou VÝHRADNĚ zaúčtované zápisy
 * (posted_at IS NOT NULL, R1); storno zápisy se nefiltrují — vyruší se v SUM (R3).
 *
 * PS okna (R6): rozvahové/uzávěrkové/podrozvahové účty kumulativně od počátku
 * historie (entry_date < from); výsledkové účty (revenue/expense) jen od začátku
 * účetního období, do nějž `from` spadá — před uzávěrkou (F4) nejsou výsledkové
 * účty nulovány a kumulativní PS by ukazoval loňské náklady.
 *
 * openingAnchor (F4 R16): po zaúčtované uzávěrce začíná PS okno rozvahových
 * účtů na starts_on posledního období se zaúčtovaným opening zápisem (PS = jen
 * opening + pohyby od něj — žádné zdvojení s historií); syntheticBalances navíc
 * vylučuje closing zápisy (výkazy uzavřeného roku k ends_on nejsou vynulované).
 * Bez uzávěrky je anchor NULL a chování je bitově shodné s F2 (behavior-preserving).
 *
 * Roll-up analytik (R15): analytika (parent_id → syntetika) se v agregacích roluje
 * na syntetiku přes COALESCE(a.parent_id, a.id); `analytics=true` = rozpad.
 */
final class LedgerReportRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Řádky obratové předvahy / hlavní knihy: PS (dle R6) + obraty v rozsahu,
     * rolované na syntetiku (R15) nebo rozpadlé po analytikách.
     *
     * @param array{vendor?:string, client?:string, item?:string} $filters hledání
     *        dle protistrany/položky zdrojového dokladu (viz {@see counterpartyFilter()})
     * @return list<array<string,mixed>> {id, account_code, name, account_type,
     *                                    normal_side, is_synthetic, parent_id, parent_code,
     *                                    parent_name, parent_normal_side, ps_md, ps_d, to_md, to_d,
     *                                    to_count (počet řádků obratu, pro limit rozpisu)}
     */
    public function trialBalanceRows(int $supplierId, string $from, string $to, string $periodStart, bool $analytics = false, array $filters = [], bool $excludeClosing = false, bool $excludeAllOpenings = false): array
    {
        $accountDefinitions=AccountDefinitionRepository::relation($to,$supplierId);
        $anchor = $this->openingAnchor($supplierId, $from);
        [$filterSql, $filterParams] = $this->counterpartyFilter($filters, 'e');
        // Rozvahové účty čtou od kotvy, výsledkové od začátku období; bez kotvy celou historii.
        [$linesSql, $linesParams] = $this->lineSource($supplierId, $filters['dimension'] ?? null, $anchor !== null ? min($anchor, $periodStart) : null, $to);
        // Inventarizace/rozvaha k rozvahovému dni potřebuje zůstatky PŘED uzavřením knih —
        // close_books zápis (source_type='closing', source_id = period_id < STOCK_SLOT_BASE, UZ)
        // převádí rozvahové účty na 702/710 a jinak by je vynuloval. Otevírací
        // (source_type='opening') a slotované skladové zápisy §3.4 (112/132/501/504,
        // source_id >= STOCK_SLOT_BASE) zůstávají — reálné zůstatky zásob k rozvahovému dni.
        $closingSql = $excludeClosing ? " AND " . JournalTaxOrigin::includedSql() : '';
        $closingParams = $excludeClosing ? [ClosingSourceId::STOCK_SLOT_BASE] : [];
        $turnoverOpeningSql = $excludeAllOpenings
            ? "e.source_type <> 'opening'"
            : "NOT (e.entry_date = ? AND e.source_type = 'opening')";
        $turnoverOpeningParams = $excludeAllOpenings ? [] : [$from];
        $stmt = $this->db->pdo()->prepare(
            ($excludeClosing ? "WITH RECURSIVE " . JournalTaxOrigin::cte($supplierId) . ", " : "WITH ") . "agg AS (
                SELECT
                    CASE WHEN ? = 1 THEN a.id ELSE COALESCE(a.parent_id, a.id) END AS acc_id,
                    -- Otevírací zápis je datovaný na PRVNÍ den období, takže při
                    -- obvyklém `from = starts_on` byl interval PS (>= anchor, < from)
                    -- PRÁZDNÝ a celý počáteční stav spadl mezi OBRATY. Předvaha pak
                    -- za 2026 tvrdila, že firma nově vytvořila 7 205 122,67 Kč
                    -- nerozděleného zisku, ačkoli 4 601 489,45 z toho je převod
                    -- z minulého roku. Otevírací zápis proto patří do PS, ne do obratů.
                    SUM(CASE WHEN (e.entry_date < ? OR (e.entry_date = ? AND e.source_type = 'opening'))
                              AND (a.account_type NOT IN ('revenue','expense') OR e.entry_date >= ?)
                              AND (a.account_type NOT IN ('asset','liability','equity') OR ? IS NULL OR e.entry_date >= ?)
                              AND l.side = 'debit'  THEN l.signed_amount ELSE 0 END) AS ps_md,
                    SUM(CASE WHEN (e.entry_date < ? OR (e.entry_date = ? AND e.source_type = 'opening'))
                              AND (a.account_type NOT IN ('revenue','expense') OR e.entry_date >= ?)
                              AND (a.account_type NOT IN ('asset','liability','equity') OR ? IS NULL OR e.entry_date >= ?)
                              AND l.side = 'credit' THEN l.signed_amount ELSE 0 END) AS ps_d,
                    SUM(CASE WHEN e.entry_date >= ? AND {$turnoverOpeningSql}
                              AND l.side = 'debit'  THEN l.signed_amount ELSE 0 END) AS to_md,
                    SUM(CASE WHEN e.entry_date >= ? AND {$turnoverOpeningSql}
                              AND l.side = 'credit' THEN l.signed_amount ELSE 0 END) AS to_d,
                    SUM(CASE WHEN e.entry_date >= ? AND {$turnoverOpeningSql} THEN 1 ELSE 0 END) AS to_count
                FROM {$linesSql}
                JOIN journal_entries e   ON e.id = l.entry_id
                " . ($excludeClosing ? JournalTaxOrigin::join() : "") . "
                JOIN {$accountDefinitions} a ON a.id = l.account_id
                WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL AND e.entry_date <= ?{$filterSql}{$closingSql}
                GROUP BY acc_id
            )
            SELECT c.id, c.account_code, c.name, c.account_type, c.normal_side, c.is_synthetic,
                   p.id AS parent_id, p.account_code AS parent_code, p.name AS parent_name,
                   p.normal_side AS parent_normal_side,
                   agg.ps_md, agg.ps_d, agg.to_md, agg.to_d, agg.to_count
              FROM agg
              JOIN {$accountDefinitions} c ON c.id = agg.acc_id
              LEFT JOIN {$accountDefinitions} p ON p.id = c.parent_id
             ORDER BY c.account_code"
        );
        $stmt->execute([
            $analytics ? 1 : 0,
            $from, $from, $periodStart, $anchor, $anchor,
            $from, $from, $periodStart, $anchor, $anchor,
            $from, ...$turnoverOpeningParams,
            $from, ...$turnoverOpeningParams,
            $from, ...$turnoverOpeningParams,
            ...$linesParams,
            $supplierId, $to,
            ...$filterParams,
            ...$closingParams,
        ]);
        return array_map(static function (array $r): array {
            $r['id'] = (int) $r['id'];
            $r['is_synthetic'] = (bool) $r['is_synthetic'];
            $r['parent_id'] = $r['parent_id'] === null ? null : (int) $r['parent_id'];
            foreach (['ps_md', 'ps_d', 'to_md', 'to_d'] as $k) {
                $r[$k] = round((float) $r[$k], 2);
            }
            // D3 (audit 2026-07): PS = NETTO počáteční zůstatek per účet. `ps_md`/`ps_d`
            // ze SQL jsou hrubé kumulativní obraty MD/D před `from`; účet s dlouhou
            // historií by jinak ukázal miliony na obou stranách místo jednoho netto PS
            // na správné straně (stejně jako accountOpening pro opis účtu). Σ delt přes
            // všechny účty zůstává 0, takže kontrola opening_balanced dál platí.
            $psDelta = round($r['ps_md'] - $r['ps_d'], 2);
            $r['ps_md'] = $psDelta > 0 ? $psDelta : 0.0;
            $r['ps_d']  = $psDelta < 0 ? -$psDelta : 0.0;
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Měsíční obraty per účet pro hlavní knihu — jen pohyby v rozsahu (PS dodá
     * trialBalanceRows).
     *
     * Vylučuje TYTÉŽ technické zápisy jako obratový sloupec v trialBalanceRows,
     * jinak Σ měsíců ≠ obrat za období: otevírací zápis z prvního dne období patří
     * do PS, ne do obratů, ale měsíční rozpad ho počítal do ledna — 221 za 2026
     * hlásilo v lednu MD 5 840 411,19 při ročním obratu 10 624 925,33, tedy o celý
     * počáteční stav 4 696 179,60 víc, než kolik součet měsíců smí dát. Nesrovnalost
     * byla vidět až po rozkliknutí měsíce na jednotlivé řádky deníku.
     *
     * @param array{vendor?:string, client?:string, item?:string} $filters viz {@see counterpartyFilter()}
     * @return list<array{account_id:int, month:string, md:float, d:float}>
     */
    public function monthlyTurnovers(int $supplierId, string $from, string $to, bool $analytics, array $filters = [], bool $excludeClosing = false, bool $excludeAllOpenings = false): array
    {
        $accountDefinitions=AccountDefinitionRepository::relation($to,$supplierId);
        [$filterSql, $filterParams] = $this->counterpartyFilter($filters, 'e');
        [$linesSql, $linesParams] = $this->lineSource($supplierId, $filters['dimension'] ?? null, $from, $to);
        $closingSql = $excludeClosing ? " AND " . JournalTaxOrigin::includedSql() : '';
        $closingParams = $excludeClosing ? [ClosingSourceId::STOCK_SLOT_BASE] : [];
        $openingSql = $excludeAllOpenings
            ? " AND e.source_type <> 'opening'"
            : " AND NOT (e.entry_date = ? AND e.source_type = 'opening')";
        $openingParams = $excludeAllOpenings ? [] : [$from];
        $stmt = $this->db->pdo()->prepare(
            ($excludeClosing ? "WITH RECURSIVE " . JournalTaxOrigin::cte($supplierId) . " " : "") . "SELECT CASE WHEN ? = 1 THEN a.id ELSE COALESCE(a.parent_id, a.id) END AS acc_id,
                    DATE_FORMAT(e.entry_date, '%Y-%m') AS ym,
                    SUM(CASE WHEN l.side = 'debit'  THEN l.signed_amount ELSE 0 END) AS md,
                    SUM(CASE WHEN l.side = 'credit' THEN l.signed_amount ELSE 0 END) AS d
               FROM {$linesSql}
               JOIN journal_entries e   ON e.id = l.entry_id
               " . ($excludeClosing ? JournalTaxOrigin::join() : "") . "
               JOIN {$accountDefinitions} a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL AND e.entry_date BETWEEN ? AND ?
                {$openingSql}{$filterSql}{$closingSql}
              GROUP BY acc_id, ym"
        );
        $stmt->execute([$analytics ? 1 : 0, ...$linesParams, $supplierId, $from, $to, ...$openingParams, ...$filterParams, ...$closingParams]);
        return array_map(static fn (array $r): array => [
            'account_id' => (int) $r['acc_id'],
            'month'      => (string) $r['ym'],
            'md'         => round((float) $r['md'], 2),
            'd'          => round((float) $r['d'], 2),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Opis účtu vč. analytik pod syntetikou, s běžícím deltou přes window funkci.
     * Window jede nad CELÝM výběrem (derivovaná tabulka), stránkuje až vnější
     * SELECT — running_delta je tedy kumulativní od začátku rozsahu i na dalších
     * stránkách; running_balance = opening + running_delta dopočítá service.
     *
     * Řádky nesou i obohacení o ZDROJOVÝ DOKLAD (source_statement_id, source_doc_number,
     * source_register_id, source_asset_id/name, source_settlement_doc_type/id) — stejná
     * sada sloupců jako {@see JournalEntryRepository::paginate()}, aby drill-down z opisu
     * účtu vedl na tentýž doklad jako z deníku (bez toho končila banka/pokladna/majetek
     * jen v deníku). JOINy jsou 1:1 přes PK, řádky se tedy neznásobují.
     *
     * Filtr protiúčtu běží nad hotovým běžícím zůstatkem, takže vyfiltrovaný řádek ukazuje
     * skutečný zůstatek účtu po tomto pohybu, ne součet jen vybraných řádků.
     *
     * `$pairs` (firma má jednoduchý zápis, EntryFormService::hasAnySingle()): řádky nesou
     * `pair_no` a filtr protiúčtu bere u dvojice jen jejího partnera. Bez něj jde přesně
     * dnešní SQL.
     *
     * @return list<array<string,mixed>>
     */
    public function accountLines(int $supplierId, int $accountId, string $from, string $to, int $limit, int $offset, bool $excludeClosing = false, ?int $counterAccountId = null, bool $pairs = false): array
    {
        [$sql, $params] = $this->accountLinesQuery($supplierId, $accountId, $from, $to, $limit, $offset, $excludeClosing, $counterAccountId, $pairs);
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(static function (array $r): array {
            $r['entry_id'] = (int) $r['entry_id'];
            $r['line_id'] = (int) $r['line_id'];
            $r['source_id'] = $r['source_id'] === null ? null : (int) $r['source_id'];
            $r['amount'] = round((float) $r['amount'], 2);
            $r['is_red_storno'] = (bool) $r['is_red_storno'];
            $r['line_no'] = (int) $r['line_no'];
            $r['running_delta'] = round((float) $r['running_delta'], 2);
            $r['line_account_id'] = (int) $r['line_account_id'];
            foreach (['source_statement_id', 'source_register_id', 'source_asset_id', 'source_settlement_doc_id'] as $k) {
                $r[$k] = $r[$k] === null ? null : (int) $r[$k];
            }
            if (array_key_exists('pair_no', $r)) {
                $r['pair_no'] = $r['pair_no'] === null ? null : (int) $r['pair_no'];
            }
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * SQL a parametry {@see accountLines()}; samostatně kvůli EXPLAIN testům plánu.
     *
     * @internal
     * @return array{0:string, 1:list<mixed>}
     */
    public function accountLinesQuery(int $supplierId, int $accountId, string $from, string $to, int $limit, int $offset, bool $excludeClosing = false, ?int $counterAccountId = null, bool $pairs = false): array
    {
        $accountDefinitions=AccountDefinitionRepository::relation($to,$supplierId);
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        [$technicalSql, $technicalParams] = $this->technicalEntryFilter($from, $excludeClosing);
        [$counterSql, $counterParams] = $this->counterAccountFilter('t.entry_id', 't.effective_side', 't.amount', $supplierId, $counterAccountId, $pairs ? 't.pair_no' : null);
        $pairColumn = $pairs ? 'l.pair_no, ' : '';
        $sql =
            ($excludeClosing ? "WITH RECURSIVE " . JournalTaxOrigin::cte($supplierId) . " " : "") . "SELECT * FROM (
                SELECT e.id AS entry_id, e.entry_date, e.document_no, e.description, e.source_type, e.source_id,
                       ca.id AS line_account_id, ca.account_code, ca.name AS line_account_name,
                       l.id AS line_id, l.side, l.amount, l.is_red_storno, l.line_no, {$pairColumn}l.currency_code, l.amount_foreign,
                       " . self::effectiveSideSql('l') . " AS effective_side,
                       bt.statement_id AS source_statement_id,
                       bt.bank_ref AS source_bank_ref,
                       cd.doc_number AS source_doc_number,
                       cd.register_id AS source_register_id,
                       ast.id AS source_asset_id,
                       ast.name AS source_asset_name,
                       stl.doc_type AS source_settlement_doc_type,
                       stl.doc_id AS source_settlement_doc_id,
                       SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END)
                         OVER (ORDER BY e.entry_date, e.id, l.line_no
                               ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS running_delta
                  FROM journal_entry_lines l
                  JOIN journal_entries e    ON e.id = l.entry_id
                  " . ($excludeClosing ? JournalTaxOrigin::join() : "") . "
                  JOIN {$accountDefinitions} ca ON ca.id = l.account_id
             LEFT JOIN bank_transactions bt ON e.source_type = 'bank' AND bt.id = e.source_id
             LEFT JOIN cash_documents cd    ON e.source_type = 'cash' AND cd.id = e.source_id
             LEFT JOIN invoice_settlements stl ON e.source_type = 'settlement'
                    AND stl.id = e.source_id AND stl.supplier_id = e.supplier_id
             LEFT JOIN depreciation_entries dep ON e.source_type = 'depreciation'
                    AND dep.id = e.source_id AND dep.supplier_id = e.supplier_id
             LEFT JOIN assets ast ON ast.supplier_id = e.supplier_id
                    AND ast.id = CASE
                        WHEN e.source_type IN ('asset', 'asset_disposal') THEN e.source_id
                        WHEN e.source_type = 'depreciation' THEN dep.asset_id
                        ELSE NULL
                    END
                 WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                   AND (l.account_id = ? OR ca.parent_id = ?)
                   AND e.entry_date BETWEEN ? AND ?{$technicalSql}
            ) t
            WHERE 1 = 1{$counterSql}
            ORDER BY t.entry_date, t.entry_id, t.line_no
            LIMIT {$limit} OFFSET {$offset}";
        return [$sql, [$supplierId, $accountId, $accountId, $from, $to, ...$technicalParams, ...$counterParams]];
    }

    /**
     * Vyloučení TECHNICKÝCH zápisů z pohybů opisu účtu.
     *
     * Otevírací zápis je datovaný na první den období a `accountOpening()` ho
     * záměrně počítá do PS. Dokud ho pohyby zároveň nevyloučily, byl započítaný
     * DVAKRÁT — pokladní kniha za 2026 ukazovala konečný zůstatek 28 464 Kč
     * místo 14 232 Kč, přesný dvojnásobek, a opis 221 se mýlil o 4,1 mil.
     * Předvaha tutéž opravu má od začátku (viz trialBalanceRows), do opisu účtu
     * se nikdy neprotáhla.
     *
     * Ve výchozím („reálný stav peněz") pohledu padají VŠECHNY otevírací zápisy,
     * ne jen ten na počátečním datu. Okno přes přelom roku totiž obsahuje pár
     * uzavření+otevření: ten se sice v součtu vyruší, ale zůstatek mezitím
     * spadne na nulu a vyskočí zpátky, obraty se nafouknou o převáděnou částku
     * a banner „záporný zůstatek" hlásí poplach. Protože uzávěrkový zápis už
     * vylučujeme, musí jít jeho protějšek pryč taky — jinak vznikne asymetrie
     * a konečný zůstatek přeroste o převod (28 464 místo 14 232).
     *
     * Výjimka u uzávěrky je stejná jako jinde: slotované skladové zápisy §3.4
     * (source_id >= STOCK_SLOT_BASE) jsou reálné pohyby a zůstávají.
     *
     * S `after_closing=1` si uživatel technické zápisy vyžádal — pak se
     * vylučuje jen otevírací zápis datovaný na `from`, který je v PS vždycky.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function technicalEntryFilter(string $from, bool $excludeClosing): array
    {
        if (!$excludeClosing) {
            return [" AND NOT (e.entry_date = ? AND e.source_type = 'opening')", [$from]];
        }
        return [
            " AND e.source_type <> 'opening'"
            . " AND " . JournalTaxOrigin::includedSql(),
            [ClosingSourceId::STOCK_SLOT_BASE],
        ];
    }

    /**
     * PS účtu (vč. analytik pod syntetikou) k datu `from` — signed delta (kladné = MD),
     * okno dle R6 (výsledkové účty jen od začátku období `periodStart`).
     */
    public function accountOpening(int $supplierId, int $accountId, string $from, string $periodStart, bool $excludeClosing = false): float
    {
        $accountDefinitions=AccountDefinitionRepository::relation($from,$supplierId);
        $anchor = $this->openingAnchor($supplierId, $from);
        $closingSql = $excludeClosing ? " AND " . JournalTaxOrigin::includedSql() : '';
        $stmt = $this->db->pdo()->prepare(
            ($excludeClosing ? "WITH RECURSIVE " . JournalTaxOrigin::cte($supplierId) . " " : "") . "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e    ON e.id = l.entry_id
               " . ($excludeClosing ? JournalTaxOrigin::join() : "") . "
               JOIN {$accountDefinitions} ca ON ca.id = l.account_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND (l.account_id = ? OR ca.parent_id = ?)
                -- Otevírací zápis z prvního dne období patří do počátečního stavu,
                -- ne až mezi pohyby — viz trialBalanceRows().
                AND (e.entry_date < ? OR (e.entry_date = ? AND e.source_type = 'opening'))
                AND (ca.account_type NOT IN ('revenue','expense') OR e.entry_date >= ?)
                AND (ca.account_type NOT IN ('asset','liability','equity') OR ? IS NULL OR e.entry_date >= ?){$closingSql}"
        );
        $params = [$supplierId, $accountId, $accountId, $from, $from, $periodStart, $anchor, $anchor];
        if ($excludeClosing) {
            $params[] = ClosingSourceId::STOCK_SLOT_BASE;
        }
        $stmt->execute($params);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Celkový počet řádků opisu účtu v rozsahu (pro paginaci).
     */
    public function accountLinesTotal(int $supplierId, int $accountId, string $from, string $to, bool $excludeClosing = false, ?int $counterAccountId = null, bool $pairs = false): int
    {
        [$sql, $params] = $this->accountLinesTotalQuery($supplierId, $accountId, $from, $to, $excludeClosing, $counterAccountId, $pairs);
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * SQL a parametry {@see accountLinesTotal()}; samostatně kvůli EXPLAIN testům plánu.
     *
     * @internal
     * @return array{0:string, 1:list<mixed>}
     */
    public function accountLinesTotalQuery(int $supplierId, int $accountId, string $from, string $to, bool $excludeClosing = false, ?int $counterAccountId = null, bool $pairs = false): array
    {
        $accountDefinitions=AccountDefinitionRepository::relation($to,$supplierId);
        [$technicalSql, $technicalParams] = $this->technicalEntryFilter($from, $excludeClosing);
        [$counterSql, $counterParams] = $this->counterAccountFilter('l.entry_id', self::effectiveSideSql('l'), 'l.amount', $supplierId, $counterAccountId, $pairs ? 'l.pair_no' : null);
        $sql =
            ($excludeClosing ? "WITH RECURSIVE " . JournalTaxOrigin::cte($supplierId) . " " : "") . "SELECT COUNT(*)
               FROM journal_entry_lines l
               JOIN journal_entries e    ON e.id = l.entry_id
               " . ($excludeClosing ? JournalTaxOrigin::join() : "") . "
               JOIN {$accountDefinitions} ca ON ca.id = l.account_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND (l.account_id = ? OR ca.parent_id = ?)
                AND e.entry_date BETWEEN ? AND ?{$technicalSql}{$counterSql}";
        return [$sql, [$supplierId, $accountId, $accountId, $from, $to, ...$technicalParams, ...$counterParams]];
    }

    /**
     * Součty MD a D opisu účtu rozdělené podle protiúčtu, přes stejnou množinu řádků jako
     * {@see accountTurnovers()}.
     *
     * Protiúčet je účet na opačné ÚČINNÉ straně téhož zápisu (červené storno stranu
     * obrací, viz {@see effectiveSideSql()}). Řádky se shodnou částkou tvoří dvojici, když
     * jich je na obou stranách stejně (pokladní doklad 501 / 211 a 513 / 211): spárovaný
     * řádek se dělí rovným dílem jen mezi řádky své dvojice.
     * Ostatní řádky se dělí poměrem jen mezi nespárované řádky protistrany, takže 311 MD
     * 1 210 má protiúčty 602 za 1 000 a 343 za 210 i vedle dvojice v témže zápisu.
     * Řádek bez protistrany (jednostranný zápis) má protiúčet NULL.
     *
     * Jednoduchý zápis (`$pairs`, firma má EntryFormService::hasAnySingle()): řádek s `pair_no`
     * má za protiúčet jen druhý řádek své dvojice, celou částkou. Dvojice je místo částky
     * klíčem skupiny (`entry_id, pair_no`), takže je vždy spárovaná a do poměrného dělení
     * nevstupuje; řádky bez `pair_no` se párují a dělí dnešním pravidlem jen mezi sebou.
     * U starších zápisů 1 : N dává poměrné dělení přesně díly {@see \MyInvoice\Service\Accounting\Pair\JournalPairer}.
     * Bez `$pairs` jde přesně dnešní SQL.
     *
     * Stejné pravidlo žije třikrát (výkon nedovoluje jednu implementaci): tady v SQL,
     * ve filtru {@see counterAccountFilter()} a v PHP v {@see \MyInvoice\Service\Accounting\Reports\JournalLineContext}.
     * Při změně upravte všechna tři místa, jinak se rozejde protiúčet řádku, filtr a součty
     * (hlídá CounterAccountConsistencyTest).
     *
     * @return list<array{account_id:?int, account_code:?string, name:?string, parent_id:?int, md:float, d:float}>
     */
    public function accountCounterTotals(int $supplierId, int $accountId, string $from, string $to, bool $excludeClosing = false, bool $pairs = false): array
    {
        [$sql, $params] = $this->accountCounterTotalsQuery($supplierId, $accountId, $from, $to, $excludeClosing, $pairs);
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(static fn (array $r): array => [
            'account_id'   => $r['account_id'] === null ? null : (int) $r['account_id'],
            'account_code' => $r['account_code'] === null ? null : (string) $r['account_code'],
            'name'         => $r['name'] === null ? null : (string) $r['name'],
            'parent_id'    => $r['parent_id'] === null ? null : (int) $r['parent_id'],
            'md'           => (float) $r['md'],
            'd'            => (float) $r['d'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * SQL a parametry {@see accountCounterTotals()}; samostatně kvůli EXPLAIN testům plánu.
     *
     * @internal
     * @return array{0:string, 1:list<mixed>}
     */
    public function accountCounterTotalsQuery(int $supplierId, int $accountId, string $from, string $to, bool $excludeClosing = false, bool $pairs = false): array
    {
        $accountDefinitions = AccountDefinitionRepository::relation($to, $supplierId);
        // Klíč skupiny spárovaných řádků: dnes částka v zápisu, u jednoduchého zápisu dvojice
        // (řádky bez dvojice dál podle částky, ale jen mezi sebou).
        $groupInner = $pairs ? 'o.entry_id, o.pair_no, CASE WHEN o.pair_no IS NULL THEN o.amount END' : 'o.entry_id, o.amount';
        $groupOuter = $pairs ? 'x.entry_id, x.pair_no, x.pair_amount' : 'x.entry_id, x.amount';
        $pairColumns = $pairs ? 'o.pair_no, CASE WHEN o.pair_no IS NULL THEN o.amount END AS pair_amount, ' : '';
        [$technicalSql, $technicalParams] = $this->technicalEntryFilter($from, $excludeClosing);
        // Jeden průchod řádky zápisů účtu, bez spojení řádku s protistranou: každý řádek zápisu
        // dostane okny součty vlastních řádků (podle účinné a zapsané strany) a z nich svůj díl.
        // CTE odkazované dvakrát MariaDB materializuje dvakrát, proto se na `counterpart`
        // odkazuje jen jednou. `paired` = částka se stejným počtem řádků na obou stranách,
        // `free_total` = nespárovaná částka strany, `own_*` = vlastní řádky (u = nespárované
        // za zápis, p = spárované za částku; d/c účinná strana, md/d strana výsledku).
        $ownSum = static fn (string $paired, string $eff, string $side, string $partition): string =>
            "SUM(CASE WHEN x.is_own AND " . ($paired === 'p' ? '' : 'NOT ') . "x.paired AND x.effective_side = '{$eff}' AND x.side = '{$side}'"
            . " THEN x.signed_amount END) OVER (PARTITION BY {$partition})";
        $own = [];
        foreach (['u' => 'x.entry_id', 'p' => $groupOuter] as $kind => $partition) {
            foreach (['d' => 'debit', 'c' => 'credit'] as $e => $eff) {
                foreach (['md' => 'debit', 'd' => 'credit'] as $res => $side) {
                    $own[] = $ownSum($kind, $eff, $side, $partition) . " AS own_{$kind}{$e}_{$res}";
                }
            }
        }
        $freeMd = "(CASE WHEN c.effective_side = 'debit' THEN c.own_uc_md ELSE c.own_ud_md END)";
        $freeD = "(CASE WHEN c.effective_side = 'debit' THEN c.own_uc_d ELSE c.own_ud_d END)";
        // Podíl nespárovaného řádku na své straně; násobit před dělením (decimal drží jen 4 místa
        // navíc). free_total > 0: nespárovaný řádek se do něj počítá a částka je kladná
        // (chk_jel_amount_positive).
        $freeShare = static fn (string $sum): string => "({$sum} * c.amount / c.free_total)";
        $sql =
            ($excludeClosing ? "WITH RECURSIVE " . JournalTaxOrigin::cte($supplierId) . ", " : "WITH ") . "own_accounts AS (
                SELECT ca.id FROM {$accountDefinitions} ca WHERE ca.id = ? OR ca.parent_id = ?
            ),
            entries AS (
                SELECT DISTINCT l.entry_id
                  FROM journal_entry_lines l
                  JOIN journal_entries e ON e.id = l.entry_id
                  " . ($excludeClosing ? JournalTaxOrigin::join() : "") . "
                 WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                   AND l.account_id IN (SELECT id FROM own_accounts)
                   AND e.entry_date BETWEEN ? AND ?{$technicalSql}
            ),
            -- Pořadí a index pevně: řádky hledáme jen pro zápisy účtu. Plánovač jinak
            -- staví rowid filtr nad celou firmou a na tisíci řádcích je to desetkrát pomalejší.
            counterpart AS (
                SELECT x.*,
                       SUM(CASE WHEN x.paired THEN 0 ELSE x.amount END) OVER (PARTITION BY x.entry_id, x.effective_side) AS free_total,
                       SUM(NOT x.paired AND x.effective_side = 'debit') OVER (PARTITION BY x.entry_id) AS free_d,
                       SUM(NOT x.paired AND x.effective_side = 'credit') OVER (PARTITION BY x.entry_id) AS free_c,
                       " . implode(",
                       ", $own) . "
                  FROM (SELECT o.entry_id, o.account_id, o.amount, o.side, o.signed_amount, {$pairColumns}" . self::effectiveSideSql('o') . " AS effective_side,
                               o.account_id IN (SELECT id FROM own_accounts) AS is_own,
                               SUM(" . self::effectiveSideSql('o') . " = 'debit') OVER (PARTITION BY {$groupInner}) AS n_debit,
                               SUM(" . self::effectiveSideSql('o') . " = 'debit') OVER (PARTITION BY {$groupInner})
                                 = SUM(" . self::effectiveSideSql('o') . " = 'credit') OVER (PARTITION BY {$groupInner}) AS paired
                          FROM entries e
                          STRAIGHT_JOIN journal_entry_lines o FORCE INDEX (idx_jel_supplier_entry)
                               ON o.supplier_id = ? AND o.entry_id = e.entry_id) x
            ),
            -- Díl řádku jako protiúčtu vlastních řádků opačné strany; k = 1 je vlastní
            -- nespárovaný řádek bez nespárované protistrany (protiúčet NULL).
            shares AS (
                SELECT CASE WHEN k.k = 0 THEN c.account_id END AS account_id,
                       CASE WHEN k.k = 1 THEN (CASE WHEN c.side = 'debit' THEN c.signed_amount ELSE 0 END)
                            WHEN c.paired THEN (CASE WHEN c.effective_side = 'debit' THEN c.own_pc_md ELSE c.own_pd_md END) / c.n_debit
                            ELSE " . $freeShare($freeMd) . " END AS md,
                       CASE WHEN k.k = 1 THEN (CASE WHEN c.side = 'credit' THEN c.signed_amount ELSE 0 END)
                            WHEN c.paired THEN (CASE WHEN c.effective_side = 'debit' THEN c.own_pc_d ELSE c.own_pd_d END) / c.n_debit
                            ELSE " . $freeShare($freeD) . " END AS d
                  FROM counterpart c
                  JOIN (SELECT 0 AS k UNION ALL SELECT 1) k
                       ON k.k = 0 OR (c.is_own AND NOT c.paired AND (CASE WHEN c.effective_side = 'debit' THEN c.free_c ELSE c.free_d END) = 0)
            ),
            allocated AS (
                SELECT account_id, SUM(md) AS md, SUM(d) AS d FROM shares
                 WHERE md IS NOT NULL OR d IS NOT NULL
                 GROUP BY account_id
            )
            SELECT x.account_id, a.account_code, a.name, a.parent_id, x.md, x.d
              FROM allocated x
         LEFT JOIN {$accountDefinitions} a ON a.id = x.account_id
             ORDER BY a.account_code IS NULL, a.account_code";
        return [$sql, [$accountId, $accountId, $supplierId, $from, $to, ...$technicalParams, $supplierId]];
    }

    /**
     * Omezení řádků opisu na ty, jejichž zápis má na opačné účinné straně daný účet
     * (nebo jeho analytiku). Spárovaný řádek (viz {@see accountCounterTotals()}) jen
     * v rámci své dvojice, nespárovaný jen mezi nespárovanými, stejně jako součty
     * a zobrazený protiúčet. Prázdný filtr nepřidá nic.
     *
     * Shodná částka je vždy ve stejné skupině (dvojice je vlastnost částky v zápisu), takže
     * spárovanost se zjišťuje jen u řádků s jinou částkou; běžný zápis 311 / 602 ji nečte.
     *
     * Skalární poddotaz místo EXISTS záměrně: EXISTS plánovač převede na semi-join řízený
     * řádky protiúčtu, u účtu s tisíci pohyby pak dotaz běžel přes sekundu místo milisekund.
     *
     * Jednoduchý zápis (`$pairNoExpr`): řádek s `pair_no` vyhoví jen partnerem své dvojice
     * (jeden lookup po idx_jel_supplier_entry_pair), řádek bez `pair_no` dnešním pravidlem
     * mezi řádky bez dvojice. Bez `$pairNoExpr` jde přesně dnešní SQL.
     *
     * @return array{0:string, 1:list<int>}
     */
    private function counterAccountFilter(string $entryExpr, string $sideExpr, string $amountExpr, int $supplierId, ?int $counterAccountId, ?string $pairNoExpr = null): array
    {
        if ($counterAccountId === null) {
            return ['', []];
        }
        if ($pairNoExpr === null) {
            return [
                " AND (SELECT 1 FROM journal_entry_lines cl
                     JOIN chart_of_accounts cc ON cc.id = cl.account_id
                    WHERE cl.supplier_id = ? AND cl.entry_id = {$entryExpr}
                      AND (cl.account_id = ? OR cc.parent_id = ?)
                      AND " . self::effectiveSideSql('cl') . " <> {$sideExpr}
                      AND (cl.amount = {$amountExpr}
                           OR (NOT " . self::pairedSql($entryExpr, $amountExpr) . "
                               AND NOT " . self::pairedSql('cl.entry_id', 'cl.amount') . "))
                    LIMIT 1) = 1",
                [$supplierId, $counterAccountId, $counterAccountId, $supplierId, $supplierId],
            ];
        }
        return [
            " AND (CASE WHEN {$pairNoExpr} IS NOT NULL
                   THEN (SELECT 1 FROM journal_entry_lines cl
                           JOIN chart_of_accounts cc ON cc.id = cl.account_id
                          WHERE cl.supplier_id = ? AND cl.entry_id = {$entryExpr} AND cl.pair_no = {$pairNoExpr}
                            AND (cl.account_id = ? OR cc.parent_id = ?)
                            AND " . self::effectiveSideSql('cl') . " <> {$sideExpr}
                          LIMIT 1)
                   ELSE (SELECT 1 FROM journal_entry_lines cl
                           JOIN chart_of_accounts cc ON cc.id = cl.account_id
                          WHERE cl.supplier_id = ? AND cl.entry_id = {$entryExpr} AND cl.pair_no IS NULL
                            AND (cl.account_id = ? OR cc.parent_id = ?)
                            AND " . self::effectiveSideSql('cl') . " <> {$sideExpr}
                            AND (cl.amount = {$amountExpr}
                                 OR (NOT " . self::pairedSql($entryExpr, $amountExpr, true) . "
                                     AND NOT " . self::pairedSql('cl.entry_id', 'cl.amount', true) . "))
                          LIMIT 1)
                   END) = 1",
            [
                $supplierId, $counterAccountId, $counterAccountId,
                $supplierId, $counterAccountId, $counterAccountId, $supplierId, $supplierId,
            ],
        ];
    }

    /**
     * Tvoří řádky dané částky v zápisu dvojici? Pravidlo viz {@see accountCounterTotals()}.
     * `$unpairedOnly`: jen mezi řádky bez `pair_no` (jednoduchý zápis).
     */
    private static function pairedSql(string $entryExpr, string $amountExpr, bool $unpairedOnly = false): string
    {
        return "(SELECT SUM(" . self::effectiveSideSql('pg') . " = 'debit') = SUM(" . self::effectiveSideSql('pg') . " = 'credit')
                   FROM journal_entry_lines pg
                  WHERE pg.supplier_id = ? AND pg.entry_id = {$entryExpr} AND pg.amount = {$amountExpr}" . ($unpairedOnly ? ' AND pg.pair_no IS NULL' : '') . ")";
    }

    /** Strana, na které řádek skutečně působí: červené storno MD je účinkem Dal a naopak. */
    private static function effectiveSideSql(string $alias): string
    {
        return "CASE WHEN {$alias}.is_red_storno = 1
                     THEN (CASE WHEN {$alias}.side = 'debit' THEN 'credit' ELSE 'debit' END)
                     ELSE {$alias}.side END";
    }

    /**
     * Obraty MD/D účtu (vč. analytik pod syntetikou) za celý rozsah.
     *
     * @return array{md: float, d: float}
     */
    public function accountTurnovers(int $supplierId, int $accountId, string $from, string $to, bool $excludeClosing = false): array
    {
        $accountDefinitions=AccountDefinitionRepository::relation($to,$supplierId);
        [$technicalSql, $technicalParams] = $this->technicalEntryFilter($from, $excludeClosing);
        $stmt = $this->db->pdo()->prepare(
            ($excludeClosing ? "WITH RECURSIVE " . JournalTaxOrigin::cte($supplierId) . " " : "") . "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit'  THEN l.signed_amount ELSE 0 END), 0) AS md,
                    COALESCE(SUM(CASE WHEN l.side = 'credit' THEN l.signed_amount ELSE 0 END), 0) AS d
               FROM journal_entry_lines l
               JOIN journal_entries e    ON e.id = l.entry_id
               " . ($excludeClosing ? JournalTaxOrigin::join() : "") . "
               JOIN {$accountDefinitions} ca ON ca.id = l.account_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND (l.account_id = ? OR ca.parent_id = ?)
                AND e.entry_date BETWEEN ? AND ?{$technicalSql}"
        );
        $stmt->execute([$supplierId, $accountId, $accountId, $from, $to, ...$technicalParams]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'md' => round((float) ($row['md'] ?? 0), 2),
            'd'  => round((float) ($row['d'] ?? 0), 2),
        ];
    }

    /**
     * Kontrolní obrat deníku v rozsahu (bez JOIN na osnovu).
     *
     * Musí počítat PŘESNĚ tu množinu řádků, kterou {@see trialBalanceRows()} sčítá
     * do sloupců OBRAT — jinak je kontrola „obrat předvahy = obrat deníku" nesplnitelná:
     *   • otevírací zápis (`opening`, datovaný na první den období) patří do PS, ne do obratů,
     *   • uzávěrkový zápis (`closing` mimo slotované skladové zápisy §3.4) se v pohledu
     *     PŘED uzavřením knih z obratů vynechává taky.
     * Bez téhle symetrie hlásila předvaha ✗ u každého roku, který má počáteční stav
     * nebo zaúčtovanou uzávěrku — tedy u všech kromě prvního roku po uzávěrce.
     *
     * @return array{md: float, d: float}
     */
    public function journalTotals(int $supplierId, string $from, string $to, bool $excludeClosing = false, ?DimensionFilter $dimension = null): array
    {
        $closingSql = $excludeClosing ? " AND " . JournalTaxOrigin::includedSql() : '';
        $closingParams = $excludeClosing ? [ClosingSourceId::STOCK_SLOT_BASE] : [];
        [$linesSql, $linesParams] = $this->lineSource($supplierId, $dimension, $from, $to);
        $stmt = $this->db->pdo()->prepare(
            ($excludeClosing ? "WITH RECURSIVE " . JournalTaxOrigin::cte($supplierId) . " " : "") . "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit'  THEN l.signed_amount ELSE 0 END), 0) AS md,
                    COALESCE(SUM(CASE WHEN l.side = 'credit' THEN l.signed_amount ELSE 0 END), 0) AS d
               FROM {$linesSql}
               JOIN journal_entries e ON e.id = l.entry_id
              " . ($excludeClosing ? JournalTaxOrigin::join() : "") . "
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL AND e.entry_date BETWEEN ? AND ?
                AND NOT (e.entry_date = ? AND e.source_type = 'opening'){$closingSql}"
        );
        $stmt->execute([...$linesParams, $supplierId, $from, $to, $from, ...$closingParams]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'md' => round((float) ($row['md'] ?? 0), 2),
            'd'  => round((float) ($row['d'] ?? 0), 2),
        ];
    }

    /**
     * Počet draft zápisů (posted_at IS NULL) v rozsahu — informativní varování (R1).
     */
    public function draftCount(int $supplierId, string $from, string $to): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM journal_entries
              WHERE supplier_id = ? AND posted_at IS NULL AND entry_date BETWEEN ? AND ?'
        );
        $stmt->execute([$supplierId, $from, $to]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Zůstatky per SYNTETIKA pro výkazy: kumulativně k `asOf`; u revenue/expense
     * jen pohyby od `plFrom` (start fiskálního roku). Vylučuje offbalance účty a
     * JEN close_books zápis (source_id < STOCK_SLOT_BASE, R2); slotované skladové
     * zápisy §3.4 (112/132/501/504, source_id >= STOCK_SLOT_BASE) se do výkazů počítají.
     * Analytiky rolované na syntetiku (R15).
     *
     * D2 (audit 2026-07, H9): pro syntetiky uvedené v `$splitCodes` (účty se saldovou
     * podmínkou v mapě výkazu — 221, 343, 341…) se saldo počítá PER ANALYTICKÝ ÚČET a
     * teprve výsledky se agregují ZVLÁŠŤ na dvě čísla za syntetiku: Σ kladných sald →
     * debetní strana, Σ záporných sald → kreditní strana. Tím se nekompenzuje kontokorent
     * (221 −) s běžným účtem (221 +) přes syntetiku (§58 vyhl. 500/2002 Sb.). Pro účty mimo
     * `$splitCodes` (balance_condition='any') zůstává chování beze změny (jedno netto číslo).
     *
     * `$onlyCodes` omezí výsledek na vyjmenované syntetiky (saldokonto čte šest účtů
     * z několika stovek). Vynechané účty se vůbec nečtou, zůstatky vyjmenovaných jsou
     * stejné jako bez filtru.
     *
     * `$extraDeltas` (účet => MD − D) přičte k zůstatkům listových účtů úpravu mimo deník
     * (průběžné časové rozlišení ve výkazu) ještě před roll-upem, aby úprava prošla stejnou
     * syntetikou, analytikou i rozdělením salda jako zápisy deníku.
     *
     * @param list<string> $splitCodes syntetické prefixy k rozdělení per analytika
     * @param list<string>|null $onlyCodes jen tyto syntetiky; null = všechny
     * @param array<string,float> $extraDeltas listový účet => změna zůstatku MD − D
     * @return list<array{account_id:int, code:string, name:string, account_type:string, md:float, d:float}>
     */
    public function syntheticBalances(
        int $supplierId,
        string $asOf,
        ?string $plFrom,
        array $splitCodes = [],
        array $preserveCodes = [],
        ?DimensionFilter $dimension = null,
        ?array $onlyCodes = null,
        array $extraDeltas = [],
    ): array
    {
        $accountDefinitions=AccountDefinitionRepository::relation($asOf,$supplierId);
        $anchor = $this->openingAnchor($supplierId, $asOf);
        $plCond = '';
        $lowerBound = $anchor !== null && $plFrom !== null ? min($anchor, $plFrom) : null;
        [$linesSql, $linesParams] = $this->lineSource($supplierId, $dimension, $lowerBound, $asOf);
        $params = [...$linesParams, $supplierId, ClosingSourceId::STOCK_SLOT_BASE, $asOf];
        if ($plFrom !== null) {
            $plCond = " AND (a.account_type NOT IN ('revenue','expense') OR e.entry_date >= ?)";
            $params[] = $plFrom;
        }
        $params[] = $anchor;
        $params[] = $anchor;
        // Spodní mez data je čistě výkonová: rozvahové účty (asset/liability/equity) už
        // omezuje kotva, výsledkové plFrom a offbalance/closing se vylučují úplně, takže
        // zápis starší než obě meze do součtu nevstoupí nikdy. Bez ní MariaDB prošla
        // všechny řádky deníku firmy za celou historii; s indexem (supplier_id, entry_date)
        // čte jen dotčené období. Bez kotvy nebo bez plFrom mez neexistuje.
        $lowerBoundSql = '';
        if ($lowerBound !== null) {
            $lowerBoundSql = ' AND e.entry_date >= ?';
            $params[] = $lowerBound;
        }
        $onlyCodesSql = '';
        if ($onlyCodes !== null) {
            $onlyCodes = array_values(array_unique(array_map('strval', $onlyCodes)));
            if ($onlyCodes === []) {
                return [];
            }
            $onlyCodesSql = ' AND COALESCE(p.account_code, a.account_code) IN (' . implode(',', array_fill(0, count($onlyCodes), '?')) . ')';
            array_push($params, ...$onlyCodes);
        }
        // Agregace per LIST účet (a.id) + jeho syntetika — roll-up i případný D2 split
        // (kladné/záporné saldo per analytika) se dopočítá v PHP.
        $stmt = $this->db->pdo()->prepare(
            "WITH RECURSIVE " . JournalTaxOrigin::cte($supplierId) . " SELECT COALESCE(p.id, a.id) AS account_id,
                    COALESCE(p.account_code, a.account_code) AS code,
                    COALESCE(p.name, a.name) AS name,
                    a.account_type,
                    a.id AS leaf_id,
                    a.account_code AS leaf_code,
                    a.name AS leaf_name,
                    SUM(CASE WHEN l.side = 'debit'  THEN l.signed_amount ELSE 0 END) AS md,
                    SUM(CASE WHEN l.side = 'credit' THEN l.signed_amount ELSE 0 END) AS d
               FROM {$linesSql}
               JOIN journal_entries e   ON e.id = l.entry_id
               " . JournalTaxOrigin::join() . "
               JOIN {$accountDefinitions} a ON a.id = l.account_id
               LEFT JOIN {$accountDefinitions} p ON p.id = a.parent_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND a.account_type NOT IN ('offbalance','closing')
                AND " . JournalTaxOrigin::includedSql() . "
                AND e.entry_date <= ?{$plCond}
                AND (a.account_type NOT IN ('asset','liability','equity') OR ? IS NULL OR e.entry_date >= ?){$lowerBoundSql}{$onlyCodesSql}
              GROUP BY COALESCE(p.id, a.id), COALESCE(p.account_code, a.account_code),
                       COALESCE(p.name, a.name), a.account_type, a.id
              ORDER BY code"
        );
        $stmt->execute($params);
        $leafRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($extraDeltas !== []) {
            $leafRows = $this->applyLeafDeltas($supplierId, $asOf, $leafRows, $extraDeltas);
        }
        return $this->rollupSyntheticBalances($leafRows, $splitCodes, $preserveCodes);
    }

    /**
     * Přičte změny zůstatků (účet => MD − D) k listovým řádkům zůstatků. Účet bez pohybu
     * v deníku se doplní z osnovy platné k `asOf` se svou syntetikou. Účet, který osnova
     * nezná, je chyba: úprava by z výkazu tiše vypadla a rozvaha by nebilancovala.
     *
     * @param list<array<string,mixed>> $leafRows
     * @param array<string,float> $deltas
     * @return list<array<string,mixed>>
     */
    private function applyLeafDeltas(int $supplierId, string $asOf, array $leafRows, array $deltas): array
    {
        $byCode = [];
        foreach ($leafRows as $i => $r) {
            $byCode[(string) $r['leaf_code']] = $i;
        }
        $missing = array_values(array_filter(array_map('strval', array_keys($deltas)), static fn (string $c): bool => !isset($byCode[$c])));
        if ($missing !== []) {
            $accountDefinitions = AccountDefinitionRepository::relation($asOf, $supplierId);
            $stmt = $this->db->pdo()->prepare(
                "SELECT COALESCE(p.id, a.id) AS account_id,
                        COALESCE(p.account_code, a.account_code) AS code,
                        COALESCE(p.name, a.name) AS name,
                        a.account_type,
                        a.id AS leaf_id,
                        a.account_code AS leaf_code,
                        a.name AS leaf_name
                   FROM {$accountDefinitions} a
                   LEFT JOIN {$accountDefinitions} p ON p.id = a.parent_id
                  WHERE a.supplier_id = ? AND a.account_code IN (" . implode(',', array_fill(0, count($missing), '?')) . ')'
            );
            $stmt->execute([$supplierId, ...$missing]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $byCode[(string) $r['leaf_code']] = count($leafRows);
                $leafRows[] = $r + ['md' => 0.0, 'd' => 0.0];
            }
        }
        foreach ($deltas as $code => $delta) {
            $i = $byCode[(string) $code] ?? null;
            if ($i === null) {
                throw new \MyInvoice\Service\Accounting\Reports\ReportException(
                    'accrual_account_missing',
                    'Účet ' . $code . ' z průběžného časového rozlišení není v účtové osnově firmy.',
                );
            }
            $delta = round((float) $delta, 2);
            if ($delta > 0) {
                $leafRows[$i]['md'] = round((float) $leafRows[$i]['md'] + $delta, 2);
            } else {
                $leafRows[$i]['d'] = round((float) $leafRows[$i]['d'] - $delta, 2);
            }
        }
        return $leafRows;
    }

    /**
     * Roll-up per-list zůstatků na syntetiku. Účty mimo `$splitCodes` se sečtou (jedno
     * netto číslo per syntetika, beze změny chování). Účty v `$splitCodes` (zákaz
     * kompenzace §58, D2) se rozdělí: Σ kladných analytických sald → debetní strana,
     * Σ záporných → kreditní strana (dvě čísla za syntetiku).
     *
     * @param list<array<string,mixed>> $leafRows
     * @param list<string> $splitCodes
     * @return list<array{account_id:int, code:string, name:string, account_type:string, md:float, d:float}>
     */
    private function rollupSyntheticBalances(array $leafRows, array $splitCodes, array $preserveCodes = []): array
    {
        $plain = [];
        $split = [];
        foreach ($leafRows as $r) {
            $leafCode = (string) ($r['leaf_code'] ?? $r['code']);
            $preserve = $this->matchesSplitCode($leafCode, $preserveCodes);
            $code = $preserve ? $leafCode : (string) $r['code'];
            if ($preserve) {
                $r['account_id'] = (int) $r['leaf_id'];
                $r['name'] = (string) $r['leaf_name'];
            }
            $md = (float) $r['md'];
            $d  = (float) $r['d'];
            if ($this->matchesSplitCode($code, $splitCodes)) {
                if (!isset($split[$code])) {
                    $split[$code] = [
                        'account_id'   => (int) $r['account_id'],
                        'name'         => (string) $r['name'],
                        'account_type' => (string) $r['account_type'],
                        'pos'          => 0.0,
                        'neg'          => 0.0,
                    ];
                }
                $delta = round($md - $d, 2); // saldo per analytický (list) účet
                if ($delta > 0) {
                    $split[$code]['pos'] = round($split[$code]['pos'] + $delta, 2);
                } elseif ($delta < 0) {
                    $split[$code]['neg'] = round($split[$code]['neg'] - $delta, 2);
                }
            } else {
                if (!isset($plain[$code])) {
                    $plain[$code] = [
                        'account_id'   => (int) $r['account_id'],
                        'name'         => (string) $r['name'],
                        'account_type' => (string) $r['account_type'],
                        'md'           => 0.0,
                        'd'            => 0.0,
                    ];
                }
                $plain[$code]['md'] = round($plain[$code]['md'] + $md, 2);
                $plain[$code]['d']  = round($plain[$code]['d'] + $d, 2);
            }
        }

        $out = [];
        foreach ($plain as $code => $v) {
            $out[] = [
                'account_id'   => $v['account_id'],
                'code'         => (string) $code,
                'name'         => $v['name'],
                'account_type' => $v['account_type'],
                'md'           => $v['md'],
                'd'            => $v['d'],
            ];
        }
        foreach ($split as $code => $v) {
            // Kladné saldo → debetní strana (md), záporné → kreditní strana (d).
            // Nulovou stranu vynecháme; při obou nulových se syntetika neobjeví (0 zůstatek).
            if ((int) round($v['pos'] * 100) !== 0) {
                $out[] = [
                    'account_id'   => $v['account_id'],
                    'code'         => (string) $code,
                    'name'         => $v['name'],
                    'account_type' => $v['account_type'],
                    'md'           => $v['pos'],
                    'd'            => 0.0,
                ];
            }
            if ((int) round($v['neg'] * 100) !== 0) {
                $out[] = [
                    'account_id'   => $v['account_id'],
                    'code'         => (string) $code,
                    'name'         => $v['name'],
                    'account_type' => $v['account_type'],
                    'md'           => 0.0,
                    'd'            => $v['neg'],
                ];
            }
        }
        usort($out, static fn (array $a, array $b): int => strcmp((string) $a['code'], (string) $b['code']));
        return $out;
    }

    /**
     * @param list<string> $splitCodes
     */
    private function matchesSplitCode(string $syntheticCode, array $splitCodes): bool
    {
        foreach ($splitCodes as $prefix) {
            // account_prefix může přijít jako číselný string; array_keys() ho zkoerceuje
            // na int (PHP číselné klíče) → přetypuj zpět, ať str_starts_with nespadne.
            $prefix = (string) $prefix;
            if ($prefix !== '' && str_starts_with($syntheticCode, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Čistý obrat pro kategorizaci ÚJ (R9): Σ(credit − debit) účtů daných
     * syntetických kódů za rozsah, analytiky rolované na syntetiku.
     *
     * @param list<string> $codes
     */
    public function netTurnoverForCodes(int $supplierId, string $from, string $to, array $codes): float
    {
        $accountDefinitions=AccountDefinitionRepository::relation($to,$supplierId);
        if ($codes === []) {
            return 0.0;
        }
        // Prefix match shodně s mapou výkazů — chytí i osiřelou analytiku
        // (parent_id NULL po smazání syntetiky), kterou by exaktní IN minul.
        $like = implode(' OR ', array_fill(0, count($codes), "COALESCE(p.account_code, a.account_code) LIKE CONCAT(?, '%')"));
        // close_books zápis (source_type='closing', source_id = period_id < STOCK_SLOT_BASE)
        // převádí výnosy na 710/702 — musí být vyloučen, jinak čistý obrat uzavřeného období
        // vyjde 0 (stejné vyloučení jako syntheticBalances). Slotované skladové zápisy §3.4
        // (source_id >= STOCK_SLOT_BASE, 501/504/648) do obratu PATŘÍ a počítají se. Chrání
        // freeze() po uzavření i fallback přepočet closed období.
        $stmt = $this->db->pdo()->prepare(
            "WITH RECURSIVE " . JournalTaxOrigin::cte($supplierId) . " SELECT COALESCE(SUM(CASE WHEN l.side = 'credit' THEN l.signed_amount ELSE -l.signed_amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               " . JournalTaxOrigin::join() . "
               JOIN {$accountDefinitions} a ON a.id = l.account_id
               LEFT JOIN {$accountDefinitions} p ON p.id = a.parent_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND " . JournalTaxOrigin::includedSql() . "
                AND e.entry_date BETWEEN ? AND ?
                AND ({$like})"
        );
        $stmt->execute(array_merge([$supplierId, ClosingSourceId::STOCK_SLOT_BASE, $from, $to], array_values($codes)));
        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * EXISTS filtr hlavní knihy na protistranu/text zdrojového dokladu (feature:
     * hledání v hlavní knize dle dodavatele/odběratele/položky faktury). Filtruje na
     * úrovni CELÉHO ZÁPISU (`e.source_type`/`e.source_id` — sloupce žádného konkrétního
     * řádku), takže gate je binární per zápis: buď se do agregace vezmou VŠECHNY jeho
     * řádky, nebo žádný — Σ MD = Σ D vyváženého zápisu se tedy zachovává i po filtru
     * (na rozdíl od JOINu na fakturu/položku, který by řádky znásobil a agregace —
     * obraty/zůstatky per účet — by přestaly sedět, viz vzor {@see JournalEntryRepository::buildWhere()}).
     *
     * `vendor` hledá jen mezi zápisy ze zdroje `purchase_invoice` (dodavatel = vendor
     * přijaté faktury), `client` jen mezi `invoice` (odběratel = client vydané faktury);
     * `item` hledá text položky napříč oběma zdroji (`invoice_items`/`purchase_invoice_items`).
     * Kombinace `vendor` + `client` současně je protimluv (zápis nemůže mít oba zdroje
     * zároveň) — vrátí prázdný výsledek, což je akceptovatelné (dvě různá pole, typicky
     * se plní jen jedno).
     *
     * `dimension` ({@see DimensionFilter}) se tu neřeší: filtruje ŘÁDKY a u řádku s rozpadem
     * i jeho částku, takže nahrazuje celý zdroj řádků ({@see lineSource()}).
     *
     * @param array{vendor?:string, client?:string, item?:string, dimension?:DimensionFilter} $filters
     * @param string $alias alias `journal_entries` v dotazu (vždy `e`)
     * @return array{0:string, 1:list<mixed>} SQL fragment (s vedoucím ' AND ...') + params
     */
    private function counterpartyFilter(array $filters, string $alias): array
    {
        $sql = '';
        $params = [];
        if (!empty($filters['vendor'])) {
            $needle = '%' . self::escapeLike((string) $filters['vendor']) . '%';
            $sql .= " AND ({$alias}.source_type = 'purchase_invoice' AND EXISTS (
                SELECT 1 FROM purchase_invoices pi
                JOIN clients v ON v.id = pi.vendor_id
                WHERE pi.id = {$alias}.source_id AND pi.supplier_id = {$alias}.supplier_id
                  AND v.company_name LIKE ? ESCAPE '='
            ))";
            $params[] = $needle;
        }
        if (!empty($filters['client'])) {
            $needle = '%' . self::escapeLike((string) $filters['client']) . '%';
            $sql .= " AND ({$alias}.source_type = 'invoice' AND EXISTS (
                SELECT 1 FROM invoices i
                JOIN clients c ON c.id = i.client_id
                WHERE i.id = {$alias}.source_id AND i.supplier_id = {$alias}.supplier_id
                  AND c.company_name LIKE ? ESCAPE '='
            ))";
            $params[] = $needle;
        }
        if (!empty($filters['item'])) {
            $needle = '%' . self::escapeLike((string) $filters['item']) . '%';
            $sql .= " AND ((
                {$alias}.source_type = 'invoice' AND EXISTS (
                    SELECT 1 FROM invoice_items ii
                    JOIN invoices i2 ON i2.id = ii.invoice_id
                    WHERE ii.invoice_id = {$alias}.source_id AND i2.supplier_id = {$alias}.supplier_id
                      AND ii.description LIKE ? ESCAPE '='
                )
            ) OR (
                {$alias}.source_type = 'purchase_invoice' AND EXISTS (
                    SELECT 1 FROM purchase_invoice_items pii
                    JOIN purchase_invoices pi2 ON pi2.id = pii.purchase_invoice_id
                    WHERE pii.purchase_invoice_id = {$alias}.source_id AND pi2.supplier_id = {$alias}.supplier_id
                      AND pii.description LIKE ? ESCAPE '='
                )
            ))";
            $params[] = $needle;
            $params[] = $needle;
        }
        return [$sql, $params];
    }

    /**
     * Zdroj řádků deníku pod aliasem `l`: bez filtru tabulka sama, s filtrem na dimenzi
     * řádky dané hodnoty včetně poměrného dílu řádků s rozpadem ({@see DimensionFilter::lineSource()})
     * omezené na interval dat zápisu, který sestava čte.
     *
     * @return array{0:string, 1:list<int|string>}
     */
    private function lineSource(int $supplierId, ?DimensionFilter $dimension, ?string $from, string $to): array
    {
        return $dimension !== null ? $dimension->lineSource($supplierId, 'l', $from, $to) : ['journal_entry_lines l', []];
    }

    /** @return array{name:string, ic:string} */
    public function supplierIdentity(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT company_name, ic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['name' => (string) ($row['company_name'] ?? ''), 'ic' => (string) ($row['ic'] ?? '')];
    }

    /**
     * Pohyby podrobné hlavní knihy, řazené účtem a chronologicky. Jediný proudový
     * dotaz se odloží do dočasného proudu (nad 2 MB na disk), pak se předává po
     * dávkách. Kontext může používat totéž PDO až po uzavření nebufferovaného
     * kurzoru. Bez odložení by každá dávka znovu řadila celý roční deník.
     * Filtry PS/obratů i osnova k datu `to` zůstávají stejné jako v trialBalanceRows,
     * jinak by kontrola souhlasu se souhrnem hlásila nesoulad.
     * @return \Generator<int,list<array<string,mixed>>>
     */
    public function generalLedgerLineBatches(int $supplierId, array $data, array $filters, bool $afterClosing, int $batchSize = 500): \Generator
    {
        $ids = array_column($data['accounts'], 'account_id');
        if ($ids === []) return;
        $batchSize = max(1, min(1000, $batchSize));
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $accountDefinitions = AccountDefinitionRepository::relation($data['to'], $supplierId);
        // Účty řádků předem, aby úzký rozsah (501–501) šel po indexu účtu, ne přes celý rok.
        $lineAccountSql = !empty($data['analytics'])
            ? "l.account_id IN ({$marks})"
            : "l.account_id IN (SELECT ca.id FROM chart_of_accounts ca WHERE ca.supplier_id = ? AND (ca.id IN ({$marks}) OR ca.parent_id IN ({$marks})))";
        $lineAccountParams = !empty($data['analytics']) ? $ids : [$supplierId, ...$ids, ...$ids];
        [$source, $sourceParams] = $this->lineSource($supplierId, $filters['dimension'] ?? null, $data['from'], $data['to']);
        // Dimenzní zdroj řádků nenese is_red_storno a znaménko dílu nestačí (díl rozpadu může být 0,00).
        // Příznak účinné strany protiúčtu se proto bere ze základního řádku po primárním klíči.
        $stornoSql = isset($filters['dimension']) ? 'raw.is_red_storno' : 'l.is_red_storno';
        $stornoJoin = isset($filters['dimension']) ? 'JOIN journal_entry_lines raw ON raw.id = l.id AND raw.supplier_id = l.supplier_id' : '';
        [$filterSql, $filterParams] = $this->counterpartyFilter($filters, 'e');
        $excludeClosing = !$afterClosing;
        $closingSql = $excludeClosing ? ' AND ' . JournalTaxOrigin::includedSql() : '';
        $closingParams = $excludeClosing ? [ClosingSourceId::STOCK_SLOT_BASE] : [];
        $openingSql = !empty($data['all_periods']) && !$afterClosing
            ? " AND e.source_type <> 'opening'"
            : " AND NOT (e.entry_date = ? AND e.source_type = 'opening')";
        $openingParams = !empty($data['all_periods']) && !$afterClosing ? [] : [$data['from']];
        $group = !empty($data['analytics']) ? 'a.id' : 'COALESCE(a.parent_id, a.id)';
        $sql = ($excludeClosing ? 'WITH RECURSIVE ' . JournalTaxOrigin::cte($supplierId) . ' ' : '') . "
                SELECT g.id AS group_id, g.account_code AS group_code,
                       e.id AS entry_id, e.entry_date, e.document_no, e.description, e.source_type, e.source_id,
                       l.id AS line_id, l.line_no, l.account_id, a.account_code, l.side, l.signed_amount AS amount,
                       {$stornoSql} AS is_red_storno
                  FROM {$source}
                  {$stornoJoin}
                  JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
                  " . ($excludeClosing ? JournalTaxOrigin::join() : '') . "
                  JOIN {$accountDefinitions} a ON a.id = l.account_id
                  JOIN {$accountDefinitions} g ON g.id = {$group}
                 WHERE l.supplier_id = ? AND e.supplier_id = ? AND e.posted_at IS NOT NULL
                   AND e.entry_date >= ? AND e.entry_date <= ? AND {$lineAccountSql} AND g.id IN ({$marks})
                   {$filterSql}{$closingSql}{$openingSql}
                 ORDER BY g.account_code, e.entry_date, e.id, l.line_no, l.id";
        $pdo = $this->db->pdo();
        $buffered = $pdo->getAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY);
        $spool = new \SplTempFileObject(2 * 1024 * 1024);
        $stmt = null;
        try {
            $pdo->setAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, false);
            $stmt = $pdo->prepare($sql);
            $stmt->execute([...$sourceParams, $supplierId, $supplierId, $data['from'], $data['to'], ...$lineAccountParams, ...$ids,
                ...$filterParams, ...$closingParams, ...$openingParams]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $encoded = json_encode($row, JSON_THROW_ON_ERROR) . "\n";
                if ($spool->fwrite($encoded) !== strlen($encoded)) throw new \RuntimeException('Nelze uložit dočasná data exportu.');
            }
        } finally {
            $stmt?->closeCursor();
            $pdo->setAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, $buffered);
        }
        $spool->rewind();
        $batch = [];
        while (!$spool->eof()) {
            $encoded = $spool->fgets();
            if ($encoded === '') continue;
            $batch[] = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
            if (count($batch) === $batchSize) {
                yield $batch;
                $batch = [];
            }
        }
        if ($batch !== []) yield $batch;
    }

    private static function escapeLike(string $value): string
    {
        return strtr($value, ['=' => '==', '%' => '=%', '_' => '=_']);
    }

    /**
     * Kotva PS okna po uzávěrce (F4 R16): starts_on posledního období se
     * zaúčtovaným opening zápisem a starts_on <= date. NULL = firma bez
     * zaúčtované uzávěrky → volající nechává kumulativní okno (dnešní chování).
     * Počítá se jednou per volání sestavy (žádný N+1).
     */
    public function openingAnchor(int $supplierId, string $date, bool $currentRead = false): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT MAX(p.starts_on)
               FROM accounting_periods p
              WHERE p.supplier_id = ? AND p.starts_on <= ?
                AND EXISTS (SELECT 1 FROM journal_entries e
                             WHERE e.supplier_id = p.supplier_id AND e.period_id = p.id
                               AND e.source_type = 'opening' AND e.posted_at IS NOT NULL)" . ($currentRead ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId, $date]);
        $val = $stmt->fetchColumn();
        return ($val === false || $val === null) ? null : (string) $val;
    }

    /**
     * Předchozí účetní období (R13): poslední období končící PŘED daným datem.
     *
     * @return array<string,mixed>|null
     */
    public function previousPeriod(int $supplierId, string $beforeDate): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, fiscal_year, starts_on, ends_on, status
               FROM accounting_periods
              WHERE supplier_id = ? AND ends_on < ?
              ORDER BY ends_on DESC
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $beforeDate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        $row['supplier_id'] = (int) $row['supplier_id'];
        $row['fiscal_year'] = (int) $row['fiscal_year'];
        return $row;
    }
}
