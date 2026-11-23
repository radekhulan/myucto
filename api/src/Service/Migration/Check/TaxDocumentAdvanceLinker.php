<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Check;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;

/**
 * Navázání vydaného daňového dokladu k přijaté platbě (DDKP) na zálohu (proformu), jak ho
 * dělá převod z POHODY ({@see \MyInvoice\Service\Migration\Pohoda\DocumentLinker}): DDKP
 * dostane `parent_invoice_id` zálohy. Částky, DPH ani zaúčtování DDKP se nemění.
 *
 * Volá ho oprava kontroly {@see Checks\TaxDocumentAdvanceLinkCheck}. Jakmile bude v aplikaci
 * ruční navázání DDKP na zálohu (služba pro detail faktury), má oprava jít přes ni, aby
 * navázání z kontroly i z detailu faktury hlídala stejná pravidla.
 */
final class TaxDocumentAdvanceLinker
{
    public const AUDIT_ACTION = 'invoice.tax_document_advance_linked';

    public function __construct(
        private readonly Connection $db,
        private readonly ActivityLogger $activity,
    ) {}

    /** Naváže DDKP na zálohu, pokud ještě vazbu nemá. Volat UVNITŘ transakce volajícího. */
    public function link(int $supplierId, int $taxDocumentId, int $proformaId, ?int $userId, string $source): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE invoices td
               JOIN invoices p ON p.id = ? AND p.supplier_id = td.supplier_id AND p.invoice_type = 'proforma' AND p.status <> 'cancelled'
                SET td.parent_invoice_id = p.id
              WHERE td.id = ? AND td.supplier_id = ? AND td.invoice_type = 'tax_document' AND td.parent_invoice_id IS NULL"
        );
        $stmt->execute([$proformaId, $taxDocumentId, $supplierId]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }
        $this->activity->log(self::AUDIT_ACTION, $userId, 'invoice', $taxDocumentId, [
            'source' => $source,
            'parent_invoice_id' => $proformaId,
        ], null, null, $supplierId);
        return true;
    }
}
