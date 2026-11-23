<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\Shared\MigratedDocumentItem;
use MyInvoice\Service\Migration\Shared\MigratedDocumentWriter;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Vat\FiledVatPeriodLookup;
use PDO;

/**
 * Aktualizace převzatého dokladu (vydaná a přijatá faktura), který Money od minulého
 * převodu změnilo: částky po sazbách, data a položky se přepíšou podle Money, když s dokladem
 * v MyÚčtu nikdo nepracoval. Jinak (a když se změnil druh dokladu, členění DPH nebo měna)
 * převod změnu jen ohlásí.
 *
 * Otisk v mapě převodu má tři části ({@see fingerprint()}): pole dokladu v Money, která
 * aktualizace přepíše (částky, sazby, data vystavení, plnění a splatnosti), ostatní pole
 * Money, na kterých doklad závisí ({@see sourceHash()}), a stav dokladu, jak ho převod zapsal
 * (součty, data a položky, {@see stateHash()}). Jiný zdroj = Money doklad změnilo, jiný stav
 * = doklad upravil uživatel. Změnu ostatních polí (číslo dokladu dodavatele, doručení,
 * variabilní symbol, zaúčtování, záloha, druh) aktualizace přenést neumí a jen ji ohlásí.
 * Doklad převedený před zavedením otisků se jen ohlásí (jako dřív) a otisk se mu doplní,
 * až ho Money a MyÚčto mají shodně.
 *
 * Doklad v období, za které je podané přiznání DPH nebo kontrolní hlášení, převod nemění
 * ({@see FiledVatPeriodLookup}).
 */
final class DocumentRefresh
{
    /** Pole dokladu Money, která aktualizace přepíše ({@see update()}). */
    private const APPLIED_FIELDS = [
        'Vystaveno', 'PlnenoDPH', 'Splatno', 'CelkemSDPH',
        'Zaklad_0', 'Zaklad_1', 'Zaklad_2', 'Zaklad_3', 'Zaklad_4', 'Zaklad_5', 'Zaklad_6',
        'SazbaDPH1', 'SazbaDPH2', 'SazbaDPH3', 'SazbaDPH4', 'SazbaDPH5', 'SazbaDPH6',
        'DPH_1', 'DPH_2', 'DPH_3', 'DPH_4', 'DPH_5', 'DPH_6', 'DPH1', 'DPH2', 'DPH3', 'DPH4', 'DPH5', 'DPH6',
    ];

    /** Ostatní pole dokladu Money, na kterých převzatý doklad závisí (bez textů); jejich změnu převod jen ohlásí. */
    private const OTHER_FIELDS = [
        'DatUcPr', 'Doruceno', 'SumZaloha', 'KodDPH', 'Druh', 'Dobropis', 'Storno', 'Neuctovat', 'RevCH',
        'Mena', 'Kurs', 'PocetJedn', 'VarSymbol', 'PrijatDokl',
        'ValutyZak0', 'ValutyZak1', 'ValutyZak2', 'ValutyZak3', 'ValutyZak4', 'ValutyZak5', 'ValutyZak6',
        'ValutyDPH1', 'ValutyDPH2', 'ValutyDPH3', 'ValutyDPH4', 'ValutyDPH5', 'ValutyDPH6',
    ];

    private readonly FiledVatPeriodLookup $filed;

    public function __construct(private readonly Connection $db)
    {
        $this->filed = new FiledVatPeriodLookup(new TaxSubmissionRepository($db));
    }

    /**
     * Otisk polí dokladu Money: 16 znaků za pole, která aktualizace přepíše, a 16 za ostatní.
     *
     * @param array<string,mixed> $r řádek dokladu Money
     */
    public static function sourceHash(array $r): string
    {
        $hash = static function (array $fields) use ($r): string {
            $out = [];
            foreach ($fields as $field) {
                if (!array_key_exists($field, $r)) {
                    continue;
                }
                $v = $r[$field];
                $out[$field] = is_float($v) || is_int($v) ? number_format((float) $v, 2, '.', '') : trim((string) $v);
            }
            return substr(hash('sha256', (string) json_encode($out)), 0, 16);
        };
        return $hash(self::APPLIED_FIELDS) . $hash(self::OTHER_FIELDS);
    }

