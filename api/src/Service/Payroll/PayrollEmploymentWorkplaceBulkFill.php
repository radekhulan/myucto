<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Repository\Payroll\PayrollTermsSettledException;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzExternalCodebookCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzReportedEmploymentPeriods;
use PDO;

/**
 * Hromadné doplnění místa výkonu práce pro JMHZ (obec 10229, stát 10230).
 *
 * Místo výkonu práce je sjednané v pracovní smlouvě (§ 34 zákoníku práce),
 * takže ho aplikace vymyslet nesmí — zvolit ho musí účetní. Když ale ve firmě
 * pracují všichni na jednom pracovišti a import podmínek pracoviště nepřinesl,
 * jediná cesta byla karta vztahu, 225 × totéž. Tahle služba doplní JEDNU
 * zvolenou obec a stát všem vybraným vztahům, které pracoviště NEMAJÍ.
 *
 * Nikdy nepřepisuje vyplněný údaj: vztah s pracovištěm, které číselník
 * neověří, patří na kartu (`invalid`), ne pod hromadnou změnu. Náhled nabízí
 * pracoviště, která už ve firmě ověřeně jsou, seřazená podle počtu vztahů.
 *
 * Zápis jde přes {@see PayrollEmploymentRepository::correctTerms()} a validátor
 * podmínek — tutéž cestu jako oprava na kartě vztahu a oprava z REGZEC A1,
 * se stejnou kontrolou číselníku, zmrazeného období a historie změn. Ověření
 * „platí pro celý měsíc" je jediné pravidlo s během:
 * {@see JmhzExternalCodebookCatalog::workplaceProvenanceForPeriod()}.
 */
final class PayrollEmploymentWorkplaceBulkFill
{
    private const MAX_EMPLOYMENTS = 2000;

