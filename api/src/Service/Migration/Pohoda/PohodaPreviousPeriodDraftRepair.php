<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Vat\FiledVatPeriodLookup;
use PDO;

/**
 * Oprava dokladů minulého období převedených z POHODY jako koncept k ruční kontrole DPH.
 *
 * Převod dřív dával do konceptu i doklad minulého období (zaúčtovaný před rokem agendy, zůstatek
 * v počátečních stavech), když jeho členění DPH neuměl zařadit, typicky „UN" s daní bez státu
 * MOSS. DPH takového dokladu podal původní program, koncept na ní nic neopravil, jen doklad
 * vyřadil ze saldokonta: otevírací zápis ho nese, sestava koncepty nebere a rozdíl saldokonta
 * proti hlavní knize narostl o jeho neuhrazenou část. Opravený převod takový doklad převezme
 * rovnou jako vystavený ({@see InvoiceImporter::heldForReview()}).
 *
 * Výběr: vydaný nebo přijatý doklad z mapy převodu ve stavu koncept, navázaný na živý
 * otevírací zápis (tak převod navazuje jen doklady minulého období), bez vlastního zápisu
 * v deníku a s poznámkou převodu o ruční kontrole. Oprava nastaví stav, jaký by doklad dostal
 * bez kontroly (uhrazený podle data úhrady z převodu, jinak vystavený, přijatý doklad
 * zaúčtovaný, záloha přijatá) a v poznámce změní „K ruční kontrole" na informaci. Částky,
 * položky, úhrady ani deník se nemění.
 *
 * Přeskočí a vypíše doklad v období s podaným přiznáním nebo KH v MyÚčtu a doklad, jehož
 * otevírací zápis leží v uzavřeném nebo uzavíraném období či pod zámkem k datu (změní se
 * saldokonto toho období). Idempotentní: opravený doklad už není konceptem. Každá oprava má
 * záznam v auditní stopě. Bez `$apply` jen vypíše, co by udělala.
 */
final class PohodaPreviousPeriodDraftRepair
{
    public const AUDIT_ACTION = 'document.pohoda_previous_period_draft_repaired';
    private const REVIEW_NOTE = '. K ruční kontrole: ';
    private const INFO_NOTE = '. Členění DPH z POHODY, období už podané: ';

