<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\OtherItemRepository;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use PDO;

/**
 * Párování bankovního pohybu s ostatní pohledávkou nebo závazkem z dialogu „Spárovat"
 * u pohybu. Protějšek výběru platby z detailu položky ({@see OtherItemService::paymentCandidates}).
 *
 * Přiřazení samo nic nového nepočítá: nezaúčtovaný pohyb zaúčtuje ručním zápisem
 * banka / účet pohledávky či závazku ({@see BankPostingService::postManual}) a úhradu
 * přiřadí přes {@see OtherItemService::allocate}, který hlídá přeplatek, měnu, směr
 * i zaúčtování proti správnému účtu.
 */
final class OtherItemBankMatchService
{
    private const AMOUNT_TOLERANCE = 0.01;

    public function __construct(
        private readonly Connection $db,
        private readonly OtherItemService $items,
        private readonly BankPostingService $bankPosting,
        private readonly OtherItemRepository $repository,
    ) {}

    /**
     * Otevřené ostatní položky, které jde k pohybu přiřadit. Bez hledání nabídne shodu
     * VS, účtu protistrany, částky otevřené splátky nebo celého zbývajícího dluhu,
     * s hledáním položky podle čísla, VS, partnera či názvu bez ohledu na částku.
     * Pořadí a důvod shody určuje {@see OtherItemPaymentCandidateRanker}.
     *
     * Částka splátky se v předvýběru porovnává s celou splátkou; zbytek částečně
     * uhrazené splátky najde položku jen přes VS, účet nebo hledání.
     *
     * @return list<array{type:string,id:int,ref:?string,amount:float,currency:string,
     *   converted_amount:null,converted_currency:null,issue_date:string,due_date:?string,
     *   party:?string,paid:bool,currency_mismatch:bool,side:string,match_score:int,
     *   match_confidence:float,match_reasons:list<string>,installment:?array<string,mixed>}>
     */
    public function candidates(int $supplierId, int $txId, string $search = '', int $limit = 25): array
    {
        $tx = $this->freeTransaction($supplierId, $txId, false);
        if ($tx === null) return [];
        $limit = max(1, min(50, $limit));
        $free = round(abs((float) $tx['amount']) - (float) $tx['used_amount'], 2);
        $account = OtherItemCounterpartyAccount::fromTransaction($tx['counterparty_account'], $tx['counterparty_bank']);
        $remaining = 'oi.amount - oi.settled_outside_amount - COALESCE(p.paid_amount, 0)';
        $params = [$supplierId, $supplierId, (float) $tx['amount'] > 0 ? 'receivable' : 'payable'];
        $where = '';
        if ($tx['locked_item_ids'] !== []) {
            // Po stornu přiřazení smí platba zpět jen k původním položkám (pravidlo allocate()).
            $where .= ' AND oi.id IN (' . implode(',', array_fill(0, count($tx['locked_item_ids']), '?')) . ')';
            array_push($params, ...$tx['locked_item_ids']);
        }
        $search = trim($search);
        if ($search !== '') {
            $term = '%' . addcslashes($search, '%_\\') . '%';
            $where .= ' AND (oi.document_no LIKE ? OR oi.variable_symbol LIKE ? OR oi.partner_name LIKE ? OR oi.title LIKE ?)';
            array_push($params, $term, $term, $term, $term);
        } else {
            $where .= " AND (ABS({$remaining} - ?) <= ? OR (oi.variable_symbol <> '' AND oi.variable_symbol = ?)
                OR EXISTS (SELECT 1 FROM other_item_installments i
                            WHERE i.supplier_id = oi.supplier_id AND i.other_item_id = oi.id AND ABS(i.amount - ?) <= ?)
                OR (? <> '' AND oi.counterparty_account = ?))";
            array_push($params, $free, self::AMOUNT_TOLERANCE, (string) ($tx['variable_symbol'] ?? ''),
                $free, self::AMOUNT_TOLERANCE, (string) ($account['account'] ?? ''), (string) ($account['account'] ?? ''));
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT oi.id, oi.document_no, oi.title, oi.partner_name, oi.issued_on, oi.due_on, oi.side,
                    oi.journal_entry_id, oi.account_code, oi.variable_symbol, oi.counterparty_account,
                    oi.counterparty_bank_code, {$remaining} AS remaining_amount
               FROM other_items oi
          LEFT JOIN (SELECT other_item_id, SUM(amount) paid_amount FROM other_item_allocations
                      WHERE supplier_id = ? AND reversed_on IS NULL GROUP BY other_item_id) p
                 ON p.other_item_id = oi.id
              WHERE oi.supplier_id = ? AND oi.side = ? AND oi.status IN ('confirmed', 'posted')
                AND oi.deleted_at IS NULL AND oi.currency = 'CZK'
                AND {$remaining} > 0.005{$where}
              ORDER BY ABS({$remaining} - ?) <= ? DESC, oi.due_on DESC, oi.id DESC
              LIMIT {$limit}"
        );
        // Shoda částky první — stejný VS mívá u jednoho partnera víc položek.
        array_push($params, $free, self::AMOUNT_TOLERANCE);
        $stmt->execute($params);
        $posted = $this->hasLiveEntry($supplierId, $txId);
        $found = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // Pohyb zaúčtovaný jinam by potvrzení odmítlo, stejně filtruje výběr z detailu položky.
            if ($posted && !$this->postedOnItemAccount($supplierId, $txId, $row)) continue;
            $found[] = $row;
        }
        $coverage = $this->repository->installmentCoverage($supplierId, array_map(
            static fn (array $row): int => (int) $row['id'], $found));
        $payment = [
            'amount' => $free, 'variable_symbol' => $tx['variable_symbol'],
            'counterparty_account' => $tx['counterparty_account'], 'counterparty_bank' => $tx['counterparty_bank'],
            'payment_on' => $tx['posted_at'],
        ];
        $rows = [];
        foreach ($found as $row) {
            $match = OtherItemPaymentCandidateRanker::evaluate($payment, [
                'variable_symbol' => $row['variable_symbol'], 'counterparty_account' => $row['counterparty_account'],
                'counterparty_bank_code' => $row['counterparty_bank_code'],
                'remaining_amount' => (float) $row['remaining_amount'],
                'installments' => $coverage[(int) $row['id']] ?? [],
            ]);
            // Předvýběr podle celé splátky propustí i už uhrazenou splátku; bez hledání nemá důvod.
            if ($search === '' && $match['score'] === 0) continue;
            $rows[] = [
                'type' => 'other_item',
                'id' => (int) $row['id'],
                'ref' => ($row['document_no'] ?? '') !== '' ? (string) $row['document_no'] : null,
                'amount' => round((float) $row['remaining_amount'], 2),
                'currency' => 'CZK',
                'converted_amount' => null,
                'converted_currency' => null,
                'issue_date' => (string) $row['issued_on'],
                'due_date' => $row['due_on'] !== null ? (string) $row['due_on'] : null,
                'party' => ($row['partner_name'] ?? '') !== '' ? (string) $row['partner_name'] : (string) $row['title'],
                'paid' => false,
                'currency_mismatch' => false,
                'side' => (string) $row['side'],
                'match_score' => $match['score'],
                'match_confidence' => $match['confidence'],
                'match_reasons' => $match['reasons'],
                'installment' => $match['installment'],
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $b['match_score'] <=> $a['match_score']);
        return $rows;
    }

    /**
     * Přiřadí pohyb položce. Nezaúčtovaný pohyb nejdřív zaúčtuje proti účtu položky;
     * částka je volná část pohybu, nanejvýš zbývající dluh (víc nepřiřadí, přeplatek
     * odmítne allocate() i u kandidáta bez shody částky).
     *
     * @param array<string,mixed> $meta auditní údaje pro ruční zápis pohybu
     * @param bool $mayPost volající smí zapisovat do účetnictví (zaúčtování pohybu)
     */
    public function match(int $supplierId, int $txId, int $itemId, ?int $userId, array $meta, bool $mayPost): array
    {
        $pdo = $this->db->pdo();
        return TransactionFailure::retrying($pdo,
            fn (): array => $this->matchOnce($pdo, $supplierId, $txId, $itemId, $userId, $meta, $mayPost));
    }

    private function matchOnce(PDO $pdo, int $supplierId, int $txId, int $itemId, ?int $userId, array $meta, bool $mayPost): array
    {
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            // Pořadí zámků položka, pohyb sdílí s allocate(); zbytek se čte až pod zámkem položky.
            $item = $this->items->lockForAllocation($supplierId, $itemId);
            $tx = $this->freeTransaction($supplierId, $txId, true)
                ?? throw new OtherItemException('payment_unavailable',
                    'Platbu nelze přiřadit: je už spárovaná, vyčerpaná nebo není v CZK.', 409);
            if ($tx['locked_item_ids'] !== [] && !in_array($itemId, $tx['locked_item_ids'], true)) {
                throw new OtherItemException('payment_reallocation_conflict',
                    'Po stornu lze platbu znovu přiřadit jen k původní položce.', 409);
            }
            $side = (float) $tx['amount'] > 0 ? 'receivable' : 'payable';
            if ($item['side'] !== $side) {
                throw new OtherItemException('payment_direction', $side === 'receivable'
                    ? 'Příchozí platbu lze přiřadit jen pohledávce.'
                    : 'Odchozí platbu lze přiřadit jen závazku.', 409);
            }
            $amount = round(min(abs((float) $tx['amount']) - (float) $tx['used_amount'],
                (float) $item['remaining_amount']), 2);
            if ($amount <= 0) {
                throw new OtherItemException('overpayment', 'Položka už nemá co uhradit.', 409);
            }
            $account = $this->items->settlementAccountCode($supplierId, $item);
            if ($account !== null && !$this->hasLiveEntry($supplierId, $txId)) {
                if (abs((float) $tx['amount']) - (float) $tx['used_amount'] - $amount > 0.005) {
                    // Zaúčtovat celý pohyb proti účtu položky by vytvořilo přeplatek na saldokontu.
                    throw new OtherItemException('payment_exceeds_item',
                        'Platba je vyšší než zbývající dluh. Zaúčtujte ji rozúčtováním a položce ji přiřaďte z jejího detailu.', 409);
                }
                if (!$mayPost) {
                    throw new OtherItemException('posting_forbidden',
                        'Platbu je potřeba zaúčtovat a k tomu chybí oprávnění k účetnictví.', 403);
                }
                $this->bankPosting->postManual($supplierId, $txId, $side === 'receivable'
                    ? ['debit_account_code' => '221', 'credit_account_code' => $account]
                    : ['debit_account_code' => $account, 'credit_account_code' => '221'], $meta);
            }
            // Položka ze zůstatku nemá vlastní zápis a allocate() její zaúčtování nekontroluje.
            $this->items->assertBankPaymentPosted($supplierId, $txId, $item, $amount);
            $result = $this->items->allocate($supplierId, $itemId,
                ['bank_transaction_id' => $txId, 'amount' => $amount], $userId);
            if ($ownTx) $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Pohyb firmy volný k přiřazení ({@see OtherItemService::freeBankPaymentSql}) s jeho
     * již přiřazenou částkou; `locked_item_ids` jsou položky, ke kterým se smí vrátit po stornu.
     *
     * @return array{id:int,amount:float,variable_symbol:?string,counterparty_account:?string,
     *     counterparty_bank:?string,posted_at:?string,used_amount:float,locked_item_ids:list<int>}|null
     */
    private function freeTransaction(int $supplierId, int $txId, bool $lock): ?array
    {
        if ($lock && $this->bankPosting->lockTransaction($supplierId, $txId) === null) return null;
        $stmt = $this->db->pdo()->prepare(
            'SELECT bt.id, bt.amount, bt.variable_symbol, bt.counterparty_account, bt.counterparty_bank, bt.posted_at,
                    COALESCE(a.used_amount, 0) used_amount,
                    (SELECT GROUP_CONCAT(DISTINCT previous.other_item_id) FROM other_item_allocations previous
                      WHERE previous.supplier_id = bs.supplier_id AND previous.bank_transaction_id = bt.id
                        AND previous.reversed_on IS NOT NULL) locked_item_ids
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id AND bs.supplier_id = ?
          LEFT JOIN (SELECT bank_transaction_id, SUM(amount) used_amount FROM other_item_allocations
                      WHERE supplier_id = ? AND bank_transaction_id = ? AND reversed_on IS NULL
                      GROUP BY bank_transaction_id) a
                 ON a.bank_transaction_id = bt.id
              WHERE bt.id = ? AND bt.amount <> 0
                AND ' . OtherItemService::freeBankPaymentSql()
        );
        $stmt->execute([$supplierId, $supplierId, $txId, $txId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        return [
            'id' => (int) $row['id'],
            'amount' => (float) $row['amount'],
            'variable_symbol' => $row['variable_symbol'] !== null ? (string) $row['variable_symbol'] : null,
            'counterparty_account' => $row['counterparty_account'] !== null ? (string) $row['counterparty_account'] : null,
            'counterparty_bank' => $row['counterparty_bank'] !== null ? (string) $row['counterparty_bank'] : null,
            'posted_at' => $row['posted_at'] !== null ? substr((string) $row['posted_at'], 0, 10) : null,
            'used_amount' => (float) $row['used_amount'],
            'locked_item_ids' => $row['locked_item_ids'] !== null
                ? array_map('intval', explode(',', (string) $row['locked_item_ids'])) : [],
        ];
    }

    /** @param array<string,mixed> $item */
    private function postedOnItemAccount(int $supplierId, int $txId, array $item): bool
    {
        try {
            $this->items->assertBankPaymentPosted($supplierId, $txId, $item, 0.01);
            return true;
        } catch (OtherItemException) {
            return false;
        }
    }

    private function hasLiveEntry(int $supplierId, int $txId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM journal_entries WHERE supplier_id = ? AND source_type = 'bank' AND source_id = ?
                AND reversed_by IS NULL LIMIT 1"
        );
        $stmt->execute([$supplierId, $txId]);
        return $stmt->fetchColumn() !== false;
    }
}
