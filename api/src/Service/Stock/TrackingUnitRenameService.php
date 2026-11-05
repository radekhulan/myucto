<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Http\IsoDate;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\FulfillmentRepository;
use MyInvoice\Repository\StockTrackingRepository;

/**
 * Oprava kódu šarže a data spotřeby u existující šarže (`stock_tracking_units`).
 *
 * Pohyby ukazují na šarži přes id, takže se přepíše jen kmen. Text šarže ale žije ještě
 * v JSON kopiích, podle kterých se koncepty párují s kmenem (`StockIssueService::locateTracking`,
 * `TrackingAllocationService::resolveUnit`), a zaúčtovaný řádek ho zobrazuje. Ty se přepisují
 * ve stejné transakci:
 *
 *  - koncepty faktur a vystavené zálohové faktury bez finální faktury
 *    (`invoice_items.tracking_json`),
 *  - řádky skladových dokladů (`stock_document_lines.tracking_input_json`): koncepty
 *    i zaúčtované a stornované doklady,
 *  - rozpracovaná expedice (`fulfillment_shipment_items.tracking_snapshot` zásilky `packing`)
 *    a řádky otevřených úloh vychystání.
 *
 * Nepřepisuje se vystavená ani stornovaná faktura (obnovení stornované faktury bere kusy
 * z vrácené výdejky), vyúčtovaná záloha, expedovaná zásilka, vratky a log čtečky. Uložené PDF
 * vystavené faktury zůstává, jak odešlo zákazníkovi. U nevyúčtované zálohy se uložené PDF
 * nezneplatňuje, ale přepsaný text se vykreslí při příštím přegenerování PDF (cache se
 * zneplatní třeba změnou šablony).
 */
final class TrackingUnitRenameService
{
    public function __construct(
        private readonly Connection $db,
        private readonly StockTrackingRepository $tracking,
        private readonly FulfillmentRepository $fulfillment,
        /** Počet pohybů šarže zpracovaných jednou dávkou. */
        private readonly int $batchSize = 500,
    ) {}

