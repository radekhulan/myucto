<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai;

use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\PostingException;

final class AiPostingOverrideResolver
{
    public function __construct(
        private readonly AiSuggestionRepository $suggestions,
        private readonly AiSuggestionService $service,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly Connection $db,
    ) {}

    public function debitOverrideForPurchase(int $supplierId, int $purchaseInvoiceId): ?string
    {
        $explicit = $this->accountsForExplicitPurchase($supplierId, $purchaseInvoiceId);
        if ($explicit) {
            return null;
        }
        $accepted = $this->suggestions->acceptedForPurchase($supplierId, $purchaseInvoiceId);
        $currentHash = $this->service->purchaseInputHash($supplierId, $purchaseInvoiceId);
        if ($accepted === null || $currentHash === null || $accepted['input_hash'] === null
            || !hash_equals($accepted['input_hash'], $currentHash)) {
            return null;
        }
        $code = $accepted['debit'];
        if ($this->forbidden($code)) {
            throw new PostingException('invalid_override', 'AI návrh obsahuje nepovolený účet.', 422);
        }
        $account = $this->accounts->findByCode($supplierId, $code);
        if ($account === null || !($account['is_active'] ?? false)) {
            throw new PostingException('invalid_override', 'AI návrh odkazuje na neaktivní nebo neexistující účet.', 422);
        }
        return $code;
    }

    private function accountsForExplicitPurchase(int $supplierId, int $purchaseInvoiceId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM purchase_invoices pi
              WHERE pi.supplier_id=? AND pi.id=?
                AND (COALESCE(pi.is_fixed_asset,0)=1 OR EXISTS (
                    SELECT 1 FROM purchase_invoice_vat_allocations piva
                     WHERE piva.supplier_id=pi.supplier_id AND piva.purchase_invoice_id=pi.id
                ))'
        );
        $stmt->execute([$supplierId, $purchaseInvoiceId]);
        return $stmt->fetchColumn() !== false;
    }

    private function forbidden(string $code): bool
    {
        return \MyInvoice\Service\Accounting\SettlementAccounts::isSettlement($code);
    }
}
