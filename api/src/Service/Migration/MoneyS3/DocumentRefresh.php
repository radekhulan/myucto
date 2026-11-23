<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\Shared\MigratedDocumentItem;
use MyInvoice\Service\Migration\Shared\MigratedDocumentWriter;
use PDO;

/**
 * Aktualizace převzatého dokladu (vydaná a přijatá faktura), který Money od minulého
 * převodu změnilo: částky po sazbách, data a položky se přepíšou podle Money, když s dokladem
 * v MyÚčtu nikdo nepracoval. Jinak (a když se změnil druh dokladu, členění DPH nebo měna)
 * převod změnu jen ohlásí.
 *
 * Otisk v mapě převodu má dvě poloviny ({@see fingerprint()}): zdroj = pole dokladu v Money,
 * která se do dokladu převádějí ({@see sourceHash()}), stav = doklad, jak ho převod zapsal
 * (součty, data a položky, {@see stateHash()}). Jiný zdroj = Money doklad změnilo, jiný stav
 * = doklad upravil uživatel. Doklad převedený před zavedením otisků se jen ohlásí (jako dřív)
 * a otisk se mu doplní, až ho Money a MyÚčto mají shodně.
 */
final class DocumentRefresh
{
    /** Pole dokladu Money, na kterých převzatý doklad závisí (bez textů). */
    private const SOURCE_FIELDS = [
        'Vystaveno', 'DatUcPr', 'PlnenoDPH', 'Splatno', 'Doruceno', 'CelkemSDPH', 'SumZaloha', 'KodDPH', 'Druh', 'Dobropis',
        'Storno', 'Neuctovat', 'RevCH', 'Mena', 'Kurs', 'PocetJedn', 'VarSymbol', 'PrijatDokl',
        'Zaklad_0', 'Zaklad_1', 'Zaklad_2', 'Zaklad_3', 'Zaklad_4', 'Zaklad_5', 'Zaklad_6',
        'SazbaDPH1', 'SazbaDPH2', 'SazbaDPH3', 'SazbaDPH4', 'SazbaDPH5', 'SazbaDPH6',
        'DPH_1', 'DPH_2', 'DPH_3', 'DPH_4', 'DPH_5', 'DPH_6', 'DPH1', 'DPH2', 'DPH3', 'DPH4', 'DPH5', 'DPH6',
        'ValutyZak0', 'ValutyZak1', 'ValutyZak2', 'ValutyZak3', 'ValutyZak4', 'ValutyZak5', 'ValutyZak6',
        'ValutyDPH1', 'ValutyDPH2', 'ValutyDPH3', 'ValutyDPH4', 'ValutyDPH5', 'ValutyDPH6',
    ];

    public function __construct(private readonly Connection $db) {}

    /** @param array<string,mixed> $r řádek dokladu Money */
    public static function sourceHash(array $r): string
    {
        $out = [];
        foreach (self::SOURCE_FIELDS as $field) {
            if (!array_key_exists($field, $r)) {
                continue;
            }
            $v = $r[$field];
            $out[$field] = is_float($v) || is_int($v) ? number_format((float) $v, 2, '.', '') : trim((string) $v);
        }
        return substr(hash('sha256', (string) json_encode($out)), 0, 32);
    }

    /**
     * Stav dokladu tak, jak ho zapsal převod: součty, data a položky (základ a daň).
     *
     * @param list<array{0:float|string,1:float|string}> $items základ a daň položek
     */
    public static function stateHash(string $issueDate, ?string $taxDate, float|string $base, float|string $vat, float|string $total, array $items): string
    {
        $fmt = static fn (float|string $v): string => number_format((float) $v, 2, '.', '');
        $lines = array_map(static fn (array $i): string => $fmt($i[0]) . '/' . $fmt($i[1]), $items);
        sort($lines);
        return substr(hash('sha256', (string) json_encode([
            substr($issueDate, 0, 10), substr((string) $taxDate, 0, 10), $fmt($base), $fmt($vat), $fmt($total), $lines,
        ])), 0, 32);
    }

    /** Otisk v mapě převodu: zdroj + stav. */
    public static function fingerprint(string $source, string $state): string
    {
        return $source . $state;
    }

