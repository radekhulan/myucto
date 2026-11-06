<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Card;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\DocumentAutoPoster;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Bank\StatementMatcher;
use PDO;

/**
 * Doklad k platbě kartou dorazil nebo se změnil — dotáhni párování a vypořádání sám.
 *
 * Volá se ze všech cest, kudy přijatý doklad přichází do stavu, kdy ho lze spárovat:
 * AI import (účtenka zaplacená kartou), připojení skenu s koncovkou karty, přijetí
 * konceptu. Kroky jsou pořád tytéž a všechny idempotentní:
 *
 *   1. doklad bez předpisu se zaúčtuje, má-li firma zapnuté automatické účtování
 *      přijatých dokladů (bez předpisu 321 vypořádání nevznikne),
 *   2. doklad s koncovkou se spáruje s jediným volným pohybem téže karty
 *      ({@see StatementMatcher::matchCardDocument()}) a pohyb se zaúčtuje,
 *   3. u pohybů, které už na doklad spárované jsou, se dorovná vypořádání 321/378.x.
 *
 * Běží jen u firmy se zapnutým režimem karet. Běžná chyba automatiky se zaloguje;
 * zrušení celé transakce se propaguje, protože ruší i uložení dokladu.
 */
final class CardPaymentAutomation
{
    public function __construct(
        private readonly Connection $db,
        private readonly CardClearingRegime $regime,
        private readonly StatementMatcher $matcher,
        private readonly BankPostingService $bankPosting,
        private readonly DocumentAutoPoster $autoPoster,
        private readonly ActivityLogger $activity,
    ) {}

    /** Má firma zapnuté kartové vypořádání (378.x)? */
    public function enabledFor(int $supplierId): bool
    {
        return !empty($this->regime->settings($supplierId)['enabled']);
    }

    /** @return array{matched_transaction_id:?int, settlements:int} */
    public function afterPurchaseReady(int $supplierId, int $purchaseInvoiceId, ?int $userId = null): array
    {
        $out = ['matched_transaction_id' => null, 'settlements' => 0];
        if (empty($this->regime->settings($supplierId)['enabled'])) {
            return $out;
        }
        // Všechny kroky jsou idempotentní, takže odvolanou transakci (1020/1213) lze
        // celou zopakovat, pokud ji automatika vlastní. V cizí transakci ji dostane vlastník.
        return TransactionFailure::retrying(
            $this->db->pdo(),
            fn (): array => $this->afterPurchaseReadyOnce($supplierId, $purchaseInvoiceId, $userId),
        );
    }

    /** @return array{matched_transaction_id:?int, settlements:int} */
    private function afterPurchaseReadyOnce(int $supplierId, int $purchaseInvoiceId, ?int $userId): array
    {
        $out = ['matched_transaction_id' => null, 'settlements' => 0];
        try {
            $stmt = $this->db->pdo()->prepare(
                "SELECT pi.status, "
                . \MyInvoice\Service\Accounting\DocumentPostingGroup::activePostingExistsSql("'purchase_invoice'", 'pi.id', 'pi.supplier_id') . " AS posted
                   FROM purchase_invoices pi WHERE pi.id = ? AND pi.supplier_id = ?"
            );
            $stmt->execute([$purchaseInvoiceId, $supplierId]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($doc === false || in_array((string) $doc['status'], ['draft', 'cancelled'], true)) {
                return $out;
            }
            if (!(bool) $doc['posted']) {
                $this->autoPoster->maybeAutoPost($supplierId, 'purchase_invoice', $purchaseInvoiceId, $userId);
            }
            $txId = $this->matcher->matchCardDocument($supplierId, $purchaseInvoiceId);
            if ($txId !== null) {
                $out['matched_transaction_id'] = $txId;
                $this->bankPosting->handleTransaction($txId, $userId);
            }
            $out['settlements'] = $this->bankPosting->syncCardSettlementsForPurchase($supplierId, $purchaseInvoiceId, $userId);
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($this->db->pdo(), $e)) {
                throw $e;
            }
            try {
                $this->activity->log('card_settlement.automation_failed', $userId, 'purchase_invoice', $purchaseInvoiceId,
                    ['message' => $e->getMessage()], supplierId: $supplierId);
            } catch (\Throwable $auditFailure) {
                if (TransactionFailure::rollbackIfAborted($this->db->pdo(), $auditFailure)) {
                    throw $auditFailure;
                }
            }
        }
        return $out;
    }
}
