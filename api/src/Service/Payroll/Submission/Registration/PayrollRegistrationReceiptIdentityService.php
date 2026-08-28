<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use MyInvoice\Repository\Payroll\PayrollRegistrationIdentityRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationSubmissionRepository;

final readonly class PayrollRegistrationReceiptIdentityService
{
    public function __construct(
        private PayrollRegistrationSubmissionRepository $submissions,
        private PayrollRegistrationIdentityRepository $identities,
        private PayrollRegistrationIdentityService $identityService,
    ) {}

    public function applyAcceptedVariableSymbolTransfer(
        int $supplierId,
        string $environment,
        int $submissionId,
        int $receiptId,
        ?int $actorId,
    ): bool {
        $outcomes = $this->submissions->acceptedVariableSymbolTransferOutcomes(
            $supplierId,
            $environment,
            $submissionId,
            $receiptId,
        );
        if ($outcomes === []) {
            return false;
        }
        if (count($outcomes) !== 1) {
            throw new \DomainException(
                'Doručenka převodu variabilního symbolu nemá právě jeden přijatý výsledek.',
            );
        }
        $outcome = $outcomes[0];
        $employmentId = (int) $outcome['employment_id'];
        $existing = $this->identities->externalIdFromReceipt(
            $supplierId,
            $employmentId,
            $environment,
            $receiptId,
        );
        if ($existing !== null) {
            return false;
        }
        $active = $this->identities->activeExternalId(
            $supplierId,
            $employmentId,
            $environment,
            'id_ppv',
        );
        if ($active === null) {
            throw new \DomainException(
                'Převod variabilního symbolu nemá původní aktivní ID PPV.',
            );
        }
        $effectiveOn = new \DateTimeImmutable((string) $outcome['effective_on']);
        if ($effectiveOn->format('Y-m-d') <= (string) $active['valid_from']) {
            throw new \DomainException(
                'Nové ID PPV musí začít platit po vzniku původního ID PPV.',
            );
        }
        $this->identities->closeExternalId(
            $supplierId,
            (int) $active['id'],
            (int) $active['row_version'],
            $effectiveOn->modify('-1 day')->format('Y-m-d'),
            $actorId,
        );
        $this->identityService->assignEmploymentExternalId(
            $supplierId,
            $employmentId,
            $environment,
            (string) $outcome['external_employment_reference'],
            $effectiveOn->format('Y-m-d'),
            'trusted_receipt',
            sprintf(
                'regzec-a5:submission:%d:form:%s',
                $submissionId,
                (string) $outcome['form_guid'],
            ),
            $receiptId,
            $actorId,
        );

        return true;
    }

    public function applyAcceptedEmploymentRegistration(
        int $supplierId,
        string $environment,
        int $submissionId,
        int $receiptId,
        ?int $actorId,
    ): bool {
        $outcomes = $this->submissions->acceptedEmploymentRegistrationOutcomes(
            $supplierId,
            $environment,
            $submissionId,
            $receiptId,
            PayrollEmployeeRegistrationDeadlinePolicy::REGISTRATION_RULESET_ID,
        );
        if ($outcomes === []) {
            return false;
        }
        $changed = false;
        foreach ($outcomes as $outcome) {
            $employeeId = (int) $outcome['employee_id'];
            $employmentId = (int) $outcome['employment_id'];
            $personReference = (string) $outcome['external_person_reference'];
            $employmentReference =
                (string) $outcome['external_employment_reference'];
            $personMatches =
                $this->identityService->activePersonExternalIdMatches(
                    $supplierId,
                    $employeeId,
                    $environment,
                    $personReference,
                );
            if ($personMatches === false) {
                continue;
            }
            $employmentMatches =
                $this->identityService->activeEmploymentExternalIdMatches(
                    $supplierId,
                    $employmentId,
                    $environment,
                    $employmentReference,
                );
            if ($employmentMatches === false) {
                continue;
            }
            $effectiveOn = (string) $outcome['effective_on'];
            $sourceReference = sprintf(
                'registration:submission:%d:form:%s',
                $submissionId,
                (string) $outcome['form_guid'],
            );
            if ($personMatches === null) {
                $this->identityService->assignPersonExternalId(
                    $supplierId,
                    $employeeId,
                    $environment,
                    $personReference,
                    $effectiveOn,
                    'trusted_receipt',
                    $sourceReference,
                    $receiptId,
                    $actorId,
                );
                $changed = true;
            }
            if ($employmentMatches === null) {
                $this->identityService->assignEmploymentExternalId(
                    $supplierId,
                    $employmentId,
                    $environment,
                    $employmentReference,
                    $effectiveOn,
                    'trusted_receipt',
                    $sourceReference,
                    $receiptId,
                    $actorId,
                );
                $changed = true;
            }
        }

        return $changed;
    }
}
