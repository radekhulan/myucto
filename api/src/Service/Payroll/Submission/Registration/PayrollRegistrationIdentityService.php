<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use MyInvoice\Repository\Payroll\PayrollRegistrationIdentityRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;

final readonly class PayrollRegistrationIdentityService
{
    private const ENVIRONMENTS = ['production', 'test'];
    private const TASK_KINDS = [
        'person_identity',
        'employment_external_id',
    ];
    private const SOURCE_KINDS = [
        'trusted_receipt',
        'verified_manual_import',
    ];

    public function __construct(
        private PayrollRegistrationIdentityRepository $repository,
        private PayrollSensitiveData $sensitiveData,
    ) {}

    /**
     * Interní citlivý snapshot; nesmí být vrácen běžným listovacím endpointem.
     *
     * @return array{
     *   identity:array<string,mixed>,
     *   identifiers:array{
     *     birth_number:?string,ecp:?string,vcp:?string,
     *     foreign_tax_identifier:?string
     *   },
     *   identifier_sources:array<string,array{id:int,row_version:int}>
     * }
     */
    public function sensitiveIdentityAt(
        int $supplierId,
        int $employeeId,
        string $onDate,
    ): array {
        return $this->sensitiveIdentityAtInternal(
            $supplierId,
            $employeeId,
            $onDate,
            false,
        );
    }

    /** @return array<string,mixed>|null */
    public function a1Profile(
        int $supplierId,
        int $employmentId,
    ): ?array {
        $this->positive($supplierId, 'Firma');
        $this->positive($employmentId, 'Pracovní vztah');

        return $this->repository->transaction(function () use (
            $supplierId,
            $employmentId,
        ): ?array {
            $employment = $this->repository->lockEmployment(
                $supplierId,
                $employmentId,
            );
            if ($employment === null) {
                throw new \OutOfBoundsException('Pracovní vztah nebyl nalezen.');
            }
            $stored = $this->repository->latestA1Profile(
                $supplierId,
                $employment['employee_id'],
                $employmentId,
            );

            return $stored === null ? null : $this->publicA1Profile(
                $stored,
                $this->decodeA1Profile($stored),
                false,
            );
        });
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function saveA1Profile(
        int $supplierId,
        int $employmentId,
        array $input,
        ?int $createdBy,
    ): array {
        $this->positive($supplierId, 'Firma');
        $this->positive($employmentId, 'Pracovní vztah');
        $expectedVersion = $input['row_version'] ?? null;
        if (!is_int($expectedVersion) || $expectedVersion < 0) {
            throw new \InvalidArgumentException(
                'row_version profilu REGZEC A1 musí být nezáporné celé číslo.',
            );
        }
        $effectiveOn = $input['effective_on'] ?? null;
        if (!is_string($effectiveOn)) {
            throw new \InvalidArgumentException(
                'Profil REGZEC A1 vyžaduje rozhodné datum.',
            );
        }
        $this->date($effectiveOn, 'Rozhodné datum profilu REGZEC A1');
        unset($input['row_version'], $input['created_at'], $input['reference_hash']);

        return $this->repository->transaction(function () use (
            $supplierId,
            $employmentId,
            $input,
            $effectiveOn,
            $expectedVersion,
            $createdBy,
        ): array {
            $employment = $this->repository->lockEmployment(
                $supplierId,
                $employmentId,
            );
            if ($employment === null) {
                throw new \OutOfBoundsException('Pracovní vztah nebyl nalezen.');
            }
            $employeeId = $employment['employee_id'];
            $registrationOn = $employment['actual_start_date']
                ?? $employment['start_date'];
            if ($registrationOn !== $effectiveOn) {
                throw new PayrollRegistrationIdentitySnapshotException(
                    'registration_regzec_a1_source_scope_mismatch',
                    'Rozhodné datum profilu REGZEC A1 musí odpovídat skutečnému datu nástupu pracovního vztahu.',
                );
            }
            $current = $this->repository->latestA1Profile(
                $supplierId,
                $employeeId,
                $employmentId,
                true,
            );
            $currentVersion = (int) ($current['row_version'] ?? 0);
            if ($expectedVersion !== $currentVersion) {
                throw new PayrollRegistrationIdentitySnapshotException(
                    'registration_regzec_a1_profile_conflict',
                    'Profil REGZEC A1 mezitím změnil jiný uživatel. Načtěte jej znovu.',
                );
            }
            $identity = $this->sensitiveIdentityAtInternal(
                $supplierId,
                $employeeId,
                $effectiveOn,
                true,
            )['identity'];
            $scope = [
                'supplier_id' => $supplierId,
                'employee_id' => $employeeId,
                'employment_id' => $employmentId,
                'effective_on' => $effectiveOn,
            ];
            $provisional = array_merge($input, ['source' => [
                'source_key' => 'payroll_registration_a1_profile',
                'source_id' => 1,
                'row_version' => $currentVersion + 1,
                'reference_hash' => str_repeat('0', 64),
                ...$scope,
            ]]);
            $snapshot = (new PayrollRegistrationA1SnapshotBuilder())->build(
                $provisional,
                $identity,
                $scope,
            );
            $profile = $this->a1ProfileData($snapshot);
            $canonical = CanonicalJson::encode($profile);
            $referenceHash = $this->sensitiveData->keyedFingerprint(
                $canonical,
                'registration-a1-profile',
                $supplierId,
            );
            if ($current !== null
                && hash_equals($current['reference_hash'], $referenceHash)
            ) {
                return $this->publicA1Profile($current, $profile, false);
            }
            $sealed = $this->sensitiveData->seal(
                $canonical,
                PayrollSensitiveField::REGISTRATION_A1_PROFILE,
                $supplierId,
                $employmentId,
            );
            $rowVersion = $currentVersion + 1;
            $id = $this->repository->insertA1Profile(
                $supplierId,
                $employeeId,
                $employmentId,
                $effectiveOn,
                $sealed->ciphertext,
                $sealed->lookupHash,
                $referenceHash,
                $rowVersion,
                $createdBy,
            );
            $stored = [
                'id' => $id,
                'supplier_id' => $supplierId,
                'employee_id' => $employeeId,
                'employment_id' => $employmentId,
                'effective_on' => $effectiveOn,
                'reference_hash' => $referenceHash,
                'row_version' => $rowVersion,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ];

            return $this->publicA1Profile($stored, $profile, true);
        });
    }

    /**
     * Interní citlivý zdroj pro neměnný submission snapshot.
     *
     * @return array{
     *   identity:array<string,mixed>,
     *   identifiers:array{
     *     birth_number:?string,ecp:?string,vcp:?string,
     *     foreign_tax_identifier:?string
     *   },
     *   identifier_sources:array<string,array{id:int,row_version:int}>,
     *   employment_external_identifier:?array{
     *     id:int,employee_id:int,employment_id:int,environment:string,
     *     identifier_type:string,value:string,valid_from:string,
     *     valid_to:?string,source_kind:string,source_receipt_id:?int,
     *     source_reference_hash:string,row_version:int
     *   },
     *   resolution:array{
     *     person_identity:string,employment_external_id:string
     *   }
     * }
     */
    public function sensitiveSnapshotSourceAt(
        int $supplierId,
        int $employeeId,
        int $employmentId,
        string $environment,
        string $onDate,
    ): array {
        $this->positive($supplierId, 'Firma');
        $this->positive($employeeId, 'Osoba');
        $this->positive($employmentId, 'Pracovní vztah');
        $this->environment($environment);
        $this->date($onDate, 'Rozhodné datum');

        return $this->repository->transaction(function () use (
            $supplierId,
            $employeeId,
            $employmentId,
            $environment,
            $onDate,
        ): array {
            $employment = $this->repository->lockEmployment(
                $supplierId,
                $employmentId,
            );
            if ($employment === null
                || $employment['employee_id'] !== $employeeId
            ) {
                throw new PayrollRegistrationIdentitySnapshotException(
                    'registration_identity_employment_scope_mismatch',
                    'Pracovní vztah nepatří stejné firmě a osobě.',
                );
            }
            if ($employment['start_date'] === null) {
                throw new PayrollRegistrationIdentitySnapshotException(
                    'registration_identity_employment_scope_mismatch',
                    'Pracovní vztah nemá doplněné datum nástupu.',
                );
            }
            if ($onDate < $employment['start_date']
                || ($employment['end_date'] !== null
                    && $onDate > $employment['end_date'])
            ) {
                throw new PayrollRegistrationIdentitySnapshotException(
                    'registration_identity_employment_scope_mismatch',
                    'Pracovní vztah není účinný k rozhodnému datu.',
                );
            }
            $tasks = $this->repository->activeResolutionTaskKinds(
                $supplierId,
                $employmentId,
                $environment,
                true,
            );
            if ($tasks !== []) {
                throw new PayrollRegistrationIdentitySnapshotException(
                    'registration_identity_unresolved',
                    'Registrační identita má otevřený úkol ztotožnění.',
                );
            }
            $person = $this->sensitiveIdentityAtInternal(
                $supplierId,
                $employeeId,
                $onDate,
                true,
            );
            $storedExternal = $this->repository->externalIdAt(
                $supplierId,
                $employmentId,
                $environment,
                'id_ppv',
                $onDate,
                true,
            );
            $external = null;
            if ($storedExternal !== null) {
                if ($storedExternal['employee_id'] !== $employeeId) {
                    throw new PayrollRegistrationIdentitySnapshotException(
                        'registration_identity_id_ppv_scope_mismatch',
                        'ID PPV patří jiné osobě.',
                    );
                }
                $plaintext = $this->sensitiveData->reveal(
                    $storedExternal['value_ciphertext'],
                    PayrollSensitiveField::EMPLOYMENT_EXTERNAL_IDENTIFIER,
                    $supplierId,
                    $storedExternal['id'],
                );
                $hash = $this->sensitiveData->lookupHash(
                    $plaintext,
                    PayrollSensitiveField::EMPLOYMENT_EXTERNAL_IDENTIFIER,
                    $supplierId,
                );
                if (!hash_equals($storedExternal['value_hash'], $hash)) {
                    throw new \RuntimeException(
                        'Otisk ID PPV neodpovídá ciphertextu.',
                    );
                }
                $external = [
                    'id' => $storedExternal['id'],
                    'employee_id' => $storedExternal['employee_id'],
                    'employment_id' => $storedExternal['employment_id'],
                    'environment' => $storedExternal['environment'],
                    'identifier_type' =>
                        $storedExternal['identifier_type'],
                    'value' => $plaintext,
                    'valid_from' => $storedExternal['valid_from'],
                    'valid_to' => $storedExternal['valid_to'],
                    'source_kind' => $storedExternal['source_kind'],
                    'source_receipt_id' =>
                        $storedExternal['source_receipt_id'],
                    'source_reference_hash' =>
                        $storedExternal['source_reference_hash'],
                    'row_version' => $storedExternal['row_version'],
                ];
            }

            $result = [
                'identity' => $person['identity'],
                'identifiers' => $person['identifiers'],
                'identifier_sources' => $person['identifier_sources'],
                'employment_external_identifier' => $external,
                'resolution' => [
                    'person_identity' => 'resolved',
                    'employment_external_id' => $external === null
                        ? 'not_assigned'
                        : 'resolved',
                ],
            ];
            $a1Stored = $this->repository->latestA1Profile(
                $supplierId,
                $employeeId,
                $employmentId,
                true,
            );
            if ($a1Stored !== null
                && $a1Stored['effective_on'] === $onDate
            ) {
                $result['regzec_a1'] = array_merge(
                    $this->decodeA1Profile($a1Stored),
                    ['source' => $this->a1Source($a1Stored)],
                );
            }

            return $result;
        });
    }

    /**
     * @return array{
     *   environment:string,
     *   person_external_identifier:array{
     *     id:int,identifier_type:string,value:string,valid_from:string,
     *     valid_to:?string,source_kind:string,source_receipt_id:?int,
     *     source_reference_hash:string,row_version:int
     *   },
     *   employment_external_identifier:array{
     *     id:int,identifier_type:string,value:string,valid_from:string,
     *     valid_to:?string,source_kind:string,source_receipt_id:?int,
     *     source_reference_hash:string,row_version:int
     *   }
     * }
     */
    public function sensitiveJmhzIdentityAt(
        int $supplierId,
        int $employeeId,
        int $employmentId,
        string $environment,
        string $onDate,
        bool $requireTrustedReceipt = false,
    ): array {
        $this->positive($supplierId, 'Firma');
        $this->positive($employeeId, 'Osoba');
        $this->positive($employmentId, 'Pracovní vztah');
        $this->environment($environment);
        $this->date($onDate, 'Rozhodné datum');

        return $this->repository->transaction(function () use (
            $supplierId,
            $employeeId,
            $employmentId,
            $environment,
            $onDate,
            $requireTrustedReceipt,
        ): array {
            $employment = $this->repository->lockEmployment(
                $supplierId,
                $employmentId,
            );
            if ($employment === null || $employment['employee_id'] !== $employeeId) {
                throw new PayrollRegistrationIdentitySnapshotException(
                    'jmhz_identity_employment_scope_mismatch',
                    'Pracovní vztah nepatří stejné firmě a osobě.',
                );
            }
            if ($employment['start_date'] === null) {
                throw new PayrollRegistrationIdentitySnapshotException(
                    'jmhz_identity_employment_scope_mismatch',
                    'Pracovní vztah nemá doplněné datum nástupu.',
                );
            }
            if ($onDate < $employment['start_date']
                || ($employment['end_date'] !== null && $onDate > $employment['end_date'])
            ) {
                throw new PayrollRegistrationIdentitySnapshotException(
                    'jmhz_identity_employment_scope_mismatch',
                    'Pracovní vztah není účinný k rozhodnému datu.',
                );
            }
            if ($this->repository->activeResolutionTaskKinds(
                $supplierId,
                $employmentId,
                $environment,
                true,
            ) !== []) {
                throw new PayrollRegistrationIdentitySnapshotException(
                    'jmhz_identity_unresolved',
                    'Identita JMHZ má otevřený úkol ztotožnění.',
                );
            }
            $person = $this->repository->personExternalIdAt(
                $supplierId,
                $employeeId,
                $environment,
                'ik_mpsv',
                $onDate,
                true,
            );
            if ($person === null) {
                throw new PayrollRegistrationIdentitySnapshotException(
                    'jmhz_identity_oic_missing',
                    'Pro osobu chybí k rozhodnému datu OIČ / IK MPSV.',
                );
            }
            $employmentExternal = $this->repository->externalIdAt(
                $supplierId,
                $employmentId,
                $environment,
                'id_ppv',
                $onDate,
                true,
            );
            if ($employmentExternal === null) {
                throw new PayrollRegistrationIdentitySnapshotException(
                    'jmhz_identity_id_ppv_missing',
                    'Pro pracovní vztah chybí k rozhodnému datu ID PPV.',
                );
            }
            $personIdentifier = $this->revealExternalIdentifier(
                $person,
                PayrollSensitiveField::PERSON_EXTERNAL_IDENTIFIER,
                $supplierId,
            );
            $employmentIdentifier = $this->revealExternalIdentifier(
                $employmentExternal,
                PayrollSensitiveField::EMPLOYMENT_EXTERNAL_IDENTIFIER,
                $supplierId,
            );
            self::oic($personIdentifier['value']);
            self::idPpv($employmentIdentifier['value']);
            if ($requireTrustedReceipt) {
                $this->assertTrustedReceiptSource(
                    $personIdentifier,
                    $supplierId,
                    $environment,
                    $employeeId,
                    $employmentId,
                    'ik_mpsv',
                    'registration_a2_oic_provenance_invalid',
                    'OIČ / IK MPSV',
                );
                $this->assertTrustedReceiptSource(
                    $employmentIdentifier,
                    $supplierId,
                    $environment,
                    $employeeId,
                    $employmentId,
                    'id_ppv',
                    'registration_a2_id_ppv_provenance_invalid',
                    'ID PPV',
                );
            }

            return [
                'environment' => $environment,
                'person_external_identifier' => $personIdentifier,
                'employment_external_identifier' => $employmentIdentifier,
            ];
        });
    }

    /**
     * @param array{
     *   id:int,identifier_type:string,value:string,valid_from:string,
     *   valid_to:?string,source_kind:string,source_receipt_id:?int,
     *   source_reference_hash:string,row_version:int
     * } $identifier
     */
    private function assertTrustedReceiptSource(
        array $identifier,
        int $supplierId,
        string $environment,
        int $employeeId,
        int $employmentId,
        string $identifierType,
        string $validationCode,
        string $label,
    ): void {
        $receiptId = $identifier['source_receipt_id'];
        if ($identifier['source_kind'] !== 'trusted_receipt'
            || $receiptId === null
            || !$this->repository->hasAcceptedRegistrationIdentifierReceipt(
                $supplierId,
                $environment,
                $receiptId,
                $employeeId,
                $employmentId,
                $identifierType,
                $identifier['value'],
                PayrollEmployeeRegistrationDeadlinePolicy::REGISTRATION_RULESET_ID,
            )
        ) {
            throw new PayrollRegistrationIdentitySnapshotException(
                $validationCode,
                "REGZEC A2 vyžaduje {$label} z důvěryhodného protokolu stejné firmy a prostředí.",
            );
        }
    }

    /**
     * @return array{
     *   identity:array<string,mixed>,
     *   identifiers:array{
     *     birth_number:?string,ecp:?string,vcp:?string,
     *     foreign_tax_identifier:?string
     *   },
     *   identifier_sources:array<string,array{id:int,row_version:int}>
     * }
     */
    private function sensitiveIdentityAtInternal(
        int $supplierId,
        int $employeeId,
        string $onDate,
        bool $forUpdate,
    ): array {
        $this->positive($supplierId, 'Firma');
        $this->positive($employeeId, 'Osoba');
        $this->date($onDate, 'Rozhodné datum');
        $identity = $this->repository->identityAt(
            $supplierId,
            $employeeId,
            $onDate,
            $forUpdate,
        );
        if ($identity === null) {
            throw new \DomainException(
                'K rozhodnému datu chybí historická identita osoby.',
            );
        }
        if ($this->nullableText($identity['first_name'] ?? null) === null
            || $this->nullableText($identity['last_name'] ?? null) === null
        ) {
            throw new \DomainException(
                'Historická identita nemá explicitní jméno a příjmení.',
            );
        }

        $identifiers = [
            'birth_number' => null,
            'ecp' => null,
            'vcp' => null,
            'foreign_tax_identifier' => null,
        ];
        $identifierSources = [];
        foreach ($this->repository->identifiers(
            $supplierId,
            $employeeId,
            $forUpdate,
        ) as $stored) {
            $type = $stored['identifier_type'];
            if (!array_key_exists($type, $identifiers)) {
                throw new \UnexpectedValueException(
                    'Osoba obsahuje nepodporovaný typ identifikátoru.',
                );
            }
            $field = $type === 'foreign_tax_identifier'
                ? PayrollSensitiveField::FOREIGN_TAX_IDENTIFIER
                : PayrollSensitiveField::PERSONAL_IDENTIFIER;
            $plaintext = $this->sensitiveData->reveal(
                $stored['value_ciphertext'],
                $field,
                $supplierId,
                $stored['id'],
            );
            $hash = $this->sensitiveData->lookupHash(
                $plaintext,
                $field,
                $supplierId,
            );
            if (!hash_equals($stored['value_hash'], $hash)) {
                throw new \RuntimeException(
                    'Otisk osobního identifikátoru neodpovídá ciphertextu.',
                );
            }
            $identifiers[$type] = $plaintext;
            $identifierSources[$type] = [
                'id' => $stored['id'],
                'row_version' => $stored['row_version'],
            ];
        }
        ksort($identifierSources, SORT_STRING);

        return [
            'identity' => $identity,
            'identifiers' => $identifiers,
            'identifier_sources' => $identifierSources,
        ];
    }

    /**
     * Bezpečný stav pro UI: identifikátory nikdy nevrací v otevřené podobě.
     *
     * @return array{
     *   employee_id:int,employment_id:int,environment:string,on_date:string,
     *   person_external_identifier:?array{
     *     id:int,value_masked:string,valid_from:string,valid_to:?string,
     *     source_kind:string,row_version:int
     *   },
     *   employment_external_identifier:?array{
     *     id:int,value_masked:string,valid_from:string,valid_to:?string,
     *     source_kind:string,row_version:int
     *   }
     * }
     */
    public function jmhzIdentityStatusAt(
        int $supplierId,
        int $employmentId,
        string $environment,
        string $onDate,
    ): array {
        $this->positive($supplierId, 'Firma');
        $this->positive($employmentId, 'Pracovní vztah');
        $this->environment($environment);
        $this->date($onDate, 'Rozhodné datum');

        $employment = $this->repository->employment(
            $supplierId,
            $employmentId,
        );
        if ($employment === null) {
            throw new \OutOfBoundsException(
                'Pracovní vztah nebyl nalezen ve stejné firmě.',
            );
        }
        if ($employment['start_date'] === null) {
            throw new \InvalidArgumentException(
                'Pracovní vztah nemá doplněné datum nástupu.',
            );
        }
        if ($onDate < $employment['start_date']
            || ($employment['end_date'] !== null
                && $onDate > $employment['end_date'])
        ) {
            throw new \InvalidArgumentException(
                'Rozhodné datum neleží v období pracovního vztahu.',
            );
        }

        $person = $this->repository->personExternalIdAt(
            $supplierId,
            $employment['employee_id'],
            $environment,
            'ik_mpsv',
            $onDate,
        );
        $employmentExternal = $this->repository->externalIdAt(
            $supplierId,
            $employmentId,
            $environment,
            'id_ppv',
            $onDate,
        );

        return [
            'employee_id' => $employment['employee_id'],
            'employment_id' => $employmentId,
            'environment' => $environment,
            'on_date' => $onDate,
            'person_external_identifier' => $person === null
                ? null
                : $this->maskedExternalIdentifier($person),
            'employment_external_identifier' => $employmentExternal === null
                ? null
                : $this->maskedExternalIdentifier($employmentExternal),
        ];
    }

    /**
     * Ruční doplnění z karty vztahu. Obě hodnoty se ukládají v jedné
     * transakci; již existující část dvojice lze vynechat.
     *
     * @return array{
     *   person_external_identifier:?array<string,mixed>,
     *   employment_external_identifier:?array<string,mixed>
     * }
     */
    public function assignManualJmhzIdentity(
        int $supplierId,
        int $employmentId,
        string $environment,
        ?string $personIdentifier,
        ?string $employmentIdentifier,
        string $validFrom,
        ?string $sourceReference,
        bool $evidenceConfirmed,
        ?int $createdBy,
    ): array {
        $this->positive($supplierId, 'Firma');
        $this->positive($employmentId, 'Pracovní vztah');
        $this->environment($environment);
        $this->date($validFrom, 'Platnost identifikátorů');
        $this->optionalPositive($createdBy, 'Uživatel');
        if (!$evidenceConfirmed) {
            throw new \InvalidArgumentException(
                'Potvrďte, že jste identifikátory ověřili v podkladu ČSSZ.',
            );
        }
        $personIdentifier = $this->nullableText($personIdentifier);
        $employmentIdentifier = $this->nullableText($employmentIdentifier);
        if ($personIdentifier === null && $employmentIdentifier === null) {
            throw new \InvalidArgumentException(
                'Doplňte OIČ / IK MPSV nebo ID PPV.',
            );
        }
        $personIdentifier = $personIdentifier === null
            ? null
            : self::oic($personIdentifier);
        $employmentIdentifier = $employmentIdentifier === null
            ? null
            : self::idPpv($employmentIdentifier);
        $reference = $this->evidenceReference(
            $this->nullableText($sourceReference)
                ?? "manual-jmhz-identity:employment:{$employmentId}",
        );

        return $this->repository->transaction(function () use (
            $supplierId,
            $employmentId,
            $environment,
            $personIdentifier,
            $employmentIdentifier,
            $validFrom,
            $reference,
            $createdBy,
        ): array {
            $employment = $this->repository->lockEmployment(
                $supplierId,
                $employmentId,
            );
            if ($employment === null) {
                throw new \OutOfBoundsException(
                    'Pracovní vztah nebyl nalezen ve stejné firmě.',
                );
            }
            if ($employment['start_date'] === null) {
                throw new \InvalidArgumentException(
                    'Pracovní vztah nemá doplněné datum nástupu.',
                );
            }
            if ($validFrom < $employment['start_date']
                || ($employment['end_date'] !== null
                    && $validFrom > $employment['end_date'])
            ) {
                throw new \InvalidArgumentException(
                    'Platnost identifikátorů neleží v období pracovního vztahu.',
                );
            }

            return [
                'person_external_identifier' => $personIdentifier === null
                    ? null
                    : $this->assignPersonExternalId(
                        $supplierId,
                        $employment['employee_id'],
                        $environment,
                        $personIdentifier,
                        $validFrom,
                        'verified_manual_import',
                        $reference,
                        null,
                        $createdBy,
                    ),
                'employment_external_identifier' => $employmentIdentifier === null
                    ? null
                    : $this->assignEmploymentExternalId(
                        $supplierId,
                        $employmentId,
                        $environment,
                        $employmentIdentifier,
                        $validFrom,
                        'verified_manual_import',
                        $reference,
                        null,
                        $createdBy,
                    ),
            ];
        });
    }

    /**
     * @param array{
     *   title_prefix?:?string,title_suffix?:?string,birth_date?:?string,
     *   birth_place?:?string,birth_country_code?:?string,
     *   citizenship_country_code?:?string,sex?:?string
     * } $facts
     */
    public function saveIdentityFacts(
        int $supplierId,
        int $employeeId,
        int $identityId,
        int $expectedRowVersion,
        array $facts,
    ): int {
        $this->positive($supplierId, 'Firma');
        $this->positive($employeeId, 'Osoba');
        $this->positive($identityId, 'Historická identita');
        $this->positive($expectedRowVersion, 'Verze identity');
        $normalized = [
            'title_prefix' => $this->optionalText(
                $facts,
                'title_prefix',
                64,
            ),
            'title_suffix' => $this->optionalText(
                $facts,
                'title_suffix',
                64,
            ),
            'birth_date' => $this->optionalDate($facts, 'birth_date'),
            'birth_place' => $this->optionalText(
                $facts,
                'birth_place',
                128,
            ),
            'birth_country_code' => $this->optionalCountry(
                $facts,
                'birth_country_code',
            ),
            'citizenship_country_code' => $this->optionalCountry(
                $facts,
                'citizenship_country_code',
            ),
            'sex' => $this->optionalEnum(
                $facts,
                'sex',
                ['female', 'male', 'unspecified'],
            ),
        ];

        return $this->repository->updateIdentityFacts(
            $supplierId,
            $employeeId,
            $identityId,
            $expectedRowVersion,
            $normalized,
        );
    }

    /**
     * @return array{
     *   id:int,employee_id:int,environment:string,identifier_type:string,
     *   value_masked:string,valid_from:string,row_version:int,created:bool
     * }
     */
    public function assignPersonExternalId(
        int $supplierId,
        int $employeeId,
        string $environment,
        string $value,
        string $validFrom,
        string $sourceKind,
        string $sourceReference,
        ?int $sourceReceiptId,
        ?int $createdBy,
    ): array {
        $this->positive($supplierId, 'Firma');
        $this->positive($employeeId, 'Osoba');
        $this->environment($environment);
        $this->date($validFrom, 'Platnost OIČ');
        $this->allowed($sourceKind, self::SOURCE_KINDS, 'Zdroj OIČ');
        $this->optionalPositive($sourceReceiptId, 'Protokol');
        $this->optionalPositive($createdBy, 'Uživatel');
        if (($sourceKind === 'trusted_receipt') !== ($sourceReceiptId !== null)) {
            throw new \InvalidArgumentException(
                'Trusted OIČ musí odkazovat na ověřený protokol.',
            );
        }
        $normalizedValue = self::oic($value);
        $sourceHash = $this->sensitiveData->keyedFingerprint(
            $this->evidenceReference($sourceReference),
            'person-external-id-source',
            $supplierId,
        );

        return $this->repository->transaction(function () use (
            $supplierId,
            $employeeId,
            $environment,
            $normalizedValue,
            $validFrom,
            $sourceKind,
            $sourceReceiptId,
            $sourceHash,
            $createdBy,
        ): array {
            if (!$this->repository->lockEmployee($supplierId, $employeeId)) {
                throw new \DomainException('Osoba nebyla nalezena ve stejné firmě.');
            }
            if ($sourceReceiptId !== null
                && !$this->repository->hasTrustedReceipt(
                    $supplierId,
                    $environment,
                    $sourceReceiptId,
                )
            ) {
                throw new \DomainException(
                    'Zdrojový protokol není důvěryhodný nebo patří jinému prostředí.',
                );
            }
            $existing = $this->repository->activePersonExternalId(
                $supplierId,
                $employeeId,
                $environment,
                'ik_mpsv',
            );
            if ($existing !== null) {
                $plaintext = $this->sensitiveData->reveal(
                    $existing['value_ciphertext'],
                    PayrollSensitiveField::PERSON_EXTERNAL_IDENTIFIER,
                    $supplierId,
                    $existing['id'],
                );
                $storedHash = $this->sensitiveData->lookupHash(
                    $plaintext,
                    PayrollSensitiveField::PERSON_EXTERNAL_IDENTIFIER,
                    $supplierId,
                );
                if (!hash_equals($existing['value_hash'], $storedHash)) {
                    throw new \RuntimeException('Otisk OIČ neodpovídá ciphertextu.');
                }
                $inputHash = $this->sensitiveData->lookupHash(
                    $normalizedValue,
                    PayrollSensitiveField::PERSON_EXTERNAL_IDENTIFIER,
                    $supplierId,
                );
                if (!hash_equals($existing['value_hash'], $inputHash)) {
                    throw new \DomainException('Osoba už má jiné aktivní OIČ.');
                }
                if ($existing['valid_from'] !== $validFrom
                    || $existing['source_kind'] !== $sourceKind
                    || $existing['source_receipt_id'] !== $sourceReceiptId
                    || !hash_equals($existing['source_reference_hash'], $sourceHash)
                ) {
                    throw new \DomainException(
                        'Opakované uložení OIČ neodpovídá původnímu datu nebo důkazu.',
                    );
                }

                return [
                    'id' => $existing['id'],
                    'employee_id' => $existing['employee_id'],
                    'environment' => $existing['environment'],
                    'identifier_type' => $existing['identifier_type'],
                    'value_masked' => $existing['value_masked'],
                    'valid_from' => $existing['valid_from'],
                    'row_version' => $existing['row_version'],
                    'created' => false,
                ];
            }

            $id = $this->repository->insertPersonExternalIdPlaceholder(
                $supplierId,
                $employeeId,
                $environment,
                'ik_mpsv',
                $validFrom,
                $sourceKind,
                $sourceReceiptId,
                $sourceHash,
                $createdBy,
            );
            $sealed = $this->sensitiveData->seal(
                $normalizedValue,
                PayrollSensitiveField::PERSON_EXTERNAL_IDENTIFIER,
                $supplierId,
                $id,
            );
            $this->repository->sealPersonExternalId(
                $supplierId,
                $id,
                $sealed->ciphertext,
                $sealed->lookupHash,
                $sealed->masked,
            );

            return [
                'id' => $id,
                'employee_id' => $employeeId,
                'environment' => $environment,
                'identifier_type' => 'ik_mpsv',
                'value_masked' => $sealed->masked,
                'valid_from' => $validFrom,
                'row_version' => 1,
                'created' => true,
            ];
        });
    }

    public function activePersonExternalIdMatches(
        int $supplierId,
        int $employeeId,
        string $environment,
        string $value,
    ): ?bool {
        $this->positive($supplierId, 'Firma');
        $this->positive($employeeId, 'Osoba');
        $this->environment($environment);
        $normalizedValue = self::oic($value);

        return $this->repository->transaction(function () use (
            $supplierId,
            $employeeId,
            $environment,
            $normalizedValue,
        ): ?bool {
            $existing = $this->repository->activePersonExternalId(
                $supplierId,
                $employeeId,
                $environment,
                'ik_mpsv',
            );
            if ($existing === null) {
                return null;
            }
            $plaintext = $this->sensitiveData->reveal(
                $existing['value_ciphertext'],
                PayrollSensitiveField::PERSON_EXTERNAL_IDENTIFIER,
                $supplierId,
                $existing['id'],
            );
            $storedHash = $this->sensitiveData->lookupHash(
                $plaintext,
                PayrollSensitiveField::PERSON_EXTERNAL_IDENTIFIER,
                $supplierId,
            );
            if (!hash_equals($existing['value_hash'], $storedHash)) {
                throw new \RuntimeException('Otisk OIČ neodpovídá ciphertextu.');
            }
            $inputHash = $this->sensitiveData->lookupHash(
                $normalizedValue,
                PayrollSensitiveField::PERSON_EXTERNAL_IDENTIFIER,
                $supplierId,
            );

            return hash_equals($existing['value_hash'], $inputHash);
        });
    }

    public function activeEmploymentExternalIdMatches(
        int $supplierId,
        int $employmentId,
        string $environment,
        string $value,
    ): ?bool {
        $this->positive($supplierId, 'Firma');
        $this->positive($employmentId, 'Pracovní vztah');
        $this->environment($environment);
        $normalizedValue = self::idPpv($value);

        return $this->repository->transaction(function () use (
            $supplierId,
            $employmentId,
            $environment,
            $normalizedValue,
        ): ?bool {
            $existing = $this->repository->activeExternalId(
                $supplierId,
                $employmentId,
                $environment,
                'id_ppv',
            );
            if ($existing === null) {
                return null;
            }
            $plaintext = $this->sensitiveData->reveal(
                $existing['value_ciphertext'],
                PayrollSensitiveField::EMPLOYMENT_EXTERNAL_IDENTIFIER,
                $supplierId,
                $existing['id'],
            );
            $storedHash = $this->sensitiveData->lookupHash(
                $plaintext,
                PayrollSensitiveField::EMPLOYMENT_EXTERNAL_IDENTIFIER,
                $supplierId,
            );
            if (!hash_equals($existing['value_hash'], $storedHash)) {
                throw new \RuntimeException(
                    'Otisk externího ID neodpovídá ciphertextu.',
                );
            }
            $inputHash = $this->sensitiveData->lookupHash(
                $normalizedValue,
                PayrollSensitiveField::EMPLOYMENT_EXTERNAL_IDENTIFIER,
                $supplierId,
            );

            return hash_equals($existing['value_hash'], $inputHash);
        });
    }

    /**
     * @return array{
     *   id:int,employment_id:int,employee_id:int,environment:string,
     *   identifier_type:string,value_masked:string,valid_from:string,
     *   row_version:int,created:bool
     * }
     */
    public function assignEmploymentExternalId(
        int $supplierId,
        int $employmentId,
        string $environment,
        string $value,
        string $validFrom,
        string $sourceKind,
        string $sourceReference,
        ?int $sourceReceiptId,
        ?int $createdBy,
    ): array {
        $this->positive($supplierId, 'Firma');
        $this->positive($employmentId, 'Pracovní vztah');
        $this->environment($environment);
        $this->date($validFrom, 'Platnost externího ID');
        $this->allowed($sourceKind, self::SOURCE_KINDS, 'Zdroj externího ID');
        $this->optionalPositive($sourceReceiptId, 'Protokol');
        $this->optionalPositive($createdBy, 'Uživatel');
        if (($sourceKind === 'trusted_receipt')
            !== ($sourceReceiptId !== null)
        ) {
            throw new \InvalidArgumentException(
                'Trusted externí ID musí odkazovat na ověřený protokol.',
            );
        }
        $value = self::idPpv($value);
        $sourceHash = $this->sensitiveData->keyedFingerprint(
            $this->evidenceReference($sourceReference),
            'employment-external-id-source',
            $supplierId,
        );

        return $this->repository->transaction(function () use (
            $supplierId,
            $employmentId,
            $environment,
            $value,
            $validFrom,
            $sourceKind,
            $sourceReceiptId,
            $sourceHash,
            $createdBy,
        ): array {
            $employment = $this->repository->lockEmployment(
                $supplierId,
                $employmentId,
            );
            if ($employment === null) {
                throw new \DomainException(
                    'Pracovní vztah nebyl nalezen ve stejné firmě.',
                );
            }
            if ($employment['start_date'] === null) {
                throw new \DomainException(
                    'Pracovní vztah nemá doplněné datum nástupu.',
                );
            }
            if ($validFrom < $employment['start_date']
                || ($employment['end_date'] !== null
                    && $validFrom > $employment['end_date'])
            ) {
                throw new \DomainException(
                    'Platnost externího ID neleží v období pracovního vztahu.',
                );
            }
            if ($sourceReceiptId !== null
                && !$this->repository->hasTrustedReceipt(
                    $supplierId,
                    $environment,
                    $sourceReceiptId,
                )
            ) {
                throw new \DomainException(
                    'Zdrojový protokol není důvěryhodný nebo patří jinému prostředí.',
                );
            }
            $existing = $this->repository->activeExternalId(
                $supplierId,
                $employmentId,
                $environment,
                'id_ppv',
            );
            if ($existing !== null) {
                $plaintext = $this->sensitiveData->reveal(
                    $existing['value_ciphertext'],
                    PayrollSensitiveField::EMPLOYMENT_EXTERNAL_IDENTIFIER,
                    $supplierId,
                    $existing['id'],
                );
                $storedHash = $this->sensitiveData->lookupHash(
                    $plaintext,
                    PayrollSensitiveField::EMPLOYMENT_EXTERNAL_IDENTIFIER,
                    $supplierId,
                );
                if (!hash_equals($existing['value_hash'], $storedHash)) {
                    throw new \RuntimeException(
                        'Otisk externího ID neodpovídá ciphertextu.',
                    );
                }
                $inputHash = $this->sensitiveData->lookupHash(
                    $value,
                    PayrollSensitiveField::EMPLOYMENT_EXTERNAL_IDENTIFIER,
                    $supplierId,
                );
                if (!hash_equals($existing['value_hash'], $inputHash)) {
                    throw new \DomainException(
                        'Pracovní vztah už má jiné aktivní ID PPV.',
                    );
                }
                if ($existing['valid_from'] !== $validFrom
                    || $existing['source_kind'] !== $sourceKind
                    || $existing['source_receipt_id'] !== $sourceReceiptId
                    || !hash_equals($existing['source_reference_hash'], $sourceHash)
                ) {
                    throw new \DomainException(
                        'Opakované uložení ID PPV neodpovídá původnímu datu nebo důkazu.',
                    );
                }

                return [
                    'id' => $existing['id'],
                    'employment_id' => $existing['employment_id'],
                    'employee_id' => $existing['employee_id'],
                    'environment' => $existing['environment'],
                    'identifier_type' => $existing['identifier_type'],
                    'value_masked' => $existing['value_masked'],
                    'valid_from' => $existing['valid_from'],
                    'row_version' => $existing['row_version'],
                    'created' => false,
                ];
            }

            $id = $this->repository->insertExternalIdPlaceholder(
                $supplierId,
                $employment['employee_id'],
                $employmentId,
                $environment,
                'id_ppv',
                $validFrom,
                $sourceKind,
                $sourceReceiptId,
                $sourceHash,
                $createdBy,
            );
            $sealed = $this->sensitiveData->seal(
                $value,
                PayrollSensitiveField::EMPLOYMENT_EXTERNAL_IDENTIFIER,
                $supplierId,
                $id,
            );
            $this->repository->sealExternalId(
                $supplierId,
                $id,
                $sealed->ciphertext,
                $sealed->lookupHash,
                $sealed->masked,
            );

            return [
                'id' => $id,
                'employment_id' => $employmentId,
                'employee_id' => $employment['employee_id'],
                'environment' => $environment,
                'identifier_type' => 'id_ppv',
                'value_masked' => $sealed->masked,
                'valid_from' => $validFrom,
                'row_version' => 1,
                'created' => true,
            ];
        });
    }

    /**
     * @return array{id:int,status:string,row_version:int,created:bool}
     */
    public function openResolutionTask(
        int $supplierId,
        int $employmentId,
        string $environment,
        string $taskKind,
        string $reasonCode,
        ?int $candidateCount,
        ?int $sourceReceiptId,
        ?int $assignedTo,
        ?int $createdBy,
    ): array {
        $this->positive($supplierId, 'Firma');
        $this->positive($employmentId, 'Pracovní vztah');
        $this->environment($environment);
        $this->allowed($taskKind, self::TASK_KINDS, 'Druh úkolu');
        $this->code($reasonCode, 'Důvod úkolu');
        if ($candidateCount !== null
            && ($candidateCount < 0 || $candidateCount > 1500)
        ) {
            throw new \InvalidArgumentException(
                'Počet kandidátů identity není platný.',
            );
        }
        $this->optionalPositive($sourceReceiptId, 'Protokol');
        $this->optionalPositive($assignedTo, 'Řešitel');
        $this->optionalPositive($createdBy, 'Uživatel');

        return $this->repository->transaction(function () use (
            $supplierId,
            $employmentId,
            $environment,
            $taskKind,
            $reasonCode,
            $candidateCount,
            $sourceReceiptId,
            $assignedTo,
            $createdBy,
        ): array {
            $employment = $this->repository->lockEmployment(
                $supplierId,
                $employmentId,
            );
            if ($employment === null) {
                throw new \DomainException(
                    'Pracovní vztah nebyl nalezen ve stejné firmě.',
                );
            }
            if ($sourceReceiptId !== null
                && !$this->repository->hasTrustedReceipt(
                    $supplierId,
                    $environment,
                    $sourceReceiptId,
                )
            ) {
                throw new \DomainException(
                    'Zdrojový protokol není důvěryhodný nebo patří jinému prostředí.',
                );
            }

            return $this->repository->openResolutionTask(
                $supplierId,
                $employment['employee_id'],
                $employmentId,
                $environment,
                $taskKind,
                $reasonCode,
                $candidateCount,
                $sourceReceiptId,
                $assignedTo,
                $createdBy,
            );
        });
    }

    public function resolveTask(
        int $supplierId,
        int $taskId,
        int $expectedRowVersion,
        string $environment,
        ?int $externalId,
        string $evidenceReference,
        int $resolvedBy,
    ): int {
        $this->positive($supplierId, 'Firma');
        $this->positive($taskId, 'Resolution task');
        $this->positive($expectedRowVersion, 'Verze úkolu');
        $this->environment($environment);
        $this->optionalPositive($externalId, 'Externí ID');
        $this->positive($resolvedBy, 'Řešitel');
        $evidenceHash = $this->sensitiveData->keyedFingerprint(
            $this->evidenceReference($evidenceReference),
            'identity-resolution-evidence',
            $supplierId,
        );

        return $this->repository->transaction(function () use (
            $supplierId,
            $taskId,
            $expectedRowVersion,
            $environment,
            $externalId,
            $evidenceHash,
            $resolvedBy,
        ): int {
            $task = $this->repository->lockResolutionTask(
                $supplierId,
                $taskId,
                $environment,
            );
            if ($task === null) {
                throw new \DomainException(
                    'Resolution task nebyl nalezen ve stejné firmě a prostředí.',
                );
            }
            if ($task['row_version'] !== $expectedRowVersion) {
                throw new \DomainException('Resolution task se mezitím změnil.');
            }
            if ($task['task_kind'] === 'employment_external_id') {
                if ($externalId === null) {
                    throw new \InvalidArgumentException(
                        'Úkol externího ID vyžaduje vyřešené ID PPV.',
                    );
                }
                $resolved = $this->repository->externalIdById(
                    $supplierId,
                    $externalId,
                    $environment,
                );
                if ($resolved === null
                    || $resolved['employment_id']
                        !== $task['employment_id']
                    || $resolved['employee_id']
                        !== $task['employee_id']
                ) {
                    throw new \DomainException(
                        'Externí ID nepatří řešenému vztahu.',
                    );
                }
            } elseif ($externalId !== null) {
                throw new \InvalidArgumentException(
                    'Úkol identity osoby nesmí být vyřešen ID pracovního vztahu.',
                );
            }

            return $this->repository->resolveTask(
                $supplierId,
                $taskId,
                $expectedRowVersion,
                $externalId,
                $evidenceHash,
                $resolvedBy,
            );
        });
    }

    /**
     * @param array{
     *   id:int,employee_id:int,environment:string,identifier_type:string,
     *   value_ciphertext:string,value_hash:string,value_masked:string,
     *   valid_from:string,valid_to:?string,source_kind:string,
     *   source_receipt_id:?int,source_reference_hash:string,row_version:int
     * }|array{
     *   id:int,employee_id:int,employment_id:int,environment:string,
     *   identifier_type:string,value_ciphertext:string,value_hash:string,
     *   value_masked:string,valid_from:string,valid_to:?string,
     *   source_kind:string,source_receipt_id:?int,
     *   source_reference_hash:string,row_version:int
     * } $stored
     * @return array{
     *   id:int,identifier_type:string,value:string,valid_from:string,
     *   valid_to:?string,source_kind:string,source_receipt_id:?int,
     *   source_reference_hash:string,row_version:int
     * }
     */
    private function revealExternalIdentifier(
        array $stored,
        PayrollSensitiveField $field,
        int $supplierId,
    ): array {
        $plaintext = $this->sensitiveData->reveal(
            (string) $stored['value_ciphertext'],
            $field,
            $supplierId,
            (int) $stored['id'],
        );
        $hash = $this->sensitiveData->lookupHash(
            $plaintext,
            $field,
            $supplierId,
        );
        if (!hash_equals((string) $stored['value_hash'], $hash)) {
            throw new \RuntimeException(
                'Otisk externího identifikátoru neodpovídá ciphertextu.',
            );
        }

        return [
            'id' => (int) $stored['id'],
            'identifier_type' => (string) $stored['identifier_type'],
            'value' => $plaintext,
            'valid_from' => (string) $stored['valid_from'],
            'valid_to' => $stored['valid_to'] === null
                ? null
                : (string) $stored['valid_to'],
            'source_kind' => (string) $stored['source_kind'],
            'source_receipt_id' => $stored['source_receipt_id'] === null
                ? null
                : (int) $stored['source_receipt_id'],
            'source_reference_hash' => (string) $stored['source_reference_hash'],
            'row_version' => (int) $stored['row_version'],
        ];
    }

    /**
     * @param array{
     *   id:int,value_masked:string,valid_from:string,valid_to:?string,
     *   source_kind:string,row_version:int
     * } $stored
     * @return array{
     *   id:int,value_masked:string,valid_from:string,valid_to:?string,
     *   source_kind:string,row_version:int
     * }
     */
    private function maskedExternalIdentifier(array $stored): array
    {
        return [
            'id' => (int) $stored['id'],
            'value_masked' => (string) $stored['value_masked'],
            'valid_from' => (string) $stored['valid_from'],
            'valid_to' => $stored['valid_to'] === null
                ? null
                : (string) $stored['valid_to'],
            'source_kind' => (string) $stored['source_kind'],
            'row_version' => (int) $stored['row_version'],
        ];
    }

    /**
     * @param array<string,mixed> $stored
     * @return array<string,mixed>
     */
    private function decodeA1Profile(array $stored): array
    {
        $canonical = $this->sensitiveData->reveal(
            (string) $stored['profile_ciphertext'],
            PayrollSensitiveField::REGISTRATION_A1_PROFILE,
            (int) $stored['supplier_id'],
            (int) $stored['employment_id'],
        );
        $hash = $this->sensitiveData->lookupHash(
            $canonical,
            PayrollSensitiveField::REGISTRATION_A1_PROFILE,
            (int) $stored['supplier_id'],
        );
        if (!hash_equals((string) $stored['profile_hash'], $hash)) {
            throw new \RuntimeException(
                'Otisk autoritativního profilu REGZEC A1 neodpovídá ciphertextu.',
            );
        }
        try {
            $profile = json_decode(
                $canonical,
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                'Autoritativní profil REGZEC A1 není platný JSON.',
                previous: $exception,
            );
        }
        if (!is_array($profile) || array_is_list($profile)) {
            throw new \RuntimeException(
                'Autoritativní profil REGZEC A1 nemá platný objekt.',
            );
        }

        return $profile;
    }

    /** @return array<string,mixed> */
    private function a1ProfileData(PayrollRegistrationA1Snapshot $snapshot): array
    {
        return [
            'permanent_address' => $snapshot->permanentAddress,
            'tax_residency' => $snapshot->taxResidency,
            'employment' => $snapshot->employment,
            'pension' => $snapshot->pension,
            'health_insurance_code' => $snapshot->healthInsuranceCode,
            'facts' => $snapshot->facts,
            'foreign_legislation' => $snapshot->foreignLegislation,
            'proof_identity' => $snapshot->proofIdentity,
            'foreign_worker' => $snapshot->foreignWorker,
            'czech_residence_address' => $snapshot->czechResidenceAddress,
            'contact_address' => $snapshot->contactAddress,
            'attachments' => $snapshot->attachments,
        ];
    }

    /**
     * @param array<string,mixed> $stored
     * @return array<string,mixed>
     */
    private function a1Source(array $stored): array
    {
        return [
            'source_key' => 'payroll_registration_a1_profile',
            'source_id' => (int) $stored['id'],
            'row_version' => (int) $stored['row_version'],
            'reference_hash' => (string) $stored['reference_hash'],
            'supplier_id' => (int) $stored['supplier_id'],
            'employee_id' => (int) $stored['employee_id'],
            'employment_id' => (int) $stored['employment_id'],
            'effective_on' => (string) $stored['effective_on'],
        ];
    }

    /**
     * @param array<string,mixed> $stored
     * @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    private function publicA1Profile(
        array $stored,
        array $profile,
        bool $created,
    ): array {
        return array_merge($profile, [
            'effective_on' => (string) $stored['effective_on'],
            'row_version' => (int) $stored['row_version'],
            'reference_hash' => (string) $stored['reference_hash'],
            'created_at' => (string) $stored['created_at'],
            'created' => $created,
        ]);
    }

    private static function oic(string $value): string
    {
        $normalized = preg_replace('/\s+/u', '', trim($value));
        if (!is_string($normalized)
            || preg_match('/^[0-9]{10}$/D', $normalized) !== 1
        ) {
            throw new \InvalidArgumentException(
                'OIČ / IK MPSV musí obsahovat přesně 10 číslic.',
            );
        }
        $remainder = 0;
        for ($index = 0; $index < 9; $index++) {
            $remainder = (($remainder * 10) + (int) $normalized[$index]) % 11;
        }
        if ($remainder > 9 || $remainder !== (int) $normalized[9]) {
            throw new \InvalidArgumentException(
                'OIČ / IK MPSV nemá platnou kontrolní číslici.',
            );
        }

        return $normalized;
    }

    private static function idPpv(string $value): string
    {
        $normalized = preg_replace('/\s+/u', '', trim($value));
        if (!is_string($normalized)
            || preg_match('/^[0-9]{1,22}$/D', $normalized) !== 1
        ) {
            throw new \InvalidArgumentException(
                'ID PPV musí obsahovat 1 až 22 číslic.',
            );
        }

        return $normalized;
    }

    /** @param array<string,mixed> $source */
    private function optionalText(
        array $source,
        string $key,
        int $maxLength,
    ): ?string {
        if (!array_key_exists($key, $source) || $source[$key] === null) {
            return null;
        }
        if (!is_string($source[$key])) {
            throw new \InvalidArgumentException("{$key} musí být text.");
        }
        $value = trim($source[$key]);
        if ($value === ''
            || mb_strlen($value, 'UTF-8') > $maxLength
            || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1
        ) {
            throw new \InvalidArgumentException("{$key} není platné.");
        }

        return $value;
    }

    /** @param array<string,mixed> $source */
    private function optionalDate(array $source, string $key): ?string
    {
        if (!array_key_exists($key, $source) || $source[$key] === null) {
            return null;
        }
        if (!is_string($source[$key])) {
            throw new \InvalidArgumentException("{$key} musí být datum.");
        }
        $this->date($source[$key], $key);

        return $source[$key];
    }

    /** @param array<string,mixed> $source */
    private function optionalCountry(array $source, string $key): ?string
    {
        $value = $this->optionalText($source, $key, 2);
        if ($value === null) {
            return null;
        }
        $value = strtoupper($value);
        if (preg_match('/^[A-Z]{2}$/D', $value) !== 1) {
            throw new \InvalidArgumentException("{$key} není platný kód státu.");
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $source
     * @param list<string> $allowed
     */
    private function optionalEnum(
        array $source,
        string $key,
        array $allowed,
    ): ?string {
        $value = $this->optionalText($source, $key, 32);
        if ($value !== null && !in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException("{$key} není podporované.");
        }

        return $value;
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \UnexpectedValueException(
                'Historická identita obsahuje neplatný text.',
            );
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function evidenceReference(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 500) {
            throw new \InvalidArgumentException(
                'Reference důkazu není platná.',
            );
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new \InvalidArgumentException(
                'Reference důkazu obsahuje řídicí znak.',
            );
        }

        return $value;
    }

    private function date(string $value, string $label): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException("{$label} není platné datum.");
        }
    }

    private function environment(string $environment): void
    {
        $this->allowed($environment, self::ENVIRONMENTS, 'Prostředí');
    }

    /** @param list<string> $allowed */
    private function allowed(
        string $value,
        array $allowed,
        string $label,
    ): void {
        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException("{$label} není podporované.");
        }
    }

    private function code(string $value, string $label): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $value) !== 1) {
            throw new \InvalidArgumentException("{$label} není platný kód.");
        }
    }

    private function positive(int $value, string $label): void
    {
        if ($value <= 0) {
            throw new \InvalidArgumentException("{$label} musí být kladné ID.");
        }
    }

    private function optionalPositive(?int $value, string $label): void
    {
        if ($value !== null) {
            $this->positive($value, $label);
        }
    }
}
