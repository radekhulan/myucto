<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceAttachmentRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Accounting\RetentionGuard;
use MyInvoice\Service\Accounting\RetentionViolationException;
use MyInvoice\Service\Pdf\PdfArchiveService;
use MyInvoice\Service\Stats\StatsRecomputer;
use PDO;

/**
 * Smazání dokladů z jedné importní dávky.
 *
 * PROČ TO EXISTUJE
 * ------------------------------------------------------------------------------
 * Zákazník migrující účetnictví z jiného systému si první dávku téměř nikdy nenahraje
 * správně — chybně zadaný export, špatný rozsah, jiná firma. Potřebuje ji zahodit
 * a nahrát znovu. Mazat tisíce dokladů po jednom nejde a hromadné mazání ze seznamu
 * není řešení: doklady se nedají označit napříč stránkami.
 *
 * ÚZKÝ ZÁBĚR JE ZÁMĚR
 * ------------------------------------------------------------------------------
 * Maže se VÝHRADNĚ doklad, který je:
 *   - z dané importní dávky,
 *   - NEZAÚČTOVANÝ (žádný aktivní zápis v deníku, `booked_at` prázdné),
 *   - nezamčený a mimo retenční lhůtu,
 *   - bez evidované úhrady.
 *
 * Cokoli jiného se PŘESKOČÍ s důvodem. Tím se tady nemusí opakovat větve, které řeší
 * {@see \MyInvoice\Action\Invoice\DeleteInvoiceAction} — storno zaúčtovaného zápisu,
 * přehlasování retence, force-delete admina. Nejde o druhou implementaci mazání, ale
 * o jeho JEDNODUCHOU cestu; složitý případ zůstává na jednodokladové akci, kde ho
 * uživatel vidí a rozhoduje o něm.
 *
 * Vazba na dávku je `invoices.import_batch_id` / `purchase_invoices.import_batch_id`
 * (migrace 1736, resp. 0141) — bez ní by „doklady z importu" nešly odlišit od dokladů
 * vystavených v aplikaci.
 */
