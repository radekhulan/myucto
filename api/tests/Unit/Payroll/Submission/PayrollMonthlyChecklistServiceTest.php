<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Payroll\Deadline\PayrollDeadlineOverviewService;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpStatementService;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSubmissionService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService;
use MyInvoice\Service\Payroll\Submission\PayrollDeadlineAssessment;
use MyInvoice\Service\Payroll\Submission\PayrollDeadlineAssessmentService;
use MyInvoice\Service\Payroll\Submission\PayrollMonthlyChecklistService;
use MyInvoice\Service\Payroll\Submission\PayrollStatutoryAgendaCatalog;
use MyInvoice\Service\Submission\Channel\Isds\IsdsTransportAvailabilityResolver;
use PHPUnit\Framework\TestCase;

/**
 * Měsíční přehled je SKLADAČ nad existujícími prameny — testy proto nemluví
 * do žádné databáze, jen ověřují, že se surová data ze zdrojů (dvojníci
 * repozitářů/služeb) správně přeloží na `action.kind` a doprovodný text podle
 * pravidla „právě jedno ze tří": `send` / `generate` / `manual` s důvodem.
 */
final class PayrollMonthlyChecklistServiceTest extends TestCase
{
    private const PERIOD = '2026-07';

    public function testGeneratedJmhzWithGatewayAvailableCanBeSentDirectly(): void
    {
        $service = $this->service(
            submissionRows: [$this->submissionRow(
                agendaCode: JmhzSubmissionBridgeService::AGENDA_CODE,
                latestSubmissionStatus: 'ready',
            )],
            transport: ['automatic' => true, 'channel' => 'gateway', 'reason' => null],
        );

        $item = $this->onlyItem($service, 'submission');
        self::assertSame('send', $item['action']['kind']);
        self::assertSame('/payroll/submissions/jmhz', $item['action']['path']);
        self::assertNull($item['action']['reason']);
        self::assertFalse($item['done']);
    }

    public function testUngeneratedJmhzOffersGenerateNotSend(): void
    {
        $service = $this->service(
            submissionRows: [$this->submissionRow(
                agendaCode: JmhzSubmissionBridgeService::AGENDA_CODE,
                latestSubmissionStatus: null,
            )],
            transport: ['automatic' => true, 'channel' => 'gateway', 'reason' => null],
        );

        $item = $this->onlyItem($service, 'submission');
        self::assertSame('generate', $item['action']['kind']);
        self::assertSame('/payroll/submissions/jmhz', $item['action']['path']);
    }

    /**
     * Bez brány a bez doložené datové schránky appka slib odeslání dát
     * NEMŮŽE — položka proto musí nést jednovětý DŮVOD, ne prázdné
     * „nepodporováno".
     */
    public function testGeneratedJmhzWithoutTransportIsManualWithReason(): void
    {
        $service = $this->service(
            submissionRows: [$this->submissionRow(
                agendaCode: JmhzSubmissionBridgeService::AGENDA_CODE,
                latestSubmissionStatus: 'ready',
            )],
            transport: ['automatic' => false, 'channel' => 'manual_upload', 'reason' => 'isds_transport_unavailable'],
        );

        $item = $this->onlyItem($service, 'submission');
        self::assertSame('manual', $item['action']['kind']);
        self::assertNotNull($item['action']['reason']);
        self::assertStringContainsString('ručně', $item['action']['reason']);
    }

    /** ELDP nemá zapojený transport vůbec — appka XML jen sestaví. */
    public function testEldpIsAlwaysManualRegardlessOfTransport(): void
    {
        $service = $this->service(
            submissionRows: [$this->submissionRow(
                agendaCode: EldpStatementService::AGENDA_CODE,
                latestSubmissionStatus: 'ready',
            )],
            transport: ['automatic' => true, 'channel' => 'gateway', 'reason' => null],
        );

        $item = $this->onlyItem($service, 'submission');
        self::assertSame('manual', $item['action']['kind']);
        self::assertStringContainsString('VREP', $item['action']['reason']);
    }

    /**
     * NEMPRI/HZUPN se nesmí objevit DVAKRÁT: bohatší verzi (vázanou na
     * konkrétní případ dávky) dodává zdroj `sickness_case`.
     */
    public function testNempriObligationRowIsExcludedFromSubmissionSource(): void
    {
        $service = $this->service(
            submissionRows: [$this->submissionRow(agendaCode: 'NEMPRI', latestSubmissionStatus: 'ready')],
        );

        $result = $service->checklist(11, 'production', self::PERIOD);
        self::assertSame([], $result['items']);
    }