    private const CHANGE_REASON = 'Hromadně doplněné místo výkonu práce pro JMHZ: %s (%s).';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollEmploymentRepository $employments,
        private readonly PayrollEmploymentValidator $validator,
        private readonly JmhzExternalCodebookCatalog $codebooks,
        private readonly ActivityLogger $activityLogger,
    ) {}

    /**
     * Stav pracoviště u vztahů měsíce. Nic nezapisuje.
     *
     * @param list<mixed>|null $employmentIds null = všechny vztahy trvající
     *        v měsíci
     * @return array<string,mixed>
     */
    public function preview(int $supplierId, string $periodStart, ?array $employmentIds): array
    {
        [$monthStart, $monthEnd] = $this->month($periodStart);
        $rows = $this->rows(
            $supplierId,
            $monthStart,
            $monthEnd,
            $employmentIds === null ? null : $this->normalizeIds($employmentIds),
        );
        $summary = ['employments' => 0, 'missing' => 0, 'verified' => 0, 'invalid' => 0, 'excluded' => 0];
        $suggestions = [];
        $missing = [];
        $items = [];
        $reported = $this->reported($supplierId, $rows);
        foreach ($rows as $row) {
            $assessment = $this->assess($supplierId, $row, $monthStart, $monthEnd, $reported[(int) $row['id']] ?? []);
            $summary['employments']++;
            $summary[$assessment['state']]++;
            if ($assessment['state'] === 'missing') {
                $missing[] = (int) $row['id'];
            }
            if ($assessment['state'] === 'verified') {
                $key = $row['jmhz_workplace_municipality_code'] . '|' . $row['jmhz_workplace_country_code'];
                $suggestions[$key] ??= [
                    'municipality_code' => (string) $row['jmhz_workplace_municipality_code'],
                    'municipality_name' => (string) $row['work_place'],
                    'country_code' => (string) $row['jmhz_workplace_country_code'],
                    'employments' => 0,
                ];
                $suggestions[$key]['employments']++;
            }
            $items[] = [
                'employment_id' => (int) $row['id'],
                'employee_id' => (int) $row['employee_id'],
                'full_name' => (string) $row['full_name'],
                'employment_code' => (string) $row['code'],
                'state' => $assessment['state'],
                'reason' => $assessment['reason'],
                'municipality_code' => $row['jmhz_workplace_municipality_code'],
                'municipality_name' => $row['work_place'],
                'country_code' => $row['jmhz_workplace_country_code'],
            ];
        }
        $suggestions = array_values($suggestions);
        usort(
            $suggestions,
            static fn (array $left, array $right): int =>
                [$right['employments'], $left['municipality_name']]
                <=> [$left['employments'], $right['municipality_name']],
        );

        return [
            'period_start' => $monthStart,
            'summary' => $summary,
            'suggestions' => $suggestions,
            'missing_employment_ids' => $missing,
            'items' => $items,
        ];
    }

    /**
     * Doplní zvolenou obec a stát vybraným vztahům bez pracoviště.
     *
     * Každý vztah je vlastní opravou podmínek (vlastní transakce, zámek
     * a kontrola verze v repozitáři); chyba jednoho ostatní nezastaví.
     *
     * @param list<mixed> $employmentIds
     * @return array<string,mixed>
     */
    public function apply(
        int $supplierId,
        string $periodStart,
        string $municipalityCode,
        string $countryCode,
        array $employmentIds,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        [$monthStart, $monthEnd] = $this->month($periodStart);
        $ids = $this->normalizeIds($employmentIds);
        if ($ids === []) {
            throw new \InvalidArgumentException('Vyberte aspoň jeden pracovní vztah.');
        }
        $code = trim($municipalityCode);
        $country = strtoupper(trim($countryCode));
        if (preg_match('/^[0-9]{6}$/D', $code) !== 1) {
            throw new \InvalidArgumentException('Kód obce pracoviště JMHZ musí mít přesně šest číslic.');
        }
        if (preg_match('/^[A-Z]{2}$/D', $country) !== 1) {
            throw new \InvalidArgumentException('Kód státu pracoviště JMHZ musí mít dvě velká písmena.');
        }
        $entry = $this->codebooks->findMunicipality($code, $monthStart);
        $name = is_array($entry) && is_string($entry['label'] ?? null) ? $entry['label'] : null;
        if ($name === null
            || $this->codebooks->workplaceProvenanceForPeriod(
                $code,
                $name,
                $country,
                null,
                null,
                $monthStart,
                $monthEnd,
            ) === null
        ) {
            throw new \InvalidArgumentException(
                "Obec {$code} nebo stát {$country} neplatí v číselníku ČSSZ po celý vykazovaný měsíc.",
            );
        }
        $reason = sprintf(self::CHANGE_REASON, $name, $code);

        $applied = [];
        $skipped = [];
        $failed = [];
        foreach ($ids as $employmentId) {
            $row = $this->rows($supplierId, $monthStart, $monthEnd, [$employmentId])[0] ?? null;
            if ($row === null) {
                $skipped[] = ['employment_id' => $employmentId, 'reason' => 'employment_not_in_period'];
                continue;
            }
            $assessment = $this->assess(
                $supplierId,
                $row,
                $monthStart,
                $monthEnd,
                $this->reported($supplierId, [$row])[$employmentId] ?? [],
            );
            if ($assessment['state'] !== 'missing') {
                $skipped[] = [
                    'employment_id' => $employmentId,
                    'reason' => $assessment['reason'] ?? $assessment['state'],
                ];
                continue;
            }
            try {
                $current = $this->employments->currentTerms($supplierId, $employmentId)
                    ?? throw new \DomainException('Pracovní vztah nemá žádnou verzi podmínek.');
                $body = PayrollEmploymentTermsBody::fromCurrent($current, $reason);
                $body['effective_from'] = (string) $current['effective_from'];
                $body['work_place'] = $name;
                $body['jmhz_workplace_municipality_code'] = $code;
                $body['jmhz_workplace_country_code'] = $country;
                $this->employments->correctTerms(
                    $supplierId,
                    $employmentId,
                    $this->validator->terms(
                        $body,
                        $this->employments->currentCzIscoCode($supplierId, $employmentId),
                        $this->employments->currentOtherWithholdingEligibility($supplierId, $employmentId),
                        $this->employments->currentRelationType($supplierId, $employmentId),
                        // Mění se jen pracoviště; ostatní uložená pole nesmí
                        // doplnění zablokovat číselníkovou kontrolou.
                        $current,
                    ),
                    (int) $row['row_version'],
                    $userId,
                    $ip,
                    $userAgent,
                );
                $applied[] = ['employment_id' => $employmentId];
            } catch (\InvalidArgumentException|\DomainException|\RuntimeException $exception) {
                $failed[] = ['employment_id' => $employmentId, 'message' => $exception->getMessage()];
            }
        }

        $this->activityLogger->log(
            'payroll.employment.workplace_bulk_fill',
            $userId,
            null,
            null,
            [
                'period_start' => $monthStart,
                'municipality_code' => $code,
                'country_code' => $country,
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
            'municipality_code' => $code,
            'municipality_name' => $name,
            'country_code' => $country,
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
     * @param array<string,mixed> $row
     * @param list<string> $reported vykázané měsíce vztahu ({@see JmhzReportedEmploymentPeriods})
     * @return array{state:'missing'|'verified'|'invalid'|'excluded',reason:?string}
     */
    private function assess(int $supplierId, array $row, string $monthStart, string $monthEnd, array $reported): array
    {
        if ($row['term_id'] === null
            || ($row['effective_to'] !== null && (string) $row['effective_to'] < $monthStart)
        ) {
            return ['state' => 'excluded', 'reason' => 'terms_missing'];
        }
        if ((string) $row['effective_from'] > $monthEnd) {
            // Měsíc pokrývá starší verze podmínek. Opravou platné verze by se
            // změnilo jiné období, než o které jde.
            return ['state' => 'excluded', 'reason' => 'later_terms'];
        }
        $text = static fn (mixed $value): ?string => is_string($value) ? $value : null;
        if ($this->codebooks->workplaceProvenanceForPeriod(
            $text($row['jmhz_workplace_municipality_code']),
            $text($row['work_place']),
            $text($row['jmhz_workplace_country_code']),
            $text($row['jmhz_external_codebook_overlay_key']),
            $text($row['jmhz_external_codebook_manifest_sha256']),
            $monthStart,
            $monthEnd,
        ) !== null) {
            return ['state' => 'verified', 'reason' => null];
        }
        if ($row['jmhz_workplace_municipality_code'] !== null) {
            return ['state' => 'invalid', 'reason' => 'workplace_not_verified'];
        }
        // Ukončený vztah jde doplnit stejně jako na kartě, dokud za jeho
        // měsíce není hlášení JMHZ zmrazené ani podané (repozitář to při
        // zápisu ověří znovu pod zámkem).
        if ((string) $row['status'] === 'ended'
            && JmhzReportedEmploymentPeriods::firstWithin(
                $reported,
                (string) $row['effective_from'],
                self::termsEnd($row),
            ) !== null
        ) {
            return ['state' => 'excluded', 'reason' => 'employment_reported'];
        }
        try {
            $this->employments->assertTermsOpenFrom(
                $supplierId,
                (int) $row['id'],
                (string) $row['effective_from'],
                $row['effective_to'] === null ? null : (string) $row['effective_to'],
            );
        } catch (PayrollTermsSettledException) {
            return ['state' => 'excluded', 'reason' => 'period_settled'];
        }

        return ['state' => 'missing', 'reason' => null];
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
     * Poslední den opravované verze podmínek: její konec, nejpozději konec vztahu.
     *
     * @param array<string,mixed> $row
     */
    private static function termsEnd(array $row): ?string
    {
        $ends = array_filter(
            [$row['effective_to'], $row['end_date']],
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        );

        return $ends === [] ? null : min($ends);
    }

    /**
     * Vztahy trvající v měsíci s poslední verzí podmínek — tou, kterou
     * `correctTerms()` opravuje.
     *
     * @param list<int>|null $employmentIds
     * @return list<array<string,mixed>>
     */
    private function rows(int $supplierId, string $monthStart, string $monthEnd, ?array $employmentIds): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $filter = '';
        $params = [$supplierId, $monthStart, $monthEnd, $monthStart];
        if ($employmentIds !== null) {
            $filter = ' AND employment.id IN (' . implode(', ', array_fill(0, count($employmentIds), '?')) . ')';
            $params = [...$params, ...$employmentIds];
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT employment.id, employment.employee_id, employment.code, employment.status,
                    employment.end_date, employment.row_version, employee.full_name,
                    term.id AS term_id, term.effective_from, term.effective_to, term.work_place,
                    term.jmhz_workplace_municipality_code, term.jmhz_workplace_country_code,
                    term.jmhz_external_codebook_overlay_key,
                    term.jmhz_external_codebook_manifest_sha256
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
