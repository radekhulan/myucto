<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzExternalCodebookCatalog;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 5) . '/tools/JmhzExternalCodebookPackageBuilder.php';

final class JmhzExternalCodebookBundleTest extends TestCase
{
    public function testBundleHasExactSourcesCountsAndDeterministicManifest(): void
    {
        $root = dirname(__DIR__, 5);
        $directory = $root . '/api/resources/payroll/jmhz/external-codebooks-2026-08-13';
        self::assertSame(
            '940d3ebef6d42294da79c7611654a59aef5beead3a48ffbdffdac9d0f1c58886',
            hash_file('sha256', $directory . '/CIS1186_CS_2026-08-13.csv'),
        );
        self::assertSame(
            'b4f130984c94904d083306b19e47f146e6e703847d315219daf97589a7526d44',
            hash_file('sha256', $directory . '/sb-2025-511-priloha-2-fragment-1093782.ttl'),
        );
        $manifest = json_decode(
            (string) file_get_contents($directory . '/manifest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        JmhzExternalCodebookCatalog::validateManifest($manifest, true);
        self::assertSame(6254, $manifest['payload']['counts']['municipalities']);
        self::assertSame(250, $manifest['payload']['counts']['countries']);
        self::assertSame(6504, $manifest['payload']['counts']['entries']);

        $temporary = tempnam(sys_get_temp_dir(), 'jmhz-codebooks-');
        self::assertIsString($temporary);
        try {
            (new \JmhzExternalCodebookPackageBuilder())->build($directory, $temporary);
            self::assertSame(
                hash_file('sha256', $directory . '/manifest.json'),
                hash_file('sha256', $temporary),
            );
        } finally {
            @unlink($temporary);
        }
    }

    public function testLegalCoverageBundlesHaveExactSourcesAndDeterministicManifests(): void
    {
        $root = dirname(__DIR__, 5);
        $builder = new \JmhzExternalCodebookPackageBuilder();
        $profiles = [
            [
                'directory' => 'external-codebooks-2026-08-31',
                'municipality' => 'sb-2025-511-priloha-2-fragment-1093782.ttl',
                'municipality_hash' => 'b4f130984c94904d083306b19e47f146e6e703847d315219daf97589a7526d44',
                'country' => 'CIS1186_CS_2026-08-13.csv',
                'build' => 'buildAugustCoverage',
            ],
            [
                'directory' => 'external-codebooks-2026-09-01',
                'municipality' => 'sb-2026-145-priloha-2-fragment-1836642.ttl',
                'municipality_hash' => '2263cd58c4dc589e42bc48f13f30db464ffce16e611762364c73f6a1c5bbc003',
                'country' => 'CIS1186_CS_2026-08-26.csv',
                'build' => 'buildSeptember2026',
            ],
        ];

        foreach ($profiles as $profile) {
            $directory = $root . '/api/resources/payroll/jmhz/' . $profile['directory'];
            self::assertSame($profile['municipality_hash'], hash_file('sha256', $directory . '/' . $profile['municipality']));
            self::assertSame(
                '940d3ebef6d42294da79c7611654a59aef5beead3a48ffbdffdac9d0f1c58886',
                hash_file('sha256', $directory . '/' . $profile['country']),
            );
            $manifest = json_decode(
                (string) file_get_contents($directory . '/manifest.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            JmhzExternalCodebookCatalog::validateManifest($manifest, true);
            self::assertSame(6504, $manifest['payload']['counts']['entries']);

            $temporary = tempnam(sys_get_temp_dir(), 'jmhz-codebooks-');
            self::assertIsString($temporary);
            try {
                $builder->{$profile['build']}($directory, $temporary);
                self::assertSame(
                    hash_file('sha256', $directory . '/manifest.json'),
                    hash_file('sha256', $temporary),
                );
            } finally {
                @unlink($temporary);
            }
        }
    }
}
