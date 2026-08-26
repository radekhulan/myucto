<?php

declare(strict_types=1);

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

require dirname(__DIR__) . '/api/vendor/autoload.php';

final class JmhzScenarioRequirementPackageBuilder
{
    private const SOURCE_SHA256 =
        'cc282115d58a3744348b500a2dcc6eec4a5899b12753ec756f01fe261fd7ff37';
    private const SPEC_PACKAGE_KEY =
        'jmhz-xsd-1.4.3.4_dictionary-1.4.1.6_controls-source-1.4.2.8_manifest-v1';
    private const SPEC_MANIFEST_SHA256 =
        '429e3de56e37442f35fdf8a79aab4bdff49a99beb8b3ac06afa8306312c1d205';
    private const SCENARIO_SHEETS = [
        '1 až 9 s příznakem', 'M', 'K,N,O,P,Q,R,S', '1 až 9 výkon trestu',
        '11,13,14', '12', '10', 'Odložený příjem',
    ];
    private const INTERACTION_KEYS = [
        'M01', 'M01,M02', 'M01,M02,M03', 'M12', 'IN01', 'IN02', 'IN03', 'IN04', 'IN05',
        'IN06', 'IN07', 'IN08', 'IN09', 'IN10', 'IN11', 'IN12', 'IN13', 'IN14', 'IN15',
        'IN16', 'IN19', 'IN20', 'IN21', 'IN22', 'IN23', 'IN24', 'IN25', 'IN28', 'IN29',
        'IN30', 'IN31', 'IN32', 'IN33', 'IN34', 'IN35', 'IN36', 'IN37',
    ];
    private const INTERACTION_MATRIX_SHEETS = [
        'IN01', 'IN02', 'IN03', 'IN04', 'IN05', 'IN06', 'IN07', 'IN08', 'IN09', 'IN10',
        'IN11', 'IN12', 'IN13', 'IN15', 'IN16', 'IN19', 'IN20', 'IN21', 'IN22', 'IN23',
        'IN24', 'IN25', 'IN28', 'IN29', 'IN30', 'IN31', 'IN32', 'IN33', 'IN34', 'IN35',
        'IN36', 'IN37', 'M01', 'M01,M02', 'M01,M02,M03', 'M12',
    ];

