<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\OperationalSettingsAccess;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\SafeLogoPath;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Supplier\SupplierBrandingSettings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Úzký supplier-scoped výřez brandingu s oprávněním
 * `settings.branding = WRITE`. Záměrně nesdílí mass-assignment se SettingsAction.
 */
final class ClientBrandingSettingsAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly InvoicePdfRenderer $pdf,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly SupplierBrandingSettings $brandingSettings,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        if (!OperationalSettingsAccess::branding($request)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění měnit provozní nastavení firmy.', 403);
        }
        return $this->respond($response, $this->supplierId($request));
    }

    public function update(Request $request, Response $response): Response
    {
        if (!OperationalSettingsAccess::branding($request)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění měnit provozní nastavení firmy.', 403);
        }

        $supplierId = $this->supplierId($request);
        if ($supplierId <= 0) {
            return Json::error($response, 'no_supplier', 'Žádný supplier scope.', 400);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $unknown = array_values(array_diff(array_keys($body), SupplierBrandingSettings::FIELDS));
        if ($unknown !== []) {
            return Json::error(
                $response,
                'field_not_delegable',
                'Toto nastavení nelze klientské roli delegovat.',
                403,
                ['fields' => $unknown],
            );
        }
        if ($body === []) {
            return $this->respond($response, $supplierId);
        }

        try {
            $body = SupplierBrandingSettings::validate($body);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }

        $sets = [];
        $params = [];
        foreach (SupplierBrandingSettings::FIELDS as $field) {
            if (!array_key_exists($field, $body)) continue;
            $sets[] = $field . ' = ?';
            $params[] = $body[$field];
        }
        $params[] = $supplierId;
        $stmt = $this->db->pdo()->prepare('UPDATE supplier SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $this->brandingSettings->persist($supplierId, $body, fn (): bool => $stmt->execute($params));
        if ($stmt->rowCount() === 0 && !$this->supplierExists($supplierId)) {
            return Json::error($response, 'not_found', 'Supplier nenalezen.', 404);
        }

        $this->pdf->invalidateDraftsBySupplier($supplierId);
        $this->log($request, $supplierId, array_keys($body));
        return $this->respond($response, $supplierId);
    }

    private function respond(Response $response, int $supplierId): Response
    {
        if ($supplierId <= 0) {
            return Json::error($response, 'no_supplier', 'Žádný supplier scope.', 400);
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, company_name, display_name, email, phone, web, tagline,
                    ' . implode(', ', SupplierBrandingSettings::FIELDS) . ', default_branding_profile_id, logo_path
               FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return Json::error($response, 'not_found', 'Supplier nenalezen.', 404);
        }

        $row['id'] = (int) $row['id'];
        $row = SupplierBrandingSettings::normalizeResponse($row);
        $row['default_branding_profile_id'] = $row['default_branding_profile_id'] !== null
            ? (int) $row['default_branding_profile_id']
            : null;
        $row['has_email_logo'] = SafeLogoPath::resolve($row['logo_path'] ?? null, $supplierId) !== null;

        return Json::ok($response, $row);
    }

    private function supplierId(Request $request): int
    {
        return (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
    }

    private function supplierExists(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return $stmt->fetchColumn() !== false;
    }

    /** @param list<string> $fields */
    private function log(Request $request, int $supplierId, array $fields): void
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $this->logger->log(
            'supplier.operational_branding_updated',
            isset($user['id']) ? (int) $user['id'] : null,
            'supplier',
            $supplierId,
            ['fields' => $fields],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
    }
}
