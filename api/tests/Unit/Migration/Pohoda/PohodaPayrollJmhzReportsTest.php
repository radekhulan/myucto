<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollJmhzReports;
use MyInvoice\Service\Migration\Pohoda\PohodaXml;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportForm;
use MyInvoice\Tests\Fixtures\Pohoda\SyntheticPohodaPayroll;
use PHPUnit\Framework\TestCase;

/**
 * Podání z exportu PAMICA: atributové sloupce, složení formuláře hlášení JMHZ touž
 * čtečkou jako nahrané XML, platný formulář měsíce (opravné po řádném, neodeslané
 * se nebere) a registrace.
 */
final class PohodaPayrollJmhzReportsTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_jmhz_' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->tmp);
    }

    public function testAttributesReadExportedBlobColumn(): void
    {
        $file = $this->tmp . '/a.xml';
        file_put_contents($file, '<?xml version="1.0" encoding="UTF-8"?><mdbExport>'
            . '<MHitems><ID>1</ID><Data v="1"><a id="10228" t="6" f="1">123</a><a id="10435" t="3" f="0" i="2" j="1">Dítě</a><a id="10453" t="2" f="1"/></Data></MHitems>'
            . '<MHitems><ID>2</ID><Data v="1"><a id="1" t="5" f="1">bezPriznaku</a></Data></MHitems>'
            . '<MHitems><ID>3</ID></MHitems></mdbExport>');
        $rows = iterator_to_array(PohodaXml::records($file, 'MHitems'), false);

        self::assertSame([
            ['id' => 10228, 'section' => 6, 'flag' => 1, 'order' => 0, 'order2' => 0, 'value' => '123'],
            ['id' => 10435, 'section' => 3, 'flag' => 0, 'order' => 2, 'order2' => 1, 'value' => 'Dítě'],
            ['id' => 10453, 'section' => 2, 'flag' => 1, 'order' => 0, 'order2' => 0, 'value' => ''],
        ], PohodaXml::attributes($rows[0], 'Data'));
        self::assertSame([['id' => 1, 'section' => 5, 'flag' => 1, 'order' => 0, 'order2' => 0, 'value' => 'bezPriznaku']], PohodaXml::attributes($rows[1], 'Data'));
        self::assertSame([], PohodaXml::attributes($rows[2], 'Data'));
    }

    public function testMonthlyReportsCarryHeaderStateAndCorrection(): void
    {
        $reports = PohodaPayrollJmhzReports::read(SyntheticPohodaPayroll::writeWithReports($this->tmp), SyntheticPohodaPayroll::YEAR);
        $guids = SyntheticPohodaPayroll::REPORT_GUIDS;

        self::assertSame(['MH:1', 'MH:2', 'MH:3', 'MH:4'], array_column($reports, 'source_key'));
        self::assertSame(['2026-01', '2026-02', '2026-02', '2026-03'], array_column($reports, 'period'));
        self::assertSame(['R', 'R', 'O', 'R'], array_column($reports, 'type'));
        self::assertSame([true, true, true, false], array_column($reports, 'sent'));
        self::assertSame([null, null, 'MH:2', null], array_column($reports, 'corrected_source_key'));
        self::assertSame([$guids['jan'], $guids['feb'], $guids['feb_o'], $guids['mar']], array_column($reports, 'guid'));
        self::assertSame('2026-03-20T10:30:00', $reports[2]['submitted_at']);
        self::assertSame('2026-03-20T10:30:00', $reports[2]['accepted_at']);
        self::assertNull($reports[3]['accepted_at']);
        self::assertSame([[
            'id' => 10001, 'section' => 0, 'flag' => 1, 'order' => 0, 'order2' => 0, 'value' => $guids['jan'],
        ], [
            'id' => 10029, 'section' => 0, 'flag' => 1, 'order' => 0, 'order2' => 0, 'value' => '15000',
        ]], $reports[0]['summary']);
        self::assertSame(['1', '2'], array_column($reports[0]['forms'], 'relation_key'));
        self::assertSame([null, null], array_column($reports[0]['forms'], 'error'));
        self::assertContainsOnlyInstancesOf(JmhzReportForm::class, array_column($reports[0]['forms'], 'form'));
        // Pořadí formuláře se počítá v rámci podání, ne přes všechny položky souboru.
        foreach ($reports as $report) {
            self::assertSame(range(1, count($report['forms'])), array_map(
                static fn (array $form): int => $form['form']->position,
                $report['forms'],
            ));
        }
    }

    public function testFormFromAttributesMapsLikeUploadedReport(): void
    {
        $reports = PohodaPayrollJmhzReports::read(SyntheticPohodaPayroll::writeWithReports($this->tmp), SyntheticPohodaPayroll::YEAR);
        $jana = $reports[0]['forms'][0]['form'];
        self::assertInstanceOf(JmhzReportForm::class, $jana);

        self::assertSame(SyntheticPohodaPayroll::REPORT_GUIDS['jana'], $jana->formGuid);
        self::assertSame('R', $jana->formType);
        self::assertTrue($jana->primary);
        self::assertSame('bezPriznaku', $jana->variant);
        self::assertSame(SyntheticPohodaPayroll::JANA_OIC, $jana->personIdentifier);
        self::assertSame(SyntheticPohodaPayroll::JANA_ID_PPV, $jana->employmentIdentifier);
        self::assertSame(['Testovací', 'Jana'], [$jana->lastName, $jana->firstName]);
        self::assertSame(['1990-05-04', '2025-03-01', '1'], [$jana->birthDate, $jana->startDate, $jana->activityCode]);
        self::assertTrue($jana->hasSummary);
        self::assertSame(43000, $jana->incomeTotal);
        self::assertSame(['base' => 43000, 'computed' => 6450, 'after_credits' => 3880, 'bonus' => 0], $jana->advance);
        self::assertNull($jana->withholding);
        self::assertTrue($jana->declarationSigned);
        self::assertSame(['taxpayer' => 2570], $jana->credits);
        self::assertSame(1267, $jana->childCredit['monthly']);
        self::assertSame(1267, $jana->childCredit['applied']);
        self::assertFalse($jana->childCredit['other_caregiver']);
        self::assertSame([
            ['given_name' => 'Tereza', 'family_name' => 'Testovací', 'birth_date' => null, 'birth_number' => '1501010005', 'ztp_p' => false, 'order' => '1'],
            ['given_name' => 'Tomáš', 'family_name' => 'Testovací', 'birth_date' => null, 'birth_number' => '1702020009', 'ztp_p' => false, 'order' => '2'],
        ], $jana->childCredit['children']);
        self::assertTrue($jana->hasPosition);
        self::assertSame(['city' => 'Praha', 'municipality_code' => '554782', 'country_code' => 'CZ'], $jana->workplace);
        self::assertSame([false, false, false], [$jana->apz, $jana->functionalBenefits, $jana->temporaryAssignment]);
        self::assertSame(['standard' => '160.000', 'agreed' => '160.000', 'weekly' => '40.00'], $jana->fund);
        self::assertSame(['workload_basis_points' => 10000, 'weekly_hours' => '40.00'], $jana->workload());
        self::assertSame(31, $jana->evidenceDays);
        self::assertSame(160000, $jana->workedMillihours);
        self::assertSame(43000, $jana->taxableIncome);
        self::assertSame([43000, 40000], [$jana->wage, $jana->tariff]);
        self::assertSame(250000, $jana->averageHourlyMilli);
        self::assertSame('2025-03-01', $jana->insuranceFrom);
        self::assertSame(['code' => '1', 'insurance_days' => 31, 'excluded_days' => 0, 'sickness_excluded_days' => null, 'absence_days' => ['docasNeschopnost' => 0, 'penezitaPomocMaterstvi' => 0, 'osetrovaniClenaRodiny' => 0, 'pracovniNeschopnost' => 0, 'vyplaceniDavek' => 0]], $jana->eldp);
        self::assertSame([43000, 3053, 10664], [$jana->socialBase, $jana->employeeSocial, $jana->employerSocial]);
        self::assertSame([1935, 3870, 33000], [$jana->employeeHealth, $jana->employerHealth, $jana->netWage]);
        self::assertSame([false, false, false], [$jana->socialDiscount, $jana->orchardDiscount, $jana->deductionsRecorded]);
        self::assertSame([0, 8000], [$jana->unworkedMillihours, $jana->leaveMillihours]);

        $petr = $reports[0]['forms'][1]['form'];
        self::assertInstanceOf(JmhzReportForm::class, $petr);
        self::assertSame(['base' => 5000, 'tax' => 750], $petr->withholding);
        self::assertFalse($petr->declarationSigned);
        self::assertSame(180500, $petr->averageHourlyMilli);
        self::assertSame('P', $petr->activityCode);
    }

    public function testEffectiveFormIsTheLatestSentSubmission(): void
    {
        $effective = PohodaPayrollJmhzReports::effective(
            PohodaPayrollJmhzReports::read(SyntheticPohodaPayroll::writeWithReports($this->tmp), SyntheticPohodaPayroll::YEAR),
        );

        self::assertSame(['2026-01', '2026-02'], array_keys($effective['1']), 'Neodeslané hlášení za březen neplatí.');
        self::assertSame('MH:3', $effective['1']['2026-02']['report']['source_key'], 'Za únor platí opravné podání.');
        self::assertSame('37.50', $effective['1']['2026-02']['form']->fund['weekly']);
        self::assertSame('MH:2', $effective['2']['2026-02']['report']['source_key'], 'Petra opravné podání neobsahuje, platí řádné.');
    }

    /**
     * Podání odeslané datovou schránkou má v PAMICA `ElOdeslano` 0: odeslané je podle stavu
     * „přijato", data přijetí nebo doručenky. Jinak by převod vyzval k druhému podání.
     */
    public function testSubmissionSentByDataBoxIsSent(): void
    {
        $file = $this->tmp . '/databox.xml';
        $mh = static fn (int $id, int $month, string $state, string $sent, string $accepted): string => "<MH><ID>{$id}</ID><Rok>2026</Rok>"
            . "<RelMesic>{$month}</RelMesic><RelTyp>1</RelTyp><RelStavDP>{$state}</RelStavDP><ElOdeslano>{$sent}</ElOdeslano>"
            . '<DatPod>2026-0' . ($month + 1) . '-13T09:00:00</DatPod>' . ($accepted !== '' ? "<DatPrij>{$accepted}</DatPrij>" : '') . '</MH>';
        file_put_contents($file, '<?xml version="1.0" encoding="UTF-8"?><mdbExport>'
            . $mh(1, 1, '7', '0', '')
            . $mh(2, 2, '4', '0', '')
            . $mh(3, 3, '1', '0', '')
            . '<DataBoxSent><ID>9</ID><RelAgID>190</RelAgID><RefID>2</RefID><JeDorucenka>1</JeDorucenka></DataBoxSent>'
            . '<RegZAM><ID>1</ID><RelStavDP>7</RelStavDP><ElOdeslano>0</ElOdeslano><DatPod>2026-06-30T09:00:00</DatPod></RegZAM>'
            . '<RegZAM><ID>2</ID><RelStavDP>1</RelStavDP><ElOdeslano>0</ElOdeslano></RegZAM>'
            . '</mdbExport>');

        $reports = PohodaPayrollJmhzReports::read($file, 2026);
        self::assertSame(['MH:1', 'MH:2', 'MH:3'], array_column($reports, 'source_key'));
        self::assertSame([true, true, false], array_column($reports, 'sent'), 'Přijaté a doručené datovou schránkou je odeslané.');

        $registrations = PohodaPayrollJmhzReports::registrations($file);
        $byKey = array_column($registrations, null, 'source_key');
        self::assertTrue($byKey['RegZAM:1']['sent']);
        self::assertTrue(PohodaPayrollJmhzReports::accepted($byKey['RegZAM:1']), 'Stav „přijato" je přijetí i bez data.');
        self::assertFalse($byKey['RegZAM:2']['sent']);
        self::assertFalse(PohodaPayrollJmhzReports::accepted($byKey['RegZAM:2']));
    }

    /**
     * Položka hlášení osoby v souběhu nese za každé PPV vlastní blok atributů (`j` = order2).
     * Každý blok je samostatný formulář svého vztahu; slité do jednoho by DPP přepsala fond
     * a úvazek pracovního poměru.
     */
    public function testConcurrentBlocksOfOneItemAreSeparateForms(): void
    {
        $file = $this->tmp . '/soubeh.xml';
        $block = static function (int $order2, string $guid, string $ppv, string $primary, string $activity, string $standard, string $weekly): string {
            $out = '';
            foreach ([[1, 'bezPriznaku'], [10012, $guid], [10016, 'R'], [10495, $primary], [10051, '1234567895'], [10228, $ppv],
                [10053, 'Souběžná'], [10054, 'Eva'], [10056, '1.2.1990'], [10223, '1.1.2025'], [10239, $activity],
                [10259, $standard], [10260, $standard], [10261, $weekly], [10265, '31'], [10268, '20.000']] as [$id, $value]) {
                $out .= '<a id="' . $id . '" t="0" f="1" j="' . $order2 . '">' . $value . '</a>';
            }
            return $out;
        };
        file_put_contents($file, '<?xml version="1.0" encoding="UTF-8"?><mdbExport>'
            . '<ZAMpomer><ID>1</ID><RefZAM>5</RefZAM><Poradi>1</Poradi><IDPPV>1111111111111</IDPPV></ZAMpomer>'
            . '<ZAMpomer><ID>2</ID><RefZAM>5</RefZAM><Poradi>2</Poradi><IDPPV>2222222222222</IDPPV></ZAMpomer>'
            . '<MH><ID>1</ID><Rok>2026</Rok><RelMesic>1</RelMesic><RelTyp>1</RelTyp><RelStavDP>7</RelStavDP><ElOdeslano>1</ElOdeslano>'
            . '<DatPod>2026-02-15T09:00:00</DatPod><DatPrij>2026-02-15T09:00:00</DatPrij></MH>'
            . '<MHitems><ID>7</ID><RefAg>1</RefAg><RefZAM>5</RefZAM><RefPomer>1</RefPomer><Soubeh>1</Soubeh><Data v="1">'
            . $block(0, 'CCCCCCCC-CCCC-4CCC-8CCC-CCCCCCCCCCCC', '1111111111111', 'A', '1', '165.000', '37.50')
            . $block(1, 'DDDDDDDD-DDDD-4DDD-8DDD-DDDDDDDDDDDD', '2222222222222', 'N', 'T', '176.000', '99')
            . '</Data></MHitems></mdbExport>');

        $forms = PohodaPayrollJmhzReports::read($file, 2026)[0]['forms'];

        self::assertCount(2, $forms, 'Souběžná položka jsou dva formuláře.');
        self::assertSame(['1', '2'], array_column($forms, 'relation_key'), 'Druhý blok patří vztahu podle ID PPV.');
        self::assertSame(['7', '7:1'], array_column($forms, 'item_id'));
        [$hpp, $dpp] = [$forms[0]['form'], $forms[1]['form']];
        self::assertInstanceOf(JmhzReportForm::class, $hpp);
        self::assertInstanceOf(JmhzReportForm::class, $dpp);
        self::assertSame(['1111111111111', true], [$hpp->employmentIdentifier, $hpp->primary]);
        self::assertSame(['2222222222222', false], [$dpp->employmentIdentifier, $dpp->primary]);
        self::assertSame('37.50', $hpp->fund['weekly'], 'Úvazek pracovního poměru nepřepíše blok DPP.');
        self::assertSame('165.000', $hpp->fund['standard']);
        self::assertNotNull($hpp->workload());
        self::assertSame([1, 2], array_map(static fn (array $f): int => $f['form']->position, $forms));
    }

    public function testRegistrationsAndProfilesFromSentRegistrations(): void
    {
        $registrations = PohodaPayrollJmhzReports::registrations(SyntheticPohodaPayroll::writeWithReports($this->tmp));

        self::assertSame(['RegZAM:2', 'RegZAM:1'], array_column($registrations, 'source_key'));
        self::assertSame([false, true], array_column($registrations, 'sent'));
        self::assertSame('3', $registrations[1]['items'][0]['type']);
        self::assertCount(8, $registrations[1]['items'][0]['attributes']);
        $profiles = PohodaPayrollJmhzReports::registrationProfiles($registrations);
        self::assertSame(['1'], array_map('strval', array_keys($profiles)), 'Neodeslaná přihláška profil vztahu nedává.');
        self::assertSame('41101', $profiles['1'][10234]);
        self::assertSame('1', $profiles['1'][10502]);
        self::assertArrayNotHasKey(10386, $profiles['1'], 'Atribut opakované skupiny do profilu vztahu nepatří.');
    }
}
