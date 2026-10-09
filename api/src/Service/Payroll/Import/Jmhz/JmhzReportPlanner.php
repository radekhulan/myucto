<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Jmhz;

use MyInvoice\Repository\Payroll\PayrollDependantRepository;
use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationIdentityRepository;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportLookup;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportPlanner;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenarioFormProfile;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenarioSelectorResolver;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;

/**
 * Z formuláře měsíčního hlášení a stavu evidence spočítá, co by import udělal.
 *
 * Stejně jako {@see RegistrationImportPlanner} vrací veřejnou část (náhled)
 * a interní plán v klíčích s podtržítkem, podle kterého zapisuje
 * {@see JmhzReportWriter}. Použití si plán počítá znovu těsně před zápisem.
 *
 * ── Párování ────────────────────────────────────────────────────────────────
 * Hlášení nenese jméno ani rodné číslo zaměstnance (větev A nese jen OIČ
 * a ID PPV). Formulář se proto páruje:
 *  1. podle ID PPV na vztah (slepý index `payroll_employment_external_ids`),
 *  2. podle OIČ na osobu a měsíce hlášení na jediný vztah, který v tom měsíci
 *     trval,
 *  3. ručně — účetní vybere vztah (`pairs` v požadavku) a OIČ s ID PPV se
 *     zapíšou jako ověřený opis (`verified_manual_import`).
 * Vztah, který už má v evidenci JINÉ ID PPV, je jiný vztah: nenabízí se jako
 * kandidát a ruční přiřazení k němu se odmítne. Když známá osoba (OIČ) žádný
 * jiný vztah nemá, formulář dokládá další, souběžný vztah (operace
 * `create_employment`) — založí ho odvozená věta registrace a formulář se pak
 * spáruje podle ID PPV sám.
 * Když údaje ukazují na různé osoby nebo ruční volba odporuje automatickému
 * párování, formulář se zablokuje: zapsat cizí osobě identifikátory ČSSZ je
 * horší než nechat účetní jeden formulář dodělat ručně.
 */
final class JmhzReportPlanner
{
    public const REFERENCE_PREFIX = 'jmhz-import:';

    private const OPEN_STATUSES = ['planned', 'preregistered', 'active', 'suspended'];
    private const DEAD_STATUSES = ['archived', 'no_show'];

    /** @var array<string,mixed>|null načtené údaje evidence během {@see batch()} */
    private ?array $memo = null;

    private ?JmhzScenarioSelectorResolver $scenarioSelector = null;

    public function __construct(
        private readonly RegistrationImportLookup $lookup,
        private readonly JmhzReportLookup $jmhzLookup,
        private readonly PayrollSensitiveData $sensitiveData,
        private readonly PayrollRegistrationIdentityService $identities,
        private readonly PayrollRegistrationIdentityRepository $registrations,
        private readonly PayrollPersonStatutoryEvidenceRepository $statutory,
        private readonly PayrollDependantRepository $dependants,
        private readonly JmhzChildClaims $childClaims,
    ) {}

    /**
     * Odkaz na doklad u všeho, co import z hlášení zapíše. Formát odpovídá
     * kanonické referenci validátorů evidence (`[A-Za-z0-9][A-Za-z0-9_.:/-]*`).
     */
    public static function reference(string $fileSha256, string $formGuid): string
    {
        return self::REFERENCE_PREFIX . $fileSha256 . ':' . $formGuid;
    }

    public static function isImported(mixed $reference): bool
    {
        return is_string($reference) && str_starts_with($reference, self::REFERENCE_PREFIX);
    }

    /** @return array<string,mixed> */
    public function plan(
        int $supplierId,
        string $environment,
        JmhzBatchItem $item,
        JmhzBatch $batch,
        ?int $pairEmploymentId,
    ): array {
        $form = $item->form;
        $plan = $this->skeleton($item);
        $state = $batch->state($item->key);
        if ($state !== JmhzBatch::EFFECTIVE) {
            $note = $batch->note($item->key);
            if ($state === JmhzBatch::CONFLICT) {
                return $this->finish($plan, $note);
            }
            if ($note !== null) {
                $plan['warnings'][] = $note;
            }

            return $this->finish($plan, null);
        }
        $plan['_effective'] = true;

        $match = $this->match($supplierId, $environment, $item, $pairEmploymentId);
        $plan['match'] = array_merge($plan['match'], $match['public']);
        $plan['warnings'] = [...$plan['warnings'], ...$match['warnings']];
        if ($match['blocker'] !== null) {
            return $this->finish($plan, $match['blocker']);
        }
        $row = $match['employment'];
        if ($row === null && ($match['new_employment'] ?? false) === true) {
            $plan['operation'] = 'create_employment';

            return $this->finish($plan, null);
        }
        if ($row === null) {
            $plan['operation'] = 'pair_required';
            $this->importedPreview($plan, $item, $batch);

            return $this->finish($plan, null);
        }

        $employeeId = (int) $row['employee_id'];
        $plan['_employee_id'] = $employeeId;
        $plan['_employment_id'] = (int) $row['id'];
        $plan['person']['full_name'] = $this->employeeName($supplierId, $employeeId) ?? $plan['person']['full_name'];
        $plan['employment']['start_on'] = $row['actual_start_date'] ?? $row['start_date'];
        $plan['employment']['end_on'] = $row['end_date'];
        $plan['employment']['relation_type'] = $row['relation_type'];

        $reference = self::reference($item->fileSha256, $form->formGuid);
        $this->planIdentifiers($supplierId, $environment, $plan, $form, $employeeId, (int) $row['id']);
        $this->planTerms($supplierId, $plan, $item, $row);
        $this->verifyActivity($supplierId, $plan, $item, $row);
        $this->warnMissingDiscountIntent($supplierId, $environment, $plan, $item, $batch, $row);
        $this->planStatutory($supplierId, $plan, $item, $batch, $employeeId, $reference);
        $this->planChildren($supplierId, $plan, $item, $employeeId, $reference);
        $this->fundInfo($plan, $form);
        $this->historyWarnings($plan, $item, $batch, $row);

        $steps = $plan['_steps'];
        $work = $steps['terms'] !== null
            || $steps['declaration'] !== null
            || $steps['credits']
            || $steps['social_discount'] !== null
            || $steps['children'];
        $identifiers = $steps['identifiers']['person'] !== null || $steps['identifiers']['employment'] !== null;
        $plan['operation'] = match (true) {
            $work => 'update',
            $identifiers => 'assign_identifiers',
            default => 'none',
        };

        return $this->finish($plan, $plan['blocker']);
    }

