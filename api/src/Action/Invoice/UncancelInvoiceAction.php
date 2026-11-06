<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\AssetSale\InvoiceAssetSaleService;
use MyInvoice\Service\Accounting\Cash\CashSettlementService;
use MyInvoice\Service\Accounting\DocumentAutoPoster;
use MyInvoice\Service\Accounting\DocumentJournalPurge;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Stats\StatsRecomputer;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockIssueService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/invoices/{id}/uncancel — zrušení interního storna (issue #80).
 *
 * Omylem stornovaná faktura se vrací do stavu, ve kterém byla před stornem, místo aby
 * se mazala a vystavovala znovu. Jde to jen u interního storna ({@see CancelInvoiceAction},
 * mode=internal) — dobropis je daňový doklad, který dostal zákazník, a ruší se vlastním
 * stornem.
 *
 * Zrcadlí kroky storna:
 *   - deník: zápisy faktury (typicky storno dvojice) i stornovacího dokladu se smažou přes
 *     {@see DocumentJournalPurge}, tedy jen když by šly smazat i ručně v deníku. Jinak 409
 *     a nic se nezmění. Fakturu pak znovu zaúčtuje auto-post, je-li zapnutý.
 *   - stornovací doklad (`cancellation`) se smaže, faktura dostane zpět odvozený stav.
 *   - sklad: auto-výdejka se vystaví znovu, jako při vystavení faktury.
 *   - prodej majetku a hotovostní vyrovnání se obnoví stejně jako po vystavení.
 *
 * Faktura se vrací do evidence DPH svého původního období, proto uzavřené účetní období
 * i uzamčené datum (podané DPH) zrušení storna tvrdě zastaví, bez admin přehlasování.
 */
final class UncancelInvoiceAction
{
    private const RESTORABLE_TYPES = ['invoice', 'proforma'];

    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly StatsRecomputer $stats,
        private readonly DocumentLockService $locks,
        private readonly DocumentJournalPurge $journalPurge,
        private readonly StockIssueService $stockIssue,
        private readonly InvoicePaymentService $payments,
        private readonly DocumentAutoPoster $autoPoster,
        private readonly InvoiceAssetSaleService $assetSale,
        private readonly CashSettlementService $cashSettlement,
        private readonly InvoicePdfRenderer $pdf,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        if (RequestAuthorization::isClientType($request)) {
            return Json::error($response, 'forbidden', 'Zrušit storno může jen účetní nebo admin.', 403);
        }
        if ((string) $invoice['status'] !== 'cancelled'
            || !in_array((string) $invoice['invoice_type'], self::RESTORABLE_TYPES, true)
        ) {
            return Json::error($response, 'invalid_state', 'Zrušit storno lze jen u stornované faktury nebo zálohy.', 409);
        }
        if (!empty($invoice['calendar_id'])) {
            return Json::error($response, 'calendar_instalment_locked', 'Splátka patří ke stornovanému kalendáři. Vystavte nový kalendář.', 409);
        }

        $supplierId = (int) $invoice['supplier_id'];
        $pdo = $this->db->pdo();

        $children = $pdo->prepare(
            'SELECT id, invoice_type, status FROM invoices WHERE supplier_id = ? AND parent_invoice_id = ?'
        );
        $children->execute([$supplierId, $id]);
        $cancellationId = null;
        foreach ($children->fetchAll(\PDO::FETCH_ASSOC) as $child) {
            if ($child['invoice_type'] === 'cancellation') {
                $cancellationId = (int) $child['id'];
            } elseif ($child['invoice_type'] === 'credit_note' && !in_array($child['status'], ['draft', 'cancelled'], true)) {
                return Json::error(
                    $response,
                    'credit_note_issued',
                    'Faktura je zrušená vystaveným dobropisem — ten se ruší stornem dobropisu, ne zrušením storna.',
                    409,
                );
            }
        }
        if ($cancellationId === null) {
            return Json::error($response, 'not_internally_cancelled', 'Faktura nemá interní storno, které by šlo zrušit.', 409);
        }

        $lock = $this->locks->forInvoice($invoice);
        if ($lock->inClosedPeriod || $lock->dateLocked) {
            return Json::error(
                $response,
                $lock->dateLocked ? 'date_locked' : 'period_closed',
                'Faktura spadá do uzavřeného nebo uzamčeného období — storno už nejde zrušit. Vystavte novou fakturu.',
                409,
            );
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = isset($user['id']) ? (int) $user['id'] : null;
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $meta = ['user_id' => $userId, 'ip' => $ip, 'user_agent' => $request->getHeaderLine('User-Agent')];

        $stockEnabled = $this->stockIssue->isStockEnabled($supplierId);
        $ownTx = !$pdo->inTransaction();
        if ($stockEnabled && $ownTx) {
            // Auto-výdejka poběží ve stejné transakci — viz CancelInvoiceAction.
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $purge = $this->journalPurge->purge($supplierId, 'invoice', [$id, $cancellationId], $meta, 'invoice_uncancel');
            if ($purge['blocked'] !== null) {
                if ($ownTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return Json::error(
                    $response,
                    'journal_' . $purge['blocked']['code'],
                    'Storno je zaúčtované v deníku a zápis nejde smazat (' . $purge['blocked']['message']
                        . '). Storno proto nelze zrušit, vystavte novou fakturu.',
                    409,
                );
            }

            $pdo->prepare('DELETE FROM invoices WHERE id = ? AND supplier_id = ? AND invoice_type = "cancellation"')
                ->execute([$cancellationId, $supplierId]);

            // Stav před stornem se neukládá — odvodí se stejně jako po smazání platby
            // (InvoicePaymentService::recompute). Finální doklad krytý zálohou byl
            // uhrazený už vystavením.
            $restore = $pdo->prepare(
                "UPDATE invoices
                    SET status = CASE
                                   WHEN invoice_type = 'invoice' AND amount_to_pay <= 0 THEN 'paid'
                                   WHEN reminder_count > 0 THEN 'reminded'
                                   WHEN sent_at IS NOT NULL THEN 'sent'
                                   ELSE 'issued'
                                 END,
                        paid_at = IF(invoice_type = 'invoice' AND amount_to_pay <= 0, COALESCE(paid_at, issue_date), NULL),
                        cancelled_at = NULL
                  WHERE id = ? AND supplier_id = ? AND status = 'cancelled'"
            );
            $restore->execute([$id, $supplierId]);
            if ($restore->rowCount() !== 1) {
                if ($ownTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return Json::error($response, 'race_condition', 'Faktura byla mezitím změněna.', 409);
            }
            $this->payments->recompute($id);

            if ($stockEnabled) {
                $this->stockIssue->issueForInvoice($supplierId, $invoice, $userId);
            }

            if ($ownTx) {
                $pdo->commit();
            }
        } catch (StockException $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return Json::error(
                $response,
                'stock.error.' . $e->errorCode,
                $e->getMessage(),
                $e->httpStatus,
                ['items' => $e->details],
            );
        } catch (\MyInvoice\Service\Accounting\PostingException $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->context);
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if ($ownTx) {
            $this->journalPurge->cleanupAttachments($supplierId, $purge['attachments']);
        }

        $this->logger->log('invoice.uncancelled', $userId, 'invoice', $id, [
            'cancellation_id'         => $cancellationId,
            'journal_entries_deleted' => $purge['deleted'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        // Stejné pořadí jako po vystavení (IssueInvoiceAction): zaúčtování → prodej majetku
        // → hotovostní vyrovnání. Chyby si služby polykají, faktura už je platná.
        $this->autoPoster->maybeAutoPost($supplierId, 'invoice', $id, $userId, $ip, $request->getHeaderLine('User-Agent'));
        $assetSaleWarnings = $this->assetSale->applyForIssuedInvoice($supplierId, $id, $meta);
        $this->cashSettlement->maybeSettle($supplierId, 'invoice', $id, $userId, $ip, $request->getHeaderLine('User-Agent'));

        $this->pdf->invalidate($id, 'invalidate_manual');
        $this->stats->recomputeAfterCommit($id);

        $restored = $this->repo->find($id);
        if ($assetSaleWarnings !== []) {
            $restored['asset_sale_warnings'] = $assetSaleWarnings;
        }

        return Json::ok($response, [
            'invoice'                 => $restored,
            'journal_entries_deleted' => count($purge['deleted']),
        ]);
    }
}
