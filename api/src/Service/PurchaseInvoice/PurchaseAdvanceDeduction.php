<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Activation\OpeningBalanceDocuments;
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
 * Vydaná strana má obdobu v {@see \MyInvoice\Support\Sql\AdvanceDrawSql}. Přijatá faktura
 * může vyúčtovat víc záloh ({@see PurchaseAdvanceLinks}): jednu hlavičkou, víc řádky
 * čerpání se zdrojem `document`. Řádek smí odečítat jen zálohu, kterou faktura takto
 * vyúčtovává; řádek cizí zálohy zaúčtování odmítne.
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

    /**
     * Nese konečná faktura `$final` odpočtový řádek zálohy z hlavičky? Jen pro vazbu jedinou
     * zálohou (uhrazená záloha v hlavičce, {@see LinkedAdvanceAmount}); faktura s víc zálohami
     * hlavičku nemá a její odpočty přepočítává {@see PurchaseAdvanceLinks::sync()}.
     */
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
     * Záloha z počátečních stavů (alias zálohy): zálohová faktura převzatá z předchozího
     * programu jako doklad zastoupený v počátečních stavech
     * ({@see OpeningBalanceDocuments}), bez živého DDKP v systému. DDKP i úhrada proběhly
     * v předchozím programu, DPH z ní je uplatněná a na 314 leží jen základ. Konečná
     * faktura ji proto musí odečíst řádkem s DPH stejně jako zálohu s DDKP.
     */
    public static function advanceFromOpeningSql(string $advance): string
    {
        self::assertAlias($advance);
        return "({$advance}.document_kind = 'advance' AND NOT " . self::advanceHasTaxDocumentSql($advance) . '
                 AND ' . OpeningBalanceDocuments::inOpeningSql('purchase_invoice', $advance) . ')';
    }

    /** Zálohu `$advance` musí konečná faktura odečíst odpočtovým řádkem (DDKP nebo počáteční stavy). */
    public static function advanceRequiresDeductionLineSql(string $advance): string
    {
        return '(' . self::advanceHasTaxDocumentSql($advance) . ' OR ' . self::advanceFromOpeningSql($advance) . ')';
    }

    /** Je záloha zálohou z počátečních stavů ({@see advanceFromOpeningSql()})? */
    public function isOpeningAdvance(int $supplierId, int $advanceId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM purchase_invoices a WHERE a.id = ? AND a.supplier_id = ? AND ' . self::advanceFromOpeningSql('a')
        );
        $stmt->execute([$advanceId, $supplierId]);
        return $stmt->fetchColumn() !== false;
    }

    /** Odečítá se záloha odpočtovým řádkem (má živý DDKP, nebo je z počátečních stavů)? */
    public function requiresDeductionLine(int $supplierId, int $advanceId): bool
    {
        return $this->taxDocuments($supplierId, $advanceId) !== [] || $this->isOpeningAdvance($supplierId, $advanceId);
    }

    /**
     * Dvojice konečná faktura × záloha s živým DDKP nebo z počátečních stavů
     * ({@see advanceFromOpeningSql()}), kde faktura odpočet té zálohy nenese: daň zálohy
     * je uplatněná a konečná faktura ji v plné výši uplatní podruhé. Faktura
     * s víc zálohami ({@see PurchaseAdvanceLinks}) se posuzuje po zálohách, takže chybějící
     * odpočet jediné z nich se ukáže i vedle odpočtů ostatních.
     * Bere se všechno s DUZP (bez DUZP datem vystavení) do `$to` včetně starších období,
     * aby šly dohledat i dřívější doklady. Vazbu na zálohu nese jen menšina dokladů, dotaz
     * proto vychází z vazeb firmy (`idx_pi_supplier_advance`, `ix_pad_advance`), ne ze
     * všech dokladů firmy.
     *
     * `amount` = daň DDKP, kterou by faktura uplatnila podruhé (v měně dokladů).
     *
     * @return list<array{id:int, vendor_invoice_number:?string, varsymbol:?string, tax_date:?string,
     *                    advance_id:int, total_vat:float, tax_document_vat:float, amount:float}>
     */
    public function finalsMissingDeduction(int $supplierId, ?string $to): array
    {
        $where = '';
        $params = [$supplierId, $supplierId, $supplierId];
        if ($to !== null) {
            $where .= ' AND (f.tax_date <= ? OR (f.tax_date IS NULL AND f.issue_date <= ?))';
            $params[] = $to;
            $params[] = $to;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT f.id, f.vendor_invoice_number, f.varsymbol, COALESCE(f.tax_date, f.issue_date) AS tax_date,
                    a.id AS advance_id, f.total_vat,
                    (SELECT COALESCE(SUM(td.total_vat), 0) FROM purchase_invoices td
                      WHERE td.supplier_id = a.supplier_id AND td.document_kind = 'tax_document'
                        AND td.status NOT IN ('draft', 'cancelled')
                        AND (td.parent_purchase_invoice_id = a.id OR (td.id = a.id AND td.parent_purchase_invoice_id IS NULL))
                    ) AS tax_document_vat
               FROM (" . PurchaseAdvanceLinks::linksSql('?') . ") l
               JOIN purchase_invoices f ON f.id = l.final_id
               JOIN purchase_invoices a ON a.id = l.advance_id AND a.supplier_id = f.supplier_id
              WHERE f.supplier_id = ? AND f.document_kind = 'invoice' AND f.status NOT IN ('draft', 'cancelled'){$where}
                AND " . self::advanceRequiresDeductionLineSql('a') . '
                AND NOT ' . self::finalDeductsAdvanceSql('f', 'a.id') . '
              ORDER BY f.tax_date, f.id, a.id'
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $taxDocumentVat = round((float) $r['tax_document_vat'], 2);
            // Záloha z počátečních stavů DDKP v systému nemá: daň, kterou faktura uplatní
            // podruhé, je daň navrženého odpočtového řádku.
            $opening = $this->isOpeningAdvance($supplierId, (int) $r['advance_id']);
            $amount = $opening ? round(-(float) ($this->openingAdvanceLine($supplierId, (int) $r['advance_id'])['vat'] ?? 0.0), 2) : $taxDocumentVat;
            $out[] = [
                'id'                    => (int) $r['id'],
                'vendor_invoice_number' => $r['vendor_invoice_number'] !== null ? (string) $r['vendor_invoice_number'] : null,
                'varsymbol'             => $r['varsymbol'] !== null ? (string) $r['varsymbol'] : null,
                'tax_date'              => $r['tax_date'] !== null ? (string) $r['tax_date'] : null,
                'advance_id'            => (int) $r['advance_id'],
                'total_vat'             => round((float) $r['total_vat'], 2),
                'tax_document_vat'      => $taxDocumentVat,
                'amount'                => $amount,
                'opening_balance'       => $opening,
            ];
        }
        return $out;
    }

    /**
     * Jednotlivý doklad podle téže podmínky jako {@see finalsMissingDeduction()}: konečná
     * faktura navázaná na zálohu s živým DDKP bez odpočtu zálohy (hrozí dvojí odpočet DPH).
     */
    public function finalMissingDeduction(int $supplierId, int $finalId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM (" . PurchaseAdvanceLinks::linksSql('?') . ") l
               JOIN purchase_invoices f ON f.id = l.final_id
               JOIN purchase_invoices a ON a.id = l.advance_id AND a.supplier_id = f.supplier_id
              WHERE l.final_id = ? AND f.supplier_id = ? AND f.document_kind = 'invoice'
                AND " . self::advanceRequiresDeductionLineSql('a') . '
                AND NOT ' . self::finalDeductsAdvanceSql('f', 'a.id') . '
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $supplierId, $finalId, $supplierId]);
        return $stmt->fetchColumn() !== false;
    }

    /** Nese konečná faktura `$final` (alias) odpočtový řádek zálohy `$advanceExpr`? */
    public static function finalDeductsAdvanceSql(string $final, string $advanceExpr): string
    {
        self::assertAlias($final);
        return "EXISTS (SELECT 1 FROM purchase_invoice_items adv_pii
                         WHERE adv_pii.purchase_invoice_id = {$final}.id
                           AND adv_pii.advance_purchase_invoice_id = {$advanceExpr}
                           AND adv_pii.total_with_vat < 0)";
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
     * @return list<array{id:int, advance_purchase_invoice_id:int, total_without_vat:float, total_vat:float, total_with_vat:float, vat_rate_snapshot:float}>
     */
    public function deductionRows(int $purchaseInvoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pii.id, pii.advance_purchase_invoice_id, pii.total_without_vat, pii.total_vat, pii.total_with_vat, pii.vat_rate_snapshot
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
            'vat_rate_snapshot'           => (float) $r['vat_rate_snapshot'],
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
     * záporný řádek se základem a daní DDKP (v měně dokladu). Záloha z počátečních stavů
     * bez DDKP dostane jeden navržený řádek ({@see openingAdvanceLine()}). Editor je vloží
     * do konečné faktury, popis řádku skládá podle jazyka uživatele.
     *
     * @return list<array{tax_document_id:?int, tax_document_number:?string, vat_rate_id:int, vat_rate:float,
     *                    vat_classification_code:?string, base:float, vat:float, gross:float, opening_balance:bool}>
     */
    public function linesFromTaxDocuments(int $supplierId, int $advanceId): array
    {
        $docs = $this->taxDocuments($supplierId, $advanceId);
        if ($docs === []) {
            $opening = $this->isOpeningAdvance($supplierId, $advanceId) ? $this->openingAdvanceLine($supplierId, $advanceId) : null;
            return $opening !== null ? [$opening] : [];
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
                'opening_balance'         => false,
            ];
        }
        return $out;
    }

    /**
     * Navržený odpočtový řádek zálohy z počátečních stavů ({@see advanceFromOpeningSql()}):
     * hrubá částka = částka zálohy, sazba z řádků zálohy (nese-li nějakou), jinak základní
     * sazba DPH k datu zálohy. Daň se dopočte shora z hrubé částky, základ je zbytek.
     * Dodavatel ji na konečné faktuře odečítá s DPH stejně jako zálohy s DDKP.
     *
     * @return array{tax_document_id:null, tax_document_number:null, vat_rate_id:int, vat_rate:float,
     *               vat_classification_code:null, base:float, vat:float, gross:float, opening_balance:true}|null
     */
    public function openingAdvanceLine(int $supplierId, int $advanceId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.total_with_vat, COALESCE(a.tax_date, a.issue_date) AS doc_date,
                    (SELECT pii.vat_rate_id FROM purchase_invoice_items pii
                      WHERE pii.purchase_invoice_id = a.id AND pii.vat_rate_snapshot > 0
                      ORDER BY pii.vat_rate_snapshot DESC, pii.id LIMIT 1) AS item_rate_id,
                    (SELECT MAX(pii.vat_rate_snapshot) FROM purchase_invoice_items pii
                      WHERE pii.purchase_invoice_id = a.id AND pii.vat_rate_snapshot > 0) AS item_rate
               FROM purchase_invoices a
              WHERE a.id = ? AND a.supplier_id = ?"
        );
        $stmt->execute([$advanceId, $supplierId]);
        $advance = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($advance === false) {
            return null;
        }
        $rateId = $advance['item_rate_id'] !== null ? (int) $advance['item_rate_id'] : null;
        $rate = $advance['item_rate'] !== null ? (float) $advance['item_rate'] : null;
        if ($rateId === null || $rate === null) {
            // Základní sazba = nejvyšší tuzemská sazba platná k datu zálohy.
            $base = $this->db->pdo()->prepare(
                "SELECT id, rate_percent FROM vat_rates
                  WHERE country = 'CZ' AND is_reverse_charge = 0 AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)
                  ORDER BY rate_percent DESC, id LIMIT 1"
            );
            $base->execute([$advance['doc_date'], $advance['doc_date']]);
            $row = $base->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return null;
            }
            $rateId = (int) $row['id'];
            $rate = (float) $row['rate_percent'];
        }
        $gross = round((float) $advance['total_with_vat'], 2);
        $vat = round($gross * $rate / (100 + $rate), 2);
        return [
            'tax_document_id'         => null,
            'tax_document_number'     => null,
            'vat_rate_id'             => $rateId,
            'vat_rate'                => $rate,
            'vat_classification_code' => null,
            'base'                    => -round($gross - $vat, 2),
            'vat'                     => -$vat,
            'gross'                   => -$gross,
            'opening_balance'         => true,
        ];
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
