<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bank;

use DateTimeImmutable;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankPostingSuggestionRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\OperationType;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Bank\BankNoticeMutationGuard;
use MyInvoice\Service\Currency\CnbExchangeRateClient;
use PDO;
use PDOException;

final class TransferPairService
{
    private const MANUAL_DUPLICATE_WINDOW_DAYS = 10;

    /**
     * Okno pro spárování dvou nohou vlastního převodu (odchozí ↔ příchozí). Ostrá firma
     * dělá 190+ převodů ročně a mezi odepsáním z jednoho účtu a připsáním na druhý bývá
     * i víc než 3 dny (mezibankovní, víkend). Širší okno je bezpečné, protože párování
     * navíc vyžaduje ZRCADLOVÉ účty (mirrorAccounts) + přesně opačnou částku + shodnou měnu.
     */
    private const PAIR_WINDOW_DAYS = 7;

    public function __construct(
        private readonly Connection $db,
        private readonly PostingService $posting,
        private readonly PostingRuleRepository $postingRules,
        private readonly JournalEntryRepository $journal,
        private readonly BankPostingSuggestionRepository $suggestions,
        private readonly OwnTransferDetector $detector,
        private readonly TransferAutoPolicyInterface $policy,
        private readonly ActivityLogger $activity,
        private readonly CnbExchangeRateClient $cnb,
        private readonly ?BankAnalyticResolver $bankAnalytics = null,
    ) {}

    /** @return array{action:string,reason?:string,entry_id?:int,suggestion_id?:int}|null */
    public function handle(int $supplierId, array $tx, ?int $userId, bool $suggestOnly): ?array
    {
        $detected = $this->detector->detect($supplierId, $tx);
        if ($detected === null) {
            return null;
        }
        $level = $this->policy->level($supplierId);
        if ($level === 'off') {
            return null;
        }

        $txId = (int) $tx['id'];
        $this->pair($supplierId, $txId);
        $existing = $this->journal->findBySource($supplierId, 'bank', $txId);
        if ($existing !== null && ($existing['reversed_by'] ?? null) === null) {
            return ['action' => 'skipped', 'reason' => 'already_posted'];
        }
        if ($this->suggestions->hasRejectedSource($supplierId, $txId, 'transfer')) {
            return ['action' => 'skipped', 'reason' => 'transfer_rejected'];
        }

        $note = null;
        if ($detected['cross_currency']) {
            $note = 'cross_currency';
        } else {
            $duplicate = $this->manualDuplicate($supplierId, $tx, $detected['direction']);
            if ($duplicate !== null) {
                $note = 'duplicate_suspect:#' . $duplicate;
            }
        }
        if ($note !== null || $suggestOnly || $level !== 'auto') {
            return $this->suggest($supplierId, $tx, $detected['direction'], $note);
        }

        try {
            $entryId = $this->postLeg($supplierId, $txId, $userId);
        } catch (PostingException $e) {
            if (in_array($e->errorCode, ['period_not_open', 'no_accounting_period', 'date_locked'], true)) {
                return $this->suggest($supplierId, $tx, $detected['direction'], 'period_closed');
            }
            throw $e;
        }
        $codes = $this->codes($supplierId, $detected['direction']);
        $this->suggestions->supersedePendingForTx($supplierId, $txId, 'overwritten_by_auto');
        $suggestionId = $this->suggestions->createAutoPosted([
            'supplier_id' => $supplierId,
            'bank_transaction_id' => $txId,
            'rule_id' => null,
            'source' => 'transfer',
            'debit_account_code' => $codes['debit'],
            'credit_account_code' => $codes['credit'],
            'amount' => round(abs((float) $tx['amount']), 2),
            'description' => $this->entryDescription($tx),
            'journal_entry_id' => $entryId,
            'confidence' => 0.95,
            'detector' => 'own_transfer',
            'operation_type' => OperationType::BANK_TRANSFER_OWN,
        ]);
        $this->activity->log('bank_transfer.auto_posted', $userId, 'bank_transaction', $txId, [
            'entry_id' => $entryId,
        ], null, null, $supplierId);
        return ['action' => 'posted', 'reason' => 'own_transfer', 'entry_id' => $entryId, 'suggestion_id' => $suggestionId];
    }

