<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;

final class EmailTemplateCatalog
{
    public const CODES = ['invoice_send', 'invoice_payment_thanks', 'invoice_reminder', 'proforma_reminder', 'invoice_approval', 'purchase_invoice_approval', 'recurring_draft_reminder', 'document_request_reminder', 'password_reset', 'user_invite', 'user_sso_invite', 'login_otp', 'email_profile_test', 'work_report_link', 'work_report_access_code', 'payroll_document_secure_link', 'payroll_document_access_code'];
    public const LOCALES = ['cs', 'en'];

    public const SUPPLIER_CODES = ['invoice_send', 'invoice_payment_thanks', 'invoice_reminder', 'proforma_reminder', 'invoice_approval', 'purchase_invoice_approval', 'recurring_draft_reminder', 'document_request_reminder', 'work_report_link', 'work_report_access_code'];

    public function __construct(private readonly Config $config) {}

    public function isSupplierTemplate(string $code, string $locale): bool
    {
        return in_array($code, self::SUPPLIER_CODES, true) && in_array($locale, self::LOCALES, true);
    }

    public function defaults(string $code, string $locale): ?array
    {
        // Codes may include internal non-editable messages; never accept a path.
        if (preg_match('/^[a-z_]+$/D', $code) !== 1 || preg_match('/^[a-z]{2}$/D', $locale) !== 1) return null;
        $dir = Bootstrap::rootDir() . '/api/templates/email';
        $html = "$dir/$code.$locale.html.twig";
        $text = "$dir/$code.$locale.txt.twig";
        if (!is_file($html) || !is_file($text)) return null;
        return ['subject' => $this->editableDefaultSubject($code, $locale), 'body_html' => file_get_contents($html), 'body_text' => file_get_contents($text)];
    }

    /** @return array<string,string> Keys are stable variable paths, descriptions are fallback text. */
    public function variables(string $code): array
    {
        $common = ['supplier.company_name', 'supplier.display_name', 'supplier.email', 'supplier.phone', 'supplier.web', 'subject'];
        $invoice = ['invoice.varsymbol', 'invoice.currency', 'invoice.issue_date', 'invoice.due_date', 'client_name', 'amount_to_pay', 'payment_varsymbol', 'is_test', 'is_paid', 'payment_method', 'qr_data_uri'];
        $specific = match ($code) {
            'invoice_send' => [...$invoice, 'greeting', 'intro_prefix', 'intro', 'note_lines', 'paid_amount', 'refund_amount', 'public_url'],
            'invoice_payment_thanks' => [...$invoice, 'paid_at', 'amount_paid', 'currency', 'is_proforma'],
            'invoice_reminder', 'proforma_reminder' => [...$invoice, 'days_overdue'],
            'invoice_approval' => ['invoice.varsymbol_or_id', 'invoice.project_name', 'invoice.client_company_name', 'invoice.currency', 'approval_url', 'is_test', 'is_reminder', 'work_report.title', 'work_report.total_hours', 'work_report.total_amount', 'work_report.material_total', 'work_report.material_title'],
            'purchase_invoice_approval' => ['approval_url', 'inbox_url', 'is_reminder', 'approver_name', 'company_name', 'document_number', 'vendor_name', 'issue_date', 'due_date', 'currency', 'total_without_vat', 'total_with_vat', 'amount_czk', 'dimension_type', 'dimension_value', 'valid_until'],
            'recurring_draft_reminder' => ['template_name', 'client_name', 'issue_date', 'work_item_count', 'amount_to_pay', 'invoice.currency', 'edit_link'],
            'document_request_reminder' => ['supplier_name', 'description', 'amount', 'deadline', 'requested_days_ago', 'portal_link'],
            'work_report_link' => ['tracking_url', 'scope', 'client_company_name', 'project_name', 'note'],
            'work_report_access_code' => ['code', 'expiresIn'],
            default => [],
        };
        $vars = [];
        foreach (array_unique([...$common, ...$specific]) as $name) $vars[$name] = str_replace(['_', '.'], ' ', $name);
        return $vars;
    }

    public function requiredVariable(string $code): ?string
    {
        return match ($code) {
            'invoice_approval', 'purchase_invoice_approval' => 'approval_url',
            'work_report_link' => 'tracking_url',
            'work_report_access_code' => 'code',
            'document_request_reminder' => 'portal_link',
            'recurring_draft_reminder' => 'edit_link',
            default => null,
        };
    }

