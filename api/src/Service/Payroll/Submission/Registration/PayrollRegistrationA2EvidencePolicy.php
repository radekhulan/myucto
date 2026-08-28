<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

final class PayrollRegistrationA2EvidencePolicy
{
    /** @param array<string,mixed> $facts @return array{decision:string,reason:?string} */
    public static function decide(array $facts): array
    {
        if (($facts['submission_status'] ?? null) === 'rejected'
            || ($facts['receipt_status'] ?? null) === 'rejected'
            || ($facts['form_status'] ?? null) === 'rejected'
        ) {
            return [
                'decision' => 'rejected',
                'reason' => 'Opravný formulář JMHZ byl ČSSZ odmítnut.',
            ];
        }
        $transportProven = ($facts['transport_status'] ?? null) === 'completed'
            && is_string($facts['sent_at'] ?? null)
            && $facts['sent_at'] !== ''
            && is_string($facts['correlation_reference'] ?? null)
            && $facts['correlation_reference'] !== ''
            && is_string($facts['receipt_correlation_reference'] ?? null)
            && hash_equals(
                $facts['correlation_reference'],
                $facts['receipt_correlation_reference'],
            );
        $receiptProven = ($facts['verification_status'] ?? null) === 'trusted'
            && in_array(
                $facts['receipt_status'] ?? null,
                ['accepted', 'partially_accepted'],
                true,
            )
            && ($facts['form_status'] ?? null) === 'accepted';
        $submissionAccepted = in_array(
            $facts['submission_status'] ?? null,
            ['accepted', 'partially_accepted'],
            true,
        );
        if ($transportProven && $receiptProven && $submissionAccepted) {
            return ['decision' => 'accepted', 'reason' => null];
        }

        return [
            'decision' => 'pending',
            'reason' => 'Opravné JMHZ nemá úplný odesílací pokus a důvěryhodný přijatý výsledek vztahu.',
        ];
    }
}

