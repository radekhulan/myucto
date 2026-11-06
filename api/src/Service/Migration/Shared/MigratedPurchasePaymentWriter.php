<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\FxPaymentSettlement;
use MyInvoice\Service\Bank\PurchasePaymentMatchWriter;
use MyInvoice\Support\Sql\PurchaseSettledExpr;
use PDO;

/**
 * Převzaté závazky s nativními bankovními alokacemi: částka alokace je kladná,
 * směr úhrady včetně vratky určuje bankovní pohyb přes PurchaseSettledExpr.
 * Na rozdíl od původního MigratedPaymentWriter nepoužívá prostý součet alokací;
 * propojuje existující nativní výpočet a toleranci, jejich pravidla nemění.
 * Zdrojové znaménko a otisk záznamu zůstávají odpovědností volajícího adaptéru.
 * Metody běží v transakci převodu, samy ji neotvírají ani nepotvrzují.
 */
final class MigratedPurchasePaymentWriter
{
    public function __construct(private readonly Connection $db) {}

    /** Zapíše kladnou velikost ověřené domácí bankovní alokace a vrátí její ID. */
    public function attachBank(int $supplierId, int $userId, int $docId, int $movementId, float $amount): int
    {
        $pdo = $this->db->pdo();
        $check = $pdo->prepare('SELECT c.code AS document_currency, bt.currency AS movement_currency
            FROM purchase_invoices pi
            JOIN currencies c ON c.id=pi.currency_id AND c.supplier_id=pi.supplier_id
            JOIN bank_transactions bt ON bt.id=?
            JOIN bank_statements bs ON bs.id=bt.statement_id AND bs.supplier_id=pi.supplier_id
            WHERE pi.id=? AND pi.supplier_id=?');
        $check->execute([$movementId, $docId, $supplierId]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if ($row === false) throw new \InvalidArgumentException('payment_target_missing');
        if ($row['document_currency'] !== 'CZK' || $row['movement_currency'] !== 'CZK') {
            throw new \InvalidArgumentException('payment_currency_unverified');
        }
        PurchasePaymentMatchWriter::record($pdo, $supplierId, $movementId, $docId, $amount, 'manual', userId: $userId);
        $match = $pdo->prepare('SELECT id FROM payment_matches WHERE supplier_id=? AND bank_transaction_id=?
            AND purchase_invoice_id=? AND invoice_id IS NULL ORDER BY id LIMIT 1');
        $match->execute([$supplierId, $movementId, $docId]);
        $id = $match->fetchColumn();
        if ($id === false) throw new \LogicException('Zápis bankovní alokace nevrátil vazbu.');
        $pdo->prepare('UPDATE bank_transactions SET match_status="manual", matched_at=NOW(), matched_by=? WHERE id=?')
            ->execute([$userId, $movementId]);
        return (int) $id;
    }

    /**
     * Cache eviduje uhrazení vůči absolutní hodnotě dokladu (i u dobropisu).
     * Částky a zůstatky odvozuje nativní SSOT ze všech evidovaných platebních kanálů.
     *
     * @param list<int> $ids
     */
    public function refreshBalances(int $supplierId, array $ids): void
    {
        $pdo = $this->db->pdo();
        $settled = PurchaseSettledExpr::settled('pi');
        $remaining = PurchaseSettledExpr::remainingAmount('pi');
        foreach (array_chunk($ids, 200) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $query = $pdo->prepare("SELECT pi.id, pi.status, c.code, pi.amount_to_pay AS total,
                    ({$settled}) AS settled, ({$remaining}) AS remaining,
                    NULLIF(GREATEST(
                        COALESCE((SELECT MAX(bt.posted_at) FROM payment_matches pm
                            JOIN bank_transactions bt ON bt.id=pm.bank_transaction_id
                            WHERE pm.supplier_id=pi.supplier_id AND pm.purchase_invoice_id=pi.id), '1000-01-01'),
                        COALESCE((SELECT MAX(cd.issue_date) FROM cash_documents cd
                            WHERE cd.supplier_id=pi.supplier_id AND cd.purchase_invoice_id=pi.id AND cd.status='posted'), '1000-01-01')
                    ), '1000-01-01') AS paid_on,
                    " . \MyInvoice\Service\Accounting\DocumentPostingGroup::activePostingExistsSql("'purchase_invoice'", 'pi.id', 'pi.supplier_id') . " AS has_posting
                FROM purchase_invoices pi
                JOIN currencies c ON c.id=pi.currency_id AND c.supplier_id=pi.supplier_id
                WHERE pi.supplier_id=? AND pi.id IN ({$in}) FOR UPDATE");
            $query->execute([$supplierId, ...$chunk]);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== count($chunk)) throw new \InvalidArgumentException('payment_target_missing');
            $cases = ['paid_amount_invoice_ccy' => [], 'status' => [], 'paid_at' => []];
            $values = ['paid_amount_invoice_ccy' => [], 'status' => [], 'paid_at' => []];
            foreach ($rows as $row) {
                if ($row['code'] !== 'CZK') throw new \InvalidArgumentException('payment_currency_unverified');
                if ($row['status'] === 'cancelled') continue;
                $sign = (float) $row['total'] < 0 ? -1 : 1;
                $paid = round((float) $row['settled'] * $sign, 2);
                // remainingAmount respektuje nativní zaokrouhlení. Při nulové čisté
                // úhradě nesmí legacy fallback statusu paid skrýt úplnou vratku.
                $full = ($paid > 0.0 || abs((float) $row['total']) < 0.005)
                    && FxPaymentSettlement::settlesRemaining(0.0, (float) $row['remaining'] * $sign, 'CZK', 1.0, 'CZK');
                // Stav odvozujeme zde, protože nativní pravidla nejdou zavolat dávkově:
                // StatementMatcher píše „uhrazeno" přímo při párování a návrat
                // (BankTransactionReleaseService::restorePurchaseInvoices, AdvanceCoveredPaidStatus::syncFinal)
                // je privátní a po jednom dokladu. Kritéria jsou stejná: plná úhrada podle
                // FxPaymentSettlement, návrat na booked jen s živým zaúčtováním, jinak received.
                // Odlišnosti jsou záměrné: převod sem posílá jen své nové nebo dotčené doklady,
                // které zakládá jako received a „uhrazeno" jim nastavuje jen tento výpočet. Proto
                // ho po vratce vrací bez kontroly paid_at (nativní návrat tím chrání ručně
                // zadané datum úhrady, převzatý doklad žádné ruční nemá) a paid_at
                // bere i příjmový pokladní doklad, aby měl datum i dobropis vyplacený hotově
                // (AdvanceCoveredPaidStatus::lastPaymentDate bere jen výdaje).
                $status = (string) $row['status'];
                if ($status !== 'draft') {
                    $status = $full ? 'paid' : ($status === 'paid' ? ((bool) $row['has_posting'] ? 'booked' : 'received') : $status);
                }
                foreach (['paid_amount_invoice_ccy' => $paid, 'status' => $status,
                    'paid_at' => $status === 'paid' ? $row['paid_on'] : null] as $column => $value) {
                    $cases[$column][] = 'WHEN ? THEN ?';
                    array_push($values[$column], (int) $row['id'], $value);
                }
            }
            if ($cases['status'] === []) continue;
            $sets = [];
            $parameters = [];
            foreach ($cases as $column => $branches) {
                $sets[] = $column . ' = CASE id ' . implode(' ', $branches) . ' ELSE ' . $column . ' END';
                array_push($parameters, ...$values[$column]);
            }
            $pdo->prepare('UPDATE purchase_invoices SET ' . implode(', ', $sets) . " WHERE supplier_id=? AND id IN ({$in})")
                ->execute([...$parameters, $supplierId, ...$chunk]);
        }
    }

}
