<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export;

use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\TaxConstantsRepository;

/**
 * Resolves MyInvoice VAT classification codes to Stereo TypeOfVAT metadata.
 *
 * Zdrojem pravdy je klasifikace položky, hlavička dokladu je fallback. Chybí-li
 * obojí (legacy řádky), dopočítá se stejným defaultem jako všude jinde —
 * {@see InvoiceRepository::defaultSaleClassificationCode()}. Vlastní kopie té
 * logiky tu BÝVALA (prahy `>= 21` a `5..15` natvrdo) a fungovala jen náhodou:
 * dvě sazby, ze kterých snížená 12 % spadla do 5–15. Druhá kopie klasifikace je
 * horší než zadrátovaná konstanta, protože se rozejde tiše — proto se sem
 * nevrací a základní sazba se bere z číselníku podle roku dokladu.
 */
final class StereoVatTypeResolver
{
    /** @var array<string, string> */
    private const TYPE_OF_VAT_BY_CLASSIFICATION_CODE = [
        '1' => 'U',      // Tuzemské plnění, základní sazba
        '1M' => 'U',     // Totéž + prodej dlouhodobého majetku (§ 76/4 — jen koeficient)
        '2' => 'U',      // Tuzemské plnění, snížená sazba
        '2M' => 'U',     // Totéž + prodej dlouhodobého majetku
        '3' => 'UO',     // Tuzemské osvobozené plnění
        '20' => 'IDZ',   // Dodání zboží do jiného členského státu
        '22' => 'UVSP',  // Poskytnutí služby s místem plnění mimo tuzemsko
        '25S' => 'URP',  // Tuzemský režim přenesení daňové povinnosti
        '26' => 'UV',    // Vývoz zboží
        '26S' => 'UVSP', // Služba s místem plnění mimo tuzemsko, 3. země (§ 9/1, ř. 26)
    ];

    public function __construct(private readonly TaxConstantsRepository $taxConstants) {}

    /**
     * @param array<string,mixed> $invoice
     * @param array<string,mixed> $item
     * @return array{classification_code:?string,type_of_vat:?string,process_vat:bool,reverse_charge:bool}
     */
    public function resolveRow(array $invoice, array $item): array
    {
        $classificationCode = $this->classificationCode($invoice, $item);
        $normalizedCode = $classificationCode !== null ? $this->normalizeCode($classificationCode) : null;

        return [
            'classification_code' => $classificationCode,
            'type_of_vat' => $normalizedCode !== null ? $this->typeOfVat($normalizedCode) : null,
            'process_vat' => false,
            'reverse_charge' => $this->isReverseCharge($normalizedCode),
        ];
    }

    /**
     * Stereo TypeOfVAT is a document-wide setting. Prefer the invoice header
     * classification when present; otherwise require all rows to resolve to the
     * same Stereo type.
     *
     * @param array<string,mixed> $invoice
     * @param list<array{classification_code:?string,type_of_vat:?string,process_vat:bool,reverse_charge:bool}> $rowResolutions
     * @return array{classification_code:?string,type_of_vat:string,process_vat:bool,reverse_charge:bool}|null
     */
    public function resolveDocument(array $invoice, array $rowResolutions): ?array
    {
        $headerCode = $this->nonEmptyString($invoice['vat_classification_code'] ?? null);
        if ($headerCode !== null) {
            $normalizedCode = $this->normalizeCode($headerCode);
            $typeOfVat = $this->typeOfVat($normalizedCode);
            if ($typeOfVat !== null) {
                $reverseCharge = $this->isReverseCharge($normalizedCode);
                return [
                    'classification_code' => $headerCode,
                    'type_of_vat' => $typeOfVat,
                    'process_vat' => false,
                    'reverse_charge' => $reverseCharge,
                ];
            }
        }

        if ($rowResolutions === []) {
            return null;
        }

        $byType = [];
        $unresolvedCodes = [];
        foreach ($rowResolutions as $resolution) {
            $type = $resolution['type_of_vat'] ?? null;
            if ($type === null) {
                $unresolvedCodes[] = $resolution['classification_code'] ?? '?';
                continue;
            }
            $byType[$type] ??= [
                'classification_code' => $resolution['classification_code'] ?? null,
                'type_of_vat' => $type,
                'process_vat' => false,
                'reverse_charge' => !empty($resolution['reverse_charge']),
            ];
            $byType[$type]['reverse_charge'] = $byType[$type]['reverse_charge'] || !empty($resolution['reverse_charge']);
        }

        if ($unresolvedCodes !== [] || count($byType) > 1) {
            throw new \RuntimeException(sprintf(
                'Stereo XML vyžaduje jeden Typ DPH pro celý doklad %s. Nalezené typy: %s%s.',
                $this->documentLabel($invoice),
                implode(', ', array_keys($byType)) ?: '-',
                $unresolvedCodes !== [] ? '; nepřeložené kódy: ' . implode(', ', array_unique($unresolvedCodes)) : '',
            ));
        }

        if ($byType === []) {
            return null;
        }

        /** @var array{classification_code:?string,type_of_vat:string,process_vat:bool,reverse_charge:bool} */
        return reset($byType);
    }

