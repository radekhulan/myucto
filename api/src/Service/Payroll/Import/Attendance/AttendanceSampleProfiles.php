<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Attendance;

/**
 * Vestavěné vzory profilu importu docházky podle klíče (`sample_key`).
 *
 * Každý vzor se firmě zakládá jednou (vlastní značka na firmě), aktualizuje
 * se a obnovuje zvlášť. Nový vzor = nová třída s pravidly a složkami, záznam
 * tady a sloupec značky v {@see \MyInvoice\Repository\Payroll\PayrollImportProfileRepository::SAMPLE_MARKERS}.
 */
final class AttendanceSampleProfiles
{
    public const GIRITON = AttendanceSampleProfile::KEY;
    public const MYUCTO = AttendanceMyUctoSampleProfile::KEY;
    public const PAMICA = AttendancePamicaSampleProfile::KEY;

    /** Klient drží stejné hodnoty jako union `AttendanceSampleKey` (PayrollEnumContractTest). */
    public const KEYS = [self::GIRITON, self::MYUCTO, self::PAMICA];

    /** @return list<string> */
    public static function keys(): array
    {
        return self::KEYS;
    }

    public static function isKey(mixed $key): bool
    {
        return is_string($key) && in_array($key, self::keys(), true);
    }

    public static function name(string $key): string
    {
        return match ($key) {
            self::GIRITON => AttendanceSampleProfile::NAME,
            self::MYUCTO => AttendanceMyUctoSampleProfile::NAME,
            self::PAMICA => AttendancePamicaSampleProfile::NAME,
            default => throw new \InvalidArgumentException('Neznámý vzor profilu mapování.'),
        };
    }

    public static function version(string $key): int
    {
        return match ($key) {
            self::GIRITON => AttendanceSampleProfile::VERSION,
            self::MYUCTO => AttendanceMyUctoSampleProfile::VERSION,
            self::PAMICA => AttendancePamicaSampleProfile::VERSION,
            default => throw new \InvalidArgumentException('Neznámý vzor profilu mapování.'),
        };
    }

    /** @return list<array<string,mixed>> */
    public static function rules(string $key): array
    {
        return match ($key) {
            self::GIRITON => AttendanceSampleProfile::rules(),
            self::MYUCTO => AttendanceMyUctoSampleProfile::rules(),
            self::PAMICA => AttendancePamicaSampleProfile::rules(),
            default => throw new \InvalidArgumentException('Neznámý vzor profilu mapování.'),
        };
    }

    /** @return list<array{code:string,name:string,kind:string}> */
    public static function components(string $key): array
    {
        return match ($key) {
            self::GIRITON => AttendanceSampleProfile::components(),
            self::MYUCTO => AttendanceMyUctoSampleProfile::components(),
            self::PAMICA => AttendancePamicaSampleProfile::components(),
            default => throw new \InvalidArgumentException('Neznámý vzor profilu mapování.'),
        };
    }
}
