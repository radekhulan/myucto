<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SessionOnlyAccountingAct;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Repository\InvoiceAttachmentRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Accounting\DocumentJournalPurge;
use MyInvoice\Service\Accounting\DocumentJournalSync;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Accounting\DocumentPaymentLinks;
use MyInvoice\Service\Accounting\DocumentDeletionBoundary;
use MyInvoice\Service\Accounting\JournalEntryDeletionRules;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\RetentionGuard;
use MyInvoice\Service\Accounting\RetentionViolationException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Eet\EetHistoryGuard;
use MyInvoice\Service\Invoice\VarsymbolGenerator;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Pdf\PdfArchiveService;
use MyInvoice\Service\Stats\StatsRecomputer;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockIssueService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * DELETE /api/invoices/{id}
 *
 * Politika mazání:
 *   - draft                              → smí kdokoliv s rolí ≥ accountant
 *   - issued / sent / cancelled / paid   → smí pouze admin (force-delete účetního dokladu)
 *   - readonly role                      → nikdy
 *
 * Cascade chování (DB úroveň, migrace 0015):
 *   FK invoices.parent_invoice_id má ON DELETE CASCADE — smazání rodiče
 *   automaticky odstraní navazující storno/dobropis (a jejich items, work_reports
 *   přes existující CASCADE směrem dolů). Bank pairing matched_invoice_id
 *   je SET NULL, takže transakce zůstane, jen ztratí pair.
 *
 * Strana effektů:
 *   1. PDF cache invalidace pro fakturu I všechny děti (DB cascade soubory neuklidí)
 *   2. SQL DELETE (cascade smaže items, work_reports, child invoices)
 *   3. StatsRecomputer pro klienta + projekt (revenue cache)
 *   4. ActivityLog: 'invoice.deleted' (draft) | 'invoice.force_deleted' (non-draft)
 *      s detaily o smazaných potomcích pro forenzní audit
 */
