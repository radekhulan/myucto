<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

use MyInvoice\Service\Payroll\Time\Surcharge\PayrollQuickSurchargeCalculator;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeKind;

final class PayrollQuickInputValidator
{
    /**
     * @param array<string,mixed> $body
     * @return array{
     *   period:string,
     *   rows:list<array{
     *     employment_id:int,
     *     employment_row_version:int,
     *     base_amount_minor:?int,
     *     overtime_mode:string,
     *     overtime_hours_milli:?int,
     *     overtime_amount_minor:?int,
     *     overtime_average_snapshot_id:?int,
     *     overtime_average_snapshot_version:?int,
     *     bonus_amount_minor:int,
     *     surcharges:array<string,array{hours_milli:?int,factors:?int}>,
     *     versions:array{base:?int,overtime:?int,bonus:?int,surcharges:array<string,?int>}
     *   }>
     * }
     */
    public function validate(array $body): array
    {
        $period = $this->period($body['period'] ?? null);
        $rawRows = $body['rows'] ?? null;
        if (!is_array($rawRows) || !array_is_list($rawRows) || count($rawRows) > 500) {
            throw new \InvalidArgumentException('rows musí být seznam nejvýše 500 pracovních vztahů.');
        }

        $seen = [];
        $rows = [];
        foreach ($rawRows as $raw) {
            if (!is_array($raw)) {
                throw new \InvalidArgumentException('Každý řádek rychlého vstupu musí být objekt.');
            }
            $employmentId = $this->positiveInt($raw['employment_id'] ?? null, 'employment_id');
            if (isset($seen[$employmentId])) {
                throw new \InvalidArgumentException('Pracovní vztah je v požadavku uveden vícekrát.');
            }
            $seen[$employmentId] = true;
            $mode = $raw['overtime_mode'] ?? null;
            if (!in_array($mode, ['hours', 'amount'], true)) {
                throw new \InvalidArgumentException('overtime_mode musí být hours nebo amount.');
            }
            $hours = $this->nullableNonNegativeInt(
                $raw['overtime_hours_milli'] ?? null,
                'overtime_hours_milli',
                1_000_000,
            );
            $overtimeAmount = $this->nullableNonNegativeInt(
                $raw['overtime_amount_minor'] ?? null,
                'overtime_amount_minor',
            );
            if ($mode === 'hours' && $hours === null) {
                throw new \InvalidArgumentException('Pro přesčas podle hodin vyplňte počet hodin.');
            }
            if ($mode === 'amount' && $overtimeAmount === null) {
                throw new \InvalidArgumentException('Pro přesčas podle částky vyplňte celkovou částku.');
            }
            $averageSnapshotId = $this->nullablePositiveInt(
                $raw['overtime_average_snapshot_id'] ?? null,
                'overtime_average_snapshot_id',
            );
            $averageSnapshotVersion = $this->nullablePositiveInt(
                $raw['overtime_average_snapshot_version'] ?? null,
                'overtime_average_snapshot_version',
            );
            if ($mode === 'hours'
                && ($averageSnapshotId === null || $averageSnapshotVersion === null)) {
                throw new \InvalidArgumentException(
                    'Přesčas podle hodin vyžaduje identifikaci a verzi schváleného průměru.'
                );
            }
            $versions = $raw['versions'] ?? null;
            if (!is_array($versions)) {
                throw new \InvalidArgumentException('versions musí obsahovat verze měněných vstupů.');
            }
            $rows[] = [
                'employment_id' => $employmentId,
                'employment_row_version' => $this->positiveInt(
                    $raw['employment_row_version'] ?? null,
                    'employment_row_version',
                ),
                'base_amount_minor' => $this->baseAmount($raw),
                'overtime_mode' => $mode,
                'overtime_hours_milli' => $mode === 'hours' ? $hours : null,
                'overtime_amount_minor' => $mode === 'amount' ? $overtimeAmount : null,
                'overtime_average_snapshot_id' => $mode === 'hours'
                    ? $averageSnapshotId
                    : null,
                'overtime_average_snapshot_version' => $mode === 'hours'
                    ? $averageSnapshotVersion
                    : null,
                'bonus_amount_minor' => $this->nonNegativeInt(
                    $raw['bonus_amount_minor'] ?? null,
                    'bonus_amount_minor',
                ),
                'surcharges' => $this->surcharges($raw),
                'versions' => [
                    'base' => $this->nullablePositiveInt($versions['base'] ?? null, 'versions.base'),
                    'overtime' => $this->nullablePositiveInt($versions['overtime'] ?? null, 'versions.overtime'),
                    'bonus' => $this->nullablePositiveInt($versions['bonus'] ?? null, 'versions.bonus'),
                    'surcharges' => $this->surchargeVersions($versions),
                ],
            ];
        }

        return ['period' => $period, 'rows' => $rows];
    }