    public function postLeg(int $supplierId, int $txId, ?int $userId): int
    {
        return (new BankNoticeMutationGuard($this->db))->mutate($supplierId, $txId, fn () => $this->postLegMutable($supplierId, $txId, $userId));
    }

    private function postLegMutable(int $supplierId, int $txId, ?int $userId): int
    {
        $tx = $this->loadTx($txId);
        if ($tx === null) {
            throw new PostingException('not_found', 'Bankovní transakce nenalezena.', 404);
        }
        $detected = $this->detector->detect($supplierId, $tx);
        if ($detected === null) {
            throw new PostingException('not_supported', 'Transakce není převodem mezi vlastními účty.', 409);
        }
        if ($detected['cross_currency']) {
            throw new PostingException('cross_currency_manual_only', 'Převod mezi měnami se účtuje ručně.', 409);
        }
        $duplicate = $this->manualDuplicate($supplierId, $tx, $detected['direction']);
        if ($duplicate !== null) {
            throw new PostingException('duplicate_suspect', 'Podobný ruční zápis přes účet 261 už existuje.', 409);
        }

        $amountForeign = round(abs((float) $tx['amount']), 2);
        $currency = $this->effectiveCurrency($tx);
        $amount = $amountForeign;
        $fx = null;
        if ($currency !== 'CZK') {
            $fx = $this->pairedFxRate($supplierId, $txId);
            if ($fx === null) {
                $rate = $this->cnb->getRate($currency, new DateTimeImmutable((string) $tx['posted_at']));
                if ($rate === null || (float) $rate['rate'] <= 0) {
                    throw new PostingException('fx_rate_unavailable', 'Kurz ČNB pro měnu ' . $currency . ' není k dispozici.');
                }
                $fx = (float) $rate['rate'];
            }
            $amount = round($amountForeign * $fx, 2);
        }

        $codes = $this->codes($supplierId, $detected['direction']);
        $lines = [
            $this->line($codes['debit'], 'debit', $amount, $currency, $fx, $amountForeign),
            $this->line($codes['credit'], 'credit', $amount, $currency, $fx, $amountForeign),
        ];
        // #35 — bankovní noha ('221') na dedikovanou analytiku vlastního účtu (261 clearing zůstává).
        $lines = $this->bankAnalytics?->apply($supplierId, $tx, $lines) ?? $lines;
        $entryId = $this->posting->postDocument($supplierId, 'bank', $txId, $lines, [
            'entry_date' => (string) $tx['posted_at'],
            'document_date' => (string) $tx['posted_at'],
            'description' => $this->entryDescription($tx),
            'posted' => true,
            'user_id' => $userId,
            'posted_by' => $userId,
        ]);
        $this->auditFxResidual($supplierId, $txId);
        return $entryId;
    }

    /** @return array{account:array<string,mixed>,direction:'out'|'in',cross_currency:bool}|null */
    public function detectTransaction(int $supplierId, int $txId): ?array
    {
        $tx = $this->loadTx($txId);
        return $tx === null ? null : $this->detector->detect($supplierId, $tx);
    }

    /**
     * Rozpoznání převodu pro cesty, které účtují podle pravidla nebo naučené kontace
     * (schválení návrhu, použití pravidla na existující pohyby). Platí stejná přednost
     * jako při importu ({@see \MyInvoice\Service\Accounting\Bank\Detect\BankDetectorChain}):
     * vypnutá detekce ani pravidlo s prioritou pod
     * {@see BankRuleMatcher::DETECTOR_OVERRIDE_PRIORITY} převod nevynutí. Typicky účet
     * faktoringu uvedený na fakturách, ze kterého chodí zálohy na postoupené pohledávky.
     *
     * @param array<string,mixed>|null $rule pravidlo návrhu; null = naučená kontace
     * @return array{account:array<string,mixed>,direction:'out'|'in',cross_currency:bool}|null
     */
    public function detectUnlessOverridden(int $supplierId, int $txId, ?array $rule): ?array
    {
        if ($this->policy->level($supplierId) === 'off'
            || ($rule !== null && BankRuleMatcher::overridesDetectors($rule))) {
            return null;
        }
        return $this->detectTransaction($supplierId, $txId);
    }

