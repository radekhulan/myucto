<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Premier;

use MyInvoice\Service\Migration\Premier\PremierBackup;
use MyInvoice\Service\Migration\Premier\PremierJournal;
use MyInvoice\Service\Migration\Premier\PremierPayroll;
use MyInvoice\Tests\Fixtures\Premier\DbfWriter;
use MyInvoice\Tests\Fixtures\Premier\SyntheticPremierBackup;
use PHPUnit\Framework\TestCase;

/**
 * Zaměstnanci a mzdy ze syntetické zálohy PREMIER: osoba z `PER_MAIN` i ze snímků
 * `PERSON2`, druh vztahu, měsíční úhrny a rekonciliace proti zaúčtování v deníku.
 */
final class PremierPayrollTest extends TestCase
{
    private string $tmp = '';

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            foreach (scandir($this->tmp) ?: [] as $f) {
                if (is_file($this->tmp . DIRECTORY_SEPARATOR . $f)) {
                    unlink($this->tmp . DIRECTORY_SEPARATOR . $f);
                }
            }
            rmdir($this->tmp);
        }
    }

    public function testRelationsPeopleAndMonths(): void
    {
        $payroll = PremierPayroll::fromBackup($this->backup(['payroll' => true]));
        self::assertTrue($payroll->hasData());
        self::assertSame([], $payroll->missingTables);
        self::assertSame(
            [['1', 'statutory_body', 'Jana Fiktivní', '855101/0105', '2025-01-01', null, '111', 14],
                ['2', 'dpp', 'Petr Zkušební', '900315/0101', '2025-03-01', '2025-06-30', null, 4],
                ['3', 'employment', 'Karel Vzorový', '920620/0102', '2026-02-01', null, '201', 1]],
            array_map(static fn (array $r): array => [$r['personal_number'], $r['relation_type'], $r['full_name'], $r['birth_number'],
                $r['start'], $r['end'], $r['insurer_code'], count($r['months'])], $payroll->relations),
        );
        [$statutory, $dpp, $employee] = $payroll->relations;
        self::assertFalse($statutory['relation_type_derived']);
        self::assertSame('PER_MAIN|OS-A', $statutory['person_key']);
        self::assertSame('CISLO|2', $dpp['person_key'], 'Osoba bez karty PER_MAIN se páruje osobním číslem.');
        self::assertSame(['street_line' => 'Nová 5', 'city' => 'Praha', 'postal_code' => '11000', 'country_code' => 'CZ', 'country_text' => 'CZ'], $dpp['residence'],
            'Platí poslední snímek PERSON2.');
        self::assertSame(['account' => SyntheticPremierBackup::BANK_ACCOUNT, 'bank_code' => SyntheticPremierBackup::BANK_CODE], $statutory['account']);
        self::assertSame(['2025-01-01' => 6000.0, '2026-01-01' => 6500.0], $statutory['wages']);
        self::assertTrue($employee['insurer_registered']);

        $january = $statutory['months']['2025-01'];
        self::assertSame([6000.0, 426.0, 270.0, 1488.0, 540.0, 0.0, 900.0, 4404.0, true, 31, false],
            [$january['gross'], $january['employee_social'], $january['employee_health'], $january['employer_social'], $january['employer_health'],
                $january['advance_tax'], $january['withholding_tax'], $january['net_payable'], $january['pension_participation'], $january['insurance_days'],
                $january['signed']], 'Odměna jednatelky je MZ_ODSTAT, ne MZ_HRUBA.');
        self::assertSame([false, 0, 500], [$dpp['months']['2025-03']['pension_participation'], $dpp['months']['2025-03']['insurance_days'],
            (int) round($dpp['months']['2025-03']['worked_days'] * 100)]);
        self::assertTrue($employee['months']['2026-02']['signed']);
        self::assertSame(2570.0, $employee['months']['2026-02']['non_refundable']);
    }

    /**
     * `KODPP_SO` je v zálohách prázdný a druh vztahu vychází z kategorie. Učeň (`UCN`)
     * dřív padal do výchozího pracovního poměru s příznakem odvození.
     */
    public function testApprenticeIsNotAnEmploymentRelation(): void
    {
        $relations = PremierPayroll::fromBackup($this->backup(['payroll' => true, 'payroll_detail' => true]))->relations;
        $apprentice = array_values(array_filter($relations, static fn (array $r): bool => $r['key'] === '4'))[0];
        self::assertSame([PremierPayroll::APPRENTICE, false], [$apprentice['relation_type'], $apprentice['relation_type_derived']]);
    }

    /**
     * Sjednaná mzda je v `SAZBA_MZ` podle `TYP_MZDY`; `MZDA_MES` je vyplněné jen někdy
     * a od sazby se může lišit. Hodinová sazba sjednanou měsíční mzdou není.
     */
    public function testAgreedWageComesFromRateByWageType(): void
    {
        $relations = PremierPayroll::fromBackup($this->backup(['payroll' => true, 'payroll_detail' => true]))->relations;
        $employee = array_values(array_filter($relations, static fn (array $r): bool => $r['key'] === '5'))[0];
        self::assertSame(['2025-01-01' => 30000.0, '2025-07-01' => 32000.0], $employee['wages']);
        self::assertSame([], $employee['hourly_wages']);
        $statutory = array_values(array_filter($relations, static fn (array $r): bool => $r['key'] === '1'))[0];
        self::assertSame(['2025-01-01' => 6000.0, '2026-01-01' => 6500.0], $statutory['wages'], 'Starší verze bez typu mzdy nesou MZDA_MES.');
    }

    /** Historie zdravotní pojišťovny z oznámení pojišťovnám, po celých navazujících měsících. */
    public function testInsurerHistoryFromNotifications(): void
    {
        self::assertSame([
            ['code' => '111', 'from' => '2024-03-01', 'to' => '2025-06-30', 'reference' => 'premier:mz_prizp:P:2024-03-18'],
            ['code' => '201', 'from' => '2025-07-01', 'to' => null, 'reference' => 'premier:mz_prizp:Q:2025-07-01'],
        ], PremierPayroll::insurerHistory([
            ['date' => '2025-07-01', 'code' => '201', 'kind' => 'Q'],
            ['date' => '2024-03-18', 'code' => '111', 'kind' => 'P'],
            // Odhláška úsek nekončí, opakovaná přihláška téže pojišťovny nic nemění.
            ['date' => '2024-10-31', 'code' => '111', 'kind' => 'O'],
            ['date' => '2024-11-04', 'code' => '111', 'kind' => 'P'],
        ], '2024-03-18'));
        self::assertSame([['code' => '205', 'from' => '2024-01-01', 'to' => null, 'reference' => 'premier:mz_prizp:M:2024-05-20']],
            PremierPayroll::insurerHistory([
                ['date' => '2024-05-02', 'code' => '211', 'kind' => 'P'],
                ['date' => '2024-05-20', 'code' => '205', 'kind' => 'M'],
            ], '2024-01-10'), 'Dvě oznámení v jednom měsíci rozhoduje pozdější; první úsek začíná měsícem nástupu.');
        self::assertSame([], PremierPayroll::insurerHistory([['date' => '2024-05-02', 'code' => '211', 'kind' => 'O']], null));

        $relations = PremierPayroll::fromBackup($this->backup(['payroll' => true, 'payroll_detail' => true]))->relations;
        $employee = array_values(array_filter($relations, static fn (array $r): bool => $r['key'] === '5'))[0];
        self::assertSame([['111', '2025-01-01', '2025-06-30'], ['201', '2025-07-01', null]],
            array_map(static fn (array $run): array => [$run['code'], $run['from'], $run['to']], $employee['insurer_history']));
    }

    /**
     * `STAT_N` je stát narození, státní občanství nese `STOBC`. Osoba narozená na Slovensku
     * s českým občanstvím dřív dostala občanství SK a stát narození se nepřevzal vůbec.
     */
    public function testBirthCountryAndCitizenshipComeFromSeparateColumns(): void
    {
        $relations = array_column(PremierPayroll::fromBackup($this->backup(['payroll' => true, 'payroll_detail' => true]))->relations, null, 'key');

        self::assertSame(['birth_country_code' => 'SK', 'citizenship_country_code' => 'CZ'],
            array_intersect_key($relations['6']['identity'], ['birth_country_code' => 1, 'citizenship_country_code' => 1]));
        self::assertSame(['birth_country_code' => 'CZ', 'citizenship_country_code' => 'CZ'],
            array_intersect_key($relations['1']['identity'], ['birth_country_code' => 1, 'citizenship_country_code' => 1]));
        self::assertSame(['birth_country_code' => 'CZ', 'citizenship_country_code' => null],
            array_intersect_key($relations['4']['identity'], ['birth_country_code' => 1, 'citizenship_country_code' => 1]),
            'Bez STOBC se občanství nehádá ze státu narození.');
    }

    /**
     * Zdravotní pojišťovna měsíce se zpracovanou mzdou je ta, které se pojistné platilo.
     * Evidenční zápis kódu pojišťovny bez odeslání (`PRIJATO` prázdné) dřív přebil pojišťovnu
     * měsíců se mzdou a osoba dostala pojišťovnu 111 i za měsíce, kdy měla 201.
     */
    public function testInsurerHistoryFollowsPayrollMonthsOverEvidenceOnlyNotice(): void
    {
        $this->backup(['payroll' => true]);
        [$fields, $rows] = SyntheticPremierBackup::tables(false, ['payroll' => true])['MZDY'];
        $template = array_values(array_filter($rows, static fn (array $r): bool => $r['INTER'] === 3))[0];
        foreach ([[3, '201'], [4, '201'], [5, '111']] as [$month, $insurer]) {
            $rows[] = ['MESIC' => $month, 'ZKR_POJ' => $insurer, 'ID' => "M3-2026-{$month}"] + $template;
        }
        DbfWriter::write($this->tmp . DIRECTORY_SEPARATOR . 'MZDY.DBF', $fields, $rows);
        [$prizpFields, $prizpRows] = SyntheticPremierBackup::tables(false, ['payroll' => true])['MZ_PRIZP'];
        $prizpFields[] = ['PRIJATO', 'L'];
        DbfWriter::write($this->tmp . DIRECTORY_SEPARATOR . 'MZ_PRIZP.DBF', $prizpFields, [
            ['INTER' => 3, 'HLAS_OD' => '2026-02-01', 'ZKRATKA_P' => '111', 'KOD' => 'P', 'PRIJATO' => false, 'ID' => 'ZP3-E'],
            ['INTER' => 3, 'HLAS_OD' => '2026-05-31', 'ZKRATKA_P' => '111', 'KOD' => 'O', 'PRIJATO' => true, 'ID' => 'ZP3-O'],
        ]);

        $employee = array_column(PremierPayroll::fromBackup(PremierBackup::open($this->tmp))->relations, null, 'key')['3'];
        self::assertSame([['201', '2026-02-01', '2026-04-30'], ['111', '2026-05-01', null]],
            array_map(static fn (array $run): array => [$run['code'], $run['from'], $run['to']], $employee['insurer_history']));
        self::assertSame('111', $employee['insurer_code'], 'Poslední pojišťovna osoby je z poslední historie.');

        // Měsíce bez mzdy platí z oznámení, po posledním měsíci se mzdou se oznámení vrací.
        self::assertSame([['201', '2026-01-01', '2026-02-28'], ['111', '2026-03-01', '2026-03-31'], ['205', '2026-04-01', null]],
            array_map(static fn (array $run): array => [$run['code'], $run['from'], $run['to']], PremierPayroll::insurerHistory(
                [['date' => '2026-01-10', 'code' => '201', 'kind' => 'P'], ['date' => '2026-04-05', 'code' => '205', 'kind' => 'Q']],
                '2026-01-10',
                ['2026-03' => '111'],
            )));
    }

    /** Srážky měsíce ze složek mezd v `DNY`, včetně zálohy na mzdu, kterou `SR_*` nenese. */
    public function testDeductionsFromPayrollItems(): void
    {
        $relations = PremierPayroll::fromBackup($this->backup(['payroll' => true, 'payroll_detail' => true]))->relations;
        $employee = array_values(array_filter($relations, static fn (array $r): bool => $r['key'] === '5'))[0];
        self::assertSame([0.0, 1000.0, 3000.0, 1000.0], [$employee['months']['2025-09']['deductions'], $employee['months']['2025-10']['deductions'],
            $employee['months']['2025-11']['deductions'], $employee['months']['2025-12']['deductions']]);
    }

    /**
     * Druh vztahu z druhu činnosti posledního hlášení JMHZ, když `KODPP_SO` chybí; hlášení
     * má přednost i před příznakem jednatele (ten zůstává v `statutory_flag` k ověření).
     */
    public function testRelationTypeFromJmhzActivity(): void
    {
        $backup = $this->backup(['payroll' => true, 'payroll_detail' => true]);
        [$fields, $rows] = SyntheticPremierBackup::tables(false, ['payroll' => true, 'payroll_detail' => true])['MZ_JMHZ2'];
        $rows[] = ['ID' => 'F1', 'ID_JMHZ' => 'J2601', 'INT_ZAM' => 1, 'XPRIJATO_Z' => 3, 'X10239' => '1', 'TS' => '2026021010:00:00#INSE#'];
        $rows[] = ['ID' => 'F2', 'ID_JMHZ' => 'J2601', 'INT_ZAM' => 2, 'XPRIJATO_Z' => 3, 'X10239' => 'A', 'TS' => '2026021010:00:00#INSE#'];
        DbfWriter::write($this->tmp . DIRECTORY_SEPARATOR . 'MZ_JMHZ2.DBF', $fields, $rows);
        $relations = PremierPayroll::fromBackup(PremierBackup::open($this->tmp))->relations;
        $byKey = array_column($relations, null, 'key');
        self::assertSame(['employment', true], [$byKey['1']['relation_type'], $byKey['1']['statutory_flag']]);
        self::assertSame('dpp', $byKey['2']['relation_type'], 'Vyplněný KODPP_SO (T) má přednost před hlášením JMHZ.');
        self::assertSame('employment', $byKey['5']['relation_type']);
        self::assertTrue($backup->hasTable('MZ_JMHZ2'));
    }

    public function testMonthTotalsMatchJournalPostings(): void
    {
        $backup = $this->backup(['payroll' => true]);
        $payroll = PremierPayroll::fromBackup($backup);
        $journal = new PremierJournal($backup);

        $totals = $payroll->monthTotals(SyntheticPremierBackup::YEAR1);
        self::assertCount(12, $totals);
        self::assertSame(['gross' => 9000.0, 'employee_insurance' => 696.0, 'employer_insurance' => 2028.0, 'tax' => 1350.0], $totals['2025-03'],
            'Jednatelka + DPP v jednom měsíci.');
        $months = PremierPayroll::reconcile($totals, PremierPayroll::ledgerTotals($journal, SyntheticPremierBackup::YEAR1));
        self::assertCount(12, $months);
        self::assertSame([], array_values(array_filter($months, static fn (array $m): bool => !$m['ok'])));

        $next = PremierPayroll::reconcile($payroll->monthTotals(SyntheticPremierBackup::YEAR2), PremierPayroll::ledgerTotals($journal, SyntheticPremierBackup::YEAR2));
        self::assertSame(['2026-01', '2026-02'], array_column($next, 'period'));
        self::assertTrue($next[1]['ok']);
        self::assertSame(3430.0 + 975.0, $next[1]['ledger']['tax'], 'Záloha na 342100 i srážková daň na 342200.');
    }

    public function testEmployerSocialInsuranceIsRoundedUpFromMonthTotal(): void
    {
        $backup = $this->backup(['payroll' => true, 'payroll_employer_fraction' => true]);
        $totals = PremierPayroll::fromBackup($backup)->monthTotals(SyntheticPremierBackup::YEAR2);
        self::assertSame(1612.0 + 585.0 + 9920.0 + 3600.0, $totals['2026-02']['employer_insurance'],
            'Přesný podíl 9 919,68 se v úhrnu měsíce zaokrouhlí nahoru na 9 920 jako v deníku.');
        $months = PremierPayroll::reconcile($totals, PremierPayroll::ledgerTotals(new PremierJournal($backup), SyntheticPremierBackup::YEAR2));
        self::assertSame([], array_values(array_filter($months, static fn (array $m): bool => !$m['ok'])));
    }

    public function testMismatchedMonthIsReported(): void
    {
        $backup = $this->backup(['payroll' => true, 'payroll_mismatch' => true]);
        $months = PremierPayroll::reconcile(
            PremierPayroll::fromBackup($backup)->monthTotals(SyntheticPremierBackup::YEAR1),
            PremierPayroll::ledgerTotals(new PremierJournal($backup), SyntheticPremierBackup::YEAR1),
        );
        $bad = array_values(array_filter($months, static fn (array $m): bool => !$m['ok']));
        self::assertCount(1, $bad);
        self::assertSame('2025-05', $bad[0]['period']);
        self::assertSame(['employer_insurance' => 40.0], $bad[0]['diffs']);
    }

    public function testLedgerOnlyMonthIsADifference(): void
    {
        $journal = PremierJournal::fromRows([
            ['INTER' => 1, 'DATUM' => '2025-02-28', 'DOKLAD' => 'MZ', 'CISLO' => '1', 'CASTKA' => 1000, 'MD' => '521100', 'DAL' => '331100'],
            // Záporná částka na stejných stranách je storno, ne mzda.
            ['INTER' => 2, 'DATUM' => '2025-02-28', 'DOKLAD' => 'MZ', 'CISLO' => '2', 'CASTKA' => -200, 'MD' => '521100', 'DAL' => '331100'],
            ['INTER' => 3, 'DATUM' => '2025-02-28', 'DOKLAD' => 'MZ', 'CISLO' => '3', 'CASTKA' => 50, 'MD' => '342100', 'DAL' => '331100'],
            ['INTER' => 4, 'DATUM' => '2025-02-28', 'DOKLAD' => 'BV', 'CISLO' => '4', 'CASTKA' => 800, 'MD' => '331100', 'DAL' => '221001'],
        ]);
        $ledger = PremierPayroll::ledgerTotals($journal, 2025);
        self::assertSame(['2025-02' => ['gross' => 800.0, 'employee_insurance' => 0.0, 'employer_insurance' => 0.0, 'tax' => -50.0]], $ledger,
            'Výplata z banky se nepočítá, bonus (342 → 331) snižuje daň.');
        $months = PremierPayroll::reconcile([], $ledger);
        self::assertFalse($months[0]['ok']);
        self::assertSame(['gross' => -800.0, 'tax' => 50.0], $months[0]['diffs']);
    }

    public function testBackupWithoutPayrollTables(): void
    {
        $payroll = PremierPayroll::fromBackup($this->backup([]));
        self::assertFalse($payroll->hasData());
        self::assertSame(['PERSONAL', 'MZDY'], $payroll->missingTables);
        self::assertSame([], $payroll->monthTotals(2025));
    }

    /** @param array<string,bool> $flags */
    private function backup(array $flags): PremierBackup
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'premier_pay_' . bin2hex(random_bytes(5));
        SyntheticPremierBackup::writeDir($this->tmp, false, $flags);
        return PremierBackup::open($this->tmp);
    }
}
