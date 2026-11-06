<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\TenantDomainMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Tenant\TenantDomainContext;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Firma povoluje klientovi spotřebu pouze vlastního vybraného AI poskytovatele. */
final class ClientAiExtractionPolicy
{
    public function __construct(
        private readonly Connection $db,
        private readonly LlmGatewayInterface $gateway,
    ) {}

    /** @return array{client_ai_extraction_enabled:bool,client_ai_extraction_available:bool} */
    public function supplierState(int $supplierId, ?bool $enabled = null): array
    {
        if ($enabled === null) {
            $stmt = $this->db->pdo()->prepare('SELECT client_ai_extraction_enabled FROM supplier WHERE id = ?');
            $stmt->execute([$supplierId]);
            $enabled = (bool) $stmt->fetchColumn();
        }
        return [
            'client_ai_extraction_enabled' => $enabled,
            // Gateway čte jen vybraného poskytovatele této firmy, bez globálního fallbacku.
            'client_ai_extraction_available' => $enabled && $this->gateway->getCredentials($supplierId) !== null,
        ];
    }

    public static function hasDocumentPermissions(Request $request): bool
    {
        return RequestAuthorization::allows($request, 'purchase_invoices.extract', AccessLevel::WRITE)
            && (!RequestAuthorization::isClientType($request)
                || (RequestAuthorization::allows($request, 'purchase_invoices.create', AccessLevel::WRITE)
                    && RequestAuthorization::allows($request, 'purchase_invoices', AccessLevel::WRITE)));
    }

    public static function canRouteAcrossSuppliers(Request $request): bool
    {
        $context = $request->getAttribute(TenantDomainMiddleware::ATTR_CONTEXT);
        // API token zůstává ve firmě, kterou integrace zvolila: doklad nesmí bez jejího
        // vědomí skončit v jiné firmě uživatele ani v jejích příchozích dokladech.
        return !RequestAuthorization::isClientType($request)
            && !RequestAuthorization::isBearerAuth($request)
            && !($context instanceof TenantDomainContext && $context->locksSupplier());
    }
}
