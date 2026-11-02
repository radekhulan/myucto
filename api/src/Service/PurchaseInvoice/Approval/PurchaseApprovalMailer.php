<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice\Approval;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionValueLabel;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Mail\SupplierEmailContext;
use MyInvoice\Service\Tenant\TenantUrlResolver;

/**
 * E-mail schvalovateli: žádost o schválení přijatého dokladu a připomínka.
 *
 * Token existuje v čitelné podobě jen tady — vloží se do odkazu a zahodí. Volající
 * má v DB jen jeho SHA-256. Odkaz vede na veřejnou stránku `/purchase-approval/{token}`
 * (bez přihlášení), schránka v aplikaci je `/purchase-approvals`.
 */
final class PurchaseApprovalMailer implements PurchaseApprovalNotifier
{
    public const TEMPLATE_CODE = 'purchase_invoice_approval';

    public function __construct(
        private readonly Mailer $mailer,
        private readonly Connection $db,
        private readonly TenantUrlResolver $tenantUrls,
    ) {}

    /**
     * @param array<string,mixed> $row řádek z PurchaseInvoiceApprovalRepository (s údaji dokladu)
     */
    public function send(array $row, string $token, bool $isReminder): void
    {
        $email = trim((string) ($row['approver_email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new PurchaseApprovalException('approval_no_email', 'Schvalovatel nemá platný e-mail.');
        }
        $supplierId = (int) $row['supplier_id'];
        $supplier = (new SupplierEmailContext($this->db))->forSupplier($supplierId);
        $company = $supplier !== null
            ? (string) (($supplier['display_name'] ?? '') ?: ($supplier['company_name'] ?? ''))
            : '';
        $number = (string) (($row['invoice_vendor_number'] ?? '') ?: (($row['invoice_varsymbol'] ?? '') ?: ('#' . $row['purchase_invoice_id'])));
        $vendor = (string) ($row['invoice_vendor_name'] ?? '');

        $subject = ($isReminder ? 'Připomínka: ' : '') . 'Doklad ke schválení: ' . $vendor . ' ' . $number;
        if ($company !== '') {
            $subject .= ' (' . $company . ')';
        }

        $this->mailer->sendTemplate(
            self::TEMPLATE_CODE,
            'cs',
            [$email],
            [
                'subject' => $subject,
                'supplier' => $supplier,
                'locale' => 'cs',
                'is_reminder' => $isReminder,
                'approver_name' => (string) ($row['approver_name'] ?? ''),
                'company_name' => $company,
                'document_number' => $number,
                'vendor_name' => $vendor,
                'issue_date' => $row['invoice_issue_date'] ?? null,
                'due_date' => $row['invoice_due_date'] ?? null,
                'currency' => (string) ($row['invoice_currency'] ?? 'CZK'),
                'total_without_vat' => (float) ($row['invoice_total_without_vat'] ?? 0),
                'total_with_vat' => (float) ($row['invoice_total_with_vat'] ?? 0),
                'amount_czk' => (float) ($row['amount_czk'] ?? 0),
                'dimension_type' => (string) ($row['dimension_type_name'] ?? ''),
                'dimension_value' => DimensionValueLabel::full(['code' => $row['dimension_value_code'] ?? '', 'name' => $row['dimension_value_name'] ?? '', 'semantic_key' => $row['dimension_value_semantic_key'] ?? null]),
                'valid_until' => $row['token_expires_at'] ?? null,
                'approval_url' => $this->tenantUrls->urlFor($supplierId, 'public_links', '/purchase-approval/' . $token),
                'inbox_url' => rtrim($this->tenantUrls->canonicalBaseUrl(), '/') . '/purchase-approvals',
            ],
            templateSupplierId: $supplierId,
        );
    }
}
