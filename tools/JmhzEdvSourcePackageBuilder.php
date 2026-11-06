<?php

declare(strict_types=1);

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PhpOffice\PhpSpreadsheet\RichText\RichText;

require dirname(__DIR__) . '/api/vendor/autoload.php';

/**
 * Dokumentační balíček definice externí datové věty (EDV) REGZEC.
 *
 * Aplikace z něj za běhu nic nečte. Manifest připíná bajty oficiálního sešitu
 * a souhrn jeho listu Changelog (počty záznamů podle verze), podle kterého
 * se hlídá, že implementace REGZEC27 vychází z úplného soupisu změn.
 */
final class JmhzEdvSourcePackageBuilder
{
    private const SOURCE_SHA256 =
        '1b1a4c1ec0d1e49663d0b793539a67c655555dfc208e2a4ba792f07226bc79c0';
    private const VERSION = '1.5.0.3';
    private const SOURCE_URL =
        'https://developers.mpsv.cz/assets/documents/bbaf3cbc-d945-40d4-baa4-9f160f449ed2/'
        . 'Definice%20extern%C3%AD%20datov%C3%A9%20v%C4%9Bty_1.5.0.3.xlsx';
    private const EXPECTED_SHEETS = 33;
    private const EXPECTED_CHANGELOG_RECORDS = 286;

    public function build(string $sourcePath, string $outputDirectory): void
    {
        if (!is_file($sourcePath) || hash_file('sha256', $sourcePath) !== self::SOURCE_SHA256) {
            throw new RuntimeException('Definice externí datové věty nemá očekávaný SHA-256.');
        }
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $reader->setReadDataOnly(true);
        $workbook = $reader->load($sourcePath);
        $changelog = $workbook->getSheetByName('Changelog')
            ?? throw new RuntimeException('Definice externí datové věty neobsahuje list Changelog.');

        $byVersion = [];
        $byType = [];
        $total = 0;
        for ($row = 2; $row <= $changelog->getHighestDataRow(); ++$row) {
            $id = $this->text($changelog->getCell("A{$row}")->getValue());
            if ($id === null) {
                continue;
            }
            $version = $this->text($changelog->getCell("C{$row}")->getValue())
                ?? throw new RuntimeException("Changelog!C{$row} nemá verzi.");
            $type = $this->text($changelog->getCell("E{$row}")->getValue()) ?? '';
            $byVersion[$version] = ($byVersion[$version] ?? 0) + 1;
            if (str_starts_with($version, '1.5')) {
                $byType[$type] = ($byType[$type] ?? 0) + 1;
            }
            ++$total;
        }
        ksort($byVersion, SORT_NATURAL);
        ksort($byType, SORT_STRING);
        if ($total !== self::EXPECTED_CHANGELOG_RECORDS || $workbook->getSheetCount() !== self::EXPECTED_SHEETS) {
            throw new RuntimeException("Sešit EDV má {$total} záznamů changelogu a {$workbook->getSheetCount()} listů.");
        }
        $series15 = array_sum(array_filter(
            $byVersion,
            static fn (string $version): bool => str_starts_with($version, '1.5'),
            ARRAY_FILTER_USE_KEY,
        ));

        $payload = [
            'schema_version' => 'jmhz-edv-source-package.v1',
            'package_key' => 'jmhz-edv-' . self::VERSION . '-source-v1',
            'version' => self::VERSION,
            'source' => [
                'filename' => basename($sourcePath),
                'sha256' => self::SOURCE_SHA256,
                'url' => self::SOURCE_URL,
                'sheets' => $workbook->getSheetNames(),
            ],
            'counts' => [
                'sheets' => $workbook->getSheetCount(),
                'changelog_records' => $total,
                'changelog_records_series_1_5' => $series15,
            ],
            'changelog_records_by_version' => $byVersion,
            'changelog_records_series_1_5_by_change_type' => $byType,
        ];
        $manifest = [
            'manifest_sha256' => hash('sha256', CanonicalJson::encode($payload)),
            'payload' => $payload,
        ];

        if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
            throw new RuntimeException("Nelze vytvořit adresář {$outputDirectory}.");
        }
        $target = $outputDirectory . DIRECTORY_SEPARATOR . basename($sourcePath);
        if (!copy($sourcePath, $target) || hash_file('sha256', $target) !== self::SOURCE_SHA256) {
            throw new RuntimeException('Exact-byte kopie sešitu EDV selhala.');
        }
        $json = json_encode(
            $manifest,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";
        if (file_put_contents($outputDirectory . DIRECTORY_SEPARATOR . 'manifest.json', $json) === false) {
            throw new RuntimeException('Nelze zapsat manifest EDV.');
        }
    }

    private function text(mixed $value): ?string
    {
        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }
        if ($value === null || is_bool($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Builder EDV JMHZ je určen pouze pro CLI.');
}
$arguments = $_SERVER['argv'] ?? [];
if (count($arguments) !== 3) {
    fwrite(STDERR, "Použití: php tools/JmhzEdvSourcePackageBuilder.php <edv.xlsx> <výstupní-adresář>\n");
    exit(2);
}

(new JmhzEdvSourcePackageBuilder())->build($arguments[1], $arguments[2]);
fwrite(STDOUT, "Dokumentační balíček EDV JMHZ byl vytvořen.\n");
