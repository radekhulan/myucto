<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Support\Sql\PurchaseSettledExpr;

/** Úhrady evidované před potvrzením konceptu; netvoří platbu ani účetní zápis. */
final class PurchaseReceivedPaymentStatus
{
    public function __construct(private readonly Connection $db) {}

    /** Volající drží zámek dokladu a právě změnil skutečný draft na received. */
    public function afterDraftReceived(int $supplierId, int $id): void
    {
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException('Přepočet přijatého konceptu vyžaduje transakci.');
        }
        $advances = new AdvanceCoveredPaidStatus($this->db);
        // I ve starším snímku transakce musí částka i datum vycházet z aktuálních úhrad.
        $paidDate = $advances->lastPaymentDates($supplierId, [$id], currentRead: true)[$id] ?? null;
        // Dobropisy a výplaty mají vlastní podepsané toky; záporný zbytek u nich nedokazuje úhradu.
        if ($paidDate !== null) {
            $remaining = PurchaseSettledExpr::remainingAmount('pi', currentRead: true);
            $this->db->pdo()->prepare(
                "UPDATE purchase_invoices pi SET status = 'paid', paid_at = ?
                  WHERE pi.supplier_id = ? AND pi.id = ? AND pi.status = 'received'
                    AND pi.document_kind IN ('invoice', 'advance') AND pi.amount_to_pay > 0.005
                    AND ({$remaining}) <= 0.005"
            )->execute([$paidDate, $supplierId, $id]);
        }
        // Nulový doplatek sám o sobě není důkaz úhrady: rozhoduje navázaná uhrazená záloha.
        $advances->syncFinal($supplierId, $id, allowRevert: false, currentRead: true);
    }
}