    public function build(string $sourcePath, string $outputDirectory): void
    {
        $this->assertSource($sourcePath);
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $reader->setReadDataOnly(false);
        $workbook = $reader->load($sourcePath);
        $this->assertWorkbookContract($workbook);

        $scenarios = $this->scenarios($this->sheet($workbook, 'Datové scénáře'));
        [$interactions, $interactionAttributeRefs] = $this->interactions(
            $this->sheet($workbook, 'Interakce'),
        );
        $matrices = [];
        foreach (['PVPOJ', 'SOUHRN'] as $sheetName) {
            $matrices[] = $this->scenarioMatrix($this->sheet($workbook, $sheetName), 'part');
        }
        foreach (self::SCENARIO_SHEETS as $sheetName) {
            $matrices[] = $this->scenarioMatrix($this->sheet($workbook, $sheetName), 'scenario');
        }
        foreach (['CORE_DATA', 'META'] as $sheetName) {
            $matrices[] = $this->interactionMatrix(
                $this->sheet($workbook, $sheetName),
                'foundation',
            );
        }
        foreach (self::INTERACTION_MATRIX_SHEETS as $sheetName) {
            $matrices[] = $this->interactionMatrix(
                $this->sheet($workbook, $sheetName),
                'interaction',
            );
        }
        $master = $this->sheet($workbook, 'MASTER');
        $masterAttributeAxis = $this->masterAttributeAxis($master);
        $matrixByKey = [];
        foreach ($matrices as $matrix) {
            if (isset($matrixByKey[$matrix['matrix_key']])) {
                throw new RuntimeException("Duplicitní matice {$matrix['matrix_key']}.");
            }
            $matrixByKey[$matrix['matrix_key']] = $matrix;
        }
        $evidenceAxes = $this->evidenceAxes(
            $master,
            $this->sheet($workbook, 'SLOVNÍK'),
            $masterAttributeAxis,
            $matrixByKey,
        );
        $projectionChecks = $this->projectionChecks($workbook, $masterAttributeAxis);
        $anomalies = $this->anomalies($workbook);

        $requirements = array_merge(...array_column($matrices, 'requirements'));
        $requirementKinds = array_count_values(array_column($requirements, 'requirement_kind'));
        $effectKinds = array_count_values(array_column($requirements, 'effect_kind'));
        $reconciliationAxes = array_values(array_filter(
            $evidenceAxes,
            static fn (array $axis): bool => $axis['axis_kind'] === 'reconciliation',
        ));
        $derivedAxes = array_values(array_filter(
            $evidenceAxes,
            static fn (array $axis): bool => $axis['axis_kind'] === 'derived_binary',
        ));
        $payload = [
            'schema_version' => 'jmhz-scenario-requirement-source-catalog.v1',
            'catalog_key' => 'jmhz-scenario-requirements-1.4.0.2-source-v1',
            'version' => '1.4.0.2',
            'spec_package_key' => self::SPEC_PACKAGE_KEY,
            'spec_manifest_sha256' => self::SPEC_MANIFEST_SHA256,
            'source' => [
                'filename' => basename($sourcePath),
                'sha256' => self::SOURCE_SHA256,
                'sheets' => array_map(
                    static fn (Worksheet $sheet): string => $sheet->getTitle(),
                    iterator_to_array($workbook->getWorksheetIterator()),
                ),
            ],
            'counts' => [
                'scenarios' => count($scenarios),
                'interactions' => count($interactions),
                'interaction_attribute_refs' => count($interactionAttributeRefs),
                'unique_interaction_attributes' => count(array_unique(array_column(
                    $interactionAttributeRefs,
                    'attribute_id',
                ))),
                'matrices' => count($matrices),
                'part_matrices' => count(array_filter($matrices, static fn (array $row): bool => $row['matrix_kind'] === 'part')),
                'scenario_matrices' => count(array_filter($matrices, static fn (array $row): bool => $row['matrix_kind'] === 'scenario')),
                'foundation_matrices' => count(array_filter($matrices, static fn (array $row): bool => $row['matrix_kind'] === 'foundation')),
                'interaction_matrices' => count(array_filter($matrices, static fn (array $row): bool => $row['matrix_kind'] === 'interaction')),
                'requirements' => count($requirements),
                'required_requirements' => $requirementKinds['required'] ?? 0,
                'optional_requirements' => $requirementKinds['optional'] ?? 0,
                'conditional_requirements' => $requirementKinds['conditional'] ?? 0,
                'add_effects' => $effectKinds['add'] ?? 0,
                'remove_effects' => $effectKinds['remove'] ?? 0,
                'master_attributes' => count($masterAttributeAxis),
                'reconciliation_axes' => count($reconciliationAxes),
                'reconciliation_nonempty_cells' => array_sum(array_column($reconciliationAxes, 'nonempty_count')),
                'derived_axes' => count($derivedAxes),
                'derived_one_cells' => array_sum(array_column($derivedAxes, 'one_count')),
                'derived_zero_cells' => array_sum(array_column($derivedAxes, 'zero_count')),
                'derived_blank_cells' => array_sum(array_column($derivedAxes, 'blank_count')),
                'derived_zero_axes' => count(array_filter($derivedAxes, static fn (array $axis): bool => $axis['one_count'] === 0)),
                'projection_checks' => count($projectionChecks),
                'anomalies' => count($anomalies),
            ],
            'scenarios' => $scenarios,
            'interactions' => $interactions,
            'interaction_attribute_refs' => $interactionAttributeRefs,
            'matrices' => $matrices,
            'master_attribute_axis' => $masterAttributeAxis,
            'evidence_axes' => $evidenceAxes,
            'projection_checks' => $projectionChecks,
            'anomalies' => $anomalies,
        ];
        $this->assertCounts($payload['counts']);
        $manifest = [
            'manifest_sha256' => hash('sha256', CanonicalJson::encode($payload)),
            'payload' => $payload,
        ];

        if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
            throw new RuntimeException("Nelze vytvořit adresář {$outputDirectory}.");
        }
        $targetSource = $outputDirectory . DIRECTORY_SEPARATOR . basename($sourcePath);
        if (!copy($sourcePath, $targetSource) || hash_file('sha256', $targetSource) !== self::SOURCE_SHA256) {
            throw new RuntimeException('Exact-byte kopie zdrojového sešitu selhala.');
        }
        $json = json_encode(
            $manifest,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";
        $manifestPath = $outputDirectory . DIRECTORY_SEPARATOR . 'scenario-requirement-manifest.json';
        if (file_put_contents($manifestPath, $json) === false) {
            throw new RuntimeException("Nelze zapsat {$manifestPath}.");
        }
    }

    /** @return list<array<string, mixed>> */
    private function scenarios(Worksheet $sheet): array
    {
        $sheetNames = self::SCENARIO_SHEETS;
        $result = [];
        for ($row = 9; $row <= 16; ++$row) {
            $selectorCell = $sheet->getCell("A{$row}");
            $descriptionCell = $sheet->getCell("D{$row}");
            $selectorRaw = $this->rawLexeme($selectorCell);
            $sourceSheet = $sheetNames[$row - 9];
            $record = [
                'scenario_key' => 'scenario_' . ($row - 8),
                'ordinal' => $row - 8,
                'source_row' => $row,
                'selector_raw_type' => $selectorCell->getDataType(),
                'selector_raw' => $selectorRaw,
                'selection_kind' => $row === 16 ? 'manual_raw' : 'activity_raw',
                'name_raw' => $this->requiredRawText($sheet->getCell("B{$row}")),
                'condition_raw' => $this->requiredRawText($sheet->getCell("C{$row}")),
                'business_description_raw' => $this->requiredRawText($descriptionCell),
                'business_description_cell_kind' => $descriptionCell->getValue() instanceof RichText
                    ? 'rich_text'
                    : 'plain',
                'source_sheet' => 'Datové scénáře',
                'matrix_key' => 'scenario_' . ($row - 8),
                'matrix_source_sheet' => $sourceSheet,
                'xsd_entrypoint' => $this->requiredText($sheet->getCell("E{$row}")),
                'source_cells' => [
                    'selector' => "Datové scénáře!A{$row}",
                    'name' => "Datové scénáře!B{$row}",
                    'condition' => "Datové scénáře!C{$row}",
                    'business_description' => "Datové scénáře!D{$row}",
                    'xsd_entrypoint' => "Datové scénáře!E{$row}",
                ],
            ];
            $record['row_hash'] = $this->hash($record);
            $result[] = $record;
        }

        return $result;
    }

