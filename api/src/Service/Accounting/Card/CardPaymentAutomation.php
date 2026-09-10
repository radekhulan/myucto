<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Card;

use MyInvoice\Infrastructure\Database\Connection;
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
 * Běží jen u firmy se zapnutým režimem karet. Nikdy nevyhazuje — doklad se uložil,
 * automatika je nadstavba a její chyba se jen zaloguje.
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

    /** @return array{matched_transaction_id:?int, settlements:int} */
    public function afterPurchaseReady(int $supplierId, int $purchaseInvoiceId, ?int $userId = null): array
    {
        $out = ['matched_transaction_id' => null, 'settlements' => 0];
        if (empty($this->regime->settings($supplierId)['enabled'])) {
            return $out;
        }
        try {
            $stmt = $this->db->pdo()->prepare(
                "SELECT pi.status,
                        EXISTS (SELECT 1 FROM journal_entries je
                                 WHERE je.supplier_id = pi.supplier_id AND je.source_type = 'purchase_invoice'
                                   AND je.source_id = pi.id AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL) AS posted
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
            try {
                $this->activity->log('card_settlement.automation_failed', $userId, 'purchase_invoice', $purchaseInvoiceId,
                    ['message' => $e->getMessage()], supplierId: $supplierId);
            } catch (\Throwable) {
            }
        }
        return $out;
    }
}