    public function fallbackSubject(string $code, string $locale): string
    {
        $app = (string) $this->config->get('app.name', 'MyÚčto.cz');
        $subjects = [
            'cs' => [
                'password_reset'    => 'Obnova hesla — ' . $app,
                'user_invite'       => 'Váš účet je připravený — ' . $app,
                'user_sso_invite'   => 'Přihlášení účtem organizace — ' . $app,
                'login_otp'         => 'Ověřovací kód pro přihlášení — ' . $app,
                'email_profile_test'=> 'Test odesílacího profilu — ' . $app,
                'invoice_send'      => 'Faktura — ' . $app,
                'invoice_payment_thanks' => 'Poděkování za úhradu — ' . $app,
                'invoice_reminder'  => 'Upomínka — ' . $app,
                'proforma_reminder' => 'Připomínka zálohy — ' . $app,
                'recurring_draft_reminder' => 'Koncept pravidelné faktury se brzy vystaví — ' . $app,
                'work_report_link'  => 'Náhled na výkaz práce — ' . $app,
                'work_report_access_code' => 'Ověřovací kód pro náhled výkazu práce — ' . $app,
                // Předmět vědomě neuvádí, o jaký dokument jde ani za jaké období:
                // předmět zprávy vidí i ten, kdo si jen prohlédne notifikaci na
                // uzamčené obrazovce nebo náhled ve firemní schránce.
                'payroll_document_secure_link' => 'Váš mzdový dokument — ' . $app,
                'payroll_document_access_code' => 'Ověřovací kód pro mzdový dokument — ' . $app,
            ],
            'en' => [
                'password_reset'    => 'Password reset — ' . $app,
                'user_invite'       => 'Your account is ready — ' . $app,
                'user_sso_invite'   => 'Sign in with your organisation account — ' . $app,
                'login_otp'         => 'Sign-in verification code — ' . $app,
                'email_profile_test'=> 'Sending profile test — ' . $app,
                'invoice_send'      => 'Invoice — ' . $app,
                'invoice_payment_thanks' => 'Thank you for your payment — ' . $app,
                'invoice_reminder'  => 'Reminder — ' . $app,
                'proforma_reminder' => 'Advance payment reminder — ' . $app,
                'recurring_draft_reminder' => 'Recurring invoice draft will be issued soon — ' . $app,
                'work_report_link'  => 'Work report preview — ' . $app,
                'work_report_access_code' => 'Verification code for work report preview — ' . $app,
                'payroll_document_secure_link' => 'Your payroll document — ' . $app,
                'payroll_document_access_code' => 'Verification code for your payroll document — ' . $app,
            ],
        ];
        return $subjects[$locale][$code] ?? ($subjects['cs'][$code] ?? $app);
    }
    public function editableDefaultSubject(string $code, string $locale): string
    {
        $cs = [
            'invoice_send'      => 'Faktura {{ invoice.varsymbol }}',
            'invoice_payment_thanks' => 'Děkujeme za úhradu faktury {{ invoice.varsymbol }}',
            'invoice_reminder'  => 'Upomínka — faktura {{ invoice.varsymbol }} ({{ days_overdue }} dní po splatnosti)',
            'proforma_reminder' => 'Připomínka — záloha {{ invoice.varsymbol }} ({{ days_overdue }} dní po splatnosti)',
            'invoice_approval'  => 'Žádost o schválení výkazu práce ({{ invoice.varsymbol_or_id }})',
            'purchase_invoice_approval' => '{{ is_reminder ? \'Připomínka: \' : \'\' }}Doklad ke schválení: {{ vendor_name }} {{ document_number }}',
            'recurring_draft_reminder' => 'Koncept pravidelné faktury se brzy vystaví ({{ issue_date }})',
            'document_request_reminder' => 'Chybí doklad — {{ description }}',
            'password_reset'    => 'Obnova hesla',
            'user_invite'       => 'Pozvánka nového uživatele',
            'user_sso_invite'   => 'Pozvánka k přihlášení účtem organizace',
            'login_otp'         => 'Ověřovací kód pro přihlášení',
            'email_profile_test'=> 'Test odesílacího profilu',
            'work_report_link'  => 'Náhled na výkaz práce — MyÚčto.cz',
            'work_report_access_code' => 'Ověřovací kód pro náhled výkazu práce — MyÚčto.cz',
            'payroll_document_secure_link' => 'Váš mzdový dokument — MyÚčto.cz',
            'payroll_document_access_code' => 'Ověřovací kód pro mzdový dokument — MyÚčto.cz',
        ];
        $en = [
            'invoice_send'      => 'Invoice {{ invoice.varsymbol }}',
            'invoice_payment_thanks' => 'Thank you for your payment — invoice {{ invoice.varsymbol }}',
            'invoice_reminder'  => 'Reminder — invoice {{ invoice.varsymbol }} ({{ days_overdue }} days overdue)',
            'proforma_reminder' => 'Reminder — proforma {{ invoice.varsymbol }} ({{ days_overdue }} days overdue)',
            'invoice_approval'  => 'Work report — please approve ({{ invoice.varsymbol_or_id }})',
            'purchase_invoice_approval' => '{{ is_reminder ? \'Reminder: \' : \'\' }}Document to approve: {{ vendor_name }} {{ document_number }}',
            'recurring_draft_reminder' => 'Recurring invoice draft will be issued soon ({{ issue_date }})',
            'document_request_reminder' => 'Missing document — {{ description }}',
            'password_reset'    => 'Password reset',
            'user_invite'       => 'New user invitation',
            'user_sso_invite'   => 'Invitation to sign in with your organisation account',
            'login_otp'         => 'Sign-in verification code',
            'email_profile_test'=> 'Sending profile test',
            'work_report_link'  => 'Work report preview — MyÚčto.cz',
            'work_report_access_code' => 'Verification code for work report preview — MyÚčto.cz',
            'payroll_document_secure_link' => 'Your payroll document — MyÚčto.cz',
            'payroll_document_access_code' => 'Verification code for your payroll document — MyÚčto.cz',
        ];
        return ($locale === 'en' ? $en : $cs)[$code] ?? '';
    }


}
