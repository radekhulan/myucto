<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\AnnualSettlement;

use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementEvidenceMonths;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidence;
use PHPUnit\Framework\TestCase;

/**
 * Prohlášení a rezidentství se posuzují za období trvání vztahu, ne k 31. 12.
 */
final class AnnualSettlementEvidenceMonthsTest extends TestCase
{
    private const YEAR = 2026;

    /** Zaměstnanec u plátce celý rok — chování se oproti čtení k 31. 12. nemění. */
    public function testFullYearEmployeeIsSignedResident(): void
    {
        $evidence = $this->evaluate(
            [$this->row('status', 'signed', '2020-01-01', null)],
            [$this->row('residence', 'czech-resident', '2020-01-01', null)],
            range(1, 12),
        );

        self::assertSame(TaxDeclarationStatus::Signed, $evidence['declaration']);
        self::assertSame(TaxResidence::CzechResident, $evidence['residence']);
    }

    /**
     * Jádro nálezu: kdo v červnu odešel, nemá k 31. 12. účinné nic — a přesto
     * měl prohlášení i rezidenci doložené po celou dobu trvání vztahu.
     */
    public function testMidYearLeaverKeepsSignedDeclarationAndResidence(): void
    {
        $evidence = $this->evaluate(
            [$this->row(
                'status',
                'signed',
                sprintf('%04d-01-01', self::YEAR),
                sprintf('%04d-06-30', self::YEAR),
            )],
            [$this->row(
                'residence',
                'czech-resident',
                sprintf('%04d-01-01', self::YEAR),
                sprintf('%04d-06-30', self::YEAR),
            )],
            range(1, 6),
        );

        self::assertSame(TaxDeclarationStatus::Signed, $evidence['declaration']);
        self::assertSame(TaxResidence::CzechResident, $evidence['residence']);
    }

    /** Nástup v půli roku: evidence od července stačí. */
    public function testMidYearJoinerIsEvaluatedOverItsOwnMonths(): void
    {
        $evidence = $this->evaluate(
            [$this->row('status', 'signed', sprintf('%04d-07-01', self::YEAR), null)],
            [$this->row('residence', 'czech-resident', sprintf('%04d-07-01', self::YEAR), null)],
            range(7, 12),
        );

        self::assertSame(TaxDeclarationStatus::Signed, $evidence['declaration']);
        self::assertSame(TaxResidence::CzechResident, $evidence['residence']);
    }

    /**
     * Souběh dvou vztahů u téhož plátce: prohlášení se vede na zaměstnance,
     * takže sjednocení měsíců obou vztahů dá týž výsledek.
     */
    public function testConcurrentEmploymentsShareTheSameEvidence(): void
    {
        $evidence = $this->evaluate(
            [$this->row('status', 'signed', '2020-01-01', null)],
            [$this->row('residence', 'czech-resident', '2020-01-01', null)],
            [1, 2, 3, 4, 5, 6, 7, 8],
        );

        self::assertSame(TaxDeclarationStatus::Signed, $evidence['declaration']);
        self::assertSame(TaxResidence::CzechResident, $evidence['residence']);
    }

    /** Prohlášení podepsané až od března: leden a únor bez řádku nevadí. */
    public function testMonthsWithoutAnyRowAreIgnored(): void
    {
        $evidence = $this->evaluate(
            [$this->row('status', 'signed', sprintf('%04d-03-01', self::YEAR), null)],
            [$this->row('residence', 'czech-resident', '2020-01-01', null)],
            range(1, 12),
        );

        self::assertSame(TaxDeclarationStatus::Signed, $evidence['declaration']);
    }

    /** Explicitní „nedoložené" v kterémkoli měsíci rozhoduje — fail-closed. */
    public function testUnverifiedMonthWins(): void
    {
        $evidence = $this->evaluate(
            [
                $this->row(
                    'status',
                    'signed',
                    sprintf('%04d-01-01', self::YEAR),
                    sprintf('%04d-05-31', self::YEAR),
                ),
                $this->row('status', 'unverified', sprintf('%04d-06-01', self::YEAR), null),
            ],
            [$this->row('residence', 'czech-resident', '2020-01-01', null)],
            range(1, 12),
        );

        self::assertSame(TaxDeclarationStatus::Unverified, $evidence['declaration']);
    }

