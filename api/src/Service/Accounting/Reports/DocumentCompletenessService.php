<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\SaldoRepository;
use PDO;

/**
 * Featura E (REAL_data_followup_UX.md) — READ-ONLY kontrola úplnosti dokladů proti bance.
 *
 * Dva směry, obojí jen agregace nad existujícími stavy (nic nezapisuje):
 *
 *  1) bank_without_document — bankovní pohyby (source='statement'), které po prahu X dní
 *     nemají navázaný doklad: nespárované, bez matched_invoice_id, bez invoice_payments /
 *     payment_matches a bez živého bankovního zápisu v deníku. Daňová pojistka §24/1 ZDP
 *     (náklad bez dokladu není uznatelný). Scope na firmu přes shodu čísla účtu s `currencies`
 *     (bank_statements nenese supplier_id) — stejný predikát jako
 *     {@see \MyInvoice\Repository\BankPostingSuggestionRepository::unpostedWithoutSuggestion()}.
 *     Zůstatek/„je zaúčtováno" se testuje živostí zápisu (`reversed_by IS NULL`), což je zde
 *     KORREKTNÍ použití (existence živého protizápisu), NE výpočet zůstatku účtu.
 *
 *  2) documents_overdue_unpaid — obrácený směr: doklad bez úhrady po splatnosti. Otevřené
 *     položky 311 (pohledávky) a 321 (závazky) k dnešnímu dni s dnem splatnosti v minulosti.
 *     Znovupoužívá auditovaný {@see SaldoRepository::openItems()} (zdroj pravdy platby dle typu
 *     dokladu, časově uvědomělé storno) — žádná nová logika zůstatků, jen filtr po splatnosti.
 */
final class DocumentCompletenessService
{
    /** Prahové hranice stáří pro aging (dny). */
    private const BUCKETS = ['d0_30' => 30, 'd31_60' => 60, 'd61_90' => 90, 'd91_180' => 180];

    /**
     * Strop otevřených položek načtených z jednoho saldokontního účtu.
     *
     * Na rozdíl od saldokonta se tady nad stropem NEODMÍTÁ, ale zkracuje s příznakem
     * `truncated`. Důvod je v povaze obou sestav: saldokonto je inventarizační podklad,
     * kde useknutý seznam rozbije konfrontaci se zůstatkem hlavní knihy, kdežto tohle je
     * pracovní upozorňovací seznam „doklad po splatnosti bez úhrady" řazený od nejstarších.
     * Prvních pár tisíc nejstarších je přesně to, co má účetní řešit; odmítnout jí celou
     * kontrolu úplnosti kvůli počtu je horší než jí ukázat začátek a říct, že pokračuje.
     *
     * Bez stropu si tahle sestava brala saldo DVAKRÁT (311 + 321) a nad ~200 tis. doklady
     * spadla na `memory_limit` stejně jako saldokonto.
     */
    private const MAX_OPEN_ITEMS_PER_ACCOUNT = 5000;

    public function __construct(
        private readonly Connection $db,
        private readonly SaldoRepository $saldo,
    ) {}

    /**
     * @param string $direction 'outgoing'|'incoming'|'all'
     * @return array<string,mixed>
     */
    public function build(int $supplierId, int $thresholdDays, string $direction = 'all', ?\DateTimeImmutable $now = null): array
    {
        $now = $now ?? new \DateTimeImmutable('today');
        $today = $now->format('Y-m-d');
        $cutoff = $now->modify('-' . max(0, $thresholdDays) . ' day')->format('Y-m-d');

        return [
            'generated_at'   => $now->format(\DateTimeInterface::ATOM),
            'threshold_days' => max(0, $thresholdDays),
            'direction'      => $direction,
            'bank_without_document'    => $this->bankWithoutDocument($supplierId, $cutoff, $direction, $today),
            'documents_overdue_unpaid' => $this->documentsOverdueUnpaid($supplierId, $today),
        ];
    }

    /**
     * @return array{items:list<array<string,mixed>>, summary:array<string,mixed>}
     */
    private function bankWithoutDocument(int $supplierId, string $cutoff, string $direction, string $today): array
    {
        $directionSql = match ($direction) {
            'outgoing' => ' AND bt.amount < 0',
            'incoming' => ' AND bt.amount > 0',
            default    => '',
        };

        $sql =
            "SELECT bt.id, bt.statement_id, bt.posted_at, bt.amount,
                    COALESCE(bt.currency, bs.currency, 'CZK') AS currency,
                    bt.counterparty_name, bt.counterparty_account, bt.variable_symbol, bt.description,
                    EXISTS(
                        SELECT 1 FROM document_requests dr
                         WHERE dr.supplier_id = ? AND dr.bank_transaction_id = bt.id AND dr.status = 'requested'
                    ) AS document_requested
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.source = 'statement'
                AND bt.match_status = 'unmatched'
                AND bt.matched_invoice_id IS NULL
                AND DATE(bt.posted_at) <= ?
                {$directionSql}
                AND " . \MyInvoice\Repository\BankStatementOwnershipResolver::sql() . "
                AND NOT EXISTS (SELECT 1 FROM invoice_payments ip WHERE ip.supplier_id = ? AND ip.bank_transaction_id = bt.id)
                AND NOT EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.supplier_id = ? AND pm.bank_transaction_id = bt.id)
                AND NOT EXISTS (
                    SELECT 1 FROM journal_entries je
                     WHERE je.supplier_id = ? AND je.source_type = 'bank'
                       AND je.source_id = bt.id AND je.reversed_by IS NULL
                )
              ORDER BY bt.posted_at ASC, bt.id ASC";
        $stmt = $this->db->pdo()->prepare($sql);
        // SEC-01: pořadí je dr.supplier_id, cutoff, resolver (2×), ip, pm, je.
        $stmt->execute(array_merge(
            [$supplierId, $cutoff],
            \MyInvoice\Repository\BankStatementOwnershipResolver::params($supplierId),
            [$supplierId, $supplierId, $supplierId],
        ));

