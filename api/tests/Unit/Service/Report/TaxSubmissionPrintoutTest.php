<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\SubmittedEpoXmlReader;
use MyInvoice\Service\Report\TaxSubmissionPrintout;
use MyInvoice\Service\Report\TaxSubmissionReceiptEvidence;
use PHPUnit\Framework\TestCase;

/**
 * Tisk odeslaného podání: obsah pochází jen z uloženého XML a řádky přiznání se
 * popisují podle stejné mapy, podle které se XML skládá. Syntetická data.
 */
final class TaxSubmissionPrintoutTest extends TestCase
{
    public const DP3_XML = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Pisemnost nazevSW="MyUcto"><DPHDP3 verzePis="03.01">'
        . '<VetaD k_uladis="DPH" dokument="DP3" rok="2026" mesic="3" dapdph_forma="B" typ_platce="P" d_poddp="20.04.2026"/>'
        . '<VetaP c_ufo="451" dic="12345678" typ_ds="P" zkrobchjm="Testovací firma s.r.o." naz_obce="Praha" psc="11000"/>'
        . '<Veta1 obrat23="100000" dan23="21000" rez_pren23="5000" dan_rpren23="1050"/>'
        . '<Veta4 pln23="40000" odp_tuz23_nar="8400" odp_tuz23="300" odp_sum_nar="8400" neznamy_atribut="7"/>'
        . '<Veta6 dan_zocelk="22050" odp_zocelk="8400" dano_da="13650"/>'
        . '</DPHDP3></Pisemnost>';

    public const KH_XML = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Pisemnost><DPHKH1 verzePis="03.01">'
        . '<VetaD dokument="KH1" k_uladis="DPH" rok="2026" mesic="3" khdph_forma="B"/>'
        . '<VetaP dic="12345678" zkrobchjm="Testovací firma s.r.o."/>'
        . '<VetaA4 c_radku="1" dic_odb="87654321" c_evid_dd="FV-0001" dppd="05.03.2026" zakl_dane1="20000.00" dan1="4200.00" kod_rezim_pl="0" zdph_44="N"/>'
        . '<VetaA4 c_radku="2" dic_odb="87654322" c_evid_dd="FV-0002" dppd="06.03.2026" zakl_dane1="30000.00" dan1="6300.00" kod_rezim_pl="0" zdph_44="N"/>'
        . '<VetaA5 zakl_dane1="1000.00" dan1="210.00"/>'
        . '<VetaC obrat23="51000" pln23="0"/>'
        . '</DPHKH1></Pisemnost>';

    public function testReaderKeepsEveryRecordAndAttributeInDocumentOrder(): void
    {
        $parsed = SubmittedEpoXmlReader::read(self::KH_XML);

        self::assertSame('DPHKH1', $parsed['form']);
        self::assertSame('03.01', $parsed['version']);
        self::assertSame(['VetaD', 'VetaP', 'VetaA4', 'VetaA4', 'VetaA5', 'VetaC'], array_column($parsed['records'], 'name'));
        self::assertSame('FV-0002', $parsed['records'][3]['attrs']['c_evid_dd']);
    }

    public function testReaderRejectsBrokenXml(): void
    {
        $this->expectException(\RuntimeException::class);
        SubmittedEpoXmlReader::read('<Pisemnost><DPHDP3>');
    }

    public function testVatReturnLinesComeFromBuilderLineMap(): void
    {
        $data = TaxSubmissionPrintout::build(self::submission('dphdp3', self::DP3_XML));

        self::assertSame('DPHDP3', $data['form_code']);
        self::assertSame('03/2026', $data['period_label']);
        self::assertSame('řádné', $data['variant_label']);

        $rows = self::rowsByLine($data['line_groups']);
        // ř. 1 a ř. 10 podle DphPriznaniBuilder::LINE_MAP.
        self::assertSame(DphPriznaniBuilder::LINE_MAP['1']['base'], 'obrat23');
        self::assertSame(['100000', '21000', null], [$rows['1']['base'], $rows['1']['full'], $rows['1']['reduced']]);
        self::assertSame(['5000', '1050'], [$rows['10']['base'], $rows['10']['full']]);
        // ř. 40: plný i krácený odpočet ve stejném řádku (40 a 40k).
        self::assertSame(['40000', '8400', '300'], [$rows['40']['base'], $rows['40']['full'], $rows['40']['reduced']]);
        self::assertSame('8400', $rows['46']['full']);
        self::assertSame('13650', $rows['64']['full']);
        self::assertSame('Vlastní daň', $rows['64']['label']);
        // Neznámý atribut tisk nezatají.
        self::assertSame('7', $rows['neznamy_atribut']['full']);

        $identity = array_column($data['identity'], 'value', 'label');
        self::assertSame('Testovací firma s.r.o.', $identity['Název']);
        self::assertSame('12345678', $identity['DIČ']);
    }

