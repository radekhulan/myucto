<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

use MyInvoice\Repository\Payroll\PayrollHealthNotificationRepository;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Repository\Submission\SubmissionRecipientRepository;
use MyInvoice\Service\Pdf\PayrollHealthPaymentOverviewPdfRenderer;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\InstitutionAccountType;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use Psr\Clock\ClockInterface;

/**
 * Most mezi zdravotní agendou a platformou podání MZ-19.
 *
 * Čtyři rozhodnutí, na kterých vrstva stojí:
 *
 * 1. **Povinnost vzniká i tam, kde podání vzniknout nemůže.** Lhůta osmi dnů
 *    běží bez ohledu na to, jestli aplikace umí soubor odeslat. Obligace se
 *    proto zakládá vždy a inbox povinností o ní ví — druhá věc je, kdo ji
 *    splní a jak.
 * 2. **Chybějící XSD nezahazuje artefakt, zastaví ho před stavem `validated`.**
 *    Podání zůstane v `draft` s blokující výhradou ve fázi `xsd`. Účetní tak
 *    vidí soubor i důvod, proč není připravený k odeslání; kdyby se prepare
 *    celý zhroutil, nezůstalo by po něm nic a povinnost by se tvářila jako
 *    nezpracovaná.
 * 3. **Kanál se nehádá.** Portálové API bez veřejně popsané transportní
 *    obálky se nevolá. Doložený formát přílohy PPZ lze u pojišťoven s
 *    ověřeným příjemcem připravit do obecné ISDS fronty; odeslání vždy
 *    potvrzuje uživatel a firma může výchozího příjemce překrýt.
 * 4. **Stav `ready` znamená „lze odeslat", ne „odesláno".** Přechod dál patří
 *    potvrzenému ISDS transportu; samotné zařazení do fronty stav nemění.
 */
