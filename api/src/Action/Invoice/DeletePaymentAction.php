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
use MyInvoice\Service\Eet\EetHistoryExistsException;
use MyInvoice\Service\Eet\EetHistoryGuard;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * DELETE /api/invoices/{id}/payments/{paymentId} — smaže evidovanou platbu.
 *
 * Guardy řeší InvoicePaymentService: platbu s bankovní vazbou maž přes „Zrušit
 * spárování" ve výpisu; platbu s vystaveným daňovým dokladem až po jeho
 * smazání/stornu. Pokud po smazání platby přestane být doklad pokrytý, vrátí se
 * ze stavu 'paid' zpět (jako unmark-paid).
 */
final class DeletePaymentAction
{
    use GuardsDocumentLock;

    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly InvoicePaymentService $payments,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly DocumentLockService $locks,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $paymentId = (int) ($args['paymentId'] ?? 0);

        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        $payment = $this->payments->findPayment($paymentId);
        if ($payment === null || (int) $payment['invoice_id'] !== $id) {
            return Json::error($response, 'not_found', 'Platba nenalezena.', 404);
        }

        // Zámek dokladu (Epic F6): smazání platby mění účetní fakt (na rozdíl od přidání).
        if ($deny = $this->denyIfLocked($request, $response, $this->locks->forInvoice($invoice), 'invoice', $id)) {
            return $deny;
        }

        try {
            $result = $this->payments->deletePayment($paymentId);
        } catch (EetHistoryExistsException $e) {
            return Json::error($response, EetHistoryGuard::ERROR_CODE, $e->getMessage(), 409);
        } catch (\RuntimeException $e) {
            return Json::error($response, 'payment_locked', $e->getMessage(), 409);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.payment_deleted', $user['id'] ?? null, 'invoice', $id, [
            'payment_id' => $paymentId,
            'amount'     => $payment['amount'],
            'paid_on'    => $payment['paid_on'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'invoice'       => $this->repo->find($id),
            'payments'      => $this->payments->listFor($id),
            'became_unpaid' => $result['became_unpaid'],
            'remaining'     => $result['remaining'],
        ]);
    }
}
