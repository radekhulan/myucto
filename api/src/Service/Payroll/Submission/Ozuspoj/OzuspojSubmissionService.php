<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Ozuspoj;

use MyInvoice\Repository\Payroll\PayrollDiscountIntentRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzSoftwareIdentification;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;

/**
 * Most mezi evidencí záměrů a platformou podání MZ-19.
 *
 * Stejná tři pravidla jako u registrací, protože platí ze stejného důvodu:
 *
 * 1. **Zmrazené XML je pravda podání** — vzniká právě jednou a při
 *    idempotentním opakování se nestaví znovu.
 * 2. **Povinnost a lhůta vznikají PŘED podáním.** Lhůta oznámení záměru
 *    (§ 7a odst. 5 pro zahájení, § 23e odst. 2 pro skončení) musí být evidovaná
 *    i tehdy, když podání nikdo nepřipraví — jinak ji nikdo neuhlídá.
 * 3. **Podání končí ve stavu `ready`, nikdy „oznámeno".** Nárok podle
 *    § 7a odst. 5 zakládá až DORUČENÍ ČSSZ; připravené XML není oznámený záměr
 *    a evidence záměru se proto na `ready` neposune na `accepted`.
 */
final readonly class OzuspojSubmissionService
{
    public const AGENDA_CODE = 'OZUSPOJ';
    public const SOURCE_EVENT_TYPE = 'payroll_discount_intent';

    private const CHANNEL = 'vrep_apep';
    private const SUBJECT_TYPE = 'employment';

    public function __construct(
        private PayrollDiscountIntentRepository $intents,
        private OzuspojIntentService $intentService,
        private OzuspojDeadlinePolicy $deadlines,
        private OzuspojXmlSerializer $serializer,
        private OzuspojXmlValidator $validator,
        private PayrollRegistrationIdentityService $identities,
        private PayrollObligationService $obligations,
        private PayrollSubmissionService $submissions,
        private PayrollSubmissionRepository $submissionRepository,
        private JmhzSoftwareIdentification $software,
    ) {}

    /**
     * Test: ukáže, co by se podalo, a nezaloží nic.
     *
     * @return array{
     *   intent_id:int,agenda_code:string,submission_kind:string,xml:string,
     *   xml_sha256:string,window:array{earliest_notification_on:string,due_on:string},
     *   official_submission:array{supported:bool,reason:string}
     * }
     */
    public function preview(
        int $supplierId,
        string $environment,
        int $intentId,
        OzuspojSubmissionKind $kind,
    ): array {
        $resolved = $this->resolve($supplierId, $environment, $intentId, $kind);

        return [
            'intent_id' => $intentId,
            'agenda_code' => self::AGENDA_CODE,
            'submission_kind' => $kind->value,
            'xml' => $resolved['xml'],
            'xml_sha256' => hash('sha256', $resolved['xml']),
            'window' => [
                'earliest_notification_on' =>
                    $resolved['window']->earliestNotificationOn,
                'due_on' => $resolved['window']->dueOn,
            ],
            'official_submission' => [
                'supported' => false,
                'reason' => 'Tohle je test: podání se nezakládá a nic se neodesílá.',
            ],
        ];
    }

    /**
     * Zmrazí oznámení do odesílatelné podoby a záměr posune na `submitted`.
     *
     * @return array<string,mixed>
     */
    public function prepare(
        int $supplierId,
        string $environment,
        int $intentId,
        OzuspojSubmissionKind $kind,
        ?int $createdBy = null,
    ): array {
        $probe = $this->resolve($supplierId, $environment, $intentId, $kind);
        $obligation = $this->registerObligation(
            $supplierId,
            $environment,
            $intentId,
            $kind,
            $probe,
            $createdBy,
        );

        return $this->submissionRepository->transaction(function () use (
            $supplierId,
            $environment,
            $intentId,
            $kind,
            $createdBy,
            $probe,
            $obligation,
        ): array {
            if (!$this->submissionRepository->lockSupplier($supplierId)) {
                throw new OzuspojException(
                    'ozuspoj_supplier_missing',
                    'Firma oznámení záměru nebyla nalezena.',
                );
            }
            $keys = $this->idempotencyKeys(
                $supplierId,
                $environment,
                $intentId,
                $kind,
                $probe['source_hash'],
            );
            $submission = $this->submissions->prepare(
                $supplierId,
                $obligation['id'],
                'regular',
                self::CHANNEL,
                $probe['source_hash'],
                $keys['submission'],
                null,
                null,
                $createdBy,
                $environment,
            );
            if (!$submission['created']) {
                return [
                    'intent_id' => $intentId,
                    'submission_id' => (int) $submission['id'],
                    'obligation_id' => $obligation['id'],
                    'status' => (string) $submission['status'],
                    'row_version' => (int) $submission['row_version'],
                    'agenda_code' => self::AGENDA_CODE,
                    'submission_kind' => $kind->value,
                    'artifact_sha256' => hash('sha256', $probe['xml']),
                    'created' => false,
                ];
            }
            $part = $this->submissions->addPart(
                $supplierId,
                (int) $submission['id'],
                (int) $submission['row_version'],
                'ozuspoj:' . $intentId . ':' . $kind->value,
                OzuspojSchemaCatalog::DOCUMENT_TYPE,
                'payroll_employment:' . $probe['employment_id'],
                'payroll_employment',
                self::sourceEventReference($intentId),
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
                OzuspojSchemaCatalog::DOCUMENT_TYPE,
                null,
                self::CHANNEL,
                $keys['artifact'],
                $createdBy,
            );
            if (!hash_equals(
                hash('sha256', $probe['xml']),
                (string) $artifact['artifact_sha256'],
            )) {
                throw new OzuspojException(
                    'ozuspoj_artifact_mismatch',
                    'Otisk uloženého artefaktu neodpovídá zmrazenému XML OZUSPOJ.',
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
                $intentId,
                $kind,
                (int) $submission['id'],
            );

            return [
                'intent_id' => $intentId,
                'submission_id' => (int) $submission['id'],
                'obligation_id' => $obligation['id'],
                'part_id' => (int) $part['id'],
                'artifact_id' => (int) $artifact['id'],
                'status' => (string) $ready['status'],
                'row_version' => (int) $ready['row_version'],
                'agenda_code' => self::AGENDA_CODE,
                'submission_kind' => $kind->value,
                'artifact_sha256' => (string) $artifact['artifact_sha256'],
                'created' => true,
            ];
        });
    }

    public static function sourceEventReference(int $intentId): string
    {
        if ($intentId <= 0) {
            throw new \InvalidArgumentException(
                'Záměr musí být kladné číslo.',
            );
        }

        return 'payroll_discount_intent:' . $intentId;
    }

    /**
     * @return array{
     *   xml:string,source_hash:string,employment_id:int,
     *   window:OzuspojNotificationWindow
     * }
     */
    private function resolve(
        int $supplierId,
        string $environment,
        int $intentId,
        OzuspojSubmissionKind $kind,
    ): array {
        $row = $this->intentService->requireIntent(
            $supplierId,
            $environment,
            $intentId,
        );
        $status = OzuspojIntentStatus::from((string) $row['status']);
        $this->assertKindAllowed($kind, $status, $row);
        $employmentId = (int) $row['employment_id'];
        $intentFrom = (string) $row['intent_from'];
        $intentTo = is_string($row['intent_to'] ?? null)
            && $row['intent_to'] !== ''
                ? (string) $row['intent_to']
                : null;
        $context = $this->intentService->requireContext(
            $supplierId,
            $employmentId,
            $intentFrom,
        );
        $identity = $this->identities->sensitiveIdentityAt(
            $supplierId,
            (int) $row['employee_id'],
            $intentFrom,
        );
        $payload = new OzuspojXmlPayload(
            kind: $kind,
            osszCode: (int) $row['ossz_code'],
            intentFrom: $kind->requiresIntentFrom() ? $intentFrom : null,
            intentTo: $kind === OzuspojSubmissionKind::End ? $intentTo : null,
            employerVariableSymbol: $this->variableSymbol($context),
            employerIdentificationNumber: $this->nullableText(
                $context['employer_business_id'] ?? null,
            ),
            employerName: (string) ($context['employer_name'] ?? ''),
            employeeFirstName: $this->requiredIdentity($identity, 'first_name'),
            employeeLastName: $this->requiredIdentity($identity, 'last_name'),
            employeeBirthDate: $this->requiredIdentity($identity, 'birth_date'),
            employeeBirthNumber: $identity['identifiers']['birth_number']
                ?? $identity['identifiers']['ecp'],
            productName: $this->software->productName,
            productVersion: $this->software->productVersion,
        );
        $xml = $this->serializer->serialize($payload);
        $this->validator->validate($payload, $xml);
        $window = $kind === OzuspojSubmissionKind::End
            ? $this->deadlines->forIntentEnd((string) $intentTo)
            : $this->deadlines->forIntentStart($intentFrom);

        return [
            'xml' => $xml,
            'source_hash' => hash('sha256', CanonicalJson::encode([
                'schema_reference' => 'payroll-ozuspoj-submission.v1',
                'intent_id' => $intentId,
                'employment_id' => $employmentId,
                'submission_kind' => $kind->value,
                'intent_from' => $intentFrom,
                'intent_to' => $intentTo,
                'xml_sha256' => hash('sha256', $xml),
            ])),
            'employment_id' => $employmentId,
            'window' => $window,
        ];
    }

    /** @param array<string,mixed> $row */
    private function assertKindAllowed(
        OzuspojSubmissionKind $kind,
        OzuspojIntentStatus $status,
        array $row,
    ): void {
        $allowed = match ($kind) {
            OzuspojSubmissionKind::Start => $status === OzuspojIntentStatus::Draft
                || $status === OzuspojIntentStatus::Submitted,
            OzuspojSubmissionKind::End => $status === OzuspojIntentStatus::Accepted
                && is_string($row['intent_to'] ?? null)
                && $row['intent_to'] !== '',
            OzuspojSubmissionKind::Cancellation =>
                $status !== OzuspojIntentStatus::Cancelled
                && $status !== OzuspojIntentStatus::Ended,
        };
        if (!$allowed) {
            throw new OzuspojException(
                'ozuspoj_submission_kind_not_allowed',
                'Tenhle typ oznámení nelze pro záměr v jeho současném stavu připravit.',
            );
        }
    }

    /**
     * @param array<string,mixed> $probe
     * @return array{id:int,due_on:string,status:string,row_version:int,created:bool}
     */
    private function registerObligation(
        int $supplierId,
        string $environment,
        int $intentId,
        OzuspojSubmissionKind $kind,
        array $probe,
        ?int $createdBy,
    ): array {
        $window = $probe['window'];
        $sourceHash = hash('sha256', CanonicalJson::encode([
            'schema_reference' => 'payroll-ozuspoj-obligation.v1',
            'intent_id' => $intentId,
            'submission_kind' => $kind->value,
            'earliest_notification_on' => $window->earliestNotificationOn,
            'due_on' => $window->dueOn,
        ]));

        return $this->obligations->register(
            $supplierId,
            self::AGENDA_CODE,
            self::SUBJECT_TYPE,
            'payroll_employment:' . $probe['employment_id'],
            $window->earliestNotificationOn,
            $window->dueOn,
            'regular',
            self::CHANNEL,
            self::SOURCE_EVENT_TYPE,
            self::sourceEventReference($intentId),
            $sourceHash,
            $window->earliestNotificationOn,
            $window->dueOn,
            $window->calendarBasis,
            $window->rulesetId,
            $window->rulesetHash,
            'ozuspoj:' . $environment . ':' . $sourceHash,
            null,
            $createdBy,
            null,
            $environment,
        );
    }

    private function bindSubmission(
        int $supplierId,
        string $environment,
        int $intentId,
        OzuspojSubmissionKind $kind,
        int $submissionId,
    ): void {
        $row = $this->intentService->requireIntent(
            $supplierId,
            $environment,
            $intentId,
        );
        $changes = $kind === OzuspojSubmissionKind::End
            ? ['end_submission_id' => $submissionId]
            : ['start_submission_id' => $submissionId, 'status' => 'submitted'];
        if ($kind === OzuspojSubmissionKind::Cancellation) {
            // Storno se stavem záměru nehýbe. Teprve protokol řekne, jestli ho
            // ČSSZ vzala; do té doby by tvrzení „zrušeno" znamenalo, že sleva
            // přestala být doložená dřív, než skutečně přestala.
            $changes = [];
        }
        if ($changes === []) {
            return;
        }
        if (!$this->intents->update(
            $supplierId,
            $environment,
            $intentId,
            (int) $row['row_version'],
            $changes,
        )) {
            throw new OzuspojException(
                'ozuspoj_intent_conflict',
                'Záměr mezitím někdo změnil. Načtěte ho znovu a oznámení připravte znovu.',
            );
        }
    }

    /** @param array<string,mixed> $context */
    private function variableSymbol(array $context): string
    {
        $raw = $context['employer_variable_symbol'] ?? null;
        $digits = preg_replace('/\D/', '', is_string($raw) ? $raw : '') ?? '';
        if ($digits === '') {
            throw new OzuspojException(
                'ozuspoj_variable_symbol_missing',
                'Firma nemá vyplněný variabilní symbol ČSSZ. Doplňte ho v Nastavení → Firma a oznámení připravte znovu.',
            );
        }

        return str_pad($digits, 10, '0', STR_PAD_LEFT);
    }

    /** @param array<string,mixed> $identity */
    private function requiredIdentity(array $identity, string $key): string
    {
        $value = $identity['identity'][$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new OzuspojException(
                'ozuspoj_identity_incomplete',
                'Osoba nemá k rozhodnému dni doplněné jméno, příjmení a datum narození. Bez nich ČSSZ oznámení záměru nepřijme.',
            );
        }

        return $value;
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
        int $intentId,
        OzuspojSubmissionKind $kind,
        string $sourceHash,
    ): array {
        $base = CanonicalJson::encode([
            'schema_reference' => 'payroll-ozuspoj-submission-key.v1',
            'supplier_id' => $supplierId,
            'environment' => $environment,
            'intent_id' => $intentId,
            'submission_kind' => $kind->value,
            'source_hash' => $sourceHash,
        ]);

        return [
            'submission' => 'ozuspoj-submission:' . hash('sha256', $base),
            'artifact' => 'ozuspoj-artifact:' . hash('sha256', $base),
        ];
    }
}