    /** @return array{list<array<string, mixed>>, list<array<string, mixed>>} */
    private function interactions(Worksheet $sheet): array
    {
        $result = [];
        $refs = [];
        for ($row = 4; $row <= 40; ++$row) {
            $rawId = $this->requiredRawText($sheet->getCell("A{$row}"));
            $key = preg_replace('/\s+/', '', $rawId) ?? $rawId;
            if ($key !== self::INTERACTION_KEYS[$row - 4]) {
                throw new RuntimeException("Neočekávaný klíč interakce Interakce!A{$row}.");
            }
            $condition = $this->requiredRawText($sheet->getCell("B{$row}"));
            preg_match_all('/(?<![0-9])[0-9]{5}(?![0-9])/', $condition, $matches);
            foreach ($matches[0] as $attributeId) {
                $ref = [
                    'interaction_key' => $key,
                    'attribute_id' => $attributeId,
                    'ordinal' => count(array_filter(
                        $refs,
                        static fn (array $ref): bool => $ref['interaction_key'] === $key,
                    )) + 1,
                    'source_cell' => "Interakce!B{$row}",
                    'source_match_raw' => $attributeId,
                ];
                $ref['row_hash'] = $this->hash($ref);
                $refs[] = $ref;
            }
            $record = [
                'ordinal' => $row - 3,
                'source_row' => $row,
                'interaction_id_raw' => $rawId,
                'interaction_key' => $key,
                'source_sheet' => 'Interakce',
                'matrix_key' => $key === 'IN14' ? null : $this->matrixKey($key),
                'trigger_kind' => str_starts_with($key, 'M')
                    ? 'month_raw'
                    : (str_contains($condition, ' + ')
                        ? 'compound_raw'
                        : (str_contains($condition, 'neřízeno atributem') ? 'virtual_raw' : 'attribute_raw')),
                'condition_raw' => $condition,
                'portal_text' => $this->rawText($sheet->getCell("C{$row}")),
                'note_raw' => $this->rawText($sheet->getCell("D{$row}")),
                'source_cells' => [
                    'interaction_id' => "Interakce!A{$row}",
                    'condition' => "Interakce!B{$row}",
                    'portal_text' => "Interakce!C{$row}",
                    'note' => "Interakce!D{$row}",
                ],
            ];
            $record['row_hash'] = $this->hash($record);
            $result[] = $record;
        }

        return [$result, $refs];
    }

    /** @return array<string, mixed> */
    private function scenarioMatrix(Worksheet $sheet, string $kind): array
    {
        $requirements = [];
        for ($row = 5; $row <= $sheet->getHighestDataRow(); ++$row) {
            if ($this->text($sheet->getCell("A{$row}")) === null) {
                continue;
            }
            $requirements[] = $this->requirement(
                $sheet,
                $row,
                'I',
                'J',
                null,
                null,
                null,
            );
        }
        $selectorRaw = $kind === 'scenario'
            ? $this->requiredRawText($sheet->getCell('G4'))
            : null;

        return $this->matrix(
            $this->matrixKey($sheet->getTitle()),
            $kind,
            $sheet,
            4,
            $selectorRaw,
            $requirements,
        );
    }

    /** @return array<string, mixed> */
    private function interactionMatrix(Worksheet $sheet, string $kind): array
    {
        $headers = [];
        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        for ($column = 1; $column <= $highestColumn; ++$column) {
            $header = $this->text($sheet->getCell([$column, 1]));
            if ($header !== null) {
                $headers[$header] = Coordinate::stringFromColumnIndex($column);
            }
        }
        foreach (['ID ATRIBUTU', 'POVINNOST', 'POVINNOST - POZNÁMKA'] as $requiredHeader) {
            if (!isset($headers[$requiredHeader])) {
                throw new RuntimeException("Matice {$sheet->getTitle()} nemá {$requiredHeader}.");
            }
        }
        $effectColumn = $kind === 'foundation' ? null : ($headers[$sheet->getTitle()] ?? null);
        if ($kind === 'interaction' && $sheet->getTitle() !== 'IN37' && $effectColumn === null) {
            throw new RuntimeException("Matice {$sheet->getTitle()} nemá sloupec efektu.");
        }
        $requirements = [];
        for ($row = 2; $row <= $sheet->getHighestDataRow(); ++$row) {
            if ($this->text($sheet->getCell("A{$row}")) === null) {
                continue;
            }
            $requirements[] = $this->requirement(
                $sheet,
                $row,
                $headers['POVINNOST'],
                $headers['POVINNOST - POZNÁMKA'],
                $headers['překlad číselník'] ?? null,
                $effectColumn,
                null,
            );
        }

        return $this->matrix(
            $this->matrixKey($sheet->getTitle()),
            $kind,
            $sheet,
            1,
            null,
            $requirements,
        );
    }

