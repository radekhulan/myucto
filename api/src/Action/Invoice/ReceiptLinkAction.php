<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\InvoicePublicLinkFeature;
use MyInvoice\Service\Invoice\ReceiptLinkService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Správa veřejné účtenky zákazníka (authenticated, stejné právo jako web faktura).
 *
 *   POST   /api/invoices/{id}/receipt-link  nový odkaz /r/{token} (volitelně `ttl_days`)
 *   DELETE /api/invoices/{id}/receipt-link  odvolání všech platných odkazů dokladu
 *
 * Plný token se vrací jen v odpovědi na vytvoření; v databázi je jen jeho otisk.
 * Odkaz jde jen na vystavený a zaplacený doklad typu faktura (409 jinak).
 */
final class ReceiptLinkAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly ReceiptLinkService $links,
        private readonly InvoicePublicLinkFeature $feature,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function create(Request $request, Response $response, array $args): Response
    {
        $invoice = $this->load($request, $response, $args);
        if ($invoice instanceof Response) {
            return $invoice;
        }
        $reason = ReceiptLinkService::ineligibility($invoice);
        if ($reason !== null) {
            return Json::error($response, $reason, match ($reason) {
                'receipt_not_paid' => 'Účtenku pro zákazníka lze vytvořit jen k zaplacenému dokladu.',
                'receipt_invalid_type' => 'Účtenku pro zákazníka lze vytvořit jen k faktuře nebo zjednodušenému dokladu.',
                default => 'Účtenku pro zákazníka lze vytvořit až k vystavenému dokladu.',
            }, 409);
        }
        $body = $request->getParsedBody();
        $ttl = is_array($body) ? ($body['ttl_days'] ?? null) : null;
        if ($ttl !== null && (!is_int($ttl) || $ttl < 1 || $ttl > ReceiptLinkService::MAX_TTL_DAYS)) {
            return Json::error($response, 'validation_failed',
                'Platnost odkazu musí být 1 až ' . ReceiptLinkService::MAX_TTL_DAYS . ' dní.', 422, ['field' => 'ttl_days']);
        }
        $userId = $this->userId($request);
        $link = $this->links->create((int) $invoice['supplier_id'], (int) $invoice['id'], $userId, $ttl);
        $this->log($request, 'invoice.receipt_link_created', $invoice, ['expires_at' => $link['expires_at']]);
        return Json::ok($response, $link, 201);
    }

    public function revoke(Request $request, Response $response, array $args): Response
    {
        $invoice = $this->load($request, $response, $args);
        if ($invoice instanceof Response) {
            return $invoice;
        }
        $revoked = $this->links->revokeAll((int) $invoice['supplier_id'], (int) $invoice['id']);
        if ($revoked > 0) {
            $this->log($request, 'invoice.receipt_link_revoked', $invoice, ['revoked' => $revoked]);
        }
        return Json::ok($response, ['revoked' => $revoked]);
    }

    /** @return array<string,mixed>|Response */
    private function load(Request $request, Response $response, array $args): array|Response
    {
        if (!$this->feature->isEnabled()) {
            return Json::error($response, 'public_links_disabled', 'Veřejné odkazy jsou na této instalaci vypnuté.', 404);
        }
        $invoice = $this->repo->find((int) ($args['id'] ?? 0));
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        return $invoice;
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $id = (int) ($user['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /**
     * @param array<string,mixed> $invoice
     * @param array<string,mixed> $details
     */
    private function log(Request $request, string $action, array $invoice, array $details): void
    {
        $this->logger->log($action, $this->userId($request), 'invoice', (int) $invoice['id'], $details,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), (int) $invoice['supplier_id']);
    }
}
