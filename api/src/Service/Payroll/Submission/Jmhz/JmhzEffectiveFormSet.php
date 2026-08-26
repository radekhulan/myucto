<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

final readonly class JmhzEffectiveFormSet
{
    /**
     * @param list<JmhzEffectiveFormState> $forms
     * @param array<string,JmhzEffectiveFormState> $byEmployment
     */
    public function __construct(
        public array $forms,
        private array $byEmployment,
    ) {}

    public function forEmployment(string $externalIdentifier): JmhzEffectiveFormState
    {
        return $this->byEmployment[$externalIdentifier]
            ?? throw new JmhzXmlException(
                'jmhz_effective_state_employment_unknown',
                'Pracovní vztah není v úplném efektivním setu JMHZ.',
            );
    }
}
