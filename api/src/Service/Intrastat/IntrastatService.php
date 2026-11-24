<?php

declare(strict_types=1);

namespace MyInvoice\Service\Intrastat;

use MyInvoice\Repository\IntrastatDataSource;

final class IntrastatService
{
    private const DIRECTIONS = ['arrival' => 'A', 'dispatch' => 'D'];
    private const TRANSACTION_CODES = ['11', '12'];
    private const TRANSPORT_MODES = ['2', '3', '4', '5', '7', '8', '9'];
    private const DELIVERY_TERMS = ['K', 'L', 'M', 'N'];
    private const RECORD_TYPES = ['ST'];

    public function __construct(private readonly IntrastatDataSource $source) {}

    /**
     * @param array<string,mixed> $input
     * @return array{preview:array<string,mixed>,csv_rows:list<list<string>>,filename:string}
     */
    public function prepare(int $supplierId, array $input): array
    {
        $params = $this->validateInput($input);
        $declarant = $this->source->declarant($supplierId);
        if ($declarant === null) {
            throw new IntrastatInputException([$this->issue('error', 'supplier_not_found', 'Firma nebyla nalezena.')]);
        }

        $start = new \DateTimeImmutable($params['period'] . '-01');
        $end = $start->modify('+1 month');
        $candidates = $this->source->movementRows(
            $supplierId,
            $start->format('Y-m-d'),
            $end->format('Y-m-d'),
            $params['direction'],
        );
        $eu = array_fill_keys(array_map('strtoupper', $this->source->euCountryCodes()), true);
        $eu['XI'] = true;
        $ownCountry = strtoupper($declarant['country_iso2']);
        $vatId = self::compactCode((string) ($declarant['vat_id'] ?? ''));

        $globalIssues = [];
        if ($candidates !== []) {
            $globalIssues[] = $this->issue(
                'warning',
                'movement_country_inferred',
                'Stát fyzického odeslání nebo určení je převzat ze země partnera. U trojstranného obchodu údaj ověřte.',
            );
        }
        if ($vatId === '' || preg_match('/^[A-Z0-9]{2,20}$/D', $vatId) !== 1) {
            $globalIssues[] = $this->issue(
                'error',
                'declarant_vat_id_missing',
                'Firma nemá vyplněné platné DIČ pro Intrastat.',
            );
        }

        $rows = [];
        $csvRows = [];
        $rowNumber = 0;
        $totalMass = '0';
        $totalValue = 0;

        foreach ($candidates as $candidate) {
            $snapshot = self::snapshot($candidate['partner_snapshot'] ?? null);
            $partnerCountry = strtoupper(trim((string) (
                $snapshot['country_iso2'] ?? $candidate['partner_country_iso2'] ?? ''
            )));
            $partnerCountryMissing = $partnerCountry === '';
            if (!$partnerCountryMissing && ($partnerCountry === $ownCountry || !isset($eu[$partnerCountry]))) {
                continue;
            }

            ++$rowNumber;
            $issues = [];
            if ($partnerCountryMissing) {
                $issues[] = $this->issue(
                    'error',
                    'movement_country_missing',
                    'U skladového pohybu chybí stát fyzického odeslání nebo určení.',
                );
            }
            $cn8 = self::compactCode((string) ($candidate['intrastat_cn8_code'] ?? ''));
            if (preg_match('/^[0-9]{8}$/D', $cn8) !== 1) {
                $issues[] = $this->issue('error', 'cn8_missing', 'Na skladové kartě chybí platný osmimístný kód KN8.');
            }

            $countryOfOrigin = strtoupper(trim((string) ($candidate['intrastat_country_of_origin'] ?? '')));
            if (preg_match('/^[A-Z]{2}$/D', $countryOfOrigin) !== 1) {
                $issues[] = $this->issue('error', 'country_of_origin_missing', 'Na skladové kartě chybí země původu.');
            }

            $qty = self::positiveDecimal($candidate['qty'] ?? null);
            if ($qty === null) {
                $issues[] = $this->issue('error', 'quantity_invalid', 'Skladový pohyb nemá platné kladné množství.');
            }

            $constantMass = $cn8 === '27160000';
            $unitMass = self::positiveDecimal($candidate['intrastat_net_mass_kg'] ?? null);
            if (!$constantMass && $unitMass === null) {
                $issues[] = $this->issue('error', 'net_mass_missing', 'Na skladové kartě chybí kladná čistá hmotnost.');
            }
            $netMass = $constantMass ? '0.001' : ($qty !== null && $unitMass !== null ? bcmul($qty, $unitMass, 6) : null);
            $massCsv = $netMass !== null ? self::formatMeasurement($netMass, false) : '';
            if ($massCsv !== '' && ($massCsv === '0' || !self::measurementFits($massCsv))) {
                $issues[] = $this->issue(
                    'error',
                    'net_mass_out_of_range',
                    'Vlastní hmotnost se nevejde do formátu InstatEvo 9,3.',
                );
            }

            $supplementaryUnit = strtoupper(trim((string) ($candidate['intrastat_supplementary_unit'] ?? '')));
            $supplementaryCoefficient = self::positiveDecimal(
                $candidate['intrastat_supplementary_unit_coefficient'] ?? null,
            );
            $supplementary = '0';
            if ($supplementaryUnit === 'ZZZ') {
                $supplementary = '0';
            } elseif ($supplementaryUnit !== '') {
                if ($supplementaryCoefficient === null || $qty === null) {
                    $issues[] = $this->issue(
                        'error',
                        'supplementary_coefficient_missing',
                        'Doplňková měrná jednotka nemá na skladové kartě platný koeficient.',
                    );
                    $supplementary = '';
                } else {
                    $supplementary = self::formatMeasurement(
                        bcmul($qty, $supplementaryCoefficient, 6),
                        in_array($supplementaryUnit, ['PCE', 'NPR', 'NEL'], true),
                    );
                    if ($supplementary === '0' || !self::measurementFits($supplementary)) {
                        $issues[] = $this->issue(
                            'error',
                            'supplementary_quantity_out_of_range',
                            'Množství v doplňkové jednotce se nevejde do formátu InstatEvo 9,3.',
                        );
                    }
                }
            }

            $itemQuantity = self::nonZeroDecimal($candidate['invoice_item_quantity'] ?? null);
            $sourceCzk = self::decimal($candidate['invoice_item_source_czk'] ?? null);
            $itemValue = $sourceCzk ?? self::decimal($candidate['invoice_item_value'] ?? null);
            $itemValuePositive = $itemValue !== null && bccomp(self::absolute($itemValue), '0', 8) === 1;
            if (empty($candidate['invoice_item_id']) && empty($candidate['purchase_invoice_item_id'])) {
                $issues[] = $this->issue(
                    'error',
                    'invoice_item_link_missing',
                    'Řádek skladového pohybu není navázaný na řádek faktury.',
                );
            }
            if ($itemQuantity === null || !$itemValuePositive) {
                $issues[] = $this->issue(
                    'error',
                    'invoiced_value_missing',
                    'Z navázaného řádku faktury nelze určit fakturovanou hodnotu.',
                );
            }

            $unmappedInvalid = false;
            $unmappedPresent = false;
            if (array_key_exists('invoice_unmapped_line_count', $candidate)) {
                $unmappedLineCount = filter_var(
                    $candidate['invoice_unmapped_line_count'],
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 0]],
                );
                if ($unmappedLineCount === false) {
                    $unmappedInvalid = true;
                } elseif ($unmappedLineCount > 0) {
                    $unmappedPresent = true;
                }
            }
            if (array_key_exists('invoice_unmapped_value', $candidate)) {
                $unmappedValue = self::decimal($candidate['invoice_unmapped_value']);
                if ($unmappedValue === null) {
                    $unmappedInvalid = true;
                } elseif (bccomp(self::absolute($unmappedValue), '0', 8) !== 0) {
                    $unmappedPresent = true;
                }
            }
            if ($unmappedInvalid) {
                $issues[] = $this->issue(
                    'error',
                    'invoice_unmapped_value_invalid',
                    'Hodnotu faktury mimo rozpoznané skladové zboží nelze ověřit.',
                );
            } elseif ($unmappedPresent) {
                $issues[] = $this->issue(
                    'error',
                    'invoice_unmapped_value_present',
                    'Faktura obsahuje nenulovou hodnotu mimo rozpoznané skladové zboží. Systém nedokáže bezpečně odlišit dopravu, služby, slevy a jiné položky, proto export blokuje.',
                );
            }

