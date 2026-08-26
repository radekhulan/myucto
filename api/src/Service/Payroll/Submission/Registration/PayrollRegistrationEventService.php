<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use DOMDocument;
use DOMElement;
use DOMXPath;
use MyInvoice\Repository\Payroll\PayrollRegistrationEventRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use Psr\Clock\ClockInterface;

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
        if ($supplierId <= 0 || $employmentId <= 0 || $approvedBy <= 0) {
            throw new \InvalidArgumentException('Rozsah zdroje REGZEC není platný.');
        }
        if (!in_array($environment, ['test', 'production'], true)) {
            throw new \InvalidArgumentException('Prostředí musí být test nebo production.');
        }
        $interaction = $this->requiredCode($input, 'interaction', 48);
        $definition = self::DEFINITIONS[$interaction] ?? null;
        if ($definition === null) {
            throw new \InvalidArgumentException('Interakce REGZEC musí být A2 až A8.');
        }
        $effectiveOn = $this->date($input['effective_on'] ?? null, 'Datum účinnosti');
        $context = $this->events->employmentSourceAt(
            $supplierId,
            $employmentId,
            $effectiveOn,
        );
        if ($context === null) {
            throw new \OutOfBoundsException('Pracovní vztah nebyl nalezen ve stejné firmě.');
        }
        $employeeId = (int) ($context['employee_id'] ?? 0);
        $identity = $this->identities->sensitiveJmhzIdentityAt(
            $supplierId,
            $employeeId,
            $employmentId,
            $environment,
            $effectiveOn,
        );
        $sourceReference = $this->sourceReference(
            $interaction,
            $employmentId,
            $effectiveOn,
            $input['source_reference'] ?? null,
        );
        $personExternal = $this->object(
            $identity['person_external_identifier'] ?? null,
            'OIČ / IK MPSV',
        );
        $employmentExternal = $this->object(
            $identity['employment_external_identifier'] ?? null,
            'ID PPV',
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
                'Registrační událost nelze schválit před dnem, kdy skutečně nastala.',
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
            'employer' => [
                'variable_symbol' => $this->requiredDigits(
                    $context['social_security_variable_symbol'] ?? null,
                    'Variabilní symbol ČSSZ',
                    8,
                    10,
                ),
                'name' => $this->requiredText(
                    $context['company_name'] ?? null,
                    'Název zaměstnavatele',
                    150,
                ),
                'workplace_code' => $this->requiredDigits(
                    $context['social_security_office_code'] ?? null,
                    'Kód pracoviště ČSSZ',
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
        $stored = $this->events->insert([
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

        return $this->publicRow($stored, false);
    }

    /** @return array<string,mixed> */
    public function load(
        int $supplierId,
        string $environment,
        int $employmentId,
        int $eventId,
    ): array {
        $stored = $this->events->find($supplierId, $environment, $eventId);
        if ($stored === null || (int) ($stored['employment_id'] ?? 0) !== $employmentId) {
            throw new \OutOfBoundsException('Zdroj REGZEC nebyl nalezen ve stejném vztahu a prostředí.');
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
        if (!hash_equals((string) ($stored['snapshot_fingerprint'] ?? ''), $expected)) {
            throw new \DomainException('Otisk neměnného zdroje REGZEC nesouhlasí.');
        }
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \DomainException('Neměnný zdroj REGZEC nemá platný tvar.');
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
        return match ($interaction) {
            'termination' => $this->termination($effectiveOn, $context, $input),
            'change' => $this->delta($input, false)
                + $this->relationIdentity($context),
            'correction' => $this->correction(
                $supplierId,
                $environment,
                $employmentId,
                $effectiveOn,
                $input,
                $employmentExternalIdentifier,
            ) + $this->relationIdentity($context),
            'variable_symbol_transfer' => [
                'new_variable_symbol' => $this->requiredDigits(
                    $input['new_variable_symbol'] ?? null,
                    'Nový variabilní symbol',
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
            default => throw new \LogicException('Neznámá interakce REGZEC.'),
        };
    }

    /** @param array<string,mixed> $context @param array<string,mixed> $input @return array<string,mixed> */
    private function termination(
        string $effectiveOn,
        array $context,
        array $input,
    ): array {
        if (!in_array($context['status'] ?? null, ['ended', 'archived'], true)
            || ($context['end_date'] ?? null) !== $effectiveOn
        ) {
            throw new PayrollRegistrationXmlException(
                'registration_a2_end_source_mismatch',
                'REGZEC A2 vyžaduje skutečně ukončený vztah se shodným datem skončení.',
            );
        }
        $activityCode = $this->requiredCodeValue(
            $context['activity_code'] ?? null,
            'Druh výdělečné činnosti',
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
                'Ukončení úmrtím',
            );
        } elseif (array_key_exists('ended_by_death', $input)
            && $input['ended_by_death'] !== null
        ) {
            throw new PayrollRegistrationXmlException(
                'registration_a2_end_by_death_forbidden',
                'Pro variantu REGZEC A2-10/A2-SPEC se příznak ukončení úmrtím neposílá.',
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
        ];
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
                    'Podklady pro podporu se pro tuto variantu REGZEC A2 neposílají.',
                );
            }
            return null;
        }
        if ($scenario === 'SPEC') {
            if ($value === null) {
                return null;
            }
            $input = $this->object($value, 'Předčasné ukončení A2-SPEC');
            $this->onlyKeys($input, ['early_termination_reason'], 'Podklady A2-SPEC');
            return [
                'early_termination_reason' => $this->requiredDigits(
                    $input['early_termination_reason'] ?? null,
                    'Důvod předčasného ukončení',
                    1,
                    1,
                ),
            ];
        }
        $input = $this->object($value, 'Podklady pro podporu v nezaměstnanosti');
        $mode = $this->requiredCode($input, 'mode', 32);
        if ($mode === 'not_provided_2') {
            $this->onlyKeys($input, ['mode'], 'Podklady A2 rsn=2');
            return ['reason_not_provided' => 2];
        }
        $periods = $this->pensionPeriods(
            $input['pension_periods'] ?? null,
            (string) ($context['start_date'] ?? ''),
            (string) ($context['end_date'] ?? ''),
        );
        $average = $this->requiredAmount(
            $input['average_net_earnings'] ?? null,
            'Průměrný čistý měsíční výdělek',
        );
        if ($mode === 'not_provided_3') {
            $this->onlyKeys(
                $input,
                ['mode', 'average_net_earnings', 'pension_periods'],
                'Podklady A2 rsn=3',
            );
            return [
                'reason_not_provided' => 3,
                'average_net_earnings' => $average,
                'pension_periods' => $periods,
            ];
        }
        if ($mode !== 'provided') {
            throw new \InvalidArgumentException('Režim podkladů A2 není podporovaný.');
        }
        $result = [
            'average_net_earnings' => $average,
            'pension_periods' => $periods,
        ];
        if (in_array($activityCode, ['M', 'N', 'O', 'P', 'Q', 'R', 'S'], true)) {
            $this->onlyKeys(
                $input,
                ['mode', 'average_net_earnings', 'pension_periods'],
                'Podklady A2 bez druhu zaměstnání',
            );
            return $result;
        }
        $type = $this->requiredDigits(
            $input['employment_type'] ?? null,
            'Druh zaměstnání',
            1,
            3,
        );
        if (!in_array($type, ['1', '2'], true)) {
            throw new \InvalidArgumentException('Druh zaměstnání pro podklady A2 musí být 1 nebo 2.');
        }
        $result['employment_type'] = $type;
        if ($type === '1') {
            $this->onlyKeys($input, [
                'mode', 'average_net_earnings', 'pension_periods',
                'employment_type', 'termination_reason', 'entitlement',
                'paid_in_full', 'replacement', 'golden_handshake',
            ], 'Podklady A2 pracovního vztahu');
            $reason = $this->requiredDigits(
                $input['termination_reason'] ?? null,
                'Důvod ukončení pracovního vztahu',
                1,
                3,
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
            ], 'Podklady A2 služebního poměru');
            $reason = $this->requiredDigits(
                $input['service_termination_reason'] ?? null,
                'Důvod ukončení služebního poměru',
                1,
                3,
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
                throw new PayrollRegistrationXmlException(
                    'registration_a2_settlement_forbidden',
                    'Údaje odstupného lze poslat jen pro odpovídající důvod ukončení 4 nebo 5.',
                );
            }
            return;
        }
        $entitlement = $this->bool(
            $input['entitlement'] ?? null,
            'Nárok na odstupné',
        );
        $result['entitlement'] = $entitlement ? 'A' : 'N';
        if (!$entitlement) {
            if ($hasFullPay || $providedAmounts !== []) {
                throw new PayrollRegistrationXmlException(
                    'registration_a2_settlement_payment_forbidden',
                    'Bez nároku na odstupné se neposílá úplná výplata ani částka.',
                );
            }
            return;
        }
        $result['paid_in_full'] = $this->yesNo(
            $input['paid_in_full'] ?? null,
            'Úplná výplata odstupného',
        );
        if (count($providedAmounts) !== 1) {
            throw new PayrollRegistrationXmlException(
                'registration_a2_settlement_amount_required',
                'Při nároku musí být uvedena právě jedna odpovídající částka.',
            );
        }
        $key = $providedAmounts[0];
        $result[$key] = $this->requiredAmount($input[$key], $key);
    }

    private function a2Scenario(string $activityCode, mixed $detail): string
    {
        if ($activityCode === '10') {
            return '10';
        }
        if (in_array($activityCode, ['11', '12', '13', '14', 'M'], true)
            || (preg_match('/^[1-9]$/D', $activityCode) === 1
                && (string) $detail === '2')
        ) {
            return 'SPEC';
        }
        return 'OST';
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function delta(array $input, bool $correction): array
    {
        $raw = $this->object(
            $input[$correction ? 'corrections' : 'changes'] ?? null,
            $correction ? 'Opravované hodnoty' : 'Měněné hodnoty',
        );
        if ($correction && array_key_exists('contract_start_on', $raw)) {
            throw new PayrollRegistrationXmlException(
                'registration_a4_explanation_attachment_required',
                'Oprava vzniku zaměstnání vyžaduje písemné vysvětlení; tato cesta zůstává do podpory přílohy uzavřená.',
            );
        }
        $allowed = $correction
            ? ['title_prefix', 'tax_residency', 'relationship_detail_code', 'highest_education_code']
            : ['title_prefix', 'contact_address', 'tax_residency', 'relationship_detail_code', 'health_insurance_code'];
        $this->onlyKeys($raw, $allowed, $correction ? 'REGZEC A4' : 'REGZEC A3');
        if ($raw === []) {
            throw new \InvalidArgumentException('REGZEC změna nebo oprava musí obsahovat alespoň jedno pole.');
        }
        $result = [];
        foreach ($raw as $key => $value) {
            $result[$key] = match ($key) {
                'title_prefix' => $this->requiredText($value, 'Titul', 30),
                'relationship_detail_code' => $this->requiredDigits($value, 'Bližší určení vztahu', 1, 1),
                'health_insurance_code' => $this->requiredDigits($value, 'Kód zdravotní pojišťovny', 3, 3),
                'contract_start_on' => $this->date($value, 'Vznik zaměstnání'),
                'highest_education_code' => $this->requiredCodeValue($value, 'Nejvyšší vzdělání', 1),
                'tax_residency' => $this->taxResidency($value),
                'contact_address' => $this->contactAddress($value),
                default => throw new \LogicException('Neznámé delta pole.'),
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
        array $input,
        string $employmentExternalIdentifier,
    ): array {
        $submissionId = $this->positive($input['source_submission_id'] ?? null, 'Původní podání');
        $source = $this->events->acceptedRegistration(
            $supplierId,
            $environment,
            $employmentId,
            $submissionId,
        );
        if ($source === null) {
            throw new PayrollRegistrationXmlException(
                'registration_a4_source_submission_invalid',
                'REGZEC A4 musí odkazovat na přijaté REGZEC stejného vztahu a prostředí.',
            );
        }
        $frozenSource = $this->acceptedSourceArtifact(
            $supplierId,
            $source,
            $effectiveOn,
            $employmentExternalIdentifier,
        );
        return $this->delta($input, true) + [
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

    /** @param array<string,mixed> $source @return array{action_code:int,filing_on:string,employment_external_identifier:?string} */
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
                'Přijaté původní podání nemá archivovaný výstupní XML artefakt.',
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
                'Archivovaný zdroj REGZEC A4 není platná datová věta REGZEC.',
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
                'Archivovaný zdroj REGZEC A4 musí obsahovat právě jednoho zaměstnance.',
            );
        }
        /** @var DOMElement $employee */
        $employee = $employees->item(0);
        $filingOn = $employee->getAttribute('dat');
        if ($this->date($filingOn, 'Datum původního podání') !== $effectiveOn) {
            throw new PayrollRegistrationXmlException(
                'registration_a4_original_filing_date_mismatch',
                'Datum REGZEC A4 musí přesně odpovídat atributu dat původního přijatého podání.',
            );
        }
        $action = $employee->getAttribute('act');
        if (preg_match('/^[1-8]$/D', $action) !== 1) {
            throw new PayrollRegistrationXmlException(
                'registration_a4_source_action_invalid',
                'Původní podání REGZEC A4 nemá podporovaný kód akce.',
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
                'ID PPV v původním přijatém podání neodpovídá opravovanému pracovnímu vztahu.',
            );
        }

        return [
            'action_code' => (int) $action,
            'filing_on' => $filingOn,
            'employment_external_identifier' => $sourceIdentifier === ''
                ? null
                : $sourceIdentifier,
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
        $submissionId = $this->positive($input['source_submission_id'] ?? null, 'Původní podání');
        if ($this->events->acceptedRegistration(
            $supplierId,
            $environment,
            $employmentId,
            $submissionId,
        ) === null) {
            throw new PayrollRegistrationXmlException(
                'registration_a8_source_submission_invalid',
                'REGZEC A8 musí odkazovat na přijaté REGZEC stejného vztahu a prostředí.',
            );
        }
        if (($input['not_started'] ?? null) !== true) {
            throw new PayrollRegistrationXmlException(
                'registration_a8_explanation_attachment_required',
                'Storno jiného než nenastoupeného vztahu vyžaduje písemné vysvětlení; tato cesta zůstává do podpory přílohy uzavřená.',
            );
        }
        if (($context['status'] ?? null) !== 'no_show'
            || ($context['start_date'] ?? null) !== $effectiveOn
        ) {
            throw new PayrollRegistrationXmlException(
                'registration_a8_no_show_source_mismatch',
                'REGZEC A8 s nenástupem vyžaduje vztah označený jako nenastoupený a původní plánovaný den nástupu.',
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
                'Datum zjištění chyby',
            );
            if ($discoveredOn < $effectiveOn) {
                throw new \InvalidArgumentException(
                    'Datum zjištění chyby nesmí předcházet opravovanému datu.',
                );
            }
            return $discoveredOn;
        }
        if ($interaction === 'cancellation') {
            return $this->date(
                $context['start_date'] ?? null,
                'Původní plánovaný den nástupu',
            );
        }

        return $effectiveOn;
    }

    /** @param array<string,mixed> $input @return array<string,string> */
    private function foreignInsurance(array $input, string $expectedCurrent): array
    {
        $raw = $this->object($input['foreign_insurance'] ?? null, 'Zahraniční nositel pojištění');
        $current = $this->requiredCodeValue($raw['current'] ?? null, 'Specifikace nositele', 1);
        if ($current !== $expectedCurrent) {
            throw new PayrollRegistrationXmlException(
                'registration_jurisdiction_direction_mismatch',
                "Interakce vyžaduje hodnotu nositele {$expectedCurrent}.",
            );
        }
        $result = [
            'current' => $current,
            'name' => $this->requiredText($raw['name'] ?? null, 'Název nositele', 100),
            'country_code' => $this->country($raw['country_code'] ?? null),
        ];
        if ($expectedCurrent === 'S') {
            $result['identifier'] = $this->requiredText($raw['identifier'] ?? null, 'Číslo zahraničního pojištění', 50);
        } elseif (array_key_exists('identifier', $raw)) {
            $result['identifier'] = $this->requiredText($raw['identifier'], 'Číslo zahraničního pojištění', 50);
        }
        foreach (['street', 'house_number', 'orientation_number', 'postal_code', 'city', 'sector'] as $key) {
            if (array_key_exists($key, $raw)) {
                $result[$key] = $this->requiredText($raw[$key], $key, 50);
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $context @return array<string,string|null> */
    private function relationIdentity(array $context): array
    {
        $activity = $this->requiredCodeValue(
            $context['activity_code'] ?? null,
            'Druh výdělečné činnosti',
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
                'Bližší určení pracovního vztahu',
                1,
                1,
            ),
        ];
    }

    /** @return array<string,string> */
    private function taxResidency(mixed $value): array
    {
        $raw = $this->object($value, 'Daňová rezidence');
        return [
            'country_code' => $this->country($raw['country_code'] ?? null),
            'changed_on' => $this->date($raw['changed_on'] ?? null, 'Datum změny rezidence'),
        ];
    }

    /** @return array<string,string> */
    private function contactAddress(mixed $value): array
    {
        $raw = $this->object($value, 'Kontaktní adresa');
        $result = [
            'street' => $this->requiredText($raw['street'] ?? null, 'Ulice', 50),
            'house_number' => $this->requiredText($raw['house_number'] ?? null, 'Číslo popisné', 12),
            'postal_code' => $this->requiredText($raw['postal_code'] ?? null, 'PSČ', 11),
            'city' => $this->requiredText($raw['city'] ?? null, 'Obec', 50),
            'country_code' => $this->country($raw['country_code'] ?? null),
        ];
        foreach (['orientation_number', 'ruian_point'] as $key) {
            if (array_key_exists($key, $raw)) {
                $result[$key] = $this->requiredText($raw[$key], $key, 12);
            }
        }
        return $result;
    }

    /** @return list<array{from:string,to:string}> */
    private function pensionPeriods(mixed $value, string $employmentFrom, string $employmentTo): array
    {
        if (!is_array($value) || $value === [] || !array_is_list($value)) {
            throw new \InvalidArgumentException('A2 vyžaduje alespoň jeden interval důchodového pojištění.');
        }
        $result = [];
        foreach ($value as $row) {
            $row = $this->object($row, 'Interval důchodového pojištění');
            $from = $this->date($row['from'] ?? null, 'Počátek intervalu');
            $to = $this->date($row['to'] ?? null, 'Konec intervalu');
            if ($from > $to || $from < $employmentFrom || $to > $employmentTo) {
                throw new \InvalidArgumentException('Interval důchodového pojištění neleží ve vztahu.');
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
            throw new \DomainException('Neměnný zdroj REGZEC neodpovídá svému databázovému rozsahu.');
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
        return $this->requiredText($value, 'Reference zdroje', 191);
    }

    private function context(int $supplierId, int $employmentId, string $manifestHash): string
    {
        return "payroll-registration-event:{$supplierId}:{$employmentId}:{$manifestHash}";
    }

    /** @return array<string,mixed> */
    private function object(mixed $value, string $label): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException("{$label} musí být objekt.");
        }
        return $value;
    }

    /** @param array<string,mixed> $value @param list<string> $allowed */
    private function onlyKeys(array $value, array $allowed, string $label): void
    {
        $extra = array_diff(array_keys($value), $allowed);
        if ($extra !== []) {
            throw new \InvalidArgumentException(
                "{$label} obsahuje zakázané pole " . implode(', ', $extra) . '.',
            );
        }
    }

    private function date(mixed $value, string $label): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException("{$label} musí být datum RRRR-MM-DD.");
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException("{$label} musí být datum RRRR-MM-DD.");
        }
        return $value;
    }

    /** @param array<string,mixed> $input */
    private function requiredCode(array $input, string $key, int $max): string
    {
        return $this->requiredCodeValue($input[$key] ?? null, $key, $max);
    }

    private function requiredCodeValue(mixed $value, string $label, int $max): string
    {
        $value = $this->requiredText($value, $label, $max);
        if (preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw new \InvalidArgumentException("{$label} nemá platný kód.");
        }
        return $value;
    }

    private function requiredDigits(mixed $value, string $label, int $min, int $max): string
    {
        if (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1
            || strlen($value) < $min || strlen($value) > $max
        ) {
            throw new \InvalidArgumentException("{$label} musí mít {$min} až {$max} číslic.");
        }
        return $value;
    }

    private function requiredAmount(mixed $value, string $label): string
    {
        if (!is_int($value) && !is_string($value)) {
            throw new \InvalidArgumentException("{$label} musí být celé číslo.");
        }
        $text = (string) $value;
        if (preg_match('/^[0-9]{1,10}$/D', $text) !== 1) {
            throw new \InvalidArgumentException("{$label} musí být nezáporné celé číslo do 10 číslic.");
        }
        return $text;
    }

    private function requiredText(mixed $value, string $label, int $max): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException("{$label} musí být text.");
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > $max
            || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1
        ) {
            throw new \InvalidArgumentException("{$label} není platný text.");
        }
        return $value;
    }

    private function bool(mixed $value, string $label): bool
    {
        if (!is_bool($value)) {
            throw new \InvalidArgumentException("{$label} musí být ano nebo ne.");
        }
        return $value;
    }

    private function yesNo(mixed $value, string $label): string
    {
        return $this->bool($value, $label) ? 'A' : 'N';
    }

    private function country(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Z]{2}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Kód státu musí mít dvě velká písmena.');
        }
        return $value;
    }

    private function positive(mixed $value, string $label): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($int) || $int <= 0) {
            throw new \InvalidArgumentException("{$label} musí být kladné celé číslo.");
        }
        return $int;
    }

    private function nullablePositive(mixed $value): ?int
    {
        return $value === null ? null : $this->positive($value, 'ID zdroje');
    }
}
