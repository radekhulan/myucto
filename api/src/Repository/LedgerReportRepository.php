<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
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
     *                                    normal_side, is_synthetic, ps_md, ps_d, to_md, to_d}
     */
    public function trialBalanceRows(int $supplierId, string $from, string $to, string $periodStart, bool $analytics = false, array $filters = [], bool $excludeClosing = false, bool $excludeAllOpenings = false): array
    {
        $anchor = $this->openingAnchor($supplierId, $from);
        [$filterSql, $filterParams] = $this->counterpartyFilter($filters, 'e');
        // Inventarizace/rozvaha k rozvahovému dni potřebuje zůstatky PŘED uzavřením knih —
        // close_books zápis (source_type='closing', source_id = period_id < STOCK_SLOT_BASE, UZ)
        // převádí rozvahové účty na 702/710 a jinak by je vynuloval. Otevírací
        // (source_type='opening') a slotované skladové zápisy §3.4 (112/132/501/504,
        // source_id >= STOCK_SLOT_BASE) zůstávají — reálné zůstatky zásob k rozvahovému dni.
        $closingSql = $excludeClosing ? " AND NOT (e.source_type = 'closing' AND e.source_id < ?)" : '';
        $closingParams = $excludeClosing ? [ClosingSourceId::STOCK_SLOT_BASE] : [];
        $turnoverOpeningSql = $excludeAllOpenings
            ? "e.source_type <> 'opening'"
            : "NOT (e.entry_date = ? AND e.source_type = 'opening')";
        $turnoverOpeningParams = $excludeAllOpenings ? [] : [$from];
        $stmt = $this->db->pdo()->prepare(
            "WITH agg AS (
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
                              AND l.side = 'debit'  THEN l.amount ELSE 0 END) AS ps_md,
                    SUM(CASE WHEN (e.entry_date < ? OR (e.entry_date = ? AND e.source_type = 'opening'))
                              AND (a.account_type NOT IN ('revenue','expense') OR e.entry_date >= ?)
                              AND (a.account_type NOT IN ('asset','liability','equity') OR ? IS NULL OR e.entry_date >= ?)
                              AND l.side = 'credit' THEN l.amount ELSE 0 END) AS ps_d,
                    SUM(CASE WHEN e.entry_date >= ? AND {$turnoverOpeningSql}
                              AND l.side = 'debit'  THEN l.amount ELSE 0 END) AS to_md,
                    SUM(CASE WHEN e.entry_date >= ? AND {$turnoverOpeningSql}
                              AND l.side = 'credit' THEN l.amount ELSE 0 END) AS to_d
                FROM journal_entry_lines l
                JOIN journal_entries e   ON e.id = l.entry_id
                JOIN chart_of_accounts a ON a.id = l.account_id
                WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL AND e.entry_date <= ?{$filterSql}{$closingSql}
                GROUP BY acc_id
            )
            SELECT c.id, c.account_code, c.name, c.account_type, c.normal_side, c.is_synthetic,
                   agg.ps_md, agg.ps_d, agg.to_md, agg.to_d
              FROM agg
              JOIN chart_of_accounts c ON c.id = agg.acc_id
             ORDER BY c.account_code"
        );
        $stmt->execute([
            $analytics ? 1 : 0,
            $from, $from, $periodStart, $anchor, $anchor,
            $from, $from, $periodStart, $anchor, $anchor,
            $from, ...$turnoverOpeningParams,
            $from, ...$turnoverOpeningParams,
            $supplierId, $to,
            ...$filterParams,
            ...$closingParams,
        ]);
        return array_map(static function (array $r): array {
            $r['id'] = (int) $r['id'];
            $r['is_synthetic'] = (bool) $r['is_synthetic'];
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
        [$filterSql, $filterParams] = $this->counterpartyFilter($filters, 'e');
        $closingSql = $excludeClosing ? " AND NOT (e.source_type = 'closing' AND e.source_id < ?)" : '';
        $closingParams = $excludeClosing ? [ClosingSourceId::STOCK_SLOT_BASE] : [];
        $openingSql = $excludeAllOpenings
            ? " AND e.source_type <> 'opening'"
            : " AND NOT (e.entry_date = ? AND e.source_type = 'opening')";
        $openingParams = $excludeAllOpenings ? [] : [$from];
        $stmt = $this->db->pdo()->prepare(
            "SELECT CASE WHEN ? = 1 THEN a.id ELSE COALESCE(a.parent_id, a.id) END AS acc_id,
                    DATE_FORMAT(e.entry_date, '%Y-%m') AS ym,
                    SUM(CASE WHEN l.side = 'debit'  THEN l.amount ELSE 0 END) AS md,
                    SUM(CASE WHEN l.side = 'credit' THEN l.amount ELSE 0 END) AS d
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL AND e.entry_date BETWEEN ? AND ?
                {$openingSql}{$filterSql}{$closingSql}
              GROUP BY acc_id, ym"
        );
        $stmt->execute([$analytics ? 1 : 0, $supplierId, $from, $to, ...$openingParams, ...$filterParams, ...$closingParams]);
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
     * @return list<array<string,mixed>>
     */
    public function accountLines(int $supplierId, int $accountId, string $from, string $to, int $limit, int $offset, bool $excludeClosing = false): array
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        [$technicalSql, $technicalParams] = $this->technicalEntryFilter($from, $excludeClosing);
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM (
                SELECT e.id AS entry_id, e.entry_date, e.document_no, e.description, e.source_type, e.source_id,
                       ca.id AS line_account_id, ca.account_code, ca.name AS line_account_name,
                       l.side, l.amount, l.line_no,
                       bt.statement_id AS source_statement_id,
                       cd.doc_number AS source_doc_number,
                       cd.register_id AS source_register_id,
                       ast.id AS source_asset_id,
                       ast.name AS source_asset_name,
                       stl.doc_type AS source_settlement_doc_type,
                       stl.doc_id AS source_settlement_doc_id,
                       SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END)
                         OVER (ORDER BY e.entry_date, e.id, l.line_no
                               ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS running_delta
                  FROM journal_entry_lines l
                  JOIN journal_entries e    ON e.id = l.entry_id
                  JOIN chart_of_accounts ca ON ca.id = l.account_id
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
            ORDER BY t.entry_date, t.entry_id, t.line_no
            LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute([$supplierId, $accountId, $accountId, $from, $to, ...$technicalParams]);
        return array_map(static function (array $r): array {
            $r['entry_id'] = (int) $r['entry_id'];
            $r['source_id'] = $r['source_id'] === null ? null : (int) $r['source_id'];
            $r['amount'] = round((float) $r['amount'], 2);
            $r['line_no'] = (int) $r['line_no'];
            $r['running_delta'] = round((float) $r['running_delta'], 2);
            $r['line_account_id'] = (int) $r['line_account_id'];
            foreach (['source_statement_id', 'source_register_id', 'source_asset_id', 'source_settlement_doc_id'] as $k) {
                $r[$k] = $r[$k] === null ? null : (int) $r[$k];
            }
            return $r;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
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
            . " AND NOT (e.source_type = 'closing' AND e.source_id < ?)",
            [ClosingSourceId::STOCK_SLOT_BASE],
        ];
    }

    /**
     * PS účtu (vč. analytik pod syntetikou) k datu `from` — signed delta (kladné = MD),
     * okno dle R6 (výsledkové účty jen od začátku období `periodStart`).
     */
    public function accountOpening(int $supplierId, int $accountId, string $from, string $periodStart, bool $excludeClosing = false): float
    {
        $anchor = $this->openingAnchor($supplierId, $from);
        $closingSql = $excludeClosing ? " AND NOT (e.source_type = 'closing' AND e.source_id < ?)" : '';
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e    ON e.id = l.entry_id
               JOIN chart_of_accounts ca ON ca.id = l.account_id
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
    public function accountLinesTotal(int $supplierId, int $accountId, string $from, string $to, bool $excludeClosing = false): int
    {
        [$technicalSql, $technicalParams] = $this->technicalEntryFilter($from, $excludeClosing);
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*)
               FROM journal_entry_lines l
               JOIN journal_entries e    ON e.id = l.entry_id
               JOIN chart_of_accounts ca ON ca.id = l.account_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND (l.account_id = ? OR ca.parent_id = ?)
                AND e.entry_date BETWEEN ? AND ?{$technicalSql}"
        );
        $stmt->execute([$supplierId, $accountId, $accountId, $from, $to, ...$technicalParams]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Obraty MD/D účtu (vč. analytik pod syntetikou) za celý rozsah.
     *
     * @return array{md: float, d: float}
     */
    public function accountTurnovers(int $supplierId, int $accountId, string $from, string $to, bool $excludeClosing = false): array
    {
        [$technicalSql, $technicalParams] = $this->technicalEntryFilter($from, $excludeClosing);
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit'  THEN l.amount ELSE 0 END), 0) AS md,
                    COALESCE(SUM(CASE WHEN l.side = 'credit' THEN l.amount ELSE 0 END), 0) AS d
               FROM journal_entry_lines l
               JOIN journal_entries e    ON e.id = l.entry_id
               JOIN chart_of_accounts ca ON ca.id = l.account_id
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
    public function journalTotals(int $supplierId, string $from, string $to, bool $excludeClosing = false): array
    {
        $closingSql = $excludeClosing ? " AND NOT (e.source_type = 'closing' AND e.source_id < ?)" : '';
        $closingParams = $excludeClosing ? [ClosingSourceId::STOCK_SLOT_BASE] : [];
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit'  THEN l.amount ELSE 0 END), 0) AS md,
                    COALESCE(SUM(CASE WHEN l.side = 'credit' THEN l.amount ELSE 0 END), 0) AS d
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL AND e.entry_date BETWEEN ? AND ?
                AND NOT (e.entry_date = ? AND e.source_type = 'opening'){$closingSql}"
        );
        $stmt->execute([$supplierId, $from, $to, $from, ...$closingParams]);
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
     * @param list<string> $splitCodes syntetické prefixy k rozdělení per analytika
     * @return list<array{account_id:int, code:string, name:string, account_type:string, md:float, d:float}>
     */
    public function syntheticBalances(
        int $supplierId,
        string $asOf,
        ?string $plFrom,
        array $splitCodes = [],
        array $preserveCodes = [],
    ): array
    {
        $anchor = $this->openingAnchor($supplierId, $asOf);
        $plCond = '';
        $params = [$supplierId, ClosingSourceId::STOCK_SLOT_BASE, $asOf];
        if ($plFrom !== null) {
            $plCond = " AND (a.account_type NOT IN ('revenue','expense') OR e.entry_date >= ?)";
            $params[] = $plFrom;
        }
        $params[] = $anchor;
        $params[] = $anchor;
        // Agregace per LIST účet (a.id) + jeho syntetika — roll-up i případný D2 split
        // (kladné/záporné saldo per analytika) se dopočítá v PHP.
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(p.id, a.id) AS account_id,
                    COALESCE(p.account_code, a.account_code) AS code,
                    COALESCE(p.name, a.name) AS name,
                    a.account_type,
                    a.id AS leaf_id,
                    a.account_code AS leaf_code,
                    a.name AS leaf_name,
                    SUM(CASE WHEN l.side = 'debit'  THEN l.amount ELSE 0 END) AS md,
                    SUM(CASE WHEN l.side = 'credit' THEN l.amount ELSE 0 END) AS d
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
               LEFT JOIN chart_of_accounts p ON p.id = a.parent_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND a.account_type NOT IN ('offbalance','closing')
                AND NOT (e.source_type = 'closing' AND e.source_id < ?)
                AND e.entry_date <= ?{$plCond}
                AND (a.account_type NOT IN ('asset','liability','equity') OR ? IS NULL OR e.entry_date >= ?)
              GROUP BY COALESCE(p.id, a.id), COALESCE(p.account_code, a.account_code),
                       COALESCE(p.name, a.name), a.account_type, a.id
              ORDER BY code"
        );
        $stmt->execute($params);
        return $this->rollupSyntheticBalances($stmt->fetchAll(PDO::FETCH_ASSOC), $splitCodes, $preserveCodes);
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
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'credit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
               LEFT JOIN chart_of_accounts p ON p.id = a.parent_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND NOT (e.source_type = 'closing' AND e.source_id < ?)
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
     * @param array{vendor?:string, client?:string, item?:string} $filters
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
    private function openingAnchor(int $supplierId, string $date): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT MAX(p.starts_on)
               FROM accounting_periods p
              WHERE p.supplier_id = ? AND p.starts_on <= ?
                AND EXISTS (SELECT 1 FROM journal_entries e
                             WHERE e.supplier_id = p.supplier_id AND e.period_id = p.id
                               AND e.source_type = 'opening' AND e.posted_at IS NOT NULL)"
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
