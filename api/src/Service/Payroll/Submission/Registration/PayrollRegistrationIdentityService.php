<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use MyInvoice\Repository\Payroll\PayrollRegistrationIdentityRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollRevealPurpose;
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

    /**
     * Opakované věty. Účetní čte tutéž situaci na pěti obrazovkách, tak ať
     * dostane pokaždé týž text a nehádá, jestli jde o jinou chybu.
     */
    private const EMPLOYMENT_NOT_FOUND =
        'Pracovní vztah v téhle firmě neexistuje. Nejspíš došlo ke smazání '
        . 'nebo přesunu. Vraťte se na přehled osob a otevřete vztah znovu.';
    private const RECEIPT_UNTRUSTED =
        'Protokol o přijetí od ČSSZ, ze kterého se identifikátor přebírá, '
        . 'není mezi ověřenými protokoly téhle firmy. Zkontrolujte, že jste '
        . 've stejném prostředí (ostré, nebo testovací) a že je protokol '
        . 'načtený.';
    private const REOPEN_FORM =
        'Zavřete formulář a otevřete ho znovu.';
    private const EMPLOYMENT_FOREIGN =
        'Pracovní vztah patří jiné osobě nebo jiné firmě, než na kterou '
        . 'registrace míří. Vraťte se na přehled osob a otevřete vztah znovu.';
    private const DATE_OUTSIDE_EMPLOYMENT =
        'Rozhodné datum leží mimo dobu trvání pracovního vztahu. Zvolte den '
        . 'mezi nástupem a skončením vztahu, nebo opravte období na kartě '
        . 'pracovního vztahu.';
    private const UNRESOLVED_IDENTITY =
        'Osoba má u ČSSZ rozpracované ztotožnění. Nejdřív ztotožnění '
        . 'dokončete na kartě pracovního vztahu, teprve pak půjde podání '
        . 'odeslat. Jinak by údaje odešly na cizí osobu.';

    public function __construct(
        private PayrollRegistrationIdentityRepository $repository,
        private PayrollSensitiveData $sensitiveData,
    ) {}

    /*
     * POZNÁMKA K \RuntimeException V TÉHLE TŘÍDĚ: hlášky o nesedícím otisku
     * proti zašifrované hodnotě zůstávají technické záměrně. Akce registrace
     * ani JMHZ je nechytají (chytají jen OutOfBounds/Domain/InvalidArgument
     * a PayrollRegistrationIdentitySnapshotException), takže nekončí v těle
     * odpovědi, ale jako obecná chyba serveru v logu. Formulovat je pro
     * účetní by zamlžilo, že jde o poškozená data, ne o její vstup.
     */

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

    /**
     * Uložený profil plus NÁVRH složený z kmenových dat. Návrh je jen
     * předvyplnění formuláře — uložit ho může výhradně saveA1Profile, a to
     * zase jen do payroll_registration_a1_profiles.
     *
     * @return array{profile:array<string,mixed>|null,draft:array<string,mixed>}
     */
    public function a1ProfileView(
        int $supplierId,
        int $employmentId,
    ): array {
        $this->positive($supplierId, 'Firma');
        $this->positive($employmentId, 'Pracovní vztah');

        return $this->repository->transaction(function () use (
            $supplierId,
            $employmentId,
        ): array {
            $employment = $this->repository->lockEmployment(
                $supplierId,
                $employmentId,
            );
            if ($employment === null) {
                throw new \OutOfBoundsException(self::EMPLOYMENT_NOT_FOUND);
            }
            $employeeId = $employment['employee_id'];
            $stored = $this->repository->latestA1Profile(
                $supplierId,
                $employeeId,
                $employmentId,
            );
            $profile = $stored === null ? null : $this->publicA1Profile(
                $stored,
                $this->decodeA1Profile($stored),
                false,
            );
            $effectiveOn = $employment['actual_start_date']
                ?? $employment['start_date'];
            if ($effectiveOn === null) {
                /*
                 * Zůstává výjimkou: bez dne nástupu není ke kterému dni
                 * formulář sestavit ani co komu ukázat. Nic se tu neukládá,
                 * takže se ničí práce neztrácí — jen se řekne, kam jít.
                 */
                throw new \InvalidArgumentException(self::fieldMessage(
                    'employment.actual_start_on',
                    'chybí. Registrace se zmrazuje právě k tomuhle dni, '
                    . 'takže bez data nástupu se formulář nedá otevřít.',
                ));
            }
            $identity = null;
            $identityError = null;
            $foreignTaxIdentifier = null;
            try {
                $sensitive = $this->sensitiveIdentityAtInternal(
                    $supplierId,
                    $employeeId,
                    $effectiveOn,
                    false,
                );
                $identity = $sensitive['identity'];
                $foreignTaxIdentifier =
                    $sensitive['identifiers']['foreign_tax_identifier'];
            } catch (\DomainException $exception) {
                $identityError = $exception->getMessage();
            }
            $draft = (new PayrollRegistrationA1DraftBuilder())->build(
                $this->repository->a1DraftSources(
                    $supplierId,
                    $employeeId,
                    $employmentId,
                    $effectiveOn,
                ),
                $identity,
                $identityError,
                $foreignTaxIdentifier,
                $effectiveOn,
                (int) ($stored['row_version'] ?? 0),
                $profile,
                /*
                 * Rozejití s kmenovými daty má smysl hlásit jedině u snímku,
                 * který už na ČSSZ ODEŠEL — jen tam je rozdíl skutečnost
                 * („takhle jsme to podali, dnes je to jinak"), se kterou se dá
                 * něco udělat (změnové podání A3). Rozpracovaný profil žádnou
                 * historii nepředstavuje a má prostě jet podle dnešních
                 * kmenových dat, takže se u něj nehlásí nic.
                 */
                $this->repository->hasSubmittedRegistration(
                    $supplierId,
                    $employmentId,
                ),
            );

            return ['profile' => $profile, 'draft' => $draft];
        });
    }

    /**
     * Poslední OVĚŘENÁ verze profilu.
     *
     * Detekce změn z ní zakládá povinnosti s běžící lhůtou, takže se nesmí
     * dívat na rozpracovanou verzi: nedopsaný koncept vypadá jako hromada
     * změn proti podanému stavu a účetní by dostala lhůty na změny, které
     * nikdo neudělal.
     *
     * @return array<string,mixed>|null
     */
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
                throw new \OutOfBoundsException(self::EMPLOYMENT_NOT_FOUND);
            }
            $stored = $this->repository->latestA1Profile(
                $supplierId,
                $employment['employee_id'],
                $employmentId,
                false,
                true,
            );

            return $stored === null ? null : $this->publicA1Profile(
                $stored,
                $this->decodeA1Profile($stored),
                false,
            );
        });
    }

    /**
     * Uloží profil VŽDY, i neúplný.
     *
     * Formulář A1 má přes stovku polí a část se dopisuje jinde na kartě osoby.
     * Dokud jedno prázdné pole odmítalo celý zápis, účetní odcházela údaj
     * doplnit a hodinu práce nechala v prohlížeči. Úplnost se proto vynucuje
     * až tam, kde z profilu vzniká podání (`preview`/`prepare` staví přísný
     * snímek), a tady se jen řekne, co ještě chybí.
     *
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
        /*
         * Verzi ani den zmrazení účetní nikam nepíše — formulář je posílá
         * zpátky z toho, co si při otevření načetl. Když chybí nebo mají
         * cizí tvar, není to nevyplněný údaj, ale rozbitý stav okna: sebrat
         * to do `problems` by předstíralo uložení, které nemá kam zapsat.
         */
        $expectedVersion = $input['row_version'] ?? null;
        if (!is_int($expectedVersion) || $expectedVersion < 0) {
            throw new \InvalidArgumentException(
                'Formulář registrace poslal poškozený údaj o verzi profilu, '
                . 'takže se uložení nedá bezpečně provést. '
                . self::REOPEN_FORM . ' (row_version)',
            );
        }
        $effectiveOn = $input['effective_on'] ?? null;
        if (!is_string($effectiveOn)) {
            throw new \InvalidArgumentException(
                'Formulář registrace neposlal den, ke kterému se profil '
                . 'zmrazuje. ' . self::REOPEN_FORM . ' (effective_on)',
            );
        }
        $this->date($effectiveOn, 'Den, ke kterému se registrace zmrazuje,');
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
                throw new \OutOfBoundsException(self::EMPLOYMENT_NOT_FOUND);
            }
            $employeeId = $employment['employee_id'];
            $registrationOn = $employment['actual_start_date']
                ?? $employment['start_date'];
            if ($registrationOn !== $effectiveOn) {
                /*
                 * Nesbírá se do `problems`: profil se pečetí ke konkrétnímu
                 * dni a s rozjetým datem by se uložil ke dni, kde ho podání
                 * nenajde. Zapsat „skoro správně" je horší než nezapsat.
                 */
                throw new PayrollRegistrationIdentitySnapshotException(
                    'registration_regzec_a1_source_scope_mismatch',
                    'Datum nástupu se mezitím na kartě pracovního vztahu '
                    . 'změnilo, takže otevřený formulář míří na jiný den, než '
                    . 'ke kterému se registrace zmrazuje. '
                    . self::REOPEN_FORM . ' Rozepsané změny se pak uloží '
                    . 'ke správnému dni.',
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
                /*
                 * Optimistický zámek zůstává výjimkou: sebrat souběžný zápis
                 * jen jako vadu by uložení potvrdilo a starší verze by beze
                 * stopy zmizela. Ztráta cizí práce se nesmí odbýt hláškou.
                 */
                throw new PayrollRegistrationIdentitySnapshotException(
                    'registration_regzec_a1_profile_conflict',
                    'Profil registrace mezitím uložil někdo jiný. '
                    . self::REOPEN_FORM . ' Rozepsané změny se tím ztratí, '
                    . 'ale nepřepíšou cizí práci.',
                );
            }
            /*
             * Chybějící evidence identity NESMÍ shodit uložení. Formulář má
             * přes stovku polí a identita se dopisuje jinde na kartě osoby;
             * dokud tahle výjimka létala ven, přišla účetní o všechno, co
             * mezitím napsala. Bez identity se přísný snímek sestavit nedá,
             * tak se profil uloží jako rozpracovaný a chybějící evidence se
             * vrátí mezi vadami.
             */
            $identity = null;
            $identityError = null;
            try {
                $identity = $this->sensitiveIdentityAtInternal(
                    $supplierId,
                    $employeeId,
                    $effectiveOn,
                    true,
                )['identity'];
            } catch (\DomainException $exception) {
                $identityError = $exception->getMessage();
            }
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
            $builder = new PayrollRegistrationA1SnapshotBuilder();
            if ($identity === null) {
                $problems = [[
                    'field' => 'identity',
                    'code' => 'registration_regzec_a1_identity_missing',
                    'message' => (string) $identityError,
                ]];
                $profile = $this->a1DraftData($input);
                $status = 'draft';
            } else {
                $problems = [];
                try {
                    $profile = $this->a1ProfileData($builder->build(
                        $provisional,
                        $identity,
                        $scope,
                    ));
                    $status = 'verified';
                } catch (PayrollRegistrationIdentitySnapshotException $exception) {
                    $problems = $builder->problems(
                        $provisional,
                        $identity,
                        $scope,
                    );
                    if ($problems === []) {
                        $problems = [[
                            'field' => null,
                            'code' => $exception->validationCode,
                            'message' => $exception->getMessage(),
                        ]];
                    }
                    $profile = $this->a1DraftData($input);
                    $status = 'draft';
                }
            }
            $canonical = CanonicalJson::encode($profile);
            $referenceHash = $this->sensitiveData->keyedFingerprint(
                $canonical,
                'registration-a1-profile',
                $supplierId,
            );
            if ($current !== null
                && hash_equals($current['reference_hash'], $referenceHash)
            ) {
                return $this->publicA1Profile(
                    $current,
                    $profile,
                    false,
                    $problems,
                );
            }
            $sealed = $this->sensitiveData->seal(
                $canonical,
                PayrollSensitiveField::REGISTRATION_A1_PROFILE,
                $supplierId,
                $employmentId,
            );
            /*
             * Dokud za vztah neodešlo podání, není profil evidence, ale
             * rozepsaná práce — a ta se PŘEPISUJE, nezakládá historii. Nová
             * verze při každém uložení účetní jen mátla: historie nebyla
             * nikde v UI dosažitelná a rozdíl proti vlastnímu staršímu
             * konceptu se hlásil jako rozejití s kmenovými daty.
             *
             * Nahrazení je smazání starého řádku a vložení nového (řádek se
             * nikdy nemění na místě, viz migrace 1716), takže šifrovaný obsah
             * a jeho otisk k sobě pořád patří. Jakmile podání odešlo, řádek
             * zůstává a nad ním přibude novější — odeslaný podklad se nemaže.
             *
             * `row_version` se zvyšuje dál: je to optimistický zámek proti
             * souběžné editaci téhož formuláře, ne číslo historické verze.
             */
            $replaced = $current !== null
                && !$this->repository->hasSubmittedRegistration(
                    $supplierId,
                    $employmentId,
                );
            if ($replaced) {
                $this->repository->deleteA1Profile(
                    $supplierId,
                    (int) $current['id'],
                );
            }
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
                $status,
            );
            $stored = [
                'id' => $id,
                'supplier_id' => $supplierId,
                'employee_id' => $employeeId,
                'employment_id' => $employmentId,
                'effective_on' => $effectiveOn,
                'status' => $status,
                'reference_hash' => $referenceHash,
                'row_version' => $rowVersion,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ];

            return $this->publicA1Profile($stored, $profile, true, $problems);
        });
    }

    /**
     * Co by přísnému sestavení A1 vadilo. Nic neukládá a nic neodmítá — je to
     * podklad pro tlačítko „Kontrola", které vadná pole označí ve formuláři.
     *
     * @param array<string,mixed> $input
     * @return array{
     *   complete:bool,
     *   problems:list<array{field:?string,code:string,message:string}>
     * }
     */
    public function checkA1Profile(
        int $supplierId,
        int $employmentId,
        array $input,
    ): array {
        $this->positive($supplierId, 'Firma');
        $this->positive($employmentId, 'Pracovní vztah');

        return $this->repository->transaction(function () use (
            $supplierId,
            $employmentId,
            $input,
        ): array {
            $employment = $this->repository->lockEmployment(
                $supplierId,
                $employmentId,
            );
            if ($employment === null) {
                throw new \OutOfBoundsException(self::EMPLOYMENT_NOT_FOUND);
            }
            $employeeId = $employment['employee_id'];
            $effectiveOn = $employment['actual_start_date']
                ?? $employment['start_date'];
            if ($effectiveOn === null) {
                /*
                 * Kontrola nesmí spadnout na první vadě: účetní mačká
                 * „Kontrolu" právě proto, aby uviděla VŠECHNO, co podání
                 * brání. Chybějící den nástupu je proto první položka
                 * seznamu, ne výjimka, která celé tlačítko shodí.
                 */
                return [
                    'complete' => false,
                    'problems' => [[
                        'field' => 'employment.actual_start_on',
                        'code' => 'registration_regzec_a1_start_date_missing',
                        'message' => self::fieldMessage(
                            'employment.actual_start_on',
                            'chybí. Registrace se zmrazuje právě k tomuhle '
                            . 'dni, takže bez data nástupu kontrolu dokončit '
                            . 'nejde.',
                            false,
                        ),
                    ]],
                ];
            }
            unset(
                $input['row_version'],
                $input['created_at'],
                $input['reference_hash'],
            );
            /*
             * Kontrola musí padat na TÝCHŽ polích jako podání, takže se
             * pouští nad stejným provizorním zdrojem jako uložení. Chybějící
             * identita osoby není důvod mlčet — ohlásí se jako první vada.
             */
            try {
                $identity = $this->sensitiveIdentityAtInternal(
                    $supplierId,
                    $employeeId,
                    $effectiveOn,
                    true,
                )['identity'];
            } catch (\DomainException $exception) {
                return [
                    'complete' => false,
                    'problems' => [[
                        'field' => 'identity',
                        'code' => 'registration_regzec_a1_identity_missing',
                        'message' => $exception->getMessage(),
                    ]],
                ];
            }
            $scope = [
                'supplier_id' => $supplierId,
                'employee_id' => $employeeId,
                'employment_id' => $employmentId,
                'effective_on' => $effectiveOn,
            ];
            $problems = (new PayrollRegistrationA1SnapshotBuilder())->problems(
                array_merge($input, ['source' => [
                    'source_key' => 'payroll_registration_a1_profile',
                    'source_id' => 1,
                    'row_version' => 1,
                    'reference_hash' => str_repeat('0', 64),
                    ...$scope,
                ]]),
                $identity,
                $scope,
            );

            return ['complete' => $problems === [], 'problems' => $problems];
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
                    self::EMPLOYMENT_FOREIGN,
                );
            }
            if ($employment['start_date'] === null) {
                throw new PayrollRegistrationIdentitySnapshotException(
                    'registration_identity_employment_scope_mismatch',
                    self::fieldMessage(
                        'contract_start_on',
                        'chybí. Bez dne nástupu nejde určit, jaká podoba '
                        . 'údajů se má na ČSSZ poslat.',
                    ),
                );
            }
            if ($onDate < $employment['start_date']
                || ($employment['end_date'] !== null
                    && $onDate > $employment['end_date'])
            ) {
                throw new PayrollRegistrationIdentitySnapshotException(
                    'registration_identity_employment_scope_mismatch',
                    self::DATE_OUTSIDE_EMPLOYMENT,
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
                    self::UNRESOLVED_IDENTITY,
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
                        self::fieldNote(
                            'employment_external_identifier',
                            'míří u téhle firmy na jinou osobu, takže by '
                            . 'podání odešlo na cizího zaměstnance. Opravte '
                            . 'přiřazení na kartě pracovního vztahu.',
                        ),
                    );
                }
                $plaintext = $this->sensitiveData->reveal(
                    $storedExternal['value_ciphertext'],
                    PayrollSensitiveField::EMPLOYMENT_EXTERNAL_IDENTIFIER,
                    $supplierId,
                    $storedExternal['id'],
                    PayrollRevealPurpose::SUBMISSION_CSSZ_REGISTRATION,
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
                    self::EMPLOYMENT_FOREIGN,
                );
            }
            if ($employment['start_date'] === null) {
                throw new PayrollRegistrationIdentitySnapshotException(
                    'jmhz_identity_employment_scope_mismatch',
                    self::fieldMessage(
                        'contract_start_on',
                        'chybí. Bez dne nástupu nejde určit, jaká podoba '
                        . 'údajů se má na ČSSZ poslat.',
                    ),
                );
            }
            if ($onDate < $employment['start_date']
                || ($employment['end_date'] !== null && $onDate > $employment['end_date'])
            ) {
                throw new PayrollRegistrationIdentitySnapshotException(
                    'jmhz_identity_employment_scope_mismatch',
                    self::DATE_OUTSIDE_EMPLOYMENT,
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
                    self::UNRESOLVED_IDENTITY,
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
                    self::fieldNote(
                        'person_external_identifier',
                        'k rozhodnému dni chybí, a hlášení JMHZ bez něj ČSSZ '
                        . 'nepřijme. Číslo najdete v protokolu o přijetí '
                        . 'registrace zaměstnance.',
                    ),
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
                    self::fieldNote(
                        'employment_external_identifier',
                        'k rozhodnému dni chybí, a hlášení JMHZ bez něj ČSSZ '
                        . 'nepřijme. Číslo najdete v protokolu o přijetí '
                        . 'registrace zaměstnance.',
                    ),
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
                    'person_external_identifier',
                );
                $this->assertTrustedReceiptSource(
                    $employmentIdentifier,
                    $supplierId,
                    $environment,
                    $employeeId,
                    $employmentId,
                    'id_ppv',
                    'registration_a2_id_ppv_provenance_invalid',
                    'employment_external_identifier',
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
        string $fieldPath,
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
                self::fieldNote(
                    $fieldPath,
                    'pochází z ručního zápisu. Změnu REGZEC A2 přijme ČSSZ '
                    . 'jen s číslem převzatým z protokolu o přijetí, který '
                    . 'patří téhle firmě a témuž prostředí (ostré, nebo '
                    . 'testovací). Načtěte protokol a číslo doplňte z něj.',
                ),
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
        /*
         * Obě věty se dál chytají a překládají na vadu v seznamu `problems`
         * (viz saveA1Profile a checkA1Profile), takže musí dávat smysl i samy
         * o sobě. Technická cesta se sem nepřidává: volající ji dá do pole
         * `field`, a v závorce by se zdvojila.
         */
        if ($identity === null) {
            throw new \DomainException(self::fieldMessage(
                'identity',
                'k rozhodnému dni chybí, takže registrace nemá odkud vzít '
                . 'jméno ani údaje o narození.',
                false,
            ));
        }
        if ($this->nullableText($identity['first_name'] ?? null) === null
            || $this->nullableText($identity['last_name'] ?? null) === null
        ) {
            throw new \DomainException(
                'Jméno a příjmení v evidenci identity chybí. ČSSZ potřebuje '
                . 'obě části zvlášť, celé jméno v jednom poli nestačí. Údaj '
                . 'doplňte na ' . PayrollRegistrationFieldVocabulary::WHERE_NAMES
                . '.',
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
                /*
                 * Porušená integrita dat, ne nevyplněný vstup: v evidenci je
                 * řádek, který sem nepatří. Sebrat to jako vadu by znamenalo
                 * pokračovat nad daty, kterým nerozumíme.
                 */
                throw new \UnexpectedValueException(
                    'V evidenci osoby je uložený typ identifikátoru, který '
                    . 'registrace na ČSSZ nezná. Jde o nesrovnalost v datech, '
                    . 'obraťte se prosím na podporu.',
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
                PayrollRevealPurpose::SUBMISSION_CSSZ_REGISTRATION,
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
            throw new \OutOfBoundsException(self::EMPLOYMENT_NOT_FOUND);
        }
        if ($employment['start_date'] === null) {
            throw new \InvalidArgumentException(self::fieldMessage(
                'contract_start_on',
                'chybí. Bez dne nástupu nejde určit, do jakého období '
                . 'identifikátory od ČSSZ patří.',
            ));
        }
        if ($onDate < $employment['start_date']
            || ($employment['end_date'] !== null
                && $onDate > $employment['end_date'])
        ) {
            throw new \InvalidArgumentException(self::DATE_OUTSIDE_EMPLOYMENT);
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
        bool $replaceExisting = false,
    ): array {
        $this->positive($supplierId, 'Firma');
        $this->positive($employmentId, 'Pracovní vztah');
        $this->environment($environment);
        $this->date($validFrom, 'Platnost identifikátorů');
        $this->optionalPositive($createdBy, 'Uživatel');
        /*
         * Obojí zůstává výjimkou vědomě: tenhle formulář nic nerozepisuje,
         * je to jednorázový opis dvou čísel z protokolu ČSSZ. Uložit prázdný
         * nebo nepotvrzený opis by do evidence zaneslo číslo bez důkazu,
         * odkud se pak berou podání.
         */
        if (!$evidenceConfirmed) {
            throw new \InvalidArgumentException(
                'Potvrzení o ověření chybí. Zaškrtněte, že jste obě čísla '
                . 'porovnali s protokolem od ČSSZ; podle nich se pak podání '
                . 'páruje s konkrétní osobou.',
            );
        }
        $personIdentifier = $this->nullableText($personIdentifier);
        $employmentIdentifier = $this->nullableText($employmentIdentifier);
        if ($personIdentifier === null && $employmentIdentifier === null) {
            throw new \InvalidArgumentException(
                'Osobní identifikační číslo od ČSSZ (OIČ / IK MPSV) ani '
                . 'identifikátor pracovního vztahu (ID PPV) nejsou vyplněné. '
                . 'Vyplňte aspoň jedno z čísel, druhé jde doplnit později.',
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
            $replaceExisting,
        ): array {
            $employment = $this->repository->lockEmployment(
                $supplierId,
                $employmentId,
            );
            if ($employment === null) {
                throw new \OutOfBoundsException(self::EMPLOYMENT_NOT_FOUND);
            }
            if ($employment['start_date'] === null) {
                throw new \InvalidArgumentException(self::fieldMessage(
                    'contract_start_on',
                    'chybí. Bez dne nástupu nejde určit, do jakého období '
                    . 'identifikátory od ČSSZ patří.',
                ));
            }
            if ($validFrom < $employment['start_date']
                || ($employment['end_date'] !== null
                    && $validFrom > $employment['end_date'])
            ) {
                throw new \InvalidArgumentException(
                    'Platnost identifikátorů leží mimo dobu trvání '
                    . 'pracovního vztahu. Zadejte den mezi nástupem '
                    . 'a skončením vztahu.',
                );
            }
            if ($replaceExisting) {
                if ($personIdentifier !== null) {
                    $this->discardManualPersonIdentifier(
                        $supplierId,
                        $employment['employee_id'],
                        $environment,
                    );
                }
                if ($employmentIdentifier !== null) {
                    $this->discardManualEmploymentIdentifier(
                        $supplierId,
                        $employmentId,
                        $environment,
                    );
                }
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
     * Zahodí ručně opsané OIČ / IK MPSV, aby šlo přepsat správnou hodnotou.
     *
     * Opis dvou čísel z protokolu ČSSZ je ruční práce a překlep v něm dřív
     * neměl cestu ven: nová hodnota narazila na „v evidenci je jiná hodnota"
     * a stávající řádek nešel ani upravit, ani zrušit. Protože je navíc
     * hodnota unikátní v celé firmě, blokoval překlep i zadání téhož čísla
     * u správné osoby.
     *
     * Zahodit jde jen ruční zápis a jen dokud na něm nevisí rozpracované
     * ztotožnění. Číslo převzaté z protokolu ČSSZ je doklad o tom, co úřad
     * přidělil, a nemaže se; odeslaná podání mají vlastní zmrazené kopie.
     */
    private function discardManualPersonIdentifier(
        int $supplierId,
        int $employeeId,
        string $environment,
    ): void {
        $existing = $this->repository->activePersonExternalId(
            $supplierId,
            $employeeId,
            $environment,
            'ik_mpsv',
        );
        if ($existing === null) {
            return;
        }
        if ($existing['source_kind'] !== 'verified_manual_import') {
            throw new \DomainException(self::fieldNote(
                'person_external_identifier',
                'převzal systém z protokolu ČSSZ, takže se nepřepisuje ručně. '
                . 'Nové číslo doložte novým protokolem od ČSSZ.',
            ));
        }
        $this->repository->discardManualPersonExternalId(
            $supplierId,
            $existing['id'],
        );
    }

    /** @see discardManualPersonIdentifier */
    private function discardManualEmploymentIdentifier(
        int $supplierId,
        int $employmentId,
        string $environment,
    ): void {
        $existing = $this->repository->activeExternalId(
            $supplierId,
            $employmentId,
            $environment,
            'id_ppv',
        );
        if ($existing === null) {
            return;
        }
        if ($existing['source_kind'] !== 'verified_manual_import') {
            throw new \DomainException(self::fieldNote(
                'employment_external_identifier',
                'převzal systém z protokolu ČSSZ, takže se nepřepisuje ručně. '
                . 'Nové číslo doložte novým protokolem od ČSSZ.',
            ));
        }
        /*
         * Na řádek může ukazovat úloha ztotožnění (FK RESTRICT), a tu z aplikace
         * zavřít nejde. Smazání by skončilo neošetřenou chybou databáze, takže
         * v tom případě řádek jen ukončíme dnem před novou platností — cesta
         * ven zůstane otevřená, jen se historie nezahazuje.
         */
        if ($this->repository->externalIdHasResolutionTask(
            $supplierId,
            $existing['id'],
        )) {
            $this->repository->closeExternalId(
                $supplierId,
                $existing['id'],
                $existing['row_version'],
                $existing['valid_from'],
                null,
            );

            return;
        }
        $this->repository->discardManualExternalId(
            $supplierId,
            $existing['id'],
        );
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
        $this->positive($identityId, 'Evidence identity osoby');
        $this->positive($expectedRowVersion, 'Verze evidence identity');
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
        $this->date(
            $validFrom,
            'Platnost osobního identifikačního čísla od ČSSZ',
        );
        $this->allowed(
            $sourceKind,
            self::SOURCE_KINDS,
            'Zdroj osobního identifikačního čísla od ČSSZ',
        );
        $this->optionalPositive($sourceReceiptId, 'Protokol o přijetí');
        $this->optionalPositive($createdBy, 'Uživatel');
        if (($sourceKind === 'trusted_receipt') !== ($sourceReceiptId !== null)) {
            // Kontrakt volajícího, ne vstup účetní: buď je zdrojem protokol
            // a musí být uvedený, nebo je zápis ruční a protokol tam nepatří.
            throw new \InvalidArgumentException(
                'Zdroj osobního identifikačního čísla od ČSSZ neodpovídá '
                . 'doloženému původu: číslo převzaté z protokolu musí mít '
                . 'odkaz na protokol, ručně opsané ho mít nesmí.',
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
                throw new \DomainException(
                    'Osoba v téhle firmě neexistuje. Nejspíš došlo ke smazání '
                    . 'nebo přesunu. Vraťte se na přehled osob a otevřete '
                    . 'kartu znovu.',
                );
            }
            if ($sourceReceiptId !== null
                && !$this->repository->hasTrustedReceipt(
                    $supplierId,
                    $environment,
                    $sourceReceiptId,
                )
            ) {
                throw new \DomainException(self::RECEIPT_UNTRUSTED);
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
                    PayrollRevealPurpose::SUBMISSION_CSSZ_REGISTRATION,
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
                    throw new \DomainException(self::fieldNote(
                        'person_external_identifier',
                        'má u téhle osoby v evidenci jinou hodnotu. Dvě '
                        . 'různá čísla vedle sebe systém nepovede: nejdřív '
                        . 'ukončete platnost stávajícího záznamu, nebo '
                        . 'opravte zadanou hodnotu.',
                    ));
                }
                if ($existing['valid_from'] !== $validFrom
                    || $existing['source_kind'] !== $sourceKind
                    || $existing['source_receipt_id'] !== $sourceReceiptId
                    || !hash_equals($existing['source_reference_hash'], $sourceHash)
                ) {
                    throw new \DomainException(self::fieldNote(
                        'person_external_identifier',
                        'už v evidenci je se stejnou hodnotou, ale s jiným dnem '
                        . 'platnosti nebo jiným podkladem. Zadejte původní '
                        . 'den a podklad, nebo nejdřív ukončete platnost '
                        . 'stávajícího záznamu.',
                    ));
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
                PayrollRevealPurpose::SUBMISSION_CSSZ_REGISTRATION,
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
                PayrollRevealPurpose::SUBMISSION_CSSZ_REGISTRATION,
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
        $this->date(
            $validFrom,
            'Platnost identifikátoru pracovního vztahu od ČSSZ',
        );
        $this->allowed(
            $sourceKind,
            self::SOURCE_KINDS,
            'Zdroj identifikátoru pracovního vztahu od ČSSZ',
        );
        $this->optionalPositive($sourceReceiptId, 'Protokol o přijetí');
        $this->optionalPositive($createdBy, 'Uživatel');
        if (($sourceKind === 'trusted_receipt')
            !== ($sourceReceiptId !== null)
        ) {
            // Kontrakt volajícího, ne vstup účetní: viz assignPersonExternalId.
            throw new \InvalidArgumentException(
                'Zdroj identifikátoru pracovního vztahu od ČSSZ neodpovídá '
                . 'doloženému původu: číslo převzaté z protokolu musí mít '
                . 'odkaz na protokol, ručně opsané ho mít nesmí.',
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
                throw new \DomainException(self::EMPLOYMENT_NOT_FOUND);
            }
            if ($employment['start_date'] === null) {
                throw new \DomainException(self::fieldMessage(
                    'contract_start_on',
                    'chybí. Bez dne nástupu nejde určit, do jakého období '
                    . 'identifikátory od ČSSZ patří.',
                ));
            }
            if ($validFrom < $employment['start_date']
                || ($employment['end_date'] !== null
                    && $validFrom > $employment['end_date'])
            ) {
                throw new \DomainException(self::fieldNote(
                    'employment_external_identifier',
                    'má platnost mimo dobu trvání pracovního vztahu. Zadejte '
                    . 'den mezi nástupem a skončením vztahu.',
                ));
            }
            if ($sourceReceiptId !== null
                && !$this->repository->hasTrustedReceipt(
                    $supplierId,
                    $environment,
                    $sourceReceiptId,
                )
            ) {
                throw new \DomainException(self::RECEIPT_UNTRUSTED);
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
                    PayrollRevealPurpose::SUBMISSION_CSSZ_REGISTRATION,
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
                    throw new \DomainException(self::fieldNote(
                        'employment_external_identifier',
                        'má u tohohle vztahu v evidenci jinou hodnotu. '
                        . 'Dvě různá čísla vedle sebe systém nepovede: '
                        . 'nejdřív ukončete platnost stávajícího záznamu, '
                        . 'nebo opravte zadanou hodnotu.',
                    ));
                }
                if ($existing['valid_from'] !== $validFrom
                    || $existing['source_kind'] !== $sourceKind
                    || $existing['source_receipt_id'] !== $sourceReceiptId
                    || !hash_equals($existing['source_reference_hash'], $sourceHash)
                ) {
                    throw new \DomainException(self::fieldNote(
                        'employment_external_identifier',
                        'už v evidenci je se stejnou hodnotou, ale s jiným dnem '
                        . 'platnosti nebo jiným podkladem. Zadejte původní '
                        . 'den a podklad, nebo nejdřív ukončete platnost '
                        . 'stávajícího záznamu.',
                    ));
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
        $this->allowed($taskKind, self::TASK_KINDS, 'Druh úkolu ke ztotožnění');
        $this->code($reasonCode, 'Důvod úkolu ke ztotožnění');
        if ($candidateCount !== null
            && ($candidateCount < 0 || $candidateCount > 1500)
        ) {
            // Číslo přichází z odpovědi ČSSZ, ne z ruky: mimo rozsah znamená
            // špatně přečtenou odpověď, ne nedopsaný formulář.
            throw new \InvalidArgumentException(
                'Počet osob, které ČSSZ nabídla jako možnou shodu, musí být '
                . 'číslo od 0 do 1500. Odpověď ČSSZ se nepodařilo přečíst, '
                . 'obraťte se prosím na podporu.',
            );
        }
        $this->optionalPositive($sourceReceiptId, 'Protokol o přijetí');
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
                throw new \DomainException(self::EMPLOYMENT_NOT_FOUND);
            }
            if ($sourceReceiptId !== null
                && !$this->repository->hasTrustedReceipt(
                    $supplierId,
                    $environment,
                    $sourceReceiptId,
                )
            ) {
                throw new \DomainException(self::RECEIPT_UNTRUSTED);
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
        $this->positive($taskId, 'Úkol ke ztotožnění osoby');
        $this->positive($expectedRowVersion, 'Verze úkolu ke ztotožnění');
        $this->environment($environment);
        $this->optionalPositive(
            $externalId,
            'Identifikátor pracovního vztahu od ČSSZ',
        );
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
                    'Úkol ke ztotožnění osoby se v téhle firmě nenašel. '
                    . 'Nejspíš je už vyřešený, nebo patří druhému prostředí '
                    . '(ostré, nebo testovací). Načtěte seznam úkolů znovu.',
                );
            }
            if ($task['row_version'] !== $expectedRowVersion) {
                /*
                 * Optimistický zámek: úkol mezitím uzavřel někdo jiný a tichý
                 * přepis by smazal cizí doložení. Zůstává výjimkou.
                 */
                throw new \DomainException(
                    'Úkol ke ztotožnění osoby mezitím změnil někdo jiný. '
                    . 'Načtěte úkol znovu, ať se cizí zápis nepřepíše.',
                );
            }
            if ($task['task_kind'] === 'employment_external_id') {
                if ($externalId === null) {
                    throw new \InvalidArgumentException(self::fieldNote(
                        'employment_external_identifier',
                        'chybí. Úkol na přiřazení pracovního vztahu jde '
                        . 'uzavřít, jen když je číslo od ČSSZ známé.',
                    ));
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
                    throw new \DomainException(self::fieldNote(
                        'employment_external_identifier',
                        'patří jinému pracovnímu vztahu nebo jiné osobě, než '
                        . 'jaké úkol řeší. Vyberte číslo evidované u téhle '
                        . 'osoby.',
                    ));
                }
            } elseif ($externalId !== null) {
                throw new \InvalidArgumentException(
                    'Úkol ke ztotožnění osoby se uzavírá osobním '
                    . 'identifikačním číslem (OIČ / IK MPSV), ne '
                    . 'identifikátorem pracovního vztahu (ID PPV). Pole '
                    . 's ID PPV nechte prázdné.',
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
            PayrollRevealPurpose::SUBMISSION_CSSZ_REGISTRATION,
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
            PayrollRevealPurpose::SUBMISSION_CSSZ_REGISTRATION,
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

    /**
     * Kostra rozpracovaného profilu.
     *
     * Koncept se neověřuje, ale ani se neukládá cokoliv: zapíše se jen tenhle
     * výčet klíčů, aby se do zapečetěného blobu nedostal cizí obsah a aby měl
     * koncept stejný tvar jako ověřený snímek. Chybějící hodnota je `null`,
     * ne dohadovaná náhrada.
     *
     * @var array<string,string>
     */
    private const A1_DRAFT_ADDRESS = [
        'street' => 'text:255',
        'house_number' => 'text:12',
        'orientation_number' => 'text:12',
        'city' => 'text:255',
        'postal_code' => 'text:12',
        'country_code' => 'text:2',
        'ruian_point' => 'text:20',
    ];

    /** @var array<string,string|array<string,string>> */
    private const A1_DRAFT_SHAPE = [
        'permanent_address' => self::A1_DRAFT_ADDRESS,
        'czech_residence_address' => self::A1_DRAFT_ADDRESS,
        'contact_address' => self::A1_DRAFT_ADDRESS,
        'health_insurance_code' => 'text:3',
        'tax_residency' => [
            'country_code' => 'text:2',
            'identifier_type' => 'text:3',
            'identifier' => 'text:64',
        ],
        'employment' => [
            'activity_code' => 'text:3',
            'relationship_detail_code' => 'text:1',
            'actual_start_on' => 'text:10',
            'contract_start_on' => 'text:10',
            'small_scale' => 'bool',
            'employment_status_code' => 'text:2',
            'work_mode_code' => 'text:2',
            'continuous_operation' => 'bool',
            'prevailing_workplace_code' => 'text:2',
            'expected_workplaces' => 'text:255',
            'contract_workplace' => 'text:255',
            'workplace_city' => 'text:255',
            'workplace_municipality_code' => 'text:12',
            'profession_code' => 'text:12',
            'required_education_code' => 'text:4',
            'position_name' => 'text:255',
            'leadership' => 'bool',
        ],
        'pension' => [
            'type_code' => 'text:3',
            'received_from' => 'text:10',
            'early_retirement' => 'bool',
            'reduced_retirement_age' => 'bool',
        ],
        'facts' => [
            'highest_education_code' => 'text:4',
            'disability_card' => 'bool',
        ],
        'foreign_legislation' => [
            'applies' => 'bool',
            'country_code' => 'text:2',
        ],
        'proof_identity' => [
            'type_code' => 'text:3',
            'number' => 'text:64',
            'foreign_issuer' => 'text:255',
            'country_code' => 'text:2',
        ],
        'foreign_worker' => [
            'free_access' => 'bool',
            'free_access_reason_code' => 'text:4',
            'permit_type_code' => 'text:4',
            'issuing_labour_office_code' => 'text:8',
            'permit_identifier' => 'text:64',
            'permit_from' => 'text:10',
            'permit_to' => 'text:10',
        ],
    ];

    /** @var array<string,string> */
    private const A1_DRAFT_RESTRICTION = [
        'type_code' => 'text:3',
        'from' => 'text:10',
        'to' => 'text:10',
    ];

    /** @var array<string,string> */
    private const A1_DRAFT_ATTACHMENT = [
        'name' => 'text:255',
        'description' => 'text:255',
        'data_base64' => 'text:20000000',
    ];

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function a1DraftData(array $input): array
    {
        $draft = [];
        foreach (self::A1_DRAFT_SHAPE as $key => $shape) {
            $draft[$key] = $this->a1DraftValue($input[$key] ?? null, $shape);
        }
        // Trvalý pobyt je v ověřeném snímku vždy objekt; koncept drží stejnou
        // kostru, ať se rozpracovaný a ověřený profil čtou stejným kódem.
        $draft['permanent_address'] ??= $this->a1DraftValue(
            [],
            self::A1_DRAFT_ADDRESS,
        );
        if (is_array($draft['facts'])) {
            $draft['facts']['health_restrictions'] = $this->a1DraftList(
                is_array($input['facts'] ?? null)
                    ? ($input['facts']['health_restrictions'] ?? null)
                    : null,
                self::A1_DRAFT_RESTRICTION,
                20,
            );
        }
        $draft['tax_residency'] = is_array($draft['tax_residency'])
            ? $draft['tax_residency'] + ['residence_address' => $this->a1DraftValue(
                is_array($input['tax_residency'] ?? null)
                    ? ($input['tax_residency']['residence_address'] ?? null)
                    : null,
                self::A1_DRAFT_ADDRESS,
            )]
            : null;
        $draft['attachments'] = $this->a1DraftList(
            $input['attachments'] ?? null,
            self::A1_DRAFT_ATTACHMENT,
            9,
        );

        return $draft;
    }

    /**
     * @param string|array<string,string> $shape
     */
    private function a1DraftValue(mixed $value, string|array $shape): mixed
    {
        if (is_array($shape)) {
            if (!is_array($value) || array_is_list($value)) {
                return null;
            }
            $result = [];
            foreach ($shape as $key => $child) {
                $result[$key] = $this->a1DraftValue($value[$key] ?? null, $child);
            }

            return $result;
        }
        if ($shape === 'bool') {
            return is_bool($value) ? $value : null;
        }
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === ''
            ? null
            : mb_substr($trimmed, 0, (int) substr($shape, strlen('text:')));
    }

    /**
     * @param array<string,string> $shape
     * @return list<array<string,mixed>>
     */
    private function a1DraftList(mixed $value, array $shape, int $limit): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }
        $result = [];
        foreach (array_slice($value, 0, $limit) as $item) {
            $row = $this->a1DraftValue($item, $shape);
            if (is_array($row)) {
                $result[] = $row;
            }
        }

        return $result;
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
     * @param list<array{field:?string,code:string,message:string}> $problems
     * @return array<string,mixed>
     */
    private function publicA1Profile(
        array $stored,
        array $profile,
        bool $created,
        array $problems = [],
    ): array {
        return array_merge($profile, [
            'effective_on' => (string) $stored['effective_on'],
            'row_version' => (int) $stored['row_version'],
            'status' => (string) ($stored['status'] ?? 'verified'),
            'reference_hash' => (string) $stored['reference_hash'],
            'created_at' => (string) $stored['created_at'],
            'created' => $created,
            'problems' => $problems,
        ]);
    }

    /**
     * Věta o konkrétním údaji: lidský název, co je s ním konkrétně za potíž,
     * a kam se pro něj jde. Slovník je společný pro celý registrační řetězec,
     * viz {@see PayrollRegistrationFieldVocabulary}.
     *
     * Technická cesta patří na konec do závorky jen tam, kde ji nemá kam dát
     * struktura `problems`. Kde se plní pole `field`, se `$withReference`
     * vypíná, ať se název pole ve výsledku nezdvojí.
     */
    private static function fieldMessage(
        string $path,
        string $expectation,
        bool $withReference = true,
    ): string {
        return self::fieldNote(
            $path,
            $expectation . ' '
                . PayrollRegistrationFieldVocabulary::describe($path),
            $withReference,
        );
    }

    /**
     * Totéž bez věty „kam jít" — pro situace, kde `$expectation` už sama
     * říká, co dělat („ukončete platnost stávajícího záznamu", „načtěte
     * protokol"). Obecné „vyplňte to tady" by tam radu popřelo.
     */
    private static function fieldNote(
        string $path,
        string $expectation,
        bool $withReference = true,
    ): string {
        return PayrollRegistrationFieldVocabulary::label($path)
            . ' ' . $expectation
            . ($withReference
                ? PayrollRegistrationFieldVocabulary::reference($path)
                : '');
    }

    private static function oic(string $value): string
    {
        $normalized = preg_replace('/\s+/u', '', trim($value));
        if (!is_string($normalized)
            || preg_match('/^[0-9]{10}$/D', $normalized) !== 1
        ) {
            throw new \InvalidArgumentException(self::fieldMessage(
                'person_external_identifier',
                'musí mít přesně 10 číslic, bez mezer, lomítek a písmen.',
            ));
        }
        $remainder = 0;
        for ($index = 0; $index < 9; $index++) {
            $remainder = (($remainder * 10) + (int) $normalized[$index]) % 11;
        }
        if ($remainder > 9 || $remainder !== (int) $normalized[9]) {
            throw new \InvalidArgumentException(self::fieldNote(
                'person_external_identifier',
                'má 10 číslic, ale poslední z nich nesedí na kontrolní '
                . 'výpočet. Porovnejte opis s protokolem od ČSSZ, nejspíš '
                . 'jsou přehozené dvě číslice.',
            ));
        }

        return $normalized;
    }

    private static function idPpv(string $value): string
    {
        $normalized = preg_replace('/\s+/u', '', trim($value));
        if (!is_string($normalized)
            || preg_match('/^[0-9]{1,22}$/D', $normalized) !== 1
        ) {
            throw new \InvalidArgumentException(self::fieldMessage(
                'employment_external_identifier',
                'musí mít 1 až 22 číslic, bez mezer, lomítek a písmen.',
            ));
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
            throw new \InvalidArgumentException(
                self::fieldMessage($key, 'musí být text.'),
            );
        }
        $value = trim($source[$key]);
        if ($value === '') {
            throw new \InvalidArgumentException(self::fieldMessage(
                $key,
                'obsahuje jen mezery. Buď hodnotu vyplňte, nebo pole nechte '
                . 'úplně prázdné.',
            ));
        }
        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            throw new \InvalidArgumentException(self::fieldMessage(
                $key,
                "má víc než {$maxLength} znaků. Zkraťte zadanou hodnotu.",
            ));
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new \InvalidArgumentException(self::fieldMessage(
                $key,
                'obsahuje neviditelný řídicí znak. Přepište hodnotu ručně, '
                . 'nejspíš se vložila kopírováním z jiného programu.',
            ));
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
            throw new \InvalidArgumentException(self::fieldMessage(
                $key,
                'musí být datum ve tvaru RRRR-MM-DD.',
            ));
        }
        $this->date(
            $source[$key],
            PayrollRegistrationFieldVocabulary::label($key),
        );

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
            throw new \InvalidArgumentException(self::fieldMessage(
                $key,
                'musí být dvoupísmenný kód státu, například CZ nebo SK.',
            ));
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
            throw new \InvalidArgumentException(self::fieldMessage(
                $key,
                'má hodnotu, kterou systém nezná. Vyberte jednu '
                . 'z nabízených možností.',
            ));
        }

        return $value;
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            // Porušená integrita dat, ne nevyplněný vstup: v evidenci je
            // hodnota cizího typu, se kterou se dál počítat nedá.
            throw new \UnexpectedValueException(
                'V evidenci identity osoby je uložená hodnota, která není '
                . 'text. Jde o nesrovnalost v datech, obraťte se prosím '
                . 'na podporu.',
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
                'Odkaz na podklad, ze kterého se čísla od ČSSZ přebírají, '
                . 'chybí nebo je delší než 500 znaků. Napište krátký popis '
                . 'podkladu, například číslo protokolu o přijetí.',
            );
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new \InvalidArgumentException(
                'Odkaz na podklad obsahuje neviditelný řídicí znak. Přepište '
                . 'text ručně, nejspíš se vložil kopírováním z jiného '
                . 'programu.',
            );
        }

        return $value;
    }

    private function date(string $value, string $label): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(
                "{$label} není platné datum ve tvaru RRRR-MM-DD.",
            );
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
            throw new \InvalidArgumentException(
                "{$label}: tuhle hodnotu systém nezná. Vyberte jednu "
                . 'z nabízených možností.',
            );
        }
    }

    private function code(string $value, string $label): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $value) !== 1) {
            throw new \InvalidArgumentException(
                "{$label}: kód smí mít nejvýš 64 znaků a obsahovat jen malá "
                . 'písmena bez diakritiky, číslice, tečku, podtržítko '
                . 'a pomlčku.',
            );
        }
    }

    /**
     * ID sem přichází z adresy nebo z načteného formuláře, ne z ruky účetní.
     * Věta proto neříká „vyplňte", ale „otevřete znovu": vyplnit se to nedá.
     */
    private function positive(int $value, string $label): void
    {
        if ($value <= 0) {
            throw new \InvalidArgumentException(
                "{$label}: odkaz na záznam je poškozený. Vraťte se "
                . 'na přehled a otevřete záznam znovu.',
            );
        }
    }

    private function optionalPositive(?int $value, string $label): void
    {
        if ($value !== null) {
            $this->positive($value, $label);
        }
    }
}