    /** @return array<string,mixed> */
    private function skeleton(JmhzBatchItem $item): array
    {
        $form = $item->form;
        $file = $item->file;
        $label = match ($form->formType) {
            'O' => 'opravný formulář',
            'S' => 'storno formuláře',
            default => $file->typeLabel(),
        };

        return [
            'key' => $item->key,
            'file' => $item->fileName,
            'sequence' => $form->position,
            'document_type' => 'JMHZ',
            'action_code' => 0,
            'action_label' => 'Měsíční hlášení ' . $file->period() . ' – ' . $label,
            'prepared_on' => substr($file->filledAt, 0, 10),
            'effective_on' => $file->periodStart(),
            'period' => $file->period(),
            'form_id' => $form->formGuid,
            'person' => [
                'full_name' => $form->fullName() ?? 'Neuvedené jméno',
                'first_name' => $form->firstName,
                'last_name' => $form->lastName,
                'birth_date' => $form->birthDate,
                'birth_number_masked' => null,
                'has_oic' => $form->personIdentifier !== null,
            ],
            'employment' => [
                'start_on' => $form->startDate,
                'end_on' => null,
                'activity_code' => $form->activityCode,
                'relation_type' => null,
                'relation_type_options' => [],
                'start_estimated' => false,
                'position_name' => null,
                'has_id_ppv' => $form->employmentIdentifier !== null,
            ],
            'match' => [
                'status' => 'not_found',
                'matched_by' => null,
                'employee_id' => null,
                'employee_name' => null,
                'employment_id' => null,
                'employment_code' => null,
                'candidates' => [],
            ],
            'operation' => 'none',
            'changes' => [],
            'history' => $this->history($item),
            'warnings' => [],
            'blocker' => null,
            'selectable' => false,
            '_item' => $item,
            '_effective' => false,
            '_employee_id' => null,
            '_employment_id' => null,
            '_steps' => [
                'identifiers' => ['person' => null, 'employment' => null],
                'terms' => null,
                'declaration' => null,
                'credits' => false,
                'social_discount' => null,
                'children' => false,
            ],
        ];
    }