    public function testAccidentInsuranceLevyIsPaymentOnlyWithNoDocument(): void
    {
        $service = $this->service(deadlineItems: [$this->levyItem('statutory_insurance')]);

        $item = $this->onlyItem($service, 'levy');
        self::assertNull($item['document']['format']);
        self::assertStringContainsString('platbu', $item['document']['note']);
        self::assertSame('generate', $item['action']['kind']);
        self::assertSame('/payroll/payments', $item['action']['path']);
    }

    public function testSocialInsuranceLevyLabelDoesNotClaimAccidentInsurance(): void
    {
        $service = $this->service(deadlineItems: [$this->levyItem('social_insurance')]);

        $item = $this->onlyItem($service, 'levy');
        self::assertSame('Sociální pojištění (odvod)', $item['agenda_label']);
    }

    public function testSicknessCaseInCurrentPeriodOffersGenerate(): void
    {
        $service = $this->service(deadlineItems: [$this->sicknessItem('NEMPRI')]);

        $item = $this->onlyItem($service, 'sickness_case');
        self::assertSame('generate', $item['action']['kind']);
        self::assertSame('/payroll/submissions/sickness', $item['action']['path']);
    }

    /**
     * Legacy NEMPRI (před rokem 2025) je v {@see PayrollStatutoryAgendaCatalog}
     * `not_supported` — přehled to musí zjistit, ne slepě nabídnout tlačítko
     * generovat pro variantu, kterou appka neumí.
     */
    public function testLegacyNempriBeforeSupportedYearIsManual(): void
    {
        $service = $this->service(deadlineItems: [
            $this->sicknessItem('NEMPRI', dueOn: '2024-06-05'),
        ]);

        $result = $service->checklist(11, 'production', '2024-06');
        $item = $result['items'][0];
        self::assertSame('sickness_case', $item['source']);
        self::assertSame('manual', $item['action']['kind']);
        self::assertStringContainsString('nepodporuje', $item['action']['reason']);
    }

    public function testTaxStatementPointsToFinanceOfficeViaEpo(): void
    {
        $service = $this->service(deadlineItems: [$this->taxStatementItem()]);

        $item = $this->onlyItem($service, 'tax_statement');
        self::assertSame('Finanční úřad', $item['recipient']['label']);
        self::assertSame('generate', $item['action']['kind']);
    }

    public function testRegistrationChangeForRegzecIsManualWithSupportedFalseReason(): void
    {
        $service = $this->service(deadlineItems: [
            $this->registrationChangeItem('regzec_change'),
        ]);

        $item = $this->onlyItem($service, 'registration_change');
        self::assertSame('manual', $item['action']['kind']);
        self::assertStringContainsString('kontrolní náhled', $item['action']['reason']);
    }

    public function testHealthInsurerChangeStaysGenerateInApp(): void
    {
        $service = $this->service(deadlineItems: [
            $this->registrationChangeItem('health_insurer_change'),
        ]);

        $item = $this->onlyItem($service, 'registration_change');
        self::assertSame('generate', $item['action']['kind']);
    }

    public function testSummaryCountsEveryPendingItemExactlyOnce(): void
    {
        $service = $this->service(
            submissionRows: [$this->submissionRow(
                agendaCode: JmhzSubmissionBridgeService::AGENDA_CODE,
                latestSubmissionStatus: 'ready',
            )],
            deadlineItems: [$this->levyItem('statutory_insurance')],
            transport: ['automatic' => true, 'channel' => 'gateway', 'reason' => null],
        );

        $result = $service->checklist(11, 'production', self::PERIOD);
        self::assertSame(2, $result['summary']['total']);
        self::assertSame(1, $result['summary']['send']);
        self::assertSame(1, $result['summary']['generate']);
        self::assertSame(0, $result['summary']['manual']);
        self::assertSame(0, $result['summary']['done']);
    }

    public function testFulfilledSubmissionCountsAsDoneNotAsAction(): void
    {
        $service = $this->service(
            submissionRows: [$this->submissionRow(
                agendaCode: JmhzSubmissionBridgeService::AGENDA_CODE,
                latestSubmissionStatus: 'accepted',
                obligationStatus: 'fulfilled',
            )],
        );

        $item = $this->onlyItem($service, 'submission');
        self::assertTrue($item['done']);
        $result = $service->checklist(11, 'production', self::PERIOD);
        self::assertSame(1, $result['summary']['done']);
        self::assertSame(0, $result['summary']['send'] + $result['summary']['generate'] + $result['summary']['manual']);
    }

