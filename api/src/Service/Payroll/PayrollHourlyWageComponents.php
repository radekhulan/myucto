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
    public static function assertReservedClassification(array $component): void
    {
        $code = (string) ($component['code'] ?? '');
        if (!self::isReserved($code)) return;
        $expected = \MyInvoice\Service\Payroll\Component\PayrollComponentDefaults::template($code)
            ?? throw new \LogicException('Rezervovaná hodinová složka chybí ve výchozím číselníku.');
        foreach ($expected as $field => $value) {
            if (in_array($field, ['name', 'frequency_kind'], true)) continue;
            if (!array_key_exists($field, $component) || $component[$field] !== $value) {
                throw new \InvalidArgumentException('Chráněná složka hodinové mzdy nemá platnou klasifikaci.');
            }
        }
    }

    public static function isReserved(string $code): bool
    {
        return in_array($code, self::RESERVED, true);
    }
}
