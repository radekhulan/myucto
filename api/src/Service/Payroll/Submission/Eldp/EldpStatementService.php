<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Eldp;

use MyInvoice\Repository\Payroll\EldpStatementRepository;
use MyInvoice\Repository\Payroll\PayrollPersonPensionEvidenceRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverReader;
use MyInvoice\Service\Payroll\Pension\PayrollPensionStatus;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;

/**
 * Evidenční list důchodového pojištění jako podání.
 *
 * Řetěz je záměrně krátký a končí dřív, než by mohl něco odeslat:
 *
 * 1. sestavení ze zmrazených schválených revizí roku, v roce přechodu z jiného
 *    mzdového programu doplněných o převzaté měsíce (`PayrollTakeoverReader`),
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
        /**
         * Druhý zdroj měsíců roku přechodu z jiného mzdového programu.
         * Sestavovač si ho nečte sám: stejně jako mzdové revize ho dostane
         * hotový, aby zůstal čistou funkcí podkladů.
         */
        private PayrollTakeoverReader $takeover,
        /** Důchodové údaje osoby — zdroj kódu D a odečtených dob listu. */
        private PayrollPersonPensionEvidenceRepository $pensions,
    ) {}

    /**
     * Důchodové údaje listu ze zákonné evidence osoby za vykazovaný rok.
     *
     * Zdrojem je evidence (sekce Důchod na kartě osoby), stejně jako
     * u měsíčního ELDP řezu JMHZ; výslovné potvrzení účetní zůstává kontrolou:
     * prázdné pole znamená „podle evidence", vyplněné musí s evidencí souhlasit
     * ({@see PayrollPensionStatus::mismatches()}). Účast na pojištění v cizině
     * evidence nevede, ta zůstává na potvrzení. Neúplné potvrzení se nechá
     * beze změny, aby ho sestavovač odmítl svým důvodem
     * (`eldp_pension_status_not_confirmed`).
     */
    private function pensionStatusFromEvidence(
        int $supplierId,
        int $employmentId,
        int $year,
        mixed $confirmed,
    ): mixed {
        if (!is_array($confirmed)
            || array_diff([...PayrollPensionStatus::STATUS_KEYS, 'foreign_insurance'], array_keys($confirmed)) !== []
        ) {
            return $confirmed;
        }
        $fromEvidence = $this->pensions->statusForEmployment(
            $supplierId,
            $employmentId,
            sprintf('%04d-01-01', $year),
            sprintf('%04d-12-31', $year),
        ) ?? array_fill_keys(PayrollPensionStatus::STATUS_KEYS, null);
        $mismatched = PayrollPensionStatus::mismatches($confirmed, $fromEvidence);
        if ($mismatched !== []) {
            throw new EldpValidationException(
                'eldp_pension_status_evidence_mismatch',
                'Důchodové údaje v potvrzení evidenčního listu se liší od zákonné evidence osoby ('
                    . implode(', ', $mismatched) . '). Zdrojem je zákonná evidence na kartě osoby '
                    . '(sekce Důchod): opravte ji tam, nebo pole v potvrzení nechte prázdná.',
            );
        }

        return array_replace($confirmed, $fromEvidence);
    }

    /**
     * Vztahy téže osoby s vlastním zmrazeným listem roku, na který tenhle vztah
     * nenavazuje. Navazující zaměstnání pokračuje v listu dřívějšího vztahu
     * jen tehdy, dokud ten list nevznikl bez něj — odeslaný list se nedoplňuje.
     *
     * @return list<int>
     */
    private function separatelyFiledEmployments(
        int $supplierId,
        string $environment,
        int $employmentId,
        int $year,
    ): array {
        $separate = [];
        foreach ($this->repository->frozenEmploymentsOfEmployee(
            $supplierId,
            $environment,
            $employmentId,
            $year,
        ) as $otherId => $continued) {
            if (!in_array($employmentId, $continued, true)) {
                $separate[] = $otherId;
            }
        }

        return $separate;
    }

    /**
     * @param array<string,mixed> $confirmation
     * @return array{
     *   statement_id:int,created:bool,statement_kind:string,
     *   eldp_type:string,corrects_statement_id:int|null,
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
            $boundStatementId = null;
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
                $boundStatementId = $claim['statement_id'];
            }

            $correction = ($confirmation['correction'] ?? false) === true;
            $latest = $this->repository->findByScopeForUpdate(
                $supplierId,
                $environment,
                $employmentId,
                $year,
            );
            /*
             * Opakování už zapsaného požadavku (idempotentní klíč je navázaný
             * na list) se porovnává s TÍM listem, ne s posledním v rozsahu —
             * jinak by opakovaný opravný list opravoval sám sebe.
             */
            $target = $boundStatementId !== null
                ? $this->repository->find($supplierId, $environment, $boundStatementId)
                : null;
            $buildConfirmation = $confirmation;
            unset($buildConfirmation['corrects']);
            $corrected = null;
            if ($correction) {
                $corrected = $target !== null
                    ? ($target['corrects_statement_id'] === null
                        ? null
                        : $this->repository->find(
                            $supplierId,
                            $environment,
                            (int) $target['corrects_statement_id'],
                        ))
                    : $latest;
                if ($corrected === null) {
                    throw new EldpValidationException(
                        'eldp_correction_without_original',
                        "Za rok {$year} zatím žádný evidenční list zmrazený není, takže není "
                            . 'co opravovat. Připravte řádný evidenční list.',
                    );
                }
                $buildConfirmation['corrects'] = self::correctionReference(
                    $corrected,
                    $this->decrypt($corrected),
                );
            }

            $buildConfirmation['pension_status'] = $this->pensionStatusFromEvidence(
                $supplierId,
                $employmentId,
                $year,
                $buildConfirmation['pension_status'] ?? null,
            );
            $statement = $this->builder->build(
                $supplierId,
                $employmentId,
                $year,
                $this->repository->revisionsForYear($supplierId, $year),
                $buildConfirmation,
                $this->takeover->forEmployment($supplierId, $employmentId, $year),
                $this->separatelyFiledEmployments($supplierId, $environment, $employmentId, $year),
                fn (int $continuedId) => $this->takeover->forEmployment($supplierId, $continuedId, $year),
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
            $manifest = [
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
            ];
            /*
             * Převzatá část roku přechodu má v manifestu tutéž váhu jako otisky
             * snapshotů mzdové revize: bez ní by z nešifrovaného manifestu nešlo
             * poznat, že část zákonné evidence nepochází z výpočtu MyÚčta.
             *
             * Klíče se přidávají jen tehdy, když převzatá data opravdu jsou.
             * Prázdné pole navíc by změnilo `source_manifest_sha256` i
             * `request_fingerprint` u všech dřív zmrazených listů a opakovaná
             * příprava by je odmítla jako změněný podklad.
             *
             * Identita osoby z původního systému (`external_person_ref`) sem
             * NEPATŘÍ — manifest se ukládá nešifrovaný. Zůstává jen v šifrovaném
             * snapshotu evidenčního listu; tady je období, zdroj a otisk řádku.
             */
            $takeovers = self::takeoverSources($statement);
            if ($takeovers !== []) {
                $manifest['source_takeovers'] = $takeovers;
            }
            $overridden = $statement->payload['takeover_overridden_periods'] ?? null;
            if (is_array($overridden) && $overridden !== []) {
                $manifest['takeover_overridden_periods'] = array_values(
                    array_map(strval(...), $overridden),
                );
            }
            if (($statement->payload['employment_dates_source'] ?? null) === 'takeover') {
                $manifest['employment_dates_source'] = 'takeover';
            }
            $continuation = $statement->payload['employment_continuation_evidence'] ?? null;
            if (is_array($continuation) && $continuation !== []) {
                $manifest['employment_continuation_evidence'] = $continuation;
            }
            $manifestJson = CanonicalJson::encode($manifest);
            $manifestHash = hash('sha256', $manifestJson);
            $requestFingerprint = hash('sha256', CanonicalJson::encode([
                'schema_reference' => self::REQUEST_SCHEMA,
                'supplier_id' => $supplierId,
                'environment' => $environment,
                'employment_id' => $employmentId,
                'statement_year' => $year,
                'source_manifest_sha256' => $manifestHash,
            ]));

            /*
             * Který zmrazený list je „ten samý požadavek": u opakovaného
             * opravného listu ten, na který je navázaný klíč, jinak poslední
             * list rozsahu. Opravný list bez zapsaného klíče se naopak
             * zakládá vždy nový — pokud podklad opravdu změnil.
             */
            $existing = $correction ? $target : ($target ?? $latest);
            if ($correction && $existing === null && $corrected !== null
                && self::sameContent($this->decrypt($corrected), $statement->payload)
            ) {
                throw new EldpValidationException(
                    'eldp_correction_without_change',
                    "Podklad evidenčního listu za rok {$year} se od zmrazeného listu "
                        . 'nezměnil, takže opravný list nemá co opravit.',
                );
            }
            if ($existing !== null) {
                if (!hash_equals(
                    (string) $existing['request_fingerprint'],
                    $requestFingerprint,
                )) {
                    throw new EldpValidationException(
                        'eldp_scope_already_frozen',
                        "Evidenční list za rok {$year} je už zmrazený s jiným obsahem. "
                            . 'Zmrazený list se nepřepisuje: změněný podklad podejte jako '
                            . 'opravný evidenční list (volba „Opravný evidenční list").',
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
                        'statement_sequence' => $corrected === null
                            ? 1
                            : (int) ($latest['statement_sequence'] ?? 1) + 1,
                        'corrects_statement_id' => $corrected === null
                            ? null
                            : (int) $corrected['id'],
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
                'eldp_type' => (string) ($statement->payload['form']['eldp_type'] ?? ''),
                'corrects_statement_id' => $corrected === null ? null : (int) $corrected['id'],
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
            'statement_sequence' => $stored['statement_sequence'] ?? 1,
            'corrects_statement_id' => $stored['corrects_statement_id'] ?? null,
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
     * Otisk převzatých měsíců pro nešifrovaný manifest.
     *
     * @return list<array{period_start:string,source:string,row_sha256:string}>
     */
    private static function takeoverSources(EldpAnnualStatement $statement): array
    {
        $sources = $statement->payload['source_takeovers'] ?? null;
        if ($sources === null) {
            return [];
        }
        if (!is_array($sources) || !array_is_list($sources)) {
            throw new \UnexpectedValueException(
                'Zdrojové převzaté měsíce evidenčního listu nejsou seznam.',
            );
        }
        $normalized = [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                throw new \UnexpectedValueException(
                    'Zdrojový převzatý měsíc evidenčního listu není objekt.',
                );
            }
            $normalized[] = [
                'period_start' => (string) $source['period_start'],
                'source' => (string) $source['source'],
                'row_sha256' => (string) $source['row_sha256'],
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
        /*
         * Lhůta z výzvy a důchodové údaje vstupují do otisku jen tehdy, když
         * přišly. Otisk dřív zapsaných požadavků se tím nemění.
         */
        $optional = [];
        if (is_string($confirmation['authority_request_due_on'] ?? null)
            && $confirmation['authority_request_due_on'] !== ''
        ) {
            $optional['authority_request_due_on'] = $confirmation['authority_request_due_on'];
        }
        if (array_key_exists('pension_status', $confirmation)) {
            $optional['pension_status'] = $confirmation['pension_status'];
        }
        if (is_string($confirmation['death_on'] ?? null) && $confirmation['death_on'] !== '') {
            $optional['death_on'] = $confirmation['death_on'];
        }

        return $optional + [
            'excluded_days_confirmed' => $confirmation['excluded_days_confirmed'] ?? null,
            // Klíč zůstává kvůli otisku dřívějších požadavků; odečtené doby
            // se dnes odvozují a potvrzení se nevyžaduje.
            'deducted_days_none' => $confirmation['deducted_days_none'] ?? null,
            'requested_by_authority' => $confirmation['requested_by_authority'] ?? null,
            'authority_request_received_on' =>
                $confirmation['authority_request_received_on'] ?? null,
            'note' => is_string($confirmation['note'] ?? null)
                ? trim($confirmation['note'])
                : null,
            'correction' => ($confirmation['correction'] ?? false) === true,
            'prepared_on' => is_string($confirmation['prepared_on'] ?? null)
                && $confirmation['prepared_on'] !== ''
                    ? $confirmation['prepared_on']
                    : null,
        ];
    }

    /**
     * Odkaz opravného listu na list, který opravuje: jeho typ a datum
     * vyhotovení. Listy sestavené dřív (bez údajů tiskopisu ve snapshotu)
     * dostanou typ ze svého druhu — roční `01`, ukončovací `02`.
     *
     * @param array<string,mixed> $stored
     * @param array<string,mixed> $payload
     * @return array{statement_id:int,eldp_type:string,prepared_on:?string}
     */
    private static function correctionReference(array $stored, array $payload): array
    {
        $form = is_array($payload['form'] ?? null) ? $payload['form'] : [];
        $type = $form['eldp_type'] ?? null;
        if (!is_string($type) || $type === '') {
            $type = $stored['statement_kind'] === 'termination' ? '02' : '01';
        }
        // Oprava opravného listu opravuje týž původní typ (51 → 01).
        $type = '0' . substr($type, 1);

        return [
            'statement_id' => (int) $stored['id'],
            'eldp_type' => $type,
            'prepared_on' => is_string($form['prepared_on'] ?? null)
                ? $form['prepared_on']
                : null,
        ];
    }

    /**
     * Mění opravný list něco, co ČSSZ z listu čte? Porovnávají se sekce,
     * období a „zaměstnán od" — ne typ, odkaz na opravovaný list ani datum
     * vyhotovení, které se u opravy mění vždy.
     *
     * @param array<string,mixed> $previous
     * @param array<string,mixed> $next
     */
    private static function sameContent(array $previous, array $next): bool
    {
        $content = static fn (array $payload): string => CanonicalJson::encode([
            'sections' => array_map(
                static fn (mixed $section): array => is_array($section)
                    ? array_diff_key($section, ['months_without_insurance' => true, 'post_termination_periods' => true])
                    : [],
                is_array($payload['eldp_sections'] ?? null) ? $payload['eldp_sections'] : [],
            ),
            'period_from' => $payload['scope']['period_from'] ?? null,
            'period_to' => $payload['scope']['period_to'] ?? null,
            'employed_from' => is_array($payload['form'] ?? null)
                ? ($payload['form']['employed_from'] ?? null)
                : null,
            'months_without_insurance' => array_map(
                static fn (mixed $section): mixed => is_array($section)
                    ? ($section['months_without_insurance'] ?? [])
                    : [],
                is_array($payload['eldp_sections'] ?? null) ? $payload['eldp_sections'] : [],
            ),
        ]);

        return hash_equals($content($previous), $content($next));
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

    public static function encryptionContext(
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
