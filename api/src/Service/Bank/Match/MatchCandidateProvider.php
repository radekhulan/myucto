<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Match;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Bank\BankMessageNormalizer;
use MyInvoice\Service\Bank\FxPaymentSettlement;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;
use MyInvoice\Support\Sql\PurchaseSettledExpr;
use PDO;

final class MatchCandidateProvider
{
    private const LOCAL_CURRENCY = FxPaymentSettlement::LOCAL_CURRENCY;
    private const AMOUNT_TOLERANCE = FxPaymentSettlement::AMOUNT_TOLERANCE;
    private const DAY_WINDOW = 14;
    private const FALLBACK_DAY_WINDOW = 90;
    private const SPLIT_POOL_PER_CLIENT = 14;
    private const SPLIT_MAX_INVOICES = 6;

    public function __construct(
        private readonly Connection $db,
        private readonly CounterpartyMapService $counterpartyMap,
        private readonly SubsetSumSolver $solver,
        private readonly MatchScorer $scorer,
    ) {}

    /** @param array<string,mixed> $tx @return list<array<string,mixed>> */
    public function candidatesFor(int $supplierId, array $tx): array
    {
        if ($supplierId <= 0 || (int) ($tx['id'] ?? 0) <= 0) return [];
        $amount = abs((float) ($tx['amount'] ?? 0.0));
        if ($amount <= 0.0) return [];
        $incoming = (float) $tx['amount'] > 0.0;
        $side = $incoming ? 'incoming' : 'outgoing';
        $currency = strtoupper((string) ($tx['currency'] ?? $tx['statement_currency'] ?? self::LOCAL_CURRENCY));
        $posted = (string) ($tx['posted_at'] ?? date('Y-m-d'));
        $accountMap = $this->counterpartyMap->lookup(
            $supplierId,
            (string) ($tx['counterparty_account'] ?? ''),
            (string) ($tx['counterparty_bank'] ?? ''),
            $side,
        );
        $rows = $incoming
            ? $this->issuedPool($supplierId, $posted)
            : $this->purchasePool($supplierId, $posted);
        $base = [];
        foreach ($rows as $row) {
            $candidate = $this->singleCandidate($tx, $row, $amount, $currency, $posted, $accountMap, $incoming);
            if ($candidate !== null) $base[] = $candidate;
        }

        $this->addUniqueVsTypoSignals($base, (string) ($tx['variable_symbol'] ?? ''));
        $ranked = [];
        foreach ($base as $candidate) {
            $ranked[] = $this->finalize($candidate);
        }
        if ($incoming) {
            $ranked = array_merge($ranked, $this->splitCandidates($base, $tx, $amount, $currency, $accountMap));
        }
        usort($ranked, static function (array $a, array $b): int {
            return ((float) $b['score'] <=> (float) $a['score'])
                ?: ((int) ($a['_date_distance'] ?? 9999) <=> (int) ($b['_date_distance'] ?? 9999))
                ?: ((int) ($a['invoice_id'] ?? $a['purchase_invoice_id'] ?? 0) <=> (int) ($b['invoice_id'] ?? $b['purchase_invoice_id'] ?? 0));
        });
        $ranked = array_slice($ranked, 0, 8);
        foreach ($ranked as &$candidate) unset($candidate['_client_id'], $candidate['_remaining'], $candidate['_converted'], $candidate['_ref_digits'], $candidate['_date_distance'], $candidate['_promoted']);
        unset($candidate);
        return $ranked;
    }

