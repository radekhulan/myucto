<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use MyInvoice\Repository\Payroll\PayrollRegistrationSubmissionRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionGuidFactory;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use Psr\Clock\ClockInterface;

/**
 * Most mezi hotovým registračním jádrem (resolver → serializér → validátor)
 * a platformou podání MZ-19. Bez něj se serializér ani validátor v běhu
 * aplikace nikdy nespustí a zaměstnance nelze u ČSSZ přihlásit.
 *
 * Čtyři rozhodnutí, na kterých vrstva stojí:
 *
 * 1. **Interakci vybírá resolver nebo schválený event, ne volající.** Běžný
 *    endpoint nepřijímá kód formuláře; REGZEC A2–A8 se připravují pouze ze
 *    samostatně schváleného a neměnného zdroje.
 * 2. **Zmrazené XML je pravda podání.** Vzniká právě jednou, uloží se jako
 *    artefakt a při idempotentním opakování se NESTAVÍ ZNOVU — nové GUIDy by
 *    pod týmž podáním vyrobily jiný dokument a přijatou duplicitu nelze u ČSSZ
 *    vzít zpět.
 * 3. **Povinnost a lhůta vznikají PŘED podáním.** `PayrollObligationService`
 *    dostane okno z `PayrollEmployeeRegistrationDeadlinePolicy`; podání se pak
 *    váže na už evidovanou povinnost. Registr povinností MZ-19 tak o registraci
 *    ví i tehdy, když ji nikdo nepřipraví.
 * 4. **Podání končí ve stavu `ready`, nikdy „odesláno".** Přechod na
 *    `submitted` patří transportu, přechod na `accepted` protokolu ČSSZ.
 *    Připravené XML není přihlášený zaměstnanec.
 */
