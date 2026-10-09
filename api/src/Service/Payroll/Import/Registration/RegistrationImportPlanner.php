<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Registration;

use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationIdentityRepository;
use MyInvoice\Service\Codebook\HealthInsurers;
use MyInvoice\Service\Payroll\CzechBirthNumber;
use MyInvoice\Service\Payroll\Import\Jmhz\JmhzDerivedRegistrations;
use MyInvoice\Service\Payroll\PayrollEmploymentJmhzActivityFamily;
use MyInvoice\Service\Payroll\PayrollVcp;
use MyInvoice\Service\Payroll\Pension\PayrollPensionStatus;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationFieldVocabulary;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;

/**
 * Z věty registrace a stavu evidence spočítá, co by import udělal.
 *
 * Výsledek je veřejná část (to, co vidí účetní v náhledu) a interní plán
 * v klíčích začínajících podtržítkem, podle kterého pak zapisuje
 * {@see RegistrationImportWriter}. Použití si plán počítá znovu těsně před
 * zápisem každé věty — náhled z prohlížeče se nepřebírá.
 *
 * Párování je konzervativní: když údaje ve větě ukazují na víc osob nebo víc
 * vztahů, věta se zablokuje. Zapsat změnu k cizí osobě je horší než nechat
 * účetní jednu větu dodělat ručně.
 */
final class RegistrationImportPlanner
{
    private const OPEN_STATUSES = ['planned', 'preregistered', 'active', 'suspended'];
    private const NOT_STARTED_STATUSES = ['planned', 'preregistered'];
    private const EXPORT_LABEL = 'Export zaměstnanců ČSSZ';
    private const DERIVED_LABEL = 'Vztah doložený měsíčními hlášeními JMHZ';

    public function __construct(
        private readonly RegistrationImportLookup $lookup,
        private readonly PayrollSensitiveData $sensitiveData,
        private readonly PayrollRegistrationIdentityService $identities,
        private readonly PayrollRegistrationIdentityRepository $registrations,
        private readonly PayrollEmploymentRepository $employments,
    ) {}

    /** @return array<string,mixed> */
    public function plan(
        int $supplierId,
        string $environment,
        RegistrationRecord $record,
        string $fileName,
        string $fileSha256,
        ?string $relationTypeChoice = null,
        bool $terminationConfirmed = false,
        bool $deferActivation = false,
        bool $registrationInBatch = false,
    ): array {
        [$birthNumber, $ecp] = $this->birthNumber($record);
        $birthDate = $record->birthDate
            ?? ($birthNumber === null ? null : CzechBirthNumber::birthDate($birthNumber));
        $relationType = $record->documentType === 'PREZEC26' ? 'employment' : $record->relationType();
        // Volba účetní platí jen mezi druhy, které věta sama nabízí.
        if ($relationTypeChoice !== null && in_array($relationTypeChoice, $record->relationTypeOptions, true)) {
            $relationType = $relationTypeChoice;
        }

        $plan = [
            'key' => self::key($fileSha256, $record->position),
            'file' => $fileName,
            'sequence' => $record->sequence,
            'document_type' => $record->documentType,
            'action_code' => $record->actionCode,
            'action_label' => match (true) {
                $record->isCsszExport() => self::EXPORT_LABEL,
                $record->isJmhzDerived() => self::DERIVED_LABEL,
                default => PayrollRegistrationFieldVocabulary::action($record->documentType, $record->actionCode),
            },
            'prepared_on' => $record->preparedOn,
            'effective_on' => $record->decisiveDate(),
            'person' => [
                'full_name' => $record->fullName() ?? 'Neuvedené jméno',
                'first_name' => $record->firstName,
                'last_name' => $record->lastName,
                'birth_date' => $birthDate,
                'birth_number_masked' => $this->maskedBirthNumber($birthNumber ?? $ecp),
                'has_oic' => $record->personIdentifier !== null,
            ],
            'employment' => [
                'start_on' => $record->documentType === 'PREZEC26' ? $record->expectedStartOn : $record->startOn,
                'end_on' => $record->endOn,
                'activity_code' => $record->activityCode,
                'relation_type' => $relationType,
                'relation_type_options' => $record->relationTypeOptions,
                'start_estimated' => $record->startEstimated,
                'position_name' => $record->positionName,
                'has_id_ppv' => $record->employmentIdentifier !== null,
            ],
            'match' => [
                'status' => 'not_found',
                'matched_by' => null,
                'employee_id' => null,
                'employee_name' => null,
                'employment_id' => null,
                'employment_code' => null,
                'candidates' => [],
            ],
            'operation' => 'none',
            'changes' => [],
            'warnings' => [],
            'blocker' => null,
            'selectable' => false,
            // Konec pojistného vztahu z exportu ČSSZ u vztahu, který evidence
            // vede jako trvající: nabídka ukončení, kterou účetní potvrzuje.
            'termination_offer' => null,
            '_record' => $record,
            '_file_sha256' => $fileSha256,
            '_terminate_confirmed' => $terminationConfirmed,
            // Přihláška, kterou v téže dávce ruší hlášení o nenastoupení: vztah
            // se jen založí a zůstane plánovaný, aby ho nenastoupení mohlo zrušit.
            '_defer_activation' => $deferActivation,
            // Nenastoupení, jehož přihlášku (vztah) založí vybraná věta téže dávky.
            '_registration_in_batch' => $registrationInBatch,
            '_supplier_id' => $supplierId,
            '_employee_id' => null,
            '_employment_id' => null,
            '_steps' => [
                'create_person' => null,
                'create_employment' => null,
                'terms' => [],
                'identity_facts' => [],
                'birth_surname' => null,
                'addresses' => [],
                'health_insurer' => null,
                'activate_on' => null,
                'terminate' => null,
                'correct_start' => null,
                'identifiers' => ['person' => null, 'employment' => null],
                'ecp' => null,
                'name' => null,
                'birth_number' => null,
                'vcp' => null,
                'tax_residence' => null,
                'foreign_tax_identifier' => null,
                'a1_profile' => null,
                'pension' => null,
                'termination_reason' => null,
            ],
        ];
        if ($record->insuredPersonNumber !== null && $record->birthNumber === null) {
            $plan['warnings'][] = 'Věta nese místo rodného čísla evidenční číslo pojištěnce (EČP), osoba '
                . 'nejspíš rodné číslo nemá (cizinec). Osoba se hledá podle EČP.';
        } elseif ($ecp !== null) {
            $plan['warnings'][] = 'Číslo pojištěnce ve větě není platné rodné číslo, osoba se hledá '
                . 'jako evidenční číslo pojištěnce (EČP).';
        }
        foreach ($record->notes as $note) {
            $plan['warnings'][] = $note;
        }
        if ($record->isCsszExport() && $record->insuranceFrom !== null && !$record->insuranceStartIsEmploymentStart()) {
            $plan['warnings'][] = "Export uvádí začátek pojištění {$record->insuranceFrom}. U zaměstnání malého "
                . 'rozsahu a DPP to nemusí být den nástupu (pojištění vzniká jen v měsících s rozhodným příjmem), '
                . 'nástup se proto z exportu nebere.';
        }

        $supported = match ($record->documentType) {
            'REGZEC25' => in_array($record->actionCode, [1, 2, 3, 4, 8], true),
            'PREZEC26' => in_array($record->actionCode, [9, 10], true),
            RegistrationRecord::CSSZ_EXPORT => true,
            RegistrationRecord::JMHZ_DERIVED => $record->actionCode === 1,
            default => false,
        };
        if (!$supported) {
            $plan['operation'] = 'unsupported';

            return $this->finish($plan, $plan['action_label'] . ' import neumí zapsat automaticky. '
                . 'Zpracujte oznámení ručně na kartě pracovního vztahu.');
        }
        $foreign = $this->foreignEmployerBlocker($supplierId, $record, $plan['warnings']);
        if ($foreign !== null) {
            return $this->finish($plan, $foreign);
        }
        if ($record->vcp !== null && $this->validVcp($record) === null) {
            $plan['warnings'][] = 'VČP ve větě není platné (devět číslic začínajících šestkou), nepřebírá se.';
        }

        $person = $this->matchPerson($supplierId, $environment, $record, $birthNumber, $ecp);
        if ($person['blocker'] !== null) {
            $plan['match'] = array_merge($plan['match'], [
                'status' => 'ambiguous',
                'candidates' => $person['candidates'],
            ]);

            return $this->finish($plan, $person['blocker']);
        }
        $employeeId = $person['employee_id'];
        if ($employeeId === null) {
            $this->warnFormerSurname($supplierId, $plan, $record, null, '');

            return $this->planWithoutPerson($plan, $record, $relationType, $birthNumber, $birthDate, $ecp);
        }

        $plan['_employee_id'] = $employeeId;
        $plan['match'] = array_merge($plan['match'], [
            'status' => 'matched',
            'matched_by' => $person['matched_by'],
            'employee_id' => $employeeId,
            'employee_name' => $this->lookup->employeeName($supplierId, $employeeId),
        ]);
        $this->warnOnIdentifierMismatch($supplierId, $plan, $employeeId, $birthNumber, $ecp, $record);

        $employment = $this->matchEmployment($supplierId, $employeeId, $record, $relationType, $person['id_ppv_employment_id']);
        if ($employment['blocker'] !== null) {
            $plan['match']['status'] = 'ambiguous';
            $plan['match']['candidates'] = $employment['candidates'];

            return $this->finish($plan, $employment['blocker']);
        }
        $row = $employment['row'];
        // Přihláška A1 a odvozená věta nesou ID PPV, které evidence nezná. Vztah
        // osoby, který už má JINÉ ID PPV, je jiný vztah (souběh) — k němu věta
        // nepatří, jinak by se druhý vztah téhož druhu slil s prvním. A2 a A3
        // se tím neřídí: ty ID PPV jen dokládají a hledají existující vztah.
        if ($row !== null && ($record->isJmhzDerived() || ($record->documentType === 'REGZEC25' && $record->actionCode === 1))
            && $record->employmentIdentifier !== null
            && $person['id_ppv_employment_id'] === null
            && $this->registrations->activeExternalId($supplierId, (int) $row['id'], $environment, 'id_ppv') !== null
        ) {
            $row = null;
        }
        if ($row !== null) {
            $plan['_employment_id'] = $row['id'];
            $plan['match']['employment_id'] = $row['id'];
            $plan['match']['employment_code'] = $row['code'];
            $plan['warnings'] = array_merge($plan['warnings'], $employment['warnings']);
        }

        return match (true) {
            $record->actionCode === 2 => $this->planTermination($supplierId, $environment, $plan, $record, $row),
            in_array($record->actionCode, [8, 10], true) => $this->planNoShow($supplierId, $environment, $plan, $record, $row),
            default => $this->planUpdate($supplierId, $environment, $plan, $record, $row, $relationType),
        };
    }

