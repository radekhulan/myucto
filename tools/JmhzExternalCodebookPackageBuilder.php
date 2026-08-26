<?php

declare(strict_types=1);

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

require dirname(__DIR__) . '/api/vendor/autoload.php';

final class JmhzExternalCodebookPackageBuilder
{
    private const SPEC_PACKAGE_KEY =
        'jmhz-xsd-1.4.3.4_dictionary-1.4.1.6_controls-source-1.4.2.8_manifest-v1';
    private const SPEC_MANIFEST_SHA256 =
        '429e3de56e37442f35fdf8a79aab4bdff49a99beb8b3ac06afa8306312c1d205';
    private const MUNICIPALITY_COUNT = 6254;
    private const COUNTRY_SHA256 =
        '940d3ebef6d42294da79c7611654a59aef5beead3a48ffbdffdac9d0f1c58886';
    private const COUNTRY_BYTES = 24645;
    private const COUNTRY_COUNT = 250;

    /** @var array<string,array<string,mixed>> */
    private const PROFILES = [
        'historical' => [
            'package_key' => 'jmhz-external-codebooks-cisob-2026_czemalfa-2026-08-13-v1',
            'snapshot_date' => '2026-08-13',
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'verified_through' => '2026-08-13',
            'municipality_filename' => 'sb-2025-511-priloha-2-fragment-1093782.ttl',
            'municipality_sha256' => 'b4f130984c94904d083306b19e47f146e6e703847d315219daf97589a7526d44',
            'municipality_bytes' => 4485761,
            'municipality_fragment_id' => '1093782',
            'municipality_first_version' => '927290',
            'municipality_entry_valid_from' => '2026-01-01',
            'municipality_entry_valid_to' => null,
            'municipality_source_metadata' => [
                'publisher' => 'Ministerstvo vnitra České republiky, e-Sbírka',
                'legal_basis' => 'Vyhláška č. 511/2025 Sb., příloha č. 2',
                'eli' => '/eli/cz/sb/2025/511/2026-01-01',
                'source_fragment_id' => '1093782',
                'source_url' => 'https://opendata.eselpoint.gov.cz/esel-esb/právní-akt-fragment/1093782',
            ],
            'country_filename' => 'CIS1186_CS_2026-08-13.csv',
            'country_source_metadata' => [
                'publisher' => 'Český statistický úřad',
                'catalog_code' => 1186,
                'catalog_acronym' => 'CZEMALFA',
                'snapshot_on' => '2026-08-13',
                'source_url' => 'https://apl2.czso.cz/iSMS/cisdata.jsp?kodcis=1186',
                'export_format' => 'CSV (otevřená data)',
            ],
            'retrieved_on' => '2026-08-13',
        ],
        'august-coverage' => [
            'package_key' => 'jmhz-external-codebooks-cisob-511-2025-through-2026-08-31_czemalfa-2026-08-13-v1',
            'snapshot_date' => '2026-08-13',
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-08-31',
            'verified_through' => '2026-08-31',
            'municipality_filename' => 'sb-2025-511-priloha-2-fragment-1093782.ttl',
            'municipality_sha256' => 'b4f130984c94904d083306b19e47f146e6e703847d315219daf97589a7526d44',
            'municipality_bytes' => 4485761,
            'municipality_fragment_id' => '1093782',
            'municipality_first_version' => '927290',
            'municipality_entry_valid_from' => '2026-01-01',
            'municipality_entry_valid_to' => '2026-08-31',
            'municipality_source_metadata' => [
                'publisher' => 'Ministerstvo vnitra České republiky, e-Sbírka',
                'legal_basis' => 'Vyhláška č. 511/2025 Sb., příloha č. 2',
                'eli' => '/eli/cz/sb/2025/511/2026-01-01',
                'source_fragment_id' => '1093782',
                'source_url' => 'https://opendata.eselpoint.gov.cz/esel-esb/právní-akt-fragment/1093782',
                'repealed_by' => 'Vyhláška č. 145/2026 Sb.',
                'legal_effective_to' => '2026-08-31',
            ],
            'country_filename' => 'CIS1186_CS_2026-08-13.csv',
            'country_source_metadata' => [
                'publisher' => 'Český statistický úřad',
                'catalog_code' => 1186,
                'catalog_acronym' => 'CZEMALFA',
                'snapshot_on' => '2026-08-13',
                'source_url' => 'https://apl2.czso.cz/iSMS/cisdata.jsp?kodcis=1186',
                'export_url' => 'https://apl2.czso.cz/iSMS/do_cis_export?kodcis=1186&typdat=0&datpohl=13.08.2026&cisjaz=203&format=2',
                'export_format' => 'CSV (otevřená data)',
            ],
            'retrieved_on' => '2026-08-13',
        ],
        'september-2026' => [
            'package_key' => 'jmhz-external-codebooks-cisob-145-2026_czemalfa-2026-08-26-v1',
            'snapshot_date' => '2026-08-26',
            'effective_from' => '2026-09-01',
            'effective_to' => '2026-12-31',
            'verified_through' => '2026-12-31',
            'municipality_filename' => 'sb-2026-145-priloha-2-fragment-1836642.ttl',
            'municipality_sha256' => '2263cd58c4dc589e42bc48f13f30db464ffce16e611762364c73f6a1c5bbc003',
            'municipality_bytes' => 3003386,
            'municipality_fragment_id' => '1836642',
            'municipality_first_version' => '1569244',
            'municipality_entry_valid_from' => '2026-09-01',
            'municipality_entry_valid_to' => null,
            'municipality_source_metadata' => [
                'publisher' => 'Ministerstvo vnitra České republiky, e-Sbírka',
                'legal_basis' => 'Vyhláška č. 145/2026 Sb., příloha č. 2',
                'eli' => '/eli/cz/sb/2026/145/2026-09-01',
                'published_on' => '2026-08-13',
                'source_fragment_id' => '1836642',
                'source_url' => 'https://opendata.eselpoint.gov.cz/esel-esb/právní-akt-fragment/1836642',
                'legal_effective_from' => '2026-09-01',
            ],
            'country_filename' => 'CIS1186_CS_2026-08-26.csv',
            'country_source_metadata' => [
                'publisher' => 'Český statistický úřad',
                'catalog_code' => 1186,
                'catalog_acronym' => 'CZEMALFA',
                'snapshot_on' => '2026-08-26',
                'source_url' => 'https://apl2.czso.cz/iSMS/cisdata.jsp?kodcis=1186',
                'export_url' => 'https://apl2.czso.cz/iSMS/do_cis_export?kodcis=1186&typdat=0&datpohl=26.08.2026&cisjaz=203&format=2',
                'export_format' => 'CSV (otevřená data)',
            ],
            'retrieved_on' => '2026-08-26',
        ],
    ];

