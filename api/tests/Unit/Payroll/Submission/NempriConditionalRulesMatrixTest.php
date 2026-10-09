<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use DOMDocument;
use DOMXPath;
use MyInvoice\Service\Payroll\Cssz\CsszSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Sickness\HzupnXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriBenefitApplication;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriDecisiveMonth;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriDecisivePeriod;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriPaymentConnection;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriPerson;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriXmlPayload;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessBenefitKind;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessException;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessXmlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Podmíněné prvky, logické kontroly a období „od-do" DV NEMPRI25, které XSD
 * nevyjádří. Každé porušení musí validace odmítnout, nebo se zakázaný prvek
 * nesmí dostat do věty; výchozí správná věta projde. Data jsou syntetická.
 */
final class NempriConditionalRulesMatrixTest extends TestCase
{
    /**
     * Porušení => [druh dávky, úprava žádosti, úprava věty, kód odmítnutí].
     *
     * @return iterable<string,array{string,array<string,mixed>,array<string,mixed>,string}>
     */
    public static function rejected(): iterable
    {
        foreach (['nem', 'ose', 'dlo'] as $kind) {
            yield "{$kind} volno bez náhrady bez data od" => [$kind, [], ['unpaidLeave' => true, 'unpaidLeaveFrom' => null, 'unpaidLeaveTo' => '2026-08-20'], 'nempri_unpaid_leave_period_missing'];
            yield "{$kind} volno bez náhrady od po do" => [$kind, [], ['unpaidLeave' => true, 'unpaidLeaveFrom' => '2026-08-20', 'unpaidLeaveTo' => '2026-08-10'], 'nempri_unpaid_leave_period_invalid'];
            yield "{$kind} rozhodné období do před od" => [$kind, [], ['decisivePeriod' => new NempriDecisivePeriod('2026-08-31', '2025-09-01', self::months('2025-09', 12), true)], 'nempri_decisive_period_incomplete'];
            yield "{$kind} bez názvu zaměstnavatele" => [$kind, [], ['employerName' => ''], 'nempri_employer_name_missing'];
        }
        foreach (['ose', 'dlo'] as $kind) {
            yield "{$kind} ošetřovaná osoba bez RČ i data narození" => [$kind, ['person' => new NempriPerson('Osoba', 'Testovací', null, null)], [], 'nempri_person_identifier_missing'];
            yield "{$kind} ošetřovaná osoba s RČ mimo modulo 11" => [$kind, ['person' => new NempriPerson('Osoba', 'Testovací', '1501010008', null)], [], 'nempri_person_birth_number_invalid'];
            yield "{$kind} ošetřovaná osoba s RČ s měsícem 13" => [$kind, ['person' => new NempriPerson('Osoba', 'Testovací', '1513010004', null)], [], 'nempri_person_birth_number_invalid'];
            yield "{$kind} pečoval ve dnech od po do" => [$kind, ['careDays' => [['from' => '2026-09-11', 'to' => '2026-09-07']], 'caredPersonally' => false, 'actionStart' => false, 'actionEnd' => true], [], 'nempri_period_invalid'];
            yield "{$kind} práce ve dnech od po do" => [$kind, ['workDays' => [['from' => '2026-09-14', 'to' => '2026-09-10']], 'actionStart' => false, 'actionEnd' => true], [], 'nempri_period_invalid'];
        }
        yield 'dlo pracovní volno od po do' => ['dlo', ['actionStart' => false, 'actionEnd' => true, 'leavePeriods' => [['from' => '2026-09-04', 'to' => '2026-09-02']]], [], 'nempri_period_invalid'];
        yield 'dlo rozvrh směn od po do' => ['dlo', ['actionStart' => false, 'actionEnd' => true, 'shiftSchedule' => [['from' => '2026-09-11', 'to' => '2026-09-07']]], [], 'nempri_period_invalid'];
        yield 'dlo bez akce' => ['dlo', ['actionStart' => false, 'actionContinuation' => false, 'actionEnd' => false], [], 'nempri_care_action_missing'];
        yield 'dlo bez čísla rozhodnutí' => ['dlo', [], ['decisionNumber' => null], 'nempri_decision_number_missing'];
    }

