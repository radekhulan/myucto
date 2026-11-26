<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Service\Accounting\TakenOverRecord;
use MyInvoice\Support\Sql\PurchaseSettledExpr;
use PDO;

/**
 * Přijatý doklad, který zdrojový systém (Fakturoid, iDoklad) převádí jako uhrazený.
 *
 * Stav `paid` musí nést evidence úhrady. Úhrada proběhla ve zdrojovém systému a MyÚčto
 * k ní nemá bankovní ani pokladní pohyb, proto ji nese `settled_outside_amount` jako
 * u dokladů převzatých z účetních programů ({@see PurchaseSettledExpr}): kryje zbytek
 * částky k úhradě, který nepokrývají evidované úhrady. Bez ní by doklad byl uhrazený bez
 * krytí, párování banky by ho bralo jako neuhrazený a oprava stavu úhrad by ho vrátila
 * mezi závazky. Protějšek vydaných dokladů je {@see ImportedPaidInvoicePayment}.
 *
 * Stav nastaví {@see mark()} jen na čerstvě založeném konceptu (deduplikace importu může
 * vrátit existující doklad, ten se nepřepisuje). Krytí dopočítá {@see cover()} až po
 * přepočtu částek dokladu. Doklad se zápornou nebo nulovou částkou k úhradě krytí nedostane.
 */
final class ImportedPaidPurchase
{
    /** Vrací true, když doklad stav převzal (byl to čerstvý koncept). */
    public static function mark(PDO $pdo, int $purchaseId, int $supplierId, string $paidAt): bool
    {
        $stmt = $pdo->prepare(
            "UPDATE purchase_invoices SET status = 'paid', paid_at = ?
              WHERE id = ? AND supplier_id = ? AND status = 'draft'"
        );
        $stmt->execute([$paidAt, $purchaseId, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * SQL podmínka „krytí `settled_outside_amount` dokladu `$alias` založil import z Fakturoidu
     * nebo iDokladu". Jiný zdroj ho takovému dokladu nezakládá: převody z účetních programů
     * (mapa převodu, {@see TakenOverRecord}) nesou krytí úhrady, která v MyÚčtu nebude.
     */
    public static function importCoverageSql(string $alias): string
    {
        return "(({$alias}.idoklad_id IS NOT NULL OR {$alias}.fakturoid_id IS NOT NULL)
                 AND NOT " . TakenOverRecord::documentSql('purchase_invoice', $alias) . ')';
    }

    /**
     * Skutečná úhrada (spárovaný bankovní pohyb) dokladu, jehož úhradu dosud neslo krytí
     * z importu: krytí se o ni sníží (nejvýš na nulu), aby doklad nebyl uhrazený dvakrát.
     * Krytí převodů z účetních programů zůstává. Volat v transakci zápisu párování.
     */
    public static function releaseForPayment(PDO $pdo, int $supplierId, int $purchaseId): void
    {
        $settled = PurchaseSettledExpr::settled('pi', currentRead: true);
        $pdo->prepare(
            "UPDATE purchase_invoices pi
                SET pi.settled_outside_amount = GREATEST(0, LEAST(pi.settled_outside_amount,
                        ROUND(pi.amount_to_pay - (({$settled}) - pi.settled_outside_amount), 2)))
              WHERE pi.id = ? AND pi.supplier_id = ? AND pi.settled_outside_amount > 0 AND " . self::importCoverageSql('pi')
        )->execute([$purchaseId, $supplierId]);
    }

    /**
     * Úhrada dokladu s krytím z importu zmizela (zrušené párování, smazaný výpis): uhrazený
     * doklad krytí ze zdroje dostane zpět ({@see cover()}). Doklad, který se mezitím vrátil
     * mezi neuhrazené, se nemění. Volat po odebrání úhrady, v transakci volajícího.
     *
     * @param list<int> $purchaseIds
     */
    public static function restoreAfterPaymentRemoved(PDO $pdo, int $supplierId, array $purchaseIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $purchaseIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return;
        }
        $in = implode(',', $ids);
        $stmt = $pdo->prepare("SELECT pi.id FROM purchase_invoices pi
                                WHERE pi.supplier_id = ? AND pi.id IN ({$in}) AND pi.status = 'paid' AND " . self::importCoverageSql('pi'));
        $stmt->execute([$supplierId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            self::cover($pdo, (int) $id, $supplierId);
        }
    }

    /** Volat po přepočtu částek dokladu, který převzal stav přes {@see mark()}. */
    public static function cover(PDO $pdo, int $purchaseId, int $supplierId): void
    {
        $settled = PurchaseSettledExpr::settled('pi');
        $pdo->prepare(
            "UPDATE purchase_invoices pi
                SET pi.settled_outside_amount = GREATEST(pi.settled_outside_amount,
                        ROUND(pi.amount_to_pay - (({$settled}) - pi.settled_outside_amount), 2))
              WHERE pi.id = ? AND pi.supplier_id = ? AND pi.status = 'paid' AND pi.amount_to_pay > 0"
        )->execute([$purchaseId, $supplierId]);
    }
}
