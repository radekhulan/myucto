<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Card;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Repository\PaymentCardRepository;
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
    ) {}

    /**
     * @return array{from:string, to:string, count:int, truncated:bool, groups:list<array<string,mixed>>}
     */
    public function unmatched(int $supplierId, string $from, string $to): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT bt.id, bt.statement_id, bt.posted_at, bt.amount,
                    COALESCE(NULLIF(bt.currency, ''), bs.currency) AS currency,
                    bt.counterparty_name, bt.description, bt.card_last4
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.card_last4 IS NOT NULL
                AND bt.amount < 0
                AND bt.match_status = 'unmatched'
                AND bt.posted_at BETWEEN ? AND ?
                AND NOT EXISTS (
                    SELECT 1 FROM payment_matches pm
                     WHERE pm.bank_transaction_id = bt.id AND pm.supplier_id = ?
                )
                AND " . BankStatementOwnershipResolver::sql('bs') . "
              ORDER BY bt.posted_at DESC, bt.id DESC
              LIMIT " . (self::MAX_ROWS + 1)
        );
        $stmt->execute(array_merge([$from, $to, $supplierId], BankStatementOwnershipResolver::params($supplierId)));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $truncated = count($rows) > self::MAX_ROWS;
        $rows = array_slice($rows, 0, self::MAX_ROWS);

        $cardByTx = $this->cards->resolveForTransactions($supplierId, $rows);
        $groups = [];
        foreach ($rows as $row) {
            $txId = (int) $row['id'];
            $card = $cardByTx[$txId] ?? null;
            $key = $card !== null ? 'card:' . $card['id'] : 'last4:' . $row['card_last4'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key'          => $key,
                    'card'         => $card,
                    'last4'        => (string) $row['card_last4'],
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
                'card_last4'        => (string) $row['card_last4'],
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
     * Odchozí pohyb kartou, který patří firmě. Cizí nebo nekaretní pohyb = null.
     *
     * @return array{id:int, posted_at:string, amount:float, card_last4:string, match_status:string}|null
     */
    public function findCardTransaction(int $supplierId, int $transactionId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT bt.id, bt.posted_at, bt.amount, bt.card_last4, bt.match_status
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.id = ?
                AND bt.card_last4 IS NOT NULL
                AND bt.amount < 0
                AND ' . BankStatementOwnershipResolver::sql('bs')
        );
        $stmt->execute(array_merge([$transactionId], BankStatementOwnershipResolver::params($supplierId)));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id'           => (int) $row['id'],
            'posted_at'    => (string) $row['posted_at'],
            'amount'       => (float) $row['amount'],
            'card_last4'   => (string) $row['card_last4'],
            'match_status' => (string) $row['match_status'],
        ];
    }

    /**
     * Doklad právě vytěžený z účtenky k platbě kartou: forma úhrady karta a koncovka
     * z bankovního pohybu. Jde o výslovné rozhodnutí uživatele (nahrál účtenku K TÉTO
     * platbě), proto zdroj `manual`. Mění jen čerstvě založený koncept.
     */
    public function markReceiptPaidByCard(int $supplierId, int $purchaseInvoiceId, string $last4): bool
    {
        if (!CardNumberMask::isValidLast4($last4)) {
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
