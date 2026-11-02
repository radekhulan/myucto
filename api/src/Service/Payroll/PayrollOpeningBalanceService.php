<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Service\Payroll\Import\OpeningBalance\OpeningBalanceMonthValidator;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverCoverage;

/**
 * Počáteční stavy mzdových kumulací.
 *
 * Zaměstnanec, který nastoupil dřív, než firma začala vést mzdy v MyÚčtu, nemá
 * za uzavřené měsíce žádnou revizi. Bez počátečního stavu vypadne z dávky
 * zákonného výpočtu, celý běh spadne do `manual_review` a přebít se to nedá —
 * override pracuje nad řádky validací, kdežto tohle je issue statutory bundlu.
 *
 * Uživatel zadává ÚHRNY PO MĚSÍCÍCH, protože tak je má v sestavě z předchozího
 * programu. Kumulace je ale roční součet, takže se tady sečtou; rozpis měsíců
 * jde do `evidence`, aby z čeho součet vznikl zůstalo dohledatelné.
 *
 * @phpstan-type OpeningMonth array{
 *   month:int,
 *   social_assessment_base_minor_units:int,
 *   health_assessment_base_minor_units:int,
 *   health_employee_contribution_minor_units:int,
 *   health_employer_contribution_minor_units:int,
 *   health_minimum_top_up_minor_units:int,
 *   advance_base_minor_units:int,
 *   advance_tax_minor_units:int,
 *   withholding_base_minor_units:int,
 *   withholding_tax_minor_units:int,
 *   applied_non_refundable_credits_minor_units:int,
 *   applied_child_credit_minor_units:int,
 *   tax_bonus_minor_units:int,
 *   bonus_qualifying_income_minor_units:int
 * }
 */
