<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\AnnualSettlement;

use DateTimeImmutable;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementAnnualClaims;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementBlocker;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementEligibility;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementFilingObligation;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementPriorEmployers;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementRequest;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementRequestStatus;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementStatute;
use MyInvoice\Service\Payroll\IncomeTax\TaxDeclarationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxResidence;
use PHPUnit\Framework\TestCase;

/** Posouzení podmínek § 38ch — kdy zúčtování provést lze a kdy ne. */
final class AnnualSettlementEligibilityTest extends TestCase
{
    private const YEAR = 2026;

    public function testCompleteEvidencePassesWithoutBlockers(): void
    {
        self::assertSame([], $this->evaluate($this->complete()));
    }

    public function testRequestedSettlementDoesNotRequireEvidenceReference(): void
    {
        $request = $this->complete(evidence: null);

        self::assertSame([], $this->evaluate($request));
        self::assertNull($request->requestEvidenceReference);
    }

    /** § 38ch odst. 1: bez žádosti není co provádět. */
    public function testMissingRequestBlocks(): void
    {
        $blockers = $this->evaluate($this->complete(
            status: AnnualSettlementRequestStatus::NotRequested,
            requestedOn: null,
            evidence: null,
        ));

        self::assertSame(
            [AnnualSettlementBlocker::NotRequested->value],
            self::codes($blockers),
        );
    }

    /**
     * Výchozí „nevíme" zúčtování zastaví stejně jako „nepožádal". Kdyby
     * neznámý stav procházel, stačilo by na zaměstnance zapomenout.
     */
    public function testUnknownEvidenceBlocksEverything(): void
    {
        $blockers = self::codes($this->evaluate(
            AnnualSettlementRequest::unknown(self::YEAR),
            TaxDeclarationStatus::Unverified,
            TaxResidence::Unverified,
        ));

        self::assertContains(AnnualSettlementBlocker::NotRequested->value, $blockers);
        self::assertContains(
            AnnualSettlementBlocker::DeclarationUnverified->value,
            $blockers,
        );
        self::assertContains(
            AnnualSettlementBlocker::PriorEmployerDocumentsMissing->value,
            $blockers,
        );
        self::assertContains(
            AnnualSettlementBlocker::FilingObligationUnknown->value,
            $blockers,
        );
        self::assertContains(
            AnnualSettlementBlocker::AnnualOnlyClaimsUnknown->value,
            $blockers,
        );
        self::assertContains(AnnualSettlementBlocker::NonResident->value, $blockers);
    }

    /** Zaměstnanec bez podepsaného prohlášení — zúčtování se neprovede. */
    public function testUnsignedDeclarationBlocks(): void
    {
        $blockers = self::codes($this->evaluate(
            $this->complete(),
            TaxDeclarationStatus::NotSigned,
        ));

        self::assertSame(
            [AnnualSettlementBlocker::DeclarationNotSigned->value],
            $blockers,
        );
    }

    /** § 38ch odst. 1 věta druhá: kdo podává přiznání, zúčtování nedostane. */
    public function testFilingObligationBlocks(): void
    {
        $blockers = self::codes($this->evaluate($this->complete(
            filing: AnnualSettlementFilingObligation::Required,
            filingReason: 'Příjmy podle § 7 nad 20 000 Kč.',
        )));

        self::assertSame(
            [AnnualSettlementBlocker::MustFileTaxReturn->value],
            $blockers,
        );
    }

    /** § 38ch odst. 1: žádost po 15. únoru je opožděná. */
    public function testLateRequestBlocks(): void
    {
        $deadline = AnnualSettlementStatute::requestDeadline(self::YEAR);
        $blockers = self::codes($this->evaluate($this->complete(
            requestedOn: $deadline->modify('+1 day'),
        )));

        self::assertSame(
            [AnnualSettlementBlocker::RequestedAfterDeadline->value],
            $blockers,
        );
        self::assertSame(
            sprintf('%04d-02-15', self::YEAR + 1),
            $deadline->format('Y-m-d'),
        );
    }

    /** § 38ch odst. 3: doklady předchozích plátců po 15. únoru. */
    public function testLatePriorEmployerDocumentsBlock(): void
    {
        $blockers = self::codes($this->evaluate($this->complete(
            prior: AnnualSettlementPriorEmployers::AllDocumented,
            priorReceivedOn: AnnualSettlementStatute::priorDocumentsDeadline(self::YEAR)
                ->modify('+1 day'),
        )));

        self::assertSame(
            [AnnualSettlementBlocker::PriorEmployerDocumentsLate->value],
            $blockers,
        );
    }

    /** § 38h odst. 6: položky uplatňované až ročně, které modul neumí. */
    public function testUnsupportedAnnualClaimsBlock(): void
    {
        $blockers = self::codes($this->evaluate($this->complete(
            claims: AnnualSettlementAnnualClaims::PresentUnsupported,
            claimsNote: 'Úroky z hypotečního úvěru podle § 15 odst. 3.',
        )));

        self::assertSame(
            [AnnualSettlementBlocker::AnnualOnlyClaimsUnsupported->value],
            $blockers,
        );
    }

