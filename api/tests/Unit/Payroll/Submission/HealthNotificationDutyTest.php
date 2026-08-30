<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationCodeCatalog;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationCodeGroup;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationDutyCatalog;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationDutyKind;
use MyInvoice\Service\Payroll\Deadline\PayrollLevyDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationDutyResolver;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationDutyRule;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationException;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationFacts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HealthNotificationDutyTest extends TestCase
{
    private HealthNotificationDutyCatalog $duties;
    private HealthNotificationCodeCatalog $codes;
    private HealthNotificationDeadlinePolicy $deadlines;
    private HealthNotificationDutyResolver $resolver;

    protected function setUp(): void
    {
        $this->duties = new HealthNotificationDutyCatalog();
        $this->codes = new HealthNotificationCodeCatalog();
        $this->deadlines = new HealthNotificationDeadlinePolicy();
        $this->resolver = new HealthNotificationDutyResolver(
            $this->duties,
            $this->deadlines,
        );
    }

    public function testEveryDutyRuleCitesItsLegalBasis(): void
    {
        foreach ($this->duties->rules() as $rule) {
            self::assertNotSame('', $rule->act, $rule->kind->value);
            self::assertNotSame('', $rule->source(), $rule->kind->value);
            self::assertNotSame('', $rule->note, $rule->kind->value);
            self::assertContains(
                $rule->sourceStatus,
                [
                    HealthNotificationDutyRule::STATUTE_VERIFIED,
                    HealthNotificationDutyRule::EXTERNAL_UNVERIFIED,
                ],
                $rule->kind->value,
            );
            self::assertSame(
                HealthNotificationDutyCatalog::VERIFIED_ON,
                $rule->verifiedOn,
                $rule->kind->value,
            );
        }
    }

    public function testEveryDutyKindHasARuleEffectiveTodayAndBeforeTheNarrowing(): void
    {
        foreach (HealthNotificationDutyKind::cases() as $kind) {
            self::assertInstanceOf(
                HealthNotificationDutyRule::class,
                $this->duties->ruleFor($kind, '2025-06-30'),
                $kind->value,
            );
            self::assertInstanceOf(
                HealthNotificationDutyRule::class,
                $this->duties->ruleFor($kind, '2026-06-30'),
                $kind->value,
            );
        }
    }

    /**
     * Jádro zúžení od 1. 1. 2026: zaměstnavatel hlásí ze skupiny „plátcem je
     * stát" už jen mateřskou a rodičovskou, ostatní hlásí sám pojištěnec.
     */
    #[DataProvider('narrowingBoundary')]
    public function testStateCategoryNarrowingTakesEffectOnTheFirstOf2026(
        HealthNotificationDutyKind $kind,
        string $onDate,
        bool $expected,
    ): void {
        self::assertSame(
            $expected,
            $this->duties->employerReports($kind, $onDate),
        );
    }

    /** @return iterable<string,array{0:HealthNotificationDutyKind,1:string,2:bool}> */
    public static function narrowingBoundary(): iterable
    {
        $other = HealthNotificationDutyKind::StateCategoryOther;
        yield 'ostatní kategorie, poslední den staré úpravy' =>
            [$other, '2025-12-31', true];
        yield 'ostatní kategorie, první den zúžení' =>
            [$other, '2026-01-01', false];
        yield 'ostatní kategorie, dávno po zúžení' =>
            [$other, '2026-08-15', false];

        $maternity = HealthNotificationDutyKind::MaternityLeaveStart;
        yield 'mateřská před zúžením' => [$maternity, '2025-12-31', true];
        yield 'mateřská v den zúžení' => [$maternity, '2026-01-01', true];
        yield 'mateřská po zúžení' => [$maternity, '2026-08-15', true];

        $parental = HealthNotificationDutyKind::ParentalLeaveStart;
        yield 'rodičovská po zúžení' => [$parental, '2026-08-15', true];

        $end = HealthNotificationDutyKind::MaternityOrParentalLeaveEnd;
        yield 'ukončení mateřské po zúžení' => [$end, '2026-08-15', true];

        $start = HealthNotificationDutyKind::EmploymentStart;
        yield 'nástup se zúžením nemá co do činění' =>
            [$start, '2026-08-15', true];
    }

    public function testNarrowedRuleAdmitsThatItsSourceIsNotTheStatuteText(): void
    {
        $rule = $this->duties->ruleFor(
            HealthNotificationDutyKind::StateCategoryOther,
            '2026-08-15',
        );
        self::assertFalse($rule->employerReports);
        self::assertNull($rule->section);
        self::assertFalse($rule->isStatutory());
        self::assertSame(
            HealthNotificationDutyRule::EXTERNAL_UNVERIFIED,
            $rule->sourceStatus,
        );
    }

    public function testUnknownDateHasNoRuleAndSaysSo(): void
    {
        $this->expectException(HealthNotificationException::class);
        $this->duties->ruleFor(
            HealthNotificationDutyKind::EmploymentStart,
            '1990-01-01',
        );
    }

    // --- kódy změny -----------------------------------------------------

    public function testCatalogHoldsExactlyTheTwentyFiveDocumentedCodes(): void
    {
        $codes = $this->codes->codes();
        self::assertCount(25, $codes);
        self::assertSame($codes, array_unique($codes));
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/^[A-Z]$/', $code);
        }
    }

    /**
     * Přesně tahle kontrola v XSD chybí: schéma propustí i kódy, které
     * zaměstnavatel od 1. 1. 2026 podat nesmí.
     */
    #[DataProvider('codeReportability')]
    public function testCodeReportabilityFollowsTheNarrowingNotTheSchema(
        string $code,
        string $onDate,
        bool $expected,
    ): void {
        self::assertSame(
            $expected,
            $this->codes->isReportableByEmployer($code, $onDate),
        );
    }

    /** @return iterable<string,array{0:string,1:string,2:bool}> */
    public static function codeReportability(): iterable
    {
        yield 'zaměstnanecký kód po zúžení' => ['P', '2026-08-15', true];
        yield 'oprava po zúžení' => ['X', '2026-08-15', true];
        yield 'mateřská M po zúžení' => ['M', '2026-08-15', true];
        yield 'rodičovská U po zúžení' => ['U', '2026-08-15', true];
        yield 'důchod D poslední den staré úpravy' => ['D', '2025-12-31', true];
        yield 'důchod D první den zúžení' => ['D', '2026-01-01', false];
        yield 'uchazeč o zaměstnání po zúžení' => ['H', '2026-08-15', false];
        yield 'kategorie V po zúžení' => ['V', '2026-08-15', false];
    }

    public function testEveryStateCategoryCodeExceptMaternityIsRefusedFrom2026(): void
    {
        $refused = [];
        foreach ($this->codes->codes() as $code) {
            if ($this->codes->group($code)
                !== HealthNotificationCodeGroup::StateCategory
            ) {
                continue;
            }
            if (!$this->codes->isReportableByEmployer($code, '2026-01-01')) {
                $refused[] = $code;
            }
        }
        self::assertCount(14, $refused);
        self::assertNotContains('M', $refused);
        self::assertNotContains('U', $refused);
    }

    public function testRefusedCodeFailsWithANamedReason(): void
    {
        try {
            $this->codes->assertReportableByEmployer('D', '2026-01-01');
            self::fail('Kód D po zúžení projít nesmí.');
        } catch (HealthNotificationException $e) {
            self::assertSame(
                'zp_change_code_not_reported_by_employer',
                $e->errorCode,
            );
        }
    }

    public function testUnknownCodeIsRefusedLoudly(): void
    {
        try {
            $this->codes->assertReportableByEmployer('B', '2026-01-01');
            self::fail('Kód B v datové větě není.');
        } catch (HealthNotificationException $e) {
            self::assertSame('zp_change_code_unknown', $e->errorCode);
        }
    }

    /**
     * Význam písmen dokládá anotace `kodZmenyZamestnaceTyp` v připnutém HOZ
     * XSD, takže tyhle druhy povinnosti kód doloženě mají. Kód „M" nese
     * podle schématu mateřskou i rodičovskou dovolenou.
     */
    public function testCodeForDutyKindFollowsThePinnedSchemaAnnotation(): void
    {
        foreach ([
            HealthNotificationDutyKind::EmploymentStart->value => 'P',
            HealthNotificationDutyKind::EmploymentEnd->value => 'O',
            HealthNotificationDutyKind::MaternityLeaveStart->value => 'M',
            HealthNotificationDutyKind::ParentalLeaveStart->value => 'M',
            HealthNotificationDutyKind::MaternityOrParentalLeaveEnd->value => 'U',
        ] as $kindValue => $expected) {
            $kind = HealthNotificationDutyKind::from($kindValue);
            self::assertTrue($this->codes->isCodeMappingDocumented($kind));
            self::assertSame($expected, $this->codes->codeFor($kind));
            self::assertTrue($this->codes->isKnown($expected));
        }
    }

    /**
     * Kde ani schéma jediný kód neurčuje (opravy podle položky, přestup podle
     * směru, skutečnosti, které zaměstnavatel nehlásí), zůstává fail-closed.
     */
    public function testCodeForDutyKindStaysFailClosedWhereTheSchemaIsAmbiguous(): void
    {
        foreach ([
            HealthNotificationDutyKind::EmployeeDataChange,
            HealthNotificationDutyKind::InsurerChange,
            HealthNotificationDutyKind::StateCategoryOther,
        ] as $kind) {
            self::assertFalse($this->codes->isCodeMappingDocumented($kind));
            try {
                $this->codes->codeFor($kind);
                self::fail('Kód se nesmí odhadnout: ' . $kind->value);
            } catch (HealthNotificationException $e) {
                self::assertSame(
                    'zp_change_code_mapping_undocumented',
                    $e->errorCode,
                );
                self::assertStringContainsString($kind->value, $e->getMessage());
            }
        }
    }

    // --- lhůty ----------------------------------------------------------

    public function testNotificationDeadlineIsEightCalendarDays(): void
    {
        $window = $this->deadlines->forNotification(
            HealthNotificationDutyKind::EmploymentStart,
            '2026-03-02',
            'employment',
        );
        self::assertSame('2026-03-02', $window->earliestSubmissionOn);
        self::assertSame('2026-03-10', $window->dueOn);
        self::assertSame('calendar_days', $window->calendarBasis);
        self::assertStringContainsString('§ 10', $window->source);
    }

    public function testAgreementsGetTheTwentiethOfTheFollowingMonth(): void
    {
        foreach (['dpp', 'dpc'] as $relationType) {
            $window = $this->deadlines->forNotification(
                HealthNotificationDutyKind::EmploymentStart,
                '2026-03-02',
                $relationType,
            );
            self::assertSame('2026-04-20', $window->dueOn, $relationType);
            self::assertSame('external_unverified', $window->sourceStatus);
        }
    }

    public function testMaternityIsReportedMonthlyRegardlessOfRelationType(): void
    {
        $window = $this->deadlines->forNotification(
            HealthNotificationDutyKind::MaternityLeaveStart,
            '2026-12-28',
            'employment',
        );
        self::assertSame('2027-01-20', $window->dueOn);
    }

    /**
     * Opravný přehled běží od ZJIŠTĚNÍ chyby, ne od mzdového období.
     *
     * § 25 odst. 4 z. 592/1992 Sb. (nový od 1. 1. 2026). Odvozovat ho od
     * období by nešlo: chyba se zpravidla najde až měsíce po řádném termínu
     * a lhůta by vycházela do minulosti.
     */
    public function testCorrectivePaymentOverviewRunsFromTheDayTheErrorWasFound(): void
    {
        $window = $this->deadlines->forCorrectivePaymentOverview('2026-09-11');

        self::assertSame('2026-09-11', $window->earliestSubmissionOn);
        self::assertSame('2026-09-19', $window->statutoryDueOn);
        self::assertStringContainsString('§ 25 odst. 4', $window->source);
    }

    /** Posouvá se na pracovní den stejně jako řádný přehled — táž povinnost. */
    public function testCorrectivePaymentOverviewShiftsToWorkingDay(): void
    {
        // 29. 8. 2026 + 8 dní = neděle 6. 9. → pondělí 7. 9.
        $window = $this->deadlines->forCorrectivePaymentOverview('2026-08-29');

        self::assertSame('2026-09-06', $window->statutoryDueOn);
        self::assertSame('2026-09-07', $window->dueOn);
        self::assertTrue($window->isShifted());
    }

    /**
     * Sada pravidel se přidáním opravné lhůty změnila. Uložené termíny nesou
     * hash a musí být poznat, podle čeho vznikly.
     */
    public function testRulesetHashCoversTheCorrectiveDeadline(): void
    {
        self::assertNotSame(
            hash('sha256', 'cz-health-insurance-notification-deadlines.v1|8|20'
                . '|payment_overview_shift=next_czech_working_day'),
            $this->deadlines->rulesetHash(),
        );
    }

    /**
     * 20. den následujícího měsíce u DPP/DPČ NESMÍ být vlastní literál —
     * musí to být TÝŽ den, který pro odvod zdravotního pojištění nese
     * {@see PayrollLevyDeadlinePolicy}. Test staví politiku s vlastní
     * instancí přes konstruktor, aby dedup ověřil skutečné čtení, ne shodu
     * dvou nezávislých čísel.
     */
    public function testAgreementDueDayComesFromThePayrollLevyDeadlinePolicy(): void
    {
        $levyDeadlines = new PayrollLevyDeadlinePolicy();
        $deadlines = new \MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationDeadlinePolicy(
            $levyDeadlines,
        );

        self::assertSame(
            $levyDeadlines->dueDayOfMonth(PayrollLevyDeadlinePolicy::HEALTH_INSURANCE),
            20,
        );

        $window = $deadlines->forNotification(
            HealthNotificationDutyKind::EmploymentStart,
            '2026-03-02',
            'dpp',
        );
        self::assertSame('2026-04-20', $window->dueOn);
    }

    public function testPaymentOverviewIsDueOnTheTwentiethOfTheFollowingMonth(): void
    {
        $window = $this->deadlines->forPaymentOverview('2026-01');
        self::assertSame('2026-01-31', $window->earliestSubmissionOn);
        self::assertSame('2026-02-20', $window->dueOn);
        self::assertStringContainsString('§ 25 odst. 3', $window->source);
    }

    /**
     * W30 / C-04 — přehled o platbě se posouvá na pracovní den stejně jako
     * odvod pojistného.
     *
     * Obojí plyne ze zákona č. 592/1992 Sb. (§ 25 odst. 3 a § 5 odst. 2) a je
     * to lhůta v řízení podle správního řádu, tedy § 40 odst. 1 písm. c)
     * zákona č. 500/2004 Sb. Odvod se posouval odjakživa, přehled ne, a
     * aplikace proto 3–4× ročně hlásila „po termínu" u podání, které po
     * termínu nebylo. 20. 6. 2026 je sobota → pondělí 22. 6. 2026.
     */
    public function testPaymentOverviewDueDateShiftsToWorkingDay(): void
    {
        $window = $this->deadlines->forPaymentOverview('2026-05');

        self::assertSame('2026-06-22', $window->dueOn);
        self::assertSame('2026-06-20', $window->statutoryDueOn);
        self::assertTrue($window->isShifted());
        self::assertStringContainsString(
            '§ 40 odst. 1 písm. c)',
            (string) $window->shiftSource,
        );
    }

    /**
     * Osmidenní lhůta hromadného oznámení se ZÁMĚRNĚ neposouvá — plyne z jiného
     * zákona (§ 10 zákona č. 48/1997 Sb.) a pramen k posunu v repozitáři není.
     * Dřívější podání je bezpečné, pozdější může znamenat penále.
     */
    public function testNotificationDeadlineIsNotShifted(): void
    {
        $window = $this->deadlines->forNotification(
            HealthNotificationDutyKind::EmploymentStart,
            '2026-06-06',
            'employment',
        );

        self::assertSame('2026-06-14', $window->dueOn);
        self::assertFalse(
            $window->isShifted(),
            '14. 6. 2026 je neděle a lhůta se přesto posouvat nesmí.',
        );
    }

    public function testInvalidPeriodIsRefused(): void
    {
        $this->expectException(HealthNotificationException::class);
        $this->deadlines->forPaymentOverview('2026-13');
    }

    // --- odvození povinnosti --------------------------------------------

    public function testRelationWithoutParticipationProducesNoDuty(): void
    {
        $duties = $this->resolver->resolve(new HealthNotificationFacts(
            employmentId: 7,
            employeeId: 3,
            relationType: 'dpp',
            participates: false,
            insurerCode: '111',
            startedOn: '2026-03-01',
        ));
        self::assertSame([], $duties);
    }

    public function testEmploymentStartAndEndBecomeDutiesInChronologicalOrder(): void
    {
        $duties = $this->resolver->resolve(new HealthNotificationFacts(
            employmentId: 7,
            employeeId: 3,
            relationType: 'employment',
            participates: true,
            insurerCode: '111',
            startedOn: '2026-03-01',
            endedOn: '2026-06-30',
        ));
        self::assertCount(2, $duties);
        self::assertSame(
            HealthNotificationDutyKind::EmploymentStart,
            $duties[0]->kind,
        );
        self::assertSame('2026-03-09', $duties[0]->deadline?->dueOn);
        self::assertSame(
            HealthNotificationDutyKind::EmploymentEnd,
            $duties[1]->kind,
        );
        self::assertSame('2026-07-08', $duties[1]->deadline?->dueOn);
    }

    /**
     * Povinnost, kterou zaměstnavatel od 2026 nemá, se nesmí zahodit — jinak
     * nikdo neumí říct, proč se skutečnost nepodává.
     */
    public function testDutyThatIsNoLongerEmployersIsReturnedWithoutADeadline(): void
    {
        $duties = $this->resolver->resolve(new HealthNotificationFacts(
            employmentId: 7,
            employeeId: 3,
            relationType: 'employment',
            participates: true,
            insurerCode: '111',
            otherStateCategoryOccurredOn: '2026-04-10',
        ));
        self::assertCount(1, $duties);
        self::assertFalse($duties[0]->reportedByEmployer);
        self::assertNull($duties[0]->deadline);
        self::assertNotSame('', $duties[0]->rule->note);
    }

    public function testSameFactBefore2026StillBelongsToTheEmployer(): void
    {
        $duties = $this->resolver->resolve(new HealthNotificationFacts(
            employmentId: 7,
            employeeId: 3,
            relationType: 'employment',
            participates: true,
            insurerCode: '111',
            otherStateCategoryOccurredOn: '2025-12-31',
        ));
        self::assertTrue($duties[0]->reportedByEmployer);
        self::assertSame('2026-01-08', $duties[0]->deadline?->dueOn);
    }

    public function testInsurerChangeIsReportedToBothInsurers(): void
    {
        $duties = $this->resolver->resolve(new HealthNotificationFacts(
            employmentId: 7,
            employeeId: 3,
            relationType: 'employment',
            participates: true,
            insurerCode: '207',
            previousInsurerCode: '111',
            insurerChangedOn: '2026-07-01',
        ));
        self::assertCount(2, $duties);
        self::assertSame(
            ['111', '207'],
            array_map(
                static fn ($duty): string => $duty->insurerCode,
                $duties,
            ),
        );
    }

    public function testMissingInsurerIsRefusedWithAnActionableReason(): void
    {
        try {
            $this->resolver->resolve(new HealthNotificationFacts(
                employmentId: 7,
                employeeId: 3,
                relationType: 'employment',
                participates: true,
                insurerCode: null,
                startedOn: '2026-03-01',
            ));
            self::fail('Bez pojišťovny nemá oznámení komu odejít.');
        } catch (HealthNotificationException $e) {
            self::assertSame('zp_insurer_code_missing', $e->errorCode);
        }
    }

    public function testDutyCarriesStableReferencesForTheObligationRegistry(): void
    {
        $duties = $this->resolver->resolve(new HealthNotificationFacts(
            employmentId: 7,
            employeeId: 3,
            relationType: 'employment',
            participates: true,
            insurerCode: '111',
            startedOn: '2026-03-01',
        ));
        self::assertSame('employment:7', $duties[0]->subjectReference());
        self::assertSame(
            'payroll_health_notification:7:employment_start:2026-03-01',
            $duties[0]->sourceEventReference(),
        );
        self::assertMatchesRegularExpression(
            '/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,95}$/',
            $duties[0]->subjectReference(),
        );
    }
}