final readonly class PayrollOpeningBalanceService
{
    private const SAVEPOINT = 'payroll_opening_balance';

    /**
     * Druhy kumulace, které počáteční stav plní. Musí odpovídat
     * `PayrollStatutoryAccumulatorRepository::VALUE_FIELDS`; zapsat se dá jen
     * druh, pro který repozitář zná sadu polí.
     */
    public const KINDS = ['social_insurance', 'health_insurance', 'income_tax'];

    /**
     * Prefix sloupce měsíčního rozpisu podle druhu kumulace.
     *
     * Vyměřovací základ sociálního i zdravotního pojištění se v kumulaci jmenuje
     * stejně (`assessment_base_minor_units`), takže by se v jednom řádku rozpisu
     * překryly — prefix je rozliší. Daňová pole prefix nepotřebují, jejich názvy
     * jsou jedinečné a takhle je zná i hlášení JMHZ.
     */
    private const MONTH_COLUMN_PREFIX = [
        'social_insurance' => 'social_',
        'health_insurance' => 'health_',
        'income_tax' => '',
    ];

    /**
     * Sloupce rozpisu, které podané hlášení z počátečního stavu nebere.
     *
     * Zdravotní pojistné se v hlášení vykazuje za měsíc z jeho výpočtu a do
     * výpočtu pozdějších měsíců kumulace nevstupuje. Doplnit ho tedy jde i po
     * podání; základ sociálního pojištění a daňové úhrny ne, ty podané hlášení
     * ovlivnily (roční strop, daňová kumulace).
     */
    private const COLUMNS_OUTSIDE_FILED_REPORTS = [
        'health_assessment_base_minor_units',
        'health_employee_contribution_minor_units',
        'health_employer_contribution_minor_units',
        'health_minimum_top_up_minor_units',
    ];

    /**
     * Pole kumulace, která se NEZADÁVAJÍ — plynou z rozpisu samotného.
     * Počet uzavřených měsíců je počet řádků, ne částka.
     *
     * @var array<string,list<string>>
     */
    private const DERIVED_FIELDS = ['income_tax' => ['completed_months']];

    public function __construct(
        private PayrollStatutoryAccumulatorRepository $accumulators,
        private Connection $db,
        private PayrollTakeoverCoverage $coverage,
    ) {}

    /**
     * Co je za daný rok uložené. Vrací i `id` aktuální verze — oprava se na něj
     * musí explicitně navázat, jinak ji repozitář odmítne jako duplicitu.
     *
     * @return array{year:int,months:list<OpeningMonth>,openings:array<string,?int>,
     *   source_reference:string,locked:bool,lock_reason:?string,approved_periods:list<string>}
     */
    public function current(int $supplierId, int $employeeId, int $year): array
    {
        $openings = [];
        $months = [];
        $sourceReference = '';
        foreach (self::KINDS as $kind) {
            $opening = $this->accumulators->openingBalance($supplierId, $employeeId, $year, $kind);
            $openings[$kind] = $opening === null ? null : (int) $opening['id'];
            // Rozpis měsíců je v evidence u všech druhů stejný; stačí ten první nalezený.
            if ($months === [] && $opening !== null && is_array($opening['evidence']['months'] ?? null)) {
                $months = $opening['evidence']['months'];
                $sourceReference = (string) $opening['source_reference'];
            }
        }

        $lockReason = $this->lockReason($supplierId, $employeeId, $year);

        return [
            'year' => $year,
            'months' => array_values($months),
            'openings' => $openings,
            'source_reference' => $sourceReference,
            'locked' => $lockReason !== null,
            'lock_reason' => $lockReason,
            // Měsíce, které se počítaly nad tímhle stavem. Nejsou zámek, ale
            // oprava je sama nepřepočítá — uživatel to musí vidět dřív, než uloží.
            'approved_periods' => $this->accumulators->approvedPeriods($supplierId, $employeeId, $year),
        ];
    }

    /**
     * Proč počáteční stav roku už nejde měnit, nebo `null`.
     *
     * JEDINÉ místo, kde se zámek formuluje — mřížka, tabulkový import i import
     * hlášení JMHZ se ptají tady. Zámek se váže na PODANÉ hlášení a vydané roční
     * doklady, ne na schválený mzdový běh: u firmy, která přešla na MyÚčto
     * v průběhu roku, se převzatá čísla dolaďují a zámek na prvním schváleném
     * běhu znamenal, že chybu nalezenou v listopadu už nešlo opravit vůbec.
     * Co ale jednou odešlo ven, se zpětně přepsat nesmí.
     *
     * {@see PayrollStatutoryAccumulatorRepository::openingLock()}
     */
    public function lockReason(int $supplierId, int $employeeId, int $year): ?string
    {
        $lock = $this->accumulators->openingLock($supplierId, $employeeId, $year);
        if ($lock === null) {
            return null;
        }

        return match ($lock['kind']) {
            'submission' => sprintf(
                'Za období %s je podané hlášení, které z těchhle úhrnů vyšlo. Podaná čísla se zpětně '
                    . 'nepřepisují — opravu proveďte opravným hlášením za dotčené období.',
                $lock['reference'],
            ),
            'annual_document' => sprintf(
                'Za rok %d je vydaný roční doklad zaměstnance (%s). Ten je neměnným otiskem celého roku '
                    . 'včetně převzatých měsíců, takže počáteční stavy už měnit nelze.',
                $year,
                $lock['reference'],
            ),
            'year_closed' => sprintf('Mzdový rok %d je uzavřený.', $year),
            default => throw new \LogicException('Neznámý důvod zámku počátečních stavů.'),
        };
    }

    /**
     * Zamyká počáteční stav tahle KONKRÉTNÍ změna? `null` = smí se uložit.
     *
     * Podané hlášení zamyká jen to, co z počátečního stavu převzalo. Vydaný roční
     * doklad a uzavřený rok jsou otiskem celého roku a zamykají všechno.
     *
     * @param list<array<string,mixed>> $months normalizovaný nový rozpis
     */
    public function lockReasonForChange(int $supplierId, int $employeeId, int $year, array $months): ?string
    {
        $lock = $this->accumulators->openingLock($supplierId, $employeeId, $year);
        if ($lock === null) {
            return null;
        }
        if ($lock['kind'] === 'submission') {
            $stored = $this->current($supplierId, $employeeId, $year)['months'];
            if (self::reportedColumns($stored) == self::reportedColumns($months)) {
                return null;
            }
        }

        return $this->lockReason($supplierId, $employeeId, $year);
    }

    /** Lze u zamčeného roku měnit aspoň údaje mimo podaná hlášení? */
    public function lockAllowsUnreportedChanges(int $supplierId, int $employeeId, int $year): bool
    {
        return ($this->accumulators->openingLock($supplierId, $employeeId, $year)['kind'] ?? null) === 'submission';
    }

    /**
     * Rozpis bez sloupců mimo podaná hlášení, nezadaný sloupec je nula.
     *
     * @param list<array<string,mixed>> $months
     * @return array<int,array<string,int>>
     */
    public static function reportedColumns(array $months): array
    {
        $columns = [];
        foreach (self::monthColumns() as $map) {
            foreach (array_keys($map) as $column) {
                if (!in_array($column, self::COLUMNS_OUTSIDE_FILED_REPORTS, true)) {
                    $columns[] = $column;
                }
            }
        }
        $rows = [];
        foreach ($months as $month) {
            $row = [];
            foreach ($columns as $column) {
                $row[$column] = (int) ($month[$column] ?? 0);
            }
            $rows[(int) ($month['month'] ?? 0)] = $row;
        }
        ksort($rows, SORT_NUMERIC);

        return $rows;
    }

    /**
     * Doplní NULOVÉ počáteční stavy tam, kde je nula prokazatelná.
     *
     * Počáteční stav je „co se spočítalo před MyÚčtem". Když firma vede mzdy
     * v aplikaci od dřívějšího měsíce, než ve kterém zaměstnanec v daném roce
     * poprvé nastoupil, tak před tímhle obdobím žádné cizí zpracování NENÍ a
     * nula je jediná možná hodnota — přesto to dřív po účetní chtěla aplikace
     * vyplnit ručně, u každého člověka zvlášť, a do té doby odmítala schválit
     * mzdový běh. Nejhůř to bilo v lednu, kdy je roční kumulace nulová vždycky
     * a přesto blokovala celou firmu.
     *
     * ⚠️ Odvozuje se jen prokazatelná nula. Zaměstnanec, který v roce pracoval
     * dřív, než firma začala vést mzdy v MyÚčtu, tudy NEPROJDE — jeho úhrny
     * aplikace nezná a hádat je nesmí (zkreslily by roční maximum sociálního
     * pojištění i daňový bonus). Ten zůstává na ručním zadání.
     *
     * @param list<int> $employeeIds prázdné = všichni zaměstnanci firmy
     * @return list<int> id zaměstnanců, kterým se nulový počátek doplnil
     */
    public function seedProvableZeroOpenings(
        int $supplierId,
        string $periodStart,
        array $employeeIds = [],
        ?int $actorUserId = null,
    ): array {
        if ($supplierId <= 0
            || preg_match('/^\d{4}-\d{2}-01$/D', $periodStart) !== 1) {
            return [];
        }
        $year = (int) substr($periodStart, 0, 4);
        $yearStart = sprintf('%04d-01-01', $year);

        $moduleStart = $this->moduleStartPeriod($supplierId);
        if ($moduleStart === null) {
            return [];
        }
        $firstRun = $this->firstRunPeriod($supplierId, $year);

        $seeded = [];
        foreach ($this->firstEmploymentStarts($supplierId, $employeeIds) as $employeeId => $firstStart) {
            // Okno „měsíce roku před obdobím, ve kterých u téhle firmy mohl mít
            // příjem" začíná pozdějším z: začátek roku a nástup.
            $windowStart = $firstStart !== null && $firstStart > $yearStart
                ? $firstStart
                : $yearStart;
            $windowIsEmpty = $windowStart >= $periodStart;
            // Kus roku firma zpracovala mimo aplikaci — nulu tvrdit nelze. Nestačí
            // začátek vedení mezd: firma ho mívá nastavený dřív, než v MyÚčtu
            // opravdu začala, a leden až květen pak doložil předchozí program.
            // Nula je prokazatelná, jen když MyÚčto za rok vedlo mzdy už od
            // začátku okna (má běh nejpozději v jeho prvním měsíci).
            if (!$windowIsEmpty
                && ($moduleStart > $windowStart || $firstRun === null || $firstRun > substr($windowStart, 0, 8) . '01')) {
                continue;
            }
            if ($this->hasAnyOpening($supplierId, $employeeId, $year)) {
                continue;
            }
            try {
                $this->save(
                    $supplierId,
                    $employeeId,
                    $year,
                    [],
                    'Automaticky: mzdy za předchozí měsíce roku vede MyÚčto, není co převádět.',
                    $actorUserId,
                );
                $seeded[] = $employeeId;
            } catch (\Throwable) {
                // Doplnění je pohodlí, ne podmínka. Když nevyjde (schválená
                // mzda za rok, souběžný zápis), zůstane ruční cesta.
                continue;
            }
        }

        return $seeded;
    }

    private function firstRunPeriod(int $supplierId, int $year): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT MIN(period_start) FROM payroll_runs
              WHERE supplier_id = ? AND status <> 'cancelled' AND period_start BETWEEN ? AND ?"
        );
        $stmt->execute([$supplierId, sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)]);
        $value = $stmt->fetchColumn();

        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }

    private function moduleStartPeriod(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT start_period FROM payroll_module_state WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $value = $stmt->fetchColumn();

        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }

    /**
     * @param list<int> $employeeIds
     * @return array<int,?string>
     */
    private function firstEmploymentStarts(int $supplierId, array $employeeIds): array
    {
        $sql = 'SELECT employee_id, MIN(COALESCE(actual_start_date, start_date)) AS first_start
                  FROM payroll_employments
                 WHERE supplier_id = ?
                   AND status <> \'no_show\'';
        $params = [$supplierId];
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $employeeIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($ids !== []) {
            $sql .= ' AND employee_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')';
            $params = [...$params, ...$ids];
        }
        $sql .= ' GROUP BY employee_id';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        $result = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $start = $row['first_start'] ?? null;
            $result[(int) $row['employee_id']] = is_string($start) ? substr($start, 0, 10) : null;
        }

        return $result;
    }

    private function hasAnyOpening(int $supplierId, int $employeeId, int $year): bool
    {
        foreach (self::KINDS as $kind) {
            if ($this->accumulators->openingBalance($supplierId, $employeeId, $year, $kind) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Uloží počáteční stavy. Opakované uložení TÝCHŽ čísel je replay (repozitář
     * vrátí původní řádek), změna čísel je oprava navázaná na aktuální verzi.
     *
     * `$requireExplicitMonths` zapínají cesty, kde čísla zadává člověk (mřížka,
     * tabulkový import): každý převzatý měsíc, ve kterém trval vztah, musí být
     * vyplněný nebo výslovně potvrzený jako nulový ({@see explicitMonthsReason()}).
     *
     * @param list<OpeningMonth> $months
     * @return array{year:int,months:list<OpeningMonth>,openings:array<string,?int>,
     *   source_reference:string,locked:bool,lock_reason:?string,approved_periods:list<string>}
     */
    public function save(
        int $supplierId,
        int $employeeId,
        int $year,
        array $months,
        string $sourceReference,
        ?int $actorUserId,
        bool $requireExplicitMonths = false,
    ): array {
        $sourceReference = trim($sourceReference);
        $entered = $months;
        $months = self::normalizedMonths($months);
        $lockReason = $this->lockReasonForChange($supplierId, $employeeId, $year, array_values($months));
        if ($lockReason !== null) {
            throw new \DomainException($lockReason);
        }
        if ($requireExplicitMonths) {
            $reason = $this->explicitMonthsReason($supplierId, $employeeId, $year, $entered);
            if ($reason !== null) {
                throw new \InvalidArgumentException($reason);
            }
        }

        $evidence = ['months' => array_values($months)];
        $values = self::accumulatorValues($months);
        $this->transactional(function () use (
            $supplierId,
            $employeeId,
            $year,
            $values,
            $sourceReference,
            $evidence,
            $actorUserId,
        ): void {
            foreach (self::KINDS as $kind) {
                $previous = $this->accumulators->openingBalance(
                    $supplierId,
                    $employeeId,
                    $year,
                    $kind,
                );
                /*
                 * Beze změny se nic nezapisuje.
                 *
                 * Tabulka je append-only, takže druhé uložení týchž čísel by jinak
                 * založilo verzi, která nic neopravuje — a v historii by po pár
                 * kliknutích stál řetěz shodných záznamů. Idempotence repozitáře
                 * to nepokryje: klíč se počítá z dat, ale `record_hash` nese
                 * i předchůdce, který se mezitím změnil z `null` na id první verze.
                 */
                if ($previous !== null
                    && $previous['values'] == $values[$kind]
                    && $previous['evidence'] == $evidence
                    && (string) $previous['source_reference'] === $sourceReference
                ) {
                    continue;
                }
                $this->accumulators->appendOpeningBalance(
                    $supplierId,
                    $employeeId,
                    $year,
                    $kind,
                    $values[$kind],
                    $sourceReference,
                    $evidence,
                    $this->idempotencyKey(
                        $employeeId,
                        $year,
                        $kind,
                        $values[$kind],
                        $evidence,
                        $sourceReference,
                        $previous['id'] ?? null,
                    ),
                    $previous['id'] ?? null,
                    $actorUserId,
                );
            }
        });

        return $this->current($supplierId, $employeeId, $year);
    }

    /**
     * Roční kumulace za všechny druhy, složené z měsíčního rozpisu.
     *
     * Jediné místo, kde se z měsíců počítají hodnoty kumulací — a schválně
     * veřejné a statické, aby šlo zavolat. Předání měsíce mzdovému modulu
     * ({@see PayrollLegacyRecapitulationService::shrinkOpeningBalance()})
     * skládá tytéž hodnoty ze zbylých měsíců; kdyby si je počítalo samo,
     * rozešel by se s ním každý nový druh kumulace i každé nové pole.
     *
     * Chybějící sloupec je nula: rozpis uložený dřív, než druh kumulace
     * existoval, se musí dát přepočítat i po jeho doplnění.
     *
     * @param list<array<string,mixed>> $months
     * @return array<string,array<string,int>>
     */
    public static function accumulatorValues(array $months): array
    {
        $columns = self::monthColumns();
        $values = [];
        foreach ($columns as $kind => $map) {
            $values[$kind] = array_fill_keys(array_values($map), 0);
            foreach (self::DERIVED_FIELDS[$kind] ?? [] as $field) {
                // Jediné odvozené pole: kolik měsíců rozpis pokrývá.
                $values[$kind][$field] = count($months);
            }
        }
        foreach ($months as $month) {
            foreach ($columns as $kind => $map) {
                foreach ($map as $column => $field) {
                    $values[$kind][$field] += (int) ($month[$column] ?? 0);
                }
            }
        }

        return $values;
    }

    /**
     * Sloupce měsíčního rozpisu podle druhu kumulace.
     *
     * Odvozuje se z {@see PayrollStatutoryAccumulatorRepository::VALUE_FIELDS},
     * ne z vlastního seznamu: nový druh kumulace i nové pole se tím objeví ve
     * všech cestách naráz (mřížka, tabulkový import, import hlášení JMHZ).
     * Vlastní seznam by znamenal, že se do počátečních stavů nové pole prostě
     * nedostane a roční úhrn bude tiše chybět.
     *
     * @return array<string,array<string,string>> druh => [sloupec rozpisu => pole kumulace]
     */
    public static function monthColumns(): array
    {
        $columns = [];
        foreach (self::KINDS as $kind) {
            $prefix = self::MONTH_COLUMN_PREFIX[$kind]
                ?? throw new \LogicException("Druh kumulace {$kind} nemá prefix sloupce rozpisu.");
            $map = [];
            foreach (PayrollStatutoryAccumulatorRepository::VALUE_FIELDS[$kind] ?? [] as $field) {
                if (in_array($field, self::DERIVED_FIELDS[$kind] ?? [], true)) {
                    continue;
                }
                $map[$prefix . $field] = $field;
            }
            $columns[$kind] = $map;
        }

        return $columns;
    }

    /** @return list<string> sloupce rozpisu napříč druhy, v pořadí kumulací */
    public static function monthFields(): array
    {
        $fields = [];
        foreach (self::monthColumns() as $map) {
            foreach (array_keys($map) as $column) {
                $fields[] = $column;
            }
        }

        return $fields;
    }

    /**
     * Rozpis seřazený, ověřený a připravený k zápisu.
     *
     * Počet dokončených měsíců smí vzniknout jen ze souvislého intervalu.
     * První měsíc je záměrně explicitní: převzatý zaměstnanec, který nastoupil
     * až v březnu, má před srpnovou aktivací pět měsíců (3–7), ne sedm.
     *
     * Veřejné a statické, aby se náhled importu mohl zeptat přesně na to, co
     * udělá zápis — náhled, který se ptá jinak, slibuje něco, co apply odmítne.
     *
     * @param list<array<string,mixed>> $months
     * @return list<array<string,mixed>>
     */
    public static function normalizedMonths(array $months): array
    {
        $byMonth = [];
        foreach ($months as $row) {
            $month = $row['month'] ?? null;
            if (!is_int($month) || $month < 1 || $month > 12) {
                throw new \InvalidArgumentException(
                    'Měsíc počátečního stavu musí být číslo 1 až 12.',
                );
            }
            if (isset($byMonth[$month])) {
                throw new \InvalidArgumentException(
                    "Měsíc {$month} je v počátečních stavech dvakrát.",
                );
            }
            OpeningBalanceMonthValidator::assertValid($row);
            // Potvrzení nulového měsíce je tvrzení při zadání, ne údaj
            // kumulace — uložený nulový řádek už JE doložená nula.
            unset($row[OpeningBalanceMonthValidator::CONFIRMED_ZERO]);
            $byMonth[$month] = $row;
        }
        if ($byMonth === []) {
            return [];
        }

        ksort($byMonth, SORT_NUMERIC);
        $numbers = array_keys($byMonth);
        $first = $numbers[0];
        $last = $numbers[count($numbers) - 1];
        if (count($numbers) !== $last - $first + 1) {
            throw new \InvalidArgumentException(
                'Měsíce počátečního stavu musí tvořit souvislou řadu.',
            );
        }
        /*
         * Prázdný rozpis je záměrný a auditovatelný nulový počátek nového
         * zaměstnance. Nesmíme z ledna až měsíce nástupu vyrábět fiktivně
         * „dokončené" měsíce, protože by zkreslily roční daňovou kumulaci.
         *
         * Kumulace nese `completed_months` a repozitář ho u openingu omezuje
         * na 11 — dvanáctý měsíc už není „před obdobím", ale celý rok.
         */
        if (count($byMonth) > 11) {
            throw new \InvalidArgumentException(
                'Počáteční stavy pokrývají měsíce PŘED prvním zpracovaným obdobím, tedy nejvýš jedenáct.',
            );
        }

        return array_values($byMonth);
    }

    /**
     * Důvod, proč by uložení neprošlo — tytéž kontroly jako {@see save()},
     * ale bez zápisu. Náhled tabulkového importu se tím ptá stejnou branou,
     * kterou pak projde (nebo neprojde) samotný zápis.
     *
     * @param list<array<string,mixed>> $months
     */
    public function rejectReason(
        int $supplierId,
        int $employeeId,
        int $year,
        array $months,
        bool $requireExplicitMonths = false,
    ): ?string {
        try {
            self::normalizedMonths($months);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return $this->lockReason($supplierId, $employeeId, $year)
            ?? ($requireExplicitMonths
                ? $this->explicitMonthsReason($supplierId, $employeeId, $year, $months)
                : null);
    }

    /**
     * Prázdné není nula: každý převzatý měsíc, ve kterém zaměstnanci trval
     * pracovní vztah, musí být v rozpisu — vyplněný, nebo výslovně potvrzený
     * jako nulový.
     *
     * Dřív mřížka i tabulka převedly nevyplněné na nulu a chybějící měsíc
     * prostě nechaly chybět. Obojí prošlo bez varování a roční zúčtování,
     * vyúčtování daně i roční maximum pojistného pak pracovaly s „doloženým"
     * rokem, ve kterém nikdo nic nedoložil. Které měsíce se čekají, říká
     * {@see PayrollTakeoverCoverage} — tentýž výklad používá vyúčtování daně
     * a uzávěrka mzdového roku.
     *
     * @param list<array<string,mixed>> $months
     */
    public function explicitMonthsReason(int $supplierId, int $employeeId, int $year, array $months): ?string
    {
        $present = [];
        foreach ($months as $row) {
            $reason = OpeningBalanceMonthValidator::rejectUnconfirmedZero($row);
            if ($reason !== null) {
                return $reason;
            }
            if (is_int($row['month'] ?? null)) {
                $present[$row['month']] = true;
            }
        }
        $missing = array_values(array_filter(
            $this->coverage->expectedMonthsForEmployee($supplierId, $employeeId, $year),
            static fn (int $month): bool => !isset($present[$month]),
        ));
        if ($missing === []) {
            return null;
        }

        return sprintf(
            'Zaměstnanci trval pracovní vztah i v měsících %s, které vedl předchozí program, ale '
                . 'v počátečních stavech chybí. Doplňte je, nebo je zadejte jako potvrzenou nulu '
                . '(měsíc bez příjmu, třeba neplacené volno).',
            PayrollTakeoverCoverage::monthRanges($missing),
        );
    }

    /** @param callable():void $callback */
    private function transactional(callable $callback): void
    {
        $pdo = $this->db->pdo();
        $nested = $pdo->inTransaction();
        if ($nested) {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        } else {
            $pdo->beginTransaction();
        }
        try {
            $callback();
            if ($nested) {
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            } else {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ($nested) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            } elseif ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Klíč se odvozuje ze VŠEHO, co tvoří otisk záznamu — hodnot, rozpisu, zdroje
     * i předchůdce. Zopakovaný požadavek (uživatel klikl dvakrát, spadlo spojení)
     * tak dostane tentýž klíč a repozitář vrátí původní řádek místo nové verze.
     *
     * Předchůdce v klíči být MUSÍ: `record_hash` ho nese, takže bez něj by druhý
     * pokus o tutéž opravu narazil na „klíč už používá jiný opening balance".
     * Časové razítko by naopak z každého kliknutí udělalo novou verzi.
     *
     * @param array<string,int> $values
     * @param array<string,mixed> $evidence
     */
    private function idempotencyKey(
        int $employeeId,
        int $year,
        string $kind,
        array $values,
        array $evidence,
        string $sourceReference,
        ?int $replacesOpeningId,
    ): string {
        ksort($values);

        return sprintf(
            'payroll-opening:%d:%d:%s:%s',
            $employeeId,
            $year,
            $kind,
            hash('sha256', json_encode([
                'values' => $values,
                'evidence' => $evidence,
                'source_reference' => $sourceReference,
                'replaces_opening_id' => $replacesOpeningId,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
        );
    }
}