    public function __construct(
        private readonly Connection $db,
        private readonly FiledVatPeriodLookup $filed,
        private readonly DocumentLockService $locks,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @return list<array{doc_type:string,id:int,supplier_id:int,document_no:string,tax_date:string,total:float,
     *                    new_status:string,status:'repaired'|'would_repair'|'skipped',reason:?string,detail:?string}>
     */
    public function run(?int $supplierId, bool $apply, ?int $userId = null): array
    {
        $out = [];
        foreach ($this->candidates($supplierId) as $doc) {
            [$reason, $detail] = $this->skipReason($doc);
            $row = [
                'doc_type' => $doc['doc_type'], 'id' => $doc['id'], 'supplier_id' => $doc['supplier_id'], 'document_no' => $doc['document_no'],
                'tax_date' => $doc['tax_date'], 'total' => $doc['total'], 'new_status' => $doc['new_status'],
                'status' => $reason !== null ? 'skipped' : ($apply ? 'repaired' : 'would_repair'),
                'reason' => $reason, 'detail' => $detail,
            ];
            if ($reason === null && $apply && !$this->repair($doc, $userId)) {
                $row['status'] = 'skipped';
                $row['reason'] = 'changed';
                $row['detail'] = 'doklad se mezitím změnil';
            }
            $out[] = $row;
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function candidates(?int $supplierId): array
    {
        $out = [];
        foreach ([
            ['invoice', 'invoices', PohodaImportRepository::KIND_INVOICE, "d.invoice_type IN ('invoice', 'credit_note')",
                "CASE WHEN d.paid_at IS NOT NULL THEN 'paid' ELSE 'sent' END"],
            ['purchase_invoice', 'purchase_invoices', PohodaImportRepository::KIND_PURCHASE_INVOICE, "d.document_kind IN ('invoice', 'credit_note', 'advance')",
                "CASE WHEN d.paid_at IS NOT NULL THEN 'paid' WHEN d.document_kind = 'advance' THEN 'received' ELSE 'booked' END"],
        ] as [$docType, $table, $kind, $typeFilter, $statusSql]) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT d.id, d.supplier_id, d.varsymbol, d.tax_date, d.issue_date, d.total_with_vat, d.note_below_items,
                        {$statusSql} AS new_status, MIN(oe.entry_date) AS opening_date
                   FROM pohoda_import_map m
                   JOIN {$table} d ON d.id = m.target_id AND d.supplier_id = m.supplier_id
                   JOIN journal_entry_document_links ol ON ol.supplier_id = d.supplier_id AND ol.doc_type = ? AND ol.doc_id = d.id
                   JOIN journal_entries oe ON oe.id = ol.entry_id AND oe.supplier_id = ol.supplier_id
                        AND oe.source_type = 'opening' AND oe.posted_at IS NOT NULL AND oe.reversed_by IS NULL
                  WHERE m.kind = ?" . ($supplierId !== null ? ' AND m.supplier_id = ?' : '') . "
                    AND d.status = 'draft' AND {$typeFilter}
                    AND d.note_below_items LIKE ?
                    AND NOT EXISTS (SELECT 1 FROM journal_entries e
                                     WHERE e.supplier_id = d.supplier_id AND e.source_type = ? AND e.source_id = d.id AND e.reversed_by IS NULL)
               GROUP BY d.id, d.supplier_id, d.varsymbol, d.tax_date, d.issue_date, d.total_with_vat, d.note_below_items, new_status
               ORDER BY d.supplier_id, d.issue_date, d.id"
            );
            $params = [$docType, $kind];
            if ($supplierId !== null) {
                $params[] = $supplierId;
            }
            $stmt->execute([...$params, '%' . self::REVIEW_NOTE . '%', $docType]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[] = [
                    'doc_type' => $docType, 'table' => $table, 'id' => (int) $r['id'], 'supplier_id' => (int) $r['supplier_id'],
                    'document_no' => (string) $r['varsymbol'], 'tax_date' => substr((string) ($r['tax_date'] ?? $r['issue_date']), 0, 10),
                    'total' => round((float) $r['total_with_vat'], 2), 'note' => (string) $r['note_below_items'],
                    'new_status' => (string) $r['new_status'], 'opening_date' => substr((string) $r['opening_date'], 0, 10),
                ];
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $doc
     * @return array{0:?string,1:?string}
     */
    private function skipReason(array $doc): array
    {
        $filed = $this->filed->describe($doc['supplier_id'], $doc['tax_date']);
        if ($filed !== null) {
            return ['filed_vat_period', $filed];
        }
        $lock = $this->locks->forDate($doc['supplier_id'], $doc['opening_date']);
        if ($lock->inClosedPeriod || $lock->inClosingPeriod) {
            return ['closed_period', 'účetní období ' . ($lock->periodStatus ?? '') . ' (' . $doc['opening_date'] . ')'];
        }
        if ($lock->dateLocked) {
            return ['date_locked', 'zámek k datu (' . $doc['opening_date'] . ')'];
        }
        return [null, null];
    }

    /** @param array<string,mixed> $doc */
    private function repair(array $doc, ?int $userId): bool
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $note = str_replace(self::REVIEW_NOTE, self::INFO_NOTE, $doc['note']);
            $booked = $doc['doc_type'] === 'invoice' ? ', booked_at = COALESCE(booked_at, ?)' : '';
            $params = [$doc['new_status'], $note];
            if ($booked !== '') {
                $params[] = $doc['tax_date'] . ' 00:00:00';
            }
            // Podmínka na původní stav a poznámku: doklad upravený mezi výběrem a zápisem zůstane.
            $stmt = $pdo->prepare(
                "UPDATE {$doc['table']} SET status = ?, note_below_items = ?{$booked}
                  WHERE id = ? AND supplier_id = ? AND status = 'draft' AND note_below_items = ?"
            );
            $stmt->execute([...$params, $doc['id'], $doc['supplier_id'], $doc['note']]);
            if ($stmt->rowCount() !== 1) {
                if ($own) {
                    $pdo->rollBack();
                }
                return false;
            }
            $this->activity->log(self::AUDIT_ACTION, $userId, $doc['doc_type'], $doc['id'], [
                'source' => 'pohoda_previous_period_draft_repair',
                'document_no' => $doc['document_no'],
                'status' => ['old' => 'draft', 'new' => $doc['new_status']],
            ], null, null, $doc['supplier_id']);
            if ($own) {
                $pdo->commit();
            }
            return true;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
