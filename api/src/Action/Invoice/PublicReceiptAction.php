<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\InvoicePublicLinkFeature;
use MyInvoice\Service\Invoice\ReceiptLinkService;
use MyInvoice\Service\Invoice\SimplifiedDocumentPolicy;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Tenant\PublicTenantGuard;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/**
 * Veřejná účtenka zákazníka (bez přihlášení, jen token z QR kódu).
 *
 *   GET /api/public/receipt/{token}      data účtenky pro stránku /r/{token}
 *   GET /api/public/receipt/{token}/pdf  PDF dokladu
 *
 * Neplatný, prošlý, odvolaný i cizí token vrací stejnou 404, aby se nedalo zjistit,
 * který odkaz existoval. Doklad, který mezitím přestal být zaplacený nebo byl
 * stornován, se taky neukáže. Při vypnuté web faktuře (InvoicePublicLinkFeature)
 * odkaz neexistuje.
 */
final class PublicReceiptAction
{
    public function __construct(
        private readonly ReceiptLinkService $links,
        private readonly InvoiceRepository $invoices,
        private readonly InvoicePdfRenderer $renderer,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly PublicTenantGuard $tenantGuard,
        private readonly InvoicePublicLinkFeature $feature,
    ) {}

    public function show(Request $request, Response $response, array $args): Response
    {
        $resolved = $this->resolve($request, (string) ($args['token'] ?? ''));
        if ($resolved === null) {
            return self::notFound($response);
        }
        [$link, $invoice] = $resolved;
        $supplierId = (int) $invoice['supplier_id'];
        return Json::ok($response, self::buildPayload(
            $invoice,
            $this->renderer->resolveSupplier($invoice),
            $this->renderer->resolveClient($invoice),
            $this->eetRecords($supplierId, (int) $invoice['id']),
            $link['expires_at'],
        ));
    }

