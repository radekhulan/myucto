<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Report\VatLedgerService;
use MyInvoice\Service\Vat\FiledVatPeriodLookup;

/**
 * Oprava přijatých dokladů převedených z POHODY s prohozenými daty DPH.
 *
 * Převod dřív zapisoval „Datum odp./doruč." (datum odpočtu) do `tax_date` a „Datum KH
 * DPH" (DUZP z dokladu dodavatele) do ručního data přijetí. Období přiznání tím zůstalo
 * správné (evidence DPH bere pozdější z obou dat), ale kontrolní hlášení neslo jako DPPD
 * datum odpočtu a od DUZP se odvíjelo i datum nákladu (`effective_cost_date`).
 *
 * Výběr: doklady z mapy převodu (`pohoda_import_map`, druh `purchase_invoice`: faktury,
 * závazky, zálohy i daňové doklady z interních dokladů) s ručním datem přijetí DŘÍV než
 * `tax_date`. Přesně takové zapsal jen původní převod (datum KH DPH před datem odpočtu);
 * opravený převod ruční datum přijetí před DUZP nezapisuje ({@see PohodaPurchaseVatDates})
 * a po prohození platí `received_at > tax_date`, takže opravený doklad už výběru neodpovídá
 * a opakovaný běh nic nemění.
 *
 * Oprava data prohodí. Přeskočí a vypíše doklad:
 *  - zahraniční přenesení daňové povinnosti s daty v různých měsících: evidence DPH ho
 *    řadí podle `tax_date`, prohození by přesunulo přiznané samovyměření do jiného období
 *    (stejné pravidlo jako převod, {@see PohodaPurchaseVatDates});
 *  - v období s podaným přiznáním nebo kontrolním hlášením ({@see FiledVatPeriodLookup});
 *  - v uzavřeném nebo uzavíraném účetním období a v rozsahu zámku k datu;
 *  - zaúčtovaný v MyÚčtu (zápis, který nepochází z deníku POHODY): datum zápisu se řídí
 *    DUZP, změnu má udělat účetní přes doklad, ať se zápis přeúčtuje.
 *
 * Evidence DPH i KH se počítají z dokladů živě a `effective_cost_date` je generovaný
 * sloupec, po změně dat se tedy nic dalšího nepřepočítává. Zápisy převzaté z deníku POHODY
 * mají datum z POHODY a na `tax_date` nezávisí. Každý opravený doklad má záznam v auditní
 * stopě. Bez `$apply` jen vypíše, co by udělala.
 */
final class PohodaDuzpRepair
{
    public const AUDIT_ACTION = 'purchase_invoice.pohoda_duzp_repaired';

    public function __construct(
        private readonly Connection $db,
        private readonly VatLedgerService $ledger,
        private readonly FiledVatPeriodLookup $filed,
        private readonly DocumentLockService $locks,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @return list<array{id:int,supplier_id:int,document_no:string,pohoda_key:string,tax_date:string,received_at:string,
     *                    status:'repaired'|'would_repair'|'skipped',reason:?string,detail:?string}>
     */
    public function run(?int $supplierId, bool $apply, ?int $userId = null): array
    {
        $candidates = $this->candidates($supplierId);
        $out = [];
        foreach ($candidates as $doc) {
            [$reason, $detail] = $this->skipReason($doc);
            $row = [
                'id' => $doc['id'], 'supplier_id' => $doc['supplier_id'], 'document_no' => $doc['varsymbol'], 'pohoda_key' => $doc['pohoda_key'],
                'tax_date' => $doc['tax_date'], 'received_at' => $doc['received_at'],
                'status' => $reason !== null ? 'skipped' : ($apply ? 'repaired' : 'would_repair'),
                'reason' => $reason, 'detail' => $detail,
            ];
            if ($reason === null && $apply && !$this->swap($doc, $userId)) {
                $row['status'] = 'skipped';
                $row['reason'] = 'changed';
                $row['detail'] = 'doklad se mezitím změnil';
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * @return list<array{id:int,supplier_id:int,varsymbol:string,pohoda_key:string,tax_date:string,received_at:string,
     *                    issue_date:string,effective_cost_date:string,reverse_charge:bool,vendor_iso2:?string,claim_date:string,
     *                    item_codes:list<?string>,myucto_entries:list<string>}>
     */
    private function candidates(?int $supplierId): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "SELECT pi.id, pi.supplier_id, pi.varsymbol, m.pohoda_key, pi.tax_date, pi.received_at, pi.issue_date,
                    pi.effective_cost_date, pi.reverse_charge, pi.vat_classification_code, co.iso2 AS vendor_iso2
               FROM pohoda_import_map m
               JOIN purchase_invoices pi ON pi.id = m.target_id AND pi.supplier_id = m.supplier_id
               JOIN clients c ON c.id = pi.vendor_id
          LEFT JOIN countries co ON co.id = c.country_id
              WHERE m.kind = ?" . ($supplierId !== null ? ' AND m.supplier_id = ?' : '') . "
                AND pi.received_at_source = 'manual'
                AND pi.tax_date IS NOT NULL
                AND pi.received_at < pi.tax_date
           ORDER BY pi.supplier_id, pi.tax_date, pi.id"
        );
        $stmt->execute($supplierId !== null ? [PohodaImportRepository::KIND_PURCHASE_INVOICE, $supplierId] : [PohodaImportRepository::KIND_PURCHASE_INVOICE]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === []) {
            return [];
        }
        $docs = [];
        $bySupplier = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $docs[$id] = [
                'id' => $id, 'supplier_id' => (int) $r['supplier_id'], 'varsymbol' => (string) $r['varsymbol'], 'pohoda_key' => (string) $r['pohoda_key'],
                'tax_date' => substr((string) $r['tax_date'], 0, 10), 'received_at' => substr((string) $r['received_at'], 0, 10),
                'issue_date' => substr((string) $r['issue_date'], 0, 10), 'effective_cost_date' => substr((string) $r['effective_cost_date'], 0, 10),
                'reverse_charge' => (int) $r['reverse_charge'] === 1, 'header_code' => $r['vat_classification_code'] !== null ? (string) $r['vat_classification_code'] : null,
                'vendor_iso2' => $r['vendor_iso2'] !== null ? (string) $r['vendor_iso2'] : null,
                'claim_date' => '', 'item_codes' => [], 'myucto_entries' => [],
            ];
            $bySupplier[(int) $r['supplier_id']][] = $id;
        }
        foreach ($bySupplier as $sid => $ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                foreach ($this->ledger->purchaseClaimInfo($sid, $chunk) as $id => $info) {
                    $docs[$id]['claim_date'] = substr((string) $info['claim_date'], 0, 10);
                }
                $in = implode(',', array_fill(0, count($chunk), '?'));
                $items = $pdo->prepare("SELECT purchase_invoice_id, vat_classification_code FROM purchase_invoice_items WHERE purchase_invoice_id IN ({$in})");
                $items->execute($chunk);
                foreach ($items->fetchAll(\PDO::FETCH_NUM) as [$id, $code]) {
                    $docs[(int) $id]['item_codes'][] = $code !== null ? (string) $code : null;
                }
                // Zápis zaúčtovaný v MyÚčtu (ne převzatý z deníku POHODY) má datum podle DUZP.
                $entries = $pdo->prepare(
                    "SELECT e.source_id, e.entry_date FROM journal_entries e
                      WHERE e.supplier_id = ? AND e.source_type = 'purchase_invoice' AND e.source_id IN ({$in}) AND e.reversed_by IS NULL
                        AND NOT EXISTS (SELECT 1 FROM pohoda_import_map jm
                                         WHERE jm.supplier_id = e.supplier_id AND jm.kind IN (?, ?) AND jm.target_id = e.id)"
                );
                $entries->execute([$sid, ...$chunk, PohodaImportRepository::KIND_JOURNAL_ENTRY, PohodaImportRepository::KIND_DERIVED_ENTRY]);
                foreach ($entries->fetchAll(\PDO::FETCH_NUM) as [$id, $date]) {
                    $docs[(int) $id]['myucto_entries'][] = substr((string) $date, 0, 10);
                }
            }
        }
        return array_values($docs);
    }

