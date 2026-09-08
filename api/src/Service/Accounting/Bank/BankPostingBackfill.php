<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Bank\FxPaymentSettlement;
use PDO;

/**
 * Dávkové (backfill) zaúčtování historických bankovních transakcí přes týž engine
 * jako import ({@see BankPostingService::handleTransaction}) — žádná druhá logika
 * (R9). Slouží CLI skriptu {@see \api/bin/backfill-bank-posting.php} i testům.
 *
 * Závazná pravidla (§7):
 *   - default dry-run (nic nezapíše); --apply zapisuje,
 *   - iteruje source='statement', match_status ≠ 'ignored', bez existujícího
 *     nestornovaného journal_entries zdroje, CHRONOLOGICKY,
 *   - bez `withRules` přeskočí nespárované (jen spárované platby),
 *   - s `withRules` běží pravidla VŽDY jen jako suggest (auto degradace),
 *   - s `honourPolicy` (CLI `--auto`) se degradace vypne a rozhoduje výhradně
 *     tenantova {@see \MyInvoice\Service\Accounting\AutoPostingPolicyService}: kde
 *     dá `auto`, dávka zaúčtuje; kde dá `suggest`, navrhne jako dosud. Firma bez
 *     nastavené politiky má default `suggest`, takže se pro ni nic nemění,
 *   - zavřená / neexistující období → skip 'period_closed', ŽÁDNÁ suggestion
 *     ze zavřených let (frontu nezaplavit historií; --from to řídí),
 *   - report agreguje důvody skipů.
 *   - jednoznačné historické platby rekonciluje s již existujícím párováním bez
 *     duplikace; u chybějícího párování vyžaduje oboustranně jedinečnou shodu VS,
 *     částky, měny a data; zdroj platby zůstává zachován,
 *   - plně kryté auto_partial přijaté faktury normalizuje před zaúčtováním;
 *     podporuje i CZK karetní úhradu cizoměnového dokladu (563/663).
 */
final class BankPostingBackfill
{
    public function __construct(
        private readonly Connection $db,
        private readonly BankPostingService $service,
        private readonly AccountingPeriodRepository $periods,
        private readonly ChartOfAccountsSeeder $seeder,
        private readonly LegacyBankPaymentReconciler $legacyPayments,
    ) {}