    /** @return array<string, mixed> */
    private function requirement(
        Worksheet $sheet,
        int $row,
        string $requirementColumn,
        string $noteColumn,
        ?string $translationColumn,
        ?string $effectColumn,
        ?string $fixedEffect,
    ): array {
        $attributeId = $this->requiredText($sheet->getCell("A{$row}"));
        if (preg_match('/^\d{5}$/', $attributeId) !== 1) {
            throw new RuntimeException("Neplatné ID atributu {$sheet->getTitle()}!A{$row}.");
        }
        $requirementRaw = $this->requiredText($sheet->getCell("{$requirementColumn}{$row}"));
        $requirementKind = match ($requirementRaw) {
            'P' => 'required',
            'N' => 'optional',
            'NSP' => 'conditional',
            default => throw new RuntimeException(
                "Neznámá povinnost {$sheet->getTitle()}!{$requirementColumn}{$row}.",
            ),
        };
        $effectRaw = $effectColumn === null ? null : $this->rawText($sheet->getCell("{$effectColumn}{$row}"));
        $effectKind = $fixedEffect ?? match ($effectRaw) {
            '+' => 'add',
            '-' => 'remove',
            null => 'none',
            default => throw new RuntimeException("Neznámý efekt {$sheet->getTitle()}!{$effectColumn}{$row}."),
        };
        $record = [
            'attribute_id' => $attributeId,
            'source_row' => $row,
            'source_cell' => "{$sheet->getTitle()}!{$requirementColumn}{$row}",
            'name_raw' => $this->requiredRawText($sheet->getCell("B{$row}")),
            'area_raw' => $this->rawText($sheet->getCell("C{$row}")),
            'class_raw' => $this->rawText($sheet->getCell("D{$row}")),
            'subclass_raw' => $this->rawText($sheet->getCell("E{$row}")),
            'xsd_mapping_raw' => $this->rawText($sheet->getCell("F{$row}")),
            'requirement_kind' => $requirementKind,
            'requirement_raw' => $requirementRaw,
            'condition_note_raw' => $this->rawText($sheet->getCell("{$noteColumn}{$row}")),
            'translation_raw' => $translationColumn === null
                ? $this->rawText($sheet->getCell("H{$row}"))
                : $this->rawText($sheet->getCell("{$translationColumn}{$row}")),
            'effect_kind' => $effectKind,
            'effect_raw' => $effectRaw,
            'source_cells' => [
                'attribute_id' => "{$sheet->getTitle()}!A{$row}",
                'requirement' => "{$sheet->getTitle()}!{$requirementColumn}{$row}",
                'condition_note' => "{$sheet->getTitle()}!{$noteColumn}{$row}",
                'translation' => $translationColumn === null
                    ? "{$sheet->getTitle()}!H{$row}"
                    : "{$sheet->getTitle()}!{$translationColumn}{$row}",
                'effect' => $effectColumn === null ? null : "{$sheet->getTitle()}!{$effectColumn}{$row}",
            ],
        ];
        $record['row_hash'] = $this->hash($record);

        return $record;
    }

    /**
     * @param list<array<string, mixed>> $requirements
     * @return array<string, mixed>
     */
    private function matrix(
        string $key,
        string $kind,
        Worksheet $sheet,
        int $headerRow,
        ?string $selectorRaw,
        array $requirements,
    ): array {
        $record = [
            'matrix_key' => $key,
            'matrix_kind' => $kind,
            'source_sheet' => $sheet->getTitle(),
            'source_header_row' => $headerRow,
            'selector_raw' => $selectorRaw,
            'row_count' => count($requirements),
            'matrix_hash' => $this->hash(['requirements' => $requirements]),
            'requirements' => $requirements,
        ];
        $record['row_hash'] = $this->hash($record);

        return $record;
    }

