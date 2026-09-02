<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use DOMDocument;
use DOMElement;
use DOMXPath;
use MyInvoice\Repository\Payroll\PayrollRegistrationA2EvidenceRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationEventRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Codebook\HealthInsurers;
use MyInvoice\Service\Payroll\PayrollEmploymentJmhzEvidenceCatalog;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
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
        $context = $this->events->employmentSourceAt(
            $supplierId,
            $employmentId,
            $effectiveOn,
        );
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
            $effectiveOn,
            $interaction === 'termination',
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
        $data = $this->data(
            $supplierId,
            $environment,
            $employmentId,
            $interaction,
            $effectiveOn,
            $context,
            $input,
            (string) ($employmentExternal['value'] ?? ''),
        );
        $notificationTriggerOn = $this->notificationTriggerOn(
            $interaction,
            $effectiveOn,
            $context,
            $input,
        );
        if ($notificationTriggerOn > $this->clock->now()
            ->setTimezone(new \DateTimeZone('Europe/Prague'))
            ->format('Y-m-d')
        ) {
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
                    $context['company_name'] ?? null,
                    'employer_name',
                    150,
                ),
                'workplace_code' => $this->requiredDigits(
                    $context['social_security_office_code'] ?? null,
                    'cssz_workplace_code',
                    3,
                    3,
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
        $context = $this->events->employmentSourceAt($supplierId, $employmentId, $effectiveOn);
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
        $identity = $this->identities->sensitiveJmhzIdentityAt(
            $supplierId,
            (int) ($context['employee_id'] ?? 0),
            $employmentId,
            $environment,
            $effectiveOn,
            true,
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
                'new_variable_symbol' => $this->requiredDigits(
                    $input['new_variable_symbol'] ?? null,
                    'new_variable_symbol',
                    8,
                    10,
                ),
            ],
            'czech_legislation_start' => [
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

        return $data + $this->relationIdentity($context);
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

        return [
            'end_on' => $effectiveOn,
            'activity_code' => $activityCode,
            'relationship_detail_code' => $detail,
            'a2_scenario' => $scenario,
            'ended_by_death' => $endedByDeath,
            'unemployment' => $this->unemployment(
                $input['unemployment'] ?? null,
                $scenario,
                $activityCode,
                $endedByDeath,
                $context,
            ),
            'jmhz_correction_evidence' => $evidence->toArray(),
        ];
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
    private function unemployment(
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
            $input = $this->object($value, 'unemployment');
            $this->onlyKeys(
                $input,
                ['early_termination_reason'],
                'unemployment.',
                'u varianty A2-SPEC',
            );
            return [
                'early_termination_reason' => $this->earlyTerminationReason(
                    $input['early_termination_reason'] ?? null,
                ),
            ];
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

        return $data;
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
            ? ['title_prefix', 'tax_residency', 'relationship_detail_code', 'highest_education_code']
            : ['title_prefix', 'contact_address', 'tax_residency', 'relationship_detail_code', 'health_insurance_code'];
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
                // Interní kontrakt: klíče už prošly onlyKeys() výš, sem se
                // uživatelský vstup nedostane. Zůstává technická — akce ji
                // nechytá, protože jde o chybu programu.
                default => throw new \LogicException(
                    'Neznámé delta pole registrační události: ' . $key,
                ),
            };
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
        if (($input['not_started'] ?? null) !== true) {
            throw new PayrollRegistrationXmlException(
                'registration_a8_explanation_attachment_required',
                $this->actionName(8)
                    . ' aplikace připraví jen pro zaměstnance, který vůbec'
                    . ' nenastoupil, a tuhle skutečnost je potřeba ve'
                    . ' formuláři potvrdit. Jiné storno vyžaduje písemné'
                    . ' vysvětlení, které aplikace zatím neumí přiložit —'
                    . ' takové storno vyřiďte s ČSSZ mimo aplikaci.'
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
        return [
            'not_started' => true,
            'source_submission_id' => $submissionId,
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
        if ($interaction === 'cancellation') {
            return $this->date(
                $context['start_date'] ?? null,
                'planned_start_on',
            );
        }

        return $effectiveOn;
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
        if ($activity === '10') {
            return [
                'activity_code' => $activity,
                'relationship_detail_code' => null,
            ];
        }

        return [
            'activity_code' => $activity,
            'relationship_detail_code' => $this->requiredDigits(
                $context['jmhz_relationship_detail_code'] ?? null,
                'relationship_detail_code',
                1,
                1,
            ),
        ];
    }

    /** @return array<string,string> */
    private function taxResidency(mixed $value): array
    {
        $raw = $this->object($value, 'tax_residency');
        return [
            'country_code' => $this->country(
                $raw['country_code'] ?? null,
                'tax_residency.country_code',
            ),
            'changed_on' => $this->date(
                $raw['changed_on'] ?? null,
                'tax_residency.changed_on',
            ),
        ];
    }

    /** @return array<string,string> */
    private function contactAddress(mixed $value): array
    {
        $raw = $this->object($value, 'contact_address');
        $result = [
            'street' => $this->requiredText(
                $raw['street'] ?? null,
                'contact_address.street',
                50,
            ),
            'house_number' => $this->requiredText(
                $raw['house_number'] ?? null,
                'contact_address.house_number',
                12,
            ),
            'postal_code' => $this->requiredText(
                $raw['postal_code'] ?? null,
                'contact_address.postal_code',
                11,
            ),
            'city' => $this->requiredText(
                $raw['city'] ?? null,
                'contact_address.city',
                50,
            ),
            'country_code' => $this->country(
                $raw['country_code'] ?? null,
                'contact_address.country_code',
            ),
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
        return $result;
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

    private function context(int $supplierId, int $employmentId, string $manifestHash): string
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
