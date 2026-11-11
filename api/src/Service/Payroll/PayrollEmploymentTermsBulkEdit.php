<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmploymentConflictException;
use MyInvoice\Repository\Payroll\PayrollEmploymentNotFoundException;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Repository\Payroll\PayrollHourlyWageCollisionGuard;
use MyInvoice\Repository\Payroll\PayrollTermsSettledException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzExternalCodebookCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzReportedEmploymentPeriods;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationRelationshipDetailPolicy;
use PDO;

/**
 * Hromadná úprava podmínek vybraných pracovních vztahů.
 *
 * Agentura se stovkou brigádníků na DPP potřebuje stejnou sazbu, pravděpodobný
 * výdělek, CZ-ISCO, druh činnosti nebo místo výkonu práce u všech dohod naráz.
 * Jediná cesta byla karta vztahu, sto krát totéž. Služba zapíše zvolené údaje
 * do platné verze podmínek vybraných vztahů:
 *
 *   - režim `fill` (výchozí) doplní jen údaj, který ve verzi chybí,
 *   - režim `overwrite` ho přepíše.
 *
 * Zápis je OPRAVA platné verze ({@see PayrollEmploymentRepository::correctTerms()})
 * se stejným validátorem jako karta, ne nová verze od dneška. Kde by karta
 * opravu nedovolila (z verze je zúčtovaná mzda), vznikne stejně jako na kartě
 * nová verze ({@see PayrollEmploymentRepository::addTerms()}) od prvního
 * nezúčtovaného měsíce. Vynechá se vztah, jehož verzi zasahuje schválený nebo zamčený běh
 * či připravené hlášení JMHZ: hromadné akce do historie schválené mzdy nesahají
 * (viz {@see PayrollApprovedPeriodFreeze}).
 */
final class PayrollEmploymentTermsBulkEdit
{
    public const MODE_FILL = 'fill';
    public const MODE_OVERWRITE = 'overwrite';
    public const MODES = [self::MODE_FILL, self::MODE_OVERWRITE];

    private const MAX_EMPLOYMENTS = 2000;

    private const RELATION_TYPES = [
        'employment',
        'small_scale_employment',
        'dpp',
        'dpc',
        'partner_dependent',
        'statutory_body',
    ];

    /** Druhy vztahů, které validátor podmínek pustí na hodinové odměňování. */
    private const HOURLY_RELATION_TYPES = ['employment', 'small_scale_employment', 'dpp', 'dpc'];

    /** Adresa pracoviště patří k obci; při změně obce by zůstala cizí. */
    private const WORKPLACE_ADDRESS_KEYS = [
        'jmhz_workplace_address_point_code',
        'jmhz_workplace_street',
        'jmhz_workplace_house_number',
        'jmhz_workplace_orientation_number',
        'jmhz_workplace_postal_code',
    ];

    private const CHANGE_REASON = 'Hromadná úprava podmínek vybraných vztahů.';

    /** Jediné hodnoty, se kterými zákonný výpočet počítá. */
    private const SUPPORTED_OVERRIDES = [
        'social_insurance_participation' => 'automatic',
        'health_insurance_participation' => 'automatic',
        'tax_regime' => 'advance',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollEmploymentRepository $employments,
        private readonly PayrollEmploymentValidator $validator,
        private readonly PayrollEmploymentJmhzEvidenceCatalog $jmhzEvidence,
        private readonly JmhzExternalCodebookCatalog $codebooks,
        private readonly CzIscoCodebook $czIsco,
        private readonly ActivityLogger $activityLogger,
        private readonly PayrollHourlyWageCollisionGuard $wageGuard,
    ) {}

