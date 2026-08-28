<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Security;

use MyInvoice\Repository\Payroll\PayrollPersonNotFoundException;
use MyInvoice\Repository\Payroll\PayrollPersonSensitiveRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\PermissionChecker;
use MyInvoice\Service\ActivityLogger;

final class PayrollPersonSensitiveRevealService
{
    public function __construct(
        private readonly PayrollPersonSensitiveRepository $repository,
        private readonly PayrollSensitiveData $sensitiveData,
        private readonly PermissionChecker $permissionChecker,
        private readonly ActivityLogger $activity,
    ) {}

    public function reveal(
        int $supplierId,
        int $employeeId,
        int $actorUserId,
        EffectiveRole $role,
        string $reason,
        ?string $ip = null,
        ?string $userAgent = null,
    ): PayrollPersonSensitiveReveal {
        $this->positive($supplierId, 'supplier_id');
        $this->positive($employeeId, 'employee_id');
        $this->positive($actorUserId, 'actor_user_id');
        $this->permissionChecker->require(
            $role,
            'payroll.person.read_sensitive',
            AccessLevel::READ,
        );
        $reason = $this->reason($reason);

        return $this->repository->transactional(function () use (
            $supplierId,
            $employeeId,
            $actorUserId,
            $reason,
            $ip,
            $userAgent,
        ): PayrollPersonSensitiveReveal {
            $profile = $this->repository->encryptedProfile(
                $supplierId,
                $employeeId,
            );
            if ($profile === null) {
                throw new PayrollPersonNotFoundException();
            }

            $identifiers = [];
            $identifierTypes = [];
            foreach ($profile['identifiers'] as $row) {
                $field = match ($row['identifier_type']) {
                    'birth_number', 'ecp', 'vcp' =>
                        PayrollSensitiveField::PERSONAL_IDENTIFIER,
                    'foreign_tax_identifier' =>
                        PayrollSensitiveField::FOREIGN_TAX_IDENTIFIER,
                    default => throw new \DomainException(
                        'Typ osobního identifikátoru není podporovaný.',
                    ),
                };
                $identifiers[] = [
                    'id' => $row['id'],
                    'identifier_type' => $row['identifier_type'],
                    'value' => $this->verifiedReveal(
                        $row['ciphertext'],
                        $row['lookup_hash'],
                        $field,
                        $supplierId,
                        $row['id'],
                    ),
                ];
                $identifierTypes[] = $row['identifier_type'];
            }

            $contacts = [];
            $contactTypes = [];
            foreach ($profile['contacts'] as $row) {
                $field = match ($row['contact_type']) {
                    'email' => PayrollSensitiveField::CONTACT_EMAIL,
                    'phone' => PayrollSensitiveField::CONTACT_PHONE,
                    default => throw new \DomainException(
                        'Typ kontaktu není podporovaný.',
                    ),
                };
                $contacts[] = [
                    'id' => $row['id'],
                    'contact_type' => $row['contact_type'],
                    'value' => $this->verifiedReveal(
                        $row['ciphertext'],
                        $row['lookup_hash'],
                        $field,
                        $supplierId,
                        $row['id'],
                    ),
                ];
                $contactTypes[] = $row['contact_type'];
            }

            $accounts = [];
            foreach ($profile['accounts'] as $row) {
                $accounts[] = [
                    'id' => $row['id'],
                    'label' => $row['label'],
                    'bank_account' => $this->verifiedReveal(
                        $row['ciphertext'],
                        $row['lookup_hash'],
                        PayrollSensitiveField::BANK_ACCOUNT,
                        $supplierId,
                        $row['id'],
                    ),
                ];
            }

            $dependants = [];
            foreach ($profile['dependants'] ?? [] as $row) {
                $dependants[] = [
                    'id' => $row['id'],
                    'full_name' => $row['full_name'],
                    'birth_number' => $this->verifiedReveal(
                        $row['ciphertext'],
                        $row['lookup_hash'],
                        PayrollSensitiveField::PERSONAL_IDENTIFIER,
                        $supplierId,
                        $row['id'],
                    ),
                ];
            }

            $result = new PayrollPersonSensitiveReveal(
                $employeeId,
                $identifiers,
                $contacts,
                $accounts,
                $dependants,
                $profile['addresses'] ?? [],
            );
            $this->activity->log(
                action: 'payroll.person_sensitive.revealed',
                userId: $actorUserId,
                entityType: 'payroll_employee',
                entityId: $employeeId,
                payload: [
                    'reason' => $reason,
                    'identifier_types' => array_values(array_unique(
                        $identifierTypes,
                    )),
                    'contact_types' => array_values(array_unique(
                        $contactTypes,
                    )),
                    'account_count' => count($accounts),
                ],
                ip: $ip,
                userAgent: $userAgent,
                supplierId: $supplierId,
            );

            return $result;
        });
    }

    private function verifiedReveal(
        string $ciphertext,
        string $expectedHash,
        PayrollSensitiveField $field,
        int $supplierId,
        int $entityId,
    ): string {
        $plaintext = $this->sensitiveData->reveal(
            $ciphertext,
            $field,
            $supplierId,
            $entityId,
            PayrollRevealPurpose::PERSON_SENSITIVE_REVEAL,
        );
        $actualHash = $this->sensitiveData->lookupHash(
            $plaintext,
            $field,
            $supplierId,
        );
        if (!hash_equals($expectedHash, $actualHash)) {
            throw new \DomainException(
                'Integrita citlivého mzdového údaje nebyla ověřena.',
            );
        }

        return $plaintext;
    }

    private function positive(int $value, string $field): void
    {
        if ($value <= 0) {
            throw new \InvalidArgumentException("{$field} musí být kladné.");
        }
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        $length = mb_strlen($reason, 'UTF-8');
        if ($length < 10 || $length > 500) {
            throw new \InvalidArgumentException(
                'Důvod odhalení musí mít 10 až 500 znaků.',
            );
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $reason) === 1) {
            throw new \InvalidArgumentException(
                'Důvod odhalení obsahuje řídicí znak.',
            );
        }

        return $reason;
    }
}
