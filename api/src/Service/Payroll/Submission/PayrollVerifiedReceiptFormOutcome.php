<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

final readonly class PayrollVerifiedReceiptFormOutcome
{
    private const REMOTE_STATUSES = ['accepted', 'rejected'];

    /** @param list<PayrollVerifiedReceiptFormError> $errors */
    public function __construct(
        public string $formReference,
        public ?int $partId,
        public ?int $protocolStatusCode,
        public ?string $protocolStatusName,
        public ?string $remoteStatus,
        public ?string $externalPersonReference,
        public ?string $externalEmploymentReference,
        public array $errors,
    ) {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/D', $formReference) !== 1) {
            throw new \InvalidArgumentException('Reference formuláře v protokolu není platná.');
        }
        if ($partId !== null && $partId <= 0) {
            throw new \InvalidArgumentException('Část podání formuláře musí být kladná.');
        }
        $hasStatus = $protocolStatusCode !== null
            || $protocolStatusName !== null
            || $remoteStatus !== null;
        if ($hasStatus && (
            $protocolStatusCode === null
            || $protocolStatusName === null
            || $remoteStatus === null
            || $protocolStatusCode < 1
            || $protocolStatusCode > 6
            || $protocolStatusName === ''
            || !in_array($remoteStatus, self::REMOTE_STATUSES, true)
        )) {
            throw new \InvalidArgumentException(
                'Doložený stav formuláře musí mít kód, název i vzdálený stav.',
            );
        }
        foreach ([$externalPersonReference, $externalEmploymentReference] as $reference) {
            if ($reference !== null && ($reference === '' || mb_strlen($reference) > 128)) {
                throw new \InvalidArgumentException(
                    'Externí identifikátor formuláře není platný.',
                );
            }
        }
        foreach ($errors as $error) {
            if (!$error instanceof PayrollVerifiedReceiptFormError) {
                throw new \InvalidArgumentException('Chyba formuláře nemá platný tvar.');
            }
        }
    }

    /** @return array<string,mixed> */
    public function fingerprintData(): array
    {
        return [
            'form_reference' => $this->formReference,
            'part_id' => $this->partId,
            'protocol_status_code' => $this->protocolStatusCode,
            'protocol_status_name' => $this->protocolStatusName,
            'remote_status' => $this->remoteStatus,
            'external_person_reference' => $this->externalPersonReference,
            'external_employment_reference' => $this->externalEmploymentReference,
            'errors' => array_map(
                static fn (PayrollVerifiedReceiptFormError $error): array
                    => $error->fingerprintData(),
                $this->errors,
            ),
        ];
    }
}