    /**
     * @return array{public:array<string,mixed>,employment:?array<string,mixed>,warnings:list<string>,blocker:?string,new_employment?:bool}
     */
    private function match(int $supplierId, string $environment, JmhzBatchItem $item, ?int $pairEmploymentId): array
    {
        $form = $item->form;
        $warnings = [];
        $oicEmployees = [];
        if ($form->personIdentifier !== null) {
            $hash = $this->hash($form->personIdentifier, PayrollSensitiveField::PERSON_EXTERNAL_IDENTIFIER, $supplierId);
            if ($hash !== null) {
                $oicEmployees = $this->remember("oic:{$supplierId}:{$environment}:{$hash}", fn () => $this->lookup->employeesByPersonExternalIdHash($supplierId, $environment, $hash));
            }
        }
        $hit = null;
        if ($form->employmentIdentifier !== null) {
            $hash = $this->hash($form->employmentIdentifier, PayrollSensitiveField::EMPLOYMENT_EXTERNAL_IDENTIFIER, $supplierId);
            if ($hash !== null) {
                $hit = $this->remember("id_ppv:{$supplierId}:{$environment}:{$hash}", fn () => $this->registrations->employmentByExternalIdValueHash($supplierId, $environment, 'id_ppv', $hash));
            }
        }

        $auto = null;
        $matchedBy = null;
        $candidates = [];
        $newEmployee = null;
        $result = static fn (?string $blocker) => [
            'public' => [],
            'employment' => null,
            'warnings' => $warnings,
            'blocker' => $blocker,
        ];
        if ($hit !== null) {
            if ($oicEmployees !== [] && !in_array((int) $hit['employee_id'], $oicEmployees, true)) {
                return ['public' => ['status' => 'ambiguous']] + $result(
                    'OIČ a ID PPV ve formuláři ukazují v evidenci na různé osoby. Nejspíš jde o duplicitní kartu '
                    . 'nebo překlep — vyjasněte to ručně a import zopakujte.',
                );
            }
            $auto = $this->employment($supplierId, (int) $hit['employment_id']);
            $matchedBy = 'id_ppv';
        } elseif (count($oicEmployees) === 1) {
            // Formulář s ID PPV, které evidence nezná, patří jinému vztahu osoby
            // (souběh) než vztah, který už ID PPV má — ten mu nepatří a nenabízí
            // se ani k ručnímu přiřazení.
            $own = $this->withoutForeignIdPpv(
                $this->employments($supplierId, $oicEmployees[0]),
                $supplierId,
                $environment,
                $form,
            );
            $rows = array_values(array_filter($own, fn (array $row): bool => $this->activeIn($row, $item->file)));
            if (count($rows) === 1) {
                $auto = $rows[0];
                $matchedBy = 'oic';
                if ($form->employmentIdentifier !== null) {
                    $warnings[] = 'Pracovní vztah je určený podle OIČ a měsíce hlášení; ID PPV v evidenci chybí '
                        . 'a z hlášení se doplní.';
                }
            } else {
                $candidates = $this->employmentCandidates($rows !== [] ? $rows : $own, $supplierId);
                // Známá osoba, ale žádný její vztah bez ID PPV: formulář dokládá
                // další vztah téže osoby (souběh, typicky dohoda vedle pracovního
                // poměru). Ten založí věta odvozená z hlášení.
                if ($candidates === [] && $form->employmentIdentifier !== null) {
                    $newEmployee = $oicEmployees[0];
                }
            }
        } elseif (count($oicEmployees) > 1) {
            foreach ($oicEmployees as $employeeId) {
                $candidates = [...$candidates, ...$this->employmentCandidates(
                    $this->withoutForeignIdPpv($this->employments($supplierId, $employeeId), $supplierId, $environment, $form),
                    $supplierId,
                )];
            }
        } elseif ($form->lastName !== null && $form->firstName !== null && $form->birthDate !== null) {
            $named = $this->remember(
                'named:' . $supplierId . ':' . json_encode([$form->firstName, $form->lastName, $form->birthDate]),
                fn () => $this->lookup->employeesByNameAndBirthDate(
                    $supplierId,
                    (string) $form->firstName,
                    (string) $form->lastName,
                    (string) $form->birthDate,
                ),
            );
            $active = [];
            foreach ($named as $employeeId) {
                $active = [...$active, ...array_values(array_filter(
                    $this->employments($supplierId, $employeeId),
                    fn (array $row): bool => $this->activeIn($row, $item->file),
                ))];
            }
            // Větev B nese jméno, datum narození, a když je v ní i datum nástupu,
            // musí sedět i to. Jediný vztah jediné takové osoby trvající v měsíci
            // hlášení je týž vztah; cokoli víc se nechává na ruční volbě.
            if (count($named) === 1 && count($active) === 1
                && ($form->startDate === null || $form->startDate === ($active[0]['actual_start_date'] ?? $active[0]['start_date']))
            ) {
                $auto = $active[0];
                $matchedBy = 'name_birth_date';
            } else {
                $candidates = $this->employmentCandidates($active, $supplierId);
            }
        }

        if ($pairEmploymentId !== null) {
            $pair = $this->employment($supplierId, $pairEmploymentId);
            if ($pair === null) {
                return ['public' => []] + $result('Vybraný pracovní vztah v téhle firmě neexistuje.');
            }
            if (in_array($pair['status'], self::DEAD_STATUSES, true)) {
                return ['public' => []] + $result('Vybraný pracovní vztah je archivovaný nebo nenastoupený, '
                    . 'formulář k němu nejde přiřadit.');
            }
            if ($auto !== null && (int) $auto['id'] !== (int) $pair['id']) {
                return ['public' => []] + $result(
                    'Formulář je podle ' . match ($matchedBy) {
                        'id_ppv' => 'ID PPV',
                        'name_birth_date' => 'jména a data narození',
                        default => 'OIČ',
                    } . ' spárovaný se vztahem ' . $auto['code'] . '; ruční přiřazení k jinému vztahu import nepřijme.',
                );
            }
            if ($auto === null && $oicEmployees !== [] && !in_array((int) $pair['employee_id'], $oicEmployees, true)) {
                return ['public' => []] + $result('OIČ ve formuláři patří v evidenci jiné osobě než vybraný vztah.');
            }
            // Vztah s jiným ID PPV je jiný vztah: převzaté měsíce by se zapsaly
            // k němu a přepsaly jeho vlastní (souběžná DPP by přepsala pracovní poměr).
            if ($auto === null && $this->foreignIdPpv($supplierId, (int) $pair['id'], $environment, $form)) {
                return ['public' => []] + $result(
                    'Vybraný vztah ' . $pair['code'] . ' má v evidenci jiné ID PPV než formulář, takže jde o jiný '
                    . 'pracovní vztah. Formulář patří dalšímu vztahu osoby; ten založí věta „Vztah doložený '
                    . 'měsíčními hlášeními JMHZ“, nebo ho založte ručně a formulář přiřaďte k němu.',
                );
            }
            if ($auto === null) {
                $auto = $pair;
                $matchedBy = 'manual';
            }
        }

        if ($auto === null && $newEmployee !== null) {
            return [
                'public' => [
                    'status' => 'new',
                    'employee_id' => $newEmployee,
                    'employee_name' => $this->employeeName($supplierId, $newEmployee),
                    'candidates' => [],
                ],
                'employment' => null,
                'new_employment' => true,
                'warnings' => [...$warnings, 'Osoba je v evidenci, ale žádný její pracovní vztah nemá ID PPV z formuláře '
                    . '(jiné vztahy mají vlastní ID PPV). Formulář dokládá další, souběžný vztah téže osoby; založí ho '
                    . 'věta „Vztah doložený měsíčními hlášeními JMHZ“; vyberte ji spolu s formulářem. K existujícímu '
                    . 'vztahu formulář přiřadit nejde.'],
                'blocker' => null,
            ];
        }
        if ($auto === null) {
            return [
                'public' => [
                    'status' => $candidates === [] ? 'not_found' : 'ambiguous',
                    'candidates' => $candidates,
                ],
                'employment' => null,
                'warnings' => $warnings,
                'blocker' => null,
            ];
        }

        return [
            'public' => [
                'status' => 'matched',
                'matched_by' => $matchedBy,
                'employee_id' => (int) $auto['employee_id'],
                'employee_name' => $this->employeeName($supplierId, (int) $auto['employee_id']),
                'employment_id' => (int) $auto['id'],
                'employment_code' => $auto['code'],
                'candidates' => [],
            ],
            'employment' => $auto,
            'warnings' => $warnings,
            'blocker' => null,
        ];
    }

