<?php

declare(strict_types=1);

namespace MyInvoice\Action\Dashboard;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Support\Sql\PayablePredicate;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Agregace pro stránku "Náklady" (Costs) — zrcadlo SummaryAction, ale nad `purchase_invoices`
 * (přijaté faktury) místo `invoices` (vystavené).
 *
 *  - KPI: letošní náklady per měna, YoY change, počet přijatých, Ø doba úhrady dodavatelům
 *  - Po splatnosti (závazky): tabulka nezaplacených přijatých faktur s due_date < today
 *  - Nezaplacené před splatností (upcoming payables)
 *  - Top dodavatelé YTD / loni / rolling 12m (přepočet na CZK přes pi.exchange_rate)
 *  - Náklady po měsících (12 měsíců) + kumulativní cash-outflow
 *  - Rolling 12m náklady, náklady po rocích, forecast (growth-adjusted seasonality)
 *  - Aging závazků, cash-flow outflow forecast (30/60/90), due buckets
 *  - Rozpad DPH na vstupu (12m), rozpad nákladů po kategoriích (12m)
 *  - Histogram doby úhrady, distribuce velikosti přijatých faktur
 *
 * VAT-aware: plátce DPH agreguje `total_without_vat` (DPH na vstupu si odečte),
 * neplátce `total_with_vat` (DPH je pro něj nákladem). Storno/koncepty se vyřazují.
 *
 * Cost recognition date = COALESCE(tax_date, issue_date) — DUZP má přednost (konzistence s § 73).
 */
final class PurchaseSummaryAction
{
    /** Stavy přijaté faktury, které tvoří náklad (vyřazujeme draft + cancelled). */
    private const COST_STATUSES = "('received', 'booked', 'paid')";
    /** Stavy nezaplacených závazků (čeká na úhradu dodavateli). */
    private const UNPAID_STATUSES = "('received', 'booked')";

    public function __construct(private readonly Connection $db) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $pdo = $this->db->pdo();
        $today = new \DateTimeImmutable('today');
        $year = (int) $today->format('Y');
        $prevYear = $year - 1;
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $isVatPayer = $this->fetchIsVatPayer($pdo, $sid);

