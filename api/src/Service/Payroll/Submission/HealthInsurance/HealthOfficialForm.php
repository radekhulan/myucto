<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

/**
 * Jeden připnutý úřední tiskopis zdravotních pojišťoven.
 *
 * `sourceSha256` je otisk souboru TAK, JAK HO POJIŠŤOVNA ZVEŘEJŇUJE;
 * `sha256` je otisk souboru připnutého v repozitáři. Liší se, protože
 * publikovaný soubor je zašifrovaný (prázdné uživatelské heslo, `change=0`)
 * a knihovny, které umí PDF číst, ho v té podobě neotevřou. Připnutá kopie je
 * BEZ toho obalu, jinak bajt po bajtu tentýž dokument — viz
 * {@see CachedHealthOfficialFormProvider} pro postup a jeho ověření.
 */
final readonly class HealthOfficialForm
{
    /** @param list<string> $fieldNames pole tiskopisu v pořadí, ve kterém je vyžadujeme */
    public function __construct(
        public string $id,
        public string $bytes,
        public string $sha256,
        public string $sourceUrl,
        public string $sourceSha256,
        public string $formNumber,
        public int $rowCapacity,
        public array $fieldNames,
    ) {}

    /** Odkaz na verzi tiskopisu do zmrazeného otisku podání. */
    public function reference(): string
    {
        return $this->id . ':' . $this->sha256;
    }
}
