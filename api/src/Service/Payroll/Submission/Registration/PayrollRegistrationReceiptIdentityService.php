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
            // Výjimka zůstává: doručenka je odpověď ČSSZ, ne uživatelský
            // vstup. Kdyby se z ní vzal „některý" výsledek, přiřadilo by se
            // ID PPV náhodnému pracovnímu vztahu.
            throw new \DomainException(
                'Odpověď ČSSZ na převod pod jiný variabilní symbol se '
                    . 'nepodařilo přiřadit k jednomu pracovnímu vztahu, takže '
                    . 'se z ní nový identifikátor nenačte. Ověřte výsledek '
                    . 'na portálu ČSSZ a ozvěte se podpoře.',
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
                'Identifikátor pracovního vztahu od ČSSZ (ID PPV) u tohoto '
                    . 'vztahu chybí, takže není co převádět. Nejdřív načtěte '
                    . 'odpověď ČSSZ na původní přihlášení zaměstnance.',
            );
        }
        $effectiveOn = new \DateTimeImmutable((string) $outcome['effective_on']);
        if ($effectiveOn->format('Y-m-d') <= (string) $active['valid_from']) {
            // Výjimka zůstává: opačné pořadí platnosti by v evidenci
            // nechalo dvě současně platná ID PPV pro jeden vztah.
            throw new \DomainException(
                'Nový identifikátor pracovního vztahu od ČSSZ (ID PPV) by '
                    . 'začal platit dřív než ten původní, takže by se oba '
                    . 'překrývaly. Zkontrolujte den účinnosti převodu na '
                    . 'portálu ČSSZ.',
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
