<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxReturnRepository;

final class IncomeTaxSubmissionGate
{
    public function __construct(private readonly Connection $db) {}

    public function assertReady(int $supplierId, array $submission): void
    {
        $formCode = strtolower((string) ($submission['form_code'] ?? ''));
        if ($formCode === 'dppdp9') {
            (new AccountingDecreeService($this->db))->assertDppoSubmissionSupported($supplierId, $submission, true);
        }
        if ($formCode !== 'dpfdp7') {
            return;
        }

        // Starší archiv má u DPFO jen výsledek XSD kontroly. Věcný výsledek
        // dohledáme v původním snímku přiznání podle otisku archivovaného XML.
        $errors = (new TaxReturnRepository($this->db))->snapshotBusinessErrorsForXml(
            $supplierId,
            (int) $submission['period_year'],
            'fo',
            (string) $submission['xml_sha256'],
        );
        if ($errors !== []) {
            throw new TaxReturnException(
                'dpfo_business_validation_failed',
                'DPFO má nevyřešené věcné chyby. Opravte podklady, přiznání znovu finalizujte a stáhněte nové XML.',
                422,
            );
        }
    }
}
