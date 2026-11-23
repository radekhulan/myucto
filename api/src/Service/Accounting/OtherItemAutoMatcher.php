<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\OtherItemRepository;
use MyInvoice\Service\ActivityLogger;
use PDO;

/**
 * Automatické párování bankovního pohybu s ostatní pohledávkou nebo závazkem. Volá ho
 * {@see \MyInvoice\Service\Bank\StatementMatcher} až poté, co pohyb nespárovalo nic
 * z fakturačního párování, takže faktura má vždy přednost.
 *
 * Běží jen u firmy se zapnutým `supplier.auto_match_other_items`. Kandidáty a důvody
 * shody bere z dialogu Spárovat ({@see OtherItemBankMatchService::candidates}), o jistotě
 * rozhoduje {@see OtherItemAutoMatchPolicy}. Přiřazení jde stejnou cestou jako ruční
 * párování z banky ({@see OtherItemBankMatchService::match}): nezaúčtovaný pohyb zaúčtuje
 * proti účtu položky, hlídá přeplatek, měnu, směr i uzavřené období a v daňové evidenci
 * nic neúčtuje.
 *
 * `match_status` pohybu zůstává `unmatched` jako u ručního přiřazení: popisuje jen párování
 * s fakturou a nespárovaný stav je podmínkou volné platby pro ostatní položky. Kód shody
 * nese řádek přiřazení (`other_item_allocations.auto_match_reason`) a aktivita položky.
 */
final class OtherItemAutoMatcher
{
    /**
     * Důvody fakturačního párování, po kterých smí automatika zkusit ostatní položky.
     * Jen „nic se nenašlo": nejednoznačný VS, nesedící částka nebo návrh ke kontrole
     * znamenají, že pohyb patří spíš faktuře, a ty zůstávají beze změny.
     */
    public const ELIGIBLE_REASONS = [
        'no_vs', 'no_invoice_with_vs', 'no_purchase_with_vs', 'no_fuzzy_match',
        'no_amount_date_match', 'no_card_match',
    ];

    public const RESULT_ALLOCATED = 'other_item_allocated';
    public const RESULT_CANDIDATES = 'other_item_candidates';

    public function __construct(
        private readonly Connection $db,
        private readonly OtherItemBankMatchService $bankMatch,
        private readonly OtherItemRepository $repository,
        private readonly ?ActivityLogger $activity = null,
    ) {}

    /**
     * Smí automatika po tomto výsledku fakturačního párování zkusit ostatní položky?
     *
     * @param array<string,mixed> $result výsledek {@see \MyInvoice\Service\Bank\StatementMatcher}
     */
    public static function eligible(array $result): bool
    {
        return ($result['status'] ?? 'unmatched') === 'unmatched'
            && empty($result['already_recorded'])
            && empty($result['requires_review'])
            && !isset($result['match_suggestion_id'])
            && in_array((string) ($result['reason'] ?? ''), self::ELIGIBLE_REASONS, true);
    }

    /**
     * Zkusí pohyb přiřadit. Volající drží transakci (savepoint), chyba přiřazení se vrací
     * jako výjimka a volající ji vrátí. Null = automatika se pohybu netýká.
     *
     * @return array{status:string,reason:string,other_item_id?:int,other_item_match?:string}|null
     */
    public function matchTransaction(int $transactionId): ?array
    {
        $tx = $this->transaction($transactionId);
        if ($tx === null) return null;
        $supplierId = (int) $tx['supplier_id'];
        $blocked = $this->blockedItems($supplierId, $transactionId);
        $candidates = array_values(array_filter(
            $this->bankMatch->candidates($supplierId, $transactionId, '', 50),
            static fn (array $row): bool => !in_array((int) $row['id'], $blocked, true),
        ));
        if ($candidates === []) return null;
        $items = $this->items($supplierId, $candidates);
        $payment = [
            'amount' => abs((float) $tx['amount']), 'variable_symbol' => $tx['variable_symbol'],
            'counterparty_account' => $tx['counterparty_account'], 'counterparty_bank' => $tx['counterparty_bank'],
            'payment_on' => substr((string) $tx['posted_at'], 0, 10),
        ];
        $decision = OtherItemAutoMatchPolicy::decide($payment, $items);
        if ($decision['decision'] === OtherItemAutoMatchPolicy::DECISION_NONE) return null;
        if ($decision['decision'] === OtherItemAutoMatchPolicy::DECISION_SUGGEST) {
            return ['status' => 'unmatched', 'reason' => self::RESULT_CANDIDATES];
        }
        $itemId = (int) $decision['item_id'];
        $this->allocate($supplierId, $transactionId, $itemId, (string) $decision['reason'], null);
        return [
            'status' => 'unmatched', 'reason' => self::RESULT_ALLOCATED,
            'other_item_id' => $itemId, 'other_item_match' => (string) $decision['reason'],
        ];
    }

