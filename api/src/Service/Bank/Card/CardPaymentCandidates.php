<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Card;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Support\Sql\PurchaseSettledExpr;
use PDO;

/**
 * Kandidáti pro párování pohybu kartou s přijatým dokladem.
 *
 * Silný kandidát = doklad se stejnou koncovkou karty. Slabý = doklad placený kartou
 * (`payment_method = card`), u kterého koncovka chybí. Doklad s JINOU koncovkou není
 * kandidát nikdy — to je pravidlo, které brání spárovat dvě stejné částky dvěma
 * kartami téhož dne křížem.
 *
 * Datum: účtenka vzniká v den nákupu, banka pohyb zaúčtuje typicky o 0–5 dní
 * později. Okno je proto nesouměrné (doklad až {@see DAYS_BEFORE_POSTING} dní před
 * zaúčtováním, nejvýš {@see DAYS_AFTER_POSTING} dny po něm).
 */
final class CardPaymentCandidates
{
    public const DAYS_BEFORE_POSTING = 7;
    public const DAYS_AFTER_POSTING = 2;
    /** Okno, ve kterém se hledají „konkurenční" pohyby téže částky. */
    public const COMPETING_DAY_WINDOW = 7;

    public function __construct(private readonly Connection $db) {}

    /**
     * Otevřené (neuhrazené) doklady firmy placené kartou s koncovkou $last4, nebo
     * placené kartou bez uvedené koncovky, v datovém okně kolem zaúčtování.
     *
     * @return list<array<string,mixed>>
     */
    public function openDocuments(int $supplierId, string $last4, string $postedAt): array
    {
        $settled = PurchaseSettledExpr::settled('pi');
        $stmt = $this->db->pdo()->prepare(
            "SELECT pi.id, pi.card_last4, pi.payment_method,
                    COALESCE(pi.amount_to_pay, pi.total_with_vat, 0) + COALESCE(pi.rounding, 0) AS amount_to_pay,
                    ({$settled}) AS settled_amount,
                    pi.exchange_rate, cur.code AS currency
               FROM purchase_invoices pi
          LEFT JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.supplier_id = ?
                AND (pi.status IN ('received','booked')
                     -- Účtenka, kterou import rovnou označil jako zaplacenou (uhrazeno kartou),
                     -- ale k níž ještě není žádná úhrada: pohyb její karty je právě ta úhrada.
                     OR (pi.status = 'paid' AND pi.card_last4 = ?
                         AND NOT EXISTS (SELECT 1 FROM payment_matches pm0
                                          WHERE pm0.supplier_id = pi.supplier_id AND pm0.purchase_invoice_id = pi.id)))
                AND pi.document_kind <> 'tax_document'
                AND pi.cash_register_id IS NULL
                AND (pi.card_last4 = ? OR (pi.card_last4 IS NULL AND pi.payment_method = 'card'))
                AND DATEDIFF(?, COALESCE(pi.tax_date, pi.issue_date)) BETWEEN ? AND ?
              ORDER BY pi.id"
        );
        $stmt->execute([$supplierId, $last4, $last4, $postedAt, -self::DAYS_AFTER_POSTING, self::DAYS_BEFORE_POSTING]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Počet JINÝCH dosud nespárovaných odchozích pohybů kartou téže firmy se stejnou
     * částkou v okně kolem data. $sameCard = true počítá pohyby téže karty (dvě stejné
     * platby), false pohyby jiných karet (kdo další si může doklad nárokovat).
     */
    public function competingTransactions(
        int $supplierId,
        int $transactionId,
        string $last4,
        float $absAmount,
        string $postedAt,
        bool $sameCard,
    ): int {
        $cardPredicate = $sameCard ? 'bt.card_last4 = ?' : 'bt.card_last4 <> ?';
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*)
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.id <> ?
                AND bt.match_status = 'unmatched'
                AND bt.amount < 0
                AND ABS(ABS(bt.amount) - ?) < 0.005
                AND bt.card_last4 IS NOT NULL
                AND {$cardPredicate}
                AND ABS(DATEDIFF(bt.posted_at, ?)) <= ?
                AND " . BankStatementOwnershipResolver::sql('bs')
        );
        $stmt->execute(array_merge(
            [$transactionId, $absAmount, $last4, $postedAt, self::COMPETING_DAY_WINDOW],
            BankStatementOwnershipResolver::params($supplierId),
        ));
        return (int) $stmt->fetchColumn();
    }

    /** Doklad nese jinou kartu než pohyb → nesmí se spárovat žádnou cestou. */
    public static function isOtherCard(?string $transactionLast4, mixed $documentLast4): bool
    {
        return $transactionLast4 !== null
            && is_string($documentLast4)
            && $documentLast4 !== ''
            && $documentLast4 !== $transactionLast4;
    }
}