final readonly class HealthInsuranceSubmissionService
{
    public const AGENDA_BULK_NOTIFICATION = HealthInsuranceSchemaCatalog::HOZ;
    public const AGENDA_PAYMENT_OVERVIEW = HealthInsuranceSchemaCatalog::PPZ;

    public const SOURCE_EVENT_NOTIFICATION = 'payroll_health_notification';
    public const SOURCE_EVENT_OVERVIEW = 'payroll_health_payment_overview';

    private const CHANNEL = 'health_portal';
    private const SUBJECT_EMPLOYMENT = 'employment';
    private const SUBJECT_RUN = 'payroll_run';

    /** Strop stránky je tvrdý — z URL ho zvednout nejde. */
    public const PERIOD_MAX_LIMIT = 200;
    public const PERIOD_DEFAULT_LIMIT = 50;

    public function __construct(
        private PayrollHealthNotificationRepository $facts,
        private PayrollInstitutionAccountRepository $institutionAccounts,
        private HealthNotificationDutyResolver $resolver,
        private HealthNotificationDutyCatalog $duties,
        private HealthNotificationCodeCatalog $codes,
        private HealthNotificationDeadlinePolicy $deadlines,
        private HealthInsuranceSchemaCatalog $schemas,
        private HealthInsurerChannelCatalog $channels,
        private HealthInsuranceXmlSerializer $serializer,
        private HealthInsuranceXmlValidator $validator,
        private PayrollHealthPaymentOverviewPdfRenderer $pdfRenderer,
        private ClockInterface $clock,
        private HealthPaymentOverviewService $overviews,
        private PayrollObligationService $obligations,
        private PayrollSubmissionService $submissions,
        private PayrollSubmissionRepository $submissionRepository,
        private SubmissionRecipientRepository $recipients,
    ) {}

    /**
     * Co aplikace u zdravotních pojišťoven umí a co ne, i s důvody. Slouží
     * obrazovce, aby nemusela odvozovat schopnosti z chybových hlášek.
     *
     * @return array<string,mixed>
     */
    public function capability(int $supplierId): array
    {
        $documents = [];
        foreach ($this->schemas->documentTypes() as $documentType) {
            $manifest = $this->schemas->manifestFor($documentType);
            $documents[$documentType] = [
                'xsd_version' => $manifest['xsd_version'],
                'namespace' => $manifest['namespace'],
                'root' => $manifest['root'],
                'schema_pinned' => $manifest['available'],
                'schema_sha256' => $manifest['sha256'],
                'schema_url' => $manifest['url'],
            ];
        }
        $channels = [];
        foreach ($this->channels->channels() as $code => $channel) {
            $channels[$code] = $this->channelDescription(
                $supplierId,
                (string) $code,
            );
        }

        return [
            'schema_reference' => 'payroll-health-submission-capability.v1',
            'shared_data_message_since' => '2026-01-01',
            'documents' => $documents,
            'channels' => $channels,
            'automated_dispatch' => [
                'supported' => false,
                'reason_code' =>
                    HealthInsurerChannelCatalog::REASON_TRANSPORT_UNDOCUMENTED,
            ],
            'isds_dispatch' => [
                'supported' => true,
                'requires_user_confirmation' => true,
                'automatic_inbox' => false,
            ],
            'change_codes' => [
                'total' => count($this->codes->codes()),
                'narrowing_effective_from' =>
                    HealthNotificationDutyCatalog::NARROWING_EFFECTIVE_FROM,
                // Mapování je doložené anotací připnutého XSD, ale jen tam,
                // kde schéma určuje jediný kód; zbytek zůstává fail-closed.
                'mapping_from_duty_documented' => array_values(array_filter(
                    array_map(
                        fn (HealthNotificationDutyKind $kind): ?string =>
                            $this->codes->isCodeMappingDocumented($kind)
                                ? $kind->value
                                : null,
                        HealthNotificationDutyKind::cases(),
                    ),
                )),
            ],
            'duties' => array_map(
                static fn (HealthNotificationDutyRule $rule): array =>
                    $rule->toArray(),
                $this->duties->rules(),
            ),
            'verification_reference' =>
                HealthNotificationDutyCatalog::VERIFICATION_REFERENCE,
        ];
    }

    /**
     * Povinnosti plynoucí z jednoho pracovního vztahu ke dni skutečnosti.
     *
     * @return list<array<string,mixed>>
     */
    public function duties(
        int $supplierId,
        int $employmentId,
        string $onDate,
    ): array {
        $duties = $this->resolver->resolve(
            $this->requireFacts($supplierId, $employmentId, $onDate),
        );

        return array_map(
            static fn (HealthNotificationDuty $duty): array => $duty->toArray(),
            $duties,
        );
    }

    /**
     * Přehled oznamovacích povinností za mzdové období.
     *
     * Filtruje i stránkuje SERVER. Filtrovat na klientovi a stránkovat na
     * serveru by znamenalo, že `total` popisuje jiný seznam, než uživatel
     * vidí — a že se filtr uplatní jen na právě načtenou stránku.
     *
     * `total` proto popisuje FILTROVANÝ seznam (ten se stránkuje), zatímco
     * `summary` popisuje CELÉ OBDOBÍ bez ohledu na filtr. Nejsou to dvě
     * odpovědi na tutéž otázku: kdyby souhrn respektoval filtr, dal by se
     * zúžením filtru schovat propadlý termín. UI to musí popsat, ne smíchat.
     *
     * @param array{
     *   insurer_code?:?string,kind?:?string,reported?:?bool,
     *   undocumented_code_only?:?bool
     * } $filters
     * @return array{
     *   period:string,items:list<array<string,mixed>>,total:int,
     *   limit:int,offset:int,summary:array<string,int>,
     *   unresolved_employments:list<array{employment_id:int,full_name:string,reason_code:string,reason:string}>
     * }
     */
    public function dutiesForPeriod(
        int $supplierId,
        string $environment,
        string $period,
        array $filters = [],
        int $limit = self::PERIOD_DEFAULT_LIMIT,
        int $offset = 0,
    ): array {
        $set = $this->periodDutySet($supplierId, $period);
        $limit = max(1, min(self::PERIOD_MAX_LIMIT, $limit));
        $offset = max(0, $offset);
        $all = [];
        $dutiesById = [];
        $registrationsById = [];
        foreach ($set['duties'] as $entry) {
            $duty = $entry['duty'];
            $all[] = $this->periodItem(
                $supplierId,
                $duty,
                $entry['full_name'],
            );
            if ($duty->reportedByEmployer && $duty->deadline !== null) {
                $dutyId = $duty->sourceEventReference();
                $dutiesById[$dutyId] = $duty;
                $registrationsById[$dutyId] = $this->registrationRowsForDuty(
                    $supplierId,
                    $environment,
                    $duty,
                    null,
                );
            }
        }

        $obligationStates = $this->submissionRepository
            ->obligationStatesBySourceReferences(
                $supplierId,
                $environment,
                self::AGENDA_BULK_NOTIFICATION,
                self::SOURCE_EVENT_NOTIFICATION,
                array_column($all, 'id'),
            );
        foreach ($all as &$item) {
            $state = $obligationStates[$item['id']] ?? null;
            $item['obligation_id'] = $state !== null
                && isset(
                    $dutiesById[$item['id']],
                    $registrationsById[$item['id']],
                )
                && $this->storedStateMatches(
                    $state,
                    $registrationsById[$item['id']],
                    $dutiesById[$item['id']],
                )
                    ? $state['id']
                    : null;
        }
        unset($item);

        // Souhrn se počítá nad CELÝM obdobím, ne nad filtrem ani nad stránkou —
        // stejně jako u inboxu podání. „Kolik je po lhůtě" nesmí záviset na
        // tom, jaký filtr má účetní zrovna zapnutý; kdyby závisel, dal by se
        // zúžením filtru schovat propadlý termín. `total` v odpovědi naopak
        // popisuje filtrovaný seznam, protože ten stránkuje.
        $summary = [
            'total' => count($all),
            'reported_by_employer' => 0,
            'reported_by_insured' => 0,
            'code_documented' => 0,
            'code_undocumented' => 0,
            'overdue' => 0,
        ];
        $today = $this->today();
        foreach ($all as $item) {
            $summary[$item['reported_by_employer']
                ? 'reported_by_employer'
                : 'reported_by_insured']++;
            $summary[$item['change_code']['documented']
                ? 'code_documented'
                : 'code_undocumented']++;
            $dueOn = $item['deadline']['due_on'] ?? null;
            if (is_string($dueOn) && $dueOn < $today) {
                $summary['overdue']++;
            }
        }

        $filtered = array_values(array_filter(
            $all,
            fn (array $item): bool => $this->matchesFilters($item, $filters),
        ));
        usort(
            $filtered,
            static fn (array $a, array $b): int =>
                [$a['occurred_on'], $a['full_name'], $a['kind']]
                <=> [$b['occurred_on'], $b['full_name'], $b['kind']],
        );

        return [
            'period' => $period,
            'environment' => $environment,
            'items' => array_slice($filtered, $offset, $limit),
            'total' => count($filtered),
            'limit' => $limit,
            'offset' => $offset,
            'summary' => $summary,
            'unresolved_employments' => $set['unresolved'],
        ];
    }

    /**
     * Zaeviduje povinnosti, které na zaměstnavatele skutečně dopadají.
     * Povinnost, kterou od 1. 1. 2026 hlásí sám pojištěnec, se do registru
     * NEZAKLÁDÁ — připomínat termín, který zaměstnavatel nemá, je šum.
     *
     * @return list<array<string,mixed>>
     */
    public function registerObligations(
        int $supplierId,
        string $environment,
        int $employmentId,
        string $onDate,
        ?int $createdBy = null,
    ): array {
        return $this->submissionRepository->transaction(function () use (
            $supplierId,
            $environment,
            $employmentId,
            $onDate,
            $createdBy,
        ): array {
            if (!$this->submissionRepository->lockSupplier($supplierId)) {
                throw new HealthNotificationException(
                    'zp_supplier_not_found',
                    'Firma pro evidenci povinností nebyla nalezena.',
                );
            }
            $duties = $this->resolver->resolve(
                $this->requireFacts($supplierId, $employmentId, $onDate),
            );
            $candidates = [];
            foreach ($duties as $duty) {
                if ($duty->reportedByEmployer && $duty->deadline !== null) {
                    $candidates[$duty->sourceEventReference()] = $duty;
                }
            }
            $persisted = $this->persistDuties(
                $supplierId,
                $environment,
                $candidates,
                $createdBy,
            );
            $registered = [];
            foreach ($duties as $duty) {
                $dutyId = $duty->sourceEventReference();
                $record = $persisted[$dutyId] ?? null;
                $registered[] = [
                    'duty_id' => $dutyId,
                    'duty' => $duty->toArray(),
                    'obligation_id' => $record['obligation_id'] ?? null,
                    'created' => $record['created'] ?? false,
                    'skipped_reason_code' => $record === null
                        ? 'zp_duty_not_reported_by_employer'
                        : null,
                ];
            }

            return $registered;
        });
    }

    /** @return array{items:list<array<string,mixed>>,total:int,created:int} */
    public function registerPeriodObligations(
        int $supplierId,
        string $environment,
        string $period,
        ?int $createdBy = null,
    ): array {
        return $this->submissionRepository->transaction(function () use (
            $supplierId,
            $environment,
            $period,
            $createdBy,
        ): array {
            if (!$this->submissionRepository->lockSupplier($supplierId)) {
                throw new HealthNotificationException(
                    'zp_supplier_not_found',
                    'Firma pro evidenci povinností nebyla nalezena.',
                );
            }
            $set = $this->periodDutySet($supplierId, $period);
            if ($set['unresolved'] !== []) {
                throw new HealthNotificationException(
                    'zp_period_contains_unresolved_employments',
                    sprintf(
                        'Povinnosti nelze synchronizovat: u %d pracovních vztahů chybí údaje potřebné k určení zdravotní pojišťovny.',
                        count($set['unresolved']),
                    ),
                );
            }

            $candidates = [];
            foreach ($set['duties'] as $entry) {
                $duty = $entry['duty'];
                if ($duty->reportedByEmployer && $duty->deadline !== null) {
                    $candidates[$duty->sourceEventReference()] = $duty;
                }
            }
            $registered = $this->persistDuties(
                $supplierId,
                $environment,
                $candidates,
                $createdBy,
            );

            $items = [];
            foreach (array_keys($candidates) as $dutyId) {
                if (isset($registered[$dutyId])) {
                    $items[] = $registered[$dutyId];
                }
            }

            return [
                'items' => $items,
                'total' => count($items),
                'created' => count(array_filter(
                    $items,
                    static fn (array $item): bool => $item['created'],
                )),
            ];
        });
    }

    /**
     * Zmrazí přehled o platbě pojistného do odesílatelné podoby. Neodesílá.
     *
     * @return array<string,mixed>
     */
    public function preparePaymentOverview(
        int $supplierId,
        string $environment,
        int $revisionId,
        string $insurerCode,
        ?int $createdBy = null,
    ): array {
        $this->schemas->assertInsurerCode($insurerCode);
        $overview = $this->overviews->overview(
            $supplierId,
            $revisionId,
            $insurerCode,
        );
        $payload = $this->payload($supplierId, $overview);
        $xml = $this->serializer->serializePaymentOverview($payload);
        $channel = $this->channelDescription($supplierId, $insurerCode);
        $pdf = $this->pdfRenderer->renderPayload(
            $payload,
            is_string($channel['insurer_name'] ?? null)
                ? $channel['insurer_name']
                : null,
            $this->today(),
        );
        $window = $this->deadlines->forPaymentOverview($overview->period);
        $sourceHash = hash('sha256', CanonicalJson::encode([
            'schema_reference' => 'payroll-health-payment-overview-submission.v4',
            'revision_id' => $overview->revisionId,
            'insurer_code' => $insurerCode,
            'statutory_result_hash' => $overview->statutoryResultHash,
            'xml_sha256' => hash('sha256', $xml),
            'pdf_template_reference' =>
                $this->pdfRenderer->templateReference($insurerCode),
            'isds_attachment_rules' => $channel['isds_attachment_rules'],
        ]));
        $obligation = $this->obligations->register(
            $supplierId,
            self::AGENDA_PAYMENT_OVERVIEW,
            self::SUBJECT_RUN,
            'payroll_run:' . $overview->runId . ':' . $insurerCode,
            // Období povinnosti je mzdový měsíc, za který se pojistné platí,
            // ne okno lhůty. Lhůta má vlastní pole a plést je znamená, že se
            // přehled zařadí do jiného měsíce, než za který je.
            $overview->period . '-01',
            $window->earliestSubmissionOn,
            'regular',
            self::CHANNEL,
            self::SOURCE_EVENT_OVERVIEW,
            'payroll_health_payment_overview:' . $overview->revisionId
                . ':' . $insurerCode,
            $sourceHash,
            $window->earliestSubmissionOn,
            $window->dueOn,
            $window->calendarBasis,
            $window->rulesetId,
            $window->rulesetHash,
            'health-overview-obligation:' . $environment . ':' . $sourceHash,
            null,
            $createdBy,
            null,
            $environment,
        );

        return $this->submissionRepository->transaction(function () use (
            $supplierId,
            $environment,
            $insurerCode,
            $overview,
            $payload,
            $xml,
            $pdf,
            $sourceHash,
            $obligation,
            $window,
            $createdBy,
        ): array {
            if (!$this->submissionRepository->lockSupplier($supplierId)) {
                throw new HealthNotificationException(
                    'zp_supplier_missing',
                    'Firma přehledu o platbě nebyla nalezena.',
                );
            }
            $keys = $this->idempotencyKeys($environment, $sourceHash);
            $submission = $this->submissions->prepare(
                $supplierId,
                $obligation['id'],
                'regular',
                self::CHANNEL,
                $sourceHash,
                $keys['submission'],
                // Revize se jako zdroj NEVÁŽE: platforma na ní trvá jen tehdy,
                // když je otisk podání shodný s otiskem výsledku revize. Otisk
                // přehledu ale váže XML a zdravotní výsledek, ne celý běh —
                // provázat je by znamenalo tvrdit shodu, která neplatí.
                null,
                null,
                $createdBy,
                $environment,
            );
            if (!$submission['created']) {
                $artifactId = $this->submissionRepository
                    ->findOutboundXmlArtifactId(
                        $supplierId,
                        $environment,
                        (int) $submission['id'],
                    );
                $pdfArtifactId = $this->submissionRepository
                    ->findOutboundPdfArtifactId(
                        $supplierId,
                        $environment,
                        (int) $submission['id'],
                    );
                $xmlArtifact = $artifactId === null
                    ? null
                    : $this->submissionRepository->findArtifact(
                        $supplierId,
                        $artifactId,
                    );
                $pdfArtifact = $pdfArtifactId === null
                    ? null
                    : $this->submissionRepository->findArtifact(
                        $supplierId,
                        $pdfArtifactId,
                    );
                if ($xmlArtifact === null || $pdfArtifact === null) {
                    throw new HealthNotificationException(
                        'zp_submission_artifact_missing',
                        'Dříve připravené podání nemá oba zmrazené podklady.',
                    );
                }
                return [
                    'submission_id' => (int) $submission['id'],
                    'obligation_id' => $obligation['id'],
                    'artifact_id' => $artifactId,
                    'pdf_artifact_id' => $pdfArtifactId,
                    'status' => (string) $submission['status'],
                    'row_version' => (int) $submission['row_version'],
                    'insurer_code' => $insurerCode,
                    'period' => $overview->period,
                    'agenda_code' => self::AGENDA_PAYMENT_OVERVIEW,
                    'artifact_sha256' =>
                        (string) $xmlArtifact['artifact_sha256'],
                    'pdf_artifact_sha256' =>
                        (string) $pdfArtifact['artifact_sha256'],
                    'created' => false,
                    'deadline' => $window->toArray(),
                    'schema_validated' => $this->isSchemaValidatedStatus(
                        (string) $submission['status'],
                    ),
                    'dispatch' => $this->dispatchDescription(
                        $supplierId,
                        $insurerCode,
                    ),
                ];
            }
            $part = $this->submissions->addPart(
                $supplierId,
                (int) $submission['id'],
                (int) $submission['row_version'],
                'ppz:' . $overview->revisionId . ':' . $insurerCode,
                self::AGENDA_PAYMENT_OVERVIEW,
                'payroll_run:' . $overview->runId . ':' . $insurerCode,
                'payroll_revision',
                'payroll_health_payment_overview:' . $overview->revisionId
                    . ':' . $insurerCode,
                $sourceHash,
            );
            $xmlArtifact = $this->submissions->storeArtifact(
                $supplierId,
                (int) $submission['id'],
                (int) $part['submission_row_version'],
                (int) $part['id'],
                'outbound_xml',
                'outbound',
                'application/xml',
                $xml,
                HealthInsuranceSchemaCatalog::XSD_VERSION,
                null,
                self::CHANNEL,
                $keys['xml_artifact'],
                $createdBy,
            );
            if (!hash_equals(
                hash('sha256', $xml),
                (string) $xmlArtifact['artifact_sha256'],
            )) {
                throw new HealthNotificationException(
                    'zp_artifact_mismatch',
                    'Otisk uloženého artefaktu neodpovídá zmrazené datové větě.',
                );
            }

            $pdfArtifact = $this->submissions->storeArtifact(
                $supplierId,
                (int) $submission['id'],
                (int) $xmlArtifact['submission_row_version'],
                (int) $part['id'],
                'outbound_pdf',
                'outbound',
                'application/pdf',
                $pdf,
                null,
                null,
                self::CHANNEL,
                $keys['pdf_artifact'],
                $createdBy,
            );
            if (!hash_equals(
                hash('sha256', $pdf),
                (string) $pdfArtifact['artifact_sha256'],
            )) {
                throw new HealthNotificationException(
                    'zp_pdf_artifact_mismatch',
                    'Otisk uloženého PDF neodpovídá zmrazenému přehledu.',
                );
            }

            $rowVersion = (int) $pdfArtifact['submission_row_version'];
            $status = (string) $submission['status'];
            $validated = false;
            try {
                $this->validator->validatePaymentOverview($payload, $xml);
                $validated = true;
            } catch (HealthNotificationException $e) {
                // Bez připnutého XSD se podání nesmí označit za ověřené.
                // Artefakt ale zůstává uložený i s výhradou, aby bylo vidět,
                // co se vyrobilo a proč to není připravené k odeslání.
                $issue = $this->submissions->recordIssue(
                    $supplierId,
                    (int) $submission['id'],
                    $rowVersion,
                    (int) $part['id'],
                    'blocker',
                    'xsd',
                    $e->errorCode,
                    'payroll_revision',
                    (string) $overview->revisionId,
                    ['message' => $e->getMessage()],
                    $createdBy,
                );
                $rowVersion = (int) $issue['submission_row_version'];
            }
            if ($validated) {
                $transition = $this->submissions->transition(
                    $supplierId,
                    (int) $submission['id'],
                    $rowVersion,
                    'validated',
                );
                $ready = $this->submissions->transition(
                    $supplierId,
                    (int) $submission['id'],
                    (int) $transition['row_version'],
                    'ready',
                );
                $rowVersion = (int) $ready['row_version'];
                $status = (string) $ready['status'];
            }

            return [
                'submission_id' => (int) $submission['id'],
                'obligation_id' => $obligation['id'],
                'part_id' => (int) $part['id'],
                'artifact_id' => (int) $xmlArtifact['id'],
                'pdf_artifact_id' => (int) $pdfArtifact['id'],
                'status' => $status,
                'row_version' => $rowVersion,
                'insurer_code' => $insurerCode,
                'period' => $overview->period,
                'agenda_code' => self::AGENDA_PAYMENT_OVERVIEW,
                'artifact_sha256' => (string) $xmlArtifact['artifact_sha256'],
                'pdf_artifact_sha256' =>
                    (string) $pdfArtifact['artifact_sha256'],
                'created' => true,
                'deadline' => $window->toArray(),
                'schema_validated' => $validated,
                'dispatch' => $this->dispatchDescription(
                    $supplierId,
                    $insurerCode,
                ),
            ];
        });
    }

    /**
     * Dostupnost přímého portálového API pojišťovny.
     *
     * `assertDispatchable()` je `never` — bez veřejně popsané portálové
     * obálky skončí výjimkou. ISDS je oddělená podporovaná cesta a tento
     * příznak ji nesmí vydávat za nedostupnou.
     *
     * @return array<string,mixed>
     */
    private function dispatchDescription(
        int $supplierId,
        string $insurerCode,
    ): array
    {
        try {
            $this->channels->assertDispatchable($insurerCode);
        } catch (HealthNotificationException $e) {
            return [
                'supported' => false,
                'reason_code' => $e->errorCode,
                'reason' => $e->getMessage(),
                'channel' => $this->channelDescription(
                    $supplierId,
                    $insurerCode,
                ),
            ];
        }
    }

    /**
     * @return array{
     *   duties:list<array{duty:HealthNotificationDuty,full_name:string}>,
     *   unresolved:list<array{employment_id:int,full_name:string,reason_code:string,reason:string}>
     * }
     */
    private function periodDutySet(int $supplierId, string $period): array
    {
        $window = $this->periodBounds($period);
        $resolved = [];
        $unresolved = [];
        foreach ($this->facts->listNotificationFacts(
            $supplierId,
            $window['from'],
            $window['to'],
        ) as $row) {
            try {
                $duties = $this->resolver->resolve($this->factsFromRow($row));
            } catch (HealthNotificationException $exception) {
                $unresolved[] = [
                    'employment_id' => $row['employment_id'],
                    'full_name' => $row['full_name'],
                    'reason_code' => $exception->errorCode,
                    'reason' => $exception->getMessage(),
                ];
                continue;
            }
            foreach ($duties as $duty) {
                if ($duty->occurredOn < $window['from']
                    || $duty->occurredOn > $window['to']
                ) {
                    continue;
                }
                $resolved[] = [
                    'duty' => $duty,
                    'full_name' => $row['full_name'],
                ];
            }
        }

        return ['duties' => $resolved, 'unresolved' => $unresolved];
    }

    /**
     * @param array<string,HealthNotificationDuty> $duties
     * @return array<string,array{duty_id:string,obligation_id:int,created:bool}>
     */
    private function persistDuties(
        int $supplierId,
        string $environment,
        array $duties,
        ?int $createdBy,
    ): array {
        $registrations = [];
        foreach ($duties as $dutyId => $duty) {
            $registrations[$dutyId] = $this->registrationRowsForDuty(
                $supplierId,
                $environment,
                $duty,
                $createdBy,
            );
        }
        $existing = $this->submissionRepository
            ->obligationStatesBySourceReferences(
                $supplierId,
                $environment,
                self::AGENDA_BULK_NOTIFICATION,
                self::SOURCE_EVENT_NOTIFICATION,
                array_keys($duties),
            );
        $result = [];
        $newObligations = [];
        foreach ($registrations as $dutyId => $registration) {
            $state = $existing[$dutyId] ?? null;
            if ($state !== null) {
                if (!$this->storedStateMatches(
                    $state,
                    $registration,
                    $duties[$dutyId],
                )) {
                    throw new HealthNotificationException(
                        'zp_registered_duty_changed',
                        'Dříve evidovaná povinnost už neodpovídá aktuálním údajům. Nejprve zkontrolujte změnu pojišťovny, lhůty nebo zrušenou povinnost.',
                    );
                }
                $result[$dutyId] = [
                    'duty_id' => $dutyId,
                    'obligation_id' => $state['id'],
                    'created' => false,
                ];
                continue;
            }
            $newObligations[] = $registration['obligation'];
        }
        if ($newObligations === []) {
            return $result;
        }

        $this->submissionRepository->insertObligationsBatch($newObligations);
        $inserted = $this->submissionRepository
            ->obligationStatesBySourceReferences(
                $supplierId,
                $environment,
                self::AGENDA_BULK_NOTIFICATION,
                self::SOURCE_EVENT_NOTIFICATION,
                array_keys($registrations),
            );
        $newDeadlines = [];
        foreach ($registrations as $dutyId => $registration) {
            if (isset($result[$dutyId])) {
                continue;
            }
            $state = $inserted[$dutyId] ?? null;
            if ($state === null
                || $state['duplicate_count'] !== 1
                || !hash_equals(
                    $state['source_event_hash'],
                    (string) $registration['obligation']['source_event_hash'],
                )
            ) {
                throw new HealthNotificationException(
                    'zp_registered_duty_changed',
                    'Nově evidovanou povinnost se nepodařilo jednoznačně načíst.',
                );
            }
            $deadline = $registration['deadline'];
            $deadline['obligation_id'] = $state['id'];
            $newDeadlines[] = $deadline;
            $result[$dutyId] = [
                'duty_id' => $dutyId,
                'obligation_id' => $state['id'],
                'created' => true,
            ];
        }
        $this->submissionRepository->insertDeadlinesBatch($newDeadlines);

        return $result;
    }

    /**
     * @return array{
     *   obligation:array<string,int|string|null>,
     *   deadline:array<string,int|string|null>
     * }
     */
    private function registrationRowsForDuty(
        int $supplierId,
        string $environment,
        HealthNotificationDuty $duty,
        ?int $createdBy,
    ): array {
        if (!$duty->reportedByEmployer || $duty->deadline === null) {
            throw new \LogicException('Nelze evidovat povinnost pojištěnce.');
        }
        $sourceHash = $this->dutySourceHash($duty);

        return $this->obligations->registrationRows(
            $supplierId,
            self::AGENDA_BULK_NOTIFICATION,
            self::SUBJECT_EMPLOYMENT,
            $duty->subjectReference(),
            $duty->occurredOn,
            $duty->deadline->dueOn,
            'regular',
            self::CHANNEL,
            self::SOURCE_EVENT_NOTIFICATION,
            $duty->sourceEventReference(),
            $sourceHash,
            $duty->deadline->earliestSubmissionOn,
            $duty->deadline->dueOn,
            $duty->deadline->calendarBasis,
            $duty->deadline->rulesetId,
            $duty->deadline->rulesetHash,
            'health-notification:' . $environment . ':' . $sourceHash,
            null,
            $createdBy,
            null,
            $environment,
        );
    }

    /**
     * @param array<string,mixed> $state
     * @param array{
     *   obligation:array<string,int|string|null>,
     *   deadline:array<string,int|string|null>
     * } $registration
     */
    private function storedStateMatches(
        array $state,
        array $registration,
        HealthNotificationDuty $duty,
    ): bool {
        $obligation = $registration['obligation'];
        $deadline = $registration['deadline'];
        if ($state['duplicate_count'] !== 1
            || $state['status'] === 'cancelled'
            || !in_array(
                $state['source_event_hash'],
                [
                    $this->dutySourceHash($duty),
                    $this->legacyDutySourceHash($duty),
                ],
                true,
            )
        ) {
            return false;
        }
        foreach ([
            'subject_type',
            'subject_reference',
            'period_start',
            'period_end',
            'obligation_kind',
            'preferred_channel',
        ] as $field) {
            if ($state[$field] !== $obligation[$field]) {
                return false;
            }
        }
        foreach ([
            'earliest_submission_on',
            'due_on',
            'calendar_basis',
            'ruleset_id',
            'ruleset_hash',
        ] as $field) {
            if ($state[$field] !== $deadline[$field]) {
                return false;
            }
        }
        if (!hash_equals(
            $state['trigger_event_hash'] ?? '',
            $state['source_event_hash'],
        )) {
            return false;
        }
        if (hash_equals(
            $state['source_event_hash'],
            (string) $obligation['source_event_hash'],
        )) {
            return hash_equals(
                $state['request_fingerprint'],
                (string) $obligation['request_fingerprint'],
            ) && hash_equals(
                $state['idempotency_key_hash'],
                (string) $obligation['idempotency_key_hash'],
            );
        }

        $legacyHash = $this->legacyDutySourceHash($duty);

        return hash_equals(
            $state['request_fingerprint'],
            $this->legacyRequestFingerprint(
                $obligation,
                $deadline,
                $legacyHash,
            ),
        ) && hash_equals(
            $state['idempotency_key_hash'],
            hash(
                'sha256',
                'health-notification:'
                    . $obligation['environment'] . ':' . $legacyHash,
                true,
            ),
        );
    }

    /**
     * @param array<string,int|string|null> $obligation
     * @param array<string,int|string|null> $deadline
     */
    private function legacyRequestFingerprint(
        array $obligation,
        array $deadline,
        string $legacyHash,
    ): string {
        return hash('sha256', CanonicalJson::encode([
            'schema_reference' => 'payroll-obligation-register.v1',
            'supplier_id' => $obligation['supplier_id'],
            'environment' => $obligation['environment'],
            'agenda_code' => $obligation['agenda_code'],
            'subject_type' => $obligation['subject_type'],
            'subject_reference' => $obligation['subject_reference'],
            'period_start' => $obligation['period_start'],
            'period_end' => $obligation['period_end'],
            'obligation_kind' => $obligation['obligation_kind'],
            'channel' => $obligation['preferred_channel'],
            'source_event_type' => $obligation['source_event_type'],
            'source_event_reference' => $obligation['source_event_reference'],
            'source_event_hash' => $legacyHash,
            'earliest_submission_on' => $deadline['earliest_submission_on'],
            'due_on' => $deadline['due_on'],
            'calendar_basis' => $deadline['calendar_basis'],
            'ruleset_id' => $deadline['ruleset_id'],
            'ruleset_hash' => $deadline['ruleset_hash'],
            'responsible_user_id' => $obligation['responsible_user_id'],
            'fiction_delivery_days' => $deadline['fiction_delivery_days'],
        ]));
    }

    private function dutySourceHash(HealthNotificationDuty $duty): string
    {
        if ($duty->deadline === null) {
            throw new \LogicException('Povinnost nemá lhůtu.');
        }

        return hash('sha256', CanonicalJson::encode([
            'schema_reference' => 'payroll-health-notification-obligation.v2',
            'employment_id' => $duty->employmentId,
            'kind' => $duty->kind->value,
            'insurer_code' => $duty->insurerCode,
            'occurred_on' => $duty->occurredOn,
            'earliest_submission_on' => $duty->deadline->earliestSubmissionOn,
            'due_on' => $duty->deadline->dueOn,
            'calendar_basis' => $duty->deadline->calendarBasis,
            'ruleset_id' => $duty->deadline->rulesetId,
            'ruleset_hash' => $duty->deadline->rulesetHash,
        ]));
    }

    private function legacyDutySourceHash(HealthNotificationDuty $duty): string
    {
        return hash('sha256', CanonicalJson::encode([
            'schema_reference' => 'payroll-health-notification-obligation.v1',
            'employment_id' => $duty->employmentId,
            'kind' => $duty->kind->value,
            'insurer_code' => $duty->insurerCode,
            'occurred_on' => $duty->occurredOn,
        ]));
    }

    /**
     * Jedna položka přehledu za období: povinnost obohacená o jméno, kanál
     * a o to, jestli se z ní dá vyrobit kód změny.
     *
     * @return array<string,mixed>
     */
    private function periodItem(
        int $supplierId,
        HealthNotificationDuty $duty,
        string $fullName,
    ): array {
        $documented = $this->codes->isCodeMappingDocumented($duty->kind);
        $code = null;
        $codeReason = null;
        try {
            $code = $this->codes->codeFor($duty->kind);
        } catch (HealthNotificationException $exception) {
            // Konkrétní důvod, ne obecné „nepodařilo se" — u každého ze tří
            // nedoložených druhů je jiný a uživatel podle něj pozná, jestli
            // chybí doklad, nebo povinnost.
            $codeReason = $exception->getMessage();
        }
        $channel = null;
        try {
            $channel = $this->channelDescription(
                $supplierId,
                $duty->insurerCode,
            );
        } catch (HealthNotificationException $exception) {
            $channel = [
                'insurer_code' => $duty->insurerCode,
                'insurer_name' => null,
                'undocumented_reason_code' => $exception->errorCode,
                'note' => $exception->getMessage(),
            ];
        }

        return [
            'id' => $duty->sourceEventReference(),
            'employment_id' => $duty->employmentId,
            'employee_id' => $duty->employeeId,
            'full_name' => $fullName,
            'kind' => $duty->kind->value,
            'label' => $duty->rule->label,
            'insurer_code' => $duty->insurerCode,
            'occurred_on' => $duty->occurredOn,
            'reported_by_employer' => $duty->reportedByEmployer,
            'rule' => $duty->rule->toArray(),
            'deadline' => $duty->deadline?->toArray(),
            'change_code' => [
                'documented' => $documented,
                'code' => $code,
                'reason' => $codeReason,
            ],
            'channel' => $channel,
            'dispatch' => $this->dispatchDescription(
                $supplierId,
                $duty->insurerCode,
            ),
        ];
    }

    /** @return array<string,mixed> */
    private function channelDescription(
        int $supplierId,
        string $insurerCode,
    ): array {
        $channel = $this->channels->forInsurer($insurerCode);
        $recipient = $this->recipients->findVisibleByCode(
            $supplierId,
            $this->channels->recipientCodeFor($insurerCode),
        );
        if ($recipient !== null && !$recipient['is_active']) {
            $recipient = null;
        }

        return $channel->toArray($recipient, $this->today());
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $filters
     */
    private function matchesFilters(array $item, array $filters): bool
    {
        $insurer = $filters['insurer_code'] ?? null;
        if (is_string($insurer) && $insurer !== ''
            && $item['insurer_code'] !== $insurer
        ) {
            return false;
        }
        $kind = $filters['kind'] ?? null;
        if (is_string($kind) && $kind !== '' && $item['kind'] !== $kind) {
            return false;
        }
        $reported = $filters['reported'] ?? null;
        if (is_bool($reported) && $item['reported_by_employer'] !== $reported) {
            return false;
        }
        $undocumented = $filters['undocumented_code_only'] ?? null;
        if ($undocumented === true && $item['change_code']['documented']) {
            return false;
        }

        return true;
    }

    /** @return array{from:string,to:string} */
    private function periodBounds(string $period): array
    {
        if (preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $period) !== 1) {
            throw new HealthNotificationException(
                'zp_period_invalid',
                'Mzdové období musí mít tvar RRRR-MM.',
            );
        }
        $from = new \DateTimeImmutable(
            $period . '-01',
            new \DateTimeZone('Europe/Prague'),
        );

        return [
            'from' => $from->format('Y-m-d'),
            'to' => $from->modify('last day of this month')->format('Y-m-d'),
        ];
    }

    /** @param array<string,mixed> $row */
    private function factsFromRow(array $row): HealthNotificationFacts
    {
        return new HealthNotificationFacts(
            employmentId: (int) $row['employment_id'],
            employeeId: (int) $row['employee_id'],
            relationType: (string) $row['relation_type'],
            participates: (bool) $row['participates'],
            insurerCode: $row['insurer_code'],
            startedOn: $row['start_date'],
            endedOn: $row['end_date'],
            previousInsurerCode: $row['previous_insurer_code'],
            insurerChangedOn: $row['insurer_changed_on'],
            maternityLeaveStartedOn: $row['maternity_leave_started_on'],
            parentalLeaveStartedOn: $row['parental_leave_started_on'],
            maternityOrParentalLeaveEndedOn:
                $row['maternity_or_parental_leave_ended_on'],
        );
    }

    private function payload(
        int $supplierId,
        HealthPaymentOverview $overview,
    ): HealthPaymentOverviewPayload {
        $employer = $this->requireEmployer(
            $supplierId,
            $overview->insurerCode,
            $overview->period . '-01',
        );
        $contribution = $overview->totals['total_contribution_minor_units'];
        if ($contribution % 100 !== 0) {
            throw new HealthNotificationException(
                'zp_contribution_not_whole_crowns',
                'Součet pojistného obsahuje haléře, ale datová věta má '
                . 'nonNegativeInteger, tedy celé koruny. Zaokrouhlit smí '
                . 'jen výpočet pojistného, ne podání.',
            );
        }

        return new HealthPaymentOverviewPayload(
            insurerCode: $overview->insurerCode,
            overviewKind: HealthPaymentOverviewPayload::KIND_REGULAR,
            employer: $employer,
            month: (int) substr($overview->period, 5, 2),
            year: (int) substr($overview->period, 0, 4),
            employeeCount: $overview->totals['person_count'],
            assessmentBaseMinorUnits:
                $overview->totals['assessment_base_minor_units'],
            contributionCzk: intdiv($contribution, 100),
        );
    }

    private function requireEmployer(
        int $supplierId,
        string $insurerCode,
        string $effectiveOn,
    ): HealthEmployerIdentification {
        $row = $this->facts->findEmployerIdentification($supplierId);
        if ($row === null) {
            throw new HealthNotificationException(
                'zp_supplier_missing',
                'Firma podání nebyla nalezena.',
            );
        }
        if ($row['business_id'] === null) {
            throw new HealthNotificationException(
                'zp_payer_business_id_missing',
                'Firma nemá vyplněné IČO. Doplňte ho v nastavení firmy — '
                . 'bez něj nelze sestavit identifikační číslo plátce.',
            );
        }

        $identifiers = $this->institutionAccounts->findEffectivePaymentIdentifiers(
            $supplierId,
            InstitutionAccountType::HEALTH_INSURER->value,
            $insurerCode,
            'CZK',
            $effectiveOn,
        );
        $payerNumber = preg_replace(
            '/\D+/',
            '',
            (string) ($identifiers['variable_symbol'] ?? ''),
        );
        if (preg_match('/^[0-9]{10}$/', $payerNumber) !== 1) {
            throw new HealthNotificationException(
                'zp_payer_number_missing',
                'U účinného platebního účtu pojišťovny chybí desetimístné identifikační číslo plátce (VS zaměstnavatele).',
            );
        }

        return new HealthEmployerIdentification(
            payerNumber: $payerNumber,
            name: $row['name'],
            street: (string) ($row['street'] ?? ''),
            houseNumber: (string) ($row['house_number'] ?? ''),
            postalCode: (string) ($row['postal_code'] ?? ''),
            city: (string) ($row['city'] ?? ''),
            phone: (string) ($row['phone'] ?? ''),
        );
    }

    private function requireFacts(
        int $supplierId,
        int $employmentId,
        string $onDate,
    ): HealthNotificationFacts {
        if ($supplierId <= 0 || $employmentId <= 0) {
            throw new \InvalidArgumentException(
                'Rozsah zdravotního oznámení není platný.',
            );
        }
        $row = $this->facts->findNotificationFacts(
            $supplierId,
            $employmentId,
            $onDate,
        );
        if ($row === null) {
            throw new \OutOfBoundsException(
                'Pracovní vztah pro zdravotní oznámení nebyl nalezen.',
            );
        }

        return $this->factsFromRow($row);
    }

    /** @return array{submission:string,xml_artifact:string,pdf_artifact:string} */
    private function idempotencyKeys(
        string $environment,
        string $sourceHash,
    ): array {
        $fingerprint = hash('sha256', CanonicalJson::encode([
            'schema_reference' => 'payroll-health-overview-submission.v1',
            'environment' => $environment,
            'source_hash' => $sourceHash,
        ]));

        return [
            'submission' => 'health-overview-submission:' . $fingerprint,
            'xml_artifact' => 'health-overview-xml-artifact:' . $fingerprint,
            'pdf_artifact' => 'health-overview-pdf-artifact:' . $fingerprint,
        ];
    }

    private function today(): string
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new \DateTimeZone('Europe/Prague'))
            ->format('Y-m-d');
    }

    private function isSchemaValidatedStatus(string $status): bool
    {
        return in_array($status, [
            'validated',
            'prepared',
            'ready',
            'submitted',
            'processing',
            'waiting_for_identity',
            'partially_accepted',
            'accepted',
            'rejected',
            'correction_required',
            'superseded',
            'cancelled_in_time',
        ], true);
    }
}