        $todayDt = new \DateTimeImmutable($today);
        $items = [];
        $bucketCounts = array_fill_keys([...array_keys(self::BUCKETS), 'd180_plus'], 0);
        $bucketSums = $bucketCounts;
        $totalCzk = 0.0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $postedDate = substr((string) $r['posted_at'], 0, 10);
            $days = (int) $todayDt->diff(new \DateTimeImmutable($postedDate))->days;
            $bucket = $this->bucketFor($days);
            $amount = (float) $r['amount'];
            $currency = (string) $r['currency'];
            $bucketCounts[$bucket]++;
            if ($currency === 'CZK') {
                $bucketSums[$bucket] = round($bucketSums[$bucket] + $amount, 2);
                $totalCzk = round($totalCzk + $amount, 2);
            }
            $items[] = [
                'bank_transaction_id' => (int) $r['id'],
                'statement_id'        => (int) $r['statement_id'],
                'date'                => $postedDate,
                'days'                => $days,
                'bucket'              => $bucket,
                'amount'              => $amount,
                'currency'            => $currency,
                'direction'           => $amount < 0 ? 'outgoing' : 'incoming',
                'counterparty'        => $r['counterparty_name'] === null ? null : (string) $r['counterparty_name'],
                'counterparty_account' => $r['counterparty_account'] === null ? null : (string) $r['counterparty_account'],
                'variable_symbol'     => $r['variable_symbol'] === null ? null : (string) $r['variable_symbol'],
                'description'         => $r['description'] === null ? null : (string) $r['description'],
                'document_requested'  => (bool) $r['document_requested'],
            ];
        }

        $byBucket = [];
        foreach (array_keys($bucketCounts) as $key) {
            if ($bucketCounts[$key] === 0) {
                continue;
            }
            $byBucket[] = ['bucket' => $key, 'count' => $bucketCounts[$key], 'total_czk' => $bucketSums[$key]];
        }

        return [
            'items'   => $items,
            'summary' => [
                'total_count' => count($items),
                'total_czk'   => $totalCzk,
                'by_bucket'   => $byBucket,
            ],
        ];
    }

    /**
     * @return array{items:list<array<string,mixed>>, summary:array<string,mixed>}
     */
    private function documentsOverdueUnpaid(int $supplierId, string $today): array
    {
        $todayDt = new \DateTimeImmutable($today);
        $items = [];
        $totalCzk = 0.0;
        $truncated = false;
        foreach (['311', '321'] as $code) {
            $acc = $this->saldo->resolveAccount($supplierId, $code);
            if ($acc === null) {
                continue;
            }
            $normalSide = $acc['normal_side'] ?? (in_array($acc['account_type'], ['asset', 'expense'], true) ? 'debit' : 'credit');
            $open = $this->saldo->openItems(
                $supplierId,
                $acc['id'],
                $today,
                $acc['code'],
                self::MAX_OPEN_ITEMS_PER_ACCOUNT + 1,
            );
            if (count($open) > self::MAX_OPEN_ITEMS_PER_ACCOUNT) {
                $truncated = true;
                $open = array_slice($open, 0, self::MAX_OPEN_ITEMS_PER_ACCOUNT);
            }
            foreach ($open as $it) {
                // Orientace na normální stranu účtu — stejná transformace jako SaldoService::buildAccount.
                $bookedNative = $normalSide === 'debit' ? $it['booked_signed'] : -$it['booked_signed'];
                $paidNative = round($bookedNative * $it['paid_ratio'], 2);
                $remaining = round($bookedNative - $paidNative, 2);
                if ((int) round($remaining * 100.0) === 0) {
                    continue; // plně uhrazeno / netto vyrovnáno
                }
                $due = (string) $it['due_date'];
                if ($due === '' || $today <= $due) {
                    continue; // není po splatnosti
                }
                $daysOverdue = (int) (new \DateTimeImmutable($due))->diff($todayDt)->days;
                $items[] = [
                    'doc_type'      => (string) $it['doc_type'],
                    'doc_id'        => (int) $it['doc_id'],
                    'doc_no'        => (string) $it['doc_no'],
                    'account_code'  => (string) $acc['code'],
                    'partner_name'  => (string) $it['partner_name'],
                    'issue_date'    => (string) $it['issue_date'],
                    'due_date'      => $due,
                    'days_overdue'  => $daysOverdue,
                    'currency_code' => (string) $it['currency_code'],
                    'remaining_czk' => $remaining,
                ];
                $totalCzk = round($totalCzk + $remaining, 2);
            }
        }

        usort($items, static fn (array $a, array $b): int => $b['days_overdue'] <=> $a['days_overdue']);

        return [
            'items'   => $items,
            'summary' => [
                'total_count' => count($items),
                'total_czk'   => $totalCzk,
                // true = otevřených položek bylo nad strop, seznam i součet jsou jen
                // za načtenou část. Bez tohoto příznaku by zkrácený součet vypadal
                // jako úplný.
                'truncated'   => $truncated,
            ],
        ];
    }

    private function bucketFor(int $days): string
    {
        foreach (self::BUCKETS as $key => $maxDays) {
            if ($days <= $maxDays) {
                return $key;
            }
        }
        return 'd180_plus';
    }
}
