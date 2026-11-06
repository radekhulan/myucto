<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Report\VatClassificationDefaulter;

/**
 * Výchozí klasifikace DPH zakládaného vydaného dokladu (řádky i hlavička), pokud ji
 * volající nezadal. Společná pro založení faktury v editoru
 * ({@see \MyInvoice\Action\Invoice\CreateInvoiceAction}) i pro rychlou účtenku
 * ({@see \MyInvoice\Service\Mobile\QuickReceiptService}), ať obě cesty zařadí stejné
 * plnění na stejný řádek přiznání.
 *
 * Víceúčelový poukaz ({@see MultiPurposeVoucher}) klasifikaci nedostane a o kódu
 * hlavičky nerozhoduje: plněním není.
 */
final class NewInvoiceVatClassification
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly VatClassificationDefaulter $vatDefaulter,
        private readonly Connection $db,
    ) {}

    /** @param array<string,mixed> $body payload zakládaného dokladu (mění se) */
    public function apply(array &$body, int $supplierId): void
    {
        $vatRates = $this->repo->vatRateMap();
        $reverseCharge = !empty($body['reverse_charge']);
        // Country-aware RC: tuzemský odběratel → §92a (ř.25), zahraniční EU → dodání do JČS (ř.20).
        $customerEuForeign = $reverseCharge
            && (int) ($body['client_id'] ?? 0) > 0
            && $this->repo->clientIsEuForeign((int) $body['client_id']);

        if (!empty($body['items']) && is_array($body['items'])) {
            $annotated = MultiPurposeVoucher::annotate($this->db->pdo(), $supplierId, $body['items']);
            foreach ($body['items'] as $key => &$item) {
                if (is_array($annotated[$key] ?? null) && MultiPurposeVoucher::isLine($annotated[$key])) {
                    $item['vat_classification_code'] = null;
                    continue;
                }
                if (!empty($item['vat_classification_code']) || InvoiceItemKind::isText($item)) continue;
                $rateId = (int) ($item['vat_rate_id'] ?? 0);
                $rate = (float) ($vatRates[$rateId] ?? 0);
                $taxDate = $body['tax_date'] ?? $body['issue_date'] ?? null;
                // Měrná jednotka řádku je signál zboží/služba pro RC prodej do EU (ř.20 vs ř.21).
                $units = ((string) ($item['unit'] ?? '') !== '') ? [(string) $item['unit']] : [];
                $item['vat_classification_code'] = $this->vatDefaulter->defaultForSale($rate, $reverseCharge, $taxDate, $supplierId, $customerEuForeign, $units);
            }
            unset($item);
        }

        $amountLines = !empty($body['items']) ? InvoiceItemKind::amountLines((array) $body['items']) : [];
        $headerItems = MultiPurposeVoucher::withoutLines($this->db->pdo(), $supplierId, $amountLines);
        // Doklad jen z poukazů žádné plnění nenese, hlavička zůstane bez kódu.
        if (empty($body['vat_classification_code']) && !empty($body['items'])
            && ($amountLines === [] || $headerItems !== [])) {
            $itemsWithTotals = array_map(function ($it) use ($vatRates) {
                $rateId = (int) ($it['vat_rate_id'] ?? 0);
                $rate = (float) ($vatRates[$rateId] ?? 0);
                $qty = (float) ($it['quantity'] ?? 1);
                $price = (float) ($it['unit_price_without_vat'] ?? 0);
                return ['vat_rate' => $rate, 'total_with_vat' => $qty * $price * (1 + $rate / 100), 'unit' => (string) ($it['unit'] ?? '')];
            }, $headerItems);
            $body['vat_classification_code'] = $this->vatDefaulter->suggestHeaderForInvoice(
                $itemsWithTotals,
                (bool) ($body['reverse_charge'] ?? false),
                'sale',
                $body['tax_date'] ?? $body['issue_date'] ?? null,
                $supplierId,
                $customerEuForeign,
            );
        }
    }
}