    /**
     * @param array<string,mixed> $application
     * @param array<string,mixed> $payload
     */
    #[DataProvider('rejected')]
    public function testViolationIsRejected(string $kind, array $application, array $payload, string $code): void
    {
        $validator = self::validator();
        $base = self::payload($kind, [], []);
        $validator->validateNempri($base, (new NempriXmlSerializer())->serialize($base));

        $broken = self::payload($kind, $application, $payload);
        try {
            $xml = (new NempriXmlSerializer())->serialize($broken);
            $validator->validateNempri($broken, $xml);
            self::fail('Věta s porušením prošla validací.');
        } catch (SicknessException $exception) {
            self::assertSame($code, $exception->validationCode, $exception->getMessage());
        }
    }

    /**
     * Zakázaný prvek (podmínka nesplněna) => [druh, úprava žádosti, úprava věty, prvek].
     *
     * @return iterable<string,array{string,array<string,mixed>,array<string,mixed>,string}>
     */
    public static function forbiddenElements(): iterable
    {
        foreach (['nem', 'ose', 'dlo'] as $kind) {
            yield "{$kind} datum převedení bez převedení" => [$kind, [], ['transferredOtherWork' => false, 'transferredOn' => '2026-08-01'], 'datumNaJinouPraci'];
        }
        yield 'dlo vztah mimo akci vznik' => ['dlo', ['actionStart' => false, 'actionEnd' => true], [], 'kodVztah'];
        yield 'dlo rozvrh směn bez plánovaných směn' => ['dlo', ['actionStart' => false, 'actionEnd' => true, 'plannedShifts' => false], [], 'seznamRozvrhuSmen'];
        yield 'dlo § 57 bez nároku jiné osoby' => ['dlo', ['otherMaternityClaim' => false, 'otherPersonS57' => true], [], 'jinaFOParagraf57'];
    }

    /**
     * @param array<string,mixed> $application
     * @param array<string,mixed> $payload
     */
    #[DataProvider('forbiddenElements')]
    public function testForbiddenElementIsNotSent(string $kind, array $application, array $payload, string $element): void
    {
        $broken = self::payload($kind, $application, $payload);
        try {
            $xml = (new NempriXmlSerializer())->serialize($broken);
            self::validator()->validateNempri($broken, $xml);
        } catch (SicknessException) {
            self::assertTrue(true);

            return;
        }
        $document = new DOMDocument();
        self::assertTrue($document->loadXML($xml));
        $nodes = (new DOMXPath($document))->query("//*[local-name()='{$element}']");
        self::assertSame(0, $nodes === false ? 0 : $nodes->length, "{$element} nesmí být ve větě");
    }

    private static function validator(): SicknessXmlValidator
    {
        return new SicknessXmlValidator(new CsszSchemaCatalog(), new NempriXmlSerializer(), new HzupnXmlSerializer());
    }