    public function testHealthAgendaParsesInsurerCodeFromSubjectReference(): void
    {
        $service = $this->service(
            submissionRows: [$this->submissionRow(
                agendaCode: HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW,
                latestSubmissionStatus: 'ready',
                subjectReference: 'payroll_run:8:111',
            )],
            transport: ['automatic' => false, 'channel' => 'mobile_key', 'reason' => null],
        );

        $item = $this->onlyItem($service, 'submission');
        self::assertSame('zdravotní pojišťovna 111', $item['recipient']['label']);
        self::assertSame('send', $item['action']['kind']);
    }

    /**
     * `subject_reference` je interní složený klíč (`payroll_run:8:office:4`).
     * Firma s víc účtárnami potřebuje vědět, které řádek se týká — proto se
     * u JMHZ/REGZEL rozpozná a zobrazí jako „mzdová účtárna 4", ne syrově.
     */
    public function testJmhzSubjectShowsOfficeNotRawReference(): void
    {
        $service = $this->service(
            submissionRows: [$this->submissionRow(
                agendaCode: JmhzSubmissionBridgeService::AGENDA_CODE,
                latestSubmissionStatus: 'ready',
                subjectReference: 'payroll_run:8:office:4',
            )],
        );

        $item = $this->onlyItem($service, 'submission');
        self::assertSame('mzdová účtárna 4', $item['subject']);
    }

    /**
     * Bez rozpoznaného tvaru (typicky `employment:37` u ELDP/OZUSPOJ/PREZEC/
     * REGZEC, nebo JMHZ za celou firmu bez účtárny) appka jméno osoby ani
     * účtárny nezná — radši nic než syrové interní ID.
     */
    public function testUnrecognizedSubjectReferenceIsSuppressedNotShownRaw(): void
    {
        $service = $this->service(
            submissionRows: [$this->submissionRow(
                agendaCode: EldpStatementService::AGENDA_CODE,
                latestSubmissionStatus: 'ready',
                subjectReference: 'employment:37',
            )],
        );

        $item = $this->onlyItem($service, 'submission');
        self::assertNull($item['subject']);
    }

    /**
     * Zdravotní pojišťovnu už jmenuje sloupec „Kam" — opakovat ji i jako
     * předmět by bylo zbytečné dvojení, ne doplněk.
     */
    /**
     * `PayrollObligationSubjectFormatter` je teď sdílená s inboxem a přehledem
     * podání — ani jeden z nich nemá sloupec „Kam", takže formátovač musí
     * kód pojišťovny vrátit vždy, ne ho tady zamlčet kvůli dvojení se sloupcem
     * „Kam", který mají jen tenhle přehled a checklist.
     */
    public function testHealthBulkNotificationSubjectShowsInsurerCode(): void
    {
        $service = $this->service(
            submissionRows: [$this->submissionRow(
                agendaCode: HealthInsuranceSubmissionService::AGENDA_BULK_NOTIFICATION,
                latestSubmissionStatus: 'ready',
                subjectReference: 'health_bulk_notification:2026-08:111',
            )],
            transport: ['automatic' => true, 'channel' => 'gateway', 'reason' => null],
        );

        $item = $this->onlyItem($service, 'submission');
        self::assertSame('zdravotní pojišťovna 111', $item['subject']);
    }

    /**
     * Úkon v kartě zaměstnance nikam neodchází — příjemce na něj nesedí.
     * „Netýká se" je jiná informace než „neznámo, ověřte".
     */
    public function testChecklistItemRecipientIsNotApplicableNotUnknown(): void
    {
        $service = $this->service(deadlineItems: [$this->checklistItem('tax_declaration')]);

        $item = $this->onlyItem($service, 'checklist');
        self::assertFalse($item['recipient']['applicable']);
        // Kanál naopak MÁ smysluplný text — appka ho nesmí zahodit.
        self::assertTrue($item['channel']['applicable']);
        self::assertSame('Vyřídí se v kartě zaměstnance.', $item['channel']['note']);
    }

