<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollSicknessWriter;
use MyInvoice\Service\Payroll\Deadline\PayrollDeadlineOverviewService;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverAbsenceWriter;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverEmployment;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverPolicy;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverRunState;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessCaseService;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessDocumentKind;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessException;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessSubmissionService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * PRE-02: rozběhnutá neschopnost převzatá z předchozího mzdového programu.
 *
 * Převod zapisuje nepřítomnosti mimo schválení v Nepřítomnostech, takže případ
 * dávky dřív nevznikl vůbec a lhůtu HZUPN k návratu do práce (§ 97 odst. 3
 * zák. č. 187/2006 Sb.) nikdo nehlídal. Mzdy vede MyÚčto od 1. 10. 2026.
 *
 * Všechna data jsou syntetická; transakci vrací tearDown.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollTakeoverSicknessCaseTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const ENVIRONMENT = 'production';

    private int $officeId;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('PRV', 'Syntetická účtárna převodu', '9990008888');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employer_settings SET social_security_office_code = "115" WHERE supplier_id = ?',
        )->execute([$this->supplierId]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_module_state SET start_period = "2026-10-01" WHERE supplier_id = ?',
        )->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * Rozpracovaný případ z PAMICA od 17. 9.: v MyÚčtu je jeho část od 1. 10.,
     * případ dávky ale začíná skutečným dnem vzniku. Patnáctý den neschopnosti
     * je 1. 10., tedy už v době MyÚčta — NEMPRI hlídá MyÚčto, stejně jako HZUPN.
     */
    public function testPamicaSicknessAcrossStartPeriodCreatesWatchedCase(): void
    {
        $person = $this->createEmployment($this->officeId, 'Pavla Převzatá', 20, 'hpp', 'employment', 40, 10_000);
        $writer = $this->writer(PohodaPayrollSicknessWriter::class);
        $result = $this->pamicaResult('FLOW-20', '2026-09-17', '2026-10-14');

        $writer->write($this->supplierId, $this->actors[0], $result, new ImportProtocol('import'), 'payroll_sickness');
        $writer->write($this->supplierId, $this->actors[0], $result, new ImportProtocol('import'), 'payroll_sickness');

        $cases = $this->casesOf($person['employment_id']);
        self::assertCount(1, $cases, 'Opakovaný převod případ nezdvojí.');
        self::assertSame('2026-09-17', $cases[0]['incapacity_from']);
        self::assertSame('2026-10-14', $cases[0]['incapacity_to']);
        self::assertSame('predecessor', $cases[0]['source']);
        self::assertSame('pending', $cases[0]['nempri_status']);
        self::assertSame('pending', $cases[0]['hzupn_status']);
        self::assertSame(['HZUPN' => '2026-10-15', 'NEMPRI' => '2026-10-01'], $this->watched((int) $cases[0]['id']));
    }

    /**
     * Neschopnost od 3. 8.: lhůta NEMPRI začala běžet 17. 8., v době předchozího
     * programu, který ho podal. Případ ho nese jako vyřízené předchozím
     * programem, MyÚčto ho nepřipraví a hlídá jen HZUPN.
     */
    public function testPredecessorFiledNempriLeavesOnlyHzupnWatched(): void
    {
        $person = $this->createEmployment($this->officeId, 'Petra Dlouhá', 21, 'hpp', 'employment', 40, 10_000);
        $this->writer(PohodaPayrollSicknessWriter::class)->write(
            $this->supplierId,
            $this->actors[0],
            $this->pamicaResult('FLOW-21', '2026-08-03', '2026-10-14'),
            new ImportProtocol('import'),
            'payroll_sickness',
        );

        $cases = $this->casesOf($person['employment_id']);
        self::assertCount(1, $cases);
        self::assertSame('2026-08-03', $cases[0]['incapacity_from']);
        self::assertSame('predecessor', $cases[0]['nempri_status']);
        self::assertSame('pending', $cases[0]['hzupn_status']);
        self::assertSame(['HZUPN' => '2026-10-15'], $this->watched((int) $cases[0]['id']));

        try {
            $this->container->get(SicknessSubmissionService::class)
                ->preview($this->supplierId, self::ENVIRONMENT, (int) $cases[0]['id'], SicknessDocumentKind::Nempri);
            self::fail('NEMPRI podané předchozím programem MyÚčto znovu nepodává.');
        } catch (SicknessException $exception) {
            self::assertSame('sickness_document_handled_by_predecessor', $exception->validationCode);
        }
    }

    /**
     * Převod označí NEMPRI za podané předchozím programem podle lhůty, ne podle
     * toho, co předchozí program skutečně odeslal. Nepodal-li ho, musí jít
     * podání vrátit: dřív zůstalo vyřízené natrvalo, povinnost se nehlídala
     * a NEMPRI nešlo připravit. Zrušením případu to obejít nejde, nový případ
     * téže události narazí na jedinečný klíč.
     */
    public function testPredecessorMarkedNempriCanBeReturnedToMyUcto(): void
    {
        $person = $this->createEmployment($this->officeId, 'Klára Vrácená', 23, 'hpp', 'employment', 40, 10_000);
        $this->writer(PohodaPayrollSicknessWriter::class)->write(
            $this->supplierId,
            $this->actors[0],
            $this->pamicaResult('FLOW-23', '2026-08-03', '2026-10-14'),
            new ImportProtocol('import'),
            'payroll_sickness',
        );
        $caseId = (int) $this->casesOf($person['employment_id'])[0]['id'];
        $cases = $this->container->get(SicknessCaseService::class);

        $case = $cases->recordReceipt($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Nempri, 'pending', null, null);

        self::assertSame('pending', $case['nempri_status']);
        self::assertNull($case['nempri_accepted_on']);
        self::assertSame('predecessor', $case['source']);
        self::assertSame(['HZUPN' => '2026-10-15', 'NEMPRI' => '2026-08-17'], $this->watched($caseId));
        try {
            $this->container->get(SicknessSubmissionService::class)
                ->preview($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Nempri);
        } catch (SicknessException $exception) {
            self::assertNotSame('sickness_document_handled_by_predecessor', $exception->validationCode, $exception->getMessage());
        } catch (\DomainException) {
            // Syntetická osoba nemá identitu pro větu; brána předchozího
            // programu je ale už za námi.
        }

        // Vrátit jde jen podání vedené jako podané předchozím programem.
        try {
            $cases->recordReceipt($this->supplierId, self::ENVIRONMENT, $caseId, SicknessDocumentKind::Hzupn, 'pending', null, null);
            self::fail('HZUPN čeká na podání z MyÚčta, není co vracet.');
        } catch (SicknessException $exception) {
            self::assertSame('sickness_receipt_reopen_not_predecessor', $exception->validationCode);
        }
    }

    /**
     * Obecný převod (JMHZ, PREMIER, POHODA): nepřítomnost přes hranici prvního
     * měsíce vedení mezd založí případ; dřív skončená ne — tu vyřídil celou
     * předchozí program.
     */
    public function testGenericTakeoverCreatesCaseOnlyAcrossStartPeriod(): void
    {
        $person = $this->createEmployment($this->officeId, 'Jana Obecná', 22, 'hpp', 'employment', 40, 10_000);
        $employment = new PayrollTakeoverEmployment(
            personalNumber: 'FLOW-22',
            relationKey: 'FLOW-22',
            absences: [
                ['type' => 'dpn', 'from' => '2026-06-01', 'to' => '2026-06-30', 'childbirth' => null],
                ['type' => 'dpn', 'from' => '2026-09-17', 'to' => '2026-10-14', 'childbirth' => null],
            ],
            transferStart: '2026-06',
        );

        $counts = $this->writer(PayrollTakeoverAbsenceWriter::class)->absences(
            $this->supplierId,
            $person['employment_id'],
            $employment,
            $this->actors[0],
            new PayrollTakeoverPolicy('jmhz', 'JMHZ'),
            new PayrollTakeoverRunState(),
        );

        self::assertSame(1, $counts['sickness_cases'] ?? 0, json_encode($counts) ?: '');
        $cases = $this->casesOf($person['employment_id']);
        self::assertCount(1, $cases);
        self::assertSame('2026-09-17', $cases[0]['incapacity_from']);
        self::assertSame('predecessor', $cases[0]['source']);
        self::assertArrayHasKey('HZUPN', $this->watched((int) $cases[0]['id']));
    }

    /**
     * T-3: nepřítomnosti převzaté z roku, pro který MyÚčto nemá ruleset náhrad
     * (`compensation_averages` začíná rokem 2025), se dřív nezapsaly vůbec
     * (`absences_rejected`) a ELDP pak u převzatého měsíce s vyloučenými dny nemělo
     * z čeho složit rozpad § 16 odst. 4. Zapíšou se jako historická evidence:
     * schválené, bez sazby náhrady a bez průměru. Převod běží po letech, takže
     * neschopnost přes konec roku přijde ve dvou částech a druhá na první naváže.
     */
    public function testTakenOverAbsencesBeforeRulesetsAreRecordedAsHistory(): void
    {
        $person = $this->createEmployment($this->officeId, 'Hana Historická', 24, 'hpp', 'employment', 40, 10_000);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET start_date = "2020-01-01", actual_start_date = "2020-01-01" WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $person['employment_id']]);
        $writer = $this->writer(PayrollTakeoverAbsenceWriter::class);
        $policy = new PayrollTakeoverPolicy('pamica', 'PAMICA');
        $year = static fn (array $absences, string $start): PayrollTakeoverEmployment => new PayrollTakeoverEmployment(
            personalNumber: 'FLOW-24',
            relationKey: 'FLOW-24',
            absences: $absences,
            transferStart: $start,
        );

        $counts2023 = $writer->absences($this->supplierId, $person['employment_id'], $year([
            ['type' => 'dpn', 'from' => '2023-12-20', 'to' => '2023-12-31', 'childbirth' => null],
        ], '2023-01'), $this->actors[0], $policy, new PayrollTakeoverRunState());
        $counts2024 = $writer->absences($this->supplierId, $person['employment_id'], $year([
            ['type' => 'dpn', 'from' => '2024-01-01', 'to' => '2024-01-10', 'childbirth' => null],
            ['type' => 'vacation', 'from' => '2024-03-04', 'to' => '2024-03-08', 'childbirth' => null],
            ['type' => 'ocr', 'from' => '2024-05-13', 'to' => '2024-05-17', 'childbirth' => null],
        ], '2024-01'), $this->actors[0], $policy, new PayrollTakeoverRunState());

        $explain = json_encode([$counts2023, $counts2024]) ?: '';
        self::assertArrayNotHasKey('absences_rejected', $counts2023, $explain);
        self::assertArrayNotHasKey('absences_rejected', $counts2024, $explain);
        self::assertSame(1, $counts2023['absences_approved'] ?? 0, $explain);
        self::assertSame(3, $counts2024['absences_approved'] ?? 0, $explain);
        $rows = $this->absencesOf($person['employment_id']);
        self::assertSame([
            ['dpn', '2023-12-20', '2023-12-31', 'approved', null, 0],
            ['dpn', '2024-01-01', '2024-01-10', 'approved', null, 12],
            ['vacation', '2024-03-04', '2024-03-08', 'approved', 10_000, 0],
            ['ocr', '2024-05-13', '2024-05-17', 'approved', null, 0],
        ], $rows);
        self::assertSame([], $this->casesOf($person['employment_id']), 'Událost skončená před vedením mezd případ nezakládá.');

        $again = $writer->absences($this->supplierId, $person['employment_id'], $year([
            ['type' => 'dpn', 'from' => '2024-01-01', 'to' => '2024-01-10', 'childbirth' => null],
        ], '2024-01'), $this->actors[0], $policy, new PayrollTakeoverRunState());
        self::assertSame(['absences_existing' => 1], $again);
    }

    /**
     * Neschopnost převzatá z roku bez rulesetu, která pokračuje do prvního roku
     * s rulesetem a do vedení mezd v MyÚčtu: navazující část nese dny okna § 192 ZP
     * vyčerpané historickou částí a případ dávky začíná skutečným dnem vzniku.
     */
    public function testTakenOverSicknessFromYearBeforeRulesetsContinuesIntoPayroll(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_module_state SET start_period = "2025-01-01" WHERE supplier_id = ?',
        )->execute([$this->supplierId]);
        $person = $this->createEmployment($this->officeId, 'Ivo Navazující', 25, 'hpp', 'employment', 40, 10_000);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET start_date = "2020-01-01", actual_start_date = "2020-01-01" WHERE supplier_id = ? AND id = ?',
        )->execute([$this->supplierId, $person['employment_id']]);
        $writer = $this->writer(PayrollTakeoverAbsenceWriter::class);
        $policy = new PayrollTakeoverPolicy('pamica', 'PAMICA');

        foreach ([['2024-12-27', '2024-12-31', '2024-01'], ['2025-01-01', '2025-01-31', '2025-01']] as [$from, $to, $start]) {
            $counts = $writer->absences($this->supplierId, $person['employment_id'], new PayrollTakeoverEmployment(
                personalNumber: 'FLOW-25',
                relationKey: 'FLOW-25',
                absences: [['type' => 'dpn', 'from' => $from, 'to' => $to, 'childbirth' => null]],
                transferStart: $start,
            ), $this->actors[0], $policy, new PayrollTakeoverRunState());
            self::assertArrayNotHasKey('absences_rejected', $counts, json_encode($counts) ?: '');
        }

        $rows = $this->absencesOf($person['employment_id']);
        self::assertSame(['dpn', '2024-12-27', '2024-12-31', 'approved', null, 0], $rows[0]);
        self::assertSame('2025-01-01', $rows[1][1]);
        self::assertSame(5, $rows[1][5], 'Navazující část nese pět dnů okna vyčerpaných v roce 2024.');
        $cases = $this->casesOf($person['employment_id']);
        self::assertCount(1, $cases);
        self::assertSame('2024-12-27', $cases[0]['incapacity_from']);
    }

    /** @return list<array{0:string,1:string,2:string,3:string,4:?int,5:int}> */
    private function absencesOf(int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT absence_type, date_from, date_to, status, compensation_rate_basis_points, sickness_window_carried_days
               FROM payroll_absences WHERE supplier_id = ? AND employment_id = ? ORDER BY date_from',
        );
        $stmt->execute([$this->supplierId, $employmentId]);

        return array_map(static fn (array $row): array => [
            (string) $row['absence_type'],
            (string) $row['date_from'],
            (string) $row['date_to'],
            (string) $row['status'],
            $row['compensation_rate_basis_points'] === null ? null : (int) $row['compensation_rate_basis_points'],
            (int) $row['sickness_window_carried_days'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return list<array<string,mixed>> */
    private function casesOf(int $employmentId): array
    {
        return $this->container->get(SicknessCaseService::class)
            ->list($this->supplierId, self::ENVIRONMENT, $employmentId);
    }

    /** @return array<string,string> agenda => termín */
    private function watched(int $caseId): array
    {
        $due = [];
        $overview = $this->container->get(PayrollDeadlineOverviewService::class)
            ->overview($this->supplierId, self::ENVIRONMENT, 400);
        foreach ($overview['items'] as $item) {
            if (($item['case_id'] ?? null) === $caseId) {
                $due[$item['title']] = $item['due_on'];
            }
        }
        ksort($due);

        return $due;
    }

    /** @return array<string,mixed> */
    private function pamicaResult(string $personalNumber, string $from, string $to): array
    {
        return [
            'start_period' => '2026-10',
            'cases' => [[
                'in_progress' => true,
                'personal_number' => $personalNumber,
                'person_key' => $personalNumber,
                'type' => 'dpn',
                'date_from' => $from,
                'date_to' => $to,
                'childbirth' => null,
                'compensation_minor' => null,
                'compensation_source' => null,
            ]],
            'wage_compensation_rows' => 0,
            'benefit_rows' => 0,
            'benefit_claims' => 0,
            'unclassified' => [],
        ];
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function writer(string $class): object
    {
        $service = $this->container->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