    /**
     * @param array<string,mixed> $changes klíče `lot_code` a `expires_on`; chybějící klíč hodnotu ponechá
     * @return array{
     *   changed: bool,
     *   unit: array{id:int, lot_code:string, expires_on:?string},
     *   previous: array{lot_code:string, expires_on:?string},
     *   updated: array{invoices:int, stock_drafts:int, stock_posted:int, fulfillment:int},
     *   invoice_ids: list<int> koncepty, jejichž cache PDF je po opravě zastaralá
     * }
     * @throws StockException
     */
    public function rename(int $supplierId, int $itemId, int $unitId, array $changes): array
    {
        $lotCode = null;
        if (array_key_exists('lot_code', $changes)) {
            $lotCode = is_string($changes['lot_code']) ? trim($changes['lot_code']) : '';
            if ($lotCode === '' || mb_strlen($lotCode) > 100) {
                throw new StockException('validation_failed', 'Kód šarže je povinný a smí mít nejvýše 100 znaků.', 422);
            }
        }
        $hasExpiry = array_key_exists('expires_on', $changes);
        $expiresOn = null;
        if ($hasExpiry && $changes['expires_on'] !== null) {
            $expiresOn = is_string($changes['expires_on']) ? trim($changes['expires_on']) : '';
            if ($expiresOn === '') {
                $expiresOn = null;
            } elseif (!IsoDate::isValid($expiresOn)) {
                throw new StockException('validation_failed', 'Datum spotřeby musí být platné datum ve tvaru RRRR-MM-DD.', 422);
            }
        }

        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
        }
        try {
            $result = $this->renameLocked($supplierId, $itemId, $unitId, $lotCode, $hasExpiry, $expiresOn);
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    private function renameLocked(int $supplierId, int $itemId, int $unitId, ?string $lotCode, bool $hasExpiry, ?string $expiresOn): array
    {
        $unit = $this->tracking->findUnit($supplierId, $itemId, $unitId, true);
        if ($unit === null) {
            throw new StockException('not_found', 'Šarže nenalezena.', 404);
        }
        if ($unit['tracking_type'] !== 'lot') {
            throw new StockException('tracking_mode_mismatch', 'Opravit jde jen šarži, ne sériové číslo.', 422);
        }
        $oldLot = (string) $unit['lot_code'];
        $oldExpires = $unit['expires_on'] === null ? null : (string) $unit['expires_on'];
        $newLot = $lotCode ?? $oldLot;
        $newExpires = $hasExpiry ? $expiresOn : $oldExpires;

        $previous = ['lot_code' => $oldLot, 'expires_on' => $oldExpires];
        $updated = ['invoices' => 0, 'stock_drafts' => 0, 'stock_posted' => 0, 'fulfillment' => 0];
        $result = [
            'changed' => false,
            'unit' => ['id' => $unitId, 'lot_code' => $oldLot, 'expires_on' => $oldExpires],
            'previous' => $previous,
            'updated' => $updated,
            'invoice_ids' => [],
        ];
        if ($newLot === $oldLot && $newExpires === $oldExpires) {
            return $result;
        }

        // Změna jen velikosti písmen najde sebe, jiná šarže téže karty s týmž klíčem je duplicita.
        $newKey = TrackingAllocationService::trackingKey(null, $newLot, $newExpires);
        $clash = $this->tracking->findUnitByKey($supplierId, $itemId, $newKey, true);
        if ($clash !== null && (int) $clash['id'] !== $unitId) {
            throw new StockException('tracking_unit_exists', 'Šarže se stejným kódem a datem spotřeby už na kartě existuje.', 409);
        }
        try {
            $this->tracking->updateUnitIdentity($supplierId, $itemId, $unitId, $newKey, $newLot, $newExpires);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                throw new StockException('tracking_unit_exists', 'Šarže se stejným kódem a datem spotřeby už na kartě existuje.', 409);
            }
            throw $e;
        }

        $args = [$unitId, $oldLot, $oldExpires, $newLot, $newExpires];

        // Koncepty faktur (jejich cache PDF je po opravě zastaralá) a vystavené zálohy bez finálu.
        $invoiceIds = [];
        foreach ([true => $this->tracking->lockDraftInvoiceTracking($supplierId, $itemId), false => $this->tracking->lockUnsettledProformaTracking($supplierId, $itemId)] as $isDraft => $rows) {
            foreach ($rows as $row) {
                $json = $this->rewriteList($row['json'], ...$args);
                if ($json !== null) {
                    $this->tracking->updateInvoiceItemTracking($row['id'], $json);
                    $updated['invoices']++;
                    if ($isDraft) {
                        $invoiceIds[$row['invoice_id']] = true;
                    }
                }
            }
        }
        $updated['stock_drafts'] = $this->rewriteLines($supplierId, $this->tracking->lockDraftDocumentLineTracking($supplierId, $itemId), $args);
        $cursor = 0;
        do {
            $batch = $this->tracking->allocationLineBatch($supplierId, $unitId, $cursor, $this->batchSize);
            $cursor = $batch['cursor'];
            $updated['stock_posted'] += $this->rewriteLines($supplierId, $this->tracking->lockLineTracking($supplierId, $batch['line_ids']), $args);
        } while ($batch['rows'] === $this->batchSize);
        foreach ($this->fulfillment->lockPackingShipmentTracking($supplierId, $itemId) as $row) {
            $rows = json_decode($row['json'], true);
            $rewritten = is_array($rows) ? TrackingIdentityRewrite::rewrite($rows, ...$args) : null;
            if ($rewritten !== null) {
                $this->fulfillment->updateShipmentTrackingSnapshot($supplierId, $row['id'], $rewritten);
                $updated['fulfillment']++;
            }
        }
        foreach ($this->fulfillment->lockOpenTaskLineSnapshots($supplierId, $itemId) as $row) {
            $snapshot = json_decode($row['json'], true);
            $allocations = is_array($snapshot) ? ($snapshot['tracking_allocations'] ?? null) : null;
            $rewritten = is_array($allocations) ? TrackingIdentityRewrite::rewrite($allocations, ...$args) : null;
            if ($rewritten !== null) {
                $snapshot['tracking_allocations'] = $rewritten;
                $this->fulfillment->updateTaskLineComponentSnapshot($supplierId, $row['id'], $snapshot);
                $updated['fulfillment']++;
            }
        }

        $result['changed'] = true;
        $result['unit'] = ['id' => $unitId, 'lot_code' => $newLot, 'expires_on' => $newExpires];
        $result['updated'] = $updated;
        $result['invoice_ids'] = array_keys($invoiceIds);
        return $result;
    }

    /**
     * Přepíše řádky skladových dokladů jedním UPDATE na dávku.
     *
     * @param list<array{id:int, json:string}> $rows
     * @param array{0:int,1:string,2:?string,3:string,4:?string} $args
     * @return int počet přepsaných řádků
     */
    private function rewriteLines(int $supplierId, array $rows, array $args): int
    {
        $changed = [];
        foreach ($rows as $row) {
            $json = $this->rewriteList($row['json'], ...$args);
            if ($json !== null) {
                $changed[$row['id']] = $json;
            }
        }
        // Po dávkách, ať UPDATE nepřekročí limit placeholderů.
        foreach (array_chunk($changed, 500, true) as $chunk) {
            $this->tracking->updateLinesTracking($supplierId, $chunk);
        }
        return count($changed);
    }

    private function rewriteList(string $json, int $unitId, string $oldLot, ?string $oldExpires, string $newLot, ?string $newExpires): ?string
    {
        $rows = json_decode($json, true);
        if (!is_array($rows)) {
            return null;
        }
        $rewritten = TrackingIdentityRewrite::rewrite($rows, $unitId, $oldLot, $oldExpires, $newLot, $newExpires);
        return $rewritten === null
            ? null
            : json_encode($rewritten, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
