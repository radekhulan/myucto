<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

final readonly class PayrollVerifiedReceipt
{
    private const STATUSES = [
        'submitted',
        'processing',
        'accepted',
        'partially_accepted',
        'rejected',
        'waiting_for_identity',
        'correction_required',
    ];

    public function __construct(
        public string $remoteStatus,
        public ?string $correlationReference,
        /** @var array<int,string> */
        public array $partStatuses = [],
        /** @var list<PayrollVerifiedReceiptFormOutcome> */
        public array $formOutcomes = [],
    ) {
        if (!in_array($remoteStatus, self::STATUSES, true)) {
            throw new \InvalidArgumentException(
                'Ověřený vzdálený stav není podporovaný.',
            );
        }
        if ($correlationReference !== null
            && preg_match(
                '/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/D',
                $correlationReference,
            ) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Ověřená correlation reference není platná.',
            );
        }
        foreach ($partStatuses as $partId => $status) {
            if ($partId <= 0
                || !in_array($status, self::STATUSES, true)
            ) {
                throw new \InvalidArgumentException(
                    'Ověřený dílčí stav není platný.',
                );
            }
        }
        foreach ($formOutcomes as $outcome) {
            if (!$outcome instanceof PayrollVerifiedReceiptFormOutcome) {
                throw new \InvalidArgumentException(
                    'Ověřený výsledek formuláře nemá platný tvar.',
                );
            }
        }
    }
}
