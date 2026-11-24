<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\DemoReadOnlyMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Approval\ApprovalTokenValidator;
use MyInvoice\Service\Invoice\InvoiceIsdocDeliverySettings;
use MyInvoice\Service\Invoice\InvoiceIsdocService;
use MyInvoice\Service\Invoice\InvoicePublicLinkFeature;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Tenant\PublicTenantGuard;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** GET /api/public/invoice/{token}/isdoc — stejný rozsah tokenu a domény jako PDF. */
final class PublicInvoiceIsdocAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly InvoiceIsdocService $isdoc,
        private readonly InvoiceIsdocDeliverySettings $settings,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly PublicTenantGuard $tenantGuard,
        private readonly InvoicePublicLinkFeature $feature,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        if (!$this->feature->isEnabled()) {
            return Json::error($response, 'not_found', 'Tento odkaz není platný.', 404);
        }
        $token = (string) ($args['token'] ?? '');
        if (!ApprovalTokenValidator::isValidFormat($token)) {
            return Json::error($response, 'invalid_token', 'Neplatný odkaz.', 404);
        }
        $invoice = $this->repo->findByPublicToken($token, includeImportedCzk: true);
        if ($invoice === null) {
            return Json::error($response, 'token_invalid_or_expired', 'Tento odkaz není platný nebo byl zneplatněn.', 404);
        }
        if (!$this->tenantGuard->allows($request, (int) $invoice['supplier_id'])
            || !$this->settings->publicEnabled($invoice)) {
            return Json::error($response, 'not_found', 'Tento odkaz není platný.', 404);
        }

        try {
            $xml = $this->isdoc->document($invoice, persistRate: !DemoReadOnlyMiddleware::enabled($request));
        } catch (\Throwable) {
            return Json::error($response, 'isdoc_failed', 'ISDOC se nepodařilo vygenerovat.', 500);
        }
        $id = (int) $invoice['id'];
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if ((!is_array($user) || empty($user['id'])) && !DemoReadOnlyMiddleware::enabled($request)) {
            $this->repo->markPublicViewed($id);
            $this->logger->log('invoice.public_isdoc_downloaded', null, 'invoice', $id, [],
                $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
                $request->getHeaderLine('User-Agent'), (int) $invoice['supplier_id']);
        }
        $response->getBody()->write($xml);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . InvoiceIsdocService::filename($invoice) . '"')
            ->withHeader('Cache-Control', 'no-store');
    }
}
