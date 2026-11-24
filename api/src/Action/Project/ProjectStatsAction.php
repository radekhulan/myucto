<?php

declare(strict_types=1);

namespace MyInvoice\Action\Project;

use MyInvoice\Http\Json;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Support\Sql\CzkAmountExpr;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/projects/stats — souhrnné statistiky zakázek pro grafy v UI:
 *  - top 10 zakázek dle obratu pro this_year a prev_year (zbytek do "Ostatní")
 *  - status breakdown (active / paused / closed)
 *  - primární měna (nejčastější ve fakturách)
 *  - YTD total per currency
 */
final class ProjectStatsAction
{
    public function __construct(private readonly Connection $db) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (!RequestAuthorization::allows($request, 'invoices')) {
            return Json::error($response, 'forbidden_permission', 'Statistiky zakázek vyžadují čtení faktur.', 403);
        }
        $pdo = $this->db->pdo();
        $thisYear = (int) date('Y');
        $prevYear = $thisYear - 1;
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $stmt = $pdo->prepare('SELECT is_vat_payer FROM supplier WHERE id = ?');
        $stmt->execute([$sid]);
        $isVatPayer = (bool) $stmt->fetchColumn();
        $rev = $isVatPayer ? 'i.total_without_vat' : 'i.total_with_vat';

        // Primární měna — nejvyužívanější ve fakturách (proti zaplnění grafů různými měnami)
        $stmt = $pdo->prepare(
            "SELECT cur.code AS currency
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.status != 'cancelled' AND i.invoice_type IN ('invoice', 'credit_note', 'tax_document')
           GROUP BY cur.code
           ORDER BY COUNT(*) DESC
              LIMIT 1"
        );
        $stmt->execute([$sid]);
        $primaryCurrency = (string) ($stmt->fetchColumn() ?: 'CZK');

