<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\TakenOverRecord;
use MyInvoice\Support\Sql\PurchaseSettledExpr;
use PDO;

/**
 * Obnova stavu „uhrazeno" běžné přijaté faktury poté, co z evidence zmizela vratka.
 *
 * Příchozí vratka (bankovní pohyb spárovaný s fakturou, pokladní příjmový doklad) úhradu
 * SNIŽUJE. Její odpárování, storno nebo smazání úhradu zase zvyšuje, a jen tento směr smí
 * znovu uzavřít otevřenou kladnou fakturu. Obecný přepočet stavu z úhrad by přepsal ruční
 * „uhrazeno", koncept i storno.
 *
 * Částku i zaokrouhlení určuje {@see PurchaseSettledExpr::remainingAmount()} stejně jako
 * seznam dokladů, datum úhrady {@see AdvanceCoveredPaidStatus::lastPaymentDates()} ze
 * zbývajících úhrad, nikdy z právě odstraněné vratky.
 *
 * Nemění:
 *   - zálohy a konečné faktury kryté zálohou, ty řídí {@see AdvanceCoveredPaidStatus},
 *   - doklady převzaté z jiného programu: stav přišel ze zdroje a uzavření je odvozený
 *     závěr, ne vrácení přímého účinku odebrané vratky (stejně jako backfill
 *     {@see AdvanceCoveredPaidStatus::backfill()} a {@see \MyInvoice\Service\Bank\AdvanceFinalMatchGuard}),
 *   - doklady, které uživatel ručně vrátil z „uhrazeno" ({@see manuallyUnpaidIds()}).
 *
 * Běží v transakci volajícího.
 */
final class PurchasePaidStatusRestore
{
    private const CHUNK = 200;

    public function __construct(
        private readonly Connection $db,
        private readonly AdvanceCoveredPaidStatus $paidStatus,
    ) {}

    /**
     * @param list<int> $purchaseInvoiceIds doklady, ze kterých vratka zmizela
     * @return array<int,string> uzavřené doklady => datum úhrady
     */
    public function afterRefundRemoved(int $supplierId, array $purchaseInvoiceIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $purchaseInvoiceIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $pdo = $this->db->pdo();
        $remaining = PurchaseSettledExpr::remainingAmount('pi');
        $takenOver = TakenOverRecord::documentSql('purchase_invoice', 'pi');
        $restored = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare(
                "SELECT pi.id FROM purchase_invoices pi
                  WHERE pi.supplier_id = ? AND pi.id IN ($placeholders)
                    AND pi.document_kind = 'invoice' AND pi.amount_to_pay > 0.005
                    AND pi.status IN ('received', 'booked') AND ($remaining) <= 0.005
                    AND NOT $takenOver
                  FOR UPDATE"
            );
            $stmt->execute([$supplierId, ...$chunk]);
            $covered = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            $covered = array_values(array_diff($covered, $this->manuallyUnpaidIds($covered)));
            if ($covered === []) {
                continue;
            }
            $dates = $this->paidStatus->lastPaymentDates($supplierId, $covered);
            if ($dates === []) {
                continue;
            }
            $cases = [];
            $params = [];
            foreach ($dates as $id => $date) {
                $cases[] = 'WHEN ? THEN ?';
                array_push($params, $id, $date);
            }
            $placeholders = implode(',', array_fill(0, count($dates), '?'));
            $pdo->prepare("UPDATE purchase_invoices SET status = 'paid', paid_at = CASE id "
                . implode(' ', $cases) . " END
                WHERE supplier_id = ? AND id IN ($placeholders) AND document_kind = 'invoice'
                  AND status IN ('received', 'booked')")
                ->execute([...$params, $supplierId, ...array_keys($dates)]);
            $restored += $dates;
        }
        return $restored;
    }

    /**
     * Doklady, které uživatel vědomě vrátil z „uhrazeno" (přechod paid → received
     * v {@see \MyInvoice\Action\PurchaseInvoice\TransitionPurchaseInvoiceStatusAction}).
     * Doklad sám to nerozliší, ruční „neuhrazeno" vypadá stejně jako stav po zrušené úhradě;
     * stopu nese jen activity log. Rozhoduje poslední ruční přechod, který se stavu paid
     * týká: odchází-li z paid, platí rozhodnutí uživatele, dokud doklad ručně znovu neuhradí.
     * Záznam logu nemá vyplněnou firmu, `entity_id` je ale id dokladu už ověřeného
     * proti firmě v dotazu výš.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    private function manuallyUnpaidIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT x.entity_id FROM (
                SELECT al.entity_id, JSON_VALUE(al.payload, '$.from') AS from_status,
                       ROW_NUMBER() OVER (PARTITION BY al.entity_id ORDER BY al.id DESC) AS rn
                  FROM activity_log al
                 WHERE al.entity_type = 'purchase_invoice' AND al.entity_id IN ($placeholders)
                   AND al.action = 'purchase_invoice.transitioned'
                   AND 'paid' IN (JSON_VALUE(al.payload, '$.from'), JSON_VALUE(al.payload, '$.to'))
             ) x
             WHERE x.rn = 1 AND x.from_status = 'paid'"
        );
        $stmt->execute($ids);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
