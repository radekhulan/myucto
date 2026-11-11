<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecVersion;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationEmploymentStatusCodebook;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationNkpzClassification;
use PHPUnit\Framework\TestCase;

/**
 * Nabídka postavení v zaměstnání jde do formuláře profilu A1 jako JSON.
 * Kód z ní musí být řetězec, jinak formulář uložený kód „1111" v nabídce
 * nenajde a ukáže ho jako „kód mimo číselník" vedle téže platné volby.
 *
 * REGZEC 2025 i 2027 berou týž výčet EDV (list 2119). Export ČSÚ 80145 nese
 * místo něj 2219, tiskovou chybu sdělení č. 401/2024 Sb.; jinak se shoduje.
 */
final class PayrollRegistrationEmploymentStatusCodebookTest extends TestCase
{
    private const CSU_EXPORT = __DIR__ . '/../../../../../resources/payroll/nkpz/csu-80145-2026-11-03/CIS80145_CS_2026-11-03.csv';

    public function testOptionCodesAreStringsInJson(): void
    {
        foreach ([JmhzSpecVersion::V2026, JmhzSpecVersion::V2027] as $version) {
            $options = PayrollRegistrationEmploymentStatusCodebook::options($version);

            self::assertNotSame([], $options);
            foreach ($options as $option) {
                self::assertIsString($option['code']);
            }
            self::assertStringContainsString(
                '"code":"1111"',
                json_encode($options, JSON_THROW_ON_ERROR),
            );
        }
    }

    /**
     * Nabídka 2027 = nabídka 2026 (EDV REGZEC27 1.5.0.3, list „CIS Klasif.
     * postavení v zaměst", řádek 41: 2119). Od exportu ČSÚ se liší jen
     * tiskovou chybou 2219 za 2119.
     */
    public function testOptions2027EqualEdvListAndDifferFromCsuOnlyByTypo(): void
    {
        $csu = [];
        $handle = fopen(self::CSU_EXPORT, 'rb');
        self::assertIsResource($handle);
        $header = fgetcsv($handle, null, ',', '"', '');
        self::assertIsArray($header);
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $row = array_combine($header, $row);
            if (preg_match('/\A\d{4}\z/D', $row['chodnota']) === 1) {
                $csu[] = $row['chodnota'];
            }
        }
        fclose($handle);

        $options2027 = PayrollRegistrationEmploymentStatusCodebook::options(JmhzSpecVersion::V2027);
        self::assertSame(PayrollRegistrationEmploymentStatusCodebook::options(JmhzSpecVersion::V2026), $options2027);
        self::assertCount(34, $options2027);
        $codes = array_column($options2027, 'code');
        self::assertSame(['2119'], array_values(array_diff($codes, $csu)));
        self::assertSame(['2219'], array_values(array_diff($csu, $codes)));
        self::assertCount(34, PayrollRegistrationNkpzClassification::leaves());
    }

    public function testBothVintagesUse2119AndNormalizeTheCsuTypo(): void
    {
        foreach ([JmhzSpecVersion::V2026, JmhzSpecVersion::V2027] as $version) {
            self::assertTrue(PayrollRegistrationEmploymentStatusCodebook::isKnown('2119', $version));
            self::assertFalse(PayrollRegistrationEmploymentStatusCodebook::isKnown('2219', $version));
        }
        self::assertSame('2119', PayrollRegistrationEmploymentStatusCodebook::normalize('2219'));
        self::assertSame('1111', PayrollRegistrationEmploymentStatusCodebook::normalize('1111'));
        self::assertNull(PayrollRegistrationEmploymentStatusCodebook::normalize(null));
    }

    public function testDecisiveDateSelectsVersion(): void
    {
        self::assertSame(
            JmhzSpecVersion::V2026,
            PayrollRegistrationEmploymentStatusCodebook::versionForDate('2026-12-31'),
        );
        self::assertSame(
            JmhzSpecVersion::V2027,
            PayrollRegistrationEmploymentStatusCodebook::versionForDate('2027-01-01'),
        );
        self::assertSame(
            JmhzSpecVersion::V2026,
            PayrollRegistrationEmploymentStatusCodebook::versionForDate(null),
        );
    }

    public function testManifestIsPinnedAndTamperingFails(): void
    {
        $path = dirname(self::CSU_EXPORT) . '/manifest.json';
        $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(PayrollRegistrationNkpzClassification::MANIFEST_SHA256, $manifest['manifest_sha256']);
        PayrollRegistrationNkpzClassification::validateManifest($manifest, true);

        $manifest['payload']['entries'][0]['label'] = 'Pozměněno';
        $this->expectException(\UnexpectedValueException::class);
        PayrollRegistrationNkpzClassification::validateManifest($manifest);
    }

    public function testSha256sumsCoversPinnedFiles(): void
    {
        $root = dirname(self::CSU_EXPORT, 2);
        $lines = file($root . '/SHA256SUMS', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);
        $seen = [];
        foreach ($lines as $line) {
            self::assertSame(1, preg_match('/\A([0-9a-f]{64})\s+(\S.*)\z/D', $line, $m), $line);
            self::assertSame($m[1], hash_file('sha256', $root . '/' . $m[2]), "Otisk {$m[2]} nesedí.");
            $seen[] = basename($m[2]);
        }
        sort($seen);
        self::assertSame(['CIS80145_CS_2026-11-03.csv', 'manifest.json'], $seen);
    }
}
