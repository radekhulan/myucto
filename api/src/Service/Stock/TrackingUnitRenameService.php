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
 *  - koncepty faktur (`invoice_items.tracking_json`),
 *  - řádky skladových dokladů (`stock_document_lines.tracking_input_json`): koncepty
 *    i zaúčtované a stornované doklady,
 *  - rozpracovaná expedice (`fulfillment_shipment_items.tracking_snapshot` zásilky `packing`)
 *    a řádky otevřených úloh vychystání.
 *
 * Pořadí zámků: nejdřív doklady (koncepty faktur, koncepty skladových dokladů), potom
 * šarže. Stejné pořadí má vystavení faktury (řádek faktury, potom šarže při výdeji) a
 * zaúčtování skladového dokladu; opačné pořadí by se se souběžným vystavením zaklínilo
 * (deadlock 1213). Kdyby k zaklínění nebo čekání na zámek (1205) přesto došlo, vrací se 409
 * s výzvou zkusit to znovu a nic se nezmění.
 *
 * Nepřepisuje se žádný vystavený doklad: vystavená ani stornovaná faktura (obnovení stornované
 * faktury bere kusy z vrácené výdejky), vystavená zálohová faktura (finální faktura z ní si
 * šarži po opravě vybere znovu), expedovaná zásilka, vratky a log čtečky. Uložené PDF
 * vystavené faktury zůstává, jak odešlo zákazníkovi.
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
            if (!is_string($changes['lot_code'])) {
                throw new StockException('validation_failed', 'Kód šarže musí být text.', 422);
            }
            $lotCode = trim($changes['lot_code']);
            if ($lotCode === '' || mb_strlen($lotCode) > 100) {
                throw new StockException('validation_failed', 'Kód šarže je povinný a smí mít nejvýše 100 znaků.', 422);
            }
        }
        $hasExpiry = array_key_exists('expires_on', $changes);
        $expiresOn = null;
        // Datum smaže jen výslovné null; číslo, boolean nebo prázdný text se neodmítá mlčky jako smazání.
        if ($hasExpiry && $changes['expires_on'] !== null) {
            $raw = $changes['expires_on'];
            if (!is_string($raw) || !IsoDate::isValid(trim($raw))) {
                throw new StockException('validation_failed', 'Datum spotřeby musí být platné datum ve tvaru RRRR-MM-DD, nebo null pro smazání data.', 422);
            }
            $expiresOn = trim($raw);
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
            // 1213 deadlock, 1205 vypršelo čekání na zámek: souběžné vystavení nebo zaúčtování.
            if ($e instanceof \PDOException && in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205], true)) {
                throw new StockException('lock_conflict', 'Šarži právě upravuje nebo používá jiná operace. Zkuste to prosím znovu.', 409);
            }
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    private function renameLocked(int $supplierId, int $itemId, int $unitId, ?string $lotCode, bool $hasExpiry, ?string $expiresOn): array
    {
        // Doklady dřív než šarže (viz pořadí zámků nahoře). Zámky drží do konce transakce,
        // i když se pak ukáže, že šarže neexistuje nebo se nemění.
        $draftInvoiceRows = $this->tracking->lockDraftInvoiceTracking($supplierId, $itemId);
        $draftLineRows = $this->tracking->lockDraftDocumentLineTracking($supplierId, $itemId);
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

        // Koncepty faktur (jejich cache PDF je po opravě zastaralá).
        $invoiceIds = [];
        foreach ($draftInvoiceRows as $row) {
            $json = $this->rewriteList($row['json'], ...$args);
            if ($json !== null) {
                $this->tracking->updateInvoiceItemTracking($row['id'], $json);
                $updated['invoices']++;
                $invoiceIds[$row['invoice_id']] = true;
            }
        }
        $updated['stock_drafts'] = $this->rewriteLines($supplierId, $draftLineRows, $args);
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