    public function build(string $sourceDirectory, string $outputPath): void
    {
        $this->buildProfile('historical', $sourceDirectory, $outputPath);
    }

    public function buildAugustCoverage(string $sourceDirectory, string $outputPath): void
    {
        $this->buildProfile('august-coverage', $sourceDirectory, $outputPath);
    }

    public function buildSeptember2026(string $sourceDirectory, string $outputPath): void
    {
        $this->buildProfile('september-2026', $sourceDirectory, $outputPath);
    }

    private function buildProfile(string $profileName, string $sourceDirectory, string $outputPath): void
    {
        $profile = self::PROFILES[$profileName];
        $municipalityFilename = $profile['municipality_filename'];
        $countryFilename = $profile['country_filename'];
        $municipalityPath = $sourceDirectory . DIRECTORY_SEPARATOR . $municipalityFilename;
        $countryPath = $sourceDirectory . DIRECTORY_SEPARATOR . $countryFilename;
        $this->verifySource(
            $municipalityPath,
            $profile['municipality_sha256'],
            $profile['municipality_bytes'],
            'CISOB',
        );
        $this->verifySource($countryPath, self::COUNTRY_SHA256, self::COUNTRY_BYTES, 'CZEMALFA');

        $municipalities = $this->municipalities($municipalityPath, $profile);
        $countries = $this->countries($countryPath, $countryFilename);
        $codebooks = [
            $this->codebook(
                'obce',
                'CISOB',
                $profile['effective_from'],
                $profile['effective_to'],
                $profile['verified_through'],
                $municipalityFilename,
                $profile['municipality_sha256'],
                $municipalities,
                $profile['municipality_source_metadata'],
            ),
            $this->codebook(
                'stat',
                'CZEMALFA',
                $profile['effective_from'],
                $profile['effective_to'],
                $profile['verified_through'],
                $countryFilename,
                self::COUNTRY_SHA256,
                $countries,
                $profile['country_source_metadata'],
            ),
        ];
        $payload = [
            'schema_version' => 'jmhz-external-codebook-overlay.v1',
            'overlay_key' => $profile['package_key'],
            'base_spec' => [
                'package_key' => self::SPEC_PACKAGE_KEY,
                'manifest_sha256' => self::SPEC_MANIFEST_SHA256,
            ],
            'snapshot_date' => $profile['snapshot_date'],
            'parser_version' => 1,
            'usage_policy' => 'authoritative_validation_only',
            'sources' => [
                [
                    'filename' => $municipalityFilename,
                    'byte_length' => $profile['municipality_bytes'],
                    'sha256' => $profile['municipality_sha256'],
                    'media_type' => 'text/turtle; charset=UTF-8',
                    'retrieved_on' => $profile['retrieved_on'],
                ],
                [
                    'filename' => $countryFilename,
                    'byte_length' => self::COUNTRY_BYTES,
                    'sha256' => self::COUNTRY_SHA256,
                    'media_type' => 'text/csv; charset=UTF-8; header=present',
                    'retrieved_on' => $profile['retrieved_on'],
                ],
            ],
            'codebooks' => $codebooks,
            'counts' => [
                'codebooks' => count($codebooks),
                'municipalities' => count($municipalities),
                'countries' => count($countries),
                'entries' => count($municipalities) + count($countries),
            ],
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
            throw new RuntimeException('Manifest externích číselníků JMHZ nelze zapsat.');
        }
    }

