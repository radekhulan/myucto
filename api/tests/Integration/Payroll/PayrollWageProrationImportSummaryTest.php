<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Absence\PayrollWageProrationService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Krácení měsíční mzdy v měsíci převzatém z jiného mzdového programu.
 *
 * Takový měsíc má nepřítomnost ve DVOU podobách naráz: dovolenou a překážky
 * jako bezdatové hodiny měsíčního souhrnu, nemoc a ošetřovné datovaně
 * v evidenci nepřítomností. Test drží tři věci pohromadě — že se obojí sečte
 * do jednoho krácení, že se čisté případy (jen souhrn, jen data) nezměnily
 * ani o haléř, a že se míchaná evidence téhož titulu odmítne.
 *
 * Červen 2026 je zvolený záměrně: začíná pondělím, má 22 rozvržených dnů
 * (fond 176 h = 10 560 minut) a žádný svátek, takže do čísel nevstupuje
 * § 115 odst. 3 ZP. Sjednaná mzda je 40 000 Kč.
 */
#[Group('integration')]
final class PayrollWageProrationImportSummaryTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = '2026-06';
    private const GROSS_MINOR = 4_000_000;
    private const FUND_MINUTES = 10_560;

    private Connection $db;
    private PayrollWageProrationService $proration;
    private int $supplierId;
    private int $userId;
    private int $employmentId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            self::markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->proration = $container->get(PayrollWageProrationService::class);
        } catch (\Throwable $e) {
            self::markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        foreach ([
            'payroll_employments', 'payroll_shifts', 'payroll_absences',
            'payroll_work_calendars', 'payroll_time_months',
            'payroll_time_month_import_summaries', 'payroll_attendance_imports',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                self::markTestSkipped("Chybí integrační tabulka {$table}.");
            }
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            self::markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $this->employmentId = $this->createEmployment();
        $this->createWorkCalendar();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    /**
     * Souhrn i datovaná nepřítomnost v jednom měsíci: 48 h dovolené z hodin
     * souhrnu a 16 h neplaceného volna z evidence dávají dohromady 3 840 minut
     * ze 10 560. Odpracováno zbývá 6 720 minut a 40 000 x 6 720/10 560 je
     * 25 454,54 → nahoru na celé koruny (§ 142 odst. 2 ZP).
     *
     * Bez smíšené větve se tenhle měsíc vůbec nespočítá — krácení skončí jako
     * nepodporované a základní mzda jde k ručnímu posouzení.
     */
    public function testSummaryHoursAndDatedAbsencesAreProratedInOneCalculation(): void
    {
        $this->importSummaryMonth(['fund_hours' => 176_000, 'vacation_hours' => 48_000, 'worked_hours' => 112_000]);
        $this->datedAbsence('unpaid_leave', '2026-06-15', '2026-06-16');
        $this->publishedShift('2026-06-15');
        $this->publishedShift('2026-06-16');

        $result = $this->proration->forImportSummary(
            $this->supplierId,
            $this->employmentId,
            self::PERIOD,
            self::GROSS_MINOR,
        );

        self::assertTrue($result['supported'], (string) $result['reason']);
        self::assertSame(self::FUND_MINUTES, $result['fund_minutes']);
        self::assertSame(['vacation' => 2_880, 'unpaid' => 960], $result['replaced_minutes_by_title']);
        self::assertSame(3_840, $result['replaced_minutes']);
        self::assertSame(2_545_500, $result['amount_minor']);
    }

    /**
     * Regrese čistého souhrnu: 48 h dovolené ze 176 h fondu dává 29 090,90 →
     * 29 091 Kč. Tohle číslo platilo před smíšenou větví a nesmí se pohnout
     * ani o haléř.
     */
    public function testSummaryOnlyMonthKeepsItsAmount(): void
    {
        $this->importSummaryMonth(['fund_hours' => 176_000, 'vacation_hours' => 48_000, 'worked_hours' => 128_000]);

        $result = $this->proration->forImportSummary(
            $this->supplierId,
            $this->employmentId,
            self::PERIOD,
            self::GROSS_MINOR,
        );

        self::assertTrue($result['supported'], (string) $result['reason']);
        self::assertSame(self::FUND_MINUTES, $result['fund_minutes']);
        self::assertSame(['vacation' => 2_880], $result['replaced_minutes_by_title']);
        self::assertSame(2_909_100, $result['amount_minor']);
    }

    /**
     * Regrese čistě datovaného měsíce: 16 h neplaceného volna ze 176 h fondu
     * dává 36 363,63 → 36 364 Kč. Měsíc se směnami měří `forMonth()`, do
     * kterého smíšená větev sáhla jen přesunem výpočtu do sdílené metody.
     *
     * Táž nepřítomnost změřená cestou souhrnu (souhrn, který žádné hodiny
     * nepřítomnosti nenese) musí dát TOTÉŽ číslo — jinak by na výsledek měl
     * vliv zdroj docházky, ne evidence.
     */
    public function testDatedOnlyMonthKeepsItsAmountOnBothPaths(): void
    {
        $this->datedAbsence('unpaid_leave', '2026-06-15', '2026-06-16');
        $this->publishedShift('2026-06-15');
        $this->publishedShift('2026-06-16');

        $byMonth = $this->proration->forMonth(
            $this->supplierId,
            $this->employmentId,
            self::PERIOD,
            self::GROSS_MINOR,
        );

        self::assertTrue($byMonth['supported'], (string) $byMonth['reason']);
        self::assertSame(self::FUND_MINUTES, $byMonth['fund_minutes']);
        self::assertSame(['unpaid' => 960], $byMonth['replaced_minutes_by_title']);
        self::assertSame(3_636_400, $byMonth['amount_minor']);

        $this->importSummaryMonth(['fund_hours' => 176_000, 'worked_hours' => 160_000]);
        $bySummary = $this->proration->forImportSummary(
            $this->supplierId,
            $this->employmentId,
            self::PERIOD,
            self::GROSS_MINOR,
        );

        self::assertTrue($bySummary['supported'], (string) $bySummary['reason']);
        self::assertSame($byMonth['replaced_minutes_by_title'], $bySummary['replaced_minutes_by_title']);
        self::assertSame($byMonth['amount_minor'], $bySummary['amount_minor']);
    }

    /**
     * Týž titul z obou zdrojů není součet, ale míchaná evidence: převod dělí
     * druhy mezi souhrn a datovanou evidenci disjunktně, takže dovolená
     * v souhrnu i v evidenci znamená, že do dat někdo sáhl ručně. Sečíst obojí
     * by dobu započetlo dvakrát, vybrat jednu stranu by byl odhad.
     */
    public function testSameTitleFromBothSourcesIsRefused(): void
    {
        $this->importSummaryMonth(['fund_hours' => 176_000, 'vacation_hours' => 48_000, 'worked_hours' => 112_000]);
        $this->datedAbsence('vacation', '2026-06-15', '2026-06-16');
        $this->publishedShift('2026-06-15');
        $this->publishedShift('2026-06-16');

        $result = $this->proration->forImportSummary(
            $this->supplierId,
            $this->employmentId,
            self::PERIOD,
            self::GROSS_MINOR,
        );

        self::assertFalse($result['supported']);
        self::assertSame('import_summary_title_in_both_sources', $result['reason']);
        self::assertNull($result['amount_minor']);
    }

    /**
     * Součet obou zdrojů přes fond pracovní doby: 170 h dovolené a 16 h
     * neplaceného volna je 11 160 minut proti fondu 10 560. Evidence si
     * odporuje a číslo by lhalo, takže se nenavrhne nic.
     */
    public function testCombinedAbsenceOverTheWorkFundFailsClosed(): void
    {
        $this->importSummaryMonth(['fund_hours' => 176_000, 'vacation_hours' => 170_000, 'worked_hours' => 6_000]);
        $this->datedAbsence('unpaid_leave', '2026-06-15', '2026-06-16');
        $this->publishedShift('2026-06-15');
        $this->publishedShift('2026-06-16');

        $result = $this->proration->forImportSummary(
            $this->supplierId,
            $this->employmentId,
            self::PERIOD,
            self::GROSS_MINOR,
        );

        self::assertFalse($result['supported']);
        self::assertSame('absence_exceeds_work_fund', $result['reason']);
    }

    /**
     * Převzatý měsíc rozvrh směn NIKDY mít nebude — převod zakládá jen pracovní
     * kalendář a měsíční souhrn — a přitom v něm skoro vždy leží nemoc. Doba
     * nemoci se proto měří rozvrhem kalendáře: 15. až 19. 6. je pět rozvržených
     * dnů, tedy 2 400 minut, k tomu 48 h dovolené ze souhrnu (2 880 minut).
     * Zbývá 5 280 z 10 560 minut, tedy přesně polovina sjednané mzdy.
     *
     * Bez kalendářní větve se tenhle měsíc naměřit nedá a skončí k ruce.
     */
    public function testDatedSicknessWithoutShiftsIsMeasuredByTheWorkCalendar(): void
    {
        $this->importSummaryMonth(['fund_hours' => 176_000, 'vacation_hours' => 48_000, 'worked_hours' => 88_000]);
        $absenceId = $this->datedAbsence('dpn', '2026-06-15', '2026-06-19');
        $this->sicknessEvent($absenceId);

        $result = $this->proration->forImportSummary(
            $this->supplierId,
            $this->employmentId,
            self::PERIOD,
            self::GROSS_MINOR,
        );

        self::assertTrue($result['supported'], (string) $result['reason']);
        self::assertSame(self::FUND_MINUTES, $result['fund_minutes']);
        self::assertSame(
            ['vacation' => 2_880, 'sickness_compensation' => 2_400],
            $result['replaced_minutes_by_title'],
        );
        self::assertSame(2_000_000, $result['amount_minor']);
    }

    /**
     * Okno § 192 ZP platí i v kalendářní větvi. Nemoc od 1. do 19. 6. spadá
     * čtrnácti kalendářními dny do 14. 6.: deset rozvržených dnů v okně nese
     * náhradu od zaměstnavatele (4 800 minut), zbylých pět dnů je dávka
     * nemocenského pojištění (2 400 minut). Bez rozdělení by celá doba spadla
     * pod jediný titul a mzdový list by okno nedoložil.
     */
    public function testSicknessWindowIsSplitInTheCalendarBranch(): void
    {
        $this->importSummaryMonth(['fund_hours' => 176_000, 'worked_hours' => 33_600]);
        $absenceId = $this->datedAbsence('dpn', '2026-06-01', '2026-06-19');
        $this->sicknessEvent($absenceId);

        $result = $this->proration->forImportSummary(
            $this->supplierId,
            $this->employmentId,
            self::PERIOD,
            self::GROSS_MINOR,
        );

        self::assertTrue($result['supported'], (string) $result['reason']);
        self::assertSame(
            ['sickness_compensation' => 4_800, 'state_benefit' => 2_400],
            $result['replaced_minutes_by_title'],
        );
        self::assertSame(7_200, $result['replaced_minutes']);
        self::assertSame(1_272_800, $result['amount_minor']);
    }

    /**
     * Měsíc, který směny má, se jimi měří dál. Nepřítomnost 15. až 17. 6. má
     * rozvržené tři dny, ale publikované jen dvě směny — platí 960 minut ze
     * směn, ne 1 440 z rozvrhu. Kalendář je náhrada za chybějící rozvrh, ne
     * druhý zdroj téhož údaje.
     */
    public function testMonthWithShiftsIsStillMeasuredByShifts(): void
    {
        $this->importSummaryMonth(['fund_hours' => 176_000, 'worked_hours' => 152_000]);
        $this->datedAbsence('unpaid_leave', '2026-06-15', '2026-06-17');
        $this->publishedShift('2026-06-15');
        $this->publishedShift('2026-06-16');

        $result = $this->proration->forImportSummary(
            $this->supplierId,
            $this->employmentId,
            self::PERIOD,
            self::GROSS_MINOR,
        );

        self::assertTrue($result['supported'], (string) $result['reason']);
        self::assertSame(['unpaid' => 960], $result['replaced_minutes_by_title']);
    }

    /**
     * Poslední záchrana: nepřítomnost o víkendu nemá rozvrženou dobu ani ve
     * směnách, ani v kalendáři. Krátit pak jen podle hodin souhrnu by ji tiše
     * nechalo zaplacenou, takže se měsíc pošle k ruce.
     */
    public function testDatedAbsenceWithoutAnyScheduledTimeFailsClosed(): void
    {
        $this->importSummaryMonth(['fund_hours' => 176_000, 'vacation_hours' => 48_000, 'worked_hours' => 112_000]);
        $this->datedAbsence('unpaid_leave', '2026-06-20', '2026-06-21');

        $result = $this->proration->forImportSummary(
            $this->supplierId,
            $this->employmentId,
            self::PERIOD,
            self::GROSS_MINOR,
        );

        self::assertFalse($result['supported']);
        self::assertSame('dated_absence_without_shift_time', $result['reason']);
    }

    /** Nerozhodnutá nepřítomnost blokuje i měsíc ze souhrnu. */
    public function testPendingDatedAbsenceBlocksTheSummaryMonth(): void
    {
        $this->importSummaryMonth(['fund_hours' => 176_000, 'vacation_hours' => 48_000, 'worked_hours' => 112_000]);
        $this->datedAbsence('unpaid_leave', '2026-06-15', '2026-06-16', status: 'requested');
        $this->publishedShift('2026-06-15');

        $result = $this->proration->forImportSummary(
            $this->supplierId,
            $this->employmentId,
            self::PERIOD,
            self::GROSS_MINOR,
        );

        self::assertFalse($result['supported']);
        self::assertSame('absence_pending_decision', $result['reason']);
    }

    /**
     * Měsíční mzda pokrývá i svátek (§ 115 odst. 3 ZP), takže se krátí poměrem
     * k fondu VČETNĚ svátku. Červenec 2026 má 23 pracovních dnů, z toho pondělí
     * 6. 7. je svátek: odpracovává se 176 h, měsíční mzda kryje 184 h
     * (11 040 minut). 48 h dovolené: 40 000 x (11 040 - 2 880)/11 040 =
     * 29 565,21 → 29 566 Kč. Z fondu bez svátku by vyšlo 29 091 Kč.
     */
    public function testSummaryMonthWithHolidayProratesAgainstFundIncludingHoliday(): void
    {
        $this->importSummaryMonth(
            ['fund_hours' => 184_000, 'vacation_hours' => 48_000, 'worked_hours' => 128_000],
            '2026-07-01',
        );

        $result = $this->proration->forImportSummary(
            $this->supplierId,
            $this->employmentId,
            '2026-07',
            self::GROSS_MINOR,
        );

        self::assertTrue($result['supported'], (string) $result['reason']);
        self::assertSame(11_040, $result['fund_minutes']);
        self::assertSame(['vacation' => 2_880], $result['replaced_minutes_by_title']);
        self::assertSame(2_956_600, $result['amount_minor']);
    }

    /**
     * Totéž na směnové cestě s placenou překážkou: 16 h překážky na straně
     * zaměstnavatele v červenci 2026 dává 40 000 x 10 080/11 040 = 36 521,73
     * → 36 522 Kč (z fondu bez svátku 36 364 Kč). Překážka přes svátek (6. 7.)
     * svátek nezahrnuje: mzda se za něj nekrátí (§ 115 odst. 3 ZP).
     */
    public function testShiftMonthWithHolidayProratesAgainstFundIncludingHoliday(): void
    {
        $this->datedAbsence('employer_obstacle', '2026-07-06', '2026-07-08');
        $this->publishedShift('2026-07-07');
        $this->publishedShift('2026-07-08');

        $result = $this->proration->forMonth(
            $this->supplierId,
            $this->employmentId,
            '2026-07',
            self::GROSS_MINOR,
        );

        self::assertTrue($result['supported'], (string) $result['reason']);
        self::assertSame(11_040, $result['fund_minutes']);
        self::assertSame(['paid_obstacle' => 960], $result['replaced_minutes_by_title']);
        self::assertSame(3_652_200, $result['amount_minor']);
    }

    /**
     * Neplacené volno 6. až 8. 7. 2026 přes svátek 6. 7.: zaměstnanec ten den
     * nepracoval kvůli volnu, ne kvůli svátku, takže ochrana § 115 odst. 3 ZP
     * se na něj nevztahuje a krátí se i obvyklých osm hodin svátku. Směna na
     * svátek publikovaná není — svátek se měří rozvrhem. 40 000 x
     * (11 040 - 1 440)/11 040 = 34 782,60 → 34 783 Kč.
     */
    public function testUnpaidLeaveOverHolidayCutsTheHolidayOnTheShiftPath(): void
    {
        $this->datedAbsence('unpaid_leave', '2026-07-06', '2026-07-08');
        $this->publishedShift('2026-07-07');
        $this->publishedShift('2026-07-08');

        $result = $this->proration->forMonth(
            $this->supplierId,
            $this->employmentId,
            '2026-07',
            self::GROSS_MINOR,
        );

        self::assertTrue($result['supported'], (string) $result['reason']);
        self::assertSame(['unpaid' => 1_440], $result['replaced_minutes_by_title']);
        self::assertSame(3_478_300, $result['amount_minor']);
    }

    /**
     * Rodičovská dovolená celý leden 2026 v měsíci ze souhrnu importu (bez
     * směn, měří se kalendářem). Leden má 22 pracovních dnů a 1. 1. je svátek:
     * mzdový fond 10 560 minut. Za rodičovskou mzda ani náhrada nepřísluší
     * a za svátek se mzda krátí také, takže základní mzda je nula. Dříve tu
     * zůstalo 40 000 x 480/10 560 → 1 819 Kč za svátek (PAMICA 0).
     */
    public function testParentalLeaveForWholeMonthLeavesNoWageForTheHoliday(): void
    {
        $this->importSummaryMonth(['fund_hours' => 176_000, 'worked_hours' => 0], '2026-01-01');
        $this->datedAbsence('parental', '2026-01-01', '2026-01-31');

        $result = $this->proration->forImportSummary(
            $this->supplierId,
            $this->employmentId,
            '2026-01',
            self::GROSS_MINOR,
        );

        self::assertTrue($result['supported'], (string) $result['reason']);
        self::assertSame(10_560, $result['fund_minutes']);
        self::assertSame(['state_benefit' => 10_560], $result['replaced_minutes_by_title']);
        self::assertSame(0, $result['amount_minor']);
    }

    /**
     * Nemoc za oknem § 192 ZP přes svátek na směnové cestě. DPN od 20. 6. do
     * 10. 7. 2026: okno náhrady končí 3. 7. (tři směny v červenci, 1 440
     * minut náhrady), za oknem jsou směny 7. až 10. 7. a svátek 6. 7. bez
     * směny. Dávku za něj platí stát, mzda se za svátek krátí: 2 400 minut.
     * 40 000 x (11 040 - 3 840)/11 040 = 26 086,95 → 26 087 Kč. Kalendářní
     * cesta svátek za oknem krátila vždy, směnová ho nechávala ve mzdě.
     */
    public function testHolidayBeyondSicknessWindowIsCutOnTheShiftPath(): void
    {
        $absenceId = $this->datedAbsence('dpn', '2026-06-20', '2026-07-10');
        $this->sicknessEvent($absenceId);
        foreach (['2026-07-01', '2026-07-02', '2026-07-03', '2026-07-07', '2026-07-08', '2026-07-09', '2026-07-10'] as $date) {
            $this->publishedShift($date);
        }

        $result = $this->proration->forMonth(
            $this->supplierId,
            $this->employmentId,
            '2026-07',
            self::GROSS_MINOR,
        );

        self::assertTrue($result['supported'], (string) $result['reason']);
        self::assertSame(
            ['sickness_compensation' => 1_440, 'state_benefit' => 2_400],
            $result['replaced_minutes_by_title'],
        );
        self::assertSame(2_608_700, $result['amount_minor']);
    }

    /**
     * Svátek v okně náhrady při DPN platí náhrada (§ 192 odst. 1 ZP), takže
     * v době kryté mzdou nezůstane. Nemoc 1. až 10. 7. 2026 bez směn (měsíc ze
     * souhrnu): osm rozvržených dnů včetně svátku 6. 7. = 3 840 minut.
     * 40 000 x (11 040 - 3 840)/11 040 = 26 086,95 → 26 087 Kč.
     */
    public function testHolidayInsideSicknessWindowIsNotPaidTwice(): void
    {
        $this->importSummaryMonth(['fund_hours' => 184_000, 'worked_hours' => 120_000], '2026-07-01');
        $absenceId = $this->datedAbsence('dpn', '2026-07-01', '2026-07-10');
        $this->sicknessEvent($absenceId);

        $result = $this->proration->forImportSummary(
            $this->supplierId,
            $this->employmentId,
            '2026-07',
            self::GROSS_MINOR,
        );

        self::assertTrue($result['supported'], (string) $result['reason']);
        self::assertSame(['sickness_compensation' => 3_840], $result['replaced_minutes_by_title']);
        self::assertSame(2_608_700, $result['amount_minor']);
    }

    private function datedAbsence(
        string $type,
        string $from,
        string $to,
        string $status = 'approved',
    ): int {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_absences
                (supplier_id, employment_id, absence_type, date_from, date_to,
                 timezone_name, compensation_policy, support_status, status, requested_by)
             VALUES (?, ?, ?, ?, ?, "Europe/Prague", "none", "supported", ?, ?)'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            $type,
            $from,
            $to,
            $status,
            $this->userId,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Zmrazený výpočet náhrady při DPN. Krácení z něj čte jedinou věc — zda byl
     * první den odpracován celý — a bez něj se okno § 192 ZP odmítne hádat.
     */
    private function sicknessEvent(int $absenceId, bool $firstDayFullyWorked = false): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_average_earning_snapshots
                (supplier_id, employment_id, applicable_year, applicable_quarter,
                 source_kind, decisive_from, decisive_to, gross_earnings_minor,
                 worked_minutes, worked_days, average_hourly_minor, support_status,
                 status, ruleset_id, ruleset_hash, input_hash, input_trace, created_by)
             VALUES (?, ?, 2026, 2, "actual", "2026-01-01", "2026-03-31", 12000000,
                     9600, 60, 25000, "supported", "approved", "test", ?, ?, "{}", ?)'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            str_repeat('b', 64),
            random_bytes(32),
            $this->userId,
        ]);
        $averageId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_sickness_events
                (supplier_id, absence_id, first_day_fully_worked,
                 insurance_eligibility_confirmed, conflicting_benefit_excluded,
                 average_snapshot_id, compensation_window_from, compensation_window_to,
                 reduced_hourly_minor, compensation_minor, support_status,
                 ruleset_id, ruleset_hash, calculation_trace, calculated_by)
             SELECT ?, ?, ?, 1, 1, ?, date_from, LEAST(date_to, date_from + INTERVAL 13 DAY),
                    15000, 0, "supported", "test", ?, "{}", ?
               FROM payroll_absences WHERE supplier_id = ? AND id = ?'
        )->execute([
            $this->supplierId,
            $absenceId,
            $firstDayFullyWorked ? 1 : 0,
            $averageId,
            str_repeat('b', 64),
            $this->userId,
            $this->supplierId,
            $absenceId,
        ]);
    }

    /** Osmihodinová publikovaná směna (6:00-14:30 UTC s půlhodinovou pauzou). */
    private function publishedShift(string $date): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_shifts
                (supplier_id, employment_id, series_key, starts_at_utc, ends_at_utc,
                 timezone_name, break_minutes, status, published_by, published_at)
             VALUES (?, ?, ?, ?, ?, "Europe/Prague", 30, "published", ?, NOW())'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            md5($date),
            $date . ' 06:00:00',
            $date . ' 14:30:00',
            $this->userId,
        ]);
    }

    /** @param array<string,int> $values millihodiny podle významu */
    private function importSummaryMonth(array $values, string $periodStart = '2026-06-01'): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_time_months
                (supplier_id, employment_id, period_start, status, work_source, revision_no)
             VALUES (?, ?, ?, "open", "import_summary", 1)'
        )->execute([$this->supplierId, $this->employmentId, $periodStart]);
        $timeMonthId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_attendance_imports
                (supplier_id, period_start, source_system, content_sha256,
                 files_json, rules_json, person_count, metric_count, created_by)
             VALUES (?, ?, "giriton", ?, "[]", "{}", 1, 1, ?)'
        )->execute([$this->supplierId, $periodStart, random_bytes(32), $this->userId]);
        $importId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO payroll_time_month_import_summaries
                (supplier_id, time_month_id, time_month_revision_no, employment_id,
                 period_start, attendance_import_id, values_json, worked_days,
                 sources_json, content_sha256, created_by)
             VALUES (?, ?, 1, ?, ?, ?, ?, 20, "{}", ?, ?)'
        )->execute([
            $this->supplierId,
            $timeMonthId,
            $this->employmentId,
            $periodStart,
            $importId,
            (string) json_encode($values),
            str_repeat('a', 64),
            $this->userId,
        ]);
    }

    /** Pondělí až pátek po osmi hodinách: červen 2026 má 22 rozvržených dnů. */
    private function createWorkCalendar(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_work_calendars
                (supplier_id, employment_id, name, timezone_name, schedule_type,
                 week_pattern, weekly_minutes, valid_from, created_by)
             VALUES (?, ?, "Test", "Europe/Prague", "regular",
                     ?, 2400, "2026-01-01", ?)'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            '{"1":480,"2":480,"3":480,"4":480,"5":480,"6":0,"7":0}',
            $this->userId,
        ]);
    }

    private function createEmployment(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetická osoba KRAC", "employee", "hpp", 1, 1, 0, 40000, 0, 1)'
        )->execute([$this->supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor,
                 is_legacy_projection)
             VALUES (?, ?, "KRAC-1", "employment", "active",
                     "2026-01-01", "2026-01-01", 4000000, 0)'
        )->execute([$this->supplierId, $employeeId]);

        return (int) $pdo->lastInsertId();
    }
}