        return Json::ok($response, [
            'this_year'        => $thisYear,
            'prev_year'        => $prevYear,
            'primary_currency' => $primaryCurrency,
            'top_this_year'    => $this->topProjects($pdo, $thisYear, $primaryCurrency, $sid, $rev),
            'top_prev_year'    => $this->topProjects($pdo, $prevYear, $primaryCurrency, $sid, $rev),
            'top_12m'          => $this->topProjects12m($pdo, $primaryCurrency, $sid, $rev),
            'totals_per_year'  => $this->totalsPerYear($pdo, [$thisYear, $prevYear], $sid, $rev),
            'status_breakdown' => $this->statusBreakdown($pdo, $sid),
            'is_vat_payer'     => $isVatPayer,
        ]);
    }

    /**
     * Top 10 zakázek dle obratu v daném roce a měně + souhrn "Ostatní".
     * @return array{top: list<array<string,mixed>>, others: array{revenue: float, count: int}}
     */
    private function topProjects(\PDO $pdo, int $year, string $currency, int $sid, string $rev): array
    {
        // CZK přepočet místo single-currency filtru — multi-currency projekty
        // se neroztrhnou ani nevypadnou z rankingu. Parametr $currency zachován
        // pro BC ale ignoruje se (vždy CZK).
        unset($currency);
        $revCzk = CzkAmountExpr::issuedRevenue($rev === 'i.total_without_vat');
        $stmt = $pdo->prepare(
            "SELECT p.id, p.name,
                    c.company_name AS client_company_name,
                    SUM($revCzk) AS revenue,
                    GROUP_CONCAT(DISTINCT cur.code ORDER BY cur.code SEPARATOR ',') AS currencies,
                    COUNT(i.id) AS invoice_count
               FROM invoices i
               JOIN projects p ON p.id = i.project_id
               JOIN clients  c ON c.id = p.client_id
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                AND i.invoice_type IN ('invoice', 'credit_note', 'tax_document')
                AND YEAR(COALESCE(i.tax_date, i.issue_date)) = ?
           GROUP BY p.id, p.name, c.company_name
             HAVING revenue > 0
           ORDER BY revenue DESC"
        );
        $stmt->execute([$sid, $year]);
        $all = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $top = array_slice($all, 0, 12);
        $rest = array_slice($all, 12);

        $top = array_map(fn (array $r) => [
            'id'                  => (int) $r['id'],
            'name'                => $r['name'],
            'client_company_name' => $r['client_company_name'],
            'revenue'             => (float) $r['revenue'],
            'currencies'          => (string) $r['currencies'],
            'invoice_count'       => (int) $r['invoice_count'],
        ], $top);

        $others = ['revenue' => 0.0, 'count' => 0];
        foreach ($rest as $r) {
            $others['revenue'] += (float) $r['revenue'];
            $others['count']++;
        }
        $others['revenue'] = round($others['revenue'], 2);

        return ['top' => $top, 'others' => $others];
    }

    /**
     * Top zakázky za posledních 12 měsíců (rolling) — stabilní vůči začátku roku.
     */
    private function topProjects12m(\PDO $pdo, string $currency, int $sid, string $rev): array
    {
        // CZK přepočet — viz topProjects().
        unset($currency);
        $revCzk = CzkAmountExpr::issuedRevenue($rev === 'i.total_without_vat');
        $stmt = $pdo->prepare(
            "SELECT p.id, p.name,
                    c.company_name AS client_company_name,
                    SUM($revCzk) AS revenue,
                    GROUP_CONCAT(DISTINCT cur.code ORDER BY cur.code SEPARATOR ',') AS currencies,
                    COUNT(i.id) AS invoice_count
               FROM invoices i
               JOIN projects p ON p.id = i.project_id
               JOIN clients  c ON c.id = p.client_id
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                AND i.invoice_type IN ('invoice', 'credit_note', 'tax_document')
                AND COALESCE(i.tax_date, i.issue_date) >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
           GROUP BY p.id, p.name, c.company_name
             HAVING revenue > 0
           ORDER BY revenue DESC"
        );
        $stmt->execute([$sid]);
        $all = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $top = array_slice($all, 0, 12);
        $rest = array_slice($all, 12);
        $top = array_map(static fn (array $r) => [
            'id'                  => (int) $r['id'],
            'name'                => $r['name'],
            'client_company_name' => $r['client_company_name'],
            'revenue'             => (float) $r['revenue'],
            'currencies'          => (string) $r['currencies'],
            'invoice_count'       => (int) $r['invoice_count'],
        ], $top);
        $others = ['revenue' => 0.0, 'count' => 0];
        foreach ($rest as $r) {
            $others['revenue'] += (float) $r['revenue'];
            $others['count']++;
        }
        $others['revenue'] = round($others['revenue'], 2);
        return ['top' => $top, 'others' => $others];
    }

    /** @param int[] $years */
    private function totalsPerYear(\PDO $pdo, array $years, int $sid, string $rev): array
    {
        $place = implode(',', array_fill(0, count($years), '?'));
        $stmt = $pdo->prepare(
            "SELECT YEAR(COALESCE(i.tax_date, i.issue_date)) AS year,
                    cur.code AS currency,
                    SUM($rev) AS total,
                    COUNT(*) AS invoice_count
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                AND i.invoice_type IN ('invoice', 'credit_note', 'tax_document')
                AND YEAR(COALESCE(i.tax_date, i.issue_date)) IN ($place)
           GROUP BY year, cur.code
           ORDER BY year DESC, total DESC"
        );
        $stmt->execute([$sid, ...$years]);
        return array_map(fn (array $r) => [
            'year'          => (int) $r['year'],
            'currency'      => $r['currency'],
            'total'         => (float) $r['total'],
            'invoice_count' => (int) $r['invoice_count'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    private function statusBreakdown(\PDO $pdo, int $sid): array
    {
        $stmt = $pdo->prepare(
            "SELECT p.status, COUNT(*) AS cnt
               FROM projects p
               JOIN clients c ON c.id = p.client_id
              WHERE p.archived_at IS NULL AND c.supplier_id = ?
           GROUP BY p.status"
        );
        $stmt->execute([$sid]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return array_map(fn (array $r) => [
            'status' => $r['status'],
            'count'  => (int) $r['cnt'],
        ], $rows);
    }
}