    private function verifySource(string $path, string $sha256, int $bytes, string $label): void
    {
        if (hash_file('sha256', $path) !== $sha256 || filesize($path) !== $bytes) {
            throw new RuntimeException("Oficiální zdroj {$label} nemá očekávané bajty.");
        }
    }

    /** @return list<array<string, mixed>> */
    private function municipalities(string $path, array $profile): array
    {
        $source = file_get_contents($path);
        if ($source === false
            || !mb_check_encoding($source, 'UTF-8')
            || substr_count(
                $source,
                '<esel-esb/pr%C3%A1vn%C3%AD-akt-fragment/' . $profile['municipality_fragment_id'] . '>',
            ) !== 1
            || substr_count($source, 'l-sgov-dat-sbirka-pojem:text-fragmentu') !== 1
            || !str_contains(
                $source,
                'l-sgov-dat-sbirka-pojem:má-první-verzi-fragmentu' . "\n                "
                    . $profile['municipality_first_version'] . ' ;',
            )
            || !str_contains($source, 'polo%C5%BEka/Prosty_Text> ;')
            || !str_contains($source, '>Název obce</th>')
            || !str_contains($source, '>Kód obce</th>')
        ) {
            throw new RuntimeException('Zdroj CISOB nemá očekávanou tabulku obcí.');
        }
        if (preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/s', $source, $rowMatches) !== 6257) {
            throw new RuntimeException('Zdroj CISOB nemá očekávaný počet řádků tabulky.');
        }

        $entries = [];
        $codes = [];
        $headerRows = 0;
        $footerRows = 0;
        foreach ($rowMatches[1] as $sourceIndex => $row) {
            $cellCount = preg_match_all('/<td\b[^>]*>(.*?)<\/td>/s', $row, $cellMatches);
            if ($cellCount === 0) {
                ++$headerRows;
                continue;
            }
            if ($cellCount === 8 && str_contains($row, 'Úhrn za ČR')) {
                ++$footerRows;
                continue;
            }
            if ($cellCount !== 11) {
                throw new RuntimeException("Zdroj CISOB má neplatný datový řádek {$sourceIndex}.");
            }
            $cells = array_map($this->cell(...), $cellMatches[1]);
            $code = preg_replace('/\s+/u', '', $cells[3]);
            if (!is_string($code) || preg_match('/\A[0-9]{6}\z/D', $code) !== 1) {
                throw new RuntimeException("Zdroj CISOB má neplatný kód na řádku {$sourceIndex}.");
            }
            if (isset($codes[$code])) {
                throw new RuntimeException("Zdroj CISOB obsahuje duplicitní kód {$code}.");
            }
            $codes[$code] = true;
            $row = [
                'item_code' => $code,
                'label' => $this->requiredText($cells[2], "obec {$code}"),
                'valid_from' => $profile['municipality_entry_valid_from'],
                'valid_to' => $profile['municipality_entry_valid_to'],
                'source_locator' => 'e-Sbírka fragment ' . $profile['municipality_fragment_id']
                    . ', table row ' . ($sourceIndex + 1),
                'ordinal' => count($entries) + 1,
                'metadata' => [
                    'region_name' => $this->requiredText($cells[0], "kraj obce {$code}"),
                    'district_name' => $this->requiredText($cells[1], "okres obce {$code}"),
                    'source_row' => $sourceIndex + 1,
                ],
            ];
            $row['row_hash'] = hash('sha256', CanonicalJson::encode($row));
            $entries[] = $row;
        }
        if ($headerRows !== 2 || $footerRows !== 1 || count($entries) !== self::MUNICIPALITY_COUNT) {
            throw new RuntimeException('Zdroj CISOB nemá očekávanou strukturu hlavičky, dat a součtu.');
        }

        return $entries;
    }