    /** @param array<string,mixed> $suggestion @param array<string,mixed> $meta */
    public function approveLeg(int $supplierId, array $suggestion, array $meta): int
    {
        $note = (string) ($suggestion['note'] ?? '');
        if ($note === 'cross_currency') {
            throw new PostingException('cross_currency_manual_only', 'Převod mezi měnami se účtuje ručně.', 409);
        }
        $txId = (int) $suggestion['bank_transaction_id'];
        $entryId = $this->postLeg($supplierId, $txId, $meta['user_id'] ?? null);
        if (!$this->suggestions->markApproved(
            $supplierId,
            (int) $suggestion['id'],
            $entryId,
            $meta['user_id'] ?? null,
        )) {
            throw new PostingException('suggestion_not_pending', 'Návrh už byl vyřízen.', 409);
        }
        $this->pair($supplierId, $txId);
        return $entryId;
    }

    /** @param array<string,mixed> $suggestion */
    public function matchesDetectedPosting(int $supplierId, array $suggestion): bool
    {
        $tx = $this->loadTx((int) ($suggestion['bank_transaction_id'] ?? 0));
        if ($tx === null) return false;
        $detected = $this->detector->detect($supplierId, $tx);
        if ($detected === null || $detected['cross_currency']) return false;
        $codes = $this->codes($supplierId, $detected['direction']);
        return (string) ($suggestion['debit_account_code'] ?? '') === $codes['debit']
            && (string) ($suggestion['credit_account_code'] ?? '') === $codes['credit']
            && abs((float) ($suggestion['amount'] ?? 0) - round(abs((float) $tx['amount']), 2)) < 0.005;
    }

    /** @return array<string,mixed> */
    public function replaceForReview(int $supplierId, int $txId): array
    {
        $tx = $this->loadTx($txId);
        if ($tx === null) {
            throw new PostingException('not_found', 'Bankovní transakce nenalezena.', 404);
        }
        $detected = $this->detector->detect($supplierId, $tx);
        if ($detected === null) {
            throw new PostingException('not_supported', 'Transakce není převodem mezi vlastními účty.', 409);
        }
        if ($detected['cross_currency']) {
            throw new PostingException('cross_currency_manual_only', 'Převod mezi měnami se účtuje ručně.', 409);
        }

        $this->pair($supplierId, $txId);
        $duplicate = $this->manualDuplicate($supplierId, $tx, $detected['direction']);
        $result = $this->suggest(
            $supplierId,
            $tx,
            $detected['direction'],
            $duplicate === null ? null : 'duplicate_suspect:#' . $duplicate,
        );
        $replacement = $this->suggestions->find($supplierId, (int) $result['suggestion_id']);
        if ($replacement === null || (string) $replacement['source'] !== 'transfer') {
            throw new PostingException('suggestion_not_pending', 'Návrh převodu se nepodařilo připravit.', 409);
        }
        return $replacement;
    }

    public function pair(int $supplierId, int $txId): ?int
    {
        $tx = $this->loadTx($txId);
        if ($tx === null || $this->detector->detect($supplierId, $tx) === null) {
            return null;
        }
        $existing = $this->pairRowForTx($supplierId, $txId);
        if ($existing !== null) {
            $this->auditFxResidual($supplierId, $txId);
            return $this->otherTxId($existing, $txId);
        }

        $candidates = $this->mirrorCandidates($supplierId, $tx);
        if ($candidates === []) {
            return null;
        }
        return $this->insertPair($supplierId, $tx, (int) $candidates[0]['id']);
    }

