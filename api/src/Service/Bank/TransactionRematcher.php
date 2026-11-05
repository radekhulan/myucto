<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Bank\BankPostingService;

/**
 * Znovu spustí automatické párování nad danými pohyby stejnou cestou jako import:
 * převzetí z e-mailového avíza, párování ve dvou průchodech a zaúčtování pohybu.
 * Používá ho „Přepárovat výpis" i dohledání platby po přijetí přijatého dokladu.
 */
final class TransactionRematcher
{
    public function __construct(
        private readonly Connection $db,
        private readonly StatementMatcher $matcher,
        private readonly BankPostingService $bankPosting,
        private readonly EmailNoticeReconciler $reconciler,
    ) {}

    /**
     * @param list<int> $transactionIds
     * @return array{newly_matched:int, newly_partial:int, still_unmatched:int, taken_over:int, superseded:int}
     */
    public function rematch(array $transactionIds, ?int $userId): array
    {
        $out = ['newly_matched' => 0, 'newly_partial' => 0, 'still_unmatched' => 0, 'taken_over' => 0, 'superseded' => 0];
        $matchIds = [];
        foreach ($transactionIds as $txId) {
            $txId = (int) $txId;
            // Tatáž platba spárovaná na e-mailovém avízu se převezme na oficiální výpis
            // místo dvojího párování (při importu to dělá StatementImporter).
            $takeover = $this->reconciler->takeOverFromEmailNotice($txId);
            if ($takeover !== null) {
                $out['taken_over']++;
                $out['newly_matched']++;
                if ($takeover['match_status'] === 'auto_exact') $this->matcher->match($txId);
                $this->bankPosting->handleTransaction($txId, $userId);
                continue;
            }
            // Karetní výdaje a poplatky pokryté i avízem by jinak zůstaly dvakrát (#76).
            if ($this->reconciler->supersedeUnmatchedEmailNotice($txId) !== null) $out['superseded']++;
            $matchIds[] = $txId;
        }

        foreach ($this->matcher->matchBatch($matchIds) as $txId => $r) {
            $s = (string) ($r['status'] ?? 'unmatched');
            if ($s === 'auto_exact' && empty($r['already_recorded'])) $out['newly_matched']++;
            elseif ($s === 'auto_partial') $out['newly_partial']++;
            elseif ($s === 'unmatched') $out['still_unmatched']++;
            // Zaúčtování po párování (no-op mimo podvojné účetnictví).
            $this->bankPosting->handleTransaction($txId, $userId, !empty($r['requires_review']));
        }
        return $out;
    }

    /** Přepočte počet spárovaných pohybů na výpisech. @param list<int> $statementIds */
    public function recountStatements(array $statementIds): void
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE bank_statements
                SET matched_count = (
                    SELECT COUNT(*) FROM bank_transactions
                     WHERE statement_id = ?
                       AND match_status IN ('auto_exact', 'auto_partial', 'manual')
                )
              WHERE id = ?"
        );
        foreach (array_unique(array_map('intval', $statementIds)) as $statementId) {
            if ($statementId > 0) $stmt->execute([$statementId, $statementId]);
        }
    }
}
