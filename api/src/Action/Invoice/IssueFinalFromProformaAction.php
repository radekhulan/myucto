<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Vystaví finální daňový doklad k zaplacené proformě.
 * Vytvoří DRAFT typu `invoice` s:
 *   - parent_invoice_id = id proformy
 *   - kopie všech položek z proformy
 *   - advance_paid_amount = (custom nebo proforma.total_with_vat)
 *   - amount_to_pay = total - advance (typicky 0)
 *
 * User pak otevře editor, zkontroluje a zavolá /issue.
 */
final class IssueFinalFromProformaAction
{
    use GuardsDocumentLock;

    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly FinalFromProformaCreator $creator,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly DocumentLockService $locks,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $proformaId = (int) ($args['id'] ?? 0);
        $proforma = $this->repo->find($proformaId);
        if (!SupplierGuard::owns($request, $proforma)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        if ($proforma['invoice_type'] !== 'proforma') {
            return Json::error($response, 'not_proforma', 'Lze pouze ze zálohové faktury (proforma).', 409);
        }
        // Vyúčtovat lze zaplacenou i ČÁSTEČNĚ uhrazenou proformu (#89) — plnění může
        // proběhnout dřív, než dorazí celá záloha; odpočet pokryje jen přijaté platby
        // a zbytek zůstane na finálním dokladu k úhradě.
        if ($proforma['status'] !== 'paid' && (float) ($proforma['paid_total'] ?? 0) <= 0) {
            return Json::error($response, 'not_paid', 'Proforma musí mít alespoň částečnou úhradu.', 409);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $taxDate = isset($body['tax_date']) && $body['tax_date'] !== '' ? (string) $body['tax_date'] : null;
        if ($taxDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $taxDate)) {
            return Json::error($response, 'invalid_date', 'Neplatné datum.', 400);
        }
        $dueDate = isset($body['due_date']) && $body['due_date'] !== '' ? (string) $body['due_date'] : null;
        $advance = isset($body['advance_paid_amount']) && $body['advance_paid_amount'] !== null && $body['advance_paid_amount'] !== ''
            ? (float) $body['advance_paid_amount']
            : null;
        // Celková cena zakázky — proforma bývá jen dílčí akontace, takže kopie jejích
        // položek popisuje jen rozsah zálohy. Zadaná hodnota doplní rozdílový řádek
        // (issue #39); prázdná zachovává dosavadní chování.
        $finalTotal = isset($body['final_total']) && $body['final_total'] !== null && $body['final_total'] !== ''
            ? (float) $body['final_total']
            : null;
        if ($finalTotal !== null && $finalTotal <= 0) {
            return Json::error($response, 'invalid_amount', 'Celková cena zakázky musí být kladná.', 400);
        }

        // H1 (Epic F6): finální draft vzniká s tax_date (creator: COALESCE(tax_date, dnes),
        // issue_date = CURDATE()) — efektivní refDate v uzavřeném období by založilo
        // „mrtvý" draft. Client 403 document_locked, účetní 409 period_closed, admin ?force=1.
        $refDate = $taxDate ?? date('Y-m-d');
        $lock = $this->locks->forDate(SupplierGuard::currentId($request), $refDate);
        if ($deny = $this->denyIfLocked($request, $response, $lock, 'invoice', $proformaId)) {
            return $deny;
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);

        try {
            $finalId = $this->creator->create($proformaId, $userId, $taxDate, $dueDate, $advance, $finalTotal);
        } catch (\Throwable $e) {
            return Json::error($response, 'create_failed', $e->getMessage(), 500);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('proforma.final_issued', $userId, 'invoice', $proformaId, [
            'final_invoice_id' => $finalId,
            'trigger'          => 'manual',
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'final_invoice_id' => $finalId,
            'edit_url'         => "/invoices/$finalId/edit",
            'invoice'          => $this->repo->find($finalId),
        ], 201);
    }
}
