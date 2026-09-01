<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\SupplierPaymentQrSettingsRepository;
use MyInvoice\Service\Invoice\InvoicePublicLinkService;
use MyInvoice\Service\Qr\PaymentQrDueDate;
use MyInvoice\Service\Qr\QrPaymentGenerator;

/**
 * Sestavuje template variables pro invoice_send.{cs|en}.{html|txt}.twig.
 * Používá se z SendEmailAction i SendTestEmailAction.
 */
final class InvoiceEmailVarsBuilder
{
    public function __construct(
        private readonly Connection $db,
        private readonly QrPaymentGenerator $qr,
        private readonly InvoicePublicLinkService $publicLinks,
        private readonly SupplierPaymentQrSettingsRepository $paymentQrSettings,
    ) {}

    /**
     * Variables pro `invoice_reminder.{locale}.{html,txt}.twig` resp.
     * `proforma_reminder.{locale}.{html,txt}.twig` (podle invoice_type).
     * Stejný shape jako build() + extra `days_overdue` pro template.
     */
    public function buildReminder(array $invoice, int $daysOverdue, string $locale): array
    {
        $varsymbol = (string) ($invoice['varsymbol'] ?? '');
        $supplier = $this->resolveSupplierName($invoice, false);
        $isProforma = ($invoice['invoice_type'] ?? '') === 'proforma';

        if ($locale === 'en') {
            $subject = $isProforma
                ? "Reminder — proforma {$varsymbol} is {$daysOverdue} day" . ($daysOverdue === 1 ? '' : 's') . ' overdue'
                : "Reminder — invoice {$varsymbol} is {$daysOverdue} day" . ($daysOverdue === 1 ? '' : 's') . ' overdue';
        } else {
            $dayWord = $daysOverdue === 1 ? 'den' : ($daysOverdue < 5 ? 'dny' : 'dní');
            $subject = $isProforma
                ? "Připomínka — záloha {$varsymbol} je {$daysOverdue} {$dayWord} po splatnosti"
                : "Upomínka — faktura {$varsymbol} je {$daysOverdue} {$dayWord} po splatnosti";
        }
        if ($supplier !== '') {
            $subject .= " — {$supplier}";
        }

        return [
            'invoice'        => $invoice,
            'client_name'    => $invoice['client_company_name'] ?? '',
            // Upomínka po částečné úhradě (#89) chce jen zbývající dluh.
            'amount_to_pay'  => round(
                (float) ($invoice['amount_to_pay'] ?? $invoice['total_with_vat']) - (float) ($invoice['paid_total'] ?? 0),
                2,
            ),
            'days_overdue'   => $daysOverdue,
            'subject'        => $subject,
            'qr_data_uri'    => $this->paymentQrDataUri($invoice),
            'supplier'       => $this->loadSupplierFooter($invoice),
            'is_test'        => false,
            'is_paid'        => ($invoice['status'] ?? '') === 'paid',
            'payment_method' => (string) ($invoice['payment_method'] ?? 'bank_transfer'),
        ];
    }

    public function build(array $invoice, bool $isTest, string $locale): array
    {
        $type = (string) $invoice['invoice_type'];
        $varsymbol = (string) ($invoice['varsymbol'] ?? '');
        // Částka k úhradě v e-mailu = zbývající dluh — částečné úhrady (#89) se odečítají
        // (upomínka po částečné platbě musí chtít jen zbytek).
        $amount = round(
            (float) ($invoice['amount_to_pay'] ?? $invoice['total_with_vat']) - (float) ($invoice['paid_total'] ?? 0),
            2,
        );

        $typeLabel = match ($type) {
            'proforma'     => $locale === 'en' ? 'proforma invoice' : 'zálohovou fakturu',
            'credit_note'  => $locale === 'en' ? 'credit note' : 'opravný daňový doklad',
            'tax_document' => $locale === 'en' ? 'tax document for payment received' : 'daňový doklad k přijaté platbě',
            default        => $locale === 'en' ? 'invoice' : 'fakturu',
        };

        // Pozn.: dříve se `intro` skládal s embedovaným <strong>č. {VS}</strong> a v šabloně
        // se renderoval `{{ intro|raw }}` — to bypassovalo Twig autoescape a umožnilo HTML
        // injection přes varsymbol importovaný z ISDOC/Pohoda (security report @andrejtomci
        // #3). Teď posíláme `intro_prefix` jako plain text + varsymbol jako separátní vars
        // ve šablonách (kde projde autoescape). `intro` ponecháno pro zpětnou kompatibilitu
        // s případnými custom šablonami v supplier_email_templates, ale šablony v repu už
        // ho nepoužívají.
        if ($locale === 'en') {
            $greeting = 'Hello,';
            $intro_prefix = "we're sending you {$typeLabel}";
            $intro_plain = "we're sending you {$typeLabel} No. {$varsymbol}.";
        } else {
            $greeting = 'Dobrý den,';
            $intro_prefix = "v příloze posíláme {$typeLabel}";
            $intro_plain = "v příloze posíláme {$typeLabel} č. {$varsymbol}.";
        }
        // Legacy `intro` value — autoescape-safe (žádné raw <strong>); custom email
        // template overrides v DB mohou používat `{{ intro }}` (bez |raw) a dostanou
        // escapovaný text. Nikdy nepoužívat `{{ intro|raw }}` v nových šablonách.
        $intro = $intro_plain;

        return [
            'greeting'       => $greeting,
            'intro'          => $intro,
            'intro_prefix'   => $intro_prefix,
            'intro_plain'    => $intro_plain,
            'invoice'        => $invoice,
            'client_name'    => $invoice['client_company_name'] ?? '',
            'amount_to_pay'  => $amount,
            'is_test'        => $isTest,
            'subject'        => $this->buildSubject($invoice, $isTest, $locale),
            'qr_data_uri'    => $this->paymentQrDataUri($invoice),
            'supplier'       => $this->loadSupplierFooter($invoice),
            'is_paid'        => ($invoice['status'] ?? '') === 'paid',
            'payment_method' => (string) ($invoice['payment_method'] ?? 'bank_transfer'),
            // Trvalý odkaz na web fakturu do e-mailu; token vzniká lazy při
            // prvním odeslání. Null pro draft (test e-mail) a bez app.url.
            'public_url'     => $this->publicLinks->ensureUrl($invoice),
        ];
    }

