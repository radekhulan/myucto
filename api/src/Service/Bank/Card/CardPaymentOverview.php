<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Card;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Repository\PaymentCardRepository;
use MyInvoice\Service\Bank\BankNoticeConfirmationScope;
use PDO;

/**
 * Přehled „Platby kartou bez dokladu": odchozí pohyby s koncovkou karty, ke kterým
 * dosud není spárovaný žádný přijatý doklad, seskupené podle karty a držitele.
 *
 * Pohyb se do přehledu počítá, dokud nemá vazbu v `payment_matches` a jeho stav je
 * `unmatched` — ignorovaný pohyb (poplatek, výběr) účetní vědomě vyřadila.
 */
final class CardPaymentOverview
{
    public const MAX_ROWS = 1000;

    public function __construct(
        private readonly Connection $db,
        private readonly PaymentCardRepository $cards,
        private readonly \MyInvoice\Service\Accounting\Card\CardClearingAccounts $clearingAccounts,
    ) {}

    /**
     * @return array{from:string, to:string, count:int, truncated:bool, groups:list<array<string,mixed>>}
     */
    /**
     * Podmínka „výpis `$bs` je výpisem úvěrového účtu kreditní karty firmy" pro JOIN na
     * `supplier_bank_accounts $sba`. Jeden `?` = supplier_id. Platební karty ji používají,
     * aby pohyby kreditky nemíchaly s platbami platebními kartami (výpis kreditky nese
     * koncovku karty taky, účtuje se ale přes kreditku).
     */
    public static function creditCardAccountJoin(string $sba, string $bs): string
    {
        return "{$sba}.supplier_id = ? AND {$sba}.kind = 'credit_card'
                AND {$sba}.account_canonical = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL({$bs}.account_number, ''), '[^0-9]', ''))
                AND {$sba}.bank_code_norm = COALESCE({$bs}.bank_code, '')";
    }

    public function unmatched(int $supplierId, string $from, string $to, ?int $creditCardAccountId = null): array
    {
        // Platební a kreditní karty se nemíchají. Bez $creditCardAccountId jen platby z běžných
        // účtů (koncovka karty); pohyb z výpisu kreditní karty sem nepatří, i když koncovku nese
        // (účtuje se přes kreditku). S ním jen nákupy toho úvěrového účtu (detail kreditky).
        $stmt = $this->db->pdo()->prepare(
            "SELECT bt.id, bt.statement_id, bt.posted_at, bt.amount,
                    COALESCE(NULLIF(bt.currency, ''), bs.currency) AS currency,
                    bt.counterparty_name, bt.description, bt.card_last4,
                    cca.id AS credit_card_account_id, cca.label AS credit_card_label
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
          LEFT JOIN supplier_bank_accounts sba
                 ON " . self::creditCardAccountJoin('sba', 'bs') . "
          LEFT JOIN credit_card_accounts cca ON cca.bank_account_id = sba.id AND cca.supplier_id = sba.supplier_id
              WHERE " . ($creditCardAccountId === null ? 'cca.id IS NULL AND bt.card_last4 IS NOT NULL' : 'cca.id = ?') . "
                AND bt.amount < 0
                AND bt.match_status = 'unmatched'
                AND bt.posted_at BETWEEN ? AND ?
                AND NOT EXISTS (
                    SELECT 1 FROM payment_matches pm
                     WHERE pm.bank_transaction_id = bt.id AND pm.supplier_id = ?
                )
                -- Platba uzavřená bez dokladu (548/335 proti mezičlenu karty) už doklad nečeká.
                AND NOT EXISTS (
                    SELECT 1 FROM journal_entries w
                     WHERE w.supplier_id = ? AND w.source_type = 'card_writeoff'
                       AND w.source_id = bt.id AND w.reversed_by IS NULL
                )
                AND " . BankNoticeConfirmationScope::activeSql('bt') . " AND " . BankStatementOwnershipResolver::sql('bs') . "
              ORDER BY bt.posted_at DESC, bt.id DESC
              LIMIT " . (self::MAX_ROWS + 1)
        );
        $stmt->execute(array_merge(
            [$supplierId],
            $creditCardAccountId === null ? [] : [$creditCardAccountId],
            [$from, $to, $supplierId, $supplierId],
            BankStatementOwnershipResolver::params($supplierId),
        ));
        // Z kreditky jen nákupy: úrok, poplatek a výběr doklad nečekají.
        $rows = array_values(array_filter(
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            static fn (array $r): bool => $r['credit_card_account_id'] === null
                || \MyInvoice\Service\Bank\CreditCard\CreditCardTransactionKind::classify(
                    $r['description'] !== null ? (string) $r['description'] : null,
                    (float) $r['amount'],
                ) === \MyInvoice\Service\Bank\CreditCard\CreditCardTransactionKind::PURCHASE,
        ));
        $truncated = count($rows) > self::MAX_ROWS;
        $rows = array_slice($rows, 0, self::MAX_ROWS);

        $cardByTx = $this->cards->resolveForTransactions($supplierId, $rows);
        $clearingByTx = $this->clearingCodes($supplierId, array_map(static fn (array $r): int => (int) $r['id'], $rows));
        $groups = [];
        foreach ($rows as $row) {
            $txId = (int) $row['id'];
            $creditId = $row['credit_card_account_id'] !== null ? (int) $row['credit_card_account_id'] : null;
            $card = $creditId === null ? ($cardByTx[$txId] ?? null) : null;
            $key = $creditId !== null ? 'credit:' . $creditId : ($card !== null ? 'card:' . $card['id'] : 'last4:' . $row['card_last4']);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key'          => $key,
                    'card'         => $card,
                    'credit_card'  => $creditId !== null ? ['id' => $creditId, 'label' => (string) $row['credit_card_label']] : null,
                    'last4'        => (string) ($row['card_last4'] ?? ''),
                    'holder'       => $card['holder'] ?? null,
                    'count'        => 0,
                    'totals'       => [],
                    'transactions' => [],
                ];
            }
            $currency = (string) ($row['currency'] ?? '') !== '' ? (string) $row['currency'] : 'CZK';
            $amount = (float) $row['amount'];
            $groups[$key]['count']++;
            $groups[$key]['totals'][$currency] = round(($groups[$key]['totals'][$currency] ?? 0.0) + abs($amount), 2);
            $groups[$key]['transactions'][] = [
                'id'                => $txId,
                'statement_id'      => (int) $row['statement_id'],
                'posted_at'         => (string) $row['posted_at'],
                'amount'            => $amount,
                'currency'          => $currency,
                'counterparty_name' => $row['counterparty_name'] !== null ? (string) $row['counterparty_name'] : null,
                'description'       => $row['description'] !== null ? (string) $row['description'] : null,
                'card_last4'        => $row['card_last4'] !== null ? (string) $row['card_last4'] : null,
                'credit_card'       => $creditId !== null,
                // Analytika mezičlenu, na které platba čeká na doklad (null = účtováno bez mezičlenu).
                'clearing_account'  => $clearingByTx[$txId] ?? null,
            ];
        }

        $groups = array_values($groups);
        // Známí držitelé abecedně, neznámé karty na konec — ty jsou první na řadě k doplnění.
        usort($groups, static function (array $a, array $b): int {
            $ha = $a['holder'] ?? null;
            $hb = $b['holder'] ?? null;
            if (($ha === null) !== ($hb === null)) {
                return $ha === null ? 1 : -1;
            }
            return strcmp((string) $ha, (string) $hb) ?: strcmp($a['last4'], $b['last4']);
        });

        return [
            'from'      => $from,
            'to'        => $to,
            'count'     => count($rows),
            'truncated' => $truncated,
            'groups'    => $groups,
        ];
    }

    /**
     * Analytika mezičlenu karty v živém bankovním zápisu pohybů (jedním dotazem).
     *
     * @param list<int> $txIds
     * @return array<int,string> id pohybu => kód analytiky
     */
    private function clearingCodes(int $supplierId, array $txIds): array
    {
        $codes = $this->clearingAccounts->allClearingCodes($supplierId);
        if ($txIds === [] || $codes === []) {
            return [];
        }
        $txPh = implode(',', array_fill(0, count($txIds), '?'));
        $codePh = implode(',', array_fill(0, count($codes), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT je.source_id, c.account_code
               FROM journal_entries je
               JOIN journal_entry_lines jel ON jel.entry_id = je.id AND jel.supplier_id = je.supplier_id
               JOIN chart_of_accounts c ON c.id = jel.account_id AND c.supplier_id = je.supplier_id
              WHERE je.supplier_id = ? AND je.source_type = 'bank' AND je.reversed_by IS NULL
                AND je.source_id IN ($txPh) AND c.account_code IN ($codePh)"
        );
        $stmt->execute([$supplierId, ...$txIds, ...$codes]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['source_id']] = (string) $r['account_code'];
        }
        return $out;
    }

    /**
     * Pohyb kartou, který patří firmě: odchozí pohyb s koncovkou karty, nebo pohyb z výpisu
     * úvěrového účtu kreditní karty (kreditní účet je karta sám, koncovku výpis nenese a vratka
     * obchodníka je kladná). Cizí nebo nekaretní pohyb = null.
     *
     * @return array{id:int, posted_at:string, amount:float, card_last4:?string, match_status:string, credit_card:bool}|null
     */
    public function findCardTransaction(int $supplierId, int $transactionId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT bt.id, bt.posted_at, bt.amount, bt.card_last4, bt.match_status,
                    EXISTS (SELECT 1 FROM supplier_bank_accounts sba
                             WHERE sba.supplier_id = ? AND sba.kind = 'credit_card'
                               AND sba.account_canonical = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''), '[^0-9]', ''))
                               AND sba.bank_code_norm = COALESCE(bs.bank_code, '')) AS credit_card
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.id = ?
                AND " . BankNoticeConfirmationScope::activeSql('bt') . " AND " . BankStatementOwnershipResolver::sql('bs')
        );
        $stmt->execute(array_merge([$supplierId, $transactionId], BankStatementOwnershipResolver::params($supplierId)));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $creditCard = (bool) $row['credit_card'];
        if (!$creditCard && ($row['card_last4'] === null || (float) $row['amount'] >= 0)) {
            return null;
        }
        return [
            'id'           => (int) $row['id'],
            'posted_at'    => (string) $row['posted_at'],
            'amount'       => (float) $row['amount'],
            'card_last4'   => $row['card_last4'] !== null ? (string) $row['card_last4'] : null,
            'match_status' => (string) $row['match_status'],
            'credit_card'  => $creditCard,
        ];
    }

    /**
     * Doklad právě vytěžený z účtenky k platbě kartou: forma úhrady karta a koncovka
     * z bankovního pohybu (u kreditní karty bez koncovky jen forma úhrady). Jde o výslovné
     * rozhodnutí uživatele (nahrál účtenku K TÉTO platbě), proto zdroj `manual`. Mění jen
     * čerstvě založený koncept.
     */
    public function markReceiptPaidByCard(int $supplierId, int $purchaseInvoiceId, ?string $last4): bool
    {
        if ($last4 !== null && !CardNumberMask::isValidLast4($last4)) {
            return false;
        }
        $stmt = $this->db->pdo()->prepare(
            "UPDATE purchase_invoices
                SET payment_method = 'card', payment_method_source = 'manual', card_last4 = ?
              WHERE id = ? AND supplier_id = ? AND status = 'draft'"
        );
        $stmt->execute([$last4, $purchaseInvoiceId, $supplierId]);
        return $stmt->rowCount() > 0;
    }
}
