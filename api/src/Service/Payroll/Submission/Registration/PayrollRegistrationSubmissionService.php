<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use MyInvoice\Repository\Payroll\PayrollRegistrationSubmissionRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecVersion;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionGuidFactory;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzSoftwareIdentification;
use MyInvoice\Service\Payroll\Submission\PayrollObligationService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionCalendar;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionStateMachine;
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
    public const EMPLOYMENT_REFERENCE_PREFIX = 'payroll_employment_registration:';
    public const CHECKLIST_PHASE = 'onboarding';
    public const CHECKLIST_ITEM_KEY = 'social_jmhz_registration';

    private const CHANNEL = 'vrep_apep';

    /**
     * Základní interakce, které vztah PŘIHLAŠUJÍ (P1, A1). Po živé přihlášce
     * se druhá nezakládá; P10 (nenastoupení) po přijaté P1 je naopak
     * zamýšlený další krok, proto v seznamu není.
     */
    private const REGISTERING_INTERACTIONS = [
        'limited_pre_registration',
        'direct_full_registration',
        'full_registration_after_p1',
    ];
    private const SUBJECT_TYPE = 'employment';

    /** Důvod náhradní lhůty: nástup nebyl předem znám (§ 19 odst. 1 písm. b). */
    private const DEADLINE_BASIS_UNKNOWN_START = 'start_not_known_in_advance';

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
        private JmhzSoftwareIdentification $software,
        private ClockInterface $clock,
    ) {}

    /**
     * Test: ukáže, co by se podalo, a nezaloží nic. GUIDy jsou zahozené,
     * proto se výsledek nesmí použít jako doklad o podání.
     *
     * @return array{
     *   employment_id:int,agenda_code:string,interaction:string,
     *   action_code:int,xml:string,xml_sha256:string,
     *   schema_validation:array{valid:bool,schema_version:string},
     *   deadline:array{earliest_registration_on:string,due_on:string,
     *     calendar_basis:string,ruleset_id:string},
     *   employer_registration:?array<string,string>,
     *   warnings:list<array{code:string,field:string,message:string,target:string}>,
     *   official_submission:array{supported:bool,reason:string}
     * }
     */
    public function preview(
        int $supplierId,
        string $environment,
        int $employmentId,
        ?int $eventId = null,
        bool $fullRegistrationRequested = false,
        bool $startNotKnownInAdvance = false,
    ): array {
        $resolved = $this->resolve(
            $supplierId,
            $environment,
            $employmentId,
            0,
            $eventId,
            $fullRegistrationRequested,
            $startNotKnownInAdvance,
        );

        $variableSymbolWarning = EmployerVariableSymbolPlausibility::warning(
            $resolved['employer_variable_symbol'],
        );

        return [
            'employment_id' => $employmentId,
            'agenda_code' => $resolved['interaction']->documentType,
            'interaction' => $resolved['interaction']->interaction,
            'action_code' => $resolved['interaction']->actionCode,
            'before_start_choice' => $resolved['before_start_choice'],
            'xml' => $resolved['xml'],
            'xml_sha256' => hash('sha256', $resolved['xml']),
            /*
             * Test XML před odesláním: {@see self::resolve()} každé XML ověří
             * proti připnutému XSD ČSSZ a hranicím agendy (nevalidní skončí
             * 422 dřív, než se sem dojde). Náhled to účetní řekne, ať XML
             * neověřuje ručně (průchod agentury). Připravené podání pak nese
             * totéž ověřené XML beze změny.
             */
            'schema_validation' => [
                'valid' => true,
                'schema_version' => $resolved['schema_version'],
            ],
            'deadline' => $this->describeDeadline($resolved['deadline']),
            'employer_registration' => $resolved['employer_deadline'],
            // Neblokuje, jen upozorní: náhled je poslední místo, kde účetní
            // XML vidí dřív, než odejde.
            'warnings' => array_values(array_filter([
                $variableSymbolWarning,
                $this->pairingReminder(
                    $supplierId,
                    $environment,
                    $employmentId,
                    $resolved['interaction'],
                ),
            ])),
            'official_submission' => [
                'supported' => false,
                'reason' => 'Tohle je jen náhled: podání se nezakládá '
                    . 'a na ČSSZ se nic neodesílá. Odeslat půjde až '
                    . 'připravené podání.',
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function listEvents(
        int $supplierId,
        string $environment,
        int $employmentId,
    ): array {
        $this->requireContext($supplierId, $employmentId, $environment);

        return $this->events->list($supplierId, $environment, $employmentId);
    }

    /** @return array<string,mixed> */
    public function a2EvidenceCandidates(
        int $supplierId,
        string $environment,
        int $employmentId,
        string $effectiveOn,
    ): array {
        $this->requireContext($supplierId, $employmentId, $environment);

        return $this->events->a2EvidenceCandidates(
            $supplierId,
            $environment,
            $employmentId,
            $effectiveOn,
        );
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function approveEvent(
        int $supplierId,
        string $environment,
        int $employmentId,
        array $input,
        int $approvedBy,
    ): array {
        $this->requireContext($supplierId, $employmentId, $environment);

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
     * S `$replacesSubmissionId` připraví ze stejné události novou větu
     * místo zmrazené změny, jejíž ročník mezitím zastaral
     * ({@see PayrollRegistrationSchemaCatalog::changeVintageExpired()});
     * původní podání se včas zruší (`cancelled_in_time`).
     *
     * @return array{
     *   submission_id:int,obligation_id:int,part_id:int,artifact_id:int,
     *   status:string,row_version:int,environment:string,agenda_code:string,
     *   interaction:string,artifact_sha256:string,created:bool,
     *   vintage_expired:bool,
     *   deadline:array{earliest_registration_on:string,due_on:string,
     *     calendar_basis:string,ruleset_id:string},
     *   problems:list<array{field:?string,code:string,message:string}>
     * }
     */
    public function prepare(
        int $supplierId,
        string $environment,
        int $employmentId,
        ?int $createdBy = null,
        ?int $eventId = null,
        bool $fullRegistrationRequested = false,
        bool $startNotKnownInAdvance = false,
        ?int $replacesSubmissionId = null,
    ): array {
        if ($replacesSubmissionId !== null && $eventId === null) {
            throw new PayrollRegistrationXmlException(
                'registration_reprepare_not_applicable',
                'Znovu se připravuje jen změna nebo oprava ze schválené'
                    . ' události. Vyberte událost a podání připravte znovu.',
            );
        }
        // Povinnost a lhůta vznikají mimo transakci podání a nezávisle na tom,
        // jestli se podání povede připravit. Kdyby vznikaly až spolu s ním,
        // neúspěšná příprava by po sobě nenechala ani stopu po termínu.
        $context = $this->requireContext($supplierId, $employmentId, $environment);
        $problems = [];
        if ($eventId === null) {
            $problem = $this->registerEmployerObligation(
                $supplierId,
                $environment,
                $context,
                $createdBy,
            );
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }
        $probe = $this->resolve(
            $supplierId,
            $environment,
            $employmentId,
            0,
            $eventId,
            $fullRegistrationRequested,
            $startNotKnownInAdvance,
        );
        $variableSymbolWarning = EmployerVariableSymbolPlausibility::warning(
            $probe['employer_variable_symbol'],
        );
        if ($variableSymbolWarning !== null) {
            $problems[] = [
                'field' => $variableSymbolWarning['field'],
                'code' => $variableSymbolWarning['code'],
                'message' => $variableSymbolWarning['message'],
            ];
        }
        $pairing = $this->pairingReminder(
            $supplierId,
            $environment,
            $employmentId,
            $probe['interaction'],
        );
        if ($pairing !== null) {
            $problems[] = [
                'field' => $pairing['field'],
                'code' => $pairing['code'],
                'message' => $pairing['message'],
            ];
        }
        $replacedEvent = $replacesSubmissionId === null || $eventId === null
            ? null
            : $this->events->load($supplierId, $environment, $employmentId, $eventId);
        if ($replacesSubmissionId !== null) {
            // Ověří se dřív, než vznikne nová povinnost: bez zastaralého
            // podání by po sobě nechala povinnost bez podání.
            $this->requireExpiredChange(
                $supplierId,
                $environment,
                $employmentId,
                $replacesSubmissionId,
                $probe['source_hash'],
                $probe,
                $replacedEvent,
            );
        }
        // Po odmítnuté (nahrazené, včas zrušené) přihlášce A1/P1 přichází nové
        // řádné podání s vlastní povinností a klíčem; bez toho by příprava
        // vrátila odmítnuté podání, nebo narazila na jediné řádné podání
        // povinnosti. Živou přihlášku dál hlídá assertNoOtherLiveBaseRegistration().
        $retriesSubmissionId = $eventId === null
            ? $this->registrations->latestClosedBaseRegistrationId(
                $supplierId,
                $environment,
                $employmentId,
            )
            : null;
        $obligation = $this->registerObligation(
            $supplierId,
            $environment,
            $context,
            $probe,
            $createdBy,
            $replacesSubmissionId,
            $retriesSubmissionId,
        );

        $result = $this->submissionRepository->transaction(function () use (
            $supplierId,
            $environment,
            $employmentId,
            $createdBy,
            $probe,
            $obligation,
            $retriesSubmissionId,
            $eventId,
            $problems,
            $fullRegistrationRequested,
            $startNotKnownInAdvance,
            $replacesSubmissionId,
            $replacedEvent,
        ): array {
            if (!$this->submissionRepository->lockSupplier($supplierId)) {
                // Výjimka zůstává: chybí firma, za kterou by se podávalo,
                // a zároveň jde o hranici mezi firmami. Není co uložit ani
                // co vypsat jako chybějící údaj.
                throw new PayrollRegistrationXmlException(
                    'registration_supplier_missing',
                    'Firma, za kterou se registrace podává, nebyla nalezena. '
                    . 'Přepněte se na správnou firmu a registraci připravte '
                    . 'znovu.',
                );
            }
            if ($eventId !== null) {
                $this->events->assertA2EvidenceCurrent(
                    $supplierId,
                    $environment,
                    $employmentId,
                    $eventId,
                );
            }
            $sourceHash = $probe['source_hash'];
            if ($eventId === null && in_array(
                $probe['interaction']->interaction,
                self::REGISTERING_INTERACTIONS,
                true,
            )) {
                $this->assertNoOtherLiveBaseRegistration(
                    $supplierId,
                    $environment,
                    $employmentId,
                    $probe['interaction']->documentType,
                    $sourceHash,
                    $probe['interaction']->interaction,
                    $probe['snapshot']->employmentExternalIdentifier['value'] ?? null,
                );
            }
            $replaced = $replacesSubmissionId === null
                ? null
                : $this->requireExpiredChange(
                    $supplierId,
                    $environment,
                    $employmentId,
                    $replacesSubmissionId,
                    $sourceHash,
                    $probe,
                    $replacedEvent,
                );
            $keys = $this->idempotencyKeys(
                $supplierId,
                $environment,
                $employmentId,
                $sourceHash,
                $replacesSubmissionId,
                $retriesSubmissionId,
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
                    $eventId,
                ) + ['problems' => $problems];
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
                $fullRegistrationRequested,
                $startNotKnownInAdvance,
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
                // Výjimka zůstává: porušená integrita dat. Uloží se přesně
                // ty bajty, které se pak odešlou ČSSZ — kdyby se rozešly,
                // účetní by odsouhlasila jiný dokument, než jaký odejde.
                throw new PayrollRegistrationXmlException(
                    'registration_artifact_mismatch',
                    'Uložené podání neodpovídá tomu, které aplikace právě '
                    . 'připravila, proto se registrace neuložila. Zkuste '
                    . 'přípravu znovu; pokud se hláška vrátí, jde o chybu '
                    . 'aplikace a podání se odesílat nesmí.',
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
            if ($replaced !== null) {
                // Zastaralá věta se neodešle nikdy, nahrazuje ji právě
                // připravená. Ruší se ve stejné transakci, aby nezůstala dvě
                // odesílatelná podání téže události.
                $this->submissions->transition(
                    $supplierId,
                    $replaced['submission_id'],
                    $replaced['row_version'],
                    'cancelled_in_time',
                );
            }
            // Neodvozená lhůta (nástup před 1. 7. 2026) do checklistu nejde —
            // checklist pro ni termín záměrně nevede (PayrollChecklistDeadlinePolicy).
            if ($eventId === null && $frozen['deadline']->derived) {
                $this->registrations->setChecklistDueDate(
                    $supplierId,
                    $employmentId,
                    self::CHECKLIST_PHASE,
                    self::CHECKLIST_ITEM_KEY,
                    $frozen['deadline']->dueOn,
                );
            } elseif ($frozen['deadline']->statutory && in_array(
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
                'before_start_choice' => $frozen['before_start_choice'],
                'artifact_sha256' => (string) $artifact['artifact_sha256'],
                'created' => true,
                'vintage_expired' => false,
                'replaced_submission_id' => $replaced['submission_id'] ?? null,
                'deadline' => $this->describeDeadline($frozen['deadline']),
                // Nedostatky, které se registrace zaměstnance netýkají, a tak
                // ji nesměly zablokovat. Prázdný seznam = všechno sedlo.
                'problems' => $problems,
            ];
        });
        if ($replacesSubmissionId === null
            && $eventId !== null
            && $result['created'] === false
            && $result['status'] === 'cancelled_in_time'
            && ($result['vintage_expired'] ?? false) === true
        ) {
            // Zastaralou větu už nahradila nová: opakovaná příprava téže
            // události vrátí tu novou, ne zrušenou.
            return $this->prepare(
                $supplierId,
                $environment,
                $employmentId,
                $createdBy,
                $eventId,
                $fullRegistrationRequested,
                $startNotKnownInAdvance,
                (int) $result['submission_id'],
            );
        }

        return $result;
    }

    /**
     * Obě výjimky níž jsou VNITŘNÍ pojistky, ne hlášky pro účetní: obě id
     * validují akce `PayrollRegistrationAction::employmentId()`
     * a `::eventId()` regulárním výrazem `^[1-9][0-9]*$` dřív, než se sem
     * cokoliv dostane. Přes API tedy nejsou dosažitelné a zůstávají technické.
     */
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

        return self::EMPLOYMENT_REFERENCE_PREFIX . $employmentId;
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
     *   employer_deadline:?array<string,string>,
     *   event_effective_on:?string,source_event_reference:string,
     *   employer_variable_symbol:string,before_start_choice:bool,
     *   deadline_basis:?string
     * }
     */
    private function resolve(
        int $supplierId,
        string $environment,
        int $employmentId,
        int $submissionId,
        ?int $eventId = null,
        bool $fullRegistrationRequested = false,
        bool $startNotKnownInAdvance = false,
    ): array {
        $context = $this->requireContext($supplierId, $employmentId, $environment);
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
            $fullRegistrationRequested,
        );
        // Dohlášení údajů jde i za vztah, který už skončil: do 10009 patří den
        // odeslání, identita se ale čte ke dni skončení (dál vztah neexistuje).
        $sourceOn = $effectiveOn;
        if ($event !== null
            && is_string($context['end_date'] ?? null)
            && $effectiveOn > $context['end_date']
        ) {
            $sourceOn = $context['end_date'];
        }
        $source = $this->identities->sensitiveSnapshotSourceAt(
            $supplierId,
            $context['employee_id'],
            $employmentId,
            $environment,
            $sourceOn,
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
        $agenda = $this->interactions->agendaFor(
            is_string($citizenship) ? $citizenship : null,
            $interactionContext,
        );
        $this->assertComplete(
            $source,
            $context,
            $event,
            // Bez občanství se mezi PREZEC a REGZEC rozhodnout nedá, ledaže
            // jde o událost A2–A8, která je vždycky REGZEC.
            is_string($citizenship) || $event !== null ? $agenda : null,
        );
        $snapshot = $this->snapshots->build(
            $this->scope(
                $supplierId,
                $environment,
                $employmentId,
                $context,
                $submissionId,
                $agenda,
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
                // Výjimka zůstává: porušená integrita dat. Kdyby podání
                // odešlo s jiným ID PPV, ČSSZ by změnu přiřadila k cizímu
                // pracovnímu vztahu a oprava se dohledává těžko.
                throw new PayrollRegistrationXmlException(
                    'registration_event_id_ppv_snapshot_mismatch',
                    PayrollRegistrationFieldVocabulary::label(
                        'employment_external_identifier',
                    )
                    . ' uložený u schválené události se liší od toho, který '
                    . 'má zaměstnanec ke dni události. Zkontrolujte údaj na '
                    // Ne `describe()`: tady údaj nechybí, jen nesedí, takže
                    // věta „Údaj doplňte na …" by mířila vedle. Konstanta
                    // drží cestu v souladu se zbytkem řetězce.
                    . PayrollRegistrationFieldVocabulary::WHERE_JMHZ_IDENTIFIERS
                    . ' a událost schvalte znovu'
                    . PayrollRegistrationFieldVocabulary::reference(
                        'employment_external_identifier',
                    )
                    . '.',
                );
            }
        }
        $interaction = $this->interactions->resolve(
            $snapshot,
            $interactionContext,
        );
        $referencedGuid = $this->referencedPreRegistrationGuid(
            $supplierId,
            $environment,
            $employmentId,
            $interaction,
        );
        $preRegistration = $this->acceptedPreRegistrationFiling(
            $supplierId,
            $environment,
            $employmentId,
            $interaction,
        );
        if ($preRegistration !== null
            && $interaction->interaction === 'full_registration_after_p1'
            && $preRegistration->startIsTooLate($effectiveOn)
        ) {
            throw new PayrollRegistrationXmlException(
                'registration_a1_after_p1_start_too_late',
                'Zaměstnanec nastoupil ' . $effectiveOn . ', tedy později než '
                    . 'osm dnů po předpokládaném dni nástupu uvedeném v přijatém '
                    . 'částečném přihlášení (' . $preRegistration->expectedStartOn
                    . '). Plnou registraci A1 se skutečným datem nástupu už dohlásit '
                    . 'nejde: podejte oznámení o nenastoupení (PREZEC P2) a nové '
                    . 'přihlášení (P1 nebo A1) s novým předpokládaným dnem nástupu.'
                    . PayrollRegistrationFieldVocabulary::reference('planned_start_on'),
            );
        }
        $deadlineBasis = $this->deadlineBasis(
            $startNotKnownInAdvance,
            $interaction,
            $event,
            $effectiveOn,
        );
        // Ročník formuláře určuje rozhodné datum události (nástup, skončení,
        // platnost změny): od 1. 1. 2027 REGZEC27. U A3/A4 bez data skončení
        // rozhoduje den vyhotovení (PayrollRegistrationSchemaCatalog::versionFor()).
        // PREZEC ročník nemění (viz PayrollRegistrationSchemaCatalog::documentTypeFor()).
        $eventData = is_array($event['data'] ?? null) ? $event['data'] : null;
        $decisiveOn = PayrollRegistrationSchemaCatalog::decisiveDate(
            $interaction->actionCode,
            $effectiveOn,
            $eventData,
        );
        $form = (new PayrollRegistrationSchemaCatalog())->documentTypeFor(
            $interaction->documentType,
            $decisiveOn,
            PayrollRegistrationSchemaCatalog::versionFor(
                $interaction->actionCode,
                $effectiveOn,
                $eventData,
                $this->today(),
            ),
        );
        $specVersion = $form === 'REGZEC27'
            ? JmhzSpecVersion::V2027
            : JmhzSpecVersion::V2026;
        // U přihlášky k budoucímu nástupu rozhoduje jediný den pro obě
        // pravidla (osm dnů před nástupem, příjem REGZEC27); hlásí ho kontrola
        // okna jednou větou, brána ročníku by k němu přidala druhé datum.
        $registersBeforeStart = $event === null
            && in_array($interaction->interaction, self::REGISTERING_INTERACTIONS, true)
            && PayrollRegistrationEarliestFiling::tooEarly($effectiveOn, $this->today());
        if (!$registersBeforeStart) {
            $this->assertFormAccepted($form, $environment, $decisiveOn);
        }
        $payload = $this->payload(
            $snapshot,
            $interaction,
            $context,
            $effectiveOn,
            $event,
            $referencedGuid,
            $interaction->interaction === 'pre_registration_no_show'
                ? $preRegistration?->variableSymbol
                : null,
            $specVersion,
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
            // Ukončení předregistrace (P2) staví na týchž údajích osoby jako
            // P1, takže bez rozlišení by obě podání měla stejný idempotenční
            // klíč a P2 by skončila konfliktem se zmrazenou P1. Rozlišuje se
            // jen P2 (a GUID P1, na kterou se odkazuje); otisk P1 a A1 zůstává
            // beze změny, aby se už zmrazená podání dál poznala jako opakování.
            'source_hash' => $event === null
                ? hash('sha256', CanonicalJson::encode([
                    'identity' => $snapshot->toArray(),
                    'event_fingerprint' => null,
                ] + ($referencedGuid === null ? [] : [
                    'interaction' => $interaction->interaction,
                    'referenced_form_guid' => $referencedGuid,
                ])))
                : $this->eventFingerprint($event),
            'schema_version' => $form,
            'deadline' => $this->deadlineFor(
                $interaction,
                $context,
                $event === null
                    ? $effectiveOn
                    : (string) ($event['notification_trigger_on'] ?? ''),
                $event,
                $deadlineBasis,
            ),
            'deadline_basis' => $deadlineBasis,
            'employer_deadline' => $event === null
                ? $this->employerDeadline($context)
                : null,
            'event_effective_on' => $event === null ? null : $effectiveOn,
            'employer_variable_symbol' => $payload->employerVariableSymbol,
            'before_start_choice' => $event === null
                && !$interactionContext['work_started']
                && !$interactionContext['did_not_start']
                && !$interactionContext['employment_ended']
                && $citizenship === 'CZ',
            'source_event_reference' => self::sourceEventReference(
                $employmentId,
                $eventId,
            ),
        ];
    }

    /**
     * Všechno, co registraci chybí, NAJEDNOU.
     *
     * Dřív se chybějící údaje hlásily po jednom tak, jak na ně narazil
     * serializér: po doplnění občanství vyskočilo rodné příjmení, pak místo
     * narození, pak variabilní symbol. Účetní tak na jednu přihlášku chodila
     * na kartu osoby čtyřikrát. Seznam požadavků drží
     * {@see PayrollRegistrationIdentityRequirements}; tady se k němu přidají
     * údaje zaměstnavatele, které čte {@see self::payload()}.
     *
     * Chybí-li identita úplně, mlčí se: to hlásí snapshot vlastní větou.
     *
     * @param array<string,mixed> $source
     * @param array<string,mixed> $context
     * @param array<string,mixed>|null $event
     */
    private function assertComplete(
        array $source,
        array $context,
        ?array $event,
        ?string $agenda,
    ): void {
        $identity = $source['identity'] ?? null;
        $identifiers = $source['identifiers'] ?? null;
        $eventAction = is_array($event) && isset($event['action_code'])
            ? (int) $event['action_code']
            : null;
        $completion = is_array($event['data'] ?? null)
            && is_string($event['data']['completion'] ?? null)
            ? $event['data']['completion']
            : null;
        $problems = is_array($identity)
            ? PayrollRegistrationIdentityRequirements::missing(
                $agenda,
                $identity,
                is_array($identifiers) ? $identifiers : [],
                null,
                $eventAction,
                $completion,
                $this->today(),
            )
            : [];

        $eventEmployer = is_array($event['employer'] ?? null)
            ? $event['employer']
            : null;
        $variableSymbol = $eventEmployer['variable_symbol']
            ?? $context['employer_variable_symbol'];
        if (!is_string($variableSymbol) || $variableSymbol === '') {
            $problems[] = PayrollRegistrationIdentityRequirements::employerProblem(
                'employer_variable_symbol',
                'chybí u mzdové účtárny, pod kterou pracovní vztah patří, '
                . 'a bez něj ČSSZ neví, komu zaměstnance přihlásit.',
            );
        }
        $workplaceCode = $eventEmployer['workplace_code']
            ?? $context['cssz_workplace_code'];
        if ($agenda === self::AGENDA_REGZEC
            && (!is_string($workplaceCode) || $workplaceCode === '')
        ) {
            $problems[] = PayrollRegistrationIdentityRequirements::employerProblem(
                'cssz_workplace_code',
                'chybí; v nastavení se položka jmenuje „Kód správy '
                . 'sociálního zabezpečení".',
            );
        }
        if ($agenda === self::AGENDA_REGZEC
            && is_string($workplaceCode)
            && $workplaceCode !== ''
            && !PayrollCsszDistrictCodebook::contains($workplaceCode)
        ) {
            $problems[] = PayrollRegistrationIdentityRequirements::employerProblem(
                'cssz_workplace_code',
                '(' . $workplaceCode . ') není v číselníku okresních správ ČSSZ, '
                . 'takže ho ČSSZ odmítne; v nastavení se položka jmenuje '
                . '„Kód správy sociálního zabezpečení".',
            );
        }
        if ($problems === []) {
            return;
        }

        throw new PayrollRegistrationXmlException(
            'registration_data_incomplete',
            PayrollRegistrationIdentityRequirements::summary($problems),
            $problems,
        );
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
        ?string $referencedFormGuid = null,
        ?string $preRegistrationVariableSymbol = null,
        JmhzSpecVersion $specVersion = JmhzSpecVersion::V2026,
    ): PayrollRegistrationXmlPayload {
        $eventEmployer = is_array($event['employer'] ?? null)
            ? $event['employer']
            : null;
        // PREZEC P2 nese stejný variabilní symbol jako přijatá P1 (PREZEC
        // Předregistrace 1.4, atribut 10221), ne ten, který zaměstnavatel
        // eviduje dnes.
        $variableSymbol = $preRegistrationVariableSymbol
            ?? $eventEmployer['variable_symbol']
            ?? $context['employer_variable_symbol'];
        if (!is_string($variableSymbol) || $variableSymbol === '') {
            // Výjimka zůstává, přestože jde o neúplný vstup: tahle metoda
            // neukládá, staví DATOVOU VĚTU pro ČSSZ. Bez variabilního symbolu
            // nevznikne ani náhled, ani zmrazené podání — není kam nedostatek
            // odložit, formulář pracovního vztahu se ukládá jinou cestou
            // a tímhle blokovaný není.
            throw new PayrollRegistrationXmlException(
                'registration_employer_variable_symbol_missing',
                PayrollRegistrationFieldVocabulary::label(
                    'employer_variable_symbol',
                )
                . ' chybí u mzdové účtárny, pod kterou pracovní vztah patří, '
                . 'a bez něj ČSSZ neví, komu zaměstnance přihlásit. '
                . PayrollRegistrationFieldVocabulary::describe(
                    'employer_variable_symbol',
                )
                . ' Potom registraci připravte znovu'
                . PayrollRegistrationFieldVocabulary::reference(
                    'employer_variable_symbol',
                )
                . '.',
            );
        }
        $regzec = $interaction->documentType === self::AGENDA_REGZEC;
        if ($regzec && $context['cssz_workplace_code'] === null) {
            // Výjimka zůstává ze stejného důvodu jako výš: plná datová věta
            // REGZEC bez kódu pracoviště nejde sestavit.
            throw new PayrollRegistrationXmlException(
                'registration_cssz_workplace_code_missing',
                PayrollRegistrationFieldVocabulary::label(
                    'cssz_workplace_code',
                )
                . ' chybí, a bez něj nejde podat „'
                . PayrollRegistrationFieldVocabulary::action(
                    $interaction->documentType,
                    $interaction->actionCode,
                )
                . '". '
                . PayrollRegistrationFieldVocabulary::describe(
                    'cssz_workplace_code',
                )
                . ' V nastavení se položka jmenuje „Kód správy sociálního '
                . 'zabezpečení". Potom registraci připravte znovu'
                . PayrollRegistrationFieldVocabulary::reference(
                    'cssz_workplace_code',
                )
                . '.',
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
            productName: $this->software->productName,
            productVersion: $this->software->productVersion,
            csszWorkplaceCode: $regzec
                ? (string) ($eventEmployer['workplace_code']
                    ?? $context['cssz_workplace_code'])
                : null,
            eventSnapshot: $event,
            referencedFormGuid: $referencedFormGuid,
            specVersion: $specVersion,
        );
    }

    /**
     * REGZEC27 přijímá ostrá ČSSZ od 23. 12. 2026 (ČSSZ, Otázky a odpovědi
     * k NV2027, odpověď 5: „REGZEC 2027 tak bude umožněno posílat již ode dne
     * 23. 12. 2026", aby šlo přihlásit před nástupem 1. 1. 2027). REGZEC25
     * s rozhodným datem od 1. 1. 2027 ČSSZ zamítne (EDV 1.4.0.7, Slovník,
     * záhlaví REGZEC 2025), takže dřív takovou událost podat nejde vůbec.
     * Testovací prostředí ČSSZ přijímá REGZEC27 už teď (fáze 2 od 2. 11. 2026).
     */
    private function assertFormAccepted(string $form, string $environment, string $decisiveOn): void
    {
        if ($form !== 'REGZEC27'
            || $environment !== 'production'
            || $this->today() >= JmhzSpecVersion::FIRST_REGZEC27_ACCEPTED_ON
        ) {
            return;
        }
        // Přihlášku k budoucímu nástupu sem volající nepustí: její jediný den
        // hlásí {@see PayrollRegistrationEarliestFiling}. Zbývají události
        // (odhláška, změna) s rozhodným datem v roce 2027.
        throw new PayrollRegistrationXmlException(
            'registration_regzec27_not_yet_accepted',
            'Událost s rozhodným datem '
                . (new \DateTimeImmutable($decisiveOn))->format('j. n. Y')
                . ' se hlásí formulářem REGZEC27 (specifikace JMHZ 2027), který'
                . ' ostrá ČSSZ přijímá až od 23. 12. 2026. Starší formulář REGZEC25'
                . ' ČSSZ pro takovou událost odmítne. Podání připravte a odešlete'
                . ' 23. 12. 2026 nebo později.',
        );
    }

    /**
     * GUID přijaté PREZEC P1 pro ukončení předregistrace (P2), jinak null.
     * Bez doložené reference se P2 nesestaví: ČSSZ by ji nespárovala s P1
     * a předregistrace by zůstala otevřená (PREZEC 1.4, atribut 10012).
     */
    private function referencedPreRegistrationGuid(
        int $supplierId,
        string $environment,
        int $employmentId,
        PayrollRegistrationInteraction $interaction,
    ): ?string {
        if ($interaction->documentType !== self::AGENDA_PREZEC
            || $interaction->actionCode !== 10
        ) {
            return null;
        }
        $guid = $this->registrations->acceptedPreRegistrationGuid(
            $supplierId,
            $environment,
            $employmentId,
            self::AGENDA_PREZEC,
        );
        if ($guid === null) {
            throw new PayrollRegistrationXmlException(
                'registration_prezec_p1_guid_missing',
                'Oznámení o nenastoupení (PREZEC P2) se musí odkázat na GUID '
                    . 'původního částečného přihlášení (P1), ale v protokolu '
                    . 'ČSSZ k přijaté P1 se GUID formuláře nenašel. Načtěte '
                    . 'nejdřív protokol k P1 (Podání, odpověď ČSSZ); bez '
                    . 'správného GUID ČSSZ ukončení předregistrace '
                    . 'nezpracuje.',
            );
        }

        return $guid;
    }

    /**
     * Přijatá P1 přečtená z archivu, ale jen tam, kde se na ni navazuje
     * (P2 a plná registrace po P1). Poškozený archivovaný soubor podání
     * zablokuje: variabilní symbol P2 a okno pro A1 by nešly doložit.
     */
    private function acceptedPreRegistrationFiling(
        int $supplierId,
        string $environment,
        int $employmentId,
        PayrollRegistrationInteraction $interaction,
    ): ?PayrollPreRegistrationFiling {
        if (!in_array($interaction->interaction, [
            'pre_registration_no_show',
            'full_registration_after_p1',
        ], true)) {
            return null;
        }
        $artifactId = $this->registrations->acceptedFilingArtifactId(
            $supplierId,
            $environment,
            $employmentId,
            self::AGENDA_PREZEC,
        );
        if ($artifactId === null) {
            // Přijatá P1 bez archivovaného souboru (převzatá z jiného zdroje):
            // není z čeho číst, podání se opírá o dosavadní údaje.
            return null;
        }
        $filing = PayrollPreRegistrationFiling::fromXml(
            $this->submissions->artifactBytes($supplierId, $artifactId),
        );
        if ($filing === null) {
            throw new PayrollRegistrationXmlException(
                'registration_prezec_p1_archive_unreadable',
                'Z archivu přijatého částečného přihlášení (PREZEC P1) se '
                    . 'nepodařilo přečíst variabilní symbol a předpokládaný '
                    . 'den nástupu, na které navazující podání musí odkazovat. '
                    . 'Zkontrolujte u vztahu odeslané podání P1.',
            );
        }

        return $filing;
    }

    /**
     * Náhradní lhůta "nástup nebyl předem znám" se týká jen přímé plné
     * registrace A1 po nástupu; jinde (P1, A1 po P1, události) nedává smysl.
     *
     * @param array<string,mixed>|null $event
     */
    private function deadlineBasis(
        bool $startNotKnownInAdvance,
        PayrollRegistrationInteraction $interaction,
        ?array $event,
        string $startOn,
    ): ?string {
        if (!$startNotKnownInAdvance) {
            return null;
        }
        if ($event !== null || $interaction->interaction !== 'direct_full_registration') {
            throw new PayrollRegistrationXmlException(
                'registration_unknown_start_not_applicable',
                'Náhradní lhůta pro zaměstnance, jehož nástup nebyl předem '
                    . 'znám, se týká jen přímé plné registrace (REGZEC A1) '
                    . 'bez předchozího částečného přihlášení. U tohoto podání '
                    . 'ji nevolte.',
            );
        }
        if ($startOn > $this->today()) {
            throw new PayrollRegistrationXmlException(
                'registration_unknown_start_in_future',
                'Nástup ' . $startOn . ' teprve nastane, takže byl předem '
                    . 'znám - náhradní lhůta (osm dnů od prvního plnění) se '
                    . 'volí jen u zaměstnance, který už začal pracovat bez '
                    . 'ohlášení. Přihlaste ho před nástupem.',
            );
        }

        return self::DEADLINE_BASIS_UNKNOWN_START;
    }

    /**
     * Upozornění na dvojici podání, která u nenastoupivšího zaměstnance patří
     * k sobě: podal-li zaměstnavatel P1 i A1, musí podat P2 i A8 (A1 předregistraci
     * neuzavírá, P2 registraci nestornuje). Neblokuje, jen připomíná.
     *
     * @return array{code:string,field:string,message:string,target:string}|null
     */
    private function pairingReminder(
        int $supplierId,
        string $environment,
        int $employmentId,
        PayrollRegistrationInteraction $interaction,
    ): ?array {
        if ($interaction->interaction === 'pre_registration_no_show'
            && $this->registrations->hasAcceptedPreRegistration(
                $supplierId,
                $environment,
                $employmentId,
                self::AGENDA_REGZEC,
            )
        ) {
            return [
                'code' => 'registration_no_show_needs_cancellation',
                'field' => 'planned_start_on',
                'message' => 'U tohoto vztahu je přijatá i plná registrace '
                    . '(REGZEC A1), která předregistraci neuzavírá: kromě '
                    . 'oznámení o nenastoupení (P2) je potřeba podat také '
                    . 'storno přihlášení (REGZEC A8) s důvodem "zaměstnanec '
                    . 'nenastoupil".',
                'target' => PayrollRegistrationIdentityRequirements::TARGET_PERSON,
            ];
        }
        if ($interaction->documentType === self::AGENDA_REGZEC
            && $interaction->actionCode === 8
            && $this->registrations->acceptedFilingCount(
                $supplierId,
                $environment,
                $employmentId,
                self::AGENDA_PREZEC,
            ) === 1
        ) {
            return [
                'code' => 'registration_cancellation_needs_no_show',
                'field' => 'planned_start_on',
                'message' => 'U tohoto vztahu je přijaté i částečné přihlášení '
                    . '(PREZEC P1), které storno A8 neuzavírá: nenastoupil-li '
                    . 'zaměstnanec, je potřeba podat také oznámení o '
                    . 'nenastoupení (PREZEC P2).',
                'target' => PayrollRegistrationIdentityRequirements::TARGET_PERSON,
            ];
        }

        return null;
    }

    /**
     * Fakta pro resolver. `full_registration_data` potvrzuje jen základní
     * metadata zaměstnavatele, nikoli právní úplnost A1; úplnou variantní
     * sadu samostatně hlídá business matice a profil A1.
     *
     * Nástup NENÍ podmínkou plné registrace. § 19 odst. 1 písm. a) zákona
     * č. 323/2025 Sb. ukládá přihlásit zaměstnance „nejpozději před okamžikem
     * nástupu", nejdříve osm dnů předem, a to KAŽDÉHO — zásady REGZEC (verze
     * 18-06-2026) počítají s případem „předpokládané datum nástupu bylo
     * oznámeno akcí 1" (při jiném skutečném dni se pak podává oprava A4, při
     * nenastoupení storno A8). Dřívější podmínka „až po nástupu" zablokovala
     * přesně cizince, kterým hláška radila podat REGZEC před zahájením práce.
     * Českému zaměstnanci zůstává výchozí částečné přihlášení (PREZEC P1),
     * plnou registraci A1 volí zaměstnavatel výslovně
     * (`$fullRegistrationRequested`).
     *
     * @param array<string,mixed> $context
     * @return array{
     *   work_started:bool,full_registration_data:bool,
     *   pre_registration_accepted:bool,did_not_start:bool,
     *   employment_ended:bool,full_registration_requested:bool
     * }
     */
    private function interactionContext(
        int $supplierId,
        string $environment,
        array $context,
        ?array $event = null,
        bool $fullRegistrationRequested = false,
    ): array {
        $workStarted = $context['actual_start_date'] !== null
            || in_array(
                $context['status'],
                ['active', 'suspended', 'ended', 'archived'],
                true,
            );
        $employerMetadataComplete = $context['employer_name'] !== ''
            && $context['employer_variable_symbol'] !== null
            && $context['cssz_workplace_code'] !== null;

        return [
            'work_started' => $workStarted,
            'full_registration_data' => $employerMetadataComplete,
            'full_registration_requested' => $fullRegistrationRequested
                && $event === null,
            'pre_registration_accepted' =>
                $this->registrations->hasAcceptedPreRegistration(
                    $supplierId,
                    $environment,
                    $context['employment_id'],
                    self::AGENDA_PREZEC,
                ),
            'did_not_start' => $context['status'] === 'no_show',
            // Sjednaný konec dohody nebo pracovního poměru na dobu určitou je
            // v `end_date` od založení vztahu. Skončil ale vztah až tím dnem:
            // dokud konec nenastal, je to trvající vztah a přihláška A1 se za
            // něj podává normálně (bez data skončení, EDV ID 10224: „nesmí
            // být > Datum vyplnění").
            'employment_ended' => in_array(
                $context['status'],
                ['ended', 'archived'],
                true,
            ) || ($context['end_date'] !== null && $context['end_date'] < $this->today()),
            'event_interaction' => is_string($event['interaction'] ?? null)
                ? $event['interaction']
                : null,
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed>|null $event
     */
    private function deadlineFor(
        PayrollRegistrationInteraction $interaction,
        array $context,
        ?string $effectiveOn = null,
        ?array $event = null,
        ?string $deadlineBasis = null,
    ): PayrollEmployeeRegistrationDeadlineWindow {
        // Oprávnění cizince zmrazené k A2/A8: lhůta úřadu práce podle § 88
        // odst. 1 zákona o zaměstnanosti může být kratší než osm dnů ČSSZ.
        $permit = is_array($event['data']['foreign_permit'] ?? null)
            ? $event['data']['foreign_permit']
            : null;
        if ($interaction->documentType === self::AGENDA_REGZEC
            && $interaction->actionCode === 8
            && $effectiveOn !== null
        ) {
            $notStarted = ($event['data']['not_started'] ?? true) !== false;

            return $this->deadlines->withForeignPermit(
                $this->deadlines->forCancellation($notStarted, $effectiveOn),
                8,
                $notStarted,
                $permit,
                $effectiveOn,
            );
        }
        if ($interaction->documentType === self::AGENDA_REGZEC
            && $interaction->actionCode >= 2
        ) {
            $triggerOn = $effectiveOn
                ?? (string) ($context['end_date']
                    ?? $this->effectiveDate($context));

            return $this->deadlines->withForeignPermit(
                $this->deadlines->forFollowUp($interaction->actionCode, $triggerOn),
                $interaction->actionCode,
                false,
                $permit,
                $triggerOn,
            );
        }
        $startOn = $this->effectiveDate($context);
        if ($deadlineBasis === self::DEADLINE_BASIS_UNKNOWN_START) {
            return $this->deadlines->forUnknownStart($startOn);
        }

        return match ($interaction->interaction) {
            'pre_registration_no_show' => $this->deadlines->forNoShow($startOn),
            // Doplnění po předregistraci má vlastní lhůtu: osm dnů PO nástupu.
            // Dokud spadalo do přihlášky, byl termínem den nástupu a aplikace
            // hlásila zpoždění, které nenastalo — a to zrovna tam, kde
            // předregistrace existuje právě proto, že údaje ještě nejsou.
            'full_registration_after_p1' => $this->deadlines
                ->forFullRegistrationAfterPreRegistration($startOn),
            default => $this->deadlines->forEmploymentStart($startOn),
        };
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
        ?int $replacesSubmissionId = null,
        ?int $retriesSubmissionId = null,
    ): array {
        $interaction = $resolved['interaction'];
        $window = $resolved['deadline'];
        $reference =
            PayrollRegistrationSubmissionRepository::employmentReference(
                $context['employment_id'],
            );
        // Nová věta místo zastaralé změny potřebuje vlastní povinnost: na
        // jednu povinnost připadá jediné řádné podání
        // (uq_payroll_submissions_regular). Původní povinnost se zrušením
        // zastaralého podání překlopí do `cancelled`.
        $sourceHash = hash('sha256', CanonicalJson::encode([
            'schema_reference' => 'payroll-registration-obligation.v1',
            'employment_id' => $context['employment_id'],
            'agenda_code' => $interaction->documentType,
            'interaction' => $interaction->interaction,
            'effective_on' => $resolved['event_effective_on']
                ?? $this->effectiveDate($context),
        ] + (($resolved['deadline_basis'] ?? null) === null
            ? []
            : ['deadline_basis' => $resolved['deadline_basis']])
          + ($replacesSubmissionId === null
            ? []
            : ['replaces_submission_id' => $replacesSubmissionId])
          + ($retriesSubmissionId === null
            ? []
            : ['retries_submission_id' => $retriesSubmissionId])));

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
     * NEBLOKUJE registraci zaměstnance. Je to povinnost NĚKOHO JINÉHO (firmy),
     * evidovaná při té příležitosti — když ji registr povinností odmítne,
     * nesmí tím spadnout přihláška zaměstnance, u které běží zákonná lhůta.
     * Odmítnutí se proto vrací jako nedostatek k vypsání, ne jako výjimka.
     * Chyby databáze se ale nechytají: ty znamenají, že se nezaložilo nic,
     * a musí být vidět.
     *
     * @param array<string,mixed> $context
     * @return array{field:?string,code:string,message:string}|null
     */
    private function registerEmployerObligation(
        int $supplierId,
        string $environment,
        array $context,
        ?int $createdBy,
    ): ?array {
        $employer = $this->employerDeadline($context);
        if ($employer === null) {
            return null;
        }
        $window = $this->employerDeadlines->forFirstEmployeeStart(
            $this->effectiveDate($context),
        );
        $sourceHash = hash('sha256', CanonicalJson::encode([
            'schema_reference' => 'payroll-employer-registration-obligation.v1',
            'first_employment_id' => $context['employment_id'],
            'expected_start_on' => $this->effectiveDate($context),
        ]));
        try {
            $this->obligations->register(
                $supplierId,
                self::AGENDA_EMPLOYER_REGISTRATION,
                'employer',
                'payroll_employer:' . $supplierId,
                $window->earliestRegistrationOn,
                $window->dueOn,
                'regular',
                // Přihláška zaměstnavatele se nepodává datovou větou, takže
                // kanál není `vrep_apep`. Označit ji tak by slibovalo
                // odeslání, které aplikace neumí.
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
        } catch (
            \DomainException
            | \InvalidArgumentException
            | PayrollRegistrationXmlException $exception
        ) {
            return [
                'field' => 'employer_registration',
                'code' => 'registration_employer_obligation_not_recorded',
                'message' => 'Přihlášku zaměstnavatele do evidence ČSSZ se '
                    . 'nepodařilo zaevidovat, takže termín '
                    . $employer['due_on']
                    . ' nikde nehlídáme. Přihlášku zaměstnance to '
                    . 'nezastavilo — ta je připravená. Důvod: '
                    . $exception->getMessage(),
            ];
        }

        return null;
    }

    /**
     * Živé základní podání (P1/A1) vztahu pro kartu po načtení: bez něj karta
     * po obnovení stránky nabízela „Připravit podání" znovu, i když přihláška
     * už čekala ve frontě nebo odešla.
     *
     * @return array{
     *   submission_id:int,agenda_code:string,status:string,created_at:string,
     *   submitted_at:?string,sent:bool
     * }|null
     */
    public function currentRegistration(
        int $supplierId,
        string $environment,
        int $employmentId,
    ): ?array {
        $this->requireContext($supplierId, $employmentId, $environment);
        $live = $this->registrations->liveBaseRegistration(
            $supplierId,
            $environment,
            $employmentId,
        );
        if ($live === null) {
            return null;
        }

        return [
            'submission_id' => $live['submission_id'],
            'agenda_code' => $live['agenda_code'],
            'status' => $live['status'],
            'created_at' => $live['created_at'],
            'submitted_at' => $live['submitted_at'],
            'sent' => !in_array(
                $live['status'],
                PayrollSubmissionStateMachine::PRE_SUBMISSION_STATUSES,
                true,
            ),
        ];
    }

    /**
     * Druhá přihláška téhož vztahu se nezakládá. Stejná data vedou na replay
     * existujícího podání (stejný otisk zdroje); s JINÝMI daty se dřív pokus
     * o druhé podání k téže povinnosti zastavil až na unikátním klíči
     * `uq_payroll_submissions_regular` jako chyba 500, bez rady, co dál.
     *
     * Vztah, který už má od ČSSZ ID PPV (převzatý z jiného programu nebo
     * načtený z protokolu), je u ČSSZ přihlášený, i když přihláška z aplikace
     * neodešla. Druhou A1 ČSSZ odmítne jako duplicitu nebo chybou 603/604
     * (zpětný převod Premier: šest takových odmítnutí). Po přijaté P1 se
     * A1 naopak podává, proto se pravidlo týká jen přímé plné registrace.
     */
    private function assertNoOtherLiveBaseRegistration(
        int $supplierId,
        string $environment,
        int $employmentId,
        string $agendaCode,
        string $sourceHash,
        string $interaction,
        mixed $employmentExternalIdentifier,
    ): void {
        $live = $this->registrations->liveBaseRegistration(
            $supplierId,
            $environment,
            $employmentId,
            $agendaCode,
        );
        if ($live === null
            && $interaction === 'direct_full_registration'
            && is_string($employmentExternalIdentifier)
            && trim($employmentExternalIdentifier) !== ''
        ) {
            throw new PayrollRegistrationXmlException(
                'registration_already_registered',
                'Pracovní vztah už má od ČSSZ přidělené ID PPV, takže je u ČSSZ '
                . 'přihlášený (přihláška odešla z jiného programu nebo přišla '
                . 's převzatými daty). Druhou přihlášku ČSSZ odmítne jako '
                . 'duplicitu. Chybějící nebo změněné údaje nahlaste změnovým '
                . 'hlášením (A3, v bloku Změny k ohlášení nebo Dohlášení údajů), '
                . 'chybu v podané přihlášce opravným hlášením (A4).'
                . PayrollRegistrationFieldVocabulary::reference(
                    'employment_external_identifier',
                ),
            );
        }
        if ($live === null
            || hash_equals($live['source_snapshot_hash'], $sourceHash)
        ) {
            return;
        }
        $number = (string) $live['submission_id'];
        if (in_array(
            $live['status'],
            PayrollSubmissionStateMachine::PRE_SUBMISSION_STATUSES,
            true,
        )) {
            throw new PayrollRegistrationXmlException(
                'registration_already_prepared',
                'Přihláška k tomuto pracovnímu vztahu už je připravená ve '
                . 'frontě (podání č. ' . $number . '). Druhou se nezakládá. '
                . 'Otevřete ji v Mzdy → Podání a hlášení → Fronta a odešlete; '
                . 'pokud se mezitím změnily údaje, připravené podání tam '
                . 'nejdřív zrušte a přihlášku připravte znovu.',
            );
        }
        throw new PayrollRegistrationXmlException(
            'registration_already_filed',
            'Přihláška k tomuto pracovnímu vztahu už odešla na ČSSZ (podání č. '
            . $number . ', stav ' . $live['status'] . '). Druhá přihláška se '
            . 'nepodává: změnu údajů nahlaste změnovým hlášením, chybu v '
            . 'odeslané přihlášce opravným hlášením.',
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
        ?int $eventId = null,
    ): array {
        $stored = $this->registrations->registrationBySubmission(
            $supplierId,
            $environment,
            $employmentId,
            (int) $submission['id'],
        );
        if ($stored === null || $stored['artifact_sha256'] === null) {
            // Výjimka zůstává: založit druhé podání by u ČSSZ vyrobilo
            // duplicitní přihlášku, kterou nelze vzít zpět.
            throw new PayrollRegistrationXmlException(
                'registration_replay_artifact_missing',
                'Registrační podání pro tenhle pracovní vztah už existuje, '
                . 'ale nemá uložené XML, které by šlo odeslat. Nové podání '
                . 'se nezakládá, aby u ČSSZ nevznikla duplicita — otevřete '
                . 'původní podání v Mzdy → Podání a hlášení a dokončete '
                . 'ho tam.',
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
            'before_start_choice' => $resolved['before_start_choice'],
            'artifact_sha256' => $stored['artifact_sha256'],
            'created' => false,
            // Připravená změna bez 10224, kterou by ČSSZ dnes odmítla: UI místo
            // odeslání nabídne Připravit znovu.
            'vintage_expired' => $eventId !== null
                && in_array((string) $submission['status'], ['ready', 'cancelled_in_time'], true)
                && PayrollRegistrationSchemaCatalog::frozenChangeVintageExpired(
                    $resolved['interaction']->actionCode,
                    $this->eventData($supplierId, $environment, $employmentId, $eventId),
                    $stored['xsd_version'] ?? null,
                    $this->today(),
                ),
            'replaced_submission_id' => null,
            'deadline' => $this->describeDeadline($resolved['deadline']),
        ];
    }

    /** @return array<string,mixed>|null */
    private function eventData(
        int $supplierId,
        string $environment,
        int $employmentId,
        int $eventId,
    ): ?array {
        $event = $this->events->load($supplierId, $environment, $employmentId, $eventId);

        return is_array($event['data'] ?? null) ? $event['data'] : null;
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
    private function requireContext(
        int $supplierId,
        int $employmentId,
        string $environment,
    ): array {
        // Vnitřní pojistka, ne hláška pro účetní: obě id validuje akce
        // regulárním výrazem `^[1-9][0-9]*$`, přes API sem nula nedojde.
        if ($supplierId <= 0 || $employmentId <= 0) {
            throw new \InvalidArgumentException(
                'Rozsah registračního podání není platný.',
            );
        }
        $context = $this->registrations->findEmploymentContext(
            $supplierId,
            $employmentId,
            $environment,
        );
        if ($context === null) {
            // Výjimka zůstává: chybí entita, které se registrace týká,
            // a je to zároveň hranice mezi firmami.
            throw new \OutOfBoundsException(
                'Pracovní vztah, který se má u ČSSZ přihlásit, v téhle '
                . 'firmě neexistuje. Otevřete kartu zaměstnance znovu — '
                . 'vztah mohl být mezitím smazán, nebo patří jiné firmě.',
            );
        }

        return $context;
    }

    /** @param array<string,mixed> $context */
    private function effectiveDate(array $context): string
    {
        $date = $context['actual_start_date'] ?? $context['start_date'];
        if (!is_string($date) || $date === '') {
            // Výjimka zůstává, přestože jde o neúplný vstup: datum nástupu je
            // rozhodný den celé registrace — bez něj se nespočítá lhůta ani
            // nesestaví datová věta. Ukládání pracovního vztahu tím blokované
            // není, tahle cesta vede jen k náhledu a k podání.
            throw new PayrollRegistrationXmlException(
                'registration_start_date_missing',
                'Datum nástupu u pracovního vztahu chybí, takže nejde '
                . 'spočítat lhůta pro přihlášku ani přihlášku podat. '
                . PayrollRegistrationFieldVocabulary::describe(
                    'contract_start_on',
                )
                . ' Registraci potom připravte znovu'
                . PayrollRegistrationFieldVocabulary::reference('start_date')
                . '.',
            );
        }

        return $date;
    }

    private function calendarBasis(string $basis): string
    {
        $mapped = self::CALENDAR_BASIS_MAP[$basis] ?? null;
        if ($mapped === null) {
            // Výjimka zůstává: nekonzistence mezi legislativními pravidly
            // a evidencí povinností. Zaevidovat lhůtu s neznámým kalendářem
            // by znamenalo hlídat špatné datum, což je horší než hláška.
            throw new PayrollRegistrationXmlException(
                'registration_calendar_basis_unsupported',
                'Lhůta pro přihlášku se počítá podle kalendáře, který '
                . 'aplikace neumí zaevidovat, takže registraci nelze '
                . 'připravit. Jde o chybu v legislativních pravidlech mezd '
                . "— ozvěte se prosím podpoře ({$basis}).",
            );
        }

        return $mapped;
    }

    /**
     * @return array{earliest_registration_on:string,due_on:string,
     *   calendar_basis:string,ruleset_id:string,derived:bool,notice:?string,
     *   statutory:bool}
     */
    private function describeDeadline(
        PayrollEmployeeRegistrationDeadlineWindow $window,
    ): array {
        return [
            'earliest_registration_on' => $window->earliestRegistrationOn,
            'due_on' => $window->dueOn,
            'calendar_basis' => $window->calendarBasis,
            'ruleset_id' => $window->rulesetId,
            'derived' => $window->derived,
            'notice' => $window->notice,
            'statutory' => $window->statutory,
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

    /**
     * Podání, které má nová věta nahradit: změna nebo oprava téže události
     * (stejný otisk zdroje), připravená a neodeslaná, jejíž ročník k dnešku
     * zastaral. Vrací `null`, když už ho nahradila dřívější příprava
     * (`cancelled_in_time`), takže opakovaný požadavek jen vrátí novou větu.
     *
     * @param array<string,mixed> $resolved
     * @return array{submission_id:int,row_version:int}|null
     */
    private function requireExpiredChange(
        int $supplierId,
        string $environment,
        int $employmentId,
        int $replacesSubmissionId,
        string $sourceHash,
        array $resolved,
        ?array $event,
    ): ?array {
        $stored = $this->registrations->registrationBySubmission(
            $supplierId,
            $environment,
            $employmentId,
            $replacesSubmissionId,
        );
        $expired = $stored !== null
            && hash_equals((string) ($stored['source_snapshot_hash'] ?? ''), $sourceHash)
            && in_array((string) $stored['status'], ['ready', 'cancelled_in_time'], true)
            && PayrollRegistrationSchemaCatalog::frozenChangeVintageExpired(
                $resolved['interaction']->actionCode,
                is_array($event['data'] ?? null) ? $event['data'] : null,
                $stored['xsd_version'] ?? null,
                $this->today(),
            );
        if (!$expired) {
            throw new PayrollRegistrationXmlException(
                'registration_reprepare_not_needed',
                'Tohle podání se znovu připravovat nemusí: není to připravená'
                    . ' změna nebo oprava téže události, jejíž formát by ČSSZ'
                    . ' dnes odmítla. Odešlete ho, nebo načtěte kartu vztahu'
                    . ' znovu.',
            );
        }

        return $stored['status'] === 'ready'
            ? [
                'submission_id' => (int) $stored['submission_id'],
                'row_version' => (int) ($stored['row_version'] ?? 0),
            ]
            : null;
    }

    /** @return array{submission:string,artifact:string} */
    private function idempotencyKeys(
        int $supplierId,
        string $environment,
        int $employmentId,
        string $sourceHash,
        ?int $replacesSubmissionId = null,
        ?int $retriesSubmissionId = null,
    ): array {
        $base = CanonicalJson::encode([
            'schema_reference' => 'payroll-registration-submission.v1',
            'supplier_id' => $supplierId,
            'environment' => $environment,
            'employment_id' => $employmentId,
            'source_hash' => $sourceHash,
        ] + ($replacesSubmissionId === null
            ? []
            : ['replaces_submission_id' => $replacesSubmissionId])
          + ($retriesSubmissionId === null
            ? []
            : ['retries_submission_id' => $retriesSubmissionId]));

        return [
            'submission' => 'registration-submission:'
                . hash('sha256', $base),
            'artifact' => 'registration-artifact:' . hash('sha256', $base),
        ];
    }

    private function today(): string
    {
        return PayrollSubmissionCalendar::today($this->clock->now());
    }
}
