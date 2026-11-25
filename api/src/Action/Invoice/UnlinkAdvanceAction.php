<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\UnbalancedEntryException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\IssuedInvoiceAdvanceDrawdown;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * DELETE /api/invoices/{id}/link-advance
 *
 * Zruší propojení daňového dokladu se zálohovou fakturou (proforma). Odpočet v hlavičce,
 * který doplnilo propojení, vrátí ({@see InvoiceRepository::unlinkAdvance()}); u vystavené
 * faktury srovná stav úhrady a přeúčtuje zápis. Vrací aktualizovaný invoice payload.
 */
final class UnlinkAdvanceAction
{
    use GuardsDocumentLock;

    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly DocumentLockService $locks,
        private readonly Connection $db,
        private readonly IssuedInvoiceAdvanceDrawdown $issued,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        // Zámek dokladu (Epic F6): rozpojení vazby na zálohu mění doklad.
        if ($deny = $this->denyIfLocked($request, $response, $this->locks->forInvoice($invoice), 'invoice', $id)) {
            return $deny;
        }

        if (!empty($invoice['booked_at']) && !RequestAuthorization::canRepostIssuedInvoice($request)) {
            return Json::error(
                $response,
                'journal_post_forbidden',
                'Zrušení vazby na zálohu u zaúčtované faktury přeúčtuje deník. K tomu je potřeba oprávnění „Zaúčtovat doklad“.',
                403,
            );
        }

        $supplierId = SupplierGuard::currentId($request);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        try {
            // Vystavená faktura: vrátit stav „uhrazeno zálohou“ a přeúčtovat zápis bez zálohy.
            (new DimensionPeriodGuard($this->db))->transaction(function () use ($id, $supplierId, $user, $ip, $request): void {
                $this->repo->unlinkAdvance($id, $supplierId);
                $this->issued->resync($supplierId, $id, [
                    'user_id' => isset($user['id']) ? (int) $user['id'] : null, 'ip' => $ip,
                    'user_agent' => $request->getHeaderLine('User-Agent'),
                ]);
            });
        } catch (PostingException | UnbalancedEntryException $e) {
            return Json::error($response, 'unlink_refused', 'Vazbu nešlo zrušit, protože zápis faktury v deníku nejde přeúčtovat: ' . $e->getMessage(), 409);
        } catch (\RuntimeException $e) {
            if (TransactionFailure::aborted($e) || $e instanceof \PDOException) {
                throw $e;
            }
            // Daňový doklad k přijaté platbě (#89) — strukturální vazba, nelze rozpojit.
            return Json::error($response, 'unlink_refused', $e->getMessage(), 409);
        }

        $this->logger->log('invoice.advance_unlinked', $user['id'] ?? null, 'invoice', $id, [
            'previous_advance_id' => $invoice['parent_invoice_id'] ?? null,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id));
    }
}