    /**
     * Jistý převod mezi vlastními účty, který matcher spáruje dřív, než by pohyb nabídl
     * k úhradě faktury. Variabilní symbol nerozhoduje: jistotu dává registr vlastních účtů.
     *
     * Podmínky: protiúčet pohybu je evidovaný vlastní účet téže firmy ve stejné měně
     * ({@see OwnTransferDetector}), na tom účtu leží v okně {@see PAIR_WINDOW_DAYS} právě
     * jeden protipohyb s opačnou částkou a shodnou měnou, jehož protiúčet je zpětně účet
     * prvního pohybu ({@see mirrorAccounts()}), a oba pohyby jsou volné
     * ({@see FREE_TX_SQL}). Víc kandidátů (i když je některý obsazený) nebo obsazený pohyb
     * nechá párování na dosavadní cestě. Zaúčtování zůstává na {@see handle()} podle úrovně automatiky.
     *
     * @return int|null ID druhé nohy, když je pár jistý (nebo už existuje)
     */
    public function pairIfCertain(int $supplierId, int $txId): ?int
    {
        if ($this->policy->level($supplierId) === 'off') {
            return null;
        }
        $tx = $this->loadTx($txId);
        if ($tx === null || (int) ($tx['statement_supplier_id'] ?? 0) !== $supplierId) {
            return null;
        }
        $detected = $this->detector->detect($supplierId, $tx);
        if ($detected === null || $detected['cross_currency']) {
            return null;
        }
        $existing = $this->pairRowForTx($supplierId, $txId);
        if ($existing !== null) {
            return $this->otherTxId($existing, $txId);
        }
        if (!$this->isFree($supplierId, $txId)) {
            return null;
        }
        $candidates = array_values(array_filter(
            $this->mirrorCandidates($supplierId, $tx),
            fn (array $row): bool => (int) ($row['statement_supplier_id'] ?? 0) === $supplierId
                && !($this->detector->detect($supplierId, $row)['cross_currency'] ?? true),
        ));
        if (count($candidates) !== 1 || !$this->isFree($supplierId, (int) $candidates[0]['id'])) {
            return null;
        }
        $otherId = $this->insertPair($supplierId, $tx, (int) $candidates[0]['id'], true);
        if ($otherId === null) {
            return null;
        }
        $this->db->pdo()->prepare(
            "UPDATE bank_match_suggestions SET status = 'superseded', updated_at = NOW()
              WHERE supplier_id = ? AND bank_transaction_id IN (?, ?) AND status = 'pending'"
        )->execute([$supplierId, $txId, $otherId]);
        $this->activity->log('bank_transfer.paired', null, 'bank_transaction', $txId, [
            'other_transaction_id' => $otherId,
            'rule' => 'registered_own_account_mirror',
        ], null, null, $supplierId);
        return $otherId;
    }