    /**
     * Vztahy trvající v měsíci s hodnotami platné verze podmínek. Nic nezapisuje.
     *
     * @param list<mixed>|null $employmentIds null = všechny vztahy měsíce
     * @return array<string,mixed>
     */
    public function preview(
        int $supplierId,
        string $periodStart,
        ?string $relationType,
        ?array $employmentIds,
    ): array {
        [$monthStart, $monthEnd] = $this->month($periodStart);
        $relationType = $this->relationType($relationType);
        $rows = $this->rows(
            $supplierId,
            $monthStart,
            $monthEnd,
            $relationType,
            $employmentIds === null ? null : $this->normalizeIds($employmentIds),
        );
        $importedWage = array_fill_keys(
            $this->importedWageEmployments($supplierId, $monthStart, array_map(
                static fn (array $row): int => (int) $row['id'],
                $rows,
            )),
            true,
        );
        $items = [];
        $editable = [];
        $summary = ['employments' => 0, 'editable' => 0, 'excluded' => 0];
        $reported = $this->reported($supplierId, $rows);
        foreach ($rows as $row) {
            $reason = $this->exclusion($row, $monthEnd, $reported[(int) $row['id']] ?? []);
            $summary['employments']++;
            $summary[$reason === null ? 'editable' : 'excluded']++;
            if ($reason === null) {
                $editable[] = (int) $row['id'];
            }
            $items[] = [
                'employment_id' => (int) $row['id'],
                'employee_id' => (int) $row['employee_id'],
                'full_name' => (string) $row['full_name'],
                'employment_code' => (string) $row['code'],
                'relation_type' => (string) $row['relation_type'],
                'state' => $reason === null ? 'editable' : 'excluded',
                'reason' => $reason,
                'locked_period' => $this->lockedPeriod($row),
                'new_version_from' => $reason === null ? $this->newVersionFrom($row) : null,
                // Sazbu u něj hromadně nenastavíme: mzda se importuje jako částka.
                'imported_wage' => isset($importedWage[(int) $row['id']]),
                'effective_from' => $row['effective_from'],
                'remuneration_type' => $row['remuneration_type'],
                'hourly_rate_minor' => $row['hourly_rate_minor'] === null ? null : (int) $row['hourly_rate_minor'],
                'probable_hourly_earning_minor' => $row['probable_hourly_earning_minor'] === null
                    ? null
                    : (int) $row['probable_hourly_earning_minor'],
                'cz_isco_code' => $row['cz_isco_code'],
                'activity_code' => $row['activity_code'],
                'work_place' => $row['work_place'],
                'jmhz_workplace_municipality_code' => $row['jmhz_workplace_municipality_code'],
                'unsupported_overrides' => self::unsupportedOverrides($row),
            ];
        }

        return [
            'period_start' => $monthStart,
            'relation_type' => $relationType,
            'summary' => $summary,
            'editable_employment_ids' => $editable,
            // Druh činnosti dává smysl jen u jednoho druhu vztahu; nabídka
            // obsahuje jen kódy, které validátor podmínek u něj přijme.
            'activity_codes' => $relationType === null ? [] : $this->activityCodes($relationType),
            'items' => $items,
        ];
    }

    /**
     * Zapíše zvolené údaje do platné verze podmínek vybraných vztahů.
     *
     * Každý vztah je vlastní opravou (transakce, zámek a kontrola verze
     * v repozitáři); chyba jednoho ostatní nezastaví.
     *
     * @param array<string,mixed> $changes
     * @param list<mixed> $employmentIds
     * @return array<string,mixed>
     */
    public function apply(
        int $supplierId,
        string $periodStart,
        string $mode,
        array $changes,
        array $employmentIds,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        [$monthStart, $monthEnd] = $this->month($periodStart);
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('Režim musí být „fill" (doplnit prázdné) nebo „overwrite" (přepsat).');
        }
        $ids = $this->normalizeIds($employmentIds);
        if ($ids === []) {
            throw new \InvalidArgumentException('Vyberte aspoň jeden pracovní vztah.');
        }
        $requested = $this->changes($changes, $monthStart, $monthEnd);