            $currency = strtoupper(trim((string) ($candidate['currency_code'] ?? '')));
            $rate = $currency === 'CZK' ? '1' : self::positiveDecimal($candidate['exchange_rate'] ?? null);
            if ($currency === '') {
                $issues[] = $this->issue('error', 'currency_missing', 'Na faktuře chybí měna.');
            } elseif ($currency !== 'CZK' && $rate === null) {
                $issues[] = $this->issue(
                    'error',
                    'exchange_rate_missing',
                    'Cizoměnová faktura nemá uložený kurz do CZK.',
                );
            }

            $invoicedValue = null;
            if ($qty !== null && $itemQuantity !== null && $itemValuePositive && $rate !== null) {
                $roundedValue = self::ceilProratedValue(
                    self::absolute($itemValue),
                    $qty,
                    self::absolute($itemQuantity),
                    $sourceCzk !== null ? '1' : $rate,
                );
                if (preg_match('/^[0-9]{1,14}$/D', $roundedValue) !== 1) {
                    $issues[] = $this->issue(
                        'error',
                        'invoiced_value_out_of_range',
                        'Fakturovaná hodnota překračuje limit 14 číslic formátu InstatEvo.',
                    );
                } else {
                    $invoicedValue = (int) $roundedValue;
                }
            }

