<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Payment\PaymentOrderService;
use MyInvoice\Support\Pagination;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Platební příkazy pro přijaté faktury.
 *
 *   GET    /api/purchase-invoices/payment-orders/candidates?currency=CZK  → kandidáti + účty plátce (read)
 *   POST   /api/purchase-invoices/payment-orders                          → vytvoř dávku (write)
 *   GET    /api/purchase-invoices/payment-orders                          → historie dávek (read)
 *   GET    /api/purchase-invoices/payment-orders/{id}                     → detail dávky (read)
 *   GET    /api/purchase-invoices/payment-orders/{id}/download?format=abo|csv|pdf → soubor (read)
 *
 * RBAC (defense-in-depth): PermissionMiddleware gatuje celou rodinu
 * /purchase-invoices/payment-orders na 'purchase_invoices.payment_orders' (GET = read,
 * ostatní metody = write). Action si totéž právo ověřuje sama, protože jinak by ji
 * chránilo jen matchování řetězce cesty — a to je právě ta vrstva, která se s routerem
 * dokázala rozejít. Hrubý klíč 'purchase_invoices' tu schválně NESTAČÍ, na ten se
 * degradovaná cesta spadla.
 */
final class PaymentOrderAction
{
    private const PERMISSION = 'purchase_invoices.payment_orders';

    public function archive(Request $request, Response $response, array $args): Response
    {
        if ($err = $this->denied($request, $response, AccessLevel::WRITE)) return $err;
        if (!RequestAuthorization::isSessionAuth($request)) return Json::sessionRequired($response);
        $body = $request->getParsedBody();
        if (!is_array($body) || ($body['bank_cancellation_confirmed'] ?? null) !== true) {
            return Json::error($response, 'bank_cancellation_confirmation_required', 'Potvrďte zrušení dávky v bance.', 422);
        }
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) return Json::sessionRequired($response);
        if (!$this->service->archiveAfterBankCancellation($id, $supplierId, $userId)) {
            return Json::error($response, 'payment_order_archive_unavailable', 'Příkaz nelze archivovat.', 409);
        }
        $this->logger->log('payment_order.archived', $userId, 'payment_order', $id,
            ['bank_cancellation_confirmed_by_user' => true, 'bank_cancellation_verified_by_api' => false],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'), $supplierId);
        return $response->withStatus(204);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if ($err = $this->denied($request, $response, AccessLevel::WRITE)) return $err;
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $result = $this->service->delete($id, $supplierId);
        if ($result === 'not_found') return Json::error($response, 'not_found', 'Platební příkaz nenalezen.', 404);
        if ($result === 'submitted') return Json::error($response, 'payment_order_delete_protected', 'Příkaz s evidovaným pokusem o odeslání do banky nelze smazat.', 409);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $this->logger->log('payment_order.deleted', (int) ($user['id'] ?? 0) ?: null, 'payment_order', $id, [],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'), $supplierId);
        return $response->withStatus(204);
    }

    public function __construct(
        private readonly PaymentOrderService $service,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** Dokontrola konkrétního práva (ne modulového 'purchase_invoices'). */
    private function denied(Request $request, Response $response, AccessLevel $minimum): ?Response
    {
        if (RequestAuthorization::allows($request, self::PERMISSION, $minimum)) {
            return null;
        }
        return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
    }

    /** GET candidates + payer accounts (stránkovaně). */
    public function candidates(Request $request, Response $response): Response
    {
        if ($err = $this->denied($request, $response, AccessLevel::READ)) return $err;

        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $currency = $q['currency'] ?? null;
        $currency = is_string($currency) && $currency !== '' ? $currency : null;
        $p = Pagination::fromQuery($q, 50);

        // Opt-out z filtru „jen bankovní převod": inkasní faktury se do příkazu nedávají,
        // ale chybně označenou fakturu musí jít najít a opravit — jinak by z obrazovky
        // zmizela beze stopy a nikdo by ji nikdy nezaplatil.
        $raw = $q['include_non_transfer'] ?? null;
        $includeNonTransfer = in_array(is_string($raw) ? strtolower($raw) : $raw, ['1', 'true', 'yes', true], true);

        $result = $this->service->candidates($supplierId, $currency, $p['per_page'], $p['offset'], $includeNonTransfer);
        $envelope = Pagination::envelope($result['candidates'], $result['total'], $p['page'], $p['per_page']);
        $envelope['payer_accounts'] = $result['payer_accounts'];
        return Json::ok($response, $envelope);
    }

    /** POST — vytvoř (ulož) platební příkaz. */
    public function create(Request $request, Response $response): Response
    {
        if ($err = $this->denied($request, $response, AccessLevel::WRITE)) return $err;

        $supplierId = SupplierGuard::currentId($request);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0) ?: null;

        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $result = $this->service->create($supplierId, [
                'invoice_ids'       => (array) ($body['invoice_ids'] ?? []),
                'payer_currency_id' => (int) ($body['payer_currency_id'] ?? 0),
                'payment_date'      => (string) ($body['payment_date'] ?? ''),
                'constant_symbol'   => $body['constant_symbol'] ?? null,
                'note'              => $body['note'] ?? null,
                'mark_paid'         => (bool) ($body['mark_paid'] ?? false),
            ], $userId);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('payment_order.created', $userId, 'payment_order', $result['order_id'], [
            'item_count' => $result['view']['item_count'] ?? 0,
            'total'      => $result['view']['total_amount'] ?? 0,
            'currency'   => $result['view']['currency'] ?? null,
            'mark_paid'  => $result['view']['mark_paid'] ?? false,
            'skipped'    => count($result['skipped']),
        ], $ip, $request->getHeaderLine('User-Agent'), $supplierId);

        return Json::ok($response, $result, 201);
    }

    /** GET — on-demand kontrola účtu faktury proti zveřejněným účtům plátce DPH (CRPDPH). */
    public function verifyAccount(Request $request, Response $response): Response
    {
        if ($err = $this->denied($request, $response, AccessLevel::READ)) return $err;

        $supplierId = SupplierGuard::currentId($request);
        $invoiceId = (int) ($request->getQueryParams()['invoice_id'] ?? 0);
        $res = $this->service->verifyInvoiceAccount($supplierId, $invoiceId);
        if ($res === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }
        return Json::ok($response, $res);
    }

    /** POST — „Jen označit": zařadit vybrané faktury k úhradě bez exportu. */
    public function markOrdered(Request $request, Response $response): Response
    {
        if ($err = $this->denied($request, $response, AccessLevel::WRITE)) return $err;

        $supplierId = SupplierGuard::currentId($request);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0) ?: null;

        $body = (array) ($request->getParsedBody() ?? []);
        $ids = (array) ($body['invoice_ids'] ?? []);
        if ($ids === []) {
            return Json::error($response, 'validation_failed', 'Není vybrána žádná faktura.', 422);
        }
        $count = $this->service->markOrdered($supplierId, $ids, (bool) ($body['mark_paid'] ?? false));

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('payment_order.marked', $userId, null, null, [
            'count'     => $count,
            'mark_paid' => (bool) ($body['mark_paid'] ?? false),
        ], $ip, $request->getHeaderLine('User-Agent'), $supplierId);

        return Json::ok($response, ['count' => $count]);
    }