    /** @param array<string,mixed> $plan */
    private function planIdentifiers(
        int $supplierId,
        string $environment,
        array &$plan,
        JmhzReportForm $form,
        int $employeeId,
        int $employmentId,
    ): void {
        if (!$form->hasIdentifierBranch()) {
            return;
        }
        $person = $this->identifierPlan(
            $plan,
            'Osobní identifikační číslo (OIČ)',
            fn (): ?bool => $this->remember(
                "oic-match:{$supplierId}:{$employeeId}:{$environment}:" . $form->personIdentifier,
                fn (): ?bool => $this->identities->activePersonExternalIdMatches(
                    $supplierId,
                    $employeeId,
                    $environment,
                    (string) $form->personIdentifier,
                ),
            ),
            fn (): ?string => $this->remember(
                "oic-source:{$supplierId}:{$employeeId}:{$environment}",
                fn (): ?string => $this->registrations->activePersonExternalId(
                    $supplierId,
                    $employeeId,
                    $environment,
                    'ik_mpsv',
                )['source_kind'] ?? null,
            ),
            (string) $form->personIdentifier,
        );
        $employment = $this->identifierPlan(
            $plan,
            'Identifikátor pracovního vztahu (ID PPV)',
            fn (): ?bool => $this->employmentIdMatches($supplierId, $employmentId, $environment, (string) $form->employmentIdentifier),
            fn (): ?string => $this->remember(
                "ppv-source:{$supplierId}:{$employmentId}:{$environment}",
                fn (): ?string => $this->registrations->activeExternalId(
                    $supplierId,
                    $employmentId,
                    $environment,
                    'id_ppv',
                )['source_kind'] ?? null,
            ),
            (string) $form->employmentIdentifier,
        );
        $plan['_steps']['identifiers'] = ['person' => $person, 'employment' => $employment];
        $this->change($plan, 'person_external_identifier', 'OIČ (IK MPSV)', null, $person);
        $this->change($plan, 'employment_external_identifier', 'ID pracovního vztahu (ID PPV)', null, $employment);
    }

    /**
     * @param array<string,mixed> $plan
     * @param callable():?bool $matches
     * @param callable():?string $sourceKind
     */
    private function identifierPlan(
        array &$plan,
        string $label,
        callable $matches,
        callable $sourceKind,
        string $value,
    ): ?string {
        try {
            $same = $matches();
        } catch (\InvalidArgumentException $e) {
            $plan['warnings'][] = $e->getMessage();

            return null;
        }
        if ($same === null) {
            return $value;
        }
        if ($same) {
            return null;
        }
        if ($sourceKind() === 'trusted_receipt') {
            $plan['blocker'] = "{$label} ve formuláři se liší od čísla, které v evidenci stojí podle protokolu ČSSZ. "
                . 'Formulář nejspíš patří jiné osobě nebo vztahu — zkontrolujte ho a zpracujte ručně.';
        } else {
            $plan['warnings'][] = "{$label} ve formuláři se liší od ručně zapsaného čísla v evidenci. Import ho "
                . 'nepřepisuje; opravte ho na kartě vztahu, pokud je v evidenci překlep.';
        }

        return null;
    }

    /**
     * Podmínky vztahu podle vykonávané pozice (10229–10233, 10247, 10251)
     * a úvazek podle fondu pracovní doby (10259–10261). Fond samotný je souhrn
     * měsíce, ne podmínka — jen se ukáže. Sjednanou mzdu zapisuje převzetí
     * historie mezd ({@see JmhzTakeoverPlanner}).
     *
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $row
     */
    private function planTerms(int $supplierId, array &$plan, JmhzBatchItem $item, array $row): void
    {
        $form = $item->form;
        if (!$form->hasPosition) {
            return;
        }
        $desired = self::desiredTerms($form, $plan['warnings']);
        if ($desired === []) {
            return;
        }
        $monthStart = $item->file->periodStart();
        $versions = $this->remember("terms:{$supplierId}:{$row['id']}", fn () => $this->jmhzLookup->termVersions($supplierId, (int) $row['id']));
        $covering = JmhzEvidenceTimeline::covering($versions, $monthStart);
        if ($covering === null) {
            if (in_array($row['status'], self::OPEN_STATUSES, true)) {
                $plan['warnings'][] = "K 1. dni měsíce {$item->period()} vztah nemá platné sjednané podmínky, "
                    . 'pracoviště, úvazek ani APZ z hlášení se nezapíšou.';
            }

            return;
        }
        $diff = [];
        foreach ($desired as $field => $value) {
            if (self::text($covering[$field] ?? null) !== $value) {
                $diff[$field] = $value;
            }
        }
        if ($diff === []) {
            return;
        }
        if (!in_array($row['status'], self::OPEN_STATUSES, true)) {
            $plan['warnings'][] = 'Pracovní vztah není otevřený, podmínky (pracoviště, úvazek, APZ, funkční požitky) '
                . 'se z hlášení nezapisují.';

            return;
        }
        if ((int) $covering['id'] !== (int) $versions[0]['id']) {
            $plan['warnings'][] = "Po měsíci {$item->period()} už platí novější verze podmínek vztahu; změnu "
                . 'z hlášení za starší měsíc import do podmínek nezapisuje. Zkontrolujte ji na kartě vztahu.';

            return;
        }
        $plan['_steps']['terms'] = [
            'mode' => (string) $covering['effective_from'] === $monthStart ? 'correct' : 'add',
            'changes' => $diff,
            'effective_from' => $monthStart,
            'terms_id' => (int) $covering['id'],
        ];
        foreach ($diff as $field => $value) {
            $this->change($plan, $field, self::termLabel($field), self::text($covering[$field] ?? null), $value);
        }
    }

