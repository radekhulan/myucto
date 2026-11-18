<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;

/**
 * Export přijaté faktury do ISDOC / Pohoda XML.
 *
 * Strategie: **role inversion adapter**. Existující IsdocExporter / PohodaXmlExporter
 * pracují s vystavenými fakturami (náš tenant = dodavatel, klient = customer). Pro
 * přijatou fakturu se role obrátí (vendor = supplier, náš tenant = customer).
 *
 * Adapter transformuje `purchase_invoice` array na `invoice`-compatible array
 * (s prohozenými party snapshots) a deleguje na buildXml() existing exportérů.
 */
final class PurchaseInvoiceExportService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PurchaseInvoiceRepository $repo,
        private readonly IsdocExporter $isdoc,
        private readonly PohodaXmlExporter $pohoda,
    ) {}

    public function toIsdocXml(int $purchaseInvoiceId, int $supplierId): string
    {
        $invoice = $this->buildInvoiceShape($purchaseInvoiceId, $supplierId);
        return $this->isdoc->buildXml($invoice);
    }

    /**
     * Pohoda `<dataPack>` s jednou položkou `<dataPackItem>` pro jednu přijatou fakturu.
     */
    public function toPohodaXml(int $purchaseInvoiceId, int $supplierId): string
    {
        $invoice = $this->buildInvoiceShape($purchaseInvoiceId, $supplierId);
        $cfg = $this->loadPohodaCfg($supplierId);
        // Přijatá faktura → exporter generuje <inv:invoice> s invoiceType=received*
        // a partnerIdentity=dodavatel; přepíná se flagem `direction='purchase'` v cfg.
        $cfg['direction'] = 'purchase';
        return $this->pohoda->buildXml([$invoice], $cfg);
    }

    /**
     * Pohoda `<dataPack>` se VŠEMI přijatými fakturami v jednom balíčku (jedna
     * `<dataPackItem>` na fakturu). Stěžejní: voláme `buildXml()` JEDNOU nad celým
     * polem — žádné string-wrappování per faktura, takže nevzniká zanořený dataPack.
     * Vadné faktury (chybný build shape) se přeskočí a vrátí v `errors`.
     *
     * @param int[] $purchaseInvoiceIds
     * @return array{xml:string, included:int, errors:list<string>}
     */
    public function toPohodaDataPack(array $purchaseInvoiceIds, int $supplierId): array
    {
        $shapes = [];
        $errors = [];
        foreach ($purchaseInvoiceIds as $id) {
            try {
                $shapes[] = $this->buildInvoiceShape((int) $id, $supplierId);
            } catch (\Throwable $e) {
                $errors[] = "#{$id} — " . $e->getMessage();
            }
        }

        if ($shapes === []) {
            return ['xml' => '', 'included' => 0, 'errors' => $errors];
        }

        $cfg = $this->loadPohodaCfg($supplierId);
        $cfg['direction'] = 'purchase';

        return [
            'xml'      => $this->pohoda->buildXml($shapes, $cfg),
            'included' => count($shapes),
            'errors'   => $errors,
        ];
    }

    /**
     * Sestaví invoice-shaped array z purchase_invoice. Klíčové: prohození party snapshots.
     */
    private function buildInvoiceShape(int $purchaseInvoiceId, int $supplierId): array
    {
        $pi = $this->repo->find($purchaseInvoiceId, $supplierId);
        if ($pi === null) {
            throw new \RuntimeException("Přijatá faktura #{$purchaseInvoiceId} nenalezena.");
        }

        // Náš tenant je v této transakci CUSTOMER (kupující). Vendor je SUPPLIER.
        $ourSnapshot = $this->loadSupplierSnapshot($supplierId);
        $vendorSnapshot = $pi['vendor_snapshot'] ?? $this->loadVendorSnapshot((int) $pi['vendor_id']);
        $paymentVariableSymbol = $this->paymentVariableSymbol($pi);

        // Items mapping — preserve structure (description, quantity, unit_price, vat_rate)
        $items = array_map(function ($it) {
            $vatRate = (float) ($it['vat_rate_snapshot'] ?? $it['vat_rate'] ?? 0);
            return [
                'description'            => $it['description'] ?? '',
                'stock_item_id'          => isset($it['stock_item_id']) ? (int) $it['stock_item_id'] : null,
                'quantity'               => (float) ($it['quantity'] ?? 1),
                'duration_minutes'       => array_key_exists('duration_minutes', $it) && $it['duration_minutes'] !== null
                    ? (int) $it['duration_minutes']
                    : null,
                'unit'                   => $it['unit'] ?? 'ks',
                'unit_price_without_vat' => (float) ($it['unit_price_without_vat'] ?? 0),
                'vat_rate'               => $vatRate,
                // Oba exportéry (Pohoda i ISDOC) čtou sazbu z `vat_rate_snapshot`.
                'vat_rate_snapshot'      => $vatRate,
                'total_without_vat'      => (float) ($it['total_without_vat'] ?? 0),
                'total_vat'              => (float) ($it['total_vat'] ?? 0),
                'total_with_vat'         => (float) ($it['total_with_vat'] ?? 0),
            ];
        }, $pi['items'] ?? []);

        return [
            'id'              => $pi['id'],
            // Machine enum konzumovaný ISDOC/Pohoda/Stereo exportéry (NE zobrazovaný text).
            // DDKP (tax_document) je skutečný daňový doklad nesoucí DPH → 'invoice'
            // (na rozdíl od zálohy = 'proforma', kde TaxVoucher=false).
            'invoice_type'    => match ($pi['document_kind'] ?? 'invoice') {
                'credit_note'  => 'credit_note',
                'advance'      => 'proforma',
                'tax_document' => 'invoice',
                default        => 'invoice',
            },
            'document_number' => $pi['vendor_invoice_number'] ?? $pi['varsymbol'] ?? ('P-' . $pi['id']),
            'internal_document_number' => $pi['varsymbol'] ?? null,
            'varsymbol'       => $pi['varsymbol'] ?? $pi['vendor_invoice_number'] ?? ('P-' . $pi['id']),
            'issue_date'      => $pi['issue_date'],
            'tax_date'        => $pi['tax_date'] ?? $pi['issue_date'],
            'due_date'        => $pi['due_date'],
            'currency'        => $pi['currency'] ?? 'CZK',
            'exchange_rate'   => $pi['exchange_rate'] ?? null,
            'reverse_charge'  => $pi['reverse_charge'] ?? false,
            // Aby exporter dopočítal netto jednotkovou cenu z řádkového základu (v tomto
            // režimu nese unit_price_without_vat brutto).
            'prices_include_vat' => !empty($pi['prices_include_vat']),
            'language'        => $pi['language'] ?? 'cs',
            // **Inverted roles**:
            //   - Vystavená: supplier=náš tenant, client=zákazník
            //   - Přijatá:   supplier=dodavatel, client=náš tenant
            'supplier_snapshot' => $vendorSnapshot,
            'client_snapshot'   => $ourSnapshot,
            // Plus pro legacy/fallback code paths v existing exporteru
            'supplier'          => $vendorSnapshot,
            'client'            => $ourSnapshot,
            'bank_snapshot'     => $this->paymentBankSnapshot($pi, $paymentVariableSymbol),
            'payment_variable_symbol' => $paymentVariableSymbol !== '' ? $paymentVariableSymbol : null,
            'payment_constant_symbol' => $pi['payment_constant_symbol'] ?? null,
            'payment_method'    => $pi['payment_method'] ?? null,
            'items'             => $items,
            // Rekapitulace DPH + souhrny — oba exportéry je čtou z `vat_breakdown`/`totals`
            // (bez nich byly summary/TaxTotal nulové). Repo je dodává ve find(), ale jiným
            // klíčováním než vydané → přemapovat na kanonický tvar `rate`/`base`/`vat`.
            'vat_breakdown'     => self::normalizeVatBreakdown($pi['vat_breakdown'] ?? []),
            'totals'            => $pi['totals'] ?? [
                'without_vat' => (float) ($pi['total_without_vat'] ?? 0),
                'vat'         => (float) ($pi['total_vat'] ?? 0),
                'with_vat'    => (float) ($pi['total_with_vat'] ?? 0),
                'rounding'    => (float) ($pi['rounding'] ?? 0),
            ],
            'amount_to_pay'      => (float) ($pi['amount_to_pay'] ?? $pi['totals']['amount_to_pay'] ?? 0),
            'advance_paid_amount' => (float) ($pi['advance_paid_amount'] ?? 0),
            'total_without_vat' => (float) ($pi['totals']['without_vat'] ?? $pi['total_without_vat'] ?? 0),
            'total_vat'         => (float) ($pi['totals']['vat'] ?? $pi['total_vat'] ?? 0),
            'total_with_vat'    => (float) ($pi['totals']['with_vat'] ?? $pi['total_with_vat'] ?? 0),
            'note_above_items'  => $pi['note_above_items'] ?? null,
            'note_below_items'  => $pi['note_below_items'] ?? null,
            // Marker pro export — pomáhá exportérovi vědět, že jde o přijatou
            '_direction'        => 'purchase',
        ];
    }

    private function paymentVariableSymbol(array $invoice): string
    {
        foreach ([
            (string) ($invoice['payment_variable_symbol'] ?? ''),
            (string) ($invoice['vendor_invoice_number'] ?? ''),
            (string) ($invoice['varsymbol'] ?? ''),
        ] as $candidate) {
            $normalized = VariableSymbolNormalizer::forPayment($candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }
        return '';
    }

    /**
     * ISDOC drží VS uvnitř PaymentMeans/Details, jehož bankovní skupina je povinná.
     * Proto vracíme snapshot i při samotném VS; prázdné bankovní elementy XSD dovoluje.
     *
     * @return array<string,?string>|null
     */
    private function paymentBankSnapshot(array $invoice, string $paymentVariableSymbol): ?array
    {
        $accountNumber = self::nullableString($invoice['payment_account_number'] ?? null);
        $bankCode = self::nullableString($invoice['payment_bank_code'] ?? null);
        $iban = self::nullableString($invoice['payment_iban'] ?? null);
        $bic = self::nullableString($invoice['payment_bic'] ?? null);

        if ($accountNumber === null && $bankCode === null && $iban === null && $bic === null
            && $paymentVariableSymbol === '') {
            return null;
        }

        return [
            'account_number' => $accountNumber,
            'bank_code'      => $bankCode,
            'bank_name'      => null,
            'iban'           => $iban,
            'bic'            => $bic,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        $string = trim((string) ($value ?? ''));
        return $string !== '' ? $string : null;
    }

    /**
     * Sjednotí rozpis DPH na kanonický tvar, který čtou exportéry (`rate`/`base`/`vat`).
     *
     * PurchaseInvoiceRepository::buildVatBreakdown vrací klíče `vat_rate`/`without_vat`/
     * `with_vat` (jiné než InvoiceRepository u vydaných faktur: `rate`/`base`/`vat`). Bez
     * tohoto přemapování četly `PohodaXmlExporter::bucketsFromBreakdown` i `classifyVat`
     * a `IsdocExporter::TaxTotal` chybějící klíče jako 0 → NULOVÝ summary/rekapitulace a
     * chybná klasifikace DPH (maxRate=0 → UNX/nonSubsume). Tolerujeme oba tvary.
     *
     * @param list<array<string,mixed>> $breakdown
     * @return list<array{rate: float, base: float, vat: float}>
     */
    public static function normalizeVatBreakdown(array $breakdown): array
    {
        $out = [];
        foreach ($breakdown as $b) {
            $out[] = [
                'rate' => (float) ($b['rate'] ?? $b['vat_rate'] ?? 0),
                'base' => (float) ($b['base'] ?? $b['without_vat'] ?? 0),
                'vat'  => (float) ($b['vat'] ?? 0),
            ];
        }
        return $out;
    }

    private function loadSupplierSnapshot(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.company_name, s.street, s.city, s.zip, s.ic, s.dic,
                    s.email, s.phone, COALESCE(c.iso2, 'CZ') AS country_iso2
               FROM supplier s
          LEFT JOIN countries c ON c.id = s.country_id
              WHERE s.id = ?"
        );
        $stmt->execute([$supplierId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    private function loadVendorSnapshot(int $vendorId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT c.company_name, c.street, c.city, c.zip, c.ic, c.dic,
                    c.main_email AS email, c.phone, COALESCE(cnt.iso2, 'CZ') AS country_iso2
               FROM clients c
          LEFT JOIN countries cnt ON cnt.id = c.country_id
              WHERE c.id = ?"
        );
        $stmt->execute([$vendorId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadPohodaCfg(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ic, dic, pohoda_account_code, pohoda_centre_code,
                    pohoda_activity_code, pohoda_contract_code, pohoda_accounting_code
               FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }
}
