<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AdvanceDeductionRepository;
use MyInvoice\Service\Accounting\DocumentPostingGroup;
use PDO;

/**
 * Jediný zdroj pravdy o tom, které poskytnuté zálohy přijatá konečná faktura vyúčtovává.
 *
 * Dvě podoby vazby, nikdy obě na jednom dokladu:
 *   - jediná záloha: hlavička `purchase_invoices.advance_purchase_invoice_id` (1:1, UNIQUE
 *     `uq_pi_advance_link`). Tak vznikly všechny dosavadní doklady a čtou ji beze změny
 *     zaúčtování, saldokonto, banka i přehledy,
 *   - víc záloh (vyúčtování energií: jedna faktura, dvanáct záloh): hlavička je prázdná
 *     a každou zálohu nese řádek `purchase_advance_deductions` se zdrojem `document`.
 *
 * Proč řádky čerpání po dvojicích a ne vlastní tabulka: saldokonto 314 čerpání po
 * dvojicích už umí ({@see \MyInvoice\Repository\SaldoRepository}, AdvanceDeductionRepository)
 * a vyúčtovanou zálohu z něj poznají i nabídky k párování, pohledávky a náklady
 * ({@see \MyInvoice\Support\Sql\AdvanceSettledSql}). Vazba hlavičkou by s víc zálohami
 * nefungovala: saldokonto jí přiřadí celou stranu čerpání faktury. Je to stejný model
 * jako na vydané straně, kde faktura čerpající víc proforem nemá `parent_invoice_id`
 * a čerpání nesou řádky ({@see \MyInvoice\Support\Sql\AdvanceDrawSql}).
 *
 * `amount` řádku = Kč, o které faktura sníží zálohu na 314, stejně jako u čerpání
 * z převodu a ze saldokonta, a stejné číslo, které zaúčtuje
 * {@see \MyInvoice\Service\Accounting\PostingService} párem 321 MD / 314 D:
 *   - záloha s daňovým dokladem k platbě (DDKP): základ jejích odpočtových řádků
 *     ({@see PurchaseAdvanceDeduction}) v Kč po řádcích,
 *   - záloha bez DDKP: uhrazená částka ({@see LinkedAdvanceAmount}), kterou faktura odečte
 *     v hlavičce (`advance_paid_amount` = součet těchto záloh). Na rozdíl od jediné zálohy
 *     bez stropu výší faktury: vyúčtování energií končí přeplatkem běžně a ten zůstane
 *     jako záporná částka k úhradě a pohledávka na 321, stejně jako u zálohy s DDKP.
 * Přepočítá ho {@see sync()} po uložení řádků, propojení a změně úhrady zálohy.
 *
 * Záloha se vyúčtuje jedinou konečnou fakturou (hlavičkou i řádkem document). Částečné
 * čerpání jedné zálohy víc fakturami dál řeší saldokonto (Vyrovnat).
 */
final class PurchaseAdvanceLinks
{
    public const SOURCE = 'document';
    private const TOLERANCE = 0.005;

    public function __construct(private readonly Connection $db) {}

    /**
     * Všechny vazby konečná faktura × záloha firmy jako odvozená tabulka (`final_id`,
     * `advance_id`). `$supplierExpr` je SQL výraz (celé číslo, `?` nebo sloupec), vyskytne se dvakrát.
     */
    public static function linksSql(string $supplierExpr): string
    {
        return "SELECT lf.id AS final_id, lf.advance_purchase_invoice_id AS advance_id
                  FROM purchase_invoices lf
                 WHERE lf.supplier_id = {$supplierExpr} AND lf.advance_purchase_invoice_id IS NOT NULL
                UNION ALL
                SELECT lp.final_invoice_id, lp.advance_invoice_id
                  FROM purchase_advance_deductions lp
                 WHERE lp.supplier_id = {$supplierExpr} AND lp.source = '" . self::SOURCE . "'";
    }

    /**
     * Konečné faktury zálohy `$advanceExpr` (odvozená tabulka se sloupcem `id`), všechny stavy.
     * Výrazy jdou v pořadí firma, záloha, firma, záloha.
     */
    public static function finalIdsSql(string $supplierExpr, string $advanceExpr): string
    {
        return "SELECT f.id FROM purchase_invoices f
                 WHERE f.supplier_id = {$supplierExpr} AND f.advance_purchase_invoice_id = {$advanceExpr}
                UNION
                SELECT lp.final_invoice_id FROM purchase_advance_deductions lp
                 WHERE lp.supplier_id = {$supplierExpr} AND lp.advance_invoice_id = {$advanceExpr}
                   AND lp.source = '" . self::SOURCE . "'";
    }