    /** @return list<array<string, mixed>> */
    private function masterAttributeAxis(Worksheet $sheet): array
    {
        $result = [];
        $seen = [];
        for ($row = 2; $row <= 443; ++$row) {
            $attributeId = $this->requiredText($sheet->getCell("A{$row}"));
            if (preg_match('/^\d{5}$/', $attributeId) !== 1 || isset($seen[$attributeId])) {
                throw new RuntimeException("Neplatná MASTER osa na řádku {$row}.");
            }
            $seen[$attributeId] = true;
            $record = [
                'attribute_id' => $attributeId,
                'ordinal' => $row - 1,
                'source_row' => $row,
            ];
            $record['row_hash'] = $this->hash($record);
            $result[] = $record;
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $masterAttributeAxis
     * @param array<string, array<string, mixed>> $matrixByKey
     * @return list<array<string, mixed>>
     */
    private function evidenceAxes(
        Worksheet $sheet,
        Worksheet $formulaSheet,
        array $masterAttributeAxis,
        array $matrixByKey,
    ): array
    {
        $result = [];
        $axisByAttribute = array_column($masterAttributeAxis, null, 'source_row');
        for ($column = Coordinate::columnIndexFromString('T'); $column <= Coordinate::columnIndexFromString('CY'); ++$column) {
            $letter = Coordinate::stringFromColumnIndex($column);
            $kind = $column <= Coordinate::columnIndexFromString('BJ')
                ? 'reconciliation'
                : 'derived_binary';
            $labelRaw = $this->requiredRawText($sheet->getCell("{$letter}1"));
            $values = [];
            $members = [];
            $nonempty = 0;
            $oneCount = 0;
            $zeroCount = 0;
            $blankCount = 0;
            $dictionaryFormulas = [];
            $dictionaryCachedValues = [];
            $dictionaryFormulaCount = 0;
            $masterMatchCount = 0;
            $masterMismatchCount = 0;
            for ($row = 2; $row <= 443; ++$row) {
                $cell = $sheet->getCell("{$letter}{$row}");
                $formulaCell = $formulaSheet->getCell("{$letter}{$row}");
                if ($kind === 'reconciliation') {
                    $formula = $this->formula($formulaCell);
                    $formulaCachedValue = $this->rawFormulaCacheLexeme($formulaCell);
                    $value = $this->rawFormulaCacheLexeme($cell);
                    $dictionaryFormulas[] = $formula;
                    $dictionaryCachedValues[] = $formulaCachedValue;
                    if ($formula !== null) {
                        ++$dictionaryFormulaCount;
                    }
                    if ($formulaCachedValue === $value) {
                        ++$masterMatchCount;
                    } else {
                        ++$masterMismatchCount;
                    }
                    $values[] = $value;
                    if ($value !== null && $value !== '') {
                        ++$nonempty;
                    }
                } else {
                    $value = $this->rawFormulaCacheLexeme($cell);
                    if ($value === '1' || $value === 1) {
                        ++$oneCount;
                        $member = [
                            'attribute_id' => $axisByAttribute[$row]['attribute_id'],
                            'ordinal' => $axisByAttribute[$row]['ordinal'],
                            'source_cell' => "MASTER!{$letter}{$row}",
                            'raw_type' => $cell->getDataType(),
                            'raw_value' => '1',
                        ];
                        $member['row_hash'] = $this->hash($member);
                        $members[] = $member;
                        $values[] = '1';
                    } elseif ($value === '0' || $value === 0) {
                        ++$zeroCount;
                        $values[] = '0';
                    } elseif ($value === null || $value === '') {
                        ++$blankCount;
                        $values[] = '';
                    } else {
                        throw new RuntimeException("Odvozená osa MASTER!{$letter}{$row} není binární.");
                    }
                }
            }
            [$expectedMatrixKey, $expectedEffect] = $kind === 'reconciliation'
                ? $this->expectedMatrixAndEffect($labelRaw)
                : [null, null];
            $status = 'not_applicable';
            if ($kind === 'reconciliation') {
                $status = $this->reconciliationStatus(
                    $labelRaw,
                    $values,
                    $expectedMatrixKey,
                    $expectedEffect,
                    $matrixByKey,
                    $masterAttributeAxis,
                );
                if ($dictionaryFormulaCount !== 442 || $masterMismatchCount !== 0) {
                    $status = 'known_anomaly';
                }
            }
            $record = [
                'axis_key' => strtolower($letter),
                'axis_kind' => $kind,
                'source_column' => $letter,
                'source_sheet' => $kind === 'reconciliation' ? 'SLOVNÍK' : 'MASTER',
                'label_raw' => $labelRaw,
                'expected_matrix_key' => $expectedMatrixKey,
                'expected_effect' => $expectedEffect,
                'dimension_count' => 442,
                'explicit_cell_count' => $kind === 'reconciliation' ? $nonempty : 442,
                'nonempty_count' => $kind === 'reconciliation' ? $nonempty : $oneCount,
                'one_count' => $kind === 'derived_binary' ? $oneCount : 0,
                'zero_count' => $kind === 'derived_binary' ? $zeroCount : 0,
                'blank_count' => $kind === 'derived_binary' ? $blankCount : 442 - $nonempty,
                'raw_vector_sha256' => $this->hash($values),
                'dictionary_formula_count' => $kind === 'reconciliation' ? $dictionaryFormulaCount : 0,
                'dictionary_formula_vector_sha256' => $kind === 'reconciliation'
                    ? $this->hash($dictionaryFormulas)
                    : null,
                'dictionary_cached_vector_sha256' => $kind === 'reconciliation'
                    ? $this->hash($dictionaryCachedValues)
                    : null,
                'master_match_count' => $kind === 'reconciliation' ? $masterMatchCount : 0,
                'master_mismatch_count' => $kind === 'reconciliation' ? $masterMismatchCount : 0,
                'reconciliation_status' => $status,
                'members' => $members,
            ];
            $record['row_hash'] = $this->hash($record);
            $result[] = $record;
        }

        return $result;
    }

    /**
     * @param list<mixed> $values
     * @param array<string, array<string, mixed>> $matrixByKey
     * @param list<array<string, mixed>> $axis
     */
    private function reconciliationStatus(
        string $label,
        array $values,
        ?string $matrixKey,
        ?string $expectedEffect,
        array $matrixByKey,
        array $axis,
    ): string {
        if ($label === 'IN14') {
            return array_filter($values, static fn ($value): bool => $value !== null && $value !== '') === []
                ? 'known_anomaly'
                : throw new RuntimeException('IN14 evidence osa musí být prázdná.');
        }
        if ($matrixKey === null || !isset($matrixByKey[$matrixKey])) {
            throw new RuntimeException("Evidence osa {$label} nemá očekávanou matici.");
        }
        $expected = [];
        foreach ($matrixByKey[$matrixKey]['requirements'] as $requirement) {
            $selectedEffect = $expectedEffect ?? 'add';
            if (!in_array($matrixKey, ['core_data', 'meta'], true)
                && $requirement['effect_kind'] !== $selectedEffect
            ) {
                continue;
            }
            $expected[$requirement['attribute_id']] = $matrixKey === 'core_data'
                ? 'CORE DATA'
                : ($expectedEffect === 'remove' ? '-' : '+');
        }
        foreach ($axis as $row) {
            $actual = $values[$row['ordinal'] - 1];
            if (($expected[$row['attribute_id']] ?? '') !== ($actual ?? '')) {
                throw new RuntimeException("Reconciliation osa {$label} nesouhlasí s maticí.");
            }
        }

        return 'match';
    }

    /** @return array{?string, ?string} */
    private function expectedMatrixAndEffect(string $label): array
    {
        $effect = str_ends_with($label, '(-)') ? 'remove' : null;
        $base = preg_replace('/\(-\)$/', '', $label) ?? $label;
        $key = match ($base) {
            'CORE DATA' => 'core_data',
            'META' => 'meta',
            default => strtolower(str_replace(',', '_', $base)),
        };

        return [$key, $effect];
    }

    /**
     * @param list<array<string, mixed>> $axis
     * @return list<array<string, mixed>>
     */
    private function projectionChecks(\PhpOffice\PhpSpreadsheet\Spreadsheet $workbook, array $axis): array
    {
        $result = [];
        $master = $this->sheet($workbook, 'MASTER');
        $masterRowByAttribute = array_column($axis, 'source_row', 'attribute_id');
        $derivedByLabel = [];
        for ($column = Coordinate::columnIndexFromString('BK'); $column <= Coordinate::columnIndexFromString('CY'); ++$column) {
            $letter = Coordinate::stringFromColumnIndex($column);
            $label = $this->requiredRawText($master->getCell("{$letter}1"));
            if (isset($derivedByLabel[$label])) {
                throw new RuntimeException("Duplicitní odvozená hlavička MASTER {$label}.");
            }
            $derivedByLabel[$label] = $letter;
        }
        foreach (self::SCENARIO_SHEETS as $sheetName) {
            $sheet = $this->sheet($workbook, $sheetName);
            $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestColumn());
            for ($column = 11; $column <= $highestColumn; ++$column) {
                $letter = Coordinate::stringFromColumnIndex($column);
                $label = $this->rawText($sheet->getCell("{$letter}4"));
                if ($label === null) {
                    continue;
                }
                $values = [];
                $expectedValues = [];
                $expectedColumn = $derivedByLabel[$label] ?? null;
                for ($row = 5; $row <= $sheet->getHighestDataRow(); ++$row) {
                    $attributeId = $this->text($sheet->getCell("A{$row}"));
                    if ($attributeId === null) {
                        continue;
                    }
                    $value = $sheet->getCell("{$letter}{$row}")->getValue();
                    $values[] = $value === null ? '' : (string) $value;
                    if ($expectedColumn !== null) {
                        $masterRow = $masterRowByAttribute[$attributeId]
                            ?? throw new RuntimeException("Projekce {$sheetName}!A{$row} není na MASTER ose.");
                        $expected = $master->getCell("{$expectedColumn}{$masterRow}")->getValue();
                        $expectedValues[] = $expected === null ? '' : (string) $expected;
                    }
                }
                if ($expectedColumn !== null && $values !== $expectedValues) {
                    throw new RuntimeException("Projekce {$sheetName}!{$letter} nesouhlasí s MASTER!{$expectedColumn}.");
                }
                $record = [
                    'scenario_key' => 'scenario_' . (array_search($sheetName, self::SCENARIO_SHEETS, true) + 1),
                    'source_sheet' => $sheetName,
                    'source_column' => $letter,
                    'selector_raw' => $this->rawLexeme($sheet->getCell("{$letter}3")),
                    'label_raw' => $label,
                    'dimension_count' => count($values),
                    'expected_axis_key' => $expectedColumn === null ? null : strtolower($expectedColumn),
                    'raw_vector_sha256' => $this->hash($values),
                    'status' => $expectedColumn === null ? 'known_anomaly' : 'match',
                ];
                $record['row_hash'] = $this->hash($record);
                $result[] = $record;
            }
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function anomalies(\PhpOffice\PhpSpreadsheet\Spreadsheet $workbook): array
    {
        $scenario = $this->sheet($workbook, 'Datové scénáře');
        $master = $this->sheet($workbook, 'MASTER');
        $dictionary = $this->sheet($workbook, 'SLOVNÍK');
        $sheet12 = $this->sheet($workbook, '12');
        $in37 = $this->sheet($workbook, 'IN37');
        $expected = [
            ['kind' => 'duplicated_activity_selector_tokens', 'source_cells' => ['Datové scénáře!A9'], 'raw_details' => ['value' => $this->requiredText($scenario->getCell('A9'))]],
            ['kind' => 'rich_text_business_description', 'source_cells' => ['Datové scénáře!D13'], 'raw_details' => ['plain_text_sha256' => hash('sha256', $this->requiredText($scenario->getCell('D13')))]],
            ['kind' => 'shifted_interaction_columns', 'source_cells' => ['IN03!G1', 'IN03!H1', 'IN03!I1', 'IN03!J1', 'IN03!K1', 'IN03!L1'], 'raw_details' => ['headers' => array_map(fn (string $cell): ?string => $this->text($this->sheet($workbook, 'IN03')->getCell($cell)), ['G1', 'H1', 'I1', 'J1', 'K1', 'L1'])]],
            ['kind' => 'interaction_without_matrix', 'source_cells' => ['Interakce!A21'], 'raw_details' => ['interaction_key' => 'IN14']],
            ['kind' => 'empty_interaction_matrix', 'source_cells' => ['IN37!A1:J1'], 'raw_details' => ['interaction_key' => 'IN37', 'row_count' => 0]],
            ['kind' => 'derived_header_trailing_digit', 'source_cells' => ['MASTER!CK1', 'MASTER!CM1'], 'raw_details' => ['values' => [$this->requiredText($master->getCell('CK1')), $this->requiredText($master->getCell('CM1'))]]],
            ['kind' => 'leading_whitespace_header', 'source_cells' => ['MASTER!CY1', 'SLOVNÍK!CY1', 'IN37!J1'], 'raw_details' => ['master' => $this->rawLexeme($master->getCell('CY1')), 'dictionary' => $this->rawLexeme($dictionary->getCell('CY1')), 'interaction_matrix_header' => $this->rawLexeme($in37->getCell('J1'))]],
            ['kind' => 'generated_empty_column_header', 'source_cells' => ['12!N4'], 'raw_details' => ['raw_value' => $this->rawLexeme($sheet12->getCell('N4'))]],
            ['kind' => 'pvpoj_header_drift', 'source_cells' => ['SLOVNÍK!G1', 'MASTER!G1'], 'raw_details' => ['dictionary' => $this->requiredText($dictionary->getCell('G1')), 'master' => $this->requiredText($master->getCell('G1'))]],
            ['kind' => 'manually_materialized_reconciliation_cells', 'source_cells' => ['SLOVNÍK!AV49', 'SLOVNÍK!AV50', 'SLOVNÍK!AV51'], 'raw_details' => ['values' => array_map(fn (string $cell): ?string => $this->rawLexeme($dictionary->getCell($cell)), ['AV49', 'AV50', 'AV51'])]],
            ['kind' => 'formula_holes', 'source_cells' => ['SLOVNÍK!BI49', 'SLOVNÍK!BI50', 'SLOVNÍK!BI51'], 'raw_details' => ['values' => array_map(fn (string $cell): ?string => $this->rawLexeme($dictionary->getCell($cell)), ['BI49', 'BI50', 'BI51'])]],
        ];
        $this->assertAnomalyValues($expected, $scenario, $master, $dictionary);
        foreach ($expected as &$anomaly) {
            $anomaly['row_hash'] = $this->hash($anomaly);
        }
        unset($anomaly);

        return $expected;
    }

    /** @param list<array<string, mixed>> $anomalies */
    private function assertAnomalyValues(array $anomalies, Worksheet $scenario, Worksheet $master, Worksheet $dictionary): void
    {
        if ($this->requiredText($scenario->getCell('A9')) !== '1 až 9 s příznakem 10502 = "žádné";  15; 16; 15; 16; A až J; T až ZC') {
            throw new RuntimeException('Změnila se známá anomálie selektoru scénáře 1.');
        }
        if (!$scenario->getCell('D13')->getValue() instanceof RichText) {
            throw new RuntimeException('Datové scénáře!D13 již není RichText.');
        }
        if ($this->requiredText($master->getCell('CK1')) !== 'IN35(-)/IN04(+) 1 až 9 s příznakem 10502 = výkon trestu odnětí svobody2'
            || $this->requiredText($master->getCell('CM1')) !== 'IN35(-)/CORE DATA(+) 1 až 9 s příznakem 10502 = výkon trestu odnětí svobody2'
        ) {
            throw new RuntimeException('Změnila se známá anomálie hlaviček CK/CM.');
        }
        if ($this->rawLexeme($master->getCell('CY1')) !== ' IN37(-)'
            || $this->rawLexeme($dictionary->getCell('CY1')) !== ' IN37(-)'
        ) {
            throw new RuntimeException('Změnila se známá anomálie hlavičky CY.');
        }
        if ($this->requiredText($dictionary->getCell('G1')) !== 'PVPOJ2'
            || $this->requiredText($master->getCell('G1')) !== 'PVPOJ'
        ) {
            throw new RuntimeException('Změnila se známá anomálie hlavičky PVPOJ2.');
        }
        $workbook = $scenario->getParent();
        if (!$workbook instanceof \PhpOffice\PhpSpreadsheet\Spreadsheet) {
            throw new RuntimeException('List scénářů není součástí sešitu.');
        }
        if ($this->requiredText($this->sheet($workbook, '12')->getCell('N4')) !== 'Column1') {
            throw new RuntimeException('Změnila se známá anomálie 12!N4.');
        }
        foreach (['AV49', 'AV50', 'AV51'] as $cell) {
            if ($this->formula($dictionary->getCell($cell)) !== null) {
                throw new RuntimeException("{$cell} již není ručně materializovaná hodnota.");
            }
        }
        foreach (['BI49', 'BI50', 'BI51'] as $cell) {
            if ($dictionary->getCell($cell)->getValue() !== null) {
                throw new RuntimeException("{$cell} již není formula hole.");
            }
        }
    }

    /** @param array<string, int> $counts */
    private function assertCounts(array $counts): void
    {
        $expected = [
            'scenarios' => 8, 'interactions' => 37, 'interaction_attribute_refs' => 22,
            'unique_interaction_attributes' => 19, 'matrices' => 48, 'part_matrices' => 2,
            'scenario_matrices' => 8, 'foundation_matrices' => 2, 'interaction_matrices' => 36,
            'requirements' => 1181, 'required_requirements' => 595, 'optional_requirements' => 350,
            'conditional_requirements' => 236, 'add_effects' => 147, 'remove_effects' => 28,
            'master_attributes' => 442, 'reconciliation_axes' => 43,
            'reconciliation_nonempty_cells' => 250, 'derived_axes' => 41,
            'derived_one_cells' => 159, 'derived_zero_cells' => 17963,
            'derived_blank_cells' => 0, 'derived_zero_axes' => 15,
        ];
        foreach ($expected as $key => $value) {
            if (($counts[$key] ?? null) !== $value) {
                throw new RuntimeException("Počet {$key} je {$counts[$key]}, očekáváno {$value}.");
            }
        }
    }

    private function assertWorkbookContract(\PhpOffice\PhpSpreadsheet\Spreadsheet $workbook): void
    {
        if ($this->requiredText($this->sheet($workbook, 'Verze')->getCell('A3')) !== '1.4.0.2') {
            throw new RuntimeException('Zdrojový sešit nemá očekávanou verzi 1.4.0.2.');
        }
        if ($this->sheet($workbook, 'MASTER')->getHighestDataRow() !== 443
            || $this->sheet($workbook, 'MASTER')->getHighestDataColumn() !== 'CY'
        ) {
            throw new RuntimeException('MASTER nemá očekávané rozměry A1:CY443.');
        }
    }

    private function assertSource(string $path): void
    {
        if (!is_file($path) || hash_file('sha256', $path) !== self::SOURCE_SHA256) {
            throw new RuntimeException('Datové scénáře JMHZ nemají očekávaný SHA-256.');
        }
    }

    private function sheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $workbook, string $name): Worksheet
    {
        return $workbook->getSheetByName($name)
            ?? throw new RuntimeException("Zdrojový sešit neobsahuje list {$name}.");
    }

    private function matrixKey(string $sheetName): string
    {
        return match ($sheetName) {
            'CORE_DATA' => 'core_data',
            'META' => 'meta',
            'PVPOJ' => 'pvpoj',
            'SOUHRN' => 'souhrn',
            default => in_array($sheetName, self::SCENARIO_SHEETS, true)
                ? 'scenario_' . (array_search($sheetName, self::SCENARIO_SHEETS, true) + 1)
                : strtolower(str_replace(',', '_', $sheetName)),
        };
    }

    private function requiredText(Cell $cell): string
    {
        return $this->text($cell)
            ?? throw new RuntimeException("Chybí povinná hodnota {$cell->getWorksheet()->getTitle()}!{$cell->getCoordinate()}.");
    }

    private function requiredRawText(Cell $cell): string
    {
        return $this->rawText($cell)
            ?? throw new RuntimeException("Chybí povinná raw hodnota {$cell->getWorksheet()->getTitle()}!{$cell->getCoordinate()}.");
    }

    private function rawText(Cell $cell): ?string
    {
        $value = $cell->getValue();
        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }
        if ($value === null || is_bool($value)) {
            return null;
        }
        $text = (string) $value;

        return $text === '' ? null : $text;
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

    private function rawLexeme(Cell $cell): string|null
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

    private function formula(Cell $cell): ?string
    {
        $value = $cell->getValue();

        return is_string($value) && str_starts_with($value, '=') ? $value : null;
    }

    private function rawFormulaCacheLexeme(Cell $cell): string|int|null
    {
        $value = $this->formula($cell) === null ? $cell->getValue() : $cell->getOldCalculatedValue();
        if ($value instanceof RichText) {
            return $value->getPlainText();
        }
        if ($value === null || is_bool($value)) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && $value === floor($value)) {
            return (int) $value;
        }

        return (string) $value;
    }

    private function normalize(string $value): string
    {
        return trim(str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $value));
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', CanonicalJson::encode($value));
    }
}

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Builder scénářů JMHZ je určen pouze pro CLI.');
}
$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 3) {
    fwrite(STDERR, "Použití: php tools/JmhzScenarioRequirementPackageBuilder.php <scénáře.xlsx> <výstupní-adresář>\n");
    exit(2);
}

(new JmhzScenarioRequirementPackageBuilder())->build($arguments[1], $arguments[2]);
fwrite(STDOUT, "Katalog scénářů a povinností JMHZ byl vytvořen.\n");
