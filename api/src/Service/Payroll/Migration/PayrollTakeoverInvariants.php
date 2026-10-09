<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use PDO;

/**
 * Invarianty převzetí mezd (brána G2): společná kontrola všech převodů, které píšou
 * převzaté mzdy přes {@see PayrollMigrationReferenceTotalsWriter} (PAMICA, PREMIER,
 * import hlášení JMHZ).
 *
 * Převod po dokončení předá úhrny tak, jak je přečetl ze zdroje, a kontrola ověří, že
 * je MyÚčto opravdu drží:
 *
 *  - žádný převzatý úhrn bez pracovního vztahu,
 *  - každý zdrojový vztah s výplatou má v MyÚčtu vztah (počet ze zdroje × počet v MyÚčtu),
 *  - součty po osobě a měsíci (hrubá mzda, vyměřovací základy, pojistné, daň) sedí na zdroj,
 *  - žádná osoba dvakrát (rodné číslo, OIČ, identita, osoba zdroje na dvou osobách),
 *  - žádné verze podmínek vztahu, mzdové složky ani pravidelné složky se nepřekrývají.
 *
 * Porušení je chyba převodu: do protokolu jde jako rozdíl k přijetí
 * ({@see ImportProtocol::difference()}), ne jako upozornění. Výsledek se uloží, aby ho
 * Kontrola převzetí ukázala i měsíce po převodu, kdy zdroj nikdo po ruce nemá; kontroly
 * nad vlastními daty počítá stránka znovu ({@see self::live()}).
 */
final class PayrollTakeoverInvariants
{
    public const ORPHAN_TOTALS = 'takeover_totals_without_employment';
    public const RELATION_MISSING = 'takeover_relation_missing';
    public const TOTALS_MISMATCH = 'takeover_totals_mismatch';
    public const SOURCE_ROWS_SKIPPED = 'takeover_source_rows_skipped';
    public const DUPLICATE_PERSON = 'takeover_duplicate_person';
    public const TERMS_OVERLAP = 'takeover_terms_overlap';
    public const COMPONENT_OVERLAP = 'takeover_component_overlap';
    public const RECURRING_OVERLAP = 'takeover_recurring_component_overlap';

    /** Všechny kódy porušení, jak jdou po drátě na Kontrolu převzetí. */
    public const CODES = [
        self::ORPHAN_TOTALS,
        self::RELATION_MISSING,
        self::TOTALS_MISMATCH,
        self::SOURCE_ROWS_SKIPPED,
        self::DUPLICATE_PERSON,
        self::TERMS_OVERLAP,
        self::COMPONENT_OVERLAP,
        self::RECURRING_OVERLAP,
    ];

    /** Kódy, které počítá stránka znovu z vlastních dat (zbytek je uložený výsledek převodu). */
    public const LIVE_CODES = [self::ORPHAN_TOTALS, self::DUPLICATE_PERSON, self::TERMS_OVERLAP, self::COMPONENT_OVERLAP, self::RECURRING_OVERLAP];

    /** Veličiny, jejichž součty po osobě a měsíci musí sedět na zdroj. */
    public const METRICS = [
        'gross' => 'gross_minor',
        'social_base' => 'social_base_minor',
        'health_base' => 'health_base_minor',
        'employee_social' => 'employee_social_minor',
        'employee_health' => 'employee_health_minor',
        'advance_tax' => 'advance_tax_minor',
        'withholding_tax' => 'withholding_tax_minor',
    ];

    /** Strop porušení jednoho kódu v protokolu a v uloženém výsledku. */
    private const LIMIT = 50;

    public function __construct(private readonly Connection $db) {}

    /**
     * Kontrola po převodu: zdrojová strana proti tomu, co je v MyÚčtu, a kontroly vlastních dat.
     *
     * @param list<PayrollMigrationReferenceTotals> $sourceTotals úhrny tak, jak je převod přečetl ze zdroje
     * @param int $sourceRowsSkipped zdrojové mzdy, které převod do úhrnů nedostal (bez měsíce nebo vztahu)
     * @return list<array{code:string,text:string,context:array<string,mixed>}>
     */
    public function verify(int $supplierId, string $source, array $sourceTotals, int $sourceRowsSkipped = 0, bool $persist = true): array
    {
        $violations = [];
        if ($sourceRowsSkipped > 0) {
            $violations[] = self::violation(self::SOURCE_ROWS_SKIPPED, sprintf(
                'Ze zdroje se do převzatých mezd nedostalo %d mezd bez platného měsíce nebo bez identifikace vztahu.',
                $sourceRowsSkipped,
            ), ['source' => $source, 'count' => $sourceRowsSkipped]);
        }
        if ($sourceTotals !== []) {
            $stored = $this->storedTotals($supplierId, $source, $sourceTotals);
            array_push($violations, ...self::relations($source, $sourceTotals, $stored));
            array_push($violations, ...self::compareTotals($source, self::sum($sourceTotals), self::sumStored($stored)));
        }
        array_push($violations, ...$this->live($supplierId, null, $source));
        if ($persist) {
            $this->store($supplierId, $source, $violations);
        }

        return $violations;
    }

