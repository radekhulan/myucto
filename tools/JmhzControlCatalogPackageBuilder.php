<?php

declare(strict_types=1);

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

require dirname(__DIR__) . '/api/vendor/autoload.php';

final class JmhzControlCatalogPackageBuilder
{
    private const SOURCE_SHA256 =
        '8c861badbd6229e9185482b0caaf19d6ded4797b27bf37f8b53dcb3b31151b49';
    private const SPEC_PACKAGE_KEY =
        'jmhz-xsd-1.4.3.4_dictionary-1.4.1.6_controls-source-1.4.2.8_manifest-v1';
    private const SPEC_MANIFEST_SHA256 =
        '429e3de56e37442f35fdf8a79aab4bdff49a99beb8b3ac06afa8306312c1d205';

    public function build(string $sourcePath, string $outputPath): void
    {
        if (hash_file('sha256', $sourcePath) !== self::SOURCE_SHA256) {
            throw new RuntimeException('Katalog kontrol JMHZ nemá očekávaný SHA-256.');
        }

        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $reader->setReadDataOnly(false);
        $workbook = $reader->load($sourcePath);
        $controls = $this->controls(
            $workbook->getSheetByName('MH')
                ?? throw new RuntimeException('Katalog kontrol JMHZ neobsahuje list MH.'),
        );
        $parameters = $this->parameters(
            $workbook->getSheetByName('Parametrické konstanty')
                ?? throw new RuntimeException('Katalog kontrol JMHZ neobsahuje parametrické konstanty.'),
            array_fill_keys(array_column($controls, 'control_id'), true),
        );

        $attributeRefs = array_merge(...array_column($controls, 'attribute_refs'));
        $parameterControlRefs = array_merge(...array_column($parameters, 'control_refs'));
        $parameterValues = array_merge(...array_column($parameters, 'values'));
        $payload = [
            'schema_version' => 'jmhz-control-source-catalog.v4',
            'catalog_key' => 'jmhz-controls-1.4.2.8-source-v4',
            'version' => '1.4.2.8',
            'spec_package_key' => self::SPEC_PACKAGE_KEY,
            'spec_manifest_sha256' => self::SPEC_MANIFEST_SHA256,
            'source' => [
                'filename' => basename($sourcePath),
                'sha256' => self::SOURCE_SHA256,
                'sheets' => ['MH', 'Parametrické konstanty'],
            ],
            'counts' => [
                'controls' => count($controls),
                'attribute_refs' => count($attributeRefs),
                'unique_attributes' => count(array_unique(array_column($attributeRefs, 'attribute_id'))),
                'symbolic_attribute_refs' => array_sum(array_map(
                    static fn (array $row): int => count($row['symbolic_attribute_refs']),
                    $controls,
                )),
                'source_anomalies' => count(array_filter(
                    $controls,
                    static fn (array $row): bool => ($row['source_anomaly'] ?? null) !== null,
                )),
                'parameters' => count($parameters),
                'parameter_control_refs' => count($parameterControlRefs),
                'unique_parameter_controls' => count(array_unique(array_column(
                    $parameterControlRefs,
                    'control_id',
                ))),
                'missing_parameter_control_refs' => count(array_filter(
                    $parameterControlRefs,
                    static fn (array $row): bool => $row['resolution'] === 'missing',
                )),
                'parameter_values' => count($parameterValues),
                'blocking_remote_controls' => count(array_filter(
                    $controls,
                    static fn (array $row): bool => $row['remote_passability'] === 'blocking',
                )),
                'passable_remote_controls' => count(array_filter(
                    $controls,
                    static fn (array $row): bool => $row['remote_passability'] === 'passable',
                )),
                'unavailable_remote_controls' => count(array_filter(
                    $controls,
                    static fn (array $row): bool => $row['remote_passability'] === 'unavailable',
                )),
            ],
            'controls' => $controls,
            'parameters' => $parameters,
        ];
        $manifest = [
            'manifest_sha256' => hash('sha256', CanonicalJson::encode($payload)),
            'payload' => $payload,
        ];
        $json = json_encode(
            $manifest,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";
        if (file_put_contents($outputPath, $json) === false) {
            throw new RuntimeException("Nelze zapsat manifest katalogu kontrol {$outputPath}.");
        }
    }

    /** @return list<array<string, mixed>> */
    private function controls(Worksheet $sheet): array
    {
        $result = [];
        $seen = [];
        for ($row = 2; $row <= $sheet->getHighestDataRow(); ++$row) {
            $rawId = $this->text($sheet->getCell("A{$row}"));
            if ($rawId === null) {
                continue;
            }
            if (!ctype_digit($rawId)) {
                throw new RuntimeException("Neplatné ID kontroly na řádku {$row}.");
            }
            $controlId = (int) $rawId;
            if (isset($seen[$controlId])) {
                throw new RuntimeException("Duplicitní kontrola JMHZ {$controlId}.");
            }
            $seen[$controlId] = true;
            $attributeRefs = [];
            preg_match_all('/(?<![0-9])[0-9]{5}(?![0-9])/', $this->text(
                $sheet->getCell("C{$row}"),
            ) ?? '', $matches);
            foreach ($matches[0] as $attributeId) {
                if (in_array($attributeId, array_column($attributeRefs, 'attribute_id'), true)) {
                    continue;
                }
                $ref = ['attribute_id' => $attributeId, 'ordinal' => count($attributeRefs) + 1];
                $ref['row_hash'] = hash('sha256', CanonicalJson::encode($ref));
                $attributeRefs[] = $ref;
            }
            preg_match_all('/\batr\.\s*([A-Za-z0-9_]+)\b/u', $this->text(
                $sheet->getCell("C{$row}"),
            ) ?? '', $symbolMatches);
            $detailCell = $sheet->getCell("L{$row}");
            $messageCell = $sheet->getCell("M{$row}");
            $detailText = $this->requiredCachedText($detailCell);
            preg_match_all('/(?<![0-9])[0-9]{5}(?![0-9])/', $detailText, $detailMatches);
            $detailAttributeIds = array_values(array_unique($detailMatches[0]));
            $sourceAnomaly = match ([$controlId, array_column($attributeRefs, 'attribute_id'), $detailAttributeIds]) {
                [333, ['10006', '10032', '10010', '10011'], ['10016', '10495']] => [
                    'code' => 'official_detail_attribute_mismatch',
                    'source_cells' => ["B{$row}", "C{$row}", "L{$row}", "M{$row}"],
                    'declared_attribute_ids' => ['10006', '10032', '10010', '10011'],
                    'detail_attribute_ids' => ['10016', '10495'],
                    'resolution' => 'fail_closed_not_evaluable',
                ],
                default => null,
            };
            $record = [
                'control_id' => $controlId,
                'source_row' => $row,
                'name' => $this->requiredText($sheet->getCell("B{$row}")),
                'attribute_refs_raw' => $this->text($sheet->getCell("C{$row}")),
                'symbolic_attribute_refs' => array_values(array_unique($symbolMatches[1])),
                'area' => $this->text($sheet->getCell("D{$row}")),
                'scope' => $this->scope($this->requiredText($sheet->getCell("E{$row}"))),
                'owner' => $this->requiredText($sheet->getCell("F{$row}")),
                'portal_system' => $this->system($this->requiredText($sheet->getCell("G{$row}"))),
                'portal_passability' => $this->passability(
                    $this->requiredText($sheet->getCell("H{$row}")),
                ),
                'remote_system' => $this->system($this->requiredText($sheet->getCell("I{$row}"))),
                'remote_passability' => $this->passability(
                    $this->requiredText($sheet->getCell("J{$row}")),
                ),
                'category' => $this->text($sheet->getCell("K{$row}")),
                'detail_text' => $detailText,
                'detail_formula' => $this->formula($detailCell),
                'error_message' => $this->requiredCachedText($messageCell),
                'error_message_formula' => $this->formula($messageCell),
                'source_label' => $this->requiredText($sheet->getCell("N{$row}")),
                'note' => $this->text($sheet->getCell("O{$row}")),
            ];
            if ($sourceAnomaly !== null) {
                $record['source_anomaly'] = $sourceAnomaly;
            }
            $record['attribute_refs'] = $attributeRefs;
            $record['row_hash'] = hash('sha256', CanonicalJson::encode($record));
            $result[] = $record;
        }

        return $result;
    }

    /**
     * @param array<int, true> $knownControls
     * @return list<array<string, mixed>>
     */
    private function parameters(Worksheet $sheet, array $knownControls): array
    {
        $periods = [];
        for ($column = 3; $column <= 12; ++$column) {
            $period = $this->text($sheet->getCell([$column, 2]));
            if ($period !== null) {
                if (preg_match('/^\d{4}-\d{2}$/', $period) !== 1) {
                    throw new RuntimeException("Neplatné období konstanty {$period}.");
                }
                $periods[$column] = $period . '-01';
            }
        }
        $result = [];
        for ($row = 3; $row <= $sheet->getHighestDataRow(); ++$row) {
            $name = $this->text($sheet->getCell("B{$row}"));
            if ($name === null) {
                continue;
            }
            $controlRefs = [];
            $controlRefsRaw = $this->requiredText($sheet->getCell("A{$row}"));
            $controlRefsFormatted = $this->normalize(
                (string) $sheet->getCell("A{$row}")->getFormattedValue(),
            );
            $anomaly = match ([$row, $controlRefsRaw, $controlRefsFormatted]) {
                [7, '118270', '118,270'] => 'known_excel_number_format_split_118_270',
                [8, '168270', '168,270'] => 'known_excel_number_format_split_168_270',
                default => null,
            };
            $formatted = $controlRefsFormatted;
            preg_match_all('/\d+/', $formatted, $matches);
            $seenControlRefs = [];
            foreach ($matches[0] as $rawControlId) {
                $controlId = (int) $rawControlId;
                if (isset($seenControlRefs[$controlId])) {
                    throw new RuntimeException("Konstanta na řádku {$row} odkazuje duplicitně na {$controlId}.");
                }
                $seenControlRefs[$controlId] = true;
                $ref = [
                    'control_id' => $controlId,
                    'ordinal' => count($controlRefs) + 1,
                    'resolution' => isset($knownControls[$controlId]) ? 'present' : 'missing',
                ];
                $ref['row_hash'] = hash('sha256', CanonicalJson::encode($ref));
                $controlRefs[] = $ref;
            }
            $values = [];
            foreach ($periods as $column => $effectiveFrom) {
                $cell = $sheet->getCell([$column, $row]);
                $rawLexeme = $this->rawLexeme($cell);
                if ($rawLexeme === null || $this->normalize($rawLexeme) === '') {
                    continue;
                }
                $normalizedValue = $this->normalize($rawLexeme);
                $canonical = $this->decimal($normalizedValue, $cell->getCoordinate());
                $value = [
                    'source_cell' => $cell->getCoordinate(),
                    'effective_from' => $effectiveFrom,
                    'raw_type' => $cell->getDataType(),
                    'raw_value' => $rawLexeme,
                    'normalized_value' => $normalizedValue,
                    'canonical_value' => $canonical,
                ];
                $value['row_hash'] = hash('sha256', CanonicalJson::encode($value));
                $values[] = $value;
            }
            $record = [
                'parameter_key' => "source_row_{$row}",
                'source_row' => $row,
                'name' => $name,
                'control_refs_raw' => $controlRefsRaw,
                'control_refs_formatted' => $controlRefsFormatted,
                'control_refs_anomaly' => $anomaly,
                'control_refs' => $controlRefs,
                'values' => $values,
            ];
            $record['row_hash'] = hash('sha256', CanonicalJson::encode($record));
            $result[] = $record;
        }

        return $result;
    }

    private function requiredText(Cell $cell): string
    {
        return $this->text($cell)
            ?? throw new RuntimeException("Chybí povinná hodnota {$cell->getCoordinate()}.");
    }

    private function requiredCachedText(Cell $cell): string
    {
        $value = $this->formula($cell) === null ? $cell->getValue() : $cell->getOldCalculatedValue();
        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }
        if ($value === null || is_bool($value)) {
            throw new RuntimeException("Chybí cached hodnota {$cell->getCoordinate()}.");
        }
        $text = $this->normalize((string) $value);
        if ($text === '') {
            throw new RuntimeException("Chybí cached hodnota {$cell->getCoordinate()}.");
        }

        return $text;
    }

