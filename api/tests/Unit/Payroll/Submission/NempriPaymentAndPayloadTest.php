<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\CzechBirthNumber;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriPaymentConnection;
use MyInvoice\Service\Payroll\Submission\Sickness\NempriPaymentConnectionResolver;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessBenefitKind;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessException;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessPayloadFactory;
use PHPUnit\Framework\TestCase;

/**
 * Mapování případu dávky na obsah NEMPRI: způsob výplaty mzdy, skutečný
 * den nástupu a žádost o dávku. Všechna data jsou syntetická.
 */
final class NempriPaymentAndPayloadTest extends TestCase
{
    public function testCzechAccountWithPrefixIsSplit(): void
    {
        $connection = (new NempriPaymentConnectionResolver())->resolve('bank', '19-1000000005/0100');

        self::assertNotNull($connection);
        self::assertSame(NempriPaymentConnection::KIND_ACCOUNT_CZ, $connection->kind);
        self::assertSame('19', $connection->accountPrefix);
        self::assertSame('1000000005', $connection->accountNumber);
        self::assertSame('0100', $connection->bankCode);
    }

    public function testCzechIbanIsConvertedToNationalAccount(): void
    {
        $connection = (new NempriPaymentConnectionResolver())
            ->resolve('mixed', 'CZ04 0100 0000 1910 0000 0005');

        self::assertNotNull($connection);
        self::assertSame(NempriPaymentConnection::KIND_ACCOUNT_CZ, $connection->kind);
        self::assertSame('19', $connection->accountPrefix);
        self::assertSame('1000000005', $connection->accountNumber);
        self::assertSame('0100', $connection->bankCode);
    }

    public function testForeignIbanGoesToForeignAccount(): void
    {
        $connection = (new NempriPaymentConnectionResolver())
            ->resolve('bank', 'DE89 3704 0044 0532 0130 00');

        self::assertNotNull($connection);
        self::assertSame(NempriPaymentConnection::KIND_ACCOUNT_FOREIGN, $connection->kind);
        self::assertSame('DE', $connection->countryCode);
        self::assertSame('DE89370400440532013000', $connection->iban);
    }

    /**
     * Všeobecné zásady NEMPRI 2025 (Způsob výplaty mzdy nebo odměny): mzda
     * v hotovosti je volba „v hotovosti nebo na adresu v zahraničí“, po níž
     * ÚSSZ vyzve pojištěnce, aby určil způsob výplaty dávky. Poštovní poukázka
     * na adresu bydliště by tvrdila jiný způsob výplaty mzdy, než jaký je.
     */
    public function testCashWageIsReportedAsCashWithoutAddress(): void
    {
        $connection = (new NempriPaymentConnectionResolver())->resolve('cash', null);

        self::assertSame(NempriPaymentConnection::KIND_CASH, $connection->kind);
        self::assertNull($connection->city);
        self::assertNull($connection->houseNumber);
        self::assertNull($connection->postalCode);
    }

    /**
     * Bez výplatního profilu není známo, jak se mzda vyplácí. Adresa bydliště
     * by z toho udělala poštovní poukázku, o kterou zaměstnanec nepožádal.
     */
    public function testUnknownPayoutMethodDoesNotGuessPostalOrder(): void
    {
        try {
            (new NempriPaymentConnectionResolver())->resolve(null, null);
            self::fail('Neznámý způsob výplaty mzdy se nesmí hádat jako poštovní poukázka.');
        } catch (SicknessException $exception) {
            self::assertSame('nempri_payment_connection_missing', $exception->validationCode);
        }
    }

    /**
     * NX-02: DV NEMPRI25 chce platební spojení u každé věty s akcí vznik.
     * U výplaty přes partnera účet ani adresu nevymýšlíme, ale ani je tiše
     * nevynecháme — podání se zastaví s vysvětlením, i když osoba nějaký
     * účet v evidenci má.
     */
    public function testPartnerSettlementStopsWithClearReason(): void
    {
        try {
            (new NempriPaymentConnectionResolver())->resolve('partner_settlement', '1000000005/0100');
            self::fail('Výplata přes partnera nesmí větu tiše nechat bez platebního spojení.');
        } catch (SicknessException $exception) {
            self::assertSame('nempri_payment_connection_partner_settlement', $exception->validationCode);
            self::assertStringContainsString('partnera', $exception->getMessage());
        }
    }

    public function testMissingAccountStopsWithReason(): void
    {
        try {
            (new NempriPaymentConnectionResolver())->resolve('bank', null);
            self::fail('Bez účtu se způsob výplaty mzdy nesmí tiše vynechat.');
        } catch (SicknessException $exception) {
            self::assertSame('nempri_payment_connection_missing', $exception->validationCode);
        }
    }

