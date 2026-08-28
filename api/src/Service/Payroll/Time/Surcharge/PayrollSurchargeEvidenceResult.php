<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Surcharge;

/**
 * Co evidence docházky o příplatcích DOLOŽILA — bez jediného vypočteného haléře.
 *
 * Oddělení „co se stalo" od „kolik to dělá" je záměrné: zjištění skutkového
 * stavu závisí na datech firmy, výpočet jen na zákonném základu a sazbě.
 * Kdyby to byla jedna třída, nešlo by testovat hraniční skutkové případy
 * (svátek o víkendu, přesčas v noci, náhradní volno) bez toho, aby se do testu
 * pletl průměrný výdělek.
 */
final readonly class PayrollSurchargeEvidenceResult
{
    /**
     * @param list<PayrollSurchargeSegment> $segments doba, za kterou příplatek NÁLEŽÍ
     * @param array<string,list<array<string,mixed>>> $waived doba, za kterou nenáleží, i s důvodem
     * @param list<array{reason:string,message:string,local_date:?string}> $findings
     */
    public function __construct(
        public array $segments,
        public array $waived,
        public array $findings,
    ) {}

    /** @return list<PayrollSurchargeSegment> */
    public function segmentsFor(PayrollSurchargeKind $kind): array
    {
        return array_values(array_filter(
            $this->segments,
            static fn (PayrollSurchargeSegment $segment): bool => $segment->kind === $kind,
        ));
    }

    /** @return list<array<string,mixed>> */
    public function waivedFor(PayrollSurchargeKind $kind): array
    {
        return $this->waived[$kind->value] ?? [];
    }

    /**
     * Vyžaduje výsledek zásah účetní, i když spočítat se dá?
     *
     * Nálezy nejsou chyby — jsou to místa, kde evidence dovolí spočítat, ale
     * někdo se na to musí podívat (nevyčerpané náhradní volno, svátek bez
     * evidence poskytnutého volna). Tichý průchod by z nich udělal nedoplatek,
     * který nikdo nikdy neuvidí.
     */
    public function requiresManualReview(): bool
    {
        return $this->findings !== [];
    }
}