    private function text(Cell $cell): ?string
    {
        $value = $cell->getValue();
        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }
        if ($value === null || is_bool($value)) {
            return null;
        }
        $text = $this->normalize((string) $value);

        return $text === '' ? null : $text;
    }

    private function formula(Cell $cell): ?string
    {
        $value = $cell->getValue();

        return is_string($value) && str_starts_with($value, '=') ? $value : null;
    }

    private function rawLexeme(Cell $cell): ?string
    {
        $value = $cell->getValue();
        if ($value instanceof RichText) {
            return $value->getPlainText();
        }
        if ($value === null || is_bool($value)) {
            return null;
        }

        return (string) $value;
    }

    private function normalize(string $value): string
    {
        return trim(str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $value));
    }

    private function decimal(string $value, string $coordinate): string
    {
        $value = $this->normalize($value);
        if (preg_match('/^-?\d+(?:\.\d+)?$/', $value) !== 1) {
            throw new RuntimeException("Hodnota {$coordinate} není kanonické desetinné číslo.");
        }
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return $value === '-0' ? '0' : $value;
    }

    private function scope(string $value): string
    {
        return match ($value) {
            'Formulář PVPOJ (pvpoj)' => 'pvpoj',
            'Formulář zaměstnance (form)' => 'employee_form',
            'Měsíční podání JMHZ (global)' => 'global',
            'Měsíční podání JMHZ (nezařazeno)' => 'unassigned',
            'Souhrnná vrstva (souhrn)' => 'summary',
            'n/a' => 'unavailable',
            default => throw new RuntimeException("Neznámý rozsah kontroly {$value}."),
        };
    }

    private function system(string $value): string
    {
        return match ($value) {
            'ePortál' => 'eportal',
            'DIS' => 'dis',
            'cJMHZ' => 'cjmhz',
            'n/a' => 'unavailable',
            default => throw new RuntimeException("Neznámý systém kontroly {$value}."),
        };
    }

    private function passability(string $value): string
    {
        return match ($value) {
            'nepropustná' => 'blocking',
            'propustná' => 'passable',
            'n/a' => 'unavailable',
            default => throw new RuntimeException("Neznámá propustnost kontroly {$value}."),
        };
    }
}

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Builder katalogu kontrol JMHZ je určen pouze pro CLI.');
}
$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 3) {
    fwrite(STDERR, "Použití: php tools/JmhzControlCatalogPackageBuilder.php <kontroly.xlsx> <manifest.json>\n");
    exit(2);
}

(new JmhzControlCatalogPackageBuilder())->build($arguments[1], $arguments[2]);
fwrite(STDOUT, "Katalog kontrol JMHZ byl vytvořen.\n");