    /**
     * Druh činnosti z hlášení (10239, jinak první pozice kódu ELDP 10240) se s evidencí jen ověří: rozdíl je
     * varování, podmínky vztahu import nemění, stejně jako u exportu ČSSZ
     * ({@see RegistrationImportPlanner}). Druh činnosti určuje kód ELDP
     * i druh vztahu, takže tichý rozdíl by se projevil až v hlášeních.
     * Porovnává se jen tehdy, když jsou obě hodnoty vyplněné a evidence má
     * k 1. dni měsíce hlášení platné podmínky.
     *
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $row
     */
    private function verifyActivity(int $supplierId, array &$plan, JmhzBatchItem $item, array $row): void
    {
        $versions = $this->remember("terms:{$supplierId}:{$row['id']}", fn () => $this->jmhzLookup->termVersions($supplierId, (int) $row['id']));
        $covering = JmhzEvidenceTimeline::covering($versions, $item->file->periodStart());
        $this->verifyFormVariant($plan, $item, $row, $covering);
        $reported = self::text(JmhzEmploymentHistory::formActivityCode($item->form));
        if ($reported === null) {
            return;
        }
        $current = $covering === null ? null : self::text($covering['activity_code'] ?? null);
        if ($current === null || strtoupper($current) === strtoupper($reported)) {
            return;
        }
        $plan['warnings'][] = "Druh činnosti ve vztahu {$row['code']} ({$current}) se liší od hlášení za "
            . "{$item->period()} ({$reported}). Import ho nemění, zkontrolujte, který je správný.";
    }

    /**
     * Formulář součásti (bezPriznaku, vezen, jinyPrijem, …) určuje druh
     * činnosti spolu s bližším určením vztahu (kontrola 343). Bližší určení
     * měsíční hlášení nenese, takže rozdíl mezi formulářem a evidencí - třeba
     * hlášení vězně u vztahu evidovaného bez určení „výkon trestu" - se jinak
     * neprojeví až do prvního vlastního hlášení, které by šlo jiným
     * formulářem. Import podmínky nemění, jen upozorní. Odložený příjem se
     * podává u kteréhokoli druhu činnosti a neporovnává se.
     *
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $row
     * @param array<string,mixed>|null $covering
     */
    private function verifyFormVariant(array &$plan, JmhzBatchItem $item, array $row, ?array $covering): void
    {
        $variant = $item->form->variant;
        $activity = $covering === null ? null : self::text($covering['activity_code'] ?? null);
        if ($variant === null || $variant === 'odlozenyPrijem' || $activity === null) {
            return;
        }
        $selection = $this->scenarioSelector()->resolve(
            $activity,
            self::text($covering['jmhz_relationship_detail_code'] ?? null),
        );
        $scenarioKey = $selection['evidence']['scenario_key'] ?? null;
        $expected = is_string($scenarioKey) ? (JmhzScenarioFormProfile::FORM_ELEMENTS[$scenarioKey] ?? null) : null;
        if ($expected === null || $expected === $variant) {
            return;
        }
        $plan['warnings'][] = "Hlášení za {$item->period()} vykazuje vztah {$row['code']} formulářem {$variant}, "
            . "podmínky vztahu (druh činnosti a bližší určení) ale vedou na formulář {$expected}. Import je nemění, "
            . 'zkontrolujte druh činnosti a bližší určení vztahu, jinak půjde další hlášení jiným formulářem.';
    }

    private function scenarioSelector(): JmhzScenarioSelectorResolver
    {
        return $this->scenarioSelector ??= JmhzScenarioSelectorResolver::load();
    }

    /**
     * Předchozí program vedl slevu na pojistném zaměstnavatele (10372), ale
     * v evidenci k vztahu není přijatý záměr (OZUSPOJ). Nárok na slevu zakládá
     * až záměr doručený ČSSZ (§ 7a odst. 5 zák. č. 589/1992 Sb.), takže se
     * sleva po převodu bez něj neuplatní. Příznak z hlášení záměr NEZASTUPUJE
     * (neříká, kdy ČSSZ záměr přijala), a proto se jen upozorní. Upozornění se
     * ukazuje u posledního hlášeného měsíce vztahu, jinak by se opakovalo
     * u každého.
     *
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $row
     */
    private function warnMissingDiscountIntent(
        int $supplierId,
        string $environment,
        array &$plan,
        JmhzBatchItem $item,
        JmhzBatch $batch,
        array $row,
    ): void {
        if ($item->form->employerDiscount !== true || !in_array($row['status'], self::OPEN_STATUSES, true)) {
            return;
        }
        $key = $item->form->relationKey();
        if ($key !== null && $batch->history()->latest($key)?->key !== $item->key) {
            return;
        }
        $present = $this->remember(
            "discount_intent:{$supplierId}:{$environment}:{$row['id']}",
            fn () => $this->jmhzLookup->hasAcceptedDiscountIntent($supplierId, $environment, (int) $row['id']),
        );
        if ($present) {
            return;
        }
        $reasons = array_values(array_unique($item->form->employerDiscountReasons));
        $plan['warnings'][] = "Předchozí program vykazuje za {$item->period()} slevu na pojistném zaměstnavatele (10372"
            . ($reasons === [] ? '' : ', důvod uplatnění ' . implode(', ', $reasons)) . '), '
            . "ale u vztahu {$row['code']} není v evidenci přijatý záměr slevy (OZUSPOJ). Bez něj se sleva po převodu "
            . 'neuplatní: převezměte záměr z přijatého oznámení předchozího programu, nebo podejte nový. '
            . 'Z hlášení se záměr neodvozuje.';
    }

    /**
     * Co z řady měsíců plyne pro práci od prvního zpracovaného období. Ukazuje se
     * jen u posledního hlášeného měsíce vztahu, jinak by se opakovalo u každého.
     *
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $row
     */
    private function historyWarnings(array &$plan, JmhzBatchItem $item, JmhzBatch $batch, array $row): void
    {
        $key = $item->form->relationKey();
        $history = $batch->history();
        if ($key === null || $history->latest($key)?->key !== $item->key
            || !in_array($row['status'], self::OPEN_STATUSES, true)
        ) {
            return;
        }
        $idle = $history->trailingIdleMonths($key);
        if ($idle !== null) {
            $plan['warnings'][] = "Od {$idle['from']} ({$idle['months']} " . ($idle['months'] === 1 ? 'měsíc' : 'měsíce/ů')
                . ') zaměstnanec podle hlášení neodpracoval žádnou hodinu a nemá mzdu, vztah přitom trvá. Nejspíš '
                . 'čerpá mateřskou nebo rodičovskou dovolenou (nebo neplacené volno) — zaevidujte nepřítomnost '
                . 'v Mzdy → Nepřítomnosti, jinak ji výpočet mzdy od prvního zpracovaného měsíce nezohlední.';
        }
        $form = $item->form;
        if (!$item->file->lenient && $form->eldp === null && ($form->socialBase ?? 0) === 0) {
            $plan['warnings'][] = 'Vztah není účasten na důchodovém pojištění (formulář nemá ELDP ani vyměřovací '
                . 'základ). Jde nejspíš o dohodu nebo zaměstnání malého rozsahu — zkontrolujte druh vztahu na kartě.';
        }
    }