    /**
     * Stav dokladů, jak jsou dnes v MyÚčtu ({@see stateHash()}), dvěma dotazy na dávku.
     *
     * @param list<int> $ids
     * @return array<int,array{state:string,status:string,currency:string,invoice_type:?string,document_kind:?string,vat_classification_code:?string,reverse_charge:bool,issue_date:string,tax_date:?string,client_id:?int}>
     */
    public function states(int $supplierId, bool $issued, array $ids): array
    {
        [$table, $itemsTable, $fk] = self::tables($issued);
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $items = [];
            $stmt = $this->db->pdo()->prepare("SELECT {$fk}, total_without_vat, total_vat FROM {$itemsTable} WHERE {$fk} IN ({$in})");
            $stmt->execute($chunk);
            while (($r = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
                $items[(int) $r[0]][] = [$r[1], $r[2]];
            }
            $kind = $issued ? 'd.invoice_type, NULL AS document_kind, d.client_id' : 'NULL AS invoice_type, d.document_kind, NULL AS client_id';
            $stmt = $this->db->pdo()->prepare(
                "SELECT d.id, d.issue_date, d.tax_date, d.total_without_vat, d.total_vat, d.total_with_vat, d.status, cur.code AS currency,
                        {$kind}, d.vat_classification_code, d.reverse_charge
                   FROM {$table} d JOIN currencies cur ON cur.id = d.currency_id
                  WHERE d.supplier_id = ? AND d.id IN ({$in})"
            );
            $stmt->execute([$supplierId, ...$chunk]);
            while (($r = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $id = (int) $r['id'];
                $out[$id] = [
                    'state' => self::stateHash((string) $r['issue_date'], $r['tax_date'], $r['total_without_vat'], $r['total_vat'], $r['total_with_vat'], $items[$id] ?? []),
                    'status' => (string) $r['status'],
                    'currency' => (string) $r['currency'],
                    'invoice_type' => $r['invoice_type'],
                    'document_kind' => $r['document_kind'],
                    'vat_classification_code' => $r['vat_classification_code'],
                    'reverse_charge' => (bool) $r['reverse_charge'],
                    'issue_date' => (string) $r['issue_date'],
                    'tax_date' => $r['tax_date'],
                    'client_id' => $r['client_id'] === null ? null : (int) $r['client_id'],
                ];
            }
        }
        return $out;
    }

    /**
     * Doklady, se kterými se v MyÚčtu pracovalo: úhrada nebo párování zadané v MyÚčtu,
     * zápočet, dohoda o započtení, dobropis či daňový doklad navázaný na doklad, čerpání
     * zálohy. Takový doklad převod nemění.
     *
     * @param list<int> $ids
     * @return array<int,true>
     */
    public function touched(int $supplierId, bool $issued, array $ids): array
    {
        $out = [];
        $type = $issued ? 'invoice' : 'purchase_invoice';
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $sql = $issued
                ? "SELECT d.id FROM invoices d WHERE d.supplier_id = ? AND d.id IN ({$in}) AND (
                       EXISTS (SELECT 1 FROM invoice_payments ip WHERE ip.invoice_id = d.id AND (ip.note IS NULL OR ip.note NOT LIKE ?))
                    OR EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.invoice_id = d.id AND NOT " . DocumentLinker::ownMatchSql('pm') . ")
                    OR EXISTS (SELECT 1 FROM invoices c WHERE c.supplier_id = d.supplier_id AND c.parent_invoice_id = d.id)
                    OR EXISTS (SELECT 1 FROM invoice_advance_deductions x WHERE x.final_invoice_id = d.id OR x.advance_invoice_id = d.id)"
                : "SELECT d.id FROM purchase_invoices d WHERE d.supplier_id = ? AND d.id IN ({$in}) AND (
                       EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.purchase_invoice_id = d.id AND NOT " . DocumentLinker::ownMatchSql('pm') . ")
                    OR EXISTS (SELECT 1 FROM purchase_advance_deductions x WHERE x.final_invoice_id = d.id OR x.advance_invoice_id = d.id)";
            $sql .= " OR EXISTS (SELECT 1 FROM invoice_settlements s WHERE s.doc_type = ? AND s.doc_id = d.id)
                    OR EXISTS (SELECT 1 FROM offset_agreement_items o WHERE o.doc_type = ? AND o.doc_id = d.id)
                    OR EXISTS (SELECT 1 FROM credit_note_offsets n WHERE n.doc_type = ? AND (n.invoice_id = d.id OR n.credit_note_id = d.id)))";
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute([$supplierId, ...$chunk, ...($issued ? [IssuedPaymentRecorder::NOTE_PREFIX . '%'] : []), $type, $type, $type]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $out[(int) $id] = true;
            }
        }
        return $out;
    }

    /**
     * Přepíše částky, data a položky dokladu podle Money. Uhrazený vydaný doklad zůstává
     * uhrazený v nové částce k úhradě (úhrada podle Money platí dál).
     *
     * @param array{issue_date:string,tax_date:string,due_date:string,base:float,vat:float,total:float,rounding:float} $header
     * @param list<MigratedDocumentItem> $items
     */
    public function update(int $supplierId, bool $issued, int $id, array $header, array $items, MigratedDocumentWriter $writer): void
    {
        [$table, $itemsTable, $fk] = self::tables($issued);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "UPDATE {$table} SET issue_date = ?, tax_date = ?, due_date = ?, total_without_vat = ?, total_vat = ?, total_with_vat = ?, rounding = ?
              WHERE id = ? AND supplier_id = ?"
        )->execute([$header['issue_date'], $header['tax_date'], $header['due_date'], $header['base'], $header['vat'], $header['total'], $header['rounding'], $id, $supplierId]);
        $pdo->prepare("DELETE FROM {$itemsTable} WHERE {$fk} = ?")->execute([$id]);
        $issued ? $writer->insertIssuedItems($id, $items) : $writer->insertPurchaseItems($id, $items);
        if ($issued) {
            $pdo->prepare("UPDATE invoices SET paid_total = amount_to_pay WHERE id = ? AND supplier_id = ? AND status = 'paid'")->execute([$id, $supplierId]);
        }
    }

    /** @return array{0:string,1:string,2:string} doklady, položky, cizí klíč položky */
    private static function tables(bool $issued): array
    {
        return $issued ? ['invoices', 'invoice_items', 'invoice_id'] : ['purchase_invoices', 'purchase_invoice_items', 'purchase_invoice_id'];
    }
}