    /**
     * Porušení jako rozdíly k přijetí v protokolu převodu.
     *
     * @param list<array{code:string,text:string,context:array<string,mixed>}> $violations
     */
    public static function report(ImportProtocol $protocol, string $step, array $violations): void
    {
        $protocol->begin($step);
        $protocol->setCount($step, 'invariant_violations', count($violations));
        foreach ($violations as $violation) {
            $protocol->difference($step, $violation['code'], $violation['text'], $violation['context']);
        }
        $protocol->finish($step);
    }

    /**
     * Kontroly nad vlastními daty firmy, které nepotřebují zdroj.
     *
     * @param ?int $year převzaté úhrny jen za rok (stránka), `null` = všechny
     * @param ?string $source převzaté úhrny jen z jednoho zdroje (převod), `null` = všechny
     * @return list<array{code:string,text:string,context:array<string,mixed>}>
     */
    public function live(int $supplierId, ?int $year = null, ?string $source = null): array
    {
        return [
            ...$this->orphanTotals($supplierId, $year, $source),
            ...$this->duplicatePersons($supplierId),
            ...$this->termsOverlaps($supplierId),
            ...$this->componentOverlaps($supplierId),
            ...$this->recurringOverlaps($supplierId),
        ];
    }

    /**
     * Uložené výsledky posledních převodů (bez kódů, které stránka počítá znovu).
     *
     * @return list<array{source:string,checked_at:string,violations:list<array{code:string,text:string,context:array<string,mixed>}>}>
     */
    public function stored(int $supplierId): array
    {
        if (!$this->db->hasTable('payroll_takeover_invariant_checks')) {
            return [];
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT source, checked_at, result_json FROM payroll_takeover_invariant_checks WHERE supplier_id = ? ORDER BY source',
        );
        $statement->execute([$supplierId]);
        $out = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $violations = json_decode((string) $row['result_json'], true);
            $out[] = [
                'source' => (string) $row['source'],
                'checked_at' => (string) $row['checked_at'],
                'violations' => array_values(array_filter(
                    is_array($violations) ? $violations : [],
                    static fn (mixed $v): bool => is_array($v) && !in_array($v['code'] ?? '', self::LIVE_CODES, true),
                )),
            ];
        }