    /**
     * @param array<string,mixed> $doc
     * @return array{0:?string,1:?string} důvod přeskočení a podrobnost, [null, null] = opravit
     */
    private function skipReason(array $doc): array
    {
        $sid = $doc['supplier_id'];
        if (VatLedgerService::purchaseClaimFollowsTaxDate($doc['reverse_charge'], $doc['header_code'], $doc['item_codes'], $doc['vendor_iso2'])
            && !PohodaPurchaseVatDates::sameMonth($doc['tax_date'], $doc['received_at'])) {
            return ['foreign_reverse_other_month', 'zahraniční přenesení, DUZP ' . $doc['received_at'] . ' v jiném měsíci než přiznání ' . $doc['tax_date']];
        }
        // Doklad je v přiznání i KH jen za období odpočtu a prohození ho nemění: u tuzemského
        // dokladu je to pozdější z obou dat, u zahraničního přenesení ve stejném měsíci DUZP.
        $filed = $this->filed->describe($sid, $doc['claim_date'] !== '' ? $doc['claim_date'] : $doc['tax_date'], false);
        if ($filed !== null) {
            return ['filed_vat_period', $filed];
        }
        $newCostDate = max($doc['received_at'], $doc['issue_date']);
        foreach (array_unique([$doc['effective_cost_date'], $newCostDate]) as $date) {
            $lock = $this->locks->forDate($sid, $date);
            if ($lock->inClosedPeriod || $lock->inClosingPeriod) {
                return ['closed_period', 'účetní období ' . ($lock->periodStatus ?? '') . ' (' . $date . ')'];
            }
            if ($lock->dateLocked) {
                return ['date_locked', 'zámek k datu (' . $date . ')'];
            }
        }
        if ($doc['myucto_entries'] !== []) {
            return ['posted_in_myucto', 'zápis v MyÚčtu k ' . implode(', ', $doc['myucto_entries'])];
        }
        return [null, null];
    }

    /** @param array<string,mixed> $doc */
    private function swap(array $doc, ?int $userId): bool
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            // Podmínka na původní hodnoty: doklad upravený mezi výběrem a zápisem zůstane.
            $stmt = $pdo->prepare(
                "UPDATE purchase_invoices SET tax_date = ?, received_at = ?
                  WHERE id = ? AND supplier_id = ? AND received_at_source = 'manual' AND tax_date = ? AND received_at = ?"
            );
            $stmt->execute([$doc['received_at'], $doc['tax_date'], $doc['id'], $doc['supplier_id'], $doc['tax_date'], $doc['received_at']]);
            if ($stmt->rowCount() !== 1) {
                if ($own) {
                    $pdo->rollBack();
                }
                return false;
            }
            $this->activity->log(self::AUDIT_ACTION, $userId, 'purchase_invoice', $doc['id'], [
                'source' => 'pohoda_duzp_repair',
                'document_no' => $doc['varsymbol'],
                'pohoda_key' => $doc['pohoda_key'],
                'tax_date' => ['old' => $doc['tax_date'], 'new' => $doc['received_at']],
                'received_at' => ['old' => $doc['received_at'], 'new' => $doc['tax_date']],
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