    /**
     * REGZEC nemá žádnou odesílací cestu (appka umí jen náhled) — kanál je
     * proto „netýká se", ne „neznámo". Důvod zůstává v `action.reason`.
     */
    public function testRegzecChannelIsNotApplicable(): void
    {
        $service = $this->service(deadlineItems: [$this->registrationChangeItem('regzec_change')]);

        $item = $this->onlyItem($service, 'registration_change');
        self::assertFalse($item['channel']['applicable']);
        self::assertTrue($item['recipient']['applicable']);
    }

    /**
     * Legacy NEMPRI appka vůbec nesestaví — příjemce i kanál jsou proto
     * „netýká se", ne „neznámo, ověřte" (to by tvrdilo, že appka jen neví).
     */
    public function testLegacyNempriRecipientAndChannelAreNotApplicable(): void
    {
        $service = $this->service(deadlineItems: [
            $this->sicknessItem('NEMPRI', dueOn: '2024-06-05'),
        ]);

        $result = $service->checklist(11, 'production', '2024-06');
        $item = $result['items'][0];
        self::assertFalse($item['recipient']['applicable']);
        self::assertFalse($item['channel']['applicable']);
    }

    /**
     * Skutečně NEZNÁMÁ agenda (appka pro ni nemá ověřený popis) musí zůstat
     * „neznámo" — `applicable` tu je (a musí zůstat) `true`, jinak by přehled
     * tvrdil, že se otázka na příjemce netýká, což neví.
     */
    public function testUnknownAgendaKeepsApplicableTrue(): void
    {
        $service = $this->service(
            submissionRows: [$this->submissionRow(
                agendaCode: 'DZMH',
                latestSubmissionStatus: 'ready',
            )],
        );

        $item = $this->onlyItem($service, 'submission');
        self::assertNull($item['recipient']['label']);
        self::assertTrue($item['recipient']['applicable']);
        self::assertNull($item['channel']['label']);
        self::assertTrue($item['channel']['applicable']);
    }

    public function testInvalidPeriodIsRejected(): void
    {
        $service = $this->service();

        $this->expectException(\InvalidArgumentException::class);
        $service->checklist(11, 'production', 'not-a-period');
    }

    /**
     * @param list<array<string,mixed>> $submissionRows
     * @param list<array<string,mixed>> $deadlineItems
     * @param array{automatic:bool,channel:string,reason:?string} $transport
     */
    private function service(
        array $submissionRows = [],
        array $deadlineItems = [],
        array $transport = ['automatic' => false, 'channel' => 'manual_upload', 'reason' => 'isds_transport_unavailable'],
    ): PayrollMonthlyChecklistService {
        $submissions = $this->createStub(PayrollSubmissionRepository::class);
        $submissions->method('listOverview')->willReturn([
            'items' => $submissionRows,
            'total' => count($submissionRows),
        ]);

        $deadlines = $this->createStub(PayrollDeadlineOverviewService::class);
        $deadlines->method('itemsForWindow')->willReturn($deadlineItems);

        $assessments = $this->createStub(PayrollDeadlineAssessmentService::class);
        $assessments->method('assess')->willReturnCallback(
            static fn (string $earliest, string $due, string $status, ?string $submissionStatus): PayrollDeadlineAssessment
                => new PayrollDeadlineAssessment(
                    $status === 'fulfilled' ? 'fulfilled' : 'open',
                    5,
                    false,
                    false,
                ),
        );

        $transportAvailability = $this->createStub(IsdsTransportAvailabilityResolver::class);
        $transportAvailability->method('resolve')->willReturn($transport);

        return new PayrollMonthlyChecklistService(
            $submissions,
            $deadlines,
            $assessments,
            new PayrollStatutoryAgendaCatalog(),
            $transportAvailability,
        );
    }

    /** @return array<string,mixed> */
    private function onlyItem(PayrollMonthlyChecklistService $service, string $source): array
    {
        $items = $service->checklist(11, 'production', self::PERIOD)['items'];
        $matching = array_values(array_filter(
            $items,
            static fn (array $item): bool => $item['source'] === $source,
        ));
        self::assertCount(1, $matching, "Očekávána právě jedna položka zdroje `{$source}`.");

        return $matching[0];
    }