final class ImportBatchEraser
{
    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceRepository $invoices,
        private readonly PurchaseInvoiceRepository $purchases,
        private readonly DocumentLockService $locks,
        private readonly RetentionGuard $retention,
        private readonly PdfArchiveService $pdfArchive,
        private readonly InvoiceAttachmentRepository $attachments,
        private readonly StatsRecomputer $stats,
    ) {}

    /**
     * @return array{
     *   deleted:array{invoices:int, purchase_invoices:int},
     *   skipped:list<array{kind:string, id:int, varsymbol:?string, reason:string}>,
     *   retention_overridden:list<array{id:int, varsymbol:?string}>
     * }
     */
    /**
     * @param bool $ackRetention Vědomé přehlasování retenční lhůty (§ 31 ZoÚ, § 35a ZDPH).
     *        Existuje ze stejného důvodu jako `?ack_retention=1` u jednodokladového mazání:
     *        povinnost uchovávat váže účetní jednotku, ne software, a tvrdý zákaz by
     *        uživatele s chybně nahranou dávkou hnal k zásahu přímo do databáze — tedy
     *        k horšímu řešení, po kterém nezůstane žádná stopa. Zapíná ho VÝHRADNĚ
     *        vědomý úkon; přehlasované doklady se vracejí v `retention_overridden`,
     *        aby se to dalo zapsat do auditní stopy.
     */
    public function erase(
        int $supplierId,
        string $batchId,
        ?callable $onProgress = null,
        ?callable $isCancelled = null,
        bool $ackRetention = false,
    ): array
    {
        $deleted = ['invoices' => 0, 'purchase_invoices' => 0];
        $skipped = [];
        $retentionOverridden = [];
        $touchedClients = [];
        $touchedProjects = [];

        $rows = $this->issuedInBatch($supplierId, $batchId);
        $purchaseRows = $this->purchasesInBatch($supplierId, $batchId);
        $total = count($rows) + count($purchaseRows);
        $processed = 0;
        $tick = static function () use (&$processed, $total, $onProgress, &$deleted, &$skipped): void {
            if ($onProgress !== null) {
                $onProgress($processed, $total, [
                    'deleted' => $deleted['invoices'] + $deleted['purchase_invoices'],
                    'skipped' => count($skipped),
                ]);
            }
        };
        $tick();

        foreach ($rows as $row) {
            if ($isCancelled !== null && $isCancelled()) break;
            $reason = $this->issuedBlocker($supplierId, $row, $ackRetention, $overrode);
            if ($overrode) {
                $retentionOverridden[] = ['id' => (int) $row['id'], 'varsymbol' => $row['varsymbol'] ?? null];
            }
            if ($reason !== null) {
                $skipped[] = ['kind' => 'invoice', 'id' => (int) $row['id'], 'varsymbol' => $row['varsymbol'] ?? null, 'reason' => $reason];
            } else {
                $this->eraseIssued($supplierId, $row, $touchedClients, $touchedProjects);
                $deleted['invoices']++;
            }
            $processed++;
            $tick();
        }

        foreach ($purchaseRows as $row) {
            if ($isCancelled !== null && $isCancelled()) break;
            $reason = $this->purchaseBlocker($supplierId, $row);
            if ($reason !== null) {
                $skipped[] = ['kind' => 'purchase_invoice', 'id' => (int) $row['id'], 'varsymbol' => $row['varsymbol'] ?? null, 'reason' => $reason];
            } else {
                $this->purchases->delete((int) $row['id'], $supplierId);
                $deleted['purchase_invoices']++;
            }
            $processed++;
            $tick();
        }

        // Cache seznamu klientů se přepočte dávkově až na konci — u tisíců dokladů nad
        // týmiž klienty by přepočet po každém smazání běžel zbytečně tisíckrát.
        if ($touchedClients !== [] || $touchedProjects !== []) {
            try {
                $this->stats->recomputeMany(array_keys($touchedClients), array_keys($touchedProjects));
            } catch (\Throwable $e) {
                error_log('ImportBatchEraser: recompute stats cache selhal: ' . $e->getMessage());
            }
        }

        return ['deleted' => $deleted, 'skipped' => $skipped, 'retention_overridden' => $retentionOverridden];
    }

    /** @return list<array<string,mixed>> */
    private function issuedInBatch(int $supplierId, string $batchId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, invoice_type, varsymbol, status, issue_date, tax_date,
                    effective_tax_date, booked_at, revenue_category_id, client_id, project_id
               FROM invoices
              WHERE supplier_id = ? AND import_batch_id = ?
           ORDER BY id'
        );
        $stmt->execute([$supplierId, $batchId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    private function purchasesInBatch(int $supplierId, string $batchId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, varsymbol, status, issue_date, tax_date, booked_at
               FROM purchase_invoices
              WHERE supplier_id = ? AND import_batch_id = ?
           ORDER BY id'
        );
        $stmt->execute([$supplierId, $batchId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string,mixed> $row
     * @param bool|null $overrode OUT: doklad prošel jen díky přehlasování retence
     */
    private function issuedBlocker(int $supplierId, array $row, bool $ackRetention = false, ?bool &$overrode = null): ?string
    {
        $overrode = false;
        if ($row['booked_at'] !== null) {
            return 'doklad je zaúčtovaný — smažte ho jednotlivě, ať vidíte storno zápisu';
        }
        $lock = $this->locks->forInvoice($row);
        if ($lock->booked || $lock->posted) {
            return 'doklad je zaúčtovaný — smažte ho jednotlivě, ať vidíte storno zápisu';
        }
        if ($lock->inClosedPeriod || $lock->inClosingPeriod || $lock->dateLocked) {
            return 'doklad je v uzavřeném období nebo zamčený k datu';
        }
        if ($this->hasPayments((int) $row['id'])) {
            return 'doklad má evidovanou úhradu';
        }
        if ((string) $row['status'] !== 'draft') {
            $year = (int) (new \DateTimeImmutable((string) ($row['tax_date'] ?: $row['issue_date'])))->format('Y');
            try {
                $this->retention->assertDeletable($supplierId, $year, 'Faktura ' . (string) ($row['varsymbol'] ?? $row['id']));
            } catch (RetentionViolationException $e) {
                if (!$ackRetention) {
                    return 'brání retenční lhůta: ' . $e->getMessage();
                }
                $overrode = true;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $row */
    private function purchaseBlocker(int $supplierId, array $row): ?string
    {
        if ($row['booked_at'] !== null) {
            return 'doklad je zaúčtovaný — smažte ho jednotlivě, ať vidíte storno zápisu';
        }
        $lock = $this->locks->forPurchaseInvoice($row);
        if ($lock->booked || $lock->posted) {
            return 'doklad je zaúčtovaný — smažte ho jednotlivě, ať vidíte storno zápisu';
        }
        if ($lock->inClosedPeriod || $lock->inClosingPeriod || $lock->dateLocked) {
            return 'doklad je v uzavřeném období nebo zamčený k datu';
        }
        if (in_array((string) $row['status'], ['paid', 'cancelled'], true)) {
            return 'doklad je zaplacený nebo stornovaný — patří do auditní stopy';
        }

        return null;
    }

    private function hasPayments(int $invoiceId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM invoice_payments WHERE invoice_id = ? LIMIT 1');
        $stmt->execute([$invoiceId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,bool> $touchedClients
     * @param array<int,bool> $touchedProjects
     */
    private function eraseIssued(int $supplierId, array $row, array &$touchedClients, array &$touchedProjects): void
    {
        $id = (int) $row['id'];
        // Soubory se musí uklidit DŘÍV než řádek: cesty k archivu i k přílohám se čtou
        // z tabulek, které DB cascade po smazání dokladu odstraní.
        $this->pdfArchive->purgeFilesForInvoice($id);
        $this->attachments->purgeFilesForInvoice($supplierId, $id);
        $this->invoices->delete($id);

        $clientId = (int) ($row['client_id'] ?? 0);
        if ($clientId > 0) $touchedClients[$clientId] = true;
        $projectId = (int) ($row['project_id'] ?? 0);
        if ($projectId > 0) $touchedProjects[$projectId] = true;
    }
}
