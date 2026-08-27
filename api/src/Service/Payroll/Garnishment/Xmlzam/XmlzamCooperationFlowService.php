<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment\Xmlzam;

use MyInvoice\Repository\Payroll\XmlzamCooperationRepository;
use MyInvoice\Repository\Submission\SubmissionRecipientRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\Payroll\Garnishment\ClaimCategory;
use MyInvoice\Service\Payroll\Garnishment\DeductionClaim;
use MyInvoice\Service\Payroll\Garnishment\DeductionLegalBasis;
use MyInvoice\Service\Payroll\Garnishment\EnforcementPriorityResolver;
use MyInvoice\Service\Payroll\Net\PayrollNetResultQueryService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Service\Submission\SubmissionOutboxService;

final readonly class XmlzamCooperationFlowService
{
    public function __construct(
        private XmlzamCooperationRepository $repository,
        private DocumentStorage $storage,
        private XmlzamCooperationRequestParser $parser,
        private XmlzamCooperationResponseSerializer $serializer,
        private XmlzamValidator $validator,
        private PayrollSensitiveData $sensitiveData,
        private SecretEncryption $encryption,
        private PayrollNetResultQueryService $netResults,
        private EnforcementPriorityResolver $priorities,
        private SubmissionRecipientRepository $recipients,
        private SubmissionOutboxService $outbox,
    ) {}

    /** @return array{id:int,employee_id:int,created:bool,request_identifier:string} */
    public function import(
        int $supplierId,
        string $environment,
        int $inboxMessageId,
        int $documentFileId,
        int $userId,
    ): array {
        self::ids($supplierId, $inboxMessageId, $documentFileId, $userId);
        self::environment($environment);
        $source = $this->repository->sourceAttachment(
            $supplierId,
            $environment,
            $inboxMessageId,
            $documentFileId,
        );
        if ($source === null
            || $source['hidden_at'] !== null
            || ($source['local_content_state'] ?? 'available') !== 'available'
            || $source['document_deleted_at'] !== null
            || $source['file_deleted_at'] !== null
            || (string) $source['source'] !== 'zfo_extract'
            || (int) $source['parent_document_id'] !== (int) $source['container_document_id']
        ) {
            throw new \DomainException('XMLZAM lze importovat jen z aktivní přílohy konkrétní příchozí zprávy ISDS.');
        }
        if (!str_contains(strtolower((string) $source['mime_type']), 'xml')) {
            throw new \DomainException('Vybraná příloha XMLZAM nemá XML MIME typ.');
        }
        $path = $this->storage->pathFor($supplierId, (string) $source['sha256'], (string) $source['filename']);
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($bytes) || $bytes === '') {
            throw new \DomainException('Obsah vybrané přílohy XMLZAM není dostupný.');
        }
        $sha = hash('sha256', $bytes);
        if (!hash_equals(strtolower((string) $source['sha256']), $sha)) {
            throw new \DomainException('Otisk vybrané přílohy XMLZAM nesouhlasí s DMS.');
        }
        $request = $this->parser->parse($bytes);
        if (!hash_equals(strtolower((string) $source['sender_box_id']), strtolower($request->executorDataBoxId))) {
            throw new \DomainException('ID datové schránky odesílatele nesouhlasí s exekutorem v XMLZAM.');
        }
        $employeeId = $this->matchEmployee($supplierId, $request);
        $payload = [
            'schema_reference' => 'payroll-xmlzam-cooperation-request.v1',
            'employee_id' => $employeeId,
            'inbox_message_id' => $inboxMessageId,
            'source_document_id' => (int) $source['source_document_id'],
            'source_document_file_id' => $documentFileId,
            'source_xml_base64' => base64_encode($bytes),
            'request' => [
                'identifier' => $request->identifier,
                'case_reference' => $request->caseReference,
                'issued_on' => $request->issuedOn,
                'requested_scopes' => $request->requestedScopes,
                'executor_box_id' => $request->executorDataBoxId,
            ],
        ];
        $canonical = CanonicalJson::encode($payload);
        $fingerprint = $this->sensitiveData->keyedFingerprint(
            $canonical,
            'xmlzam-cooperation-request',
            $supplierId,
        );
        $context = "payroll:xmlzam:request:{$supplierId}:{$environment}:{$sha}:{$fingerprint}";
        $stored = $this->repository->insertRequest([
            'supplier_id' => $supplierId,
            'environment' => $environment,
            'employee_id' => $employeeId,
            'inbox_message_id' => $inboxMessageId,
            'source_document_id' => (int) $source['source_document_id'],
            'source_document_file_id' => $documentFileId,
            'request_identifier' => $request->identifier,
            'issued_on' => $request->issuedOn,
            'executor_box_id' => strtolower($request->executorDataBoxId),
            'source_xml_sha256' => $sha,
            'snapshot_ciphertext' => $this->encryption->encryptFor($canonical, $context),
            'snapshot_fingerprint' => $fingerprint,
            'imported_by' => $userId,
        ]);
        return [
            'id' => (int) $stored['row']['id'],
            'employee_id' => $employeeId,
            'created' => $stored['created'],
            'request_identifier' => $request->identifier,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function candidates(int $supplierId, string $environment): array
    {
        self::ids($supplierId);
        self::environment($environment);
        return $this->repository->pendingSourceCandidates($supplierId, $environment);
    }

    /** @return array<string,mixed> */
    public function detail(int $supplierId, string $environment, int $requestId): array
    {
        self::ids($supplierId, $requestId);
        self::environment($environment);
        $verified = $this->verifiedRequest($supplierId, $environment, $requestId);
        $row = $verified['row'];
        $employee = $this->repository->employeeSummary($supplierId, (int) $row['employee_id']);
        if ($employee === null) {
            throw new \DomainException('Zaměstnanec importovaného požadavku XMLZAM nebyl nalezen.');
        }
        $recipientMatches = $this->recipients->findActiveVisibleByExactBoxId(
            $supplierId,
            (string) $row['executor_box_id'],
        );
        $recipient = null;
        $recipientStatus = 'missing';
        if (count($recipientMatches) === 1) {
            $match = $recipientMatches[0];
            $recipient = [
                'id' => (int) $match['id'],
                'code' => (string) $match['code'],
                'name' => (string) $match['name'],
                'kind' => (string) $match['kind'],
                'isds_box_id' => (string) $match['isds_box_id'],
            ];
            $recipientStatus = 'matched';
        } elseif (count($recipientMatches) > 1) {
            $recipientStatus = 'ambiguous';
        }

        return [
            'id' => (int) $row['id'],
            'environment' => (string) $row['environment'],
            'request_identifier' => (string) $row['request_identifier'],
            'case_reference' => $verified['case_reference'],
            'issued_on' => (string) $row['issued_on'],
            'requested_scopes' => $verified['scopes'],
            'executor_box_id' => (string) $row['executor_box_id'],
            'employee' => $employee,
            'source' => [
                'inbox_message_id' => (int) $row['inbox_message_id'],
                'document_id' => (int) $row['source_document_id'],
                'document_file_id' => (int) $row['source_document_file_id'],
                'sha256' => (string) $row['source_xml_sha256'],
            ],
            'recipient_match_status' => $recipientStatus,
            'recipient' => $recipient,
            'imported_at' => (string) $row['imported_at'],
        ];
    }

    /**
     * @param list<string> $periods
     * @return array<string,mixed>
     */
    public function preview(int $supplierId, string $environment, int $requestId, int $caseId, array $periods): array
    {
        self::ids($supplierId, $requestId, $caseId);
        self::environment($environment);
        $verified = $this->verifiedRequest($supplierId, $environment, $requestId);
        return $this->buildPreview(
            $supplierId,
            $requestId,
            $caseId,
            $periods,
            $verified['row'],
            $verified['scopes'],
        );
    }

    /**
     * @param list<string> $periods
     * @param array<string,mixed> $request
     * @param list<string> $scopes
     * @return array<string,mixed>
     */
    private function buildPreview(
        int $supplierId,
        int $requestId,
        int $caseId,
        array $periods,
        array $request,
        array $scopes,
    ): array {
        $employeeId = (int) $request['employee_id'];
        if ($this->repository->caseForEmployee($supplierId, $caseId, $employeeId) === null) {
            throw new \DomainException('Exekuční případ nepatří zaměstnanci z požadavku XMLZAM.');
        }
        $includesWages = in_array('vyse_srazek', $scopes, true);
        $includesEmployment = in_array('trvani_praconiho_pomeru', $scopes, true);
        $includesPriority = in_array('poradi_exekuce', $scopes, true);
        $normalizedPeriods = $includesWages ? self::periods($periods) : [];
        $wages = [];
        $sources = [];
        foreach ($normalizedPeriods as $period) {
            $periodStart = $period . '-01';
            $revision = $this->repository->approvedRevisionForPeriod($supplierId, $employeeId, $periodStart);
            if ($revision === null) {
                throw new \DomainException("Období {$period} nemá schválenou neměnnou mzdovou revizi.");
            }
            $breakdown = $this->netResults->breakdown($supplierId, (int) $revision['id'], $employeeId);
            $evidence = self::enforcementEvidence((string) ($revision['enforcement_input_json'] ?? ''));
            $wages[] = [
                'period' => $period,
                'gross_minor' => (int) $breakdown['income']['gross_minor'],
                'withheld_minor' => (int) $breakdown['enforcement_withheld_minor'],
                'dependants' => $evidence,
            ];
            $sources[] = [
                'period' => $period,
                'revision_id' => (int) $revision['id'],
                'revision_no' => (int) $revision['revision_no'],
                'input_hash' => (string) $revision['input_snapshot_hash'],
                'result_hash' => (string) $revision['result_snapshot_hash'],
                'enforcement_input_hash' => (string) $revision['enforcement_input_hash'],
            ];
        }
        $priority = null;
        $shared = null;
        if ($includesPriority) {
            [$priority, $shared] = $this->priority($supplierId, $employeeId, $caseId);
        }
        $employment = $includesEmployment
            ? $this->repository->employmentSummary($supplierId, $employeeId)
            : null;
        $today = gmdate('Y-m-d');
        $seed = hash('sha256', CanonicalJson::encode([$requestId, $caseId, $normalizedPeriods, $sources]));
        $response = new XmlzamCooperationResponse(
            identifier: '999-' . str_replace('-', '', $today) . '-' . strtoupper(substr($seed, 0, 5)),
            reactionTo: (string) $request['request_identifier'],
            issuedOn: $today,
            note: null,
            debtorContact: null,
            employerContact: null,
            priority: $priority,
            sharedPriority: $shared,
            employmentActive: $employment['active'] ?? null,
            employedFrom: $employment['start'] ?? null,
            employedTo: $employment['end'] ?? null,
            wages: $includesWages ? $wages : null,
            enforcements: $includesPriority ? [] : null,
            attachments: [],
        );
        $xml = $this->serializer->serialize($response);
        $this->validator->validateResponse($response, $xml);
        $xmlSha256 = hash('sha256', $xml);
        $preview = [
            'request_id' => $requestId,
            'case_id' => $caseId,
            'response_identifier' => $response->identifier,
            'includes_wages' => $includesWages,
            'source_manifest' => $sources,
            'xml' => $xml,
            'xml_sha256' => $xmlSha256,
        ];
        if ($includesPriority) {
            $preview['priority'] = $priority;
            $preview['shared_priority'] = $shared;
        }
        if ($includesEmployment) {
            $preview['employment'] = $employment;
        }
        if ($includesWages) {
            $preview['wages'] = $wages;
        }
        return $preview;
    }

    /**
     * @param list<string> $periods
     * @return array{id:int,created:bool,xml_sha256:string}
     */
    public function freeze(
        int $supplierId,
        string $environment,
        int $requestId,
        int $caseId,
        array $periods,
        string $idempotencyKey,
        int $userId,
    ): array {
        self::ids($supplierId, $requestId, $caseId, $userId);
        self::environment($environment);
        if (trim($idempotencyKey) === '' || strlen($idempotencyKey) > 191) {
            throw new \InvalidArgumentException('Idempotency key XMLZAM není platný.');
        }
        $verified = $this->verifiedRequest($supplierId, $environment, $requestId);
        $normalizedPeriods = in_array('vyse_srazek', $verified['scopes'], true)
            ? self::periods($periods)
            : [];
        $idempotencyHash = hash(
            'sha256',
            "xmlzam-response\0{$supplierId}\0{$environment}\0{$idempotencyKey}",
            true,
        );
        $existing = $this->repository->findResponseByIdempotency(
            $supplierId,
            $environment,
            $idempotencyHash,
        );
        if ($existing !== null) {
            $manifest = json_decode((string) $existing['source_manifest_json'], true, flags: JSON_THROW_ON_ERROR);
            $existingPeriods = [];
            foreach (is_array($manifest) ? $manifest : [] as $source) {
                if (is_array($source) && is_string($source['period'] ?? null)) {
                    $existingPeriods[] = $source['period'];
                }
            }
            sort($existingPeriods, SORT_STRING);
            if ((int) $existing['request_id'] !== $requestId
                || (int) $existing['case_id'] !== $caseId
                || $existingPeriods !== $normalizedPeriods
            ) {
                throw new \DomainException('XMLZAM idempotency key koliduje s jinou odpovědí.');
            }
            return [
                'id' => (int) $existing['id'],
                'created' => false,
                'xml_sha256' => (string) $existing['xml_sha256'],
            ];
        }
        $preview = $this->buildPreview(
            $supplierId,
            $requestId,
            $caseId,
            $normalizedPeriods,
            $verified['row'],
            $verified['scopes'],
        );
        $manifestJson = CanonicalJson::encode($preview['source_manifest']);
        $snapshot = $preview;
        unset($snapshot['xml']);
        $snapshotJson = CanonicalJson::encode($snapshot);
        $fingerprint = $this->sensitiveData->keyedFingerprint(
            $snapshotJson,
            'xmlzam-cooperation-response',
            $supplierId,
        );
        $snapshotContext = "payroll:xmlzam:response-snapshot:{$supplierId}:{$environment}:{$requestId}:{$fingerprint}";
        $xmlContext = XmlzamCooperationArtifactStore::responseXmlContext(
            $supplierId,
            $environment,
            $requestId,
            $fingerprint,
        );
        $stored = $this->repository->insertResponse([
            'supplier_id' => $supplierId,
            'environment' => $environment,
            'request_id' => $requestId,
            'case_id' => $caseId,
            'response_identifier' => $preview['response_identifier'],
            'includes_wages' => $preview['includes_wages'],
            'source_manifest_json' => $manifestJson,
            'source_manifest_sha256' => hash('sha256', $manifestJson),
            'snapshot_ciphertext' => $this->encryption->encryptFor($snapshotJson, $snapshotContext),
            'snapshot_fingerprint' => $fingerprint,
            'xml_ciphertext' => $this->encryption->encryptFor((string) $preview['xml'], $xmlContext),
            'xml_sha256' => $preview['xml_sha256'],
            'idempotency_key_hash' => $idempotencyHash,
            'approved_by' => $userId,
        ]);
        return [
            'id' => (int) $stored['row']['id'],
            'created' => $stored['created'],
            'xml_sha256' => (string) $stored['row']['xml_sha256'],
        ];
    }

    /** @return array{outbox_id:int,created:bool,dispatch_id:int} */
    public function enqueue(
        int $supplierId,
        string $environment,
        int $responseId,
        int $recipientId,
        int $userId,
    ): array {
        self::ids($supplierId, $responseId, $recipientId, $userId);
        self::environment($environment);
        $response = $this->repository->findResponse($supplierId, $environment, $responseId);
        if ($response === null) {
            throw new \OutOfBoundsException('Odpověď XMLZAM nebyla nalezena.');
        }
        $recipient = $this->recipients->findVisible($supplierId, $recipientId);
        if ($recipient === null
            || !$recipient['is_active']
            || $recipient['isds_box_id'] === null
            || !hash_equals(strtolower((string) $response['executor_box_id']), strtolower((string) $recipient['isds_box_id']))
        ) {
            throw new \DomainException('Vybraný příjemce není aktivní exekutor z importovaného požadavku XMLZAM.');
        }
        $queued = $this->outbox->enqueue(
            $supplierId,
            $environment,
            'isds',
            'XMLZAM',
            'payroll_xmlzam',
            $responseId,
            $recipientId,
            'XMLZAM — odpověď na součinnost',
            $userId,
        );
        $outboxId = (int) $queued['row']['id'];
        $dispatchId = $this->repository->recordDispatch(
            $supplierId,
            $environment,
            $responseId,
            $outboxId,
            $userId,
        );
        return ['outbox_id' => $outboxId, 'created' => $queued['created'], 'dispatch_id' => $dispatchId];
    }

    /**
     * @return array{row:array<string,mixed>,scopes:list<string>,case_reference:string}
     */
    private function verifiedRequest(int $supplierId, string $environment, int $requestId): array
    {
        $row = $this->repository->findRequest($supplierId, $environment, $requestId);
        if ($row === null) {
            throw new \OutOfBoundsException('Požadavek XMLZAM nebyl nalezen.');
        }
        $sha = strtolower((string) $row['source_xml_sha256']);
        $fingerprint = strtolower((string) $row['snapshot_fingerprint']);
        $context = "payroll:xmlzam:request:{$supplierId}:{$environment}:{$sha}:{$fingerprint}";
        try {
            $canonical = $this->encryption->decryptFor((string) $row['snapshot_ciphertext'], $context);
            $calculatedFingerprint = $this->sensitiveData->keyedFingerprint(
                $canonical,
                'xmlzam-cooperation-request',
                $supplierId,
            );
            if (!hash_equals($fingerprint, $calculatedFingerprint)) {
                throw new \UnexpectedValueException('Fingerprint mismatch.');
            }
            $payload = json_decode($canonical, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($payload)
                || ($payload['schema_reference'] ?? null) !== 'payroll-xmlzam-cooperation-request.v1'
                || ($payload['employee_id'] ?? null) !== (int) $row['employee_id']
                || ($payload['inbox_message_id'] ?? null) !== (int) $row['inbox_message_id']
                || ($payload['source_document_id'] ?? null) !== (int) $row['source_document_id']
                || ($payload['source_document_file_id'] ?? null) !== (int) $row['source_document_file_id']
                || !is_string($payload['source_xml_base64'] ?? null)
                || !is_array($payload['request'] ?? null)
            ) {
                throw new \UnexpectedValueException('Snapshot binding mismatch.');
            }
            $sourceXml = base64_decode($payload['source_xml_base64'], true);
            if (!is_string($sourceXml) || !hash_equals($sha, hash('sha256', $sourceXml))) {
                throw new \UnexpectedValueException('Source hash mismatch.');
            }
            $parsed = $this->parser->parse($sourceXml);
            $request = $payload['request'];
            $requestIdentifier = $request['identifier'] ?? null;
            $caseReference = $request['case_reference'] ?? null;
            $issuedOn = $request['issued_on'] ?? null;
            $executorBoxId = $request['executor_box_id'] ?? null;
            $storedScopes = $request['requested_scopes'] ?? null;
            if (!is_array($storedScopes) || !array_is_list($storedScopes)) {
                throw new \UnexpectedValueException('Scope snapshot is invalid.');
            }
            $scopes = [];
            foreach ($storedScopes as $scope) {
                if (!is_string($scope) || $scope === '') {
                    throw new \UnexpectedValueException('Scope snapshot is invalid.');
                }
                $scopes[] = $scope;
            }
            if ($scopes !== $parsed->requestedScopes
                || $requestIdentifier !== $parsed->identifier
                || $caseReference !== $parsed->caseReference
                || $issuedOn !== $parsed->issuedOn
                || strtolower(is_string($executorBoxId) ? $executorBoxId : '') !== strtolower($parsed->executorDataBoxId)
                || $requestIdentifier !== (string) $row['request_identifier']
                || $issuedOn !== (string) $row['issued_on']
                || strtolower(is_string($executorBoxId) ? $executorBoxId : '') !== strtolower((string) $row['executor_box_id'])
            ) {
                throw new \UnexpectedValueException('Request snapshot mismatch.');
            }
        } catch (\Throwable $e) {
            throw new \DomainException('Zašifrovaný snapshot požadavku XMLZAM nelze bezpečně ověřit.', previous: $e);
        }

        $unsupported = array_values(array_diff($scopes, [
            'vyse_srazek',
            'trvani_praconiho_pomeru',
            'poradi_exekuce',
        ]));
        if ($unsupported !== []) {
            throw new \DomainException(
                'Požadavek XMLZAM obsahuje nepodporovaný rozsah součinnosti: ' . implode(', ', $unsupported) . '.',
            );
        }

        return ['row' => $row, 'scopes' => $scopes, 'case_reference' => $parsed->caseReference];
    }

    private function matchEmployee(int $supplierId, XmlzamCooperationRequest $request): int
    {
        $candidates = $this->repository->identityCandidates(
            $supplierId,
            $request->debtorGivenName,
            $request->debtorFamilyName,
            $request->debtorBirthDate,
            $request->issuedOn,
        );
        $expectedHash = $this->sensitiveData->lookupHash(
            $request->debtorBirthNumber,
            PayrollSensitiveField::PERSONAL_IDENTIFIER,
            $supplierId,
        );
        $matches = array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => $candidate['identifier_hash'] !== null
                && hash_equals($expectedHash, $candidate['identifier_hash']),
        ));
        if (count($matches) > 1 || ($matches === [] && count($candidates) > 1)) {
            throw new \DomainException('Identita povinného je nejednoznačná; požadavek XMLZAM vyžaduje ruční dořešení.');
        }
        if (count($matches) !== 1) {
            throw new \DomainException('Povinného nelze jednoznačně ověřit podle jména, narození a rodného čísla.');
        }
        return $matches[0]['employee_id'];
    }

    /** @return array{0:int,1:bool} */
    private function priority(int $supplierId, int $employeeId, int $caseId): array
    {
        $rows = $this->repository->activeClaims($supplierId, $employeeId);
        $caseOrderKeys = [];
        $claims = [];
        $caseByClaim = [];
        foreach ($rows as $row) {
            $key = (string) $row['claim_key'];
            $orderKey = trim((string) ($row['enforcement_order_key'] ?? ''));
            if ((int) $row['case_id'] === $caseId) {
                $caseOrderKeys[$orderKey !== '' ? $orderKey : "claim:{$key}"] = true;
            }
            $claims[] = new DeductionClaim(
                $key,
                DeductionLegalBasis::from((string) $row['legal_basis']),
                ClaimCategory::from((string) $row['category']),
                (int) $row['outstanding_minor_units'],
                $row['priority_date'] === null ? null : (string) $row['priority_date'],
                (bool) $row['legal_title_verified'],
                (bool) $row['order_or_notice_delivered'],
                $row['order_issued_on'] === null ? null : (string) $row['order_issued_on'],
                (bool) $row['priority_classification_verified'],
                (bool) $row['agreement_verified'],
                $row['maintenance_weight_minor_units'] === null ? null : (int) $row['maintenance_weight_minor_units'],
                (bool) $row['due_monetary_claim_verified'],
                true,
                $orderKey !== '' ? $orderKey : null,
            );
            $caseByClaim[$key] = (int) $row['case_id'];
        }
        if (count($caseOrderKeys) !== 1) {
            throw new \DomainException('Exekuční případ nemá právě jeden aktivní exekuční příkaz pro určení pořadí.');
        }
        foreach ($this->priorities->resolve($claims) as $index => $group) {
            $containsCase = false;
            $orders = [];
            foreach ($group as $claim) {
                $containsCase = $containsCase || ($caseByClaim[$claim->id] ?? 0) === $caseId;
                $orders[$claim->enforcementOrderId ?? "claim:{$claim->id}"] = true;
            }
            if ($containsCase) {
                return [$index + 1, count($orders) > 1];
            }
        }
        throw new \DomainException('Exekuční případ není v aktivním pořadí srážek.');
    }

    private static function enforcementEvidence(string $json): int
    {
        if ($json === '') {
            throw new \DomainException('Schválená revize nemá zmrazený exekuční podklad.');
        }
        $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $value = $payload['evidence']['eligible_dependants'] ?? $payload['eligible_dependants'] ?? null;
        if (!is_int($value) || $value < 0) {
            throw new \DomainException('Schválený exekuční podklad neobsahuje ověřený počet vyživovaných osob.');
        }
        return $value;
    }

    /**
     * @param list<string> $periods
     * @return list<string>
     */
    private static function periods(array $periods): array
    {
        $result = array_values(array_unique(array_map('trim', $periods)));
        sort($result, SORT_STRING);
        if ($result === [] || count($result) > 24) {
            throw new \InvalidArgumentException('XMLZAM vyžaduje 1 až 24 mzdových období.');
        }
        foreach ($result as $period) {
            if (preg_match('/^[12]\d{3}-(0[1-9]|1[0-2])$/D', $period) !== 1) {
                throw new \InvalidArgumentException('Mzdové období XMLZAM není platné.');
            }
        }
        return $result;
    }

    private static function ids(int ...$ids): void
    {
        foreach ($ids as $id) {
            if ($id <= 0) {
                throw new \InvalidArgumentException('Identifikátory XMLZAM musí být kladné.');
            }
        }
    }

    private static function environment(string $environment): void
    {
        if (!in_array($environment, ['production', 'test'], true)) {
            throw new \InvalidArgumentException('Prostředí XMLZAM není platné.');
        }
    }
}
