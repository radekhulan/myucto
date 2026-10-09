<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

use MyInvoice\Repository\Payroll\EldpStatementRepository;
use MyInvoice\Repository\Payroll\PayrollEmployerIdentifierSql;
use MyInvoice\Repository\Payroll\PayrollSicknessCaseRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;
use MyInvoice\Service\Payroll\Submission\PayrollDispatchGate;
use MyInvoice\Service\Payroll\Cssz\CsszSchemaCatalog;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverReader;
use MyInvoice\Service\Payroll\PayrollHistoricalPeriodService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollRevealPurpose;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Service\Payroll\Submission\Isds\PayrollIsdsSubmissionService;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzSoftwareIdentification;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionCalendar;
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
 * Podání se připravuje na kanál `isds` — viz {@see SicknessChannelCatalog}.
 * Druhý doložený kanál je VREP/APEP (Class `CSSZ_NEM_PRI`); odesílá ho
 * {@see \MyInvoice\Service\Payroll\Submission\Vrep\CsszFormVrepTransportService}
 * a kanál podání si přepíše až při odeslání. Oba kanály se vzájemně hlídají:
 * podání, které odešlo přes VREP, se do datové schránky nezařadí a naopak.
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
        private SicknessPayloadFactory $payloads,
        private NempriDecisivePeriodResolver $decisivePeriods,
        private NempriPaymentConnectionResolver $paymentConnections,
        private PayrollTakeoverReader $takeover,
        private PayrollHistoricalPeriodService $historical,
        private PayrollSensitiveData $sensitiveData,
        private NempriPayrollMonthReader $payrollMonths,
        private EldpStatementRepository $revisions,
        private SicknessInsuredContactReader $insuredContacts,
        private PayrollSubmissionTransportAttemptRepository $attempts,
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
        $column = $document->submissionColumn();
        $submissionId = ($row[$column] ?? null) === null ? 0 : (int) $row[$column];
        if ($submissionId <= 0) {
            throw new SicknessException(
                'sickness_submission_not_prepared',
                'Podání ještě není připravené, takže není co odeslat.'
                    . ' Nejdřív u případu zvolte Připravit.',
            );
        }
        // Podání, které už odešlo přes VREP (nebo možná odešlo), se do datové
        // schránky nezařadí: u ČSSZ by vzniklo podruhé. Pravidla jsou tatáž
        // jako ve frontě podání ({@see PayrollDispatchGate}).
        foreach ($this->attempts->listForSubmission($supplierId, $environment, $submissionId) as $attempt) {
            $reason = PayrollDispatchGate::possiblyDeliveredReason($attempt);
            if ($reason === null && !PayrollDispatchGate::attemptAllowsRetry($attempt)) {
                $reason = 'Podání už bylo odesláno přes VREP (pokus č. '
                    . (int) ($attempt['attempt_no'] ?? 0) . '). Datovou schránkou'
                    . ' se znovu neodesílá, u ČSSZ by vzniklo jako duplicita.';
            }
            if ($reason !== null) {
                throw new SicknessException('sickness_submission_sent_via_vrep', $reason);
            }
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
        if ($document === SicknessDocumentKind::NempriTransfer && !SicknessCaseService::transferNoticeRequired($row)) {
            throw new SicknessException(
                'nempri_transfer_not_required',
                'Druhé oznámení NEMPRI ke dni převedení se u tohoto případu nepodává. Podává se jen '
                . 'u zaměstnankyně převedené na jinou práci z důvodu těhotenství, mateřství nebo kojení '
                . 'v dřívějším kalendářním měsíci, než vznikla sociální událost (§ 19 odst. 6 zákona '
                . 'č. 187/2006 Sb.). Převedení a jeho důvod se vyplňují u případu.',
            );
        }
        if (SicknessCaseService::documentStatus($row, $document) === SicknessDocumentStatus::Predecessor) {
            throw new SicknessException(
                'sickness_document_handled_by_predecessor',
                $document->shortLabel() . ' k této události podal předchozí mzdový program, '
                . 'MyÚčto ho znovu nepodává. Druhé podání téže věci by ČSSZ odmítla. Nepodal-li '
                . 'ho předchozí program, vraťte ho u případu tlačítkem Předchozí program nepodal.',
            );
        }
        $kind = SicknessBenefitKind::from((string) $row['benefit_kind']);
        $employmentId = (int) $row['employment_id'];
        $incapacityFrom = (string) $row['incapacity_from'];
        $incapacityTo = $this->nullableText($row['incapacity_to'] ?? null);
        if ($document->isNempri()) {
            $legacy = $kind->legacyFormProblem(
                $incapacityFrom,
                $this->nullableText($row['maternity_care_reason'] ?? null) !== null,
            );
            if ($legacy !== null) {
                throw new SicknessException($legacy['code'], $legacy['message']);
            }
        }
        $context = PayrollEmployerIdentifierSql::resolveVariableSymbol(
            $this->caseService->requireContext(
                $supplierId,
                $employmentId,
                $incapacityFrom,
            ),
            $environment,
        );
        $identity = $this->identities->sensitiveIdentityAt(
            $supplierId,
            (int) $row['employee_id'],
            $incapacityFrom,
        );
        // Jméno a příjmení platné v den provádění zápisu (Všeobecné zásady
        // NEMPRI, sekce B), ne k rozhodnému dni: změní-li zaměstnanec po vzniku
        // události příjmení, ČSSZ ho ztotožňuje podle současného. Ostatní údaje
        // identity zůstávají ke dni události.
        $current = $this->identities->sensitiveIdentityAt(
            $supplierId,
            (int) $row['employee_id'],
            PayrollSubmissionCalendar::today(),
        );
        $identity['identity']['first_name'] = $current['identity']['first_name'];
        $identity['identity']['last_name'] = $current['identity']['last_name'];

        // Odpracovaná celá směna v den vzniku posouvá první den neschopnosti
        // (§ 26 odst. 3) a počátek podpůrčí doby ošetřovného (§ 40 odst. 1),
        // a tím okno 14 dnů i lhůty, stejně jako v seznamu případů a v hlídači.
        $workedFirstDay = SicknessDeadlinePolicy::firstDayShiftDefersSupport($kind)
            && SicknessCaseService::firstDayFullyWorked($row);
        if ($document->isNempri()) {
            if (!$this->deadlines->nempriRequired($kind, $incapacityFrom, $incapacityTo, 0, $workedFirstDay)) {
                throw new SicknessException(
                    'nempri_within_wage_compensation_window',
                    'Neschopnost nebo karanténa nepřesáhla 14 kalendářních dnů. Celou ji '
                    . 'kryje náhrada mzdy (§ 192 zákoníku práce), nemocenské náleží až od '
                    . '15. dne (§ 26 odst. 1 zák. č. 187/2006 Sb.), takže se ČSSZ nic '
                    . 'nepředává. Trvá-li neschopnost déle, opravte v případu den skončení.',
                );
            }
            // Událost po skončení vztahu jen v ochranné lhůtě (§ 15); mimo ni
            // nárok z tohoto vztahu nevznikl a zaměstnavatel nic nepředává.
            $this->caseService->assertEventCovered($kind, $incapacityFrom, $context, $row);
            $this->caseService->assertLongTermCareNotRefused($kind, $row);
            /*
             * Chybějící údaje případu se hlásí NAJEDNOU. Dřív náhled spadl na
             * prvním (pravděpodobný příjem), po doplnění na dalším (číslo
             * rozhodnutí) a účetní opravovala a zkoušela náhled pořád dokola.
             */
            $missing = [];
            try {
                $payload = $this->nempriPayload(
                    $supplierId,
                    $environment,
                    $caseId,
                    $row,
                    $kind,
                    $context,
                    $identity,
                    $document,
                );
            } catch (SicknessException $exception) {
                $missing[] = $exception;
                $payload = null;
            }
            // Číslo rozhodnutí podle druhu dávky (povinnost, zákaz i tvar) se
            // ohlídá hned, stejným pravidlem jako ve validátoru věty, a hlásí
            // se spolu s ostatními chybami případu; validátor ho jinak vidí
            // až u hotové věty.
            $problem = $kind->decisionNumberProblem(
                $this->decisionNumberForCheck($row),
                $kind->hasApplication()
                    && $this->nullableText($row['maternity_care_reason'] ?? null) !== null,
                SicknessPayloadFactory::nempriForeignCase($row),
            );
            if ($problem !== null) {
                $missing[] = new SicknessException($problem['code'], $problem['message']);
            }
            // U DPP oznámení nese započitatelný příjem z měsíce události
            // (`prijemMalyRozsah`); kvůli němu se ostatně čeká na konec měsíce.
            if (($context['relation_type'] ?? null) === 'dpp'
                && ($row['small_scope_income_minor'] ?? null) === null
            ) {
                $missing[] = new SicknessException(
                    'nempri_small_scope_income_missing',
                    'Zaměstnanec pracuje na dohodu o provedení práce, takže oznámení nese započitatelný '
                    . 'příjem z kalendářního měsíce, v němž sociální událost vznikla. Doplňte ho '
                    . 'u případu (Příjem ze zaměstnání malého rozsahu nebo DPP).',
                );
            }
            if ($missing !== [] || $payload === null) {
                throw new SicknessException(
                    $missing[0]->validationCode,
                    implode("\n", array_map(
                        static fn (SicknessException $exception): string => $exception->getMessage(),
                        $missing,
                    )),
                );
            }
            $xml = $this->nempriSerializer->serialize($payload);
            $this->validator->validateNempri($payload, $xml);
            $window = $this->deadlines->forNempri(
                $kind,
                $incapacityFrom,
                $incapacityTo,
                $this->nullableText($row['payroll_payment_date'] ?? null),
                (bool) ($row['lone_caregiver'] ?? false),
                $workedFirstDay,
                SicknessDeadlinePolicy::awaitsEventMonthIncome($context, $row),
            );
        } else {
            $notRequired = $this->deadlines->hzupnNotRequired(
                $kind,
                $incapacityFrom,
                $incapacityTo,
                $this->nullableText($context['end_date'] ?? null),
                $workedFirstDay,
            );
            if ($notRequired !== null) {
                throw new SicknessException($notRequired['code'], $notRequired['message']);
            }
            $manifest = $this->schemas->manifestFor(CsszSchemaCatalog::HZUPN20);
            $payload = $this->payloads->hzupn(
                $row,
                $context,
                $identity,
                $manifest['payload_version'],
                $this->software->productName,
                $this->software->productVersion,
            );
            $xml = $this->hzupnSerializer->serialize($payload);
            $this->validator->validateHzupn($payload, $xml, $incapacityFrom);
            $window = $this->deadlines->forHzupn(
                $incapacityFrom,
                $incapacityTo,
                $this->nullableText($row['returned_on'] ?? null),
            );
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
     * Obsah věty NEMPRI. Mapování dělá čistá {@see SicknessPayloadFactory};
     * tady se jen načte a odhalí to, co k němu potřebuje databázi: dítě nebo
     * ošetřovanou osobu, rozhodné období z převzatých mezd a způsob výplaty
     * mzdy.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $context
     * @param array<string,mixed> $identity
     */
    private function nempriPayload(
        int $supplierId,
        string $environment,
        int $caseId,
        array $row,
        SicknessBenefitKind $kind,
        array $context,
        array $identity,
        SicknessDocumentKind $document = SicknessDocumentKind::Nempri,
    ): NempriXmlPayload {
        $manifest = $this->schemas->manifestFor(CsszSchemaCatalog::NEMPRI25);
        $employeeId = (int) $row['employee_id'];
        $eventOn = (string) $row['incapacity_from'];
        // Ošetřovné a dlouhodobé ošetřovné bez akce vznik (jen trvání nebo
        // ukončení) rozhodné období ani platební spojení nenesou: DV NEMPRI25
        // je u nich zakazuje. Způsob výplaty se proto ani nezjišťuje — dřív
        // podání zaměstnance bez účtu spadlo na údaji, který věta nesmí mít.
        $startsClaim = !$kind->hasActions() || (bool) ($row['action_start'] ?? true);
        // Druhé oznámení ke dni převedení je táž věta s jiným rozhodným dnem
        // a s větou pro územní správu v dalším sdělení (NempriTransferNotice).
        $transferredOn = null;
        if ($document === SicknessDocumentKind::NempriTransfer) {
            $transferredOn = NempriTransferNotice::transferDate(
                $kind,
                $row,
                $this->nullableText($context['end_date'] ?? null),
            );
            if ($transferredOn === null) {
                throw new SicknessException(
                    'nempri_transfer_not_required',
                    'Druhé oznámení ke dni převedení se podle údajů případu nepodává: chybí převedení '
                    . 'z důvodu těhotenství, mateřství nebo kojení, nebo leží ve stejném měsíci jako '
                    . 'rozhodný den, takže by neslo totéž rozhodné období jako první oznámení.',
                );
            }
            $row['additional_note'] = NempriTransferNotice::note(
                $this->nullableText($row['additional_note'] ?? null),
            );
        }

        return $this->payloads->nempri(
            $row,
            $kind,
            $context,
            $identity,
            $manifest['payload_version'],
            $this->software->productName,
            $this->software->productVersion,
            $kind->hasApplication()
                ? $this->caredPerson($supplierId, $employeeId, $row)
                : null,
            $startsClaim
                ? $this->decisivePeriod($supplierId, $environment, $caseId, $row, $context, $transferredOn)
                : null,
            $startsClaim
                ? $this->paymentConnection($supplierId, $employeeId, $eventOn)
                : null,
            $this->insuredContacts->forEmployee($supplierId, $employeeId),
        );
    }

    /**
     * Dítě nebo ošetřovaná osoba. Z evidence vyživovaných osob se bere jméno,
     * datum narození a rodné číslo (odhalené jen pro tohle podání); osoba mimo
     * evidenci nese jen jméno a datum narození, které účetní opsala z žádosti.
     *
     * @param array<string,mixed> $row
     */
    private function caredPerson(int $supplierId, int $employeeId, array $row): ?NempriPerson
    {
        $dependantId = (int) ($row['cared_dependant_id'] ?? 0);
        if ($dependantId > 0) {
            $dependant = $this->cases->dependant($supplierId, $employeeId, $dependantId);
            if ($dependant === null) {
                throw new SicknessException(
                    'nempri_cared_person_not_found',
                    'Vybraná vyživovaná osoba u zaměstnance už není. Vyberte ji u případu znovu.',
                );
            }
            [$first, $last] = self::splitName($dependant);
            $ciphertext = $dependant['birth_number_ciphertext'] ?? null;

            return new NempriPerson(
                $first,
                $last,
                // Evidence vyživovaných osob drží RČ jako RRMMDD/XXXX,
                // NEMPRI (`rodneCislo`) bere jen číslice.
                is_string($ciphertext) && $ciphertext !== ''
                    ? \MyInvoice\Service\Payroll\CzechBirthNumber::forSubmission(
                        $this->sensitiveData->reveal(
                            $ciphertext,
                            PayrollSensitiveField::PERSONAL_IDENTIFIER,
                            $supplierId,
                            $dependantId,
                            PayrollRevealPurpose::SUBMISSION_CSSZ_SICKNESS,
                        ),
                    )
                    : null,
                $this->nullableText($dependant['birth_date'] ?? null),
            );
        }
        $first = $this->nullableText($row['cared_first_name'] ?? null);
        $last = $this->nullableText($row['cared_last_name'] ?? null);
        if ($first === null && $last === null) {
            return null;
        }

        return new NempriPerson(
            (string) $first,
            (string) $last,
            null,
            $this->nullableText($row['cared_birth_date'] ?? null),
        );
    }

    /**
     * @param array<string,mixed> $dependant
     * @return array{0:string,1:string}
     */
    private static function splitName(array $dependant): array
    {
        $given = trim((string) ($dependant['given_name'] ?? ''));
        $family = trim((string) ($dependant['family_name'] ?? ''));
        if ($given !== '' && $family !== '') {
            return [$given, $family];
        }
        // Starší záznamy mají jen celé jméno. Poslední slovo je příjmení —
        // stejná konvence jako v evidenci osob; nesedí-li, účetní doplní
        // jméno a příjmení zvlášť na kartě vyživované osoby.
        $parts = preg_split('/\s+/u', trim((string) ($dependant['full_name'] ?? ''))) ?: [];
        $last = (string) array_pop($parts);

        return [implode(' ', $parts), $last];
    }

    /**
     * Rozhodné období věty. Rozhodný den je den vzniku sociální události,
     * u události v ochranné lhůtě den po skončení zaměstnání (§ 19 odst. 11).
     * Druhé oznámení převedené těhotné, matky nebo kojící zaměstnankyně nese
     * období ke dni převedení (§ 19 odst. 6); výhodnější z obou vybere územní
     * správa. Platební spojení se dál řídí dnem události.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $context
     * @param ?string $transferredOn den převedení u druhého oznámení
     */
    private function decisivePeriod(
        int $supplierId,
        string $environment,
        int $caseId,
        array $row,
        array $context,
        ?string $transferredOn = null,
    ): NempriDecisivePeriod {
        $employmentStart = SicknessPayloadFactory::employmentFrom($context);
        $decisiveDate = $transferredOn ?? NempriDecisivePeriodResolver::decisiveDate(
            (string) $row['incapacity_from'],
            $this->nullableText($context['end_date'] ?? null),
        );
        $employmentId = (int) $row['employment_id'];
        $sources = new NempriDecisiveSources(
            function (int $year) use ($supplierId, $employmentId): array {
                $months = [];
                foreach ($this->takeover->forEmployment($supplierId, $employmentId, $year)->months as $month) {
                    if ($month->employmentId === $employmentId) {
                        $months[] = $month;
                    }
                }

                return $months;
            },
            fn (int $year): array => $this->payrollMonths->months(
                $supplierId,
                $employmentId,
                $this->revisions->revisionsForYear($supplierId, $year),
            ),
            $this->cases->decisiveMonths($supplierId, $environment, $caseId),
        );
        $probable = $row['probable_income_czk'] ?? null;

        return $this->decisivePeriods->resolve(
            $decisiveDate,
            $employmentStart,
            $this->historical->startPeriod($supplierId),
            $sources,
            $probable === null || $probable === '' ? null : (int) $probable,
        );
    }

    private function paymentConnection(
        int $supplierId,
        int $employeeId,
        string $onDate,
    ): ?NempriPaymentConnection {
        $target = $this->cases->payoutTarget($supplierId, $employeeId, $onDate);
        $plaintext = null;
        if ($target['account'] !== null) {
            $plaintext = $this->sensitiveData->reveal(
                $target['account']['ciphertext'],
                PayrollSensitiveField::BANK_ACCOUNT,
                $supplierId,
                $target['account']['id'],
                PayrollRevealPurpose::SUBMISSION_CSSZ_SICKNESS,
            );
            $hash = bin2hex($this->sensitiveData->lookupHash(
                $plaintext,
                PayrollSensitiveField::BANK_ACCOUNT,
                $supplierId,
            ));
            if (!hash_equals($target['account']['hash'], $hash)) {
                throw new \RuntimeException('Otisk výplatního účtu neodpovídá ciphertextu.');
            }
        }

        return $this->paymentConnections->resolve(
            $target['payout_method'],
            $plaintext,
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
            $window->obligationCalendarBasis(),
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
        // Společný stav případu se odvozuje (`prepared` z vazby na podání),
        // takže se tu nezapisuje. Nové podání po odmítnutí vrací tiskopis do
        // stavu „čeká na doručení".
        $changes = [$document->submissionColumn() => $submissionId];
        if (SicknessCaseService::documentStatus($row, $document) === SicknessDocumentStatus::Rejected) {
            $changes[$document->statusColumn()] = SicknessDocumentStatus::Pending->value;
            $changes[$document->rejectionReasonColumn()] = null;
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

    /**
     * Číslo rozhodnutí tak, jak ho nese věta (velká písmena, bez mezer
     * okolo): {@see SicknessPayloadFactory} ho normalizuje stejně.
     *
     * @param array<string,mixed> $row
     */
    private function decisionNumberForCheck(array $row): ?string
    {
        $number = $this->nullableText($row['decision_number'] ?? null);

        return $number === null ? null : strtoupper($number);
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