    /** Liší se ostatní pole Money (ta, která aktualizace nepřenese)? */
    public static function otherFieldsChanged(string $stored, string $source): bool
    {
        return substr($stored, 16, 16) !== substr($source, 16, 16);
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
     * @return array<int,array{state:string,status:string,currency:string,invoice_type:?string,document_kind:?string,vat_classification_code:?string,reverse_charge:bool,issue_date:string,tax_date:?string,client_id:?int,received_at:?string}>
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
            $kind = $issued ? 'd.invoice_type, NULL AS document_kind, d.client_id, NULL AS received_at' : 'NULL AS invoice_type, d.document_kind, NULL AS client_id, d.received_at';
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
                    'received_at' => $r['received_at'] === null ? null : substr((string) $r['received_at'], 0, 10),
                ];
            }
        }
        return $out;
    }

    /**
     * Doklady, se kterými se v MyÚčtu pracovalo ({@see touchedQueries()}), a doklady navázané
     * na zápis deníku, který převod ponechal kvůli zásahu uživatele (`$keptEntries`) - doklad
     * se nepřepíše, aby se nerozešel se svým zápisem.
     *
     * @param list<int> $ids
     * @param list<int> $keptEntries
     * @return array<int,true>
     */
    public function touched(int $supplierId, bool $issued, array $ids, array $keptEntries = []): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $parts = array_map(static fn (string $q): string => str_replace('{ids}', $in, $q), self::touchedQueries($issued));
            $stmt = $this->db->pdo()->prepare(implode("\nUNION\n", $parts));
            $stmt->execute(array_merge(...array_fill(0, count($parts), [$supplierId, ...$chunk])));
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $out[(int) $id] = true;
            }
        }
        $type = $issued ? 'invoice' : 'purchase_invoice';
        foreach (array_chunk($keptEntries, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT k.doc_id FROM journal_entry_document_links k WHERE k.supplier_id = ? AND k.doc_type = ? AND k.entry_id IN ({$in})
                 UNION SELECT e.source_id FROM journal_entries e WHERE e.supplier_id = ? AND e.source_type = ? AND e.id IN ({$in}) AND e.source_id IS NOT NULL"
            );
            $stmt->execute([$supplierId, $type, ...$chunk, $supplierId, $type, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $out[(int) $id] = true;
            }
        }
        return $out;
    }

    /**
     * Dotazy „s dokladem se v MyÚčtu pracovalo": stornovaný, odeslaný, upomínaný nebo
     * s poděkováním za platbu, s uloženou verzí PDF, přílohou, dokumentem, dimenzí typu,
     * který převod nespravuje, splátkovým kalendářem, úhradou nebo párováním zadaným
     * v MyÚčtu, zápočtem, dohodou o započtení, dobropisem, navázaným dokladem (dobropis,
     * daňový doklad k platbě), čerpáním zálohy, opravou § 46 / § 74b, opravnou položkou,
     * dodaněním neuhrazeného závazku; přijatý doklad navíc se skenem, schvalováním,
     * majetkem, drobným majetkem nebo skladem z položky. Každý dotaz má parametry firma
     * a id dokladů (`{ids}` = zástupné znaky seznamu) a jde od dokladu po indexu odkazující
     * tabulky. Společné aktualizaci dokladu i zápisu, který na doklad odkazuje
     * ({@see JournalEntryRefresh::touchedEntries()}).
     *
     * @return list<string>
     */
    public static function touchedQueries(bool $issued): array
    {
        $table = $issued ? 'invoices' : 'purchase_invoices';
        $type = $issued ? "'invoice'" : "'purchase_invoice'";
        $base = "SELECT d.id FROM {$table} d %s WHERE d.supplier_id = ? AND d.id IN ({ids})%s";
        $join = static fn (string $joins, string $where = ''): string => sprintf($base, $joins, $where);
        $managed = implode(', ', array_map(static fn (string $c): string => "'{$c}'", DimensionImporter::MANAGED_TYPE_CODES));
        $queries = [
            $join("JOIN document_links x ON x.entity_type = {$type} AND x.entity_id = d.id AND x.supplier_id = d.supplier_id"),
            $join("JOIN document_dimensions x ON x.supplier_id = d.supplier_id AND x.doc_type = {$type} AND x.doc_id = d.id
                   JOIN dimension_types t ON t.id = x.dimension_type_id", " AND t.code NOT IN ({$managed})"),
            $join('JOIN payment_matches pm ON pm.' . ($issued ? 'invoice_id' : 'purchase_invoice_id') . ' = d.id AND pm.supplier_id = d.supplier_id',
                ' AND NOT ' . DocumentLinker::ownMatchSql('pm')),
            $join("JOIN invoice_settlements x ON x.supplier_id = d.supplier_id AND x.doc_type = {$type} AND x.doc_id = d.id"),
            $join("JOIN offset_agreement_items x ON x.supplier_id = d.supplier_id AND x.doc_type = {$type} AND x.doc_id = d.id"),
            $join("JOIN credit_note_offsets x ON x.supplier_id = d.supplier_id AND x.doc_type = {$type} AND x.invoice_id = d.id"),
            $join("JOIN credit_note_offsets x ON x.doc_type = {$type} AND x.credit_note_id = d.id AND x.supplier_id = d.supplier_id"),
        ];
        if ($issued) {
            $note = str_replace("'", "''", IssuedPaymentRecorder::NOTE_PREFIX) . '%';
            return [...$queries,
                $join('', " AND (d.status = 'cancelled' OR d.sent_at IS NOT NULL OR d.reminder_count > 0 OR d.last_reminder_at IS NOT NULL OR d.payment_thanks_sent_at IS NOT NULL)"),
                $join('JOIN invoice_pdfs x ON x.invoice_id = d.id'),
                $join('JOIN invoice_attachments x ON x.invoice_id = d.id'),
                $join('JOIN invoice_payment_schedule x ON x.invoice_id = d.id AND x.supplier_id = d.supplier_id'),
                $join('JOIN invoice_payments x ON x.invoice_id = d.id AND x.supplier_id = d.supplier_id', " AND (x.note IS NULL OR x.note NOT LIKE '{$note}')"),
                $join('JOIN invoices c ON c.supplier_id = d.supplier_id AND c.parent_invoice_id = d.id'),
                $join('JOIN invoice_advance_deductions x ON x.supplier_id = d.supplier_id AND x.final_invoice_id = d.id'),
                $join('JOIN invoice_advance_deductions x ON x.supplier_id = d.supplier_id AND x.advance_invoice_id = d.id'),
                $join('JOIN vat_s46_corrections x ON x.supplier_id = d.supplier_id AND x.invoice_id = d.id'),
                $join('JOIN accounting_receivable_provisions x ON x.supplier_id = d.supplier_id AND x.invoice_id = d.id'),
            ];
        }
        return [...$queries,
            $join('', " AND (d.status = 'cancelled' OR d.pdf_path IS NOT NULL)"),
            $join('JOIN purchase_invoice_approvals x ON x.purchase_invoice_id = d.id AND x.supplier_id = d.supplier_id'),
            $join('JOIN purchase_advance_deductions x ON x.supplier_id = d.supplier_id AND x.final_invoice_id = d.id'),
            $join('JOIN purchase_advance_deductions x ON x.supplier_id = d.supplier_id AND x.advance_invoice_id = d.id'),
            $join('JOIN assets x ON x.purchase_invoice_id = d.id AND x.supplier_id = d.supplier_id'),
            $join('JOIN purchase_invoice_items i ON i.purchase_invoice_id = d.id JOIN assets x ON x.purchase_invoice_item_id = i.id AND x.supplier_id = d.supplier_id'),
            $join('JOIN small_assets x ON x.purchase_invoice_id = d.id AND x.supplier_id = d.supplier_id'),
            $join('JOIN purchase_invoice_items i ON i.purchase_invoice_id = d.id JOIN stock_document_lines x ON x.purchase_invoice_item_id = i.id AND x.supplier_id = d.supplier_id'),
            $join('JOIN vat_s74b_corrections x ON x.supplier_id = d.supplier_id AND x.purchase_invoice_id = d.id'),
            $join('JOIN tax_unpaid_liability_addbacks x ON x.supplier_id = d.supplier_id AND x.purchase_invoice_id = d.id'),
        ];
    }

    /**
     * Popis podaného přiznání DPH, kontrolního hlášení nebo OSS, které pokrývá některé
     * z dat (plnění, u přijatého dokladu i nárok na odpočet), nebo null.
     *
     * @param list<?string> $dates
     */
    public function filedPeriod(int $supplierId, array $dates): ?string
    {
        foreach (array_unique(array_filter($dates)) as $date) {
            $filed = $this->filed->describe($supplierId, substr((string) $date, 0, 10));
            if ($filed !== null) {
                return $filed;
            }
        }
        return null;
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
