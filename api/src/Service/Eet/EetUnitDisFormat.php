<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

/**
 * Údaje evidenční jednotky pro oznámení v DIS+ a jejich hromadný import.
 *
 * Zdroj: GFŘ, „Import evidenčních jednotek EET – popis struktury CSV souboru"
 * (verze 3. 11. 2026) a § 15 odst. 2 zákona č. 180/2026 Sb. CSV má 16 sloupců
 * v předepsaném pořadí, oddělovač „;", bez záhlaví, nejvýš 500 řádků; texty
 * nesmí obsahovat „;" ani „|" a musí jít uložit v ISO-8859-2.
 */
final class EetUnitDisFormat
{
    /** Druh jednotky v aplikaci → kód druhu v DIS+ (sl. 2). `other` kód nemá. */
    public const KIND_CODES = [
        'premises' => 'S',
        'mobile' => 'M',
        'vending' => 'A',
        'online' => 'I',
        'vehicle' => 'D',
        'taxpayer' => 'P',
    ];

    /** Druh dopravního prostředku (sl. 15). */
    public const VEHICLE_KINDS = ['A', 'N', 'B', 'M', 'V', 'T', 'P', 'L', 'J'];

    /** Nejvýš řádků jednoho importního souboru. */
    public const MAX_ROWS = 500;

    /** Druhy, jejichž lokalizací je adresa (sl. 7 až 13). */
    private const ADDRESS_KINDS = ['premises', 'vending', 'taxpayer'];

    /** Textová pole: sloupec → nejvyšší délka. */
    private const TEXT_LIMITS = [
        'activity_note' => 255,
        'street' => 32,
        'house_number' => 10,
        'orientation_number' => 10,
        'municipality' => 48,
        'municipality_part' => 48,
        'location_text' => 255,
        'vehicle_kind_note' => 150,
    ];

    /**
     * Přečte a ověří formát údajů pro DIS+ ze vstupu (úplnost hlídá až export,
     * aby šly uložit i rozpracované jednotky).
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $current
     * @return array<string,mixed>
     */
    public static function normalize(array $input, ?array $current): array
    {
        $pick = static fn (string $key): mixed => array_key_exists($key, $input) ? $input[$key] : ($current[$key] ?? null);
        $data = [];

        $nace = self::text($pick('nace_code'));
        if ($nace !== null && preg_match('/^\d{1,6}$/D', $nace) !== 1) {
            throw EetException::validation('Kód převažující činnosti (CZ-NACE) smí mít jen 1 až 6 číslic.', ['field' => 'nace_code']);
        }
        $data['nace_code'] = $nace;

        $ruian = self::text($pick('ruian_code'));
        if ($ruian !== null && preg_match('/^\d{1,9}$/D', $ruian) !== 1) {
            throw EetException::validation('Kód adresního místa RÚIAN smí mít jen 1 až 9 číslic.', ['field' => 'ruian_code']);
        }
        $data['ruian_code'] = $ruian === null ? null : (int) $ruian;

        $postal = self::text($pick('postal_code'));
        $postal = $postal === null ? null : str_replace(' ', '', $postal);
        if ($postal !== null && preg_match('/^\d{5}$/D', $postal) !== 1) {
            throw EetException::validation('PSČ musí mít přesně 5 číslic.', ['field' => 'postal_code']);
        }
        $data['postal_code'] = $postal;

        foreach (self::TEXT_LIMITS as $field => $limit) {
            $value = self::text($pick($field));
            if ($value !== null) {
                self::assertText($value, $limit, $field);
            }
            $data[$field] = $value;
        }

        $vehicle = self::text($pick('vehicle_kind'));
        if ($vehicle !== null && !in_array($vehicle, self::VEHICLE_KINDS, true)) {
            throw EetException::validation('Druh dopravního prostředku musí být jeden z: ' . implode(', ', self::VEHICLE_KINDS) . '.', ['field' => 'vehicle_kind']);
        }
        $data['vehicle_kind'] = $vehicle;

        foreach (['records_sales' => true, 'indirect_representation' => false] as $field => $default) {
            $value = $pick($field);
            $value = $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($value === null) {
                throw EetException::validation('Příznak musí být true/false.', ['field' => $field]);
            }
            $data[$field] = $value;
        }

        return $data;
    }