    /**
     * @param bool $honourPolicy dávka respektuje `auto_posting_policy` místo tvrdé
     *        degradace auto → suggest; implikuje `withRules` (jinak by se nespárované
     *        transakce k politice vůbec nedostaly). S `activationMode` se ZÁMĚRNĚ
     *        ignoruje: aktivace potřebuje `suggestOnly=true`, jinak jí spadne guard
     *        `not_double_entry` (firma podvojné účetnictví teprve zapíná).
     *
     * @return array{
     *   supplier_id:int, dry_run:bool, with_rules:bool, honour_policy:bool, from:?string,
     *   candidates:int, posted:int, suggested:int, skipped:int,
     *   reconciled_legacy:int, normalized_full:int,
     *   skip_reasons:array<string,int>, suggest_reasons:array<string,int>,
     *   errors:list<array{tx_id:int, reason:string, message:string}>
     * }
     */
    public function run(
        int $supplierId,
        ?string $from,
        bool $apply,
        bool $withRules,
        ?int $userId = null,
        bool $activationMode = false,
        bool $honourPolicy = false,
    ): array
    {
        $dryRun = !$apply;
        $honourPolicy = $honourPolicy && !$activationMode;
        $withRules = $withRules || $honourPolicy;
        $suggestOnly = !$honourPolicy;
        $pdo = $this->db->pdo();

        // Ostrý běh: idempotentní seed osnovy (postDocument potřebuje účty).
        if (!$dryRun && !$pdo->inTransaction()) {
            $this->seeder->seedForSupplier($supplierId);
        }

        $rows = $this->candidates($supplierId, $from);

        $report = [
            'supplier_id'      => $supplierId,
            'dry_run'          => $dryRun,
            'with_rules'       => $withRules,
            'honour_policy'    => $honourPolicy,
            'from'             => $from,
            'candidates'       => count($rows),
            'posted'           => 0,
            'suggested'        => 0,
            'skipped'          => 0,
            'reconciled_legacy' => 0,
            'normalized_full'  => 0,
            'skip_reasons'     => [],
            'suggest_reasons'  => [],
            'errors'           => [],
        ];

        // Dry-run: engine zapisuje, na konci vše zahodíme. Nested-safe: pokud žádná
        // transakce neběží, otevřeme vlastní; pokud běží (např. test), izolujeme
        // SAVEPOINTem a rollbacknem jen k němu (vnější transakci nezrušíme).
        $ownTx = $dryRun && !$pdo->inTransaction();
        $useSavepoint = $dryRun && $pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        } elseif ($useSavepoint) {
            $pdo->exec('SAVEPOINT bpbf_dry');
        }
        try {
            foreach ($rows as $row) {
                $txId = (int) $row['id'];
                $discoveredInvoiceId = null;
                $isMatched = in_array((string) $row['match_status'], ['auto_exact', 'auto_partial', 'manual'], true)
                    || !empty($row['has_explicit_allocation']);
                if (!$isMatched && (float) $row['amount'] > 0.0) {
                    $discoveredInvoiceId = $this->discoverLegacyIncomingInvoice($supplierId, $txId);
                    $isMatched = $discoveredInvoiceId !== null;
                }

                // Bez --rules jen spárované platby (applyRules větev se přeskočí).
                if (!$isMatched && !$withRules) {
                    $this->tallySkip($report, 'rules_disabled');
                    continue;
                }

                // Zavřené / neexistující období → skip bez suggestion (frontu nezaplavit).
                $period = $this->periods->findForDate($supplierId, (string) $row['posted_at']);
                if ($period === null) {
                    $this->tallySkip($report, 'no_period');
                    continue;
                }
                if ((string) ($period['status'] ?? 'open') !== 'open') {
                    $this->tallySkip($report, 'period_closed');
                    continue;
                }

                $candidateTx = $this->beginCandidateTransaction($txId);
                $reconciled = false;
                $normalized = false;
                try {
                    $reconciled = $this->reconcileLegacyIncoming($supplierId, $txId, $discoveredInvoiceId);
                    // Engine si normalizaci dělá i sám (matchedOutcome); tady voláme explicitně
                    // kvůli reportu `normalized_full` a rozhodnutí o `has_live_entry` níž.
                    // Druhé (vnitřní) volání je no-op — metoda je idempotentní.
                    $normalized = $this->service->normalizeRoundingFullPurchase($supplierId, $txId);

                    if (!empty($row['has_live_entry']) && !$normalized) {
                        $this->finishCandidateTransaction($candidateTx, false);
                        $this->tallySkip($report, 'already_posted');
                        continue;
                    }

                    // Týž engine; bez honourPolicy degraduje dávka auto pravidla na suggest.
                    $res = $this->service->handleTransaction(
                        $txId,
                        $userId,
                        $suggestOnly,
                        $activationMode ? $supplierId : null,
                    );
                    if (($reconciled || $normalized) && $res['action'] !== 'posted') {
                        $reason = (string) ($res['reason'] ?? 'unknown');
                        $this->finishCandidateTransaction($candidateTx, false);
                        $this->tallySkip($report, 'reconcile_not_posted:' . $reason);
                        $this->tallyError($report, $txId, $reason, (string) ($res['message'] ?? ''));
                        continue;
                    }
                    $this->finishCandidateTransaction($candidateTx, true);
                } catch (\Throwable $e) {
                    $this->finishCandidateTransaction($candidateTx, false);
                    $this->tallySkip($report, 'reconcile_error');
                    $this->tallyError($report, $txId, 'reconcile_error', $e->getMessage());
                    continue;
                }

                if ($res['action'] === 'posted') {
                    if ($reconciled) {
                        $report['reconciled_legacy']++;
                    }
                    if ($normalized) {
                        $report['normalized_full']++;
                    }
                }
                $this->tally($report, $res, $txId);
            }
        } finally {
            if ($ownTx) {
                $pdo->rollBack();
            } elseif ($useSavepoint) {
                $pdo->exec('ROLLBACK TO SAVEPOINT bpbf_dry');
            }
        }

