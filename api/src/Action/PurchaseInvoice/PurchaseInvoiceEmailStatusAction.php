<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Repository\BankEmailNoticeRepository;
use MyInvoice\Repository\PurchaseInvoiceSubmissionRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Přehled pro stránku Nákup → Doklady z e-mailu: stav IMAP schránek firmy bez
 * přístupových údajů a počty dokladů, které z e-mailu dorazily do příchozích.
 */
final class PurchaseInvoiceEmailStatusAction
{
    public const DAYS = 30;

    public function __construct(
        private readonly BankEmailNoticeRepository $mailboxes,
        private readonly PurchaseInvoiceSubmissionRepository $submissions,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (RequestAuthorization::isClientType($request)
            || !RequestAuthorization::allows($request, 'documents.inbox', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'K příchozím dokladům nemáte oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);

        $accounts = array_map(static fn (array $account): array => [
            'id' => (int) $account['id'],
            'name' => (string) $account['name'],
            'enabled' => (bool) $account['enabled'],
            'ingest_pdf_invoices' => (bool) $account['ingest_pdf_invoices'],
            'ingest_pdf_statements' => (bool) $account['ingest_pdf_statements'],
            'distribute_to_suppliers' => (bool) $account['distribute_to_suppliers'],
            'last_scan_at' => $account['last_scan_at'] ?? null,
            'last_scan_status' => $account['last_scan_status'] ?? null,
            'last_scan_message' => $account['last_scan_message'] ?? null,
        ], $this->mailboxes->imapAccounts($supplierId));

        $since = (new \DateTimeImmutable('today'))->modify('-' . self::DAYS . ' days')->format('Y-m-d H:i:s');
        $stats = $this->submissions->emailIntakeStats($supplierId, $since);

        return Json::ok($response, [
            'accounts' => $accounts,
            'active' => array_filter($accounts, static fn (array $a): bool => $a['enabled'] && $a['ingest_pdf_invoices']) !== [],
            'days' => self::DAYS,
            'received' => $stats['received'],
            'waiting' => $stats['waiting'],
        ]);
    }
}
