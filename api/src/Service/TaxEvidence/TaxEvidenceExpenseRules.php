<?php

declare(strict_types=1);

namespace MyInvoice\Service\TaxEvidence;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ExpenseClassificationRuleRepository;
use MyInvoice\Service\Accounting\Expense\ExpenseAutoClassifier;
use MyInvoice\Service\ActivityLogger;
use PDO;

/**
 * Pravidla klasifikace výdajů v daňové evidenci (§ 7b ZDP).
 *
 * V podvojném účetnictví pravidla doplňují druh výdaje před zaúčtováním
 * ({@see \MyInvoice\Service\Accounting\DocumentAutoPoster::maybeAutoPost()}). Daňová evidence
 * nic neúčtuje, proto se tytéž pravidla uplatní při přijetí dokladu:
 *   - druh výdaje na položkách zapíše {@see ExpenseAutoClassifier} (stejná jistota i ochrana
 *     ruční volby jako v účetnictví) — z druhu žije evidence drobného majetku;
 *   - daňovou uznatelnost dokladu (`purchase_invoices.tax_deductible`, podle ní peněžní deník
 *     řadí úhradu mezi daňové nebo nedaňové výdaje) nastaví jen tehdy, když VŠECHNY položky
 *     dokladu zachytilo automatické pravidlo s vyplněnou uznatelností a pravidla se shodují.
 *     Doklad, kde se pravidla rozcházejí nebo část položek nezachytila, se nemění: uznatelnost
 *     je v daňové evidenci vlastností celého dokladu a rozdělit ji smí jen uživatel.
 */
final class TaxEvidenceExpenseRules
{
    public function __construct(
        private readonly Connection $db,
        private readonly ExpenseAutoClassifier $classifier,
        private readonly ExpenseClassificationRuleRepository $rules,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @return array{applied:bool, expense_kinds:int, tax_deductible:?bool}
     */
    public function applyOnReceive(int $supplierId, int $purchaseInvoiceId, ?int $userId = null): array
    {
        $out = ['applied' => false, 'expense_kinds' => 0, 'tax_deductible' => null];
        if (!$this->isTaxEvidence($supplierId)) {
            return $out;
        }
        $out['applied'] = true;
        $out['expense_kinds'] = count($this->classifier->applyToInvoice($supplierId, $purchaseInvoiceId, [], $userId));

        $decision = $this->deductibilityFromRules($supplierId, $purchaseInvoiceId);
        if ($decision === null) {
            return $out;
        }
        $stmt = $this->db->pdo()->prepare(
            "UPDATE purchase_invoices SET tax_deductible = ?
              WHERE id = ? AND supplier_id = ? AND tax_deductible <> ? AND status <> 'cancelled'"
        );
        $stmt->execute([$decision ? 1 : 0, $purchaseInvoiceId, $supplierId, $decision ? 1 : 0]);
        if ($stmt->rowCount() > 0) {
            $out['tax_deductible'] = $decision;
            $this->activity->log('tax_evidence.expense_rule_deductibility', $userId, 'purchase_invoice',
                $purchaseInvoiceId, ['tax_deductible' => $decision], null, null, $supplierId);
        }

        return $out;
    }

    /** Shodná uznatelnost ze všech položek dokladu, nebo null, když ji pravidla neurčují. */
    private function deductibilityFromRules(int $supplierId, int $purchaseInvoiceId): ?bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pii.expense_rule_id, pii.expense_classification_source
               FROM purchase_invoice_items pii
               JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
              WHERE pii.purchase_invoice_id = ? AND pi.supplier_id = ?'
        );
        $stmt->execute([$purchaseInvoiceId, $supplierId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($items === []) {
            return null;
        }
        $values = [];
        foreach ($items as $item) {
            if ($item['expense_classification_source'] !== 'rule' || $item['expense_rule_id'] === null) {
                return null;
            }
            $rule = $this->rules->find($supplierId, (int) $item['expense_rule_id']);
            if ($rule === null || $rule['tax_deductible'] === null || $rule['application_mode'] !== 'auto') {
                return null;
            }
            $values[(int) $rule['tax_deductible']] = true;
        }

        return count($values) === 1 ? (bool) array_key_first($values) : null;
    }

    private function isTaxEvidence(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);

        return $stmt->fetchColumn() === 'tax_evidence';
    }
}
