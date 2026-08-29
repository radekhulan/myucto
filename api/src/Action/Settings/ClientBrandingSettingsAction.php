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
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Úzký supplier-scoped výřez brandingu pro klientské role s
 * `settings.company = WRITE`. Záměrně nesdílí mass-assignment se SettingsAction.
 */
final class ClientBrandingSettingsAction
{
    /** @var list<string> */
    private const FIELDS = [
        'email_branding_enabled',
        'email_accent_color',
        'pdf_logo_show_name',
        'branding_profiles_enabled',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly InvoicePdfRenderer $pdf,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
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
        $unknown = array_values(array_diff(array_keys($body), self::FIELDS));
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

        if (array_key_exists('email_accent_color', $body)) {
            $color = strtoupper(trim((string) $body['email_accent_color']));
            if (preg_match('/^#[0-9A-F]{6}$/', $color) !== 1) {
                return Json::error($response, 'validation_failed', 'Barva musí mít formát #RRGGBB.', 400);
            }
            $body['email_accent_color'] = $color;
        }
        foreach (array_diff(self::FIELDS, ['email_accent_color']) as $field) {
            if (!array_key_exists($field, $body)) continue;
            if (!is_bool($body[$field]) && !in_array($body[$field], [0, 1], true)) {
                return Json::error(
                    $response,
                    'validation_failed',
                    'Přepínače musí mít logickou hodnotu.',
                    400,
                    ['fields' => [$field]],
                );
            }
        }

        $sets = [];
        $params = [];
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $body)) continue;
            $sets[] = $field . ' = ?';
            $params[] = $field === 'email_accent_color' ? $body[$field] : ((bool) $body[$field] ? 1 : 0);
        }
        $params[] = $supplierId;
        $stmt = $this->db->pdo()->prepare('UPDATE supplier SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($params);
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
                    email_branding_enabled, email_accent_color, pdf_logo_show_name,
                    branding_profiles_enabled, default_branding_profile_id, logo_path
               FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return Json::error($response, 'not_found', 'Supplier nenalezen.', 404);
        }

        $row['id'] = (int) $row['id'];
        $row['email_branding_enabled'] = (bool) $row['email_branding_enabled'];
        $row['pdf_logo_show_name'] = (bool) $row['pdf_logo_show_name'];
        $row['branding_profiles_enabled'] = (bool) $row['branding_profiles_enabled'];
        $row['default_branding_profile_id'] = $row['default_branding_profile_id'] !== null
            ? (int) $row['default_branding_profile_id']
            : null;
        $row['email_accent_color'] = (string) ($row['email_accent_color'] ?: '#3B2D83');
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
