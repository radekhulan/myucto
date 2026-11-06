<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SessionOnlyAccountingAct;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\AccountingSetupRepository;
use MyInvoice\Repository\PurchaseInvoiceInboxDismissalRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceSubmissionRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\DocumentJournalSync;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Accounting\DocumentPaymentLinks;
use MyInvoice\Service\Accounting\DocumentDeletionBoundary;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\PurchaseInvoice\SubmissionOriginalFiler;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * DELETE /api/purchase-invoices/{id}
 *
 * Smaže přijatou fakturu vč. items (ON DELETE CASCADE). Pouze draft lze smazat.
 * Vystavené / zaúčtované doklady jsou součástí auditní stopy — používá se cancel.
 */
final class DeletePurchaseInvoiceAction
{
    use GuardsDocumentLock;

    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Config $config,
        private readonly DocumentLockService $locks,
        private readonly DocumentPaymentLinks $paymentLinks,
        private readonly DocumentDeletionBoundary $deletionBoundary,
        private readonly Connection $db,
        private readonly DocumentJournalSync $journalSync,
        private readonly PurchaseInvoiceSubmissionRepository $submissions,
        private readonly SubmissionOriginalFiler $filer,
        private readonly PurchaseInvoiceInboxDismissalRepository $dismissals,
        private readonly AccountingSetupRepository $accountingSetup,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'invalid_id', 'Neplatné ID', 400);
        }

        $supplierId = SupplierGuard::currentId($request);
        $existing = $this->repo->find($id, $supplierId);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }

        $documentLock = $this->locks->forPurchaseInvoice($existing);
        $explicitForceOverride = !empty($request->getQueryParams()['force']) && RequestAuthorization::isCompanyAdmin($request);
        $hasFinancialLinks = !$explicitForceOverride && $existing['status'] === 'draft' && !$documentLock->booked && !$documentLock->posted
            && ($documentLock->inClosedPeriod || $documentLock->inClosingPeriod || $documentLock->dateLocked)
            && $this->paymentLinks->existsForDocuments($supplierId, 'purchase_invoice', [$id]);
        $deletionLock = $explicitForceOverride ? $documentLock : $documentLock->forDeletion((string) $existing['status'], $hasFinancialLinks);
        $periodOnlyExemption = $deletionLock !== $documentLock
            && ($documentLock->inClosedPeriod || ($documentLock->inClosingPeriod && RequestAuthorization::isClientType($request)));
        if ($deny = $this->denyIfLocked($request, $response, $deletionLock, 'purchase_invoice', $id)) {
            return $deny;
        }

        // Default: jen draft lze smazat. Force=1 (admin) povolí smazat received/booked
        // (paid/cancelled stále chráněné — auditní stopa).
        $force = (string) ($request->getQueryParams()['force'] ?? '') === '1';
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $isAdmin = RequestAuthorization::isCompanyAdmin($request);
        $allowedForce = ['received', 'booked'];
        if ($existing['status'] !== 'draft') {
            // Vynucené smazání přijatého/zaúčtovaného dokladu stornuje i jeho zápis, takže jen z relace.
            if ($force
                && ($deny = SessionOnlyAccountingAct::deny($request, $response, 'Vynucené smazání přijatého nebo zaúčtovaného dokladu'))) {
                return $deny;
            }
            if (!($force && $isAdmin && in_array($existing['status'], $allowedForce, true))) {
                return Json::error(
                    $response,
                    'not_deletable',
                    'Lze smazat pouze koncepty (nebo received/booked s force=1 jako admin). Pro paid/cancelled použijte storno.',
                    409,
                );
            }
        }

        // Před DB delete uchovat info o PDF (k orphan cleanup)
        $pdfPath = (string) ($existing['pdf_path'] ?? '');
        $pdfHash = (string) ($existing['pdf_hash'] ?? '');
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());

        // A3 (audit H4): mazání zaúčtované PF nesmí nechat v deníku aktivní sirotčí zápis.
        // Reverze aktivního zápisu + vlastní delete v JEDNÉ transakci — reverze první;
        // při uzavřeném období vyhodí PostingException → rollback + 409, doklad zůstane.
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        $reopenedSubmissionIds = [];
        try {
            if ($periodOnlyExemption && $this->deletionBoundary->changed($supplierId, 'purchase_invoice', [$existing], $documentLock->accountantManaged)) {
                $deny = $this->denyIfLocked($request, $response, $documentLock, 'purchase_invoice', $id);
                if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
                return $deny ?? Json::error($response, 'document_changed', 'Doklad byl mezitím změněn. Načtěte jej znovu.', 409);
            }
            $this->accountingSetup->deleteReclassificationItems($supplierId, $id);
            $this->journalSync->onDelete($supplierId, 'purchase_invoice', $id, [
                'user_id'    => $user['id'] ?? null,
                'posted_by'  => $user['id'] ?? null,
                'ip'         => $ip,
                'user_agent' => $request->getHeaderLine('User-Agent'),
            ]);
            $reopenedSubmissionIds = $this->submissions->reopenForDeletedInvoice($supplierId, $id);
            // Podání je zpátky ve frontě — jeho originál patří z archivu zpět mezi čekající.
            $this->filer->restore($supplierId, $reopenedSubmissionIds);
            // Soubor v inbox adresáři zůstává — bez téhle stopy by ho další sken
            // založil jako nový koncept (issue #118).
            $this->dismissals->remember(
                $supplierId,
                [$existing['pdf_hash'] ?? null, $existing['source_hash'] ?? null],
                $id,
                isset($existing['vendor_invoice_number']) ? (string) $existing['vendor_invoice_number'] : null,
                isset($user['id']) ? (int) $user['id'] : null,
            );
            $this->repo->delete($id, $supplierId);
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
                'Přijatou fakturu nelze smazat — má zaúčtovaný zápis, který nelze stornovat ('
                    . $e->getMessage() . '). Nejdřív vyřešte zaúčtování v deníku.',
                409,
            );
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Orphan PDF cleanup — pokud žádná jiná faktura tenanta nemá stejný hash,
        // smaž soubor (s realpath check pro path traversal).
        $pdfDeleted = false;
        if ($pdfPath !== '' && $pdfHash !== '') {
            $stillUsed = $this->repo->findIdByPdfHash($supplierId, $pdfHash);
            if ($stillUsed === null) {
                $pdfDeleted = $this->safeUnlinkPdf($supplierId, $pdfPath);
            }
        }

        $this->logger->log('purchase_invoice.deleted', $user['id'] ?? null, 'purchase_invoice', $id,
            [
                'varsymbol'   => $existing['varsymbol'] ?? null,
                'pdf_deleted' => $pdfDeleted,
                'pdf_hash'    => $pdfHash !== '' ? substr($pdfHash, 0, 12) . '…' : null,
            ],
            $ip, $request->getHeaderLine('User-Agent'),
        );
        foreach ($reopenedSubmissionIds as $submissionId) {
            $this->logger->log(
                'purchase_invoice_submission.reopened',
                isset($user['id']) ? (int) $user['id'] : null,
                'purchase_invoice_submission',
                $submissionId,
                ['deleted_purchase_invoice_id' => $id],
                $ip,
                $request->getHeaderLine('User-Agent'),
                $supplierId,
            );
        }

        return Json::ok($response, [
            'ok' => true,
            'pdf_deleted' => $pdfDeleted,
            'reopened_submission_ids' => $reopenedSubmissionIds,
        ]);
    }

    /**
     * Smaže PDF soubor s realpath check vůči archive root (path traversal guard).
     */
    private function safeUnlinkPdf(int $supplierId, string $relativePath): bool
    {
        $archiveRoot = (string) $this->config->get('purchase_invoice.archive_storage', '');
        if ($archiveRoot === '') {
            $storageBase = (string) $this->config->get('storage.uploads_dir', '');
            $archiveRoot = $storageBase !== ''
                ? dirname($storageBase) . '/purchase-invoices'
                : \MyInvoice\Infrastructure\Config\RuntimePaths::storage('purchase-invoices');
        }
        $fullPath = $archiveRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $archiveRootReal = realpath($archiveRoot);
        $fullPathReal = realpath($fullPath);
        if ($archiveRootReal === false || $fullPathReal === false || !is_file($fullPathReal)) {
            return false;
        }
        // Windows is case-insensitive, normalize obě strany na lowercase
        $isWindows = DIRECTORY_SEPARATOR === '\\';
        $haystack = ($isWindows ? strtolower($fullPathReal) : $fullPathReal);
        $needle   = ($isWindows ? strtolower($archiveRootReal) : $archiveRootReal) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($haystack, $needle)) {
            return false; // mimo archive root — path traversal attempt, refuse
        }
        return @unlink($fullPathReal);
    }
}