    /** Změna rezidentství v průběhu roku je důvod k přiznání, ne k zúčtování. */
    public function testNonResidentMonthWins(): void
    {
        $evidence = $this->evaluate(
            [$this->row('status', 'signed', '2020-01-01', null)],
            [
                $this->row(
                    'residence',
                    'czech-resident',
                    sprintf('%04d-01-01', self::YEAR),
                    sprintf('%04d-08-31', self::YEAR),
                ),
                $this->row(
                    'residence',
                    'non-resident',
                    sprintf('%04d-09-01', self::YEAR),
                    null,
                ),
            ],
            range(1, 12),
        );

        self::assertSame(TaxResidence::NonResident, $evidence['residence']);
    }

    /**
     * Nerezidentská část roku MIMO rozsah vztahu se neposuzuje — odešel
     * v srpnu, do ciziny se odstěhoval potom.
     */
    public function testResidenceOutsideEmploymentMonthsIsNotConsidered(): void
    {
        $evidence = $this->evaluate(
            [$this->row(
                'status',
                'signed',
                sprintf('%04d-01-01', self::YEAR),
                sprintf('%04d-08-31', self::YEAR),
            )],
            [
                $this->row(
                    'residence',
                    'czech-resident',
                    sprintf('%04d-01-01', self::YEAR),
                    sprintf('%04d-08-31', self::YEAR),
                ),
                $this->row(
                    'residence',
                    'non-resident',
                    sprintf('%04d-09-01', self::YEAR),
                    null,
                ),
            ],
            range(1, 8),
        );

        self::assertSame(TaxResidence::CzechResident, $evidence['residence']);
        self::assertSame(TaxDeclarationStatus::Signed, $evidence['declaration']);
    }

    /** Bez jediného řádku v rozsahu se nic nedomýšlí — vyjde nedoložené. */
    public function testEmptyEvidenceIsUnverified(): void
    {
        $evidence = $this->evaluate([], [], range(1, 12));

        self::assertSame(TaxDeclarationStatus::Unverified, $evidence['declaration']);
        self::assertSame(TaxResidence::Unverified, $evidence['residence']);
    }

    /** Výslovně nepodepsané prohlášení zůstává „nepodepsané", ne „nedoložené". */
    public function testExplicitNotSignedIsKept(): void
    {
        $evidence = $this->evaluate(
            [$this->row('status', 'not-signed', '2020-01-01', null)],
            [$this->row('residence', 'czech-resident', '2020-01-01', null)],
            range(1, 12),
        );

        self::assertSame(TaxDeclarationStatus::NotSigned, $evidence['declaration']);
    }

    /**
     * Neznámé měsíce vztahu (legacy evidence bez záznamu) se posuzují za celý
     * rok — zúžit rozsah na nic by zablokovalo i toho, komu nic nechybí.
     */
    public function testEmptyMonthListFallsBackToWholeYear(): void
    {
        $evidence = $this->evaluate(
            [$this->row(
                'status',
                'signed',
                sprintf('%04d-02-01', self::YEAR),
                sprintf('%04d-04-30', self::YEAR),
            )],
            [$this->row('residence', 'czech-resident', '2020-01-01', null)],
            [],
        );

        self::assertSame(TaxDeclarationStatus::Signed, $evidence['declaration']);
    }

    /** Interval mimo rok nepatří nikam — evidence z jiného roku se nepočítá. */
    public function testIntervalOutsideTheYearDoesNotCount(): void
    {
        $evidence = $this->evaluate(
            [$this->row(
                'status',
                'signed',
                sprintf('%04d-01-01', self::YEAR - 2),
                sprintf('%04d-12-31', self::YEAR - 1),
            )],
            [],
            range(1, 12),
        );

        self::assertSame(TaxDeclarationStatus::Unverified, $evidence['declaration']);
    }

    /** Rozbité datum v evidenci nesmí shodit posouzení — je to neznámý stav. */
    public function testBrokenDateIsTreatedAsNoCoverage(): void
    {
        $evidence = $this->evaluate(
            [$this->row('status', 'signed', 'not-a-date', null)],
            [],
            range(1, 12),
        );

        self::assertSame(TaxDeclarationStatus::Unverified, $evidence['declaration']);
    }

    /**
     * @param list<array<string,mixed>> $declarations
     * @param list<array<string,mixed>> $residences
     * @param list<int> $months
     * @return array{declaration:TaxDeclarationStatus,residence:TaxResidence}
     */
    private function evaluate(
        array $declarations,
        array $residences,
        array $months,
    ): array {
        return (new AnnualSettlementEvidenceMonths())->evaluate(
            $declarations,
            $residences,
            self::YEAR,
            $months,
        );
    }

    /** @return array<string,mixed> */
    private function row(
        string $column,
        string $value,
        string $from,
        ?string $to,
    ): array {
        return [
            $column => $value,
            'effective_from' => $from,
            'effective_to' => $to,
        ];
    }
}
