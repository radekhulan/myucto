<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use DOMDocument;
use DOMElement;
use DOMXPath;
use MyInvoice\Repository\Payroll\PayrollEmploymentTerminationRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationA2EvidenceRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationEventRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Codebook\HealthInsurers;
use MyInvoice\Service\Payroll\PayrollEmploymentJmhzEvidenceCatalog;
use MyInvoice\Service\Payroll\CzechBirthNumber;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Submission\CsszEmployerVariableSymbol;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionCalendar;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationChangeDeltaPlanner;
use MyInvoice\Service\Payroll\Termination\PayrollTerminationReason;
use Psr\Clock\ClockInterface;

/**
 * Schválení registrační události A2–A8 a čtení už schválených podkladů.
 *
 * PROČ TU VÝJIMKY ZŮSTÁVAJÍ. Zásada „uložit musí jít cokoliv, validace patří
 * k podání" na tuhle třídu nedopadá: žádná cesta tudy nic rozpracovaného
 * neukládá. `approve()` je sám o sobě akt zmrazení podkladu — vytvoří
 * zašifrovaný, otiskem chráněný a dál needitovatelný záznam, ze kterého se
 * pak sestaví datová věta pro ČSSZ. Rozpracovaná data účetní ukládá jinde
 * (karta osoby, karta pracovního vztahu, profil A1) a tam ji nic neblokuje.
 * Uložit sem „napůl schválenou" událost by znamenalo mít v evidenci podklad,
 * který vypadá jako závazný a přitom není.
 *
 * Co se tedy dá udělat pro průchodnost, je hlášky: každá musí začínat lidským
 * názvem údaje nebo podání, říct KONKRÉTNĚ co chybí a KAM jít. Názvy a místa
 * drží {@see PayrollRegistrationFieldVocabulary}; technická cesta k poli patří
 * jen do závorky na konci věty.
 */