    /**
     * @param array<string,mixed> $applicationOverrides
     * @param array<string,mixed> $overrides
     */
    private static function payload(string $kind, array $applicationOverrides, array $overrides): NempriXmlPayload
    {
        $benefit = match ($kind) {
            'nem' => SicknessBenefitKind::Nem,
            'ose' => SicknessBenefitKind::Ose,
            default => SicknessBenefitKind::Dlo,
        };
        $application = match ($kind) {
            'nem' => null,
            'ose' => self::care($applicationOverrides),
            default => self::dlo($applicationOverrides),
        };
        $starts = !$benefit->hasActions() || ($application?->actionStart ?? true);
        $values = [
            'benefitKind' => $benefit,
            'osszCode' => 115,
            'correction' => false,
            'decisionNumber' => match ($benefit) {
                SicknessBenefitKind::Nem => 'A1234567',
                SicknessBenefitKind::Ose => '1234567N',
                default => '1234567L',
            },
            'foreignCase' => false,
            'insuredFirstName' => 'Jan',
            'insuredLastName' => 'Testovací',
            'insuredBirthNumber' => '8001010008',
            'insuredPhone' => null,
            'insuredEmail' => null,
            'employerVariableSymbol' => '1234567890',
            'employerIdentificationNumber' => '12345678',
            'employerName' => 'Testovací zaměstnavatel s.r.o.',
            'employmentFrom' => '2020-01-01',
            'employmentTo' => null,
            'activityCode' => '1',
            'workedOnDecisiveDay' => false,
            'hoursWorked' => null,
            'dailyWorkingHours' => null,
            'smallScopeIncomeMinor' => null,
            'receivesPension' => false,
            'pensionKind' => null,
            'isStudent' => false,
            'withinSchoolHolidays' => null,
            'firstEmploymentFreeTime' => false,
            'unpaidLeave' => false,
            'unpaidLeaveFrom' => null,
            'unpaidLeaveTo' => null,
            'startsMaternity' => null,
            'childBirthDate' => null,
            'transferredOtherWork' => false,
            'transferredOn' => null,
            'enforcement' => false,
            'insolvency' => false,
            'additionalNote' => null,
            'productName' => 'MyUcto',
            'productVersion' => '1.0',
            'payloadVersion' => '1.0',
            'application' => $application,
            'decisivePeriod' => new NempriDecisivePeriod('2025-09-01', '2026-08-31', self::months('2025-09', 12), true),
            'paymentConnection' => $starts
                ? new NempriPaymentConnection(NempriPaymentConnection::KIND_ACCOUNT_CZ, accountPrefix: '19', accountNumber: '1000000005', bankCode: '0100')
                : null,
        ];

        return new NempriXmlPayload(...[...$values, ...$overrides]);
    }

    /** @param array<string,mixed> $overrides */
    private static function care(array $overrides): NempriBenefitApplication
    {
        return new NempriBenefitApplication(...[...[
            'actionStart' => true,
            'actionContinuation' => false,
            'actionEnd' => false,
            'fromDate' => '2026-09-07',
            'toDate' => '2026-09-11',
            'person' => new NempriPerson('Dítě', 'Testovací', '1501010005', null),
            'careReason' => NempriBenefitApplication::CARE_REASON_ILL,
            'sharedHousehold' => true,
            'loneCaregiver' => false,
            'childUnder16' => true,
            'otherMaternityClaim' => false,
            'caredPersonally' => true,
            'careDays' => [['from' => '2026-09-07', 'to' => '2026-09-11']],
            'relationshipCode' => 'PL',
            'workedLastDay' => false,
            'plannedShifts' => true,
            'plannedShiftsWorked' => false,
            'workDays' => [['from' => '2026-09-08', 'to' => '2026-09-08']],
        ], ...$overrides]);
    }

    /** @param array<string,mixed> $overrides */
    private static function dlo(array $overrides): NempriBenefitApplication
    {
        return new NempriBenefitApplication(...[...[
            'actionStart' => true,
            'actionEnd' => false,
            'fromDate' => '2026-09-01',
            'toDate' => '2026-09-30',
            'person' => new NempriPerson('Osoba', 'Ošetřovaná', null, '1950-01-01'),
            'sharedHousehold' => true,
            'otherMaternityClaim' => false,
            'caredPersonally' => true,
            'careDays' => [['from' => '2026-09-01', 'to' => '2026-09-30']],
            'relationshipCode' => '3',
            'alternation' => false,
            'shiftHoursLastDay' => '8',
            'hoursWorkedLastDay' => '0',
            'plannedShifts' => true,
            'returnedOn' => '2026-10-01',
            'workDays' => [['from' => '2026-09-14', 'to' => '2026-09-14']],
            'hasLeave' => true,
            'leavePeriods' => [['from' => '2026-09-02', 'to' => '2026-09-04']],
            'shiftSchedule' => [['from' => '2026-09-07', 'to' => '2026-09-11']],
        ], ...$overrides]);
    }

    /** @return list<NempriDecisiveMonth> */
    private static function months(string $from, int $count): array
    {
        $months = [];
        for ($i = 0; $i < $count; $i++) {
            $date = (new \DateTimeImmutable($from . '-01'))->modify('+' . $i . ' months');
            $months[] = new NempriDecisiveMonth((int) $date->format('Y'), (int) $date->format('n'), 3_000_000, 0, NempriDecisiveMonth::SOURCE_TAKEOVER);
        }

        return $months;
    }
}