    /** @return list<array<string, mixed>> */
    private function countries(string $path, string $filename): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Zdroj CZEMALFA nelze otevřít.');
        }
        try {
            $raw = file_get_contents($path);
            if ($raw === false || !mb_check_encoding($raw, 'UTF-8') || str_starts_with($raw, "\xEF\xBB\xBF")) {
                throw new RuntimeException('Zdroj CZEMALFA není čisté UTF-8 bez BOM.');
            }
            $header = fgetcsv($handle, null, ',', '"', '');
            $expectedHeader = [
                'kodjaz', 'akrcis', 'kodcis', 'chodnota', 'zkrtext', 'text', 'admplod', 'admnepo', 'zeme3',
            ];
            if ($header !== $expectedHeader) {
                throw new RuntimeException('Zdroj CZEMALFA nemá očekávanou hlavičku.');
            }
            $entries = [];
            $codes = [];
            $sourceRow = 1;
            while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
                ++$sourceRow;
                if (count($cells) !== count($expectedHeader)) {
                    throw new RuntimeException("Zdroj CZEMALFA má neplatný řádek {$sourceRow}.");
                }
                [$language, $acronym, $catalogCode, $code, $shortName, $longName, $validFrom, $validTo, $alpha3]
                    = $this->csvRow($cells, $sourceRow);
                if ($language !== 'CS' || $acronym !== 'CZEMALFA' || $catalogCode !== '1186'
                    || preg_match('/\A[A-Z]{2}\z/D', $code) !== 1
                    || preg_match('/\A[A-Z]{3}\z/D', $alpha3) !== 1
                    || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $validFrom) !== 1
                    || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $validTo) !== 1
                ) {
                    throw new RuntimeException("Zdroj CZEMALFA má neplatná data na řádku {$sourceRow}.");
                }
                if (isset($codes[$code])) {
                    throw new RuntimeException("Zdroj CZEMALFA obsahuje duplicitní kód {$code}.");
                }
                $codes[$code] = true;
                $row = [
                    'item_code' => $code,
                    'label' => $this->requiredText($shortName, "země {$code}"),
                    'valid_from' => $validFrom,
                    'valid_to' => $validTo === '9999-09-09' ? null : $validTo,
                    'source_locator' => $filename . ':' . $sourceRow,
                    'ordinal' => count($entries) + 1,
                    'metadata' => [
                        'long_name' => $this->requiredText($longName, "dlouhý název země {$code}"),
                        'alpha3' => $alpha3,
                        'raw_valid_to' => $validTo,
                        'source_row' => $sourceRow,
                    ],
                ];
                $row['row_hash'] = hash('sha256', CanonicalJson::encode($row));
                $entries[] = $row;
            }
        } finally {
            fclose($handle);
        }
        if (count($entries) !== self::COUNTRY_COUNT) {
            throw new RuntimeException('Zdroj CZEMALFA nemá očekávaný počet zemí.');
        }

        return $entries;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param array<string, mixed> $sourceMetadata
     * @return array<string, mixed>
     */
    private function codebook(
        string $key,
        string $sourceName,
        string $effectiveFrom,
        ?string $effectiveTo,
        string $verifiedThrough,
        string $filename,
        string $sourceSha256,
        array $entries,
        array $sourceMetadata,
    ): array {
        return [
            'codebook_key' => $key,
            'source_name' => $sourceName,
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'verified_through' => $verifiedThrough,
            'source_filename' => $filename,
            'source_sha256' => $sourceSha256,
            'source_metadata' => $sourceMetadata,
            'entry_count' => count($entries),
            'content_hash' => hash('sha256', CanonicalJson::encode(['entries' => $entries])),
            'entries' => $entries,
        ];
    }

    private function cell(string $value): string
    {
        $value = str_replace('\\"', '"', $value);
        $value = strip_tags($value);

        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function requiredText(string $value, string $field): string
    {
        if ($value === '' || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1) {
            throw new RuntimeException("Zdroj externího číselníku má neplatné pole {$field}.");
        }

        return $value;
    }

    /**
     * @param array<int,string|null> $cells
     * @return list<string>
     */
    private function csvRow(array $cells, int $sourceRow): array
    {
        $result = [];
        foreach ($cells as $cell) {
            if (!is_string($cell)) {
                throw new RuntimeException("Zdroj CZEMALFA má neplatnou buňku na řádku {$sourceRow}.");
            }
            $result[] = $cell;
        }

        return $result;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $arguments = $_SERVER['argv'] ?? [];
    if (count($arguments) < 3 || count($arguments) > 4) {
        fwrite(
            STDERR,
            "Použití: php JmhzExternalCodebookPackageBuilder.php <source-directory> <manifest.json> [historical|august-coverage|september-2026]\n",
        );
        exit(2);
    }
    $builder = new JmhzExternalCodebookPackageBuilder();
    match ($arguments[3] ?? 'historical') {
        'historical' => $builder->build($arguments[1], $arguments[2]),
        'august-coverage' => $builder->buildAugustCoverage($arguments[1], $arguments[2]),
        'september-2026' => $builder->buildSeptember2026($arguments[1], $arguments[2]),
        default => throw new InvalidArgumentException('Neznámý profil externího číselníku JMHZ.'),
    };
}
