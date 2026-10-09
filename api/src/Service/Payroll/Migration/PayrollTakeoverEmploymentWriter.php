<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAverageEarningRepository;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentConflictException;
use MyInvoice\Repository\Payroll\PayrollEmploymentNotFoundException;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Repository\Payroll\PayrollRecurringComponentRepository;
use MyInvoice\Repository\Payroll\PayrollRegistrationIdentityRepository;
use MyInvoice\Repository\Payroll\PayrollTermsSettledException;
use MyInvoice\Service\Payroll\Absence\AverageEarningResult;
use MyInvoice\Service\Payroll\Component\PayrollRecurringComponentValidator;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportWriter;
use MyInvoice\Service\Payroll\PayrollEmploymentValidator;
use MyInvoice\Service\Payroll\PayrollPersonCreateValidator;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentityService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationRelationshipDetailPolicy;

/**
 * Zápis převzatého PRACOVNÍHO VZTAHU ({@see PayrollTakeoverEmployment}): sjednaná mzda
 * a její předpis, průměrný výdělek, pracoviště JMHZ a CZ-ISCO, OIČ a ID PPV, skončení
 * vztahu a Zákonné termíny. Společné pro všechny převody mezd z předchozího systému.
 *
 * Jako {@see PayrollTakeoverPersonWriter}: každý krok jde cestou karty vztahu
 * (validátor, zámek verze), doplňuje jen chybějící a savepoint si drží volající.
 */
