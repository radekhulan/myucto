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

    /**
     * Hledání obce pracoviště: bez diakritiky a s nejlepší shodou vpředu.
     * „Praha" v profilu A1 nevracela Prahu a „Plzen" bez háčku nic.
     */
    public function testMunicipalitySearchFoldsDiacriticsAndRanksBestMatchFirst(): void
    {
        $catalog = $this->catalog();

        $praha = $catalog->searchMunicipalities('Praha', '2026-10-31', 5);
        self::assertSame('554782', $praha[0]['code']);
        self::assertSame('Hlavní město Praha', $praha[0]['label']);

        $plzen = $catalog->searchMunicipalities('plzen', '2026-10-31', 5);
        self::assertSame('Plzeň', $plzen[0]['label']);

        self::assertSame(
            [['code' => '537004', 'label' => 'Nymburk']],
            $catalog->searchMunicipalities('537004', '2026-10-31', 5),
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

    /**
     * Soubory historického otisku se nedistribuují (bajtově shodné s pokrytím
     * do 31. 8.). Provenience uložená s jeho identitou dál projde ověřením
     * pro období, protože balíček zůstává registrovaný.
     */
    public function testHistoricalIdentityStaysRegisteredWithoutShippedFiles(): void
    {
        $catalog = $this->catalog();

        self::assertFalse($catalog->hasLoadableIdentity(
            JmhzExternalCodebookCatalog::HISTORICAL_OVERLAY_KEY,
            JmhzExternalCodebookCatalog::HISTORICAL_MANIFEST_SHA256,
        ));
        self::assertNotNull($catalog->workplaceProvenanceForPeriod(
            '554782', 'Hlavní město Praha', 'CZ',
            JmhzExternalCodebookCatalog::HISTORICAL_OVERLAY_KEY,
            JmhzExternalCodebookCatalog::HISTORICAL_MANIFEST_SHA256,
            '2026-08-01', '2026-08-31',
        ));
    }

    /**
     * Připnutí nové verze specifikace mění otisk manifestu registrovaného
     * balíčku. Pracoviště převzaté dřív (se starším otiskem) muselo dál projít
     * ověřením pro období — jinak by každé přepnutí verze zablokovalo měsíční
     * hlášení všem převzatým vztahům. Neznámý balíček ani neplatná obec projít
     * nesmí.
     */
    public function testStaleManifestHashOfRegisteredPackageStillVerifiesThePeriod(): void
    {
        $catalog = $this->catalog();
        $stale = str_repeat('3', 64);

        self::assertNotNull($catalog->workplaceProvenanceForPeriod(
            '554782', 'Hlavní město Praha', 'CZ',
            JmhzExternalCodebookCatalog::AUGUST_2026_OVERLAY_KEY, $stale,
            '2026-09-01', '2026-09-30',
        ));
        self::assertNull($catalog->workplaceProvenanceForPeriod(
            '554782', 'Hlavní město Praha', 'CZ',
            'jmhz-external-codebooks-neexistujici-v1', $stale,
            '2026-09-01', '2026-09-30',
        ));
        self::assertNull($catalog->workplaceProvenanceForPeriod(
            '554782', 'Praha', 'CZ',
            JmhzExternalCodebookCatalog::AUGUST_2026_OVERLAY_KEY, $stale,
            '2026-09-01', '2026-09-30',
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
            . '/api/resources/payroll/jmhz/external-codebooks-2026-08-31/manifest.json';
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

    /** Nález neověřeného pracoviště řekne konkrétní nesoulad a úřední název. */
    public function testWorkplaceMismatchMessageNamesTheOfficialMunicipality(): void
    {
        $catalog = $this->catalog();

        $message = $catalog->workplaceMismatchMessage('554782', 'Praha', '2026-10-01');
        self::assertIsString($message);
        self::assertStringContainsString('Místo výkonu práce „Praha"', $message);
        self::assertStringContainsString('opravte na „Hlavní město Praha"', $message);
        self::assertStringContainsString('Kód obce pracoviště 999999', (string) $catalog->workplaceMismatchMessage('999999', 'Praha', '2026-10-01'));
        self::assertNull($catalog->workplaceMismatchMessage('554782', 'Hlavní město Praha', '2026-10-01'));
        // Měsíc mimo pokrytí číselníku: příčina je mezera číselníku, ne název.
        self::assertNull($catalog->workplaceMismatchMessage('554782', 'Praha', '2027-01-01'));
        self::assertNull($catalog->workplaceMismatchMessage(null, 'Praha', '2026-10-01'));
    }

    private function catalog(): JmhzExternalCodebookCatalog
    {
        return new JmhzExternalCodebookCatalog(new JmhzSpecPackageCatalog());
    }
}