    /** Vyúčtovává konečná faktura `$final` (alias) zálohu `$advanceExpr` hlavičkou nebo řádkem document? */
    public static function finalLinksAdvanceSql(string $final, string $advanceExpr): string
    {
        self::assertAlias($final);
        return "({$final}.advance_purchase_invoice_id = {$advanceExpr}
                 OR EXISTS (SELECT 1 FROM purchase_advance_deductions lnk
                             WHERE lnk.supplier_id = {$final}.supplier_id AND lnk.final_invoice_id = {$final}.id
                               AND lnk.advance_invoice_id = {$advanceExpr} AND lnk.source = '" . self::SOURCE . "'))";
    }

    /** Vyúčtovává konečná faktura `$final` (alias) nějakou zálohu (hlavičkou nebo řádkem document)? */
    public static function finalHasAdvanceSql(string $final): string
    {
        self::assertAlias($final);
        return "({$final}.advance_purchase_invoice_id IS NOT NULL
                 OR EXISTS (SELECT 1 FROM purchase_advance_deductions lnk
                             WHERE lnk.supplier_id = {$final}.supplier_id AND lnk.final_invoice_id = {$final}.id
                               AND lnk.source = '" . self::SOURCE . "'))";
    }

    /**
     * Zálohy konečné faktury: hlavička, nebo řádky document v pořadí propojení.
     *
     * @return list<int>
     */
    public function advanceIds(int $supplierId, int $finalId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT advance_purchase_invoice_id FROM purchase_invoices WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$finalId, $supplierId]);
        $header = $stmt->fetchColumn();
        if ($header !== false && $header !== null) {
            return [(int) $header];
        }
        return $this->documentAdvanceIds($supplierId, $finalId);
    }

    /** Vyúčtovává faktura víc záloh (řádky document)? */
    public function isMulti(int $supplierId, int $finalId): bool
    {
        return $this->documentAdvanceIds($supplierId, $finalId) !== [];
    }

    /**
     * Konečné faktury zálohy (hlavičkou i řádkem document), všechny stavy.
     *
     * @return list<int>
     */
    public function finalIds(int $supplierId, int $advanceId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM (' . self::finalIdsSql('?', '?') . ') x ORDER BY id');
        $stmt->execute([$supplierId, $advanceId, $supplierId, $advanceId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * Přidá konečné faktuře další zálohu. Faktura s jedinou zálohou v hlavičce se převede
     * na řádky document (hlavička se vyprázdní). Volající ověřil zámek dokladu.
     *
     * @throws PurchaseAdvanceLinkException 4xx s hláškou pro uživatele
     */
    public function append(int $supplierId, int $finalId, int $advanceId, ?int $userId = null): void
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            (new AdvanceDeductionRepository($this->db))->lockDocuments($supplierId, 'purchase_invoice', [$finalId, $advanceId]);
            $final = $this->document($supplierId, $finalId)
                ?? throw new PurchaseAdvanceLinkException('not_found', 'Přijatá faktura nenalezena.', 404);
            $current = $this->advanceIds($supplierId, $finalId);
            if (in_array($advanceId, $current, true)) {
                throw new PurchaseAdvanceLinkException('already_linked', 'Faktura tuto zálohu už vyúčtovává.', 409);
            }
            $this->assertFinal($supplierId, $final);
            $this->assertAdvance($supplierId, $final, $advanceId);

            if ($current === []) {
                // První záloha: vazba hlavičkou, stejně jako PurchaseInvoiceRepository::linkAdvance().
                $advance = $this->document($supplierId, $advanceId) ?? [];
                $amount = (new LinkedAdvanceAmount($this->db))->forFinal($supplierId, $advance, (float) $final['total_with_vat'], $finalId);
                $pdo->prepare(
                    'UPDATE purchase_invoices SET advance_purchase_invoice_id = ?, advance_link_suggested_id = NULL,
                            advance_paid_amount = CASE WHEN advance_paid_amount = 0 THEN ? ELSE advance_paid_amount END
                      WHERE id = ? AND supplier_id = ?'
                )->execute([$advanceId, round($amount, 2), $finalId, $supplierId]);
                if ($ownTx) $pdo->commit();
                return;
            }
            if (count($current) === 1 && $final['advance_purchase_invoice_id'] !== null) {
                $pdo->prepare('UPDATE purchase_invoices SET advance_purchase_invoice_id = NULL WHERE id = ? AND supplier_id = ?')
                    ->execute([$finalId, $supplierId]);
                $this->insertRow($supplierId, $finalId, $current[0], $userId);
            }
            $this->insertRow($supplierId, $finalId, $advanceId, $userId);
            $pdo->prepare('UPDATE purchase_invoices SET advance_link_suggested_id = NULL WHERE id = ? AND supplier_id = ?')
                ->execute([$finalId, $supplierId]);
            $this->sync($supplierId, $finalId);
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Odpojí zálohu (null = všechny) od faktury s víc zálohami. Zbude-li jediná, vrátí se
     * do hlavičky, takže faktura s jednou zálohou má vazbu vždy jen hlavičkou.
     * Vrací false, když faktura víc záloh nevyúčtovává (odpojí ji volající hlavičkou).
     *
     * @throws PurchaseAdvanceLinkException
     */
    public function unlinkDocument(int $supplierId, int $finalId, ?int $advanceId): bool
    {
        $current = $this->documentAdvanceIds($supplierId, $finalId);
        if ($current === []) {
            return false;
        }
        if ($advanceId !== null && !in_array($advanceId, $current, true)) {
            throw new PurchaseAdvanceLinkException('not_linked', 'Faktura tuto zálohu nevyúčtovává.', 409);
        }
        if ((new DocumentPostingGroup($this->db))->hasActive($supplierId, 'purchase_invoice', $finalId)) {
            throw new PurchaseAdvanceLinkException(
                'unlink_refused',
                'Zaúčtovanou fakturu nelze odpojit od zálohy — zúčtování záloh je součástí účetního zápisu. '
                    . 'Nejdřív zápis stornujte, teprve pak vazbu zrušte.',
                409,
            );
        }
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $remove = $advanceId === null ? $current : [$advanceId];
            $delete = $pdo->prepare(
                'DELETE FROM purchase_advance_deductions
                  WHERE supplier_id = ? AND final_invoice_id = ? AND advance_invoice_id = ? AND source = ?'
            );
            foreach ($remove as $id) {
                $delete->execute([$supplierId, $finalId, $id, self::SOURCE]);
            }
            $left = array_values(array_diff($current, $remove));
            if (count($left) === 1) {
                $delete->execute([$supplierId, $finalId, $left[0], self::SOURCE]);
                $final = $this->document($supplierId, $finalId);
                $advance = $this->document($supplierId, $left[0]);
                $amount = $final !== null && $advance !== null
                    ? (new LinkedAdvanceAmount($this->db))->forFinal($supplierId, $advance, (float) $final['total_with_vat'], $finalId)
                    : 0.0;
                $pdo->prepare('UPDATE purchase_invoices SET advance_purchase_invoice_id = ?, advance_paid_amount = ? WHERE id = ? AND supplier_id = ?')
                    ->execute([$left[0], round($amount, 2), $finalId, $supplierId]);
            } elseif ($left === []) {
                $pdo->prepare('UPDATE purchase_invoices SET advance_paid_amount = 0 WHERE id = ? AND supplier_id = ?')
                    ->execute([$finalId, $supplierId]);
            } else {
                $this->sync($supplierId, $finalId);
            }
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return true;
    }

    /**
     * Přepočítá faktuře s víc zálohami částky řádků document a uhrazenou zálohu v hlavičce
     * (součet záloh bez DDKP). Faktuře s jedinou zálohou nic nemění (vazba hlavičkou má
     * vlastní pravidla v {@see LinkedAdvanceAmount}). Běží v transakci volajícího.
     *
     * @throws PurchaseAdvanceLinkException odpočet zálohy nad její částku
     */
    public function sync(int $supplierId, int $finalId): void
    {
        $advanceIds = $this->documentAdvanceIds($supplierId, $finalId);
        if ($advanceIds === []) {
            return;
        }
        $final = $this->document($supplierId, $finalId);
        if ($final === null) {
            return;
        }
        $rate = $final['currency_code'] === 'CZK' || (float) $final['exchange_rate'] <= 0.0 ? 1.0 : (float) $final['exchange_rate'];
        $deduction = new PurchaseAdvanceDeduction($this->db);
        $linesByAdvance = [];
        foreach ($deduction->deductionRows($finalId) as $row) {
            $linesByAdvance[$row['advance_purchase_invoice_id']][] = $row;
        }
        $amounts = new LinkedAdvanceAmount($this->db);
        $headerPaid = 0.0;
        $update = $this->db->pdo()->prepare(
            'UPDATE purchase_advance_deductions SET amount = ?
              WHERE supplier_id = ? AND final_invoice_id = ? AND advance_invoice_id = ? AND source = ?'
        );
        foreach ($advanceIds as $advanceId) {
            $advance = $this->document($supplierId, $advanceId);
            if ($advance === null) {
                continue;
            }
            $lines = $linesByAdvance[$advanceId] ?? [];
            if ($deduction->taxDocuments($supplierId, $advanceId) !== []) {
                $base = 0.0;
                $gross = 0.0;
                foreach ($lines as $line) {
                    $base += round(-$line['total_without_vat'] * $rate, 2);
                    $gross += -$line['total_with_vat'];
                }
                if ($gross - (float) $advance['total_with_vat'] > self::TOLERANCE) {
                    throw new PurchaseAdvanceLinkException(
                        'advance_overdeducted',
                        sprintf(
                            'Odpočet zálohy %s (%s) převyšuje její částku %s. Opravte odpočtové řádky.',
                            $advance['doc_no'],
                            number_format($gross, 2, ',', ' '),
                            number_format((float) $advance['total_with_vat'], 2, ',', ' '),
                        ),
                        422,
                    );
                }
                $amount = round($base, 2);
            } else {
                // Bez stropu výší faktury, stejně jako zúčtování (PostingService): přeplatek
                // záloh zůstane jako záporná částka k úhradě.
                $paid = $lines === [] ? $amounts->forFinal($supplierId, $advance, PHP_FLOAT_MAX) : 0.0;
                $paid = round(max(0.0, $paid), 2);
                $headerPaid += $paid;
                $amount = round($paid * $rate, 2);
            }
            $update->execute([max(0.0, $amount), $supplierId, $finalId, $advanceId, self::SOURCE]);
        }
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET advance_paid_amount = ? WHERE id = ? AND supplier_id = ?')
            ->execute([round($headerPaid, 2), $finalId, $supplierId]);
    }

    /**
     * Přepočítá faktury s víc zálohami, které zálohu vyúčtovávají (po změně její úhrady).
     * Úhrada mění jen částky záloh bez DDKP; přečerpání odpočtovými řádky, které by sync
     * odmítl, s úhradou nesouvisí a úhradu nezastaví (ukáže ho uložení a zaúčtování faktury).
     *
     * @return list<int> přepočítané faktury
     */
    public function syncFinalsOfAdvance(int $supplierId, int $advanceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT final_invoice_id FROM purchase_advance_deductions
              WHERE supplier_id = ? AND advance_invoice_id = ? AND source = ? ORDER BY final_invoice_id'
        );
        $stmt->execute([$supplierId, $advanceId, self::SOURCE]);
        $synced = [];
        foreach (array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $finalId) {
            try {
                $this->sync($supplierId, $finalId);
                $synced[] = $finalId;
            } catch (PurchaseAdvanceLinkException) {
                continue;
            }
        }
        return $synced;
    }

    /** @return list<int> */
    private function documentAdvanceIds(int $supplierId, int $finalId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT advance_invoice_id FROM purchase_advance_deductions
              WHERE supplier_id = ? AND final_invoice_id = ? AND source = ? ORDER BY id'
        );
        $stmt->execute([$supplierId, $finalId, self::SOURCE]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** @param array<string,mixed> $final */
    private function assertFinal(int $supplierId, array $final): void
    {
        if ((string) $final['document_kind'] !== 'invoice') {
            throw new PurchaseAdvanceLinkException('invalid_final', 'Víc záloh vyúčtovává jen konečná faktura.', 422);
        }
        if ((string) $final['status'] === 'cancelled') {
            throw new PurchaseAdvanceLinkException('invalid_final', 'Stornovaná faktura zálohy nevyúčtovává.', 409);
        }
        if ((new DocumentPostingGroup($this->db))->hasActive($supplierId, 'purchase_invoice', (int) $final['id'])) {
            throw new PurchaseAdvanceLinkException(
                'final_posted',
                'Faktura je zaúčtovaná — zúčtování záloh je součástí účetního zápisu. Nejdřív zápis stornujte, pak zálohu přidejte.',
                409,
            );
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM purchase_advance_deductions WHERE supplier_id = ? AND final_invoice_id = ? AND source <> ? LIMIT 1'
        );
        $stmt->execute([$supplierId, (int) $final['id'], self::SOURCE]);
        if ($stmt->fetchColumn() !== false) {
            throw new PurchaseAdvanceLinkException(
                'final_has_saldo_deductions',
                'Faktura už čerpá zálohu v saldokontu. Další zálohu jí přiřaďte tam (Saldokonto, Vyrovnat).',
                409,
            );
        }
    }

    /** @param array<string,mixed> $final */
    private function assertAdvance(int $supplierId, array $final, int $advanceId): void
    {
        if ($advanceId === (int) $final['id']) {
            throw new PurchaseAdvanceLinkException('invalid_advance', 'Nelze propojit doklad sám se sebou.', 422);
        }
        $advance = $this->document($supplierId, $advanceId)
            ?? throw new PurchaseAdvanceLinkException('not_found', 'Záloha nenalezena.', 404);
        $kind = (string) $advance['document_kind'];
        $standaloneDdkp = $kind === 'tax_document' && $advance['parent_purchase_invoice_id'] === null;
        if ($kind !== 'advance' && !$standaloneDdkp) {
            throw new PurchaseAdvanceLinkException('invalid_advance', $kind === 'tax_document'
                ? 'Tenhle daňový doklad k platbě patří k zálohové faktuře — propojte fakturu s ní.'
                : 'Propojit lze jen se zálohovou fakturou nebo se samostatným daňovým dokladem k platbě.', 422);
        }
        if ((int) $advance['vendor_id'] !== (int) $final['vendor_id']) {
            throw new PurchaseAdvanceLinkException('vendor_mismatch', 'Záloha ' . $advance['doc_no'] . ' je od jiného dodavatele než faktura.', 422);
        }
        if ((int) $advance['currency_id'] !== (int) $final['currency_id']) {
            throw new PurchaseAdvanceLinkException('currency_mismatch', 'Záloha ' . $advance['doc_no'] . ' je v jiné měně než faktura.', 422);
        }
        if ((string) $advance['status'] === 'cancelled') {
            throw new PurchaseAdvanceLinkException('advance_cancelled', 'Záloha ' . $advance['doc_no'] . ' je stornovaná.', 409);
        }
        if ((string) $advance['status'] === 'draft') {
            throw new PurchaseAdvanceLinkException('advance_draft', 'Záloha ' . $advance['doc_no'] . ' je koncept. Nejdřív ji přijměte.', 409);
        }
        $others = array_values(array_diff($this->finalIds($supplierId, $advanceId), [(int) $final['id']]));
        if ($others !== []) {
            throw new PurchaseAdvanceLinkException('advance_settled', 'Záloha ' . $advance['doc_no'] . ' je už vyúčtovaná jinou fakturou.', 409);
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM purchase_advance_deductions WHERE supplier_id = ? AND advance_invoice_id = ? AND source <> ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $advanceId, self::SOURCE]);
        if ($stmt->fetchColumn() !== false) {
            throw new PurchaseAdvanceLinkException(
                'advance_has_saldo_deductions',
                'Záloha ' . $advance['doc_no'] . ' je už čerpaná konečnou fakturou v saldokontu. Zbytek zálohy vyrovnejte tam.',
                409,
            );
        }
    }

    private function insertRow(int $supplierId, int $finalId, int $advanceId, ?int $userId): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_advance_deductions (supplier_id, final_invoice_id, advance_invoice_id, amount, source, created_by)
             VALUES (?, ?, ?, 0, ?, ?)'
        )->execute([$supplierId, $finalId, $advanceId, self::SOURCE, $userId]);
    }

    /** @return array<string,mixed>|null */
    private function document(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT pi.id, pi.document_kind, pi.status, pi.vendor_id, pi.currency_id, pi.exchange_rate,
                    pi.parent_purchase_invoice_id, pi.advance_purchase_invoice_id, pi.total_with_vat,
                    pi.paid_amount_invoice_ccy, cur.code AS currency_code,
                    COALESCE(NULLIF(pi.vendor_invoice_number, ''), NULLIF(pi.varsymbol, ''), CONCAT('#', pi.id)) AS doc_no
               FROM purchase_invoices pi
               JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.id = ? AND pi.supplier_id = ?"
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['currency_code'] = strtoupper((string) $row['currency_code']);
        $row['advance_purchase_invoice_id'] = $row['advance_purchase_invoice_id'] !== null ? (int) $row['advance_purchase_invoice_id'] : null;
        $row['parent_purchase_invoice_id'] = $row['parent_purchase_invoice_id'] !== null ? (int) $row['parent_purchase_invoice_id'] : null;
        return $row;
    }

    private static function assertAlias(string $alias): void
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $alias) !== 1) {
            throw new \InvalidArgumentException('Neplatný alias.');
        }
    }
}