    /**
     * @param list<string> $warnings
     * @return array<string,?string>
     */
    public static function desiredTerms(JmhzReportForm $form, array &$warnings): array
    {
        $desired = [];
        $place = $form->workplace;
        if ($place !== null && $place['city'] !== '' && $place['municipality_code'] !== '' && $place['country_code'] !== '') {
            $desired['work_place'] = mb_substr($place['city'], 0, 255);
            $desired['jmhz_workplace_municipality_code'] = $place['municipality_code'];
            $desired['jmhz_workplace_country_code'] = $place['country_code'];
        }
        if ($form->apz === true && $form->apzInstrument === null) {
            $warnings[] = 'Hlášení uvádí příspěvek APZ bez kódu nástroje, příspěvek se nepřebírá.';
        } elseif ($form->apz !== null) {
            $desired['jmhz_apz_contribution_status'] = $form->apz ? 'yes' : 'no';
            $desired['jmhz_apz_instrument_code'] = $form->apz ? $form->apzInstrument : null;
        }
        if ($form->functionalBenefits !== null) {
            $desired['jmhz_functional_benefits_status'] = $form->functionalBenefits ? 'yes' : 'no';
        }
        if ($form->temporaryAssignment === true) {
            $warnings[] = 'Hlášení uvádí zaměstnání za účelem dočasného přidělení u uživatele (10251). '
                . 'To evidence zatím nevede, údaj se nepřebírá.';
        } elseif ($form->temporaryAssignment === false) {
            $desired['jmhz_temporary_assignment_status'] = 'no';
        }
        $workload = $form->workload();
        if ($workload !== null) {
            $desired['weekly_hours'] = $workload['weekly_hours'];
            $desired['workload_basis_points'] = (string) $workload['workload_basis_points'];
        }

        return $desired;
    }

    /** @param array<string,mixed> $plan */
    private function planStatutory(
        int $supplierId,
        array &$plan,
        JmhzBatchItem $item,
        JmhzBatch $batch,
        int $employeeId,
        string $reference,
    ): void {
        $form = $item->form;
        $monthStart = $item->file->periodStart();
        $claimed = self::socialDiscountClaim($item, $batch);
        if ($form->orchardDiscount === true) {
            $plan['warnings'][] = 'Sleva na pojistném v ovocnářství a pěstování zeleniny (10546) evidence nevede, '
                . 'nepřebírá se.';
        }
        if (!$form->hasSummary && $claimed === null) {
            return;
        }
        // Řádky sekcí ani hranice zámku na měsíci nezávisí, formuláře osoby
        // za další měsíce je v jednom plánování sdílejí.
        $view = $this->remember("statutory:{$supplierId}:{$employeeId}", function () use ($supplierId, $employeeId, $monthStart): ?array {
            $view = $this->statutory->editorView($supplierId, $employeeId, $monthStart);

            return $view === null ? null : ['sections' => $view['sections'], 'frozen_through' => $view['frozen_through'] ?? null];
        });
        if ($view === null) {
            return;
        }
        $sections = $view['sections'];
        $frozen = is_string($view['frozen_through'] ?? null) ? $view['frozen_through'] : null;

        if ($form->hasSummary && $form->declarationSigned !== null) {
            $result = JmhzStatutoryChanges::declaration($sections, $frozen, $monthStart, $form->declarationSigned, $reference);
            if ($result['warning'] !== null) {
                $plan['warnings'][] = $result['warning'];
            }
            if ($result['changed']) {
                $plan['_steps']['declaration'] = ['signed' => $form->declarationSigned];
                $this->change(
                    $plan,
                    'tax_declaration',
                    'Prohlášení poplatníka od ' . $item->period(),
                    $result['current'],
                    $result['imported'],
                );
            }
        }
        if ($form->hasSummary && $form->declarationSigned === true) {
            $result = JmhzStatutoryChanges::credits($sections, $frozen, $monthStart, $form->credits, $reference);
            $plan['warnings'] = [...$plan['warnings'], ...$result['warnings']];
            foreach ($result['changes'] as $change) {
                $plan['_steps']['credits'] = true;
                $this->change(
                    $plan,
                    'tax_credit:' . $change['kind'],
                    JmhzStatutoryChanges::CREDIT_LABELS[$change['kind']] . ' od ' . $item->period(),
                    $change['current'],
                    $change['action'] === 'claim' ? 'verified' : 'ukončeno k ' . JmhzEvidenceTimeline::previousDay($monthStart),
                );
            }
        } elseif ($form->hasSummary && $form->credits !== []) {
            $plan['warnings'][] = 'Hlášení uvádí slevy na dani bez podepsaného prohlášení poplatníka; slevy se nepřebírají.';
        }
        if ($claimed !== null) {
            $result = JmhzStatutoryChanges::socialDiscount($sections, $frozen, $monthStart, $claimed, $reference);
            if ($result['warning'] !== null) {
                $plan['warnings'][] = $result['warning'];
            }
            if ($result['changed']) {
                $plan['_steps']['social_discount'] = ['claimed' => $claimed];
                $this->change(
                    $plan,
                    'social_discount',
                    'Sleva pracujícího důchodce od ' . $item->period(),
                    $result['current'],
                    $result['imported'],
                );
            }
        }
    }