        $rows = [];
        foreach ($this->rows($supplierId, $monthStart, $monthEnd, null, $ids) as $row) {
            $rows[(int) $row['id']] = $row;
        }
        $importedWage = isset($requested['hourly_rate'])
            ? array_fill_keys($this->importedWageEmployments($supplierId, $monthStart, array_keys($rows)), true)
            : [];
        $applied = [];
        $skipped = [];
        $failed = [];
        $reported = $this->reported($supplierId, array_values($rows));
        foreach ($ids as $employmentId) {
            $row = $rows[$employmentId] ?? null;
            if ($row === null) {
                $skipped[] = $this->outcome($employmentId, null, 'employment_not_in_period');
                continue;
            }
            $reason = $this->exclusion($row, $monthEnd, $reported[$employmentId] ?? []);
            if ($reason !== null) {
                $skipped[] = $this->outcome($employmentId, $row, $reason);
                continue;
            }
            try {
                $current = $this->employments->currentTerms($supplierId, $employmentId)
                    ?? throw new \DomainException('Pracovní vztah nemá žádnou verzi podmínek.');
                $warnings = [];
                [$body, $fields, $refusal] = $this->body(
                    $row,
                    $current,
                    $requested,
                    $mode,
                    isset($importedWage[$employmentId]),
                    $warnings,
                );
                if ($refusal !== null || $fields === []) {
                    $skipped[] = $this->outcome($employmentId, $row, $refusal ?? $warnings[0] ?? 'nothing_to_change');
                    continue;
                }
                $newVersionFrom = $this->newVersionFrom($row);
                if ($newVersionFrom !== null) {
                    $body['effective_from'] = $newVersionFrom;
                }
                $terms = $this->validator->terms(
                    $body,
                    is_string($current['cz_isco_code'] ?? null) ? $current['cz_isco_code'] : null,
                    is_string($current['other_withholding_eligibility'] ?? null)
                        ? $current['other_withholding_eligibility']
                        : null,
                    (string) $row['relation_type'],
                    // Nezměněná uložená pole nesmí úpravu zablokovat
                    // číselníkovou kontrolou — stejně jako na kartě.
                    $current,
                );
                if ($newVersionFrom === null) {
                    $this->employments->correctTerms(
                        $supplierId,
                        $employmentId,
                        $terms,
                        (int) $row['row_version'],
                        $userId,
                        $ip,
                        $userAgent,
                    );
                } else {
                    // Tatáž cesta jako „Od data platí nové podmínky" na kartě.
                    $this->employments->addTerms(
                        $supplierId,
                        $employmentId,
                        $terms,
                        (int) $row['row_version'],
                        $userId,
                        $ip,
                        $userAgent,
                    );
                }
                $applied[] = [
                    ...$this->outcome($employmentId, $row, null),
                    'fields' => $fields,
                    'kind' => $newVersionFrom === null ? 'correction' : 'new_version',
                    'effective_from' => (string) $terms['effective_from'],
                    'warnings' => $warnings,
                ];
            } catch (PayrollTermsSettledException) {
                $skipped[] = $this->outcome($employmentId, $row, 'period_settled');
            } catch (\InvalidArgumentException|\DomainException|PayrollEmploymentConflictException|PayrollEmploymentNotFoundException $exception) {
                $failed[] = [...$this->outcome($employmentId, $row, null), 'message' => $exception->getMessage()];
            }
        }

        $this->activityLogger->log(
            'payroll.employment.terms_bulk_edit',
            $userId,
            null,
            null,
            [
                'period_start' => $monthStart,
                'mode' => $mode,
                'fields' => array_keys($requested),
                'applied_employment_ids' => array_column($applied, 'employment_id'),
                'skipped_employment_ids' => array_column($skipped, 'employment_id'),
                'failed_employment_ids' => array_column($failed, 'employment_id'),
            ],
            $ip,
            $userAgent,
            $supplierId,
        );