    /**
     * @param array<string,mixed> $invoice
     * @param array{type_of_vat:string}|null $documentResolution
     */
    public function documentProcessVat(array $invoice, ?array $documentResolution): ?bool
    {
        if ($documentResolution === null) {
            return null;
        }

        return (string) ($invoice['invoice_type'] ?? '') !== 'proforma';
    }

    /**
     * @param array{reverse_charge:bool}|null $documentResolution
     */
    public function documentReverseCharge(?array $documentResolution): ?bool
    {
        if ($documentResolution === null) {
            return null;
        }

        return !empty($documentResolution['reverse_charge']);
    }

    /**
     * @param array<string,mixed> $invoice
     * @param array<string,mixed> $item
     */
    private function classificationCode(array $invoice, array $item): ?string
    {
        return $this->nonEmptyString($item['vat_classification_code'] ?? null)
            ?? $this->nonEmptyString($invoice['vat_classification_code'] ?? null)
            ?? $this->defaultSaleClassificationCode($invoice, $item);
    }

    /**
     * Sdílený default, jen s Stereo-specifickým doplňkem pro nulovou sazbu u tuzemského
     * odběratele: sdílená klasifikace tam vrací NULL (nulový řádek nemusí být osvobozené
     * plnění § 51 — může to být přeúčtování, náhrada škody nebo plnění mimo předmět daně).
     * Stereo ale potřebuje JEDEN Typ DPH na celý doklad, takže `null` by tady neznamenalo
     * „nevíme", ale tvrdý pád exportu ({@see resolveDocument()}). Konzervativní 'UO'
     * odpovídá tomu, co exportér posílal doteď; přesnější zařazení si uživatel zadá kódem
     * na položce, který má stejně přednost.
     *
     * @param array<string,mixed> $invoice
     * @param array<string,mixed> $item
     */
    private function defaultSaleClassificationCode(array $invoice, array $item): string
    {
        $docDate = $this->nonEmptyString($invoice['tax_date'] ?? null)
            ?? $this->nonEmptyString($invoice['issue_date'] ?? null);
        $year = $docDate !== null ? (int) substr($docDate, 0, 4) : (int) date('Y');

        return InvoiceRepository::defaultSaleClassificationCode(
            (float) ($item['vat_rate_snapshot'] ?? 0),
            !empty($invoice['reverse_charge']),
            $this->clientCountryIso2($invoice),
            $this->nonEmptyString($item['unit'] ?? null),
            $this->taxConstants->vatRateStandard($year),
        ) ?? '3';
    }

    /**
     * @param array<string,mixed> $invoice
     */
    private function clientCountryIso2(array $invoice): string
    {
        $snapshot = $this->snapshot($invoice['client_snapshot'] ?? null);
        $country = $this->nonEmptyString($snapshot['country_iso2'] ?? null)
            ?? $this->nonEmptyString($invoice['client_country_iso2'] ?? null)
            ?? 'CZ';

        return strtoupper($country);
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);
        return $string !== '' ? $string : null;
    }

    private function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    private function typeOfVat(string $normalizedCode): ?string
    {
        return self::TYPE_OF_VAT_BY_CLASSIFICATION_CODE[$normalizedCode] ?? null;
    }

    private function isReverseCharge(?string $normalizedCode): bool
    {
        return $normalizedCode === '25S';
    }

    /**
     * @param array<string,mixed> $invoice
     */
    private function documentLabel(array $invoice): string
    {
        $label = $this->nonEmptyString($invoice['varsymbol'] ?? null)
            ?? $this->nonEmptyString($invoice['id'] ?? null)
            ?? '?';

        return '#' . $label;
    }
}
