<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Currency\ExchangeRateApplier;
use MyInvoice\Service\Export\IsdocExporter;
use MyInvoice\Service\Signing\Pdf\PdfSigningService;

/** Společný export samostatného ISDOC pro přihlášené uživatele, web fakturu a e-mail. */
final class InvoiceIsdocService
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly IsdocExporter $exporter,
        private readonly ExchangeRateApplier $rates,
        private readonly PdfSigningService $signing,
    ) {}

    public static function isExportable(array $invoice): bool
    {
        return !in_array($invoice['status'] ?? '', ['draft', 'cancelled'], true);
    }

    public static function filename(array $invoice): string
    {
        $number = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($invoice['varsymbol'] ?? $invoice['id'] ?? 'invoice'));
        return 'Faktura-' . $number . '.isdoc';
    }

    public function buildXml(array $invoice, bool $persistRate = true): string
    {
        if (!self::isExportable($invoice)) {
            throw new \DomainException('Koncept ani stornovanou fakturu nelze exportovat do ISDOC.');
        }
        if ((string) ($invoice['currency'] ?? 'CZK') !== 'CZK' && empty($invoice['exchange_rate'])) {
            $id = (int) $invoice['id'];
            $resolved = $this->rates->ensureRate($id, persist: $persistRate);
            $invoice = $persistRate
                ? ($this->repo->find($id) ?? throw new \RuntimeException('Faktura nenalezena.'))
                : $this->rates->applyResolvedToInvoiceData($invoice, $resolved);
        } elseif (isset($invoice['id'], $invoice['czk_recap']) && !array_key_exists('import_total_with_vat_czk', $invoice)) {
            $invoice = $this->repo->find((int) $invoice['id'], includeImportedCzk: true) ?? $invoice;
        }
        return $this->exporter->buildXml($invoice);
    }

    public function signXml(string $xml, array $invoice, ?int $userId = null): string
    {
        return $this->signing->signIsdocIfEnabled(
            $xml,
            ['id' => (int) ($invoice['supplier_id'] ?? 0)],
            (int) $invoice['id'],
            $userId,
        );
    }

    public function document(array $invoice, ?int $userId = null, bool $persistRate = true): string
    {
        return $this->signXml($this->buildXml($invoice, $persistRate), $invoice, $userId);
    }
}