        return [
            'period_start' => $monthStart,
            'mode' => $mode,
            'counts' => [
                'applied' => count($applied),
                'skipped' => count($skipped),
                'failed' => count($failed),
            ],
            'applied' => $applied,
            'skipped' => $skipped,
            'failed' => $failed,
        ];
    }

    /**
     * Cílové tělo opravy: uložená verze a přes ni zvolené údaje.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $current
     * @param array<string,mixed> $requested
     * @param list<string> $warnings výstup: údaje, které se u vztahu záměrně nezapsaly
     * @return array{0:array<string,mixed>,1:list<string>,2:?string} tělo, změněná pole, důvod vynechání
     */
    private function body(array $row, array $current, array $requested, string $mode, bool $importedWage, array &$warnings): array
    {
        $relationType = (string) $row['relation_type'];
        $overwrite = $mode === self::MODE_OVERWRITE;
        $body = PayrollEmploymentTermsBody::fromCurrent($current, self::CHANGE_REASON);
        $body['effective_from'] = (string) $current['effective_from'];
        $fields = [];

        if (isset($requested['hourly_rate']) && (string) $row['status'] === 'ended') {
            // Sazba je sjednání mzdy, ne údaj pro JMHZ; u ukončeného vztahu
            // ji repozitář neopraví (viz ENDED_CORRECTABLE_FIELDS).
            return [$body, [], 'employment_closed'];
        }
        if (isset($requested['hourly_rate']) && $importedWage) {
            // Mzda se k vztahu importuje jako hotová částka. Hodinové podmínky
            // by ji PayrollHourlyWageCollisionGuard při dalším importu odmítl
            // a s ní celý import měsíce. Sazbu proto nenastavíme.
            $warnings[] = 'hourly_rate_imported_wage';
        } elseif (isset($requested['hourly_rate'])) {
            if (!in_array($relationType, self::HOURLY_RELATION_TYPES, true)) {
                return [$body, [], 'hourly_not_supported'];
            }
            if ($overwrite || (bool) $row['rate_empty']) {
                $rate = $requested['hourly_rate'];
                $basisKind = $rate['basis_kind'] ?? $body['remuneration_basis_kind'];
                $basis = $rate['basis'] ?? $body['remuneration_basis'];
                if (in_array($relationType, ['dpp', 'dpc'], true)) {
                    // Totéž výchozí podložení jako na kartě vztahu.
                    $basisKind ??= 'agreement';
                    $basis ??= $relationType === 'dpp' ? 'Dohoda o provedení práce' : 'Dohoda o pracovní činnosti';
                }
                if ($body['remuneration_type'] !== 'hourly' || $body['hourly_rate_minor'] !== $rate['minor']
                    || $body['remuneration_basis_kind'] !== $basisKind || $body['remuneration_basis'] !== $basis
                ) {
                    $fields[] = 'hourly_rate';
                }
                $body['remuneration_type'] = 'hourly';
                $body['hourly_rate_minor'] = $rate['minor'];
                $body['remuneration_basis_kind'] = $basisKind;
                $body['remuneration_basis'] = $basis;
            }
        }

        if (isset($requested['probable_earning'])
            && ($overwrite || $body['probable_hourly_earning_minor'] === null)
        ) {
            $earning = $requested['probable_earning'];
            if ($body['probable_hourly_earning_minor'] !== $earning['minor']
                || $body['probable_earning_rationale'] !== $earning['rationale']
            ) {
                $fields[] = 'probable_earning';
            }
            $body['probable_hourly_earning_minor'] = $earning['minor'];
            $body['probable_earning_rationale'] = $earning['rationale'];
        }

        if (isset($requested['cz_isco_code'])
            && ($overwrite || $this->blank($body['cz_isco_code']))
        ) {
            if ($body['cz_isco_code'] !== $requested['cz_isco_code']) {
                $fields[] = 'cz_isco_code';
            }
            $body['cz_isco_code'] = $requested['cz_isco_code'];
        }

        if (isset($requested['activity_code'])
            && ($overwrite || $this->blank($body['activity_code']))
        ) {
            $code = $requested['activity_code'];
            $detail = self::evidenceDetail($code, is_string($body['jmhz_relationship_detail_code'])
                ? $body['jmhz_relationship_detail_code']
                : null);
            if (!PayrollEmploymentJmhzActivityFamily::matches($relationType, $code, $detail)) {
                return [$body, [], 'activity_code_relation_mismatch'];
            }
            if ($body['activity_code'] !== $code || $body['jmhz_relationship_detail_code'] !== $detail) {
                $fields[] = 'activity_code';
            }
            $body['activity_code'] = $code;
            $body['jmhz_relationship_detail_code'] = $detail;
        }

        if (isset($requested['workplace'])
            && ($overwrite || $this->blank($body['jmhz_workplace_municipality_code']))
        ) {
            $workplace = $requested['workplace'];
            if ($body['jmhz_workplace_municipality_code'] !== $workplace['municipality_code']) {
                foreach (self::WORKPLACE_ADDRESS_KEYS as $key) {
                    $body[$key] = null;
                }
            }
            if ($body['jmhz_workplace_municipality_code'] !== $workplace['municipality_code']
                || $body['jmhz_workplace_country_code'] !== $workplace['country_code']
                || $body['work_place'] !== $workplace['name']
            ) {
                $fields[] = 'workplace';
            }
            $body['work_place'] = $workplace['name'];
            $body['jmhz_workplace_municipality_code'] = $workplace['municipality_code'];
            $body['jmhz_workplace_country_code'] = $workplace['country_code'];
        }

        if (isset($requested['reset_unsupported_overrides'])) {
            // Výpočet umí jen automatickou účast a zálohovou daň; zákonné
            // zařazení (srážka, účast podle příjmu) si odvodí sám. Ruční
            // přepsání ho vždy zastavilo, takže návrat nic nezhorší.
            foreach (self::SUPPORTED_OVERRIDES as $key => $supported) {
                if ($body[$key] !== $supported) {
                    $body[$key] = $supported;
                    $fields[] = $key;
                }
            }
        }

        return [$body, $fields, null];
    }

    /**
     * Ruční přepsání, které výpočet nepodporuje
     * (PayrollRunStatutoryInputAssembler, `*_override_unsupported`).
     *
     * @param array<string,mixed> $row
     * @return list<string>
     */
    public static function unsupportedOverrides(array $row): array
    {
        $unsupported = [];
        foreach (self::SUPPORTED_OVERRIDES as $key => $supported) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== $supported) {
                $unsupported[] = $key;
            }
        }

        return $unsupported;
    }

    /**
     * Ověří a znormalizuje zvolené údaje — jednou pro celou dávku, aby chybná
     * hodnota skončila jednou větou, ne stem stejných chyb u řádků.
     *
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    private function changes(array $changes, string $monthStart, string $monthEnd): array
    {
        $requested = [];
        if (array_key_exists('hourly_rate_minor', $changes) && $changes['hourly_rate_minor'] !== null) {
            $minor = $changes['hourly_rate_minor'];
            if (!is_int($minor) || $minor <= 0 || $minor > PayrollEmploymentValidator::MAX_HOURLY_RATE_MINOR) {
                throw new \InvalidArgumentException('Hodinová sazba musí být kladná částka v haléřích.');
            }
            $basisKind = $this->optionalText($changes, 'remuneration_basis_kind', 32);
            if ($basisKind !== null
                && !in_array($basisKind, PayrollEmploymentValidator::REMUNERATION_BASIS_KINDS, true)
            ) {
                throw new \InvalidArgumentException('Druh podkladu odměňování není podporován.');
            }
            $requested['hourly_rate'] = [
                'minor' => $minor,
                'basis_kind' => $basisKind,
                'basis' => $this->optionalText($changes, 'remuneration_basis', 255),
            ];
        }
        if (array_key_exists('probable_hourly_earning_minor', $changes)
            && $changes['probable_hourly_earning_minor'] !== null
        ) {
            $minor = $changes['probable_hourly_earning_minor'];
            if (!is_int($minor) || $minor <= 0) {
                throw new \InvalidArgumentException('Pravděpodobný hodinový výdělek musí být kladná částka v haléřích.');
            }
            $rationale = $this->optionalText($changes, 'probable_earning_rationale', 1000)
                ?? throw new \InvalidArgumentException(
                    'K pravděpodobnému výdělku doplňte odůvodnění — z čeho jste ho stanovili'
                    . ' (§ 355 odst. 2 zákoníku práce).',
                );
            $requested['probable_earning'] = ['minor' => $minor, 'rationale' => $rationale];
        }
        $czIsco = $this->optionalText($changes, 'cz_isco_code', 16);
        if ($czIsco !== null) {
            if (preg_match('/^[0-9]{4,5}$/D', $czIsco) !== 1
                || $this->czIsco->status($czIsco) !== CzIscoCodebook::STATUS_ACTIVE
            ) {
                throw new \InvalidArgumentException("Kód CZ-ISCO {$czIsco} není v platné klasifikaci zaměstnání ČSÚ.");
            }
            $requested['cz_isco_code'] = $czIsco;
        }
        $activity = $this->optionalText($changes, 'activity_code', 32);
        if ($activity !== null) {
            $this->jmhzEvidence->requireActivityCode($activity);
            $requested['activity_code'] = $activity;
        }
        $municipality = $this->optionalText($changes, 'jmhz_workplace_municipality_code', 6);
        if ($municipality !== null) {
            $country = strtoupper($this->optionalText($changes, 'jmhz_workplace_country_code', 2) ?? 'CZ');
            if (preg_match('/^[0-9]{6}$/D', $municipality) !== 1) {
                throw new \InvalidArgumentException('Kód obce pracoviště JMHZ musí mít přesně šest číslic.');
            }
            if (preg_match('/^[A-Z]{2}$/D', $country) !== 1) {
                throw new \InvalidArgumentException('Kód státu pracoviště JMHZ musí mít dvě velká písmena.');
            }
            if (!\MyInvoice\Service\Payroll\Submission\Jmhz\JmhzExternalCodebookCatalog::publishedForPeriod($monthStart, $monthEnd)) {
                throw new \InvalidArgumentException(
                    \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzExternalCodebookCatalog::unpublishedPeriodMessage($monthStart),
                );
            }
            $name = $this->jmhzEvidence->officialMunicipalityName($municipality, $monthStart);
            if ($name === null
                || $this->codebooks->workplaceProvenanceForPeriod(
                    $municipality,
                    $name,
                    $country,
                    null,
                    null,
                    $monthStart,
                    $monthEnd,
                ) === null
            ) {
                throw new \InvalidArgumentException(
                    "Obec {$municipality} nebo stát {$country} neplatí v číselníku ČSSZ po celý vykazovaný měsíc.",
                );
            }
            $requested['workplace'] = [
                'municipality_code' => $municipality,
                'country_code' => $country,
                'name' => $name,
            ];
        }
        if (($changes['reset_unsupported_overrides'] ?? false) === true) {
            $requested['reset_unsupported_overrides'] = true;
        }
        if ($requested === []) {
            throw new \InvalidArgumentException('Zvolte aspoň jeden údaj, který se má u vybraných vztahů upravit.');
        }

        return $requested;
    }

    /**
     * Proč vztah nejde hromadně upravit; `null` = jde.
     *
     * Zúčtované období vztah nevyřadí — vede na novou verzi
     * ({@see newVersionFrom()}). Zamčený běh nebo připravené JMHZ chce
     * nejdřív otevřít mzdu k opravě.
     *
     * @param array<string,mixed> $row
     * @param list<string> $reported vykázané měsíce vztahu ({@see JmhzReportedEmploymentPeriods})
     */
    private function exclusion(array $row, string $monthEnd, array $reported = []): ?string
    {
        if ($row['term_id'] === null) {
            return 'terms_missing';
        }
        if ((string) $row['effective_from'] > $monthEnd) {
            // Měsíc pokrývá starší verze podmínek. Opravou platné verze by se
            // změnilo jiné období, než o které jde.
            return 'later_terms';
        }
        if ((string) $row['status'] === 'ended') {
            // Ukončený vztah jde opravit jen v údajích pro JMHZ a jen opravou
            // platné verze (repozitář to ověří znovu pod zámkem): nová verze
            // po zúčtovaném měsíci by začínala po konci vztahu.
            if ($this->newVersionFrom($row) !== null) {
                return 'employment_closed';
            }
            $end = array_filter(
                [$row['effective_to'], $row['end_date']],
                static fn (mixed $value): bool => is_string($value) && $value !== '',
            );
            if (JmhzReportedEmploymentPeriods::firstWithin(
                $reported,
                (string) $row['effective_from'],
                $end === [] ? null : min($end),
            ) !== null) {
                return 'employment_reported';
            }
        }
        if ($row['report_prepared_from'] !== null) {
            return 'report_prepared';
        }
        if ($row['locked_from'] !== null) {
            return 'run_locked';
        }

        return null;
    }

    /**
     * Vykázané měsíce JMHZ ukončených vztahů výběru, jedním dotazem.
     *
     * @param list<array<string,mixed>> $rows
     * @return array<int,list<string>>
     */
    private function reported(int $supplierId, array $rows): array
    {
        $ended = [];
        foreach ($rows as $row) {
            if ((string) $row['status'] === 'ended') {
                $ended[] = (int) $row['id'];
            }
        }

        return JmhzReportedEmploymentPeriods::forEmployments($this->db->pdo(), $supplierId, $ended);
    }

    /**
     * Vztahy, kterým se mzda od tří měsíců před zvoleným měsícem importuje
     * jako hotová částka, jedním dotazem pro celou dávku.
     *
     * @param list<int> $employmentIds
     * @return list<int>
     */
    private function importedWageEmployments(int $supplierId, string $monthStart, array $employmentIds): array
    {
        return $this->wageGuard->employmentsBlockingHourlySwitch($supplierId, $employmentIds, $monthStart);
    }

    /**
     * Od kdy začne nová verze, když z platné verze už je zúčtovaná mzda;
     * `null` = platnou verzi jde opravit.
     *
     * Totéž rozhodnutí dělá karta vztahu: opravit jde jen verzi, ze které
     * nikdo nic nevyplatil, jinak nová verze od prvního dne prvního
     * nezúčtovaného měsíce ({@see PayrollEmploymentRepository::settledThrough()}).
     *
     * @param array<string,mixed> $row
     */
    private function newVersionFrom(array $row): ?string
    {
        if ($row['settled_from'] === null || $row['settled_through'] === null) {
            return null;
        }

        return (new \DateTimeImmutable((string) $row['settled_through']))
            ->modify('first day of next month')
            ->format('Y-m-d');
    }

    /** @param array<string,mixed> $row */
    private function lockedPeriod(array $row): ?string
    {
        foreach (['report_prepared_from', 'locked_from'] as $key) {
            if ($row[$key] !== null) {
                return (string) $row[$key];
            }
        }

        return null;
    }

    /**
     * Vztahy trvající v měsíci s poslední verzí podmínek (tou, kterou opravuje
     * `correctTerms()`) a s nejstarším měsícem, ve kterém ji drží zúčtovaný,
     * schválený či zamčený běh nebo připravené hlášení JMHZ. Jeden dotaz pro
     * celou dávku; zámky jdou po indexu `(supplier_id, employment_id,
     * period_start)` výsledků běhu.
     *
     * @param list<int>|null $employmentIds
     * @return list<array<string,mixed>>
     */
    private function rows(
        int $supplierId,
        string $monthStart,
        string $monthEnd,
        ?string $relationType,
        ?array $employmentIds,
    ): array {
        if ($employmentIds === []) {
            return [];
        }
        $filter = '';
        $params = [$supplierId, $monthStart, $monthEnd, $monthStart];
        if ($relationType !== null) {
            $filter .= ' AND employment.relation_type = ?';
            $params[] = $relationType;
        }
        if ($employmentIds !== null) {
            $filter .= ' AND employment.id IN (' . implode(', ', array_fill(0, count($employmentIds), '?')) . ')';
            $params = [...$params, ...$employmentIds];
        }
        $lock = static fn (string $condition, string $aggregate = 'MIN'): string =>
            "(SELECT {$aggregate}(run_employment.period_start)
                FROM payroll_run_employments run_employment
                JOIN payroll_run_revisions revision
                  ON revision.supplier_id = run_employment.supplier_id
                 AND revision.id = run_employment.revision_id
                JOIN payroll_runs run
                  ON run.supplier_id = revision.supplier_id
                 AND run.id = revision.run_id
               WHERE run_employment.supplier_id = employment.supplier_id
                 AND run_employment.employment_id = employment.id
                 AND run_employment.period_start >= DATE_FORMAT(term.effective_from, '%Y-%m-01')
                 AND (term.effective_to IS NULL OR run_employment.period_start <= term.effective_to)
                 AND {$condition})";
        // Zúčtované stavy jsou tytéž jako v PayrollEmploymentRepository::assertTermsOpenFrom()
        // a settledThrough(); zápis je tam pod zámkem ověří znovu. Zúčtovaný běh
        // vede na novou verzi, ne na vynechání — proto ho zámky níže nepočítají.
        $settledStatuses = "'posted', 'payment_ready', 'paid', 'closed'";
        $settled = $lock("run.status IN ({$settledStatuses})");
        $settledThrough = $lock("run.status IN ({$settledStatuses})", 'MAX');
        $reportPrepared = $lock(
            "run.status NOT IN ('correction_pending', 'reopened', 'cancelled', {$settledStatuses})
                 AND EXISTS (
                     SELECT 1
                       FROM payroll_jmhz_preparation_snapshots snapshot
                      WHERE snapshot.supplier_id = run.supplier_id
                        AND snapshot.run_id = run.id
                 )",
        );
        $locked = $lock(
            "run.status NOT IN ('correction_pending', 'reopened', 'cancelled', {$settledStatuses})
                 AND (run.status IN ('inputs_locked', 'approved') OR revision.status = 'approved')",
        );
        $stmt = $this->db->pdo()->prepare(
            "SELECT employment.id, employment.employee_id, employment.code, employment.status,
                    employment.end_date, employment.relation_type, employment.row_version, employee.full_name,
                    term.id AS term_id, term.effective_from, term.effective_to,
                    term.remuneration_type, term.hourly_rate_minor,
                    term.probable_hourly_earning_minor, term.cz_isco_code, term.activity_code,
                    term.work_place, term.jmhz_workplace_municipality_code,
                    term.social_insurance_participation, term.health_insurance_participation,
                    term.tax_regime,
                    (term.hourly_rate_minor IS NULL
                        AND (term.remuneration_type = 'hourly'
                             OR COALESCE(term.monthly_gross_minor, employment.monthly_gross_minor) IS NULL)
                    ) AS rate_empty,
                    {$settled} AS settled_from,
                    {$settledThrough} AS settled_through,
                    {$reportPrepared} AS report_prepared_from,
                    {$locked} AS locked_from
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
               LEFT JOIN payroll_employment_terms term
                 ON term.supplier_id = employment.supplier_id
                AND term.id = (
                    SELECT latest.id
                      FROM payroll_employment_terms latest
                     WHERE latest.supplier_id = employment.supplier_id
                       AND latest.employment_id = employment.id
                     ORDER BY latest.effective_from DESC, latest.id DESC
                     LIMIT 1
                )
              WHERE employment.supplier_id = ?
                AND employment.status NOT IN ('archived', 'no_show')
                AND COALESCE(employment.actual_start_date, employment.start_date, ?) <= ?
                AND (employment.end_date IS NULL OR employment.end_date >= ?)
                {$filter}
              ORDER BY employee.full_name, employment.code, employment.id",
        );
        $stmt->execute($params);

        /** @var list<array<string,mixed>> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Druhy činnosti z připnutého číselníku, které validátor podmínek
     * ({@see PayrollEmploymentJmhzActivityFamily::matches()}) u druhu vztahu přijme.
     *
     * @return list<array{code:string,label:string}>
     */
    private function activityCodes(string $relationType): array
    {
        if (!PayrollEmploymentJmhzActivityFamily::appliesTo($relationType)) {
            return [];
        }
        $codes = [];
        foreach ($this->jmhzEvidence->options()['activity_codes'] as $option) {
            $code = (string) $option['code'];
            if (PayrollEmploymentJmhzActivityFamily::matches(
                $relationType,
                $code,
                self::evidenceDetail($code, null),
            )) {
                $codes[] = ['code' => $code, 'label' => (string) $option['label']];
            }
        }

        return $codes;
    }

    /**
     * Bližší určení vztahu, které k druhu činnosti vede evidence: u dohod nic,
     * u druhu 1 až 9 uložená volba (jinak „žádné"), jinde „žádné".
     */
    private static function evidenceDetail(string $activityCode, ?string $storedDetail): ?string
    {
        return match (PayrollRegistrationRelationshipDetailPolicy::modeForActivity($activityCode)) {
            PayrollRegistrationRelationshipDetailPolicy::MODE_FORBIDDEN => null,
            PayrollRegistrationRelationshipDetailPolicy::MODE_SELECT => in_array($storedDetail, ['1', '2', '3'], true)
                ? $storedDetail
                : PayrollRegistrationRelationshipDetailPolicy::NONE,
            default => PayrollRegistrationRelationshipDetailPolicy::NONE,
        };
    }

    /**
     * @param array<string,mixed>|null $row
     * @return array{employment_id:int,employee_id:?int,full_name:?string,reason?:string}
     */
    private function outcome(int $employmentId, ?array $row, ?string $reason): array
    {
        $outcome = [
            'employment_id' => $employmentId,
            'employee_id' => $row === null ? null : (int) $row['employee_id'],
            'full_name' => $row === null ? null : (string) $row['full_name'],
        ];
        if ($reason !== null) {
            $outcome['reason'] = $reason;
        }

        return $outcome;
    }

    private function blank(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /** @param array<string,mixed> $input */
    private function optionalText(array $input, string $key, int $maxLength): ?string
    {
        $value = $input[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException("{$key} musí být text.");
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException("{$key} smí mít nejvýše {$maxLength} znaků.");
        }

        return $value;
    }

    private function relationType(?string $relationType): ?string
    {
        if ($relationType === null || $relationType === '') {
            return null;
        }
        if (!in_array($relationType, self::RELATION_TYPES, true)) {
            throw new \InvalidArgumentException('Druh pracovního vztahu není podporován.');
        }

        return $relationType;
    }

    /**
     * @param list<mixed> $employmentIds
     * @return list<int>
     */
    private function normalizeIds(array $employmentIds): array
    {
        $ids = [];
        foreach ($employmentIds as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!is_int($id)) {
                throw new \InvalidArgumentException('ID pracovního vztahu musí být kladné celé číslo.');
            }
            $ids[$id] = true;
        }
        if (count($ids) > self::MAX_EMPLOYMENTS) {
            throw new \InvalidArgumentException(sprintf(
                'Najednou lze zpracovat nejvýše %d pracovních vztahů.',
                self::MAX_EMPLOYMENTS,
            ));
        }

        return array_keys($ids);
    }

    /** @return array{0:string,1:string} */
    private function month(string $periodStart): array
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $periodStart);
        if ($date === false || $date->format('Y-m-d') !== $periodStart) {
            throw new \InvalidArgumentException('period_start musí být datum YYYY-MM-DD.');
        }
        $start = $date->modify('first day of this month');

        return [$start->format('Y-m-d'), $start->modify('last day of this month')->format('Y-m-d')];
    }
}
