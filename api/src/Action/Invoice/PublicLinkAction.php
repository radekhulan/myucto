<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\InvoicePublicLinkFeature;
use MyInvoice\Service\Invoice\InvoicePublicLinkService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Správa veřejného odkazu „web faktura" (authenticated).
 *
 * POST /api/invoices/{id}/public-link            → ensure (lazy vytvoření) + URL
 * POST /api/invoices/{id}/public-link/regenerate → revokace = nový token
 *
 * Jen pro vystavené doklady (draft 409) — veřejná stránka drafty nezobrazuje,
 * odkaz na ně by byl mrtvý. Při vypnuté web faktuře (InvoicePublicLinkFeature)
 * 404 — token nevzniká ani se neregeneruje.
 */
final class PublicLinkAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly InvoicePublicLinkService $links,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly InvoicePublicLinkFeature $feature,
    ) {}

    public function ensure(Request $request, Response $response, array $args): Response
    {
        $invoice = $this->loadIssuedInvoice($request, $response, $args);
        if ($invoice instanceof Response) {
            return $invoice;
        }

        $token = (string) ($invoice['public_token'] ?? '');
        if ($token === '') {
            $token = $this->repo->ensurePublicToken((int) $invoice['id']);
            $this->log($request, 'invoice.public_link_created', (int) $invoice['id']);
        }

        return Json::ok($response, [
            'url'              => $this->links->url($token, (int) $invoice['supplier_id']),
            'token'            => $token,
            'public_viewed_at' => $invoice['public_viewed_at'] ?? null,
            'published_at'     => $invoice['public_published_at'] ?? null,
        ]);
    }

    public function regenerate(Request $request, Response $response, array $args): Response
    {
        $invoice = $this->loadIssuedInvoice($request, $response, $args);
        if ($invoice instanceof Response) {
            return $invoice;
        }

        $token = $this->repo->regeneratePublicToken((int) $invoice['id']);
        $this->log($request, 'invoice.public_link_regenerated', (int) $invoice['id']);

        return Json::ok($response, [
            'url'              => $this->links->url($token, (int) $invoice['supplier_id']),
            'token'            => $token,
            'public_viewed_at' => null,
            'published_at'     => $invoice['public_published_at'] ?? null,
        ]);
    }

    public function publish(Request $request, Response $response, array $args): Response
    {
        $invoice = $this->loadIssuedInvoice($request, $response, $args);
        if ($invoice instanceof Response) {
            return $invoice;
        }

        $supplierId = (int) $invoice['supplier_id'];
        $result = $this->repo->publishPublicLink($supplierId, (int) $invoice['id']);
        if ($result === null) {
            return Json::error($response, 'invalid_state', 'Stornovaný doklad ani koncept nelze zveřejnit.', 409);
        }
        if ($result['first']) {
            $this->log($request, 'invoice.public_link_published', (int) $invoice['id']);
        }

        return Json::ok($response, [
            'url' => $this->links->url($result['token'], $supplierId),
            'token' => $result['token'],
            'public_viewed_at' => $result['public_viewed_at'],
            'published_at' => $result['published_at'],
        ]);
    }

    /**
     * Ověří zapnutou web fakturu, načte fakturu a ověří vlastnictví
     * (SupplierGuard) + ne-draft stav.
     * Při neúspěchu vrací rovnou chybovou Response.
     */
    private function loadIssuedInvoice(Request $request, Response $response, array $args): array|Response
    {
        if (!$this->feature->isEnabled()) {
            return Json::error($response, 'public_links_disabled', 'Web faktura je na této instalaci vypnutá.', 404);
        }
        $invoice = $this->repo->find((int) ($args['id'] ?? 0));
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        if (($invoice['status'] ?? '') === 'draft') {
            return Json::error($response, 'invalid_state', 'Web faktura je dostupná až po vystavení dokladu.', 409);
        }
        return $invoice;
    }

    private function log(Request $request, string $action, int $invoiceId): void
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $this->logger->log($action, $user['id'] ?? null, 'invoice', $invoiceId, [],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'));
    }
}