    /** @return list<array<string,mixed>> */
    private function issuedPool(int $supplierId, string $posted): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 'invoice' AS candidate_type, i.id, i.client_id, i.varsymbol AS ref,
                    i.amount_to_pay, i.paid_total, i.invoice_type, i.status,
                    i.exchange_rate, i.issue_date, i.due_date, cur.code AS currency,
                    c.company_name AS party
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
               JOIN clients c ON c.id = i.client_id AND c.supplier_id = i.supplier_id
              WHERE i.supplier_id = ?
                AND i.status IN ('issued','sent','reminded')
                AND i.invoice_type IN ('invoice','proforma')
                AND (ABS(DATEDIFF(i.due_date, ?)) <= ? OR ABS(DATEDIFF(i.issue_date, ?)) <= ?)
              ORDER BY ABS(DATEDIFF(i.due_date, ?)), i.id DESC LIMIT 300"
        );
        $stmt->execute([$supplierId, $posted, self::FALLBACK_DAY_WINDOW, $posted, self::FALLBACK_DAY_WINDOW, $posted]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string,mixed>> */
    private function purchasePool(int $supplierId, string $posted): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 'purchase_invoice' AS candidate_type, p.id, p.vendor_id AS client_id,
                    COALESCE(NULLIF(p.vendor_invoice_number,''), p.varsymbol) AS ref,
                    p.amount_to_pay,
                    (" . PurchaseSettledExpr::settled('p') . ") AS paid_total,
                    p.document_kind AS invoice_type, p.status, p.exchange_rate,
                    p.issue_date, p.due_date, cur.code AS currency, c.company_name AS party
               FROM purchase_invoices p
               JOIN currencies cur ON cur.id = p.currency_id
               JOIN clients c ON c.id = p.vendor_id AND c.supplier_id = p.supplier_id
              WHERE p.supplier_id = ? AND p.status IN ('received','booked')
                AND p.document_kind IN ('invoice','advance')
                AND (ABS(DATEDIFF(p.due_date, ?)) <= ? OR ABS(DATEDIFF(p.issue_date, ?)) <= ?)
              ORDER BY ABS(DATEDIFF(p.due_date, ?)), p.id DESC LIMIT 300"
        );
        $stmt->execute([$supplierId, $posted, self::FALLBACK_DAY_WINDOW, $posted, self::FALLBACK_DAY_WINDOW, $posted]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param array<string,mixed> $tx @param array<string,mixed> $row @param array<string,mixed>|null $map */
    private function singleCandidate(array $tx, array $row, float $amount, string $txCurrency, string $posted, ?array $map, bool $incoming): ?array
    {
        $remaining = round((float) $row['amount_to_pay'] - (float) ($row['paid_total'] ?? 0.0), 2);
        if ($remaining <= 0.0) return null;
        $invoiceCurrency = strtoupper((string) $row['currency']);
        $converted = $this->toTransactionCurrency($remaining, $invoiceCurrency, (float) ($row['exchange_rate'] ?? 0.0), $txCurrency);
        if ($converted === null) return null;
        $converted = round($converted, 2);
        $fx = $invoiceCurrency !== $txCurrency;
        $tol = $fx
            ? FxPaymentSettlement::matchTolerance($converted, self::AMOUNT_TOLERANCE)
            : self::AMOUNT_TOLERANCE;
        $signals = [];
        $flags = $fx ? ['currency_mismatch'] : [];
        $vs = VariableSymbolNormalizer::forMatching((string) ($tx['variable_symbol'] ?? ''));
        $refDigits = VariableSymbolNormalizer::forMatching((string) ($row['ref'] ?? ''));
        $vsExact = $vs !== '' && $refDigits !== '' && $vs === $refDigits;
        if ($vsExact) $signals['vs_exact'] = MatchScorer::W_VS_EXACT;
        $amountExact = abs($amount - $converted) <= $tol;
        if ($amountExact) $signals['amount_remaining'] = MatchScorer::W_AMOUNT_REMAINING;
        $message = preg_replace('/\s+/', '', BankMessageNormalizer::normalizeKeepDigits(
            (string) ($tx['description'] ?? '') . ' ' . (string) ($tx['bank_ref'] ?? '')
        )) ?? '';
        $rawRefDigits = VariableSymbolNormalizer::digits((string) ($row['ref'] ?? ''));
        if (strlen($rawRefDigits) >= 4 && str_contains($message, $rawRefDigits)) {
            $signals['invoice_no_in_message'] = MatchScorer::W_INVOICE_NO_IN_MSG;
        }
        $promoted = $map !== null && $map['promoted'] && (int) $map['client_id'] === (int) $row['client_id'];
        if ($promoted) $signals['known_account'] = MatchScorer::W_KNOWN_ACCOUNT;
        if ($this->nameSimilarity((string) ($tx['counterparty_name'] ?? ''), (string) ($row['party'] ?? '')) > 0.2) {
            $signals['name_fuzzy'] = MatchScorer::W_NAME_FUZZY;
        }
        $dateDistance = $this->dayDistance($posted, (string) ($row['due_date'] ?? $row['issue_date'] ?? $posted));
        if ($dateDistance <= 5) $signals['due_proximity'] = MatchScorer::W_DUE_PROXIMITY;
        $overpayment = $amount - $converted;
        if ($overpayment > $tol && ($vsExact || $promoted)) $flags[] = 'overpayment';
        $proforma = $incoming
            ? (string) $row['invoice_type'] === 'proforma'
            : (string) $row['invoice_type'] === 'advance';
        if ($proforma) $flags[] = 'proforma';
        if ($signals === [] || (!isset($signals['amount_remaining']) && !$vsExact && !$promoted)) return null;
        $isIssued = (string) $row['candidate_type'] === 'invoice';
        return [
            'type' => $isIssued ? 'invoice' : 'purchase_invoice',
            'invoice_id' => $isIssued ? (int) $row['id'] : null,
            'invoice_ids' => null,
            'purchase_invoice_id' => $isIssued ? null : (int) $row['id'],
            'signals' => $signals,
            'flags' => array_values(array_unique($flags)),
            'fee_amount' => null,
            'overpayment_amount' => $overpayment > $tol ? round($overpayment, 2) : null,
            'display' => [
                'ref' => ($row['ref'] ?? '') !== '' ? (string) $row['ref'] : null,
                'party' => ($row['party'] ?? '') !== '' ? (string) $row['party'] : null,
                'amount' => $remaining,
                'currency' => $invoiceCurrency,
                'due_date' => $row['due_date'] !== null ? (string) $row['due_date'] : null,
                'paid' => false,
            ],
            '_client_id' => (int) $row['client_id'],
            '_remaining' => $remaining,
            '_converted' => $converted,
            '_ref_digits' => $refDigits,
            '_date_distance' => $dateDistance,
            '_promoted' => $promoted,
        ];
    }

    /** @param list<array<string,mixed>> $candidates */
    private function addUniqueVsTypoSignals(array &$candidates, string $rawVs): void
    {
        $vs = VariableSymbolNormalizer::forMatching($rawVs);
        if ($vs === '' || strlen($vs) < 4) return;
        $matches = [];
        foreach ($candidates as $index => $candidate) {
            $ref = (string) ($candidate['_ref_digits'] ?? '');
            if ($ref !== '' && !isset($candidate['signals']['vs_exact'])
                && isset($candidate['signals']['amount_remaining']) && levenshtein($vs, $ref) <= 1) {
                $matches[] = $index;
            }
        }
        if (count($matches) !== 1) return;
        $index = $matches[0];
        $candidates[$index]['signals']['vs_typo'] = MatchScorer::W_VS_LEVENSHTEIN;
        $candidates[$index]['flags'][] = 'vs_typo';
    }

    /** @param list<array<string,mixed>> $base @param array<string,mixed> $tx @param array<string,mixed>|null $map @return list<array<string,mixed>> */
    private function splitCandidates(array $base, array $tx, float $amount, string $currency, ?array $map): array
    {
        $byClient = [];
        foreach ($base as $candidate) {
            if ($candidate['type'] !== 'invoice' || in_array('currency_mismatch', $candidate['flags'], true)) continue;
            $byClient[(int) $candidate['_client_id']][] = [
                'converted' => (float) $candidate['_converted'],
                'candidate' => $candidate,
            ];
        }
        $out = [];
        foreach ($byClient as $clientId => $items) {
            $items = array_slice($items, 0, self::SPLIT_POOL_PER_CLIENT);
            $exact = $this->solver->findSubsets($items, $amount, self::AMOUNT_TOLERANCE, 2, self::SPLIT_MAX_INVOICES);
            foreach ($exact as $combo) $out[] = $this->buildSplit($combo, $tx, $amount, $currency, $map, (int) $clientId, false);
            if ($currency === self::LOCAL_CURRENCY) {
                $feeTolerance = max(self::AMOUNT_TOLERANCE, round($amount * 0.031, 2));
                $feeCombos = $this->solver->findSubsets($items, $amount, $feeTolerance, 2, self::SPLIT_MAX_INVOICES);
                foreach ($feeCombos as $combo) {
                    $sum = array_sum(array_column($combo, 'converted'));
                    $gap = round($sum - $amount, 2);
                    if ($gap > self::AMOUNT_TOLERANCE && $gap <= round($sum * 0.03, 2)) {
                        $out[] = $this->buildSplit($combo, $tx, $amount, $currency, $map, (int) $clientId, true);
                    }
                }
            }
        }
        $unique = [];
        foreach ($out as $candidate) {
            $key = implode(',', $candidate['invoice_ids']) . ':' . ($candidate['fee_amount'] === null ? 'exact' : 'fee');
            $unique[$key] = $candidate;
        }
        $candidates = array_values($unique);
        $exactCount = count(array_filter($candidates, static fn (array $candidate): bool => $candidate['fee_amount'] === null));
        if ($exactCount > 1) {
            foreach ($candidates as &$candidate) {
                if ($candidate['fee_amount'] === null) $candidate['deterministic_core'] = false;
            }
            unset($candidate);
        }
        return $candidates;
    }

    /** @param list<array{converted:float,candidate:array<string,mixed>}> $combo @param array<string,mixed> $tx @param array<string,mixed>|null $map @return array<string,mixed> */
    private function buildSplit(array $combo, array $tx, float $amount, string $currency, ?array $map, int $clientId, bool $fee): array
    {
        $ids = [];
        $signals = ['subset_sum' => MatchScorer::W_SUBSET_SUM];
        $flags = [];
        $sum = 0.0;
        $refs = [];
        $party = null;
        $due = null;
        $hasProforma = false;
        foreach ($combo as $item) {
            $candidate = $item['candidate'];
            $ids[] = (int) $candidate['invoice_id'];
            $sum += (float) $item['converted'];
            $refs[] = (string) ($candidate['display']['ref'] ?? '');
            $party ??= $candidate['display']['party'] ?? null;
            $due ??= $candidate['display']['due_date'] ?? null;
            if (isset($candidate['signals']['vs_exact'])) $signals['vs_exact'] = MatchScorer::W_VS_EXACT;
            if (isset($candidate['signals']['invoice_no_in_message'])) $signals['invoice_no_in_message'] = MatchScorer::W_INVOICE_NO_IN_MSG;
            if (isset($candidate['signals']['name_fuzzy'])) $signals['name_fuzzy'] = MatchScorer::W_NAME_FUZZY;
            if (isset($candidate['signals']['due_proximity'])) $signals['due_proximity'] = MatchScorer::W_DUE_PROXIMITY;
            if (in_array('proforma', $candidate['flags'], true)) $hasProforma = true;
        }
        if ($map !== null && $map['promoted'] && (int) $map['client_id'] === $clientId) $signals['known_account'] = MatchScorer::W_KNOWN_ACCOUNT;
        if ($fee) $flags[] = 'fee_gap';
        if ($hasProforma) $flags[] = 'proforma';
        $candidate = [
            'type' => 'split', 'invoice_id' => null, 'invoice_ids' => $ids, 'purchase_invoice_id' => null,
            'signals' => $signals, 'flags' => $flags,
            'fee_amount' => $fee ? round($sum - $amount, 2) : null, 'overpayment_amount' => null,
            'display' => ['ref' => implode(', ', array_filter($refs)), 'party' => $party,
                'amount' => round($sum, 2), 'currency' => $currency, 'due_date' => $due, 'paid' => false],
            '_client_id' => $clientId, '_remaining' => round($sum, 2), '_converted' => round($sum, 2),
            '_ref_digits' => '', '_date_distance' => 0, '_promoted' => isset($signals['known_account']),
        ];
        return $this->finalize($candidate);
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    private function finalize(array $candidate): array
    {
        $candidate['score'] = $this->scorer->score($candidate['signals']);
        $candidate['deterministic_core'] = $this->scorer->hasDeterministicCore($candidate['signals'], $candidate['flags']);
        if (($candidate['type'] ?? '') !== 'split' && !isset($candidate['signals']['amount_remaining'])) {
            $candidate['deterministic_core'] = false;
        }
        return $candidate;
    }

    private function toTransactionCurrency(float $remaining, string $invoiceCurrency, float $rate, string $txCurrency): ?float
    {
        if ($invoiceCurrency === $txCurrency) return $remaining;
        if ($txCurrency === self::LOCAL_CURRENCY && $rate > 0.0) {
            return FxPaymentSettlement::expectedLocalAmount($remaining, $rate);
        }
        return null;
    }

    private function dayDistance(string $a, string $b): int
    {
        $at = strtotime($a); $bt = strtotime($b);
        return $at === false || $bt === false ? 9999 : (int) floor(abs($at - $bt) / 86400);
    }

    private function nameSimilarity(string $a, string $b): float
    {
        $tokens = static function (string $value): array {
            $value = mb_strtoupper($value, 'UTF-8');
            $value = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
            $value = preg_replace('/[^A-Z0-9]+/', ' ', $value) ?? '';
            $stop = ['SRO','AS','INC','LTD','LLC','GMBH','VOS','SPOL','THE','AND','CZ','CZE','SK','SVK','DE','DEU','PRAHA','BRNO'];
            return array_values(array_unique(array_filter(preg_split('/\s+/', trim($value)) ?: [], static fn (string $v): bool => strlen($v) >= 3 && !in_array($v, $stop, true))));
        };
        $ta = $tokens($a); $tb = $tokens($b);
        if ($ta === [] || $tb === []) return 0.0;
        return count(array_intersect($ta, $tb)) / count(array_unique(array_merge($ta, $tb)));
    }
}
