<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\TaxStatement;

/**
 * Jeden řádek přílohy č. 1 — počet zaměstnanců podle místa výkonu práce.
 *
 * Obec je základní územní jednotka (ZÚJ) z evidence JMHZ; podle ní se dělí
 * podíl obcí na výnosu daně z příjmů. Okres se dopočítává z připnutého
 * číselníku obcí, protože ho tiskopis chce jménem a v evidenci vztahu není.
 */
final readonly class WorkplaceHeadcount
{
    /**
     * @param ?string $municipalityCode Šestimístný kód ZÚJ. `null` = vztah nemá
     *        vyplněné místo výkonu práce; takový řádek se do XML nedostane
     *        a je z něj varování.
     */
    public function __construct(
        public ?string $municipalityCode,
        public ?string $municipalityName,
        public ?string $districtName,
        public int $headcount,
    ) {
        if ($headcount < 0) {
            throw new \DomainException('Počet zaměstnanců nesmí být záporný.');
        }
    }

    public function isComplete(): bool
    {
        return $this->municipalityCode !== null && $this->municipalityCode !== '';
    }
}