    /**
     * Sleva pracujícího důchodce je za OSOBU, ale hlášení ji nese jen na
     * formuláři, který vykazuje pojistné osoby
     * ({@see \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1XmlSerializer::insurance()}:
     * ostatní vztahy vykazují „ne"). Uplatněná sleva se proto převezme z toho
     * formuláře, kde je „ano"; „neuplatňuje" jen z formuláře se souhrnnými daty,
     * a to jen když žádný jiný formulář téže osoby v tom měsíci slevu nemá.
     */
    public static function socialDiscountClaim(JmhzBatchItem $item, JmhzBatch $batch): ?bool
    {
        $form = $item->form;
        if ($form->socialDiscount === true) {
            return true;
        }
        if ($form->socialDiscount !== false || !$form->hasSummary) {
            return null;
        }
        foreach ($batch->siblings($item) as $sibling) {
            if ($sibling->form->socialDiscount === true) {
                return null;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $plan */
    private function planChildren(int $supplierId, array &$plan, JmhzBatchItem $item, int $employeeId, string $reference): void
    {
        $form = $item->form;
        if (!$form->hasSummary || $form->declarationSigned !== true) {
            return;
        }
        $monthStart = $item->file->periodStart();
        $overview = $this->dependants->overview($supplierId, $employeeId, $monthStart);
        if ($overview === null) {
            return;
        }
        $children = $this->childClaims->plan($supplierId, $employeeId, $overview, $form->childCredit, $monthStart, $reference);
        $plan['warnings'] = [...$plan['warnings'], ...$children['warnings']];
        foreach ($children['actions'] as $index => $action) {
            $plan['_steps']['children'] = true;
            $this->change($plan, 'dependant:' . $index, 'Vyživované dítě ' . $action['label'], $action['current'], $action['imported']);
        }
        foreach ($children['ends'] as $index => $end) {
            $plan['_steps']['children'] = true;
            $this->change(
                $plan,
                'dependant_end:' . $index,
                'Nárok na dítě ' . $end['label'],
                'od ' . $end['claim']['effective_from'],
                'ukončeno k ' . JmhzEvidenceTimeline::previousDay($monthStart),
            );
        }
    }

    /** @param array<string,mixed> $plan */
    private function fundInfo(array &$plan, JmhzReportForm $form): void
    {
        if ($form->fund === null) {
            return;
        }
        $plan['changes'][] = [
            'field' => 'work_fund',
            'label' => 'Fond pracovní doby (jen informace)',
            'current' => null,
            'imported' => sprintf(
                'stanovený %s h, sjednaný %s h, týdně %s h',
                $form->fund['standard'],
                $form->fund['agreed'],
                $form->fund['weekly'],
            ),
        ];
    }

    /**
     * Náhled toho, co se převezme po ručním přiřazení vztahu.
     *
     * @param array<string,mixed> $plan
     */
    private function importedPreview(array &$plan, JmhzBatchItem $item, JmhzBatch $batch): void
    {
        $form = $item->form;
        $plan['warnings'][] = 'Formulář se nepodařilo spárovat s pracovním vztahem v evidenci. Vyberte vztah, '
            . 'ke kterému patří; OIČ a ID PPV z hlášení se k němu zapíšou jako ověřený opis.';
        if ($form->hasIdentifierBranch()) {
            $this->change($plan, 'person_external_identifier', 'OIČ (IK MPSV)', null, $form->personIdentifier);
            $this->change($plan, 'employment_external_identifier', 'ID pracovního vztahu (ID PPV)', null, $form->employmentIdentifier);
        }
        if ($form->hasPosition) {
            $warnings = [];
            foreach (self::desiredTerms($form, $warnings) as $field => $value) {
                $this->change($plan, $field, self::termLabel($field), null, $value);
            }
            $plan['warnings'] = [...$plan['warnings'], ...$warnings];
        }
        if ($form->hasSummary && $form->declarationSigned !== null) {
            $this->change(
                $plan,
                'tax_declaration',
                'Prohlášení poplatníka od ' . $item->period(),
                null,
                $form->declarationSigned ? 'signed' : 'not-signed',
            );
            if ($form->declarationSigned) {
                foreach ($form->credits as $kind => $amount) {
                    if ($amount > 0) {
                        $this->change($plan, 'tax_credit:' . $kind, JmhzStatutoryChanges::CREDIT_LABELS[$kind] . ' od ' . $item->period(), null, 'verified');
                    }
                }
                foreach ($form->childCredit['children'] ?? [] as $index => $child) {
                    $this->change(
                        $plan,
                        'dependant:' . $index,
                        'Vyživované dítě ' . $child['given_name'] . ' ' . $child['family_name'],
                        null,
                        $child['order'] === 'N' ? 'N' : 'pořadí ' . $child['order'],
                    );
                }
            }
        }
        $claimed = self::socialDiscountClaim($item, $batch);
        if ($claimed !== null) {
            $this->change($plan, 'social_discount', 'Sleva pracujícího důchodce od ' . $item->period(), null, $claimed ? 'verified' : 'not_claimed');
        }
        $this->fundInfo($plan, $form);
    }

    /** @return array<string,mixed>|null */
    private function history(JmhzBatchItem $item): ?array
    {
        $form = $item->form;
        if (!$form->hasBody()) {
            return null;
        }
        $minor = static fn (?int $czk): ?int => $czk === null ? null : $czk * 100;

        return [
            'period' => $item->period(),
            'gross_minor' => $minor($form->wage),
            'tax_base_minor' => $minor($form->advance['base'] ?? null),
            'advance_tax_minor' => $minor($form->advance['after_credits'] ?? null),
            'bonus_minor' => $minor($form->advance['bonus'] ?? null),
            'social_base_minor' => $minor($form->socialBase),
            'worked_hours' => $form->workedHoursText(),
            'average_hourly_minor' => $form->averageHourlyMinor(),
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array{employee_id:int,employment_id:int,label:string}>
     */
    private function employmentCandidates(array $rows, int $supplierId): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (in_array($row['status'], self::DEAD_STATUSES, true)) {
                continue;
            }
            $result[] = [
                'employee_id' => (int) $row['employee_id'],
                'employment_id' => (int) $row['id'],
                'label' => ($this->employeeName($supplierId, (int) $row['employee_id']) ?? ('Osoba #' . $row['employee_id']))
                    . ' · ' . $row['code'] . ' · ' . $row['relation_type'] . ' · od ' . ($row['start_date'] ?? '—'),
            ];
        }

        return $result;
    }

    /**
     * Vztahy, ke kterým formulář s ID PPV může patřit: bez aktivního ID PPV
     * v evidenci. Vztah s JINÝM ID PPV je jiný vztah (ID PPV, které evidence
     * zná, by formulář spárovalo samo).
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function withoutForeignIdPpv(array $rows, int $supplierId, string $environment, JmhzReportForm $form): array
    {
        if ($form->employmentIdentifier === null) {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            fn (array $row): bool => !$this->foreignIdPpv($supplierId, (int) $row['id'], $environment, $form),
        ));
    }

    private function foreignIdPpv(int $supplierId, int $employmentId, string $environment, JmhzReportForm $form): bool
    {
        if ($form->employmentIdentifier === null) {
            return false;
        }
        try {
            return $this->employmentIdMatches($supplierId, $employmentId, $environment, $form->employmentIdentifier) === false;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    private function employmentIdMatches(int $supplierId, int $employmentId, string $environment, string $value): ?bool
    {
        return $this->remember(
            "ppv-match:{$supplierId}:{$employmentId}:{$environment}:{$value}",
            fn (): ?bool => $this->identities->activeEmploymentExternalIdMatches($supplierId, $employmentId, $environment, $value),
        );
    }

    /** @return array<string,mixed>|null */
    private function employment(int $supplierId, int $employmentId): ?array
    {
        return $this->remember(
            "employment:{$supplierId}:{$employmentId}",
            fn (): ?array => $this->lookup->employment($supplierId, $employmentId),
        );
    }

    /** @return list<array<string,mixed>> */
    private function employments(int $supplierId, int $employeeId): array
    {
        return $this->remember(
            "employments:{$supplierId}:{$employeeId}",
            fn (): array => $this->lookup->employments($supplierId, $employeeId),
        );
    }

    private function employeeName(int $supplierId, int $employeeId): ?string
    {
        return $this->remember(
            "name:{$supplierId}:{$employeeId}",
            fn (): ?string => $this->lookup->employeeName($supplierId, $employeeId),
        );
    }

    /**
     * Plány celé dávky nad jedním, během plánování neměnným stavem evidence.
     * Údaje evidence (vztahy, identifikátory, podmínky) se uvnitř načtou jednou
     * a formuláře téže osoby je sdílejí; po návratu se zapomenou, takže plán
     * po zápisu vidí aktuální stav. Uvnitř se nesmí nic zapisovat.
     *
     * @template T
     * @param callable():T $plan
     * @return T
     */
    public function batch(callable $plan): mixed
    {
        if ($this->memo !== null) {
            return $plan();
        }
        $this->memo = [];
        try {
            return $plan();
        } finally {
            $this->memo = null;
        }
    }

    /** Uvnitř {@see batch()} po zápisu do evidence: další plán načte evidenci znovu. */
    public function forget(): void
    {
        if ($this->memo !== null) {
            $this->memo = [];
        }
    }

    /**
     * @template T
     * @param callable():T $load
     * @return T
     */
    private function remember(string $key, callable $load): mixed
    {
        if ($this->memo === null) {
            return $load();
        }
        if (!array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $load();
        }

        return $this->memo[$key];
    }

    /** @param array<string,mixed> $row */
    private function activeIn(array $row, JmhzReportFile $file): bool
    {
        if (in_array($row['status'], self::DEAD_STATUSES, true)) {
            return false;
        }
        $start = $row['actual_start_date'] ?? $row['start_date'];

        return is_string($start)
            && $start <= $file->periodEnd()
            && ($row['end_date'] === null || $row['end_date'] >= $file->periodStart());
    }

    private function hash(string $value, PayrollSensitiveField $field, int $supplierId): ?string
    {
        try {
            return $this->sensitiveData->lookupHash($value, $field, $supplierId);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** @param array<string,mixed> $plan */
    private function change(array &$plan, string $field, string $label, ?string $current, ?string $imported): void
    {
        if ($imported === null) {
            return;
        }
        $plan['changes'][] = ['field' => $field, 'label' => $label, 'current' => $current, 'imported' => $imported];
    }

    /**
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function finish(array $plan, ?string $blocker): array
    {
        $plan['blocker'] = $blocker;
        $plan['warnings'] = array_values(array_unique($plan['warnings']));
        $plan['selectable'] = $blocker === null
            && in_array($plan['operation'], ['update', 'assign_identifiers', 'pair_required', 'create_employment'], true);

        return $plan;
    }

    public static function termLabel(string $field): string
    {
        return match ($field) {
            'work_place' => 'Místo výkonu práce',
            'jmhz_workplace_municipality_code' => 'Kód obce pracoviště',
            'jmhz_workplace_country_code' => 'Stát pracoviště',
            'jmhz_apz_contribution_status' => 'Příspěvek v rámci APZ',
            'jmhz_apz_instrument_code' => 'Nástroj APZ',
            'jmhz_functional_benefits_status' => 'Funkční požitky',
            'jmhz_temporary_assignment_status' => 'Dočasné přidělení',
            'weekly_hours' => 'Týdenní pracovní doba',
            'workload_basis_points' => 'Úvazek (setiny procenta)',
            default => $field,
        };
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