    public function pdf(Request $request, Response $response, array $args): Response
    {
        ini_set('display_errors', '0');
        ini_set('html_errors', '0');
        $resolved = $this->resolve($request, (string) ($args['token'] ?? ''));
        if ($resolved === null) {
            return self::notFound($response);
        }
        [, $invoice] = $resolved;
        $id = (int) $invoice['id'];
        ob_start();
        try {
            $path = $this->renderer->render($id);
        } catch (\Throwable) {
            ob_end_clean();
            return Json::error($response, 'pdf_failed', 'PDF se nepodařilo vygenerovat.', 500);
        }
        ob_end_clean();
        $this->logger->log('invoice.public_receipt_pdf_downloaded', null, 'invoice', $id, [],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'), (int) $invoice['supplier_id']);

        $filename = basename($path);
        $disposition = !empty($request->getQueryParams()['download'])
            ? "attachment; filename=\"{$filename}\""
            : "inline; filename=\"{$filename}\"";
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', $disposition)
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Cache-Control', 'no-store')
            ->withBody(new Stream(fopen($path, 'rb')));
    }

    /**
     * Whitelist polí veřejné účtenky — jediné místo, které rozhoduje, co endpoint
     * prozradí. Žádné interní poznámky, ID uživatelů, tokeny, snapshoty ani ID záznamů.
     * DPH rekapitulace je z řádků dokladu ({@see InvoiceRepository::find()}).
     *
     * @param array<string,mixed> $invoice  řádek z InvoiceRepository::find()
     * @param array<string,mixed> $supplier z InvoicePdfRenderer::resolveSupplier()
     * @param array<string,mixed> $client   z InvoicePdfRenderer::resolveClient()
     * @param list<array<string,mixed>> $eet záznamy EET dokladu
     * @return array<string,mixed>
     */
    public static function buildPayload(array $invoice, array $supplier, array $client, array $eet, string $expiresAt): array
    {
        $pick = static fn (array $src, array $keys): array => array_intersect_key($src, array_flip($keys));
        $isVatPayer = (bool) ($supplier['is_vat_payer'] ?? true);
        $hideClient = !empty($client['is_walk_in']) || SimplifiedDocumentPolicy::hidesCustomer($invoice, $supplier);

        return [
            'receipt' => [
                'number' => $invoice['varsymbol'] ?? null,
                'issue_date' => $invoice['issue_date'] ?? null,
                'tax_date' => $invoice['tax_date'] ?? null,
                'is_simplified' => !empty($invoice['is_simplified']) && $isVatPayer,
                'language' => ($invoice['language'] ?? 'cs') === 'en' ? 'en' : 'cs',
                'currency' => $invoice['currency'] ?? 'CZK',
                'currency_decimals' => $invoice['currency_decimals'] ?? 2,
                'prices_include_vat' => !empty($invoice['prices_include_vat']),
                'payment_method' => $invoice['payment_method'] ?? null,
                'paid_at' => $invoice['paid_at'] ?? null,
                'totals' => $invoice['totals'] ?? null,
                'vat_breakdown' => $isVatPayer ? ($invoice['vat_breakdown'] ?? []) : [],
                'items' => array_values(array_map(
                    static fn (array $it): array => $pick($it, [
                        'description', 'quantity', 'unit', 'unit_price_without_vat',
                        'vat_rate_snapshot', 'total_without_vat', 'total_with_vat', 'item_kind',
                    ]),
                    (array) ($invoice['items'] ?? []),
                )),
                'expires_at' => $expiresAt,
            ],
            'supplier' => $pick($supplier, [
                'company_name', 'street', 'city', 'zip', 'ic', 'dic', 'is_vat_payer', 'commercial_register', 'web',
            ]),
            'client' => $hideClient ? null : $pick($client, ['company_name', 'first_name', 'last_name', 'ic', 'dic']),
            'eet' => array_map(static fn (array $r): array => [
                'kind' => (string) $r['kind'],
                'eic' => (string) $r['eic'],
                'premises' => $r['unit_number'] !== null ? (int) $r['unit_number'] : null,
                'cash_register' => $r['device_code'],
                'sequence_number' => $r['sequence_number'],
                'sale_at' => (new \DateTimeImmutable((string) $r['sale_at'] . (string) $r['sale_at_offset']))->format(DATE_ATOM),
                'total_amount' => (string) $r['total_amount'],
                // POK na účtence povinný není; ukáže se, jakmile ho FS potvrdí.
                'pok' => $r['pok'] ?? null,
                'confirmed' => in_array((string) $r['status'], ['confirmed', 'confirmed_warning'], true),
                'playground' => (string) $r['environment'] === 'playground',
            ], $eet),
        ];
    }

    /**
     * @return array{0: array{supplier_id:int, invoice_id:int, expires_at:string}, 1: array<string,mixed>}|null
     */
    private function resolve(Request $request, string $token): ?array
    {
        if (!$this->feature->isEnabled()) {
            return null;
        }
        $link = $this->links->resolve($token);
        if ($link === null || !$this->tenantGuard->allows($request, $link['supplier_id'])) {
            return null;
        }
        $invoice = $this->invoices->find($link['invoice_id']);
        if ($invoice === null || (int) $invoice['supplier_id'] !== $link['supplier_id']
            || ReceiptLinkService::ineligibility($invoice) !== null) {
            return null;
        }
        return [$link, $invoice];
    }

    /**
     * Tržby EET dokladu (prodej i opravy) přes alokace platebních událostí na doklad;
     * index (supplier_id, invoice_id) alokací, žádný průchod celou evidencí.
     *
     * @return list<array<string,mixed>>
     */
    private function eetRecords(int $supplierId, int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT DISTINCT r.id, r.kind, r.environment, r.eic, r.unit_number, r.device_code, r.sequence_number,
                    r.sale_at, r.sale_at_offset, r.total_amount, r.pok, r.status
               FROM payment_event_allocations a
               JOIN eet_records r ON r.payment_event_id = a.payment_event_id AND r.supplier_id = a.supplier_id
              WHERE a.supplier_id = ? AND a.invoice_id = ? AND r.kind IN ('sale', 'correction')
              ORDER BY r.id"
        );
        $stmt->execute([$supplierId, $invoiceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private static function notFound(Response $response): Response
    {
        return Json::error($response, 'token_invalid_or_expired', 'Tento odkaz není platný nebo jeho platnost skončila.', 404);
    }
}