    /**
     * `zamestnanOd` je den, kdy zaměstnání skutečně vzniklo. Nastoupil-li
     * zaměstnanec jindy, než sjednala smlouva, věta dřív nesla sjednaný den.
     */
    public function testEmploymentFromIsTheActualStartDate(): void
    {
        $payload = (new SicknessPayloadFactory())->nempri(
            $this->row(),
            SicknessBenefitKind::Nem,
            [
                'start_date' => '2026-03-01',
                'actual_start_date' => '2026-03-04',
                'end_date' => null,
                'employer_business_id' => '12345678',
                'employer_name' => 'Testovací zaměstnavatel s.r.o.',
                'employer_variable_symbol' => '1234567890',
                'activity_code' => '1',
            ],
            [
                'identity' => ['first_name' => 'Jan', 'last_name' => 'Testovací'],
                'identifiers' => ['birth_number' => '8001010006', 'ecp' => null],
            ],
            '1.0',
            'MyUcto',
            '1.0',
        );

        self::assertSame('2026-03-04', $payload->employmentFrom);
        self::assertSame('Mzdová Účetní', $payload->contactWorkerName);
    }

    /**
     * Karta osoby drží rodné číslo jako RRMMDD/XXXX; `rodneCislo` v NEMPRI
     * bere jen číslice, jinak validátor věty odmítne každého českého
     * zaměstnance.
     */
    public function testBirthNumberWithSlashIsSentAsDigits(): void
    {
        $payload = (new SicknessPayloadFactory())->nempri(
            $this->row(),
            SicknessBenefitKind::Nem,
            [
                'start_date' => '2026-03-01',
                'end_date' => null,
                'employer_business_id' => '12345678',
                'employer_name' => 'Testovací zaměstnavatel s.r.o.',
                'employer_variable_symbol' => '1234567890',
                'activity_code' => '1',
            ],
            [
                'identity' => ['first_name' => 'Jan', 'last_name' => 'Testovací'],
                'identifiers' => ['birth_number' => '800101/0006', 'ecp' => null],
            ],
            '1.0',
            'MyUcto',
            '1.0',
        );

        self::assertSame('8001010006', $payload->insuredBirthNumber);
    }

    public function testSubmissionBirthNumberKeepsNonBirthNumbersForTheValidator(): void
    {
        self::assertSame('8001010006', CzechBirthNumber::forSubmission('800101/0006'));
        self::assertSame('1234567890', CzechBirthNumber::forSubmission('1234567890'));
        self::assertSame('bez-cisla', CzechBirthNumber::forSubmission('bez-cisla'));
        self::assertNull(CzechBirthNumber::forSubmission(null));
    }

    /**
     * Hranice žádosti se berou z případu, pokud je účetní nepřepsala — žádá-li
     * zaměstnanec o dávku za celé trvání události, jsou to tytéž dny.
     */
    public function testApplicationPeriodDefaultsToCasePeriod(): void
    {
        $application = (new SicknessPayloadFactory())->application(
            [...$this->row(), 'incapacity_from' => '2026-09-07', 'incapacity_to' => '2026-09-11', 'action_start' => 1, 'action_end' => 1],
            null,
        );

        self::assertSame('2026-09-07', $application->fromDate);
        self::assertSame('2026-09-11', $application->toDate);
        self::assertTrue($application->actionEnd);
    }

    /**
     * Zásady NEMPRI: prohlášení, které zaměstnanec v žádosti nevyplnil,
     * zaměstnavatel uvede jako „NE“ — žádost kvůli němu nezadrží.
     */
    public function testUndeclaredCareStatementsAreSentAsNo(): void
    {
        $factory = new SicknessPayloadFactory();

        $care = $factory->application(
            [...$this->row(), 'action_start' => 1, 'action_end' => 1],
            null,
            SicknessBenefitKind::Ose,
        );
        self::assertFalse($care->sharedHousehold);
        self::assertFalse($care->loneCaregiver);
        self::assertFalse($care->caredPersonally);

        $paternity = $factory->application($this->row(), null, SicknessBenefitKind::Opp);
        self::assertNull($paternity->sharedHousehold);
    }

    /** @return array<string,mixed> */
    private function row(): array
    {
        return [
            'ossz_code' => 115,
            'correction' => 0,
            'decision_number' => 'A1234567',
            'foreign_case' => 0,
            'worked_on_decisive_day' => 0,
            'hours_worked' => null,
            'daily_working_hours' => '8.00',
            'small_scope_income_minor' => null,
            'receives_pension' => 0,
            'pension_kind' => null,
            'is_student' => 0,
            'within_school_holidays' => null,
            'first_employment_free_time' => 0,
            'unpaid_leave' => 0,
            'unpaid_leave_from' => null,
            'unpaid_leave_to' => null,
            'starts_maternity' => null,
            'child_birth_date' => null,
            'transferred_other_work' => 0,
            'transferred_on' => null,
            'enforcement' => 0,
            'insolvency' => 0,
            'additional_note' => null,
            'contact_worker_name' => 'Mzdová Účetní',
            'work_days' => [],
        ];
    }
}
