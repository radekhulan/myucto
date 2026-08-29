<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Eldp;

use MyInvoice\Repository\Payroll\EldpStatementRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;

/**
 * Evidenční list důchodového pojištění jako podání.
 *
 * Řetěz je záměrně krátký a končí dřív, než by mohl něco odeslat:
 *
 * 1. sestavení ze zmrazených schválených revizí roku,
 * 2. XML podle připnutého oficiálního typu `eldpType` a jeho validace,
 * 3. neměnný šifrovaný snapshot evidenčního listu,
 * 4. zápis do **registru povinností** s vlastní zákonnou lhůtou,
 * 5. podání na společné platformě dovedené do stavu **`prepared`**.
 *
 * **Odesílá člověk, ne tahle služba.** Kanál je `other` a stav se zastaví na
 * `prepared`, takže existující ČSSZ transport (který bere jen `ready` na
 * kanálu `vrep_apep`) evidenční list nikdy nesebere. Druhý transport se
 * nestaví; ten pro ČSSZ existuje a je ověřený provozem.
 *
 * Opakované sestavení téhož roku a vztahu nevytvoří druhý evidenční list ani
 * druhé podání — brání tomu idempotency claim, jedinečný klíč nad rozsahem
 * a idempotentní klíče platformy podání.
 */
