<?php

declare(strict_types=1);

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationNkpzClassification;

require dirname(__DIR__) . '/api/vendor/autoload.php';

/**
 * Staví deterministický manifest Národní klasifikace postavení v zaměstnání
 * (NKPZ, ČSÚ číselník 80145) z exportu otevřených dat iSMS.
 *
 * Datový slovník JMHZ 1.5.0.3 vede NKPZ jako externí referenci bez položek,
 * takže pro registrace s rozhodným datem od 1. 1. 2027 je zdrojem přímo ČSÚ.
 * Export je připnutý otiskem i počtem bajtů; jiný soubor znamená jiné vydání
 * a builder musí spadnout, ne tiše postavit jiný manifest.
 */
final class NkpzClassificationPackageBuilder
{
    public const SOURCE_FILENAME = 'CIS80145_CS_2026-11-03.csv';
    public const SOURCE_SHA256 =
        '9a82d70e812b520b348f2e638f7cd506b5915c2f63c79e6b43f9416b8ecabf4c';
    public const SOURCE_BYTES = 11168;
    public const RETRIEVED_ON = '2026-11-03';
    public const EXPORT_URL =
        'https://apl2.czso.cz/iSMS/do_cis_export?kodcis=80145&typdat=0&datpohl=01.01.2027&cisjaz=203&format=2';
    public const SOURCE_URL = 'https://apl2.czso.cz/iSMS/klasstru.jsp?kodcis=80145';
    public const EXPECTED_COUNT = 65;

    private const HEADER = [
        'kodjaz', 'akrcis', 'kodcis', 'uroven', 'chodnota', 'zkrtext', 'text', 'admplod', 'admnepo', 'nadvaz',
    ];

    public function build(string $sourceDirectory, string $outputPath): void
    {
        $path = $sourceDirectory . DIRECTORY_SEPARATOR . self::SOURCE_FILENAME;
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if ($bytes === false
            || strlen($bytes) !== self::SOURCE_BYTES
            || !hash_equals(self::SOURCE_SHA256, hash('sha256', $bytes))
        ) {
            throw new RuntimeException('Export NKPZ neodpovídá připnutému otisku nebo velikosti.');
        }
        if (str_starts_with($bytes, "\xEF\xBB\xBF") || !mb_check_encoding($bytes, 'UTF-8')) {
            throw new RuntimeException('Export NKPZ není čisté UTF-8 bez BOM.');
        }

        $lines = preg_split('/\r?\n/', rtrim($bytes, "\r\n"));
        if (!is_array($lines) || str_getcsv((string) array_shift($lines), ',', '"', '') !== self::HEADER) {
            throw new RuntimeException('Export NKPZ má jinou hlavičku, než builder zná.');
        }
        $entries = [];
        $byLevel = [];
        foreach ($lines as $index => $line) {
            $cells = str_getcsv($line, ',', '"', '');
            if (count($cells) !== count(self::HEADER)) {
                throw new RuntimeException('Řádek ' . ($index + 2) . ' exportu NKPZ nemá očekávaný počet sloupců.');
            }
            $row = array_combine(self::HEADER, $cells);
            if ($row['kodjaz'] !== 'CS' || $row['akrcis'] !== 'NKPZ_KL' || $row['kodcis'] !== '80145') {
                throw new RuntimeException('Řádek ' . ($index + 2) . ' nepatří do číselníku NKPZ.');
            }
            $level = (int) $row['uroven'];
            $entry = [
                'ordinal' => $index + 1,
                'code' => $row['chodnota'],
                'level' => $level,
                'parent_code' => $row['nadvaz'] === '' ? null : $row['nadvaz'],
                'label' => trim($row['text']),
                'short_label' => trim($row['zkrtext']),
                'valid_from' => $row['admplod'],
                'valid_to' => $row['admnepo'] === '9999-09-09' ? null : $row['admnepo'],
                'source_locator' => self::SOURCE_FILENAME . ', řádek ' . ($index + 2),
            ];
            $entry['row_hash'] = hash('sha256', CanonicalJson::encode($entry));
            $entries[] = $entry;
            $byLevel[(string) $level] = ($byLevel[(string) $level] ?? 0) + 1;
        }
        if (count($entries) !== self::EXPECTED_COUNT) {
            throw new RuntimeException('Export NKPZ má ' . count($entries) . ' položek, připnuto je ' . self::EXPECTED_COUNT . '.');
        }
        ksort($byLevel, SORT_STRING);

        $payload = [
            'schema_version' => PayrollRegistrationNkpzClassification::SCHEMA_VERSION,
            'package_key' => PayrollRegistrationNkpzClassification::PACKAGE_KEY,
            'parser_version' => 1,
            'publisher' => 'Český statistický úřad',
            'usage_policy' => 'authoritative_validation_only',
            'source' => [
                'filename' => self::SOURCE_FILENAME,
                'sha256' => self::SOURCE_SHA256,
                'byte_length' => self::SOURCE_BYTES,
                'media_type' => 'text/csv; charset=UTF-8; header=present',
                'url' => self::SOURCE_URL,
                'export_url' => self::EXPORT_URL,
                'retrieved_on' => self::RETRIEVED_ON,
            ],
            'classification' => [
                'catalog_code' => 80145,
                'catalog_acronym' => 'NKPZ_KL',
                'legal_basis' => 'Sdělení ČSÚ č. 401/2024 Sb., o zavedení Národní klasifikace postavení v zaměstnání (NKPZ)',
                'classification_effective_from' => '2025-01-01',
                'snapshot_for' => '2027-01-01',
            ],
            'applies_from' => PayrollRegistrationNkpzClassification::APPLIES_FROM,
            'counts' => [
                'entries' => count($entries),
                'by_level' => $byLevel,
            ],
            'content_hash' => hash('sha256', CanonicalJson::encode(['entries' => $entries])),
            'entries' => $entries,
        ];
        $manifest = [
            'manifest_sha256' => hash('sha256', CanonicalJson::encode($payload)),
            'payload' => $payload,
        ];
        PayrollRegistrationNkpzClassification::validateManifest($manifest);

        $json = json_encode(
            $manifest,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        );
        if (file_put_contents($outputPath, $json . "\n") === false) {
            throw new RuntimeException('Manifest NKPZ nelze zapsat.');
        }
        fwrite(STDOUT, sprintf(
            "NKPZ: %d položek %s, manifest_sha256=%s\n",
            count($entries),
            json_encode($byLevel, JSON_THROW_ON_ERROR),
            $manifest['manifest_sha256'],
        ));
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $root = dirname(__DIR__) . '/api/resources/payroll/nkpz/' . PayrollRegistrationNkpzClassification::DIRECTORY;
    (new NkpzClassificationPackageBuilder())->build($root, $root . '/manifest.json');
}