            $rateDate = trim((string) ($candidate['invoice_rate_date'] ?? ''));
            if ($currency !== 'CZK' && $rateDate !== '' && substr($rateDate, 0, 7) !== $params['period']) {
                $issues[] = $this->issue(
                    'error',
                    'exchange_rate_period_mismatch',
                    'Kurz faktury neodpovídá referenčnímu měsíci skladového pohybu.',
                );
            }

            $partnerVatSource = array_key_exists('dic', $snapshot)
                ? $snapshot['dic']
                : ($candidate['partner_vat_id'] ?? '');
            $partnerVatId = self::compactCode((string) $partnerVatSource);
            if ($params['direction'] === 'dispatch' && $params['transaction_code'] === '12' && $partnerVatId === '') {
                $partnerVatId = 'QV123';
            }
            if ($params['direction'] === 'dispatch' && (
                $partnerVatId === ''
                || preg_match('/^[A-Z]{2}[A-Z0-9]{1,18}$/D', $partnerVatId) !== 1
            )) {
                $issues[] = $this->issue('error', 'partner_vat_id_missing', 'Pro odeslání chybí platné DIČ partnera.');
            }

            $description = trim((string) ($candidate['item_description'] ?? $candidate['stock_item_name'] ?? ''));
            $partnerName = trim((string) ($snapshot['company_name'] ?? $candidate['partner_name'] ?? ''));
            if (mb_strlen($description) > 80) {
                $description = mb_substr($description, 0, 80);
                $issues[] = $this->issue('warning', 'description_truncated', 'Popis zboží byl zkrácen na 80 znaků.');
            }
            $note = $params['note_1'];
            if (mb_strlen($note) > 256) {
                $note = mb_substr($note, 0, 256);
                $issues[] = $this->issue('warning', 'note_truncated', 'Interní poznámka byla zkrácena na 256 znaků.');
            }

            if ($massCsv !== '') {
                $totalMass = bcadd($totalMass, $massCsv, 3);
            }
            if ($invoicedValue !== null) {
                $totalValue += $invoicedValue;
            }

            $rows[] = [
                'row_number' => $rowNumber,
                'source_document' => [
                    'id' => (int) $candidate['document_id'],
                    'number' => $candidate['doc_number'] !== null ? (string) $candidate['doc_number'] : null,
                    'date' => (string) $candidate['doc_date'],
                    'line_id' => (int) $candidate['source_line_id'],
                    'type' => (string) $candidate['doc_type'],
                ],
                'partner_name' => $partnerName,
                'partner_country' => $partnerCountry,
                'partner_vat_id' => $params['direction'] === 'dispatch' ? $partnerVatId : '',
                'cn8_code' => $cn8,
                'country_of_origin' => $countryOfOrigin,
                'description' => $description,
                'quantity' => $qty,
                'net_mass_kg' => $massCsv !== '' ? $massCsv : null,
                'supplementary_unit' => $supplementaryUnit !== '' ? $supplementaryUnit : null,
                'supplementary_quantity' => $supplementary !== '' ? $supplementary : null,
                'invoiced_value' => $invoicedValue,
                'issues' => $issues,
            ];

