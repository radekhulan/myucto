<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

final readonly class JmhzControlDefinition
{
    /**
     * @param list<string> $attributeIds
     * @param list<string> $symbolicAttributeRefs
     */
    public function __construct(
        public JmhzControlId $id,
        public string $name,
        public JmhzControlScope $scope,
        public JmhzControlSystem $portalSystem,
        public JmhzControlPassability $portalPassability,
        public JmhzControlSystem $remoteSystem,
        public JmhzControlPassability $remotePassability,
        public string $detail,
        public string $errorMessage,
        public array $attributeIds,
        public array $symbolicAttributeRefs,
        public ?string $category,
        public ?string $area,
        public ?string $sourceAnomaly,
    ) {}

    /**
     * Katalog rozlišuje technické kontroly (T*) od formálních (F*). Technická
     * vada odmítá celé podání, formální jen část nebo součást — bez téhle osy
     * nelze z nálezu určit, co přesně se zamítá.
     */
    public function isTechnical(): bool
    {
        return $this->category !== null && str_starts_with($this->category, 'T');
    }
}
