<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Repository\Payroll\JmhzPreparationSnapshotRepository;
use MyInvoice\Repository\Payroll\PayrollPeopleRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Report\EpoEnvelope;
use Psr\Clock\ClockInterface;

final readonly class JmhzContentCorrectionSubmissionService
{
    private const CHANNEL = 'vrep_apep';
    private const PRODUCT_NAME = 'MyÚčto.cz';

    public function __construct(
        private JmhzScenario1DocumentService $documents,
        private JmhzScenario1XmlValidator $validator,
        private JmhzScenario1ControlValidator $controls,
        private JmhzSubmissionGuidFactory $guids,
        private JmhzEffectiveFormLedgerResolver $effective,
        private JmhzFrozenPayloadReader $frozen,
        private JmhzPreparationSnapshotRepository $preparations,
        private PayrollPeopleRepository $people,
        private PayrollSubmissionRepository $repository,
        private PayrollSubmissionService $submissions,
        private PayrollObligationService $obligations,
        private ClockInterface $clock,
        private JmhzDeadlinePolicy $deadlines = new JmhzDeadlinePolicy(),
    ) {}

    /** @return array<string,mixed> */
    public function preparationCandidates(
        int $supplierId,
        string $environment,
        int $regularSubmissionId,
        ?int $officeId = null,
    ): array {
        $this->regularRoot($supplierId, $environment, $regularSubmissionId);
        $obligation = $this->repository->findObligationOfSubmission(
            $supplierId,
            $environment,
            $regularSubmissionId,
        ) ?? throw new \DomainException('K řádnému podání chybí evidovaná povinnost.');
        if ($obligation['subject_type'] !== 'payroll_run'
            || preg_match(
                '/^payroll_run:([1-9][0-9]*)(?::office:([1-9][0-9]*))?$/D',
                $obligation['subject_reference'],
                $matches,
            ) !== 1
        ) {
            throw new \DomainException('Řádné podání nemá jednoznačný mzdový běh.');
        }
        $runId = (int) $matches[1];
        $obligationOfficeId = isset($matches[2]) ? (int) $matches[2] : null;
        if ($officeId !== null && $obligationOfficeId !== null && $officeId !== $obligationOfficeId) {
            throw new \DomainException('Zvolená mzdová účtárna neodpovídá řádnému podání.');
        }
        $effectiveOfficeId = $officeId ?? $obligationOfficeId;

        $rows = [];
        foreach ($this->preparations->listSourceReadyForCorrection(
            $supplierId,
            $environment,
            $runId,
            $obligation['period_start'],
        ) as $preparation) {
            try {
                [, $document] = $this->context(
                    $supplierId,
                    $environment,
                    $regularSubmissionId,
                    $preparation['id'],
                    $effectiveOfficeId,
                );
            } catch (\DomainException $exception) {
                continue;
            }
            $rows[] = [
                ...$preparation,
                'document_sha256' => $document->sha256(),
            ];
        }

        return [
            'environment' => $environment,
            'submission_id' => $regularSubmissionId,
            'preparations' => $rows,
            'auto_selected_preparation_id' => count($rows) === 1 ? $rows[0]['id'] : null,
        ];
    }

    /** @return array<string,mixed> */
    public function candidates(
        int $supplierId,
        string $environment,
        int $regularSubmissionId,
        int $preparationId,
        ?int $officeId = null,
    ): array {
        [$resolution, $document, $identity, $current] = $this->context(
            $supplierId,
            $environment,
            $regularSubmissionId,
            $preparationId,
            $officeId,
        );
        unset($resolution);
        $set = $this->effective->resolve(
            $supplierId,
            $environment,
            $regularSubmissionId,
            array_keys($current),
        );
        $names = $this->people->namesForTenant(
            $supplierId,
            array_values(array_unique(array_column($current, 'employee_id'))),
        );
        $rows = [];
        foreach ($current as $externalId => $row) {
            $state = $set->forEmployment($externalId);
            $rows[] = [
                'employment_external_identifier' => $externalId,
                'person_external_identifier' => $row['person_external_identifier'],
                'employee_name' => $names[$row['employee_id']] ?? null,
                'effective_state' => $state->state,
                'action' => $state->state === 'accepted'
                    ? 'correct_values'
                    : 'complete_form',
            ];
        }

        return [
            'environment' => $environment,
            'submission_id' => $regularSubmissionId,
            'preparation_id' => $preparationId,
            'submission_guid' => $identity->submissionGuid,
            'document_sha256' => $document->sha256(),
            'forms' => $rows,
        ];
    }

    /**
     * @param list<string> $employmentExternalIdentifiers
     * @return array<string,mixed>
     */
    public function freeze(
        int $supplierId,
        string $environment,
        int $regularSubmissionId,
        int $preparationId,
        array $employmentExternalIdentifiers,
        ?int $createdBy = null,
        ?int $officeId = null,
    ): array {
        if ($supplierId <= 0 || $regularSubmissionId <= 0 || $preparationId <= 0
            || !in_array($environment, ['test', 'production'], true)
        ) {
            throw new \InvalidArgumentException('Rozsah obsahové opravy JMHZ není platný.');
        }
        $selection = self::selection($employmentExternalIdentifiers);
        $requestHash = hash('sha256', CanonicalJson::encode([
            'schema_reference' => 'payroll-jmhz-content-correction-request.v1',
            'supplier_id' => $supplierId,
            'environment' => $environment,
            'regular_submission_id' => $regularSubmissionId,
            'preparation_id' => $preparationId,
            'office_id' => $officeId,
            'employment_external_identifiers' => $selection,
        ]));
        $submissionKey = 'jmhz25-content-correction-submission:' . $requestHash;
        $artifactKey = 'jmhz25-content-correction-artifact:' . $requestHash;

        return $this->repository->transaction(function () use (
            $supplierId,
            $environment,
            $regularSubmissionId,
            $preparationId,
            $officeId,
            $selection,
            $createdBy,
            $requestHash,
            $submissionKey,
            $artifactKey,
        ): array {
            if (!$this->repository->lockSupplier($supplierId)) {
                throw new \DomainException('Firma JMHZ podání nebyla nalezena.');
            }
            $existing = $this->repository->findSubmissionByIdempotencyForUpdate(
                $supplierId,
                hash('sha256', $submissionKey, true),
                $environment,
            );
            if ($existing !== null) {
                return $this->replayed($supplierId, $environment, $existing, $artifactKey);
            }
            [$resolution, $document, $identity, $current] = $this->context(
                $supplierId,
                $environment,
                $regularSubmissionId,
                $preparationId,
                $officeId,
            );
            $set = $this->effective->resolve(
                $supplierId,
                $environment,
                $regularSubmissionId,
                array_keys($current),
            );
            $forms = [];
            $formGuids = [];
            foreach ($selection as $externalId) {
                $source = $current[$externalId] ?? null;
                if ($source === null) {
                    throw new JmhzXmlException(
                        'jmhz_content_correction_source_form_missing',
                        'Aktuální příprava neobsahuje vybraný pracovní vztah.',
                    );
                }
                $state = $set->forEmployment($externalId);
                $employmentId = $source['employment_id'];
                if ($state->state === 'accepted' && $state->formGuid !== null) {
                    $forms[] = JmhzContentCorrectionForm::amendAccepted(
                        $employmentId,
                        $state->formGuid,
                        true,
                        true,
                    );
                    $formGuids[$employmentId] = $state->formGuid;
                    continue;
                }
                $forms[] = match ($state->state) {
                    'rejected' => JmhzContentCorrectionForm::replaceRejected($employmentId, true, true),
                    'cancelled' => JmhzContentCorrectionForm::replaceCancelled($employmentId, true, true),
                    'missing' => JmhzContentCorrectionForm::replaceMissing($employmentId, true, true),
                    default => throw new JmhzXmlException(
                        'jmhz_content_correction_state_invalid',
                        'Vybraný formulář nemá bezpečně opravitelný stav.',
                    ),
                };
                $formGuids[$employmentId] = $this->guids->next();
            }
            $plan = JmhzContentCorrectionPlan::create($forms);
            $envelope = JmhzSubmissionEnvelope::createForExistingSubmission(
                $identity->submissionGuid,
                $formGuids,
                $this->filledAt(),
                self::PRODUCT_NAME,
                EpoEnvelope::appVersion() ?? '0',
            );
            $result = $this->validator->dryRunCorrection($resolution, $envelope, $plan);
            $this->assertWholeCompanyControls($resolution, $identity, $current, $set);
            $obligation = $this->repository->findObligationOfSubmission(
                $supplierId,
                $environment,
                $regularSubmissionId,
            ) ?? throw new JmhzXmlException(
                'jmhz_submission_obligation_required',
                'K původnímu podání chybí evidovaná povinnost.',
            );
            $correctionObligationId = $this->correctionObligation(
                $supplierId,
                $environment,
                $obligation,
                $regularSubmissionId,
                $preparationId,
                $requestHash,
                $createdBy,
            );
            $submission = $this->submissions->prepare(
                $supplierId,
                $correctionObligationId,
                'correction',
                self::CHANNEL,
                $document->sha256(),
                $submissionKey,
                null,
                $regularSubmissionId,
                $createdBy,
                $environment,
            );
            if (!$submission['created']) {
                return $this->replayed($supplierId, $environment, $submission, $artifactKey);
            }
            $part = $this->submissions->addPart(
                $supplierId,
                $submission['id'],
                $submission['row_version'],
                "jmhz25-content-correction:{$preparationId}",
                JmhzSubmissionBridgeService::AGENDA_CODE,
                $obligation['subject_reference'],
                'jmhz_preparation',
                JmhzSubmissionBridgeService::sourceEventReference($preparationId),
                $document->sha256(),
            );
            $artifact = $this->submissions->storeArtifact(
                $supplierId,
                $submission['id'],
                $part['submission_row_version'],
                $part['id'],
                'outbound_xml',
                'outbound',
                'application/xml',
                $result['xml'],
                $result['schema']['package_key'],
                JmhzControlSourceCatalog::CATALOG_KEY,
                self::CHANNEL,
                $artifactKey,
                $createdBy,
            );
            $validated = $this->submissions->transition(
                $supplierId,
                $submission['id'],
                $artifact['submission_row_version'],
                'validated',
            );
            $ready = $this->submissions->transition(
                $supplierId,
                $submission['id'],
                $validated['row_version'],
                'ready',
            );

            return [
                'submission_id' => $submission['id'],
                'part_id' => $part['id'],
                'artifact_id' => $artifact['id'],
                'artifact_sha256' => $artifact['artifact_sha256'],
                'status' => $ready['status'],
                'row_version' => $ready['row_version'],
                'environment' => $environment,
                'created' => true,
                'submission_kind' => 'correction',
                'corrects_submission_id' => $regularSubmissionId,
                'submission_guid' => $identity->submissionGuid,
                'variable_symbol' => $identity->variableSymbol,
                'month' => $identity->month,
                'year' => $identity->year,
            ];
        });
    }

    /** @return array{JmhzScenario1Resolution,JmhzScenario1NormalizedDocument,JmhzFrozenSubmissionIdentity,array<string,array{employee_id:int,employment_id:int,person_external_identifier:string}>} */
    private function context(
        int $supplierId,
        string $environment,
        int $regularSubmissionId,
        int $preparationId,
        ?int $officeId,
    ): array {
        if ($supplierId <= 0 || $regularSubmissionId <= 0 || $preparationId <= 0
            || !in_array($environment, ['test', 'production'], true)
        ) {
            throw new \InvalidArgumentException('Rozsah obsahové opravy JMHZ není platný.');
        }
        $this->regularRoot($supplierId, $environment, $regularSubmissionId);
        $resolution = $this->documents->resolve($supplierId, $environment, $preparationId, $officeId);
        if ($resolution->status() !== 'resolved') {
            throw new JmhzXmlException(
                'jmhz_content_correction_preparation_blocked',
                'Aktuální příprava JMHZ není úplná: '
                    . JmhzBlockerExplainer::describe($resolution->blockers),
            );
        }
        $document = $resolution->requireResolvedDocument();
        $identity = $this->frozen->identity($supplierId, $environment, $regularSubmissionId);
        $header = is_array($document->payload['header'] ?? null) ? $document->payload['header'] : [];
        if (($header['variable_symbol'] ?? null) !== $identity->variableSymbol
            || ($header['year'] ?? null) !== $identity->year
            || ($header['month'] ?? null) !== $identity->month
        ) {
            throw new JmhzXmlException(
                'jmhz_content_correction_scope_mismatch',
                'Aktuální příprava patří jiné registraci nebo jinému období.',
            );
        }
        $lastCorrectionOn = $this->deadlines->lastCorrectionOn(sprintf(
            '%04d-%02d-01',
            $identity->year,
            $identity->month,
        ));
        if ($this->localDate() > $lastCorrectionOn) {
            throw new JmhzXmlException(
                'jmhz_content_correction_deadline_expired',
                'Desetiletá lhůta pro obsahovou opravu tohoto období uplynula.',
            );
        }

        return [$resolution, $document, $identity, self::currentForms($document)];
    }

    /** @return array<string,array{employee_id:int,employment_id:int,person_external_identifier:string}> */
    private static function currentForms(JmhzScenario1NormalizedDocument $document): array
    {
        $forms = [];
        $people = $document->payload['people'] ?? null;
        if (!is_array($people) || !array_is_list($people)) {
            throw new JmhzXmlException('jmhz_content_correction_current_set_invalid', 'Aktuální příprava nemá úplný firemní set formulářů.');
        }
        foreach ($people as $person) {
            $employments = is_array($person) ? ($person['employments'] ?? null) : null;
            if (!is_array($employments) || count($employments) !== 1 || !is_array($employments[0])) {
                throw new JmhzXmlException('jmhz_content_correction_current_set_invalid', 'Aktuální příprava nemá právě jeden formulář na osobu.');
            }
            $employment = $employments[0];
            $identity = is_array($employment['identity'] ?? null) ? $employment['identity'] : [];
            $employeeId = $person['employee_id'] ?? null;
            $employmentId = $employment['employment_id'] ?? null;
            $externalId = $identity['employment_external_identifier'] ?? null;
            $personId = $identity['person_external_identifier'] ?? null;
            if (!is_int($employeeId) || !is_int($employmentId)
                || !is_string($externalId) || $externalId === ''
                || !is_string($personId) || $personId === '' || isset($forms[$externalId])
            ) {
                throw new JmhzXmlException('jmhz_content_correction_current_set_invalid', 'Aktuální příprava obsahuje nejednoznačnou identitu formuláře.');
            }
            $forms[$externalId] = [
                'employee_id' => $employeeId,
                'employment_id' => $employmentId,
                'person_external_identifier' => $personId,
            ];
        }
        ksort($forms, SORT_STRING);

        return $forms;
    }

    /** @param array<string,array{employee_id:int,employment_id:int,person_external_identifier:string}> $current */
    private function assertWholeCompanyControls(
        JmhzScenario1Resolution $resolution,
        JmhzFrozenSubmissionIdentity $identity,
        array $current,
        JmhzEffectiveFormSet $set,
    ): void {
        $guids = [];
        foreach ($current as $externalId => $row) {
            $state = $set->forEmployment($externalId);
            $guids[$row['employment_id']] = $state->formGuid ?? $this->guids->next();
        }
        $projection = $this->validator->dryRun(
            $resolution,
            JmhzSubmissionEnvelope::createForExistingSubmission(
                $identity->submissionGuid,
                $guids,
                $this->filledAt(),
                self::PRODUCT_NAME,
                EpoEnvelope::appVersion() ?? '0',
            ),
        );
        $report = $this->controls->validate(
            $projection['xml'],
            new JmhzControlContext($this->localDate(), null, true),
        );
        if (!$report->submittable()) {
            $findings = array_map(
                static fn (JmhzControlFinding $finding): string
                    => "kontrola {$finding->controlId} — {$finding->message}",
                [...$report->blocking(), ...$report->coverageGaps()],
            );
            throw new JmhzXmlException(
                'jmhz_content_correction_full_set_controls_failed',
                'Souhrn a PVPOJ neprošly kontrolami nad celým efektivním setem firmy: '
                    . ($findings === [] ? 'důvod neuveden' : implode('; ', $findings)),
            );
        }
    }

    /** @param array<string,mixed> $obligation */
    private function correctionObligation(
        int $supplierId,
        string $environment,
        array $obligation,
        int $regularSubmissionId,
        int $preparationId,
        string $requestHash,
        ?int $createdBy,
    ): int {
        $lastCorrectionOn = $this->deadlines->lastCorrectionOn(
            (string) $obligation['period_start'],
        );
        $rulesetId = 'jmhz-correction-10y-from-due-year-v2';
        $registered = $this->obligations->register(
            $supplierId,
            (string) $obligation['agenda_code'],
            (string) $obligation['subject_type'],
            (string) $obligation['subject_reference'],
            (string) $obligation['period_start'],
            (string) $obligation['period_end'],
            'correction',
            self::CHANNEL,
            'jmhz_preparation_snapshot',
            JmhzSubmissionBridgeService::sourceEventReference($preparationId),
            $requestHash,
            (string) $obligation['period_start'],
            $lastCorrectionOn,
            'calendar_days',
            $rulesetId,
            hash('sha256', $rulesetId),
            "jmhz25-content-correction-obligation:{$supplierId}:{$environment}:{$regularSubmissionId}:{$requestHash}",
            $createdBy,
            $createdBy,
            null,
            $environment,
        );

        return $registered['id'];
    }

    /**
     * @param array<string,mixed> $submission
     * @return array<string,mixed>
     */
    private function replayed(int $supplierId, string $environment, array $submission, string $artifactKey): array
    {
        $artifact = $this->repository->findArtifactByIdempotencyForUpdate(
            $supplierId,
            hash('sha256', $artifactKey, true),
            $environment,
        );
        if ($artifact === null || $artifact['submission_id'] !== $submission['id'] || $artifact['part_id'] === null) {
            throw new JmhzXmlException('jmhz_submission_replay_mismatch', 'Zmrazený artefakt obsahové opravy chybí nebo neodpovídá podání.');
        }
        $rootId = $submission['corrects_submission_id'] ?? null;
        if (!is_int($rootId) || $rootId <= 0) {
            throw new JmhzXmlException('jmhz_submission_replay_mismatch', 'Obsahová oprava nemá jednoznačnou vazbu na řádné podání.');
        }
        $identity = $this->frozen->identity($supplierId, $environment, $rootId);

        return [
            'submission_id' => $submission['id'],
            'part_id' => $artifact['part_id'],
            'artifact_id' => $artifact['id'],
            'artifact_sha256' => $artifact['artifact_sha256'],
            'status' => $submission['status'],
            'row_version' => $submission['row_version'],
            'environment' => $environment,
            'created' => false,
            'submission_kind' => 'correction',
            'corrects_submission_id' => $rootId,
            'submission_guid' => $identity->submissionGuid,
            'variable_symbol' => $identity->variableSymbol,
            'month' => $identity->month,
            'year' => $identity->year,
        ];
    }

    /**
     * @param array<array-key,mixed> $values
     * @return list<string>
     */
    private static function selection(array $values): array
    {
        $selection = [];
        foreach ($values as $value) {
            if (!is_string($value) || trim($value) === '') {
                throw new \InvalidArgumentException('Vyberte alespoň jeden pracovní vztah s platným identifikátorem.');
            }
            $selection[trim($value)] = true;
        }
        $selection = array_keys($selection);
        sort($selection, SORT_STRING);
        if ($selection === []) {
            throw new \InvalidArgumentException('Vyberte alespoň jeden pracovní vztah k obsahové opravě.');
        }

        return $selection;
    }

    private function filledAt(): string
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    private function localDate(): string
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new \DateTimeZone('Europe/Prague'))
            ->format('Y-m-d');
    }

    /** @return array<string,mixed> */
    private function regularRoot(
        int $supplierId,
        string $environment,
        int $regularSubmissionId,
    ): array {
        if ($supplierId <= 0 || $regularSubmissionId <= 0
            || !in_array($environment, ['test', 'production'], true)
        ) {
            throw new \InvalidArgumentException('Rozsah obsahové opravy JMHZ není platný.');
        }
        $root = $this->repository->findSubmission($supplierId, $regularSubmissionId);
        if ($root === null || $root['environment'] !== $environment
            || $root['submission_kind'] !== 'regular' || $root['channel'] !== self::CHANNEL
        ) {
            throw new \DomainException('Řádné podání nebylo nalezeno ve stejné firmě a prostředí.');
        }
        if (!in_array($root['status'], ['accepted', 'partially_accepted'], true)) {
            throw new \DomainException(
                'Obsahovou opravu lze navázat jen na přijaté nebo částečně přijaté řádné podání.',
            );
        }

        return $root;
    }
}