    /**
     * Přiřadí pohyb položce cestou ručního párování z banky a označí přiřazení kódem shody.
     * Sdílí ho automatika i pravidlo účtování s cílovou položkou.
     */
    public function allocate(int $supplierId, int $transactionId, int $itemId, string $reason, ?int $userId): void
    {
        $this->bankMatch->match($supplierId, $transactionId, $itemId, $userId,
            ['user_id' => $userId, 'posted_by' => $userId], true);
        $this->db->pdo()->prepare(
            'UPDATE other_item_allocations SET auto_match_reason = ?
              WHERE supplier_id = ? AND other_item_id = ? AND bank_transaction_id = ? AND reversed_on IS NULL'
        )->execute([mb_substr($reason, 0, 40), $supplierId, $itemId, $transactionId]);
        try {
            $this->activity?->log('other_item.payment_auto_matched', $userId, 'other_item', $itemId,
                ['bank_transaction_id' => $transactionId, 'match' => $reason], null, null, $supplierId);
        } catch (\Throwable $e) {
            if (TransactionFailure::aborted($e)) throw $e;
        }
    }

    /**
     * Pohyb firmy se zapnutou automatikou: z oficiálního výpisu (avízo se nepřiřazuje,
     * převezme ho výpis), nenulový a bez jakéhokoli dřívějšího přiřazení. Stornované
     * přiřazení smí obnovit jen člověk, automatika by ho jinak vracela po každém
     * Přepárovat výpis.
     *
     * @return array<string,mixed>|null
     */
    private function transaction(int $transactionId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT bt.id, bt.amount, bt.variable_symbol, bt.counterparty_account, bt.counterparty_bank,
                    bt.posted_at, bs.supplier_id
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
               JOIN supplier s ON s.id = bs.supplier_id AND s.auto_match_other_items = 1
              WHERE bt.id = ? AND bt.amount <> 0 AND COALESCE(bt.source, 'statement') = 'statement'
                AND NOT EXISTS (SELECT 1 FROM other_item_allocations a
                                 WHERE a.supplier_id = bs.supplier_id AND a.bank_transaction_id = bt.id)"
        );
        $stmt->execute([$transactionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return list<int> položky, jejichž přiřazení k pohybu uživatel zrušil */
    private function blockedItems(int $supplierId, int $transactionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT other_item_id FROM other_item_auto_match_blocks WHERE supplier_id = ? AND bank_transaction_id = ?'
        );
        $stmt->execute([$supplierId, $transactionId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * Kandidáti doplnění o VS, účet protistrany a stav splátek pro {@see OtherItemAutoMatchPolicy}.
     *
     * @param list<array<string,mixed>> $candidates
     * @return list<array<string,mixed>>
     */
    private function items(int $supplierId, array $candidates): array
    {
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $candidates);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, variable_symbol, counterparty_account, counterparty_bank_code
               FROM other_items WHERE supplier_id = ? AND id IN ($placeholders)"
        );
        $stmt->execute([$supplierId, ...$ids]);
        $stored = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stored[(int) $row['id']] = $row;
        }
        $coverage = $this->repository->installmentCoverage($supplierId, $ids);
        $items = [];
        foreach ($candidates as $candidate) {
            $id = (int) $candidate['id'];
            if (!isset($stored[$id])) continue;
            $items[] = [
                'id' => $id,
                'variable_symbol' => $stored[$id]['variable_symbol'],
                'counterparty_account' => $stored[$id]['counterparty_account'],
                'counterparty_bank_code' => $stored[$id]['counterparty_bank_code'],
                'remaining_amount' => (float) $candidate['amount'],
                'installments' => $coverage[$id] ?? [],
            ];
        }
        return $items;
    }
}
