<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCodebookUnavailableException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCodebookValueException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzExternalCodebookCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use PHPUnit\Framework\TestCase;

final class JmhzExternalCodebookCatalogTest extends TestCase
{
    public function testResolvesMunicipalityAndCountryAcrossRegisteredLegalPeriods(): void
    {
        $catalog = $this->catalog();

        self::assertSame(
            'Hlavní město Praha',
            $catalog->requireMunicipality('554782', 'Hlavní město Praha', '2026-08-13')['label'],
        );
        self::assertSame('Česko', $catalog->requireCountry('CZ', '2026-08-13')['label']);
        self::assertSame('Kosovo', $catalog->requireCountry('XK', '2026-08-13')['label']);
        self::assertSame('Česko', $catalog->requireKnownCountry('CZ', '2026-09-01')['label']);
        self::assertSame(
            'Hlavní město Praha',
            $catalog->requireKnownMunicipality(
                '554782',
                'Hlavní město Praha',
                '2026-09-01',
            )['label'],
        );
        self::assertCount(250, $catalog->countries('2026-08-13'));
        self::assertSame(
            [['code' => '537004', 'label' => 'Nymburk']],
            $catalog->searchMunicipalities('Nymburk', '2026-10-31', 20),
        );
    }

    public function testDateRegistryUses511ThroughAugustAnd145FromSeptember(): void
    {
        $catalog = $this->catalog();

        $august = $catalog->provenanceForDate('2026-08-31');
        self::assertSame(JmhzExternalCodebookCatalog::AUGUST_2026_OVERLAY_KEY, $august['overlay_key']);
        self::assertSame(JmhzExternalCodebookCatalog::AUGUST_2026_MANIFEST_SHA256, $august['manifest_sha256']);
        self::assertSame('2026-08-31', $august['effective_to']);
        self::assertSame('2026-08-31', $august['verified_through']);

        foreach (['2026-09-01', '2026-10-31'] as $validOn) {
            $provenance = $catalog->provenanceForDate($validOn);
            self::assertSame(JmhzExternalCodebookCatalog::DEFAULT_OVERLAY_KEY, $provenance['overlay_key']);
            self::assertSame(JmhzExternalCodebookCatalog::DEFAULT_MANIFEST_SHA256, $provenance['manifest_sha256']);
            self::assertSame('2026-09-01', $provenance['effective_from']);
        }
    }

    public function testHistoricalPackageIdentityAndHashRemainLoadable(): void
    {
        $catalog = $this->catalog();
        $manifest = $catalog->manifestForIdentity(
            JmhzExternalCodebookCatalog::HISTORICAL_OVERLAY_KEY,
            JmhzExternalCodebookCatalog::HISTORICAL_MANIFEST_SHA256,
        );

        self::assertSame(
            JmhzExternalCodebookCatalog::HISTORICAL_OVERLAY_KEY,
            $manifest['payload']['overlay_key'],
        );
        self::assertSame(JmhzExternalCodebookCatalog::HISTORICAL_MANIFEST_SHA256, $manifest['manifest_sha256']);
        self::assertTrue($catalog->hasLoadableIdentity(
            JmhzExternalCodebookCatalog::HISTORICAL_OVERLAY_KEY,
            JmhzExternalCodebookCatalog::HISTORICAL_MANIFEST_SHA256,
        ));
        self::assertFalse($catalog->hasLoadableIdentity(
            JmhzExternalCodebookCatalog::HISTORICAL_OVERLAY_KEY,
            str_repeat('0', 64),
        ));
    }

    public function testKnownValueRejectsTermBeforeOverlayEffectivity(): void
    {
        $this->expectException(JmhzCodebookUnavailableException::class);
        $this->catalog()->requireKnownCountry('CZ', '2025-12-31');
    }

    public function testRejectsNameMismatchUnknownValueAndUnverifiedDate(): void
    {
        $catalog = $this->catalog();
        try {
            $catalog->requireMunicipality('554782', 'Praha', '2026-08-13');
            self::fail('Neshodný název obce musí být odmítnut.');
        } catch (JmhzCodebookValueException $e) {
            self::assertStringContainsString('Název obce', $e->getMessage());
        }
        try {
            $catalog->requireCountry('ZZ', '2026-08-13');
            self::fail('Neznámý kód státu musí být odmítnut.');
        } catch (JmhzCodebookValueException $e) {
            self::assertStringContainsString('ZZ', $e->getMessage());
        }

        $this->expectException(JmhzCodebookUnavailableException::class);
        $catalog->requireCountry('CZ', '2027-01-01');
    }

    public function testSelfConsistentTamperedManifestStillFailsPinnedTrustAnchor(): void
    {
        $path = dirname(__DIR__, 5)
            . '/api/resources/payroll/jmhz/external-codebooks-2026-08-13/manifest.json';
        $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $manifest['payload']['codebooks'][1]['entries'][55]['label'] = 'Pozměněné Česko';
        $entry = $manifest['payload']['codebooks'][1]['entries'][55];
        unset($entry['row_hash']);
        $manifest['payload']['codebooks'][1]['entries'][55]['row_hash'] = hash(
            'sha256',
            \MyInvoice\Service\Payroll\Ruleset\CanonicalJson::encode($entry),
        );
        $manifest['payload']['codebooks'][1]['content_hash'] = hash(
            'sha256',
            \MyInvoice\Service\Payroll\Ruleset\CanonicalJson::encode([
                'entries' => $manifest['payload']['codebooks'][1]['entries'],
            ]),
        );
        $manifest['manifest_sha256'] = hash(
            'sha256',
            \MyInvoice\Service\Payroll\Ruleset\CanonicalJson::encode($manifest['payload']),
        );

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('připnutý SHA-256');
        JmhzExternalCodebookCatalog::validateManifest($manifest, true);
    }

    private function catalog(): JmhzExternalCodebookCatalog
    {
        return new JmhzExternalCodebookCatalog(new JmhzSpecPackageCatalog());
    }
}