        return $out;
    }

    /**
     * Součty zdroje a MyÚčta po osobě a měsíci. Porovnávají se jen dvojice, které zdroj
     * nese: import hlášení může převzít jen část formulářů měsíce.
     *
     * @param array<string,array<string,array<string,int>>> $expected osoba zdroje => měsíc => veličina => haléře
     * @param array<string,array<string,array<string,int>>> $actual totéž z MyÚčta
     * @return list<array{code:string,text:string,context:array<string,mixed>}>
     */
    public static function compareTotals(string $source, array $expected, array $actual): array
    {
        $out = [];
        ksort($expected, SORT_STRING);
        foreach ($expected as $person => $periods) {
            ksort($periods, SORT_STRING);
            foreach ($periods as $period => $metrics) {
                $differences = [];
                foreach (array_keys(self::METRICS) as $metric) {
                    $want = $metrics[$metric] ?? 0;
                    $have = $actual[$person][$period][$metric] ?? 0;
                    if ($want !== $have) {
                        $differences[$metric] = ['source_minor' => $want, 'takeover_minor' => $have];
                    }
                }
                if ($differences === []) {
                    continue;
                }
                $out[] = self::violation(self::TOTALS_MISMATCH, sprintf(
                    'Převzaté úhrny osoby %s za %s nesedí na zdroj: %s.',
                    (string) $person,
                    (string) $period,
                    implode(', ', array_map(
                        static fn (string $metric, array $d): string => sprintf('%s zdroj %s, MyÚčto %s', $metric,
                            self::money($d['source_minor']), self::money($d['takeover_minor'])),
                        array_keys($differences),
                        $differences,
                    )),
                ), ['source' => $source, 'external_person_ref' => (string) $person, 'period' => (string) $period, 'differences' => $differences]);
            }
        }

        return self::limited($out);
    }

    /**
     * @param list<PayrollMigrationReferenceTotals> $totals
     * @return array<string,array<string,array<string,int>>>
     */
    public static function sum(array $totals): array
    {
        $out = [];
        foreach ($totals as $row) {
            $values = $row->metrics();
            foreach (array_keys(self::METRICS) as $metric) {
                $out[$row->externalPersonRef][$row->period][$metric] = ($out[$row->externalPersonRef][$row->period][$metric] ?? 0) + $values[$metric];
            }
        }

        return $out;
    }

    /**
     * Každý zdrojový vztah s výplatou má převzatý úhrn navázaný na existující vztah.
     *
     * @param list<PayrollMigrationReferenceTotals> $sourceTotals
     * @param list<array<string,mixed>> $stored
     * @return list<array{code:string,text:string,context:array<string,mixed>}>
     */
    private static function relations(string $source, array $sourceTotals, array $stored): array
    {
        /** @var array<string,array{person:string,periods:list<string>}> $paid */
        $paid = [];
        foreach ($sourceTotals as $row) {
            if ($row->grossMinor === 0 && $row->netMinor === 0) {
                continue;
            }
            $paid[$row->externalRelationshipRef] ??= ['person' => $row->externalPersonRef, 'periods' => []];
            $paid[$row->externalRelationshipRef]['periods'][] = $row->period;
        }
        $linked = [];
        foreach ($stored as $row) {
            if ($row['employment_exists']) {
                $linked[(string) $row['external_relationship_ref']] = true;
            }
        }
        $out = [];
        ksort($paid, SORT_STRING);
        foreach ($paid as $ref => $relation) {
            if (isset($linked[(string) $ref])) {
                continue;
            }
            $periods = array_values(array_unique($relation['periods']));
            sort($periods, SORT_STRING);
            $out[] = self::violation(self::RELATION_MISSING, sprintf(
                'Vztah %s osoby %s má ve zdroji výplatu (%s), ale v MyÚčtu k němu pracovní vztah není. Zdroj má vztahů s výplatou %d, MyÚčto z nich drží %d.',
                (string) $ref, $relation['person'], implode(', ', $periods), count($paid), count(array_intersect_key($linked, $paid)),
            ), ['source' => $source, 'external_relationship_ref' => (string) $ref, 'external_person_ref' => $relation['person'], 'periods' => $periods,
                'source_relations' => count($paid), 'linked_relations' => count(array_intersect_key($linked, $paid))]);
        }

        return self::limited($out);
    }

    /**
     * Uložené převzaté úhrny osob a měsíců, které zdroj nese.
     *
     * @param list<PayrollMigrationReferenceTotals> $sourceTotals
     * @return list<array<string,mixed>>
     */
    private function storedTotals(int $supplierId, string $source, array $sourceTotals): array
    {
        $periods = [];
        foreach ($sourceTotals as $row) {
            $periods[$row->period . '-01'] = true;
        }
        $columns = implode(', ', array_map(static fn (string $c): string => 't.' . $c, self::METRICS));
        $out = [];
        foreach (array_chunk(array_keys($periods), 200) as $chunk) {
            $statement = $this->db->pdo()->prepare(sprintf(
                'SELECT t.external_person_ref, t.external_relationship_ref, DATE_FORMAT(t.period_start, "%%Y-%%m") AS period,
                        e.id IS NOT NULL AS employment_exists, %s
                   FROM payroll_migration_reference_totals t
                   LEFT JOIN payroll_employments e ON e.supplier_id = t.supplier_id AND e.id = t.employment_id
                  WHERE t.supplier_id = ? AND t.source = ? AND t.period_start IN (%s)',
                $columns,
                implode(', ', array_fill(0, count($chunk), '?')),
            ));
            $statement->execute([$supplierId, $source, ...$chunk]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $row['employment_exists'] = (int) $row['employment_exists'] === 1;
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string,mixed>> $stored
     * @return array<string,array<string,array<string,int>>>
     */
    private static function sumStored(array $stored): array
    {
        $out = [];
        foreach ($stored as $row) {
            $person = (string) $row['external_person_ref'];
            $period = (string) $row['period'];
            foreach (self::METRICS as $metric => $column) {
                $out[$person][$period][$metric] = ($out[$person][$period][$metric] ?? 0) + (int) $row[$column];
            }
        }

        return $out;
    }

    /** @return list<array{code:string,text:string,context:array<string,mixed>}> */
    private function orphanTotals(int $supplierId, ?int $year, ?string $source): array
    {
        $where = '';
        $params = [$supplierId];
        if ($year !== null) {
            $where .= ' AND t.period_start BETWEEN ? AND ?';
            $params[] = sprintf('%04d-01-01', $year);
            $params[] = sprintf('%04d-12-01', $year);
        }
        if ($source !== null) {
            $where .= ' AND t.source = ?';
            $params[] = $source;
        }
        $statement = $this->db->pdo()->prepare(
            "SELECT t.source, t.external_relationship_ref, MIN(t.external_person_ref) AS external_person_ref, MAX(t.employee_id) AS employee_id,
                    GROUP_CONCAT(DATE_FORMAT(t.period_start, '%Y-%m') ORDER BY t.period_start SEPARATOR ',') AS periods
               FROM payroll_migration_reference_totals t
               LEFT JOIN payroll_employments e ON e.supplier_id = t.supplier_id AND e.id = t.employment_id
              WHERE t.supplier_id = ? AND e.id IS NULL{$where}
              GROUP BY t.source, t.external_relationship_ref
              ORDER BY t.source, t.external_relationship_ref",
        );
        $statement->execute($params);
        $out = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $periods = explode(',', (string) $row['periods']);
            $out[] = self::violation(self::ORPHAN_TOTALS, sprintf(
                'Převzaté mzdy vztahu %s osoby %s (%s) nevisí na žádném pracovním vztahu: %s.',
                (string) $row['external_relationship_ref'], (string) $row['external_person_ref'], (string) $row['source'], implode(', ', $periods),
            ), [
                'source' => (string) $row['source'],
                'external_relationship_ref' => (string) $row['external_relationship_ref'],
                'external_person_ref' => (string) $row['external_person_ref'],
                'employee_id' => $row['employee_id'] === null ? null : (int) $row['employee_id'],
                'periods' => $periods,
            ]);
        }

        return self::limited($out);
    }

    /** @return list<array{code:string,text:string,context:array<string,mixed>}> */
    private function duplicatePersons(int $supplierId): array
    {
        $groups = [];
        $add = function (string $kind, string $sql) use ($supplierId, &$groups): void {
            $statement = $this->db->pdo()->prepare($sql);
            $statement->execute([$supplierId]);
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $ids) {
                $list = array_map('intval', explode(',', (string) $ids));
                sort($list);
                $groups[$kind . ':' . implode(',', $list)] = ['kind' => $kind, 'employee_ids' => $list];
            }
        };
        $add('birth_number', 'SELECT GROUP_CONCAT(DISTINCT employee_id ORDER BY employee_id) FROM payroll_person_identifiers
            WHERE supplier_id = ? AND identifier_type = "birth_number" GROUP BY value_hash HAVING COUNT(DISTINCT employee_id) > 1');
        $add('oic', 'SELECT GROUP_CONCAT(DISTINCT employee_id ORDER BY employee_id) FROM payroll_person_external_ids
            WHERE supplier_id = ? AND identifier_type = "ik_mpsv" AND valid_to IS NULL GROUP BY environment, value_hash HAVING COUNT(DISTINCT employee_id) > 1');
        $add('identity', 'SELECT GROUP_CONCAT(DISTINCT employee_id ORDER BY employee_id) FROM payroll_person_identity_history
            WHERE supplier_id = ? AND effective_to IS NULL AND birth_date IS NOT NULL
            GROUP BY LOWER(first_name), LOWER(last_name), birth_date HAVING COUNT(DISTINCT employee_id) > 1');
        // Shoda jména a data narození je dvojí osoba jen tehdy, když ji rodná čísla nevyvrací:
        // mají-li všechny osoby skupiny rodné číslo a každá jiné, jde o různé lidi.
        $birthNumbers = $this->db->pdo()->prepare(
            'SELECT employee_id, value_hash FROM payroll_person_identifiers WHERE supplier_id = ? AND identifier_type = "birth_number"',
        );
        $birthNumbers->execute([$supplierId]);
        $hashes = [];
        foreach ($birthNumbers->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $hashes[(int) $row['employee_id']] = (string) $row['value_hash'];
        }
        foreach ($groups as $key => $group) {
            if ($group['kind'] !== 'identity') {
                continue;
            }
            $known = array_intersect_key($hashes, array_flip($group['employee_ids']));
            if (count($known) === count($group['employee_ids']) && count(array_unique($known)) === count($known)) {
                unset($groups[$key]);
            }
        }
        $add('source_person', 'SELECT GROUP_CONCAT(DISTINCT employee_id ORDER BY employee_id) FROM payroll_migration_reference_totals
            WHERE supplier_id = ? AND employee_id IS NOT NULL GROUP BY source, external_person_ref HAVING COUNT(DISTINCT employee_id) > 1');
        $out = [];
        foreach ($groups as $group) {
            $out[] = self::violation(self::DUPLICATE_PERSON, sprintf(
                'Osoba je v MyÚčtu vícekrát (%s): osoby %s.',
                match ($group['kind']) {
                    'birth_number' => 'stejné rodné číslo',
                    'oic' => 'stejné OIČ',
                    'identity' => 'stejné jméno a datum narození',
                    default => 'jedna osoba zdroje na více osobách',
                },
                implode(', ', array_map(static fn (int $id): string => '#' . $id, $group['employee_ids'])),
            ), $group);
        }

        return self::limited($out);
    }

    /** @return list<array{code:string,text:string,context:array<string,mixed>}> */
    private function termsOverlaps(int $supplierId): array
    {
        $statement = $this->db->pdo()->prepare(
            "WITH ordered AS (
                 SELECT t.employment_id, t.effective_from, t.effective_to,
                        LEAD(t.effective_from) OVER (PARTITION BY t.employment_id ORDER BY t.effective_from, t.id) AS next_from,
                        LEAD(t.effective_to) OVER (PARTITION BY t.employment_id ORDER BY t.effective_from, t.id) AS next_to
                   FROM payroll_employment_terms t
                  WHERE t.supplier_id = ?
             )
             SELECT o.employment_id, e.code, e.employee_id, o.effective_from, o.effective_to, o.next_from, o.next_to
               FROM ordered o
               JOIN payroll_employments e ON e.supplier_id = ? AND e.id = o.employment_id
              WHERE o.next_from IS NOT NULL AND (o.effective_to IS NULL OR o.effective_to >= o.next_from)
              ORDER BY o.employment_id, o.effective_from",
        );
        $statement->execute([$supplierId, $supplierId]);
        $out = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = self::violation(self::TERMS_OVERLAP, sprintf(
                'Verze podmínek vztahu %s se překrývají: %s až %s a %s až %s.',
                (string) $row['code'], (string) $row['effective_from'], self::date($row['effective_to']),
                (string) $row['next_from'], self::date($row['next_to']),
            ), [
                'employment_id' => (int) $row['employment_id'],
                'employment_code' => (string) $row['code'],
                'employee_id' => (int) $row['employee_id'],
                'ranges' => [[(string) $row['effective_from'], $row['effective_to']], [(string) $row['next_from'], $row['next_to']]],
            ]);
        }

        return self::limited($out);
    }

    /** @return list<array{code:string,text:string,context:array<string,mixed>}> */
    private function componentOverlaps(int $supplierId): array
    {
        $statement = $this->db->pdo()->prepare(
            "WITH ordered AS (
                 SELECT d.code, d.valid_from, d.valid_to,
                        LEAD(d.valid_from) OVER (PARTITION BY d.code ORDER BY d.valid_from, d.id) AS next_from,
                        LEAD(d.valid_to) OVER (PARTITION BY d.code ORDER BY d.valid_from, d.id) AS next_to
                   FROM payroll_component_definitions d
                  WHERE d.supplier_id = ?
             )
             SELECT code, valid_from, valid_to, next_from, next_to FROM ordered
              WHERE next_from IS NOT NULL AND (valid_to IS NULL OR valid_to >= next_from)
              ORDER BY code, valid_from",
        );
        $statement->execute([$supplierId]);
        $out = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = self::violation(self::COMPONENT_OVERLAP, sprintf(
                'Verze mzdové složky %s se překrývají: %s až %s a %s až %s.',
                (string) $row['code'], self::date($row['valid_from']), self::date($row['valid_to']),
                self::date($row['next_from']), self::date($row['next_to']),
            ), [
                'component_code' => (string) $row['code'],
                'ranges' => [[self::date($row['valid_from']), $row['valid_to'] === null ? null : self::date($row['valid_to'])],
                    [self::date($row['next_from']), $row['next_to'] === null ? null : self::date($row['next_to'])]],
            ]);
        }

        return self::limited($out);
    }

    /** @return list<array{code:string,text:string,context:array<string,mixed>}> */
    private function recurringOverlaps(int $supplierId): array
    {
        $statement = $this->db->pdo()->prepare(
            "WITH ordered AS (
                 SELECT r.employment_id, d.code, r.valid_from, r.valid_to,
                        LEAD(r.valid_from) OVER (PARTITION BY r.employment_id, d.code ORDER BY r.valid_from, r.id) AS next_from,
                        LEAD(r.valid_to) OVER (PARTITION BY r.employment_id, d.code ORDER BY r.valid_from, r.id) AS next_to
                   FROM payroll_recurring_components r
                   JOIN payroll_component_definitions d ON d.supplier_id = r.supplier_id AND d.id = r.component_id
                  WHERE r.supplier_id = ? AND r.is_active = 1
             )
             SELECT o.employment_id, e.code AS employment_code, e.employee_id, o.code, o.valid_from, o.valid_to, o.next_from, o.next_to
               FROM ordered o
               JOIN payroll_employments e ON e.supplier_id = ? AND e.id = o.employment_id
              WHERE o.next_from IS NOT NULL AND (o.valid_to IS NULL OR o.valid_to >= o.next_from)
              ORDER BY o.employment_id, o.code, o.valid_from",
        );
        $statement->execute([$supplierId, $supplierId]);
        $out = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = self::violation(self::RECURRING_OVERLAP, sprintf(
                'Pravidelná složka %s vztahu %s má překrývající se verze: %s až %s a %s až %s.',
                (string) $row['code'], (string) $row['employment_code'], self::date($row['valid_from']), self::date($row['valid_to']),
                self::date($row['next_from']), self::date($row['next_to']),
            ), [
                'employment_id' => (int) $row['employment_id'],
                'employment_code' => (string) $row['employment_code'],
                'employee_id' => (int) $row['employee_id'],
                'component_code' => (string) $row['code'],
                'ranges' => [[self::date($row['valid_from']), $row['valid_to'] === null ? null : self::date($row['valid_to'])],
                    [self::date($row['next_from']), $row['next_to'] === null ? null : self::date($row['next_to'])]],
            ]);
        }

        return self::limited($out);
    }

    /** @param list<array{code:string,text:string,context:array<string,mixed>}> $violations */
    private function store(int $supplierId, string $source, array $violations): void
    {
        if (!$this->db->hasTable('payroll_takeover_invariant_checks')) {
            return;
        }
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_takeover_invariant_checks (supplier_id, source, checked_at, violation_count, result_json)
             VALUES (?, ?, NOW(), ?, ?)
             ON DUPLICATE KEY UPDATE checked_at = VALUES(checked_at), violation_count = VALUES(violation_count), result_json = VALUES(result_json)',
        )->execute([$supplierId, $source, count($violations),
            json_encode($violations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
    }

    /**
     * Strop porušení jednoho kódu; zbytek shrne poslední položka s počtem.
     *
     * @param list<array{code:string,text:string,context:array<string,mixed>}> $violations
     * @return list<array{code:string,text:string,context:array<string,mixed>}>
     */
    private static function limited(array $violations): array
    {
        if (count($violations) <= self::LIMIT) {
            return $violations;
        }
        $rest = count($violations) - self::LIMIT;
        $kept = array_slice($violations, 0, self::LIMIT);
        $kept[] = self::violation($violations[0]['code'], sprintf('A dalších %d porušení téhož druhu.', $rest), ['truncated' => $rest]);

        return $kept;
    }

    /**
     * @param array<string,mixed> $context
     * @return array{code:string,text:string,context:array<string,mixed>}
     */
    private static function violation(string $code, string $text, array $context): array
    {
        return ['code' => $code, 'text' => $text, 'context' => $context];
    }

    private static function date(mixed $value): string
    {
        return $value === null ? '…' : substr((string) $value, 0, 10);
    }

    private static function money(int $minor): string
    {
        return number_format($minor / 100, 2, ',', ' ') . ' Kč';
    }
}
