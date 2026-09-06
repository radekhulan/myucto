<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Expense;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ExpenseClassificationRuleRepository;
use MyInvoice\Service\ActivityLogger;
use PDO;

/**
 * Chybějící článek mezi klasifikací a účtováním.
 *
 * {@see ExpenseClassificationService} umí od začátku navrhnout druh výdaje i účet, ale
 * NIC neukládá — návrh se dosud dostal jen do UI náhledu. Účtovací cesta
 * ({@see \MyInvoice\Service\Accounting\PostingService::purchaseExpenseWeights()}) přitom
 * čte VÝHRADNĚ už uložené sloupce `purchase_invoice_items.expense_kind` /
 * `expense_account_code`; když jsou prázdné, spadne celý řádek tiše na default 518.
 *
 * Reálný důsledek: faktura za „Prémiovou naftu" od AXIGONu se zaúčtovala na 518
 * (Ostatní služby) místo na PHM, přestože pravidlo „PHM AXIGON" sedělo se 100% jistotou.
 * Řádek se přitom současně stal tankováním v knize jízd — dvě části systému o téže
 * položce rozhodly opačně.
 *
 * Tahle služba návrh ZAPÍŠE na položku, ale jen když:
 *   - je jistý ({@see ExpenseKindSuggestion::isAutoApplicable()}, tj. confidence ≥ 0.9), a
 *   - položka ještě nemá vlastní `expense_account_code` (ruční volba účetní je vždy
 *     silnější než automat — §DM „Nikdy neúčtuj automaticky, když si nejsi jistý").
 *
 * Výjimka z druhé podmínky je `force` u PHM: když se řádek prokazatelně stal tankováním,
 * je to PHM bez ohledu na to, co na něm zbylo z dřívějška (viz FuelInvoiceScanner).
 */
final class ExpenseAutoClassifier
{
    public function __construct(
        private readonly Connection $db,
        private readonly ExpenseClassificationService $suggestions,
        private readonly ActivityLogger $activity,
        private readonly ExpenseClassificationRuleRepository $rules,
    ) {}

    /**
     * Doplní jistou klasifikaci na položky faktury, které ji ještě nemají.
     *
     * @param list<int> $forceItemIds položky, které se přepíšou i když už účet mají
     *                                (prokazatelné PHM z knihy tankování)
     * @param list<string>|null $limitToAccounts když je zadané, uloží se jen návrhy mířící
     *                                na tyto účty (back-fill omezený na analytiky auta)
     * @return list<array{item_id:int, description:string, from_kind:?string, from_account:?string,
     *                    to_kind:string, to_account:?string, reason:string}> co se změnilo
     */
    public function applyToInvoice(
        int $supplierId,
        int $purchaseInvoiceId,
        array $forceItemIds = [],
        ?int $userId = null,
        ?array $limitToAccounts = null,
    ): array {
        $suggestions = $this->suggestions->suggestForInvoice($supplierId, $purchaseInvoiceId);
        if ($suggestions === []) {
            return [];
        }
        $current = $this->currentItems($supplierId, $purchaseInvoiceId);
        $force = array_flip($forceItemIds);

        $changes = [];
        foreach ($suggestions as $itemId => $s) {
            if (!isset($current[$itemId]) || empty($s['auto'])) {
                continue;
            }
            $row = $current[$itemId];
            $forced = isset($force[$itemId]);

            // Ruční volbu účetní automat nepřepisuje (leda u prokazatelného PHM).
            if (!$forced && $row['expense_account_code'] !== null) {
                continue;
            }
            $toKind = (string) $s['expense_kind'];
            $toAccount = $s['expense_account_code'] !== null ? (string) $s['expense_account_code'] : null;
            if ($limitToAccounts !== null && !in_array($toAccount, $limitToAccounts, true)) {
                continue;
            }
            if ($row['expense_kind'] === $toKind && $row['expense_account_code'] === $toAccount) {
                continue;
            }

            // Provenience se ukládá spolu s výsledkem, ne vedle něj: jinak by na položce
            // zůstalo „účet 501" bez jakékoli stopy, podle čeho se tam dostal.
            $ruleId = isset($s['expense_rule_id']) ? (int) $s['expense_rule_id'] : null;
            $source = isset($s['source']) ? (string) $s['source'] : null;
            $this->updateItem($itemId, $toKind, $toAccount, $ruleId ?: null, $source);
            // Pravidlo se „trefilo" právě teď — návrh se sám aplikoval na doklad, což je
            // silnější přijetí než klik v náhledu. Bez tohohle volání zůstávaly hit_count
            // a last_hit_at navždy prázdné, přestože je UI vypisuje a activeFor() podle
            // hit_count řadí (týž vzor jako BankPostingService u bankovních pravidel).
            if ($ruleId > 0 && $source === 'rule') {
                $this->rules->recordHit($supplierId, $ruleId);
            }
            $changes[] = [
                'item_id'      => $itemId,
                'description'  => $row['description'],
                'from_kind'    => $row['expense_kind'],
                'from_account' => $row['expense_account_code'],
                'to_kind'      => $toKind,
                'to_account'   => $toAccount,
                'reason'       => (string) ($s['reason'] ?? ''),
                'source'       => $source,
                'rule_id'      => $ruleId ?: null,
            ];
        }

        if ($changes !== []) {
            $this->activity->log(
                'accounting.expense_auto_classified',
                $userId,
                'purchase_invoice',
                $purchaseInvoiceId,
                ['changes' => $changes],
                null,
                null,
                $supplierId,
            );
        }
        return $changes;
    }

