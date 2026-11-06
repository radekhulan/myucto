<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Repository\Payroll\PayrollAbsenceRepository;
use MyInvoice\Repository\Payroll\PayrollPeopleRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationIdentityRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionCalendar;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Report\EpoEnvelope;
use Psr\Clock\ClockInterface;

/**
 * Anulace měsíce nulovým opravným hlášením (Pravidla podání JMHZ 1.4.5,
 * kap. 1, „storno po 20. dni" a tabulka „OPRAVNÉ HLÁŠENÍ s nulovými
 * hodnotami za měsíc, který neměl být již hlášen").
 *
 * Storno součásti jde jen do lhůty řádného podání a za leden až březen 2026
 * vůbec; po lhůtě se měsíc, ve kterém pracovněprávní vztah netrval, napravuje
 * jen nulovým opravným formulářem. Pro takový měsíc revize mzdového běhu
 * s tímto vztahem není, proto se formulář staví ze zmrazeného původního
 * podání ({@see JmhzZeroAmendmentXmlSerializer}), ne z přípravy.
 *
 * Situace se nikdy neodvozuje z dat ({@see JmhzZeroReportProfile}); volá se
 * výslovně a služba ji ověří proti evidenci: anulovat jde jen měsíc, který
 * celý leží mimo období vztahu v evidenci. Kdyby vztah v měsíci podle
 * evidence trval, anulace by zrušila existující pojistný vztah.
 *
 * Zmrazení, idempotence a odeslání jsou stejné jako u obsahové opravy:
 * podání druhu `correction` navázané na řádné podání, odesílá se cestou
 * řádného hlášení.
 */
