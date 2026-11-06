<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use Blondak\Nx1\Reader;
use Blondak\Nx1\Source\MemorySource;
use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use MyInvoice\Service\Migration\StereoNx\StereoNxIssuedDocuments;
use MyInvoice\Service\Migration\StereoNx\StereoNxPurchaseRecap;
use MyInvoice\Service\Migration\StereoNx\StereoNxVat;
use MyInvoice\Service\Migration\StereoNx\StereoNxSourcePlan;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxTables;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticNx1Archive;
use PHPUnit\Framework\TestCase;

final class StereoNxRecapAmountsTest extends TestCase
{
    public function testNativeNullUnusedBucketsPreserveBothDocumentTotals(): void
    {
        foreach (['VF', 'PF'] as $agenda) {
            $header = $this->header($agenda);
            foreach (['ZaklDPHs', 'DPHs', 'SazbaDPHs', 'ZaklDPHt', 'DPHt', 'SazbaDPHt', 'BezDane'] as $field) $header[$field] = null;
            $reader = Reader::fromSource(new MemorySource(SyntheticNx1Archive::table([$header]), 'synthetic-recap.nx1'));
            $decoded = iterator_to_array($reader->rows(), false)[0];
            self::assertNull($decoded['ZaklDPHt']);
            $plan = $this->plan($decoded);
            self::assertCount(1, $plan['items']);
            self::assertSame(100.0, $plan['total_without_vat']);
            self::assertSame(21.0, $plan['total_vat']);
            self::assertSame(121.0, $plan['source_total_with_vat']);
        }
    }

    public function testIssuedSourceLinesAllowOnlyUnusedNullBuckets(): void
    {
        $header = $this->header('VF');
        $header['ZaklDPHt'] = $header['DPHt'] = null;
        $plan = $this->issued()->plan($header, [$this->line('z', 100.0, 21.0)]);
        self::assertSame('source_lines', $plan['origin']);
        self::assertFalse($plan['requires_draft']);
        self::assertSame(121.0, $plan['source_total_with_vat']);
        $this->expectException(StereoNxException::class);
        $this->issued()->plan($header, [$this->line('z', 100.0, 21.0), $this->line('t', 0.0, 0.0)]);
    }

    public function testNullRoundingRequiresIndependentExactTotal(): void
    {
        foreach (['VF', 'PF'] as $agenda) {
            $header = $this->header($agenda);
            $header['Zaokrouhleni'] = null;
            self::assertSame(0.0, $this->plan($header)['rounding']);
            $header['Celkem'] = 121.01;
            try { $this->plan($header); self::fail('Missing rounding explained away a difference.'); }
            catch (StereoNxException) { self::assertTrue(true); }
        }
    }

    public function testInvalidOrMissingFinancialValuesRemainErrors(): void
    {
        foreach (['VF', 'PF'] as $agenda) {
            foreach ([['ZaklDPHz', null], ['DPHz', null], ['SazbaDPHz', null], ['Celkem', null],
                ['DPHt', '0'], ['DPHt', NAN], ['DPHt', INF]] as [$field, $value]) {
                $header = $this->header($agenda); $header[$field] = $value;
                try { $this->plan($header); self::fail('Invalid field accepted: ' . $field); }
                catch (StereoNxException) { self::assertTrue(true); }
            }
            foreach (['DPHt', 'Zaokrouhleni'] as $field) {
                $header = $this->header($agenda); unset($header[$field]);
                try { $this->plan($header); self::fail('Missing field accepted: ' . $field); }
                catch (StereoNxException) { self::assertTrue(true); }
            }
        }
    }

