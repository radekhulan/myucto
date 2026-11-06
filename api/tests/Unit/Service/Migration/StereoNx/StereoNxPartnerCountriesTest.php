<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use MyInvoice\Service\Migration\StereoNx\StereoNxAccountingDocuments;
use MyInvoice\Service\Migration\StereoNx\StereoNxBackup;
use MyInvoice\Service\Migration\StereoNx\StereoNxImporter;
use MyInvoice\Service\Migration\StereoNx\StereoNxPartnerCountries;
use MyInvoice\Service\Migration\StereoNx\StereoNxSourcePlan;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxTables;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticNx1Archive;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../Fixtures/StereoNx/SyntheticStereoNxTables.php';
require_once __DIR__ . '/../../../../Fixtures/StereoNx/SyntheticNx1Archive.php';

final class StereoNxPartnerCountriesTest extends TestCase
{
    public function testConfirmedCountryResolvesOnlyCountryReviewInBothPlans(): void
    {
        foreach ([false, true] as $accounting) {
            $tables = $this->tables();
            $before = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), false, $accounting);
            $after = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), false, $accounting, null, ['V1' => 'DE']);
            self::assertSame('DE', $after['clients'][1]['country_code']);
            self::assertFalse($after['clients'][1]['country_unresolved']);
            self::assertSame('DE', $after['purchases'][0]['partner_snapshot']['country_code']);
            self::assertNotContains('partner_country_unresolved', $after['purchases'][0]['review_codes']);
            self::assertSame($before['purchases'][0]['items'], $after['purchases'][0]['items']);
            self::assertSame($before['clients'][1]['row'], $after['clients'][1]['row']);
            self::assertSame($before['purchases'][0]['header'], $after['purchases'][0]['header']);
            self::assertContains('vat_participation_unassigned', $after['purchases'][1]['review_codes']);
            self::assertTrue($after['purchases'][1]['requires_draft']);
        }
    }

    public function testExplicitCountryWinsOverBlanketCzechDefaultAndRetainsKnownHistoricalCountry(): void
    {
        $tables = $this->tables();
        $tables['SPFH'][0]['FirmaStat'] = 'PL';
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true, null, ['V1' => 'DE']);
        self::assertSame('DE', $plan['clients'][1]['country_code']);
        self::assertSame('PL', $plan['purchases'][0]['partner_snapshot']['country_code']);
        self::assertSame('DE', $plan['purchases'][1]['partner_snapshot']['country_code']);
    }

    public function testSnapshotPartnerUsesItsOwnSourceKeyAndHasCandidateDocumentIdentity(): void
    {
        $tables = $this->tables();
        $tables['SPFH'][0]['Firma'] = '';
        $tables['SPFH'][0]['FirmaNazev'] = 'Syntetická historická protistrana';
        $before = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), false, true);
        $key = $before['purchases'][0]['partner_key'];
        self::assertStringStartsWith('snapshot:purchase:', $key);
        $after = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), false, true, null, [$key => 'DE']);
        self::assertSame('DE', $after['purchases'][0]['partner_snapshot']['country_code']);
        self::assertNotContains('partner_country_unresolved', $after['purchases'][0]['review_codes']);
        $candidate = array_values(array_filter($after['partner_country_candidates'], static fn (array $row): bool => $row['source_key'] === $key))[0];
        self::assertSame('DE', $candidate['confirmed_country']);
        self::assertSame('22222222', $candidate['ico']);
        self::assertSame([['source_key' => $after['purchases'][0]['source_key'], 'document_no' => 'PF-1', 'kind' => 'purchase']], $candidate['documents']);
    }

    public function testDifferentSourceKeysAreNotMergedBySameIcoAndUnusedPartnersStayVisible(): void
    {
        $tables = $this->tables();
        $second = $tables['LAdresy'][1];
        $second['Firma'] = 'V2';
        $tables['LAdresy'][] = $second;
        $second['Firma'] = 'UNUSED';
        $tables['LAdresy'][] = $second;
        $tables['SPFH'][1]['Firma'] = 'V2';
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), false, true, null, ['V1' => 'DE']);
        self::assertSame('DE', $plan['clients'][1]['country_code']);
        self::assertTrue($plan['clients'][2]['country_unresolved']);
        self::assertContains('partner_country_unresolved', $plan['purchases'][1]['review_codes']);
        self::assertSame(['V1', 'V2', 'UNUSED'], array_column($plan['partner_country_candidates'], 'source_key'));
        self::assertSame([], $plan['partner_country_candidates'][2]['documents']);
    }

    public function testEachUnresolvedDirectoryCardHasNamedWarningEvenWithoutDocuments(): void
    {
        foreach ([false, true] as $accounting) {
            $tables = $this->tables();
            $unused = $tables['LAdresy'][1];
            $unused['Firma'] = 'UNUSED';
            $unused['Nazev'] = 'Syntetická nepoužitá karta';
            $unused['ICO'] = '33333333';
            $unused['Stat'] = 'EMU';
            $tables['LAdresy'][] = $unused;
            $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), false, $accounting);
            self::assertArrayHasKey('partner_country_warnings', $plan);
            self::assertCount(2, $plan['partner_country_warnings']);
            $warnings = array_column($plan['partner_country_warnings'], null, 'partner_source_key');
            self::assertSame('partner_country_unresolved_directory', $warnings['UNUSED']['code']);
            self::assertSame('Syntetická nepoužitá karta', $warnings['UNUSED']['partner_name']);
            self::assertSame('33333333', $warnings['UNUSED']['ico']);
            self::assertSame('EMU', $warnings['UNUSED']['source_country']);
            self::assertSame(0, $warnings['UNUSED']['document_count']);
            self::assertSame(2, $warnings['V1']['document_count']);
            self::assertStringContainsString('Syntetická nepoužitá karta', $warnings['UNUSED']['message']);
            self::assertStringContainsString('Adresář', $warnings['UNUSED']['message']);
            self::assertContains('partner_country_unresolved', $plan['purchases'][0]['review_codes']);
            $confirmed = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), false, $accounting, null, ['UNUSED' => 'DE']);
            self::assertCount(1, $confirmed['partner_country_warnings']);
            $candidates = array_column($confirmed['partner_country_candidates'], null, 'source_key');
            self::assertSame('DE', $candidates['UNUSED']['confirmed_country']);
            self::assertSame([], $candidates['UNUSED']['documents']);
        }
    }

    public function testAccountingPreparationReportsDirectoryCardsSeparatelyFromDocumentReviews(): void
    {
        $tables = $this->tables();
        $unused = $tables['LAdresy'][1];
        $unused['Firma'] = 'UNUSED';
        $unused['Nazev'] = 'Syntetická nepoužitá karta';
        $tables['LAdresy'][] = $unused;
        $path = sys_get_temp_dir() . '/stereo-country-directory-' . bin2hex(random_bytes(6)) . '.zip';
        try {
            SyntheticNx1Archive::write($path, $tables, SyntheticStereoNxTables::identity());
            $importer = (new \ReflectionClass(StereoNxImporter::class))->newInstanceWithoutConstructor();
            $module = new StereoNxAccountingDocuments(new StereoNxSourcePlan(), $importer);
            $prepared = $module->prepare(StereoNxBackup::open($path, 0));
            $directory = array_values(array_filter($prepared['warnings'],
                static fn (array $row): bool => $row['code'] === 'partner_country_unresolved_directory'));
            self::assertCount(2, $directory);
            self::assertSame(['V1', 'UNUSED'], array_column($directory, 'partner_source_key'));
            self::assertSame([2, 0], array_column($directory, 'document_count'));
            $documents = array_values(array_filter($prepared['warnings'],
                static fn (array $row): bool => $row['code'] === 'partner_country_unresolved'));
            self::assertCount(2, $documents);
            foreach ($documents as $document) self::assertNotSame('', $document['source_key']);
            self::assertSame([], $prepared['partner_country_candidates'][1]['documents']);
        } finally {
            if (is_file($path)) unlink($path);
        }
    }

    public function testDifferentHistoricalIdentityRemainsUnresolvedAfterDirectoryConfirmation(): void
    {
        $tables = $this->tables();
        $tables['SPFH'][0]['FirmaICO'] = '33333333';
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), false, true, null, ['V1' => 'DE']);
        self::assertTrue($plan['purchases'][0]['partner_snapshot']['country_unresolved']);
        self::assertContains('partner_country_unresolved', $plan['purchases'][0]['review_codes']);
        self::assertTrue($plan['purchases'][0]['requires_draft']);
    }

    public function testConflictingHistoricalVatIdsWithoutIcoCannotShareCountryConfirmation(): void
    {
        foreach ([false, true] as $accounting) {
            $tables = $this->tables();
            $tables['LAdresy'][1]['ICO'] = '';
            $tables['LAdresy'][1]['DIC'] = ' de111111111 ';
            $tables['LAdresy'][1]['Stat'] = 'EU';
            $tables['SPFH'][0]['FirmaICO'] = '';
            $tables['SPFH'][0]['FirmaDIC'] = ' atu22222222 ';
            $tables['SPFH'][0]['FirmaStat'] = 'EU';
            $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), false, $accounting, null, ['V1' => 'DE']);
            self::assertTrue($plan['purchases'][0]['partner_snapshot']['country_unresolved'],
                'Neshodná normalizovaná DIČ dokazují jiného historického partnera i bez IČO.');
            self::assertSame('CZ', $plan['purchases'][0]['partner_snapshot']['country_code']);
            self::assertSame('EU', $plan['purchases'][0]['partner_snapshot']['country']);
            self::assertContains('partner_country_eu_unspecified', $plan['purchases'][0]['review_codes']);
            self::assertTrue($plan['purchases'][0]['requires_draft']);
            self::assertSame('DE', $plan['clients'][1]['country_code']);
        }
    }

    public function testEquivalentNormalizedVatIdsDoNotCreateFalseHistoricalConflict(): void
    {
        $tables = $this->tables();
        $tables['LAdresy'][1]['ICO'] = '';
        $tables['LAdresy'][1]['DIC'] = ' de111111111 ';
        $tables['LAdresy'][1]['Stat'] = 'EU';
        $tables['SPFH'][0]['FirmaICO'] = '';
        $tables['SPFH'][0]['FirmaDIC'] = 'DE111111111';
        $tables['SPFH'][0]['FirmaStat'] = 'EU';
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), false, true, null, ['V1' => 'DE']);
        self::assertFalse($plan['purchases'][0]['partner_snapshot']['country_unresolved']);
        self::assertSame('DE', $plan['purchases'][0]['partner_snapshot']['country_code']);
        self::assertNotContains('partner_country_eu_unspecified', $plan['purchases'][0]['review_codes']);
    }

    public function testUnknownEmuTokenWithForeignVatIdNeedsExplicitCountryConfirmation(): void
    {
        foreach ([false, true] as $accounting) {
            $tables = $this->tables();
            $tables['LAdresy'][1]['Stat'] = 'EMU';
            $tables['LAdresy'][1]['DIC'] = 'DE111111111';
            foreach ($tables['SPFH'] as &$header) {
                $header['FirmaStat'] = 'EMU';
                $header['FirmaDIC'] = 'DE111111111';
            }
            unset($header);
            $before = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), false, $accounting);
            self::assertTrue($before['clients'][1]['country_unresolved']);
            self::assertTrue($before['purchases'][0]['partner_snapshot']['country_unresolved']);
            self::assertContains('partner_country_unresolved', $before['purchases'][0]['review_codes']);
            $after = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), false, $accounting, null, ['V1' => 'DE']);
            self::assertSame('DE', $after['clients'][1]['country_code']);
            self::assertSame('EMU', $after['clients'][1]['country']);
            foreach ($before['purchases'] as $index => $document) {
                self::assertSame(array_values(array_diff($document['review_codes'], ['partner_country_unresolved'])),
                    $after['purchases'][$index]['review_codes']);
                self::assertSame($document['items'], $after['purchases'][$index]['items']);
                self::assertSame($document['reverse_charge'], $after['purchases'][$index]['reverse_charge']);
            }
            self::assertTrue($after['purchases'][1]['requires_draft']);
        }
    }

    public function testEmptyConfigurationPreservesDefaultHashesButChangedEffectiveCountryChangesThem(): void
    {
        $tables = $this->tables();
        $default = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity());
        self::assertSame($default, StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), false, false, null, []));
        $confirmed = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), false, false, null, ['V1' => 'DE']);
        $hash = new \ReflectionMethod(StereoNxImporter::class, 'sourceHash');
        self::assertNotSame($hash->invoke(null, $default['clients'][1]), $hash->invoke(null, $confirmed['clients'][1]));
        self::assertNotSame($hash->invoke(null, $default['purchases'][0]), $hash->invoke(null, $confirmed['purchases'][0]));
        self::assertSame($hash->invoke(null, $default['issued'][0]), $hash->invoke(null, $confirmed['issued'][0]));
    }

    public function testConfigurationNormalizesValuesAndOrderButNotSourceIdentity(): void
    {
        self::assertSame(['123' => 'PL', 'V1' => 'DE'], StereoNxPartnerCountries::configuration(['V1' => ' de ', 123 => 'pl']));
        self::assertSame(StereoNxPartnerCountries::fingerprint(['V1' => ' de ', 'V2' => 'CZ']),
            StereoNxPartnerCountries::fingerprint(['V2' => 'cz', 'V1' => 'DE']));
        self::assertSame(StereoNxPartnerCountries::fingerprint(null), StereoNxPartnerCountries::fingerprint([]));
        self::assertSame([' V1' => 'CZ'], StereoNxPartnerCountries::configuration([' V1' => 'CZ']));
        self::assertSame(['code' => 'PL', 'unresolved' => false],
            StereoNxPartnerCountries::resolve(['code' => 'PL', 'unresolved' => false], 'V1', ['V1' => 'DE']));
    }

    public function testMalformedConfigurationsAreRejected(): void
    {
        foreach (['CZ', true, ['V1' => null], ['V1' => []], ['V1' => 'EU'], ['V1' => 'CZE'], ['' => 'CZ'], [str_repeat('x', 191) => 'CZ']] as $value) {
            try {
                StereoNxPartnerCountries::configuration($value);
                self::fail('Neplatné potvrzení země musí být odmítnuto.');
            } catch (StereoNxException $e) {
                self::assertSame('partner_countries_invalid', $e->errorCode);
            }
        }
    }

    public function testUnknownPartnerAndUnsupportedCountryAreRejected(): void
    {
        $clients = StereoNxSourcePlan::partners($this->tables()['LAdresy'], false);
        foreach ([['MISSING' => 'CZ'], ['V1' => 'ZZ']] as $configuration) {
            try {
                StereoNxPartnerCountries::assertKnown($configuration, $clients, ['CZ', 'DE', 'PL']);
                self::fail('Neznámý partner nebo země musí být odmítnuti.');
            } catch (StereoNxException $e) {
                self::assertSame(isset($configuration['MISSING']) ? 'partner_country_unknown' : 'partner_countries_invalid', $e->errorCode);
            }
        }
    }

    private function tables(): array
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['LAdresy'][1]['Stat'] = '';
        $tables['LAdresy'][1]['DIC'] = '';
        foreach ($tables['SPFH'] as &$row) {
            $row['FirmaStat'] = '';
            $row['FirmaDIC'] = '';
            $row['FirmaICO'] = '22222222';
        }
        return $tables;
    }
}