    /**
     * Pohyb není spotřebovaný jinou evidencí: párování s dokladem, úhrada ostatní položky,
     * záloha na daň, GoPay, mzdy, ignorování ani jiný živý bankovní zápis než převod.
     */
    private const FREE_TX_SQL = "bt.match_status = 'unmatched' AND bt.matched_invoice_id IS NULL
        AND NOT EXISTS (SELECT 1 FROM invoice_payments ip WHERE ip.bank_transaction_id = bt.id)
        AND NOT EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.bank_transaction_id = bt.id)
        AND NOT EXISTS (SELECT 1 FROM other_item_allocations oa WHERE oa.bank_transaction_id = bt.id)
        AND NOT EXISTS (SELECT 1 FROM tax_advance_schedules ta WHERE ta.matched_transaction_id = bt.id)
        AND NOT EXISTS (SELECT 1 FROM gopay_clearings gc
                         WHERE gc.bank_transaction_id = bt.id OR gc.payout_match_transaction_id = bt.id)
        AND NOT EXISTS (SELECT 1 FROM payroll_payment_matches ppm
                         WHERE ppm.supplier_id = :sid AND ppm.bank_transaction_id = bt.id)
        AND NOT EXISTS (SELECT 1 FROM journal_entries je
                         WHERE je.supplier_id = :sid2 AND je.source_type = 'bank' AND je.source_id = bt.id
                           AND je.reversed_by IS NULL
                           AND NOT EXISTS (SELECT 1 FROM bank_posting_suggestions bps
                                            WHERE bps.supplier_id = je.supplier_id AND bps.bank_transaction_id = bt.id
                                              AND bps.source = 'transfer' AND bps.journal_entry_id = je.id))";

    private function isFree(int $supplierId, int $txId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM bank_transactions bt WHERE bt.id = :id AND ' . \MyInvoice\Service\Bank\BankNoticeConfirmationScope::activeSql('bt') . ' AND ' . self::FREE_TX_SQL
        );
        $stmt->execute(['id' => $txId, 'sid' => $supplierId, 'sid2' => $supplierId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Protipohyby převodu: opačná částka, shodná měna, okno {@see PAIR_WINDOW_DAYS},
     * zatím nespárované, protiúčet evidovaný vlastní účet a zrcadlové účty. Nejbližší datum první.
     *
     * @param array<string,mixed> $tx
     * @return list<array<string,mixed>>
     */
    private function mirrorCandidates(int $supplierId, array $tx): array
    {
        $windowDays = self::PAIR_WINDOW_DAYS;
        $stmt = $this->db->pdo()->prepare(
            'SELECT bt.*, bs.account_number AS recipient_account, bs.bank_code AS recipient_bank,
                    bs.currency AS statement_currency, bs.supplier_id AS statement_supplier_id
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE ' . \MyInvoice\Service\Bank\BankNoticeConfirmationScope::activeSql('bt') . ' AND bt.id <> ? AND bt.amount = ? AND bt.posted_at BETWEEN DATE_SUB(?, INTERVAL ' . $windowDays . ' DAY) AND DATE_ADD(?, INTERVAL ' . $windowDays . ' DAY)
                AND COALESCE(bt.currency, bs.currency, "CZK") = ?
                AND NOT EXISTS (SELECT 1 FROM bank_transfer_matches m
                                 WHERE m.out_transaction_id = bt.id OR m.in_transaction_id = bt.id)
              ORDER BY ABS(DATEDIFF(bt.posted_at, ?)), bt.id
              LIMIT 20'
        );
        $stmt->execute([(int) $tx['id'], -(float) $tx['amount'], $tx['posted_at'], $tx['posted_at'], $this->effectiveCurrency($tx), $tx['posted_at']]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($this->detector->detect($supplierId, $row) !== null && $this->mirrorAccounts($tx, $row)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $tx */
    private function insertPair(int $supplierId, array $tx, int $otherId, bool $requireFree = false): ?int
    {
        $txId = (int) $tx['id'];
        $amount = (float) $tx['amount'];
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $ids = [$txId, $otherId];
            sort($ids, SORT_NUMERIC);
            $lock = $pdo->prepare('SELECT id FROM bank_transactions WHERE id IN (?, ?) ORDER BY id FOR UPDATE');
            $lock->execute($ids);
            $confirmations = new \MyInvoice\Service\Bank\BankNoticeConfirmationService($this->db);
            foreach ($ids as $id) $confirmations->assertMutable($supplierId, $id);
            if ($this->pairRowForTx($supplierId, $txId) !== null || $this->pairRowForTx($supplierId, $otherId) !== null
                || ($requireFree && (!$this->isFree($supplierId, $txId) || !$this->isFree($supplierId, $otherId)))) {
                if ($ownTx) $pdo->commit();
                return null;
            }
            $outId = $amount < 0 ? $txId : $otherId;
            $inId = $amount > 0 ? $txId : $otherId;
            $pdo->prepare(
                'INSERT INTO bank_transfer_matches
                    (supplier_id, out_transaction_id, in_transaction_id, amount, currency)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$supplierId, $outId, $inId, round(abs($amount), 2), $this->effectiveCurrency($tx)]);
            $this->auditFxResidual($supplierId, $txId);
            if ($ownTx) $pdo->commit();
            return $otherId;
        } catch (PDOException $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            if (($e->errorInfo[0] ?? null) === '23000') return null;
            throw $e;
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function releasePairs(int $supplierId, int $txId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM bank_transfer_matches
              WHERE supplier_id = ? AND (out_transaction_id = ? OR in_transaction_id = ?)'
        );
        $stmt->execute([$supplierId, $txId, $txId]);
    }

    /** @return array{action:string,reason:string,suggestion_id:int,created:bool} */
    private function suggest(int $supplierId, array $tx, string $direction, ?string $note): array
    {
        $codes = $this->codes($supplierId, $direction);
        $data = [
            'supplier_id' => $supplierId,
            'bank_transaction_id' => (int) $tx['id'],
            'rule_id' => null,
            'source' => 'transfer',
            'debit_account_code' => $codes['debit'],
            'credit_account_code' => $codes['credit'],
            'amount' => round(abs((float) $tx['amount']), 2),
            'description' => $this->entryDescription($tx),
            'note' => $note,
            'status' => $note === 'cross_currency' || $note === 'period_closed'
                ? 'blocked' : (str_starts_with((string) $note, 'duplicate_suspect') ? 'needs_input' : 'pending'),
            'confidence' => 0.95,
            'detector' => 'own_transfer',
            'operation_type' => OperationType::BANK_TRANSFER_OWN,
        ];
        $result = $this->suggestions->createIfNoPending($data);
        $pending = $this->suggestions->pendingForTx($supplierId, (int) $tx['id']);
        if ($pending !== null && (string) $pending['source'] !== 'transfer') {
            $this->suggestions->supersedePendingForTx($supplierId, (int) $tx['id'], 'overwritten_by_detector');
            $result = $this->suggestions->createIfNoPending($data);
        }
        $this->suggestions->refreshPendingTransfer($supplierId, (int) $result['id'], [
            'debit_account_code' => $data['debit_account_code'],
            'credit_account_code' => $data['credit_account_code'],
            'amount' => $data['amount'],
            'description' => $data['description'],
            'note' => $data['note'],
            'status' => $data['status'],
            'confidence' => $data['confidence'],
            'detector' => $data['detector'],
            'operation_type' => $data['operation_type'],
        ]);
        return [
            'action' => 'suggested',
            'reason' => $note ?? 'own_transfer',
            'suggestion_id' => $result['id'],
            'created' => $result['created'],
        ];
    }

    private function manualDuplicate(int $supplierId, array $tx, string $direction): ?int
    {
        $windowDays = self::MANUAL_DUPLICATE_WINDOW_DAYS;
        $stmt = $this->db->pdo()->prepare(
            'SELECT je.id
               FROM journal_entries je
               JOIN journal_entry_lines jl ON jl.entry_id = je.id AND jl.supplier_id = je.supplier_id
               JOIN chart_of_accounts coa ON coa.id = jl.account_id AND coa.supplier_id = je.supplier_id
              WHERE je.supplier_id = ? AND je.source_type = "manual" AND je.reversed_by IS NULL
                AND coa.account_code LIKE "261%" AND jl.side = ? AND jl.amount = ?
                AND jl.is_red_storno = 0
                AND je.entry_date BETWEEN DATE_SUB(?, INTERVAL ' . $windowDays . ' DAY) AND DATE_ADD(?, INTERVAL ' . $windowDays . ' DAY)
                AND EXISTS (
                    SELECT 1
                      FROM journal_entry_lines bank_jl
                      JOIN chart_of_accounts bank_coa
                        ON bank_coa.id = bank_jl.account_id AND bank_coa.supplier_id = bank_jl.supplier_id
                     WHERE bank_jl.entry_id = je.id AND bank_jl.supplier_id = je.supplier_id
                       AND bank_coa.account_code LIKE "221%" AND bank_jl.side <> jl.side
                       AND bank_jl.amount = jl.amount AND bank_jl.is_red_storno = 0
                )
              ORDER BY ABS(DATEDIFF(je.entry_date, ?)), je.id
              LIMIT 1'
        );
        $stmt->execute([
            $supplierId,
            $direction === 'out' ? 'debit' : 'credit',
            round(abs((float) $tx['amount']), 2),
            $tx['posted_at'],
            $tx['posted_at'],
            $tx['posted_at'],
        ]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private function pairedFxRate(int $supplierId, int $txId): ?float
    {
        $pair = $this->pairRowForTx($supplierId, $txId);
        if ($pair === null) return null;
        $otherId = $this->otherTxId($pair, $txId);
        $entry = $this->journal->findBySource($supplierId, 'bank', $otherId);
        if ($entry === null || ($entry['reversed_by'] ?? null) !== null) return null;
        foreach ($this->journal->linesForEntry((int) $entry['id'], $supplierId) as $line) {
            if ($line['fx_rate'] !== null && (float) $line['fx_rate'] > 0) return (float) $line['fx_rate'];
        }
        return null;
    }

    private function auditFxResidual(int $supplierId, int $txId): void
    {
        $pair = $this->pairRowForTx($supplierId, $txId);
        if ($pair === null || (string) $pair['currency'] === 'CZK') return;
        $outRate = $this->fxRateForTx($supplierId, (int) $pair['out_transaction_id']);
        $inRate = $this->fxRateForTx($supplierId, (int) $pair['in_transaction_id']);
        if ($outRate === null || $inRate === null || abs($outRate - $inRate) < 0.000001) return;
        $exists = $this->db->pdo()->prepare(
            'SELECT 1 FROM activity_log
              WHERE supplier_id = ? AND action = "bank_transfer.fx_residual"
                AND entity_type = "bank_transfer" AND entity_id = ? LIMIT 1'
        );
        $exists->execute([$supplierId, (int) $pair['id']]);
        if ($exists->fetchColumn() !== false) return;
        $this->activity->log('bank_transfer.fx_residual', null, 'bank_transfer', (int) $pair['id'], [
            'out_transaction_id' => (int) $pair['out_transaction_id'],
            'in_transaction_id' => (int) $pair['in_transaction_id'],
            'out_rate' => $outRate,
            'in_rate' => $inRate,
            'residual' => round((float) $pair['amount'] * ($outRate - $inRate), 2),
        ], null, null, $supplierId);
    }

    private function fxRateForTx(int $supplierId, int $txId): ?float
    {
        $entry = $this->journal->findBySource($supplierId, 'bank', $txId);
        if ($entry === null || ($entry['reversed_by'] ?? null) !== null) return null;
        foreach ($this->journal->linesForEntry((int) $entry['id'], $supplierId) as $line) {
            if ($line['fx_rate'] !== null && (float) $line['fx_rate'] > 0) return (float) $line['fx_rate'];
        }
        return null;
    }

    private function pairRowForTx(int $supplierId, int $txId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM bank_transfer_matches
              WHERE supplier_id = ? AND (out_transaction_id = ? OR in_transaction_id = ?) LIMIT 1'
        );
        $stmt->execute([$supplierId, $txId, $txId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function otherTxId(array $pair, int $txId): int
    {
        return (int) ((int) $pair['out_transaction_id'] === $txId ? $pair['in_transaction_id'] : $pair['out_transaction_id']);
    }

    private function mirrorAccounts(array $a, array $b): bool
    {
        return $this->sameAccount(
            (string) ($a['recipient_account'] ?? ''),
            $a['recipient_bank'] ?? null,
            (string) ($b['counterparty_account'] ?? ''),
            $b['counterparty_bank'] ?? null,
        ) && $this->sameAccount(
            (string) ($a['counterparty_account'] ?? ''),
            $a['counterparty_bank'] ?? null,
            (string) ($b['recipient_account'] ?? ''),
            $b['recipient_bank'] ?? null,
        );
    }

    private function sameAccount(string $a, mixed $aBank, string $b, mixed $bBank): bool
    {
        $ac = AccountNumberNormalizer::canonical($a);
        $bc = AccountNumberNormalizer::canonical($b);
        if ($ac === null || $bc === null || $ac !== $bc) return false;
        $ab = AccountNumberNormalizer::canonicalBankCode(is_string($aBank) ? $aBank : null);
        $bb = AccountNumberNormalizer::canonicalBankCode(is_string($bBank) ? $bBank : null);
        return $ab === null || $bb === null || $ab === $bb;
    }

    /** @return array{debit:string,credit:string} */
    private function codes(int $supplierId, string $direction): array
    {
        $rule = $this->postingRules->resolve($supplierId, $direction === 'out'
            ? 'bank.transfer.own.out' : 'bank.transfer.own.in');
        return [
            'debit' => (string) (($rule['debit_account_code'] ?? null) ?: ($direction === 'out' ? '261' : '221')),
            'credit' => (string) (($rule['credit_account_code'] ?? null) ?: ($direction === 'out' ? '221' : '261')),
        ];
    }

    private function line(string $code, string $side, float $amount, string $currency, ?float $fx, float $foreign): array
    {
        $line = ['account_code' => $code, 'side' => $side, 'amount' => round($amount, 2)];
        if ($currency !== 'CZK' && $fx !== null) {
            $line['currency_code'] = $currency;
            $line['fx_rate'] = $fx;
            $line['amount_foreign'] = $foreign;
        }
        return $line;
    }

    private function loadTx(int $txId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT bt.*, bs.account_number AS recipient_account, bs.bank_code AS recipient_bank,
                    bs.currency AS statement_currency, bs.supplier_id AS statement_supplier_id
               FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.id = ?'
        );
        $stmt->execute([$txId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function effectiveCurrency(array $tx): string
    {
        return strtoupper((string) ($tx['currency'] ?? $tx['statement_currency'] ?? 'CZK'));
    }

    /**
     * Popis bankovního zápisu — tatáž SSOT jako u {@see BankPostingService}
     * ({@see \MyInvoice\Service\Accounting\JournalDescriptionBuilder::forBankRow()}).
     * Dřív tady stála okopírovaná verze téhož pravidla, takže se obě větve mohly
     * rozejít při první úpravě.
     *
     * @param array<string,mixed> $tx
     */
    private function entryDescription(array $tx): string
    {
        return \MyInvoice\Service\Accounting\JournalDescriptionBuilder::forBankRow($tx);
    }
}