final readonly class PayrollRegistrationSubmissionService
{
    public const AGENDA_PREZEC = 'PREZEC26';
    public const AGENDA_REGZEC = 'REGZEC25';

    /**
     * Interní klíč agendy registru povinností pro přihlášku ZAMĚSTNAVATELE
     * do evidence (§ 17). Není to kód formuláře ČSSZ — první přihláška
     * zaměstnavatele nemá datovou větu ani XSD a podává se mimo aplikaci.
     * Povinnost a lhůtu ale evidovat musíme, jinak ji nikdo neuhlídá.
     */
    public const AGENDA_EMPLOYER_REGISTRATION = 'REGZEL26';

    public const SOURCE_EVENT_TYPE = 'payroll_employment_registration';
    public const CHECKLIST_PHASE = 'onboarding';
    public const CHECKLIST_ITEM_KEY = 'social_jmhz_registration';

    private const CHANNEL = 'vrep_apep';
    private const SUBJECT_TYPE = 'employment';

    /**
     * Lhůta zaměstnavatele počítá české pracovní dny; slovník povinností zná
     * jen `calendar_days` a `business_days`. Mapuje se tady, jednou a viditelně
     * — tiché předání `czech_working_days` by povinnost odmítla až validací
     * hluboko v `PayrollObligationService`.
     */
    private const CALENDAR_BASIS_MAP = [
        'czech_working_days' => 'business_days',
        'calendar_days' => 'calendar_days',
        'business_days' => 'business_days',
    ];

    public function __construct(
        private PayrollRegistrationSubmissionRepository $registrations,
        private PayrollRegistrationIdentityService $identities,
        private PayrollRegistrationEventService $events,
        private PayrollRegistrationIdentitySnapshotBuilder $snapshots,
        private PayrollRegistrationInteractionResolver $interactions,
        private PayrollRegistrationXmlSerializer $serializer,
        private PayrollRegistrationXmlValidator $validator,
        private PayrollEmployeeRegistrationDeadlinePolicy $deadlines,
        private EmployerRegistrationDeadlinePolicy $employerDeadlines,
        private PayrollObligationService $obligations,
        private PayrollSubmissionService $submissions,
        private PayrollSubmissionRepository $submissionRepository,
        private JmhzSubmissionGuidFactory $guids,
        private ClockInterface $clock,
    ) {}

    /**
     * Test: ukáže, co by se podalo, a nezaloží nic. GUIDy jsou zahozené,
     * proto se výsledek nesmí použít jako doklad o podání.
     *
     * @return array{
     *   employment_id:int,agenda_code:string,interaction:string,
     *   action_code:int,xml:string,xml_sha256:string,
     *   deadline:array{earliest_registration_on:string,due_on:string,
     *     calendar_basis:string,ruleset_id:string},
     *   employer_registration:?array<string,string>,
     *   official_submission:array{supported:bool,reason:string}
     * }
     */
    public function preview(
        int $supplierId,
        string $environment,
        int $employmentId,
        ?int $eventId = null,
    ): array {
        $resolved = $this->resolve(
            $supplierId,
            $environment,
            $employmentId,
            0,
            $eventId,
        );

        return [
            'employment_id' => $employmentId,
            'agenda_code' => $resolved['interaction']->documentType,
            'interaction' => $resolved['interaction']->interaction,
            'action_code' => $resolved['interaction']->actionCode,
            'xml' => $resolved['xml'],
            'xml_sha256' => hash('sha256', $resolved['xml']),
            'deadline' => $this->describeDeadline($resolved['deadline']),
            'employer_registration' => $resolved['employer_deadline'],
            'official_submission' => [
                'supported' => false,
                'reason' => 'Tohle je test: podání se nezakládá a nic se neodesílá.',
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function listEvents(
        int $supplierId,
        string $environment,
        int $employmentId,
    ): array {
        $this->requireContext($supplierId, $employmentId);

        return $this->events->list($supplierId, $environment, $employmentId);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function approveEvent(
        int $supplierId,
        string $environment,
        int $employmentId,
        array $input,
        int $approvedBy,
    ): array {
        $this->requireContext($supplierId, $employmentId);

        return $this->events->approve(
            $supplierId,
            $environment,
            $employmentId,
            $input,
            $approvedBy,
        );
    }

    /**
     * Zmrazí registraci do odesílatelné podoby: povinnost, podání, část
     * a artefakt s přesnými bajty XML. Neodesílá.
     *
     * @return array{
     *   submission_id:int,obligation_id:int,part_id:int,artifact_id:int,
     *   status:string,row_version:int,environment:string,agenda_code:string,
     *   interaction:string,artifact_sha256:string,created:bool,
     *   deadline:array{earliest_registration_on:string,due_on:string,
     *     calendar_basis:string,ruleset_id:string}
     * }
     */
    public function prepare(
        int $supplierId,
        string $environment,
        int $employmentId,
        ?int $createdBy = null,
        ?int $eventId = null,
    ): array {
        // Povinnost a lhůta vznikají mimo transakci podání a nezávisle na tom,
        // jestli se podání povede připravit. Kdyby vznikaly až spolu s ním,
        // neúspěšná příprava by po sobě nenechala ani stopu po termínu.
        $context = $this->requireContext($supplierId, $employmentId);
        if ($eventId === null) {
            $this->registerEmployerObligation(
                $supplierId,
                $environment,
                $context,
                $createdBy,
            );
        }
        $probe = $this->resolve(
            $supplierId,
            $environment,
            $employmentId,
            0,
            $eventId,
        );
        $obligation = $this->registerObligation(
            $supplierId,
            $environment,
            $context,
            $probe,
            $createdBy,
        );

        return $this->submissionRepository->transaction(function () use (
            $supplierId,
            $environment,
            $employmentId,
            $createdBy,
            $probe,
            $obligation,
            $eventId,
        ): array {
            if (!$this->submissionRepository->lockSupplier($supplierId)) {
                throw new PayrollRegistrationXmlException(
                    'registration_supplier_missing',
                    'Firma registračního podání nebyla nalezena.',
                );
            }
            $sourceHash = $probe['source_hash'];
            $keys = $this->idempotencyKeys(
                $supplierId,
                $environment,
                $employmentId,
                $sourceHash,
            );
            $submission = $this->submissions->prepare(
                $supplierId,
                $obligation['id'],
                'regular',
                self::CHANNEL,
                $sourceHash,
                $keys['submission'],
                null,
                null,
                $createdBy,
                $environment,
            );
            if (!$submission['created']) {
                return $this->replayedResult(
                    $supplierId,
                    $environment,
                    $employmentId,
                    $submission,
                    $probe,
                    $obligation['id'],
                );
            }

            // Teprve tady je známé id podání, které patří do rozsahu snapshotu.
            // Proto se snapshot i XML staví ZNOVU s definitivním rozsahem —
            // sonda výš sloužila jen k tomu, aby se nic nezakládalo, když by
            // podání stejně neprošlo.
            $frozen = $this->resolve(
                $supplierId,
                $environment,
                $employmentId,
                (int) $submission['id'],
                $eventId,
            );
            $part = $this->submissions->addPart(
                $supplierId,
                (int) $submission['id'],
                (int) $submission['row_version'],
                $this->partReference(
                    $frozen['interaction'],
                    $employmentId,
                    $eventId,
                ),
                $frozen['interaction']->documentType,
                PayrollRegistrationSubmissionRepository::employmentReference(
                    $employmentId,
                ),
                $eventId === null
                    ? 'payroll_employment'
                    : 'payroll_registration_event',
                self::sourceEventReference($employmentId, $eventId),
                $frozen['source_hash'],
            );
            $artifact = $this->submissions->storeArtifact(
                $supplierId,
                (int) $submission['id'],
                (int) $part['submission_row_version'],
                (int) $part['id'],
                'outbound_xml',
                'outbound',
                'application/xml',
                $frozen['xml'],
                $frozen['schema_version'],
                null,
                self::CHANNEL,
                $keys['artifact'],
                $createdBy,
            );
            if (!hash_equals(
                hash('sha256', $frozen['xml']),
                (string) $artifact['artifact_sha256'],
            )) {
                throw new PayrollRegistrationXmlException(
                    'registration_artifact_mismatch',
                    'Otisk uloženého artefaktu neodpovídá zmrazenému registračnímu XML.',
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
            if ($eventId === null) {
                $this->registrations->setChecklistDueDate(
                    $supplierId,
                    $employmentId,
                    self::CHECKLIST_PHASE,
                    self::CHECKLIST_ITEM_KEY,
                    $frozen['deadline']->dueOn,
                );
            } elseif (in_array(
                $frozen['interaction']->actionCode,
                [2, 8],
                true,
            )) {
                $this->registrations->setChecklistDueDate(
                    $supplierId,
                    $employmentId,
                    'offboarding',
                    'social_jmhz_deregistration',
                    $frozen['deadline']->dueOn,
                );
            }

            return [
                'submission_id' => (int) $submission['id'],
                'obligation_id' => $obligation['id'],
                'part_id' => (int) $part['id'],
                'artifact_id' => (int) $artifact['id'],
                'status' => (string) $ready['status'],
                'row_version' => (int) $ready['row_version'],
                'environment' => $environment,
                'agenda_code' => $frozen['interaction']->documentType,
                'interaction' => $frozen['interaction']->interaction,
                'artifact_sha256' => (string) $artifact['artifact_sha256'],
                'created' => true,
                'deadline' => $this->describeDeadline($frozen['deadline']),
            ];
        });
    }

    public static function sourceEventReference(
        int $employmentId,
        ?int $eventId = null,
    ): string
    {
        if ($employmentId <= 0) {
            throw new \InvalidArgumentException(
                'Pracovní vztah musí být kladné číslo.',
            );
        }

        if ($eventId !== null) {
            if ($eventId <= 0) {
                throw new \InvalidArgumentException(
                    'Zdrojová událost musí být kladné číslo.',
                );
            }
            return "payroll_registration_event:{$eventId}";
        }

        return "payroll_employment_registration:{$employmentId}";
    }

    /**
     * Jediné místo, kde se z pracovního vztahu stane registrační podání.
     * Vrací všechno, co obě veřejné cesty potřebují — nácvik i zmrazení musí
     * projít týmiž kontrolami, jinak by nácvik sliboval něco, co zmrazení
     * odmítne.
     *
     * @return array{
     *   interaction:PayrollRegistrationInteraction,
     *   snapshot:PayrollRegistrationIdentitySnapshot,
     *   xml:string,source_hash:string,schema_version:string,
     *   deadline:PayrollEmployeeRegistrationDeadlineWindow,
     *   employer_deadline:?array<string,string>
     * }
     */
    private function resolve(
        int $supplierId,
        string $environment,
        int $employmentId,
        int $submissionId,
        ?int $eventId = null,
    ): array {
        $context = $this->requireContext($supplierId, $employmentId);
        $event = $eventId === null
            ? null
            : $this->events->load(
                $supplierId,
                $environment,
                $employmentId,
                $eventId,
            );
        $effectiveOn = $event === null
            ? $this->effectiveDate($context)
            : (string) $event['effective_on'];
        $interactionContext = $this->interactionContext(
            $supplierId,
            $environment,
            $context,
            $event,
        );
        $source = $this->identities->sensitiveSnapshotSourceAt(
            $supplierId,
            $context['employee_id'],
            $employmentId,
            $environment,
            $effectiveOn,
        );
        if ($event !== null
            && is_array($event['employment_external_identifier'] ?? null)
        ) {
            $currentExternal = is_array(
                $source['employment_external_identifier'] ?? null,
            ) ? $source['employment_external_identifier'] : [];
            $source['employment_external_identifier'] = array_merge(
                $currentExternal,
                $event['employment_external_identifier'],
                [
                    'employee_id' => $context['employee_id'],
                    'employment_id' => $employmentId,
                    'environment' => $environment,
                    'identifier_type' => 'id_ppv',
                ],
            );
            $source['resolution']['employment_external_id'] = 'resolved';
        }
        // Snapshot nese agendu ve svém rozsahu a resolver na shodě trvá, takže
        // agendu je nutné znát DŘÍV než interakci. Rozhoduje o ní `agendaFor()`
        // téhož resolveru — tady se nic neodvozuje podruhé. `resolve()` pak
        // vazbu agenda ↔ snapshot ověří znovu, takže případný rozpor spadne
        // hlasitě a ne až na XSD.
        $citizenship = $source['identity']['citizenship_country_code'] ?? null;
        $snapshot = $this->snapshots->build(
            $this->scope(
                $supplierId,
                $environment,
                $employmentId,
                $context,
                $submissionId,
                $this->interactions->agendaFor(
                    is_string($citizenship) ? $citizenship : null,
                    $interactionContext,
                ),
                $effectiveOn,
            ),
            $source,
        );
        if ($event !== null) {
            $eventEmploymentIdentifier = $event['employment_external_identifier']['value']
                ?? null;
            if (!is_string($eventEmploymentIdentifier)
                || ($snapshot->employmentExternalIdentifier['value'] ?? null)
                    !== $eventEmploymentIdentifier
            ) {
                throw new PayrollRegistrationXmlException(
                    'registration_event_id_ppv_snapshot_mismatch',
                    'ID PPV v neměnném zdroji neodpovídá identitě účinné k datu události.',
                );
            }
        }
        $interaction = $this->interactions->resolve(
            $snapshot,
            $interactionContext,
        );
        $payload = $this->payload(
            $snapshot,
            $interaction,
            $context,
            $effectiveOn,
            $event,
        );
        $xml = $this->serializer->serialize($payload);
        // Validátor si XML serializuje znovu a porovná bajty; volá se i tady,
        // v produkční cestě, ne jen v testu. XSD a hranice agendy jsou to
        // jediné, co brání odeslat dokument, který ČSSZ odmítne.
        $this->validator->validate($payload, $xml);

        return [
            'interaction' => $interaction,
            'snapshot' => $snapshot,
            'xml' => $xml,
            'source_hash' => $event === null
                ? hash('sha256', CanonicalJson::encode([
                    'identity' => $snapshot->toArray(),
                    'event_fingerprint' => null,
                ]))
                : $this->eventFingerprint($event),
            'schema_version' => $interaction->documentType,
            'deadline' => $this->deadlineFor(
                $interaction,
                $context,
                $event === null
                    ? $effectiveOn
                    : (string) ($event['notification_trigger_on'] ?? ''),
            ),
            'employer_deadline' => $event === null
                ? $this->employerDeadline($context)
                : null,
            'event_effective_on' => $event === null ? null : $effectiveOn,
            'source_event_reference' => self::sourceEventReference(
                $employmentId,
                $eventId,
            ),
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array{
     *   supplier_id:int,submission_id:int,source_revision_id:null,
     *   employee_id:int,employment_id:int,environment:string,
     *   agenda_code:string,effective_on:string
     * }
     */
    private function scope(
        int $supplierId,
        string $environment,
        int $employmentId,
        array $context,
        int $submissionId,
        string $agendaCode,
        string $effectiveOn,
    ): array {
        return [
            'supplier_id' => $supplierId,
            // Nácvik ještě žádné podání nemá. Nula by neprošla kontrolou
            // rozsahu, proto sonda dostane technickou jedničku a definitivní
            // snapshot až skutečné id — a protože se rozsah promítá do otisku,
            // nácvikový otisk se se zmrazeným nikdy nepotká.
            'submission_id' => $submissionId > 0 ? $submissionId : 1,
            'source_revision_id' => null,
            'employee_id' => $context['employee_id'],
            'employment_id' => $employmentId,
            'environment' => $environment,
            'agenda_code' => $agendaCode,
            'effective_on' => $effectiveOn,
        ];
    }

    /** @param array<string,mixed> $context */
    private function payload(
        PayrollRegistrationIdentitySnapshot $snapshot,
        PayrollRegistrationInteraction $interaction,
        array $context,
        string $effectiveOn,
        ?array $event,
    ): PayrollRegistrationXmlPayload {
        $eventEmployer = is_array($event['employer'] ?? null)
            ? $event['employer']
            : null;
        $variableSymbol = $eventEmployer['variable_symbol']
            ?? $context['employer_variable_symbol'];
        if (!is_string($variableSymbol) || $variableSymbol === '') {
            throw new PayrollRegistrationXmlException(
                'registration_employer_variable_symbol_missing',
                'Účtárna nemá vyplněný variabilní symbol ČSSZ. Doplňte ho v Nastavení mezd → Účtárny a registraci podejte znovu.',
            );
        }
        $regzec = $interaction->documentType === self::AGENDA_REGZEC;
        if ($regzec && $context['cssz_workplace_code'] === null) {
            throw new PayrollRegistrationXmlException(
                'registration_cssz_workplace_code_missing',
                'Plná registrace REGZEC vyžaduje kód pracoviště ČSSZ. Doplňte ho v Nastavení mezd → Zaměstnavatel a registraci podejte znovu.',
            );
        }

        return new PayrollRegistrationXmlPayload(
            identity: $snapshot,
            interaction: $interaction,
            sequenceNumber: 1,
            formGuid: $this->guids->next(),
            preparedOn: $this->today(),
            expectedStartOn: $effectiveOn,
            actualStartOn: $regzec && $event === null ? $effectiveOn : null,
            employerVariableSymbol: $variableSymbol,
            employerName: $regzec
                ? (string) ($eventEmployer['name']
                    ?? $context['employer_name'])
                : null,
            csszWorkplaceCode: $regzec
                ? (string) ($eventEmployer['workplace_code']
                    ?? $context['cssz_workplace_code'])
                : null,
            eventSnapshot: $event,
        );
    }

    /**
     * Fakta pro resolver. `full_registration_data` NEZNAMENÁ „máme vyplněná
     * pole" — znamená „plnou registraci lze doložit jako skutečnost". Před
     * nástupem českého občana se za doloženou vědomě nepovažuje: `job/@fro`
     * je datum SKUTEČNÉHO nástupu a předjímat ho znamená tvrdit ČSSZ událost,
     * která se ještě nestala. Přesně na tuhle mezeru je PREZEC.
     *
     * @param array<string,mixed> $context
     * @return array{
     *   work_started:bool,full_registration_data:bool,
     *   pre_registration_accepted:bool,did_not_start:bool,
     *   employment_ended:bool
     * }
     */
    private function interactionContext(
        int $supplierId,
        string $environment,
        array $context,
        ?array $event = null,
    ): array {
        $workStarted = $context['actual_start_date'] !== null
            || in_array(
                $context['status'],
                ['active', 'suspended', 'ended', 'archived'],
                true,
            );
        $employerDataComplete = $context['employer_name'] !== ''
            && $context['employer_variable_symbol'] !== null
            && $context['cssz_workplace_code'] !== null;

        return [
            'work_started' => $workStarted,
            'full_registration_data' => $employerDataComplete && $workStarted,
            'pre_registration_accepted' =>
                $this->registrations->hasAcceptedPreRegistration(
                    $supplierId,
                    $environment,
                    $context['employment_id'],
                    self::AGENDA_PREZEC,
                ),
            'did_not_start' => $context['status'] === 'no_show',
            'employment_ended' => in_array(
                $context['status'],
                ['ended', 'archived'],
                true,
            ) || $context['end_date'] !== null,
            'event_interaction' => is_string($event['interaction'] ?? null)
                ? $event['interaction']
                : null,
        ];
    }

    /** @param array<string,mixed> $context */
    private function deadlineFor(
        PayrollRegistrationInteraction $interaction,
        array $context,
        ?string $effectiveOn = null,
    ): PayrollEmployeeRegistrationDeadlineWindow {
        if ($interaction->documentType === self::AGENDA_REGZEC
            && $interaction->actionCode >= 2
        ) {
            return $this->deadlines->forFollowUp(
                $interaction->actionCode,
                $effectiveOn
                    ?? (string) ($context['end_date']
                        ?? $this->effectiveDate($context)),
            );
        }
        $startOn = $this->effectiveDate($context);

        return $interaction->interaction === 'pre_registration_no_show'
            ? $this->deadlines->forNoShow($startOn)
            : $this->deadlines->forEmploymentStart($startOn);
    }

    /**
     * Lhůta přihlášky zaměstnavatele se hlásí jen u PRVNÍHO pracovního vztahu
     * — u dalších je zaměstnavatel dávno v evidenci a upozorňovat na ni znovu
     * by z povinnosti udělalo šum.
     *
     * @param array<string,mixed> $context
     * @return array<string,string>|null
     */
    private function employerDeadline(array $context): ?array
    {
        if ($context['is_first_employment'] !== true) {
            return null;
        }
        try {
            $window = $this->employerDeadlines->forFirstEmployeeStart(
                $this->effectiveDate($context),
            );
        } catch (\InvalidArgumentException) {
            // Nástup mimo podporované okno: lhůta zaměstnavatele se neodvozuje.
            // Registrace zaměstnance na tom nestojí, takže se nic neblokuje.
            return null;
        }

        return [
            'earliest_registration_on' => $window->earliestRegistrationOn,
            'due_on' => $window->dueOn,
            'deemed_employer_from' => $window->deemedEmployerFrom,
            'no_show_notification_due_on' =>
                $window->noShowNotificationDueOn,
            'calendar_basis' => $window->calendarBasis,
            'ruleset_id' => $window->rulesetId,
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $resolved
     * @return array{id:int,due_on:string,status:string,row_version:int,created:bool}
     */
    private function registerObligation(
        int $supplierId,
        string $environment,
        array $context,
        array $resolved,
        ?int $createdBy,
    ): array {
        $interaction = $resolved['interaction'];
        $window = $resolved['deadline'];
        $reference =
            PayrollRegistrationSubmissionRepository::employmentReference(
                $context['employment_id'],
            );
        $sourceHash = hash('sha256', CanonicalJson::encode([
            'schema_reference' => 'payroll-registration-obligation.v1',
            'employment_id' => $context['employment_id'],
            'agenda_code' => $interaction->documentType,
            'interaction' => $interaction->interaction,
            'effective_on' => $resolved['event_effective_on']
                ?? $this->effectiveDate($context),
        ]));

        return $this->obligations->register(
            $supplierId,
            $interaction->documentType,
            self::SUBJECT_TYPE,
            $reference,
            $window->earliestRegistrationOn,
            $window->dueOn,
            'regular',
            self::CHANNEL,
            self::SOURCE_EVENT_TYPE,
            $resolved['source_event_reference'],
            $sourceHash,
            $window->earliestRegistrationOn,
            $window->dueOn,
            $this->calendarBasis($window->calendarBasis),
            $window->rulesetId,
            $window->rulesetHash,
            'registration:' . $environment . ':' . $sourceHash,
            null,
            $createdBy,
            null,
            $environment,
        );
    }

    /**
     * Povinnost přihlásit ZAMĚSTNAVATELE do evidence. Podání za ni tahle
     * aplikace nevyrábí (nemá datovou větu ani XSD), ale lhůta bez evidované
     * povinnosti neexistuje pro nikoho — a je to lhůta, po jejímž zmeškání
     * vzniká fikce zaměstnavatele.
     *
     * @param array<string,mixed> $context
     */
    private function registerEmployerObligation(
        int $supplierId,
        string $environment,
        array $context,
        ?int $createdBy,
    ): void {
        $employer = $this->employerDeadline($context);
        if ($employer === null) {
            return;
        }
        $window = $this->employerDeadlines->forFirstEmployeeStart(
            $this->effectiveDate($context),
        );
        $sourceHash = hash('sha256', CanonicalJson::encode([
            'schema_reference' => 'payroll-employer-registration-obligation.v1',
            'first_employment_id' => $context['employment_id'],
            'expected_start_on' => $this->effectiveDate($context),
        ]));
        $this->obligations->register(
            $supplierId,
            self::AGENDA_EMPLOYER_REGISTRATION,
            'employer',
            'payroll_employer:' . $supplierId,
            $window->earliestRegistrationOn,
            $window->dueOn,
            'regular',
            // Přihláška zaměstnavatele se nepodává datovou větou, takže kanál
            // není `vrep_apep`. Označit ji tak by slibovalo odeslání, které
            // aplikace neumí.
            'other',
            'payroll_employer_registration',
            'payroll_employer_registration:' . $supplierId,
            $sourceHash,
            $window->earliestRegistrationOn,
            $window->dueOn,
            $this->calendarBasis($window->calendarBasis),
            $window->rulesetId,
            $window->rulesetHash,
            'employer-registration:' . $environment . ':' . $sourceHash,
            null,
            $createdBy,
            null,
            $environment,
        );
    }

    /**
     * @param array<string,mixed> $submission
     * @param array<string,mixed> $resolved
     * @return array<string,mixed>
     */
    private function replayedResult(
        int $supplierId,
        string $environment,
        int $employmentId,
        array $submission,
        array $resolved,
        int $obligationId,
    ): array {
        $stored = $this->registrations->registrationBySubmission(
            $supplierId,
            $environment,
            $employmentId,
            (int) $submission['id'],
        );
        if ($stored === null || $stored['artifact_sha256'] === null) {
            throw new PayrollRegistrationXmlException(
                'registration_replay_artifact_missing',
                'Registrační podání pro tenhle vztah už existuje, ale nemá zmrazený artefakt. Neopakuje se — vyřešte původní podání.',
            );
        }

        return [
            'submission_id' => (int) $submission['id'],
            'obligation_id' => $obligationId,
            'part_id' => 0,
            'artifact_id' => 0,
            'status' => (string) $submission['status'],
            'row_version' => (int) $submission['row_version'],
            'environment' => $environment,
            'agenda_code' => $stored['agenda_code'],
            'interaction' => $resolved['interaction']->interaction,
            'artifact_sha256' => $stored['artifact_sha256'],
            'created' => false,
            'deadline' => $this->describeDeadline($resolved['deadline']),
        ];
    }

    /**
     * @return array{
     *   employment_id:int,employee_id:int,office_id:?int,status:string,
     *   relation_type:string,start_date:?string,actual_start_date:?string,
     *   end_date:?string,employer_name:string,
     *   employer_variable_symbol:?string,cssz_workplace_code:?string,
     *   is_first_employment:bool
     * }
     */
    private function requireContext(int $supplierId, int $employmentId): array
    {
        if ($supplierId <= 0 || $employmentId <= 0) {
            throw new \InvalidArgumentException(
                'Rozsah registračního podání není platný.',
            );
        }
        $context = $this->registrations->findEmploymentContext(
            $supplierId,
            $employmentId,
        );
        if ($context === null) {
            throw new \OutOfBoundsException(
                'Pracovní vztah pro registraci nebyl nalezen.',
            );
        }

        return $context;
    }

    /** @param array<string,mixed> $context */
    private function effectiveDate(array $context): string
    {
        $date = $context['actual_start_date'] ?? $context['start_date'];
        if (!is_string($date) || $date === '') {
            throw new PayrollRegistrationXmlException(
                'registration_start_date_missing',
                'Pracovní vztah nemá datum nástupu. Doplňte ho v kartě pracovního vztahu — bez něj nelze určit lhůtu ani podat přihlášku.',
            );
        }

        return $date;
    }

    private function calendarBasis(string $basis): string
    {
        $mapped = self::CALENDAR_BASIS_MAP[$basis] ?? null;
        if ($mapped === null) {
            throw new PayrollRegistrationXmlException(
                'registration_calendar_basis_unsupported',
                'Kalendář registrační lhůty nemá odpovídající hodnotu v registru povinností.',
            );
        }

        return $mapped;
    }

    /**
     * @return array{earliest_registration_on:string,due_on:string,
     *   calendar_basis:string,ruleset_id:string}
     */
    private function describeDeadline(
        PayrollEmployeeRegistrationDeadlineWindow $window,
    ): array {
        return [
            'earliest_registration_on' => $window->earliestRegistrationOn,
            'due_on' => $window->dueOn,
            'calendar_basis' => $window->calendarBasis,
            'ruleset_id' => $window->rulesetId,
        ];
    }

    private function partReference(
        PayrollRegistrationInteraction $interaction,
        int $employmentId,
        ?int $eventId = null,
    ): string {
        return strtolower($interaction->documentType)
            . ':' . $employmentId
            . ($eventId === null ? '' : ':event:' . $eventId);
    }

    /** @param array<string,mixed> $event */
    private function eventFingerprint(array $event): string
    {
        return hash('sha256', CanonicalJson::encode($event));
    }

    /** @return array{submission:string,artifact:string} */
    private function idempotencyKeys(
        int $supplierId,
        string $environment,
        int $employmentId,
        string $sourceHash,
    ): array {
        $base = CanonicalJson::encode([
            'schema_reference' => 'payroll-registration-submission.v1',
            'supplier_id' => $supplierId,
            'environment' => $environment,
            'employment_id' => $employmentId,
            'source_hash' => $sourceHash,
        ]);

        return [
            'submission' => 'registration-submission:'
                . hash('sha256', $base),
            'artifact' => 'registration-artifact:' . hash('sha256', $base),
        ];
    }

    private function today(): string
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new \DateTimeZone('Europe/Prague'))
            ->format('Y-m-d');
    }
}