    public function testControlStatementPrintsItemizedSectionsAsTables(): void
    {
        $data = TaxSubmissionPrintout::build(self::submission('dphkh1', self::KH_XML));

        self::assertSame([], $data['line_groups']);
        $tables = array_column($data['tables'], null, 'title');
        $a4 = $tables['A.4 Uskutečněná plnění nad 10 000 Kč'];
        self::assertCount(2, $a4['rows']);
        self::assertSame('FV-0001', $a4['rows'][0]['c_evid_dd']);
        $columns = array_column($a4['columns'], null, 'key');
        self::assertSame('Ev. číslo dokladu', $columns['c_evid_dd']['label']);
        self::assertTrue($columns['zakl_dane1']['num']);
        self::assertFalse($columns['dic_odb']['num']);
        self::assertArrayHasKey('C. Kontrolní řádky', $tables);
    }

    public function testReceiptEvidenceListsStoredConfirmationAndIsdsReceipt(): void
    {
        $evidence = TaxSubmissionReceiptEvidence::summarize([
            'status' => 'submitted',
            'submission_ref' => null,
            'submitted_at' => '2026-04-20 10:00:00',
            'artifacts' => [
                ['id' => 5, 'artifact_kind' => 'source_xml', 'original_name' => 'a.xml'],
                ['id' => 6, 'artifact_kind' => 'confirmation_xml', 'original_name' => 'potvrzeni.xml',
                 'verification' => ['receipt' => ['reference' => 'TEST-123', 'submitted_at' => '2026-04-20T10:00:05']]],
                ['id' => 7, 'artifact_kind' => 'isds_delivery_zfo', 'original_name' => 'dorucenka.zfo'],
            ],
            'isds_dispatch' => null,
        ]);

        self::assertTrue($evidence['available']);
        self::assertNull($evidence['reason']);
        self::assertSame('TEST-123', $evidence['reference']);
        self::assertSame('2026-04-20T10:00:05', $evidence['submitted_at']);
        self::assertSame(['confirmation_xml', 'isds_delivery_zfo'], array_column($evidence['items'], 'kind'));
    }

    public function testMissingReceiptExplainsWhy(): void
    {
        $base = ['artifacts' => [], 'isds_dispatch' => null, 'submission_ref' => 'ref-1', 'submitted_at' => null];

        self::assertSame('not_submitted', TaxSubmissionReceiptEvidence::summarize($base + ['status' => 'downloaded'])['reason']);
        $outside = TaxSubmissionReceiptEvidence::summarize($base + ['status' => 'submitted']);
        self::assertSame('submitted_outside', $outside['reason']);
        self::assertStringContainsString('portálu EPO', (string) $outside['reason_label']);
        self::assertSame('ref-1', $outside['reference']);
        self::assertSame('isds_receipt_pending', TaxSubmissionReceiptEvidence::summarize(
            ['isds_dispatch' => ['dispatch_state' => 'sent', 'external_message_id' => '999']] + $base + ['status' => 'submitted'],
        )['reason']);
    }

    /** @return array<string,mixed> */
    public static function submission(string $formCode, string $xml): array
    {
        return [
            'id' => 1, 'form_code' => $formCode, 'form_variant' => 'B', 'period_year' => 2026,
            'period_month' => 3, 'period_quarter' => null, 'status' => 'submitted',
            'generated_at' => '2026-04-20 09:00:00', 'xml_sha256' => str_repeat('a', 64),
            'xml_content' => $xml, 'artifacts' => [], 'isds_dispatch' => null,
            'submitted_at' => '2026-04-20 10:00:00', 'submission_ref' => null,
        ];
    }

    /**
     * @param list<array{title:string, rows:list<array<string,mixed>>}> $groups
     * @return array<string,array<string,mixed>>
     */
    private static function rowsByLine(array $groups): array
    {
        $out = [];
        foreach ($groups as $group) {
            foreach ($group['rows'] as $row) {
                $out[$row['line'] !== '' ? $row['line'] : $row['label']] = $row;
            }
        }
        return $out;
    }
}