final readonly class EldpStatementService
{
    public const AGENDA_CODE = 'ELDP';
    public const SOURCE_EVENT_TYPE = 'eldp_statement';
    private const CHANNEL = 'other';
    private const SUBJECT_TYPE = 'employment';
    private const MANIFEST_SCHEMA = 'payroll-eldp-statement-manifest.v1';
    private const REQUEST_SCHEMA = 'payroll-eldp-statement-request.v1';
    private const ENCRYPTION_PURPOSE = 'eldp-statement';

    public function __construct(
        private EldpStatementRepository $repository,
        private EldpAnnualStatementBuilder $builder,
        private EldpXmlSerializer $serializer,
        private EldpXmlValidator $validator,
        private PayrollSensitiveData $sensitiveData,
        private SecretEncryption $encryption,
        private PayrollObligationService $obligations,
        private PayrollSubmissionService $submissions,
        private PayrollSubmissionRepository $submissionRepository,
    ) {}

    /**
     * @param array<string,mixed> $confirmation
     * @return array{
     *   statement_id:int,created:bool,statement_kind:string,
     *   section_count:int,insurance_days:int,excluded_days_total:int,
     *   due_on:string,earliest_submission_on:string,
     *   obligation_id:int,submission_id:int,part_id:int,artifact_id:int,
     *   submission_status:string,xml_sha256:string,environment:string
     * }
     */
    public function prepare(
        int $supplierId,
        int $employmentId,
        int $year,
        string $environment,
        array $confirmation,
        string $idempotencyKey,
        int $createdBy,
    ): array {
        if ($supplierId <= 0 || $employmentId <= 0 || $createdBy <= 0) {
            throw new \InvalidArgumentException(
                'Firma, pracovní vztah a uživatel musí být kladná čísla.',
            );
        }
        if (!in_array($environment, ['production', 'test'], true)) {
            throw new EldpValidationException(
                'eldp_environment_invalid',
                'Prostředí evidenčního listu není platné.',
            );
        }
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 190) {
            throw new \InvalidArgumentException(
                'Idempotency klíč musí mít 1 až 190 bajtů.',
            );
        }
        $idempotencyHash = hash('sha256', $idempotencyKey, true);
        $confirmationFingerprint = hash('sha256', CanonicalJson::encode([
            'schema_reference' => 'payroll-eldp-confirmation.v1',
            'supplier_id' => $supplierId,
            'environment' => $environment,
            'employment_id' => $employmentId,
            'statement_year' => $year,
            'confirmation' => self::normalizedConfirmation($confirmation),
        ]));

        return $this->repository->transaction(function () use (
            $supplierId,
            $employmentId,
            $year,
            $environment,
            $confirmation,
            $idempotencyHash,
            $confirmationFingerprint,
            $createdBy,
        ): array {
            if (!$this->repository->lockSupplier($supplierId)) {
                throw new \DomainException(
                    'Firma evidenčního listu nebyla nalezena.',
                );
            }
            $claimed = $this->repository->insertClaim(
                $supplierId,
                $environment,
                $idempotencyHash,
                $employmentId,
                $year,
                $confirmationFingerprint,
                $createdBy,
            );
            if (!$claimed) {
                $claim = $this->repository->findClaimForUpdate(
                    $supplierId,
                    $environment,
                    $idempotencyHash,
                );
                if ($claim === null
                    || $claim['employment_id'] !== $employmentId
                    || $claim['statement_year'] !== $year
                ) {
                    throw new EldpValidationException(
                        'eldp_idempotency_scope_mismatch',
                        'Opakování evidenčního listu neodpovídá původnímu rozsahu.',
                    );
                }
                if (!hash_equals(
                    (string) $claim['confirmation_fingerprint'],
                    $confirmationFingerprint,
                )) {
                    throw new EldpValidationException(
                        'eldp_idempotency_payload_mismatch',
                        'Idempotentní opakování evidenčního listu má jiný obsah potvrzení.',
                    );
                }
            }

            $statement = $this->builder->build(
                $supplierId,
                $employmentId,
                $year,
                $this->repository->revisionsForYear($supplierId, $year),
                $confirmation,
            );
            $xml = $this->serializer->serialize($statement);
            $schema = $this->validator->validate($statement, $xml);
            $xmlSha256 = hash('sha256', $xml);
            $plaintext = $statement->canonicalJson();
            $fingerprint = $this->sensitiveData->keyedFingerprint(
                $plaintext,
                self::ENCRYPTION_PURPOSE,
                $supplierId,
            );
            $scope = $statement->scope();
            $manifestJson = CanonicalJson::encode([
                'schema_reference' => self::MANIFEST_SCHEMA,
                'builder_version' => EldpAnnualStatementBuilder::BUILDER_VERSION,
                'scope' => $scope,
                'eligibility' => $statement->payload['eligibility'],
                'deadline' => $statement->payload['deadline'],
                'specification' => $statement->payload['specification'],
                'source_revisions' => $statement->payload['source_revisions'],
                'schema' => $schema,
                'xml_sha256' => $xmlSha256,
                'section_count' => count($statement->sections()),
                'statement_fingerprint' => $fingerprint,
            ]);
            $manifestHash = hash('sha256', $manifestJson);
            $requestFingerprint = hash('sha256', CanonicalJson::encode([
                'schema_reference' => self::REQUEST_SCHEMA,
                'supplier_id' => $supplierId,
                'environment' => $environment,
                'employment_id' => $employmentId,
                'statement_year' => $year,
                'source_manifest_sha256' => $manifestHash,
            ]));

            $existing = $this->repository->findByScopeForUpdate(
                $supplierId,
                $environment,
                $employmentId,
                $year,
            );
            if ($existing !== null) {
                if (!hash_equals(
                    (string) $existing['request_fingerprint'],
                    $requestFingerprint,
                )) {
                    throw new EldpValidationException(
                        'eldp_scope_already_frozen',
                        "Evidenční list za rok {$year} je už zmrazený s jiným obsahem; "
                            . 'změněný podklad vyžaduje opravné podání, ne přepsání.',
                    );
                }
                $statementId = $existing['id'];
                $created = false;
            } else {
                $totals = self::totals($statement);
                $statementId = $this->repository->insert(
                    [
                        'supplier_id' => $supplierId,
                        'environment' => $environment,
                        'employee_id' => $scope['employee_id'],
                        'employment_id' => $employmentId,
                        'statement_year' => $year,
                        'statement_kind' => $scope['statement_kind'],
                        'period_from' => $scope['period_from'],
                        'period_to' => $scope['period_to'],
                        'schema_reference' => EldpAnnualStatement::SCHEMA_REFERENCE,
                        'builder_version' => EldpAnnualStatementBuilder::BUILDER_VERSION,
                        'section_count' => $totals['section_count'],
                        'insurance_days' => $totals['insurance_days'],
                        'excluded_days_total' => $totals['excluded_days_total'],
                        'deducted_days_total' => $totals['deducted_days_total'],
                        'deadline_ruleset_id' => $statement->payload['deadline']['ruleset_id'],
                        'deadline_ruleset_hash' => $statement->payload['deadline']['ruleset_hash'],
                        'earliest_submission_on' =>
                            $statement->payload['deadline']['earliest_submission_on'],
                        'due_on' => $statement->payload['deadline']['due_on'],
                        'xsd_package_key' => $schema['package_key'],
                        'xsd_bundle_sha256' => $schema['bundle_sha256'],
                        'xml_sha256' => $xmlSha256,
                        'source_manifest_json' => $manifestJson,
                        'source_manifest_sha256' => $manifestHash,
                        'statement_ciphertext' => $this->encryption->encryptFor(
                            $plaintext,
                            $this->encryptionContext(
                                $supplierId,
                                $environment,
                                $employmentId,
                                $year,
                                $fingerprint,
                                $manifestHash,
                            ),
                        ),
                        'statement_fingerprint' => $fingerprint,
                        'request_fingerprint' => $requestFingerprint,
                        'idempotency_key_hash' => $idempotencyHash,
                        'created_by' => $createdBy,
                    ],
                    self::sources($statement),
                );
                $created = true;
            }
            $this->repository->bindClaim(
                $supplierId,
                $environment,
                $idempotencyHash,
                $statementId,
            );

            $obligation = $this->registerObligation(
                $supplierId,
                $employmentId,
                $year,
                $environment,
                $statement,
                $manifestHash,
                $statementId,
                $createdBy,
            );
            $submission = $this->bridge(
                $supplierId,
                $employmentId,
                $environment,
                $obligation['id'],
                $statementId,
                $manifestHash,
                $xml,
                $xmlSha256,
                $schema,
                $createdBy,
            );
            $totals = self::totals($statement);

            return [
                'statement_id' => $statementId,
                'created' => $created,
                'statement_kind' => (string) $scope['statement_kind'],
                'section_count' => $totals['section_count'],
                'insurance_days' => $totals['insurance_days'],
                'excluded_days_total' => $totals['excluded_days_total'],
                'due_on' => (string) $statement->payload['deadline']['due_on'],
                'earliest_submission_on' =>
                    (string) $statement->payload['deadline']['earliest_submission_on'],
                'obligation_id' => $obligation['id'],
                'submission_id' => $submission['submission_id'],
                'part_id' => $submission['part_id'],
                'artifact_id' => $submission['artifact_id'],
                'submission_status' => $submission['status'],
                'xml_sha256' => $xmlSha256,
                'environment' => $environment,
            ];
        });
    }

    /**
     * Smí za tenhle rok a vztah vůbec vzniknout samostatný evidenční list?
     *
     * Odpověď se vydává PŘED sestavením, aby obrazovka nevypadala jako roční
     * rutina, kterou stačí odklikat. Zaměstnavatel od roku 2026 evidenční list
     * nevede (§ 38 odst. 1 a 2 zákona č. 582/1991 Sb.) a jediné přípustné cesty
     * jsou výjimky; kdyby se to obsluha dozvěděla až z chyby po vyplnění
     * potvrzení, naučí se ji odklikávat jako překážku, ne číst jako pravidlo.
     *
     * `authority_request_available` říká, že tentýž rozsah by přípustný byl,
     * kdyby ho vyžádala ČSSZ/ÚSSZ. Není to nabídka, jak zákaz obejít — výzva je
     * skutečná událost, kterou uživatel dokládá datem doručení.
     *
     * @return array{
     *   allowed:bool,routine:bool,reason:string,rule:string,
     *   employment_end_date:string|null,authority_request_available:bool,
     *   last_annual_year:int
     * }
     */
    public function eligibility(
        int $supplierId,
        int $employmentId,
        int $year,
    ): array {
        $participation = $this->repository->employmentParticipation(
            $supplierId,
            $employmentId,
        );
        $endDate = $participation['end_date'];
        $eligibility = EldpDeadlinePolicy::standaloneStatementAllowed(
            $year,
            $endDate !== null && $endDate <= sprintf('%04d-12-31', $year)
                ? $endDate
                : null,
            false,
        );

        return [
            ...$eligibility,
            'employment_end_date' => $endDate,
            'authority_request_available' => !$eligibility['allowed'],
            'last_annual_year' => EldpDeadlinePolicy::LAST_ANNUAL_YEAR,
        ];
    }

    /**
     * Přesné bajty zmrazeného XML se v evidenčním listu neuchovávají znovu —
     * pravdou je artefakt platformy podání. Tahle metoda proto vrací jen
     * ověřený dešifrovaný snapshot pro zobrazení a znovusestavení.
     *
     * @return array<string,mixed>|null
     */
    public function statement(
        int $supplierId,
        string $environment,
        int $employmentId,
        int $year,
    ): ?array {
        $stored = $this->repository->findByScopeForUpdate(
            $supplierId,
            $environment,
            $employmentId,
            $year,
        );
        if ($stored === null) {
            return null;
        }

        return [
            'id' => $stored['id'],
            'statement_kind' => $stored['statement_kind'],
            'period_from' => $stored['period_from'],
            'period_to' => $stored['period_to'],
            'section_count' => $stored['section_count'],
            'insurance_days' => $stored['insurance_days'],
            'excluded_days_total' => $stored['excluded_days_total'],
            'deducted_days_total' => $stored['deducted_days_total'],
            'due_on' => $stored['due_on'],
            'earliest_submission_on' => $stored['earliest_submission_on'],
            'xml_sha256' => $stored['xml_sha256'],
            'payload' => $this->decrypt($stored),
        ];
    }

    /**
     * @param array<string,mixed> $stored
     * @return array<string,mixed>
     */
    private function decrypt(array $stored): array
    {
        $manifestJson = (string) $stored['source_manifest_json'];
        if (!hash_equals(
            (string) $stored['source_manifest_sha256'],
            hash('sha256', $manifestJson),
        )) {
            throw new EldpValidationException(
                'eldp_hash_mismatch',
                'Otisk manifestu evidenčního listu nesouhlasí.',
            );
        }
        $plaintext = $this->encryption->decryptFor(
            (string) $stored['statement_ciphertext'],
            $this->encryptionContext(
                (int) $stored['supplier_id'],
                (string) $stored['environment'],
                (int) $stored['employment_id'],
                (int) $stored['statement_year'],
                (string) $stored['statement_fingerprint'],
                (string) $stored['source_manifest_sha256'],
            ),
        );
        if (!hash_equals(
            (string) $stored['statement_fingerprint'],
            $this->sensitiveData->keyedFingerprint(
                $plaintext,
                self::ENCRYPTION_PURPOSE,
                (int) $stored['supplier_id'],
            ),
        )) {
            throw new EldpValidationException(
                'eldp_hash_mismatch',
                'Citlivý snapshot evidenčního listu má jiný otisk.',
            );
        }
        $payload = json_decode($plaintext, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($payload) || array_is_list($payload)
            || CanonicalJson::encode($payload) !== $plaintext
            || ($payload['schema_reference'] ?? null)
                !== EldpAnnualStatement::SCHEMA_REFERENCE
        ) {
            throw new EldpValidationException(
                'eldp_hash_mismatch',
                'Citlivý snapshot evidenčního listu neodpovídá manifestu.',
            );
        }

        return $payload;
    }

    /** @return array{id:int,due_on:string,status:string,row_version:int,created:bool} */
    private function registerObligation(
        int $supplierId,
        int $employmentId,
        int $year,
        string $environment,
        EldpAnnualStatement $statement,
        string $manifestHash,
        int $statementId,
        int $createdBy,
    ): array {
        $deadline = $statement->payload['deadline'];
        $scope = $statement->scope();

        return $this->obligations->register(
            $supplierId,
            self::AGENDA_CODE,
            self::SUBJECT_TYPE,
            self::employmentReference($employmentId),
            (string) $scope['period_from'],
            (string) $scope['period_to'],
            'regular',
            self::CHANNEL,
            self::SOURCE_EVENT_TYPE,
            self::statementReference($statementId),
            $manifestHash,
            (string) $deadline['earliest_submission_on'],
            (string) $deadline['due_on'],
            (string) $deadline['calendar_basis'],
            (string) $deadline['ruleset_id'],
            (string) $deadline['ruleset_hash'],
            "eldp-obligation:{$environment}:{$employmentId}:{$year}:{$manifestHash}",
            null,
            $createdBy,
            null,
            $environment,
        );
    }

    /**
     * @param array{package_key:string,data_version:string,bundle_sha256:string} $schema
     * @return array{submission_id:int,part_id:int,artifact_id:int,status:string}
     */
    private function bridge(
        int $supplierId,
        int $employmentId,
        string $environment,
        int $obligationId,
        int $statementId,
        string $manifestHash,
        string $xml,
        string $xmlSha256,
        array $schema,
        int $createdBy,
    ): array {
        $keyBase = "eldp:{$environment}:{$statementId}:{$manifestHash}";
        $submission = $this->submissions->prepare(
            $supplierId,
            $obligationId,
            'regular',
            self::CHANNEL,
            $manifestHash,
            "eldp-submission:{$keyBase}",
            null,
            null,
            $createdBy,
            $environment,
        );
        if (!$submission['created']) {
            return $this->replay(
                $supplierId,
                $submission,
                "eldp-artifact:{$keyBase}",
                $xmlSha256,
                $environment,
            );
        }
        $part = $this->submissions->addPart(
            $supplierId,
            $submission['id'],
            $submission['row_version'],
            "eldp:{$statementId}",
            self::AGENDA_CODE,
            self::employmentReference($employmentId),
            self::SOURCE_EVENT_TYPE,
            self::statementReference($statementId),
            $manifestHash,
        );
        $artifact = $this->submissions->storeArtifact(
            $supplierId,
            $submission['id'],
            $part['submission_row_version'],
            $part['id'],
            'outbound_xml',
            'outbound',
            'application/xml',
            $xml,
            $schema['data_version'],
            $schema['package_key'],
            self::CHANNEL,
            "eldp-artifact:{$keyBase}",
            $createdBy,
        );
        if (!hash_equals($xmlSha256, (string) $artifact['artifact_sha256'])) {
            throw new \UnexpectedValueException(
                'Otisk artefaktu neodpovídá přesnému XML evidenčního listu.',
            );
        }
        $validated = $this->submissions->transition(
            $supplierId,
            $submission['id'],
            $artifact['submission_row_version'],
            'validated',
        );
        // Konec řetězu. Do `ready` evidenční list nepřechází — odeslání
        // spouští člověk a datová věta odesílaného ELDP navíc není připnutá.
        $prepared = $this->submissions->transition(
            $supplierId,
            $submission['id'],
            $validated['row_version'],
            'prepared',
        );

        return [
            'submission_id' => $submission['id'],
            'part_id' => $part['id'],
            'artifact_id' => $artifact['id'],
            'status' => (string) $prepared['status'],
        ];
    }

    /**
     * @param array<string,mixed> $submission
     * @return array{submission_id:int,part_id:int,artifact_id:int,status:string}
     */
    private function replay(
        int $supplierId,
        array $submission,
        string $artifactKey,
        string $xmlSha256,
        string $environment,
    ): array {
        if (!in_array($submission['status'] ?? null, ['prepared', 'validated'], true)) {
            throw new EldpValidationException(
                'eldp_submission_replay_state_invalid',
                'Existující podání evidenčního listu už není v idempotentním stavu připraveno.',
            );
        }
        $artifact = $this->submissionRepository
            ->findArtifactByIdempotencyForUpdate(
                $supplierId,
                hash('sha256', $artifactKey, true),
                $environment,
            );
        if ($artifact === null
            || $artifact['submission_id'] !== $submission['id']
            || $artifact['part_id'] === null
            || $artifact['artifact_kind'] !== 'outbound_xml'
            || $artifact['direction'] !== 'outbound'
            || !hash_equals($xmlSha256, (string) $artifact['artifact_sha256'])
        ) {
            throw new EldpValidationException(
                'eldp_submission_replay_mismatch',
                'Existující podání evidenčního listu neodpovídá přesnému XML.',
            );
        }

        return [
            'submission_id' => (int) $submission['id'],
            'part_id' => (int) $artifact['part_id'],
            'artifact_id' => (int) $artifact['id'],
            'status' => (string) $submission['status'],
        ];
    }

    /**
     * @return array{
     *   section_count:int,insurance_days:int,
     *   excluded_days_total:int,deducted_days_total:int
     * }
     */
    private static function totals(EldpAnnualStatement $statement): array
    {
        $sections = $statement->sections();
        $insuranceDays = 0;
        $excluded = 0;
        $deducted = 0;
        foreach ($sections as $section) {
            $insuranceDays += (int) $section['insurance_days'];
            $excluded += (int) $section['excluded_days_total'];
            $deducted += (int) $section['deducted_days_total'];
        }

        return [
            'section_count' => count($sections),
            'insurance_days' => $insuranceDays,
            'excluded_days_total' => $excluded,
            'deducted_days_total' => $deducted,
        ];
    }

    /**
     * @return list<array{
     *   period_start:string,revision_id:int,run_id:int,
     *   input_snapshot_hash:string,result_snapshot_hash:string
     * }>
     */
    private static function sources(EldpAnnualStatement $statement): array
    {
        $sources = $statement->payload['source_revisions'] ?? null;
        if (!is_array($sources) || !array_is_list($sources)) {
            throw new \UnexpectedValueException(
                'Zdrojové revize evidenčního listu nejsou seznam.',
            );
        }
        $normalized = [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                throw new \UnexpectedValueException(
                    'Zdrojová revize evidenčního listu není objekt.',
                );
            }
            $normalized[] = [
                'period_start' => (string) $source['period_start'],
                'revision_id' => (int) $source['revision_id'],
                'run_id' => (int) $source['run_id'],
                'input_snapshot_hash' => (string) $source['input_snapshot_hash'],
                'result_snapshot_hash' => (string) $source['result_snapshot_hash'],
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $confirmation
     * @return array<string,mixed>
     */
    private static function normalizedConfirmation(array $confirmation): array
    {
        return [
            'excluded_days_confirmed' => $confirmation['excluded_days_confirmed'] ?? null,
            'deducted_days_none' => $confirmation['deducted_days_none'] ?? null,
            'requested_by_authority' => $confirmation['requested_by_authority'] ?? null,
            'authority_request_received_on' =>
                $confirmation['authority_request_received_on'] ?? null,
            'note' => is_string($confirmation['note'] ?? null)
                ? trim($confirmation['note'])
                : null,
        ];
    }

    public static function employmentReference(int $employmentId): string
    {
        if ($employmentId <= 0) {
            throw new \InvalidArgumentException(
                'Pracovní vztah musí být kladné číslo.',
            );
        }

        return "employment:{$employmentId}";
    }

    public static function statementReference(int $statementId): string
    {
        if ($statementId <= 0) {
            throw new \InvalidArgumentException(
                'Evidenční list musí být kladné číslo.',
            );
        }

        return "eldp_statement:{$statementId}";
    }

    private function encryptionContext(
        int $supplierId,
        string $environment,
        int $employmentId,
        int $year,
        string $fingerprint,
        string $manifestHash,
    ): string {
        return CanonicalJson::encode([
            'purpose' => self::ENCRYPTION_PURPOSE,
            'supplier_id' => $supplierId,
            'environment' => $environment,
            'employment_id' => $employmentId,
            'statement_year' => $year,
            'statement_fingerprint' => $fingerprint,
            'source_manifest_sha256' => $manifestHash,
        ]);
    }
}
