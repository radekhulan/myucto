<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

final class JmhzEmployerAnnualEvidenceConflictException extends \RuntimeException
{
    public function __construct(public readonly ?int $currentRevisionId)
    {
        parent::__construct('Roční údaje JMHZ mezitím změnil jiný uživatel. Načtěte je znovu.');
    }
}
