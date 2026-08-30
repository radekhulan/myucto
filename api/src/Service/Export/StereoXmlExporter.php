<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export;

use DOMDocument;
use DOMElement;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\TaxConstantsRepository;

/**
 * Stereo for Windows DocumentPack exporter for issued invoices.
 *
 * The format uses "net" in the accounting sense: line base without VAT. Keep
 * LineNet tied to invoice_items.total_without_vat, not to total_with_vat.
 */
final class StereoXmlExporter
{
    private readonly InvoiceExportDataResolver $dataResolver;
    private readonly StereoVatTypeResolver $vatTypeResolver;

    public function __construct(
        private readonly InvoiceRepository $repo,
        Connection $db,
        ?InvoiceExportDataResolver $dataResolver = null,
        ?StereoVatTypeResolver $vatTypeResolver = null,
    ) {
        $this->dataResolver = $dataResolver ?? new InvoiceExportDataResolver($db);
        // Resolver potřebuje číselník daňových konstant (základní sazba pro rok dokladu).
        // Volitelné class-parametry PHP-DI neautowiruje, takže se repozitář sestaví
        // z už injektovaného Connection — stejná instance, žádná další vazba v kontejneru.
        $this->vatTypeResolver = $vatTypeResolver ?? new StereoVatTypeResolver(new TaxConstantsRepository($db));
    }

    /**
     * @param int[] $invoiceIds
     * @return array{filename:string, content:string, mime:string}
     */
    public function export(array $invoiceIds, string $periodLabel = ''): array
    {
        $invoices = [];
        foreach ($invoiceIds as $id) {
            $invoice = $this->repo->find((int) $id);
            if ($invoice !== null && !empty($invoice['items']) && is_array($invoice['items'])) {
                $invoices[] = $invoice;
            }
        }

        if ($invoices === []) {
            throw new \RuntimeException('Žádné faktury s položkami k exportu do Stereo XML.');
        }

        $base = 'stereo-' . ($periodLabel !== '' ? $periodLabel : date('Y-m-d'));
        return [
            'filename' => "$base.xml",
            'content' => $this->buildXml($invoices),
            'mime' => 'application/xml',
        ];
    }

    /**
     * @param list<array<string,mixed>> $invoices
     */
    public function buildXml(array $invoices): string
    {
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->preserveWhiteSpace = false;
        $xml->formatOutput = true;

        $root = $xml->appendChild($xml->createElement('DocumentPack'));
        $header = $root->appendChild($xml->createElement('HEADER'));
        $source = $header->appendChild($xml->createElement('Source'));
        $this->el($xml, $source, 'SoftwareVendor', 'myucto.cz');
        $this->el($xml, $source, 'SoftwareProduct', 'myucto.cz');
        $this->el($xml, $source, 'SoftwareVersion', date('YmdHis'));

        $root->appendChild($xml->createElement('PARAMETERS'));
        $documents = $root->appendChild($xml->createElement('DOCUMENTS'));

        foreach ($invoices as $invoice) {
            $documents->appendChild($this->documentNode($xml, $invoice));
        }

        return $xml->saveXML() ?: '';
    }