    public static function key(string $fileSha256, int $position): string
    {
        return substr($fileSha256, 0, 16) . ':' . $position;
    }

    /**
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function planWithoutPerson(
        array $plan,
        RegistrationRecord $record,
        ?string $relationType,
        ?string $birthNumber,
        ?string $birthDate,
        ?string $ecp = null,
    ): array {
        $creates =($record->documentType === 'REGZEC25' && $record->actionCode === 1)
            || ($record->documentType === 'PREZEC26' && $record->actionCode === 9)
            || ($record->isJmhzDerived() && $record->actionCode === 1)
            || $record->isCsszExport();
        if (!$creates && $plan['_registration_in_batch'] === true && in_array($record->actionCode, [8, 10], true)) {
            return $this->planNoShow((int) ($plan['_supplier_id'] ?? 0), '', $plan, $record, null);
        }
        if (!$creates) {
            $plan['operation'] = $record->actionCode === 2 ? 'terminate' : 'update';

            return $this->finish($plan, 'Osoba z téhle věty v evidenci není (nenašla se podle rodného čísla, '
                . 'OIČ ani ID PPV). Nejdřív naimportujte její přihlášení, nebo ji založte ručně.');
        }

        $plan['match']['status'] = 'new';
        $plan['operation'] = 'create_person';
        $start = $plan['employment']['start_on'];
        if ($record->firstName === null || $record->lastName === null) {
            return $this->finish($plan, 'Věta nemá vyplněné jméno i příjmení, takže z ní osobu založit nejde. '
                . 'Založte ji ručně na přehledu osob.');
        }
        if ($start === null) {
            if ($record->isCsszExport()) {
                return $this->finish($plan, self::exportWithoutStartBlocker('Osoba v evidenci není a export'));
            }

            return $this->finish($plan, 'Věta nemá datum nástupu, takže z ní vztah založit nejde. '
                . 'Založte osobu ručně na přehledu osob.');
        }
        if ($relationType === null) {
            if ($record->isJmhzDerived() && $record->activityCode === null) {
                return $this->finish($plan, 'Z hlášení nejde určit druh pracovního vztahu (vztah nemá ELDP ani '
                    . 'druh činnosti). Založte vztah ručně na kartě osoby a formuláře hlášení k němu přiřaďte.');
            }

            return $this->finish($plan, 'Druh činnosti „' . ($record->activityCode ?? '—')
                . '“ import neumí přiřadit k druhu pracovního vztahu. Založte osobu ručně.');
        }
        if ($record->documentType === 'PREZEC26') {
            $plan['warnings'][] = 'Částečné přihlášení druh vztahu neuvádí — osoba se založí s pracovním '
                . 'poměrem. Jde-li o dohodu, změňte druh vztahu na kartě.';
        }
        $this->derivedStartWarnings($plan, $record);

        $insurer = $this->insurer($plan, $record->healthInsurerCode);
        $plan['_steps']['create_person'] = [
            'full_name' => $record->fullName(),
            'first_name' => $record->firstName,
            'last_name' => $record->lastName,
            'birth_date' => $birthDate,
            'birth_number' => $birthNumber,
            'health_insurer_code' => $insurer,
            'relation_type' => $relationType,
            'planned_start_on' => $start,
            'workload' => $record->workload,
        ];
        if ($record->workload !== null) {
            $this->change($plan, 'weekly_hours', 'Týdenní pracovní doba', null, $record->workload['weekly_hours']);
        }
        $this->change($plan, 'full_name', 'Jméno a příjmení', null, $record->fullName());
        $this->change($plan, 'birth_date', 'Datum narození', null, $birthDate);
        $this->change($plan, 'relation_type', 'Druh pracovního vztahu', null, $relationType);
        $this->change($plan, 'start_on', 'Nástup', null, $start);
        $this->change($plan, 'health_insurer_code', 'Zdravotní pojišťovna', null, $insurer);
        if ($birthNumber === null && $ecp !== null) {
            // Cizinec bez rodného čísla: EČP je jeho identifikátor pro ČSSZ
            // (PREZEC `bno`, REGZEC) a podle něj se osoba při dalším importu
            // najde. Dřív se jen ohlásilo „doplňte ručně".
            $plan['_steps']['ecp'] = $ecp;
            $this->change($plan, 'ecp', 'Evidenční číslo pojištěnce (EČP)', null, $this->maskedBirthNumber($ecp));
        } elseif ($birthNumber === null && $this->validVcp($record) === null) {
            $plan['warnings'][] = 'Rodné číslo se z věty nepřevezme — doplňte ho na kartě osoby.';
        }
        $vcp = $this->validVcp($record);
        if ($vcp !== null) {
            $plan['_steps']['vcp'] = $vcp;
            $this->change($plan, 'vcp', 'Variabilní číslo pojištěnce (VČP)', null, $this->maskedBirthNumber($vcp));
        }

        $facts = $this->identityFacts($record);
        foreach ($facts as $field => $value) {
            $this->change($plan, $field, $this->factLabel($field), null, $value);
        }
        $plan['_steps']['identity_facts'] = $facts;
        $plan['_steps']['birth_surname'] = $record->birthSurname;
        foreach ($this->addresses($record) as $type => $address) {
            $plan['_steps']['addresses'][$type] = $address;
            $this->change($plan, $type . '_address', $this->addressLabel($type), null, $this->addressText($address));
        }
        if ($record->documentType === 'REGZEC25') {
            $this->planTaxResidence($plan, $record, []);
            $this->planA1Profile($plan, $record, null);
            $this->planPension((int) $plan['_supplier_id'], $plan, $record, null);
        }
        if ($record->documentType === 'REGZEC25' || $record->isCsszExport() || $record->isJmhzDerived()) {
            $this->newEmploymentTerms($plan, $record, $relationType);
            if ($start <= date('Y-m-d')) {
                if ($plan['_defer_activation'] === true) {
                    $this->deferredActivationWarning($plan);
                } else {
                    $plan['_steps']['activate_on'] = $start;
                    $this->change($plan, 'status', 'Stav vztahu', null, 'active');
                }
            }
        }
        if ($record->isCsszExport()) {
            $this->exportInsuranceEnd($plan, $record, null);
        }
        $plan['_steps']['identifiers'] = [
            'person' => $record->personIdentifier,
            'employment' => $record->employmentIdentifier,
        ];
        $this->educationInfo($plan, $record, null);

        return $this->finish($plan, $plan['blocker']);
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>
     */
    private function planUpdate(
        int $supplierId,
        string $environment,
        array $plan,
        RegistrationRecord $record,
        ?array $row,
        ?string $relationType,
    ): array {
        $employeeId = (int) $plan['_employee_id'];
        $decisive = $record->decisiveDate() ?? date('Y-m-d');
        $registers = ($record->documentType === 'REGZEC25' && $record->actionCode === 1)
            || ($record->documentType === 'PREZEC26' && $record->actionCode === 9)
            || ($record->isJmhzDerived() && $record->actionCode === 1)
            || $record->isCsszExport();

        if ($row === null) {
            if (!$registers) {
                $plan['warnings'][] = 'Pracovní vztah z věty se u osoby nepodařilo určit, zapíšou se jen '
                    . 'údaje osoby. Údaje vztahu (pracoviště, CZ-ISCO) doplňte na kartě vztahu.';
            } else {
                $start = $plan['employment']['start_on'];
                if ($record->isCsszExport() && $start === null) {
                    $plan['operation'] = 'create_employment';

                    return $this->finish($plan, self::exportWithoutStartBlocker(
                        'Osoba v evidenci je, ale otevřený pracovní vztah, ke kterému věta patří, se nenašel. Export',
                    ));
                }
                if ($start === null || $relationType === null) {
                    $plan['operation'] = 'create_employment';

                    return $this->finish($plan, 'Osoba v evidenci je, ale nový vztah z věty založit nejde — '
                        . 'chybí datum nástupu nebo druh vztahu. Založte vztah ručně na kartě osoby.');
                }
                $plan['_steps']['create_employment'] = [
                    'relation_type' => $relationType,
                    'planned_start_on' => $start,
                    'workload' => $record->workload,
                ];
                $this->change($plan, 'relation_type', 'Nový pracovní vztah', null, $relationType);
                $this->change($plan, 'start_on', 'Nástup', null, $start);
                if ($record->documentType === 'PREZEC26') {
                    $plan['warnings'][] = 'Částečné přihlášení druh vztahu neuvádí — vztah se založí jako '
                        . 'pracovní poměr. Jde-li o dohodu, změňte ho na kartě.';
                }
                $this->derivedStartWarnings($plan, $record);
            }
        }

        $this->planPersonFacts($supplierId, $plan, $record, $employeeId, $decisive);
        $this->warnFormerSurname($supplierId, $plan, $record, $employeeId, $decisive);
        $this->planPersonIdentifier($supplierId, $plan, $record, $employeeId);

        if ($record->isCsszExport()) {
            if ($row !== null) {
                $this->verifyExportActivity($supplierId, $plan, $record, $row, $relationType);
                // ID PPV v exportu dokládá, že ČSSZ vztah přihlášený má: jen
                // naplánovaný vztah se aktivuje stejně jako u přihlášky A1.
                if (in_array($row['status'], self::NOT_STARTED_STATUSES, true)) {
                    $start = $row['start_date'] ?? $record->startOn;
                    if ($row['start_date'] === null) {
                        $this->derivedStartWarnings($plan, $record);
                    }
                    if (is_string($start) && $start <= date('Y-m-d')) {
                        $plan['_steps']['activate_on'] = $start;
                        $this->change($plan, 'status', 'Stav vztahu', (string) $row['status'], 'active');
                    }
                }
            } elseif ($plan['_steps']['create_employment'] !== null && $relationType !== null) {
                $this->newEmploymentTerms($plan, $record, $relationType);
                $start = $plan['employment']['start_on'];
                if (is_string($start) && $start <= date('Y-m-d')) {
                    $plan['_steps']['activate_on'] = $start;
                    $this->change($plan, 'status', 'Stav vztahu', null, 'active');
                }
            }
            $this->exportInsuranceEnd($plan, $record, $row);
        }

        // Datum nástupu v registraci je doklad stejně jako hlášení nebo export:
        // REGZEC nahraný po hlášeních posune odhadnutý nástup na skutečný.
        if (($record->isJmhzDerived() || $record->isCsszExport() || $record->documentType === 'REGZEC25')
            && $row !== null
        ) {
            $current = $row['actual_start_date'] ?? $row['start_date'];
            // Podání dokládají dřívější nástup, než eviduje vztah (typicky vztah
            // založený z pozdějších hlášení, ke kterým přibyla starší): nástup se
            // posune na doložený den. Pozdější nástup import nikdy nezapisuje.
            if (is_string($current) && $record->startOn !== null && $record->startOn < $current) {
                if (in_array($row['status'], ['active', 'suspended', 'ended'], true)) {
                    $plan['_steps']['correct_start'] = ['from' => $current, 'to' => $record->startOn];
                    $this->change($plan, 'start_on', 'Nástup', $current, $record->startOn);
                }
            }
        }
        if ($record->isJmhzDerived()) {
            if ($row !== null) {
                if (in_array($row['status'], self::NOT_STARTED_STATUSES, true)) {
                    $start = $row['start_date'] ?? $record->startOn;
                    if (is_string($start) && $start <= date('Y-m-d')) {
                        $plan['_steps']['activate_on'] = $start;
                        $this->change($plan, 'status', 'Stav vztahu', (string) $row['status'], 'active');
                    }
                }
            } elseif ($plan['_steps']['create_employment'] !== null && $relationType !== null) {
                $this->newEmploymentTerms($plan, $record, $relationType);
                $start = $plan['employment']['start_on'];
                if (is_string($start) && $start <= date('Y-m-d')) {
                    $plan['_steps']['activate_on'] = $start;
                    $this->change($plan, 'status', 'Stav vztahu', null, 'active');
                }
            }
        }

        if ($record->documentType === 'REGZEC25') {
            $this->planInsurer($supplierId, $plan, $record, $employeeId, $decisive);
            $this->planAddresses($supplierId, $plan, $record, $employeeId, $decisive);
            if ($row !== null) {
                $this->planTerms($supplierId, $plan, $record, $row, $decisive);
            } elseif ($plan['_steps']['create_employment'] !== null && $relationType !== null) {
                $this->newEmploymentTerms($plan, $record, $relationType);
            }
            if ($record->actionCode === 1) {
                $start = $row['start_date'] ?? $plan['employment']['start_on'];
                $notStarted = $row === null || in_array($row['status'], self::NOT_STARTED_STATUSES, true);
                if ($notStarted && is_string($start) && $start <= date('Y-m-d')) {
                    if ($plan['_defer_activation'] === true) {
                        $this->deferredActivationWarning($plan);
                    } else {
                        $plan['_steps']['activate_on'] = $start;
                        $this->change($plan, 'status', 'Stav vztahu', $row['status'] ?? null, 'active');
                    }
                }
            }
            $this->educationInfo($plan, $record, $employeeId);
            $this->planTaxResidence($plan, $record, $this->lookup->taxResidences($supplierId, $employeeId));
            if (is_string($plan['_steps']['tax_residence']['identifier'] ?? null)
                && !$this->lookup->hasPersonIdentifier($supplierId, $employeeId, 'foreign_tax_identifier')
            ) {
                $plan['_steps']['foreign_tax_identifier'] = $plan['_steps']['tax_residence']['identifier'];
            }
            $this->planA1Profile($plan, $record, $row === null ? null : (int) $row['id']);
            $this->planPension($supplierId, $plan, $record, $employeeId);
        }

        $this->planIdentifiers($supplierId, $environment, $plan, $record, $employeeId, $row);

        $steps = $plan['_steps'];
        $hasWork = $steps['create_employment'] !== null
            || $steps['correct_start'] !== null
            || $steps['terms'] !== []
            || $steps['identity_facts'] !== []
            || $steps['birth_surname'] !== null
            || $steps['addresses'] !== []
            || $steps['health_insurer'] !== null
            || $steps['activate_on'] !== null
            || $steps['ecp'] !== null
            || $steps['name'] !== null
            || $steps['birth_number'] !== null
            || $steps['vcp'] !== null
            || $steps['tax_residence'] !== null
            || $steps['foreign_tax_identifier'] !== null
            || $steps['a1_profile'] !== null
            || $steps['pension'] !== null;
        $hasIdentifiers = $steps['identifiers']['person'] !== null || $steps['identifiers']['employment'] !== null;
        $plan['operation'] = match (true) {
            $steps['create_employment'] !== null => 'create_employment',
            $hasWork => 'update',
            $steps['terminate'] !== null => 'terminate',
            $hasIdentifiers => 'assign_identifiers',
            // Nepotvrzená nabídka ukončení musí jít vybrat, jinak by u věty,
            // která jinak nic nemění, nešla ani potvrdit.
            $plan['termination_offer'] !== null => 'terminate',
            default => 'none',
        };

        return $this->finish($plan, $plan['blocker']);
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>
     */
    private function planTermination(
        int $supplierId,
        string $environment,
        array $plan,
        RegistrationRecord $record,
        ?array $row,
    ): array {
        $plan['operation'] = 'terminate';
        if ($row === null) {
            return $this->finish($plan, 'Osoba v evidenci je, ale pracovní vztah, který věta odhlašuje, se '
                . 'nepodařilo určit. Ukončete vztah ručně na kartě osoby.');
        }
        $end = $record->endOn;
        if ($end === null) {
            return $this->finish($plan, 'Odhlášení nemá datum skončení vztahu, takže ho nejde zapsat.');
        }
        $status = (string) $row['status'];
        if ($status === 'ended') {
            if ($row['end_date'] === $end) {
                $plan['operation'] = 'none';
                $this->planIdentifiers($supplierId, $environment, $plan, $record, (int) $plan['_employee_id'], $row);
                $this->planTerminationDetails($supplierId, $plan, $record, (int) $row['id']);
                if ($plan['_steps']['identifiers']['person'] !== null
                    || $plan['_steps']['identifiers']['employment'] !== null
                ) {
                    $plan['operation'] = 'assign_identifiers';
                }
                if ($plan['_steps']['termination_reason'] !== null) {
                    $plan['operation'] = 'update';
                }

                return $this->finish($plan, $plan['blocker']);
            }

            return $this->finish($plan, "Vztah je už ukončený k {$row['end_date']}, ale odhlášení hlásí {$end}. "
                . 'Datum skončení opravte ručně na kartě vztahu.');
        }
        if (in_array($status, self::NOT_STARTED_STATUSES, true)) {
            return $this->finish($plan, 'Vztah ještě nezačal (je jen plánovaný), odhlášení k němu nejde zapsat. '
                . 'Pokud zaměstnanec nenastoupil, zapište to na kartě vztahu.');
        }
        if (!in_array($status, ['active', 'suspended'], true)) {
            return $this->finish($plan, 'Vztah není aktivní, odhlášení k němu nejde zapsat.');
        }
        $start = $row['actual_start_date'] ?? $row['start_date'];
        if (is_string($start) && $end < $start) {
            return $this->finish($plan, "Datum skončení {$end} předchází nástupu {$start}. Zkontrolujte soubor.");
        }
        $plan['_steps']['terminate'] = ['target' => 'ended', 'on' => $end];
        $this->change($plan, 'end_date', 'Skončení vztahu', $row['end_date'], $end);
        $this->planTerminationDetails($supplierId, $plan, $record, (int) $row['id']);
        $this->planIdentifiers($supplierId, $environment, $plan, $record, (int) $plan['_employee_id'], $row);

        return $this->finish($plan, $plan['blocker']);
    }

    /**
     * Úmrtí (`job@endbydeath`) se zapíše jako způsob skončení, pokud ho vztah
     * ještě nemá; kód důvodu ukončení pro úřad práce (`unemplcomp`) se do
     * evidence nepřebírá (vede se jako způsob a zákonný důvod skončení), jen se
     * na něj upozorní.
     *
     * @param array<string,mixed> $plan
     */
    private function planTerminationDetails(int $supplierId, array &$plan, RegistrationRecord $record, int $employmentId): void
    {
        if ($record->endedByDeath) {
            if ($this->lookup->hasTerminationRecord($supplierId, $employmentId)) {
                $plan['warnings'][] = 'Věta hlásí skončení vztahu úmrtím zaměstnance; způsob skončení je na kartě '
                    . 'vztahu už vyplněný, import ho nemění.';
            } else {
                $plan['_steps']['termination_reason'] = 'death';
                $this->change($plan, 'termination_method', 'Způsob skončení', null, 'death');
                $plan['warnings'][] = 'Věta hlásí skončení vztahu úmrtím zaměstnance, způsob skončení se zapíše '
                    . 'jako úmrtí. Vypořádání (mzda do dne úmrtí, daň) zkontrolujte na kartě vztahu.';
            }
        }
        if ($record->terminationReasonCode !== null) {
            $plan['warnings'][] = "Věta nese kód důvodu ukončení {$record->terminationReasonCode} z podkladů pro úřad "
                . 'práce. Import ho nepřebírá; způsob a důvod skončení doplňte na kartě vztahu v části Skončení vztahu.';
        }
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>
     */
    private function planNoShow(
        int $supplierId,
        string $environment,
        array $plan,
        RegistrationRecord $record,
        ?array $row,
    ): array {
        $plan['operation'] = 'terminate';
        if ($row === null && $plan['_registration_in_batch'] === true) {
            // Vztah teprve vznikne přihláškou z téže dávky; při zápisu se věta plánuje
            // znovu nad stavem po ní, takže vztah už najde.
            $plan['warnings'][] = 'Vztah, ke kterému věta hlásí nenastoupení, založí přihláška z téže dávky. '
                . 'Vyberte obě věty - přihlášený vztah zůstane plánovaný a hned se označí jako nenastoupený.';
            $this->change($plan, 'status', 'Stav vztahu', null, 'no_show');

            return $this->finish($plan, null);
        }
        if ($row === null) {
            return $this->finish($plan, 'Pracovní vztah, ke kterému věta hlásí nenastoupení, se nepodařilo '
                . 'určit. Zapište to ručně na kartě osoby.');
        }
        if ($row['status'] === 'no_show') {
            $plan['operation'] = 'none';

            return $this->finish($plan, null);
        }
        if (!in_array($row['status'], self::NOT_STARTED_STATUSES, true)) {
            return $this->finish($plan, 'Vztah už začal nebo skončil, nenastoupení k němu nejde zapsat. '
                . 'Zkontrolujte stav vztahu na kartě.');
        }
        $on = $row['start_date'] ?? ($record->decisiveDate() ?? date('Y-m-d'));
        $plan['_steps']['terminate'] = ['target' => 'no_show', 'on' => $on];
        $this->change($plan, 'status', 'Stav vztahu', (string) $row['status'], 'no_show');

        return $this->finish($plan, null);
    }

    /** @param array<string,mixed> $plan */
    private function deferredActivationWarning(array &$plan): void
    {
        $plan['warnings'][] = 'V dávce je i hlášení o nenastoupení téže osoby, proto vztah zůstane plánovaný a '
            . 'neaktivuje se; nenastoupení ho uzavře.';
    }

    /**
     * Dřívější příjmení z věty (`name@ona`, ID 10064) se do evidence ZÁMĚRNĚ
     * nezapisuje: historie jména vede každé příjmení s datem platnosti od-do
     * (`effective_from` je povinné, intervaly se nesmí překrývat) a věta datum
     * změny příjmení nenese. Vymyšlené datum by se objevilo na kartě osoby
     * jako doložená skutečnost, posunulo by hranici verzí identity, ze které
     * se detekují změny příjmení, a určovalo by pořadí příjmení v dalších
     * podáních. Příjmení se proto jen porovná s tím, co evidence už vede:
     * vede-li ho, mlčí se; jinak import řekne, co a kde doplnit ručně.
     *
     * @param array<string,mixed> $plan
     */
    private function warnFormerSurname(
        int $supplierId,
        array &$plan,
        RegistrationRecord $record,
        ?int $employeeId,
        string $decisive,
    ): void {
        $stated = self::surnameList($record->formerSurname);
        if ($stated === []) {
            return;
        }
        $label = implode(', ', $stated);
        $manual = 'Doplňte je s datem změny na kartě osoby v historii jména (Identita a adresy → Historie jména), '
            . 'aby je další podání REGZEC nesla.';
        $known = [];
        $matched = [];
        if ($employeeId !== null) {
            try {
                $today = date('Y-m-d');
                $onDate = min($decisive === '' ? $today : $decisive, $today);
                $identity = $this->registrations->identityAt($supplierId, $employeeId, $onDate)
                    ?? $this->registrations->identityAt($supplierId, $employeeId, $today);
                $current = trim((string) ($identity['last_name'] ?? ''));
                if ($current !== '') {
                    $known = self::surnameList($this->registrations->previousSurnames(
                        $supplierId,
                        $employeeId,
                        $onDate,
                        $current,
                    ));
                }
                // Cizí program může v `ona` uvést i rodné příjmení, které ale
                // evidence vede ve vlastním údaji, ne v historii jména.
                $birth = trim((string) ($identity['birth_surname'] ?? ''));
                $matched = $birth === '' ? $known : [...$known, $birth];
            } catch (\DomainException) {
                $known = [];
                $matched = [];
            }
        }
        $missing = array_values(array_filter(
            $stated,
            static fn (string $name): bool => !in_array(mb_strtolower($name), array_map('mb_strtolower', $matched), true),
        ));
        if ($missing === []) {
            return;
        }
        $plan['warnings'][] = $known === []
            ? "Věta uvádí dřívější příjmení „{$label}“ (ona), evidence žádné dřívější příjmení osoby nevede. "
                . 'Import ho nezapisuje, protože věta nenese datum, od kdy a do kdy platilo. ' . $manual
            : 'Věta uvádí dřívější příjmení „' . implode(', ', $missing) . '“ (ona), které historie jména osoby '
                . 'nevede (vede: ' . implode(', ', $known) . '). Import ho nezapisuje, protože věta nenese datum '
                . 'změny. ' . $manual;
    }

    /** @return list<string> */
    private static function surnameList(?string $value): array
    {
        if ($value === null) {
            return [];
        }
        $names = [];
        foreach (preg_split('/[,;]/u', $value) ?: [] as $part) {
            $name = trim($part);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** @param array<string,mixed> $plan */
    private function planPersonFacts(
        int $supplierId,
        array &$plan,
        RegistrationRecord $record,
        int $employeeId,
        string $decisive,
    ): void {
        try {
            $identity = $this->registrations->identityAt($supplierId, $employeeId, min($decisive, date('Y-m-d')))
                ?? $this->registrations->identityAt($supplierId, $employeeId, date('Y-m-d'));
        } catch (\DomainException $e) {
            $plan['warnings'][] = $e->getMessage();

            return;
        }
        if ($identity === null) {
            $plan['warnings'][] = 'Osoba nemá k rozhodnému dni evidovanou identitu, údaje o narození '
                . 'a občanství se nezapíšou. Doplňte je na kartě osoby.';

            return;
        }
        $facts = [];
        foreach ($this->identityFacts($record) as $field => $value) {
            $current = self::text($identity[$field] ?? null);
            if ($current !== $value) {
                $facts[$field] = $value;
                $this->change($plan, $field, $this->factLabel($field), $current, $value);
            }
        }
        $birthDate = self::text($identity['birth_date'] ?? null);
        $importedBirthDate = $plan['person']['birth_date'] ?? null;
        if ($birthDate === null && is_string($importedBirthDate)) {
            $facts['birth_date'] = $importedBirthDate;
            $this->change($plan, 'birth_date', 'Datum narození', null, $importedBirthDate);
        }
        $plan['_steps']['identity_facts'] = $facts;
        // Osoba založená z hlášení větve A má zástupné jméno, které čeká na
        // skutečné. Věta se jménem ho nahradí; skutečné jméno import nemění.
        $placeholder = JmhzDerivedRegistrations::isPlaceholderName(
            self::text($identity['first_name'] ?? null),
            self::text($identity['last_name'] ?? null),
        );
        if ($placeholder && $record->firstName !== null && $record->lastName !== null) {
            $plan['_steps']['name'] = [
                'identity_id' => (int) $identity['id'],
                'first_name' => $record->firstName,
                'last_name' => $record->lastName,
                'full_name' => (string) $record->fullName(),
            ];
            $this->change(
                $plan,
                'full_name',
                'Jméno a příjmení',
                self::text(($identity['first_name'] ?? '') . ' ' . ($identity['last_name'] ?? '')),
                $record->fullName(),
            );
        }
        if ($record->birthSurname !== null && self::text($identity['birth_surname'] ?? null) === null) {
            $plan['_steps']['birth_surname'] = $record->birthSurname;
            $this->change($plan, 'birth_surname', 'Rodné příjmení', null, $record->birthSurname);
        }
        foreach (['first_name' => $record->firstName, 'last_name' => $record->lastName] as $field => $value) {
            $current = self::text($identity[$field] ?? null);
            if (!$placeholder && $value !== null && $current !== null && $current !== $value) {
                $plan['warnings'][] = ($field === 'first_name' ? 'Jméno' : 'Příjmení')
                    . " ve větě ({$value}) se liší od evidence ({$current}). Import ho nemění — "
                    . 'jde-li o skutečnou změnu, zapište ji na kartě osoby.';
            }
        }
        $birthDate = self::text($identity['birth_date'] ?? null);
        if ($record->birthDate !== null && $birthDate !== null && $birthDate !== $record->birthDate) {
            $plan['warnings'][] = "Datum narození ve větě ({$record->birthDate}) se liší od evidence "
                . "({$birthDate}). Ověřte, že jde o tutéž osobu.";
        }
    }

    /**
     * Rodné číslo (u cizince EČP), které karta osoby ještě nevede. Vedené
     * číslo import nepřepisuje — podle něj se osoba páruje.
     *
     * @param array<string,mixed> $plan
     */
    private function planPersonIdentifier(int $supplierId, array &$plan, RegistrationRecord $record, int $employeeId): void
    {
        [$birthNumber, $ecp] = $this->birthNumber($record);
        if ($birthNumber !== null && !$this->lookup->hasPersonIdentifier($supplierId, $employeeId, 'birth_number')) {
            $plan['_steps']['birth_number'] = $birthNumber;
            $this->change($plan, 'birth_number', 'Rodné číslo', null, $this->maskedBirthNumber($birthNumber));
        } elseif ($birthNumber === null && $ecp !== null
            && !$this->lookup->hasPersonIdentifier($supplierId, $employeeId, 'ecp')
        ) {
            $plan['_steps']['ecp'] = $ecp;
            $this->change($plan, 'ecp', 'Evidenční číslo pojištěnce (EČP)', null, $this->maskedBirthNumber($ecp));
        }
        $vcp = $this->validVcp($record);
        if ($vcp !== null && !$this->lookup->hasPersonIdentifier($supplierId, $employeeId, 'vcp')) {
            $plan['_steps']['vcp'] = $vcp;
            $this->change($plan, 'vcp', 'Variabilní číslo pojištěnce (VČP)', null, $this->maskedBirthNumber($vcp));
        }
    }

    /** VČP z věty ve tvaru, který karta osoby přijme; `null`, když věta žádné nenese nebo je vadné. */
    private function validVcp(RegistrationRecord $record): ?string
    {
        if ($record->vcp === null) {
            return null;
        }
        $value = (string) preg_replace('/\s+/', '', $record->vcp);

        return PayrollVcp::isValid($value) ? $value : null;
    }

    /**
     * Osoba se našla podle jiného údaje (OIČ, ID PPV, VČP) a věta nese rodné číslo
     * či EČP, které karta vede jinak. Import vedený identifikátor nepřepisuje, ale
     * účetní se musí dozvědět, že věta možná patří někomu jinému.
     *
     * @param array<string,mixed> $plan
     */
    private function warnOnIdentifierMismatch(
        int $supplierId,
        array &$plan,
        int $employeeId,
        ?string $birthNumber,
        ?string $ecp,
        RegistrationRecord $record,
    ): void {
        $checks = [];
        if ($birthNumber !== null) {
            $checks[] = ['birth_number', $birthNumber, 'Rodné číslo'];
        } elseif ($ecp !== null) {
            $checks[] = ['ecp', $ecp, 'Evidenční číslo pojištěnce (EČP)'];
        }
        $vcp = $this->validVcp($record);
        if ($vcp !== null) {
            $checks[] = ['vcp', $vcp, 'VČP'];
        }
        foreach ($checks as [$type, $value, $label]) {
            if (!$this->lookup->hasPersonIdentifier($supplierId, $employeeId, $type)) {
                continue;
            }
            $hash = $this->sensitiveData->lookupHash($value, PayrollSensitiveField::PERSONAL_IDENTIFIER, $supplierId);
            if (!in_array($employeeId, $this->lookup->employeesByIdentifierHash($supplierId, $type, $hash), true)) {
                $plan['warnings'][] = "{$label} ve větě se liší od údaje vedeného u osoby (osoba se našla podle jiného "
                    . 'identifikátoru). Ověřte, že věta patří této osobě; import vedené číslo nepřepisuje.';
            }
        }
    }

    /**
     * Daňová rezidence z `taxidrezid`. Zapíše se jen do prázdné (nebo jen
     * neověřené) řady zákonné evidence, a to od nástupu: věta dokládá stav,
     * ve kterém osoba ve vztahu je, `statchang` bývá jen den vyplnění věty.
     * Ověřenou rezidenci import nemění, jiný stát jen ohlásí.
     *
     * @param array<string,mixed> $plan
     * @param list<array{residence:string,country_code:?string,effective_from:string,effective_to:?string}> $current
     */
    private function planTaxResidence(array &$plan, RegistrationRecord $record, array $current): void
    {
        $imported = $record->taxResidency;
        if ($imported === null) {
            return;
        }
        $country = (string) $imported['country_code'];
        $residence = $country === 'CZ' ? 'czech-resident' : 'non-resident';
        $verified = array_values(array_filter(
            $current,
            static fn (array $row): bool => $row['residence'] !== 'unverified',
        ));
        if ($verified !== []) {
            $decisive = $record->decisiveDate() ?? date('Y-m-d');
            foreach ($verified as $row) {
                if ($row['effective_from'] <= $decisive && ($row['effective_to'] === null || $row['effective_to'] >= $decisive)
                    && ($row['residence'] !== $residence || ($residence === 'non-resident' && $row['country_code'] !== $country))
                ) {
                    $plan['warnings'][] = "Daňová rezidence ve větě ({$country}) se liší od zákonné evidence "
                        . '(' . ($row['country_code'] ?? $row['residence']) . '). Import ji nemění, zkontrolujte '
                        . 'ji na kartě osoby (Zákonná evidence).';
                }
            }

            return;
        }
        $plan['_steps']['tax_residence'] = [
            'residence' => $residence,
            'country_code' => $country,
            'identifier' => $country === 'CZ' ? null : $imported['identifier'],
        ];
        $this->change($plan, 'tax_residence', 'Daňová rezidence', null, $country . ' (od nástupu)');
    }

    /**
     * Údaje věty REGZEC, které evidence vede jen v profilu registrace A1
     * (postavení v zaměstnání, režim práce, nepřetržitý provoz, vedoucí
     * pozice, vzdělání, důchod, cizí předpisy, strukturované adresy…).
     * Profil, ze kterého aplikace sama už registraci podala, se nemění.
     *
     * @param array<string,mixed> $plan
     */
    private function planA1Profile(array &$plan, RegistrationRecord $record, ?int $employmentId): void
    {
        if ($record->a1Profile === []) {
            return;
        }
        $base = [];
        if ($employmentId !== null) {
            try {
                $view = $this->identities->a1ProfileView((int) $plan['_supplier_id'], $employmentId);
            } catch (\Throwable $e) {
                $plan['warnings'][] = 'Profil registrace A1 se z věty nedoplní: ' . $e->getMessage();

                return;
            }
            if (($view['draft']['submitted'] ?? false) === true) {
                $plan['warnings'][] = 'Registraci vztahu už podala aplikace, profil registrace A1 import nemění. '
                    . 'Rozdíly proti větě opravte změnovým podáním.';

                return;
            }
            $base = is_array($view['profile']) ? $view['profile'] : (array) ($view['draft']['suggested'] ?? []);
        }
        $changed = [];
        foreach (self::flatten($record->a1Profile) as $path => $value) {
            if (self::scalar(self::at($base, $path)) !== self::scalar($value)) {
                $changed[] = PayrollRegistrationFieldVocabulary::label((string) preg_replace('/\.\d+(\.|$)/', '$1', $path));
            }
        }
        if ($changed === []) {
            return;
        }
        $plan['_steps']['a1_profile'] = $record->a1Profile;
        $this->change($plan, 'a1_profile', 'Profil registrace A1', null, implode(', ', array_unique($changed)));
    }

    /**
     * Pobíraný důchod z věty (`pens`: druh, pobírán od, předčasný, snížený
     * důchodový věk) do zákonné evidence osoby, ze které ho čtou ELDP i JMHZ.
     *
     * Jen do PRÁZDNÉ řady: evidenci, kterou už vede účetní, import nemění
     * (rozdíl jen ohlásí). Profil A1 dostane tytéž údaje svou cestou
     * ({@see planA1Profile()}), tady jde o zdroj kódu D a odečtených dob.
     *
     * @param array<string,mixed> $plan
     */
    private function planPension(int $supplierId, array &$plan, RegistrationRecord $record, ?int $employeeId): void
    {
        $pension = $record->a1Profile['pension'] ?? null;
        $type = is_array($pension) ? ($pension['type_code'] ?? null) : null;
        $from = is_array($pension) ? ($pension['received_from'] ?? null) : null;
        if (!is_string($type) || !is_string($from)) {
            return;
        }
        $early = ($pension['early_retirement'] ?? false) === true;
        if (!in_array($type, PayrollPensionStatus::PENSION_TYPE_CODES, true)
            || ($early && $type !== PayrollPensionStatus::OLD_AGE)
        ) {
            $plan['warnings'][] = "Důchod ve větě (druh {$type}" . ($early ? ', předčasný' : '')
                . ') neodpovídá číselníku ČSSZ, do zákonné evidence se nezapíše. Doplňte ho na kartě '
                . 'osoby (Zákonná evidence, Pobíraný důchod).';

            return;
        }
        if ($employeeId !== null) {
            $current = $this->lookup->pensions($supplierId, $employeeId);
            if ($current !== []) {
                foreach ($current as $row) {
                    if ($row['pension_type_code'] === $type && $row['effective_from'] === $from) {
                        return;
                    }
                }
                $plan['warnings'][] = "Důchod ve větě (druh {$type} od {$from}) se liší od zákonné evidence osoby. "
                    . 'Import ji nemění, zkontrolujte ji na kartě osoby (Zákonná evidence, Pobíraný důchod).';

                return;
            }
        }
        $plan['_steps']['pension'] = [
            'pension_type_code' => $type,
            'early_retirement' => $early,
            'reduced_retirement_age' => ($pension['reduced_retirement_age'] ?? false) === true,
            'effective_from' => $from,
        ];
        $this->change($plan, 'pension', 'Pobíraný důchod', null, "druh {$type}" . ($early ? ' (předčasný)' : '') . " od {$from}");
    }

    /**
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private static function flatten(array $values, string $prefix = ''): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value) && $value !== []) {
                $result += self::flatten($value, $path);
            } else {
                $result[$path] = $value;
            }
        }

        return $result;
    }

    /** @param array<string,mixed> $root */
    private static function at(array $root, string $path): mixed
    {
        $node = $root;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    private static function scalar(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'A' : 'N',
            is_scalar($value) => trim((string) $value),
            default => null,
        };
    }

    /** @param array<string,mixed> $plan */
    private function planInsurer(
        int $supplierId,
        array &$plan,
        RegistrationRecord $record,
        int $employeeId,
        string $decisive,
    ): void {
        $imported = $this->insurer($plan, $record->healthInsurerCode);
        if ($imported === null) {
            return;
        }
        $monthStart = substr($decisive, 0, 7) . '-01';
        $current = $this->lookup->healthInsurerAt($supplierId, $employeeId, $monthStart);
        if ($current === $imported) {
            return;
        }
        // Stará věta by uzavřela dnešní otevřenou verzi pojišťovny a protáhla
        // svou hodnotu dopředu. Má-li evidence verzi pozdější než věta, věta
        // je starší než evidence a nepíše se (jako u podmínek vztahu).
        if ($this->lookup->hasHealthCoverageAfter($supplierId, $employeeId, $monthStart)) {
            $plan['warnings'][] = "Věta ({$decisive}) je starší než evidence: pojišťovna osoby se po tomto dni už "
                . 'měnila, proto se z věty nezapíše. Zkontrolujte pojišťovnu na kartě osoby (Zákonná evidence).';

            return;
        }
        $plan['_steps']['health_insurer'] = ['code' => $imported, 'on' => $decisive];
        $this->change($plan, 'health_insurer_code', 'Zdravotní pojišťovna', $current, $imported);
    }

    /** @param array<string,mixed> $plan */
    private function planAddresses(
        int $supplierId,
        array &$plan,
        RegistrationRecord $record,
        int $employeeId,
        string $decisive,
    ): void {
        foreach ($this->addresses($record) as $type => $address) {
            $current = $this->lookup->addressAt($supplierId, $employeeId, $type, $decisive);
            $currentText = $current === null ? null : $this->addressText($current);
            if ($current !== null && self::sameAddress($current, $address)) {
                continue;
            }
            // Adresa platná až po dni věty znamená, že věta je starší než evidence.
            if ($this->lookup->hasAddressAfter($supplierId, $employeeId, $type, $decisive)) {
                $plan['warnings'][] = "Věta ({$decisive}) je starší než evidence: " . mb_strtolower($this->addressLabel($type))
                    . ' osoby se po tomto dni už měnila, proto se z věty nezapíše. Zkontrolujte ji na kartě osoby.';
                continue;
            }
            $plan['_steps']['addresses'][$type] = $address + ['on' => $decisive];
            $this->change($plan, $type . '_address', $this->addressLabel($type), $currentText, $this->addressText($address));
        }
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $row
     */
    private function planTerms(
        int $supplierId,
        array &$plan,
        RegistrationRecord $record,
        array $row,
        string $decisive,
    ): void {
        if (!in_array($row['status'], self::OPEN_STATUSES, true)) {
            return;
        }
        $current = $this->employments->currentTerms($supplierId, (int) $row['id']);
        if ($current === null) {
            return;
        }
        $imported = $this->importedTerms($plan, $record, (string) $row['relation_type'], $current);
        $changes = [];
        foreach ($imported as $field => $value) {
            $stored = self::text($current[$field] ?? null);
            if ($stored !== $value) {
                $changes[$field] = $value;
            }
        }
        if ($changes === []) {
            return;
        }
        if ((string) $current['effective_from'] > $decisive) {
            $plan['warnings'][] = 'Ke dni věty platila starší verze sjednaných podmínek než ta dnešní, '
                . 'pracoviště ani druh činnosti se proto nezapíšou. Opravte je na kartě vztahu.';

            return;
        }
        foreach ($changes as $field => $value) {
            $this->change($plan, $field, $this->termLabel($field), self::text($current[$field] ?? null), $value);
        }
        $plan['_steps']['terms'] = $changes;
    }

    /**
     * Podmínky vztahu, které věta nese. Druh činnosti se převezme jen tehdy,
     * když sedí na druh vztahu — jinak by ho validátor podmínek stejně odmítl.
     *
     * @param array<string,mixed> $plan
     * @param array<string,mixed>|null $current
     * @return array<string,string>
     */
    private function importedTerms(array &$plan, RegistrationRecord $record, string $relationType, ?array $current): array
    {
        $terms = [];
        if ($record->activityCode !== null) {
            /*
             * Cizí mzdové programy posílají `relDetail="1"` i u dohod a ČSSZ
             * takové podání přijímá. Evidence ale u DPP/DPČ bližší určení
             * vztahu nevede, takže se u nich použije výchozí hodnota druhu
             * vztahu — jinak by import hlásil nesoulad, který žádným není.
             */
            $defaultDetail = PayrollEmploymentJmhzActivityFamily::firstRelationDefaults($relationType)[1];
            $detail = $record->relationshipDetailCode
                ?? self::text($current['jmhz_relationship_detail_code'] ?? null)
                ?? $defaultDetail;
            if (!PayrollEmploymentJmhzActivityFamily::matches($relationType, $record->activityCode, $detail)
                && PayrollEmploymentJmhzActivityFamily::matches($relationType, $record->activityCode, $defaultDetail)
            ) {
                // Evidence vede bližší určení vztahu jen jako 1 (u dohod vůbec). Věta
                // s jiným určením u pracovního poměru (2-9) se zapíše s výchozím
                // a účetní se to musí dozvědět. Cizí programy posílají u dohod
                // relDetail 1, to není nesoulad.
                if ($defaultDetail !== null && $record->relationshipDetailCode !== null
                    && $record->relationshipDetailCode !== $defaultDetail
                ) {
                    $plan['warnings'][] = "Bližší určení vztahu „{$record->relationshipDetailCode}“ (relDetail) ve větě "
                        . "evidence nevede, zapíše se „{$defaultDetail}“. Zkontrolujte, že odpovídá smlouvě, a případně "
                        . 'podejte změnu u ČSSZ.';
                }
                $detail = $defaultDetail;
            }
            if (PayrollEmploymentJmhzActivityFamily::matches($relationType, $record->activityCode, $detail)) {
                $terms['activity_code'] = $record->activityCode;
                if ($detail !== null) {
                    $terms['jmhz_relationship_detail_code'] = $detail;
                }
            } else {
                $plan['warnings'][] = "Druh činnosti „{$record->activityCode}“ ve větě neodpovídá druhu "
                    . 'pracovního vztahu v evidenci, nezapisuje se.';
            }
        }
        if ($record->professionCode !== null) {
            if (preg_match('/^[0-9]{4,5}$/D', $record->professionCode) === 1) {
                $terms['cz_isco_code'] = $record->professionCode;
            } else {
                $plan['warnings'][] = "Kód profese „{$record->professionCode}“ není kód CZ-ISCO, nezapisuje se.";
            }
        }
        $workPlace = $record->workplaceCity ?? $record->contractPlace;
        if ($workPlace !== null) {
            $terms['work_place'] = mb_substr($workPlace, 0, 255);
        }
        if ($record->workplaceMunicipalityCode !== null) {
            if ($record->workplaceCity !== null) {
                $terms['jmhz_workplace_municipality_code'] = $record->workplaceMunicipalityCode;
                $terms['jmhz_workplace_country_code'] = 'CZ';
            } else {
                $plan['warnings'][] = 'Kód obce pracoviště je ve větě bez názvu obce, nezapisuje se.';
            }
        }

        return $terms;
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed>|null $row
     */
    private function planIdentifiers(
        int $supplierId,
        string $environment,
        array &$plan,
        RegistrationRecord $record,
        int $employeeId,
        ?array $row,
    ): void {
        $person = null;
        if ($record->personIdentifier !== null) {
            $person = $this->identifierPlan(
                $plan,
                'Osobní identifikační číslo (OIČ)',
                fn (): ?bool => $this->identities->activePersonExternalIdMatches(
                    $supplierId,
                    $employeeId,
                    $environment,
                    (string) $record->personIdentifier,
                ),
                fn (): ?string => $this->registrations->activePersonExternalId(
                    $supplierId,
                    $employeeId,
                    $environment,
                    'ik_mpsv',
                )['source_kind'] ?? null,
                $record->personIdentifier,
            );
        }
        $employment = null;
        if ($record->employmentIdentifier !== null) {
            if ($row === null) {
                if ($plan['_steps']['create_employment'] !== null) {
                    $employment = $record->employmentIdentifier;
                }
            } else {
                $employment = $this->identifierPlan(
                    $plan,
                    'Identifikátor pracovního vztahu (ID PPV)',
                    fn (): ?bool => $this->identities->activeEmploymentExternalIdMatches(
                        $supplierId,
                        (int) $row['id'],
                        $environment,
                        (string) $record->employmentIdentifier,
                    ),
                    fn (): ?string => $this->registrations->activeExternalId(
                        $supplierId,
                        (int) $row['id'],
                        $environment,
                        'id_ppv',
                    )['source_kind'] ?? null,
                    $record->employmentIdentifier,
                );
            }
        }
        if ($row === null && $plan['_steps']['create_employment'] === null && ($person !== null || $employment !== null)) {
            $plan['warnings'][] = 'Identifikátory od ČSSZ se ukládají k pracovnímu vztahu, a ten se nepodařilo '
                . 'určit. Doplňte je ručně na kartě vztahu.';
            $person = null;
            $employment = null;
        }
        $plan['_steps']['identifiers'] = ['person' => $person, 'employment' => $employment];
        if ($person !== null) {
            $this->change($plan, 'person_external_identifier', 'OIČ (IK MPSV)', null, $person);
        }
        if ($employment !== null) {
            $this->change($plan, 'employment_external_identifier', 'ID pracovního vztahu (ID PPV)', null, $employment);
        }
    }

    /**
     * @param array<string,mixed> $plan
     * @param callable():?bool $matches
     * @param callable():?string $sourceKind
     */
    private function identifierPlan(
        array &$plan,
        string $label,
        callable $matches,
        callable $sourceKind,
        string $value,
    ): ?string {
        try {
            $same = $matches();
        } catch (\InvalidArgumentException $e) {
            $plan['warnings'][] = $e->getMessage();

            return null;
        }
        if ($same === null) {
            return $value;
        }
        if ($same) {
            return null;
        }
        if ($sourceKind() === 'trusted_receipt') {
            $plan['blocker'] = "{$label} ve větě se liší od čísla, které v evidenci stojí podle protokolu ČSSZ. "
                . 'Věta nejspíš patří jiné osobě nebo vztahu — zkontrolujte ji a zpracujte ručně.';
        } else {
            $plan['warnings'][] = "{$label} ve větě se liší od ručně zapsaného čísla v evidenci. Import ho "
                . 'nepřepisuje; opravte ho na kartě vztahu, pokud je v evidenci překlep.';
        }

        return null;
    }

    /**
     * @return array{employee_id:?int,matched_by:?string,id_ppv_employment_id:?int,blocker:?string,candidates:list<array<string,mixed>>}
     */
    private function matchPerson(
        int $supplierId,
        string $environment,
        RegistrationRecord $record,
        ?string $birthNumber,
        ?string $ecp,
    ): array {
        /** @var array<int,string> $found */
        $found = [];
        if ($record->personIdentifier !== null) {
            $hash = $this->sensitiveData->lookupHash(
                $record->personIdentifier,
                PayrollSensitiveField::PERSON_EXTERNAL_IDENTIFIER,
                $supplierId,
            );
            foreach ($this->lookup->employeesByPersonExternalIdHash($supplierId, $environment, $hash) as $id) {
                $found[$id] ??= 'oic';
            }
        }
        if ($birthNumber !== null || $ecp !== null) {
            $hash = $this->sensitiveData->lookupHash(
                (string) ($birthNumber ?? $ecp),
                PayrollSensitiveField::PERSONAL_IDENTIFIER,
                $supplierId,
            );
            $type = $birthNumber !== null ? 'birth_number' : 'ecp';
            foreach ($this->lookup->employeesByIdentifierHash($supplierId, $type, $hash) as $id) {
                $found[$id] ??= 'birth_number';
            }
        }
        $vcp = $this->validVcp($record);
        if ($vcp !== null) {
            $hash = $this->sensitiveData->lookupHash($vcp, PayrollSensitiveField::PERSONAL_IDENTIFIER, $supplierId);
            foreach ($this->lookup->employeesByIdentifierHash($supplierId, 'vcp', $hash) as $id) {
                $found[$id] ??= 'vcp';
            }
        }
        $idPpvEmploymentId = null;
        if ($record->employmentIdentifier !== null) {
            $hit = $this->registrations->employmentByExternalIdValueHash(
                $supplierId,
                $environment,
                'id_ppv',
                $this->sensitiveData->lookupHash(
                    $record->employmentIdentifier,
                    PayrollSensitiveField::EMPLOYMENT_EXTERNAL_IDENTIFIER,
                    $supplierId,
                ),
            );
            if ($hit !== null) {
                $idPpvEmploymentId = $hit['employment_id'];
                $found[$hit['employee_id']] ??= 'id_ppv';
            }
        }
        // Odvozená věta z formuláře větve B žádný identifikátor nenese; bez
        // párování podle jména by se osoba při každém importu založila znovu.
        if ($found === [] && $record->isJmhzDerived() && $record->personIdentifier === null
            && $record->employmentIdentifier === null && $record->firstName !== null
            && $record->lastName !== null && $record->birthDate !== null
        ) {
            foreach ($this->lookup->employeesByNameAndBirthDate(
                $supplierId,
                $record->firstName,
                $record->lastName,
                $record->birthDate,
            ) as $id) {
                $found[$id] ??= 'name_birth_date';
            }
        }

        if (count($found) > 1) {
            $candidates = [];
            foreach (array_keys($found) as $employeeId) {
                $candidates[] = [
                    'employee_id' => $employeeId,
                    'employment_id' => null,
                    'label' => $this->lookup->employeeName($supplierId, $employeeId) ?? ('Osoba #' . $employeeId),
                ];
            }

            return [
                'employee_id' => null,
                'matched_by' => null,
                'id_ppv_employment_id' => null,
                'blocker' => 'Údaje ve větě (rodné číslo, VČP, OIČ, ID PPV) ukazují na různé osoby v evidenci. '
                    . 'Nejspíš jde o duplicitní kartu nebo překlep — vyjasněte to ručně a import zopakujte.',
                'candidates' => $candidates,
            ];
        }
        $employeeId = array_key_first($found);

        return [
            'employee_id' => $employeeId,
            'matched_by' => $employeeId === null ? null : $found[$employeeId],
            'id_ppv_employment_id' => $idPpvEmploymentId,
            'blocker' => null,
            'candidates' => [],
        ];
    }

    /**
     * @return array{row:?array<string,mixed>,blocker:?string,candidates:list<array<string,mixed>>,warnings:list<string>}
     */
    private function matchEmployment(
        int $supplierId,
        int $employeeId,
        RegistrationRecord $record,
        ?string $relationType,
        ?int $idPpvEmploymentId,
    ): array {
        $rows = $this->lookup->employments($supplierId, $employeeId);
        if ($idPpvEmploymentId !== null) {
            foreach ($rows as $row) {
                if ($row['id'] === $idPpvEmploymentId) {
                    return ['row' => $row, 'blocker' => null, 'candidates' => [], 'warnings' => []];
                }
            }
        }

        $start = $record->documentType === 'PREZEC26' ? $record->expectedStartOn : $record->startOn;
        if ($start !== null) {
            $sameStart = array_values(array_filter(
                $rows,
                static fn (array $row): bool => $row['start_date'] === $start && $row['status'] !== 'archived',
            ));
            if (count($sameStart) === 1) {
                return ['row' => $sameStart[0], 'blocker' => null, 'candidates' => [], 'warnings' => []];
            }
            if (count($sameStart) > 1) {
                return $this->ambiguousEmployment($sameStart);
            }
        }

        // Částečné přihlášení se podává PŘED nástupem, k už běžícímu vztahu tedy patřit nemůže.
        $statuses = $record->documentType === 'PREZEC26' ? self::NOT_STARTED_STATUSES : self::OPEN_STATUSES;
        // Odhláška, změna a nenastoupení `job@sme` obvykle nenesou, takže kód 1-9
        // bez příznaku patří pracovnímu poměru i zaměstnání malého rozsahu.
        // Víc shod pak skončí jako nejednoznačný vztah, ne jako nenalezený.
        $types = $relationType === null ? null : [$relationType];
        if ($relationType === 'employment' && $record->documentType === 'REGZEC25'
            && in_array($record->actionCode, [2, 3, 4, 8], true)
        ) {
            $types = ['employment', 'small_scale_employment'];
        }
        $open = array_values(array_filter(
            $rows,
            static fn (array $row): bool => in_array($row['status'], $statuses, true)
                && ($types === null || in_array($row['relation_type'], $types, true)),
        ));
        // Export vztah jen ověřuje: druh vztahu, který v evidenci nesedí, je
        // důvod k varování, ne k založení druhého vztahu vedle existujícího.
        if ($open === [] && $record->isCsszExport()) {
            $open = array_values(array_filter(
                $rows,
                static fn (array $row): bool => in_array($row['status'], $statuses, true),
            ));
        }
        if ($open === [] && $record->actionCode === 2 && $record->endOn !== null) {
            $open = array_values(array_filter(
                $rows,
                static fn (array $row): bool => $row['status'] === 'ended' && $row['end_date'] === $record->endOn
                    && ($types === null || in_array($row['relation_type'], $types, true)),
            ));
        }
        if (count($open) > 1) {
            return $this->ambiguousEmployment($open);
        }
        if ($open === []) {
            return ['row' => null, 'blocker' => null, 'candidates' => [], 'warnings' => []];
        }
        $warnings = [];
        if ($start !== null && $open[0]['start_date'] !== $start && !$record->isCsszExport()) {
            $warnings[] = "Nástup ve vztahu {$open[0]['code']} ({$open[0]['start_date']}) se liší od věty ({$start}). "
                . 'Datum nástupu import nemění — zkontrolujte ho na kartě vztahu.';
        }

        return ['row' => $open[0], 'blocker' => null, 'candidates' => [], 'warnings' => $warnings];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{row:null,blocker:string,candidates:list<array<string,mixed>>,warnings:list<string>}
     */
    private function ambiguousEmployment(array $rows): array
    {
        $candidates = [];
        foreach ($rows as $row) {
            $candidates[] = [
                'employee_id' => $row['employee_id'],
                'employment_id' => $row['id'],
                'label' => $row['code'] . ' · ' . $row['relation_type'] . ' · od ' . ($row['start_date'] ?? '—'),
            ];
        }

        return [
            'row' => null,
            'blocker' => 'Osoba má víc pracovních vztahů, na které věta sedí, a ze souboru nejde poznat, '
                . 'kterého se týká. Zpracujte větu ručně na kartě osoby.',
            'candidates' => $candidates,
            'warnings' => [],
        ];
    }

    /**
     * Podmínky nového vztahu z věty. Výchozí druh činnosti druhu vztahu se
     * nezapisuje — založení vztahu ho nastaví samo.
     *
     * @param array<string,mixed> $plan
     */
    private function newEmploymentTerms(array &$plan, RegistrationRecord $record, string $relationType): void
    {
        $terms = $this->importedTerms($plan, $record, $relationType, null);
        [$defaultActivity, $defaultDetail] = PayrollEmploymentJmhzActivityFamily::firstRelationDefaults($relationType);
        if (($terms['activity_code'] ?? $defaultActivity) === $defaultActivity
            && ($terms['jmhz_relationship_detail_code'] ?? $defaultDetail) === $defaultDetail
        ) {
            unset($terms['activity_code'], $terms['jmhz_relationship_detail_code']);
        }
        foreach ($terms as $field => $value) {
            $this->change($plan, $field, $this->termLabel($field), null, $value);
        }
        $plan['_steps']['terms'] = $terms;
    }

    /**
     * Export zaměstnanců ČSSZ druh činnosti existujícího vztahu jen ověří:
     * nesoulad je varování, podmínky vztahu import nemění.
     *
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $row
     */
    private function verifyExportActivity(
        int $supplierId,
        array &$plan,
        RegistrationRecord $record,
        array $row,
        ?string $relationType,
    ): void {
        if ($relationType !== null && $row['relation_type'] !== $relationType) {
            $plan['warnings'][] = "Druh vztahu {$row['code']} v evidenci ({$row['relation_type']}) neodpovídá "
                . "exportu ČSSZ ({$relationType}"
                . ($record->smallScale ? ', zaměstnání malého rozsahu' : '')
                . '). Import ho nemění — zkontrolujte vztah a případně podejte opravu u ČSSZ.';
        }
        if ($record->activityCode === null) {
            return;
        }
        $current = self::text($this->employments->currentTerms($supplierId, (int) $row['id'])['activity_code'] ?? null);
        if ($current !== null && $current !== $record->activityCode) {
            $plan['warnings'][] = "Druh činnosti ve vztahu {$row['code']} ({$current}) se liší od exportu ČSSZ "
                . "({$record->activityCode}). Import ho nemění — zkontrolujte, který je správný.";
        }
    }

    /**
     * Konec pojistného vztahu z exportu ČSSZ (`PojistnyVztahDo`). Nový vztah se
     * k němu rovnou ukončí; u vztahu, který evidence už vede, import skončení
     * nezapisuje (může ho doložit odhlášení nebo hlášení) a jen upozorní.
     *
     * @param array<string,mixed> $plan
     * @param array<string,mixed>|null $row
     */
    private function exportInsuranceEnd(array &$plan, RegistrationRecord $record, ?array $row): void
    {
        $end = $record->insuranceTo;
        if ($end === null) {
            return;
        }
        if (!$record->insuranceEndIsEmploymentEnd()) {
            $plan['warnings'][] = "Export uvádí konec pojištění {$end}. U zaměstnání malého rozsahu a DPP to "
                . 'nemusí být skončení vztahu (pojištění trvá jen v měsících s rozhodným příjmem), import ho '
                . 'proto nezapisuje.';

            return;
        }
        if ($row !== null) {
            if ($row['end_date'] === null && in_array($row['status'], self::OPEN_STATUSES, true)) {
                $start = $row['actual_start_date'] ?? $row['start_date'];
                if ($end > date('Y-m-d') || (is_string($start) && $end < $start)) {
                    $plan['warnings'][] = "Export uvádí konec pojistného vztahu {$end}, vztah {$row['code']} v evidenci "
                        . 'trvá a k tomuto dni ho ukončit nejde. Zkontrolujte datum a skončení zapište na kartě vztahu.';

                    return;
                }
                // Konec pojištění z exportu ČSSZ je doklad, že úřad vztah
                // odhlásil. Import ho sám nezapíše (skončení může nést i jiné
                // podklady — důvod, vyrovnání dovolené), ale NABÍDNE: účetní
                // ukončení u věty potvrdí a zapíše se stejnou cestou jako na
                // kartě vztahu. Bez potvrzení se jen ukáže nabídka.
                $plan['termination_offer'] = [
                    'end_on' => $end,
                    'employment_code' => (string) $row['code'],
                    'confirmed' => $plan['_terminate_confirmed'] === true,
                ];
                if ($plan['_terminate_confirmed'] === true) {
                    $plan['_steps']['terminate'] = ['target' => 'ended', 'on' => $end];
                    $plan['employment']['end_on'] = $end;
                    $this->change($plan, 'end_date', 'Skončení vztahu', null, $end);
                } else {
                    $plan['warnings'][] = "Export uvádí konec pojistného vztahu {$end}, vztah {$row['code']} v evidenci "
                        . 'trvá. Zaškrtněte u věty „Ukončit vztah" a skončení se zapíše; důvod skončení pak doplňte '
                        . 'na kartě vztahu v části Skončení vztahu.';
                }
            } elseif ($row['end_date'] !== null && $row['end_date'] !== $end) {
                $plan['warnings'][] = "Export uvádí konec pojistného vztahu {$end}, vztah {$row['code']} je v evidenci "
                    . "ukončený k {$row['end_date']}. Zkontrolujte, které datum platí.";
            }

            return;
        }
        $start = $plan['employment']['start_on'];
        if (is_string($start) && $end < $start) {
            $plan['blocker'] = "Konec pojistného vztahu {$end} v exportu předchází nástupu {$start}. Zkontrolujte soubor.";

            return;
        }
        if ($end > date('Y-m-d') || !is_string($plan['_steps']['activate_on'])) {
            return;
        }
        $plan['_steps']['terminate'] = ['target' => 'ended', 'on' => $end];
        $plan['employment']['end_on'] = $end;
        $this->change($plan, 'end_date', 'Skončení vztahu', null, $end);
    }

    /** @param array<string,mixed> $plan */
    private function derivedStartWarnings(array &$plan, RegistrationRecord $record): void
    {
        $start = $record->derivedStart;
        if (!$record->isCsszExport() || $start === null) {
            return;
        }
        if ($start['source'] === CsszExportStartResolver::SOURCE_EXPORT) {
            $plan['warnings'][] = "Nástup {$start['on']} je začátek pojistného vztahu podle exportu ČSSZ.";

            return;
        }
        if ($start['source'] === CsszExportStartResolver::SOURCE_START_DATE) {
            $plan['warnings'][] = "Export ČSSZ datum nástupu nenese; převzalo se z měsíčního hlášení za {$start['period']}.";

            return;
        }
        $plan['warnings'][] = "Export ČSSZ datum nástupu nenese; jako nástup se použil začátek pojištění "
            . "{$start['on']} z měsíčního hlášení za {$start['period']}.";
        if (CsszExportStartResolver::needsCheck($start)) {
            $plan['warnings'][] = 'Začátek pojištění vyšel na první den nejstaršího hlášeného měsíce, takže '
                . 'pojištění mohlo trvat už dřív. Skutečný nástup zkontrolujte (pracovní smlouva, přihláška) '
                . 'a případně ho opravte na kartě vztahu.';
        }
    }

    /**
     * VS zaměstnavatele ve větě exportu musí patřit některé mzdové účtárně
     * firmy. Když firma žádný VS nevede, kontrola se přeskočí.
     */
    /**
     * Věta nese VS starý (`vs`) i nový (`nvs`, věta o změně VS); stačí, aby
     * některý patřil firmě. Firma bez jediného VS se ověřit nedá: věta se
     * nechá projít a REGZEC/PREZEC jen dostane varování, jako hlášení JMHZ.
     *
     * @param list<string> $warnings
     */
    private function foreignEmployerBlocker(int $supplierId, RegistrationRecord $record, array &$warnings): ?string
    {
        $symbols = [];
        foreach ([$record->employerVariableSymbol, $record->employerNewVariableSymbol] as $value) {
            $symbol = $value === null ? null : RegistrationImportLookup::variableSymbol($value);
            if ($symbol !== null) {
                $symbols[$symbol] = $value;
            }
        }
        if ($symbols === []) {
            return null;
        }
        $known = $this->lookup->variableSymbols($supplierId);
        if ($known === []) {
            if (in_array($record->documentType, ['REGZEC25', 'PREZEC26'], true)) {
                $warnings[] = 'Firma nemá vyplněný VS mzdové účtárny, takže nejde ověřit, že věta (VS '
                    . $record->employerVariableSymbol . ') patří jí. Doplňte VS v nastavení mzdové účtárny.';
            }

            return null;
        }
        foreach (array_keys($symbols) as $symbol) {
            if (in_array((string) $symbol, $known, true)) {
                return null;
            }
        }

        return 'Věta nese variabilní symbol zaměstnavatele ' . implode(' / ', array_values($symbols))
            . ', který nepatří žádné mzdové účtárně této firmy. Soubor je nejspíš jiného zaměstnavatele - nahrajte '
            . 'soubor stažený pod správným VS, nebo VS doplňte v nastavení mzdové účtárny.';
    }

    private static function exportWithoutStartBlocker(string $prefix): string
    {
        return $prefix . ' zaměstnanců ČSSZ datum nástupu nenese a v dávce není měsíční hlášení JMHZ '
            . 's formulářem se stejným ID PPV, ze kterého by šel nástup odvodit. Nahrajte spolu s exportem '
            . 'i měsíční hlášení (nebo přihlášku REGZEC) a obnovte náhled.';
    }

    /** @return array{0:?string,1:?string} [rodné číslo v kanonickém tvaru, EČP] */
    private function birthNumber(RegistrationRecord $record): array
    {
        if ($record->birthNumber === null) {
            return [null, $record->insuredPersonNumber];
        }
        try {
            return [CzechBirthNumber::normalize($record->birthNumber), null];
        } catch (\InvalidArgumentException) {
            return [null, $record->birthNumber];
        }
    }

    private function maskedBirthNumber(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $digits = (string) preg_replace('/\D/', '', $value);

        return strlen($digits) >= 6 ? substr($digits, 0, 6) . '/****' : '****';
    }

    /** @return array<string,string> */
    private function identityFacts(RegistrationRecord $record): array
    {
        return array_filter([
            'title_prefix' => $record->titlePrefix,
            'birth_place' => $record->birthPlace,
            'birth_country_code' => $record->birthCountryCode,
            'citizenship_country_code' => $record->citizenshipCountryCode,
            'sex' => $record->sex,
        ], static fn (?string $value): bool => $value !== null);
    }

    /** @return array<string,array{street_line:string,city:string,postal_code:string,country_code:string}> */
    private function addresses(RegistrationRecord $record): array
    {
        $result = [];
        foreach (['residence' => $record->permanentAddress, 'mailing' => $record->contactAddress] as $type => $address) {
            if ($address === null || $address['city'] === null || $address['postal_code'] === null) {
                continue;
            }
            $number = $address['house_number'] ?? '';
            if (($address['orientation_number'] ?? null) !== null) {
                $number .= '/' . $address['orientation_number'];
            }
            $result[$type] = [
                'street_line' => trim(($address['street'] ?? $address['city']) . ' ' . $number),
                'city' => $address['city'],
                'postal_code' => $address['postal_code'],
                'country_code' => $address['country_code'] ?? 'CZ',
            ];
        }

        return $result;
    }

    /** @param array<string,mixed> $plan */
    private function insurer(array &$plan, ?string $code): ?string
    {
        if ($code === null) {
            return null;
        }
        if (!HealthInsurers::isValid($code)) {
            $plan['warnings'][] = "Kód zdravotní pojišťovny {$code} v číselníku pojišťoven není, nezapisuje se.";

            return null;
        }

        return $code;
    }

    /** @param array<string,mixed> $plan */
    private function educationInfo(array &$plan, RegistrationRecord $record, ?int $employeeId): void
    {
        // REGZEC vzdělání nese do profilu registrace A1, viz planA1Profile().
        if ($record->highestEducationCode === null || $record->a1Profile !== []) {
            return;
        }
        $plan['changes'][] = [
            'field' => 'highest_education_code',
            'label' => 'Nejvyšší dosažené vzdělání (jen informace)',
            'current' => null,
            'imported' => $record->highestEducationCode,
        ];
        $plan['warnings'][] = 'Nejvyšší dosažené vzdělání se v kmenových datech nevede, import ho jen ukazuje.';
    }

    /** @param array<string,mixed> $plan */
    private function change(array &$plan, string $field, string $label, ?string $current, ?string $imported): void
    {
        if ($imported === null) {
            return;
        }
        $plan['changes'][] = ['field' => $field, 'label' => $label, 'current' => $current, 'imported' => $imported];
    }

    /**
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function finish(array $plan, ?string $blocker): array
    {
        $plan['blocker'] = $blocker;
        $plan['selectable'] = $blocker === null && !in_array($plan['operation'], ['none', 'unsupported'], true);

        return $plan;
    }

    private function factLabel(string $field): string
    {
        return match ($field) {
            'title_prefix' => 'Titul před jménem',
            'birth_place' => 'Místo narození',
            'birth_country_code' => 'Stát narození',
            'citizenship_country_code' => 'Státní občanství',
            'sex' => 'Pohlaví',
            default => $field,
        };
    }

    private function termLabel(string $field): string
    {
        return match ($field) {
            'activity_code' => 'Druh činnosti pro ČSSZ',
            'jmhz_relationship_detail_code' => 'Bližší určení vztahu',
            'cz_isco_code' => 'Kód CZ-ISCO',
            'work_place' => 'Místo výkonu práce',
            'jmhz_workplace_municipality_code' => 'Kód obce pracoviště',
            'jmhz_workplace_country_code' => 'Stát pracoviště',
            default => $field,
        };
    }

    private function addressLabel(string $type): string
    {
        return $type === 'mailing' ? 'Kontaktní adresa' : 'Trvalá adresa';
    }

    /** @param array<string,mixed> $address */
    private function addressText(array $address): string
    {
        return trim(sprintf(
            '%s, %s %s, %s',
            (string) ($address['street_line'] ?? ''),
            (string) ($address['postal_code'] ?? ''),
            (string) ($address['city'] ?? ''),
            (string) ($address['country_code'] ?? ''),
        ));
    }

    /**
     * @param array<string,mixed> $current
     * @param array<string,mixed> $imported
     */
    private static function sameAddress(array $current, array $imported): bool
    {
        $fold = static fn (mixed $value): string => mb_strtolower(
            (string) preg_replace('/\s+/u', '', (string) $value),
        );
        foreach (['street_line', 'city', 'postal_code', 'country_code'] as $key) {
            if ($fold($current[$key] ?? '') !== $fold($imported[$key] ?? '')) {
                return false;
            }
        }

        return true;
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