    /**
     * Co jednotce chybí k importu do DIS+.
     *
     * @param array<string,mixed> $unit jednotka ve tvaru {@see EetUnitRepository}
     * @return list<array{field:string,message:string}>
     */
    public static function problems(array $unit): array
    {
        $kind = (string) $unit['kind'];
        $problems = [];
        $add = static function (string $field, string $message) use (&$problems): void {
            $problems[] = ['field' => $field, 'message' => $message];
        };
        if (!isset(self::KIND_CODES[$kind])) {
            $add('kind', 'Druh „jiný" DIS+ nezná, vyberte druh podle finanční správy.');
        }
        if (($unit['nace_code'] ?? null) === null) {
            $add('nace_code', 'Chybí kód převažující činnosti (CZ-NACE).');
        }
        $name = (string) ($unit['name'] ?? '');
        if (str_contains($name, ';') || str_contains($name, '|') || !self::latin2($name)) {
            $add('name', 'Název obsahuje znak, který import DIS+ nepřijme („;", „|" nebo znak mimo češtinu).');
        }
        if (in_array($kind, self::ADDRESS_KINDS, true) && ($unit['ruian_code'] ?? null) === null) {
            $hasAddress = false;
            foreach (['street', 'house_number', 'orientation_number', 'municipality', 'municipality_part', 'postal_code'] as $field) {
                $hasAddress = $hasAddress || ($unit[$field] ?? null) !== null;
            }
            // Automat smí mít místo adresy jen popis umístění.
            if ($kind !== 'vending' || $hasAddress) {
                foreach (['house_number' => 'číslo popisné nebo evidenční', 'municipality' => 'obec', 'postal_code' => 'PSČ'] as $field => $label) {
                    if (($unit[$field] ?? null) === null) {
                        $add($field, 'Chybí kód adresního místa RÚIAN, nebo ' . $label . '.');
                    }
                }
            } elseif (($unit['location_text'] ?? null) === null) {
                $add('location_text', 'Automat bez adresy potřebuje popis umístění.');
            }
        }
        if (in_array($kind, ['mobile', 'online', 'vehicle'], true) && ($unit['location_text'] ?? null) === null) {
            $add('location_text', match ($kind) {
                'mobile' => 'Chybí popis umístění mobilní provozovny.',
                'online' => 'Chybí adresa internetové stránky nebo název aplikace.',
                default => 'Chybí registrační značka nebo jiné označení dopravního prostředku.',
            });
        }
        if ($kind === 'vehicle') {
            if (($unit['vehicle_kind'] ?? null) === null) {
                $add('vehicle_kind', 'Chybí druh dopravního prostředku.');
            } elseif ($unit['vehicle_kind'] === 'J' && ($unit['vehicle_kind_note'] ?? null) === null) {
                $add('vehicle_kind_note', 'U druhu „jiný" chybí upřesnění dopravního prostředku.');
            }
        }

        return $problems;
    }

    /**
     * Importní soubor DIS+ (UTF-8 bez BOM, bez záhlaví, řádky CRLF).
     *
     * @param list<array<string,mixed>> $units jednotky bez problémů ({@see self::problems()})
     */
    public static function csv(array $units): string
    {
        $lines = [];
        foreach ($units as $unit) {
            $lines[] = implode(';', self::row($unit));
        }

        return $lines === [] ? '' : implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Sloupce 1 až 16 v pořadí podle popisu struktury.
     *
     * @param array<string,mixed> $unit
     * @return list<string>
     */
    public static function row(array $unit): array
    {
        $kind = (string) $unit['kind'];
        $address = in_array($kind, self::ADDRESS_KINDS, true);
        $byRuian = $address && ($unit['ruian_code'] ?? null) !== null;
        $vehicle = $kind === 'vehicle';
        $value = static fn (string $field, bool $used = true): string => $used && ($unit[$field] ?? null) !== null
            ? (string) $unit[$field]
            : '';

        return [
            (string) $unit['name'],
            self::KIND_CODES[$kind] ?? '',
            $value('nace_code'),
            $value('activity_note'),
            ($unit['records_sales'] ?? true) ? '1' : '0',
            ($unit['indirect_representation'] ?? false) ? '1' : '0',
            $value('ruian_code', $address),
            $value('street', $address && !$byRuian),
            $value('house_number', $address && !$byRuian),
            $value('orientation_number', $address && !$byRuian),
            $value('municipality', $address && !$byRuian),
            $value('municipality_part', $address && !$byRuian),
            $value('postal_code', $address && !$byRuian),
            $value('location_text'),
            $value('vehicle_kind', $vehicle),
            $value('vehicle_kind_note', $vehicle && ($unit['vehicle_kind'] ?? null) === 'J'),
        ];
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null || (!is_string($value) && !is_int($value))) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function assertText(string $value, int $limit, string $field): void
    {
        if (mb_strlen($value) > $limit) {
            throw EetException::validation("Údaj může mít nejvýš {$limit} znaků.", ['field' => $field]);
        }
        if (str_contains($value, ';') || str_contains($value, '|')) {
            throw EetException::validation('Údaj nesmí obsahovat znaky „;" ani „|" (import DIS+ je odmítne).', ['field' => $field]);
        }
        if (!self::latin2($value)) {
            throw EetException::validation('Údaj obsahuje znak, který DIS+ neuloží (jen čeština a běžná interpunkce).', ['field' => $field]);
        }
    }

    /** Lze text uložit v ISO-8859-2 (požadavek importu DIS+)? */
    private static function latin2(string $value): bool
    {
        $converted = @iconv('UTF-8', 'ISO-8859-2', $value);

        return $converted !== false && iconv('ISO-8859-2', 'UTF-8', $converted) === $value;
    }
}