            $csvRows[] = [
                (string) (int) $start->format('n'),
                $start->format('Y'),
                $vatId,
                self::DIRECTIONS[$params['direction']],
                $params['direction'] === 'dispatch' ? $partnerVatId : '',
                $partnerCountry,
                '',
                $countryOfOrigin,
                $params['transaction_code'],
                $params['transport_mode'],
                $params['delivery_terms'],
                $params['record_type'],
                $cn8,
                $params['statistical_code'],
                $description,
                $massCsv,
                $supplementary,
                $invoicedValue !== null ? (string) $invoicedValue : '',
                $note,
                '',
            ];
        }

        if ($rows === []) {
            $globalIssues[] = $this->issue(
                'warning',
                'no_rows',
                'Pro zvolené období a směr nebyly nalezeny žádné přeshraniční pohyby v EU.',
            );
        }

        $allIssues = $globalIssues;
        foreach ($rows as $row) {
            foreach ($row['issues'] as $issue) {
                $allIssues[] = $issue + ['row_number' => $row['row_number']];
            }
        }
        $errorCount = count(array_filter($allIssues, static fn (array $issue): bool => $issue['severity'] === 'error'));
        $warningCount = count(array_filter($allIssues, static fn (array $issue): bool => $issue['severity'] === 'warning'));

        return [
            'preview' => [
                'period' => $params['period'],
                'direction' => $params['direction'],
                'defaults' => [
                    'transaction_code' => $params['transaction_code'],
                    'transport_mode' => $params['transport_mode'],
                    'delivery_terms' => $params['delivery_terms'],
                    'record_type' => $params['record_type'],
                    'statistical_code' => $params['statistical_code'],
                ],
                'summary' => [
                    'row_count' => count($rows),
                    'error_count' => $errorCount,
                    'warning_count' => $warningCount,
                    'total_net_mass_kg' => $totalMass,
                    'total_invoiced_value' => $totalValue,
                ],
                'rows' => $rows,
                'issues' => $allIssues,
            ],
            'csv_rows' => $csvRows,
            'filename' => sprintf('instat-evo-%s-%s.csv', $params['period'], $params['direction']),
        ];
    }

    /** @param array<string,mixed> $input @return array<string,string> */
    private function validateInput(array $input): array
    {
        $issues = [];
        $period = trim((string) ($input['period'] ?? ''));
        if (preg_match('/^(20[2-9][0-9]|21[0-9]{2})-(0[1-9]|1[0-2])$/D', $period) !== 1 || $period < '2026-01') {
            $issues[] = $this->issue('error', 'period_invalid', 'Období musí být ve formátu RRRR-MM a nejdříve 2026-01.');
        }
        $direction = trim((string) ($input['direction'] ?? ''));
        if (!isset(self::DIRECTIONS[$direction])) {
            $issues[] = $this->issue('error', 'direction_invalid', 'Směr musí být arrival nebo dispatch.');
        }

        $params = [
            'period' => $period,
            'direction' => $direction,
            'transaction_code' => self::compactCode((string) ($input['transaction_code'] ?? '11')),
            'transport_mode' => self::compactCode((string) ($input['transport_mode'] ?? '3')),
            'delivery_terms' => self::compactCode((string) ($input['delivery_terms'] ?? 'K')),
            'record_type' => self::compactCode((string) ($input['record_type'] ?? 'ST')),
            'statistical_code' => self::compactCode((string) ($input['statistical_code'] ?? '')),
            'note_1' => trim((string) ($input['note_1'] ?? '')),
        ];
        foreach ([
            ['transaction_code', self::TRANSACTION_CODES, 'Kód transakce není v platném číselníku Intrastatu.'],
            ['transport_mode', self::TRANSPORT_MODES, 'Kód dopravy není v platném číselníku Intrastatu.'],
            ['delivery_terms', self::DELIVERY_TERMS, 'Kód dodací podmínky není v platném číselníku Intrastatu.'],
            ['record_type', self::RECORD_TYPES, 'Typ věty není v platném číselníku Intrastatu.'],
        ] as [$key, $allowed, $message]) {
            if (!in_array($params[$key], $allowed, true)) {
                $issues[] = $this->issue('error', $key . '_invalid', $message);
            }
        }
        if (preg_match('/^(|[0-9]{2})$/D', $params['statistical_code']) !== 1) {
            $issues[] = $this->issue(
                'error',
                'statistical_code_invalid',
                'Statistický znak musí být prázdný nebo mít dva znaky.',
            );
        }
        if ($issues !== []) {
            throw new IntrastatInputException($issues);
        }

        return $params;
    }

    /** @return array{severity:string,code:string,message:string} */
    private function issue(string $severity, string $code, string $message): array
    {
        return ['severity' => $severity, 'code' => $code, 'message' => $message];
    }

    private static function compactCode(string $value): string
    {
        return strtoupper((string) preg_replace('/\s+/u', '', trim($value)));
    }

    /** @return array<string,mixed> */
    private static function snapshot(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function decimal(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value !== '' && preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $value) === 1 ? $value : null;
    }

    private static function positiveDecimal(mixed $value): ?string
    {
        $decimal = self::decimal($value);
        return $decimal !== null && bccomp($decimal, '0', 6) === 1 ? $decimal : null;
    }

    private static function nonZeroDecimal(mixed $value): ?string
    {
        $decimal = self::decimal($value);
        return $decimal !== null && bccomp(self::absolute($decimal), '0', 8) === 1 ? $decimal : null;
    }

    private static function absolute(string $value): string
    {
        return str_starts_with($value, '-') ? substr($value, 1) : $value;
    }

    private static function roundPositive(string $value, int $scale): string
    {
        $increment = $scale === 0 ? '0.5' : '0.' . str_repeat('0', $scale) . '5';
        return bcadd($value, $increment, $scale);
    }

    private static function formatMeasurement(string $value, bool $wholeOnly): string
    {
        if ($wholeOnly || bccomp($value, '1', 6) >= 0) {
            return self::roundPositive($value, 0);
        }
        $rounded = self::roundPositive($value, 3);
        $trimmed = rtrim(rtrim($rounded, '0'), '.');
        return $trimmed === '' ? '0' : $trimmed;
    }

    private static function measurementFits(string $value): bool
    {
        return preg_match('/^[0-9]{1,9}(?:\.[0-9]{1,3})?$/D', $value) === 1;
    }

    private static function ceilProratedValue(
        string $itemValue,
        string $qty,
        string $itemQuantity,
        string $rate,
    ): string
    {
        [$itemValueInteger, $itemValueScale] = self::decimalInteger($itemValue);
        [$qtyInteger, $qtyScale] = self::decimalInteger($qty);
        [$itemQuantityInteger, $itemQuantityScale] = self::decimalInteger($itemQuantity);
        [$rateInteger, $rateScale] = self::decimalInteger($rate);

        $numerator = bcmul(bcmul($itemValueInteger, $qtyInteger, 0), $rateInteger, 0);
        $denominator = $itemQuantityInteger;
        $numeratorScale = $itemValueScale + $qtyScale + $rateScale;
        if ($numeratorScale > $itemQuantityScale) {
            $denominator .= str_repeat('0', $numeratorScale - $itemQuantityScale);
        } elseif ($itemQuantityScale > $numeratorScale) {
            $numerator .= str_repeat('0', $itemQuantityScale - $numeratorScale);
        }

        $integer = bcdiv($numerator, $denominator, 0);
        return bccomp(bcmod($numerator, $denominator, 0), '0', 0) === 1
            ? bcadd($integer, '1', 0)
            : $integer;
    }

    /** @return array{string,int} */
    private static function decimalInteger(string $value): array
    {
        $parts = explode('.', $value, 2);
        $fraction = $parts[1] ?? '';
        $integer = ltrim($parts[0] . $fraction, '0');
        return [$integer !== '' ? $integer : '0', strlen($fraction)];
    }

}
