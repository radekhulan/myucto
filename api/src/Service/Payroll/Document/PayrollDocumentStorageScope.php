<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

/**
 * Soubory, které transakce nově vytvořila na disku — kompenzace při rollbacku.
 *
 * Od zavedení šifrování per subjekt (W30 / C-05) nestačí pamatovat si klíč:
 * tentýž hash může ležet ve dvou různých podadresářích subjektů, takže úklid
 * potřebuje vědět i to, ke komu soubor patřil.
 */
final class PayrollDocumentStorageScope
{
    /** @var array<string,array{storage_key:string,subject_id:int}> */
    private array $created = [];
    private bool $closed = false;

    public function recordCreated(
        string $storageKey,
        int $subjectId = PayrollDocumentKeyRing::COMPANY_SUBJECT_ID,
    ): void {
        if ($this->closed) {
            throw new \LogicException('Storage scope is already closed.');
        }
        $this->created[$subjectId . ':' . $storageKey] = [
            'storage_key' => $storageKey,
            'subject_id' => $subjectId,
        ];
    }

    /** @return list<string> */
    public function createdKeys(): array
    {
        return array_values(array_unique(
            array_column($this->created, 'storage_key'),
        ));
    }

    /** @return list<array{storage_key:string,subject_id:int}> */
    public function createdEntries(): array
    {
        return array_values($this->created);
    }

    public function close(): void
    {
        $this->closed = true;
        $this->created = [];
    }
}
