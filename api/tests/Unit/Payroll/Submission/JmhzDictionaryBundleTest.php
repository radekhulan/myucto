<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use PHPUnit\Framework\TestCase;

final class JmhzDictionaryBundleTest extends TestCase
{
    private const MANIFEST_SHA256 =
        '3d8b45317198db8d21d1eda6aed304ad70bdf8448bc4a118d7092c7bd5a05fe3';

    public function testOfficialDictionaryPackageIsPinnedAndSelfConsistent(): void
    {
        $manifest = (new JmhzSpecPackageCatalog())->load(
            JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            self::MANIFEST_SHA256,
        );

        self::assertSame(self::MANIFEST_SHA256, $manifest['manifest_sha256']);
        self::assertSame(
            count($manifest['payload']['dictionary_attributes']),
            $manifest['payload']['counts']['attributes'],
        );
        self::assertSame(
            count($manifest['payload']['codebooks']),
            $manifest['payload']['counts']['codebooks'],
        );
        self::assertContains(
            'external_reference',
            array_column($manifest['payload']['codebooks'], 'source_kind'),
        );
    }

    public function testCodesArePreservedAsExactStrings(): void
    {
        $payload = (new JmhzSpecPackageCatalog())->load(
            JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            self::MANIFEST_SHA256,
        )['payload'];
        $byKey = array_column($payload['codebooks'], null, 'codebook_key');

        self::assertContains('1D+', array_column($byKey['kod_eldp']['entries'], 'item_code'));
        self::assertContains('01', array_column($byKey['sektor']['entries'], 'item_code'));
        self::assertContains(
            'x010203',
            array_column($payload['dictionary_attributes'], 'monthly_marker'),
        );
        foreach ($payload['dictionary_attributes'] as $attribute) {
            if ($attribute['codebook_key'] !== null) {
                self::assertArrayHasKey($attribute['codebook_key'], $byKey);
            }
        }
    }
}