        return $report;
    }

    /**
     * Kandidátské transakce (chronologicky) vlastněné supplierem, bez živého zápisu.
     *
     * @return list<array<string,mixed>>
     */
    private function candidates(int $supplierId, ?string $from): array
    {
        $hasFrom = $from !== null && $from !== '';
        $fromSql = $hasFrom ? ' AND bt.posted_at >= ?' : '';
        $sql = "SELECT bt.id, bt.amount, bt.match_status, bt.posted_at,
                       (EXISTS (SELECT 1 FROM invoice_payments alloc_ip
                                 WHERE alloc_ip.supplier_id = ? AND alloc_ip.bank_transaction_id = bt.id)
                        OR EXISTS (SELECT 1 FROM payment_matches alloc_pm
                                    WHERE alloc_pm.supplier_id = ? AND alloc_pm.bank_transaction_id = bt.id))
                           AS has_explicit_allocation,
                       EXISTS (SELECT 1 FROM journal_entries live
                                WHERE live.supplier_id = ? AND " . \MyInvoice\Service\Bank\BankTransactionPostingScope::sourceSql('live', 'bt.id') . "
                                  AND live.reversed_by IS NULL) AS has_live_entry
                  FROM bank_transactions bt
                  JOIN bank_statements bs ON bs.id = bt.statement_id
                 WHERE bt.source = 'statement'
                   AND bt.match_status <> 'ignored'
                   AND (
                       " . \MyInvoice\Repository\BankStatementOwnershipResolver::sql() . "
                       OR EXISTS (SELECT 1 FROM invoice_payments ip
                                   WHERE ip.supplier_id = ? AND ip.bank_transaction_id = bt.id)
                       OR EXISTS (SELECT 1 FROM payment_matches explicit_pm
                                   WHERE explicit_pm.supplier_id = ? AND explicit_pm.bank_transaction_id = bt.id)
                       OR EXISTS (SELECT 1 FROM invoices matched_i
                                   WHERE matched_i.supplier_id = ? AND matched_i.id = bt.matched_invoice_id)
                   )
                   AND (
                       NOT EXISTS (SELECT 1 FROM journal_entries je
                                    WHERE je.supplier_id = ? AND " . \MyInvoice\Service\Bank\BankTransactionPostingScope::sourceSql('je', 'bt.id') . "
                                      AND je.reversed_by IS NULL)
                       -- Už zaúčtovaná tx se znovu nabídne JEN kvůli normalizaci haléřového
                       -- zbytku. Tenhle blok je pouze PŘEDFILTR: rozhoduje výhradně
                       -- {@see BankPostingService::normalizeRoundingFullPurchase()}, a když
                       -- normalizace neproběhne, řádek spadne na skip 'already_posted'.
                       -- Predikáty proto musí být NADMNOŽINOU guardu služby — užší filtr tu
                       -- znamená, že se doklad nikdy nedozaučtuje a haléře zůstanou viset na
                       -- 321 (tak vzniklo 28 nedoúčtovaných dokladů: SQL znalo jen
                       -- 'auto_partial' + match_type 'auto' + confidence ≥ 70, zatímco služba
                       -- bere i 'auto_exact'/'manual' a ruční párování confidence nemá).
                       -- Shodu obou stran hlídá BankPostingBackfillCandidatesTest.
                       OR (
                           bt.match_status IN ('auto_partial', 'auto_exact', 'manual') AND bt.amount < 0
                           AND EXISTS (
                               SELECT 1
                                 FROM payment_matches pm
                                 JOIN purchase_invoices pi
                                   ON pi.id = pm.purchase_invoice_id AND pi.supplier_id = pm.supplier_id
                                 JOIN currencies pic ON pic.id = pi.currency_id
                                WHERE pm.supplier_id = ? AND pm.bank_transaction_id = bt.id
                                  AND pm.invoice_id IS NULL AND pm.purchase_invoice_id IS NOT NULL
                                  AND pm.match_type IN ('auto', 'manual')
                                  AND (
                                      (
                                          UPPER(pic.code) = UPPER(COALESCE(NULLIF(bt.currency, ''), NULLIF(bs.currency, '')))
                                          -- ruční párování nemá confidence (NULL) — důkazem je člověk, ne skóre
                                          AND (pm.match_type = 'manual' OR COALESCE(pm.match_confidence, 0) >= 70)
                                          AND ABS(ABS(bt.amount) - pi.amount_to_pay) <= ?
                                      )
                                      OR (
                                          UPPER(pic.code) <> UPPER(COALESCE(NULLIF(bt.currency, ''), NULLIF(bs.currency, '')))
                                          AND (pm.match_type = 'manual' OR COALESCE(pm.match_confidence, 0) >= 60)
                                          AND UPPER(COALESCE(NULLIF(bt.currency, ''), NULLIF(bs.currency, ''))) = 'CZK'
                                          AND UPPER(pic.code) <> 'CZK' AND pi.exchange_rate > 0
                                          AND ABS(ABS(bt.amount) - pi.amount_to_pay * pi.exchange_rate)
                                              <= GREATEST(?, pi.amount_to_pay * pi.exchange_rate * ?)
                                      )
                                  )
                                  AND ABS(pm.amount - ABS(bt.amount)) < 0.005
                                  AND (SELECT COUNT(*) FROM payment_matches all_pm
                                        WHERE all_pm.supplier_id = ? AND all_pm.bank_transaction_id = bt.id) = 1
                           )
                       )
                   )
                   {$fromSql}
                 ORDER BY bt.posted_at ASC, bt.id ASC";
        // pořadí parametrů: příznak explicitní alokace 2×, live,
        // vlastnictví účtu přes resolver (2× — SEC-01) + explicitní vazby 3×, journal,
        // normalizace partial: supplier + sdílené tolerance + supplier, [from]
        $params = array_merge(
            [$supplierId, $supplierId, $supplierId],
            \MyInvoice\Repository\BankStatementOwnershipResolver::params($supplierId),
            [$supplierId, $supplierId, $supplierId],
            [$supplierId],
            [
                $supplierId,
                FxPaymentSettlement::AMOUNT_TOLERANCE,
                FxPaymentSettlement::AMOUNT_TOLERANCE,
                FxPaymentSettlement::MATCH_TOLERANCE_PCT,
                $supplierId,
            ],
        );
        if ($hasFrom) {
            $params[] = $from;
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Jednoznačně naváže historickou platbu na již spárovanou nebo bezpečně
     * dohledanou příchozí transakci. Všechny guardy se znovu ověřují pod zámkem;
     * metoda sama necommitne.
     */
    private function reconcileLegacyIncoming(int $supplierId, int $txId, ?int $discoveredInvoiceId = null): bool
    {
        $pdo = $this->db->pdo();
        if ($discoveredInvoiceId !== null) {
            $discoveredAgain = $this->discoverLegacyIncomingInvoice($supplierId, $txId);
            if ($discoveredAgain !== $discoveredInvoiceId) {
                return false;
            }
            $claim = $pdo->prepare(
                "UPDATE bank_transactions
                    SET matched_invoice_id = ?, match_status = 'auto_exact'
                  WHERE id = ? AND source = 'statement' AND matched_invoice_id IS NULL
                    AND match_status = 'unmatched'
                    AND NOT EXISTS (SELECT 1 FROM invoice_payments ip WHERE ip.bank_transaction_id = bank_transactions.id)
                    AND NOT EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.bank_transaction_id = bank_transactions.id)"
            );
            $claim->execute([$discoveredInvoiceId, $txId]);
            if ($claim->rowCount() !== 1) {
                return false;
            }
        }

        return $this->legacyPayments->reconcileMatchedIncoming($supplierId, $txId);
    }

    /**
     * Najde pouze oboustranně jednoznačnou historickou platbu podle VS, částky,
     * měny a data. Bez VS ani při více kandidátech nic automaticky nepáruje.
     */
    private function discoverLegacyIncomingInvoice(int $supplierId, int $txId): ?int
    {
        $pdo = $this->db->pdo();
        $normalizedInvoiceVs = "TRIM(LEADING '0' FROM REGEXP_REPLACE(COALESCE(i.varsymbol, ''), '[^0-9]', ''))";
        $normalizedTxVs = "TRIM(LEADING '0' FROM REGEXP_REPLACE(COALESCE(bt.variable_symbol, ''), '[^0-9]', ''))";
        $stmt = $pdo->prepare(
            "SELECT i.id
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
               JOIN invoices i ON i.supplier_id = ? AND i.invoice_type IN ('invoice','proforma')
                              AND i.status = 'paid'
               JOIN currencies cur ON cur.id = i.currency_id
               JOIN invoice_payments ip ON ip.supplier_id = i.supplier_id AND ip.invoice_id = i.id
                                        AND ip.source = 'legacy' AND ip.bank_transaction_id IS NULL
              WHERE bt.id = ? AND bt.source = 'statement' AND bt.amount > 0
                AND bt.matched_invoice_id IS NULL AND bt.match_status = 'unmatched'
                AND {$normalizedInvoiceVs} <> '' AND {$normalizedInvoiceVs} = {$normalizedTxVs}
                AND UPPER(cur.code) = UPPER(COALESCE(NULLIF(bt.currency, ''), bs.currency))
                AND ABS(ip.amount - bt.amount) <= 0.05
                AND ABS(DATEDIFF(DATE(bt.posted_at), ip.paid_on)) <= 31
                AND ABS(i.paid_total - i.amount_to_pay) <= 0.05
                AND (SELECT COUNT(*) FROM invoice_payments all_ip
                      WHERE all_ip.supplier_id = i.supplier_id AND all_ip.invoice_id = i.id) = 1
                AND NOT EXISTS (SELECT 1 FROM invoice_payments used_ip WHERE used_ip.bank_transaction_id = bt.id)
                AND NOT EXISTS (SELECT 1 FROM payment_matches used_pm WHERE used_pm.bank_transaction_id = bt.id)"
        );
        $stmt->execute([$supplierId, $txId]);
        $invoiceIds = array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
        if (count($invoiceIds) !== 1) {
            return null;
        }

        $invoiceId = $invoiceIds[0];
        $reverse = $pdo->prepare(
            "SELECT COUNT(*)
               FROM bank_transactions other
               JOIN bank_statements other_bs ON other_bs.id = other.statement_id
               JOIN invoices i ON i.id = ? AND i.supplier_id = ?
               JOIN currencies cur ON cur.id = i.currency_id
               JOIN invoice_payments ip ON ip.supplier_id = i.supplier_id AND ip.invoice_id = i.id
                                      AND ip.source = 'legacy' AND ip.bank_transaction_id IS NULL
              WHERE other.source = 'statement' AND other.amount > 0
                AND other.matched_invoice_id IS NULL AND other.match_status = 'unmatched'
                AND TRIM(LEADING '0' FROM REGEXP_REPLACE(COALESCE(other.variable_symbol, ''), '[^0-9]', ''))
                    = TRIM(LEADING '0' FROM REGEXP_REPLACE(COALESCE(i.varsymbol, ''), '[^0-9]', ''))
                AND UPPER(cur.code) = UPPER(COALESCE(NULLIF(other.currency, ''), other_bs.currency))
                AND ABS(ip.amount - other.amount) <= 0.05
                AND ABS(DATEDIFF(DATE(other.posted_at), ip.paid_on)) <= 31
                AND NOT EXISTS (SELECT 1 FROM invoice_payments used_ip WHERE used_ip.bank_transaction_id = other.id)
                AND NOT EXISTS (SELECT 1 FROM payment_matches used_pm WHERE used_pm.bank_transaction_id = other.id)"
        );
        $reverse->execute([$invoiceId, $supplierId]);
        return (int) $reverse->fetchColumn() === 1 ? $invoiceId : null;
    }

    /** @return array{own:bool,savepoint:?string} */
    private function beginCandidateTransaction(int $txId): array
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            return ['own' => true, 'savepoint' => null];
        }
        $savepoint = 'bpbf_row_' . $txId;
        $pdo->exec('SAVEPOINT ' . $savepoint);
        return ['own' => false, 'savepoint' => $savepoint];
    }

    /** @param array{own:bool,savepoint:?string} $tx */
    private function finishCandidateTransaction(array $tx, bool $commit): void
    {
        $pdo = $this->db->pdo();
        if ($tx['own']) {
            if ($pdo->inTransaction()) {
                $commit ? $pdo->commit() : $pdo->rollBack();
            }
            return;
        }
        if ($tx['savepoint'] === null || !$pdo->inTransaction()) {
            return;
        }
        if (!$commit) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . $tx['savepoint']);
        }
        $pdo->exec('RELEASE SAVEPOINT ' . $tx['savepoint']);
    }

    /**
     * @param array{action:string, reason?:string, message?:string} $res
     * @param array<string,mixed> $report
     */
    private function tally(array &$report, array $res, int $txId): void
    {
        switch ($res['action']) {
            case 'posted':
                $report['posted']++;
                break;
            case 'suggested':
                $report['suggested']++;
                $reason = $res['reason'] ?? 'rule';
                $report['suggest_reasons'][$reason] = ($report['suggest_reasons'][$reason] ?? 0) + 1;
                break;
            default:
                $reason = (string) ($res['reason'] ?? 'unknown');
                $this->tallySkip($report, $reason);
                $this->tallyError($report, $txId, $reason, (string) ($res['message'] ?? ''));
        }
    }

    /** @param array<string,mixed> $report */
    private function tallySkip(array &$report, string $reason): void
    {
        $report['skipped']++;
        $report['skip_reasons'][$reason] = ($report['skip_reasons'][$reason] ?? 0) + 1;
    }

    /**
     * Tvrdá chyba (výjimka enginu) se vedle počítadla uloží i s textem. `error` bez
     * textu je pro dávku k ničemu — příčinou bývá triviálně opravitelná věc typu
     * neaplikovaná migrace, kterou ale z agregovaného reportu nikdo nepozná.
     *
     * @param array<string,mixed> $report
     */
    private function tallyError(array &$report, int $txId, string $reason, string $message): void
    {
        if ($message === '' || count($report['errors']) >= 50) {
            return;
        }
        $report['errors'][] = [
            'tx_id'   => $txId,
            'reason'  => $reason,
            'message' => mb_substr($message, 0, 300),
        ];
    }
}
