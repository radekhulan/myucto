<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use DateTimeImmutable;
use InvalidArgumentException;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\PayrollApprovedPeriodFreeze;
use MyInvoice\Service\Payroll\PayrollPersonStatutoryEvidenceValidator;
use PDO;
use UnexpectedValueException;

final class PayrollPersonStatutoryEvidenceRepository
{
    /**
     * Jediný popis kolekcí zákonné evidence osoby.
     *
     * Dotaz za jednu osobu i dávkový dotaz nad celou množinou se z něj GENERUJÍ,
     * takže nemůžou dostat jiné sloupce ani jiné řazení. Kdyby to byly dva SQL
     * literály, rozejdou se — a snapshot mzdového běhu by tiše změnil obsah.
     *
     * @var array<string, array<string, array{table:string, columns:string, order:string}>>
     */
    private const COLLECTIONS = [
        'health' => [
            'coverages' => [
                'table' => 'payroll_person_health_coverage_history',
                'columns' => 'id, jurisdiction, foreign_country_code,
                            jurisdiction_evidence_reference, insurer_status,
                            insurer_code, insurer_evidence_reference,
                            health_evidence_document_id, health_evidence_document_sha256,
                            effective_from, effective_to, row_version',
                'order' => 'effective_from, id',
            ],
            'minimum_reductions' => [
                'table' => 'payroll_person_health_minimum_reductions',
                'columns' => 'id, reason, evidence_reference,
                            effective_from, effective_to, row_version',
                'order' => 'reason, effective_from, id',
            ],
            'month_evidence' => [
                'table' => 'payroll_person_health_month_evidence',
                'columns' => 'id, period_start, top_up_responsibility,
                            top_up_responsibility_evidence_reference,
                            selected_top_up_employer_reference,
                            selected_top_up_employer_evidence_reference,
                            row_version',
                'order' => 'period_start, id',
            ],
            'other_employer_bases' => [
                'table' => 'payroll_person_health_other_employer_bases',
                'columns' => 'id, period_start, employer_reference,
                            assessment_base_minor_units, employment_from,
                            employment_to, evidence_reference, row_version',
                'order' => 'period_start, employer_reference, id',
            ],
        ],
        'income_tax' => [
            'declarations' => [
                'table' => 'payroll_person_tax_declarations',
                'columns' => 'id, status, effective_from, effective_to,
                            evidence_reference, row_version',
                'order' => 'effective_from, id',
            ],
            'residences' => [
                'table' => 'payroll_person_tax_residences',
                'columns' => 'id, residence, country_code, effective_from,
                            effective_to, evidence_reference, row_version',
                'order' => 'effective_from, id',
            ],
            'credit_claims' => [
                'table' => 'payroll_person_tax_credit_claims',
                'columns' => 'id, credit_kind, evidence_status, effective_from,
                            effective_to, evidence_reference, row_version',
                'order' => 'credit_kind, effective_from, id',
            ],
            'child_claims' => [
                'table' => 'payroll_person_tax_child_claims',
                'columns' => 'id, child_reference, child_order, ztp_p,
                            evidence_status, shared_household_confirmed,
                            other_claimant_excluded, effective_from, effective_to,
                            evidence_reference, row_version',
                'order' => 'child_order, child_reference, effective_from, id',
            ],
        ],
        'social' => [
            'jurisdictions' => [
                'table' => 'payroll_person_social_jurisdictions',
                'columns' => 'id, jurisdiction, foreign_country_code,
                            jurisdiction_evidence_reference, a1_status,
                            a1_certificate_reference, a1_valid_until,
                            effective_from, effective_to, row_version',
                'order' => 'effective_from, id',
            ],
            'discount_claims' => [
                'table' => 'payroll_person_social_discount_claims',
                'columns' => 'id, status, effective_from, effective_to,
                            evidence_reference, row_version',
                'order' => 'effective_from, id',
            ],
        ],
    ];

    /**
     * Kolekce, do kterých vede zapisovací cesta z karty osoby.
     *
     * Klíč = jméno sekce v API i v UI, `section`/`collection` ukazuje zpět do
     * `COLLECTIONS`, odkud se bere tabulka i seznam sloupců — editor tak nemůže
     * číst jiné sloupce než snímek mzdového běhu.
     *
     * `fields` jsou VĚCNÉ sloupce (bez `id`, dat účinnosti a `row_version`);
     * jejich hodnoty prochází stejným `PayrollPersonStatutoryEvidenceValidator`em
     * jako čtecí cesta, takže se pravidla nemůžou rozejít.
     *
     * @var array<string, array{
     *     section:string,
     *     collection:string,
     *     kind:'interval'|'month',
     *     fields:list<string>
     * }>
     */
    /**
     * Jména sekcí, jak je zná API i UI — pár k unionu
     * `PayrollStatutoryEvidenceSection` ve `web/src/api/payroll.ts`.
     *
     * Musí se krýt s klíči {@see self::EDITABLE}; PHP konstantu nejde odvodit
     * výrazem, tak to hlídá test (`PayrollPersonStatutoryEvidenceApiTest`).
     *
     * @var list<string>
     */
    public const EDITABLE_SECTIONS = [
        'tax_declarations',
        'tax_residences',
        'social_jurisdictions',
        'social_discount_claims',
        'health_coverages',
        'health_month_evidence',
    ];

