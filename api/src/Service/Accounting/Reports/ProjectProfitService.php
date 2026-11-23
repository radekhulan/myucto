<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Support\Sql\PurchaseCostSql;
use PDO;

/**
 * Výsledovka po zakázkách — výnosy, náklady a marže jedné akce (issue #29).
 *
 * Klient (cestovní kancelář) váže k jedné akci víc odběratelů (víc vydaných faktur)
 * i víc dodavatelů (vstupenky, hotel, doprava). Zakázku proto nelze počítat „přes
 * protistranu" ani přes klienta zakázky — jediné, co doklady spojuje, je `project_id`.
 *
 * ## Odkud se čísla berou
 *
 * PODVOJNÉ ÚČETNICTVÍ → z DENÍKU (`journal_entry_lines.project_id`, razítkuje
 * {@see \MyInvoice\Service\Accounting\PostingService::stampProjectDimension}). Výnos =
 * 6xx, náklad = 5xx, obojí orientované na normální stranu účtu. Proč ne součet nad
 * hlavičkami dokladů: deník je jediné místo, kde se sejde VŠECHNO, co akci zatěžuje —
 * faktury, pokladna i ruční zápisy — a kde už jsou vyřešené dobropisy, storna
 * (protizápis nese tutéž zakázku) a poměrné odpočty DPH. Součet nad doklady by dával
 * jiné číslo než výsledovka a účetní by pak dohledávala, které z nich lže.
 *
 * DAŇOVÁ EVIDENCE → deník neexistuje, takže se sčítají DOKLADY (`source='documents'`).
 * Fallback je akruální (dle data vystavení, ne úhrady) a je to vědomé zjednodušení:
 * slouží k průběžnému řízení ekonomiky akce, ne jako podklad k dani.
 *
 * ## Storna a nezaúčtované doklady
 *
 * Z deníku se NEVYŘAZUJÍ stornované zápisy — protizápis nese stejnou zakázku a v
 * součtu se s originálem vyruší na nulu. Vyřadit originál a nechat storno by naopak
 * dalo záporný nesmysl.
 *
 * Doklad se zakázkou, který ještě NENÍ zaúčtovaný, do součtu z deníku nevstupuje —
 * proto se vrací i `unposted`. Bez toho by prázdná marže vypadala jako výdělek nula,
 * ne jako „ještě není zaúčtováno".
 */