    /**
     * QR platba pro zbývající částku — public: kromě e-mailů ho používá i veřejná
     * web faktura (PublicInvoiceGetAction). Bank z snapshot (issued+) nebo live
     * z currencies; null když není co platit / jiná platební metoda.
     */
    public function paymentQrDataUri(array $invoice): ?string
    {
        // QR na zbývající částku — po částečné úhradě (#89) se platí jen zbytek.
        $remaining = round((float) ($invoice['amount_to_pay'] ?? 0) - (float) ($invoice['paid_total'] ?? 0), 2);
        if (empty($invoice['varsymbol'])) return null;
        if ($remaining <= 0) return null;
        if (($invoice['status'] ?? '') === 'paid') return null;
        if (($invoice['payment_method'] ?? 'bank_transfer') !== 'bank_transfer') return null;

        // Bank z snapshot (issued+) nebo live z currencies
        $bank = null;
        if (!empty($invoice['bank_snapshot'])) {
            $snap = is_string($invoice['bank_snapshot']) ? json_decode($invoice['bank_snapshot'], true) : $invoice['bank_snapshot'];
            if (is_array($snap)) $bank = $snap;
        }
        if ($bank === null && !empty($invoice['currency_id'])) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT account_number, bank_code, bank_name, iban, bic FROM currencies WHERE id = ?'
            );
            $stmt->execute([(int) $invoice['currency_id']]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) $bank = $row;
        }
        if ($bank === null) return null;

        $supplierName = $this->resolveSupplierName($invoice, true);
        $qrSettings = $this->paymentQrSettings->find((int) ($invoice['supplier_id'] ?? 0));

        return $this->qr->generate(
            (string) $invoice['currency'],
            $remaining,
            (string) $invoice['varsymbol'],
            $bank,
            (string) ($supplierName ?: 'MyÚčto.cz'),
            PaymentQrDueDate::parse($invoice['due_date'] ?? null),
            includeDueDate: (bool) ($qrSettings[SupplierPaymentQrSettingsRepository::INVOICE_FIELD] ?? false),
        );
    }

    private function buildSubject(array $invoice, bool $isTest, string $locale): string
    {
        $varsymbol = $invoice['varsymbol'] ?? '';
        $supplier = $this->resolveSupplierName($invoice, false);
        $prefix = $isTest ? '[TEST] ' : '';
        $type = (string) ($invoice['invoice_type'] ?? 'invoice');

        // Předmět odpovídá typu dokladu (stejně jako text v těle e-mailu) —
        // zálohová faktura ani opravný daňový doklad nejsou „Faktura".
        if ($locale === 'en') {
            $label = match ($type) {
                'proforma'    => 'Proforma invoice',
                'credit_note' => 'Credit note',
                default       => 'Invoice',
            };
        } else {
            $label = match ($type) {
                'proforma'    => 'Zálohová faktura',
                'credit_note' => 'Opravný daňový doklad',
                default       => 'Faktura',
            };
        }

        return "{$prefix}{$label} {$varsymbol}" . ($supplier ? " — {$supplier}" : '');
    }

    /**
     * Vrátí jméno supplier pro fakturu — preferuje snapshot (issued+),
     * fallback live `supplier` tabulka přes invoice.supplier_id.
     * $preferDisplayName=true → COALESCE(display_name, company_name) (vhodné pro QR jméno odesílatele).
     */
    private function resolveSupplierName(array $invoice, bool $preferDisplayName): string
    {
        // 1. Snapshot (immutable po vystavení)
        if (!empty($invoice['supplier_snapshot'])) {
            $snap = is_string($invoice['supplier_snapshot'])
                ? json_decode($invoice['supplier_snapshot'], true)
                : $invoice['supplier_snapshot'];
            if (is_array($snap)) {
                if ($preferDisplayName) {
                    return (string) ($snap['display_name'] ?: ($snap['company_name'] ?? ''));
                }
                return (string) ($snap['company_name'] ?? '');
            }
        }
        // 2. Live lookup přes supplier_id
        $sid = (int) ($invoice['supplier_id'] ?? 0);
        if ($sid <= 0) return '';
        $col = $preferDisplayName ? 'COALESCE(display_name, company_name)' : 'company_name';
        $stmt = $this->db->pdo()->prepare("SELECT $col FROM supplier WHERE id = ?");
        $stmt->execute([$sid]);
        return (string) ($stmt->fetchColumn() ?: '');
    }

    /**
     * Vrátí kompletní supplier kontext pro patičku emailu (podle invoice.supplier_id).
     * Logika žije v SupplierEmailContext — sdílí ji i ApprovalEmailVarsBuilder.
     */
    private function loadSupplierFooter(array $invoice): ?array
    {
        return (new SupplierEmailContext($this->db))->forInvoice($invoice);
    }
}