    /**
     * @param array<string,mixed> $invoice
     */
    private function documentNode(DOMDocument $xml, array $invoice): DOMElement
    {
        $document = $xml->createElement('Document');

        $header = $document->appendChild($xml->createElement('DocumentHeader'));
        $this->el($xml, $header, 'DocumentID', (string) ($invoice['varsymbol'] ?? $invoice['id'] ?? ''));
        $this->el($xml, $header, 'TypeOfDocument', 'Invoice');
        $this->el($xml, $header, 'DocumentCaption', $this->documentCaption((string) ($invoice['invoice_type'] ?? 'invoice')));

        $supplier = $this->supplierData($invoice);
        if ($supplier !== []) {
            $document->appendChild($this->partyNode($xml, 'Suplier', $supplier, true));
        }

        $client = $this->clientData($invoice);
        $document->appendChild($this->partyNode($xml, 'Buyer', $client, false));

        $issue = $document->appendChild($xml->createElement('Issue'));
        $this->el($xml, $issue, 'IssuePerson', $this->issuePerson($invoice));
        $this->el($xml, $issue, 'IssueDate', $this->dateTime((string) ($invoice['issue_date'] ?? '')));

        $bank = $this->bankData($invoice);
        $currencyIso = $this->currencyIso($invoice);
        $currency = $this->stereoCurrencyCode($invoice);

        $payment = $document->appendChild($xml->createElement('Payment'));
        $this->el($xml, $payment, 'PaymentType', $this->paymentType((string) ($invoice['payment_method'] ?? '')));
        $this->el($xml, $payment, 'DueDate', $this->date((string) ($invoice['due_date'] ?? $invoice['issue_date'] ?? '')));
        $this->el($xml, $payment, 'CurrencyCode', $currency);
        $this->el($xml, $payment, 'BankAccount', (string) ($bank['account_number'] ?? ''));
        $this->el($xml, $payment, 'BankCode', (string) ($bank['bank_code'] ?? ''));
        if (!empty($bank['iban'])) {
            $this->el($xml, $payment, 'IBAN', (string) $bank['iban']);
        }
        if (!empty($bank['bic'])) {
            $this->el($xml, $payment, 'Swift', (string) $bank['bic']);
        }
        $this->el($xml, $payment, 'VariableSymbol', (string) ($invoice['varsymbol'] ?? ''));
        $this->el($xml, $payment, 'ConstantSymbol', (string) ($invoice['constant_symbol'] ?? ''));
        if (!empty($bank['bank_name'])) {
            $this->el($xml, $payment, 'BankName', (string) $bank['bank_name']);
        }
        if (!empty($invoice['exchange_rate']) && $currencyIso !== 'CZK') {
            $this->el($xml, $payment, 'CurrencyRate', $this->fmt((float) $invoice['exchange_rate']));
            $this->el($xml, $payment, 'CurrencyAmount', '1.00');
        }

        $this->appendVatTotalsAndRows($xml, $document, $invoice);

        return $document;
    }

    /**
     * @param array<string,mixed> $invoice
     */
    /**
     * @param array<string,mixed> $invoice
     */
    private function documentLabelFor(array $invoice): string
    {
        $label = trim((string) ($invoice['varsymbol'] ?? ''));
        if ($label === '') {
            $label = trim((string) ($invoice['id'] ?? ''));
        }

        return '#' . ($label !== '' ? $label : '?');
    }

