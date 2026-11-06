<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

/** Složky, které smí vzniknout jen z auditovatelného výpočtu hodinové mzdy. */
final class PayrollHourlyWageComponents
{
    public const ORDINARY_BASE = 'MZDA_HODINOVA_ZAKLAD';
    public const ACHIEVED_OVERTIME = 'MZDA_DOSAZENA_PRESCAS';
    public const HOLIDAY_TIME_OFF = 'NAHRADA_MZDY_VOLNO_ZA_SVATEK';
    public const MINIMUM_TOP_UP = 'DOPLATEK_MINIMALNI_MZDA';
    public const RESERVED = [self::ORDINARY_BASE, self::ACHIEVED_OVERTIME, self::HOLIDAY_TIME_OFF, self::MINIMUM_TOP_UP];
    public const GENERATED = [
        ...self::RESERVED, 'NAHRADA_MZDY_SVATEK', 'PRIPLATEK_PRESCAS', 'PRIPLATEK_SVATEK',
        'PRIPLATEK_NOCNI', 'PRIPLATEK_VIKEND', 'PRIPLATEK_ZTIZENE_PROSTREDI',
    ];

    /**
     * Nové rezervované kódy nesmějí převzít odlišné peněžní zacházení vlastní
     * stejnojmenné složky. Klasifikaci neopravujeme; účetní musí střet vyřešit.
     * Název, četnost, účtování a cílová kolonka JMHZ zůstávají její volbou.
     * Starší sdílené příplatkové kódy tato nová ochrana nemění.
     *
     * @param array<string,mixed> $component
     */
    public static function hasReservedClassification(array $component): bool
    {
        $code = (string) ($component['code'] ?? '');
        if (!self::isReserved($code)) return true;
        $expected = \MyInvoice\Service\Payroll\Component\PayrollComponentDefaults::template($code)
            ?? throw new \LogicException('Rezervovaná hodinová složka chybí ve výchozím číselníku.');
        foreach ($expected as $field => $value) {
            if (in_array($field, ['name', 'frequency_kind'], true)) continue;
            if (!array_key_exists($field, $component) || $component[$field] !== $value) return false;
        }
        return true;
    }

    /**
     * Smí výpočet hodinové mzdy zapsat vstup na tuto účinnou verzi složky?
     *
     * @param array<string,mixed> $component
     */
    public static function isUsableDefinition(array $component): bool
    {
        $code = (string) ($component['code'] ?? '');
        if (!in_array($code, self::GENERATED, true)) return false;
        $expectedKind = in_array($code, [self::ORDINARY_BASE, self::ACHIEVED_OVERTIME, self::MINIMUM_TOP_UP], true) ? 'hourly_wage'
            : (str_starts_with($code, 'PRIPLATEK_') ? 'premium' : 'compensation');
        return ($component['component_kind'] ?? null) === $expectedKind
            && ($component['annual_limit_minor'] ?? null) === null
            && ($component['exemption_basket'] ?? null) === null
            && self::hasReservedClassification($component);
    }

    /**
     * Složky hodinového výpočtu, které firma v období nemá jednoznačně a s platnou
     * klasifikací (typicky vlastní stejnojmenná složka). Kód, který firma vůbec
     * nemá, doplní výchozí číselník až při zápisu, proto problémem není.
     *
     * @return array<string,'missing'|'ambiguous'|'classification'> kód => důvod
     */
    public static function definitionProblems(\PDO $pdo, int $supplierId, string $periodStart): array
    {
        $in = implode(',', array_fill(0, count(self::GENERATED), '?'));
        $stmt = $pdo->prepare("SELECT * FROM payroll_component_definitions WHERE supplier_id = ? AND code IN ($in)");
        $stmt->execute([$supplierId, ...self::GENERATED]);
        $known = [];
        $effective = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $code = (string) $row['code'];
            $known[$code] = true;
            if ((int) $row['is_active'] === 1 && $row['valid_from'] <= $periodStart && ($row['valid_to'] === null || $row['valid_to'] >= $periodStart)) {
                $effective[$code][] = $row;
            }
        }
        $problems = [];
        foreach (array_keys($known) as $code) {
            $rows = $effective[$code] ?? [];
            $problem = match (true) {
                $rows === [] => 'missing',
                count($rows) > 1 => 'ambiguous',
                !self::isUsableDefinition($rows[0]) => 'classification',
                default => null,
            };
            if ($problem !== null) $problems[$code] = $problem;
        }
        return $problems;
    }

    public static function isReserved(string $code): bool
    {
        return in_array($code, self::RESERVED, true);
    }
}
