<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

use DateTimeImmutable;

/**
 * Evidence žádosti o roční zúčtování a podkladů k ní.
 *
 * Odpovídá jednomu řádku `payroll_annual_settlement_requests`. Je to jediné
 * místo, kde je zaznamenané, co poplatník doložil — a proto taky jediné místo,
 * odkud se dá zjistit, PROČ se zúčtování neprovedlo.
 */
final readonly class AnnualSettlementRequest
{
    public function __construct(
        public int $taxYear,
        public AnnualSettlementRequestStatus $status,
        public ?DateTimeImmutable $requestedOn,
        public ?string $requestEvidenceReference,
        public AnnualSettlementPriorEmployers $priorEmployers,
        public ?DateTimeImmutable $priorDocumentsReceivedOn,
        public AnnualSettlementFilingObligation $filingObligation,
        public ?string $filingObligationReason,
        public AnnualSettlementAnnualClaims $annualClaims,
        public ?string $annualClaimsNote,
        public ?string $note,
        public int $rowVersion = 1,
    ) {
        if ($taxYear < 2000 || $taxYear > 2199) {
            throw new \InvalidArgumentException('Rok žádosti není platný.');
        }
        // Rozpracovaná evidence se ukládá tak, jak je.
        //
        // Dřív tu stálo pět tvrdých podmínek na dvojice polí („podaná žádost
        // musí mít datum", „doložené doklady musí mít datum převzetí" …) a
        // každá z nich uložení ODMÍTLA. Účetní, která zaškrtla „požádal" a
        // ještě nestihla opsat datum z papíru, přišla o celý formulář —
        // včetně věcí, které vyplnila správně.
        //
        // Podmínky nezmizely, jen se přesunuly tam, kam patří: do posouzení
        // (`AnnualSettlementEligibility`). Zúčtování bez data žádosti nebo bez
        // data převzetí dokladů se dál NEPROVEDE, protože u obou je datum
        // jediné, čím se doloží dodržení lhůty podle § 38ch odst. 1 a 3 —
        // ale rozdělaná evidence se kvůli tomu neztratí.
    }

    /**
     * Chybí-li u vyplněného stavu jeho datum, není to důvod evidenci
     * neuložit — je to důvod neprovést zúčtování. Vrací překážky, které z
     * neúplnosti plynou; prázdné pole znamená „po téhle stránce je podklad
     * úplný".
     *
     * @return list<AnnualSettlementBlocker>
     */
    public function incompletenessBlockers(): array
    {
        $blockers = [];
        if ($this->status === AnnualSettlementRequestStatus::Requested
            && $this->requestedOn === null
        ) {
            $blockers[] = AnnualSettlementBlocker::RequestDateMissing;
        }
        if ($this->priorEmployers === AnnualSettlementPriorEmployers::AllDocumented
            && $this->priorDocumentsReceivedOn === null
        ) {
            $blockers[] = AnnualSettlementBlocker::PriorDocumentsDateMissing;
        }

        return $blockers;
    }

    public static function unknown(int $taxYear): self
    {
        return new self(
            $taxYear,
            AnnualSettlementRequestStatus::Unknown,
            null,
            null,
            AnnualSettlementPriorEmployers::Unknown,
            null,
            AnnualSettlementFilingObligation::Unknown,
            null,
            AnnualSettlementAnnualClaims::Unknown,
            null,
            null,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'tax_year' => $this->taxYear,
            'status' => $this->status->value,
            'requested_on' => $this->requestedOn?->format('Y-m-d'),
            'request_evidence_reference' => $this->requestEvidenceReference,
            'prior_employers' => $this->priorEmployers->value,
            'prior_documents_received_on' =>
                $this->priorDocumentsReceivedOn?->format('Y-m-d'),
            'filing_obligation' => $this->filingObligation->value,
            'filing_obligation_reason' => $this->filingObligationReason,
            'annual_claims' => $this->annualClaims->value,
            'annual_claims_note' => $this->annualClaimsNote,
            'note' => $this->note,
            'row_version' => $this->rowVersion,
        ];
    }
}
