<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail;

/** Synthetic values only; preview must never load real supplier/client/document data. */
final class EmailTemplatePreviewData
{
    public static function variables(string $locale): array
    {
        return [
            'locale' => $locale, 'subject' => 'Ukázková zpráva / Example message',
            'supplier' => ['id' => 0, 'company_name' => 'Ukázková firma s.r.o.', 'display_name' => 'Ukázková firma',
                'street' => 'Ukázková 12', 'city' => 'Ukázkové město', 'zip' => '10000', 'country' => 'CZ',
                'email' => 'firma@example.test', 'phone' => '000 000 000', 'web' => 'https://firma.example.test', 'email_branding_enabled' => false],
            'invoice' => ['varsymbol' => '20260001', 'varsymbol_or_id' => '20260001', 'currency' => 'CZK',
                'issue_date' => '2026-01-15', 'due_date' => '2026-01-29', 'total_with_vat' => 1210,
                'client_company_name' => 'Ukázkový odběratel', 'project_name' => 'Ukázková zakázka',
                'bank_account' => '1000000005/0100', 'invoice_type' => 'invoice'],
            'work_report' => ['title' => 'Ukázkový výkaz', 'total_hours' => 4, 'material_total' => 100, 'total_amount' => 1000, 'material_title' => 'Materiál'],
            'greeting' => $locale === 'en' ? 'Hello,' : 'Dobrý den,', 'intro_prefix' => $locale === 'en' ? 'Please find your invoice' : 'Posíláme fakturu',
            'quote_number' => 'NAB-2026-001', 'quote_title' => 'Ukázková nabídka',
            'message' => 'Dobrý den, v příloze posíláme ukázkovou nabídku.',
            'client_name' => 'Ukázkový odběratel', 'amount_to_pay' => 1210, 'paid_amount' => 1210,
            'days_overdue' => 7, 'payment_varsymbol' => '20260001', 'payment_method' => 'bank_transfer',
            'paid_at' => '2026-01-20', 'amount_paid' => 1210, 'is_proforma' => false, 'public_url' => 'https://preview.example.test/invoice/demo',
            'is_paid' => false, 'is_test' => false, 'is_reminder' => false, 'accent_soft' => '#F1EEF8', 'accent' => '#3B2D83',
            'approval_url' => 'https://preview.example.test/approve/synthetic-token',
            'tracking_url' => 'https://preview.example.test/work-report/synthetic-token',
            'code' => '583194', 'expiresIn' => '15 min', 'scope' => 'project', 'project_name' => 'Ukázková zakázka',
            'client_company_name' => 'Ukázkový odběratel', 'note' => 'Ukázková poznámka', 'note_lines' => ['Ukázková poznámka'],
            'company_name' => 'Ukázková firma', 'supplier_name' => 'Ukázková firma', 'vendor_name' => 'Ukázkový dodavatel',
            'document_number' => 'DEMO-2026-001', 'dimension_type' => 'Středisko', 'dimension_value' => 'Ukázkové středisko',
            'approver_name' => 'Ukázkový schvalovatel', 'total_without_vat' => 1000, 'total_with_vat' => 1210, 'amount_czk' => 1000, 'currency' => 'CZK',
            'issue_date' => '2026-01-15', 'due_date' => '2026-01-29', 'valid_until' => '2026-02-15',
            'inbox_url' => 'https://preview.example.test/inbox', 'description' => 'Ukázkový chybějící doklad',
            'amount' => 1210, 'deadline' => '2026-01-29', 'requested_days_ago' => 7,
            'portal_link' => 'https://preview.example.test/portal', 'template_name' => 'Ukázková pravidelná faktura',
            'work_item_count' => 3, 'edit_link' => 'https://preview.example.test/invoices/1',
        ];
    }
}
