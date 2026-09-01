<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Repository\SupplierPaymentQrSettingsRepository;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;
use MyInvoice\Service\Import\LlmGatewayInterface;
use MyInvoice\Service\Import\IsdocParser;
use MyInvoice\Service\Import\PdfIsdocExtractor;
use MyInvoice\Service\Payment\BankAccountParser;
use MyInvoice\Service\Pdf\PdfImageExtractor;
use MyInvoice\Service\Qr\PaymentQrDueDate;
use MyInvoice\Service\Qr\QrPaymentGenerator;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * „Zaplatit pomocí QR" u přijaté faktury.
 *
 *   GET  /api/purchase-invoices/{id}/payment-qr            → QR z uloženého účtu
 *        (nebo signál needs_account / fallback obrázek QR z PDF). Čistě read.
 *   POST /api/purchase-invoices/{id}/payment-qr/extract-account
 *        → jednorázové lazy doplnění účtu z ISDOC → AI (zápis; readonly blokuje
 *          PermissionMiddleware podle metody).
 *   PUT  /api/purchase-invoices/{id}/payment-account       → ruční editace účtu.
 *
 * QR generuje server-side přes QrPaymentGenerator (CZK SPAYD / jinak SEPA EPC),
 * příjemce platby = dodavatel (vendor).
 */