    /**
     * Položky faktury, které {@see FuelKeywords} vyhodnotí jako palivo — vstup pro
     * `forceItemIds`. Sdílený zdroj pravdy s knihou tankování.
     *
     * @return list<int>
     */
    public function fuelItemIds(int $supplierId, int $purchaseInvoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pii.id, pii.description
               FROM purchase_invoice_items pii
               JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
              WHERE pii.purchase_invoice_id = ? AND pi.supplier_id = ?'
        );
        $stmt->execute([$purchaseInvoiceId, $supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if (\MyInvoice\Service\Logbook\Fuel\FuelKeywords::isFuelForAccounting((string) $row['description'])) {
                $out[] = (int) $row['id'];
            }
        }
        return $out;
    }

    /** @return array<int, array{description:string, expense_kind:?string, expense_account_code:?string}> */
    private function currentItems(int $supplierId, int $purchaseInvoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pii.id, pii.description, pii.expense_kind, pii.expense_account_code
               FROM purchase_invoice_items pii
               JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
              WHERE pii.purchase_invoice_id = ? AND pi.supplier_id = ?'
        );
        $stmt->execute([$purchaseInvoiceId, $supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int) $row['id']] = [
                'description'          => (string) $row['description'],
                'expense_kind'         => $row['expense_kind'] !== null ? (string) $row['expense_kind'] : null,
                'expense_account_code' => $row['expense_account_code'] !== null
                    ? (string) $row['expense_account_code'] : null,
            ];
        }
        return $out;
    }

    private function updateItem(
        int $itemId,
        string $kind,
        ?string $accountCode,
        ?int $ruleId,
        ?string $source,
    ): void {
        // is_fixed_asset drží invariant s expense_kind (viz PurchaseInvoiceRepository).
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoice_items
                SET expense_kind = ?, expense_account_code = ?, is_fixed_asset = ?,
                    expense_rule_id = ?, expense_classification_source = ?
              WHERE id = ?'
        )->execute([
            $kind,
            $accountCode,
            $kind === 'fixed_asset' ? 1 : 0,
            $ruleId,
            in_array($source, ['rule', 'catalog', 'keyword', 'threshold', 'ai'], true) ? $source : null,
            $itemId,
        ]);
    }
}