final class PayrollTakeoverEmploymentWriter
{
    private const ENVIRONMENT = 'production';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollEmploymentRepository $employments,
        private readonly PayrollEmploymentValidator $employmentValidator,
        private readonly PayrollRegistrationIdentityService $identities,
        private readonly PayrollRegistrationIdentityRepository $registrations,
        private readonly PayrollAverageEarningRepository $averages,
        private readonly PayrollRulesetProvider $rulesets,
        private readonly PayrollRecurringComponentRepository $recurring,
        private readonly PayrollRecurringComponentValidator $recurringValidator,
        private readonly PayrollComponentRepository $components,
        private readonly PayrollPersonCreateValidator $personValidator,
    ) {}

    /**
     * Další pracovní vztah osoby, která už ve firmě je (souběh nebo opakovaný nástup ve
     * zdroji). Vztah dostane osobní číslo ze zdroje, pokud je volné a platné; jinak číslo
     * přidělí aplikace. Hlavním je jen tehdy, když osoba žádný trvající hlavní vztah nemá.
     *
     * Stejná cesta jako karta osoby (validátor zakládání, repozitář vztahů); vztah vzniká
     * jako plánovaný, aktivuje ho {@see self::activateTakenOver()}.
     */
    public function addEmployment(
        int $supplierId,
        int $employeeId,
        string $fullName,
        string $code,
        string $relationType,
        string $start,
        ?int $monthlyGross,
        ?string $weeklyHours,
        ?int $userId,
    ): int {
        $validated = $this->personValidator->validate([
            'full_name' => $fullName,
            'relation_type' => $relationType,
            'planned_start_on' => $start,
            'monthly_gross' => $monthlyGross,
            'weekly_hours' => $weeklyHours,
            'employment_code' => $this->employmentCodeAvailable($supplierId, $code) ? $code : null,
        ]);
        $employment = $validated['employment'];
        $employment['terms']['is_primary'] = !$this->hasActivePrimary($supplierId, $employeeId);
        $employment['code'] = $validated['employment_code'] ?? '';
        $created = $this->employments->create($supplierId, $employeeId, $employment, $userId, null, null);

        return (int) $created['id'];
    }

    /**
     * Plánovaný vztah, který ve zdroji už běžel (nástup nejpozději dnes), se aktivuje
     * k nástupu ze zdroje.
     *
     * @return array<string,int>
     */
    public function activateTakenOver(int $supplierId, int $employmentId, string $start, string $today, ?int $userId, PayrollTakeoverPolicy $policy): array
    {
        $row = $this->employmentById($supplierId, $employmentId);
        if ($row === null || $row['status'] !== 'planned' || $start > $today) {
            return [];
        }
        $this->employments->transition($supplierId, $employmentId, 'active', (int) $row['row_version'], $start,
            $policy->note('vztah vedený od ' . PayrollTakeoverFormat::czechDate($start) . '.'), $userId, null, null);

        return ['activated' => 1];
    }

    /** Osobní číslo jde dát vztahu: má platný tvar a ve firmě ho nemá jiný vztah. */
    public function employmentCodeAvailable(int $supplierId, string $code): bool
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,63}$/', $code) !== 1) {
            return false;
        }
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM payroll_employments WHERE supplier_id = ? AND code = ?');
        $stmt->execute([$supplierId, $code]);

        return $stmt->fetchColumn() === false;
    }

    private function hasActivePrimary(int $supplierId, int $employeeId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM payroll_employments WHERE supplier_id = ? AND employee_id = ? AND is_primary = 1 AND status IN ('planned', 'active', 'suspended')"
        );
        $stmt->execute([$supplierId, $employeeId]);

        return $stmt->fetchColumn() !== false;
    }

    /** Důvod verze podmínek, kterou zapisuje převod ze sjednané mzdy zdroje. */
    public static function wageNote(PayrollTakeoverPolicy $policy): string
    {
        return 'Sjednaná měsíční mzda z ' . $policy->label . '.';
    }

    /**
     * Sjednaná měsíční mzda v podmínkách vztahu podle historie ve zdroji. První verze se
     * opraví na místě, další se zakládají s účinností od měsíce změny. Bez ní běh nemá
     * z čeho spočítat základní mzdu.
     *
     * @return array<string,int>
     */
    public function monthlyWage(int $supplierId, int $employmentId, PayrollTakeoverEmployment $employment, ?int $userId, PayrollTakeoverPolicy $policy): array
    {
        $wages = $employment->monthlyWages;
        if ($wages === []) {
            return [];
        }
        $employmentRow = $this->employmentById($supplierId, $employmentId)
            ?? throw new \DomainException('pracovní vztah ve firmě není.');
        // Ukončený vztah už podmínky měnit nedovolí a jeho mzdy jsou historie; sjednaná
        // mzda se u něj nepřenáší.
        if (in_array((string) $employmentRow['status'], ['ended', 'archived', 'no_show'], true)) {
            return ['monthly_wage_ended' => 1];
        }
        // Totéž platí pro vztah, který podle zdroje skončil dřív, než MyÚčto začne mzdy
        // počítat, i když ho tenhle převod ukončí až po mzdě: výsledek nesmí záviset na
        // pořadí převáděných let (novější rok ho ukončí dřív, než přijde starší).
        if ($this->endedBeforeModuleStart($supplierId, $employment)) {
            return ['monthly_wage_ended' => 1];
        }
        $counts = $wages[0]['prorated'] === true ? ['monthly_wage_max' => 1] : [];
        // Sazba, kterou ještě před první verzí podmínek vystřídala další, do vztahu nepatří;
        // jinak by se první verze opravovala tam a zpátky při každém převodu.
        $firstFrom = (string) ($this->termsAt($supplierId, $employmentId, '0000-01-01')['effective_from'] ?? '');
        $wages = array_values(array_filter(
            $wages,
            static fn (array $wage, int $index): bool => !isset($wages[$index + 1]) || $wages[$index + 1]['from'] > $firstFrom,
            ARRAY_FILTER_USE_BOTH,
        ));
        $written = 0;
        $settled = 0;
        foreach ($wages as $index => $wage) {
            $minor = (int) round($wage['amount'] * 100);
            if ($minor <= 0) {
                continue;
            }
            $current = $this->employments->currentTerms($supplierId, $employmentId)
                ?? throw new \DomainException('pracovní vztah nemá verzi sjednaných podmínek.');
            // Sazba se srovnává s verzí platnou k jejímu datu, ne s nejnovější: opakovaný
            // převod by jinak historickou sazbou přepsal pozdější verzi. Nese-li ji verze,
            // není co psát, i když verze začíná dřív než mzda ve zdroji.
            $at = $this->termsAt($supplierId, $employmentId, $wage['from']);
            if ((int) ($at['monthly_gross_minor'] ?? 0) === $minor) {
                continue;
            }
            // Jinou sazbu ve verzi, po které už následuje další, převod nepřepisuje:
            // oprava jde jen na poslední verzi a nová verze jen za ni.
            if ((int) $at['id'] !== (int) $current['id']) {
                $counts['monthly_wage_history_kept'] = ($counts['monthly_wage_history_kept'] ?? 0) + 1;
                continue;
            }
            // Verze vztahu se po každém zápisu mění, proto se čte znovu před každou verzí mzdy.
            $row = $this->employmentById($supplierId, $employmentId);
            $body = RegistrationImportWriter::termsBody($current, self::wageNote($policy));
            $terms = $this->employmentValidator->terms(
                $body + ['effective_from' => $wage['from']],
                $this->employments->currentCzIscoCode($supplierId, $employmentId),
                $this->employments->currentOtherWithholdingEligibility($supplierId, $employmentId),
                $this->employments->currentRelationType($supplierId, $employmentId),
            );
            // První verze podmínek se opraví na místě (vztah začal dřív, než převod sahá),
            // pozdější změna mzdy je nová verze od měsíce, ve kterém ji zdroj zvedl.
            if ($index === 0 || $wage['from'] <= (string) $current['effective_from']) {
                $terms['effective_from'] = (string) $current['effective_from'];
                try {
                    $this->employments->correctTerms($supplierId, $employmentId, $terms, (int) $row['row_version'], $userId, null, null, true, $minor);
                } catch (PayrollTermsSettledException $e) {
                    // Z verze už bylo zúčtováno: mzda se zapíše jako nová verze od měsíce,
                    // který uzavřený běh nepokrývá.
                    $from = self::nextMonth($e->settledPeriod);
                    if ($from === null) {
                        throw $e;
                    }
                    $terms['effective_from'] = $from;
                    $this->employments->addTerms($supplierId, $employmentId, $terms, (int) $row['row_version'], $userId, null, null, true, $minor);
                }
            } else {
                try {
                    $this->employments->addTerms($supplierId, $employmentId, $terms, (int) $row['row_version'], $userId, null, null, true, $minor);
                } catch (PayrollTermsSettledException $e) {
                    $from = self::nextMonth($e->settledPeriod);
                    if ($from === null || $from <= (string) $current['effective_from']) {
                        $settled++;
                        continue;
                    }
                    $terms['effective_from'] = $from;
                    $this->employments->addTerms($supplierId, $employmentId, $terms, (int) $row['row_version'], $userId, null, null, true, $minor);
                }
            }
            $written++;
        }
        if ($settled > 0) {
            $counts['monthly_wage_settled'] = $settled;
        }
        return $written > 0 ? $counts + ['monthly_wage' => $written] : $counts;
    }

    /**
     * Týdenní pracovní doba vztahu v měsíci, jak ji zdroj u zpracované mzdy vedl.
     *
     * Vztah vzniká s týdenní dobou z karty zdroje, tedy s tou, která platí DNES. Měsíc, ve
     * kterém zdroj počítal s jinou (kratší úvazek v minulosti, změna úvazku během roku), by
     * jinak zůstal s dnešní dobou a fond i hlášení za něj by vyšly z ní; podané hlášení ji
     * opravuje jen u měsíců, za které nějaké existuje. Doba ze mzdy měsíce je proto zdrojem
     * pravdy pro ten měsíc: verze platná k jeho začátku se opraví na místě (první převáděný
     * měsíc první verze, jako u sjednané mzdy), jinak vznikne nová verze od začátku měsíce.
     * Úvazek se přepočte poměrem, takže stanovená týdenní doba zaměstnavatele (§ 79 ZP)
     * zůstává, jak ji verze nesla. Verzi, po které už následuje další, převod nepřepisuje.
     *
     * @param bool $firstMonth první převáděný měsíc vztahu v tomto převodu
     * @return array<string,int>
     */
    public function monthWeeklyHours(int $supplierId, int $employmentId, string $period, float $weeklyHours, bool $firstMonth, ?int $userId, PayrollTakeoverPolicy $policy): array
    {
        if ($weeklyHours <= 0) {
            return [];
        }
        $employmentRow = $this->employmentById($supplierId, $employmentId)
            ?? throw new \DomainException('pracovní vztah ve firmě není.');
        if (in_array((string) $employmentRow['status'], ['ended', 'archived', 'no_show'], true)) {
            return [];
        }
        $from = $period . '-01';
        $at = $this->termsAt($supplierId, $employmentId, $from);
        $desired = (int) round($weeklyHours * 100);
        $currentWeekly = $at['weekly_hours'] === null ? null : (int) round((float) $at['weekly_hours'] * 100);
        if ($currentWeekly === $desired) {
            return [];
        }
        $current = $this->employments->currentTerms($supplierId, $employmentId)
            ?? throw new \DomainException('pracovní vztah nemá verzi sjednaných podmínek.');
        if ((int) $at['id'] !== (int) $current['id']) {
            return ['weekly_hours_history_kept' => 1];
        }
        $workload = (int) ($current['workload_basis_points'] ?? 0);
        $weekly = sprintf('%.2f', $desired / 100);
        $changes = [
            'weekly_hours' => $weekly,
            'workload_basis_points' => $currentWeekly !== null && $currentWeekly > 0 && $workload > 0
                ? max(1, min(10_000, (int) round($workload * $desired / $currentWeekly)))
                : PayrollPersonCreateValidator::workloadBasisPoints($weekly),
        ];
        $body = RegistrationImportWriter::termsBody($current, 'Týdenní pracovní doba ze mzdy ' . $policy->label . ' za ' . $period . '.');
        foreach ($changes as $field => $value) {
            $body[$field] = $value;
        }
        $inPlace = $firstMonth && $from > (string) $current['effective_from']
            && (int) $this->termsAt($supplierId, $employmentId, '0000-01-01')['id'] === (int) $current['id'];
        $body['effective_from'] = $inPlace || $from <= (string) $current['effective_from'] ? (string) $current['effective_from'] : $from;
        $terms = $this->employmentValidator->terms(
            $body,
            $this->employments->currentCzIscoCode($supplierId, $employmentId),
            $this->employments->currentOtherWithholdingEligibility($supplierId, $employmentId),
            $this->employments->currentRelationType($supplierId, $employmentId),
        );
        try {
            if ($body['effective_from'] === (string) $current['effective_from']) {
                $this->employments->correctTerms($supplierId, $employmentId, $terms, (int) $employmentRow['row_version'], $userId, null, null);
            } else {
                $this->employments->addTerms($supplierId, $employmentId, $terms, (int) $employmentRow['row_version'], $userId, null, null);
            }
        } catch (PayrollTermsSettledException) {
            return ['weekly_hours_settled' => 1];
        }

        return ['weekly_hours' => 1];
    }

    /** Vztah podle zdroje skončil před prvním měsícem, který počítá MyÚčto. */
    private function endedBeforeModuleStart(int $supplierId, PayrollTakeoverEmployment $employment): bool
    {
        if ($employment->end === null) {
            return false;
        }
        $stmt = $this->db->pdo()->prepare('SELECT start_period FROM payroll_module_state WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $start = $stmt->fetchColumn();

        return is_string($start) && $start !== '' && $employment->end < substr($start, 0, 10);
    }

    /**
     * Verze podmínek platná k datu; před první verzí ta první.
     *
     * @return array{id:int|string,effective_from:string,monthly_gross_minor:int|string|null,weekly_hours:string|null,workload_basis_points:int|string|null}
     */
    private function termsAt(int $supplierId, int $employmentId, string $date): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, effective_from, monthly_gross_minor, weekly_hours, workload_basis_points FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ?
              ORDER BY effective_from <= ? DESC,
                       CASE WHEN effective_from <= ? THEN effective_from END DESC,
                       effective_from, id DESC
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $employmentId, $date, $date]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? throw new \DomainException('pracovní vztah nemá verzi sjednaných podmínek.') : $row;
    }

    /**
     * Předpis pravidelné měsíční mzdy (`MZDA_MESICNI`, druh `base_wage`). Bez něj za období
     * nevznikne vstup základní mzdy a běh počítá jen to, co přišlo z docházky.
     *
     * Předpis dostane každý vztah se sjednanou měsíční mzdou, a to od prvního převáděného
     * měsíce (ořízne se na nástup a na platnost složky v číselníku); další verze mzdy
     * předchozí předpis ukončí, takže řada je souvislá bez děr a překryvů. Rozpočítání je
     * podle kalendářních dnů, takže nástup nebo skončení v půlce měsíce krátí částku samo
     * ({@see \MyInvoice\Service\Payroll\Component\PayrollRecurringAmountCalculator}).
     *
     * @return array<string,int>
     */
    public function recurringWage(int $supplierId, int $employmentId, PayrollTakeoverEmployment $employment, ?int $userId, PayrollTakeoverPolicy $policy, PayrollTakeoverRunState $state): array
    {
        $wages = $employment->monthlyWages;
        if ($wages === []) {
            return [];
        }
        /*
         * Vztah, který má v převáděných měsících hodinovou nebo úkolovou mzdu, předpis
         * NEDOSTANE. Tyhle složky jdou do běhu jako vstupy z docházky a základní mzda je
         * ve zdroji (PAMICA `KcZaklM`) nese v sobě, ne vedle nich: ověřeno na spočítaném
         * běhu za 6/2026, kde všech 116 takových vztahů vyšlo výš (o 4,32 mil. Kč), tedy
         * dvojí započtení. Základní mzdu u nich zadá účetní, protokol je spočítá.
         */
        if ($employment->hourlyWage) {
            $state->hourlyWageRelations++;
            return ['recurring_wage_hourly' => 1];
        }
        $counts = [];
        $component = $this->monthlyWageComponent($supplierId);
        if ($component === null) {
            throw new \DomainException('firma nemá v číselníku složku základní měsíční mzdy (MZDA_MESICNI).');
        }
        $componentId = (int) $component['id'];
        $row = $this->employmentById($supplierId, $employmentId);
        // Předpis musí ležet uvnitř trvání vztahu i platnosti složky v číselníku.
        $lower = max(
            (string) ($row['actual_start_date'] ?? $row['start_date'] ?? '0000-01-01'),
            (string) ($component['valid_from'] ?? '0000-01-01'),
        );
        $upper = null;
        // Skončení ze zdroje platí i tehdy, když ho vztah ve firmě ještě nemá (zapíše ho
        // tentýž převod až po mzdě); jinak by předpis běžel za konec vztahu.
        foreach ([$row['end_date'] ?? null, $employment->end, $component['valid_to'] ?? null] as $limit) {
            if (is_string($limit) && ($upper === null || $limit < $upper)) {
                $upper = $limit;
            }
        }
        $existing = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_recurring_components WHERE supplier_id = ? AND employment_id = ? AND component_id = ?'
        );
        $existing->execute([$supplierId, $employmentId, $componentId]);
        if ((int) $existing->fetchColumn() > 0) {
            return $counts + $this->repairOwnRecurringWage($supplierId, $employmentId, $componentId, $wages, $userId, $policy);
        }
        $written = 0;
        foreach ($wages as $index => $wage) {
            $minor = (int) round($wage['amount'] * 100);
            if ($minor <= 0) {
                continue;
            }
            $next = $wages[$index + 1]['from'] ?? null;
            $from = max($wage['from'], $lower);
            $to = $next === null ? null : (new \DateTimeImmutable($next))->modify('-1 day')->format('Y-m-d');
            if ($upper !== null && ($to === null || $to > $upper)) {
                $to = $upper;
            }
            if ($to !== null && $to < $from) {
                continue;
            }
            $this->recurring->create($supplierId, $this->recurringValidator->validate([
                'employment_id' => $employmentId,
                'component_id' => $componentId,
                'calculation_kind' => 'fixed_amount',
                'amount_minor' => $minor,
                'rate_basis_points' => null,
                'valid_from' => $from,
                'valid_to' => $to,
                'allocation_rule' => 'calendar_days',
                'maximum_amount_minor' => null,
                'note' => $policy->note('sjednaná měsíční mzda ze zpracovaných mezd.'),
                'is_active' => true,
            ]), $userId);
            $written++;
        }
        return $written > 0 ? $counts + ['recurring_wage' => $written] : $counts;
    }

    /**
     * Opakovaný převod srovná předpis, který zapsal dřívější převod téhož zdroje, se
     * sjednanou mzdou zdroje. Předpis zadaný nebo upravený jinak (jiná poznámka) se
     * nemění. Padne-li do jednoho předpisu víc verzí mzdy zdroje (zvýšení v průběhu
     * roku), předpis se ukončí den před změnou a další verze dostanou vlastní předpis,
     * stejně jako při prvním převodu. Začátek předpisu se nemění nikdy.
     *
     * Sazba se srovnává s předpisem platným k jejímu datu, ne se všemi předpisy. Převod
     * jde rok po roce a zdroj každého roku zná jen sazby do svého konce; jeho poslední
     * sazba je otevřená jen proto, že o další změně neví. Opakovaný převod staršího roku
     * by jinak přepsal pozdější předpis a další rok by ho vracel zpátky. Opravuje se
     * proto jen poslední předpis, stejně jako u verzí podmínek; jinou sazbu
     * v předpisu, po kterém už následuje další, převod ponechá.
     *
     * @param list<array{from:string,amount:float,prorated:bool}> $wages
     * @return array<string,int>
     */
    private function repairOwnRecurringWage(int $supplierId, int $employmentId, int $componentId, array $wages, ?int $userId, PayrollTakeoverPolicy $policy): array
    {
        $note = $policy->note('sjednaná měsíční mzda ze zpracovaných mezd.');
        $rows = $this->ownRecurringRows($supplierId, $employmentId, $componentId);
        foreach ($rows as $row) {
            if ((string) $row['note'] !== $note) {
                return [];
            }
        }
        $start = (string) $rows[0]['valid_from'];
        $corrected = 0;
        foreach ($wages as $index => $wage) {
            $next = $wages[$index + 1]['from'] ?? null;
            // Sazba, kterou ještě před začátkem předpisu vystřídala další, do něj nepatří.
            if ($next !== null && $next <= $start) {
                continue;
            }
            $date = max($start, $wage['from']);
            $minor = (int) round($wage['amount'] * 100);
            $rows = $this->ownRecurringRows($supplierId, $employmentId, $componentId);
            $atIndex = null;
            foreach ($rows as $rowIndex => $candidate) {
                if ((string) $candidate['valid_from'] <= $date) {
                    $atIndex = $rowIndex;
                }
            }
            if ($atIndex === null) {
                continue;
            }
            $at = $rows[$atIndex];
            $from = (string) $at['valid_from'];
            $to = $at['valid_to'] === null ? null : (string) $at['valid_to'];
            if (($to !== null && $to < $date)
                || $atIndex !== count($rows) - 1
                || ($minor > 0 && (int) $at['amount_minor'] === $minor)
                || ($minor <= 0 && $from === $date)
            ) {
                continue;
            }
            $dayBefore = (new \DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
            if ($minor <= 0) {
                // Měsíční mzda ve zdroji skončila: předpis se ukončí den předtím.
                $this->recurring->update($supplierId, (int) $at['id'], $this->recurringData($at, $employmentId, $componentId, $from, $dayBefore, (int) $at['amount_minor'], $note), (int) $at['row_version'], $userId);
                $corrected++;
                continue;
            }
            if ($from === $date) {
                $this->recurring->update($supplierId, (int) $at['id'], $this->recurringData($at, $employmentId, $componentId, $from, $to, $minor, $note), (int) $at['row_version'], $userId);
                $corrected++;
                continue;
            }
            $this->recurring->update($supplierId, (int) $at['id'], $this->recurringData($at, $employmentId, $componentId, $from, $dayBefore, (int) $at['amount_minor'], $note), (int) $at['row_version'], $userId);
            $this->recurring->create($supplierId, $this->recurringData($at, $employmentId, $componentId, $date, $to, $minor, $note), $userId);
            $corrected += 2;
        }

        return $corrected > 0 ? ['recurring_wage_corrected' => $corrected] : [];
    }

    /** @return list<array<string,mixed>> */
    private function ownRecurringRows(int $supplierId, int $employmentId, int $componentId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_recurring_components WHERE supplier_id = ? AND employment_id = ? AND component_id = ? ORDER BY valid_from'
        );
        $statement->execute([$supplierId, $employmentId, $componentId]);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function recurringData(array $row, int $employmentId, int $componentId, string $from, ?string $to, int $amountMinor, string $note): array
    {
        return $this->recurringValidator->validate([
            'employment_id' => $employmentId,
            'component_id' => $componentId,
            'calculation_kind' => (string) $row['calculation_kind'],
            'amount_minor' => $amountMinor,
            'rate_basis_points' => $row['rate_basis_points'] === null ? null : (int) $row['rate_basis_points'],
            'valid_from' => $from,
            'valid_to' => $to,
            'allocation_rule' => (string) $row['allocation_rule'],
            'maximum_amount_minor' => $row['maximum_amount_minor'] === null ? null : (int) $row['maximum_amount_minor'],
            'note' => $note,
            'is_active' => (int) $row['is_active'] === 1,
        ]);
    }

    /**
     * Čtvrtletní průměrný výdělek pro náhrady: hodnota, se kterou počítal zdroj, jako
     * schválený snímek. Rozhodné období a výdělek v něm jdou do snímku jako doložení.
     *
     * @return array<string,int>
     */
    public function averageEarnings(int $supplierId, int $employmentId, PayrollTakeoverEmployment $employment, ?int $userId, PayrollTakeoverPolicy $policy): array
    {
        $averages = $employment->averages;
        if ($averages === []) {
            return [];
        }
        $written = 0;
        $defaultPeriod = 0;
        foreach ($averages as $average) {
            $hourlyMinor = (int) round(((float) $average['hourly']) * 100);
            if ($hourlyMinor <= 0) {
                continue;
            }
            $quarterStart = sprintf('%04d-%02d-01', (int) $average['year'], ((int) $average['quarter'] - 1) * 3 + 1);
            if ($this->averages->findApproved($supplierId, $employmentId, (int) $average['year'], (int) $average['quarter']) !== null) {
                continue;
            }
            // Pravděpodobný výdělek nástupce zdroj vede bez rozhodného období (od > do).
            // Snímek ho vyžaduje, proto se doplní předchozí kalendářní čtvrtletí (§ 354 ZP),
            // jak ho u pravděpodobného výdělku bere i výpočet v aplikaci.
            $from = (string) $average['from'];
            $to = (string) $average['to'];
            if ($from === '' || $to === '' || $to < $from) {
                $previous = (new \DateTimeImmutable($quarterStart))->modify('-3 months');
                $from = $previous->format('Y-m-d');
                $to = $previous->modify('+2 months')->format('Y-m-t');
                $defaultPeriod++;
            }
            // `probable`, ne `actual`: rozhodné období leží před převodem a jeho odpracované
            // hodiny a dny zdroj nenese. Hodnota je ta, se kterou zdroj počítal náhrady.
            $result = new AverageEarningResult('probable', $hourlyMinor, 'supported', [
                'source' => $policy->sourceKey,
                'hourly_minor' => $hourlyMinor,
            ]);
            $snapshot = $this->averages->create(
                $supplierId,
                $employmentId,
                (int) $average['year'],
                (int) $average['quarter'],
                $from,
                $to,
                (int) round(((float) $average['gross']) * 100),
                0,
                (int) round(((float) $average['worked']) * 60),
                (int) round((float) $average['days']),
                $policy->note("průměrný výdělek, se kterým {$policy->label} počítala náhrady čtvrtletí."),
                $result,
                $this->rulesets->forDate(PayrollRulesetDomain::CompensationAverages, $quarterStart),
                $userId,
            );
            $this->averages->approve($supplierId, (int) $snapshot['id'], (int) $snapshot['row_version'], $userId);
            $written++;
        }
        $counts = $written > 0 ? ['averages' => $written] : [];
        return $defaultPeriod > 0 ? $counts + ['averages_default_period' => $defaultPeriod] : $counts;
    }

    /**
     * Pracoviště JMHZ (obec a stát) do platné verze podmínek, pokud ho ještě nemá.
     *
     * @return array<string,int>
     */
    public function workplace(int $supplierId, int $employmentId, PayrollTakeoverEmployment $employment, ?int $userId, PayrollTakeoverPolicy $policy): array
    {
        $place = $employment->workplace;
        if (!is_array($place)) {
            return [];
        }
        $current = $this->employments->currentTerms($supplierId, $employmentId)
            ?? throw new \DomainException('pracovní vztah nemá verzi sjednaných podmínek.');
        if (($current['jmhz_workplace_municipality_code'] ?? null) !== null) {
            return [];
        }
        $workPlace = trim((string) ($current['work_place'] ?? ''));
        if ($workPlace !== '' && $workPlace !== $place['work_place']) {
            throw new \DomainException("místo výkonu práce na vztahu ({$workPlace}) se liší od obce pracoviště v {$policy->label}, kód obce doplňte ručně.");
        }
        $changes = [
            'work_place' => $place['work_place'],
            'jmhz_workplace_municipality_code' => $place['municipality_code'],
            'jmhz_workplace_country_code' => $place['country_code'],
        ];
        if (trim((string) ($current['regular_workplace'] ?? '')) === '' && $place['regular_workplace'] !== null) {
            $changes['regular_workplace'] = $place['regular_workplace'];
        }
        $this->correctTerms($supplierId, $employmentId, $current, $changes, $userId, $policy);
        return ['workplace' => 1];
    }

    /** Pole podmínek, která doplní {@see self::fillTerms()}; stav `unverified` se bere jako nevyplněný. */
    private const FILLABLE_STATUS_TERMS = [
        'jmhz_apz_contribution_status',
        'jmhz_functional_benefits_status',
        'jmhz_temporary_assignment_status',
    ];
    private const FILLABLE_TEXT_TERMS = ['cz_isco_code', 'activity_code', 'jmhz_relationship_detail_code', 'regular_workplace'];

    /**
     * Údaje podmínek vztahu, které zdroj dokládá z podaných hlášení a registrací (vykonávaná
     * pozice, úvazek, druh činnosti, CZ-ISCO), do platné verze podmínek - jen pole, která
     * verze ještě nemá. Vyplněné pole se nepřepisuje: stav příznaku JMHZ jen z `unverified`,
     * týdenní doba s úvazkem jen tam, kde týdenní doba chybí, pracoviště jen bez kódu obce
     * a se shodným místem výkonu práce (jako {@see self::workplace()}).
     *
     * @param array<string,?string> $desired pole `payroll_employment_terms` => hodnota
     * @return array<string,int> doplněná pole => 1
     */
    public function fillTerms(int $supplierId, int $employmentId, array $desired, ?int $userId, PayrollTakeoverPolicy $policy): array
    {
        if ($desired === []) {
            return [];
        }
        $current = $this->employments->currentTerms($supplierId, $employmentId)
            ?? throw new \DomainException('pracovní vztah nemá verzi sjednaných podmínek.');
        $empty = static fn (mixed $value): bool => $value === null || trim((string) $value) === '';
        $changes = [];
        foreach (self::FILLABLE_STATUS_TERMS as $field) {
            if (isset($desired[$field]) && in_array($desired[$field], ['yes', 'no'], true)
                && ($current[$field] ?? 'unverified') === 'unverified'
            ) {
                $changes[$field] = $desired[$field];
                if ($field === 'jmhz_apz_contribution_status') {
                    $changes['jmhz_apz_instrument_code'] = $desired['jmhz_apz_instrument_code'] ?? null;
                }
            }
        }
        foreach (self::FILLABLE_TEXT_TERMS as $field) {
            if (!$empty($desired[$field] ?? null) && $empty($current[$field] ?? null)) {
                $changes[$field] = $desired[$field];
            }
        }
        // Upřesnění vztahu jen u druhu činnosti, který ho připouští (jediné pravidlo,
        // podle kterého ho kontroluje i karta vztahu a registrace).
        $activity = (string) ($changes['activity_code'] ?? $current['activity_code'] ?? '');
        if (isset($changes['jmhz_relationship_detail_code'])
            && ($activity === '' || PayrollRegistrationRelationshipDetailPolicy::modeForActivity($activity)
                === PayrollRegistrationRelationshipDetailPolicy::MODE_FORBIDDEN)
        ) {
            unset($changes['jmhz_relationship_detail_code']);
        }
        if (!$empty($desired['weekly_hours'] ?? null) && $empty($current['weekly_hours'] ?? null)) {
            $changes['weekly_hours'] = $desired['weekly_hours'];
            if (!$empty($desired['workload_basis_points'] ?? null)) {
                $changes['workload_basis_points'] = (int) $desired['workload_basis_points'];
            }
        } elseif (($workload = self::defaultedWorkloadCorrection($current, $desired)) !== null) {
            $changes['workload_basis_points'] = $workload;
        }
        $place = trim((string) ($current['work_place'] ?? ''));
        if (!$empty($desired['jmhz_workplace_municipality_code'] ?? null) && !$empty($desired['work_place'] ?? null)
            && $empty($current['jmhz_workplace_municipality_code'] ?? null)
            && ($place === '' || mb_strtolower($place) === mb_strtolower((string) $desired['work_place']))
        ) {
            $changes['work_place'] = $place !== '' ? $place : $desired['work_place'];
            $changes['jmhz_workplace_municipality_code'] = $desired['jmhz_workplace_municipality_code'];
            $changes['jmhz_workplace_country_code'] = $desired['jmhz_workplace_country_code'] ?? 'CZ';
        }
        if ($changes === []) {
            return [];
        }
        $this->correctTerms($supplierId, $employmentId, $current, $changes, $userId, $policy);
        $counts = [];
        foreach (array_keys($changes) as $field) {
            $counts['terms_' . $field] = 1;
        }

        return $counts;
    }

    /**
     * Úvazek, který zdroj dokládá, místo úvazku dosazeného při založení vztahu.
     *
     * Úvazek nemá prázdnou hodnotu: založení osoby ho dopočítá jako podíl týdenní doby
     * a zákonných 40 h ({@see PayrollPersonCreateValidator::workloadBasisPoints()}).
     * U zaměstnavatele s kratší stanovenou dobou (§ 79 odst. 2 ZP, třeba 37,5 h ve
     * třísměnném režimu) tak plný úvazek 37,5 h vyjde jako 93,75 % a hlášení by
     * uvádělo stanovenou týdenní dobu 40 h (10261) a fond ze 40 h (10259). Opraví se
     * jen úvazek, který je pořád ten dosazený, u shodné týdenní doby — úvazek, který
     * někdo změnil, se nepřepisuje.
     *
     * @param array<string,mixed> $current
     * @param array<string,?string> $desired
     */
    public static function defaultedWorkloadCorrection(array $current, array $desired): ?int
    {
        $desiredWorkload = $desired['workload_basis_points'] ?? null;
        $desiredWeekly = $desired['weekly_hours'] ?? null;
        $currentWeekly = $current['weekly_hours'] ?? null;
        if (!is_numeric($desiredWorkload) || !is_numeric($desiredWeekly) || !is_numeric($currentWeekly)) {
            return null;
        }
        $desiredWorkload = (int) $desiredWorkload;
        $currentWorkload = (int) ($current['workload_basis_points'] ?? 10_000);
        if ($desiredWorkload < 1 || $desiredWorkload > 10_000 || $desiredWorkload === $currentWorkload
            || (int) round((float) $desiredWeekly * 100) !== (int) round((float) $currentWeekly * 100)
            || $currentWorkload !== PayrollPersonCreateValidator::workloadBasisPoints(sprintf('%.2f', (float) $currentWeekly))
        ) {
            return null;
        }

        return $desiredWorkload;
    }

    /**
     * Kód CZ-ISCO do platné verze podmínek, pokud ho ještě nemá.
     *
     * @return array<string,int>
     */
    public function czIsco(int $supplierId, int $employmentId, PayrollTakeoverEmployment $employment, ?int $userId, PayrollTakeoverPolicy $policy): array
    {
        if (!is_string($employment->czIsco)) {
            return [];
        }
        $current = $this->employments->currentTerms($supplierId, $employmentId)
            ?? throw new \DomainException('pracovní vztah nemá verzi sjednaných podmínek.');
        if (($current['cz_isco_code'] ?? null) !== null && $current['cz_isco_code'] !== '') {
            return [];
        }
        $this->correctTerms($supplierId, $employmentId, $current, ['cz_isco_code' => $employment->czIsco], $userId, $policy);
        return ['cz_isco' => 1];
    }

    /**
     * OIČ a ID PPV ze zdroje - uživatel v průvodci potvrdil, že pocházejí z protokolů ČSSZ.
     * Platí od nástupu, uložená čísla se nepřepisují. OIČ, které nesedí na kontrolní
     * číslici, se nepřevezme a zapíše do `$state->invalidOic`.
     *
     * @return array<string,int>
     */
    public function identifiers(int $supplierId, int $employeeId, int $employmentId, PayrollTakeoverEmployment $employment, ?int $userId, PayrollTakeoverPolicy $policy, PayrollTakeoverRunState $state): array
    {
        $row = $this->employmentById($supplierId, $employmentId)
            ?? throw new \DomainException('pracovní vztah ve firmě není.');
        $validFrom = $row['actual_start_date'] ?? $row['start_date'];
        if ($validFrom === null) {
            throw new \DomainException('vztah nemá datum nástupu, OIČ a ID PPV doplňte ručně.');
        }
        $validFrom = (string) $validFrom;
        $counts = [];
        $oic = $employment->oic;
        if ($oic !== null) {
            try {
                $oic = PayrollRegistrationIdentityService::oic($oic);
            } catch (\InvalidArgumentException) {
                $state->invalidOic[] = $employment->personalNumber;
                $counts['oic_invalid'] = 1;
                $oic = null;
            }
        }
        if ($oic !== null && $this->registrations->personExternalIdAt($supplierId, $employeeId, self::ENVIRONMENT, 'ik_mpsv', $validFrom) !== null) {
            $oic = null;
        }
        $ppv = $employment->idPpv;
        if ($ppv !== null && $this->registrations->externalIdAt($supplierId, $employmentId, self::ENVIRONMENT, 'id_ppv', $validFrom) !== null) {
            $ppv = null;
        }
        if ($oic === null && $ppv === null) {
            return $counts;
        }
        $this->identities->assignManualJmhzIdentity(
            $supplierId,
            $employmentId,
            self::ENVIRONMENT,
            $oic,
            $ppv,
            $validFrom,
            'Převzato z ' . $policy->label . ', osobní číslo ' . $employment->personalNumber,
            true,
            $userId,
        );
        if ($oic !== null) {
            $counts['oic'] = 1;
        }
        if ($ppv !== null) {
            $counts['id_ppv'] = 1;
        }
        return $counts;
    }

    /**
     * Skončení vztahu k datu ze zdroje. Budoucí skončení (smlouva na dobu určitou) převod
     * nezapisuje, zapíše se až v den skončení běžnou cestou; se
     * {@see PayrollTakeoverPolicy::$countPlannedTermination} ho aspoň spočítá.
     *
     * @param ?string $until skončení po tomto dni (mimo převáděné období) se nezapisuje
     * @return array<string,int>
     */
    public function termination(int $supplierId, int $employmentId, PayrollTakeoverEmployment $employment, string $today, ?string $until, ?int $userId, PayrollTakeoverPolicy $policy): array
    {
        $end = $employment->end;
        if (!is_string($end) || ($until !== null && $end > $until)) {
            return [];
        }
        if ($end > $today) {
            return $policy->countPlannedTermination ? ['end_planned' => 1] : [];
        }
        if ($policy->ignoreEndBeforeStart && $end < (string) $employment->start) {
            return [];
        }
        $row = $this->employmentById($supplierId, $employmentId);
        if ($row === null) {
            if ($policy->strict) {
                throw new \DomainException('pracovní vztah ve firmě není.');
            }
            return [];
        }
        if (!in_array($row['status'], ['active', 'suspended'], true)) {
            return [];
        }
        $this->employments->transition(
            $supplierId,
            $employmentId,
            'ended',
            (int) $row['row_version'],
            $end,
            $policy->note('vztah skončil ' . PayrollTakeoverFormat::czechDate($end) . '.'),
            $userId,
            null,
            null,
        );
        return ['ended' => 1];
    }

    /**
     * Odškrtne nevyřízené položky Zákonných termínů, ke kterým zdroj nese doklad.
     *
     * Změnové položky (`$changeItems`) dostanou poznámku z `$changeNote`, který se zavolá
     * nejvýš jednou a jen tehdy, když taková položka čeká; `null` z něj položku nechá
     * otevřenou. Položku, kterou nejde odškrtnout, předá `$onFailure` a pokračuje dál
     * (co se toleruje, říká {@see self::checklistFailure()}).
     *
     * @param array<string,string> $notes položka => poznámka
     * @param list<string> $changeItems
     * @param (callable():?string)|null $changeNote
     * @param callable(string,\Exception):void $onFailure
     * @return array<string,int>
     */
    public function completeChecklist(
        int $supplierId,
        int $employmentId,
        array $notes,
        array $changeItems,
        ?callable $changeNote,
        ?int $userId,
        PayrollTakeoverPolicy $policy,
        PayrollTakeoverRunState $state,
        callable $onFailure,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            "SELECT phase, item_key, row_version FROM payroll_employment_checklist_items
              WHERE supplier_id = ? AND employment_id = ? AND status = 'pending' ORDER BY id"
        );
        $stmt->execute([$supplierId, $employmentId]);
        $done = 0;
        $change = false;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $item) {
            $key = (string) $item['item_key'];
            if ($changeNote !== null && $item['phase'] === 'change' && in_array($key, $changeItems, true)) {
                $change = $change === false ? $changeNote() : $change;
                if ($change !== null) {
                    $notes[$key] = $change;
                }
            }
            if (!isset($notes[$key])) {
                continue;
            }
            try {
                $this->employments->updateChecklist($supplierId, $employmentId, $key, (int) $item['row_version'], 'completed', $notes[$key], $userId, null, null);
            } catch (\Exception $e) {
                if (!self::checklistFailure($e)) {
                    throw $e;
                }
                $onFailure($key, $e);
                continue;
            }
            $state->completed[$key] = ($state->completed[$key] ?? 0) + 1;
            $done++;
        }
        return $done > 0 ? ['checklist_completed' => $done] : [];
    }

    /**
     * Úkoly na vztahu, které zdroj dokládá jen příznakem — třeba hlášení JMHZ
     * vykazuje srážky ze mzdy, ale ne jejich druh, pořadí ani plátce. Bez úkolu
     * by se srážky převzaly tiše jako nula a první mzda v MyÚčtu by vyšla bez
     * srážky. Úkol se zakládá jednou (opakovaný import ho nezdvojí) s termínem
     * prvního dne vedení mezd v MyÚčtu: do první výplaty musí být srážka
     * zaevidovaná.
     *
     * @return array<string,int>
     */
    public function followUps(
        int $supplierId,
        int $employmentId,
        PayrollTakeoverEmployment $employment,
        string $startPeriod,
        PayrollTakeoverPolicy $policy,
    ): array {
        $created = 0;
        foreach ($employment->followUps as $itemKey) {
            $created += $this->employments->ensureFollowUpItem(
                $supplierId,
                $employmentId,
                $itemKey,
                substr($startPeriod, 0, 7) . '-01',
                $policy->note('úkol založen při převzetí.'),
            ) ? 1 : 0;
        }

        return $created > 0 ? ['follow_ups' => $created] : [];
    }

    /** @return array<string,mixed>|null */
    public function employmentById(int $supplierId, int $employmentId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, employee_id, status, start_date, actual_start_date, end_date, row_version
               FROM payroll_employments WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Důvod slevy zaměstnavatele na pojistném (§ 7a odst. 1 zák. č. 589/1992 Sb.) do platné
     * verze podmínek, pokud ho ještě nemá. Jen důvod: nárok zakládá až přijatý záměr OZUSPOJ
     * (§ 7a odst. 5), který se z předchozího programu převezme zvlášť; bez něj výpočet slevu
     * neuplatní a kontrola převodu ho vyjmenuje ({@see PayrollTakeoverDiscountIntentCheck}).
     *
     * @return array<string,int>
     */
    public function partTimeDiscountReason(int $supplierId, int $employmentId, string $reason, string $evidence, ?int $userId, PayrollTakeoverPolicy $policy): array
    {
        $current = $this->employments->currentTerms($supplierId, $employmentId)
            ?? throw new \DomainException('pracovní vztah nemá verzi sjednaných podmínek.');
        $existing = (string) ($current['social_part_time_discount_reason'] ?? 'none');
        if ($existing !== '' && $existing !== 'none') {
            return $existing === $reason ? ['part_time_discount_existing' => 1] : ['part_time_discount_differs' => 1];
        }
        $this->correctTerms($supplierId, $employmentId, $current, [
            'social_part_time_discount_reason' => $reason,
            'social_part_time_discount_evidence' => mb_substr($evidence, 0, 190),
        ], $userId, $policy);

        return ['part_time_discount' => 1];
    }

    /**
     * Očekávané odmítnutí položky: konflikt verze, chybějící vztah nebo položka a zamítnutí
     * kontrolou (datum nástupu). Chyba databáze ani jiná RuntimeException mezi ně nepatří,
     * projde výš a převod ji ohlásí, místo aby položka tiše zůstala neodškrtnutá.
     */
    private static function checklistFailure(\Exception $e): bool
    {
        return $e instanceof PayrollEmploymentConflictException || $e instanceof PayrollEmploymentNotFoundException
            || $e instanceof \DomainException || $e instanceof \InvalidArgumentException;
    }

    /**
     * Oprava platné verze podmínek - stejná cesta jako karta vztahu (validátor, zámek verze).
     *
     * @param array<string,mixed> $current
     * @param array<string,mixed> $changes
     */
    private function correctTerms(int $supplierId, int $employmentId, array $current, array $changes, ?int $userId, PayrollTakeoverPolicy $policy): void
    {
        $body = RegistrationImportWriter::termsBody($current, 'Údaje převzaté z ' . $policy->label . '.');
        foreach ($changes as $field => $value) {
            $body[$field] = $value;
        }
        $body['effective_from'] = (string) $current['effective_from'];
        $row = $this->employmentById($supplierId, $employmentId)
            ?? throw new \DomainException('pracovní vztah ve firmě není.');
        $this->employments->correctTerms(
            $supplierId,
            $employmentId,
            $this->employmentValidator->terms(
                $body,
                $this->employments->currentCzIscoCode($supplierId, $employmentId),
                $this->employments->currentOtherWithholdingEligibility($supplierId, $employmentId),
                $this->employments->currentRelationType($supplierId, $employmentId),
            ),
            (int) $row['row_version'],
            $userId,
            null,
            null,
        );
    }

    /**
     * Složka základní měsíční mzdy i s platností v číselníku. Výchozí číselník se
     * firmě zakládá až při prvním čtení; převod do firmy, kde ještě nikdo mzdové
     * složky neotevřel, ho proto založí sám — jinak by předpis mzdy nevznikl.
     *
     * @return array<string,mixed>|null
     */
    private function monthlyWageComponent(int $supplierId): ?array
    {
        $this->components->ensureDefaults($supplierId);
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, valid_from, valid_to FROM payroll_component_definitions
              WHERE supplier_id = ? AND code = 'MZDA_MESICNI' AND is_active = 1
              ORDER BY id LIMIT 1"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** První den měsíce následujícího po uzavřeném období, nebo null u neznámého tvaru. */
    private static function nextMonth(string $period): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})/', $period, $match) !== 1) {
            return null;
        }
        return (new \DateTimeImmutable("{$match[1]}-{$match[2]}-01"))->modify('+1 month')->format('Y-m-d');
    }
}