final readonly class PayrollRegistrationEventService
{
    public const SCHEMA_REFERENCE = 'payroll-registration-event-snapshot.v1';

    private const DEFINITIONS = [
        'termination' => [2, 'employment_exit'],
        'change' => [3, 'verified_change'],
        'correction' => [4, 'verified_correction'],
        'variable_symbol_transfer' => [5, 'employer_transfer'],
        'czech_legislation_start' => [6, 'jurisdiction_evidence'],
        'czech_legislation_end' => [7, 'jurisdiction_evidence'],
        'cancellation' => [8, 'verified_cancellation'],
    ];

    public function __construct(
        private PayrollRegistrationEventRepository $events,
        private PayrollRegistrationIdentityService $identities,
        private PayrollSensitiveData $sensitiveData,
        private SecretEncryption $encryption,
        private PayrollSubmissionService $submissions,
        private PayrollEmploymentJmhzEvidenceCatalog $jmhzEvidence,
        private PayrollRegistrationA2EvidenceRepository $a2Evidence,
        private ClockInterface $clock,
        private PayrollEmploymentTerminationRepository $terminations,
    ) {}

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function approve(
        int $supplierId,
        string $environment,
        int $employmentId,
        array $input,
        int $approvedBy,
    ): array {
        return $this->a2Evidence->transaction(function () use (
            $supplierId,
            $environment,
            $employmentId,
            $input,
            $approvedBy,
        ): array {
            // Výjimka zůstává: chybějící firma je porušená bezpečnostní
            // hranice (cizí nebo smazaný rozsah), ne nevyplněný údaj.
            if (!$this->a2Evidence->lockSupplier($supplierId)) {
                throw new \OutOfBoundsException(
                    'Firma, ke které pracovní vztah patří, nebyla nalezena.'
                        . ' Otevřete kartu pracovního vztahu znovu a akci zopakujte.',
                );
            }
            return $this->approveLocked(
                $supplierId,
                $environment,
                $employmentId,
                $input,
                $approvedBy,
            );
        });
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function approveLocked(
        int $supplierId,
        string $environment,
        int $employmentId,
        array $input,
        int $approvedBy,
    ): array {
        // Výjimka zůstává: identita firmy, vztahu a přihlášeného uživatele
        // nepochází z formuláře, ale z relace a routy. Prázdná hodnota tady
        // znamená rozbitý kontext, ne nevyplněné pole.
        if ($supplierId <= 0 || $employmentId <= 0 || $approvedBy <= 0) {
            throw new \InvalidArgumentException(
                'Registrační událost nelze schválit: chybí údaj o firmě,'
                    . ' pracovním vztahu nebo přihlášeném uživateli.'
                    . ' Otevřete kartu pracovního vztahu znovu a akci zopakujte.',
            );
        }
        if (!in_array($environment, ['test', 'production'], true)) {
            throw new \InvalidArgumentException($this->note(
                'environment',
                'musí být zkušební provoz (test), nebo ostrý provoz'
                    . ' (production). Přepněte prostředí v registračním panelu.',
            ));
        }
        $interaction = $this->requiredCode($input, 'interaction', 48, 'interaction');
        $definition = self::DEFINITIONS[$interaction] ?? null;
        if ($definition === null) {
            throw new \InvalidArgumentException($this->note(
                'interaction',
                'není podporovaný. Vyberte jednu z událostí A2 až A8: skončení'
                    . ' pracovního vztahu, změnu údajů zaměstnance, opravu'
                    . ' dříve oznámených údajů, převod pod jiný variabilní'
                    . ' symbol zaměstnavatele, vznik nebo skončení'
                    . ' příslušnosti k českým předpisům, anebo storno'
                    . ' přihlášení nenastoupeného zaměstnance.',
            ));
        }
        $effectiveOn = $this->date($input['effective_on'] ?? null, 'effective_on');
        $completion = $interaction === 'change'
            && array_key_exists('completion', $input)
            ? PayrollRegistrationProfileCompletion::requireMode($input['completion'])
            : null;
        $context = $this->events->employmentSourceAt(
            $supplierId,
            $employmentId,
            $effectiveOn,
            $environment,
        );
        /*
         * Dohlášení jde i za vztah, který už skončil (MPSV, aktualita
         * 28. 4. 2026). Do 10009 jde den odeslání, údaje ale platí ke dni
         * skončení — k tomu dni se čtou podmínky vztahu i identifikátory.
         */
        $sourceOn = $effectiveOn;
        $endDate = is_array($context) ? ($context['end_date'] ?? null) : null;
        if ($completion !== null
            && is_string($endDate)
            && $endDate !== ''
            && $effectiveOn > $endDate
        ) {
            $sourceOn = $endDate;
            $context = $this->events->employmentSourceAt(
                $supplierId,
                $employmentId,
                $sourceOn,
                $environment,
            );
        }
        // Výjimka zůstává: chybějící vztah je chybějící entita v rozsahu
        // firmy, ne nevyplněný údaj formuláře.
        if ($context === null) {
            throw new \OutOfBoundsException(
                'Pracovní vztah k tomuto dni v této firmě neexistuje.'
                    . ' Zkontrolujte den, ke kterému se změna hlásí, nebo'
                    . ' vztah otevřete znovu z jeho karty.',
            );
        }
        PayrollRegistrationBusinessMatrix::requireActionVariant(
            $definition[0],
            is_string($context['activity_code'] ?? null)
                ? $context['activity_code']
                : null,
            is_string($context['jmhz_relationship_detail_code'] ?? null)
                ? $context['jmhz_relationship_detail_code']
                : null,
        );
        $employeeId = (int) ($context['employee_id'] ?? 0);
        $identity = $this->identities->sensitiveJmhzIdentityAt(
            $supplierId,
            $employeeId,
            $employmentId,
            $environment,
            $sourceOn,
            $interaction === 'termination',
            $interaction === 'termination'
                && ($input['identifiers_verified_in_cssz_list'] ?? null) === true,
        );
        $sourceReference = $this->sourceReference(
            $interaction,
            $employmentId,
            $effectiveOn,
            $input['source_reference'] ?? null,
        );
        $personExternal = $this->object(
            $identity['person_external_identifier'] ?? null,
            'person_external_identifier',
        );
        $employmentExternal = $this->object(
            $identity['employment_external_identifier'] ?? null,
            'employment_external_identifier',
        );
        $data = $completion === null
            ? $this->data(
                $supplierId,
                $environment,
                $employmentId,
                $interaction,
                $effectiveOn,
                $context,
                $input,
                (string) ($employmentExternal['value'] ?? ''),
            )
            : $this->profileCompletion(
                $supplierId,
                $environment,
                $employmentId,
                $context,
                $completion,
                $sourceOn,
            ) + $this->relationIdentity($context);
        $this->assertEffectiveOnNotBeforeStart($definition[0], $effectiveOn, $context, $data);
        if ($interaction === 'termination' && is_array($identity['provenance'] ?? null)) {
            // Doklad, o co se odhláška opírá: protokol, přijaté A3, export
            // zaměstnanců ČSSZ, nebo výslovné potvrzení účetní u ONZ.
            $data['identifier_basis'] = $identity['provenance'];
        }
        $notificationTriggerOn = $this->notificationTriggerOn(
            $interaction,
            $effectiveOn,
            $context,
            $input,
        );
        if ($notificationTriggerOn > PayrollSubmissionCalendar::today($this->clock->now())) {
            throw new PayrollRegistrationXmlException(
                'registration_event_in_future',
                $this->actionName($definition[0])
                    . " nelze schválit dopředu: rozhodný den {$notificationTriggerOn}"
                    . ' teprve nastane. Schvalte událost až v den, kdy skutečně'
                    . ' nastane, nebo opravte datum ve formuláři.',
            );
        }
        $snapshot = [
            'schema_reference' => self::SCHEMA_REFERENCE,
            'supplier_id' => $supplierId,
            'employee_id' => $employeeId,
            'employment_id' => $employmentId,
            'environment' => $environment,
            'interaction' => $interaction,
            'action_code' => $definition[0],
            'effective_on' => $effectiveOn,
            'notification_trigger_on' => $notificationTriggerOn,
            'person_external_identifier' => $personExternal,
            'employment_external_identifier' => $employmentExternal,
            'jmhz_codebook' => $this->jmhzEvidence->packageProvenance(),
            'employer' => [
                'variable_symbol' => $this->requiredDigits(
                    $context['social_security_variable_symbol'] ?? null,
                    'employer_variable_symbol',
                    8,
                    10,
                ),
                'name' => $this->requiredText(
                    PayrollRegistrationEmployerName::forSubmission(
                        (string) ($context['company_name'] ?? ''),
                        is_string($context['company_city'] ?? null)
                            ? $context['company_city']
                            : null,
                    ),
                    'employer_name',
                    150,
                ),
                'workplace_code' => $this->csszWorkplaceCode(
                    $context['social_security_office_code'] ?? null,
                ),
            ],
            'data' => $data,
            'source' => [
                'kind' => $definition[1],
                'reference' => $sourceReference,
            ],
        ];
        $snapshotJson = CanonicalJson::encode($snapshot);
        $fingerprint = $this->sensitiveData->keyedFingerprint(
            $snapshotJson,
            'registration-event-snapshot-v1',
            $supplierId,
        );
        $manifest = [
            'schema_reference' => 'payroll-registration-event-source-manifest.v1',
            'supplier_id' => $supplierId,
            'employee_id' => $employeeId,
            'employment_id' => $employmentId,
            'environment' => $environment,
            'interaction' => $interaction,
            'action_code' => $definition[0],
            'effective_on' => $effectiveOn,
            'notification_trigger_on' => $notificationTriggerOn,
            'source_kind' => $definition[1],
            'source_reference' => $sourceReference,
            'employment_row_version' => (int) ($context['row_version'] ?? 0),
            'terms_id' => $this->nullablePositive($context['terms_id'] ?? null),
            'terms_row_version' => $this->nullablePositive(
                $context['terms_row_version'] ?? null,
            ),
            'person_external_id' => (int) ($personExternal['id'] ?? 0),
            'person_external_row_version' => (int) ($personExternal['row_version'] ?? 0),
            'employment_external_id' => (int) ($employmentExternal['id'] ?? 0),
            'employment_external_row_version' => (int) ($employmentExternal['row_version'] ?? 0),
            'snapshot_fingerprint' => $fingerprint,
        ];
        $manifestJson = CanonicalJson::encode($manifest);
        $manifestHash = hash('sha256', $manifestJson);
        $result = $this->events->insert([
            'supplier_id' => $supplierId,
            'employee_id' => $employeeId,
            'employment_id' => $employmentId,
            'environment' => $environment,
            'interaction_code' => $interaction,
            'action_code' => $definition[0],
            'effective_on' => $effectiveOn,
            'source_kind' => $definition[1],
            'source_reference' => $sourceReference,
            'source_manifest_json' => $manifestJson,
            'source_manifest_hash' => $manifestHash,
            'snapshot_ciphertext' => $this->encryption->encryptFor(
                $snapshotJson,
                $this->context($supplierId, $employmentId, $manifestHash),
            ),
            'snapshot_fingerprint' => $fingerprint,
            'approved_by' => $approvedBy,
        ]);
        if ($interaction === 'termination') {
            $evidence = is_array($data['jmhz_correction_evidence'] ?? null)
                ? $data['jmhz_correction_evidence']
                : [];
            $plan = PayrollRegistrationA2EvidencePlan::create(
                $supplierId,
                $environment,
                $employmentId,
                $effectiveOn,
                is_array($evidence['months'] ?? null) ? $evidence['months'] : [],
            );
            $this->a2Evidence->append(
                $supplierId,
                $environment,
                $employmentId,
                (int) $result['row']['id'],
                $plan,
                $approvedBy,
            );
        }

        $public = $this->publicRow($result['row'], false);
        $public['created'] = $result['created'];

        return $public;
    }

    /** @return array<string,mixed> */
    public function load(
        int $supplierId,
        string $environment,
        int $employmentId,
        int $eventId,
    ): array {
        $stored = $this->events->find($supplierId, $environment, $eventId);
        // Výjimka zůstává: chybějící záznam v rozsahu firmy, vztahu
        // a prostředí je bezpečnostní hranice, ne nevyplněný údaj.
        if ($stored === null || (int) ($stored['employment_id'] ?? 0) !== $employmentId) {
            throw new \OutOfBoundsException(
                'Schválená registrační událost k tomuto pracovnímu vztahu'
                    . ' a prostředí neexistuje. Vyberte událost znovu ze'
                    . ' seznamu schválených podkladů.',
            );
        }
        $manifestHash = (string) ($stored['source_manifest_hash'] ?? '');
        $json = $this->encryption->decryptFor(
            (string) ($stored['snapshot_ciphertext'] ?? ''),
            $this->context($supplierId, $employmentId, $manifestHash),
        );
        $expected = $this->sensitiveData->keyedFingerprint(
            $json,
            'registration-event-snapshot-v1',
            $supplierId,
        );
        // Obě výjimky zůstávají: jde o kontrolu neporušenosti uloženého
        // podkladu (otisk a tvar). Podat něco, co se po schválení změnilo,
        // je horší než akci odmítnout — a účetní to nijak nevyplní.
        if (!hash_equals((string) ($stored['snapshot_fingerprint'] ?? ''), $expected)) {
            throw new \DomainException(
                'Uložená registrační událost neodpovídá svému kontrolnímu'
                    . ' otisku, takže s ní aplikace dál nepracuje. Schvalte'
                    . ' událost znovu; pokud hláška zůstane, jde o poškozený'
                    . ' záznam a je potřeba zásah podpory.',
            );
        }
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \DomainException(
                'Uložená registrační událost je poškozená a nejde přečíst.'
                    . ' Schvalte událost znovu.',
            );
        }
        $this->assertSnapshot($decoded, $stored);

        return $decoded;
    }

    /** @return list<array<string,mixed>> */
    public function list(
        int $supplierId,
        string $environment,
        int $employmentId,
    ): array {
        return array_map(
            fn (array $row): array => $this->publicRow(
                $row,
                ((int) ($row['consumed'] ?? 0)) === 1,
            ),
            $this->events->listForEmployment(
                $supplierId,
                $environment,
                $employmentId,
            ),
        );
    }

    /** @return array<string,mixed> */
    public function a2EvidenceCandidates(
        int $supplierId,
        string $environment,
        int $employmentId,
        string $effectiveOn,
    ): array {
        $effectiveOn = $this->date($effectiveOn, 'end_on');
        $context = $this->events->employmentSourceAt($supplierId, $employmentId, $effectiveOn, $environment);
        // Výjimka zůstává: chybějící vztah je chybějící entita v rozsahu firmy.
        if ($context === null) {
            throw new \OutOfBoundsException(
                'Pracovní vztah k tomuto dni v této firmě neexistuje.'
                    . ' Zkontrolujte datum skončení, nebo vztah otevřete'
                    . ' znovu z jeho karty.',
            );
        }
        if (!in_array($context['status'] ?? null, ['ended', 'archived'], true)
            || ($context['end_date'] ?? null) !== $effectiveOn
        ) {
            throw new PayrollRegistrationXmlException(
                'registration_a2_end_source_mismatch',
                $this->endSourceMismatchMessage($effectiveOn, $context),
            );
        }
        // Jen náhled měsíců k opravě: původ čísel tu nerozhoduje (a u ONZ by
        // náhled zablokoval dřív, než účetní stihne čísla potvrdit). O původu
        // rozhoduje až schválení odhlášky.
        $identity = $this->identities->sensitiveJmhzIdentityAt(
            $supplierId,
            (int) ($context['employee_id'] ?? 0),
            $employmentId,
            $environment,
            $effectiveOn,
        );
        $external = $this->object(
            $identity['employment_external_identifier'] ?? null,
            'employment_external_identifier',
        );
        $value = $this->requiredText(
            $external['value'] ?? null,
            'employment_external_identifier',
            128,
        );

        return $this->a2Plan(
            $supplierId,
            $environment,
            $employmentId,
            $effectiveOn,
            $value,
            false,
        )->toArray();
    }

    public function assertA2EvidenceCurrent(
        int $supplierId,
        string $environment,
        int $employmentId,
        int $eventId,
    ): void {
        $event = $this->load($supplierId, $environment, $employmentId, $eventId);
        if (($event['action_code'] ?? null) !== 2) {
            return;
        }
        $stored = is_array($event['data']['jmhz_correction_evidence'] ?? null)
            ? $event['data']['jmhz_correction_evidence']
            : null;
        $external = is_array($event['employment_external_identifier'] ?? null)
            ? ($event['employment_external_identifier']['value'] ?? null)
            : null;
        if ($stored === null || !is_string($external) || $external === '') {
            throw new PayrollRegistrationXmlException(
                'registration_a2_jmhz_evidence_missing',
                $this->actionName(2)
                    . ' nemá u sebe uložený doklad o tom, že jsou opravná'
                    . ' jednotná měsíční hlášení zaměstnavatele (JMHZ)'
                    . ' uzavřená. Schvalte událost znovu — doklad si uloží'
                    . ' teprve nově schválená událost.',
            );
        }
        $current = $this->a2Plan(
            $supplierId,
            $environment,
            $employmentId,
            (string) $event['effective_on'],
            $external,
            true,
        );
        if ($current->decision() !== 'accepted'
            || !is_string($stored['fingerprint'] ?? null)
            || !hash_equals($stored['fingerprint'], $current->fingerprint())
        ) {
            throw new PayrollRegistrationXmlException(
                'registration_a2_jmhz_evidence_changed',
                $this->actionName(2)
                    . ' se opírá o jednotná měsíční hlášení zaměstnavatele'
                    . ' (JMHZ), která se od schválení změnila. Načtěte měsíce'
                    . ' k opravě znovu a schvalte novou událost.',
            );
        }
        $ledger = $this->a2Evidence->findForEvent($supplierId, $environment, $eventId);
        if ($ledger === null
            || !hash_equals((string) ($ledger['plan_sha256'] ?? ''), $current->fingerprint())
        ) {
            throw new PayrollRegistrationXmlException(
                'registration_a2_jmhz_evidence_missing',
                $this->actionName(2)
                    . ' nemá v evidenci uzavřený záznam o opravných jednotných'
                    . ' měsíčních hlášeních zaměstnavatele (JMHZ). Schvalte'
                    . ' událost znovu.',
            );
        }
    }

    /**
     * Věta pro A2 tam, kde vztah ještě není ukončený nebo mu nesedí datum.
     * Účetní musí vidět obě strany rozporu, ne jen že „něco nesedí".
     *
     * @param array<string,mixed> $context
     */
    private function endSourceMismatchMessage(string $effectiveOn, array $context): string
    {
        $endDate = $context['end_date'] ?? null;
        $recorded = is_string($endDate) && $endDate !== ''
            ? "v evidenci má vztah datum skončení {$endDate}"
            : 'v evidenci vztah zatím žádné datum skončení nemá';

        return $this->actionName(2)
            . ' jde schválit až tehdy, když je pracovní vztah v evidenci'
            . " opravdu ukončený a jeho datum skončení se shoduje s datem"
            . " ve formuláři. Ve formuláři je {$effectiveOn}, {$recorded}."
            . ' Datum skončení nastavte na kartě pracovního vztahu.'
            . PayrollRegistrationFieldVocabulary::reference('end_on');
    }

    /** @param array<string,mixed> $context @param array<string,mixed> $input @return array<string,mixed> */
    private function data(
        int $supplierId,
        string $environment,
        int $employmentId,
        string $interaction,
        string $effectiveOn,
        array $context,
        array $input,
        string $employmentExternalIdentifier,
    ): array {
        $data = match ($interaction) {
            'termination' => $this->termination(
                $supplierId,
                $environment,
                $employmentId,
                $effectiveOn,
                $context,
                $input,
                $employmentExternalIdentifier,
            ),
            'change' => $this->change($effectiveOn, $input),
            'correction' => $this->correction(
                $supplierId,
                $environment,
                $employmentId,
                $effectiveOn,
                $context,
                $input,
                $employmentExternalIdentifier,
            ),
            'variable_symbol_transfer' => [
                'new_variable_symbol' => $this->newVariableSymbol(
                    $input['new_variable_symbol'] ?? null,
                    $context['social_security_variable_symbol'] ?? null,
                ),
            ],
            'czech_legislation_start' => $this->assertNotForeignFromStart(
                $supplierId,
                $environment,
                $employmentId,
            ) + [
                'foreign_insurance' => $this->foreignInsurance($input, 'P'),
            ],
            'czech_legislation_end' => [
                'foreign_insurance' => $this->foreignInsurance($input, 'S'),
            ],
            'cancellation' => $this->cancellation(
                $supplierId,
                $environment,
                $employmentId,
                $effectiveOn,
                $context,
                $input,
            ),
            // Interní kontrakt: klíč už prošel self::DEFINITIONS, sem se
            // uživatelský vstup nedostane. Zůstává technická, protože ji
            // akce nechytá a je to hlášení o chybě programu.
            default => throw new \LogicException(
                'Neznámá interakce REGZEC: ' . $interaction,
            ),
        };

        $relation = $this->relationIdentity($context);
        if ($interaction === 'change' || $interaction === 'correction') {
            $this->assertDeltaMatchesVariant(
                $interaction === 'change' ? 3 : 4,
                $relation,
                $data['delta'],
            );
        }
        if ($interaction === 'change') {
            $this->assertStartDateAge($supplierId, $context, $effectiveOn, $data['delta']);
            $this->assertCzechResidenceAllowed($supplierId, $employmentId, $effectiveOn, $data['delta']);
        }
        if ($interaction === 'change' || $interaction === 'correction') {
            $actionCode = $interaction === 'change' ? 3 : 4;
            $data += $this->clientBirthNumber(
                $supplierId,
                $context,
                $effectiveOn,
                $data['delta'],
                $actionCode,
            );
            $data += $this->deltaForeignInsurance(
                $supplierId,
                $environment,
                $employmentId,
                $context,
                $relation,
                $actionCode,
            );
        }

        return $data + $relation;
    }

    /**
     * EDV 1.4.0.6, ID 10057/10058 (podmínka P pro A1, A3 a A4): u českého
     * státního občanství je rodné číslo / EČP v `client/@bno` povinné i ve
     * změně a opravě, kde jinak osobu nese `client/@ikmpsv`. Mění-li se
     * rodné číslo samo, nese ho delta; jinak se do události zmrazí to
     * z karty osoby. U A1 totéž hlídá
     * {@see PayrollRegistrationIdentityRequirements::missing()}.
     *
     * @param array<string,mixed> $context
     * @param array<string,mixed> $delta
     * @return array{birth_number?:string}
     */
    private function clientBirthNumber(
        int $supplierId,
        array $context,
        string $onDate,
        array $delta,
        int $actionCode,
    ): array {
        if (isset($delta['birth_number'])) {
            return [];
        }
        $current = $this->identities->sensitiveIdentityAt(
            $supplierId,
            (int) ($context['employee_id'] ?? 0),
            $onDate,
        );
        $citizenship = is_array($delta['identity'] ?? null)
            && is_string($delta['identity']['citizenship_country_code'] ?? null)
                ? $delta['identity']['citizenship_country_code']
                : ($current['identity']['citizenship_country_code'] ?? null);
        if ($citizenship !== 'CZ') {
            return [];
        }
        $identifiers = is_array($current['identifiers'] ?? null) ? $current['identifiers'] : [];
        $birthNumber = CzechBirthNumber::forSubmission(
            is_string($identifiers['birth_number'] ?? null) ? $identifiers['birth_number'] : null,
        ) ?? (is_string($identifiers['ecp'] ?? null) && trim($identifiers['ecp']) !== ''
            ? trim($identifiers['ecp'])
            : null);
        if ($birthNumber === null) {
            throw new PayrollRegistrationXmlException(
                'registration_event_birth_number_missing',
                $this->actionName($actionCode) . ' zaměstnance s českým státním'
                    . ' občanstvím musí nést rodné číslo nebo EČP, jinak ho ČSSZ'
                    . ' zamítne. Doplňte rodné číslo na kartě osoby (Identifikátory)'
                    . ' a oznámení schvalte znovu.'
                    . PayrollRegistrationFieldVocabulary::reference('birth_number'),
            );
        }

        return ['birth_number' => $birthNumber];
    }

    /**
     * EDV 1.4.0.6, ID 10092 (podmínka P pro A1-OST, A3-OST a A4-OST): u druhu
     * činnosti „N" je cizozemský nositel pojištění (`forin`) povinný i ve
     * změně a opravě. Nositele vede profil registrace A1 (přihláška ho
     * vyžaduje stejně, viz {@see PayrollRegistrationA1SnapshotBuilder}),
     * odtud se do události zmrazí.
     *
     * @param array<string,mixed> $context
     * @param array<string,mixed> $relation
     * @return array{foreign_insurance?:array<string,string>}
     */
    private function deltaForeignInsurance(
        int $supplierId,
        string $environment,
        int $employmentId,
        array $context,
        array $relation,
        int $actionCode,
    ): array {
        if (($relation['activity_code'] ?? null) !== 'N') {
            return [];
        }
        $startOn = $context['actual_start_date'] ?? $context['start_date'] ?? null;
        $profile = null;
        if (is_string($startOn) && $startOn !== '') {
            try {
                $source = $this->identities->sensitiveSnapshotSourceAt(
                    $supplierId,
                    (int) ($context['employee_id'] ?? 0),
                    $employmentId,
                    $environment,
                    $startOn,
                );
                $profile = $source['regzec_a1'] ?? null;
            } catch (PayrollRegistrationIdentitySnapshotException) {
                $profile = null;
            }
        }
        $raw = is_array($profile) && is_array($profile['foreign_insurance'] ?? null)
            ? $profile['foreign_insurance']
            : [];
        $result = [];
        foreach ([
            'current', 'name', 'street', 'house_number', 'orientation_number',
            'postal_code', 'city', 'country_code', 'identifier', 'sector',
        ] as $key) {
            $value = $raw[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $result[$key] = trim($value);
            }
        }
        if (!in_array($result['current'] ?? null, ['P', 'S'], true)
            || !isset($result['country_code'])
            || PayrollRegistrationForeignInsurerAddress::missing($result) !== []
            || (isset($result['sector'])
                && !PayrollRegistrationForeignInsurerSector::isKnown($result['sector']))
        ) {
            throw new PayrollRegistrationXmlException(
                'registration_event_foreign_insurance_missing',
                $this->actionName($actionCode) . ' zaměstnance s druhem činnosti'
                    . ' „N" (smluvní zaměstnanec) musí nést cizozemského nositele'
                    . ' pojištění, bez něj ho ČSSZ nepřijme. V profilu registrace'
                    . ' (A1) vyplňte oddíl Cizozemský nositel pojištění'
                    . ' (specifikace P nebo S a stát), uložte ho a oznámení'
                    . ' schvalte znovu.'
                    . PayrollRegistrationFieldVocabulary::reference('foreign_insurance'),
            );
        }
        ksort($result, SORT_STRING);

        return ['foreign_insurance' => $result];
    }

    /**
     * Nový variabilní symbol (A5, ID 10222): 8 až 10 číslic a nesmí se
     * shodovat s původním (EDV 1.4.0.6, kontrola `nvs <> vs`).
     */
    private function newVariableSymbol(mixed $value, mixed $currentVariableSymbol): string
    {
        $new = $this->requiredDigits($value, 'new_variable_symbol', 8, 10);
        $problem = CsszEmployerVariableSymbol::invalidReason($new);
        if ($problem !== null) {
            throw new PayrollRegistrationXmlException(
                'registration_a5_variable_symbol_invalid',
                PayrollRegistrationFieldVocabulary::label('new_variable_symbol')
                    . " {$new} není platný: {$problem}. ČSSZ by "
                    . $this->actionName(5) . ' odmítla. Opište nový symbol '
                    . 'přesně z oznámení ČSSZ.'
                    . PayrollRegistrationFieldVocabulary::reference('new_variable_symbol'),
            );
        }
        if (is_string($currentVariableSymbol) && $new === trim($currentVariableSymbol)) {
            throw new PayrollRegistrationXmlException(
                'registration_a5_variable_symbol_unchanged',
                $this->actionName(5) . ' musí nést jiný variabilní symbol, než pod '
                    . 'jakým je zaměstnavatel veden teď (' . $new . '). ČSSZ '
                    . 'podání se shodným novým symbolem zamítne. Zadejte nový '
                    . 'variabilní symbol přidělený ČSSZ.'
                    . PayrollRegistrationFieldVocabulary::reference('new_variable_symbol'),
            );
        }

        return $new;
    }

    /**
     * Kód správy sociálního zabezpečení (`employee/@dep`, ID 10004) musí být
     * z číselníku okresů ČSSZ (C_COKR).
     */
    private function csszWorkplaceCode(mixed $value): string
    {
        $code = $this->requiredDigits($value, 'cssz_workplace_code', 3, 3);
        if (!PayrollCsszDistrictCodebook::contains($code)) {
            throw new PayrollRegistrationXmlException(
                'registration_cssz_workplace_code_invalid',
                PayrollRegistrationFieldVocabulary::label('cssz_workplace_code')
                    . " „{$code}\" není v číselníku okresních správ ČSSZ, takže "
                    . 'ho ČSSZ odmítne. Opravte kód v nastavení mezd zaměstnavatele '
                    . '(například 110 pro Prahu 10).'
                    . PayrollRegistrationFieldVocabulary::reference('cssz_workplace_code'),
            );
        }

        return $code;
    }

    /**
     * Části změny a opravy, které varianta věty zakazuje (EDV 1.4.0.6):
     * pro A3-10, A3-SPEC, A4-10 a A4-SPEC se zakázaný element nepošle,
     * podání by ČSSZ zamítla.
     *
     * @param array<string,mixed> $relation
     * @param array<string,mixed> $delta
     */
    private function assertDeltaMatchesVariant(
        int $actionCode,
        array $relation,
        array $delta,
    ): void {
        $variant = PayrollRegistrationBusinessMatrix::requireActionVariant(
            $actionCode,
            is_string($relation['activity_code'] ?? null) ? $relation['activity_code'] : null,
            is_string($relation['relationship_detail_code'] ?? null)
                ? $relation['relationship_detail_code']
                : null,
        );
        $forbidden = PayrollRegistrationDeltaVariantRule::forbiddenPaths($variant, $delta);
        if ($forbidden === []) {
            return;
        }
        $single = count($forbidden) === 1;
        throw new PayrollRegistrationXmlException(
            'registration_event_delta_variant_forbidden',
            $this->names('', $forbidden) . ' se'
                . ' u druhu činnosti „' . (string) $relation['activity_code']
                . '" (varianta ' . $this->actionName($actionCode) . ', ' . $variant . ')'
                . ($single ? ' neposílá' : ' neposílají')
                . ' - ČSSZ by takové podání zamítla. Odeberte '
                . ($single ? 'ji' : 'je') . ' z oznámení.'
                . $this->references('', $forbidden),
        );
    }

    /**
     * EDV 1.4.0.6, ID 10514 až 10517 (podmínka Z pro A1, A3 a A4): adresa
     * pobytu v ČR (`fdr`) se při trvalém pobytu v ČR nesmí poslat, ČSSZ by
     * podání zamítla. Stát trvalého pobytu je ten ze stejné změny, jinak
     * z kmenových dat osoby k rozhodnému dni; u přihlášky A1 totéž hlídá
     * {@see PayrollRegistrationA1SnapshotBuilder}.
     *
     * @param array<string,mixed> $delta
     */
    private function assertCzechResidenceAllowed(
        int $supplierId,
        int $employmentId,
        string $effectiveOn,
        array $delta,
    ): void {
        if (!is_array($delta['czech_residence_address'] ?? null)) {
            return;
        }
        $country = is_array($delta['permanent_address'] ?? null)
            ? ($delta['permanent_address']['country_code'] ?? null)
            : null;
        if (!is_string($country) || $country === '') {
            $projection = $this->identities->a1MasterProjectionAt(
                $supplierId,
                $employmentId,
                $effectiveOn,
            );
            $country = is_array($projection['permanent_address'] ?? null)
                ? ($projection['permanent_address']['country_code'] ?? null)
                : null;
        }
        if (!is_string($country) || trim($country) === '') {
            throw new PayrollRegistrationXmlException(
                'registration_event_czech_residence_unverifiable',
                'Adresa pobytu v ČR se posílá jen zaměstnanci s trvalým pobytem'
                    . ' mimo ČR, ale stát trvalého pobytu u osoby vyplněný není.'
                    . ' Doplňte trvalou adresu se státem na kartě osoby, nebo ji'
                    . ' ohlaste ve stejné změně.'
                    . PayrollRegistrationFieldVocabulary::reference(
                        'permanent_address.country_code',
                    ),
            );
        }
        if (strtoupper(trim($country)) !== 'CZ') {
            return;
        }
        throw new PayrollRegistrationXmlException(
            'registration_event_czech_residence_forbidden',
            'Adresa pobytu v ČR se u zaměstnance s trvalým pobytem v ČR'
                . ' neposílá, ČSSZ by takové oznámení zamítla. Odeberte ji'
                . ' z oznámení; mění-li se trvalý pobyt do zahraničí, ohlaste'
                . ' novou trvalou adresu ve stejné změně.'
                . PayrollRegistrationFieldVocabulary::reference(
                    'czech_residence_address',
                ),
        );
    }

    /**
     * EDV 1.4.0.6, ID 10009 (kontrola pro A3, A5, A6 a A7, u A4 se
     * nevyhodnocuje): datum nástupu (job/@fro, ID 10223) musí být menší nebo
     * rovno datu, ke kterému se změna hlásí (employee/@fro). Nástupem je nový
     * den nástupu z A3, jinak skutečný nástup vztahu, jak ho zná ČSSZ
     * z přihlášky.
     *
     * @param array<string,mixed> $context
     * @param array<string,mixed> $data
     */
    private function assertEffectiveOnNotBeforeStart(
        int $actionCode,
        string $effectiveOn,
        array $context,
        array $data,
    ): void {
        if (!in_array($actionCode, [3, 5, 6, 7], true)) {
            return;
        }
        $shifted = is_array($data['delta']['employment'] ?? null)
            ? ($data['delta']['employment']['actual_start_on'] ?? null)
            : null;
        $start = is_string($shifted) && $shifted !== ''
            ? $shifted
            : ($context['actual_start_date'] ?? $context['start_date'] ?? null);
        if (!is_string($start) || $start === '' || $start <= $effectiveOn) {
            return;
        }
        throw new PayrollRegistrationXmlException(
            'registration_event_before_start',
            $this->actionName($actionCode) . " se hlásí ke dni {$effectiveOn},"
                . " ale zaměstnanec nastoupil až {$start}. ČSSZ přijme jen"
                . ' změnu ke dni nástupu nebo pozdějšímu, jinak podání zamítne.'
                . ' Opravte datum ve formuláři, nebo zkontrolujte den nástupu'
                . ' na kartě pracovního vztahu.'
                . PayrollRegistrationFieldVocabulary::reference('effective_on'),
        );
    }

    /**
     * Nový nástup v A3 (job/@fro, ID 10223): zaměstnanci mladšímu 14 let
     * k tomuto dni ČSSZ podání zamítne na vstupu.
     *
     * @param array<string,mixed> $context
     * @param array<string,mixed> $delta
     */
    private function assertStartDateAge(
        int $supplierId,
        array $context,
        string $effectiveOn,
        array $delta,
    ): void {
        $start = is_array($delta['employment'] ?? null)
            ? ($delta['employment']['actual_start_on'] ?? null)
            : null;
        if (!is_string($start)) {
            return;
        }
        $current = $this->identities->sensitiveIdentityAt(
            $supplierId,
            (int) ($context['employee_id'] ?? 0),
            $effectiveOn,
        );
        $birthDate = $current['identity']['birth_date'] ?? null;
        if (is_string($birthDate)
            && PayrollRegistrationMinimumAge::isUnderage($birthDate, $start)
        ) {
            throw new PayrollRegistrationXmlException(
                'registration_a3_underage',
                'Zaměstnanci je k novému datu nástupu ' . $start . ' méně než '
                    . PayrollRegistrationMinimumAge::YEARS . ' let a ČSSZ takové '
                    . 'podání zamítne na vstupu. Zkontrolujte datum narození na '
                    . 'kartě osoby a datum nástupu.'
                    . PayrollRegistrationFieldVocabulary::reference('employment.actual_start_on'),
            );
        }
    }

    /** @param array<string,mixed> $context @param array<string,mixed> $input @return array<string,mixed> */
    private function termination(
        int $supplierId,
        string $environment,
        int $employmentId,
        string $effectiveOn,
        array $context,
        array $input,
        string $employmentExternalIdentifier,
    ): array {
        if (!in_array($context['status'] ?? null, ['ended', 'archived'], true)
            || ($context['end_date'] ?? null) !== $effectiveOn
        ) {
            throw new PayrollRegistrationXmlException(
                'registration_a2_end_source_mismatch',
                $this->endSourceMismatchMessage($effectiveOn, $context),
            );
        }
        $activityCode = $this->requiredCodeValue(
            $context['activity_code'] ?? null,
            'activity_code',
            2,
        );
        $detail = $context['jmhz_relationship_detail_code'] ?? null;
        $scenario = $this->a2Scenario($activityCode, $detail);
        try {
            $detail = PayrollRegistrationRelationshipDetailPolicy::requireForActivity(
                $activityCode,
                is_string($detail) ? $detail : null,
            );
        } catch (\InvalidArgumentException $exception) {
            throw new PayrollRegistrationXmlException(
                'registration_a2_relationship_detail_invalid',
                $exception->getMessage(),
            );
        }
        $endedByDeath = null;
        if ($scenario === 'OST') {
            $endedByDeath = $this->bool(
                $input['ended_by_death'] ?? null,
                'ended_by_death',
            );
        } elseif (array_key_exists('ended_by_death', $input)
            && $input['ended_by_death'] !== null
        ) {
            throw new PayrollRegistrationXmlException(
                'registration_a2_end_by_death_forbidden',
                'Ukončení pracovního vztahu úmrtím se u tohoto druhu činnosti'
                    . " (varianta A2-{$scenario}) neoznamuje. Nechte přepínač"
                    . ' ve formuláři prázdný.'
                    . PayrollRegistrationFieldVocabulary::reference('ended_by_death'),
            );
        }

        $evidence = $this->a2Plan(
            $supplierId,
            $environment,
            $employmentId,
            $effectiveOn,
            $employmentExternalIdentifier,
            true,
        );
        if ($evidence->decision() !== 'accepted') {
            throw new PayrollRegistrationXmlException(
                'registration_a2_jmhz_corrections_incomplete',
                $this->actionName(2)
                    . ' nejde schválit, dokud nejsou uzavřená opravná jednotná'
                    . ' měsíční hlášení zaměstnavatele (JMHZ) za období '
                    . implode(', ', $evidence->blockedPeriods())
                    . '. Hlášení za tato období dokončete a schválení zopakujte.',
            );
        }

        $unemployment = $this->unemployment(
            $input['unemployment'] ?? null,
            $scenario,
            $activityCode,
            $endedByDeath,
            $context,
            $this->earlyTerminationApplies($supplierId, $employmentId, $effectiveOn),
        );
        $this->assertMatchesTerminationRecord(
            $supplierId,
            $employmentId,
            $endedByDeath,
            $unemployment,
            (string) ($context['relation_type'] ?? ''),
        );

        $permit = $this->foreignPermit($supplierId, $employmentId);

        return [
            'end_on' => $effectiveOn,
            'activity_code' => $activityCode,
            'relationship_detail_code' => $detail,
            'a2_scenario' => $scenario,
            'ended_by_death' => $endedByDeath,
            'unemployment' => $unemployment,
            'jmhz_correction_evidence' => $evidence->toArray(),
        ] + ($permit === null ? [] : ['foreign_permit' => $permit]);
    }

    /**
     * Důvod skončení má jediný zdroj — záznam o skončení na kartě vztahu
     * (PayrollTerminationReason). Je-li vyplněný, odhláška A2 ho nesmí
     * tvrdit jinak: kód důvodu pro Úřad práce i příznak úmrtí se musí shodovat.
     * Bez záznamu zůstává A2 beze změny (ruční zadání jako dosud).
     *
     * @param array<string,mixed>|null $unemployment
     */
    private function assertMatchesTerminationRecord(
        int $supplierId,
        int $employmentId,
        ?bool $endedByDeath,
        ?array $unemployment,
        string $relationType = '',
    ): void {
        $record = $this->terminations->find($supplierId, $employmentId);
        if ($record === null) {
            return;
        }
        $reason = new PayrollTerminationReason(
            (string) $record['termination_method'],
            (string) $record['legal_ground'],
        );
        if ($endedByDeath !== null && $endedByDeath !== $reason->endedByDeath()) {
            throw new PayrollRegistrationXmlException(
                'registration_a2_termination_record_mismatch',
                'Přepínač „Skončení úmrtím" neodpovídá způsobu skončení zapsanému na kartě'
                    . ' vztahu v části Skončení vztahu. Opravte jedno z nich, ať obě místa'
                    . ' tvrdí totéž.',
            );
        }
        $code = $unemployment['termination_reason'] ?? null;
        $entitlement = $unemployment['entitlement'] ?? null;
        if (is_string($code)
            && $code === $reason->regzecReasonCode()
            && is_string($entitlement)
        ) {
            // Odstupné (10378 náleží, 10531 odstupné / 10530 náhrada § 271ca)
            // plyne ze záznamu o skončení stejně jako důvod — A2 nesmí
            // tvrdit „náleží" u skončení, které ho nezakládá, ani ho zamlčet.
            $expected = $reason->a2SettlementKind($relationType);
            $claimed = $entitlement === 'A'
                ? (array_key_exists('golden_handshake', $unemployment)
                    ? 'golden_handshake'
                    : (array_key_exists('replacement', $unemployment) ? 'replacement' : null))
                : null;
            if (($entitlement === 'A') !== ($expected !== null)
                || ($claimed !== null && $claimed !== $expected)
            ) {
                throw new PayrollRegistrationXmlException(
                    'registration_a2_settlement_record_mismatch',
                    'Údaj o odstupném v odhlášce neodpovídá záznamu Skončení vztahu: '
                        . match ($expected) {
                            'golden_handshake' => 'podle způsobu a důvodu skončení náleží'
                                . ' odstupné podle § 67 odst. 1 zákoníku práce.',
                            'replacement' => 'podle způsobu a důvodu skončení náleží'
                                . ' jednorázová náhrada podle § 271ca zákoníku práce.',
                            default => 'podle způsobu a důvodu skončení (a druhu vztahu)'
                                . ' odstupné nenáleží.',
                        }
                        . ' Použijte „Předvyplnit ze skončení vztahu", nebo opravte'
                        . ' záznam na kartě vztahu.'
                        . PayrollRegistrationFieldVocabulary::reference('unemployment.entitlement'),
                );
            }
        }
        if (is_string($code) && $code !== $reason->regzecReasonCode()) {
            throw new PayrollRegistrationXmlException(
                'registration_a2_termination_record_mismatch',
                "Důvod ukončení pro Úřad práce ({$code}) neodpovídá důvodu skončení zapsanému"
                    . ' na kartě vztahu v části Skončení vztahu (kód '
                    . $reason->regzecReasonCode() . '). Použijte „Předvyplnit ze skončení"'
                    . ' ve formuláři odhlášky, nebo opravte důvod na kartě vztahu.'
                    . PayrollRegistrationFieldVocabulary::reference('unemployment'),
            );
        }
    }

    private function a2Plan(
        int $supplierId,
        string $environment,
        int $employmentId,
        string $effectiveOn,
        string $employmentExternalIdentifier,
        bool $forUpdate,
    ): PayrollRegistrationA2EvidencePlan {
        return PayrollRegistrationA2EvidencePlan::create(
            $supplierId,
            $environment,
            $employmentId,
            $effectiveOn,
            $this->a2Evidence->correctiveMonths(
                $supplierId,
                $environment,
                $employmentId,
                $effectiveOn,
                $employmentExternalIdentifier,
                $forUpdate,
            ),
        );
    }

    /** @param array<string,mixed> $context @return array<string,mixed>|null */
    /**
     * Důvod předčasného ukončení (`unemplcomp/@earlyterm`, ID 10534) je podle
     * EDV 1.4.0.6 povinný u A2-OST a A2-SPEC, když cizinec s povolením
     * k zaměstnání, zaměstnaneckou nebo modrou kartou (druh oprávnění 1, 2, 4)
     * končí dřív, než oprávnění vyprší; jinak je zakázaný. Údaje o oprávnění
     * jsou v ověřeném profilu registrace A1.
     */
    private function earlyTerminationApplies(
        int $supplierId,
        int $employmentId,
        string $endOn,
    ): bool {
        return PayrollEmployeeRegistrationDeadlinePolicy::endsBeforePermitExpiry(
            $this->foreignPermit($supplierId, $employmentId),
            $endOn,
        );
    }

    /**
     * Pracovní oprávnění cizince z ověřeného profilu A1, pokud jde o povolení
     * k zaměstnání, zaměstnaneckou nebo modrou kartu (druh 1, 2, 4). Na nich
     * stojí důvod předčasného ukončení (ID 10534) i lhůty oznámení úřadu práce
     * podle § 88 odst. 1 zákona o zaměstnanosti, proto se k A2 a A8 zmrazí.
     *
     * @return array{type_code:string,permit_from:?string,permit_to:?string}|null
     */
    private function foreignPermit(int $supplierId, int $employmentId): ?array
    {
        $profile = $this->identities->a1Profile($supplierId, $employmentId);
        $worker = is_array($profile['foreign_worker'] ?? null)
            ? $profile['foreign_worker']
            : null;
        $type = $worker['permit_type_code'] ?? null;
        if (!is_string($type)
            || !in_array($type, PayrollEmployeeRegistrationDeadlinePolicy::FOREIGN_PERMIT_TYPES, true)
        ) {
            return null;
        }
        $date = static fn (mixed $value): ?string => is_string($value) && $value !== '' ? $value : null;

        return [
            'type_code' => $type,
            'permit_from' => $date($worker['permit_from'] ?? null),
            'permit_to' => $date($worker['permit_to'] ?? null),
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>|null
     */
    private function unemployment(
        mixed $value,
        string $scenario,
        string $activityCode,
        ?bool $endedByDeath,
        array $context,
        bool $earlyTerminationApplies = false,
    ): ?array {
        $early = null;
        if (is_array($value) && !array_is_list($value)
            && array_key_exists('early_termination_reason', $value)
        ) {
            $rawEarly = $value['early_termination_reason'];
            unset($value['early_termination_reason']);
            if ($value === []) {
                $value = null;
            }
            if ($rawEarly !== null) {
                $early = $this->earlyTerminationReason($rawEarly);
            }
        }
        $directlyEnded = $scenario === '10' || $endedByDeath === true;
        if ($early !== null && ($directlyEnded || !$earlyTerminationApplies)) {
            throw new PayrollRegistrationXmlException(
                'registration_a2_early_termination_forbidden',
                'Důvod předčasného ukončení se v odhlášce neposílá: uvádí se'
                    . ' jen u cizince s povolením k zaměstnání, zaměstnaneckou'
                    . ' nebo modrou kartou, jehož zaměstnání končí dřív, než'
                    . ' oprávnění vyprší. Nechte pole ve formuláři prázdné.'
                    . PayrollRegistrationFieldVocabulary::reference(
                        'unemployment.early_termination_reason',
                    ),
            );
        }
        if ($early === null && !$directlyEnded && $earlyTerminationApplies) {
            throw new PayrollRegistrationXmlException(
                'registration_a2_early_termination_required',
                'Zaměstnání cizince končí dřív, než vyprší jeho povolení'
                    . ' k zaměstnání, zaměstnanecká nebo modrá karta, a proto'
                    . ' ČSSZ vyžaduje důvod předčasného ukončení (1 až 3).'
                    . ' Doplňte ho ve formuláři odhlášky.'
                    . PayrollRegistrationFieldVocabulary::reference(
                        'unemployment.early_termination_reason',
                    ),
            );
        }
        $result = $this->unemploymentBody(
            $value,
            $scenario,
            $activityCode,
            $endedByDeath,
            $context,
        );
        if ($early === null) {
            return $result;
        }

        return ($result ?? []) + ['early_termination_reason' => $early];
    }

    /** @param array<string,mixed> $context @return array<string,mixed>|null */
    private function unemploymentBody(
        mixed $value,
        string $scenario,
        string $activityCode,
        ?bool $endedByDeath,
        array $context,
    ): ?array {
        if ($scenario === '10' || $endedByDeath === true) {
            if ($value !== null) {
                throw new PayrollRegistrationXmlException(
                    'registration_a2_unemployment_forbidden',
                    'Podklady pro podporu v nezaměstnanosti se u tohoto'
                        . ' skončení neposílají — jde o přímou variantu 10,'
                        . ' nebo o skončení úmrtím. Ve formuláři zvolte'
                        . ' možnost bez podkladů.'
                        . PayrollRegistrationFieldVocabulary::reference('unemployment'),
                );
            }
            return null;
        }
        if ($scenario === 'SPEC') {
            if ($value === null) {
                return null;
            }
            $this->onlyKeys(
                $this->object($value, 'unemployment'),
                [],
                'unemployment.',
                'u varianty A2-SPEC',
            );
            return null;
        }
        $input = $this->object($value, 'unemployment');
        $mode = $this->requiredCode($input, 'mode', 32, 'unemployment.mode');
        if ($mode === 'not_provided_2') {
            $this->onlyKeys(
                $input,
                ['mode'],
                'unemployment.',
                'u podkladů s důvodem neposkytnutí 2',
            );
            return ['reason_not_provided' => 2];
        }
        $periods = $this->pensionPeriods(
            $input['pension_periods'] ?? null,
            (string) ($context['start_date'] ?? ''),
            (string) ($context['end_date'] ?? ''),
        );
        $average = $this->requiredAmount(
            $input['average_net_earnings'] ?? null,
            'unemployment.average_net_earnings',
        );
        if ($mode === 'not_provided_3') {
            $this->onlyKeys(
                $input,
                ['mode', 'average_net_earnings', 'pension_periods'],
                'unemployment.',
                'u podkladů s důvodem neposkytnutí 3',
            );
            return [
                'reason_not_provided' => 3,
                'average_net_earnings' => $average,
                'pension_periods' => $periods,
            ];
        }
        if ($mode !== 'provided') {
            throw new \InvalidArgumentException($this->note(
                'unemployment.mode',
                'není podporovaný. Vyberte jednu z nabízených možností:'
                    . ' podklady nebudou poskytnuty (důvod 2), částečné'
                    . ' podklady (důvod 3), nebo úplné podklady.'
                    . " Teď je zadáno „{$mode}“.",
            ));
        }
        $result = [
            'average_net_earnings' => $average,
            'pension_periods' => $periods,
        ];
        if (in_array($activityCode, ['M', 'N', 'O', 'P', 'Q', 'R', 'S'], true)) {
            $this->onlyKeys(
                $input,
                ['mode', 'average_net_earnings', 'pension_periods'],
                'unemployment.',
                "u druhu činnosti „{$activityCode}“",
            );
            return $result;
        }
        $type = $this->requiredDigits(
            $input['employment_type'] ?? null,
            'unemployment.employment_type',
            1,
            3,
        );
        if (!in_array($type, ['1', '2'], true)) {
            throw new \InvalidArgumentException($this->say(
                'unemployment.employment_type',
                "musí být 1 (pracovní vztah), nebo 2 (služební poměr),"
                    . " teď je „{$type}“.",
            ));
        }
        $result['employment_type'] = $type;
        if ($type === '1') {
            $this->onlyKeys($input, [
                'mode', 'average_net_earnings', 'pension_periods',
                'employment_type', 'termination_reason', 'entitlement',
                'paid_in_full', 'replacement', 'golden_handshake',
            ], 'unemployment.', 'u podkladů za pracovní vztah');
            $reason = $this->employmentTerminationReason(
                $input['termination_reason'] ?? null,
            );
            $result['termination_reason'] = $reason;
            $this->settlement(
                $input,
                $result,
                $reason,
                ['replacement', 'golden_handshake'],
            );
        } else {
            $this->onlyKeys($input, [
                'mode', 'average_net_earnings', 'pension_periods',
                'employment_type', 'service_termination_reason', 'entitlement',
                'paid_in_full', 'severance_pay', 'disposal',
            ], 'unemployment.', 'u podkladů za služební poměr');
            $reason = $this->serviceTerminationReason(
                $input['service_termination_reason'] ?? null,
            );
            $result['service_termination_reason'] = $reason;
            $this->settlement(
                $input,
                $result,
                $reason,
                ['severance_pay', 'disposal'],
            );
        }
        return $result;
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $result @param list<string> $amountKeys */
    private function settlement(
        array $input,
        array &$result,
        string $reason,
        array $amountKeys,
    ): void {
        $hasEntitlement = array_key_exists('entitlement', $input);
        $hasFullPay = array_key_exists('paid_in_full', $input);
        $providedAmounts = array_values(array_filter(
            $amountKeys,
            static fn (string $key): bool => array_key_exists($key, $input),
        ));
        if (!in_array($reason, ['4', '5'], true)) {
            if ($hasEntitlement || $hasFullPay || $providedAmounts !== []) {
                $sent = array_values(array_merge(
                    $hasEntitlement ? ['entitlement'] : [],
                    $hasFullPay ? ['paid_in_full'] : [],
                    $providedAmounts,
                ));
                throw new PayrollRegistrationXmlException(
                    'registration_a2_settlement_forbidden',
                    $this->names('unemployment.', $sent)
                        . (count($sent) === 1 ? ' se posílá' : ' se posílají')
                        . ' jen u důvodu skončení 4 nebo 5, teď je zvolený'
                        . " důvod {$reason}. "
                        . (count($sent) === 1
                            ? 'Nechte příslušné pole ve formuláři prázdné'
                            : 'Nechte příslušná pole ve formuláři prázdná')
                        . ', nebo změňte důvod skončení.'
                        . $this->references('unemployment.', $sent),
                );
            }
            return;
        }
        $entitlement = $this->bool(
            $input['entitlement'] ?? null,
            'unemployment.entitlement',
        );
        $result['entitlement'] = $entitlement ? 'A' : 'N';
        if (!$entitlement) {
            if ($hasFullPay || $providedAmounts !== []) {
                $sent = array_values(array_merge(
                    $hasFullPay ? ['paid_in_full'] : [],
                    $providedAmounts,
                ));
                throw new PayrollRegistrationXmlException(
                    'registration_a2_settlement_payment_forbidden',
                    $this->names('unemployment.', $sent)
                        . (count($sent) === 1 ? ' se posílá' : ' se posílají')
                        . ' jen tehdy, když nárok na odstupné vznikl. '
                        . (count($sent) === 1
                            ? 'Nechte příslušné pole ve formuláři prázdné'
                            : 'Nechte příslušná pole ve formuláři prázdná')
                        . ', nebo nárok na odstupné zaškrtněte.'
                        . $this->references('unemployment.', $sent),
                );
            }
            return;
        }
        $result['paid_in_full'] = $this->yesNo(
            $input['paid_in_full'] ?? null,
            'unemployment.paid_in_full',
        );
        if (count($providedAmounts) !== 1) {
            throw new PayrollRegistrationXmlException(
                'registration_a2_settlement_amount_required',
                'Částka plnění chybí, nebo je vyplněná víc než jedna. Při'
                    . ' nároku na odstupné vyplňte právě jednu z těchto'
                    . ' částek: '
                    . $this->names('unemployment.', $amountKeys, false)
                    . '.' . $this->references('unemployment.', $amountKeys),
            );
        }
        $key = $providedAmounts[0];
        $result[$key] = $this->requiredAmount($input[$key], 'unemployment.' . $key);
    }

    private function a2Scenario(string $activityCode, mixed $detail): string
    {
        return PayrollRegistrationBusinessMatrix::requireActionVariant(
            2,
            $activityCode,
            is_string($detail) ? $detail : null,
        );
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function change(string $effectiveOn, array $input): array
    {
        $data = $this->delta($input, false);
        if (array_key_exists('relationship_detail_code', $data['delta'])) {
            throw new PayrollRegistrationXmlException(
                'registration_a3_activity_explanation_attachment_required',
                'Bližší určení pracovněprávního vztahu se přes '
                    . $this->actionName(3)
                    . ' ohlásit nedá: ČSSZ k němu vyžaduje přílohu'
                    . ' s vysvětlením, kterou aplikace zatím neumí přiložit.'
                    . ' Změnu vyřiďte s ČSSZ mimo aplikaci.'
                    . PayrollRegistrationFieldVocabulary::reference(
                        'relationship_detail_code',
                    ),
            );
        }
        $taxResidency = $data['delta']['tax_residency'] ?? null;
        if (is_array($taxResidency)
            && ($taxResidency['changed_on'] ?? null) !== $effectiveOn
        ) {
            throw new PayrollRegistrationXmlException(
                'registration_a3_effective_date_mismatch',
                'Datum změny daňové rezidence ('
                    . (string) ($taxResidency['changed_on'] ?? '')
                    . ') se musí shodovat s dnem, ke kterému se změna hlásí ('
                    . $effectiveOn . '). Jedno podání nese vždy jen jedno'
                    . ' datum účinnosti — srovnejte obě data, nebo změnu'
                    . ' rezidence ohlaste samostatně.'
                    . PayrollRegistrationFieldVocabulary::reference(
                        'tax_residency.changed_on',
                    ),
            );
        }
        $this->assertTaxResidencyAddress($data['delta']);

        return $data;
    }

    /**
     * Rezidence v jiném státě než ČR musí nést adresu bydliště (`rdr`, zásady
     * REGZEC 10519 až 10524). Pravidlo je totéž jako u přihlášky A1, proto ho
     * drží {@see PayrollRegistrationTaxResidencyRule}.
     *
     * @param array<string,mixed> $delta
     */
    private function assertTaxResidencyAddress(array $delta): void
    {
        $residency = $delta['tax_residency'] ?? null;
        if (!is_array($residency)
            || !PayrollRegistrationTaxResidencyRule::requiresResidenceAddress(
                is_string($residency['country_code'] ?? null)
                    ? $residency['country_code']
                    : null,
            )
            || is_array($residency['residence_address'] ?? null)
        ) {
            return;
        }

        throw new PayrollRegistrationXmlException(
            'registration_tax_residence_address_missing',
            'Při změně daňové rezidence na jiný stát než ČR musí podání nést'
                . ' i adresu bydliště v tom státě: ČSSZ ji u daňového'
                . ' rezidenta jiného státu vyžaduje. Vyplňte ji v profilu'
                . ' registrace A1 a změnu ohlaste z návrhu v bloku Změny'
                . ' k ohlášení.'
                . PayrollRegistrationFieldVocabulary::reference(
                    'tax_residency.residence_address',
                ),
        );
    }

    /**
     * Dohlášení údajů (A3) z ověřeného profilu registrace A1.
     *
     * Delta se neskládá z toho, co pošle formulář, ale z profilu uloženého
     * na serveru: jde o prvotní naplnění registru a ČSSZ z něj bere poslední
     * stav údajů. Profil se proto staví přísně — neúplný nepustíme dál.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function profileCompletion(
        int $supplierId,
        string $environment,
        int $employmentId,
        array $context,
        string $mode,
        string $sourceOn,
    ): array {
        $employeeId = (int) ($context['employee_id'] ?? 0);
        $startOn = $context['actual_start_date'] ?? $context['start_date'] ?? null;
        if (!is_string($startOn) || $startOn === '') {
            throw new PayrollRegistrationXmlException(
                'registration_start_date_missing',
                'Datum nástupu u pracovního vztahu chybí, takže nejde najít '
                    . 'profil registrace, ze kterého se dohlášení skládá. '
                    . PayrollRegistrationFieldVocabulary::describe('contract_start_on'),
            );
        }
        $source = $this->identities->sensitiveSnapshotSourceAt(
            $supplierId,
            $employeeId,
            $employmentId,
            $environment,
            $startOn,
        );
        $profile = $source['regzec_a1'] ?? null;
        if (!is_array($profile)) {
            throw new PayrollRegistrationXmlException(
                'registration_a3_completion_profile_missing',
                $this->actionName(3) . ' s dohlášením údajů se skládá z profilu '
                    . 'registrace (A1), který u tohoto pracovního vztahu chybí. '
                    . 'Otevřete u vztahu registraci, vyplňte profil A1 a uložte '
                    . 'ho; potom dohlášení zopakujte.',
            );
        }
        try {
            $a1 = (new PayrollRegistrationA1SnapshotBuilder())->build(
                $profile,
                $source['identity'],
                [
                    'supplier_id' => $supplierId,
                    'employee_id' => $employeeId,
                    'employment_id' => $employmentId,
                    'effective_on' => $startOn,
                ],
                ($source['employer_protected_labor_market'] ?? false) === true,
                PayrollRegistrationIdentityRequirements::completionIdentityFields($mode),
            );
        } catch (PayrollRegistrationIdentitySnapshotException $exception) {
            throw new PayrollRegistrationXmlException(
                'registration_a3_completion_profile_incomplete',
                'Profil registrace (A1) není úplný, takže z něj dohlášení '
                    . 'nejde sestavit. Doplňte ho v registraci u pracovního '
                    . 'vztahu (tlačítko Kontrola ukáže všechno, co chybí). '
                    . $exception->getMessage(),
            );
        }
        $current = $this->identities->sensitiveIdentityAt(
            $supplierId,
            $employeeId,
            $sourceOn,
        );
        if ($mode === PayrollRegistrationProfileCompletion::FULL) {
            // Dohlášení nese datum narození i pohlaví, ČSSZ je kontroluje
            // proti rodnému číslu (EDV 1.4.0.6, ID 10056 a 10059).
            $mismatch = PayrollRegistrationBirthNumberConsistency::problems(
                $current['identity'],
                CzechBirthNumber::forSubmission(
                    is_string($current['identifiers']['birth_number'] ?? null)
                        ? $current['identifiers']['birth_number']
                        : null,
                ),
            );
            if ($mismatch !== []) {
                throw new PayrollRegistrationXmlException(
                    $mismatch[0]['code'],
                    $mismatch[0]['message'],
                );
            }
        }
        $endDate = $context['end_date'] ?? null;
        // EDV 1.4.0.6, ID 10092: u druhu „N" nese dohlášení A3 i cizozemského
        // nositele; profil A1 ho u tohoto druhu vyžaduje už při sestavení.
        $foreignInsurance = $a1->foreignInsurance === null
            || ($a1->employment['activity_code'] ?? null) !== 'N'
            ? []
            : ['foreign_insurance' => array_filter(
                $a1->foreignInsurance,
                static fn (mixed $value): bool => $value !== null,
            )];

        return $foreignInsurance + [
            'completion' => $mode,
            'delta' => PayrollRegistrationProfileCompletion::delta(
                $a1,
                $current['identity'],
                $current['identifiers'],
                $mode,
                is_string($endDate) && $endDate !== '' && $endDate <= $sourceOn
                    ? $endDate
                    : null,
            ),
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function delta(array $input, bool $correction): array
    {
        $listPath = $correction ? 'corrections' : 'changes';
        $raw = $this->object($input[$listPath] ?? null, $listPath);
        if ($correction && array_key_exists('contract_start_on', $raw)) {
            throw new PayrollRegistrationXmlException(
                'registration_a4_explanation_attachment_required',
                'Sjednaný den nástupu se přes ' . $this->actionName(4)
                    . ' opravit nedá: ČSSZ k němu vyžaduje písemné'
                    . ' vysvětlení, které aplikace zatím neumí přiložit.'
                    . ' Opravu vyřiďte s ČSSZ mimo aplikaci.'
                    . PayrollRegistrationFieldVocabulary::reference(
                        'contract_start_on',
                    ),
            );
        }
        $allowed = $correction
            ? ['title_prefix', 'tax_residency', 'relationship_detail_code', 'highest_education_code', 'employment']
            : [
                'title_prefix', 'contact_address', 'tax_residency',
                'relationship_detail_code', 'health_insurance_code',
                'highest_education_code', 'employment',
                'permanent_address', 'foreign_worker',
                'identity', 'pension', 'facts', 'czech_residence_address',
                'proof_identity', 'foreign_legislation',
            ];
        $this->onlyKeys(
            $raw,
            $allowed,
            '',
            'v podání „' . $this->actionName($correction ? 4 : 3) . '“',
        );
        if ($raw === []) {
            throw new \InvalidArgumentException($this->note(
                $listPath,
                'je prázdný. Vyberte aspoň jeden údaj, který se má ohlásit.',
            ));
        }
        $result = [];
        foreach ($raw as $key => $value) {
            $result[$key] = match ($key) {
                'title_prefix' => $this->requiredText($value, 'title_prefix', 30),
                'relationship_detail_code' => $this->requiredDigits(
                    $value,
                    'relationship_detail_code',
                    1,
                    1,
                ),
                'health_insurance_code' => $this->healthInsuranceCode($value),
                'contract_start_on' => $this->date($value, 'contract_start_on'),
                'highest_education_code' => $this->requiredCodeValue(
                    $value,
                    'highest_education_code',
                    1,
                ),
                'tax_residency' => $this->taxResidency($value),
                'contact_address' => $this->contactAddress($value),
                'permanent_address' => $this->permanentAddress($value),
                'foreign_worker' => $this->foreignWorkerChange($value),
                'employment' => $this->employmentChange($value),
                'identity' => $this->identityChange($value),
                'pension' => $this->pensionChange($value),
                'facts' => $this->factsChange($value),
                'czech_residence_address' => $this->czechResidenceAddress($value),
                'proof_identity' => $this->proofIdentityChange($value),
                'foreign_legislation' => $this->foreignLegislationChange($value),
                // Interní kontrakt: klíče už prošly onlyKeys() výš, sem se
                // uživatelský vstup nedostane. Zůstává technická — akce ji
                // nechytá, protože jde o chybu programu.
                default => throw new \LogicException(
                    'Neznámé delta pole registrační události: ' . $key,
                ),
            };
        }
        if ($correction && is_array($result['employment'] ?? null)) {
            // Oprava A4 z pracovních údajů nese jen skutečný den nástupu
            // (zásady REGZEC, specifický postup č. 10 d), vždy s písemným
            // vysvětlením v příloze.
            $this->onlyKeys(
                $result['employment'],
                ['actual_start_on'],
                'employment.',
                'v podání „' . $this->actionName(4) . '“',
            );
        }
        return ['delta' => $result];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function correction(
        int $supplierId,
        string $environment,
        int $employmentId,
        string $effectiveOn,
        array $context,
        array $input,
        string $employmentExternalIdentifier,
    ): array {
        $submissionId = $this->positive(
            $input['source_submission_id'] ?? null,
            'source_submission_id',
        );
        $source = $this->events->acceptedRegistration(
            $supplierId,
            $environment,
            $employmentId,
            $submissionId,
        );
        if ($source === null) {
            throw new PayrollRegistrationXmlException(
                'registration_a4_source_submission_invalid',
                'Číslo původního přijatého podání neodpovídá žádnému podání,'
                    . ' které ČSSZ u tohoto pracovního vztahu a v tomto'
                    . ' prostředí přijala. Vyberte podání ze seznamu'
                    . ' odeslaných podání.'
                    . PayrollRegistrationFieldVocabulary::reference(
                        'source_submission_id',
                    ),
            );
        }
        $frozenSource = $this->acceptedSourceArtifact(
            $supplierId,
            $source,
            $effectiveOn,
            $employmentExternalIdentifier,
        );
        $data = $this->delta($input, true);
        $delta = $data['delta'];
        $this->assertTaxResidencyAddress($delta);
        if (isset($delta['employment']['actual_start_on'])) {
            // Zásady REGZEC, specifický postup č. 10 (d): přihláška A1 podaná
            // s předpokládaným nástupem se při jiném skutečném nástupu opraví
            // A4. Oprava 10223 jde jen s průvodním dopisem v příloze, ÚSSZ ji
            // zpracuje referentsky.
            $this->assertStartDateAge($supplierId, $context, $effectiveOn, $delta);
            $data['explanation_attachment'] = $this->explanationAttachment(
                $input['explanation_attachment'] ?? null,
                4,
            );
        }
        if (array_key_exists('relationship_detail_code', $delta)) {
            $sourceActivity = $frozenSource['activity_code'];
            if (!is_string($sourceActivity) || $sourceActivity === '') {
                throw new PayrollRegistrationXmlException(
                    'registration_a4_source_activity_missing',
                    'Druh činnosti pro ČSSZ v původním přijatém podání chybí,'
                        . ' takže podle něj opravu ověřit nejde. Vyberte jiné'
                        . ' původní podání, nebo opravu vyřiďte s ČSSZ mimo'
                        . ' aplikaci.'
                        . PayrollRegistrationFieldVocabulary::reference(
                            'source.activity_code',
                        ),
                );
            }
            PayrollRegistrationBusinessMatrix::requireActivityCorrectionTransition(
                $sourceActivity,
                $frozenSource['relationship_detail_code'],
                $this->requiredCodeValue(
                    $context['activity_code'] ?? null,
                    'activity_code',
                    2,
                ),
                (string) $delta['relationship_detail_code'],
            );
            throw new PayrollRegistrationXmlException(
                'registration_a4_activity_explanation_attachment_required',
                'Bližší určení pracovněprávního vztahu se přes '
                    . $this->actionName(4)
                    . ' opravit nedá: ČSSZ k němu vyžaduje přílohu'
                    . ' s vysvětlením, kterou aplikace zatím neumí přiložit.'
                    . ' Opravu vyřiďte s ČSSZ mimo aplikaci.'
                    . PayrollRegistrationFieldVocabulary::reference(
                        'relationship_detail_code',
                    ),
            );
        }

        return $data + [
            'source_submission_id' => $submissionId,
            'source_snapshot_hash' => (string) $source['source_snapshot_hash'],
            'source_part_id' => (int) $source['part_id'],
            'source_artifact_id' => (int) $source['artifact_id'],
            'source_artifact_sha256' => (string) $source['artifact_sha256'],
            'source_action_code' => $frozenSource['action_code'],
            'source_filing_on' => $frozenSource['filing_on'],
            'source_employment_external_identifier' =>
                $frozenSource['employment_external_identifier'],
        ];
    }

    /** @param array<string,mixed> $source @return array{action_code:int,filing_on:string,employment_external_identifier:?string,activity_code:?string,relationship_detail_code:?string} */
    private function acceptedSourceArtifact(
        int $supplierId,
        array $source,
        string $effectiveOn,
        string $employmentExternalIdentifier,
    ): array {
        $artifactId = (int) ($source['artifact_id'] ?? 0);
        if ($artifactId <= 0) {
            throw new PayrollRegistrationXmlException(
                'registration_a4_source_artifact_missing',
                'Původní přijaté podání nemá v archivu uložený odeslaný'
                    . ' soubor, podle kterého se oprava ověřuje. Vyberte jiné'
                    . ' původní podání, nebo opravu vyřiďte s ČSSZ mimo'
                    . ' aplikaci.'
                    . PayrollRegistrationFieldVocabulary::reference(
                        'source_submission_id',
                    ),
            );
        }
        $xml = $this->submissions->artifactBytes($supplierId, $artifactId);
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $document->documentElement;
        if (!$loaded || !$root instanceof DOMElement
            || $root->localName !== 'REGZEC'
            || $root->namespaceURI !== 'http://schemas.cssz.cz/REGZEC/2025'
        ) {
            throw new PayrollRegistrationXmlException(
                'registration_a4_source_artifact_invalid',
                'Archivovaný soubor původního podání není čitelné podání'
                    . ' REGZEC, takže podle něj opravu ověřit nejde. Vyberte'
                    . ' jiné původní podání.'
                    . PayrollRegistrationFieldVocabulary::reference(
                        'source_submission_id',
                    ),
            );
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('r', $root->namespaceURI);
        $employees = $xpath->query('/r:REGZEC/r:employees/r:employee');
        if ($employees === false || $employees->length !== 1
            || !$employees->item(0) instanceof DOMElement
        ) {
            throw new PayrollRegistrationXmlException(
                'registration_a4_source_artifact_invalid',
                'Archivovaný soubor původního podání obsahuje jiný počet'
                    . ' zaměstnanců než jednoho, takže podle něj opravu ověřit'
                    . ' nejde. Vyberte podání, které se týká jen tohoto'
                    . ' zaměstnance.'
                    . PayrollRegistrationFieldVocabulary::reference(
                        'source_submission_id',
                    ),
            );
        }
        /** @var DOMElement $employee */
        $employee = $employees->item(0);
        $filingOn = $employee->getAttribute('dat');
        if ($this->date($filingOn, 'source_filing_on') !== $effectiveOn) {
            throw new PayrollRegistrationXmlException(
                'registration_a4_original_filing_date_mismatch',
                'Datum původního opravovaného podání se musí přesně shodovat'
                    . ' s datem v archivovaném původním podání'
                    . " ({$filingOn}), teď je ve formuláři {$effectiveOn}."
                    . ' Opravte datum ve formuláři.'
                    . PayrollRegistrationFieldVocabulary::reference('effective_on'),
            );
        }
        $action = $employee->getAttribute('act');
        if (preg_match('/^[1-8]$/D', $action) !== 1) {
            throw new PayrollRegistrationXmlException(
                'registration_a4_source_action_invalid',
                'Archivované původní podání nemá rozpoznatelný druh podání,'
                    . ' takže podle něj opravu ověřit nejde. Vyberte jiné'
                    . ' původní podání.'
                    . PayrollRegistrationFieldVocabulary::reference(
                        'source_submission_id',
                    ),
            );
        }
        $jobs = $xpath->query('/r:REGZEC/r:employees/r:employee/r:job');
        $job = $jobs === false ? null : $jobs->item(0);
        $sourceIdentifier = $job instanceof DOMElement
            ? trim($job->getAttribute('oid'))
            : '';
        if ($sourceIdentifier !== ''
            && ($employmentExternalIdentifier === ''
                || !hash_equals($sourceIdentifier, $employmentExternalIdentifier))
        ) {
            throw new PayrollRegistrationXmlException(
                'registration_a4_source_identity_mismatch',
                'Identifikátor pracovního vztahu od ČSSZ (ID PPV) v původním'
                    . ' přijatém podání patří jinému pracovnímu vztahu.'
                    . ' Vyberte podání, které se týká opravovaného vztahu.'
                    . PayrollRegistrationFieldVocabulary::reference(
                        'employment_external_identifier',
                    ),
            );
        }
        $sourceActivity = $job instanceof DOMElement
            ? trim($job->getAttribute('rel'))
            : '';
        $sourceRelationshipDetail = $job instanceof DOMElement
            ? trim($job->getAttribute('relDetail'))
            : '';

        return [
            'action_code' => (int) $action,
            'filing_on' => $filingOn,
            'employment_external_identifier' => $sourceIdentifier === ''
                ? null
                : $sourceIdentifier,
            'activity_code' => $sourceActivity === '' ? null : $sourceActivity,
            'relationship_detail_code' => $sourceRelationshipDetail === ''
                ? null
                : $sourceRelationshipDetail,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function cancellation(
        int $supplierId,
        string $environment,
        int $employmentId,
        string $effectiveOn,
        array $context,
        array $input,
    ): array {
        $submissionId = $this->positive(
            $input['source_submission_id'] ?? null,
            'source_submission_id',
        );
        if ($this->events->acceptedRegistration(
            $supplierId,
            $environment,
            $employmentId,
            $submissionId,
        ) === null) {
            throw new PayrollRegistrationXmlException(
                'registration_a8_source_submission_invalid',
                'Číslo původního přijatého podání neodpovídá žádnému podání,'
                    . ' které ČSSZ u tohoto pracovního vztahu a v tomto'
                    . ' prostředí přijala. Vyberte podání ze seznamu'
                    . ' odeslaných podání.'
                    . PayrollRegistrationFieldVocabulary::reference(
                        'source_submission_id',
                    ),
            );
        }
        $notStarted = $input['not_started'] ?? null;
        if ($notStarted === false) {
            // Zásady REGZEC (18-06-2026), kód akce 8: storno z jiného důvodu
            // než nenastoupení (chybný variabilní symbol, nepovolená oprava
            // druhu činnosti, soudní zneplatnění) je nutné zdůvodnit
            // samostatnou písemností v příloze podání.
            return [
                'not_started' => false,
                'source_submission_id' => $submissionId,
                'explanation_attachment' => $this->explanationAttachment(
                    $input['explanation_attachment'] ?? null,
                ),
            ];
        }
        if ($notStarted !== true) {
            throw new PayrollRegistrationXmlException(
                'registration_a8_explanation_attachment_required',
                $this->actionName(8)
                    . ' potřebuje vědět, proč se podává: buď zaměstnanec vůbec'
                    . ' nenastoupil (zaškrtněte to ve formuláři), nebo jde'
                    . ' o jiný důvod — pak přiložte soubor s písemným'
                    . ' zdůvodněním, bez něj ČSSZ storno nezpracuje.'
                    . PayrollRegistrationFieldVocabulary::reference('not_started'),
            );
        }
        if (($context['status'] ?? null) !== 'no_show'
            || ($context['start_date'] ?? null) !== $effectiveOn
        ) {
            $recorded = is_string($context['start_date'] ?? null)
                && $context['start_date'] !== ''
                    ? "v evidenci je den nástupu {$context['start_date']}"
                    : 'v evidenci žádný den nástupu není';
            throw new PayrollRegistrationXmlException(
                'registration_a8_no_show_source_mismatch',
                $this->actionName(8)
                    . ' jde schválit až tehdy, když je pracovní vztah'
                    . ' v evidenci označený jako nenastoupený a datum ve'
                    . " formuláři ({$effectiveOn}) se shoduje s původním"
                    . " plánovaným dnem nástupu; {$recorded}. Stav vztahu"
                    . ' i datum nastavte na kartě pracovního vztahu.'
                    . PayrollRegistrationFieldVocabulary::reference(
                        'planned_start_on',
                    ),
            );
        }
        $permit = $this->foreignPermit($supplierId, $employmentId);

        return [
            'not_started' => true,
            'source_submission_id' => $submissionId,
        ] + ($permit === null ? [] : ['foreign_permit' => $permit]);
    }

    /**
     * Soubor se zdůvodněním storna, který jde do přílohy podání A8
     * (`attachs/attach`). Stejné meze jako přílohy profilu A1: název,
     * nepoškozený obsah v base64 a rozumná velikost.
     *
     * @return array{name:string,description:?string,data_base64:string}
     */
    private function explanationAttachment(mixed $value, int $actionCode = 8): array
    {
        if (!is_array($value) || array_is_list($value)) {
            if ($actionCode === 4) {
                throw new PayrollRegistrationXmlException(
                    'registration_a4_start_attachment_required',
                    'Opravu skutečného dne nástupu přes ' . $this->actionName(4)
                        . ' ČSSZ zpracuje jen s průvodním dopisem v příloze.'
                        . ' Přiložte ve formuláři soubor, který opravu'
                        . ' vysvětluje (například že zaměstnanec nastoupil'
                        . ' jindy, než bylo v přihlášce).'
                        . PayrollRegistrationFieldVocabulary::reference(
                            'explanation_attachment',
                        ),
                );
            }
            throw new PayrollRegistrationXmlException(
                'registration_a8_explanation_attachment_required',
                $this->actionName(8)
                    . ' z jiného důvodu než nenastoupení ČSSZ zpracuje jen'
                    . ' s písemným zdůvodněním v příloze. Přiložte ve'
                    . ' formuláři soubor, který důvod storna vysvětluje'
                    . ' (například chybný variabilní symbol nebo druh'
                    . ' činnosti).'
                    . PayrollRegistrationFieldVocabulary::reference(
                        'explanation_attachment',
                    ),
            );
        }
        $invalidCode = $actionCode === 4
            ? 'registration_a4_explanation_attachment_invalid'
            : 'registration_a8_explanation_attachment_invalid';
        $name = $this->requiredText(
            $value['name'] ?? null,
            'explanation_attachment.name',
            255,
        );
        $data = $value['data_base64'] ?? null;
        if (!is_string($data) || $data === ''
            || strlen($data) > 20_000_000
            || base64_decode($data, true) === false
        ) {
            throw new PayrollRegistrationXmlException(
                $invalidCode,
                'Soubor s písemným vysvětlením se nepodařilo přečíst. Přiložte'
                    . ' ho ve formuláři znovu.'
                    . PayrollRegistrationFieldVocabulary::reference(
                        'explanation_attachment.data_base64',
                    ),
            );
        }
        foreach (PayrollRegistrationAttachmentRules::violations([
            ['name' => $name, 'data_base64' => $data],
        ]) as $violation) {
            throw new PayrollRegistrationXmlException(
                $invalidCode,
                PayrollRegistrationAttachmentRules::message($violation)
                    . PayrollRegistrationFieldVocabulary::reference(
                        'explanation_attachment',
                    ),
            );
        }
        $description = $value['description'] ?? null;

        return [
            'name' => $name,
            'description' => is_string($description) && trim($description) !== ''
                ? mb_substr(trim($description), 0, 255)
                : null,
            'data_base64' => $data,
        ];
    }

    /** @param array<string,mixed> $context @param array<string,mixed> $input */
    private function notificationTriggerOn(
        string $interaction,
        string $effectiveOn,
        array $context,
        array $input,
    ): string {
        if ($interaction === 'correction') {
            $discoveredOn = $this->date(
                $input['discovered_on'] ?? null,
                'discovered_on',
            );
            if ($discoveredOn < $effectiveOn) {
                throw new \InvalidArgumentException($this->note(
                    'discovered_on',
                    "nesmí být dřív než datum původního opravovaného podání"
                        . " ({$effectiveOn}), teď je {$discoveredOn}."
                        . ' Opravte jedno z obou dat.',
                ));
            }
            return $discoveredOn;
        }
        if ($interaction === 'change' && ($input['learned_on'] ?? null) !== null) {
            // Platnost změny (`effective_on`, atribut 10009) a začátek osmidenní
            // lhůty (§ 19 odst. 5 zákona č. 323/2025 Sb.: ode dne, kdy se
            // zaměstnavatel o změně dozvěděl) jsou dvě různá data. Změna
            // zjištěná zpětně se hlásí s platností od skutečného dne a lhůta
            // běží od zjištění; změna, o které se ví předem, se nehlásí dřív,
            // než nastane.
            $learnedOn = $this->date($input['learned_on'], 'learned_on');

            return max($effectiveOn, $learnedOn);
        }
        if ($interaction === 'cancellation'
            && ($input['not_started'] ?? null) !== false
        ) {
            return $this->date(
                $context['start_date'] ?? null,
                'planned_start_on',
            );
        }

        return $effectiveOn;
    }

    /**
     * Zásady REGZEC 1.4.6, Specifický postup č. 1 a atribut 10427: byl-li
     * vztah přihlášen akcí A1 s příslušností k cizím právním předpisům od
     * počátku a později vznikne příslušnost k českým předpisům, nelze
     * z technických důvodů použít A3 ani A6. Podává se skončení (A2) a nová
     * přihláška (A1) ode dne změny. A6 by ČSSZ odmítla.
     *
     * @return array{}
     */
    private function assertNotForeignFromStart(
        int $supplierId,
        string $environment,
        int $employmentId,
    ): array {
        $registered = $this->identities->a1ProfileAsRegistered(
            $supplierId,
            $environment,
            $employmentId,
        );
        $legislation = is_array($registered['foreign_legislation'] ?? null)
            ? $registered['foreign_legislation']
            : [];
        if (($legislation['applies'] ?? null) !== true) {
            return [];
        }
        $country = is_string($legislation['country_code'] ?? null)
            && $legislation['country_code'] !== ''
                ? ' (' . $legislation['country_code'] . ')'
                : '';
        throw new PayrollRegistrationXmlException(
            'registration_a6_foreign_from_start',
            $this->actionName(6) . ' nejde podat: pracovní vztah byl přihlášen'
                . ' s příslušností k cizím právním předpisům' . $country
                . ' od počátku. ČSSZ v tom případě vznik příslušnosti k českým'
                . ' předpisům neumí přijmout přes A3 ani A6. Podejte skončení'
                . ' zaměstnání (REGZEC A2) a novou přihlášku (REGZEC A1) dnem,'
                . ' kdy začaly platit české předpisy, i když zaměstnání trvá'
                . ' dál.'
                . PayrollRegistrationFieldVocabulary::reference(
                    'foreign_legislation.applies',
                ),
        );
    }

    /** @param array<string,mixed> $input @return array<string,string> */
    private function foreignInsurance(array $input, string $expectedCurrent): array
    {
        $raw = $this->object($input['foreign_insurance'] ?? null, 'foreign_insurance');
        $current = $this->requiredCodeValue(
            $raw['current'] ?? null,
            'foreign_insurance.current',
            1,
        );
        if ($current !== $expectedCurrent) {
            // P se posílá při vzniku příslušnosti k českým předpisům (A6),
            // S při jejím skončení (A7).
            throw new PayrollRegistrationXmlException(
                'registration_jurisdiction_direction_mismatch',
                $this->note(
                    'foreign_insurance.current',
                    "musí být „{$expectedCurrent}“, protože se podává "
                        . $this->actionName($expectedCurrent === 'P' ? 6 : 7)
                        . ". Teď je zadáno „{$current}“; pro opačný směr"
                        . ' vyberte druhou z těchto událostí.',
                ),
            );
        }
        $result = [
            'current' => $current,
            'name' => $this->requiredText(
                $raw['name'] ?? null,
                'foreign_insurance.name',
                100,
            ),
            'country_code' => $this->country(
                $raw['country_code'] ?? null,
                'foreign_insurance.country_code',
            ),
        ];
        if ($expectedCurrent === 'S') {
            $result['identifier'] = $this->requiredText(
                $raw['identifier'] ?? null,
                'foreign_insurance.identifier',
                50,
            );
        } elseif (array_key_exists('identifier', $raw)) {
            $result['identifier'] = $this->requiredText(
                $raw['identifier'],
                'foreign_insurance.identifier',
                50,
            );
        }
        foreach (['street', 'house_number', 'orientation_number', 'postal_code', 'city', 'sector'] as $key) {
            if (array_key_exists($key, $raw)) {
                $result[$key] = $this->requiredText(
                    $raw[$key],
                    'foreign_insurance.' . $key,
                    50,
                );
            }
        }
        // EDV 1.4.0.6, ID 10101: sektor je kód z číselníku Sektor (EESSI),
        // stejně jako v přihlášce A1.
        if (isset($result['sector'])
            && !PayrollRegistrationForeignInsurerSector::isKnown($result['sector'])
        ) {
            throw new \InvalidArgumentException($this->say(
                'foreign_insurance.sector',
                'musí být kód z číselníku Sektor (01 až 08), teď je „'
                    . $result['sector'] . '“. Vyberte sektor z nabídky.',
            ));
        }
        // EDV 1.4.0.6, ID 10095, 10097, 10098: je-li uvedena část adresy,
        // jsou povinné číslo popisné, PSČ i obec.
        $missing = PayrollRegistrationForeignInsurerAddress::missing($result);
        if ($missing !== []) {
            throw new \InvalidArgumentException($this->note(
                'foreign_insurance.' . $missing[0],
                'chybí. Je-li uvedena jakákoli část adresy zahraničního nositele, '
                    . 'musí být vyplněné číslo popisné, PSČ i obec ('
                    . $this->names('foreign_insurance.', $missing, false) . ').',
            ));
        }
        return $result;
    }

    /** @param array<string,mixed> $context @return array<string,string|null> */
    private function relationIdentity(array $context): array
    {
        $activity = $this->requiredCodeValue(
            $context['activity_code'] ?? null,
            'activity_code',
            2,
        );
        $detail = $context['jmhz_relationship_detail_code'] ?? null;
        try {
            // Dohody bližší určení v evidenci nemají, REGZEC ho chce jako „1".
            $detail = PayrollRegistrationRelationshipDetailPolicy::requireForActivity(
                $activity,
                is_string($detail) && $detail !== '' ? $detail : null,
            );
        } catch (\InvalidArgumentException $exception) {
            throw new PayrollRegistrationXmlException(
                'registration_event_relationship_detail_invalid',
                $exception->getMessage(),
            );
        }

        return [
            'activity_code' => $activity,
            'relationship_detail_code' => $detail,
        ];
    }

    /**
     * Daňová rezidence v A3/A4 (element `taxidrezid`) s volitelným daňovým
     * identifikátorem a adresou bydliště ve státě rezidence (`rdr`).
     *
     * @return array<string,mixed>
     */
    private function taxResidency(mixed $value): array
    {
        $raw = $this->object($value, 'tax_residency');
        $result = [
            'country_code' => $this->country(
                $raw['country_code'] ?? null,
                'tax_residency.country_code',
            ),
            'changed_on' => $this->date(
                $raw['changed_on'] ?? null,
                'tax_residency.changed_on',
            ),
        ];
        $hasType = ($raw['identifier_type'] ?? null) !== null;
        $hasIdentifier = ($raw['identifier'] ?? null) !== null;
        if ($hasType !== $hasIdentifier) {
            throw new \InvalidArgumentException($this->say(
                $hasType ? 'tax_residency.identifier' : 'tax_residency.identifier_type',
                'chybí. Daňový identifikátor se uvádí vždy s druhem, oba'
                    . ' údaje najednou.',
            ));
        }
        if ($hasType) {
            // Druh identifikátoru je v schématu jednoznakový kód (`type`).
            $result['identifier_type'] = $this->requiredText(
                $raw['identifier_type'],
                'tax_residency.identifier_type',
                1,
            );
            $result['identifier'] = $this->requiredText(
                $raw['identifier'],
                'tax_residency.identifier',
                64,
            );
        }
        if (($raw['residence_address'] ?? null) !== null) {
            // EDV 1.4.0.6, ID 10520 a další: u rezidence v ČR je adresa
            // bydliště ve státě rezidence zakázaná a její stát (10524)
            // je shodný se státem rezidence (10068).
            if ($result['country_code'] === 'CZ') {
                throw new \InvalidArgumentException($this->say(
                    'tax_residency.residence_address',
                    'se u daňové rezidence v ČR neposílá - adresa bydliště ve'
                        . ' státě rezidence patří jen k rezidenci v jiném státě.',
                ));
            }
            $result['residence_address'] = $this->residenceAddress(
                $raw['residence_address'],
            );
            if ($result['residence_address']['country_code'] !== $result['country_code']) {
                throw new \InvalidArgumentException($this->say(
                    'tax_residency.residence_address.country_code',
                    'musí být shodný se státem daňové rezidence („'
                        . $result['country_code'] . '“), teď je „'
                        . $result['residence_address']['country_code'] . '“.',
                ));
            }
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * Adresa bydliště ve státě daňové rezidence (`rdr`): číslo domu, PSČ,
     * obec a stát jsou povinné, ulice a orientační číslo ne.
     *
     * @return array<string,string>
     */
    private function residenceAddress(mixed $value): array
    {
        $path = 'tax_residency.residence_address';
        $raw = $this->object($value, $path);
        $this->onlyKeys($raw, [
            'street', 'house_number', 'orientation_number', 'postal_code',
            'city', 'country_code', 'ruian_point',
        ], $path . '.', 'v adrese bydliště ve státě rezidence');
        $country = $this->country($raw['country_code'] ?? null, $path . '.country_code');
        $result = [
            'house_number' => $this->houseNumber(
                $raw['house_number'] ?? null,
                $country,
                $path . '.house_number',
            ),
            'postal_code' => $this->postalCode(
                $raw['postal_code'] ?? null,
                $country,
                $path . '.postal_code',
            ),
            'city' => $this->requiredText($raw['city'] ?? null, $path . '.city', 50),
            'country_code' => $country,
        ];
        foreach (['street' => 50, 'orientation_number' => 12, 'ruian_point' => 12] as $key => $max) {
            if (($raw[$key] ?? null) !== null) {
                $result[$key] = $this->requiredText($raw[$key], $path . '.' . $key, $max);
            }
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * Číslo popisné v adrese datové věty. U české adresy (stát CZ a vždy
     * u pobytu v ČR) je jen číselné do čtyř číslic (EDV 1.4.0.6, kontrola 1).
     */
    private function houseNumber(
        mixed $value,
        string $country,
        string $path,
        bool $czechResidence = false,
    ): string {
        $text = $this->requiredText($value, $path, 12);
        if (($czechResidence || $country === 'CZ')
            && !PayrollRegistrationHouseNumber::validDescriptive($text, $czechResidence)
        ) {
            throw new \InvalidArgumentException($this->say(
                $path,
                'musí být u české adresy jen číslo o nejvýš čtyřech číslicích'
                    . " (bez písmene a lomítka), teď je „{$text}“. Orientační"
                    . ' číslo patří do vlastního pole.',
            ));
        }

        return $text;
    }

    /**
     * PSČ v adrese datové věty. Mezery se odstraní (schéma je nepřipouští)
     * a tvar se ověří podle státu; chybné PSČ vrací větu, ne hlášku XSD.
     */
    private function postalCode(mixed $value, string $country, string $path): string
    {
        $text = $this->requiredText($value, $path, 20);
        $normalized = PayrollRegistrationPostalCode::valid($text, $country);
        if ($normalized === null) {
            throw new \InvalidArgumentException($this->say(
                $path,
                $country === 'CZ'
                    ? "musí mít u české adresy pět číslic a nesmí začínat 0, 8 ani 9, například 11000 (mezery se při podání odstraní), teď je „{$text}“."
                    : "obsahuje znak, který datová věta ČSSZ nepřipouští (mezery se odstraní, povolená jsou písmena, číslice a - , . + ' /), teď je „{$text}“.",
            ));
        }
        if (mb_strlen($normalized, 'UTF-8') > 11) {
            throw new \InvalidArgumentException($this->say(
                $path,
                'smí mít po odstranění mezer nejvýš 11 znaků, teď má '
                    . mb_strlen($normalized, 'UTF-8') . '.',
            ));
        }

        return $normalized;
    }

    /**
     * Jméno, příjmení a státní občanství v A3 (elementy `name` a `stat`).
     * `previous_surnames` jsou dřívější příjmení oddělená čárkou; serializér
     * z nich skládá `name/@ona` (ID 10064).
     *
     * @return array<string,string>
     */
    private function identityChange(mixed $value): array
    {
        $raw = $this->object($value, 'identity');
        $this->onlyKeys($raw, [
            'last_name', 'first_name', 'citizenship_country_code',
            'previous_surnames',
        ], 'identity.', 'v podání „' . $this->actionName(3) . '“');
        $result = [];
        foreach (['last_name' => 50, 'first_name' => 50] as $key => $max) {
            if (($raw[$key] ?? null) !== null) {
                $result[$key] = $this->requiredText($raw[$key], 'identity.' . $key, $max);
            }
        }
        if (($raw['citizenship_country_code'] ?? null) !== null) {
            $result['citizenship_country_code'] = $this->country(
                $raw['citizenship_country_code'],
                'identity.citizenship_country_code',
            );
        }
        if ($result === []) {
            throw new \InvalidArgumentException($this->note(
                'identity',
                'je prázdná. Vyberte jméno, příjmení nebo státní občanství,'
                    . ' které se mění.',
            ));
        }
        if (($raw['previous_surnames'] ?? null) !== null) {
            $result['previous_surnames'] = $this->requiredText(
                $raw['previous_surnames'],
                'identity.previous_surnames',
                100,
            );
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * Důchod v A3 (element `pens`): druh a datum přiznání jdou vždy spolu.
     *
     * @return array<string,string|bool>
     */
    private function pensionChange(mixed $value): array
    {
        $raw = $this->object($value, 'pension');
        $this->onlyKeys($raw, [
            'type_code', 'received_from', 'early_retirement',
            'reduced_retirement_age',
        ], 'pension.', 'v podání „' . $this->actionName(3) . '“');
        $result = [
            'type_code' => $this->requiredText($raw['type_code'] ?? null, 'pension.type_code', 3),
            'received_from' => $this->date($raw['received_from'] ?? null, 'pension.received_from'),
        ];
        foreach (['early_retirement', 'reduced_retirement_age'] as $key) {
            if (($raw[$key] ?? null) !== null) {
                $result[$key] = $this->bool($raw[$key], 'pension.' . $key);
            }
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * Zdravotní stav v A3 (element `fact`): průkaz ZTP a zdravotní omezení.
     * Schéma připouští nejvýš jedno omezení (`healtrest`). Nejvyšší vzdělání
     * se posílá samostatným klíčem `highest_education_code`.
     *
     * @return array<string,mixed>
     */
    private function factsChange(mixed $value): array
    {
        $raw = $this->object($value, 'facts');
        $this->onlyKeys($raw, [
            'disability_card', 'health_restrictions',
        ], 'facts.', 'v podání „' . $this->actionName(3) . '“');
        $result = [];
        if (($raw['disability_card'] ?? null) !== null) {
            $result['disability_card'] = $this->bool(
                $raw['disability_card'],
                'facts.disability_card',
            );
        }
        if (($raw['health_restrictions'] ?? null) !== null) {
            $restrictions = $raw['health_restrictions'];
            if (!is_array($restrictions) || !array_is_list($restrictions)
                || $restrictions === []
            ) {
                throw new \InvalidArgumentException($this->say(
                    'facts.health_restrictions',
                    'chybí. Vyplňte zdravotní omezení s druhem a datem od.',
                ));
            }
            if (count($restrictions) > 1) {
                throw new \InvalidArgumentException($this->say(
                    'facts.health_restrictions',
                    'smí mít v jednom podání jediné omezení, ČSSZ jich v jedné'
                        . ' větě víc nepřijímá.',
                ));
            }
            $row = $this->object($restrictions[0], 'facts.health_restrictions[]');
            $item = [
                'type_code' => $this->requiredText(
                    $row['type_code'] ?? null,
                    'facts.health_restrictions[].type_code',
                    3,
                ),
                'from' => $this->date(
                    $row['from'] ?? null,
                    'facts.health_restrictions[].from',
                ),
            ];
            if (($row['to'] ?? null) !== null) {
                $item['to'] = $this->date($row['to'], 'facts.health_restrictions[].to');
            }
            $result['health_restrictions'] = [$item];
        }
        if ($result === []) {
            throw new \InvalidArgumentException($this->note(
                'facts',
                'je prázdný. Vyberte průkaz ZTP nebo zdravotní omezení,'
                    . ' které se mění.',
            ));
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * Adresa pobytu v ČR u cizince s trvalým pobytem v zahraničí (element
     * `fdr`, bez státu): číslo domu, PSČ a obec jsou povinné.
     *
     * @return array<string,string>
     */
    private function czechResidenceAddress(mixed $value): array
    {
        $raw = $this->object($value, 'czech_residence_address');
        $this->onlyKeys($raw, [
            'street', 'house_number', 'orientation_number', 'postal_code',
            'city', 'ruian_point',
        ], 'czech_residence_address.', 'v podání „' . $this->actionName(3) . '“');
        $result = [
            'house_number' => $this->houseNumber(
                $raw['house_number'] ?? null,
                'CZ',
                'czech_residence_address.house_number',
                true,
            ),
            'postal_code' => $this->postalCode(
                $raw['postal_code'] ?? null,
                'CZ',
                'czech_residence_address.postal_code',
            ),
            'city' => $this->requiredText(
                $raw['city'] ?? null,
                'czech_residence_address.city',
                50,
            ),
        ];
        foreach (['street' => 50, 'orientation_number' => 12, 'ruian_point' => 12] as $key => $max) {
            if (($raw[$key] ?? null) !== null) {
                $result[$key] = $this->requiredText(
                    $raw[$key],
                    'czech_residence_address.' . $key,
                    $max,
                );
            }
        }
        $this->assertOrientationNumber($result, 'CZ', 'czech_residence_address', true);
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * Doklad totožnosti cizince v A3 (element `proofid`).
     *
     * @return array<string,string>
     */
    private function proofIdentityChange(mixed $value): array
    {
        $raw = $this->object($value, 'proof_identity');
        $this->onlyKeys($raw, [
            'type_code', 'number', 'foreign_issuer', 'country_code',
        ], 'proof_identity.', 'v podání „' . $this->actionName(3) . '“');
        $result = [
            'type_code' => $this->requiredText(
                $raw['type_code'] ?? null,
                'proof_identity.type_code',
                3,
            ),
            'number' => $this->requiredText(
                $raw['number'] ?? null,
                'proof_identity.number',
                64,
            ),
            'country_code' => $this->country(
                $raw['country_code'] ?? null,
                'proof_identity.country_code',
            ),
        ];
        if (($raw['foreign_issuer'] ?? null) !== null) {
            $result['foreign_issuer'] = $this->requiredText(
                $raw['foreign_issuer'],
                'proof_identity.foreign_issuer',
                255,
            );
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * Změna státu při trvající příslušnosti k cizím předpisům (element
     * `forinreg`). Vznik a skončení příslušnosti jdou akcemi A6 a A7.
     *
     * @return array<string,string|bool>
     */
    private function foreignLegislationChange(mixed $value): array
    {
        $raw = $this->object($value, 'foreign_legislation');
        $this->onlyKeys($raw, [
            'applies', 'country_code',
        ], 'foreign_legislation.', 'v podání „' . $this->actionName(3) . '“');
        if ($this->bool($raw['applies'] ?? null, 'foreign_legislation.applies') !== true) {
            throw new PayrollRegistrationXmlException(
                'registration_a3_foreign_legislation_requires_other_action',
                'Skončení příslušnosti k cizím předpisům se přes '
                    . $this->actionName(3)
                    . ' nehlásí. Použijte oznámení o skončení příslušnosti'
                    . ' (REGZEC A7).'
                    . PayrollRegistrationFieldVocabulary::reference(
                        'foreign_legislation.applies',
                    ),
            );
        }

        return [
            'applies' => true,
            'country_code' => $this->country(
                $raw['country_code'] ?? null,
                'foreign_legislation.country_code',
            ),
        ];
    }

    /**
     * Pracovní údaje změny A3 (postavení, režim, místo výkonu, profese…).
     * Hodnoty se kontrolují stejně přísně jako v profilu A1.
     *
     * @return array<string,string|bool>
     */
    private function employmentChange(mixed $value): array
    {
        $raw = $this->object($value, 'employment');
        $fields = PayrollRegistrationChangeDeltaPlanner::EMPLOYMENT_FIELDS;
        $this->onlyKeys(
            $raw,
            array_keys($fields),
            'employment.',
            'v podání „' . $this->actionName(3) . '“',
        );
        if ($raw === []) {
            throw new \InvalidArgumentException($this->note(
                'employment',
                'je prázdná. Vyberte aspoň jeden pracovní údaj, který se má '
                    . 'ohlásit.',
            ));
        }
        $result = [];
        foreach ($raw as $key => $item) {
            $path = "employment.{$key}";
            $result[$key] = match ($fields[$key]) {
                'bool' => $this->bool($item, $path),
                'date' => $this->date($item, $path),
                default => $this->requiredText($item, $path, 100),
            };
        }
        if (isset($result['employment_status_code'])) {
            $code = (string) $result['employment_status_code'];
            if (!PayrollRegistrationEmploymentStatusCodebook::isKnown($code)) {
                throw new \InvalidArgumentException($this->say(
                    'employment.employment_status_code',
                    'musí být čtyřmístný kód z číselníku Klasifikace postavení '
                        . "v zaměstnání (NKPZ), teď je „{$code}“.",
                ));
            }
        }
        if (isset($result['profession_code'])
            && !PayrollRegistrationProfessionCode::isRegistrable((string) $result['profession_code'])
        ) {
            throw new \InvalidArgumentException($this->say(
                'employment.profession_code',
                'musí být pětimístný kód CZ-ISCO (kategorie); čtyřmístnou podskupinu ČSSZ '
                    . "v registraci nepřijme, teď je „{$result['profession_code']}“.",
            ));
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * Trvalý pobyt v A3 (element `adr`). Ulice smí chybět (obec bez ulic),
     * číslo popisné, PSČ, obec a stát ne.
     *
     * @return array<string,string>
     */
    private function permanentAddress(mixed $value): array
    {
        $raw = $this->object($value, 'permanent_address');
        $this->onlyKeys($raw, [
            'street', 'house_number', 'orientation_number', 'postal_code',
            'city', 'country_code', 'ruian_point',
        ], 'permanent_address.', 'v podání „' . $this->actionName(3) . '“');
        $country = $this->country(
            $raw['country_code'] ?? null,
            'permanent_address.country_code',
        );
        $result = [
            'house_number' => $this->houseNumber(
                $raw['house_number'] ?? null,
                $country,
                'permanent_address.house_number',
            ),
            'postal_code' => $this->postalCode(
                $raw['postal_code'] ?? null,
                $country,
                'permanent_address.postal_code',
            ),
            'city' => $this->requiredText(
                $raw['city'] ?? null,
                'permanent_address.city',
                50,
            ),
            'country_code' => $country,
        ];
        foreach (['street' => 50, 'orientation_number' => 12, 'ruian_point' => 12] as $key => $max) {
            if (($raw[$key] ?? null) !== null) {
                $result[$key] = $this->requiredText($raw[$key], 'permanent_address.' . $key, $max);
            }
        }
        $this->assertOrientationNumber($result, $country, 'permanent_address');
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * Přístup cizince na trh práce v A3 (element `nocitizen`): volný přístup
     * s důvodem, nebo povolení s druhem, číslem rozhodnutí a platností.
     *
     * @return array<string,string|bool>
     */
    private function foreignWorkerChange(mixed $value): array
    {
        $raw = $this->object($value, 'foreign_worker');
        $this->onlyKeys($raw, [
            'free_access', 'free_access_reason_code', 'permit_type_code',
            'issuing_labour_office_code', 'permit_identifier', 'permit_from',
            'permit_to',
        ], 'foreign_worker.', 'v podání „' . $this->actionName(3) . '“');
        if ($this->bool($raw['free_access'] ?? null, 'foreign_worker.free_access')) {
            return [
                'free_access' => true,
                'free_access_reason_code' => $this->requiredText(
                    $raw['free_access_reason_code'] ?? null,
                    'foreign_worker.free_access_reason_code',
                    4,
                ),
            ];
        }
        $from = $this->date($raw['permit_from'] ?? null, 'foreign_worker.permit_from');
        $to = $this->date($raw['permit_to'] ?? null, 'foreign_worker.permit_to');
        if ($to < $from) {
            throw new \InvalidArgumentException($this->say(
                'foreign_worker.permit_to',
                "nesmí být dřív než začátek platnosti ({$from}).",
            ));
        }
        $result = [
            'free_access' => false,
            'permit_type_code' => $this->requiredText(
                $raw['permit_type_code'] ?? null,
                'foreign_worker.permit_type_code',
                4,
            ),
            'permit_identifier' => $this->requiredText(
                $raw['permit_identifier'] ?? null,
                'foreign_worker.permit_identifier',
                64,
            ),
            'permit_from' => $from,
            'permit_to' => $to,
        ];
        if (($raw['issuing_labour_office_code'] ?? null) !== null) {
            $result['issuing_labour_office_code'] = $this->requiredText(
                $raw['issuing_labour_office_code'],
                'foreign_worker.issuing_labour_office_code',
                8,
            );
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /** @return array<string,string> */
    private function contactAddress(mixed $value): array
    {
        $raw = $this->object($value, 'contact_address');
        $country = $this->country(
            $raw['country_code'] ?? null,
            'contact_address.country_code',
        );
        $result = [
            'street' => $this->requiredText(
                $raw['street'] ?? null,
                'contact_address.street',
                50,
            ),
            'house_number' => $this->houseNumber(
                $raw['house_number'] ?? null,
                $country,
                'contact_address.house_number',
            ),
            'postal_code' => $this->postalCode(
                $raw['postal_code'] ?? null,
                $country,
                'contact_address.postal_code',
            ),
            'city' => $this->requiredText(
                $raw['city'] ?? null,
                'contact_address.city',
                50,
            ),
            'country_code' => $country,
        ];
        foreach (['orientation_number', 'ruian_point'] as $key) {
            if (array_key_exists($key, $raw)) {
                $result[$key] = $this->requiredText(
                    $raw[$key],
                    'contact_address.' . $key,
                    12,
                );
            }
        }
        $this->assertOrientationNumber($result, $country, 'contact_address');
        return $result;
    }

    /**
     * Orientační číslo má u české adresy (stát CZ, vždy u pobytu v ČR)
     * nejvýš 4 znaky, u cizí 12 (EDV 1.4.0.6, ID 10079, 10508, 10515).
     * Pravidlo drží {@see PayrollRegistrationHouseNumber}, stejně jako
     * u přihlášky A1.
     *
     * @param array<string,mixed> $address
     */
    private function assertOrientationNumber(
        array $address,
        string $country,
        string $path,
        bool $czechResidence = false,
    ): void {
        $value = $address['orientation_number'] ?? null;
        $czech = $czechResidence || $country === 'CZ';
        if (!is_string($value)
            || PayrollRegistrationHouseNumber::validOrientation($value, $czech)
        ) {
            return;
        }
        throw new \InvalidArgumentException($this->say(
            $path . '.orientation_number',
            'smí mít u ' . ($czechResidence ? 'adresy pobytu v ČR' : 'české adresy')
                . ' nejvýš ' . PayrollRegistrationHouseNumber::orientationMax($czech)
                . ' znaky, teď je „' . $value . '“.',
        ));
    }

    /** @return list<array{from:string,to:string}> */
    private function pensionPeriods(mixed $value, string $employmentFrom, string $employmentTo): array
    {
        if (!is_array($value) || $value === [] || !array_is_list($value)) {
            throw new \InvalidArgumentException($this->note(
                'unemployment.pension_periods',
                'chybí. Vyplňte aspoň jeden interval od–do, ve kterém byl'
                    . ' zaměstnanec důchodově pojištěný.',
            ));
        }
        $result = [];
        foreach ($value as $row) {
            $row = $this->object($row, 'unemployment.pension_periods[]');
            $from = $this->date(
                $row['from'] ?? null,
                'unemployment.pension_periods[].from',
            );
            $to = $this->date(
                $row['to'] ?? null,
                'unemployment.pension_periods[].to',
            );
            if ($from > $to || $from < $employmentFrom || $to > $employmentTo) {
                throw new \InvalidArgumentException($this->note(
                    'unemployment.pension_periods[]',
                    "({$from} až {$to}) musí ležet uvnitř trvání pracovního"
                        . " vztahu ({$employmentFrom} až {$employmentTo})"
                        . ' a jeho počátek nesmí být po konci. Opravte data'
                        . ' intervalu.',
                ));
            }
            $result[] = ['from' => $from, 'to' => $to];
        }
        return $result;
    }

    /** @param array<string,mixed> $snapshot @param array<string,mixed> $stored */
    private function assertSnapshot(array $snapshot, array $stored): void
    {
        if (($snapshot['schema_reference'] ?? null) !== self::SCHEMA_REFERENCE
            || (int) ($snapshot['supplier_id'] ?? 0) !== (int) $stored['supplier_id']
            || (int) ($snapshot['employment_id'] ?? 0) !== (int) $stored['employment_id']
            || ($snapshot['environment'] ?? null) !== $stored['environment']
            || ($snapshot['interaction'] ?? null) !== $stored['interaction_code']
            || (int) ($snapshot['action_code'] ?? 0) !== (int) $stored['action_code']
            || ($snapshot['effective_on'] ?? null) !== $stored['effective_on']
        ) {
            // Výjimka zůstává: rozpor mezi zašifrovaným podkladem a jeho
            // databázovým záznamem je porušená integrita. Účetní ho nijak
            // nevyplní a podat rozporný podklad by bylo horší než odmítnout.
            throw new \DomainException(
                'Uložená registrační událost neodpovídá svému databázovému'
                    . ' záznamu, takže s ní aplikace dál nepracuje. Schvalte'
                    . ' událost znovu; pokud hláška zůstane, jde o poškozený'
                    . ' záznam a je potřeba zásah podpory.',
            );
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function publicRow(array $row, bool $consumed): array
    {
        return [
            'id' => (int) $row['id'],
            'employment_id' => (int) $row['employment_id'],
            'environment' => (string) $row['environment'],
            'interaction' => (string) $row['interaction_code'],
            'action_code' => (int) $row['action_code'],
            'effective_on' => (string) $row['effective_on'],
            'source_kind' => (string) $row['source_kind'],
            'source_reference' => (string) $row['source_reference'],
            'snapshot_fingerprint' => (string) $row['snapshot_fingerprint'],
            'approved_at' => (string) $row['approved_at'],
            'consumed' => $consumed,
            'created' => true,
        ];
    }

    private function sourceReference(
        string $interaction,
        int $employmentId,
        string $effectiveOn,
        mixed $value,
    ): string {
        if ($interaction === 'termination') {
            return "employment-end:{$employmentId}:{$effectiveOn}";
        }
        return $this->requiredText($value, 'source_reference', 191);
    }

    public static function context(int $supplierId, int $employmentId, string $manifestHash): string
    {
        return "payroll-registration-event:{$supplierId}:{$employmentId}:{$manifestHash}";
    }

    /**
     * Jedna věta k jednomu údaji: lidský název, konkrétní vada a kam jít.
     *
     * Účetní musí z hlášky poznat, CO je špatně a KDE se to opraví. Technický
     * název zůstává jen v závorce na konci — formulář podle něj skáče na
     * správný vstup a v podpoře se podle něj pole dohledá.
     */
    private function say(string $path, string $problem): string
    {
        return PayrollRegistrationFieldVocabulary::label($path)
            . ' ' . $problem . ' '
            . PayrollRegistrationFieldVocabulary::describe($path)
            . PayrollRegistrationFieldVocabulary::reference($path);
    }

    /**
     * Totéž co {@see say()}, ale bez obecné věty „kam jít".
     *
     * Používá se tam, kde si hláška vlastní pokyn nese sama („Přepněte
     * prostředí v registračním panelu."). Dvě navazující instrukce za sebou
     * čtenáře jen zdržují a druhá tu první oslabuje.
     */
    private function note(string $path, string $problem): string
    {
        return PayrollRegistrationFieldVocabulary::label($path)
            . ' ' . $problem
            . PayrollRegistrationFieldVocabulary::reference($path);
    }

    /**
     * Lidský výčet názvů polí do věty. První název začíná větu, tak se
     * kapitalizuje; ostatní zůstávají malými.
     *
     * @param list<string> $keys
     */
    private function names(
        string $prefix,
        array $keys,
        bool $startsSentence = true,
    ): string {
        $names = [];
        foreach (array_values($keys) as $index => $key) {
            $path = $prefix . $key;
            $names[] = $index === 0 && $startsSentence
                ? PayrollRegistrationFieldVocabulary::label($path)
                : (PayrollRegistrationFieldVocabulary::name($path) ?? $path);
        }
        if (count($names) === 1) {
            return $names[0];
        }
        $last = array_pop($names);

        return implode(', ', $names) . ' a ' . $last;
    }

    /** @param list<string> $keys */
    private function references(string $prefix, array $keys): string
    {
        return ' (' . implode(', ', array_map(
            static fn (string $key): string => $prefix . $key,
            array_values($keys),
        )) . ')';
    }

    /**
     * Skloňování „číslice" podle počtu. Bez něj hlášky psaly „3 číslic",
     * což vypadá jako chyba aplikace a účetní pak nevěří ani zbytku věty.
     */
    private function digitWord(int $count): string
    {
        if ($count === 1) {
            return 'číslici';
        }

        return $count >= 2 && $count <= 4 ? 'číslice' : 'číslic';
    }

    /** Lidský název registrační události podle jejího kódu akce. */
    private function actionName(int $actionCode): string
    {
        return PayrollRegistrationFieldVocabulary::action('REGZEC25', $actionCode);
    }

    /** @return array<string,mixed> */
    private function object(mixed $value, string $path): array
    {
        if (!is_array($value) || array_is_list($value)) {
            // Bez zájmen a bez shody v rodě: názvy jsou mužské i ženské
            // i množné („kód", „adresa", „podklady").
            throw new \InvalidArgumentException($this->say(
                $path,
                'chybí. Vyplňte celou skupinu údajů, ne jen jednu hodnotu.',
            ));
        }
        return $value;
    }

    /**
     * @param array<string,mixed> $value
     * @param list<string> $allowed
     */
    private function onlyKeys(
        array $value,
        array $allowed,
        string $prefix,
        string $variant,
    ): void {
        $extra = array_values(array_diff(array_keys($value), $allowed));
        if ($extra === []) {
            return;
        }
        $single = count($extra) === 1;

        throw new \InvalidArgumentException(
            $this->names($prefix, $extra)
                . ' se '
                . $variant
                . ($single ? ' neposílá.' : ' neposílají.')
                . ($single
                    ? ' Nechte příslušné pole ve formuláři prázdné.'
                    : ' Nechte příslušná pole ve formuláři prázdná.')
                . $this->references($prefix, $extra),
        );
    }

    private function date(mixed $value, string $path): string
    {
        $problem = 'musí mít tvar RRRR-MM-DD, například 2026-03-31.';
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException($this->say(
                $path,
                $value === '' || $value === null
                    ? 'chybí; vyplňte datum ve tvaru RRRR-MM-DD, například 2026-03-31.'
                    : $problem,
            ));
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException($this->say($path, $problem));
        }
        return $value;
    }

    /** @param array<string,mixed> $input */
    private function requiredCode(
        array $input,
        string $key,
        int $max,
        string $path,
    ): string {
        return $this->requiredCodeValue($input[$key] ?? null, $path, $max);
    }

    private function requiredCodeValue(mixed $value, string $path, int $max): string
    {
        $value = $this->requiredText($value, $path, $max);
        if (preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw new \InvalidArgumentException($this->say(
                $path,
                "smí obsahovat jen písmena bez diakritiky, číslice, pomlčku"
                    . " a podtržítko; teď je „{$value}“.",
            ));
        }
        return $value;
    }

    private function requiredDigits(
        mixed $value,
        string $path,
        int $min,
        int $max,
    ): string {
        $shape = $min === $max
            ? "musí mít přesně {$min} " . $this->digitWord($min)
                . ' bez mezer, pomlček a lomítek.'
            : "musí mít {$min} až {$max} " . $this->digitWord($max)
                . ' bez mezer, pomlček a lomítek.';
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException($this->say(
                $path,
                'chybí; ' . $shape,
            ));
        }
        if (preg_match('/^[0-9]+$/D', $value) !== 1
            || strlen($value) < $min || strlen($value) > $max
        ) {
            throw new \InvalidArgumentException($this->say(
                $path,
                $shape . " Teď je zadáno „{$value}“.",
            ));
        }
        return $value;
    }

    private function earlyTerminationReason(mixed $value): string
    {
        $code = $this->requiredDigits(
            $value,
            'unemployment.early_termination_reason',
            1,
            1,
        );
        $this->jmhzEvidence->requireEarlyTerminationReason($code);

        return $code;
    }

    private function employmentTerminationReason(mixed $value): string
    {
        $code = $this->requiredDigits(
            $value,
            'unemployment.termination_reason',
            1,
            3,
        );
        $this->jmhzEvidence->requireEmploymentTerminationReason($code);

        return $code;
    }

    private function serviceTerminationReason(mixed $value): string
    {
        $code = $this->requiredDigits(
            $value,
            'unemployment.service_termination_reason',
            1,
            3,
        );
        $this->jmhzEvidence->requireServiceTerminationReason($code);

        return $code;
    }

    private function healthInsuranceCode(mixed $value): string
    {
        $code = $this->requiredDigits($value, 'health_insurance_code', 3, 3);
        if (!HealthInsurers::isValid($code)) {
            throw new \InvalidArgumentException(HealthInsurers::invalidCodeMessage($code));
        }

        return $code;
    }

    private function requiredAmount(mixed $value, string $path): string
    {
        $shape = 'musí být částka v celých korunách — jen číslice, bez haléřů,'
            . ' mezer a znaku Kč, nejvýš deset číslic.';
        if (!is_int($value) && !is_string($value)) {
            throw new \InvalidArgumentException($this->say(
                $path,
                'chybí; ' . $shape,
            ));
        }
        $text = (string) $value;
        if (preg_match('/^[0-9]{1,10}$/D', $text) !== 1) {
            throw new \InvalidArgumentException($this->say(
                $path,
                $shape . " Teď je zadáno „{$text}“.",
            ));
        }
        return $text;
    }

    private function requiredText(mixed $value, string $path, int $max): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException($this->say($path, 'chybí.'));
        }
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException($this->say($path, 'chybí.'));
        }
        if (mb_strlen($value, 'UTF-8') > $max) {
            throw new \InvalidArgumentException($this->say(
                $path,
                "smí mít nejvýš {$max} znaků, teď má "
                    . mb_strlen($value, 'UTF-8') . '.',
            ));
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new \InvalidArgumentException($this->say(
                $path,
                'obsahuje neviditelné řídicí znaky. Text zadejte ručně,'
                    . ' ne vložením ze schránky.',
            ));
        }
        return $value;
    }

    private function bool(mixed $value, string $path): bool
    {
        if (!is_bool($value)) {
            throw new \InvalidArgumentException($this->say(
                $path,
                'chybí; vyberte ano, nebo ne.',
            ));
        }
        return $value;
    }

    private function yesNo(mixed $value, string $path): string
    {
        return $this->bool($value, $path) ? 'A' : 'N';
    }

    private function country(mixed $value, string $path): string
    {
        if (!is_string($value) || preg_match('/^[A-Z]{2}$/D', $value) !== 1) {
            throw new \InvalidArgumentException($this->say(
                $path,
                'musí být dvoupísmenný kód státu velkými písmeny,'
                    . ' například CZ nebo SK.',
            ));
        }
        return $value;
    }

    private function positive(mixed $value, string $path): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($int) || $int <= 0) {
            throw new \InvalidArgumentException($this->say(
                $path,
                'musí být kladné celé číslo.',
            ));
        }
        return $int;
    }

    private function nullablePositive(mixed $value): ?int
    {
        return $value === null ? null : $this->positive($value, 'terms_reference');
    }
}
