<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

final readonly class PayrollRunTransitionContext
{
    public function __construct(
        public int $actorUserId,
        public ?int $calculatedBy = null,
        public ?int $reviewedBy = null,
        public int $blockerCount = 0,
        public int $unresolvedOverrideCount = 0,
        public bool $hasImmutableSnapshot = false,
        public bool $hasCalculatedResult = false,
        public bool $hasPostingBatch = false,
        public bool $hasPaymentBatch = false,
        public ?string $reason = null,
        /**
         * Vyžaduje firma pravidlo čtyř očí?
         *
         * Uzavřené produktové rozhodnutí: nikdy. Řada firem má jedinou účetní,
         * takže samostatný krok „Zkontrolovat" byl jen prázdný obřad před
         * „Schválit" — workflow ho nikdy nekontrolovalo proti JINÉ osobě, jen
         * proti tomu, že je vyplněný. `PayrollEmployerPolicyService` proto
         * `four_eyes_required` drží natvrdo na `false`.
         *
         * Příznak tu zůstává, aby podmínka byla vidět a dala se otestovat, ne
         * aby se pravidlo zapínalo.
         */
        public bool $fourEyesRequired = false,
    ) {
        if ($actorUserId <= 0) {
            throw new \InvalidArgumentException('Uživatel přechodu musí být platný.');
        }
        if ($blockerCount < 0 || $unresolvedOverrideCount < 0) {
            throw new \InvalidArgumentException('Počet validačních problémů nesmí být záporný.');
        }
    }
}
