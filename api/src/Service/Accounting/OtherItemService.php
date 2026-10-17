<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\BankNoticeMutationGuard;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\OtherItemRepository;
use MyInvoice\Service\Accounting\Closing\DocumentSeriesService;
use MyInvoice\Service\Accounting\Dimension\DimensionDefaults;
use PDO;

final class OtherItemService
{
    private const KINDS = ['other', 'rent', 'loan', 'deposit', 'insurance', 'fee', 'claim'];

    public function __construct(
        private readonly Connection $db,
        private readonly OtherItemRepository $items,
        private readonly PostingService $posting,
        private readonly DocumentSeriesService $series,
        private readonly ChartOfAccountsRepository $accounts,
    ) {}

    public function get(int $supplierId, int $id): array
    {
        return $this->items->find($supplierId, $id)
            ?? throw new OtherItemException('not_found', 'Doklad nebyl nalezen.', 404);
    }

    public function list(int $supplierId, array $filters, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        return $this->items->list($supplierId, $filters, $page, $perPage);
    }

    public function create(int $supplierId, array $input, ?int $userId): array
    {
        $data = $this->normalize($supplierId, $input);
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $id = $this->items->insert($supplierId, $data, $userId);
            $this->items->replacePostingLines($supplierId, $id, $data['posting_lines']);
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->get($supplierId, $id);
    }