final readonly class JmhzZeroAmendmentSubmissionService
{
    public const SITUATION = JmhzZeroReportProfile::SITUATION_AMENDMENT_UNREPORTABLE_MONTH;

    private const CHANNEL = 'vrep_apep';

    private const ROOT_CHANNELS = ['vrep_apep', 'isds'];

    private const PRODUCT_NAME = 'MyÚčto.cz';

    public function __construct(
        private PayrollSubmissionRepository $repository,
        private PayrollSubmissionService $submissions,
        private PayrollObligationService $obligations,
        private JmhzFrozenPayloadReader $frozen,
        private JmhzScenario1XmlValidator $validator,
        private JmhzScenario1ControlValidator $controls,
        private JmhzDeadlinePolicy $deadlines,
        private ClockInterface $clock,
        private PayrollSensitiveData $sensitiveData,
        private PayrollRegistrationIdentityRepository $identities,
        private PayrollAbsenceRepository $employments,
        private PayrollPeopleRepository $people,
        private JmhzZeroAmendmentXmlSerializer $serializer = new JmhzZeroAmendmentXmlSerializer(),
    ) {}

    /**
     * Součásti přijatého řádného podání s tím, zda jde jejich měsíc anulovat
     * a proč ne.
     *
     * @return array<string,mixed>
     */
    public function candidates(
        int $supplierId,
        string $environment,
        int $regularSubmissionId,
        ?string $onlyEmploymentHash = null,
    ): array {
        $original = $this->requireRoot($supplierId, $environment, $regularSubmissionId);
        $xml = $this->frozen->bytes($supplierId, $environment, $regularSubmissionId);
        $identity = JmhzFrozenSubmissionIdentity::read($xml);
        $periodStart = sprintf('%04d-%02d-01', $identity->year, $identity->month);
        $touched = $this->touchedFormGuids($supplierId, $environment, $regularSubmissionId);
        $shape = $this->serializer->assess($xml);
        $rows = [];
        $employeeIds = [];
        foreach ($this->frozen->components($supplierId, $environment, $regularSubmissionId) as $component) {
            $guid = strtoupper($component['form_guid']);
            $hash = $this->employmentHash($supplierId, $component['employment_external_identifier']);
            if ($onlyEmploymentHash !== null && ($hash === null || !hash_equals($onlyEmploymentHash, $hash))) {
                continue;
            }
            $link = $hash === null
                ? null
                : $this->identities->employmentByExternalIdValueHash($supplierId, $environment, 'id_ppv', $hash);
            $bounds = $link === null
                ? ['start' => null, 'end' => null]
                : $this->employments->employmentBounds($supplierId, $link['employment_id']);
            $blocker = null;
            try {
                if ($original['status'] !== 'accepted') {
                    throw new \DomainException('Řádné hlášení nebylo přijato celé.');
                }
                $this->assertComponent($guid, $periodStart, $link, $bounds, $touched);
                if (($shape[$guid] ?? null) !== null) {
                    throw $shape[$guid];
                }
            } catch (JmhzXmlException $exception) {
                $blocker = ['code' => $exception->validationCode, 'message' => $exception->getMessage()];
            } catch (\DomainException $exception) {
                $blocker = ['code' => 'jmhz_zero_amendment_root_not_accepted', 'message' => $exception->getMessage()];
            }
            if ($link !== null) {
                $employeeIds[] = $link['employee_id'];
            }
            $rows[] = [
                'amendments' => $touched[$guid] ?? [],
                'form_guid' => $guid,
                'person_external_identifier' => $component['person_external_identifier'],
                'employment_external_identifier' => $component['employment_external_identifier'],
                'employment_id' => $link['employment_id'] ?? null,
                'employee_id' => $link['employee_id'] ?? null,
                'employment_start' => $bounds['start'],
                'employment_end' => $bounds['end'],
                'annullable' => $blocker === null,
                'blocker' => $blocker,
            ];
        }
        $names = $employeeIds === []
            ? []
            : $this->people->namesForTenant($supplierId, array_values(array_unique($employeeIds)));
        foreach ($rows as $index => $row) {
            $rows[$index]['employee_name'] = $row['employee_id'] === null ? null : ($names[$row['employee_id']] ?? null);
        }

        return [
            'environment' => $environment,
            'submission_id' => $regularSubmissionId,
            'submission_status' => $original['status'],
            'situation' => self::SITUATION,
            'month' => $identity->month,
            'year' => $identity->year,
            'cancellation_allowed' => $this->cancellationAllowed($identity),
            'forms' => $rows,
        ];
    }

    /**
     * @param list<string> $formGuids
     * @return array<string,mixed>
     */
    public function freeze(
        int $supplierId,
        string $environment,
        int $regularSubmissionId,
        array $formGuids,
        string $situation,
        ?int $createdBy = null,
    ): array {
        if ($supplierId <= 0 || $regularSubmissionId <= 0
            || !in_array($environment, ['test', 'production'], true)
        ) {
            throw new \InvalidArgumentException('Rozsah nulového opravného hlášení JMHZ není platný.');
        }
        if ($situation !== self::SITUATION) {
            throw new JmhzXmlException(
                'jmhz_zero_amendment_situation_required',
                'Anulaci měsíce je nutné výslovně potvrdit jako situaci „měsíc, který neměl být'
                    . ' hlášen". Řádný vztah bez práce se hlásí řádným nulovým formulářem.',
            );
        }
        $guids = self::canonicalGuids($formGuids);
        $requestHash = hash('sha256', CanonicalJson::encode([
            'schema_reference' => 'payroll-jmhz-zero-amendment-request.v1',
            'supplier_id' => $supplierId,
            'environment' => $environment,
            'regular_submission_id' => $regularSubmissionId,
            'situation' => $situation,
            'form_guids' => $guids,
        ]));
        $submissionKey = 'jmhz25-zero-amendment-submission:' . $requestHash;
        $artifactKey = 'jmhz25-zero-amendment-artifact:' . $requestHash;

        return $this->repository->transaction(function () use (
            $supplierId,
            $environment,
            $regularSubmissionId,
            $guids,
            $createdBy,
            $requestHash,
            $submissionKey,
            $artifactKey,
        ): array {
            if (!$this->repository->lockSupplier($supplierId)) {
                throw new \DomainException('Firma JMHZ podání nebyla nalezena.');
            }
            $xml = $this->frozen->bytes($supplierId, $environment, $regularSubmissionId);
            $identity = JmhzFrozenSubmissionIdentity::read($xml);
            $existing = $this->repository->findSubmissionByIdempotencyForUpdate(
                $supplierId,
                hash('sha256', $submissionKey, true),
                $environment,
            );
            if ($existing !== null) {
                return $this->replayed($supplierId, $environment, $existing, $artifactKey, $identity, $regularSubmissionId);
            }
            $original = $this->requireRoot($supplierId, $environment, $regularSubmissionId);
            if ($original['status'] !== 'accepted') {
                throw new \DomainException(
                    'Měsíc lze anulovat až po úplném přijetí řádného hlášení. U částečného'
                        . ' výsledku nejdříve načtěte úplný protokol ČSSZ.',
                );
            }
            $periodStart = sprintf('%04d-%02d-01', $identity->year, $identity->month);
            $touched = $this->touchedFormGuids($supplierId, $environment, $regularSubmissionId);
            $components = [];
            foreach ($this->frozen->components($supplierId, $environment, $regularSubmissionId) as $component) {
                $components[strtoupper($component['form_guid'])] = $component;
            }
            foreach ($guids as $guid) {
                $component = $components[$guid] ?? throw new JmhzXmlException(
                    'jmhz_cancellation_component_not_frozen',
                    'Vybraný pracovní vztah není mezi součástmi původního podání.',
                );
                $link = $this->employment($supplierId, $environment, $component['employment_external_identifier']);
                $bounds = $link === null
                    ? ['start' => null, 'end' => null]
                    : $this->employments->employmentBounds($supplierId, $link['employment_id']);
                $this->assertComponent($guid, $periodStart, $link, $bounds, $touched);
            }

            $envelope = $this->envelope($identity->submissionGuid);
            $amendment = $this->serializer->serialize($xml, $guids, $envelope);
            $schema = $this->validator->validateFrozen($amendment);
            $effective = $this->serializer->effectiveSet($xml, $guids);
            $this->validator->validateFrozen($effective);
            $report = $this->controls->validate(
                $effective,
                new JmhzControlContext($this->localDate(), null, true),
            );
            if (!$report->submittable()) {
                $findings = array_map(
                    static fn (JmhzControlFinding $finding): string
                        => "kontrola {$finding->controlId} — {$finding->message}",
                    [...$report->blocking(), ...$report->coverageGaps()],
                );
                throw new JmhzXmlException(
                    'jmhz_zero_amendment_controls_failed',
                    'Hlášení po anulaci neprošlo kontrolami katalogu: '
                        . ($findings === [] ? 'důvod neuveden' : implode('; ', $findings)),
                );
            }

            $obligation = $this->repository->findObligationOfSubmission(
                $supplierId,
                $environment,
                $regularSubmissionId,
            ) ?? throw new JmhzXmlException(
                'jmhz_submission_obligation_required',
                'K původnímu podání chybí evidovaná povinnost, takže se na ně nedá navázat oprava.',
            );
            $lastCorrectionOn = $this->deadlines->lastCorrectionOn((string) $obligation['period_start']);
            $rulesetId = 'jmhz-correction-10y-from-due-year-v2';
            $correctionObligation = $this->obligations->register(
                $supplierId,
                (string) $obligation['agenda_code'],
                (string) $obligation['subject_type'],
                (string) $obligation['subject_reference'],
                (string) $obligation['period_start'],
                (string) $obligation['period_end'],
                'correction',
                self::CHANNEL,
                'jmhz_submission_correction',
                "jmhz_submission:{$regularSubmissionId}",
                $requestHash,
                (string) $obligation['period_start'],
                $lastCorrectionOn,
                'calendar_days',
                $rulesetId,
                hash('sha256', $rulesetId),
                "jmhz25-zero-amendment-obligation:{$supplierId}:{$environment}:{$regularSubmissionId}:{$requestHash}",
                $createdBy,
                $createdBy,
                null,
                $environment,
            );
            $submission = $this->submissions->prepare(
                $supplierId,
                $correctionObligation['id'],
                'correction',
                self::CHANNEL,
                $requestHash,
                $submissionKey,
                null,
                $regularSubmissionId,
                $createdBy,
                $environment,
            );
            if (!$submission['created']) {
                return $this->replayed($supplierId, $environment, $submission, $artifactKey, $identity, $regularSubmissionId);
            }
            $part = $this->submissions->addPart(
                $supplierId,
                $submission['id'],
                $submission['row_version'],
                "jmhz25-zero-amendment:{$regularSubmissionId}:" . substr($requestHash, 0, 16),
                JmhzSubmissionBridgeService::AGENDA_CODE,
                (string) $obligation['subject_reference'],
                'jmhz_submission',
                "jmhz_submission:{$regularSubmissionId}",
                $requestHash,
            );
            $artifact = $this->submissions->storeArtifact(
                $supplierId,
                $submission['id'],
                $part['submission_row_version'],
                $part['id'],
                'outbound_xml',
                'outbound',
                'application/xml',
                $amendment,
                $schema['package_key'],
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
                'situation' => self::SITUATION,
                'corrects_submission_id' => $regularSubmissionId,
                'form_guids' => $guids,
                'submission_guid' => $identity->submissionGuid,
                'variable_symbol' => $identity->variableSymbol,
                'month' => $identity->month,
                'year' => $identity->year,
            ];
        });
    }

    /**
     * Vlastní předpoklady anulace nad rámec tvaru formuláře.
     *
     * @param array{id:int,employee_id:int,employment_id:int}|null $link
     * @param array{start:?string,end:?string} $bounds
     * @param array<string,list<array<string,mixed>>> $touched
     */
    private function assertComponent(
        string $guid,
        string $periodStart,
        ?array $link,
        array $bounds,
        array $touched,
    ): void {
        if (isset($touched[$guid])) {
            throw new JmhzXmlException(
                'jmhz_zero_amendment_form_already_amended',
                'K formuláři už existuje návazné opravné nebo stornovací podání. Anulace'
                    . ' se staví jen z formuláře, který od řádného podání nikdo neměnil.',
            );
        }
        if ($link === null) {
            throw new JmhzXmlException(
                'jmhz_zero_amendment_employment_unknown',
                'Formulář nejde přiřadit k pracovnímu vztahu v evidenci, takže nejde ověřit,'
                    . ' že vztah v anulovaném měsíci opravdu netrval.',
            );
        }
        $periodEnd = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
        $endedBefore = $bounds['end'] !== null && strcmp($bounds['end'], $periodStart) < 0;
        $startedAfter = $bounds['start'] !== null && strcmp($bounds['start'], $periodEnd) > 0;
        if (!$endedBefore && !$startedAfter) {
            throw new JmhzXmlException(
                'jmhz_zero_amendment_employment_active_in_month',
                'Podle evidence pracovní vztah v anulovaném měsíci trvá. Nejdříve opravte'
                    . ' datum skončení nebo nástupu (a podejte k tomu odhlášku nebo změnu'
                    . ' REGZEC); jinak by anulace zrušila existující pojistný vztah.',
            );
        }
    }

    /**
     * GUIDy formulářů, které už nějaké návazné podání k témuž řádnému
     * opravilo nebo stornovalo — u nich zmrazený původní formulář přestal
     * být tím, co u ČSSZ platí.
     *
     * @return array<string,list<array{submission_id:int,status:string,submission_kind:string}>>
     */
    private function touchedFormGuids(int $supplierId, string $environment, int $regularSubmissionId): array
    {
        $touched = [];
        foreach ($this->repository->jmhzChainForRoot($supplierId, $environment, $regularSubmissionId) as $row) {
            if ($row['id'] === $regularSubmissionId) {
                continue;
            }
            foreach ($this->frozen->formGuids($supplierId, $environment, $row['id']) as $guid) {
                $touched[strtoupper($guid)][] = [
                    'submission_id' => $row['id'],
                    'status' => $row['status'],
                    'submission_kind' => $row['submission_kind'],
                ];
            }
        }

        return $touched;
    }

    /** @return array{id:int,employee_id:int,employment_id:int}|null */
    private function employment(int $supplierId, string $environment, string $externalIdentifier): ?array
    {
        $hash = $this->employmentHash($supplierId, $externalIdentifier);

        return $hash === null
            ? null
            : $this->identities->employmentByExternalIdValueHash($supplierId, $environment, 'id_ppv', $hash);
    }

    private function employmentHash(int $supplierId, string $externalIdentifier): ?string
    {
        try {
            return $this->sensitiveData->lookupHash(
                $externalIdentifier,
                PayrollSensitiveField::EMPLOYMENT_EXTERNAL_IDENTIFIER,
                $supplierId,
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    private function requireRoot(int $supplierId, string $environment, int $submissionId): array
    {
        $original = $this->repository->findSubmission($supplierId, $submissionId);
        if ($original === null || $original['environment'] !== $environment) {
            throw new \DomainException('Původní podání nebylo nalezeno ve stejné firmě a prostředí.');
        }
        if ($original['submission_kind'] !== 'regular'
            || !in_array($original['channel'], self::ROOT_CHANNELS, true)
        ) {
            throw new JmhzXmlException(
                'jmhz_cancellation_target_invalid',
                'Anulovat lze jen formulář řádného měsíčního hlášení odeslaného přes VREP'
                    . ' nebo datovou schránku.',
            );
        }

        return $original;
    }

    private function cancellationAllowed(JmhzFrozenSubmissionIdentity $identity): bool
    {
        try {
            JmhzCancellationRequest::create(
                $identity->submissionGuid,
                $identity->variableSymbol,
                $identity->year,
                $identity->month,
                $this->deadlines,
                $this->localDate(),
            );
        } catch (JmhzXmlException) {
            return false;
        }

        return true;
    }

    private function envelope(string $submissionGuid): JmhzSubmissionEnvelope
    {
        return JmhzSubmissionEnvelope::createForExistingSubmission(
            $submissionGuid,
            [],
            PayrollSubmissionCalendar::filledAt($this->clock->now()),
            self::PRODUCT_NAME,
            EpoEnvelope::appVersion() ?? '0',
        );
    }

    private function localDate(): string
    {
        return PayrollSubmissionCalendar::today($this->clock->now());
    }

    /**
     * @param array<string,mixed> $submission
     * @return array<string,mixed>
     */
    private function replayed(
        int $supplierId,
        string $environment,
        array $submission,
        string $artifactKey,
        JmhzFrozenSubmissionIdentity $identity,
        int $regularSubmissionId,
    ): array {
        $artifact = $this->repository->findArtifactByIdempotencyForUpdate(
            $supplierId,
            hash('sha256', $artifactKey, true),
            $environment,
        );
        if ($artifact === null || $artifact['submission_id'] !== $submission['id'] || $artifact['part_id'] === null) {
            throw new JmhzXmlException(
                'jmhz_submission_replay_mismatch',
                'Zmrazený artefakt nulového opravného hlášení chybí nebo neodpovídá podání.',
            );
        }

        return [
            'submission_id' => $submission['id'],
            'part_id' => $artifact['part_id'],
            'artifact_id' => $artifact['id'],
            'artifact_sha256' => $artifact['artifact_sha256'],
            'status' => $submission['status'],
            'row_version' => $submission['row_version'],
            'environment' => $environment,
            'created' => false,
            'submission_kind' => $submission['submission_kind'],
            'situation' => self::SITUATION,
            'corrects_submission_id' => $regularSubmissionId,
            'form_guids' => [],
            'submission_guid' => $identity->submissionGuid,
            'variable_symbol' => $identity->variableSymbol,
            'month' => $identity->month,
            'year' => $identity->year,
        ];
    }

    /**
     * @param list<mixed> $formGuids
     * @return list<string>
     */
    private static function canonicalGuids(array $formGuids): array
    {
        if ($formGuids === []) {
            throw new JmhzXmlException(
                'jmhz_amendment_without_components',
                'Vyberte alespoň jeden pracovněprávní vztah, jehož měsíc se má anulovat.',
            );
        }
        $normalized = [];
        foreach ($formGuids as $formGuid) {
            if (!is_string($formGuid) || trim($formGuid) === '') {
                throw new JmhzXmlException(
                    'jmhz_cancellation_component_invalid',
                    'Vybraný formulář pracovního vztahu nemá platný identifikátor.',
                );
            }
            $guid = strtoupper(trim($formGuid));
            if (isset($normalized[$guid])) {
                throw new JmhzXmlException(
                    'jmhz_cancellation_component_duplicate',
                    'Tentýž pracovní vztah je k anulaci vybraný vícekrát.',
                );
            }
            $normalized[$guid] = true;
        }
        $guids = array_keys($normalized);
        sort($guids, SORT_STRING);

        return $guids;
    }
}
