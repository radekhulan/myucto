<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Repository\Payroll\PayrollPeopleRepository;

/**
 * Kam ve frontendu se nález měsíčního hlášení opravuje.
 *
 * Jediné místo, které skládá cestu z druhu nápravy ({@see JmhzBlockerCatalog})
 * a dotčeného záznamu. Používá ho zmrazení podání (odpověď 409 nese u každého
 * nálezu odkaz na opravu) i kontrola před mzdovým během, aby účetní vedly
 * stejné odkazy ze všech míst.
 */
final class JmhzBlockerRemediationPath
{
    /** Strop řádků v odpovědi; zbytek by jen prodloužil odpověď, hláška ho shrne. */
    public const MAX_BLOCKERS = 500;

    /** Kódy identifikátorů ČSSZ, které vedou na kartu pracovního vztahu. */
    private const IDENTITY_CODES = [
        'jmhz_identity_missing',
        'jmhz_identity_oic_missing',
        'jmhz_identity_id_ppv_missing',
        'jmhz_identity_incomplete',
        'jmhz_identity_unresolved',
    ];

    /**
     * Nálezy s jmény a odkazy pro odpověď serveru.
     *
     * @param list<JmhzScenario1Blocker> $blockers
     * @return list<array<string,mixed>>
     */
    public static function payload(
        array $blockers,
        ?PayrollPeopleRepository $people,
        int $supplierId,
    ): array {
        $blockers = array_slice($blockers, 0, self::MAX_BLOCKERS);
        $ids = ['employment' => [], 'person' => []];
        foreach ($blockers as $blocker) {
            $type = self::labelType($blocker->entityType);
            if ($blocker->entityId !== null && isset($ids[$type])) {
                $ids[$type][] = $blocker->entityId;
            }
        }
        $labels = $people === null ? ['employment' => [], 'person' => []] : [
            'employment' => $people->employmentLabels($supplierId, $ids['employment']),
            'person' => $people->personLabels($supplierId, $ids['person']),
        ];

        $rows = [];
        foreach ($blockers as $blocker) {
            $type = self::labelType($blocker->entityType);
            $rows[] = $blocker->toArray() + [
                'label' => $blocker->entityId === null ? null : ($labels[$type][$blocker->entityId] ?? null),
                'remediation_path' => self::forBlocker($blocker),
            ];
        }

        return $rows;
    }

    public static function forBlocker(JmhzScenario1Blocker $blocker): ?string
    {
        $remediation = JmhzBlockerCatalog::remediation($blocker->code, $blocker->entityType);

        return self::resolve(
            $blocker->code,
            $remediation['kind'],
            $remediation['field'],
            $blocker->entityType,
            $blocker->entityId,
        );
    }

    /**
     * Odkaz kontroly před během: kód bez katalogového druhu (identita, složky,
     * chybějící průměr). Průměr vede na hromadný výpočet za čtvrtletí období.
     */
    public static function forReadinessCode(string $code, ?int $entityId = null, ?string $periodStart = null): ?string
    {
        if ($code === 'jmhz_average_hourly_earning_missing') {
            $query = ['tab' => 'averages'];
            if ($periodStart !== null && preg_match('/^(\d{4})-(\d{2})-/', $periodStart, $m) === 1) {
                $query['year'] = (int) $m[1];
                $query['quarter'] = intdiv((int) $m[2] - 1, 3) + 1;
            }
            if ($entityId !== null) {
                $query['employment'] = $entityId;
            }

            return '/payroll/absences?' . http_build_query($query);
        }
        if (in_array($code, self::IDENTITY_CODES, true)) {
            return self::identityPath($code, $entityId === null ? [] : ['employment' => $entityId]);
        }
        if (in_array($code, [
            'component_jmhz_mapping_missing',
            'component_jmhz_manual_review',
            'component_jmhz_treatment_invalid',
            'component_jmhz_bonus_regularity_unverified',
        ], true)) {
            return self::componentPath($code, $entityId);
        }

        return null;
    }

