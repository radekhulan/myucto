<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Jediný zdroj pravdy o odpočtu poskytnuté zálohy na přijaté konečné faktuře.
 *
 * Dodavatel, který k záloze vystavil daňový doklad k přijaté platbě (DDKP, § 28 ZDPH),
 * vyúčtuje zálohu konečnou fakturou se záporným řádkem: základ a daň DDKP. Řádek je
 * odpočtem zálohy jen tehdy, když ho výslovně označuje `purchase_invoice_items.
 * advance_purchase_invoice_id` (migrace 2307), tedy záloha, kterou faktura vyúčtovává
 * (`purchase_invoices.advance_purchase_invoice_id`: zálohová faktura nebo samostatný DDKP).
 * Samotné záporné znaménko nestačí, záporný řádek bývá i vratka nebo sleva.
 *
 * Vydaná strana má obdobu v {@see \MyInvoice\Support\Sql\AdvanceDrawSql}. Tam odpočtový
 * řádek vystavujeme sami a faktura může čerpat víc záloh; přijatá faktura je na zálohu
 * vázaná 1:1 hlavičkou, takže řádek smí odečítat jen zálohu z hlavičky.
 *
 * Čtou ho zaúčtování ({@see \MyInvoice\Service\Accounting\PostingService}), částka uhrazené
 * zálohy v hlavičce ({@see LinkedAdvanceAmount}), nabídka řádků odpočtu pro editor
 * a kontrola dvojího odpočtu DPH (Měsíční kontrola, varování při uložení dokladu).
 */
final class PurchaseAdvanceDeduction
{
    public function __construct(private readonly Connection $db) {}

    /** Podmínka odpočtového řádku zálohy nad aliasem řádku přijaté faktury. */
    public static function deductionLineSql(string $item): string
    {
        self::assertAlias($item);
        return "{$item}.advance_purchase_invoice_id IS NOT NULL AND {$item}.total_with_vat < 0";
    }

