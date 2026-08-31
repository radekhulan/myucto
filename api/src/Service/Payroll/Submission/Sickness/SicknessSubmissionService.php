<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

use MyInvoice\Repository\Payroll\PayrollSicknessCaseRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Payroll\Cssz\CsszSchemaCatalog;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Isds\PayrollIsdsSubmissionService;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzSoftwareIdentification;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;

/**
 * Most mezi evidencí případů dávek a platformou podání.
 *
 * Tři pravidla, stejná jako u registrací a OZUSPOJ, a ze stejného důvodu:
 *
 * 1. **Zmrazené XML je pravda podání** — vzniká právě jednou a při
 *    idempotentním opakování se nestaví znovu.
 * 2. **Povinnost a lhůta vznikají PŘED podáním.** Lhůta podle § 97 odst. 2
 *    zák. č. 187/2006 Sb. běží od 15. dne trvání dočasné pracovní neschopnosti
 *    i tehdy, když podání nikdo nepřipraví — jinak ji nikdo neuhlídá.
 * 3. **Podání končí ve stavu `ready`, nikdy „podáno".** Povinnost splní až
 *    PŘEDÁNÍ územní správě sociálního zabezpečení; připravené XML není
 *    předané podání a případ se proto na `ready` neposune na `accepted`.
 *
 * Kanál je `isds`. Je to jediný kanál, který je pro tyhle dvě agendy doložený
 * až do tvaru zprávy — viz {@see SicknessChannelCatalog}. VREP/APEP ČSSZ
 * přijímá, ale identifikátor třídy podání pro NEMPRI a HZUPN nemáme
 * v připnutém protokolu, takže se neotevírá.
 *
 * Doložený kanál ale musí být i PRŮCHODNÝ. Dokud {@see enqueueDataBox()}
 * neexistovalo, končilo podání ve stavu `ready` a účetní ho neměla kde odeslat:
 * obrazovka „Stav odeslání" patří kanálu VREP/APEP a ptala se natvrdo na JMHZ.
 * Zařazení do fronty proto visí přímo na případu dávky — tam, kde se podání
 * připravilo.
 */