    private static function resolve(
        string $code,
        string $kind,
        ?string $field,
        string $entityType,
        ?int $entityId,
    ): ?string {
        $employmentId = $entityType === 'employment' ? $entityId : null;
        $personId = in_array($entityType, ['person', 'employee'], true) ? $entityId : null;
        $scope = $employmentId !== null
            ? ['employment' => $employmentId]
            : ($personId !== null ? ['person' => $personId] : []);

        if (in_array($code, self::IDENTITY_CODES, true) || $kind === 'employment_identity') {
            return self::identityPath($code, $scope);
        }
        $people = static fn (array $query): string => '/payroll/people?' . http_build_query($query);
        $personOnly = $personId !== null ? ['person' => $personId] : null;

        return match ($kind) {
            'employment_terms', 'workplace' => $scope === [] ? '/payroll/people'
                : $people($scope + ['panel' => 'employment_terms'] + ($field === null ? [] : ['field' => $field])),
            'employment_profile' => $scope === [] ? '/payroll/people' : $people($scope + ['panel' => 'jmhz_profile']),
            'registration' => $employmentId === null ? '/payroll/people' : $people($scope + ['panel' => 'registration']),
            'employee_identity' => $personOnly === null ? '/payroll/people' : $people($personOnly + ['panel' => 'registration_identity']),
            'statutory_evidence' => $personOnly === null ? '/payroll/people' : $people($personOnly + ['panel' => 'statutory_evidence']),
            'dependants' => $personOnly === null ? '/payroll/people' : $people($personOnly + ['panel' => 'dependants']),
            'absences' => '/payroll/absences?' . http_build_query(
                ($employmentId === null ? [] : ['employment' => $employmentId]) + ['tab' => 'absences'],
            ),
            'averages' => '/payroll/absences?' . http_build_query(
                ($employmentId === null ? [] : ['employment' => $employmentId]) + ['tab' => 'averages'],
            ),
            'time' => '/payroll/time' . ($employmentId === null ? '' : '?' . http_build_query(['employment' => $employmentId])),
            'runs' => '/payroll/runs',
            'components' => self::componentPath($code, $entityType === 'component' ? $entityId : null),
            'ordinary_evidence' => '/payroll/submissions#jmhz-ordinary-evidence',
            'employer_annual' => '/payroll/settings?tab=submissions#jmhz-employer-annual-evidence',
            'office' => '/payroll/settings?tab=employer#payroll-employer-offices',
            'annual_settlement' => '/payroll/annual-settlement'
                . ($personId === null ? '' : '?' . http_build_query(['person' => $personId])),
            'takeover' => '/payroll/imports?tab=reconciliation',
            'correction', 'submission' => '/payroll/submissions/transport',
            default => null,
        };
    }

    /** @param array<string,int> $scope employment nebo person */
    private static function identityPath(string $code, array $scope): string
    {
        $query = $scope;
        $query['panel'] = 'jmhz_identity';
        $query['field'] = $code === 'jmhz_identity_id_ppv_missing'
            ? 'jmhz.employment_external_identifier'
            : 'jmhz.person_external_identifier';

        return '/payroll/people?' . http_build_query($query);
    }

    /**
     * Odkaz, který vede ROVNOU na opravu.
     *
     * Jednotlivá složka vede na svoje zařazení (`component` + `panel=jmhz`),
     * souhrnný nález na číselník vyfiltrovaný na složky s tímtéž problémem,
     * odkud se dají zařadit hromadně.
     */
    private static function componentPath(string $code, ?int $componentId): string
    {
        $query = ['tab' => 'catalog'];
        if ($componentId !== null) {
            $query['component'] = $componentId;
            $query['panel'] = 'jmhz';
        } elseif ($code !== 'component_jmhz_bonus_regularity_unverified') {
            // Neověřené zařazení odměny je zařazení, ne chybějící stav — filtr
            // stavů ho nemá, souhrnný nález proto vede na celý číselník.
            $query['jmhz'] = match ($code) {
                'component_jmhz_manual_review' => 'manual_review',
                'component_jmhz_treatment_invalid' => 'invalid',
                default => 'missing',
            };
        }

        return '/payroll/components?' . http_build_query($query);
    }

    private static function labelType(string $entityType): string
    {
        return $entityType === 'employee' ? 'person' : $entityType;
    }
}
