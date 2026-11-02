<?php

declare(strict_types=1);

namespace MyInvoice\Service\Dashboard;

use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\LicenseMiddleware;
use MyInvoice\Service\License\LicenseConsistency;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Pojmenovaná pravidla manifestu včetně dílčích akcí; frontend matici práv neopisuje. */
final class DashboardWidgetAccess
{
    public const RULES = ['base', 'invoices.read', 'purchases.read', 'clients.invoices.read',
        'invoices.approval.read', 'documents.requests', 'actions.quick',
        'reports.read', 'portfolio.read', 'accounting.status'];
    public const SCOPES = ['company', 'vat_payer', 'self_employed', 'double_entry'];

    public function __construct(private readonly Connection $db, private readonly DashboardWidgetRegistry $registry, private readonly LicenseConsistency $consistency) {}

    public function supplier(Request $request): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, is_vat_payer, taxpayer_type, accounting_mode,
            (EXISTS (SELECT 1 FROM invoices activity_invoice WHERE activity_invoice.supplier_id=supplier.id)
                OR EXISTS (SELECT 1 FROM purchase_invoices activity_purchase WHERE activity_purchase.supplier_id=supplier.id)) AS has_documents
            FROM supplier WHERE id = ?');
        $stmt->execute([SupplierGuard::currentId($request)]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    public function allows(Request $request, array $widget, array $supplier): bool
    {
        if ((int) ($supplier['id'] ?? 0) <= 0 || (int) $supplier['id'] !== SupplierGuard::currentId($request)) return false;
        if (in_array($widget['type'], ['tax.net', 'tax.advances', 'tax.calendar', 'accounting.status'], true)
            && !$this->commercialAvailable($request)) return false;
        $scope = match ($widget['scope']) {
            'company' => true,
            'vat_payer' => (bool) ($supplier['is_vat_payer'] ?? false),
            'self_employed' => ($supplier['taxpayer_type'] ?? '') === 'fo',
            'double_entry' => ($supplier['accounting_mode'] ?? '') === 'double_entry',
            default => false,
        };
        if (!$scope) return false;
        return match ($widget['accessRule']) {
            'base' => true,
            'invoices.read' => RequestAuthorization::allows($request, 'invoices'),
            'purchases.read' => RequestAuthorization::allows($request, 'purchase_invoices'),
            'clients.invoices.read' => RequestAuthorization::allows($request, 'invoices') && RequestAuthorization::allows($request, 'clients'),
            'invoices.approval.read' => RequestAuthorization::allows($request, 'invoices.approval'),
            'documents.requests', 'actions.quick', 'accounting.status' => in_array(true, $this->capabilities($request, $widget['type']), true),
            // Úplný feed i odkládání používají práva původních /api/crm/action-items rout.
            'portfolio.read' => RequestAuthorization::allows($request, 'dashboard.portfolio'),
            'reports.read' => RequestAuthorization::allows($request, 'reports'),
            default => false,
        };
    }

    public function capabilities(Request $request, string $type): array
    {
        $read = static fn (string $key): bool => RequestAuthorization::allows($request, $key);
        $write = static fn (string $key): bool => RequestAuthorization::allows($request, $key, AccessLevel::WRITE);
        return match ($type) {
            'actions.quick' => ['invoiceCreate' => $write('invoices.create'), 'purchaseCreate' => $write('purchase_invoices.create'), 'clientCreate' => $write('clients.create')],
            'actions.todo' => ['dismiss' => $write('dashboard.portfolio')],
            'documents.requests' => ['clientView' => RequestAuthorization::allowsClientDocumentPortal($request),
                'internalView' => !RequestAuthorization::isClientType($request) && $read('documents.requests')],
            'accounting.status' => ['postDocuments' => $write('accounting.periods.manage') && $this->commercialAvailable($request),
                'bankStatus' => $read('accounting') && $this->commercialAvailable($request), 'automation' => $read('accounting') && $this->commercialAvailable($request)],
            default => [],
        };
    }

    private function commercialAvailable(Request $request): bool
    {
        return LicenseMiddleware::state($request)?->hasCommercialFeatures() === true
            && $this->consistency->allowsCommercial();
    }

    public function catalog(Request $request, string $board, ?array $supplier = null): array
    {
        $supplier ??= $this->supplier($request);
        $widgets = [];
        foreach ($this->registry->all() as $widget) {
            if (!$this->allows($request, $widget, $supplier)) continue;
            // Pravidla a závislosti patří backendu; katalog předává vykreslovací metadata.
            $widgets[] = ['type' => $widget['type'], 'titleKey' => $widget['titleKey'],
                'descriptionKey' => $widget['descriptionKey'], 'options' => (object) $widget['options'],
                'capabilities' => (object) $this->capabilities($request, $widget['type'])];
        }
        return ['board' => $board, 'widgets' => $widgets,
            'businessActivity' => ['hasDocuments' => (bool) ($supplier['has_documents'] ?? false)]];
    }
}