    /** GET — historie dávek (stránkovaně). */
    public function history(Request $request, Response $response): Response
    {
        if ($err = $this->denied($request, $response, AccessLevel::READ)) return $err;

        $supplierId = SupplierGuard::currentId($request);
        $p = Pagination::fromQuery($request->getQueryParams(), 50);
        [$rows, $total] = $this->service->history($supplierId, $p['per_page'], $p['offset']);
        return Json::ok($response, Pagination::envelope($rows, $total, $p['page'], $p['per_page']));
    }

    /** GET — detail dávky (vč. položek). */
    public function show(Request $request, Response $response, array $args): Response
    {
        if ($err = $this->denied($request, $response, AccessLevel::READ)) return $err;

        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $view = $this->service->view($id, $supplierId);
        if ($view === null) {
            return Json::error($response, 'not_found', 'Platební příkaz nenalezen.', 404);
        }
        return Json::ok($response, $view);
    }

    /** GET — stažení souboru (csv/pdf/abo). */
    public function download(Request $request, Response $response, array $args): Response
    {
        if ($err = $this->denied($request, $response, AccessLevel::READ)) return $err;

        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $format = strtolower((string) ($request->getQueryParams()['format'] ?? 'abo'));

        try {
            $file = $this->service->download($id, $supplierId, $format);
        } catch (\RuntimeException $e) {
            return Json::error($response, 'export_failed', $e->getMessage(), 422);
        }
        if ($file === null) {
            return Json::error($response, 'not_found', 'Platební příkaz nenalezen.', 404);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('payment_order.exported', null, 'payment_order', $id, [
            'format' => $format,
        ], $ip, $request->getHeaderLine('User-Agent'), $supplierId);

        $response->getBody()->write($file['bytes']);
        return $response
            ->withHeader('Content-Type', $file['content_type'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $file['filename'] . '"');
    }
}
