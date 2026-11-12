<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

/**
 * Kontrola, že {@see AnonymizationPolicy} pokrývá strukturu databáze.
 *
 * Tutéž kontrolu pouští architektonický test nad otiskem `db/schema.snapshot.json`
 * i samotná anonymizace nad živou databází před prvním zápisem — kopie se nevyrobí,
 * dokud existuje textový sloupec bez rozhodnutí.
 */
final class AnonymizationPolicyAudit
{
    /**
     * Je sloupec „textový", tedy může nést osobní údaj? Výčty (`enum`) ne —
     * hodnoty jsou pevně dané schématem. Generované sloupce se odvozují z jiných.
     */
    public static function isTextColumn(string $definition): bool
    {
        $definition = strtolower(trim($definition));
        if (str_contains($definition, 'generated')) {
            return false;
        }

        return preg_match('/^(varchar|char|tinytext|text|mediumtext|longtext|json|tinyblob|blob|mediumblob|longblob|varbinary|binary|set)\b/', $definition) === 1;
    }

    /**
     * @param array<string, array<string,string>> $tables tabulka → sloupec → definice typu
     * @param array<string, list<array{table:string, references:string}>> $foreignKeys cizí klíče
     * @param bool $olderSource struktura je starší verze (chybějící tabulky a sloupce politiky nevadí)
     * @return list<string> popis nálezů (prázdné = politika pokrývá strukturu)
     */
    public static function problems(array $tables, array $foreignKeys = [], bool $olderSource = false): array
    {
        $problems = [];
        $known = AnonymizationPolicy::knownStrategies();

        foreach (AnonymizationPolicy::TRUNCATE as $table => $reason) {
            if (!isset($tables[$table]) && !$olderSource) {
                $problems[] = "TRUNCATE: tabulka {$table} ve struktuře neexistuje.";
            }
            if (isset(AnonymizationPolicy::COLUMNS[$table])) {
                $problems[] = "TRUNCATE: tabulka {$table} má zároveň sloupcová pravidla.";
            }
        }

        foreach ($tables as $table => $columns) {
            if (isset(AnonymizationPolicy::TRUNCATE[$table])) {
                continue;
            }
            $textColumns = array_keys(array_filter($columns, self::isTextColumn(...)));
            $policy = AnonymizationPolicy::COLUMNS[$table] ?? null;
            if ($policy === null) {
                if ($textColumns !== []) {
                    $problems[] = "{$table}: tabulka s textovými sloupci (" . implode(', ', $textColumns)
                        . ') nemá rozhodnutí v AnonymizationPolicy::COLUMNS ani TRUNCATE.';
                }
                continue;
            }
            foreach ($textColumns as $column) {
                if (!isset($policy[$column])) {
                    $problems[] = "{$table}.{$column}: nový textový sloupec bez rozhodnutí (anonymizovat / ponechat).";
                }
            }
            $companions = [];
            foreach ($policy as $column => $strategy) {
                if (!isset($columns[$column])) {
                    if (!$olderSource) {
                        $problems[] = "{$table}.{$column}: sloupec v politice ve struktuře neexistuje.";
                    }
                    continue;
                }
                if (!self::isTextColumn($columns[$column])) {
                    $problems[] = "{$table}.{$column}: sloupec není textový (generovaný nebo jiný typ) — do politiky nepatří.";
                }
                if (AnonymizationPolicy::isSealed($strategy)) {
                    $sealed = AnonymizationPolicy::parseSealed($strategy);
                    foreach (array_filter([$sealed['hash'], $sealed['masked']]) as $companion) {
                        if (($policy[$companion] ?? null) !== 'sealed_companion') {
                            $problems[] = "{$table}.{$companion}: doprovodný sloupec šifrované hodnoty musí mít strategii sealed_companion.";
                        }
                        $companions[$companion] = true;
                    }
                    foreach (array_filter([$sealed['entity'], $sealed['type_column']]) as $needed) {
                        if (!isset($columns[$needed])) {
                            $problems[] = "{$table}.{$needed}: sloupec, na který odkazuje šifrovaná hodnota, neexistuje.";
                        }
                    }
                    if ($sealed['type_column'] === null && \MyInvoice\Service\Payroll\Security\PayrollSensitiveField::tryFrom($sealed['field']) === null) {
                        $problems[] = "{$table}.{$column}: neznámé pole šifrované hodnoty {$sealed['field']}.";
                    }
                    continue;
                }
                if (!in_array($strategy, $known, true)) {
                    $problems[] = "{$table}.{$column}: neznámá strategie {$strategy}.";
                }
            }
            foreach ($policy as $column => $strategy) {
                if ($strategy === 'sealed_companion' && !isset($companions[$column])) {
                    $problems[] = "{$table}.{$column}: sealed_companion bez šifrovaného sloupce, ke kterému patří.";
                }
            }
        }

        foreach (AnonymizationPolicy::COLUMNS as $table => $policy) {
            if (!isset($tables[$table]) && !$olderSource) {
                $problems[] = "{$table}: tabulka v politice ve struktuře neexistuje.";
            }
        }

        foreach ($foreignKeys as $table => $keys) {
            if (isset(AnonymizationPolicy::TRUNCATE[$table])) {
                continue;
            }
            foreach ($keys as $key) {
                // Vyprazdňuje se s vypnutou kontrolou cizích klíčů (bez triggerů a kaskád),
                // takže i CASCADE / SET NULL by v kopii nechaly neplatné odkazy.
                if (isset(AnonymizationPolicy::TRUNCATE[$key['references']])) {
                    $problems[] = "{$table}: cizí klíč na vyprázdněnou tabulku {$key['references']} — vyprázdni i {$table}, jinak kopie ponese neplatné odkazy.";
                }
            }
        }

        foreach (AnonymizationPolicy::POST_SQL as $sql) {
            if (preg_match('/^UPDATE\s+(\w+)\s+SET\s+(\w+)\s*=/i', $sql, $m) !== 1) {
                $problems[] = "POST_SQL: nerozpoznaný příkaz {$sql}";
                continue;
            }
            if (!isset($tables[$m[1]][$m[2]]) && !$olderSource) {
                $problems[] = "POST_SQL: {$m[1]}.{$m[2]} ve struktuře neexistuje.";
            }
        }

        return $problems;
    }

    /**
     * Struktura z otisku `db/schema.snapshot.json` ve tvaru pro {@see problems()}.
     *
     * @param array{tables: array<string, array{type?:string, columns: array<string,string>, foreign_keys?: array<string,string>}>} $snapshot
     * @return array{0: array<string, array<string,string>>, 1: array<string, list<array{table:string, references:string, on_delete:string}>>}
     */
    public static function fromSnapshot(array $snapshot): array
    {
        $tables = [];
        $foreignKeys = [];
        foreach ($snapshot['tables'] as $table => $definition) {
            if (($definition['type'] ?? 'BASE TABLE') === 'VIEW') {
                continue;
            }
            $tables[$table] = $definition['columns'];
            foreach ($definition['foreign_keys'] ?? [] as $fk) {
                if (preg_match('/->\s*(\w+)\s*\(.*ON DELETE (CASCADE|SET NULL|RESTRICT|NO ACTION|SET DEFAULT)/i', $fk, $m) === 1) {
                    $foreignKeys[$table][] = ['table' => $table, 'references' => $m[1], 'on_delete' => $m[2]];
                }
            }
        }

        return [$tables, $foreignKeys];
    }
}