final readonly class SicknessSubmissionService
{
    public const SOURCE_EVENT_TYPE = 'payroll_sickness_case';

    /**
     * Agendy, které se z případu dávky odesílají. Je to ROZSAH obrazovky:
     * odsud se nesmí zařadit podání jiné agendy, i kdyby jeho ID někdo do
     * požadavku podstrčil.
     *
     * @var list<string>
     */
    public const DISPATCHABLE_AGENDA_CODES = ['NEMPRI', 'HZUPN'];

    private const SUBJECT_TYPE = 'employment';

    public function __construct(
        private PayrollSicknessCaseRepository $cases,
        private SicknessCaseService $caseService,
        private SicknessDeadlinePolicy $deadlines,
        private NempriXmlSerializer $nempriSerializer,
        private HzupnXmlSerializer $hzupnSerializer,
        private SicknessXmlValidator $validator,
        private SicknessChannelCatalog $channels,
        private CsszSchemaCatalog $schemas,
        private PayrollRegistrationIdentityService $identities,
        private PayrollObligationService $obligations,
        private PayrollSubmissionService $submissions,
        private PayrollSubmissionRepository $submissionRepository,
        private JmhzSoftwareIdentification $software,
        private PayrollIsdsSubmissionService $dataBox,
    ) {}

    /**
     * Test: ukáže, co by se podalo, a nezaloží nic.
     *
     * @return array<string,mixed>
     */
    public function preview(
        int $supplierId,
        string $environment,
        int $caseId,
        SicknessDocumentKind $document,
    ): array {
        $resolved = $this->resolve($supplierId, $environment, $caseId, $document);

        return [
            'case_id' => $caseId,
            'agenda_code' => $document->agendaCode(),
            'document_kind' => $document->value,
            'document_type' => $document->documentType(),
            'xml' => $resolved['xml'],
            'xml_sha256' => hash('sha256', $resolved['xml']),
            'channel' => $this->channels->dispatchChannel(),
            'window' => [
                'earliest_notification_on' =>
                    $resolved['window']->earliestNotificationOn,
                'due_on' => $resolved['window']->dueOn,
                'legal_reference' => $resolved['window']->legalReference,
                'deadline_source_status' => $resolved['window']->sourceStatus,
            ],
            'official_submission' => [
                'supported' => false,
                'reason' => 'Tohle je test: podání se nezakládá a nic se neodesílá.',
            ],
        ];
    }

    /**
     * Zmrazí podání do odesílatelné podoby a případ posune na `prepared`.
     *
     * @return array<string,mixed>
     */
    public function prepare(
        int $supplierId,
        string $environment,
        int $caseId,
        SicknessDocumentKind $document,
        ?int $createdBy = null,
    ): array {
        $channel = $this->channels->dispatchChannel();
        $this->channels->assertDispatchable($channel);
        $probe = $this->resolve($supplierId, $environment, $caseId, $document);
        $obligation = $this->registerObligation(
            $supplierId,
            $environment,
            $caseId,
            $document,
            $probe,
            $createdBy,
        );

        return $this->submissionRepository->transaction(function () use (
            $supplierId,
            $environment,
            $caseId,
            $document,
            $createdBy,
            $probe,
            $obligation,
            $channel,
        ): array {
            if (!$this->submissionRepository->lockSupplier($supplierId)) {
                throw new SicknessException(
                    'sickness_supplier_missing',
                    'Firma případu dávky nebyla nalezena.',
                );
            }
            $keys = $this->idempotencyKeys(
                $supplierId,
                $environment,
                $caseId,
                $document,
                $probe['source_hash'],
            );
            $submission = $this->submissions->prepare(
                $supplierId,
                $obligation['id'],
                'regular',
                $channel,
                $probe['source_hash'],
                $keys['submission'],
                null,
                null,
                $createdBy,
                $environment,
            );
            if (!$submission['created']) {
                return [
                    'case_id' => $caseId,
                    'submission_id' => (int) $submission['id'],
                    'obligation_id' => $obligation['id'],
                    'status' => (string) $submission['status'],
                    'row_version' => (int) $submission['row_version'],
                    'agenda_code' => $document->agendaCode(),
                    'document_kind' => $document->value,
                    'artifact_sha256' => hash('sha256', $probe['xml']),
                    'created' => false,
                ];
            }
            $part = $this->submissions->addPart(
                $supplierId,
                (int) $submission['id'],
                (int) $submission['row_version'],
                'sickness:' . $caseId . ':' . $document->value,
                $document->documentType(),
                'payroll_employment:' . $probe['employment_id'],
                'payroll_employment',
                self::sourceEventReference($caseId),
                $probe['source_hash'],
            );
            $artifact = $this->submissions->storeArtifact(
                $supplierId,
                (int) $submission['id'],
                (int) $part['submission_row_version'],
                (int) $part['id'],
                'outbound_xml',
                'outbound',
                'application/xml',
                $probe['xml'],
                $document->documentType(),
                null,
                $channel,
                $keys['artifact'],
                $createdBy,
            );
            if (!hash_equals(
                hash('sha256', $probe['xml']),
                (string) $artifact['artifact_sha256'],
            )) {
                throw new SicknessException(
                    'sickness_artifact_mismatch',
                    'Otisk uloženého artefaktu neodpovídá zmrazenému XML podání.',
                );
            }
            $validated = $this->submissions->transition(
                $supplierId,
                (int) $submission['id'],
                (int) $artifact['submission_row_version'],
                'validated',
            );
            $ready = $this->submissions->transition(
                $supplierId,
                (int) $submission['id'],
                (int) $validated['row_version'],
                'ready',
            );
            $this->bindSubmission(
                $supplierId,
                $environment,
                $caseId,
                $document,
                (int) $submission['id'],
            );

            return [
                'case_id' => $caseId,
                'submission_id' => (int) $submission['id'],
                'obligation_id' => $obligation['id'],
                'part_id' => (int) $part['id'],
                'artifact_id' => (int) $artifact['id'],
                'status' => (string) $ready['status'],
                'row_version' => (int) $ready['row_version'],
                'agenda_code' => $document->agendaCode(),
                'document_kind' => $document->value,
                'artifact_sha256' => (string) $artifact['artifact_sha256'],
                'channel' => $channel,
                'created' => true,
            ];
        });
    }

    /**
     * Zařadí připravené podání případu do fronty podání datovou schránkou.
     *
     * Podání se tím NEPOSOUVÁ na „podáno": zařazení do fronty je krok dopravy,
     * povinnost splní až doručení územní správě sociálního zabezpečení. Stav
     * případu proto zůstává `prepared` a mění ho teprve `recordReceipt()` proti
     * protokolu — stejně jako v ručním režimu.
     *
     * @return array<string,mixed>
     */
    public function enqueueDataBox(
        int $supplierId,
        string $environment,
        int $caseId,
        SicknessDocumentKind $document,
        ?int $userId,
    ): array {
        $this->channels->assertDispatchable($this->channels->dispatchChannel());
        $row = $this->caseService->requireCase($supplierId, $environment, $caseId);
        $column = $document === SicknessDocumentKind::Nempri
            ? 'nempri_submission_id'
            : 'hzupn_submission_id';
        $submissionId = $row[$column] === null ? 0 : (int) $row[$column];
        if ($submissionId <= 0) {
            throw new SicknessException(
                'sickness_submission_not_prepared',
                'Podání ještě není připravené, takže není co odeslat.'
                    . ' Nejdřív u případu zvolte Připravit.',
            );
        }

        $queued = $this->dataBox->enqueue(
            $supplierId,
            $environment,
            $submissionId,
            self::DISPATCHABLE_AGENDA_CODES,
            $userId,
        );

        return [
            'case_id' => $caseId,
            'document_kind' => $document->value,
            ...$queued,
        ];
    }

    /**
     * Jak je na tom firma s odesíláním datovkou — bez ohledu na konkrétní případ.
     *
     * Seznam případů to potřebuje ještě předtím, než uživatel na cokoliv klikne:
     * podle toho se rozhoduje, jestli se nabídne „Odeslat datovou schránkou",
     * „Odeslat po potvrzení v mobilu", nebo věta, proč to jde jen ručně.
     *
     * @return array{automatic:bool,channel:string,reason:?string}
     */
    public function dataBoxTransport(int $supplierId, string $environment): array
    {
        return $this->dataBox->transportAvailability($supplierId, $environment);
    }

    public static function sourceEventReference(int $caseId): string
    {
        if ($caseId <= 0) {
            throw new \InvalidArgumentException(
                'Případ dávky musí být kladné číslo.',
            );
        }

        return 'payroll_sickness_case:' . $caseId;
    }

    /**
     * @return array{
     *   xml:string,source_hash:string,employment_id:int,
     *   window:SicknessNotificationWindow
     * }
     */
    private function resolve(
        int $supplierId,
        string $environment,
        int $caseId,
        SicknessDocumentKind $document,
    ): array {
        $row = $this->caseService->requireCase($supplierId, $environment, $caseId);
        $status = SicknessCaseStatus::from((string) $row['status']);
        if ($status === SicknessCaseStatus::Cancelled) {
            throw new SicknessException(
                'sickness_case_cancelled',
                'Ze zrušeného případu se podání nepřipravuje.',
            );
        }
        $kind = SicknessBenefitKind::from((string) $row['benefit_kind']);
        $employmentId = (int) $row['employment_id'];
        $incapacityFrom = (string) $row['incapacity_from'];
        $incapacityTo = $this->nullableText($row['incapacity_to'] ?? null);
        $context = $this->caseService->requireContext(
            $supplierId,
            $employmentId,
            $incapacityFrom,
        );
        $identity = $this->identities->sensitiveIdentityAt(
            $supplierId,
            (int) $row['employee_id'],
            $incapacityFrom,
        );

        if ($document === SicknessDocumentKind::Nempri) {
            if (!$kind->isSerializable()) {
                throw new SicknessException(
                    $kind->unsupportedReasonCode(),
                    $kind->unsupportedReason(),
                );
            }
            $payload = $this->nempriPayload($row, $kind, $context, $identity);
            $xml = $this->nempriSerializer->serialize($payload);
            $this->validator->validateNempri($payload, $xml);
            $window = $this->deadlines->forNempri(
                $kind,
                $incapacityFrom,
                $incapacityTo,
                $this->nullableText($row['payroll_payment_date'] ?? null),
            );
        } else {
            $payload = $this->hzupnPayload($row, $context, $identity);
            $xml = $this->hzupnSerializer->serialize($payload);
            $this->validator->validateHzupn($payload, $xml, $incapacityFrom);
            $window = $this->deadlines->forHzupn($incapacityFrom, $incapacityTo);
        }

        return [
            'xml' => $xml,
            'source_hash' => hash('sha256', CanonicalJson::encode([
                'schema_reference' => 'payroll-sickness-submission.v1',
                'case_id' => $caseId,
                'employment_id' => $employmentId,
                'document_kind' => $document->value,
                'benefit_kind' => $kind->value,
                'incapacity_from' => $incapacityFrom,
                'incapacity_to' => $incapacityTo,
                'xml_sha256' => hash('sha256', $xml),
            ])),
            'employment_id' => $employmentId,
            'window' => $window,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $context
     * @param array<string,mixed> $identity
     */
    private function nempriPayload(
        array $row,
        SicknessBenefitKind $kind,
        array $context,
        array $identity,
    ): NempriXmlPayload {
        $manifest = $this->schemas->manifestFor(CsszSchemaCatalog::NEMPRI25);

        return new NempriXmlPayload(
            benefitKind: $kind,
            osszCode: (int) $row['ossz_code'],
            correction: (bool) $row['correction'],
            decisionNumber: $this->nullableText($row['decision_number'] ?? null),
            foreignCase: (bool) $row['foreign_case'],
            insuredFirstName: $this->requiredIdentity($identity, 'first_name'),
            insuredLastName: $this->requiredIdentity($identity, 'last_name'),
            insuredBirthNumber: $this->requireBirthNumber($identity),
            insuredPhone: null,
            insuredEmail: null,
            employerVariableSymbol: $this->variableSymbol($context),
            employerIdentificationNumber: $this->nullableText(
                $context['employer_business_id'] ?? null,
            ),
            employerName: (string) ($context['employer_name'] ?? ''),
            employmentFrom: (string) ($context['start_date'] ?? ''),
            employmentTo: $this->nullableText($context['end_date'] ?? null),
            activityCode: $this->activityCode($context),
            workedOnDecisiveDay: (bool) $row['worked_on_decisive_day'],
            hoursWorked: $this->decimal($row['hours_worked'] ?? null),
            dailyWorkingHours: $this->decimal($row['daily_working_hours'] ?? null),
            smallScopeIncomeMinor: $row['small_scope_income_minor'] === null
                ? null
                : (int) $row['small_scope_income_minor'],
            receivesPension: (bool) $row['receives_pension'],
            pensionKind: $this->nullableText($row['pension_kind'] ?? null),
            isStudent: (bool) $row['is_student'],
            withinSchoolHolidays: $row['within_school_holidays'] === null
                ? null
                : (bool) $row['within_school_holidays'],
            firstEmploymentFreeTime: (bool) $row['first_employment_free_time'],
            unpaidLeave: (bool) $row['unpaid_leave'],
            unpaidLeaveFrom: $this->nullableText($row['unpaid_leave_from'] ?? null),
            unpaidLeaveTo: $this->nullableText($row['unpaid_leave_to'] ?? null),
            startsMaternity: $row['starts_maternity'] === null
                ? null
                : (bool) $row['starts_maternity'],
            childBirthDate: $this->nullableText($row['child_birth_date'] ?? null),
            transferredOtherWork: (bool) $row['transferred_other_work'],
            transferredOn: $this->nullableText($row['transferred_on'] ?? null),
            enforcement: (bool) $row['enforcement'],
            insolvency: (bool) $row['insolvency'],
            additionalNote: $this->nullableText($row['additional_note'] ?? null),
            productName: $this->software->productName,
            productVersion: $this->software->productVersion,
            payloadVersion: $manifest['payload_version'],
        );
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $context
     * @param array<string,mixed> $identity
     */
    private function hzupnPayload(
        array $row,
        array $context,
        array $identity,
    ): HzupnXmlPayload {
        $manifest = $this->schemas->manifestFor(CsszSchemaCatalog::HZUPN20);
        $issuedOn = $this->nullableText($row['issued_on'] ?? null);
        if ($issuedOn === null) {
            throw new SicknessException(
                'hzupn_issue_date_missing',
                'Hlášení musí nést den vystavení (dokument/datumVystaveni). Doplňte ho u případu.',
            );
        }

        return new HzupnXmlPayload(
            // Podání zaměstnavatele. Hlášení osoby dobrovolně nemocensky
            // pojištěné je tentýž tiskopis, ale podává ho pojištěnec sám —
            // aplikace ho za něj sestavovat nesmí.
            employerReport: true,
            personReport: false,
            foreignCase: (bool) $row['foreign_case'],
            confirmationNumber: $this->nullableText($row['decision_number'] ?? null),
            osszCode: (int) $row['ossz_code'],
            osszName: null,
            issuedOn: $issuedOn,
            correction: (bool) $row['correction'],
            insuredFirstName: $this->requiredIdentity($identity, 'first_name'),
            insuredLastName: $this->requiredIdentity($identity, 'last_name'),
            insuredTitle: null,
            insuredBirthNumber: $identity['identifiers']['birth_number']
                ?? $identity['identifiers']['ecp']
                ?? null,
            insuredBirthDate: $this->nullableText(
                $identity['identity']['birth_date'] ?? null,
            ),
            employerName: (string) ($context['employer_name'] ?? ''),
            employerIdentificationNumber: $this->nullableText(
                $context['employer_business_id'] ?? null,
            ),
            employerVariableSymbol: $this->variableSymbol($context),
            returnedToWork: $row['returned_to_work'] === null
                ? null
                : (bool) $row['returned_to_work'],
            returnReason: $this->nullableText($row['return_reason'] ?? null),
            returnedOn: $this->nullableText($row['returned_on'] ?? null),
            hoursWorkedLastDay: $this->decimal($row['hours_worked_last_day'] ?? null),
            shiftHoursLastDay: $this->decimal($row['shift_hours_last_day'] ?? null),
            workIntervals: is_array($row['work_days'] ?? null)
                ? array_values($row['work_days'])
                : [],
            productName: $this->software->productName,
            productVersion: $this->software->productVersion,
            payloadVersion: $manifest['payload_version'],
        );
    }

    /**
     * @param array<string,mixed> $probe
     * @return array{id:int,due_on:string,status:string,row_version:int,created:bool}
     */
    private function registerObligation(
        int $supplierId,
        string $environment,
        int $caseId,
        SicknessDocumentKind $document,
        array $probe,
        ?int $createdBy,
    ): array {
        $window = $probe['window'];
        $sourceHash = hash('sha256', CanonicalJson::encode([
            'schema_reference' => 'payroll-sickness-obligation.v1',
            'case_id' => $caseId,
            'document_kind' => $document->value,
            'earliest_notification_on' => $window->earliestNotificationOn,
            'due_on' => $window->dueOn,
            'legal_reference' => $window->legalReference,
        ]));

        return $this->obligations->register(
            $supplierId,
            $document->agendaCode(),
            self::SUBJECT_TYPE,
            'payroll_employment:' . $probe['employment_id'],
            $window->earliestNotificationOn,
            $window->dueOn,
            'regular',
            $this->channels->dispatchChannel(),
            self::SOURCE_EVENT_TYPE,
            self::sourceEventReference($caseId),
            $sourceHash,
            $window->earliestNotificationOn,
            $window->dueOn,
            $window->calendarBasis,
            $window->rulesetId,
            $window->rulesetHash,
            'sickness:' . $environment . ':' . $sourceHash,
            null,
            $createdBy,
            null,
            $environment,
        );
    }

    private function bindSubmission(
        int $supplierId,
        string $environment,
        int $caseId,
        SicknessDocumentKind $document,
        int $submissionId,
    ): void {
        $row = $this->caseService->requireCase($supplierId, $environment, $caseId);
        $column = $document === SicknessDocumentKind::Nempri
            ? 'nempri_submission_id'
            : 'hzupn_submission_id';
        $changes = [$column => $submissionId];
        if (SicknessCaseStatus::from((string) $row['status'])
            === SicknessCaseStatus::Draft
        ) {
            $changes['status'] = SicknessCaseStatus::Prepared->value;
        }
        if (!$this->cases->update(
            $supplierId,
            $environment,
            $caseId,
            (int) $row['row_version'],
            $changes,
        )) {
            throw new SicknessException(
                'sickness_case_conflict',
                'Případ mezitím někdo změnil. Načtěte ho znovu a podání připravte znovu.',
            );
        }
    }

    /** @param array<string,mixed> $context */
    private function variableSymbol(array $context): string
    {
        $raw = $context['employer_variable_symbol'] ?? null;
        $digits = preg_replace('/\D/', '', is_string($raw) ? $raw : '') ?? '';
        if ($digits === '') {
            throw new SicknessException(
                'sickness_variable_symbol_missing',
                'Firma nemá vyplněný variabilní symbol ČSSZ. Doplňte ho v Nastavení → Firma '
                . 'a podání připravte znovu.',
            );
        }

        // Doplnit zleva nulami NELZE: obě XSD mají variabilní symbol jako typ N
        // s pevnou délkou 10 a vzorem `[1-9][0-9]*`, takže nula na začátku je
        // tvrdá chyba. Symbol se proto předává tak, jak je, a neplatný odhalí
        // validátor s vlastním důvodovým kódem.
        return $digits;
    }

    /** @param array<string,mixed> $context */
    private function activityCode(array $context): string
    {
        $code = $context['activity_code'] ?? null;
        if (!is_string($code) || trim($code) === '') {
            throw new SicknessException(
                'nempri_activity_code_missing',
                'Pracovní vztah nemá ke dni vzniku sociální události vyplněný druh činnosti. '
                . 'NEMPRI ho vyžaduje (zamestnani/druhCinnosti) — doplňte ho v podmínkách vztahu.',
            );
        }

        return strtoupper(trim($code));
    }

    /** @param array<string,mixed> $identity */
    private function requiredIdentity(array $identity, string $key): string
    {
        $value = $identity['identity'][$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new SicknessException(
                'sickness_identity_incomplete',
                'Osoba nemá k rozhodnému dni doplněné jméno, příjmení a datum narození. '
                . 'Bez nich ČSSZ podání nepřijme.',
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $identity */
    private function requireBirthNumber(array $identity): string
    {
        $value = $identity['identifiers']['birth_number']
            ?? $identity['identifiers']['ecp']
            ?? null;
        if (!is_string($value) || $value === '') {
            throw new SicknessException(
                'nempri_birth_number_missing',
                'NEMPRI vyžaduje rodné číslo nebo evidenční číslo pojištěnce '
                . '(pojistenec/rodneCislo je povinný prvek). Doplňte ho na kartě osoby.',
            );
        }

        return $value;
    }

    /**
     * Desetinné číslo pro XSD. DECIMAL z MariaDB přichází jako `8.00`; pro
     * `xs:double` je to platná hodnota, takže se jen ořízne prázdný řetězec.
     */
    private function decimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function nullableText(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /** @return array{submission:string,artifact:string} */
    private function idempotencyKeys(
        int $supplierId,
        string $environment,
        int $caseId,
        SicknessDocumentKind $document,
        string $sourceHash,
    ): array {
        $base = CanonicalJson::encode([
            'schema_reference' => 'payroll-sickness-submission-key.v1',
            'supplier_id' => $supplierId,
            'environment' => $environment,
            'case_id' => $caseId,
            'document_kind' => $document->value,
            'source_hash' => $sourceHash,
        ]);

        return [
            'submission' => 'sickness-submission:' . hash('sha256', $base),
            'artifact' => 'sickness-artifact:' . hash('sha256', $base),
        ];
    }
}