    public function testVatRegisterMatchesDocumentWithUnusedNullBucket(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        $tables['Svfh'][0]['ZaklDPHt'] = $tables['Svfh'][0]['DPHt'] = null;
        $tables['ZAZPVDPH'] = [['Doklad' => 'VF-1', 'Agenda' => 'VF', 'Zaklad' => 100.0,
            'DPH' => 21.0, 'DatumDPH' => $tables['Svfh'][0]['DatumDPH']]];
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity());
        self::assertNotContains('vat_register_mismatch', $plan['issued'][0]['review_codes']);
        self::assertSame(121.0, $plan['issued'][0]['source_total_with_vat']);
        $tables['ZAZPVDPH'][0]['DPH'] = 20.99;
        $mismatch = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity());
        self::assertContains('vat_register_mismatch', $mismatch['issued'][0]['review_codes']);
    }

    public function testAdvanceWithUnusedNullBucketsStillRequiresExplicitRounding(): void
    {
        $tables = SyntheticStereoNxTables::tables();
        foreach (['Svfh', 'SPFH'] as $table) {
            $tables[$table][0]['TypDokladu'] = 'Z';
            $tables[$table][0]['ZpracovatDPH'] = false;
            $tables[$table][0]['DatumDPH'] = null;
            foreach (['ZaklDPHz', 'DPHz', 'ZaklDPHs', 'DPHs', 'ZaklDPHt', 'DPHt', 'BezDane'] as $field) $tables[$table][0][$field] = null;
            $tables[$table][0]['Celkem'] = 500.0;
        }
        $tables['Svfp'] = [];
        $plan = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true);
        foreach ([$plan['issued'][0], $plan['purchases'][0]] as $doc) {
            self::assertSame('advance_total', $doc['origin']);
            self::assertSame(500.0, $doc['source_total_with_vat']);
        }
        $tables['SPFH'][0]['Zaokrouhleni'] = null;
        $uncertain = StereoNxSourcePlan::fromTables($tables, SyntheticStereoNxTables::identity(), true, true);
        self::assertTrue($uncertain['purchases'][0]['requires_draft']);
        self::assertNotSame('advance_total', $uncertain['purchases'][0]['origin']);
    }

    private function header(string $agenda): array
    {
        return ['Agenda' => $agenda, 'TypDokladu' => 'F', 'Stornovano' => false,
            'Mena' => 'CZK', 'Kurz' => 1.0, 'KurzMn' => 1.0, 'Zalohy' => 0.0,
            'CenySDPH' => false, 'ZpracovatDPH' => true, 'TypDPH' => 'SYNTHETIC',
            'Text' => 'Synthetic document', 'ZaklDPHz' => 100.0, 'DPHz' => 21.0, 'SazbaDPHz' => 21.0,
            'ZaklDPHs' => 0.0, 'DPHs' => 0.0, 'SazbaDPHs' => 12.0,
            'ZaklDPHt' => 0.0, 'DPHt' => 0.0, 'SazbaDPHt' => 0.0,
            'BezDane' => 0.0, 'Zaokrouhleni' => 0.0, 'Celkem' => 121.0];
    }

    private function line(string $slot, float $base, float $tax): array
    {
        return ['Stornovano' => false, 'Zaloha' => false, 'ZalohaProforma' => false,
            'TypSazby' => $slot, 'ZakladDPH' => $base, 'CelkemDPH' => $tax,
            'SazbaDPH' => $slot === 'z' ? 21.0 : 0.0, 'Mnozstvi' => 1.0,
            'JednCena' => $base, 'ProcSlevy' => 0.0, 'Text' => 'Synthetic line'];
    }

    private function vat(string $agenda): StereoNxVat
    {
        $row = ['TypDPH' => 'SYNTHETIC', 'Plneni' => $agenda === 'VF' ? 'U' : 'P', 'Kraceni' => false];
        foreach (['Z', 'S', 'T', '0'] as $slot) foreach (['Zaklad', 'Dan'] as $kind) {
            $row['E19Radek' . $kind . $slot] = $slot === '0' ? 'NE' : ($agenda === 'VF' ? ($slot === 'Z' ? '1' : '2') : ($slot === 'Z' ? '40' : '41'));
            $row['E19Radek' . $kind . $slot . 'x'] = '';
        }
        return new StereoNxVat([$row]);
    }

    private function issued(): StereoNxIssuedDocuments { return new StereoNxIssuedDocuments($this->vat('VF')); }
    private function plan(array $header): array
    {
        return $header['Agenda'] === 'VF' ? $this->issued()->plan($header, [])
            : (new StereoNxPurchaseRecap($this->vat('PF')))->plan($header);
    }
}
