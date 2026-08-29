<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PayrollEmployeeRepository;
use MyInvoice\Repository\PayrollMonthlyRecordRepository;
use MyInvoice\Service\Accounting\Payroll\PayrollSheetService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Identifikace zaměstnance na legacy mzdovém listu (§38j ZDP).
 *
 * Sestava sahala na `birth_number`, jenže ten `PayrollEmployeeRepository` od
 * W1/P-02 nevrací — legacy routa je chráněná jen právem `accounting`, takže by
 * plné rodné číslo viděl i uživatel bez jediného mzdového práva. Klíč tedy v
 * poli nebyl a každé vykreslení mzdového listu vyhodilo „Undefined array key",
 * aby stejně skončilo u data narození.
 */
final class PayrollSheetIdentificationTest extends TestCase
{
    /**
     * @param array<string,mixed> $employee
     * @return array<string,mixed>
     */
    private function sheet(array $employee): array
    {
        $employees = $this->createStub(PayrollEmployeeRepository::class);
        $employees->method('find')->willReturn($employee);
        $records = $this->createStub(PayrollMonthlyRecordRepository::class);
        $records->method('listForYear')->willReturn([]);

        // Hlavička sestavy si sáhne na `supplier` — pro identifikaci osoby je
        // to šum, takže dotaz jen odpoví prázdno.
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetch')->willReturn(false);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $connection = $this->createStub(Connection::class);
        $connection->method('pdo')->willReturn($pdo);

        $service = new PayrollSheetService($connection, $employees, $records);

        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        }, E_WARNING | E_NOTICE);
        try {
            $sheet = $service->build(1, 2, 2026);
        } finally {
            restore_error_handler();
        }
        self::assertSame([], $warnings, 'Mzdový list nesmí sahat na chybějící klíče.');

        $person = $sheet['employee'];
        self::assertIsArray($person);

        return $person;
    }

    /** @return array<string,mixed> */
    private function employee(?string $birthDate): array
    {
        // Přesně to, co dnes vrací `PayrollEmployeeRepository::find()` — bez
        // `birth_number` a bez `address`. Kdyby se do fixtury dopsaly, test by
        // přestal hlídat právě tu díru, kvůli které vznikl.
        return [
            'id' => 2,
            'supplier_id' => 1,
            'full_name' => 'Syntetická Osoba',
            'birth_date' => $birthDate,
            'taxpayer_type' => 'employee',
            'employment_type' => 'employment',
            'tax_declaration_signed' => true,
            'tax_credit_taxpayer' => true,
            'child_count' => 0,
            'monthly_gross' => null,
            'auto_post' => false,
            'is_active' => true,
        ];
    }

    public function testSheetIdentifiesThePersonByBirthDateWithoutTouchingMissingKeys(): void
    {
        $person = $this->sheet($this->employee('1985-03-17'));

        self::assertSame('Datum narození', $person['birth_id_label']);
        self::assertSame('17.03.1985', $person['birth_id_value']);
    }

    /** Bez data narození se nic nedomýšlí — sestava přizná, že údaj nemá. */
    public function testSheetAdmitsWhenItHasNoIdentificationAtAll(): void
    {
        $person = $this->sheet($this->employee(null));

        self::assertSame('Datum narození', $person['birth_id_label']);
        self::assertSame('—', $person['birth_id_value']);
    }

    /**
     * Adresa je druhý vyřazený sloupec (migrace 1611) a repository ji taky
     * nevrací. Sestava ji smí jen zastoupit pomlčkou, ne spadnout.
     */
    public function testSheetSurvivesTheRetiredAddressColumn(): void
    {
        $person = $this->sheet($this->employee('1985-03-17'));

        self::assertSame('—', $person['address']);
    }
}