    /** Nese konečná faktura `$final` odpočtový řádek zálohy, kterou vyúčtovává? */
    public static function finalHasDeductionSql(string $final): string
    {
        self::assertAlias($final);
        return "EXISTS (SELECT 1 FROM purchase_invoice_items adv_pii
                         WHERE adv_pii.purchase_invoice_id = {$final}.id
                           AND adv_pii.advance_purchase_invoice_id = {$final}.advance_purchase_invoice_id
                           AND adv_pii.total_with_vat < 0)";
    }

    /**
     * Živé DDKP zálohy `$advance` (alias zálohy): DDKP navázaný na zálohovou fakturu, nebo
     * záloha sama, je-li samostatným DDKP. „Živý" = ne koncept, ne storno, stejně jako
     * na vydané straně.
     */
    public static function advanceHasTaxDocumentSql(string $advance): string
    {
        self::assertAlias($advance);
        return "({$advance}.document_kind = 'tax_document' AND {$advance}.parent_purchase_invoice_id IS NULL
                     AND {$advance}.status NOT IN ('draft', 'cancelled')
                 OR EXISTS (SELECT 1 FROM purchase_invoices adv_td
                             WHERE adv_td.supplier_id = {$advance}.supplier_id
                               AND adv_td.parent_purchase_invoice_id = {$advance}.id
                               AND adv_td.document_kind = 'tax_document'
                               AND adv_td.status NOT IN ('draft', 'cancelled')))";
    }

    /**
     * Konečné faktury firmy navázané na zálohu s živým DDKP, které odpočet zálohy nenesou:
     * daň z DDKP je uplatněná a konečná faktura ji v plné výši uplatní podruhé.
     * Bere se všechno s DUZP (bez DUZP datem vystavení) do `$to` včetně starších období,
     * aby šly dohledat i dřívější doklady. Vazbu na zálohu nese jen menšina dokladů, dotaz
     * proto jde po indexu vazby (`uq_pi_advance_link`), ne po všech dokladech firmy.
     *
     * `amount` = daň DDKP, kterou by faktura uplatnila podruhé (v měně dokladů).
     *
     * @return list<array{id:int, vendor_invoice_number:?string, varsymbol:?string, tax_date:?string,
     *                    advance_id:int, total_vat:float, tax_document_vat:float, amount:float}>
     */
    public function finalsMissingDeduction(int $supplierId, ?string $to): array
    {
        $where = '';
        $params = [$supplierId];
        if ($to !== null) {
            $where .= ' AND (f.tax_date <= ? OR (f.tax_date IS NULL AND f.issue_date <= ?))';
            $params[] = $to;
            $params[] = $to;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT f.id, f.vendor_invoice_number, f.varsymbol, COALESCE(f.tax_date, f.issue_date) AS tax_date,
                    f.advance_purchase_invoice_id AS advance_id, f.total_vat,
                    (SELECT COALESCE(SUM(td.total_vat), 0) FROM purchase_invoices td
                      WHERE td.supplier_id = a.supplier_id AND td.document_kind = 'tax_document'
                        AND td.status NOT IN ('draft', 'cancelled')
                        AND (td.parent_purchase_invoice_id = a.id OR (td.id = a.id AND td.parent_purchase_invoice_id IS NULL))
                    ) AS tax_document_vat
               FROM purchase_invoices f
               JOIN purchase_invoices a ON a.id = f.advance_purchase_invoice_id AND a.supplier_id = f.supplier_id
              WHERE f.supplier_id = ? AND f.document_kind = 'invoice' AND f.status NOT IN ('draft', 'cancelled')
                AND f.advance_purchase_invoice_id IS NOT NULL{$where}
                AND " . self::advanceHasTaxDocumentSql('a') . '
                AND NOT ' . self::finalHasDeductionSql('f') . '
              ORDER BY f.tax_date, f.id'
        );
        $stmt->execute($params);
        return array_map(static fn (array $r): array => [
            'id'                    => (int) $r['id'],
            'vendor_invoice_number' => $r['vendor_invoice_number'] !== null ? (string) $r['vendor_invoice_number'] : null,
            'varsymbol'             => $r['varsymbol'] !== null ? (string) $r['varsymbol'] : null,
            'tax_date'              => $r['tax_date'] !== null ? (string) $r['tax_date'] : null,
            'advance_id'            => (int) $r['advance_id'],
            'total_vat'             => round((float) $r['total_vat'], 2),
            'tax_document_vat'      => round((float) $r['tax_document_vat'], 2),
            'amount'                => round((float) $r['tax_document_vat'], 2),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Jednotlivý doklad podle téže podmínky jako {@see finalsMissingDeduction()}: konečná
     * faktura navázaná na zálohu s živým DDKP bez odpočtu zálohy (hrozí dvojí odpočet DPH).
     */
    public function finalMissingDeduction(int $supplierId, int $finalId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM purchase_invoices f
               JOIN purchase_invoices a ON a.id = f.advance_purchase_invoice_id AND a.supplier_id = f.supplier_id
              WHERE f.id = ? AND f.supplier_id = ? AND f.document_kind = 'invoice'
                AND " . self::advanceHasTaxDocumentSql('a') . '
                AND NOT ' . self::finalHasDeductionSql('f')
        );
        $stmt->execute([$finalId, $supplierId]);
        return $stmt->fetchColumn() !== false;
    }

    /** Nese konečná faktura odpočtový řádek zálohy, kterou vyúčtovává? */
    public function finalHasDeduction(int $supplierId, int $finalId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM purchase_invoices f WHERE f.id = ? AND f.supplier_id = ? AND ' . self::finalHasDeductionSql('f')
        );
        $stmt->execute([$finalId, $supplierId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Odpočtové řádky přijaté faktury (všechny označené, i ty, jejichž záloha nesedí
     * s hlavičkou; to posoudí volající).
     *
     * @return list<array{id:int, advance_purchase_invoice_id:int, total_without_vat:float, total_vat:float, total_with_vat:float}>
     */
    public function deductionRows(int $purchaseInvoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pii.id, pii.advance_purchase_invoice_id, pii.total_without_vat, pii.total_vat, pii.total_with_vat
               FROM purchase_invoice_items pii
              WHERE pii.purchase_invoice_id = ? AND ' . self::deductionLineSql('pii') . '
              ORDER BY pii.order_index, pii.id'
        );
        $stmt->execute([$purchaseInvoiceId]);
        return array_map(static fn (array $r): array => [
            'id'                          => (int) $r['id'],
            'advance_purchase_invoice_id' => (int) $r['advance_purchase_invoice_id'],
            'total_without_vat'           => (float) $r['total_without_vat'],
            'total_vat'                   => (float) $r['total_vat'],
            'total_with_vat'              => (float) $r['total_with_vat'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Živé DDKP zálohy (samostatný DDKP je DDKP sám sobě).
     *
     * @return list<array<string,mixed>> řádky purchase_invoices (id, vendor_invoice_number, varsymbol, currency_id, …)
     */
    public function taxDocuments(int $supplierId, int $advanceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT td.id, td.vendor_invoice_number, td.varsymbol, td.currency_id, td.exchange_rate,
                    td.total_without_vat, td.total_vat, td.total_with_vat
               FROM purchase_invoices td
              WHERE td.supplier_id = ? AND td.document_kind = 'tax_document'
                AND td.status NOT IN ('draft', 'cancelled')
                AND (td.parent_purchase_invoice_id = ? OR (td.id = ? AND td.parent_purchase_invoice_id IS NULL))
              ORDER BY td.id"
        );
        $stmt->execute([$supplierId, $advanceId, $advanceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Řádky odpočtu zálohy podle jejích živých DDKP: za každý DDKP a sazbu DPH jeden
     * záporný řádek se základem a daní DDKP (v měně dokladu). Editor je vloží do konečné
     * faktury, popis řádku skládá podle jazyka uživatele.
     *
     * @return list<array{tax_document_id:int, tax_document_number:string, vat_rate_id:int, vat_rate:float,
     *                    vat_classification_code:?string, base:float, vat:float, gross:float}>
     */
    public function linesFromTaxDocuments(int $supplierId, int $advanceId): array
    {
        $docs = $this->taxDocuments($supplierId, $advanceId);
        if ($docs === []) {
            return [];
        }
        $byId = [];
        foreach ($docs as $doc) {
            $byId[(int) $doc['id']] = $doc;
        }
        $ids = implode(',', array_keys($byId));
        $stmt = $this->db->pdo()->query(
            "SELECT pii.purchase_invoice_id, pii.vat_rate_id, MAX(pii.vat_rate_snapshot) AS vat_rate,
                    MIN(pii.vat_classification_code) AS vat_classification_code,
                    SUM(pii.total_without_vat) AS base, SUM(pii.total_vat) AS vat
               FROM purchase_invoice_items pii
              WHERE pii.purchase_invoice_id IN ({$ids})
              GROUP BY pii.purchase_invoice_id, pii.vat_rate_id
              ORDER BY pii.purchase_invoice_id, MAX(pii.vat_rate_snapshot) DESC"
        );
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $base = round((float) $r['base'], 2);
            $vat = round((float) $r['vat'], 2);
            if ((int) round(($base + $vat) * 100) === 0) {
                continue;
            }
            $doc = $byId[(int) $r['purchase_invoice_id']];
            $number = trim((string) ($doc['vendor_invoice_number'] ?? ''));
            if ($number === '') {
                $number = trim((string) ($doc['varsymbol'] ?? '')) ?: '#' . (int) $doc['id'];
            }
            $out[] = [
                'tax_document_id'         => (int) $doc['id'],
                'tax_document_number'     => $number,
                'vat_rate_id'             => (int) $r['vat_rate_id'],
                'vat_rate'                => (float) $r['vat_rate'],
                'vat_classification_code' => $r['vat_classification_code'] !== null ? (string) $r['vat_classification_code'] : null,
                'base'                    => -$base,
                'vat'                     => -$vat,
                'gross'                   => -round($base + $vat, 2),
            ];
        }
        return $out;
    }

    /**
     * Konečná faktura s odpočtovým řádkem zálohy zálohu v hlavičce (`advance_paid_amount`)
     * neodečítá: odpočet už snížil celkovou částku a `amount_to_pay` by zálohu odečetl
     * podruhé. Vynuluje ji, vrací true, když se něco změnilo.
     */
    public function clearHeaderAdvanceWhenDeducted(int $supplierId, int $finalId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE purchase_invoices f SET f.advance_paid_amount = 0
              WHERE f.id = ? AND f.supplier_id = ? AND f.advance_paid_amount <> 0 AND ' . self::finalHasDeductionSql('f')
        );
        $stmt->execute([$finalId, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    private static function assertAlias(string $alias): void
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $alias) !== 1) {
            throw new \InvalidArgumentException('Neplatný alias.');
        }
    }
}