    /** § 38ch odst. 4: po 31. březnu se zúčtování neprovádí. */
    public function testSettlementAfterDeadlineBlocks(): void
    {
        $deadline = AnnualSettlementStatute::settlementDeadline(self::YEAR);
        self::assertSame(
            sprintf('%04d-03-31', self::YEAR + 1),
            $deadline->format('Y-m-d'),
        );

        $blockers = self::codes($this->evaluate(
            $this->complete(),
            today: $deadline->modify('+1 day'),
        ));

        self::assertSame(
            [AnnualSettlementBlocker::SettlementDeadlinePassed->value],
            $blockers,
        );
    }

    /**
     * Zdaňovací období ještě běží — zúčtování je úkon nad UPLYNULÝM rokem.
     *
     * Bez téhle překážky by šlo v červnu zúčtovat pět uzavřených měsíců a
     * odečíst od nich celou roční slevu na poplatníka, protože ta se podle
     * § 35ba odst. 1 písm. a) nekrátí. Vyšel by přeplatek, který poplatníkovi
     * nenáleží, a `AlreadySettled` by pak zablokovala řádné zúčtování.
     */
    public function testSettlementBeforeTaxYearEndsBlocks(): void
    {
        $blockers = self::codes($this->evaluate(
            $this->complete(),
            today: new DateTimeImmutable(sprintf('%04d-06-15', self::YEAR)),
        ));

        self::assertSame(
            [AnnualSettlementBlocker::TaxYearNotFinished->value],
            $blockers,
        );
    }

    /** Poslední den roku ještě ne, první den následujícího už ano. */
    public function testTaxYearBoundaryIsInclusiveFromFirstJanuary(): void
    {
        self::assertSame(
            sprintf('%04d-01-01', self::YEAR + 1),
            AnnualSettlementStatute::settlementEarliest(self::YEAR)->format('Y-m-d'),
        );
        self::assertSame(
            [AnnualSettlementBlocker::TaxYearNotFinished->value],
            self::codes($this->evaluate(
                $this->complete(),
                today: new DateTimeImmutable(sprintf('%04d-12-31 23:59:59', self::YEAR)),
            )),
        );
        self::assertSame([], $this->evaluate(
            $this->complete(),
            today: new DateTimeImmutable(sprintf('%04d-01-01 00:00:00', self::YEAR + 1)),
        ));
    }

    /**
     * Poslední den lhůty se počítá celý. `new DateTimeImmutable()` nese i čas,
     * takže syrové porovnání s půlnocí by 31. března dopoledne vyhodnotilo jako
     * „po lhůtě".
     */
    public function testDeadlineDayIsUsableUntilMidnight(): void
    {
        self::assertSame([], $this->evaluate(
            $this->complete(),
            today: new DateTimeImmutable(sprintf('%04d-03-31 09:15:00', self::YEAR + 1)),
        ));
    }

    /** § 38g odst. 2: nerezident si přiznání podává sám. */
    public function testNonResidentBlocks(): void
    {
        $blockers = self::codes($this->evaluate(
            $this->complete(),
            residence: TaxResidence::NonResident,
        ));

        self::assertSame([AnnualSettlementBlocker::NonResident->value], $blockers);
    }

    /** Překážky se vracejí VŠECHNY najednou, ne jen ta první nalezená. */
    public function testAllBlockersAreReportedTogether(): void
    {
        $blockers = self::codes($this->evaluate(
            $this->complete(
                status: AnnualSettlementRequestStatus::NotRequested,
                requestedOn: null,
                evidence: null,
                filing: AnnualSettlementFilingObligation::Required,
                filingReason: 'Doplatek mzdy za minulá léta.',
            ),
            TaxDeclarationStatus::NotSigned,
        ));

        self::assertCount(3, $blockers);
    }

    /** @return list<AnnualSettlementBlocker> */
    private function evaluate(
        AnnualSettlementRequest $request,
        TaxDeclarationStatus $declaration = TaxDeclarationStatus::Signed,
        TaxResidence $residence = TaxResidence::CzechResident,
        ?DateTimeImmutable $today = null,
    ): array {
        return (new AnnualSettlementEligibility())->evaluate(
            $request,
            $declaration,
            $residence,
            $today ?? new DateTimeImmutable(sprintf('%04d-03-01', self::YEAR + 1)),
        );
    }

    private function complete(
        AnnualSettlementRequestStatus $status = AnnualSettlementRequestStatus::Requested,
        ?DateTimeImmutable $requestedOn = null,
        ?string $evidence = 'synthetic-request-evidence',
        AnnualSettlementPriorEmployers $prior = AnnualSettlementPriorEmployers::None,
        ?DateTimeImmutable $priorReceivedOn = null,
        AnnualSettlementFilingObligation $filing = AnnualSettlementFilingObligation::None,
        ?string $filingReason = null,
        AnnualSettlementAnnualClaims $claims = AnnualSettlementAnnualClaims::None,
        ?string $claimsNote = null,
    ): AnnualSettlementRequest {
        if ($status === AnnualSettlementRequestStatus::Requested && $requestedOn === null) {
            $requestedOn = new DateTimeImmutable(sprintf('%04d-02-10', self::YEAR + 1));
        }

        return new AnnualSettlementRequest(
            self::YEAR,
            $status,
            $requestedOn,
            $evidence,
            $prior,
            $priorReceivedOn,
            $filing,
            $filingReason,
            $claims,
            $claimsNote,
            null,
        );
    }

    /**
     * @param list<AnnualSettlementBlocker> $blockers
     * @return list<string>
     */
    private static function codes(array $blockers): array
    {
        return array_map(
            static fn (AnnualSettlementBlocker $blocker): string => $blocker->value,
            $blockers,
        );
    }
}