    public function update(int $supplierId, int $id, array $input, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $existing = $this->items->find($supplierId, $id, true)
                ?? throw new OtherItemException('not_found', 'Doklad nebyl nalezen.', 404);
            if ($existing['status'] !== 'draft') {
                throw new OtherItemException('not_draft', 'Upravovat lze jen koncept.', 409);
            }
            $data = $this->normalize($supplierId, $this->mergeStored($supplierId, $id, $existing, $input));
            $planned = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) AS total, MIN(due_on) AS first_due
                FROM other_item_installments WHERE supplier_id = ? AND other_item_id = ?');
            $planned->execute([$supplierId, $id]);
            $plan = $planned->fetch(PDO::FETCH_ASSOC);
            if ($plan['first_due'] !== null && (int) round((float) $plan['total'] * 100) !== (int) round($data['amount'] * 100)) {
                throw new OtherItemException('has_installments', 'Částka musí odpovídat součtu splátek. Před změnou částky zrušte splátkový kalendář.', 409);
            }
            if ($plan['first_due'] !== null && $data['issued_on'] > $plan['first_due']) {
                throw new OtherItemException('installment_date', 'Datum vzniku musí předcházet první splátce.', 409);
            }
            $this->items->updateDraft($supplierId, $id, $data, $userId);
            $this->items->replacePostingLines($supplierId, $id, $data['posting_lines']);
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->get($supplierId, $id);
    }

    /**
     * Částečná úprava: chybějící klíč = uložená hodnota, explicitní null/'' = smazat.
     * Rozpad protiúčtů (2+ řádky) bez klíče posting_lines zůstává, ledaže tělo posílá
     * jiný neprázdný protiúčet — to je přepnutí na jednu kontaci. Jediný uložený
     * protiřádek nese counter_account_code, takže jde s novou částkou.
     */
    private function mergeStored(int $supplierId, int $id, array $existing, array $input): array
    {
        $counter = trim((string) ($input['counter_account_code'] ?? ''));
        $keepLines = !array_key_exists('posting_lines', $input)
            && ($counter === '' || $counter === (string) ($existing['counter_account_code'] ?? ''));
        // Datum zaúčtování dosazené z data vzniku jde s novým datem vzniku (jako u nového dokladu).
        if (!array_key_exists('accounting_on', $input) && array_key_exists('issued_on', $input)
            && (string) $existing['accounting_on'] === (string) $existing['issued_on']) {
            $input['accounting_on'] = null;
        }
        foreach (['side', 'kind', 'title', 'partner_id', 'partner_name', 'issued_on', 'accounting_on', 'due_on',
            'currency', 'amount', 'variable_symbol', 'account_code', 'counter_account_code', 'note'] as $key) {
            if (!array_key_exists($key, $input)) {
                $input[$key] = $existing[$key] ?? null;
            }
        }
        if ($keepLines) {
            $stored = $this->items->postingLines($supplierId, $id);
            if (count($stored) > 1) {
                $input['posting_lines'] = $stored;
            }
        }
        return $input;
    }

    public function deleteDraft(int $supplierId, int $id): void
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $item = $this->items->find($supplierId, $id, true)
                ?? throw new OtherItemException('not_found', 'Doklad nebyl nalezen.', 404);
            if ($item['status'] !== 'draft') {
                throw new OtherItemException('not_draft', 'Smazat lze jen koncept.', 409);
            }
            $scheduled = $pdo->prepare('SELECT occurrence_index FROM other_item_schedule_occurrences
                WHERE supplier_id = ? AND item_id = ? LIMIT 1');
            $scheduled->execute([$supplierId, $id]);
            $occurrence = $scheduled->fetch(PDO::FETCH_ASSOC);
            if ($occurrence !== false && (int) $occurrence['occurrence_index'] === 0) {
                throw new OtherItemException('has_schedule', 'Zdrojový doklad rozvrhu nelze smazat.', 409);
            }
            if ($occurrence !== false) {
                $this->items->cancelDraft($supplierId, $id);
            } else {
                $this->items->softDeleteDraft($supplierId, $id);
            }
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function post(int $supplierId, int $id, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $item = $this->items->find($supplierId, $id, true)
                ?? throw new OtherItemException('not_found', 'Doklad nebyl nalezen.', 404);
            if ($item['status'] !== 'draft') {
                throw new OtherItemException('not_draft', 'Potvrdit lze jen koncept.', 409);
            }
            $doubleEntry = $this->isDoubleEntry($supplierId);
            $number = $this->series->next(
                $supplierId,
                $item['side'] === 'receivable' ? 'other_receivable' : 'other_payable',
                (int) substr((string) $item['issued_on'], 0, 4),
            );
            $entryId = null;
            if ($doubleEntry) {
                $postingLines = $item['posting_lines'];
                if ($postingLines === []) {
                    throw new OtherItemException('counter_account_required', 'Před zaúčtováním vyberte protiúčet.');
                }
                $account = (string) ($item['account_code'] ?: ($item['side'] === 'receivable' ? '315' : '325'));
                $this->assertPostingAccounts($account, $postingLines);
                $this->assertBalanceAccount($supplierId, $account, (string) $item['side']);
                $amount = (float) $item['amount_czk'];
                $receivable = $item['side'] === 'receivable';
                $lines = [['account_code' => $account, 'side' => $receivable ? 'debit' : 'credit', 'amount' => $amount]];
                foreach ($postingLines as $line) {
                    $lines[] = ['account_code' => $line['account_code'],
                        'side' => $receivable ? 'credit' : 'debit', 'amount' => $line['amount']];
                }
                $entryId = $this->posting->postDocument($supplierId, 'other_item', $id, $lines, [
                    'entry_date' => (string) ($item['accounting_on'] ?: $item['issued_on']),
                    'document_date' => (string) $item['issued_on'],
                    'document_no' => $number,
                    'description' => (string) $item['title'],
                    'posted' => true,
                    'user_id' => $userId,
                    'posted_by' => $userId,
                ]);
            }
            $this->items->setPosted($supplierId, $id, $doubleEntry ? 'posted' : 'confirmed', $number, $entryId);
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->get($supplierId, $id);
    }

    public function prepareEntryDeletion(int $supplierId, int $id, int $entryId, ?int $userId): void
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('OtherItemService::prepareEntryDeletion vyžaduje transakci.');
        }
        $item = $this->items->find($supplierId, $id, true)
            ?? throw new OtherItemException('not_found', 'Zdrojový doklad nebyl nalezen.', 404);
        if ($item['status'] !== 'posted' || (int) $item['journal_entry_id'] !== $entryId) {
            throw new OtherItemException('not_posted', 'Zápis není aktuálním zaúčtováním této položky.', 409);
        }
        $payments = $pdo->prepare('SELECT id FROM other_item_allocations
            WHERE supplier_id = ? AND other_item_id = ? AND reversed_on IS NULL FOR UPDATE');
        $payments->execute([$supplierId, $id]);
        if ($payments->fetchColumn() !== false) {
            throw new OtherItemException('has_payments', 'Doklad má spárované úhrady. Nejprve je odpojte.', 409);
        }
        $pdo->prepare("UPDATE other_item_schedules s SET status = 'paused'
            WHERE s.supplier_id = ? AND s.status = 'active'
              AND (s.source_item_id = ? OR EXISTS (
                  SELECT 1 FROM other_item_schedule_occurrences o
                  WHERE o.supplier_id = s.supplier_id AND o.schedule_id = s.id AND o.item_id = ?
              ))")->execute([$supplierId, $id, $id]);
        $this->items->setUnposted($supplierId, $id, $userId);
    }

    public function reverse(int $supplierId, int $id, string $reason, ?int $userId, ?string $entryDate = null): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new OtherItemException('reason_required', 'Uveďte důvod storna (alespoň 3 znaky).');
        }
        if ($entryDate !== null) self::assertDate($entryDate, 'entry_date');
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $item = $this->items->find($supplierId, $id, true)
                ?? throw new OtherItemException('not_found', 'Doklad nebyl nalezen.', 404);
            if (!in_array($item['status'], ['posted', 'confirmed'], true)) {
                throw new OtherItemException('not_posted', 'Stornovat lze jen potvrzený doklad.', 409);
            }
            if ((float) $item['paid_amount'] > 0) {
                throw new OtherItemException('has_payments', 'Doklad má spárované úhrady. Nejprve je odpojte.', 409);
            }
            $entryId = $item['journal_entry_id'] !== null
                ? $this->posting->reverse($supplierId, (int) $item['journal_entry_id'], [
                    'entry_date' => $entryDate,
                    'description' => 'Storno ' . $item['document_no'] . ': ' . $reason,
                    'user_id' => $userId,
                    'posted_by' => $userId,
                ])
                : null;
            $this->items->setReversed($supplierId, $id, $entryId !== null ? 'reversed' : 'cancelled', $entryId);
            $pdo->prepare("UPDATE other_item_schedules SET status = 'paused'
                WHERE supplier_id = ? AND source_item_id = ? AND status = 'active'")
                ->execute([$supplierId, $id]);
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->get($supplierId, $id);
    }

    public function repost(int $supplierId, int $id, array $input, ?int $userId): array
    {
        $reason = trim((string) ($input['reason'] ?? ''));
        $date = (string) ($input['entry_date'] ?? '');
        $account = trim((string) ($input['account_code'] ?? ''));
        $counter = trim((string) ($input['counter_account_code'] ?? ''));
        if (mb_strlen($reason) < 3) {
            throw new OtherItemException('reason_required', 'Uveďte důvod přeúčtování (alespoň 3 znaky).');
        }
        self::assertDate($date, 'entry_date');
        if (strlen($counter) > 20 || strlen($account) > 20) {
            throw new OtherItemException('invalid_account', 'Vyberte platný účet a protiúčet.');
        }
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $item = $this->items->find($supplierId, $id, true)
                ?? throw new OtherItemException('not_found', 'Doklad nebyl nalezen.', 404);
            if ($item['status'] !== 'posted' || $item['journal_entry_id'] === null) {
                throw new OtherItemException('not_posted', 'Přeúčtovat lze jen zaúčtovaný doklad.', 409);
            }
            if ((float) $item['paid_amount'] > 0) {
                throw new OtherItemException('has_payments', 'Doklad má spárované úhrady. Nejprve je odpojte.', 409);
            }
            $account = $account ?: (string) ($item['account_code'] ?: ($item['side'] === 'receivable' ? '315' : '325'));
            $postingLines = array_key_exists('posting_lines', $input)
                ? $this->normalizePostingLines($input['posting_lines'], (float) $item['amount_czk'])
                : ($counter !== '' ? [['account_code' => $counter, 'amount' => (float) $item['amount_czk']]]
                    : $item['posting_lines']);
            if ($postingLines === []) {
                throw new OtherItemException('counter_account_required', 'Před přeúčtováním vyberte protiúčet.');
            }
            $this->assertPostingAccounts($account, $postingLines);
            $this->assertBalanceAccount($supplierId, $account, (string) $item['side']);
            if ($account === (string) ($item['account_code'] ?: ($item['side'] === 'receivable' ? '315' : '325'))
                && $postingLines === $item['posting_lines']) {
                throw new OtherItemException('unchanged_accounts', 'Změňte alespoň jeden účet.');
            }
            $reversalId = $this->posting->reverse($supplierId, (int) $item['journal_entry_id'], [
                'entry_date' => $date, 'description' => 'Přeúčtování ' . $item['document_no'] . ': ' . $reason,
                'user_id' => $userId, 'posted_by' => $userId,
            ]);
            $receivable = $item['side'] === 'receivable';
            $amount = (float) $item['amount_czk'];
            $lines = [['account_code' => $account, 'side' => $receivable ? 'debit' : 'credit', 'amount' => $amount]];
            foreach ($postingLines as $line) {
                $lines[] = ['account_code' => $line['account_code'],
                    'side' => $receivable ? 'credit' : 'debit', 'amount' => $line['amount']];
            }
            $entryId = $this->posting->postDocument($supplierId, 'other_item', $id, $lines, [
                'entry_date' => $date, 'document_date' => (string) $item['issued_on'],
                'document_no' => (string) $item['document_no'], 'description' => (string) $item['title'],
                'posted' => true, 'user_id' => $userId, 'posted_by' => $userId,
            ]);
            $this->items->setReposted($supplierId, $id, $account,
                count($postingLines) === 1 ? $postingLines[0]['account_code'] : null,
                $date, $entryId, $reversalId, $userId);
            $this->items->replacePostingLines($supplierId, $id, $postingLines);
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->get($supplierId, $id);
    }

    public function allocations(int $supplierId, int $id): array
    {
        $this->get($supplierId, $id);
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, bank_transaction_id, cash_document_id, amount, payment_on, reversed_on, created_at
               FROM other_item_allocations WHERE supplier_id = ? AND other_item_id = ? ORDER BY payment_on, id'
        );
        $stmt->execute([$supplierId, $id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function paymentCandidates(int $supplierId, int $id, string $search, int $limit,
        bool $includeBank = true, bool $includeCash = true): array
    {
        $item = $this->get($supplierId, $id);
        if ((!$includeBank && !$includeCash)
            || !in_array($item['status'], ['posted', 'confirmed'], true) || $item['currency'] !== 'CZK') return [];
        $limit = max(1, min(50, $limit));
        $term = '%' . addcslashes(trim($search), '%_\\') . '%';
        $rows = [];
        if ($includeBank) {
            $direction = $item['side'] === 'receivable' ? 'bt.amount > 0' : 'bt.amount < 0';
            $stmt = $this->db->pdo()->prepare(
                'SELECT bt.id, bt.posted_at payment_on, ABS(bt.amount) amount,
                        COALESCE(bt.currency, bs.currency, \'CZK\') currency,
                        COALESCE(bt.description, bt.counterparty_name, \'\') description,
                        COALESCE(a.used_amount, 0) used_amount
                   FROM bank_transactions bt
                   JOIN bank_statements bs ON bs.id = bt.statement_id AND bs.supplier_id = ?
              LEFT JOIN (SELECT bank_transaction_id, SUM(amount) used_amount FROM other_item_allocations
                         WHERE supplier_id = ? AND bank_transaction_id IS NOT NULL AND reversed_on IS NULL
                         GROUP BY bank_transaction_id) a
                     ON a.bank_transaction_id = bt.id
                  WHERE ' . \MyInvoice\Service\Bank\BankNoticeConfirmationScope::activeSql('bt') . ' AND ' . $direction . '
                    AND COALESCE(bt.currency, bs.currency, \'CZK\') = \'CZK\'
                    AND bt.match_status = \'unmatched\' AND bt.matched_invoice_id IS NULL
                    AND ABS(bt.amount) > COALESCE(a.used_amount, 0)
                    AND NOT EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.bank_transaction_id = bt.id)
                    AND NOT EXISTS (SELECT 1 FROM invoice_payments ip WHERE ip.bank_transaction_id = bt.id)
                    AND NOT EXISTS (SELECT 1 FROM payroll_payment_matches ppm WHERE ppm.bank_transaction_id = bt.id)
                    AND NOT EXISTS (SELECT 1 FROM tax_advance_schedules tas WHERE tas.matched_transaction_id = bt.id)
                    AND (
                        NOT EXISTS (SELECT 1 FROM other_item_allocations previous
                                     WHERE previous.supplier_id = bs.supplier_id
                                       AND previous.bank_transaction_id = bt.id AND previous.reversed_on IS NOT NULL)
                        OR EXISTS (SELECT 1 FROM other_item_allocations previous
                                    WHERE previous.supplier_id = bs.supplier_id
                                      AND previous.bank_transaction_id = bt.id
                                      AND previous.other_item_id = ? AND previous.reversed_on IS NOT NULL)
                    )
                    AND (bt.variable_symbol LIKE ? OR bt.counterparty_name LIKE ? OR bt.description LIKE ?)
                  ORDER BY bt.posted_at DESC, bt.id DESC LIMIT ' . $limit
            );
            $stmt->execute([$supplierId, $supplierId, $id, $term, $term, $term]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rows[] = [
                    'source' => 'bank', 'id' => (int) $row['id'], 'payment_on' => $row['payment_on'],
                    'amount' => (float) $row['amount'] - (float) $row['used_amount'],
                    'currency' => $row['currency'], 'description' => $row['description'],
                    'used_amount' => (float) $row['used_amount'],
                ];
            }
        }
        if ($includeCash) {
            $cashDirection = $item['side'] === 'receivable' ? 'in' : 'out';
            $stmt = $this->db->pdo()->prepare(
                'SELECT cd.id, cd.issue_date payment_on, cd.total_amount amount, cd.currency_code currency,
                        cd.description, COALESCE(a.used_amount, 0) used_amount
                   FROM cash_documents cd
              LEFT JOIN (SELECT cash_document_id, SUM(amount) used_amount FROM other_item_allocations
                         WHERE supplier_id = ? AND cash_document_id IS NOT NULL AND reversed_on IS NULL
                         GROUP BY cash_document_id) a
                     ON a.cash_document_id = cd.id
                  WHERE cd.supplier_id = ? AND cd.doc_type = ? AND cd.purpose = \'other\'
                    AND cd.status = \'posted\' AND cd.currency_code = \'CZK\'
                    AND cd.invoice_id IS NULL AND cd.purchase_invoice_id IS NULL
                    AND cd.total_amount > COALESCE(a.used_amount, 0)
                    AND (
                        NOT EXISTS (SELECT 1 FROM other_item_allocations previous
                                     WHERE previous.supplier_id = cd.supplier_id
                                       AND previous.cash_document_id = cd.id AND previous.reversed_on IS NOT NULL)
                        OR EXISTS (SELECT 1 FROM other_item_allocations previous
                                    WHERE previous.supplier_id = cd.supplier_id
                                      AND previous.cash_document_id = cd.id
                                      AND previous.other_item_id = ? AND previous.reversed_on IS NOT NULL)
                    )
                    AND (cd.doc_number LIKE ? OR cd.partner_name LIKE ? OR cd.description LIKE ?)
                  ORDER BY cd.issue_date DESC, cd.id DESC LIMIT ' . $limit
            );
            $stmt->execute([$supplierId, $supplierId, $cashDirection, $id, $term, $term, $term]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rows[] = [
                    'source' => 'cash', 'id' => (int) $row['id'], 'payment_on' => $row['payment_on'],
                    'amount' => (float) $row['amount'] - (float) $row['used_amount'],
                    'currency' => $row['currency'], 'description' => $row['description'],
                    'used_amount' => (float) $row['used_amount'],
                ];
            }
        }
        if ($item['journal_entry_id'] !== null) {
            $accountId = $this->settlementAccountId($supplierId, $item);
            $rows = array_values(array_filter($rows, function (array $row) use ($supplierId, $accountId, $item): bool {
                try {
                    $this->assertPaymentPosting($supplierId, $row['source'], $row['id'],
                        $accountId, (string) $item['side'],
                        $this->allocatedOnAccount($supplierId, $row['source'], $row['id'], $accountId) + 0.01);
                    return true;
                } catch (OtherItemException) {
                    return false;
                }
            }));
        }
        $rows = array_map(static function (array $row): array { unset($row['used_amount']); return $row; }, $rows);
        usort($rows, static fn (array $a, array $b): int => [$b['payment_on'], $b['id']] <=> [$a['payment_on'], $a['id']]);
        return array_slice($rows, 0, $limit);
    }

    public function allocate(int $supplierId, int $id, array $input, ?int $userId): array
    {
        return (new BankNoticeMutationGuard($this->db))->mutate($supplierId, isset($input['bank_transaction_id']) ? (int) $input['bank_transaction_id'] : null, fn () => $this->allocateMutable($supplierId, $id, $input, $userId));
    }

    private function allocateMutable(int $supplierId, int $id, array $input, ?int $userId): array
    {
        $bankId = isset($input['bank_transaction_id']) ? (int) $input['bank_transaction_id'] : 0;
        $cashId = isset($input['cash_document_id']) ? (int) $input['cash_document_id'] : 0;
        if (($bankId > 0) === ($cashId > 0)) {
            throw new OtherItemException('invalid_payment', 'Zvolte právě jednu bankovní nebo pokladní platbu.');
        }
        $amount = filter_var($input['amount'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($amount === false || !is_finite((float) $amount) || $amount <= 0
            || round((float) $amount, 2) !== (float) $amount) {
            throw new OtherItemException('invalid_amount', 'Párovaná částka musí být kladná a zadaná na haléře.');
        }
        $amount = round((float) $amount, 2);
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $item = $this->items->find($supplierId, $id, true)
                ?? throw new OtherItemException('not_found', 'Doklad nebyl nalezen.', 404);
            if (!in_array($item['status'], ['posted', 'confirmed'], true)) {
                throw new OtherItemException('not_posted', 'Úhradu lze přiřadit jen potvrzenému dokladu.', 409);
            }
            if ($item['currency'] !== 'CZK') {
                throw new OtherItemException('currency_unsupported', 'Párování cizoměnové položky zatím vyžaduje kurzové vypořádání v účetnictví.', 409);
            }
            $payment = $bankId > 0
                ? $this->bankPayment($supplierId, $bankId, (string) $item['side'])
                : $this->cashPayment($supplierId, $cashId, (string) $item['side']);
            if ((string) $payment['currency'] !== 'CZK') {
                throw new OtherItemException('currency_mismatch', 'Měna úhrady se liší od měny dokladu.');
            }
            if ($amount > round((float) $item['remaining_amount'], 2)) {
                throw new OtherItemException('overpayment', 'Párovaná částka převyšuje zbývající dluh.', 409);
            }
            $stmt = $pdo->prepare(
                'SELECT COALESCE(SUM(amount), 0) FROM other_item_allocations
                  WHERE supplier_id = ? AND reversed_on IS NULL AND '
                    . ($bankId > 0 ? 'bank_transaction_id' : 'cash_document_id') . ' = ?'
            );
            $stmt->execute([$supplierId, $bankId > 0 ? $bankId : $cashId]);
            $used = (float) $stmt->fetchColumn();
            if ($amount + $used > abs((float) $payment['amount']) + 0.001) {
                throw new OtherItemException('payment_overallocated', 'Platba nemá dostatek volné částky.', 409);
            }
            if ($item['journal_entry_id'] !== null) {
                $accountId = $this->settlementAccountId($supplierId, $item);
                $this->assertPaymentPosting($supplierId, $bankId > 0 ? 'bank' : 'cash',
                    $bankId > 0 ? $bankId : $cashId, $accountId, (string) $item['side'],
                    $amount + $this->allocatedOnAccount($supplierId, $bankId > 0 ? 'bank' : 'cash',
                        $bankId > 0 ? $bankId : $cashId, $accountId));
            }
            $sourceColumn = $bankId > 0 ? 'bank_transaction_id' : 'cash_document_id';
            $sourceId = $bankId > 0 ? $bankId : $cashId;
            $previous = $pdo->prepare(
                "SELECT id, other_item_id, amount, reversed_on FROM other_item_allocations
                  WHERE supplier_id = ? AND {$sourceColumn} = ? FOR UPDATE"
            );
            $previous->execute([$supplierId, $sourceId]);
            $prior = false;
            $hasReversed = false;
            foreach ($previous->fetchAll(PDO::FETCH_ASSOC) as $allocation) {
                if ($allocation['reversed_on'] !== null) $hasReversed = true;
                if ((int) $allocation['other_item_id'] === $id) $prior = $allocation;
            }
            if ($hasReversed && $prior === false) {
                throw new OtherItemException('payment_reallocation_conflict',
                    'Po stornu lze platbu znovu přiřadit jen k původní položce.', 409);
            }
            if ($prior !== false) {
                if ($prior['reversed_on'] === null) {
                    throw new OtherItemException('payment_used', 'Tato platba už je k dokladu přiřazena.', 409);
                }
                if (abs((float) $prior['amount'] - $amount) > 0.001) {
                    throw new OtherItemException('payment_reallocation_amount',
                        'Po stornu lze znovu přiřadit stejnou částku původní úhrady.', 409);
                }
                $reactivate = $pdo->prepare(
                    'UPDATE other_item_allocations SET reversed_on = NULL
                      WHERE id = ? AND supplier_id = ? AND reversed_on IS NOT NULL'
                );
                $reactivate->execute([(int) $prior['id'], $supplierId]);
                $this->posting->restampDimensions($supplierId, $bankId > 0 ? 'bank' : 'cash', $sourceId, true);
                if ($ownTx) $pdo->commit();
                return $this->get($supplierId, $id);
            }
            $stmt = $pdo->prepare(
                'INSERT INTO other_item_allocations
                   (supplier_id, other_item_id, bank_transaction_id, cash_document_id, amount, payment_on, created_by)
                 VALUES (?,?,?,?,?,?,?)'
            );
            $stmt->execute([$supplierId, $id, $bankId ?: null, $cashId ?: null, $amount, $payment['payment_on'], $userId]);
            // Úhrada přebírá dimenze položky (DimensionDefaults::bankDocuments / forCash).
            $this->posting->restampDimensions($supplierId, $bankId > 0 ? 'bank' : 'cash', $sourceId, true);
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->get($supplierId, $id);
    }

    public function unallocate(int $supplierId, int $id, int $allocationId): array
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $this->items->find($supplierId, $id, true)
                ?? throw new OtherItemException('not_found', 'Doklad nebyl nalezen.', 404);
            $payment = $pdo->prepare('SELECT bank_transaction_id, cash_document_id FROM other_item_allocations
                WHERE id = ? AND supplier_id = ? AND other_item_id = ?');
            $payment->execute([$allocationId, $supplierId, $id]);
            $paymentRow = $payment->fetch(PDO::FETCH_ASSOC);
            // Dimenze, které úhrada od položky převzala; po odpojení se z jejích řádků odeberou.
            $itemDims = (new DimensionDefaults($this->db))->effectiveDimensions($supplierId, 'other_item', $id);
            $released = $itemDims['header'] + array_fill_keys(array_keys($itemDims['splits']), null);
            $stmt = $pdo->prepare('DELETE FROM other_item_allocations WHERE id = ? AND supplier_id = ? AND other_item_id = ?');
            $stmt->execute([$allocationId, $supplierId, $id]);
            if ($stmt->rowCount() === 0) throw new OtherItemException('payment_not_found', 'Úhrada nebyla nalezena.', 404);
            if ($paymentRow !== false) {
                $this->posting->restampDimensions($supplierId,
                    $paymentRow['bank_transaction_id'] !== null ? 'bank' : 'cash',
                    (int) ($paymentRow['bank_transaction_id'] ?? $paymentRow['cash_document_id']), true, $released);
            }
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->get($supplierId, $id);
    }

    private function bankPayment(int $supplierId, int $id, string $side): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT bt.amount, bt.posted_at payment_on, COALESCE(bt.currency, bs.currency, \'CZK\') currency,
                    bt.matched_invoice_id, bt.match_status
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id AND bs.supplier_id = ?
              WHERE bt.id = ? FOR UPDATE'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) throw new OtherItemException('payment_not_found', 'Bankovní platba nebyla nalezena.', 404);
        if ($side === 'receivable' && (float) $row['amount'] <= 0 || $side === 'payable' && (float) $row['amount'] >= 0) {
            throw new OtherItemException('payment_direction', 'Směr bankovní platby neodpovídá dokladu.');
        }
        if ($row['matched_invoice_id'] !== null || $row['match_status'] !== 'unmatched') {
            throw new OtherItemException('payment_used', 'Bankovní platba už je spárována s jiným dokladem.', 409);
        }
        foreach (['payment_matches' => 'bank_transaction_id', 'invoice_payments' => 'bank_transaction_id',
                  'payroll_payment_matches' => 'bank_transaction_id', 'tax_advance_schedules' => 'matched_transaction_id'] as $table => $column) {
            $stmt = $this->db->pdo()->prepare("SELECT 1 FROM {$table} WHERE {$column} = ? LIMIT 1");
            $stmt->execute([$id]);
            if ($stmt->fetchColumn()) throw new OtherItemException('payment_used', 'Bankovní platba už je spárována s jiným dokladem.', 409);
        }
        return $row;
    }

    private function cashPayment(int $supplierId, int $id, string $side): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT total_amount amount, issue_date payment_on, currency_code currency,
                    doc_type, purpose, invoice_id, purchase_invoice_id
               FROM cash_documents WHERE id = ? AND supplier_id = ? AND status = \'posted\' FOR UPDATE'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) throw new OtherItemException('payment_not_found', 'Pokladní platba nebyla nalezena.', 404);
        if ($row['purpose'] !== 'other' || $row['invoice_id'] !== null || $row['purchase_invoice_id'] !== null
            || $side === 'receivable' && $row['doc_type'] !== 'in'
            || $side === 'payable' && $row['doc_type'] !== 'out') {
            throw new OtherItemException('payment_used', 'Pokladní doklad není volnou úhradou této položky.', 409);
        }
        return $row;
    }

    private function settlementAccountId(int $supplierId, array $item): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.account_id
               FROM journal_entries e
               JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
              WHERE e.id = ? AND e.supplier_id = ? AND e.source_type = \'other_item\' AND e.source_id = ?
                AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL AND l.side = ?
              LIMIT 1'
        );
        $stmt->execute([(int) $item['journal_entry_id'], $supplierId, (int) $item['id'],
            $item['side'] === 'receivable' ? 'debit' : 'credit']);
        $accountId = $stmt->fetchColumn();
        if ($accountId === false) {
            throw new OtherItemException('payment_not_posted', 'Doklad nemá platný účetní zápis.', 409);
        }
        return (int) $accountId;
    }

    private function assertPaymentPosting(int $supplierId, string $sourceType, int $sourceId,
        int $accountId, string $itemSide, float $allocatedTotal): void
    {
        $requiredSide = $itemSide === 'receivable' ? 'credit' : 'debit';
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(CASE WHEN l.side = ? THEN l.signed_amount ELSE -l.signed_amount END), 0)
               FROM journal_entries e
               JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
              WHERE e.supplier_id = ? AND e.source_type = ? AND e.source_id = ?
                AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL
                AND l.account_id = ?'
        );
        $stmt->execute([$requiredSide, $supplierId, $sourceType, $sourceId, $accountId]);
        if ((float) $stmt->fetchColumn() + 0.001 < $allocatedTotal) {
            throw new OtherItemException('payment_not_posted', 'Platbu nejprve zaúčtujte proti účtu pohledávky nebo závazku.', 409);
        }
    }

    private function allocatedOnAccount(int $supplierId, string $sourceType, int $sourceId,
        int $accountId): float
    {
        $column = $sourceType === 'bank' ? 'bank_transaction_id' : 'cash_document_id';
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(a.amount), 0)
               FROM other_item_allocations a
               JOIN other_items oi ON oi.id = a.other_item_id AND oi.supplier_id = a.supplier_id
              WHERE a.supplier_id = ? AND a.{$column} = ? AND a.reversed_on IS NULL
                AND EXISTS (
                    SELECT 1 FROM journal_entry_lines l
                     WHERE l.entry_id = oi.journal_entry_id AND l.supplier_id = oi.supplier_id
                       AND l.account_id = ?
                       AND l.side = CASE WHEN oi.side = 'receivable' THEN 'debit' ELSE 'credit' END
                )"
        );
        $stmt->execute([$supplierId, $sourceId, $accountId]);
        return (float) $stmt->fetchColumn();
    }

    public function belongsToAutoPostSchedule(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM other_item_schedule_occurrences o
            JOIN other_item_schedules s ON s.id = o.schedule_id AND s.supplier_id = o.supplier_id
            WHERE o.supplier_id = ? AND o.item_id = ? AND s.auto_post = 1 LIMIT 1');
        $stmt->execute([$supplierId, $id]);
        return (bool) $stmt->fetchColumn();
    }

    public function isDoubleEntry(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return $stmt->fetchColumn() !== 'tax_evidence';
    }

    private function assertBalanceAccount(int $supplierId, string $code, string $side): void
    {
        $account = $this->accounts->findByCode($supplierId, $code);
        if ($account === null || !$account['is_active'] || $account['account_type'] !== 'asset' && $account['account_type'] !== 'liability') {
            throw new OtherItemException('invalid_account', 'Pohledávka nebo závazek musí být veden na aktivním rozvahovém účtu.');
        }
        if ($side === 'receivable' && $account['account_type'] !== 'asset'
            || $side === 'payable' && $account['account_type'] !== 'liability') {
            throw new OtherItemException('invalid_account_side', 'Vybraný účet neodpovídá typu dokladu.');
        }
    }

    private function assertPostingAccounts(string $settlementAccount, array $postingLines): void
    {
        foreach ($postingLines as $line) {
            $code = $line['account_code'];
            if ($code === $settlementAccount || preg_match('/^(315|325)(?:$|[.\-])/', $code)) {
                throw new OtherItemException('same_accounts', 'Saldokontní účet smí být pouze na straně pohledávky nebo závazku.');
            }
        }
    }

    private function normalizePostingLines(mixed $raw, float $total): array
    {
        if (!is_array($raw) || !array_is_list($raw) || count($raw) < 1 || count($raw) > 50) {
            throw new OtherItemException('invalid_posting_lines', 'Zadejte 1 až 50 protiřádků.');
        }
        $lines = [];
        $sum = 0;
        foreach ($raw as $row) {
            if (!is_array($row)) {
                throw new OtherItemException('invalid_posting_lines', 'Neplatný řádek kontace.');
            }
            $code = trim((string) ($row['account_code'] ?? ''));
            $amount = filter_var($row['amount'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($code === '' || strlen($code) > 20 || $amount === false || !is_finite((float) $amount)
                || $amount <= 0 || round((float) $amount, 2) !== (float) $amount) {
                throw new OtherItemException('invalid_posting_lines', 'Každý protiřádek potřebuje účet a kladnou částku na haléře.');
            }
            $sum += (int) round((float) $amount * 100);
            $lines[] = ['account_code' => $code, 'amount' => round((float) $amount, 2)];
        }
        if ($sum !== (int) round($total * 100)) {
            throw new OtherItemException('posting_lines_total', 'Součet protiřádků musí odpovídat celé částce dokladu.');
        }
        return $lines;
    }

    private function normalize(int $supplierId, array $input): array
    {
        $side = (string) ($input['side'] ?? '');
        if (!in_array($side, ['receivable', 'payable'], true)) {
            throw new OtherItemException('invalid_side', 'Vyberte pohledávku nebo závazek.');
        }
        $kind = (string) ($input['kind'] ?? 'other');
        if (!in_array($kind, self::KINDS, true)) {
            throw new OtherItemException('invalid_kind', 'Neplatný druh dokladu.');
        }
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 255) {
            throw new OtherItemException('invalid_title', 'Zadejte popis dokladu do 255 znaků.');
        }
        $issued = (string) ($input['issued_on'] ?? '');
        $due = (string) ($input['due_on'] ?? '');
        self::assertDate($issued, 'issued_on');
        self::assertDate($due, 'due_on');
        $accounting = $input['accounting_on'] ?? null;
        if ($accounting !== null && $accounting !== '') self::assertDate((string) $accounting, 'accounting_on');
        else $accounting = $issued;
        $amount = filter_var($input['amount'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($amount === false || !is_finite((float) $amount) || $amount <= 0
            || $amount > 999999999999.99 || round((float) $amount, 2) !== (float) $amount) {
            throw new OtherItemException('invalid_amount', 'Částka musí být kladná a zadaná na haléře.');
        }
        $currency = strtoupper(trim((string) ($input['currency'] ?? 'CZK')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new OtherItemException('invalid_currency', 'Neplatná měna.');
        }
        if ($currency !== 'CZK') {
            throw new OtherItemException('currency_unsupported', 'Ostatní pohledávky a závazky lze nyní pořídit jen v CZK; cizoměnové položky vyžadují kurzové přecenění.');
        }
        $rate = 1.0;
        $partnerId = isset($input['partner_id']) && $input['partner_id'] !== '' ? (int) $input['partner_id'] : null;
        $partnerName = self::optional($input['partner_name'] ?? null, 190);
        if ($partnerId !== null) {
            $stmt = $this->db->pdo()->prepare('SELECT company_name FROM clients WHERE id = ? AND supplier_id = ?');
            $stmt->execute([$partnerId, $supplierId]);
            $clientName = $stmt->fetchColumn();
            if ($clientName === false) throw new OtherItemException('invalid_partner', 'Protistrana nebyla nalezena.', 404);
            // API/MCP posílá často jen partner_id; editor, přehled i deník zobrazují partner_name.
            $partnerName ??= self::optional(mb_substr((string) $clientName, 0, 190), 190);
        }
        $vs = trim((string) ($input['variable_symbol'] ?? ''));
        if ($vs !== '' && !preg_match('/^[0-9]{1,20}$/', $vs)) {
            throw new OtherItemException('invalid_variable_symbol', 'Variabilní symbol smí obsahovat jen číslice.');
        }
        $account = trim((string) ($input['account_code'] ?? ''));
        $counter = trim((string) ($input['counter_account_code'] ?? ''));
        if (strlen($account) > 20 || strlen($counter) > 20) {
            throw new OtherItemException('invalid_account', 'Neplatný kód účtu.');
        }
        // null/[] = bez rozpadu (jediný protiúčet), stejně jako chybějící klíč u nového dokladu.
        $postingLines = ($input['posting_lines'] ?? []) !== []
            ? $this->normalizePostingLines($input['posting_lines'], round((float) $amount * $rate, 2))
            : [];
        if ($postingLines !== []) {
            $counter = count($postingLines) === 1 ? $postingLines[0]['account_code'] : '';
        }
        return [
            'side' => $side, 'kind' => $kind, 'title' => $title,
            'partner_id' => $partnerId,
            'partner_name' => $partnerName,
            'issued_on' => $issued, 'accounting_on' => $accounting, 'due_on' => $due,
            'currency' => $currency, 'amount' => round((float) $amount, 2),
            'exchange_rate' => $rate, 'amount_czk' => round((float) $amount * $rate, 2),
            'variable_symbol' => $vs === '' ? null : $vs,
            'account_code' => $account === '' ? null : $account,
            'counter_account_code' => $counter === '' ? null : $counter,
            'posting_lines' => $postingLines,
            'note' => self::optional($input['note'] ?? null, 10000),
        ];
    }

    private static function optional(mixed $value, int $limit): ?string
    {
        $text = trim((string) ($value ?? ''));
        if (mb_strlen($text) > $limit) throw new OtherItemException('text_too_long', 'Text je příliš dlouhý.');
        return $text === '' ? null : $text;
    }

    private static function assertDate(string $date, string $field): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new OtherItemException('invalid_date', 'Neplatné datum ' . $field . '.');
        }
    }
}