    /** @return array<string,mixed> */
    private function submissionRow(
        string $agendaCode,
        ?string $latestSubmissionStatus,
        string $obligationStatus = 'open',
        string $subjectReference = 'office:synthetic',
    ): array {
        return [
            'id' => 7,
            'environment' => 'production',
            'agenda_code' => $agendaCode,
            'subject_type' => 'office',
            'subject_reference' => $subjectReference,
            'period_start' => self::PERIOD . '-01',
            'period_end' => self::PERIOD . '-31',
            'obligation_kind' => 'regular',
            'preferred_channel' => 'manual_upload',
            'status' => $obligationStatus,
            'row_version' => 1,
            'agenda_group' => 'jmhz',
            'earliest_submission_on' => self::PERIOD . '-01',
            'due_on' => '2026-08-20',
            'calendar_basis' => 'calendar_days',
            'latest_submission' => $latestSubmissionStatus === null ? null : [
                'id' => 31,
                'status' => $latestSubmissionStatus,
                'submission_kind' => 'regular',
                'channel' => 'isds',
                'submitted_at' => null,
                'decided_at' => null,
            ],
        ];
    }

    /** @return array<string,mixed> */
    /** @return array<string,mixed> */
    private function checklistItem(string $itemKey): array
    {
        return [
            'source' => 'checklist',
            'reference' => 'payroll_checklist_item:1',
            'title' => $itemKey,
            'subject' => 'Cyril Syntetický',
            'period' => null,
            'due_on' => '2026-08-15',
            'phase' => 'open',
            'days_to_due' => 15,
            'is_overdue' => false,
            'employment_id' => 4,
            'employee_id' => 3,
            'checklist_phase' => 'onboarding',
            'deadline_source' => null,
            'deadline_source_status' => null,
            'path' => '/payroll/people/3',
        ];
    }

    private function levyItem(string $liabilityKind): array
    {
        return [
            'source' => 'levy',
            'reference' => 'payroll_liability:1',
            'title' => $liabilityKind,
            'subject' => 'institution:' . $liabilityKind . ':123',
            'period' => self::PERIOD,
            'due_on' => '2026-08-31',
            'phase' => 'open',
            'days_to_due' => 20,
            'is_overdue' => false,
            'remaining_minor' => 10_000,
            'run_id' => 8,
            'path' => '/payroll/payments',
        ];
    }

    /** @return array<string,mixed> */
    private function sicknessItem(string $agenda, string $dueOn = '2026-07-15'): array
    {
        return [
            'source' => 'sickness_case',
            'reference' => 'payroll_sickness_case:1',
            'title' => $agenda,
            'subject' => 'Cyril Syntetický',
            'period' => null,
            'due_on' => $dueOn,
            'phase' => 'open',
            'days_to_due' => 5,
            'is_overdue' => false,
            'case_id' => 1,
            'document_kind' => strtolower($agenda),
            'benefit_kind' => 'sickness',
            'employment_id' => 4,
            'employee_id' => 3,
            'status' => 'open',
            'deadline_source' => '§ 97',
            'deadline_source_status' => 'statute_verified',
            'deadline_ruleset_id' => 'x',
            'path' => '/payroll/submissions',
        ];
    }

    /** @return array<string,mixed> */
    private function taxStatementItem(): array
    {
        return [
            'source' => 'tax_statement',
            'reference' => 'tax_statement:dpzvd6:2025',
            'title' => 'dpzvd6',
            'subject' => '§ 38j odst. 4',
            'period' => null,
            'due_on' => '2026-03-20',
            'phase' => 'open',
            'days_to_due' => 10,
            'is_overdue' => false,
            'form_code' => 'dpzvd6',
            'statement_year' => 2025,
            'statutory_due_on' => '2026-03-02',
            'electronic_due_on' => '2026-03-20',
            'extendable' => false,
            'deadline_source' => '§ 38j',
            'deadline_source_status' => 'statute_verified',
            'deadline_ruleset_id' => 'x',
            'path' => '/payroll#payroll-tax-statement',
        ];
    }

    /** @return array<string,mixed> */
    private function registrationChangeItem(string $dutyKind): array
    {
        return [
            'source' => 'registration_change',
            'reference' => 'payroll_registration_change_proposal:1',
            'title' => $dutyKind,
            'subject' => 'Cyril Syntetický',
            'period' => null,
            'due_on' => '2026-07-18',
            'phase' => 'open',
            'days_to_due' => 3,
            'is_overdue' => false,
            'employment_id' => 4,
            'employee_id' => 3,
            'proposal_id' => 1,
            'action_code' => null,
            'detected_on' => '2026-07-10',
            'deadline_source' => 'x',
            'deadline_source_status' => 'statute_verified',
            'deadline_ruleset_id' => 'x',
            'path' => '/payroll/people/3',
        ];
    }
}