    private function appendVatTotalsAndRows(DOMDocument $xml, DOMElement $document, array $invoice): void
    {
        $currency = $this->currencyCode($invoice);
        $vatRates = [];
        $lineCount = 0;
        $items = [];
        $rowVatResolutions = [];

        foreach (($invoice['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $items[] = $item;
            if (!empty($item['oss_applicable'])) {
                // OSS plnění se odvádí v jiném státě a Stereo pro něj nemá typ DPH.
                // Resolver by zahraniční sazbu propadl na '3' → UO „tuzemské osvobozené
                // plnění" (u čistě OSS dokladu tiše, u smíšeného matoucí hláškou o jednom
                // typu DPH). Radši odmítneme export, než abychom účetní podstrčili
                // nesprávné zařazení.
                throw new \RuntimeException(sprintf(
                    'Stereo XML nepodporuje OSS plnění (doklad %s). Řádky v režimu OSS vykažte přes Daně → OSS přiznání a z exportu do Stereo je vyřaďte.',
                    $this->documentLabelFor($invoice),
                ));
            }
            $rowVatResolutions[] = $this->vatTypeResolver->resolveRow($invoice, $item);
        }

        $documentVatResolution = $this->vatTypeResolver->resolveDocument($invoice, $rowVatResolutions);
        $documentProcessVat = $this->vatTypeResolver->documentProcessVat($invoice, $documentVatResolution);
        $documentReverseCharge = $this->vatTypeResolver->documentReverseCharge($documentVatResolution);

        $vatInfo = $document->appendChild($xml->createElement('VatInfo'));
        if ($documentVatResolution !== null) {
            $this->el($xml, $vatInfo, 'TypeOfVAT', $documentVatResolution['type_of_vat']);
        }
        $this->el($xml, $vatInfo, 'VatDate', $this->date((string) ($invoice['tax_date'] ?? $invoice['issue_date'] ?? '')));
        $this->el($xml, $vatInfo, 'TaxVoucher', (($invoice['invoice_type'] ?? '') === 'proforma') ? 'false' : 'true');
        $this->el($xml, $vatInfo, 'VatSource', 'TaxableValue');

        $documentTotals = $document->appendChild($xml->createElement('DocumentTotals'));
        $rows = $xml->createElement('Rows');

        foreach ($items as $item) {
            $lineCount++;
            $rate = $this->fmt((float) ($item['vat_rate_snapshot'] ?? 0));
            $lineBase = (float) ($item['total_without_vat'] ?? 0);
            $lineVat = (float) ($item['total_vat'] ?? 0);
            $lineTotal = (float) ($item['total_with_vat'] ?? ($lineBase + $lineVat));

            $vatRates[$rate] ??= [
                'rate' => (float) ($item['vat_rate_snapshot'] ?? 0),
                'taxable' => 0.0,
                'vat' => 0.0,
                'total' => 0.0,
            ];
            $vatRates[$rate]['taxable'] += $lineBase;
            $vatRates[$rate]['vat'] += $lineVat;
            $vatRates[$rate]['total'] += $lineTotal;

            $quantity = (float) ($item['quantity'] ?? 1);
            $unitPrice = (!empty($invoice['prices_include_vat']) && $quantity != 0.0)
                ? round($lineBase / $quantity, 2)
                : (float) ($item['unit_price_without_vat'] ?? 0);

            $row = $rows->appendChild($xml->createElement('Row'));
            $this->el($xml, $row, 'LineText', (string) ($item['description'] ?? ''));
            $this->el($xml, $row, 'Quantity', $this->fmt($quantity));
            $this->el($xml, $row, 'UnitOfMeasure', (string) ($item['unit'] ?? 'ks'));
            $this->el($xml, $row, 'UnitPrice', $this->fmt($unitPrice));
            $this->el($xml, $row, 'LineNet', $this->fmt($lineBase));
            $this->el($xml, $row, 'LineVATRate', $this->fmt((float) ($item['vat_rate_snapshot'] ?? 0)));
            $this->el($xml, $row, 'LineVAT', $this->fmt($lineVat));
            $this->el($xml, $row, 'CurrencyCode', $currency);
            if ($documentVatResolution !== null) {
                $this->el($xml, $row, 'TypeOfVAT', $documentVatResolution['type_of_vat']);
                $this->el($xml, $row, 'ProcessVAT', $documentVatResolution['process_vat'] ? 'true' : 'false');
                $this->el($xml, $row, 'ReverseCharge', $documentVatResolution['reverse_charge'] ? 'true' : 'false');
            }
        }

        foreach ($vatRates as $rate) {
            $vatRow = $vatInfo->appendChild($xml->createElement('VATTableRow'));
            $this->el($xml, $vatRow, 'VATRate', $this->fmt($rate['rate']));
            $this->el($xml, $vatRow, 'TotalTaxableAtRate', $this->fmt($rate['taxable']));
            $this->el($xml, $vatRow, 'VATAtRate', $this->fmt($rate['vat']));
            $this->el($xml, $vatRow, 'TotalWithVAT', $this->fmt($rate['total']));
        }

        $taxableTotal = array_sum(array_column($vatRates, 'taxable'));
        $vatTotal = array_sum(array_column($vatRates, 'vat'));
        $netTotal = array_sum(array_column($vatRates, 'total'));
        $advance = (float) ($invoice['advance_paid_amount'] ?? 0);
        $rounding = (float) ($invoice['rounding'] ?? ($invoice['totals']['rounding'] ?? 0));
        $amountToPay = array_key_exists('amount_to_pay', $invoice) && $invoice['amount_to_pay'] !== null
            ? (float) $invoice['amount_to_pay']
            : $netTotal - $advance + $rounding;

        $this->el($xml, $documentTotals, 'NumberOfLines', (string) $lineCount);
        $this->el($xml, $documentTotals, 'NumberOfVATRates', (string) count($vatRates));
        $this->el($xml, $documentTotals, 'TaxableTotal', $this->fmt($taxableTotal));
        $this->el($xml, $documentTotals, 'VatTotal', $this->fmt($vatTotal));
        $this->el($xml, $documentTotals, 'NetTotal', $this->fmt($netTotal));
        $this->el($xml, $documentTotals, 'AdvancePaymentTotal', $this->fmt($advance));
        $this->el($xml, $documentTotals, 'NetPaymentTotal', $this->fmt($amountToPay - $rounding));
        $this->el($xml, $documentTotals, 'NetPaymentTotalRounding', $this->fmt($rounding));
        $this->el($xml, $documentTotals, 'NetPaymentTotalRounded', $this->fmt($amountToPay));
        if ($documentProcessVat !== null) {
            $this->el($xml, $documentTotals, 'ProcessVAT', $documentProcessVat ? 'true' : 'false');
        }
        if ($documentReverseCharge !== null) {
            $this->el($xml, $documentTotals, 'ReverseCharge', $documentReverseCharge ? 'true' : 'false');
        }

        $document->appendChild($rows);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function partyNode(DOMDocument $xml, string $name, array $data, bool $supplier): DOMElement
    {
        $node = $xml->createElement($name);

        $person = $node->appendChild($xml->createElement('Person'));
        if (!empty($data['first_name'])) {
            $this->el($xml, $person, 'FirstName', (string) $data['first_name']);
        }
        $this->el($xml, $person, 'LastName', (string) ($data['last_name'] ?? ''));
        if (!empty($data['main_email']) || !empty($data['email'])) {
            $this->el($xml, $person, 'Email', (string) ($data['main_email'] ?? $data['email']));
        }

        $address = $node->appendChild($xml->createElement('Address'));
        $this->el($xml, $address, 'Street', (string) ($data['street'] ?? ''));
        $this->el($xml, $address, 'City', (string) ($data['city'] ?? ''));
        $this->el($xml, $address, 'PostalCode', (string) ($data['zip'] ?? ''));
        if (!empty($data['country_iso2'])) {
            $this->el($xml, $address, 'Country', (string) $data['country_iso2']);
        }

        $company = $node->appendChild($xml->createElement('Company'));
        $this->el($xml, $company, 'Company', (string) ($data['company_name'] ?? $data['display_name'] ?? ''));
        $this->el($xml, $company, 'CompanyRegistrationNo', (string) ($data['ic'] ?? ''));
        $this->el($xml, $company, 'CompanyTaxRegistrationNo', (string) ($data['dic'] ?? ''));
        $isVatPayer = $supplier
            ? (!array_key_exists('is_vat_payer', $data) || (bool) $data['is_vat_payer'])
            : ((string) ($data['dic'] ?? '') !== '');
        $this->el($xml, $company, 'VATPayer', $isVatPayer ? 'true' : 'false');

        return $node;
    }

    /**
     * @param array<string,mixed> $invoice
     * @return array<string,mixed>
     */
    private function clientData(array $invoice): array
    {
        return $this->dataResolver->client($invoice);
    }

    /**
     * @param array<string,mixed> $invoice
     * @return array<string,mixed>
     */
    private function supplierData(array $invoice): array
    {
        return $this->dataResolver->supplier($invoice);
    }

    /**
     * @param array<string,mixed> $invoice
     * @return array<string,mixed>
     */
    private function bankData(array $invoice): array
    {
        return $this->dataResolver->bank($invoice) ?? [];
    }

    /**
     * @param array<string,mixed> $invoice
     */
    private function issuePerson(array $invoice): string
    {
        return $this->dataResolver->issuePerson($invoice);
    }

    private function documentCaption(string $invoiceType): string
    {
        return match ($invoiceType) {
            'proforma' => 'ZÁLOHOVÁ FAKTURA',
            'credit_note' => 'DOBROPIS',
            'cancellation' => 'STORNO',
            default => 'FAKTURA - daňový doklad',
        };
    }

    /**
     * Mapování na `PaymentType` ve Stereo XML. Hodnoty z migrace 1128 jsou uvedené
     * EXPLICITNĚ, i když končí na 'Other': slovník Stereo pro ně nemá vlastní token a
     * tipovat neexistující ('DirectDebit'…) by import rozbil. 'Other' je ztrátové, ale
     * bezpečné — hlavně se inkaso NESMÍ vyexportovat jako 'BankTransfer'.
     */
    private function paymentType(string $paymentMethod): string
    {
        return match ($paymentMethod) {
            'bank_transfer'    => 'BankTransfer',
            'cash'             => 'Cash',
            'card'             => 'CreditCard',
            'direct_debit'     => 'Other',
            'cash_on_delivery' => 'Other',
            'offset'           => 'Other',
            default            => 'Other',
        };
    }

    /**
     * @param array<string,mixed> $invoice
     */
    private function currencyCode(array $invoice): string
    {
        return $this->stereoCurrencyCode($invoice);
    }

    /**
     * @param array<string,mixed> $invoice
     */
    private function stereoCurrencyCode(array $invoice): string
    {
        $code = $this->currencyIso($invoice);

        return $code === 'CZK' ? 'Kč' : $code;
    }

    /**
     * @param array<string,mixed> $invoice
     */
    private function currencyIso(array $invoice): string
    {
        $code = strtoupper((string) ($invoice['currency'] ?? 'CZK'));
        return preg_match('/^[A-Z]{3}$/', $code) ? $code : 'CZK';
    }

    private function date(string $date): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
    }

    private function dateTime(string $date): string
    {
        return $this->date($date) . 'T00:00:00.00';
    }

    private function fmt(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function el(DOMDocument $xml, DOMElement $parent, string $name, string $value): DOMElement
    {
        $element = $xml->createElement($name);
        $element->appendChild($xml->createTextNode($value));
        $parent->appendChild($element);

        return $element;
    }
}