final class ProjectProfitService
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Výsledovka pro VŠECHNY zakázky tenanta (řazeno dle marže vzestupně — prodělečné
     * akce nahoře, ty jsou důvod, proč se sestava otevírá).
     *
     * @param array{date_from?:?string, date_to?:?string, include_archived?:bool} $filters
     * @return array{source:string, currency:string, date_from:?string, date_to:?string,
     *               items:list<array<string,mixed>>, totals:array<string,float>}
     */
    public function overview(int $supplierId, array $filters = []): array
    {
        $dateFrom = self::date($filters['date_from'] ?? null);
        $dateTo   = self::date($filters['date_to'] ?? null);
        $source   = $this->sourceFor($supplierId);

        $sums = $source === 'journal'
            ? $this->sumsFromJournal($supplierId, null, $dateFrom, $dateTo)
            : $this->sumsFromDocuments($supplierId, null, $dateFrom, $dateTo);
        $unposted = $source === 'journal'
            ? $this->unpostedCounts($supplierId, null, $dateFrom, $dateTo)
            : [];

        $items = [];
        foreach ($this->projects($supplierId, !empty($filters['include_archived'])) as $project) {
            $id  = (int) $project['id'];
            $sum = $sums[$id] ?? ['revenue' => 0.0, 'cost' => 0.0];
            $unpostedCount = $unposted[$id] ?? 0;
            // Zakázka bez jediného pohybu a bez rozpočtu je v přehledu jen šum.
            if (self::cents($sum['revenue']) === 0 && self::cents($sum['cost']) === 0 && $unpostedCount === 0) {
                continue;
            }
            $items[] = $this->itemFor($project, $sum, $unpostedCount);
        }

        usort($items, static fn (array $a, array $b) => $a['margin'] <=> $b['margin']);

        return [
            'source'    => $source,
            'currency'  => 'CZK',
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
            'items'     => $items,
            'totals'    => [
                'revenue' => round(array_sum(array_column($items, 'revenue')), 2),
                'cost'    => round(array_sum(array_column($items, 'cost')), 2),
                'margin'  => round(array_sum(array_column($items, 'margin')), 2),
            ],
        ];
    }

    /**
     * Výsledovka JEDNÉ zakázky + seznam dokladů, ze kterých vznikla (bez ohledu na
     * protistranu — přesně to, co v POHODĚ dělá přehled zakázky).
     *
     * @param array{date_from?:?string, date_to?:?string} $filters
     * @return array<string,mixed>|null null = zakázka neexistuje nebo nepatří tenantovi
     */
    public function detail(int $supplierId, int $projectId, array $filters = []): ?array
    {
        $project = $this->project($supplierId, $projectId);
        if ($project === null) {
            return null;
        }
        $dateFrom = self::date($filters['date_from'] ?? null);
        $dateTo   = self::date($filters['date_to'] ?? null);
        $source   = $this->sourceFor($supplierId);

        $sums = $source === 'journal'
            ? $this->sumsFromJournal($supplierId, $projectId, $dateFrom, $dateTo)
            : $this->sumsFromDocuments($supplierId, $projectId, $dateFrom, $dateTo);
        $unposted = $source === 'journal'
            ? ($this->unpostedCounts($supplierId, $projectId, $dateFrom, $dateTo)[$projectId] ?? 0)
            : 0;

        $summary = $this->itemFor(
            $project,
            $sums[$projectId] ?? ['revenue' => 0.0, 'cost' => 0.0],
            $unposted,
        );

        return $summary + [
            'source'    => $source,
            'currency'  => 'CZK',
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
            'documents' => $this->documents($supplierId, $projectId, $dateFrom, $dateTo),
        ];
    }

    // ── součty ────────────────────────────────────────────────────────────────

    /**
     * Výnos/náklad z deníku. Znaménko: výnos je 6xx na straně DAL, náklad 5xx na MD;
     * opačná strana (dobropis, storno, vnitropodnikové přeúčtování) se ODEČÍTÁ.
     *
     * @return array<int, array{revenue:float, cost:float}>
     */
    private function sumsFromJournal(int $supplierId, ?int $projectId, ?string $from, ?string $to): array
    {
        $where  = ['jel.supplier_id = ?', 'jel.project_id IS NOT NULL', 'je.posted_at IS NOT NULL'];
        $params = [$supplierId];
        if ($projectId !== null) {
            $where[] = 'jel.project_id = ?';
            $params[] = $projectId;
        }
        if ($from !== null) {
            $where[] = 'je.entry_date >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $where[] = 'je.entry_date <= ?';
            $params[] = $to;
        }
        $sql = 'SELECT jel.project_id,
                       SUM(CASE WHEN a.account_type = \'revenue\'
                                THEN (CASE WHEN jel.side = \'credit\' THEN jel.signed_amount ELSE -jel.signed_amount END)
                                ELSE 0 END) AS revenue,
                       SUM(CASE WHEN a.account_type = \'expense\'
                                THEN (CASE WHEN jel.side = \'debit\' THEN jel.signed_amount ELSE -jel.signed_amount END)
                                ELSE 0 END) AS cost
                  FROM journal_entry_lines jel
                  JOIN journal_entries je   ON je.id = jel.entry_id
                  JOIN chart_of_accounts a  ON a.id = jel.account_id
                 WHERE ' . implode(' AND ', $where) . '
              GROUP BY jel.project_id';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['project_id']] = [
                'revenue' => round((float) $r['revenue'], 2),
                'cost'    => round((float) $r['cost'], 2),
            ];
        }
        return $out;
    }

    /**
     * Fallback pro daňovou evidenci (deník neexistuje) — součet nad doklady.
     *
     * Plátce DPH počítá bez daně (daň není ani náklad, ani výnos), neplátce s daní.
     * Cizí měna se přepočítává kurzem dokladu; bez kurzu se bere 1 (u CZK je to
     * správně, u cizoměnového dokladu bez kurzu není co lepšího udělat).
     *
     * @return array<int, array{revenue:float, cost:float}>
     */
    private function sumsFromDocuments(int $supplierId, ?int $projectId, ?string $from, ?string $to): array
    {
        $isVatPayer = $this->isVatPayer($supplierId);
        $amount = static fn (string $alias): string => $isVatPayer
            ? "{$alias}.total_without_vat * COALESCE({$alias}.exchange_rate, 1)"
            : "{$alias}.total_with_vat * COALESCE({$alias}.exchange_rate, 1)";

        $out = [];
        $add = static function (array $rows, string $bucket) use (&$out): void {
            foreach ($rows as $r) {
                $id = (int) $r['project_id'];
                $out[$id] ??= ['revenue' => 0.0, 'cost' => 0.0];
                $out[$id][$bucket] = round($out[$id][$bucket] + (float) $r['total'], 2);
            }
        };

        // Výnos — vydané faktury (stejná definice „platného dokladu" jako ProjectStatsAction).
        [$dateWhere, $dateParams] = self::dateClause('COALESCE(i.tax_date, i.issue_date)', $from, $to);
        $projWhere = $projectId !== null ? ' AND i.project_id = ?' : '';
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.project_id, SUM(' . $amount('i') . ') AS total
               FROM invoices i
              WHERE i.supplier_id = ? AND i.project_id IS NOT NULL
                AND i.status IN (\'issued\', \'sent\', \'reminded\', \'paid\')
                AND i.invoice_type IN (\'invoice\', \'credit_note\', \'tax_document\')'
            . $projWhere . $dateWhere . ' GROUP BY i.project_id'
        );
        $stmt->execute(array_merge([$supplierId], $projectId !== null ? [$projectId] : [], $dateParams));
        $add($stmt->fetchAll(PDO::FETCH_ASSOC), 'revenue');

        // Náklad — přijaté faktury. Zálohové doklady se vynechávají: konečná faktura
        // je vyúčtuje, takže by se náklad akce započítal dvakrát. Daňový doklad k platbě
        // (DDKP) nákladem není nikdy, náklad nese konečná faktura bez odpočtu zálohy
        // ({@see PurchaseCostSql}), stejně jako v ostatních přehledech nákladů.
        [$dateWhere, $dateParams] = self::dateClause('COALESCE(pi.tax_date, pi.issue_date)', $from, $to);
        $projWhere = $projectId !== null ? ' AND pi.project_id = ?' : '';
        $cost = PurchaseCostSql::cost('pi', $isVatPayer) . " * COALESCE(IF(cur.code = 'CZK', 1, pi.exchange_rate), 1)";
        $stmt = $this->db->pdo()->prepare(
            'SELECT pi.project_id, SUM(' . $cost . ') AS total
               FROM purchase_invoices pi' . PurchaseCostSql::deductionJoin('pi', $supplierId) . '
          LEFT JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.supplier_id = ? AND pi.project_id IS NOT NULL
                AND pi.status <> \'cancelled\'
                AND pi.document_kind NOT IN (\'advance\', \'tax_document\')'
            . $projWhere . $dateWhere . ' GROUP BY pi.project_id'
        );
        $stmt->execute(array_merge([$supplierId], $projectId !== null ? [$projectId] : [], $dateParams));
        $add($stmt->fetchAll(PDO::FETCH_ASSOC), 'cost');

        // Pokladna — jen přímý prodej/nákup. Úhrady faktur a převody hotovosti do
        // hospodářského výsledku akce nepatří (fakturu už započetla větev výš).
        [$dateWhere, $dateParams] = self::dateClause('COALESCE(cd.tax_date, cd.issue_date)', $from, $to);
        $projWhere = $projectId !== null ? ' AND cd.project_id = ?' : '';
        $stmt = $this->db->pdo()->prepare(
            'SELECT cd.project_id, cd.purpose, SUM(cd.total_amount * COALESCE(cd.fx_rate, 1)) AS total
               FROM cash_documents cd
              WHERE cd.supplier_id = ? AND cd.project_id IS NOT NULL
                AND cd.status = \'posted\'
                AND cd.purpose IN (\'sale\', \'purchase\')'
            . $projWhere . $dateWhere . ' GROUP BY cd.project_id, cd.purpose'
        );
        $stmt->execute(array_merge([$supplierId], $projectId !== null ? [$projectId] : [], $dateParams));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $add(array_values(array_filter($rows, static fn (array $r) => $r['purpose'] === 'sale')), 'revenue');
        $add(array_values(array_filter($rows, static fn (array $r) => $r['purpose'] === 'purchase')), 'cost');

        return $out;
    }

    /**
     * Kolik dokladů se zakázkou ještě čeká na zaúčtování (jen podvojné účetnictví).
     * Bez tohohle čísla vypadá nezaúčtovaná akce jako akce s nulovou marží.
     *
     * @return array<int, int>
     */
    private function unpostedCounts(int $supplierId, ?int $projectId, ?string $from, ?string $to): array
    {
        $out = [];
        $collect = function (string $sql, array $params) use (&$out): void {
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $id = (int) $r['project_id'];
                $out[$id] = ($out[$id] ?? 0) + (int) $r['cnt'];
            }
        };

        foreach ([['invoices', 'i'], ['purchase_invoices', 'pi']] as [$table, $alias]) {
            [$dateWhere, $dateParams] = self::dateClause("COALESCE({$alias}.tax_date, {$alias}.issue_date)", $from, $to);
            $projWhere = $projectId !== null ? " AND {$alias}.project_id = ?" : '';
            $collect(
                "SELECT {$alias}.project_id, COUNT(*) AS cnt
                   FROM {$table} {$alias}
                  WHERE {$alias}.supplier_id = ? AND {$alias}.project_id IS NOT NULL
                    AND {$alias}.status <> 'cancelled' AND {$alias}.booked_at IS NULL"
                . $projWhere . $dateWhere . " GROUP BY {$alias}.project_id",
                array_merge([$supplierId], $projectId !== null ? [$projectId] : [], $dateParams),
            );
        }

        [$dateWhere, $dateParams] = self::dateClause('COALESCE(cd.tax_date, cd.issue_date)', $from, $to);
        $projWhere = $projectId !== null ? ' AND cd.project_id = ?' : '';
        $collect(
            'SELECT cd.project_id, COUNT(*) AS cnt
               FROM cash_documents cd
              WHERE cd.supplier_id = ? AND cd.project_id IS NOT NULL
                AND cd.status = \'draft\''
            . $projWhere . $dateWhere . ' GROUP BY cd.project_id',
            array_merge([$supplierId], $projectId !== null ? [$projectId] : [], $dateParams),
        );

        return $out;
    }

    // ── doklady zakázky ───────────────────────────────────────────────────────

    /**
     * Všechny doklady zakázky napříč typy a protistranami, seřazené dle data sestupně.
     *
     * @return list<array<string,mixed>>
     */
    private function documents(int $supplierId, int $projectId, ?string $from, ?string $to): array
    {
        $pdo = $this->db->pdo();
        $isVatPayer = $this->isVatPayer($supplierId);
        $out = [];

        [$dateWhere, $dateParams] = self::dateClause('COALESCE(i.tax_date, i.issue_date)', $from, $to);
        $stmt = $pdo->prepare(
            'SELECT i.id, i.varsymbol AS number, COALESCE(i.tax_date, i.issue_date) AS doc_date,
                    i.total_without_vat, i.total_with_vat, i.exchange_rate, i.status, i.booked_at,
                    cur.code AS currency, c.company_name AS partner_name
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
          LEFT JOIN clients c      ON c.id = i.client_id
              WHERE i.supplier_id = ? AND i.project_id = ? AND i.status <> \'cancelled\''
            . $dateWhere . ' ORDER BY doc_date DESC, i.id DESC'
        );
        $stmt->execute(array_merge([$supplierId, $projectId], $dateParams));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = self::documentRow($r, 'invoice', 'revenue', $isVatPayer);
        }

        [$dateWhere, $dateParams] = self::dateClause('COALESCE(pi.tax_date, pi.issue_date)', $from, $to);
        $stmt = $pdo->prepare(
            'SELECT pi.id, pi.vendor_invoice_number AS number, COALESCE(pi.tax_date, pi.issue_date) AS doc_date,
                    pi.total_without_vat, pi.total_with_vat, pi.exchange_rate, pi.status, pi.booked_at,
                    cur.code AS currency, c.company_name AS partner_name
               FROM purchase_invoices pi
               JOIN currencies cur ON cur.id = pi.currency_id
               JOIN clients c      ON c.id = pi.vendor_id
              WHERE pi.supplier_id = ? AND pi.project_id = ? AND pi.status <> \'cancelled\''
            . $dateWhere . ' ORDER BY doc_date DESC, pi.id DESC'
        );
        $stmt->execute(array_merge([$supplierId, $projectId], $dateParams));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = self::documentRow($r, 'purchase_invoice', 'cost', $isVatPayer);
        }

        [$dateWhere, $dateParams] = self::dateClause('COALESCE(cd.tax_date, cd.issue_date)', $from, $to);
        $stmt = $pdo->prepare(
            'SELECT cd.id, cd.doc_number AS number, COALESCE(cd.tax_date, cd.issue_date) AS doc_date,
                    cd.total_amount, cd.fx_rate, cd.currency_code AS currency, cd.status,
                    cd.doc_type, cd.purpose, cd.partner_name, cd.description
               FROM cash_documents cd
              WHERE cd.supplier_id = ? AND cd.project_id = ? AND cd.status <> \'reversed\''
            . $dateWhere . ' ORDER BY doc_date DESC, cd.id DESC'
        );
        $stmt->execute(array_merge([$supplierId, $projectId], $dateParams));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'kind'         => 'cash_document',
                'direction'    => $r['doc_type'] === 'in' ? 'revenue' : 'cost',
                'id'           => (int) $r['id'],
                'number'       => $r['number'] ?? $r['description'],
                'doc_date'     => $r['doc_date'],
                'partner_name' => $r['partner_name'],
                'currency'     => $r['currency'],
                'amount'       => round((float) $r['total_amount'], 2),
                'amount_czk'   => round((float) $r['total_amount'] * (float) ($r['fx_rate'] ?: 1), 2),
                'status'       => $r['status'],
                'booked'       => $r['status'] === 'posted',
            ];
        }

        usort($out, static fn (array $a, array $b) => [$b['doc_date'], $b['id']] <=> [$a['doc_date'], $a['id']]);
        return $out;
    }

    /**
     * `amount` je v měně dokladu (co uživatel na dokladu vidí), `amount_czk` je ta částka,
     * kterou doklad reálně nese do hospodářského výsledku akce — u plátce bez DPH, u
     * neplátce s DPH, vždy přepočtená kurzem dokladu.
     *
     * @return array<string,mixed>
     */
    private static function documentRow(array $r, string $kind, string $direction, bool $isVatPayer): array
    {
        $rate = (float) ($r['exchange_rate'] ?? 0) ?: 1.0;
        $base = (float) ($isVatPayer ? $r['total_without_vat'] : $r['total_with_vat']);
        return [
            'kind'         => $kind,
            'direction'    => $direction,
            'id'           => (int) $r['id'],
            'number'       => $r['number'],
            'doc_date'     => $r['doc_date'],
            'partner_name' => $r['partner_name'],
            'currency'     => $r['currency'],
            'amount'       => round((float) $r['total_with_vat'], 2),
            'amount_czk'   => round($base * $rate, 2),
            'status'       => $r['status'],
            'booked'       => $r['booked_at'] !== null,
        ];
    }

    // ── pomocné ───────────────────────────────────────────────────────────────

    /**
     * @param array{revenue:float, cost:float} $sum
     * @return array<string,mixed>
     */
    private function itemFor(array $project, array $sum, int $unposted): array
    {
        $revenue = round($sum['revenue'], 2);
        $cost    = round($sum['cost'], 2);
        $margin  = round($revenue - $cost, 2);
        $budget  = $project['budget_total'] !== null ? (float) $project['budget_total'] : null;

        return [
            'id'                  => (int) $project['id'],
            'name'                => $project['name'],
            'project_number'      => $project['project_number'],
            'contract_number'     => $project['contract_number'],
            'status'              => $project['status'],
            'client_company_name' => $project['client_company_name'],
            'revenue'             => $revenue,
            'cost'                => $cost,
            'margin'              => $margin,
            // Marže v procentech výnosu. Bez výnosu je procento nedefinované (ne 0 %) —
            // akce, na kterou zatím jen padají náklady, není stoprocentní ztráta.
            'margin_percent'      => self::cents($revenue) !== 0 ? round($margin / $revenue * 100, 2) : null,
            'budget_total'        => $budget,
            'budget_used_percent' => $budget !== null && self::cents($budget) !== 0
                ? round($cost / $budget * 100, 2)
                : null,
            'unposted_documents'  => $unposted,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function projects(int $supplierId, bool $includeArchived): array
    {
        $sql = 'SELECT p.id, p.name, p.project_number, p.contract_number, p.status, p.budget_total,
                       c.company_name AS client_company_name
                  FROM projects p
                  JOIN clients c ON c.id = p.client_id
                 WHERE c.supplier_id = ?'
             . ($includeArchived ? '' : ' AND p.archived_at IS NULL');
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed>|null */
    private function project(int $supplierId, int $projectId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.id, p.name, p.project_number, p.contract_number, p.status, p.budget_total,
                    c.company_name AS client_company_name
               FROM projects p
               JOIN clients c ON c.id = p.client_id
              WHERE p.id = ? AND c.supplier_id = ?'
        );
        $stmt->execute([$projectId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** 'journal' pro podvojné účetnictví, 'documents' pro daňovou evidenci. */
    private function sourceFor(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return (string) $stmt->fetchColumn() === 'double_entry' ? 'journal' : 'documents';
    }

    private function isVatPayer(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT is_vat_payer FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return (bool) $stmt->fetchColumn();
    }

    /** @return array{0:string, 1:list<string>} */
    private static function dateClause(string $expr, ?string $from, ?string $to): array
    {
        $sql = '';
        $params = [];
        if ($from !== null) {
            $sql .= " AND {$expr} >= ?";
            $params[] = $from;
        }
        if ($to !== null) {
            $sql .= " AND {$expr} <= ?";
            $params[] = $to;
        }
        return [$sql, $params];
    }

    private static function date(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', trim($value));
        return $d !== false && $d->format('Y-m-d') === trim($value) ? trim($value) : null;
    }

    private static function cents(float $v): int
    {
        return (int) round($v * 100);
    }
}
