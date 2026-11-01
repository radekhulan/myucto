<?php

declare(strict_types=1);

namespace MyInvoice\Action\Approval;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\WorkReportRepository;
use MyInvoice\Service\Approval\ApprovalTokenValidator;
use MyInvoice\Service\Mail\SafeLogoPath;
use MyInvoice\Service\Tenant\PublicTenantGuard;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/public/approval/{token}
 *
 * Veřejný (bez auth) endpoint — vrací data potřebná pro schvalovací stránku.
 *
 * Token je v invoices.approval_token a po rozhodnutí se nuluje, aby odkaz nešel
 * použít podruhé. Zůstane po něm ale SHA-256 v `approval_receipt_hash` (migrace
 * 1185), takže endpoint vrátí 200 se `state` = approved|rejected a stránka může
 * poděkovat místo červeného „Odkaz není platný" — schvalovatel se do e-mailu
 * běžně vrátí zkontrolovat, co odklikl, a varování u úspěšného schválení vypadá
 * jako porucha. Rozhodnout se přes hash nedá; decide hledá podle `approval_token`.
 *
 * Returns: { state, invoice: {minimal}, work_report, supplier_name, captcha_site_key }
 */
final class PublicApprovalGetAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly WorkReportRepository $workReports,
        private readonly Config $config,
        private readonly Connection $db,
        private readonly PublicTenantGuard $tenantGuard,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $token = (string) ($args['token'] ?? '');
        if (!ApprovalTokenValidator::isValidFormat($token)) {
            return Json::error($response, 'invalid_token', 'Neplatný odkaz.', 404);
        }

        $invoice = $this->repo->findByApprovalToken($token);

        // Zkonzumovaný odkaz — token je pryč, ale jeho hash zůstal. Držitel
        // odkazu je ten, kdo rozhodl, takže vlastní rozhodnutí (i důvod, který
        // sám napsal) mu smíme ukázat zpátky.
        if ($invoice === null) {
            $decided = $this->repo->findByApprovalReceipt($token);
            $status = (string) ($decided['approval_status'] ?? '');
            if ($decided !== null && ($status === 'approved' || $status === 'rejected')) {
                if (!$this->tenantGuard->allows($request, (int) $decided['supplier_id'])) {
                    return Json::error($response, 'not_found', 'Tento odkaz není platný.', 404);
                }
                return Json::ok($response, [
                    'state'            => $status,
                    'supplier_name'    => $this->resolveSupplierName($decided),
                    'branding'         => $this->branding($decided, $token),
                    'invoice'          => [
                        'varsymbol' => $decided['varsymbol'],
                        'language'  => $decided['language'],
                    ],
                    'decided_at'       => $decided['approval_decided_at'] ?? null,
                    'rejection_reason' => $status === 'rejected'
                        ? ($decided['approval_rejection_reason'] ?? null)
                        : null,
                ]);
            }
            return Json::error($response, 'token_invalid_or_expired',
                'Tento odkaz byl již použit nebo není platný.', 404);
        }

        if (!$this->tenantGuard->allows($request, (int) $invoice['supplier_id'])) {
            return Json::error($response, 'not_found', 'Tento odkaz není platný.', 404);
        }

        if ((string) ($invoice['approval_status'] ?? '') !== 'requested') {
            return Json::error($response, 'token_invalid_or_expired',
                'Tento odkaz byl již použit nebo není platný.', 404);
        }

        $workReport = $this->workReports->findByInvoice((int) $invoice['id'], (int) ($invoice['supplier_id'] ?? 0));
        if ($workReport === null) {
            return Json::error($response, 'no_work_report', 'Faktura nemá výkaz práce.', 404);
        }

        $supplierName = $this->resolveSupplierName($invoice);

        $publicInvoice = [
            'id'                  => $invoice['id'],
            'varsymbol'           => $invoice['varsymbol'],
            'invoice_type'        => $invoice['invoice_type'],
            'currency'            => $invoice['currency'],
            'language'            => $invoice['language'],
            'client_company_name' => $invoice['client_company_name'] ?? null,
            'project_name'        => $invoice['project_name'] ?? null,
            'total_with_vat'      => $invoice['total_with_vat'] ?? null,
            'amount_to_pay'       => $invoice['amount_to_pay'] ?? null,
            'requested_at'        => $invoice['approval_requested_at'] ?? null,
        ];

        return Json::ok($response, [
            'state'            => 'requested',
            'invoice'          => $publicInvoice,
            'work_report'      => \MyInvoice\Service\WorkReport\WorkReportDisplayOptions::forPublic($workReport),
            'supplier_name'    => $supplierName,
            'branding'         => $this->branding($invoice, $token),
            'captcha_site_key' => (string) $this->config->get('captcha.site_key', ''),
            'captcha_provider' => (string) $this->config->get('captcha.provider', 'none'),
        ]);
    }

    /**
     * Branding pro hlavičku stránky. Schvalovatel je zákazník dodavatele, ne náš —
     * viděl v e-mailu logo dodavatele a stránka, kam ho odkaz pustí, mu musí
     * odpovídat; hlavička „MyÚčto.cz — Fakturační systém" působí jako podvržený
     * odkaz. Stejný přepínač jako u e-mailu: `email_branding_enabled`.
     *
     * Logo se nevrací jako cesta, ale jako URL vázaná na TOKEN — cesta v úložišti
     * by prozradila strukturu a šla by zkoušet pro cizí firmy.
     *
     * @return array{display_name: ?string, accent_color: ?string, logo_url: ?string}
     */
    private function branding(array $invoice, string $token): array
    {
        $supplierId = (int) ($invoice['supplier_id'] ?? 0);
        if ($supplierId <= 0) {
            return ['display_name' => null, 'accent_color' => null, 'logo_url' => null];
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(display_name, company_name) AS name, tagline,
                    email_branding_enabled, email_accent_color, logo_path
               FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false || empty($row['email_branding_enabled'])) {
            return ['display_name' => null, 'accent_color' => null, 'logo_url' => null];
        }

        $hasLogo = SafeLogoPath::resolve(
            $row['logo_path'] !== null ? (string) $row['logo_path'] : null,
            $supplierId,
        ) !== null;

        return [
            'display_name' => $row['name'] !== null ? (string) $row['name'] : null,
            'accent_color' => $row['email_accent_color'] !== null ? (string) $row['email_accent_color'] : null,
            'logo_url'     => $hasLogo ? '/api/public/approval/' . $token . '/logo' : null,
        ];
    }

    /** Jen omezený set polí — public endpoint, žádné citlivé údaje. */
    private function resolveSupplierName(array $invoice): string
    {
        if (!empty($invoice['supplier_snapshot'])) {
            $snap = is_string($invoice['supplier_snapshot'])
                ? json_decode($invoice['supplier_snapshot'], true)
                : $invoice['supplier_snapshot'];
            if (is_array($snap)) {
                $name = (string) ($snap['display_name'] ?: ($snap['company_name'] ?? ''));
                if ($name !== '') return $name;
            }
        }
        $sid = (int) ($invoice['supplier_id'] ?? 0);
        if ($sid <= 0) return '';
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(display_name, company_name) FROM supplier WHERE id = ?'
        );
        $stmt->execute([$sid]);
        return (string) ($stmt->fetchColumn() ?: '');
    }
}
