<?php

declare(strict_types=1);

/**
 * Profily balíku specifikace JMHZ pro buildery
 * (`JmhzDictionaryPackageBuilder`, `JmhzControlCatalogPackageBuilder`,
 * `JmhzScenarioRequirementPackageBuilder`).
 *
 * Každý profil připíná zdrojové sešity (SHA-256), verze a identitu balíku.
 * Profil `2026` odpovídá dosud nasazenému balíku (XSD 1.4.3.6), profil `2027`
 * balíku pro rok 2027 (XSD 1.5.0.3). Builder bez volby `--profile=` používá
 * profil `2026`, takže stávající výstup zůstává bajtově stejný.
 *
 * `manifest_sha256` je otisk manifestu slovníku (`manifest.json`) daného
 * balíku; control a scenario builder ho zapisují do svých katalogů jako
 * `spec_manifest_sha256`.
 *
 * @return array<string,array<string,mixed>>
 */
return [
    '2026' => [
        'directory' => 'dictionary-1.4.1.6',
        'package_key' => 'jmhz-xsd-1.4.3.6_dictionary-1.4.1.6_controls-source-1.4.2.11_manifest-v1',
        'manifest_sha256' => '39bb516b4fff204c44f0b8a6b9e0ec40601ef73af79460bdf937777e046c00d8',
        'versions' => [
            'xsd' => '1.4.3.6',
            'dictionary' => '1.4.1.6',
            'control_catalog' => '1.4.2.11',
            'process' => '1.4.0.2',
            'instructions' => '1.4.13',
        ],
        'dictionary_sha256' => 'e794a56d3baa48dd876ad45a0deb5b1bb77c17a0cb44a3511e8ef4028be69743',
        'control_catalog_sha256' => 'e5b664c135b63774b555e067c5ed83991bd919f023f3f577bcfafd030b441b62',
        'scenario_sha256' => 'cc282115d58a3744348b500a2dcc6eec4a5899b12753ec756f01fe261fd7ff37',
        'control_catalog_key' => 'jmhz-controls-1.4.2.11-source-v4',
        'control_catalog_version' => '1.4.2.11',
        'scenario_catalog_key' => 'jmhz-scenario-requirements-1.4.0.2-source-v1',
        'scenario_catalog_version' => '1.4.0.2',
        'scenario_workbook_version' => '1.4.0.2',
        'master_dimensions' => ['rows' => 443, 'column' => 'CY'],
        'master_axes' => [['T', 'BJ', 'reconciliation'], ['BK', 'CY', 'derived_binary']],
        'interaction_keys' => [
            'M01', 'M01,M02', 'M01,M02,M03', 'M12', 'IN01', 'IN02', 'IN03', 'IN04', 'IN05',
            'IN06', 'IN07', 'IN08', 'IN09', 'IN10', 'IN11', 'IN12', 'IN13', 'IN14', 'IN15',
            'IN16', 'IN19', 'IN20', 'IN21', 'IN22', 'IN23', 'IN24', 'IN25', 'IN28', 'IN29',
            'IN30', 'IN31', 'IN32', 'IN33', 'IN34', 'IN35', 'IN36', 'IN37',
        ],
        'interaction_matrix_sheets' => [
            'IN01', 'IN02', 'IN03', 'IN04', 'IN05', 'IN06', 'IN07', 'IN08', 'IN09', 'IN10',
            'IN11', 'IN12', 'IN13', 'IN15', 'IN16', 'IN19', 'IN20', 'IN21', 'IN22', 'IN23',
            'IN24', 'IN25', 'IN28', 'IN29', 'IN30', 'IN31', 'IN32', 'IN33', 'IN34', 'IN35',
            'IN36', 'IN37', 'M01', 'M01,M02', 'M01,M02,M03', 'M12',
        ],
        'scenario_expected_counts' => [
            'scenarios' => 8, 'interactions' => 37, 'interaction_attribute_refs' => 22,
            'unique_interaction_attributes' => 19, 'matrices' => 48, 'part_matrices' => 2,
            'scenario_matrices' => 8, 'foundation_matrices' => 2, 'interaction_matrices' => 36,
            'requirements' => 1181, 'required_requirements' => 595, 'optional_requirements' => 350,
            'conditional_requirements' => 236, 'add_effects' => 147, 'remove_effects' => 28,
            'master_attributes' => 442, 'reconciliation_axes' => 43,
            'reconciliation_nonempty_cells' => 250, 'derived_axes' => 41,
            'derived_one_cells' => 159, 'derived_zero_cells' => 17963,
            'derived_blank_cells' => 0, 'derived_zero_axes' => 15,
        ],
        'anomaly_layout' => [
            'shifted_interaction_columns' => true,
            'empty_interaction_matrix' => true,
            'generated_empty_column_header' => true,
            'whitespace_column' => 'CY',
            'materialized_rows' => [49, 50, 51],
            'trailing_digit_headers' => [
                ['CK', 'IN35(-)/IN04(+) 1 až 9 s příznakem 10502 = výkon trestu odnětí svobody2'],
                ['CM', 'IN35(-)/CORE DATA(+) 1 až 9 s příznakem 10502 = výkon trestu odnětí svobody2'],
            ],
        ],
    ],
    '2027' => [
        'directory' => 'dictionary-1.5.0.3',
        'package_key' => 'jmhz-xsd-1.5.0.3_dictionary-1.5.0.3_controls-source-1.5.0.4_manifest-v1',
        'manifest_sha256' => 'fc244e34b74117cfc332b62d787738754562b91c61f33ee29370197967f8e5b0',
        'versions' => [
            'xsd' => '1.5.0.3',
            'dictionary' => '1.5.0.3',
            'control_catalog' => '1.5.0.4',
            'process' => '1.5.0.3',
            'instructions' => '1.4.14',
        ],
        'dictionary_sha256' => '861bc297adbadd475efa68214bdf194ce1b60a14337d5cf10307cf00b12e92ca',
        'control_catalog_sha256' => '748dd83a376540b85d5e0942af95ba6230671007c6e4269dda50db74faa88d35',
        'scenario_sha256' => 'c4cb0704208f463417b564fd91a1884c71789b84d2966b074af33e5c41b5f45e',
        'control_catalog_key' => 'jmhz-controls-1.5.0.4-source-v4',
        'control_catalog_version' => '1.5.0.4',
        'scenario_catalog_key' => 'jmhz-scenario-requirements-1.5.0.3-source-v1',
        'scenario_catalog_version' => '1.5.0.3',
        'scenario_workbook_version' => '1.5.0.3',
        'scenario_workbook_version_cell' => 'A7',
        'master_dimensions' => ['rows' => 455, 'column' => 'DK'],
        'master_axes' => [
            ['T', 'BJ', 'reconciliation'],
            ['BK', 'DD', 'derived_binary'],
            ['DE', 'DK', 'reconciliation'],
        ],
        'interaction_keys' => [
            'M01', 'M01,M02', 'M01,M02,M03', 'M12', 'IN01', 'IN02', 'IN03', 'IN04', 'IN05',
            'IN06', 'IN07', 'IN08', 'IN09', 'IN10', 'IN11', 'IN12', 'IN13', 'IN14', 'IN15',
            'IN16', 'IN19', 'IN20', 'IN21', 'IN22', 'IN23', 'IN24', 'IN25', 'IN28', 'IN29',
            'IN30', 'IN31', 'IN32', 'IN33', 'IN34', 'IN35', 'IN36', 'IN37', 'IN38', 'IN39',
            'IN40', 'IN41', 'IN42', 'IN43',
        ],
        'interaction_matrix_sheets' => [
            'IN01', 'IN02', 'IN03', 'IN04', 'IN05', 'IN06', 'IN07', 'IN08', 'IN09', 'IN10',
            'IN11', 'IN12', 'IN13', 'IN15', 'IN16', 'IN19', 'IN20', 'IN21', 'IN22', 'IN23',
            'IN24', 'IN25', 'IN28', 'IN29', 'IN30', 'IN31', 'IN32', 'IN33', 'IN34', 'IN35',
            'IN36', 'IN37', 'IN38', 'IN39', 'IN40', 'IN41', 'IN42', 'IN43', 'M01', 'M01,M02',
            'M01,M02,M03', 'M12',
        ],
        'allow_blank_requirement' => true,
        'scenario_expected_counts' => [
            'scenarios' => 8, 'interactions' => 43, 'interaction_attribute_refs' => 27,
            'unique_interaction_attributes' => 24, 'matrices' => 54, 'part_matrices' => 2,
            'scenario_matrices' => 8, 'foundation_matrices' => 2, 'interaction_matrices' => 42,
            'requirements' => 1437, 'required_requirements' => 762, 'optional_requirements' => 412,
            'conditional_requirements' => 254, 'unspecified_requirements' => 9,
            'add_effects' => 162, 'remove_effects' => 25,
            'master_attributes' => 454, 'reconciliation_axes' => 50,
            'reconciliation_nonempty_cells' => 267, 'derived_axes' => 46,
            'derived_one_cells' => 163, 'derived_zero_cells' => 20721,
            'derived_blank_cells' => 0, 'derived_zero_axes' => 15,
        ],
        'anomaly_layout' => [
            'shifted_interaction_columns' => false,
            'empty_interaction_matrix' => false,
            'generated_empty_column_header' => false,
            'whitespace_column' => 'DD',
            'materialized_rows' => [52, 53, 54],
            'trailing_digit_headers' => [
                ['CN', 'IN35(-)/IN04(+) 1 až 9 s příznakem 10502 = výkon trestu odnětí svobody2'],
                ['CP', 'IN35(-)/CORE DATA(+) 1 až 9 s příznakem 10502 = výkon trestu odnětí svobody2'],
            ],
        ],
    ],
];
