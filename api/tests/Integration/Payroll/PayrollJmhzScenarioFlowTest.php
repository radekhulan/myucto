<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollDependantAction;
use MyInvoice\Repository\Payroll\PayrollAttendanceImportRepository;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenPayloadReader;
use MyInvoice\Service\Payroll\Time\PayrollTimeImportApprovalService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

/**
 * Scénáře měsíčního hlášení JMHZ od založení zaměstnance po testovací
 * sestavení XML: dovolená, souběh vztahů, neplacené volno, náhradní volno,
 * pracovní neschopnost, mateřská, pracující důchodce, dítě s pořadím N,
 * dohoda se srážkovou daní a dohoda bez stanovené týdenní doby.
 *
 * Každý scénář jde cestou účetní: evidence, absence, docházka, běh,
 * příprava hlášení a dry-run s XSD a kontrolami katalogu. Kde se tok
 * zastaví, test vypíše krok a důvod, tedy místo, kde by se zasekl uživatel.
 * Tvar výsledného XML se ověřuje proti tvarům, které JMHZ přijalo.
 *
 * S proměnnou prostředí MYUCTO_JMHZ_SCENARIO_DUMP (adresář) se XML každého
 * scénáře uloží pro soukromé porovnání mimo repozitář.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollJmhzScenarioFlowTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-07';
    private const PERIOD_START = '2026-07-01';
    private const PAYDAY = '2026-08-14';

    private int $officeId;
    private int $baseComponentId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('JMHZ', 'Syntetická registrace JMHZ', '1100001237');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->baseComponentId = $this->createComponent('MZDA_MESICNI_FLOW', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        if (!$mappings instanceof PayrollComponentJmhzMappingRepository) {
            throw new \RuntimeException('Mapování mzdových složek JMHZ není dostupné.');
        }
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testFullTimeEmployeeWithVacation(): void
    {
        $person = $this->hire('Eva Dovolená', 'female', '1988-04-12');
        $vacation = ['2026-07-20', '2026-07-21'];
        $this->createApprovedAbsence(
            $person['employment_id'],
            'vacation',
            $vacation[0],
            $vacation[1],
            $person['average_id'],
        );
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD, $vacation));
        $this->pay($person, 4_500_000);

        $xml = $this->submission('vacation');

        self::assertSame(1, substr_count($xml, '</formularOsoby>'));
        // Dovolená jde do 10279 a zároveň do placených neodpracovaných 10276;
        // k ní svátek 6. 7. (pokyny MPSV k 10275/10276), takže 16 + 8 hodin.
        self::assertStringContainsString('<form:hodinyNeodpracCelkem>24.000</form:hodinyNeodpracCelkem>', $xml);
        self::assertStringContainsString('<form:hodinyNeodpracNahrada>24.000</form:hodinyNeodpracNahrada>', $xml);
        self::assertStringContainsString('<form:hodinyNeodpracDovol>16.000</form:hodinyNeodpracDovol>', $xml);
        self::assertStringContainsString('<form:vylouceneDobyCelkem>0</form:vylouceneDobyCelkem>', $xml);
        // 10268 + 10275 = 10260: 160 odpracovaných + 24 neodpracovaných = 184.
        self::assertStringContainsString('<form:sjednanyFond>184.000</form:sjednanyFond>', $xml);
        self::assertStringContainsString('<form:pocet>160.000</form:pocet>', $xml);
    }

    /**
     * Měsíc bez nepřítomnosti se svátkem v jinak pracovní den (6. 7. 2026).
     * Pokyny MPSV: stanovený fond 10259 svátky zahrnuje (23 pracovních dnů
     * = 184 hodin) a jejich neodpracované hodiny patří do 10275 i do 10276,
     * takže platí 10268 + 10275 = 10260. Hromadné i ruční schválení pošle
     * neodpracované hodiny prázdné, svátek doplní souhrn sám.
     */
    public function testHolidayOnWorkdayIsReportedAsPaidUnworkedHours(): void
    {
        $person = $this->hire('Olga Svátek', 'female', '1986-02-14');
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->pay($person, 4_000_000);

        $xml = $this->submission('holiday');

        self::assertStringContainsString('<form:stanovenyFond>184.000</form:stanovenyFond>', $xml);
        self::assertStringContainsString('<form:sjednanyFond>184.000</form:sjednanyFond>', $xml);
        self::assertStringContainsString('<form:pocet>176.000</form:pocet>', $xml);
        self::assertStringContainsString(
            '<form:neodpracovaneHodiny><form:hodinyNeodpracCelkem>8.000</form:hodinyNeodpracCelkem>'
                . '<form:hodinyNeodpracNahrada>8.000</form:hodinyNeodpracNahrada></form:neodpracovaneHodiny>',
            $xml,
        );
    }

    /**
     * Docházka převzatá z docházkového systému: měsíční součty hodin bez
     * intervalů, hromadně schválené z dávky importu. Běh ani příprava hlášení
     * nesmí tvrdit, že pracovní doba není schválená; nepovinné dny 10267
     * podklady nenesou, a proto v hlášení chybí.
     */
    public function testImportedAttendanceMonthApprovedInBulkReachesSubmission(): void
    {
        $person = $this->hire('Ivo Import', 'male', '1984-11-20');
        $imports = $this->container->get(PayrollAttendanceImportRepository::class);
        $approvals = $this->container->get(PayrollTimeImportApprovalService::class);
        self::assertInstanceOf(PayrollAttendanceImportRepository::class, $imports);
        self::assertInstanceOf(PayrollTimeImportApprovalService::class, $approvals);
        $file = 'dochazka-cervenec.xlsx';
        $importId = $imports->insertBatch(
            $this->supplierId,
            self::PERIOD_START,
            'attendance',
            random_bytes(32),
            [['name' => $file, 'sha256' => hash('sha256', 'syntetická docházka červenec')]],
            [],
            1,
            [
                [
                    'employment_id' => $person['employment_id'],
                    'meaning' => 'worked_hours',
                    'component_code' => '',
                    'quantity_millihours' => 176_000,
                    'amount_minor' => null,
                    'source_ref' => "{$file}!List1!C3",
                ],
                [
                    'employment_id' => $person['employment_id'],
                    'meaning' => 'fund_hours',
                    'component_code' => '',
                    'quantity_millihours' => 176_000,
                    'amount_minor' => null,
                    'source_ref' => "{$file}!List1!D3",
                ],
            ],
            $this->actors[0],
        );
        self::assertNotNull($importId);
        $approval = $approvals->applyBatch($this->supplierId, $importId, true, $this->actors[0]);
        self::assertSame(1, $approval['approved'], 'Zaseknutí: hromadné schválení. ' . CanonicalJson::encode($approval));
        $this->pay($person, 4_500_000);

        $xml = $this->submission('import-summary');

        self::assertSame(0, (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_run_validations
              WHERE supplier_id = ? AND code IN ("time_month_missing", "time_month_not_approved")',
            [$this->supplierId],
        ));
        self::assertStringContainsString('<form:odpracovaneHodiny><form:pocet>176.000</form:pocet>', $xml);
        self::assertStringNotContainsString('<form:dnyOdpracovanePocet>', $xml);
    }

    public function testConcurrentFullTimeAndAgreementOfOnePerson(): void
    {
        $person = $this->hire('Filip Souběh', 'male', '1979-09-30');
        $agreement = $this->hireAgreement($person, 'dpp');
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->approveMonth($agreement['employment_id'], ['2026-07-04', '2026-07-11'], dailyMinutes: 240);
        $this->pay($person, 4_000_000);
        $this->pay($agreement, 800_000);

        $xml = $this->submission('concurrent');

        // Formulář za každý vztah, souhrnná data osoby jen na hlavním.
        self::assertSame(2, substr_count($xml, '</formularOsoby>'));
        self::assertSame(1, substr_count($xml, '<form:souhrnDataZec>'));
        self::assertSame(1, substr_count($xml, '<primarniPpv>true</primarniPpv>'));
        self::assertSame(1, substr_count($xml, '<primarniPpv>false</primarniPpv>'));
    }

    public function testWholeMonthUnpaidLeave(): void
    {
        // Doplatek do minima za neplacené volno hradí zaměstnanec.
        $person = $this->hire('Gita Volno', 'female', '1990-01-15', healthTopUpResponsibility: 'employee');
        $this->createApprovedAbsence($person['employment_id'], 'unpaid_leave', '2026-07-01', '2026-07-31');
        $this->approveMonth($person['employment_id'], []);

        $xml = $this->submission('unpaid-leave');

        self::assertSame(1, substr_count($xml, '</formularOsoby>'));
        // § 11 odst. 2 zák. 155/1995: měsíc bez příjmu mimo dobu pojištění,
        // kód zůstává, dny i základ nula; § 18 odst. 7 vylučuje celý měsíc.
        self::assertStringContainsString('<form:kod>1++</form:kod>', $xml);
        self::assertStringContainsString('<form:pocetDnu>0</form:pocetDnu>', $xml);
        self::assertStringContainsString('<form:omluvenaNepritomnost>31</form:omluvenaNepritomnost>', $xml);
        // Neplacené volno evidenční stav nemění.
        self::assertStringContainsString('<form:dnyEvidencniStav>31</form:dnyEvidencniStav>', $xml);
        // Kontroly 282 a 283: při nulových hodinách a nulovém příjmu se
        // rozpad přesčasu ani osvobozený příjem neuvádějí.
        self::assertStringNotContainsString('<form:rozpad>', $xml);
        self::assertStringNotContainsString('<form:osvobozenoCelkem>', $xml);
        // Doplatek do minima (13,5 % z 22 400 Kč) za neplacené volno hradí zaměstnanec.
        self::assertStringContainsString(
            '<form:zdravPojZamestnavatel><form:zdravotniPojisteni>0</form:zdravotniPojisteni></form:zdravPojZamestnavatel>'
                . '<form:zdravPojZamestnanec><form:zdravotniPojisteni>3024</form:zdravotniPojisteni></form:zdravPojZamestnanec>',
            $xml,
        );
    }

    /**
     * Nástup rodičovské v polovině měsíce u vztahu bez publikovaných směn.
     * Hodiny rodičovské se měří pracovním kalendářem jako krácení mzdy
     * (12 pracovních dnů od 16. 7. = 96 h), svátek 6. 7. v odpracované části
     * je placený. 10268 + 10275 = 10260: 80 + 104 = 184.
     */
    public function testParentalLeaveFromMidMonthWithoutPublishedShifts(): void
    {
        $person = $this->hire('Renata Kalendářová', 'female', '1992-06-06', publishShifts: false);
        $this->createApprovedAbsence($person['employment_id'], 'parental', '2026-07-16', '2026-12-31');
        $worked = array_values(array_filter(
            self::workdays(self::PERIOD),
            static fn (string $date): bool => $date < '2026-07-16',
        ));
        $this->approveMonth($person['employment_id'], $worked);
        $this->pay($person, 2_000_000);

        $xml = $this->submission('parental-calendar');

        self::assertStringContainsString('<form:sjednanyFond>184.000</form:sjednanyFond>', $xml);
        self::assertStringContainsString('<form:pocet>80.000</form:pocet>', $xml);
        self::assertStringContainsString(
            '<form:neodpracovaneHodiny><form:hodinyNeodpracCelkem>104.000</form:hodinyNeodpracCelkem>'
                . '<form:hodinyNeodpracNahrada>8.000</form:hodinyNeodpracNahrada></form:neodpracovaneHodiny>',
            $xml,
        );
    }

    public function testCompensatoryTimeOff(): void
    {
        $person = $this->hire('Hynek Náhradní', 'male', '1985-05-05');
        $this->createApprovedAbsence($person['employment_id'], 'compensatory_time_off', '2026-07-24', '2026-07-24');
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD, ['2026-07-24']));
        $this->pay($person, 4_200_000);

        $xml = $this->submission('compensatory');

        self::assertSame(1, substr_count($xml, '</formularOsoby>'));
        // Náhradní volno: jen úhrn 10275, bez placených 10276 (§ 114 odst. 1 ZP),
        // celý den je vyloučeným dnem § 18 odst. 7. Svátek 6. 7. je v obou.
        self::assertStringContainsString('<form:hodinyNeodpracCelkem>16.000</form:hodinyNeodpracCelkem>', $xml);
        self::assertStringContainsString('<form:hodinyNeodpracNahrada>8.000</form:hodinyNeodpracNahrada>', $xml);
        self::assertStringContainsString('<form:omluvenaNepritomnost>1</form:omluvenaNepritomnost>', $xml);
    }

    public function testSickLeaveBeyondEmployerCompensationWindow(): void
    {
        $person = $this->hire('Ivan Nemocný', 'male', '1982-11-02');
        $sick = self::dateRange('2026-07-06', '2026-07-31');
        $this->createApprovedAbsence(
            $person['employment_id'],
            'dpn',
            '2026-07-06',
            '2026-07-31',
            $person['average_id'],
            decisionExtra: [
                'first_day_fully_worked' => false,
                'insurance_eligibility_confirmed' => true,
                'conflicting_benefit_excluded' => true,
            ],
        );
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD, $sick));
        $this->pay($person, 900_000);

        $xml = $this->submission('sick-leave');

        self::assertSame(1, substr_count($xml, '</formularOsoby>'));
        // Celá DPN je vyloučenou dobou; hodiny se dělí oknem náhrady § 192 ZP.
        self::assertStringContainsString('<form:docasNeschopnost>26</form:docasNeschopnost>', $xml);
        // Vyloučené dny § 18 odst. 7: 14 dnů v okně náhrady mzdy (10474),
        // 12 dnů nemocenského s potvrzeným nárokem (10475).
        self::assertStringContainsString(
            '<form:vyloucenePar18>26</form:vyloucenePar18><form:omluvenaNepritomnost>0</form:omluvenaNepritomnost>'
                . '<form:pracovniNeschopnost>14</form:pracovniNeschopnost><form:vyplaceniDavek>12</form:vyplaceniDavek>',
            $xml,
        );
        self::assertStringContainsString('<form:hodinyNeodpracNeschop>80.000</form:hodinyNeodpracNeschop>', $xml);
        self::assertStringContainsString('<form:hodinyNeodpracBezNahrady>80.000</form:hodinyNeodpracBezNahrady>', $xml);
        // Pokyny MPSV k 10276: hodiny DPN se neuvádějí. Nemoc s náhradou mzdy
        // je překážkou na straně zaměstnance (ZP část osmá, hlava I), 10471.
        self::assertStringNotContainsString('<form:hodinyNeodpracNahrada>', $xml);
        self::assertStringContainsString(
            '<form:prekazkyVPraci><form:prekazkaZamestnanec>80.000</form:prekazkaZamestnanec></form:prekazkyVPraci>',
            $xml,
        );
        // Pracovní souhrn, ze kterého sleva § 7a ZPSZ počítá hodiny s náhradou
        // mzdy, nemoc v okně náhrady dál nese: převod na 10276/10471 je jen
        // v hlášení a výpočet slevy se nemění.
        $stmt = $this->db->pdo()->prepare(
            'SELECT unworked_paid_millihours, dpn_with_employer_compensation_millihours,
                    employee_obstacle_paid_millihours, work_obstacles_occurred
               FROM payroll_jmhz_work_month_revisions
              WHERE supplier_id = ? AND employment_id = ?
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$this->supplierId, $person['employment_id']]);
        self::assertSame(
            [
                'unworked_paid_millihours' => 80_000,
                'dpn_with_employer_compensation_millihours' => 80_000,
                'employee_obstacle_paid_millihours' => null,
                'work_obstacles_occurred' => 0,
            ],
            array_map(
                static fn (mixed $value): ?int => $value === null ? null : (int) $value,
                $stmt->fetch(\PDO::FETCH_ASSOC) ?: [],
            ),
        );
        // § 3 odst. 9 písm. b) zák. 592/1992: minimum se krátí o 26 dnů DPN
        // na 3 613 Kč, základ 9 000 Kč ho převyšuje, takže doplatek nevzniká:
        // 9 % a 4,5 % z 9 000 Kč.
        self::assertStringContainsString(
            '<form:zdravPojZamestnavatel><form:zdravotniPojisteni>810</form:zdravotniPojisteni></form:zdravPojZamestnavatel>'
                . '<form:zdravPojZamestnanec><form:zdravotniPojisteni>405</form:zdravotniPojisteni></form:zdravPojZamestnanec>',
            $xml,
        );
    }

    public function testPreBirthMaternityForTheWholeMonth(): void
    {
        $person = $this->hire('Jana Mateřská', 'female', '1993-03-08');
        $this->createApprovedAbsence(
            $person['employment_id'],
            'ppm',
            '2026-07-01',
            '2026-12-31',
            extra: ['expected_childbirth_date' => '2026-08-20'],
        );
        $this->approveMonth($person['employment_id'], []);

        $xml = $this->submission('maternity');

        self::assertSame(1, substr_count($xml, '</formularOsoby>'));
        // PPM před porodem je omluvný důvod: dobou pojištění zůstává celý
        // měsíc a celý je vyloučenou dobou 10359.
        self::assertStringContainsString('<form:pocetDnu>31</form:pocetDnu>', $xml);
        self::assertStringContainsString('<form:penezitaPomocMaterstvi>31</form:penezitaPomocMaterstvi>', $xml);
        self::assertStringContainsString('<form:vylouceneDobyCelkem>31</form:vylouceneDobyCelkem>', $xml);
        self::assertStringNotContainsString('<form:osvobozenoCelkem>', $xml);
        // Na mateřské není zaměstnankyně v evidenčním stavu (10265 = 0) a bez
        // práce i příjmu jde o řádný formulář s nulovými hodnotami: fond
        // 10259 až 10261 je 0 (Pravidla podání 1.4.6, str. 16-17).
        self::assertStringContainsString('<form:dnyEvidencniStav>0</form:dnyEvidencniStav>', $xml);
        self::assertStringContainsString('<form:stanovenyFond>0.000</form:stanovenyFond>', $xml);
        self::assertStringContainsString('<form:sjednanyFond>0.000</form:sjednanyFond>', $xml);
        self::assertStringContainsString('<form:stanovenaTydenniDoba>0.00</form:stanovenaTydenniDoba>', $xml);
        // Za příjemkyni PPM platí pojistné stát (§ 7 odst. 1 písm. d) zák.
        // 48/1997), minimum se po celý měsíc nepoužije: bez doplatku.
        self::assertStringContainsString(
            '<form:zdravPojZamestnavatel><form:zdravotniPojisteni>0</form:zdravotniPojisteni></form:zdravPojZamestnavatel>'
                . '<form:zdravPojZamestnanec><form:zdravotniPojisteni>0</form:zdravotniPojisteni></form:zdravPojZamestnanec>',
            $xml,
        );
    }

    public function testWorkingPensionerDiscount(): void
    {
        $person = $this->hire('Karel Důchodce', 'male', '1958-06-21', socialDiscountStatus: 'verified');
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->pay($person, 3_000_000);

        $xml = $this->submission('pensioner');

        // 6,5 % z 30 000 Kč = 1 950 Kč (§ 7e zák. 589/1992).
        self::assertStringContainsString('<form:slevaZamestnanceEvidovana>true</form:slevaZamestnanceEvidovana>', $xml);
        self::assertStringContainsString('<form:vyseSlevy>1950</form:vyseSlevy>', $xml);
        // Poživatel starobního důchodu: údaje třídy ELDP se nehlásí, jen
        // pojištění od–do a 10356 = 0 (metodika MPSV/ČSSZ). Vyměřovací základ
        // a pojistné zůstávají.
        self::assertStringContainsString(
            '<form:eldpSeznam><form:eldp><form:pocetDnu>0</form:pocetDnu></form:eldp></form:eldpSeznam>',
            $xml,
        );
        self::assertStringNotContainsString('<form:kod>', $xml);
        self::assertStringContainsString('<form:castkaOdvodPojistneho>30000</form:castkaOdvodPojistneho>', $xml);
    }

    /**
     * Pracující důchodce v pracovní neschopnosti: sekce bez kódu ELDP, ale
     * vyloučené dny § 18 odst. 7 nese dál (nemocensky pojištěný zůstává).
     */
    public function testWorkingPensionerOnSickLeaveKeepsSection18Days(): void
    {
        $person = $this->hire('Petr Důchodce', 'male', '1957-03-11', socialDiscountStatus: 'verified');
        $sick = self::dateRange('2026-07-13', '2026-07-24');
        $this->createApprovedAbsence(
            $person['employment_id'],
            'dpn',
            '2026-07-13',
            '2026-07-24',
            $person['average_id'],
            decisionExtra: [
                'first_day_fully_worked' => false,
                'insurance_eligibility_confirmed' => true,
                'conflicting_benefit_excluded' => true,
            ],
        );
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD, $sick));
        $this->pay($person, 2_000_000);

        $xml = $this->submission('pensioner-sick');

        self::assertStringContainsString(
            '<form:eldp><form:pocetDnu>0</form:pocetDnu><form:vylouceneDny>'
                . '<form:vyloucenePar18>12</form:vyloucenePar18><form:omluvenaNepritomnost>0</form:omluvenaNepritomnost>'
                . '<form:pracovniNeschopnost>12</form:pracovniNeschopnost><form:vyplaceniDavek>0</form:vyplaceniDavek>'
                . '</form:vylouceneDny></form:eldp>',
            $xml,
        );
        self::assertStringNotContainsString('<form:vylouceneDobyCelkem>', $xml);
    }

    /**
     * Měsíc porodu bez příjmu (PPM od června, porod 15. 7.): tvar přijatý ČSSZ
     * v hlášení jiného systému — celý měsíc je dobou pojištění (10356 = 31),
     * vyloučenou dobou 10357 = 10359 dny před porodem (14) a celý měsíc
     * vyloučenými dny s vyplacenou dávkou (10475 = 31).
     */
    public function testChildbirthMonthWithoutIncome(): void
    {
        $person = $this->hire('Věra Porodní', 'female', '1994-05-05');
        $this->createApprovedAbsence(
            $person['employment_id'],
            'ppm',
            '2026-06-01',
            '2026-12-31',
            extra: ['expected_childbirth_date' => '2026-07-20', 'childbirth_date' => '2026-07-15'],
        );
        $this->approveMonth($person['employment_id'], []);

        $xml = $this->submission('childbirth-month');

        self::assertStringContainsString('<form:kod>1++</form:kod>', $xml);
        self::assertStringContainsString('<form:pocetDnu>31</form:pocetDnu>', $xml);
        self::assertStringContainsString(
            '<form:vylouceneDobyCelkem>14</form:vylouceneDobyCelkem><form:docasNeschopnost>0</form:docasNeschopnost>'
                . '<form:penezitaPomocMaterstvi>14</form:penezitaPomocMaterstvi>',
            $xml,
        );
        self::assertStringContainsString(
            '<form:vyloucenePar18>31</form:vyloucenePar18><form:omluvenaNepritomnost>0</form:omluvenaNepritomnost>'
                . '<form:pracovniNeschopnost>0</form:pracovniNeschopnost><form:vyplaceniDavek>31</form:vyplaceniDavek>',
            $xml,
        );
    }

    public function testChildClaimedByOtherCaregiverAndOwnSecondChild(): void
    {
        $person = $this->hire('Lucie Rodičovská', 'female', '1987-10-10');
        $first = $this->createChild($person['employee_id'], 'Syntetické Dítě Starší', '2016-02-02', 1);
        $this->claimChild($person['employee_id'], $first, 1, [
            'credit_status' => 'claimed_by_other',
            'other_claimant_excluded' => false,
            'other_household_caregiver_status' => 'present',
            'other_caregiver_given_name' => 'Syntetický',
            'other_caregiver_family_name' => 'Poplatník',
            'other_caregiver_birth_date' => '1980-01-01',
        ]);
        $second = $this->createChild($person['employee_id'], 'Syntetické Dítě Mladší', '2020-06-06', 2);
        $this->claimChild($person['employee_id'], $second, 2);
        $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
        $this->pay($person, 4_800_000);

        $xml = $this->submission('child-n');

        // Dítě uplatňované jinou osobou má pořadí „N“, vlastní dítě pořadí 2
        // a sazbu druhého dítěte (1 860 Kč v 2026).
        self::assertStringContainsString('<form:vyzivujeJinaOsoba>true</form:vyzivujeJinaOsoba>', $xml);
        self::assertStringContainsString('<form:poradi>N</form:poradi>', $xml);
        self::assertStringContainsString('<form:poradi>2</form:poradi>', $xml);
        self::assertStringContainsString('<form:slevaDite>1860</form:slevaDite>', $xml);
    }

    public function testAgreementWithWithholdingTax(): void
    {
        $person = $this->hire(
            'Marek Srážka',
            'male',
            '2001-12-24',
            employmentType: 'dpp',
            relationType: 'dpp',
            weeklyHours: 10,
            workload: 2_500,
            taxDeclarationSigned: false,
        );
        $this->approveMonth($person['employment_id'], ['2026-07-04', '2026-07-11', '2026-07-18'], dailyMinutes: 240);
        $this->pay($person, 600_000);

        $xml = $this->submission('dpp-withholding');

        // DPP do 11 500 Kč bez prohlášení: srážková daň 15 %, bez účasti na
        // pojištění, týdenní doba „99“ (pokyny MPSV k 10261).
        self::assertStringContainsString(
            '<form:zvlastniSazbaDane><form:zakladDane>6000</form:zakladDane><form:srazenaDan>900</form:srazenaDan></form:zvlastniSazbaDane>',
            $xml,
        );
        self::assertStringContainsString('<form:stanovenaTydenniDoba>99.00</form:stanovenaTydenniDoba>', $xml);
        self::assertStringContainsString('<form:eldp><form:pocetDnu>0</form:pocetDnu></form:eldp>', $xml);
    }

    /**
     * „Jeden zaměstnanec nesmí zablokovat hlášení za ostatní."
     *
     * Tři zaměstnanci, jednomu chybí schválený průměrný výdělek. Účetní ho
     * v testu hlášení odloží, řádné hlášení se dvěma formuláři projde XSD
     * i katalogem kontrol a zmrazí se. Pojistná část je dál za všechny tři;
     * nesoulad se součtem formulářů hlásí jen propustné kontroly. Po doplnění
     * průměru se vztah pošle opravným hlášením jako chybějící formulář.
     */
    public function testBlockedEmployeeIsDeferredAndCompletedByCorrection(): void
    {
        $first = $this->hire('Adam Včasný', 'male', '1980-02-02');
        $second = $this->hire('Běla Včasná', 'female', '1985-03-03');
        $late = $this->hire('Cyril Opožděný', 'male', '1990-04-04', withAverage: false);
        foreach ([$first, $second, $late] as $person) {
            $this->approveMonth($person['employment_id'], self::workdays(self::PERIOD));
            $this->pay($person, 4_000_000);
        }
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, 'scenario-deferral');
        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet mzdy. ' . CanonicalJson::encode($run['blockers']));
        self::assertSame([], $run['warnings'], 'Zaseknutí: varování běhu. ' . CanonicalJson::encode($run['warnings']));
        self::assertNotNull($run['approved']);
        $revisionId = (int) $run['approved']->revision['id'];

        $preparation = $this->prepareJmhz($revisionId, 'scenario-deferral');
        self::assertSame(201, $preparation['status'], CanonicalJson::encode($preparation['body']));
        $preparationId = (int) $preparation['body']['id'];
        $blocked = $this->dryRunJmhz($preparationId, $this->officeId);
        self::assertSame(200, $blocked['status'], CanonicalJson::encode($blocked['body']));
        self::assertSame('blocked', $blocked['body']['status']);
        foreach ($blocked['body']['blockers'] as $blocker) {
            self::assertTrue(
                $blocker['deferrable']
                    && (($blocker['entity_type'] === 'employment' && $blocker['entity_id'] === $late['employment_id'])
                        || ($blocker['entity_type'] === 'person' && $blocker['entity_id'] === $late['employee_id'])),
                'Blokovat smí jen vztah bez průměru: ' . CanonicalJson::encode($blocker),
            );
            self::assertNotSame('', $blocker['reason']);
            self::assertNotSame('support', $blocker['remediation']['kind']);
        }
        self::assertContains(
            'jmhz_average_hourly_earning_missing',
            array_column($blocked['body']['blockers'], 'code'),
        );

        $deferred = $this->deferJmhzEmployment(
            $preparationId,
            $late['employment_id'],
            $this->officeId,
            'Průměr doplní mzdová účetní, vztah se pošle opravou.',
        );
        self::assertSame(201, $deferred['status'], CanonicalJson::encode($deferred['body']));
        self::assertSame([$late['employment_id']], $deferred['body']['employment_ids']);
        $replayed = $this->deferJmhzEmployment(
            $preparationId,
            $late['employment_id'],
            $this->officeId,
            'Průměr doplní mzdová účetní, vztah se pošle opravou.',
        );
        self::assertSame(200, $replayed['status'], 'Opakované odložení je idempotentní.');

        $tested = $this->dryRunJmhz($preparationId, $this->officeId);
        self::assertSame(
            'dry_run_valid',
            $tested['body']['status'],
            'Zaseknutí: XSD nebo kontroly. ' . CanonicalJson::encode($tested['body']['controls'] ?? $tested['body']),
        );
        $xml = (string) preg_replace('/>\s+</', '><', (string) $tested['body']['xml']);
        self::assertSame(2, substr_count($xml, '</formularOsoby>'));
        self::assertSame([$late['employment_id']], $tested['body']['deferred']['employment_ids']);
        self::assertContains(12, $tested['body']['deferred']['expected_warning_control_ids']);
        self::assertSame(
            ['jmhz_average_hourly_earning_missing'],
            array_values(array_unique(array_column($tested['body']['deferred']['blockers'], 'code'))),
        );

        $frozen = $this->freezeJmhzSubmission($preparationId, $this->officeId);
        self::assertSame(201, $frozen['status'], CanonicalJson::encode($frozen['body']));
        $regularId = (int) $frozen['body']['submission_id'];
        $listed = $this->listJmhzDeferrals($revisionId);
        self::assertSame(200, $listed['status']);
        self::assertSame('omitted', $listed['body']['deferrals'][0]['state']);
        self::assertSame($regularId, $listed['body']['deferrals'][0]['regular_submission_id']);
        self::assertFalse($listed['body']['deferrals'][0]['can_revoke']);
        self::assertSame(1, $listed['body']['open_count']);

        $this->acceptJmhzSubmission($regularId, 'partially_accepted');
        $listed = $this->listJmhzDeferrals($revisionId);
        self::assertSame('to_complete', $listed['body']['deferrals'][0]['state']);
        self::assertTrue($listed['body']['deferrals'][0]['can_complete']);

        // Náprava: průměr se doplní a běh se opraví, protože průměr je
        // zmrazený ve vstupu revize.
        $this->createApprovedAverage($late['employment_id'], 3);
        $corrected = $this->correctPayrollRun(
            $run['approved']->run,
            'scenario-deferral-fix',
            'Doplněn průměrný výdělek odloženého vztahu.',
        );
        self::assertSame('approved', $corrected->run['status']);
        $completed = $this->completeJmhzDeferral((int) $listed['body']['deferrals'][0]['id']);
        self::assertSame(201, $completed['status'], CanonicalJson::encode($completed['body']));
        self::assertSame('correction', $completed['body']['submission_kind']);
        self::assertSame($regularId, $completed['body']['corrects_submission_id']);
        $frozenReader = $this->container->get(JmhzFrozenPayloadReader::class);
        self::assertInstanceOf(JmhzFrozenPayloadReader::class, $frozenReader);
        $correction = (string) preg_replace(
            '/>\s+</',
            '><',
            $frozenReader->bytes($this->supplierId, 'test', (int) $completed['body']['submission_id']),
        );
        self::assertStringContainsString('<typPodani>O</typPodani>', $correction);
        self::assertSame(1, substr_count($correction, '</formularOsoby>'));
        self::assertStringContainsString('<typFormulare>R</typFormulare>', $correction);
        self::assertStringContainsString(
            '<form:idPpv>' . self::syntheticPpv($late['sequence']) . '</form:idPpv>',
            $correction,
        );
        self::assertStringContainsString('<pvpoj:PVPOJ>', $correction);
        $listed = $this->listJmhzDeferrals($revisionId);
        self::assertSame('completing', $listed['body']['deferrals'][0]['state']);
        self::assertSame(
            (int) $completed['body']['submission_id'],
            $listed['body']['deferrals'][0]['correction_submission_id'],
        );
        // Dokud ČSSZ opravu nepřijme, povinnost trvá.
        self::assertSame(1, $listed['body']['open_count']);
    }

    /**
     * Osoba s úplnou evidencí pro JMHZ, zveřejněnými směnami na pracovní dny
     * měsíce a schváleným průměrem za 3. čtvrtletí.
     *
     * @return array{employee_id:int,employment_id:int,name:string,average_id:?int,sequence:int}
     */
    private function hire(
        string $name,
        string $sex,
        string $birthDate,
        string $employmentType = 'hpp',
        string $relationType = 'employment',
        int $weeklyHours = 40,
        int $workload = 10_000,
        bool $taxDeclarationSigned = true,
        string $taxRegime = 'advance',
        string $socialDiscountStatus = 'not_claimed',
        string $healthTopUpResponsibility = 'employer_obstacle_verified',
        bool $withAverage = true,
        bool $publishShifts = true,
    ): array {
        $sequence = ++$this->sequence;
        $person = $this->createEmployment(
            $this->officeId,
            $name,
            $sequence,
            $employmentType,
            $relationType,
            $weeklyHours,
            $workload,
            $taxDeclarationSigned,
            self::PERIOD_START,
            taxRegime: $taxRegime,
            socialDiscountStatus: $socialDiscountStatus,
            healthTopUpResponsibility: $healthTopUpResponsibility,
        );
        [$firstName, $lastName] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => $birthDate,
            'sex' => $sex,
            'birth_number' => self::syntheticBirthNumber($birthDate, $sex, $sequence),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), self::syntheticPpv($sequence));
        if ($publishShifts) {
            $this->publishShifts($person['employment_id'], self::workdays(self::PERIOD));
        }
        $average = $withAverage ? $this->createApprovedAverage($person['employment_id'], 3) : null;

        return $person + [
            'average_id' => $average === null ? null : (int) $average['id'],
            'sequence' => $sequence,
        ];
    }

    /**
     * Další vztah téže osoby (souběh).
     *
     * @param array{employee_id:int,employment_id:int,name:string} $person
     * @return array{employee_id:int,employment_id:int,name:string}
     */
    private function hireAgreement(array $person, string $relationType): array
    {
        $sequence = ++$this->sequence;
        $agreement = $this->createEmployment(
            $this->officeId,
            $person['name'],
            $sequence,
            $relationType,
            $relationType,
            10,
            2_500,
            true,
            self::PERIOD_START,
            existingEmployeeId: $person['employee_id'],
        );
        $this->completeJmhzEmployment($agreement, withIdentity: false);
        $this->assignJmhzIdentity($agreement, null, self::syntheticPpv($sequence));
        $this->createApprovedAverage($agreement['employment_id'], 3);

        return $agreement;
    }

    private static function syntheticPpv(int $sequence): string
    {
        return sprintf('2%020d', $sequence);
    }

    /** @param list<string> $workedDates */
    private function approveMonth(int $employmentId, array $workedDates, int $dailyMinutes = 480): void
    {
        $response = $this->approveTimeMonth($employmentId, self::PERIOD, $workedDates, dailyMinutes: $dailyMinutes);
        self::assertSame(
            200,
            $response->getStatusCode(),
            'Zaseknutí: schválení docházky. ' . (string) $response->getBody(),
        );
    }

    /** @param array{employee_id:int,employment_id:int,name:string} $person */
    private function pay(array $person, int $amountMinor): void
    {
        $this->createApprovedInput(
            $person,
            $this->baseComponentId,
            $amountMinor,
            'base-' . $person['employment_id'],
            self::PERIOD_START,
        );
    }

    /**
     * Běh, příprava a dry-run. Vrací XML; při zastavení selže s krokem a
     * důvodem.
     */
    private function submission(string $scenario): string
    {
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, "scenario-{$scenario}");
        self::assertSame(
            [],
            $run['blockers'],
            'Zaseknutí: výpočet mzdy. ' . CanonicalJson::encode($run['blockers'])
                . ' Zákonný výpočet: '
                . CanonicalJson::encode($run['calculated']->revision['result_snapshot']['statutory'] ?? null),
        );
        self::assertSame(
            [],
            $run['warnings'],
            'Zaseknutí: varování před schválením běhu. ' . CanonicalJson::encode($run['warnings']),
        );
        self::assertNotNull($run['approved']);
        $revisionId = (int) $run['approved']->revision['id'];

        $preparation = $this->prepareJmhz($revisionId, "scenario-{$scenario}");
        self::assertSame(201, $preparation['status'], 'Zaseknutí: příprava hlášení. ' . CanonicalJson::encode($preparation['body']));
        self::assertSame(
            'source_ready',
            $preparation['body']['readiness_status'],
            'Zaseknutí: příprava hlášení. ' . CanonicalJson::encode($preparation['body']['issues'] ?? []),
        );

        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame(200, $tested['status'], 'Zaseknutí: sestavení XML. ' . CanonicalJson::encode($tested['body']));
        $xml = (string) ($tested['body']['xml'] ?? '');
        $this->dump($scenario, $xml);
        self::assertSame(
            'dry_run_valid',
            $tested['body']['status'],
            'Zaseknutí: XSD nebo kontroly. ' . CanonicalJson::encode($tested['body']['controls'] ?? $tested['body']),
        );
        self::assertTrue(
            $tested['body']['controls']['submittable'],
            'Zaseknutí: kontroly katalogu. ' . CanonicalJson::encode($tested['body']['controls']),
        );

        return (string) preg_replace('/>\s+</', '><', $xml);
    }

    private function dump(string $scenario, string $xml): void
    {
        $directory = getenv('MYUCTO_JMHZ_SCENARIO_DUMP');
        if (!is_string($directory) || $directory === '' || $xml === '') {
            return;
        }
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($directory . DIRECTORY_SEPARATOR . "{$scenario}.xml", $xml);
    }

    private function createChild(int $employeeId, string $name, string $birthDate, int $sequence): int
    {
        $birthNumber = self::syntheticBirthNumber($birthDate, 'male', 100 + $sequence);
        [$givenName, $familyName] = explode(' ', $name, 2);
        $response = $this->dependants()->create(
            $this->request('POST', "/api/payroll/people/{$employeeId}/dependants")->withParsedBody([
                'relation' => 'child_own',
                'full_name' => $name,
                'given_name' => $givenName,
                'family_name' => $familyName,
                'birth_date' => $birthDate,
                'birth_number' => substr($birthNumber, 0, 6) . '/' . substr($birthNumber, 6),
                'ztp_p' => false,
                'student' => false,
                'existence_from' => $birthDate,
                'existence_to' => null,
                'note' => null,
            ]),
            new Response(),
            ['id' => (string) $employeeId],
        );
        self::assertSame(200, $response->getStatusCode(), 'Zaseknutí: vyživovaná osoba. ' . (string) $response->getBody());
        foreach ($this->json($response)['dependants'] as $dependant) {
            if ($dependant['full_name'] === $name) {
                return (int) $dependant['id'];
            }
        }
        self::fail("Vyživovaná osoba {$name} chybí.");
    }

    /** @param array<string,mixed> $overrides */
    private function claimChild(int $employeeId, int $dependantId, int $order, array $overrides = []): void
    {
        $response = $this->dependants()->createClaim(
            $this->request('POST', "/api/payroll/people/{$employeeId}/dependants/{$dependantId}/claims")
                ->withParsedBody($overrides + [
                    'child_order' => $order,
                    'claim_reason' => 'own_household',
                    'evidence_status' => 'verified',
                    'evidence_reference' => 'document:child-claim',
                    'shared_household_confirmed' => true,
                    'other_claimant_excluded' => true,
                    'ztp_p' => false,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                ]),
            new Response(),
            ['id' => (string) $employeeId, 'dependantId' => (string) $dependantId],
        );
        self::assertSame(200, $response->getStatusCode(), 'Zaseknutí: nárok na dítě. ' . (string) $response->getBody());
    }

    private function dependants(): PayrollDependantAction
    {
        $action = $this->container->get(PayrollDependantAction::class);
        if (!$action instanceof PayrollDependantAction) {
            throw new \RuntimeException('Evidence vyživovaných osob není dostupná.');
        }

        return $action;
    }
}