    /**
     * Ručně zadané hodiny zákonných příplatků § 115 až § 118.
     *
     * Klíč, který v požadavku není, znamená „tenhle druh neřeším" — ne
     * „vyprázdnit". Rozdíl je podstatný: starší klient, který o příplatcích neví,
     * by jinak každým uložením zrušil, co zadal někdo jiný v novějším.
     * Vyprázdnění se posílá výslovným `null`.
     *
     * @param array<string,mixed> $raw
     * @return array<string,array{hours_milli:?int,factors:?int}>
     */
    private function surcharges(array $raw): array
    {
        $input = $raw['surcharges'] ?? null;
        if ($input === null) {
            return [];
        }
        if (!is_array($input)) {
            throw new \InvalidArgumentException('surcharges musí být objekt podle druhu příplatku.');
        }

        $result = [];
        foreach ($input as $key => $value) {
            $kind = PayrollSurchargeKind::tryFrom((string) $key);
            if ($kind === null || !$kind->allowsQuickManualEntry()) {
                throw new \InvalidArgumentException(
                    "Druh příplatku {$key} nelze v rychlém měsíčním vstupu zadat."
                );
            }
            if (!is_array($value)) {
                throw new \InvalidArgumentException(
                    "surcharges.{$key} musí být objekt s počtem hodin."
                );
            }
            $factors = $this->nullablePositiveInt(
                $value['factors'] ?? null,
                "surcharges.{$key}.factors",
            );
            if ($factors !== null && $factors > 255) {
                throw new \InvalidArgumentException(
                    'Počet ztěžujících vlivů podle § 117 musí být 1 až 255.'
                );
            }
            if ($factors !== null && $kind !== PayrollSurchargeKind::DifficultEnvironment) {
                // Násobit noční nebo víkendový příplatek počtem vlivů zákon
                // nedovoluje. Kdyby to prošlo, byl by to nenápadný přeplatek.
                throw new \InvalidArgumentException(
                    'Počet ztěžujících vlivů má smysl jen u příplatku § 117.'
                );
            }
            $result[$kind->value] = [
                'hours_milli' => $this->nullableNonNegativeInt(
                    $value['hours_milli'] ?? null,
                    "surcharges.{$key}.hours_milli",
                    PayrollQuickSurchargeCalculator::MAX_HOURS_MILLI,
                ),
                'factors' => $factors,
            ];
        }

        return $result;
    }

    /**
     * @param array<array-key,mixed> $versions
     * @return array<string,?int>
     */
    private function surchargeVersions(array $versions): array
    {
        $raw = $versions['surcharges'] ?? null;
        if ($raw !== null && !is_array($raw)) {
            throw new \InvalidArgumentException('versions.surcharges musí být objekt.');
        }
        $result = [];
        foreach (PayrollSurchargeKind::quickManualEntry() as $kind) {
            $result[$kind->value] = $this->nullablePositiveInt(
                is_array($raw) ? ($raw[$kind->value] ?? null) : null,
                "versions.surcharges.{$kind->value}",
            );
        }

        return $result;
    }

    public function period(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('period musí být měsíc YYYY-MM.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m', $value);
        if ($date === false || $date->format('Y-m') !== $value) {
            throw new \InvalidArgumentException('period musí být měsíc YYYY-MM.');
        }
        return $value;
    }

    /**
     * Základní mzda rozlišuje prázdné pole od zadané nuly.
     *
     * `null` = uživatel pole nevyplnil, základ v tomto měsíci neřeší.
     * `0` = uživatel vědomě zadal nulu; v částečném nebo přerušeném měsíci to je
     * plnohodnotný údaj („nic se nevydělalo"), a musí tedy vzniknout řádek.
     * Bez tohoto rozlišení obojí přicházelo jako `0` a nulový základ nešlo zadat.
     *
     * Chybějící klíč zůstává chybou — jen výslovné `null` znamená „nevyplněno".
     * Kdyby se klíč směl vynechat, rozbitý klient by tiše zrušil existující základ.
     *
     * @param array<string,mixed> $raw
     */
    private function baseAmount(array $raw): ?int
    {
        if (!array_key_exists('base_amount_minor', $raw)) {
            throw new \InvalidArgumentException(
                'base_amount_minor musí být uvedeno; nevyplněné pole pošlete jako null.'
            );
        }

        return $this->nullableNonNegativeInt(
            $raw['base_amount_minor'],
            'base_amount_minor',
        );
    }

    private function positiveInt(mixed $value, string $field): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($number)) {
            throw new \InvalidArgumentException("{$field} musí být kladné celé číslo.");
        }
        return $number;
    }

    private function nonNegativeInt(mixed $value, string $field, int $maximum = 1_000_000_000_000): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => $maximum],
        ]);
        if (!is_int($number)) {
            throw new \InvalidArgumentException("{$field} musí být nezáporné celé číslo.");
        }
        return $number;
    }

    private function nullableNonNegativeInt(
        mixed $value,
        string $field,
        int $maximum = 1_000_000_000_000,
    ): ?int {
        return $value === null ? null : $this->nonNegativeInt($value, $field, $maximum);
    }

    private function nullablePositiveInt(mixed $value, string $field): ?int
    {
        return $value === null ? null : $this->positiveInt($value, $field);
    }
}
