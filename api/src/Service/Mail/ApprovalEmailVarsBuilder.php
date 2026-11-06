<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\WorkReportRepository;
use MyInvoice\Service\Tenant\TenantUrlResolver;

/**
 * Sestavuje template variables pro invoice_approval.{cs|en}.{html|txt}.twig.
 */
final class ApprovalEmailVarsBuilder
{
    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        private readonly WorkReportRepository $workReports,
        private readonly ?TenantUrlResolver $tenantUrls = null,
    ) {}

    /**
     * @param string $approvalToken  Token uložený v invoices.approval_token
     * @param bool   $isTest         True = TEST odeslání, používá dummy token v URL
     * @param bool   $isReminder     True = upomínka (cron-send-approval-reminders), jiný subject + nadpis
     */
    public function build(
        array $invoice,
        string $approvalToken,
        bool $isTest,
        string $locale,
        bool $isReminder = false,
    ): array {
        $approvalUrl = $this->tenantUrls !== null
            ? $this->tenantUrls->forSupplier(
                (int) ($invoice['supplier_id'] ?? 0),
                'public_links',
                '/approval/' . $approvalToken,
            )
            : rtrim((string) $this->config->get('app.url', ''), '/') . '/approval/' . $approvalToken;

        $varsymbolOrId = $invoice['varsymbol'] ?: ('DRAFT-' . $invoice['id']);
        // Doplň pole, které se používá ve šabloně (subject + filename)
        $invoice['varsymbol_or_id'] = $varsymbolOrId;

        $supplierName = $this->resolveSupplierName($invoice);
        $subject = self::buildSubject($varsymbolOrId, $supplierName, $locale, $isTest, $isReminder);

        return [
            'invoice'       => $invoice,
            'work_report'   => $this->workReports->findByInvoice((int) $invoice['id'], (int) ($invoice['supplier_id'] ?? 0)),
            'approval_url'  => $approvalUrl,
            'is_test'       => $isTest,
            'is_reminder'   => $isReminder,
            'subject'       => $subject,
            'supplier'      => $this->loadSupplierFooter($invoice),
        ];
    }

    /**
     * Pure function — buduje subject string ze vstupních parametrů.
     * Public/static aby se dalo otestovat bez DB/Config dependencies.
     */
    public static function buildSubject(
        string $varsymbolOrId,
        string $supplierName,
        string $locale,
        bool $isTest,
        bool $isReminder,
    ): string {
        if ($isReminder) {
            $subject = $locale === 'en'
                ? "Reminder: please approve work report ({$varsymbolOrId})"
                : "Připomínka: žádost o schválení výkazu ({$varsymbolOrId})";
        } else {
            $subject = $locale === 'en'
                ? "Work report — please approve ({$varsymbolOrId})"
                : "Žádost o schválení výkazu práce ({$varsymbolOrId})";
        }
        if ($isTest) $subject = '[TEST] ' . $subject;
        if ($supplierName !== '') $subject .= " — {$supplierName}";
        return $subject;
    }

    private function resolveSupplierName(array $invoice): string
    {
        if (!empty($invoice['supplier_snapshot'])) {
            $snap = is_string($invoice['supplier_snapshot'])
                ? json_decode($invoice['supplier_snapshot'], true)
                : $invoice['supplier_snapshot'];
            if (is_array($snap)) {
                return (string) ($snap['display_name'] ?: ($snap['company_name'] ?? ''));
            }
        }
        $sid = (int) ($invoice['supplier_id'] ?? 0);
        if ($sid <= 0) return '';
        $stmt = $this->db->pdo()->prepare('SELECT COALESCE(display_name, company_name) FROM supplier WHERE id = ?');
        $stmt->execute([$sid]);
        return (string) ($stmt->fetchColumn() ?: '');
    }

    /**
     * Dřív si tenhle builder tahal jen „patičkovou" podmnožinu sloupců — bez
     * `id`, `email_branding_enabled`, `email_accent_color` a `logo_path`. Layout
     * pak vykreslil výchozí hlavičku MyÚčto.cz místo dodavatele a Mailer ani
     * nepřipojil logo. Sdílený kontext vrací totéž co u odeslání faktury.
     */
    private function loadSupplierFooter(array $invoice): ?array
    {
        return (new SupplierEmailContext($this->db))->forInvoice($invoice);
    }
}