        return Json::ok($response, [
            'kpi'                    => $this->kpi($pdo, $year, $prevYear, $sid, $isVatPayer),
            'overdue'                => $this->overdue($pdo, $sid),
            'unpaid_upcoming'        => $this->unpaidUpcoming($pdo, $sid),
            'top_vendors_ytd'        => $this->topVendors($pdo, $year, $sid, $isVatPayer),
            'top_vendors_prev_year'  => $this->topVendors($pdo, $prevYear, $sid, $isVatPayer),
            'top_vendors_12m'        => $this->topVendorsRolling12m($pdo, $sid, $isVatPayer),
            'costs_by_month'         => $this->costsByMonth($pdo, $sid, $isVatPayer),
            'costs_by_year'          => $this->costsByYear($pdo, $sid, $isVatPayer),
            'rolling_12m'            => $this->rolling12mCosts($pdo, $sid, $isVatPayer),
            'cashflow_out_ytd'       => $this->cashflowOutYtd($pdo, $year, $prevYear, $sid),
            'payment_days_histogram' => $this->paymentDaysHistogram($pdo, $sid),
            'vat_breakdown_12m'      => $isVatPayer ? $this->vatInputBreakdown12m($pdo, $sid) : [],
            'cashflow_forecast'      => $this->cashflowOutForecast($pdo, $sid),
            'due_buckets'            => $this->dueBuckets($pdo, $sid),
            'aging_report'           => $this->agingReport($pdo, $sid),
            'costs_forecast'         => $this->costsForecast($pdo, $year, $prevYear, $sid, $isVatPayer),
            'expense_breakdown_12m'  => $this->expenseBreakdown12m($pdo, $sid, $isVatPayer),
            'invoice_size_histogram' => $this->invoiceSizeHistogram($pdo, $sid, $isVatPayer),
            'costs_last_30d'         => $this->costsLast30d($pdo, $sid, $isVatPayer),
            'active_vendors_count'   => $this->activeVendorsCount($pdo, $sid),
            'today'                  => $today->format('Y-m-d'),
            'year'                   => $year,
            'prev_year'              => $prevYear,
            'is_vat_payer'           => $isVatPayer,
        ]);
    }

    /** Zda je aktuální dodavatel plátce DPH — určuje sloupec nákladu (bez/s DPH). */
    private function fetchIsVatPayer(\PDO $pdo, int $sid): bool
    {
        $stmt = $pdo->prepare('SELECT is_vat_payer FROM supplier WHERE id = ?');
        $stmt->execute([$sid]);
        return (bool) $stmt->fetchColumn();
    }

    /** SQL fragment vybírající správný sloupec nákladu dle plátcovství DPH. */
    private function costCol(bool $isVatPayer): string
    {
        return $isVatPayer ? 'pi.total_without_vat' : 'pi.total_with_vat';
    }

    /**
     * SQL predikát (cash sémantika, daňová evidence): zálohovou fakturu (advance)
     * započti do NÁKLADŮ jen tehdy, když je ZAPLACENÁ a zároveň NENÍ spárovaná
     * s vyúčtovací fakturou. Vyřaď ji, pokud:
     *   • není zaplacená (received/booked) → cash ještě neodešel, výdaj nevznikl, NEBO
     *   • je spárovaná s finální fakturou → tu nese plný náklad (advance_paid_amount
     *     krátí jen amount_to_pay, ne total) → proti dvojímu započtení.
     * Tj. zaplacená kartou + zatím bez faktury od dodavatele se počítá, dokud se
     * nespáruje. Cashflow/závazky predikát NEPOUŽÍVAJÍ (nezaplacená záloha je závazek).
     */
    private function advanceCostExclude(string $alias = 'pi'): string
    {
        $p     = $alias === '' ? '' : $alias . '.';
        $idRef = $alias === '' ? 'purchase_invoices.id' : $alias . '.id';
        return " AND NOT (COALESCE({$p}document_kind, '') = 'advance'"
             . " AND ({$p}status <> 'paid'"
             . " OR EXISTS (SELECT 1 FROM purchase_invoices adv_s"
             . " WHERE adv_s.advance_purchase_invoice_id = {$idRef})))"
             // DDKP (daňový doklad k platbě) není náklad ani závazek — jen odpočet DPH ze
             // zálohy (343/314); jeho základ vzniká jako náklad až u vyúčtovací faktury. Proto
             // BEZPODMÍNEČNĚ ven z nákladových/závazkových sum (na rozdíl od 'advance' výše).
             . " AND COALESCE({$p}document_kind, '') <> 'tax_document'";
    }

    /**
     * DDKP (§ 28 ZDPH) ven ze ZÁVAZKOVÝCH sum — nezaplacené závazky, po splatnosti,
     * aging i cashflow. Odůvodnění a jediný zdroj pravdy: {@see PayablePredicate}.
     *
     * ZÁLOHA ('advance') se tu naopak NEVYLUČUJE, na rozdíl od {@see advanceCostExclude}:
     * nezaplacená zálohová faktura JE reálný závazek (viz tamní docblock). Proto
     * samostatný predikát, ne sdílení toho nákladového.
     */
    private function payableDocKindExclude(string $alias = 'pi'): string
    {
        return PayablePredicate::excludeAdvanceVatDocument($alias);
    }

    /** Počet aktivních (nearchivovaných) dodavatelů. */
    private function activeVendorsCount(\PDO $pdo, int $sid): int
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM clients WHERE supplier_id = ? AND is_vendor = 1 AND archived_at IS NULL');
        $stmt->execute([$sid]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Náklady za posledních 30 dní per měna — live indikátor aktuálních výdajů.
     * @return list<array{currency: string, total: float, invoice_count: int}>
     */
    private function costsLast30d(\PDO $pdo, int $sid, bool $isVatPayer): array
    {
        $cost = $this->costCol($isVatPayer);
        $sql = "SELECT cur.code AS currency, SUM($cost) AS total, COUNT(*) AS invoice_count
                  FROM purchase_invoices pi
                  JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE pi.supplier_id = ?
                   AND pi.effective_cost_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                   AND pi.status IN " . self::COST_STATUSES . $this->advanceCostExclude() . "
                 GROUP BY cur.code";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        return array_map(static fn (array $r) => [
            'currency'      => (string) $r['currency'],
            'total'         => round((float) $r['total'], 2),
            'invoice_count' => (int) $r['invoice_count'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Náklady po rocích per měna — všechny roky s přijatými fakturami.
     * @return list<array{year: int, currency: string, total: float, invoice_count: int}>
     */
    private function costsByYear(\PDO $pdo, int $sid, bool $isVatPayer): array
    {
        $cost = $this->costCol($isVatPayer);
        $sql = "SELECT YEAR(pi.effective_cost_date) AS year,
                       cur.code AS currency,
                       SUM($cost) AS total,
                       COUNT(*) AS invoice_count
                  FROM purchase_invoices pi
                  JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE pi.supplier_id = ?
                   AND pi.status IN " . self::COST_STATUSES . $this->advanceCostExclude() . "
                 GROUP BY year, cur.code
                 ORDER BY year DESC, total DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        return array_map(static fn (array $r) => [
            'year'          => (int) $r['year'],
            'currency'      => (string) $r['currency'],
            'total'         => round((float) $r['total'], 2),
            'invoice_count' => (int) $r['invoice_count'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * KPI bloky: náklady per měna (letos/loni + YoY), počet přijatých YTD, nezaplacené,
     * po splatnosti, Ø doba úhrady, rozpad podle stavu.
     *
     * @return array<string, mixed>
     */
    private function kpi(\PDO $pdo, int $year, int $prevYear, int $sid, bool $isVatPayer): array
    {
        $cost = $this->costCol($isVatPayer);
        // YoY: this_year (YTD) vs prev_year_ytd (loni do stejné kalendářní pozice). prev_year = celý loňský rok.
        $sql = "SELECT cur.code AS currency,
                       SUM(CASE WHEN YEAR(pi.effective_cost_date) = ?
                                 THEN $cost ELSE 0 END) AS this_year,
                       SUM(CASE WHEN YEAR(pi.effective_cost_date) = ?
                                 THEN $cost ELSE 0 END) AS prev_year,
                       SUM(CASE WHEN YEAR(pi.effective_cost_date) = ?
                                  AND pi.effective_cost_date <= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
                                 THEN $cost ELSE 0 END) AS prev_year_ytd,
                       SUM(CASE WHEN YEAR(pi.effective_cost_date) = ?
                                 THEN 1 ELSE 0 END) AS this_year_invoice_count,
                       SUM(CASE WHEN YEAR(pi.effective_cost_date) = ?
                                 THEN 1 ELSE 0 END) AS prev_year_invoice_count,
                       COUNT(DISTINCT CASE WHEN YEAR(pi.effective_cost_date) = ?
                                            THEN pi.vendor_id END) AS this_year_vendor_count,
                       COUNT(DISTINCT CASE WHEN YEAR(pi.effective_cost_date) = ?
                                            THEN pi.vendor_id END) AS prev_year_vendor_count
                  FROM purchase_invoices pi
                  JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE pi.supplier_id = ?
                   -- prevYear a year po sobě jdoucí → půlotevřený rozsah = YEAR IN (year, prevYear)
                   AND pi.effective_cost_date >= ? AND pi.effective_cost_date < ?
                   AND pi.status IN " . self::COST_STATUSES . $this->advanceCostExclude() . "
                 GROUP BY cur.code";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $year, $prevYear, $prevYear,
            $year, $prevYear,
            $year, $prevYear,
            $sid, sprintf('%04d-01-01', $prevYear), sprintf('%04d-01-01', $year + 1),
        ]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $perCurrency = [];
        foreach ($rows as $r) {
            $thisYear = (float) $r['this_year'];
            $prevYearTotal = (float) $r['prev_year'];
            $prevYearYtd = (float) $r['prev_year_ytd'];
            $changePct = null;
            if ($prevYearYtd > 0) {
                $changePct = round((($thisYear - $prevYearYtd) / $prevYearYtd) * 100, 1);
            }
            $perCurrency[(string) $r['currency']] = [
                'currency'                 => (string) $r['currency'],
                'this_year'                => round($thisYear, 2),
                'prev_year'                => round($prevYearTotal, 2),
                'prev_year_ytd'            => round($prevYearYtd, 2),
                'change_pct'               => $changePct,
                'this_year_invoice_count'  => (int) $r['this_year_invoice_count'],
                'prev_year_invoice_count'  => (int) $r['prev_year_invoice_count'],
                'this_year_vendor_count'   => (int) $r['this_year_vendor_count'],
                'prev_year_vendor_count'   => (int) $r['prev_year_vendor_count'],
            ];
        }

        // Počet přijatých YTD (mimo draft/cancelled)
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM purchase_invoices
              WHERE supplier_id = ?
                AND YEAR(COALESCE(tax_date, issue_date)) = ?
                AND status IN " . self::COST_STATUSES . $this->advanceCostExclude('')
        );
        $stmt->execute([$sid, $year]);
        $purchaseCount = (int) $stmt->fetchColumn();

        // Nezaplacené závazky per měna
        $remaining = PayablePredicate::remainingExpression('pi');
        $stmt = $pdo->prepare(
            "SELECT cur.code AS currency, COUNT(*) AS cnt, SUM(GREATEST({$remaining}, 0)) AS total
               FROM purchase_invoices pi
               JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.supplier_id = ?
                AND pi.status IN " . self::UNPAID_STATUSES . $this->payableDocKindExclude()
                . PayablePredicate::excludeFullySettled() . "
              GROUP BY cur.code"
        );
        $stmt->execute([$sid]);
        $unpaid = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $unpaidPerCurrency = array_map(static fn (array $r) => [
            'currency' => (string) $r['currency'],
            'count'    => (int) $r['cnt'],
            'total'    => round((float) $r['total'], 2),
        ], $unpaid);
        $unpaidTotalCount = array_sum(array_column($unpaidPerCurrency, 'count'));

        // Po splatnosti — počet
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM purchase_invoices pi
              WHERE pi.supplier_id = ?
                AND pi.status IN " . self::UNPAID_STATUSES . $this->payableDocKindExclude()
                . PayablePredicate::excludeFullySettled() . "
                AND pi.due_date < CURDATE()"
        );
        $stmt->execute([$sid]);
        $overdueCount = (int) $stmt->fetchColumn();

        // Ø doba úhrady dodavatelům (paid_at - issue_date) pro letošní zaplacené
        $stmt = $pdo->prepare(
            "SELECT AVG(DATEDIFF(paid_at, issue_date)) FROM purchase_invoices
              WHERE supplier_id = ? AND status = 'paid' AND paid_at IS NOT NULL
                AND YEAR(COALESCE(tax_date, issue_date)) = ?"
        );
        $stmt->execute([$sid, $year]);
        $avgPaymentDays = $stmt->fetchColumn();
        $avgPaymentDays = $avgPaymentDays !== null && $avgPaymentDays !== false
            ? round((float) $avgPaymentDays, 1)
            : null;

        // Rozpad podle stavu YTD (počet)
        $stmt = $pdo->prepare(
            "SELECT status, COUNT(*) AS cnt
               FROM purchase_invoices
              WHERE supplier_id = ?
                AND YEAR(COALESCE(tax_date, issue_date)) = ?
              GROUP BY status"
        );
        $stmt->execute([$sid, $year]);
        $statusCounts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $statusCounts[(string) $r['status']] = (int) $r['cnt'];
        }

        return [
            'per_currency'        => array_values($perCurrency),
            'purchase_count_ytd'  => $purchaseCount,
            'unpaid_count'        => $unpaidTotalCount,
            'unpaid_per_currency' => $unpaidPerCurrency,
            'overdue_count'       => $overdueCount,
            'avg_payment_days'    => $avgPaymentDays,
            'status_counts_ytd'   => $statusCounts,
        ];
    }

    /** Po splatnosti — nezaplacené závazky s due_date < dnes (top 20). */
    private function overdue(\PDO $pdo, int $sid): array
    {
        $remaining = PayablePredicate::remainingExpression('pi');
        $sql = "SELECT pi.id, pi.varsymbol, pi.vendor_invoice_number, pi.document_kind, pi.vendor_id,
                       cur.code AS currency, pi.issue_date, pi.due_date,
                       GREATEST({$remaining}, 0) AS amount_to_pay, pi.status,
                       c.company_name AS vendor_company_name,
                       DATEDIFF(CURDATE(), pi.due_date) AS days_overdue
                  FROM purchase_invoices pi
                  JOIN clients c ON c.id = pi.vendor_id
                  JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE pi.supplier_id = ?
                   AND pi.status IN " . self::UNPAID_STATUSES . $this->payableDocKindExclude()
                   . PayablePredicate::excludeFullySettled() . "
                   AND pi.due_date < CURDATE()
                 ORDER BY pi.due_date ASC
                 LIMIT 20";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        return array_map(fn (array $r) => $this->castListItem($r), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** Před splatností — nezaplacené závazky s due_date >= dnes (top 20). */
    private function unpaidUpcoming(\PDO $pdo, int $sid): array
    {
        $remaining = PayablePredicate::remainingExpression('pi');
        $sql = "SELECT pi.id, pi.varsymbol, pi.vendor_invoice_number, pi.document_kind, pi.vendor_id,
                       cur.code AS currency, pi.issue_date, pi.due_date,
                       GREATEST({$remaining}, 0) AS amount_to_pay, pi.status,
                       c.company_name AS vendor_company_name
                  FROM purchase_invoices pi
                  JOIN clients c ON c.id = pi.vendor_id
                  JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE pi.supplier_id = ?
                   AND pi.status IN " . self::UNPAID_STATUSES . $this->payableDocKindExclude()
                   . PayablePredicate::excludeFullySettled() . "
                   AND pi.due_date >= CURDATE()
                 ORDER BY pi.due_date ASC
                 LIMIT 20";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        return array_map(fn (array $r) => $this->castListItem($r), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Top dodavatelé za daný rok — náklady přepočtené na CZK (pi.exchange_rate), grupováno
     * po dodavateli (multi-currency vendor se neroztrhne, ranking je správný napříč měnami).
     */
    private function topVendors(\PDO $pdo, int $year, int $sid, bool $isVatPayer): array
    {
        $cost = $this->costCol($isVatPayer);
        $costCzk = "$cost * COALESCE(IF(cur.code = 'CZK', 1, pi.exchange_rate), 1)";
        $sql = "SELECT c.id, c.company_name,
                       SUM($costCzk) AS total_czk,
                       GROUP_CONCAT(DISTINCT cur.code ORDER BY cur.code SEPARATOR ',') AS currencies,
                       COUNT(*) AS invoice_count
                  FROM purchase_invoices pi
                  JOIN clients c ON c.id = pi.vendor_id
                  JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE pi.supplier_id = ?
                   AND pi.effective_cost_date >= ? AND pi.effective_cost_date < ?
                   AND pi.status IN " . self::COST_STATUSES . $this->advanceCostExclude() . "
                 GROUP BY c.id, c.company_name
                 ORDER BY total_czk DESC
                 LIMIT 12";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid, sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)]);
        return array_map(static fn (array $r) => [
            'vendor_id'     => (int) $r['id'],
            'company_name'  => (string) $r['company_name'],
            'currencies'    => (string) $r['currencies'],
            'total_czk'     => round((float) $r['total_czk'], 2),
            'invoice_count' => (int) $r['invoice_count'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /** Top dodavatelé za rolling 12 měsíců — robustní vůči začátku roku. */
    private function topVendorsRolling12m(\PDO $pdo, int $sid, bool $isVatPayer): array
    {
        $cost = $this->costCol($isVatPayer);
        $costCzk = "$cost * COALESCE(IF(cur.code = 'CZK', 1, pi.exchange_rate), 1)";
        $sql = "SELECT c.id, c.company_name,
                       SUM($costCzk) AS total_czk,
                       GROUP_CONCAT(DISTINCT cur.code ORDER BY cur.code SEPARATOR ',') AS currencies,
                       COUNT(*) AS invoice_count
                  FROM purchase_invoices pi
                  JOIN clients c ON c.id = pi.vendor_id
                  JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE pi.supplier_id = ?
                   AND pi.effective_cost_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                   AND pi.status IN " . self::COST_STATUSES . $this->advanceCostExclude() . "
                 GROUP BY c.id, c.company_name
                 ORDER BY total_czk DESC
                 LIMIT 12";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        return array_map(static fn (array $r) => [
            'vendor_id'     => (int) $r['id'],
            'company_name'  => (string) $r['company_name'],
            'currencies'    => (string) $r['currencies'],
            'total_czk'     => round((float) $r['total_czk'], 2),
            'invoice_count' => (int) $r['invoice_count'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Náklady po měsících (rolling 12m + porovnávací řada −1 rok), per měna.
     * @return list<array{currency: string, months: list<array{ym: string, total: float}>, prev_year: list<array{ym: string, total: float}>}>
     */
    private function costsByMonth(\PDO $pdo, int $sid, bool $isVatPayer): array
    {
        $cost = $this->costCol($isVatPayer);
        $sql = "SELECT cur.code AS currency,
                       DATE_FORMAT(pi.effective_cost_date, '%Y-%m') AS ym,
                       SUM($cost) AS total
                  FROM purchase_invoices pi
                  JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE pi.supplier_id = ?
                   AND pi.effective_cost_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 23 MONTH), '%Y-%m-01')
                   AND pi.status IN " . self::COST_STATUSES . $this->advanceCostExclude() . "
                 GROUP BY cur.code, ym";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        return $this->bucketize12m($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Kumulativní cash-outflow per měsíc (skutečně zaplaceno dodavatelům), letos + loni.
     * Bere `paid_at`, total_with_vat (reálná zaplacená částka).
     */
    private function cashflowOutYtd(\PDO $pdo, int $year, int $prevYear, int $sid): array
    {
        $sql = "SELECT cur.code AS currency,
                       DATE_FORMAT(pi.paid_at, '%Y-%m') AS ym,
                       SUM(pi.total_with_vat) AS total
                  FROM purchase_invoices pi
                  JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE pi.supplier_id = ?
                   AND pi.status = 'paid'
                   AND pi.paid_at IS NOT NULL
                   -- prevYear a year po sobě jdoucí → půlotevřený rozsah = YEAR IN (year, prevYear)
                   AND pi.paid_at >= ? AND pi.paid_at < ?
                 GROUP BY cur.code, ym";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid, sprintf('%04d-01-01', $prevYear), sprintf('%04d-01-01', $year + 1)]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $thisSlots = [];
        $prevSlots = [];
        for ($m = 1; $m <= 12; $m++) {
            $thisSlots[sprintf('%04d-%02d', $year, $m)] = 0.0;
            $prevSlots[sprintf('%04d-%02d', $prevYear, $m)] = 0.0;
        }
        $perCurrency = [];
        foreach ($rows as $r) {
            $cur = (string) $r['currency'];
            $ym = (string) $r['ym'];
            $total = round((float) $r['total'], 2);
            if (!isset($perCurrency[$cur])) {
                $perCurrency[$cur] = ['months' => $thisSlots, 'prev_year' => $prevSlots];
            }
            if (array_key_exists($ym, $perCurrency[$cur]['months'])) {
                $perCurrency[$cur]['months'][$ym] = $total;
            } elseif (array_key_exists($ym, $perCurrency[$cur]['prev_year'])) {
                $perCurrency[$cur]['prev_year'][$ym] = $total;
            }
        }
        $toList = static fn (array $slots): array => array_map(
            static fn ($ym, $t) => ['ym' => $ym, 'total' => $t],
            array_keys($slots),
            $slots
        );
        $out = [];
        foreach ($perCurrency as $cur => $data) {
            $out[] = ['currency' => $cur, 'months' => $toList($data['months']), 'prev_year' => $toList($data['prev_year'])];
        }
        return $out;
    }

    /**
     * Pomocná funkce — z řádků {currency, ym, total} sestaví rolling 12m okno
     * (months = aktuálních 12 měsíců, prev_year = stejných 12 měsíců o rok dříve).
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array{currency: string, months: list<array{ym: string, total: float}>, prev_year: list<array{ym: string, total: float}>}>
     */
    private function bucketize12m(array $rows): array
    {
        $monthsSlots = [];
        $prevSlots = [];
        $cursor = new \DateTimeImmutable(date('Y-m-01'));
        $cursorThis = $cursor->modify('-11 months');
        $cursorPrev = $cursor->modify('-23 months');
        for ($i = 0; $i < 12; $i++) {
            $monthsSlots[$cursorThis->format('Y-m')] = 0.0;
            $prevSlots[$cursorPrev->format('Y-m')]   = 0.0;
            $cursorThis = $cursorThis->modify('+1 month');
            $cursorPrev = $cursorPrev->modify('+1 month');
        }

        $perCurrency = [];
        foreach ($rows as $r) {
            $cur = (string) $r['currency'];
            $ym = (string) $r['ym'];
            $total = round((float) $r['total'], 2);
            if (!isset($perCurrency[$cur])) {
                $perCurrency[$cur] = ['months' => $monthsSlots, 'prev_year' => $prevSlots];
            }
            if (array_key_exists($ym, $perCurrency[$cur]['months'])) {
                $perCurrency[$cur]['months'][$ym] = $total;
            } elseif (array_key_exists($ym, $perCurrency[$cur]['prev_year'])) {
                $perCurrency[$cur]['prev_year'][$ym] = $total;
            }
        }
        $toList = static fn (array $slots): array => array_map(
            static fn ($ym, $t) => ['ym' => $ym, 'total' => $t],
            array_keys($slots),
            $slots
        );
        $out = [];
        foreach ($perCurrency as $cur => $data) {
            $out[] = ['currency' => $cur, 'months' => $toList($data['months']), 'prev_year' => $toList($data['prev_year'])];
        }
        return $out;
    }

    /**
     * Plovoucí 12měsíční náklady (rolling) + tentýž součet o 12 měsíců dříve (YoY). Per měna.
     * @return list<array{currency: string, total: float, prev_period_total: float}>
     */
    private function rolling12mCosts(\PDO $pdo, int $sid, bool $isVatPayer): array
    {
        $cost = $this->costCol($isVatPayer);
        $sql = "SELECT cur.code AS currency,
                       SUM(CASE WHEN pi.effective_cost_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                                 THEN $cost ELSE 0 END) AS total_12m,
                       SUM(CASE WHEN pi.effective_cost_date >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
                                  AND pi.effective_cost_date <  DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                                 THEN $cost ELSE 0 END) AS total_prev_12m
                  FROM purchase_invoices pi
                  JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE pi.supplier_id = ?
                   AND pi.effective_cost_date >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
                   AND pi.status IN " . self::COST_STATUSES . $this->advanceCostExclude() . "
                 GROUP BY cur.code";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        return array_map(static fn (array $r) => [
            'currency'          => (string) $r['currency'],
            'total'             => round((float) $r['total_12m'], 2),
            'prev_period_total' => round((float) $r['total_prev_12m'], 2),
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Histogram doby úhrady dodavatelům — kolik faktur jsme zaplatili v jakém okně po vystavení.
     * Okno = posledních 12 měsíců dle paid_at.
     */
    private function paymentDaysHistogram(\PDO $pdo, int $sid): array
    {
        $sql = "SELECT DATEDIFF(paid_at, issue_date) AS days
                  FROM purchase_invoices
                 WHERE supplier_id = ?
                   AND status = 'paid'
                   AND paid_at IS NOT NULL
                   AND paid_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        $days = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $buckets = [
            '0-7'   => ['key' => '0-7',   'label' => '0–7 dní',  'count' => 0],
            '8-14'  => ['key' => '8-14',  'label' => '8–14 dní', 'count' => 0],
            '15-30' => ['key' => '15-30', 'label' => '15–30 dní', 'count' => 0],
            '30+'   => ['key' => '30+',   'label' => '30+ dní',   'count' => 0],
        ];
        $sum = 0;
        foreach ($days as $d) {
            $d = (int) $d;
            $sum += max(0, $d);
            if ($d <= 7)       $buckets['0-7']['count']++;
            elseif ($d <= 14)  $buckets['8-14']['count']++;
            elseif ($d <= 30)  $buckets['15-30']['count']++;
            else               $buckets['30+']['count']++;
        }
        $total = count($days);
        return [
            'buckets'  => array_values($buckets),
            'total'    => $total,
            'avg_days' => $total > 0 ? round($sum / $total, 1) : null,
        ];
    }

    /**
     * Rozpad nároku na odpočet DPH na vstupu (base bez DPH) podle sazby — posledních 12 měsíců,
     * jen plátce DPH. Odpovídá Knize DPH / DPHDP3 ř. 40/41:
     *   • faktury bez nároku (`vat_deduction = 'none'`) se vyřazují,
     *   • poměrný odpočet (`proportional`, § 75) se krátí na `vat_deduction_percent`,
     *   • reverse-charge řádky se vykazují odděleně,
     *   • zálohové faktury (advance) se vyřazují úplně — nejsou daňový doklad, nárok
     *     na odpočet nese až vyúčtovací faktura (konzistence s VatLedgerService),
     *   • DDKP (tax_document) se naopak ZAHRNUJE: daňovým dokladem k poskytnuté záloze
     *     (§ 28 ZDPH) nárok na odpočet vzniká a VatLedgerService ho do evidence pouští
     *     (vylučuje jen 'advance'). Dokud se vyřazoval, widget podhodnocoval základ
     *     o celé DDKP a nesedělo mu to na ř. 40/41 přiznání.
     *
     * POZN.: tenhle rozpad si staví vlastní SQL místo VatLedgerService, což je proti
     * AGENTS.md:73 („veškerá evidence DPH jde přes VatLedgerService"). Filtry se proto
     * musí ručně držet v syncu — sjednocení na službu je samostatný úkol.
     *
     * @return list<array{label: string, base: float, currency: string}>
     */
    private function vatInputBreakdown12m(\PDO $pdo, int $sid): array
    {
        $sql = "SELECT cur.code AS currency,
                       CASE WHEN pi.reverse_charge = 1 THEN 'RC' ELSE CAST(pii.vat_rate_snapshot AS CHAR) END AS rate_label,
                       SUM(pii.total_without_vat
                           * IF(pi.vat_deduction = 'proportional', COALESCE(pi.vat_deduction_percent, 100) / 100, 1)) AS base
                  FROM purchase_invoice_items pii
                  JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
                  JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE pi.supplier_id = ?
                   AND pi.status IN " . self::COST_STATUSES . "
                   AND COALESCE(pi.document_kind, '') <> 'advance'
                   AND pi.vat_deduction <> 'none'
                   AND pi.effective_cost_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                 GROUP BY cur.code, rate_label
                 ORDER BY cur.code, base DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return array_map(static function (array $r): array {
            $label = $r['rate_label'] === 'RC'
                ? 'RC (reverse charge)'
                : (rtrim(rtrim((string) $r['rate_label'], '0'), '.')) . ' %';
            return [
                'label'    => $label,
                'base'     => round((float) $r['base'], 2),
                'currency' => (string) $r['currency'],
            ];
        }, $rows);
    }

    /**
     * Rozpad nákladů po kategoriích (12m) přepočtený na CZK — pro koláč/tabulku.
     * @return list<array{category_id: ?int, code: ?string, label: ?string, total_czk: float, count: int, percent: float}>
     */
    private function expenseBreakdown12m(\PDO $pdo, int $sid, bool $isVatPayer): array
    {
        $cost = $this->costCol($isVatPayer);
        $costCzk = "$cost * COALESCE(IF(cur.code = 'CZK', 1, pi.exchange_rate), 1)";
        $sql = "SELECT pi.expense_category_id, ec.code, ec.label,
                       SUM($costCzk) AS total,
                       COUNT(*) AS cnt
                  FROM purchase_invoices pi
             LEFT JOIN expense_categories ec ON ec.id = pi.expense_category_id
                  JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE pi.supplier_id = ?
                   AND pi.effective_cost_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                   AND pi.status IN " . self::COST_STATUSES . $this->advanceCostExclude() . "
                 GROUP BY pi.expense_category_id, ec.code, ec.label
                 ORDER BY total DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $sum = array_sum(array_map(static fn ($r) => (float) $r['total'], $rows));
        return array_map(static fn (array $r) => [
            'category_id' => $r['expense_category_id'] !== null ? (int) $r['expense_category_id'] : null,
            'code'        => $r['code'] !== null ? (string) $r['code'] : null,
            'label'       => $r['label'] !== null ? (string) $r['label'] : null,
            'total_czk'   => round((float) $r['total'], 2),
            'count'       => (int) $r['cnt'],
            'percent'     => $sum > 0 ? round(((float) $r['total'] / $sum) * 100, 1) : 0.0,
        ], $rows);
    }

    /**
     * Cash-outflow forecast — kolik je třeba uhradit dodavatelům v příštích 30/60/90 dnech
     * z nezaplacených závazků (due_date v daném okně). Per měna.
     */
    private function cashflowOutForecast(\PDO $pdo, int $sid): array
    {
        $remaining = PayablePredicate::remainingExpression('pi');
        $sql = "SELECT cur.code AS currency,
                       SUM(CASE WHEN pi.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN GREATEST({$remaining}, 0) ELSE 0 END) AS out_30,
                       SUM(CASE WHEN pi.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY) THEN GREATEST({$remaining}, 0) ELSE 0 END) AS out_60,
                       SUM(CASE WHEN pi.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN GREATEST({$remaining}, 0) ELSE 0 END) AS out_90,
                       SUM(CASE WHEN pi.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS count_30,
                       SUM(CASE WHEN pi.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY) THEN 1 ELSE 0 END) AS count_60,
                       SUM(CASE WHEN pi.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN 1 ELSE 0 END) AS count_90
                  FROM purchase_invoices pi
                  JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE pi.supplier_id = ?
                   AND pi.status IN " . self::UNPAID_STATUSES . $this->payableDocKindExclude()
                   . PayablePredicate::excludeFullySettled() . "
                   AND pi.due_date >= CURDATE()
                 GROUP BY cur.code";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        return array_map(static fn (array $r) => [
            'currency' => (string) $r['currency'],
            'out_30'   => round((float) $r['out_30'], 2),
            'out_60'   => round((float) $r['out_60'], 2),
            'out_90'   => round((float) $r['out_90'], 2),
            'count_30' => (int) $r['count_30'],
            'count_60' => (int) $r['count_60'],
            'count_90' => (int) $r['count_90'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Splatnost bucket — kolik závazků je splatných dnes / tento týden / tento měsíc (kumulativně).
     */
    private function dueBuckets(\PDO $pdo, int $sid): array
    {
        $remaining = PayablePredicate::remainingExpression('pi');
        $sql = "SELECT cur.code AS currency,
                       SUM(CASE WHEN pi.due_date = CURDATE() THEN 1 ELSE 0 END) AS today_count,
                       SUM(CASE WHEN pi.due_date = CURDATE() THEN GREATEST({$remaining}, 0) ELSE 0 END) AS today_total,
                       SUM(CASE WHEN pi.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL (6 - WEEKDAY(CURDATE())) DAY) THEN 1 ELSE 0 END) AS week_count,
                       SUM(CASE WHEN pi.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL (6 - WEEKDAY(CURDATE())) DAY) THEN GREATEST({$remaining}, 0) ELSE 0 END) AS week_total,
                       SUM(CASE WHEN pi.due_date BETWEEN CURDATE() AND LAST_DAY(CURDATE()) THEN 1 ELSE 0 END) AS month_count,
                       SUM(CASE WHEN pi.due_date BETWEEN CURDATE() AND LAST_DAY(CURDATE()) THEN GREATEST({$remaining}, 0) ELSE 0 END) AS month_total
                  FROM purchase_invoices pi
                  JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE pi.supplier_id = ?
                   AND pi.status IN " . self::UNPAID_STATUSES . $this->payableDocKindExclude()
                   . PayablePredicate::excludeFullySettled() . "
                   AND pi.due_date >= CURDATE()
                 GROUP BY cur.code";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        return array_map(static fn (array $r) => [
            'currency'     => (string) $r['currency'],
            'today_count'  => (int) $r['today_count'],
            'today_total'  => round((float) $r['today_total'], 2),
            'week_count'   => (int) $r['week_count'],
            'week_total'   => round((float) $r['week_total'], 2),
            'month_count'  => (int) $r['month_count'],
            'month_total'  => round((float) $r['month_total'], 2),
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Aging report — stáří závazků (nezaplacených přijatých faktur) dle dní po splatnosti.
     */
    private function agingReport(\PDO $pdo, int $sid): array
    {
        $remaining = PayablePredicate::remainingExpression('pi');
        $sql = "SELECT cur.code AS currency,
                       SUM(CASE WHEN pi.due_date >= CURDATE() THEN GREATEST({$remaining}, 0) ELSE 0 END) AS current_amt,
                       SUM(CASE WHEN pi.due_date < CURDATE() AND DATEDIFF(CURDATE(), pi.due_date) BETWEEN 1 AND 30 THEN GREATEST({$remaining}, 0) ELSE 0 END) AS b1_30,
                       SUM(CASE WHEN pi.due_date < CURDATE() AND DATEDIFF(CURDATE(), pi.due_date) BETWEEN 31 AND 60 THEN GREATEST({$remaining}, 0) ELSE 0 END) AS b31_60,
                       SUM(CASE WHEN pi.due_date < CURDATE() AND DATEDIFF(CURDATE(), pi.due_date) BETWEEN 61 AND 90 THEN GREATEST({$remaining}, 0) ELSE 0 END) AS b61_90,
                       SUM(CASE WHEN pi.due_date < CURDATE() AND DATEDIFF(CURDATE(), pi.due_date) > 90 THEN GREATEST({$remaining}, 0) ELSE 0 END) AS b90_plus,
                       SUM(CASE WHEN pi.due_date >= CURDATE() THEN 1 ELSE 0 END) AS current_n,
                       SUM(CASE WHEN pi.due_date < CURDATE() AND DATEDIFF(CURDATE(), pi.due_date) BETWEEN 1 AND 30 THEN 1 ELSE 0 END) AS b1_30_n,
                       SUM(CASE WHEN pi.due_date < CURDATE() AND DATEDIFF(CURDATE(), pi.due_date) BETWEEN 31 AND 60 THEN 1 ELSE 0 END) AS b31_60_n,
                       SUM(CASE WHEN pi.due_date < CURDATE() AND DATEDIFF(CURDATE(), pi.due_date) BETWEEN 61 AND 90 THEN 1 ELSE 0 END) AS b61_90_n,
                       SUM(CASE WHEN pi.due_date < CURDATE() AND DATEDIFF(CURDATE(), pi.due_date) > 90 THEN 1 ELSE 0 END) AS b90_plus_n
                  FROM purchase_invoices pi
                  JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE pi.supplier_id = ?
                   AND pi.status IN " . self::UNPAID_STATUSES . $this->payableDocKindExclude()
                   . PayablePredicate::excludeFullySettled() . "
                 GROUP BY cur.code";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        return array_map(static fn (array $r) => [
            'currency'  => (string) $r['currency'],
            'current'   => round((float) $r['current_amt'], 2),
            'b1_30'     => round((float) $r['b1_30'], 2),
            'b31_60'    => round((float) $r['b31_60'], 2),
            'b61_90'    => round((float) $r['b61_90'], 2),
            'b90_plus'  => round((float) $r['b90_plus'], 2),
            'current_n' => (int) $r['current_n'],
            'b1_30_n'   => (int) $r['b1_30_n'],
            'b31_60_n'  => (int) $r['b31_60_n'],
            'b61_90_n'  => (int) $r['b61_90_n'],
            'b90_plus_n'=> (int) $r['b90_plus_n'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Forecast ročních nákladů — growth-adjusted seasonality (stejná logika jako u obratu).
     */
    private function costsForecast(\PDO $pdo, int $year, int $prevYear, int $sid, bool $isVatPayer): array
    {
        $cost = $this->costCol($isVatPayer);
        $sql = "SELECT cur.code AS currency,
                       SUM(CASE WHEN YEAR(pi.effective_cost_date) = ?
                                 THEN $cost ELSE 0 END) AS ytd,
                       SUM(CASE WHEN YEAR(pi.effective_cost_date) = ?
                                  AND pi.effective_cost_date <= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
                                 THEN $cost ELSE 0 END) AS prev_year_ytd,
                       SUM(CASE WHEN YEAR(pi.effective_cost_date) = ?
                                  AND pi.effective_cost_date > DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
                                 THEN $cost ELSE 0 END) AS prev_year_remainder,
                       SUM(CASE WHEN YEAR(pi.effective_cost_date) = ?
                                 THEN $cost ELSE 0 END) AS prev_year_full
                  FROM purchase_invoices pi
                  JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE pi.supplier_id = ?
                   -- prevYear a year po sobě jdoucí → půlotevřený rozsah = YEAR IN (year, prevYear)
                   AND pi.effective_cost_date >= ? AND pi.effective_cost_date < ?
                   AND pi.status IN " . self::COST_STATUSES . $this->advanceCostExclude() . "
                 GROUP BY cur.code";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$year, $prevYear, $prevYear, $prevYear, $sid, sprintf('%04d-01-01', $prevYear), sprintf('%04d-01-01', $year + 1)]);
        return array_map(static function (array $r): array {
            $ytd = round((float) $r['ytd'], 2);
            $ytdPrev = round((float) $r['prev_year_ytd'], 2);
            $rem = round((float) $r['prev_year_remainder'], 2);
            $growth = ($ytdPrev > 0) ? max(0.3, min(3.0, $ytd / $ytdPrev)) : 1.0;
            $forecast = round($ytd + ($rem * $growth), 2);
            return [
                'currency'            => (string) $r['currency'],
                'ytd'                 => $ytd,
                'prev_year_ytd'       => $ytdPrev,
                'prev_year_remainder' => $rem,
                'growth_ratio'        => round($growth, 3),
                'forecast'            => $forecast,
                'prev_year_full'      => round((float) $r['prev_year_full'], 2),
            ];
        }, $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Histogram velikosti přijatých faktur (12m) — buckety v CZK ekvivalentu.
     */
    private function invoiceSizeHistogram(\PDO $pdo, int $sid, bool $isVatPayer): array
    {
        $cost = $isVatPayer ? 'total_without_vat' : 'total_with_vat';
        // CZK pojistka jako ve zbytku kódu: bez ní by se korunový doklad se zbloudilým
        // `exchange_rate` vynásobil kurzem. Ostatních ~45 kurzových výrazů ji má.
        $sql = "SELECT $cost * COALESCE(IF(cur.code = 'CZK', 1, pi.exchange_rate), 1) AS size_czk
                  FROM purchase_invoices pi
             LEFT JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE pi.supplier_id = ?
                   AND pi.status IN " . self::COST_STATUSES . $this->advanceCostExclude() . "
                   AND pi.effective_cost_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid]);
        $sizes = array_map(static fn ($v) => (float) $v, $stmt->fetchAll(\PDO::FETCH_COLUMN));

        $buckets = [
            '0-5'    => ['key' => '0-5',   'label' => '0–5 k', 'count' => 0, 'total_czk' => 0.0],
            '5-25'   => ['key' => '5-25',  'label' => '5–25 k', 'count' => 0, 'total_czk' => 0.0],
            '25-100' => ['key' => '25-100','label' => '25–100 k', 'count' => 0, 'total_czk' => 0.0],
            '100+'   => ['key' => '100+',  'label' => '100 k+', 'count' => 0, 'total_czk' => 0.0],
        ];
        foreach ($sizes as $s) {
            $abs = abs($s);
            if      ($abs <  5000)   $key = '0-5';
            elseif  ($abs <  25000)  $key = '5-25';
            elseif  ($abs <  100000) $key = '25-100';
            else                     $key = '100+';
            $buckets[$key]['count']++;
            $buckets[$key]['total_czk'] += $s;
        }
        foreach ($buckets as &$b) { $b['total_czk'] = round($b['total_czk'], 2); }
        unset($b);

        return ['buckets' => array_values($buckets), 'total' => count($sizes)];
    }

    /** @param array<string,mixed> $r */
    private function castListItem(array $r): array
    {
        return [
            'id'                  => (int) $r['id'],
            'varsymbol'           => $r['varsymbol'],
            'vendor_invoice_number' => $r['vendor_invoice_number'] ?? null,
            'document_kind'       => $r['document_kind'] ?? 'invoice',
            'vendor_id'           => (int) $r['vendor_id'],
            'vendor_company_name' => $r['vendor_company_name'],
            'currency'            => $r['currency'],
            'issue_date'          => $r['issue_date'],
            'due_date'            => $r['due_date'],
            'amount_to_pay'       => (float) $r['amount_to_pay'],
            'status'              => $r['status'],
            'days_overdue'        => isset($r['days_overdue']) ? (int) $r['days_overdue'] : null,
        ];
    }
}