    private const EDITABLE = [
        'tax_declarations' => [
            'section' => 'income_tax',
            'collection' => 'declarations',
            'kind' => 'interval',
            'fields' => ['status', 'evidence_reference'],
        ],
        'tax_residences' => [
            'section' => 'income_tax',
            'collection' => 'residences',
            'kind' => 'interval',
            'fields' => ['residence', 'country_code', 'evidence_reference'],
        ],
        'social_jurisdictions' => [
            'section' => 'social',
            'collection' => 'jurisdictions',
            'kind' => 'interval',
            'fields' => [
                'jurisdiction',
                'foreign_country_code',
                'jurisdiction_evidence_reference',
                'a1_status',
                'a1_certificate_reference',
                'a1_valid_until',
            ],
        ],
        'social_discount_claims' => [
            'section' => 'social',
            'collection' => 'discount_claims',
            'kind' => 'interval',
            'fields' => ['status', 'evidence_reference'],
        ],
        'health_coverages' => [
            'section' => 'health',
            'collection' => 'coverages',
            'kind' => 'interval',
            'fields' => [
                'jurisdiction',
                'foreign_country_code',
                'jurisdiction_evidence_reference',
                'insurer_status',
                'insurer_code',
                'insurer_evidence_reference',
                'health_evidence_document_id',
                'health_evidence_document_sha256',
            ],
        ],
        'health_month_evidence' => [
            'section' => 'health',
            'collection' => 'month_evidence',
            'kind' => 'month',
            'fields' => [
                'top_up_responsibility',
                'top_up_responsibility_evidence_reference',
                'selected_top_up_employer_reference',
                'selected_top_up_employer_evidence_reference',
            ],
        ],
    ];

    /**
     * Lidské vysvětlení k důkazu. Není v `COLLECTIONS`, protože do snímku
     * mzdového běhu nepatří — reference musí zůstat kanonická (viz validátor),
     * takže „proč to tak je" nemá kam jinam jít.
     */
    private const NOTE_COLUMN = 'evidence_note';

    private const NOTE_MAX_LENGTH = 500;

    /** Alias skupinového klíče; po seskupení se z řádku zase odstraní. */
    private const GROUP_KEY = 'snapshot_group_employee_id';

    private const CHUNK_SIZE = 500;

    private const SAVEPOINT = 'payroll_person_statutory_evidence_save';

