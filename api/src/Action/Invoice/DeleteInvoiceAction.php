<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Repository\InvoiceAttachmentRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Accounting\DocumentJournalSync;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\RetentionGuard;
use MyInvoice\Service\Accounting\RetentionViolationException;
use MyInvoice\Service\ActivityLogger;
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
        private readonly DocumentJournalSync $journalSync,
        private readonly StockIssueService $stockIssue,
        private readonly RetentionGuard $retention,
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

        // Zámek dokladu (Epic F6) — PŘED status guardem: zaúčtovaný/uzavřený doklad
        // klient nesmaže (403 document_locked), účetní v zavřeném období 409, admin
        // jen s ?force=1 (client nikdy admin větev — M6).
        $documentLock = $this->locks->forInvoice($existing);
        if ($deny = $this->denyIfLocked($request, $response, $documentLock, 'invoice', $id)) {
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

        $supplierId = (int) ($existing['supplier_id'] ?? 0);

        // Najdi všechny child doklady (storno, dobropis) — díky CASCADE se smažou
        // s parentem, ale chceme je zalogovat, invalidovat jejich PDF cache a zahrnout
        // do posouzení, zda force-delete maže výhradně nezaúčtované doklady.
        $children = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, invoice_type, varsymbol, status, issue_date, tax_date,
                    effective_tax_date, booked_at, revenue_category_id
               FROM invoices
              WHERE parent_invoice_id = ?'
        );
        $children->execute([$id]);
        $childRows = $children->fetchAll(\PDO::FETCH_ASSOC);

        $forceDelete = ($request->getQueryParams()['force'] ?? '') === '1'
            && RequestAuthorization::isCompanyAdmin($request);
        $allUnposted = !$documentLock->booked && !$documentLock->posted;
        foreach ($childRows as $child) {
            $childLock = $this->locks->forInvoice($child);
            if ($childLock->booked || $childLock->posted) {
                $allUnposted = false;
                break;
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
        $retentionOverride = null;
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
                    return Json::error($response, 'retention_period', $e->getMessage(), 422);
                }
                $retentionOverride = [
                    'reason'       => $e->getMessage(),
                    'retain_until' => $this->retention->retainUntil($supplierId, $periodYear),
                ];
            }
        }

        // Zachyt stats závislosti PŘED delete (po delete už client_id/project_id nepřečteme)
        $clientId  = isset($existing['client_id'])  ? (int) $existing['client_id']  : null;
        $projectId = isset($existing['project_id']) && $existing['project_id'] ? (int) $existing['project_id'] : null;

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $counterReleased = [];

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
            $deleteIds = array_map(static fn(array $row): int => (int) $row['id'], $childRows);
            $deleteIds[] = $id;
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
            // dostane stejné číslo. Drafty nemají counter-derived varsymbol; cancellation
            // nedostává varsymbol z counteru vůbec (IssueInvoiceAction).
            if ($supplierId > 0 && $status !== 'draft') {
                $parentType = (string) ($existing['invoice_type'] ?? '');
                $parentVs   = (string) ($existing['varsymbol'] ?? '');
                if ($parentVs !== '' && in_array($parentType, ['invoice', 'proforma', 'credit_note'], true)) {
                    $issueDate = !empty($existing['issue_date']) ? new \DateTimeImmutable($existing['issue_date']) : null;
                    $parentCat = (int) ($existing['revenue_category_id'] ?? 0);
                    if ($this->varsymbol->releaseIfLatest($supplierId, $parentType, $parentVs, $issueDate, $clientId ?? 0, $parentCat)) {
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
                if ($this->varsymbol->releaseIfLatest($supplierId, $ctype, $cvs, $cdate, $clientId ?? 0, $ccat)) {
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
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'ok'               => true,
            'cascade_deleted'  => count($childRows),
            'counter_released' => count($counterReleased),
        ]);
    }
}
