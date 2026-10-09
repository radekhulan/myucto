<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice;

use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Accounting\Card\CardPaymentAutomation;
use MyInvoice\Service\Accounting\Cash\CashSettlementService;
use MyInvoice\Service\Accounting\DocumentAutoPoster;
use MyInvoice\Service\Accounting\SmallAsset\SmallAssetService;
use MyInvoice\Service\ActivityLogger;

/**
 * Přijetí přijatého dokladu (stav `received`) — jediné místo, kde žijí kroky, které
 * přijetí spouští: automatické zaúčtování, kartové vypořádání, evidence drobného
 * majetku a hotovostní úhrada z pokladny.
 *
 * Volá ho ruční přechod ({@see \MyInvoice\Action\PurchaseInvoice\TransitionPurchaseInvoiceStatusAction})
 * i dokončené schvalování manažerem střediska
 * ({@see \MyInvoice\Service\PurchaseInvoice\Approval\PurchaseInvoiceApprovalService}). Doklad
 * schválený všemi tak přijetím projde stejnou cestou, jako kdyby ho přijala účetní.
 */
final class PurchaseInvoiceReceiver
{
    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly DocumentAutoPoster $autoPoster,
        private readonly SmallAssetService $smallAssets,
        private readonly CashSettlementService $cashSettlement,
        private readonly CardPaymentAutomation $cardAutomation,
        private readonly \MyInvoice\Service\Invoice\CreditNoteOffsetService $creditNoteOffsets,
    ) {}

    /**
     * Koncept → přijato mimo HTTP přechod. Kroky odpovídají přechodu draft → received
     * v akci: interní číslo, stav, vyčištění varování vytěžení, audit a háčky přijetí.
     *
     * @return bool false = doklad už není koncept (nic se nestalo)
     * @throws \PDOException kolize interního čísla (unikátní index), volající ji ohlásí
     */
    public function receiveDraft(int $supplierId, int $id, ?int $userId, ?string $ip = null, ?string $userAgent = null, array $auditExtra = []): bool
    {
        $existing = $this->repo->find($id, $supplierId);
        if ($existing === null || (string) $existing['status'] !== 'draft') {
            return false;
        }
        if (empty($existing['varsymbol'])) {
            $this->repo->ensureVarsymbol($id, $supplierId);
        }
        if (!$this->repo->setStatus($id, 'received', $supplierId)) {
            return false;
        }
        if (!empty($existing['extraction_warning'])) {
            try {
                $this->repo->setExtractionWarning($id, $supplierId, null);
            } catch (\Throwable) {
                // Stejně jako v akci přechodu: vyčištění varování je jen doplněk.
            }
        }
        $this->logger->log('purchase_invoice.transitioned', $userId, 'purchase_invoice', $id, [
            'from' => 'draft',
            'to'   => 'received',
        ] + $auditExtra, $ip, $userAgent);
        $this->afterReceived($supplierId, $id, $userId, $ip, $userAgent);
        return true;
    }

    /**
     * Háčky přijetí. Každý je měkký — chyba zaúčtování, karty, majetku ani pokladny
     * nesmí zablokovat přechod stavu; jen se zaloguje.
     *
     * @return array<string,mixed>|null výsledek hotovostního vyrovnání
     */
    public function afterReceived(int $supplierId, int $id, ?int $userId, ?string $ip = null, ?string $userAgent = null): ?array
    {
        // Auto-post hook (A2): přijetí přijaté faktury je analog vystavení FV — má-li firma
        // zapnutý auto_post_purchases a běží v podvojném účetnictví, zaúčtuj PF hned.
        // Idempotentní, takže opakované dosažení stavu received (un-cancel) zápis neduplikuje.
        $this->autoPoster->maybeAutoPost($supplierId, 'purchase_invoice', $id, $userId, $ip, $userAgent);

        // Platba kartou: přijatý doklad s koncovkou karty se spáruje s pohybem karty.
        $this->cardAutomation->afterPurchaseReady($supplierId, $id, $userId);

        // Evidence drobného majetku (§DM): přijetí dokladu je okamžik, kdy se z
        // rozpracovaného stává pořízení — klasifikace udělaná v konceptu se teprve tady
        // propíše do evidence. Idempotentní přes přirozený klíč (název + cena).
        try {
            $this->smallAssets->syncFromPurchaseInvoice($supplierId, $id, $userId);
        } catch (\Throwable $e) {
            $this->logger->log('purchase_invoice.small_asset_sync_failed', $userId,
                'purchase_invoice', $id, ['error' => $e->getMessage()], $ip, $userAgent);
        }

        // Hotovostní vyrovnání (migrace 1327): koncept ještě není závazek, volbu
        // „uhradit hotově z pokladny" uplatní až přijetí.
        $settlement = $this->cashSettlement->maybeSettle($supplierId, 'purchase_invoice', $id, $userId, $ip, $userAgent);

        // Dobropis navázaný na nezaplacenou fakturu se s ní započte (issue #140). Až za
        // pokladnou: dobropis vrácený hotově už zápočet nepotřebuje. Pro jiné druhy
        // dokladu i opakované přijetí (un-cancel se zápočtem) je to no-op.
        try {
            $this->creditNoteOffsets->applyForPurchase($supplierId, $id, $userId);
        } catch (\Throwable $e) {
            $this->logger->log('purchase_invoice.credit_note_offset_failed', $userId,
                'purchase_invoice', $id, ['error' => $e->getMessage()], $ip, $userAgent);
        }

        return $settlement;
    }
}