    /**
     * ID pro řádky, které teprve vzniknou.
     *
     * Validátor vyžaduje kladné `id` i `row_version` už při kontrole — a to je
     * dobře, protože jinak by se čtecí a zápisová cesta validovaly jinak. Nové
     * řádky proto dostanou jen pro tu kontrolu náhradní ID; do SQL se nikdy
     * nedostane, INSERT si nechá vygenerovat vlastní.
     */
    private const PLANNED_ID_BASE = 1_000_000_000;

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollPersonStatutoryEvidenceValidator $validator,
        private readonly PayrollApprovedPeriodFreeze $freeze,
        private readonly ActivityLogger $activityLogger,
    ) {}

    /** @return array<string,mixed>|null */
    public function snapshot(
        int $supplierId,
        int $employeeId,
        string $effectiveOn,
    ): ?array {
        $exists = $this->db->pdo()->prepare(
            'SELECT 1
               FROM payroll_employees
              WHERE supplier_id = ? AND id = ?'
        );
        $exists->execute([$supplierId, $employeeId]);
        if ($exists->fetchColumn() === false) {
            return null;
        }

        $raw = [];
        foreach (self::COLLECTIONS as $section => $collections) {
            foreach ($collections as $key => $collection) {
                $raw[$section][$key] = $this->rows(
                    sprintf(
                        'SELECT %s FROM %s WHERE supplier_id = ? AND employee_id = ?
                          ORDER BY %s',
                        $collection['columns'],
                        $collection['table'],
                        $collection['order'],
                    ),
                    [$supplierId, $employeeId],
                );
            }
        }

        return $this->validator->normalize($employeeId, $effectiveOn, $raw);
    }

    /**
     * Dávkový protějšek snapshot(): jedenáct dotazů na CELOU množinu osob místo
     * jedenácti na každou z nich.
     *
     * Výsledek je pro každou osobu bajtově shodný se samostatným snapshot():
     * seskupovací sloupec jde do SELECTu pod vlastním aliasem a po seskupení se
     * z řádku odstraní, `ORDER BY` zůstává beze změny — podposloupnost globálně
     * seřazeného výsledku pro jednu osobu má totéž pořadí jako dotaz za ni samotnou.
     *
     * @param list<int> $employeeIds
     * @return array<int,array<string,mixed>> klíčováno ID osoby; osoby, které
     *     firmě nepatří, ve výsledku chybí (jako null z snapshot())
     */
    public function snapshotMany(
        int $supplierId,
        array $employeeIds,
        string $effectiveOn,
    ): array {
        $known = $this->existingEmployeeIds($supplierId, $employeeIds);
        if ($known === []) {
            return [];
        }

        $grouped = [];
        foreach (self::COLLECTIONS as $section => $collections) {
            foreach ($collections as $key => $collection) {
                $grouped[$section][$key] = $this->groupedRows($collection, $supplierId, $known);
            }
        }

        $result = [];
        foreach ($known as $employeeId) {
            $raw = [];
            foreach ($grouped as $section => $collections) {
                foreach ($collections as $key => $rows) {
                    $raw[$section][$key] = $rows[$employeeId] ?? [];
                }
            }
            $result[$employeeId] = $this->validator->normalize(
                $employeeId,
                $effectiveOn,
                $raw,
            );
        }

        return $result;
    }

    /**
     * Podklad pro editor zákonné evidence na kartě osoby.
     *
     * Na rozdíl od `snapshot()` vrací CELOU historii (uživatel musí vidět, co
     * kdy platilo), a k tomu blokátory k datu snímku — tedy přesně ty klíče,
     * kterými `PayrollRunStatutoryInputAssembler` shodí mzdový běh do ručního
     * posouzení. Bez nich by stránka jen ukazovala prázdné tabulky a nedala by
     * odpověď na otázku „proč mi neprojde srpen".
     *
     * @return array<string,mixed>|null null = osoba téhle firmě nepatří
     */
    public function editorView(
        int $supplierId,
        int $employeeId,
        string $effectiveOn,
    ): ?array {
        if (!$this->employeeExists($supplierId, $employeeId)) {
            return null;
        }

        $sections = [];
        foreach (array_keys(self::EDITABLE) as $key) {
            $sections[$key] = $this->editorRows($supplierId, $employeeId, $key);
        }

        return [
            'employee_id' => $employeeId,
            'effective_on' => $effectiveOn,
            'frozen_through' => $this->freeze->frozenThrough($supplierId),
            'sections' => $sections,
            // Volba plátce doplatku minima se odkazuje na vyměřovací základ
            // u jiného zaměstnavatele. Ten se tady needituje, ale bez jeho
            // seznamu by uživatel v UI vybíral referenci naslepo.
            'other_employer_bases' => $this->rows(
                sprintf(
                    'SELECT %s FROM %s WHERE supplier_id = ? AND employee_id = ?
                      ORDER BY %s',
                    self::COLLECTIONS['health']['other_employer_bases']['columns'],
                    self::COLLECTIONS['health']['other_employer_bases']['table'],
                    self::COLLECTIONS['health']['other_employer_bases']['order'],
                ),
                [$supplierId, $employeeId],
            ),
            'blockers' => $this->blockers($supplierId, $employeeId, $effectiveOn),
        ];
    }

    /**
     * Uloží celou zákonnou evidenci osoby jedním krokem.
     *
     * ## Proč jeden společný zápis a ne endpoint na řádek
     *
     * Kolekce jsou ČASOVÉ ŘADY a jejich pravidla (žádný překryv, žádná díra)
     * platí nad celou řadou, ne nad řádkem. Kdyby se řádky ukládaly po jednom,
     * musel by mezistav pravidla porušovat — uživatel by musel hádat pořadí
     * kroků. Tělo požadavku proto popisuje CÍLOVÝ stav a rozdíl si spočítá
     * server.
     *
     * ## Jak se verzuje v čase
     *
     * 1. Řádek, který začal PŘED koncem posledního schváleného období, je
     *    zmrazený: jeho začátek se nesmí posunout, nesmí se smazat a věcná
     *    změna se do něj nezapíše. Místo toho se uzavře posledním zmrazeným
     *    dnem a nová právní skutečnost vznikne jako NOVÝ řádek od následujícího
     *    dne — historie schválené mzdy se tak nikdy nepřepíše.
     * 2. Doplnit CHYBĚJÍCÍ řádek do zmrazeného období naopak jde. Nic tím
     *    nepřepisuje (schválená revize si drží vlastní snímek) a je to přesně
     *    ten úkon, kvůli kterému uživatel na stránku přišel.
     * 3. Řádky jedné řady musí na sebe navazovat den po dni. Díra v řadě není
     *    „nevyplněno", ale měsíc, ve kterém mzdový běh spadne do ručního
     *    posouzení — a to je lepší odmítnout při zápisu než objevit při výpočtu.
     * 4. Účinnost se zadává po celých měsících. Čtecí cesta totiž vyhodnocuje
     *    evidenci k prvnímu dni měsíce (daně) nebo přes celý kalendářní měsíc
     *    (pojistné), takže změna uprostřed měsíce by se buď ztratila, nebo by
     *    ve snímku vyrobila dvě současně platné verze.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function save(
        int $supplierId,
        int $employeeId,
        array $payload,
        string $effectiveOn,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        }

        try {
            $this->lockEmployee($supplierId, $employeeId);
            $frozenThrough = $this->freeze->frozenThrough($supplierId);

            $plans = [];
            $deletions = [];
            foreach (self::EDITABLE as $key => $spec) {
                $current = [];
                foreach ($this->editorRows($supplierId, $employeeId, $key) as $row) {
                    $current[(int) $row['id']] = $row;
                }
                [$plans[$key], $deletions[$key]] = $this->planCollection(
                    $key,
                    $spec,
                    $current,
                    $this->inputRows($payload, $key),
                    $frozenThrough,
                );
            }

            foreach (self::EDITABLE as $key => $spec) {
                if ($spec['kind'] === 'interval') {
                    $this->assertTimeline($key, $plans[$key]);
                }
            }
            $this->assertPlannedEvidenceIsValid(
                $supplierId,
                $employeeId,
                $plans,
                $effectiveOn,
            );
            $this->resolveHealthEvidenceDocuments($supplierId, $employeeId, $plans);

            $counts = $this->executePlan(
                $supplierId,
                $employeeId,
                $plans,
                $deletions,
                $userId,
            );
            $this->activityLogger->log(
                'payroll.person_statutory_evidence.saved',
                $userId,
                'payroll_employee',
                $employeeId,
                $counts + [
                    'effective_on' => $effectiveOn,
                    'frozen_through' => $frozenThrough,
                ],
                $ip,
                $userAgent,
                $supplierId,
            );

            $view = $this->editorView($supplierId, $employeeId, $effectiveOn)
                ?? throw new PayrollPersonNotFoundException();
            if ($owns) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }

            return $view;
        } catch (\Throwable $exception) {
            if ($owns) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } else {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }
            if ($exception instanceof \PDOException
                && (string) $exception->getCode() === '23000'
            ) {
                throw new InvalidArgumentException(
                    'Zákonná evidence obsahuje záznam, který databáze odmítla'
                    . ' jako duplicitní nebo vnitřně rozporný.',
                    0,
                    $exception,
                );
            }
            throw $exception;
        }
    }

    /**
     * @param array{section:string,collection:string,kind:'interval'|'month',fields:list<string>} $spec
     * @param array<int,array<string,mixed>> $current
     * @param list<array<string,mixed>> $input
     * @return array{0:list<array<string,mixed>>,1:list<int>}
     */
    private function planCollection(
        string $key,
        array $spec,
        array $current,
        array $input,
        ?string $frozenThrough,
    ): array {
        $plan = [];
        $seen = [];
        foreach ($input as $index => $row) {
            $values = $this->inputValues($key, $spec['fields'], $row, $index);
            $note = $this->inputNote($key, $row, $index);
            $id = $this->inputId($key, $row, $index);

            if ($id === null) {
                $plan[] = $this->plannedRow(
                    null,
                    null,
                    $spec['kind'] === 'month'
                        ? ['period_start' => $this->requiredDate($key, $row, 'period_start')]
                        : [
                            'effective_from' => $this->requiredDate($key, $row, 'effective_from'),
                            'effective_to' => $this->optionalDate($key, $row, 'effective_to'),
                        ],
                    $values,
                    $note,
                    true,
                );
                continue;
            }
            $existing = $current[$id] ?? throw new InvalidArgumentException(sprintf(
                'Záznam %d v evidenci „%s“ u této osoby neexistuje.',
                $id,
                $key,
            ));
            if (isset($seen[$id])) {
                throw new InvalidArgumentException(sprintf(
                    'Záznam %d je v evidenci „%s“ poslán dvakrát.',
                    $id,
                    $key,
                ));
            }
            $seen[$id] = true;
            $this->assertVersion($key, $id, $row, $existing);

            $substantive = false;
            foreach ($spec['fields'] as $field) {
                if ($this->nullableText($existing[$field] ?? null) !== $values[$field]) {
                    $substantive = true;
                    break;
                }
            }

            if ($spec['kind'] === 'month') {
                $plan[] = $this->plannedMonthUpdate(
                    $key,
                    $id,
                    $existing,
                    $row,
                    $values,
                    $note,
                    $substantive,
                    $frozenThrough,
                );
                continue;
            }
            foreach ($this->plannedIntervalUpdate(
                $key,
                $spec,
                $id,
                $existing,
                $row,
                $values,
                $note,
                $substantive,
                $frozenThrough,
            ) as $planned) {
                $plan[] = $planned;
            }
        }

        $deletions = [];
        foreach ($current as $id => $existing) {
            if (isset($seen[$id])) {
                continue;
            }
            $start = (string) ($spec['kind'] === 'month'
                ? $existing['period_start']
                : $existing['effective_from']);
            if ($frozenThrough !== null && $start <= $frozenThrough) {
                throw new InvalidArgumentException(sprintf(
                    'Záznam v evidenci „%s“ od %s spadá do období uzavřeného'
                    . ' schválenou mzdou — smazat ho nelze, jde jen ukončit.',
                    $key,
                    $start,
                ));
            }
            $deletions[] = $id;
        }

        return [$plan, $deletions];
    }

    /**
     * @param array{section:string,collection:string,kind:'interval'|'month',fields:list<string>} $spec
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $row
     * @param array<string,?string> $values
     * @return list<array<string,mixed>>
     */
    private function plannedIntervalUpdate(
        string $key,
        array $spec,
        int $id,
        array $existing,
        array $row,
        array $values,
        ?string $note,
        bool $substantive,
        ?string $frozenThrough,
    ): array {
        $existingFrom = (string) $existing['effective_from'];
        $existingTo = $this->nullableText($existing['effective_to'] ?? null);
        $from = $this->requiredDate($key, $row, 'effective_from');
        $to = $this->optionalDate($key, $row, 'effective_to');
        $frozen = $frozenThrough !== null && $existingFrom <= $frozenThrough;

        if (!$frozen) {
            return [$this->plannedRow(
                $id,
                (int) $existing['row_version'],
                ['effective_from' => $from, 'effective_to' => $to],
                $values,
                $note,
                $substantive || $from !== $existingFrom || $to !== $existingTo,
            )];
        }

        if ($from !== $existingFrom) {
            throw new InvalidArgumentException(sprintf(
                'Začátek záznamu v evidenci „%s“ je uzavřený schválenou mzdou'
                . ' (do %s) a nelze ho posunout.',
                $key,
                (string) $frozenThrough,
            ));
        }
        if (!$substantive) {
            if ($to !== $existingTo && $to !== null && $to < $frozenThrough) {
                throw new InvalidArgumentException(sprintf(
                    'Záznam v evidenci „%s“ nelze ukončit uvnitř období'
                    . ' uzavřeného schválenou mzdou (do %s).',
                    $key,
                    (string) $frozenThrough,
                ));
            }

            return [$this->plannedRow(
                $id,
                (int) $existing['row_version'],
                ['effective_from' => $existingFrom, 'effective_to' => $to],
                $values,
                $note,
                $to !== $existingTo,
            )];
        }

        if ($existingTo !== null && $existingTo < $frozenThrough) {
            throw new InvalidArgumentException(sprintf(
                'Záznam v evidenci „%s“ už skončil uvnitř období uzavřeného'
                . ' schválenou mzdou — jeho obsah se měnit nesmí.',
                $key,
            ));
        }
        $next = (new DateTimeImmutable((string) $frozenThrough))
            ->modify('+1 day')
            ->format('Y-m-d');
        if ($to !== null && $to < $next) {
            throw new InvalidArgumentException(sprintf(
                'Nová verze záznamu v evidenci „%s“ musí platit nejdříve od %s.',
                $key,
                $next,
            ));
        }

        $historical = [];
        foreach ($spec['fields'] as $field) {
            $historical[$field] = $this->nullableText($existing[$field] ?? null);
        }

        return [
            $this->plannedRow(
                $id,
                (int) $existing['row_version'],
                ['effective_from' => $existingFrom, 'effective_to' => $frozenThrough],
                $historical,
                $this->nullableText($existing[self::NOTE_COLUMN] ?? null),
                true,
            ),
            $this->plannedRow(
                null,
                null,
                ['effective_from' => $next, 'effective_to' => $to],
                $values,
                $note,
                true,
            ),
        ];
    }

    /**
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $row
     * @param array<string,?string> $values
     * @return array<string,mixed>
     */
    private function plannedMonthUpdate(
        string $key,
        int $id,
        array $existing,
        array $row,
        array $values,
        ?string $note,
        bool $substantive,
        ?string $frozenThrough,
    ): array {
        $existingStart = (string) $existing['period_start'];
        $start = $this->requiredDate($key, $row, 'period_start');
        $noteChanged = $note !== $this->nullableText($existing[self::NOTE_COLUMN] ?? null);
        $changed = $substantive || $noteChanged || $start !== $existingStart;
        // Měsíční evidence NENÍ interval — jednotkou je celý měsíc, takže není
        // kam odsunout novou verzi. Zmrazený měsíc proto zůstává, jak byl.
        if ($changed && $frozenThrough !== null && $existingStart <= $frozenThrough) {
            throw new InvalidArgumentException(sprintf(
                'Měsíční evidence za %s spadá do období uzavřeného schválenou'
                . ' mzdou a měnit ji nelze.',
                substr($existingStart, 0, 7),
            ));
        }

        return $this->plannedRow(
            $id,
            (int) $existing['row_version'],
            ['period_start' => $start],
            $values,
            $note,
            $changed,
        );
    }

    /**
     * @param array<string,?string> $dates
     * @param array<string,?string> $values
     * @return array<string,mixed>
     */
    private function plannedRow(
        ?int $id,
        ?int $version,
        array $dates,
        array $values,
        ?string $note,
        bool $touched,
    ): array {
        return [
            'id' => $id,
            'expected_version' => $version,
            'values' => $values,
            'note' => $note,
            'touched' => $touched,
        ] + $dates;
    }

    /**
     * Řada musí na sebe navazovat den po dni.
     *
     * Kontroluje se jen hranice, za kterou tenhle zápis odpovídá (aspoň jeden
     * z dvojice se mění). Starší díru, kterou uživatel nemůže zavřít (leží ve
     * zmrazeném období), by jinak nešlo obejít a stránka by se stala
     * nepoužitelnou právě pro data, kvůli kterým vznikla.
     *
     * @param list<array<string,mixed>> $plan
     */
    private function assertTimeline(string $key, array $plan): void
    {
        $rows = $plan;
        usort(
            $rows,
            static fn (array $left, array $right): int =>
                (string) $left['effective_from'] <=> (string) $right['effective_from'],
        );
        $previous = null;
        foreach ($rows as $row) {
            $from = (string) $row['effective_from'];
            $to = $row['effective_to'] === null ? null : (string) $row['effective_to'];
            if ($row['touched'] === true) {
                $this->assertMonthAligned($key, $from, $to);
            }
            if ($previous === null) {
                $previous = $row;
                continue;
            }
            if ($previous['touched'] !== true && $row['touched'] !== true) {
                $previous = $row;
                continue;
            }
            $previousTo = $previous['effective_to'] === null
                ? null
                : (string) $previous['effective_to'];
            if ($previousTo === null) {
                throw new InvalidArgumentException(sprintf(
                    'Evidence „%s“ má od %s otevřený záznam, na který nemůže'
                    . ' navazovat další — nejdřív ho ukonči.',
                    $key,
                    (string) $previous['effective_from'],
                ));
            }
            $expected = (new DateTimeImmutable($previousTo))
                ->modify('+1 day')
                ->format('Y-m-d');
            if ($from !== $expected) {
                throw new InvalidArgumentException(sprintf(
                    'Evidence „%s“ musí na sebe navazovat: po %s má následující'
                    . ' záznam začít %s, ne %s.',
                    $key,
                    $previousTo,
                    $expected,
                    $from,
                ));
            }
            $previous = $row;
        }
    }

    private function assertMonthAligned(string $key, string $from, ?string $to): void
    {
        if (substr($from, 8, 2) !== '01') {
            throw new InvalidArgumentException(sprintf(
                'Evidence „%s“ se zadává po celých měsících — začátek %s musí'
                . ' být první den měsíce.',
                $key,
                $from,
            ));
        }
        if ($to === null) {
            return;
        }
        $lastDay = (new DateTimeImmutable($to))
            ->modify('last day of this month')
            ->format('Y-m-d');
        if ($to !== $lastDay) {
            throw new InvalidArgumentException(sprintf(
                'Evidence „%s“ se zadává po celých měsících — konec %s musí být'
                . ' poslední den měsíce (%s).',
                $key,
                $to,
                $lastDay,
            ));
        }
    }

    /**
     * Plánovaný stav musí projít TÝMŽ validátorem jako čtecí cesta.
     *
     * Validátor kontroluje řádek vždy jen k jednomu datu, takže se volá ke
     * každému začátku účinnosti — každý plánovaný řádek tak projde svou
     * typovou kontrolou aspoň jednou. Kolekce, do kterých editor nepíše, jdou
     * do kontroly prázdné: jinak by rozbitý řádek jiné agendy (třeba nároku na
     * dítě) blokoval opravu prohlášení k dani. Výjimkou jsou základy u jiného
     * zaměstnavatele — na ty se měsíční evidence přímo odkazuje.
     *
     * @param array<string,list<array<string,mixed>>> $plans
     */
    private function assertPlannedEvidenceIsValid(
        int $supplierId,
        int $employeeId,
        array $plans,
        string $effectiveOn,
    ): void {
        $raw = [
            'health' => [
                'coverages' => [],
                'minimum_reductions' => [],
                'month_evidence' => [],
                'other_employer_bases' => $this->rows(
                    sprintf(
                        'SELECT %s FROM %s WHERE supplier_id = ? AND employee_id = ?',
                        self::COLLECTIONS['health']['other_employer_bases']['columns'],
                        self::COLLECTIONS['health']['other_employer_bases']['table'],
                    ),
                    [$supplierId, $employeeId],
                ),
            ],
            'income_tax' => [
                'declarations' => [],
                'residences' => [],
                'credit_claims' => [],
                'child_claims' => [],
            ],
            'social' => ['jurisdictions' => [], 'discount_claims' => []],
        ];

        $dates = [$effectiveOn => true];
        $planned = self::PLANNED_ID_BASE;
            foreach (self::EDITABLE as $key => $spec) {
            foreach ($plans[$key] as $row) {
                $candidate = $row['values'];
                if ($spec['kind'] === 'month') {
                    $candidate['period_start'] = $row['period_start'];
                    $dates[(string) $row['period_start']] = true;
                } else {
                    $candidate['effective_from'] = $row['effective_from'];
                    $candidate['effective_to'] = $row['effective_to'];
                    $dates[(string) $row['effective_from']] = true;
                }
                $candidate['id'] = $row['id'] ?? ++$planned;
                $candidate['row_version'] = $row['expected_version'] ?? 1;
                $raw[$spec['section']][$spec['collection']][] = $candidate;
            }
        }

        foreach (array_keys($dates) as $date) {
            $this->validator->normalize($employeeId, (string) $date, $raw);
        }
    }

    /**
     * DMS vazba není klientský údaj: klient smí vybrat jen ID aktivního
     * firemního dokumentu, jeho otisk se vždy přečte pod zámkem ze serveru.
     * Tím nejde připojit doklad cizí firmy ani podstrčit jiný hash.
     *
     * Staré textové reference zůstávají metadaty. Nový řádek, který textovou
     * referenci uvádí, ale musí mít i skutečný DMS důkaz.
     *
     * @param array<string,list<array<string,mixed>>> $plans
     */
    private function resolveHealthEvidenceDocuments(int $supplierId, int $employeeId, array &$plans): void
    {
        foreach ($plans['health_coverages'] as &$row) {
            $values = &$row['values'];
            $documentId = $this->positiveDocumentId(
                $values['health_evidence_document_id'] ?? null,
            );
            $existingDocument = $row['id'] === null
                ? null
                : $this->existingHealthEvidenceDocument($supplierId, $employeeId, (int) $row['id']);

            if ($existingDocument !== null && $documentId === null) {
                $values['health_evidence_document_id'] = (string) $existingDocument['id'];
                $values['health_evidence_document_sha256'] = $existingDocument['sha256'];
                continue;
            }
            if ($existingDocument !== null && $documentId !== $existingDocument['id']) {
                throw new InvalidArgumentException(
                    'DMS důkaz zdravotního pojištění je po připojení neměnný.',
                );
            }
            if ($documentId === null) {
                if ($row['id'] === null
                    && $values['insurer_evidence_reference'] !== null
                ) {
                    throw new InvalidArgumentException(
                        'Nový důkaz zdravotního pojištění musí obsahovat dokument z úložiště.',
                    );
                }
                $values['health_evidence_document_id'] = null;
                $values['health_evidence_document_sha256'] = null;
                continue;
            }

            $document = $this->db->pdo()->prepare(
                'SELECT id, sha256
                   FROM documents
                  WHERE supplier_id = ? AND id = ? AND deleted_at IS NULL
                  FOR UPDATE',
            );
            $document->execute([$supplierId, $documentId]);
            $reference = $document->fetch(PDO::FETCH_ASSOC);
            if ($reference === false || !is_string($reference['sha256'] ?? null)) {
                throw new InvalidArgumentException(
                    'Důkaz zdravotního pojištění musí být aktivní dokument této firmy.',
                );
            }
            $values['health_evidence_document_id'] = (string) $documentId;
            $values['health_evidence_document_sha256'] = $reference['sha256'];
        }
        unset($row, $values);
    }

    private function positiveDocumentId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($id)) {
            throw new InvalidArgumentException('ID důkazu zdravotního pojištění musí být kladné celé číslo.');
        }
        return $id;
    }

    /** @return array{id:int,sha256:string}|null */
    private function existingHealthEvidenceDocument(
        int $supplierId,
        int $employeeId,
        int $coverageId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT health_evidence_document_id, health_evidence_document_sha256
               FROM payroll_person_health_coverage_history
              WHERE supplier_id = ? AND employee_id = ? AND id = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $employeeId, $coverageId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false || $row['health_evidence_document_id'] === null
            || !is_string($row['health_evidence_document_sha256'] ?? null)
        ) {
            return null;
        }

        return [
            'id' => (int) $row['health_evidence_document_id'],
            'sha256' => $row['health_evidence_document_sha256'],
        ];
    }

    /**
     * @param array<string,list<array<string,mixed>>> $plans
     * @param array<string,list<int>> $deletions
     * @return array{inserted:int,updated:int,deleted:int}
     */
    private function executePlan(
        int $supplierId,
        int $employeeId,
        array $plans,
        array $deletions,
        ?int $userId,
    ): array {
        $counts = ['inserted' => 0, 'updated' => 0, 'deleted' => 0];
            foreach (self::EDITABLE as $key => $spec) {
            $table = self::COLLECTIONS[$spec['section']][$spec['collection']]['table'];
            $dateColumns = $spec['kind'] === 'month'
                ? ['period_start']
                : ['effective_from', 'effective_to'];

            // Nejdřív mazání: uvolní unikátní klíč (firma, osoba, začátek), na
            // který se může trefit řádek posunutý v témže uložení.
            foreach ($deletions[$key] as $id) {
                $delete = $this->db->pdo()->prepare(sprintf(
                    'DELETE FROM %s WHERE supplier_id = ? AND employee_id = ? AND id = ?',
                    $table,
                ));
                $delete->execute([$supplierId, $employeeId, $id]);
                $counts['deleted'] += $delete->rowCount();
            }

            foreach ($plans[$key] as $row) {
                $columns = [...$spec['fields'], ...$dateColumns, self::NOTE_COLUMN];
                $values = [];
                foreach ($spec['fields'] as $field) {
                    $values[] = $row['values'][$field];
                }
                foreach ($dateColumns as $column) {
                    $values[] = $row[$column];
                }
                $values[] = $row['note'];

                if ($row['id'] === null) {
                    $insert = $this->db->pdo()->prepare(sprintf(
                        'INSERT INTO %s (supplier_id, employee_id, %s, created_by, updated_by)
                         VALUES (?, ?, %s, ?, ?)',
                        $table,
                        implode(', ', $columns),
                        implode(', ', array_fill(0, count($columns), '?')),
                    ));
                    $insert->execute([$supplierId, $employeeId, ...$values, $userId, $userId]);
                    $counts['inserted']++;
                    continue;
                }
                if ($row['touched'] !== true) {
                    continue;
                }
                $update = $this->db->pdo()->prepare(sprintf(
                    'UPDATE %s SET %s, updated_by = ?, row_version = row_version + 1
                      WHERE supplier_id = ? AND employee_id = ? AND id = ?
                        AND row_version = ?',
                    $table,
                    implode(' = ?, ', $columns) . ' = ?',
                ));
                $update->execute([
                    ...$values,
                    $userId,
                    $supplierId,
                    $employeeId,
                    $row['id'],
                    $row['expected_version'],
                ]);
                if ($update->rowCount() !== 1) {
                    throw new PayrollPersonStatutoryEvidenceConflictException(
                        $key,
                        (int) $row['id'],
                        $this->currentVersion($table, $supplierId, $employeeId, (int) $row['id']),
                    );
                }
                $counts['updated']++;
            }
        }

        return $counts;
    }

    /**
     * Blokátory k datu snímku pojmenované STEJNĚ jako v assembleru mzdového
     * běhu — jinak by stránka tvrdila něco jiného než chyba u výpočtu.
     *
     * @return list<string>
     */
    private function blockers(int $supplierId, int $employeeId, string $effectiveOn): array
    {
        try {
            $snapshot = $this->snapshot($supplierId, $employeeId, $effectiveOn);
        } catch (InvalidArgumentException) {
            return ['statutory_evidence_snapshot_missing_or_mismatched'];
        }
        if ($snapshot === null) {
            return ['statutory_evidence_snapshot_missing_or_mismatched'];
        }

        $blockers = [];
        $declaration = $snapshot['income_tax']['declaration'] ?? null;
        if (!is_array($declaration)) {
            $blockers[] = 'tax_declaration_evidence_missing';
        } elseif (($declaration['status'] ?? null) === 'unverified') {
            $blockers[] = 'tax_declaration_evidence_unverified';
        }
        $residence = $snapshot['income_tax']['residence'] ?? null;
        if (!is_array($residence)) {
            $blockers[] = 'tax_residence_evidence_missing';
        } elseif (($residence['residence'] ?? null) === 'unverified') {
            $blockers[] = 'tax_residence_evidence_unverified';
        }
        $jurisdiction = $snapshot['social']['jurisdiction'] ?? null;
        if (!is_array($jurisdiction)) {
            $blockers[] = 'social_jurisdiction_evidence_missing';
        } elseif (($jurisdiction['jurisdiction'] ?? null) === 'unverified') {
            $blockers[] = 'social_jurisdiction_evidence_unverified';
        }
        $discount = $snapshot['social']['working_pensioner_discount'] ?? null;
        if (!is_array($discount)) {
            $blockers[] = 'working_pensioner_discount_evidence_missing';
        } elseif (($discount['status'] ?? null) === 'unverified') {
            $blockers[] = 'working_pensioner_discount_evidence_unverified';
        }
        $coverage = $snapshot['health']['coverage'] ?? null;
        if (!is_array($coverage)) {
            $blockers[] = 'health_coverage_evidence_missing';
        } else {
            if (($coverage['jurisdiction'] ?? null) === 'unverified') {
                $blockers[] = 'health_jurisdiction_evidence_unverified';
            }
            if (($coverage['insurer_status'] ?? null) === 'unverified') {
                $blockers[] = 'health_insurer_evidence_unverified';
            }
        }

        return $blockers;
    }

    /** @return list<array<string,mixed>> */
    private function editorRows(int $supplierId, int $employeeId, string $key): array
    {
        $spec = self::EDITABLE[$key];
        $collection = self::COLLECTIONS[$spec['section']][$spec['collection']];

        return $this->rows(
            sprintf(
                'SELECT %s, %s FROM %s WHERE supplier_id = ? AND employee_id = ?
                  ORDER BY %s',
                $collection['columns'],
                self::NOTE_COLUMN,
                $collection['table'],
                $collection['order'],
            ),
            [$supplierId, $employeeId],
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<array<string,mixed>>
     */
    private function inputRows(array $payload, string $key): array
    {
        $sections = $payload['sections'] ?? null;
        if (!is_array($sections)) {
            throw new InvalidArgumentException('Tělo požadavku musí mít objekt „sections“.');
        }
        $value = $sections[$key] ?? null;
        if ($value === null) {
            throw new InvalidArgumentException("Kolekce „{$key}“ v těle požadavku chybí.");
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException("Kolekce „{$key}“ musí být seznam.");
        }
        $rows = [];
        foreach ($value as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new InvalidArgumentException("Kolekce „{$key}“ obsahuje neplatný záznam.");
            }
            $normalized = [];
            foreach ($row as $field => $item) {
                if (is_string($field)) {
                    $normalized[$field] = $item;
                }
            }
            $rows[] = $normalized;
        }

        return $rows;
    }

    /**
     * @param list<string> $fields
     * @param array<string,mixed> $row
     * @return array<string,?string>
     */
    private function inputValues(string $key, array $fields, array $row, int $index): array
    {
        $values = [];
        foreach ($fields as $field) {
            if ($field === 'health_evidence_document_sha256') {
                // Otisk se nikdy nebere z HTTP; resolveHealthEvidenceDocuments()
                // ho odvodí z uzamčeného DMS řádku.
                $values[$field] = null;
                continue;
            }
            if ($field === 'health_evidence_document_id') {
                $values[$field] = $this->nullableText($row[$field] ?? null);
                continue;
            }
            $value = $row[$field] ?? null;
            if ($value !== null && !is_string($value)) {
                throw new InvalidArgumentException(sprintf(
                    'Pole „%s“ v %d. záznamu evidence „%s“ musí být text nebo null.',
                    $field,
                    $index + 1,
                    $key,
                ));
            }
            $values[$field] = $this->nullableText($value);
        }

        return $values;
    }

    /** @param array<string,mixed> $row */
    private function inputNote(string $key, array $row, int $index): ?string
    {
        $value = $row[self::NOTE_COLUMN] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new InvalidArgumentException(sprintf(
                'Poznámka v %d. záznamu evidence „%s“ musí být text.',
                $index + 1,
                $key,
            ));
        }
        $note = $this->nullableText($value);
        if ($note !== null && mb_strlen($note) > self::NOTE_MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'Poznámka v %d. záznamu evidence „%s“ je delší než %d znaků.',
                $index + 1,
                $key,
                self::NOTE_MAX_LENGTH,
            ));
        }

        return $note;
    }

    /** @param array<string,mixed> $row */
    private function inputId(string $key, array $row, int $index): ?int
    {
        $value = $row['id'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($id)) {
            throw new InvalidArgumentException(sprintf(
                'ID v %d. záznamu evidence „%s“ musí být kladné celé číslo.',
                $index + 1,
                $key,
            ));
        }

        return $id;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $existing
     */
    private function assertVersion(string $key, int $id, array $row, array $existing): void
    {
        $version = filter_var(
            $row['row_version'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        if (!is_int($version)) {
            throw new InvalidArgumentException(sprintf(
                'Záznam %d v evidenci „%s“ musí nést row_version.',
                $id,
                $key,
            ));
        }
        if ($version !== (int) $existing['row_version']) {
            throw new PayrollPersonStatutoryEvidenceConflictException(
                $key,
                $id,
                (int) $existing['row_version'],
            );
        }
    }

    /** @param array<string,mixed> $row */
    private function requiredDate(string $key, array $row, string $field): string
    {
        $value = $this->nullableText($row[$field] ?? null);
        if ($value === null || !$this->isDate($value)) {
            throw new InvalidArgumentException(sprintf(
                'Pole „%s“ v evidenci „%s“ musí být datum ve tvaru RRRR-MM-DD.',
                $field,
                $key,
            ));
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function optionalDate(string $key, array $row, string $field): ?string
    {
        $value = $this->nullableText($row[$field] ?? null);
        if ($value === null) {
            return null;
        }
        if (!$this->isDate($value)) {
            throw new InvalidArgumentException(sprintf(
                'Pole „%s“ v evidenci „%s“ musí být datum ve tvaru RRRR-MM-DD.',
                $field,
                $key,
            ));
        }

        return $value;
    }

    private function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim(is_string($value) ? $value : (string) $value);

        return $text === '' ? null : $text;
    }

    private function currentVersion(
        string $table,
        int $supplierId,
        int $employeeId,
        int $id,
    ): int {
        $statement = $this->db->pdo()->prepare(sprintf(
            'SELECT row_version FROM %s WHERE supplier_id = ? AND employee_id = ? AND id = ?',
            $table,
        ));
        $statement->execute([$supplierId, $employeeId, $id]);

        return (int) $statement->fetchColumn();
    }

    private function employeeExists(int $supplierId, int $employeeId): bool
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT 1 FROM payroll_employees WHERE supplier_id = ? AND id = ?'
        );
        $statement->execute([$supplierId, $employeeId]);

        return $statement->fetchColumn() !== false;
    }

    private function lockEmployee(int $supplierId, int $employeeId): void
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_employees
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE'
        );
        $statement->execute([$supplierId, $employeeId]);
        if ($statement->fetchColumn() === false) {
            throw new PayrollPersonNotFoundException();
        }
    }

    /**
     * @param list<int> $employeeIds
     * @return list<int> podmnožina patřící firmě, v pořadí vstupu
     */
    private function existingEmployeeIds(int $supplierId, array $employeeIds): array
    {
        $unique = array_values(array_unique($employeeIds));
        if ($unique === []) {
            return [];
        }
        $found = [];
        foreach (array_chunk($unique, self::CHUNK_SIZE) as $chunk) {
            $stmt = $this->db->pdo()->prepare(sprintf(
                'SELECT id FROM payroll_employees
                  WHERE supplier_id = ? AND id IN (%s)',
                implode(', ', array_fill(0, count($chunk), '?')),
            ));
            $stmt->execute([$supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $found[(int) $row['id']] = true;
            }
        }

        return array_values(array_filter(
            $unique,
            static fn (int $id): bool => isset($found[$id]),
        ));
    }

    /**
     * @param array{table:string, columns:string, order:string} $collection
     * @param list<int> $employeeIds
     * @return array<int,list<array<string,mixed>>>
     */
    private function groupedRows(
        array $collection,
        int $supplierId,
        array $employeeIds,
    ): array {
        $grouped = [];
        foreach (array_chunk($employeeIds, self::CHUNK_SIZE) as $chunk) {
            $rows = $this->rows(
                sprintf(
                    'SELECT %s, employee_id AS %s FROM %s
                      WHERE supplier_id = ? AND employee_id IN (%s)
                      ORDER BY %s',
                    $collection['columns'],
                    self::GROUP_KEY,
                    $collection['table'],
                    implode(', ', array_fill(0, count($chunk), '?')),
                    $collection['order'],
                ),
                [$supplierId, ...$chunk],
            );
            foreach ($rows as $row) {
                $key = (int) $row[self::GROUP_KEY];
                unset($row[self::GROUP_KEY]);
                $grouped[$key][] = $row;
            }
        }

        return $grouped;
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fetched) {
            if (!is_array($fetched)) {
                throw new UnexpectedValueException(
                    'Databáze vrátila neplatný řádek zákonné evidence osoby.',
                );
            }
            $row = [];
            foreach ($fetched as $key => $value) {
                if (!is_string($key)
                    || (!is_string($value) && !is_int($value)
                        && !is_bool($value) && $value !== null)
                ) {
                    throw new UnexpectedValueException(
                        'Databáze vrátila neplatnou hodnotu zákonné evidence osoby.',
                    );
                }
                $row[$key] = $value;
            }
            $result[] = $row;
        }

        return $result;
    }
}