final class DeleteInvoiceAction
{
    use GuardsDocumentLock;

    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly InvoicePdfRenderer $pdf,
        private readonly PdfArchiveService $pdfArchive,
        private readonly InvoiceAttachmentRepository $attachments,
        private readonly StatsRecomputer $stats,
        private readonly VarsymbolGenerator $varsymbol,
        private readonly DocumentLockService $locks,
        private readonly DocumentPaymentLinks $paymentLinks,
        private readonly DocumentDeletionBoundary $deletionBoundary,
        private readonly DocumentJournalSync $journalSync,
        private readonly StockIssueService $stockIssue,
        private readonly RetentionGuard $retention,
        private readonly DocumentJournalPurge $journalPurge,
        private readonly EetHistoryGuard $eetHistory,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $existing = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $existing)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        $user   = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $status = (string) $existing['status'];

        if (!RequestAuthorization::allows($request, 'invoices.delete', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Read-only role nemůže mazat.', 403);
        }
        if ($status !== 'draft'
            && (!empty($existing['calendar_id']) || \MyInvoice\Service\Invoice\InvoiceCalendar::isCalendar($existing['invoice_type']))) {
            return Json::error($response, 'calendar_not_deletable', 'Vystavený kalendář a jeho splátky nejde smazat. Kalendář stornujte.', 409);
        }

        $documentLock = $this->locks->forInvoice($existing);
        $supplierId = (int) ($existing['supplier_id'] ?? 0);
        $explicitForceOverride = !empty($request->getQueryParams()['force']) && RequestAuthorization::isCompanyAdmin($request);
        $periodCandidate = !$explicitForceOverride && $status === 'draft' && !$documentLock->booked && !$documentLock->posted
            && ($documentLock->inClosedPeriod || ($documentLock->inClosingPeriod && RequestAuthorization::isClientType($request)));
        $children = $this->db->pdo()->prepare(
            $periodCandidate ? 'WITH RECURSIVE deletion_tree AS (
                SELECT id, supplier_id, invoice_type, is_simplified, varsymbol, status, issue_date, tax_date, parent_invoice_id,
                       effective_tax_date, booked_at, revenue_category_id
                  FROM invoices FORCE INDEX (idx_inv_parent) WHERE supplier_id = ? AND parent_invoice_id = ?
                UNION DISTINCT
                SELECT i.id, i.supplier_id, i.invoice_type, i.is_simplified, i.varsymbol, i.status, i.issue_date, i.tax_date, i.parent_invoice_id,
                       i.effective_tax_date, i.booked_at, i.revenue_category_id
                  FROM deletion_tree parent STRAIGHT_JOIN invoices i FORCE INDEX (idx_inv_parent) ON parent.id = i.parent_invoice_id
                 WHERE i.supplier_id = ?
             ) SELECT * FROM deletion_tree WHERE id <> ?'
            : 'SELECT id, supplier_id, invoice_type, is_simplified, varsymbol, status, issue_date, tax_date, parent_invoice_id,
                      effective_tax_date, booked_at, revenue_category_id
                 FROM invoices WHERE supplier_id = ? AND parent_invoice_id = ?'
        );
        $children->execute($periodCandidate ? [$supplierId, $id, $supplierId, $id] : [$supplierId, $id]);
        $childRows = $children->fetchAll(\PDO::FETCH_ASSOC);
        $childIds = array_map(static fn (array $row): int => (int) $row['id'], $childRows);
        $deleteIds = [...$childIds, $id];
        $childLocks = [];
        foreach (array_chunk($childIds, 500) as $batch) {
            $childLocks += $this->locks->lockedMapForSources($supplierId, 'invoice', $batch);
        }
        $allUnposted = !$documentLock->booked && !$documentLock->posted;
        $allChildrenDraft = true;
        foreach ($childRows as $child) {
            $childLock = $childLocks[(int) $child['id']] ?? null;
            if ($childLock === null || $childLock->booked || $childLock->posted) {
                $allUnposted = false;
            }
            if ($child['status'] !== 'draft') {
                $allChildrenDraft = false;
            }
        }
        $hasFinancialLinks = !$allUnposted || !$allChildrenDraft;
        if (!$explicitForceOverride && !$hasFinancialLinks && $status === 'draft'
            && ($documentLock->inClosedPeriod || $documentLock->inClosingPeriod || $documentLock->dateLocked)) {
            $hasFinancialLinks = $this->paymentLinks->existsForDocuments($supplierId, 'invoice', $deleteIds);
        }
        $deletionLock = $explicitForceOverride ? $documentLock : $documentLock->forDeletion($status, $hasFinancialLinks);
        $periodOnlyExemption = $deletionLock !== $documentLock
            && ($documentLock->inClosedPeriod || ($documentLock->inClosingPeriod && RequestAuthorization::isClientType($request)));
        if ($deny = $this->denyIfLocked($request, $response, $deletionLock, 'invoice', $id)) {
            return $deny;
        }

        if ($status !== 'draft' && !RequestAuthorization::isCompanyAdmin($request)) {
            return Json::error(
                $response,
                'admin_required',
                'Smazat vystavenou, odeslanou, zaplacenou nebo stornovanou fakturu může jen admin.',
                403,
            );
        }

        // Vynucené smazání vystaveného dokladu maže i jeho zápisy v deníku, takže jen z relace.
        if ($status !== 'draft'
            && ($request->getQueryParams()['force'] ?? '') === '1'
            && ($deny = SessionOnlyAccountingAct::deny($request, $response, 'Vynucené smazání vystaveného dokladu'))) {
            return $deny;
        }

        // EET-07: doklad s platební událostí nebo evidovanou tržbou jde jen stornovat
        // (i admin s ?force=1); fiskální historie musí zůstat navázaná na doklad.
        $eetConflict = $this->eetHistory->invoiceConflict(
            $supplierId,
            [$id, ...array_map(static fn (array $row): int => (int) $row['id'], $childRows)],
        );
        if ($eetConflict !== null) {
            return Json::error($response, EetHistoryGuard::ERROR_CODE, $eetConflict, 409);
        }

        $forceDelete = ($request->getQueryParams()['force'] ?? '') === '1'
            && RequestAuthorization::isCompanyAdmin($request);

        // Zachyt stats závislosti PŘED delete (po delete už client_id/project_id nepřečteme)
        $clientId  = isset($existing['client_id'])  ? (int) $existing['client_id']  : null;
        $projectId = isset($existing['project_id']) && $existing['project_id'] ? (int) $existing['project_id'] : null;

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $counterReleased = [];
        $retentionOverride = null;
        $journalPurge = null;

        // A3 (audit H4): mazání zaúčtované faktury NESMÍ nechat v deníku aktivní sirotčí
        // zápis. Reverze aktivního zápisu + PDF/soubory + counter release + vlastní delete
        // běží v JEDNÉ transakci — buď vše, nebo nic (rollback). Reverze je PRVNÍ krok:
        // když je období uzavřené a storno nelze zaúčtovat, transakce se rollbackne
        // PŘED destrukcí souborů i dokladu a vrátí 409 (žádný sirotek).
        $reverseMeta = [
            'user_id'    => $user['id'] ?? null,
            'posted_by'  => $user['id'] ?? null,
            'ip'         => $ip,
            'user_agent' => $request->getHeaderLine('User-Agent'),
        ];

        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            if ($periodOnlyExemption && $this->deletionBoundary->changed($supplierId, 'invoice', [$existing, ...$childRows], false)) {
                $deny = $this->denyIfLocked($request, $response, $documentLock, 'invoice', $id);
                if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
                return $deny ?? Json::error($response, 'document_changed', 'Doklad byl mezitím změněn. Načtěte jej znovu.', 409);
            }
            // Admin force-delete zaúčtované faktury: dají-li se její zápisy (i storno
            // dvojice) smazat v deníku, smažou se nejdřív a faktura pak odchází jako
            // nezaúčtovaná. Nejde-li to u kteréhokoli dokladu skupiny, nesmaže se nic
            // a platí dosavadní cesta — protizápis a retenční brána.
            //
            // Zápis v uzamčeném období (po podání přiznání k DPH) se nepřeskakuje tichým
            // stornem: bez potvrzení vrátí varování, s ním se smaže zápis i faktura naráz.
            if ($forceDelete && !$allUnposted) {
                $lockedAck = ($request->getQueryParams()[JournalEntryDeletionRules::ACK_LOCKED_PARAM] ?? '') === '1';
                $purge = $this->journalPurge->purge(
                    $supplierId, 'invoice', $deleteIds, $reverseMeta, 'invoice_force_delete', $lockedAck,
                );
                if ($purge['blocked'] === null && $purge['deleted'] !== []) {
                    $journalPurge = $purge;
                    $allUnposted = !$this->hasPostingTrace($supplierId, $deleteIds);
                } elseif (!empty($purge['blocked']['can_acknowledge'])) {
                    if ($ownTx && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    return Json::error(
                        $response,
                        (string) $purge['blocked']['code'],
                        (string) $purge['blocked']['message'],
                        409,
                        ['can_acknowledge' => true],
                    );
                }
            }

            // Retenční brána (§ 31/§ 32 ZoÚ, § 35a ZDPH). Explicitní admin force-delete
            // smí bez dalšího potvrzení odstranit vydaný doklad jen tehdy, když target ani
            // žádný CASCADE child nikdy nebyl zaúčtován (booked_at ani aktivní posted zápis).
            // Zaúčtované doklady zůstávají chráněné retenční lhůtou; draft se jí netýká.
            //
            // Přehlasování `?ack_retention=1` existuje vědomě. Povinnost uchovávat váže účetní
            // jednotku, ne software, a tvrdý zákaz by uživatele s vadným dokladem (omylem
            // vystaveným v ostrém tenantu) hnal k zásahu přímo do databáze — tedy k horšímu
            // řešení, po kterém nezůstane žádná stopa. Takhle je smazání vědomý úkon, který
            // se i s prošlapanou lhůtou zapíše do auditní stopy.
            if ($status !== 'draft' && !($forceDelete && $allUnposted)) {
                $periodYear = (int) (new \DateTimeImmutable(
                    (string) ($existing['tax_date'] ?: $existing['issue_date'])
                ))->format('Y');
                try {
                    $this->retention->assertDeletable(
                        $supplierId,
                        $periodYear,
                        'Faktura ' . (string) ($existing['varsymbol'] ?? $id),
                    );
                } catch (RetentionViolationException $e) {
                    $acknowledged = ($request->getQueryParams()['ack_retention'] ?? '') === '1'
                        && RequestAuthorization::isCompanyAdmin($request);
                    if (!$acknowledged) {
                        if ($ownTx && $pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        return Json::error($response, 'retention_period', $e->getMessage(), 422);
                    }
                    $retentionOverride = [
                        'reason'       => $e->getMessage(),
                        'retain_until' => $this->retention->retainUntil($supplierId, $periodYear),
                    ];
                }
            }

            $this->journalSync->onDeleteMany($supplierId, 'invoice', $deleteIds, $reverseMeta);
            foreach ($deleteIds as $deleteId) {
                $this->stockIssue->reverseForInvoice($supplierId, $deleteId, isset($user['id']) ? (int) $user['id'] : null);
            }

            // 1. Invalidate aktivní PDF cache (parent + děti)
            $this->pdf->invalidate($id, 'invalidate_manual');
            foreach ($childRows as $child) {
                $this->pdf->invalidate((int) $child['id'], 'invalidate_manual');
            }

            // 1b. Smaž historii PDF + uživatelské přílohy (FYZICKÉ soubory na disku).
            // DB řádky v invoice_pdfs / invoice_attachments se cascade smažou v kroku 2,
            // ale archivní soubory v _archive/ a attachments/{invoiceId}/ by jinak zůstaly orphan.
            // Čte invoice_pdfs → MUSÍ běžet před delete (proto uvnitř transakce, ne po commitu).
            $this->pdfArchive->purgeFilesForInvoice($id);
            if ($supplierId > 0) {
                $this->attachments->purgeFilesForInvoice($supplierId, $id);
            }
            foreach ($childRows as $child) {
                $cid = (int) $child['id'];
                $this->pdfArchive->purgeFilesForInvoice($cid);
                if ($supplierId > 0) {
                    $this->attachments->purgeFilesForInvoice($supplierId, $cid);
                }
            }

            // 1c. Pokud je tato faktura "poslední" ve své counter scope (a stejně tak
            // její cascade-deleted credit_note potomci), uvolni counter — další vystavená
            // dostane stejné číslo. Týká se i konceptu: žádost o schválení výkazu mu číslo
            // přidělí předem (AutoIssueAndSendService::allocateVarsymbolAndSnapshots) a bez
            // uvolnění by po smazání zůstala v řadě díra. Ručně zadané číslo konceptu counter
            // nepotká (releaseIfLatest porovnává s aktuální hodnotou counteru). Cancellation
            // nedostává varsymbol z counteru vůbec (IssueInvoiceAction).
            if ($supplierId > 0) {
                $parentType = (string) ($existing['invoice_type'] ?? '');
                $parentVs   = (string) ($existing['varsymbol'] ?? '');
                if ($parentVs !== '' && in_array($parentType, ['invoice', 'proforma', 'credit_note'], true)) {
                    $issueDate = !empty($existing['issue_date']) ? new \DateTimeImmutable($existing['issue_date']) : null;
                    $parentCat = (int) ($existing['revenue_category_id'] ?? 0);
                    // Řada, ze které číslo přišlo (zjednodušený doklad může mít vlastní).
                    $parentSeries = VarsymbolGenerator::seriesTypeFor($existing);
                    if ($this->varsymbol->releaseIfLatest($supplierId, $parentSeries, $parentVs, $issueDate, $clientId ?? 0, $parentCat)) {
                        $counterReleased[] = ['id' => $id, 'varsymbol' => $parentVs, 'type' => $parentType];
                    }
                }
            }
            foreach ($childRows as $child) {
                $ctype = (string) ($child['invoice_type'] ?? '');
                $cvs   = (string) ($child['varsymbol'] ?? '');
                if ($cvs === '' || !in_array($ctype, ['invoice', 'proforma', 'credit_note'], true)) continue;
                $cdate = !empty($child['issue_date']) ? new \DateTimeImmutable($child['issue_date']) : null;
                $ccat  = (int) ($child['revenue_category_id'] ?? 0);
                if ($this->varsymbol->releaseIfLatest($supplierId, VarsymbolGenerator::seriesTypeFor($child), $cvs, $cdate, $clientId ?? 0, $ccat)) {
                    $counterReleased[] = ['id' => (int) $child['id'], 'varsymbol' => $cvs, 'type' => $ctype];
                }
            }

            // 2. Vlastní delete (CASCADE smaže items, work_reports, child invoices,
            //    invoice_pdfs, invoice_attachments — vše nahoru na FK invoice_id)
            $this->repo->delete($id);

            if ($ownTx) {
                $pdo->commit();
            }
        } catch (PostingException $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return Json::error(
                $response,
                'journal_' . $e->errorCode,
                'Fakturu nelze smazat — má zaúčtovaný zápis, který nelze stornovat (' . $e->getMessage()
                    . '). Nejdřív vyřešte zaúčtování v deníku.',
                409,
            );
        } catch (StockException $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return Json::error(
                $response,
                'stock_' . $e->errorCode,
                'Fakturu nelze smazat — navázaný skladový doklad nelze stornovat (' . $e->getMessage() . ').',
                409,
            );
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if ($journalPurge !== null && $ownTx) {
            $this->journalPurge->cleanupAttachments($supplierId, $journalPurge['attachments']);
        }

        // 3. Recompute revenue stats (po smazání issued/sent/paid se mění agregát)
        if ($clientId !== null) {
            $this->stats->recomputeForIds($clientId, $projectId);
        }

        // 4. Audit log — víc detailů pro force-delete než pro draft
        $eventName = ($status === 'draft') ? 'invoice.deleted' : 'invoice.force_deleted';
        $this->logger->log($eventName, $user['id'] ?? null, 'invoice', $id, [
            'varsymbol'           => $existing['varsymbol'] ?? null,
            'type'                => $existing['invoice_type'] ?? null,
            'status_before'       => $status,
            'total'               => $existing['total_with_vat'] ?? null,
            'currency'            => $existing['currency'] ?? null,
            'cascade_deleted_ids' => array_column($childRows, 'id'),
            'cascade_deleted'     => array_map(static fn ($c) => [
                'id'        => (int) $c['id'],
                'type'      => $c['invoice_type'],
                'varsymbol' => $c['varsymbol'],
                'status'    => $c['status'],
            ], $childRows),
            'counter_released'    => $counterReleased,
            // Prošlapaná retenční lhůta MUSÍ zůstat dohledatelná — bez toho by se
            // z vědomého přehlasování stalo obyčejné smazání.
            'retention_override'  => $retentionOverride,
            'journal_entries_deleted' => $journalPurge['deleted'] ?? [],
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'ok'               => true,
            'cascade_deleted'  => count($childRows),
            'counter_released' => count($counterReleased),
            'journal_entries_deleted' => count($journalPurge['deleted'] ?? []),
        ]);
    }

    /** Zůstala některému z dokladů po odklizení deníku stopa zaúčtování? */
    private function hasPostingTrace(int $supplierId, array $invoiceIds): bool
    {
        $place = implode(',', array_fill(0, count($invoiceIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT EXISTS(SELECT 1 FROM invoices
                            WHERE supplier_id = ? AND id IN ($place) AND booked_at IS NOT NULL)
                 OR EXISTS(SELECT 1 FROM invoices d
                            WHERE d.supplier_id = ? AND d.id IN ($place)
                              AND " . \MyInvoice\Service\Accounting\DocumentPostingGroup::activePostingExistsSql("'invoice'", 'd.id', 'd.supplier_id', true, false) . ')'
        );
        $stmt->execute(array_merge([$supplierId], $invoiceIds, [$supplierId], $invoiceIds));

        return (bool) $stmt->fetchColumn();
    }
}