final class PaymentQrAction
{
    use GuardsDocumentLock;

    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly QrPaymentGenerator $qr,
        private readonly BankAccountParser $bankParser,
        private readonly Config $config,
        private readonly PdfIsdocExtractor $pdfIsdoc,
        private readonly IsdocParser $isdoc,
        private readonly LlmGatewayInterface $anthropic,
        private readonly PdfImageExtractor $pdfImages,
        private readonly DocumentLockService $locks,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly SupplierPaymentQrSettingsRepository $paymentQrSettings,
    ) {}

    /** GET — QR z uloženého účtu, jinak needs_account / fallback obrázek. */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        [$err, $invoice, $supplierId] = $this->load($request, $response, $args);
        if ($err !== null) {
            return $err;
        }

        if ($this->hasStoredAccount($invoice)) {
            return Json::ok($response, $this->buildQrPayload($invoice));
        }

        $hasPdf = ((string) ($invoice['pdf_path'] ?? '')) !== '';
        $checked = ($invoice['payment_account_checked_at'] ?? null) !== null;

        // Účet ještě nikdo nezkusil doplnit a máme PDF → necháme frontend spustit
        // jednorázovou extrakci (POST extract-account); readonly tu akci neuvidí.
        if ($hasPdf && !$checked) {
            return Json::ok($response, $this->needsAccountPayload($invoice, canExtract: true));
        }

        // Fallback: zkus z PDF vytáhnout obrázek QR kódu (heuristika).
        $imageUri = $this->tryQrImage($invoice);
        if ($imageUri !== null) {
            return Json::ok($response, $this->imageFallbackPayload($invoice, $imageUri));
        }

        return Json::ok($response, $this->needsAccountPayload($invoice, canExtract: false));
    }

    /** POST — jednorázové lazy doplnění účtu z ISDOC → AI. */
    public function extractAccount(Request $request, Response $response, array $args): Response
    {
        [$err, $invoice, $supplierId] = $this->load($request, $response, $args);
        if ($err !== null) {
            return $err;
        }
        $id = (int) $invoice['id'];
        if ($denied = $this->denyIfLocked(
            $request,
            $response,
            $this->locks->forPurchaseInvoice($invoice),
            'purchase_invoice',
            $id,
        )) return $denied;

        if ($this->hasStoredAccount($invoice)) {
            return Json::ok($response, $this->buildQrPayload($invoice));
        }

        $pdf = $this->readPdfBytes($invoice);
        if ($pdf === null) {
            // Není z čeho extrahovat — zkus aspoň obrázek QR z PDF (žádný tu není).
            return Json::ok($response, $this->needsAccountPayload($invoice, canExtract: false));
        }

        // 1) ISDOC embed v PDF (lokální, zdarma).
        $found = $this->extractFromIsdoc($pdf);
        $source = 'isdoc';

        // 2) AI account-only (jen pokud má tenant Anthropic klíč).
        if ($found === null) {
            $ai = $this->anthropic->extractPaymentAccount($supplierId, $pdf);
            if (!empty($ai['ok'])) {
                $parsedA = $this->bankParser->parse((string) ($ai['bank_account'] ?? ''));
                $parsedB = $this->bankParser->parse((string) ($ai['iban'] ?? ''));
                $found = [
                    'account_number'  => $parsedA['account_number'] ?? null,
                    'bank_code'       => $parsedA['bank_code'] ?? null,
                    'iban'            => $parsedA['iban'] ?? ($parsedB['iban'] ?? null),
                    'bic'             => null,
                    'variable_symbol' => $ai['variable_symbol'] ?? null,
                ];
                $source = 'ai_reextract';
            }
        }

        if ($found !== null && $this->bankParser->hasAccount($found['account_number'] ?? null, $found['bank_code'] ?? null, $found['iban'] ?? null)) {
            $found['source'] = $source;
            $this->repo->updatePaymentAccount($id, $found, $supplierId);
        } else {
            // Nenalezeno → označ checked, ať se lazy extrakce už nespouští.
            $this->repo->updatePaymentAccount($id, ['checked' => true], $supplierId);
        }

        $fresh = $this->repo->find($id, $supplierId);
        if ($fresh === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }
        if ($this->hasStoredAccount($fresh)) {
            return Json::ok($response, $this->buildQrPayload($fresh));
        }

        $imageUri = $this->tryQrImage($fresh);
        if ($imageUri !== null) {
            return Json::ok($response, $this->imageFallbackPayload($fresh, $imageUri));
        }
        return Json::ok($response, $this->needsAccountPayload($fresh, canExtract: false));
    }

    /** PUT — ruční editace účtu (source='manual'). */
    public function updateAccount(Request $request, Response $response, array $args): Response
    {
        [$err, $invoice, $supplierId] = $this->load($request, $response, $args);
        if ($err !== null) {
            return $err;
        }
        $id = (int) $invoice['id'];
        if ($denied = $this->denyIfLocked(
            $request,
            $response,
            $this->locks->forPurchaseInvoice($invoice),
            'purchase_invoice',
            $id,
        )) return $denied;

        $body = (array) ($request->getParsedBody() ?? []);
        $payment = [
            'account_number'  => $this->cleanInput($body['account_number'] ?? null, 34),
            'bank_code'       => $this->cleanInput($body['bank_code'] ?? null, 10),
            'iban'            => $this->normalizeIban($body['iban'] ?? null),
            'bic'             => $this->cleanInput($body['bic'] ?? null, 11),
            'variable_symbol' => $this->cleanInput($body['variable_symbol'] ?? null, 20),
            'source'          => 'manual',
        ];
        $this->repo->updatePaymentAccount($id, $payment, $supplierId);

        $fresh = $this->repo->find($id, $supplierId);
        if ($fresh === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }
        return Json::ok($response, $this->hasStoredAccount($fresh)
            ? $this->buildQrPayload($fresh)
            : $this->needsAccountPayload($fresh, canExtract: false));
    }

    // ── interní ──────────────────────────────────────────────────────────────

    /**
     * @return array{0:?Response,1:array<string,mixed>,2:int}
     */
    private function load(Request $request, Response $response, array $args): array
    {
        $id = (int) ($args['id'] ?? 0);
        $supplierId = SupplierGuard::currentId($request);
        if ($id <= 0) {
            return [Json::error($response, 'invalid_id', 'Neplatné ID', 400), [], $supplierId];
        }
        $invoice = $this->repo->find($id, $supplierId);
        if ($invoice === null) {
            return [Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404), [], $supplierId];
        }
        return [null, $invoice, $supplierId];
    }

    private function hasStoredAccount(array $invoice): bool
    {
        return $this->bankParser->hasAccount(
            $invoice['payment_account_number'] ?? null,
            $invoice['payment_bank_code'] ?? null,
            $invoice['payment_iban'] ?? null,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function buildQrPayload(array $invoice): array
    {
        $currency = strtoupper((string) ($invoice['currency'] ?? 'CZK'));
        $amount   = $this->payableAmount($invoice);
        $vs       = $this->variableSymbol($invoice);
        $bank     = $this->bankParser->bankSnapshot(
            $invoice['payment_account_number'] ?? null,
            $invoice['payment_bank_code'] ?? null,
            $invoice['payment_iban'] ?? null,
            $invoice['payment_bic'] ?? null,
        );
        $dueDate = PaymentQrDueDate::parse($invoice['due_date'] ?? null);
        $message = 'Faktura ' . (string) ($invoice['vendor_invoice_number'] ?? $vs);
        $settings = $this->paymentQrSettings->find((int) ($invoice['supplier_id'] ?? 0));

        $dataUri = $this->qr->generate(
            $currency,
            $amount,
            $vs,
            $bank,
            (string) ($invoice['vendor_company_name'] ?? ''),
            $dueDate,
            $message,
            (bool) ($settings[SupplierPaymentQrSettingsRepository::PURCHASE_INVOICE_FIELD] ?? false),
        );

        return [
            'ok'           => $dataUri !== null,
            'qr_data_uri'  => $dataUri,
            'source'       => (string) ($invoice['payment_account_source'] ?? 'manual'),
            'amount'       => $amount,
            'currency'     => $currency,
            'account'      => $this->accountView($invoice),
            'editable'     => true,
            'needs_account' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function needsAccountPayload(array $invoice, bool $canExtract): array
    {
        return [
            'ok'            => false,
            'qr_data_uri'   => null,
            'source'        => null,
            'amount'        => $this->payableAmount($invoice),
            'currency'      => strtoupper((string) ($invoice['currency'] ?? 'CZK')),
            'account'       => $this->accountView($invoice),
            'editable'      => true,
            'needs_account' => true,
            'can_extract'   => $canExtract,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function imageFallbackPayload(array $invoice, string $imageUri): array
    {
        return [
            'ok'            => true,
            'qr_data_uri'   => $imageUri,
            'source'        => 'qr_image',
            'amount'        => $this->payableAmount($invoice),
            'currency'      => strtoupper((string) ($invoice['currency'] ?? 'CZK')),
            'account'       => $this->accountView($invoice),
            'editable'      => true,
            'needs_account' => false,
        ];
    }

    /**
     * @return array<string,?string>
     */
    private function accountView(array $invoice): array
    {
        return [
            'account_number'  => $invoice['payment_account_number'] ?? null,
            'bank_code'       => $invoice['payment_bank_code'] ?? null,
            'iban'            => $invoice['payment_iban'] ?? null,
            'bic'             => $invoice['payment_bic'] ?? null,
            'variable_symbol' => $invoice['payment_variable_symbol'] ?? null,
        ];
    }

    private function payableAmount(array $invoice): float
    {
        $toPay = (float) ($invoice['amount_to_pay'] ?? 0);
        $base  = $toPay > 0 ? $toPay : (float) ($invoice['total_with_vat'] ?? 0);
        // Zaokrouhlení dokladu (haléřový offset „K úhradě") je vedeno mimo
        // total_with_vat/amount_to_pay → přičti ho, ať QR žádá částku PO
        // zaokrouhlení (issue #166).
        return round($base + (float) ($invoice['rounding'] ?? 0), 2);
    }

    private function variableSymbol(array $invoice): string
    {
        $candidates = [
            (string) ($invoice['payment_variable_symbol'] ?? ''),
            (string) ($invoice['vendor_invoice_number'] ?? ''),
            (string) ($invoice['varsymbol'] ?? ''),
        ];
        foreach ($candidates as $c) {
            $vs = VariableSymbolNormalizer::forPayment($c);
            if ($vs !== '') {
                return $vs;
            }
        }
        return '';
    }

    /**
     * @return array{account_number:?string,bank_code:?string,iban:?string,bic:?string,variable_symbol:?string}|null
     */
    private function extractFromIsdoc(string $pdf): ?array
    {
        try {
            $xml = $this->pdfIsdoc->extract($pdf);
            if ($xml === null || $xml === '') {
                return null;
            }
            $parsed = $this->isdoc->parse($xml);
            $payment = $parsed['invoices'][0]['payment'] ?? null;
            if (!is_array($payment)) {
                return null;
            }
            return [
                'account_number'  => $payment['account_number'] ?? null,
                'bank_code'       => $payment['bank_code'] ?? null,
                'iban'            => $payment['iban'] ?? null,
                'bic'             => $payment['bic'] ?? null,
                'variable_symbol' => $payment['variable_symbol'] ?? null,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function tryQrImage(array $invoice): ?string
    {
        $pdf = $this->readPdfBytes($invoice);
        if ($pdf === null) {
            return null;
        }
        try {
            return $this->pdfImages->findQrLikeImage($pdf);
        } catch (\Throwable) {
            return null;
        }
    }

    private function readPdfBytes(array $invoice): ?string
    {
        $relativePath = (string) ($invoice['pdf_path'] ?? '');
        if ($relativePath === '') {
            return null;
        }
        $archiveRootReal = realpath($this->resolveArchiveRoot());
        if ($archiveRootReal === false) {
            return null;
        }
        $fullPath = $archiveRootReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $fullPathReal = realpath($fullPath);
        if ($fullPathReal === false || !is_file($fullPathReal)) {
            return null;
        }
        // Path traversal guard (case-insensitive na Windows — viz feedback_windows_paths).
        $isWindows = DIRECTORY_SEPARATOR === '\\';
        $haystack = $isWindows ? strtolower($fullPathReal) : $fullPathReal;
        $needle   = ($isWindows ? strtolower($archiveRootReal) : $archiveRootReal) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($haystack, $needle)) {
            return null;
        }
        $bytes = @file_get_contents($fullPathReal);
        return $bytes === false ? null : $bytes;
    }

    private function resolveArchiveRoot(): string
    {
        $dir = (string) $this->config->get('purchase_invoice.archive_storage', '');
        if ($dir !== '') {
            return $dir;
        }
        $uploads = (string) $this->config->get('storage.uploads_dir', '');
        if ($uploads !== '') {
            return dirname($uploads) . '/purchase-invoices';
        }
        return RuntimePaths::storage('purchase-invoices');
    }

    private function cleanInput(mixed $v, int $maxLen): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        $s = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $s);
        if ($s === '') {
            return null;
        }
        return mb_substr($s, 0, $maxLen);
    }

    private function normalizeIban(mixed $v): ?string
    {
        $s = $this->cleanInput($v, 34);
        if ($s === null) {
            return null;
        }
        return strtoupper((string) preg_replace('/\s+/', '', $s));
    }
}
