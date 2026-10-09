<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Jmhz;

use MyInvoice\Service\Payroll\Import\OpeningBalance\OpeningBalanceMonthValidator;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportLookup;
use MyInvoice\Service\Payroll\PayrollOpeningBalanceService;

/**
 * Počáteční stavy mzdových kumulací z hlášení předchozího programu.
 *
 * Firma, která přešla na MyÚčto v průběhu roku, potřebuje za měsíce před
 * prvním zpracovaným obdobím roční úhrny ({@see PayrollOpeningBalanceService}).
 * Měsíční hlášení je nese — a import je převezme jako ZRCADLO toho, co by do
 * kumulace zapsalo schválení vlastního běhu
 * ({@see \MyInvoice\Service\Payroll\Run\PayrollRunStatutoryAccumulatorApprover}):
 *
 * | pole kumulace                              | hlášení                                    |
 * |--------------------------------------------|--------------------------------------------|
 * | social_assessment_base_minor_units         | Σ 10477 `castkaOdvodPojistneho` přes formuláře osoby |
 * | advance_base_minor_units                   | Σ 10535 `prijem/dan/zakladDane` přes formuláře osoby (je-li `zalohaNaDan`) |
 * | advance_tax_minor_units                    | 10305 `danZalohaPoSleve`                    |
 * | tax_bonus_minor_units                      | 10306 `danBonus`                            |
 * | withholding_base / withholding_tax         | 10307 / 10309 `zvlastniSazbaDane`           |
 * | applied_child_credit_minor_units           | 10304 `slevaDite`                           |
 * | applied_non_refundable_credits_minor_units | 10298 − 10305 − 10304 (odvozeno, viz níže)  |
 * | bonus_qualifying_income_minor_units        | = advance_base (tak ho plní i schválení běhu) |
 *
 * Doložení odvozených polí:
 *  - Sociální základ: schválení běhu kumuluje `capped_assessment_base_minor_units`
 *    za OSOBU; výpočet ho po ročním maximu rozděluje mezi vztahy
 *    (`SocialInsuranceMonthCalculator::allocateCappedBase()`), takže součet
 *    10477 přes formuláře osoby je právě osobní úhrn.
 *  - Základ zálohy: schválení kumuluje `advance_tax.taxable_income_minor_units`,
 *    což je 10535; serializér ho píše po vztazích a jejich součet dává základ
 *    osoby (`JmhzScenario1XmlSerializer::income()`). Bez bloku `zalohaNaDan`
 *    (osoba jen se srážkovou daní) kumuluje schválení nulu — stejně tady.
 *  - Uplatněné nevratné slevy: `MonthlyEmploymentIncomeTaxCalculator` uplatní
 *    nejdřív slevy § 35ba (min(záloha, nárok)) a pak slevu na dítě ze zbytku;
 *    `MonthlyAdvanceTaxCalculator` počítá 10305 = max(0, max(0, 10298 − slevy)
 *    − zvýhodnění). Z toho plyne přesně 10298 − 10305 = uplatněné slevy
 *    § 35ba + uplatněná sleva na dítě (10304), v obou větvích (záloha vyšší
 *    i nižší než nárok). Pořadí uplatnění ukládá zákon (§ 35ba před § 35c),
 *    takže platí i pro hlášení cizího programu. Výsledek musí být mezi nulou
 *    a součtem nároků 10299–10302 — jinak je hlášení vnitřně rozporné a
 *    import počáteční stavy osoby zablokuje.
 *  - Příjem rozhodný pro bonus: schválení běhu ho plní základem zálohy.
 *
 * Chybějící částka v přítomném bloku je nula (XSD je má `minOccurs=0`
 * a ČSSZ chybějící částku čte jako nulu, viz komentář u
 * `JmhzScenario1DocumentResolver::socialBase()`).
 *
 * Měsíce se převezmou jen tehdy, když dávka pokrývá CELOU řadu od nástupu
 * (nebo ledna) do měsíce před prvním obdobím, které zpracovává MyÚčto —
 * počet dokončených měsíců je součástí kumulace a neúplná řada by ho zkreslila.
 */
final class JmhzOpeningBalancePlanner
{
    public function __construct(
        private readonly PayrollOpeningBalanceService $openings,
        private readonly RegistrationImportLookup $lookup,
        private readonly JmhzReportLookup $jmhzLookup,
    ) {}

    /**
     * @param list<array<string,mixed>> $plans plány formulářů hlášení (včetně interních klíčů)
     * @return list<array<string,mixed>>
     */
    public function preview(int $supplierId, array $plans, JmhzBatch $batch): array
    {
        return array_map(
            static fn (array $candidate): array => $candidate['public'],
            $this->candidates($supplierId, $plans, $batch),
        );
    }

    /**
     * @param list<array<string,mixed>> $plans
     * @return array{saved:int,skipped:list<array<string,mixed>>}
     */
    public function apply(int $supplierId, array $plans, JmhzBatch $batch, ?int $userId): array
    {
        $saved = 0;
        $skipped = [];
        foreach ($this->candidates($supplierId, $plans, $batch) as $candidate) {
            $public = $candidate['public'];
            if ($public['status'] !== 'ready') {
                $skipped[] = $this->skip($public, (string) $public['reason']);
                continue;
            }
            try {
                $this->openings->save(
                    $supplierId,
                    (int) $public['employee_id'],
                    (int) $public['year'],
                    $public['months'],
                    $candidate['source_reference'],
                    $userId,
                );
                $saved++;
            } catch (\InvalidArgumentException|\DomainException $e) {
                $skipped[] = $this->skip($public, $e->getMessage());
            }
        }

        return ['saved' => $saved, 'skipped' => $skipped];
    }

    /**
     * Řádek počátečního stavu za jeden měsíc z formulářů TÉŽE osoby.
     *
     * @param list<JmhzBatchItem> $items
     * @return array{row:?array<string,int>,reason:?string}
     */
    public static function monthRow(int $month, array $items): array
    {
        $period = $items[0]->period();
        $summaries = array_values(array_filter($items, static fn (JmhzBatchItem $item): bool => $item->form->hasSummary));
        if (count($summaries) !== 1) {
            return ['row' => null, 'reason' => $summaries === []
                ? "Hlášení za {$period} nenese souhrnná data zaměstnance (daň), počáteční stav za měsíc z něj nejde sestavit."
                : "Hlášení za {$period} nese souhrnná data zaměstnance na víc formulářích; nejde určit, který platí."];
        }
        $summary = $summaries[0]->form;
        $social = 0;
        $taxable = 0;
        $taxablePresent = false;
        foreach ($items as $item) {
            $social += $item->form->socialBase ?? 0;
            if ($item->form->taxableIncome !== null) {
                $taxable += $item->form->taxableIncome;
                $taxablePresent = true;
            }
        }
        $advanceBase = 0;
        $computed = 0;
        $afterCredits = 0;
        $bonus = 0;
        if ($summary->advance !== null) {
            $computed = $summary->advance['computed'] ?? 0;
            $afterCredits = $summary->advance['after_credits'] ?? 0;
            $bonus = $summary->advance['bonus'] ?? 0;
            if (!$taxablePresent && (($summary->advance['base'] ?? 0) > 0 || $computed > 0)) {
                return ['row' => null, 'reason' => "Hlášení za {$period} neuvádí základ daně po vztazích (10535), "
                    . 'ze kterého se kumuluje základ zálohy; počáteční stav nejde doložit.'];
            }
            // 10535 nese u vztahu se srážkovou daní základ srážky (DS 1.4.1.6,
            // Pokyny MH 1.4.14 kap. 3.4), do základu zálohy ale nepatří. Osoba
            // se zálohou i srážkou v témže měsíci proto základ zálohy dostane
            // z 10297; Σ 10535 ho musí doložit buď bez srážky (hlášení, které
            // srážku v 10535 nevykazovalo), nebo i se základem srážky 10307.
            $advanceBase = $taxable;
            $withholdingBase = $summary->withholding['base'] ?? 0;
            $declared = $summary->advance['base'] ?? null;
            if ($withholdingBase > 0 && is_int($declared) && $taxable !== $declared) {
                if ($taxable - $withholdingBase !== $declared) {
                    return ['row' => null, 'reason' => "Hlášení za {$period} nese zálohu i srážkovou daň a součet základů "
                        . 'po vztazích (10535) neodpovídá základu zálohy (10297) ani se základem srážky (10307); '
                        . 'počáteční stav nejde doložit.'];
                }
                $advanceBase = $declared;
            }
        }
        $appliedChild = $summary->childCredit['applied'] ?? 0;
        $appliedCredits = $computed - $afterCredits - $appliedChild;
        $claimedCredits = array_sum($summary->credits);
        if ($appliedCredits < 0) {
            return ['row' => null, 'reason' => "V hlášení za {$period} nesedí záloha: vypočtená záloha (10298) je nižší "
                . 'než sražená záloha (10305) a uplatněná sleva na dítě (10304) dohromady.'];
        }
        if ($appliedCredits > $claimedCredits) {
            return ['row' => null, 'reason' => "V hlášení za {$period} vychází uplatněné slevy na dani "
                . "(10298 − 10305 − 10304 = {$appliedCredits} Kč) vyšší než slevy v prohlášení ({$claimedCredits} Kč)."];
        }

        $row = [
            'month' => $month,
            'social_assessment_base_minor_units' => $social * 100,
            'advance_base_minor_units' => $advanceBase * 100,
            'advance_tax_minor_units' => $afterCredits * 100,
            'withholding_base_minor_units' => ($summary->withholding['base'] ?? 0) * 100,
            'withholding_tax_minor_units' => ($summary->withholding['tax'] ?? 0) * 100,
            'applied_non_refundable_credits_minor_units' => $appliedCredits * 100,
            'applied_child_credit_minor_units' => $appliedChild * 100,
            'tax_bonus_minor_units' => $bonus * 100,
            'bonus_qualifying_income_minor_units' => $advanceBase * 100,
        ];
        /*
         * Společná věcná kontrola měsíce — táž, kterou projde ruční mřížka
         * i tabulkový import. Bez ní tudy prošlo hlášení s daní bez základu:
         * zápis by ho odmítl až v `save()`, takže by náhled sliboval převzetí,
         * které apply zahodí.
         */
        $reason = OpeningBalanceMonthValidator::reject($row);
        if ($reason !== null) {
            return ['row' => null, 'reason' => "Hlášení za {$period}: " . $reason];
        }

        return ['row' => $row, 'reason' => null];
    }

    /**
     * @param list<array<string,mixed>> $plans
     * @return list<array{public:array<string,mixed>,source_reference:string}>
     */
    private function candidates(int $supplierId, array $plans, JmhzBatch $batch): array
    {
        $byEmployee = [];
        $unresolved = [];
        foreach ($plans as $plan) {
            if (($plan['_effective'] ?? false) !== true) {
                continue;
            }
            /** @var JmhzBatchItem $item */
            $item = $plan['_item'];
            if ($plan['blocker'] !== null || $plan['_employee_id'] === null) {
                if ($item->form->personIdentifier !== null) {
                    $unresolved[$item->period() . '|' . $item->form->personIdentifier] = true;
                }
                continue;
            }
            $byEmployee[(int) $plan['_employee_id']][$item->file->year][$item->file->month][] = $item;
        }

        $result = [];
        foreach ($byEmployee as $employeeId => $years) {
            ksort($years);
            foreach ($years as $year => $months) {
                ksort($months);
                $candidate = $this->candidate($supplierId, $employeeId, $year, $months, $unresolved, $batch);
                if ($candidate !== null) {
                    $result[] = $candidate;
                }
            }
        }

        return $result;
    }

    /**
     * @param array<int,list<JmhzBatchItem>> $months
     * @param array<string,true> $unresolved
     * @return array{public:array<string,mixed>,source_reference:string}|null
     */
    private function candidate(
        int $supplierId,
        int $employeeId,
        int $year,
        array $months,
        array $unresolved,
        JmhzBatch $batch,
    ): ?array {
        $public = [
            'employee_id' => $employeeId,
            'employee_name' => $this->lookup->employeeName($supplierId, $employeeId) ?? ('Osoba #' . $employeeId),
            'year' => $year,
            'months' => [],
            'status' => 'blocked',
            'reason' => null,
        ];
        $boundary = $this->boundary($supplierId, $year);
        if ($boundary === null) {
            return ['public' => ['reason' => "Není jasné, od kterého měsíce vede mzdy MyÚčto: firma nemá nastavený začátek "
                . "vedení mezd ani mzdový běh za rok {$year}. Počáteční stavy se převezmou, až to bude jasné."] + $public,
                'source_reference' => ''];
        }
        $contradiction = $this->boundaryContradiction($supplierId, $year, $months);
        if ($contradiction !== null) {
            return ['public' => ['reason' => $contradiction] + $public, 'source_reference' => ''];
        }
        if ($boundary < sprintf('%04d-01-01', $year) || $boundary > sprintf('%04d-12-31', $year)) {
            return null;
        }
        $lastMonth = (int) substr($boundary, 5, 2) - 1;
        if ($lastMonth < 1 || array_key_first($months) > $lastMonth) {
            return null;
        }
        [$firstMonth, $endMonth, $reason] = $this->span($supplierId, $employeeId, $year);
        if ($reason !== null) {
            return ['public' => ['reason' => $reason] + $public, 'source_reference' => ''];
        }
        $lastMonth = min($lastMonth, $endMonth);
        if ($firstMonth > $lastMonth) {
            return null;
        }
        $missing = [];
        for ($month = $firstMonth; $month <= $lastMonth; $month++) {
            if (!isset($months[$month])) {
                $missing[] = sprintf('%04d-%02d', $year, $month);
            }
        }
        if ($missing !== []) {
            return ['public' => ['reason' => 'Počáteční stavy se převezmou jen za celou řadu měsíců před prvním '
                . 'obdobím v MyÚčtu. V dávce chybí hlášení za: ' . implode(', ', $missing) . '.'] + $public,
                'source_reference' => ''];
        }

        $rows = [];
        $files = [];
        for ($month = $firstMonth; $month <= $lastMonth; $month++) {
            foreach ($months[$month] as $item) {
                $files[substr($item->fileSha256, 0, 16)] = true;
                if (!$batch->packageComplete($item)) {
                    return ['public' => ['reason' => "Hlášení za {$item->period()} je rozdělené do víc balíků a v dávce "
                        . 'nejsou všechny; nejde doložit, že formuláře osoby jsou úplné.'] + $public, 'source_reference' => ''];
                }
                if ($item->form->personIdentifier !== null
                    && isset($unresolved[$item->period() . '|' . $item->form->personIdentifier])
                ) {
                    return ['public' => ['reason' => "Za {$item->period()} má osoba v dávce formulář, který se nepodařilo "
                        . 'spárovat nebo je zablokovaný; bez něj by počáteční stav nebyl úplný.'] + $public,
                        'source_reference' => ''];
                }
            }
            $computed = self::monthRow($month, $months[$month]);
            if ($computed['reason'] !== null) {
                return ['public' => ['reason' => $computed['reason']] + $public, 'source_reference' => ''];
            }
            $rows[] = $computed['row'];
        }
        $public['months'] = $rows;
        // Zámek počátečních stavů má jediný zdroj pravdy; kdyby si ho planner
        // držel vlastní („schválená mzda"), rozešel by se se zápisem a náhled
        // by blokoval i to, co `save()` pustí.
        $lockReason = $this->openings->lockReason($supplierId, $employeeId, $year);
        if ($lockReason !== null) {
            return ['public' => ['reason' => $lockReason] + $public, 'source_reference' => ''];
        }
        $current = $this->openings->current($supplierId, $employeeId, $year);
        if ($current['months'] == $rows) {
            return ['public' => ['status' => 'unchanged', 'reason' => 'Počáteční stavy v evidenci už odpovídají hlášení.'] + $public,
                'source_reference' => ''];
        }
        $reference = 'jmhz-import:' . implode(',', array_keys($files));
        if (strlen($reference) > 190) {
            $reference = 'jmhz-import:batch:' . hash('sha256', implode(',', array_keys($files)));
        }

        return ['public' => ['status' => 'ready'] + $public, 'source_reference' => $reference];
    }

    /**
     * Nastavení tvrdí, že první měsíc dávky už vedlo MyÚčto, ale běh za něj
     * v MyÚčtu není a hlášení přišlo z předchozího programu. Dřív to planner
     * tiše zahodil a založení běhu pak podle začátku vedení mezd zapsalo
     * všem nulové roční úhrny ({@see PayrollOpeningBalanceService::seedProvableZeroOpenings()}),
     * což zkreslí strop sociálního pojištění, bonus i roční zúčtování.
     *
     * @param array<int,list<JmhzBatchItem>> $months
     */
    private function boundaryContradiction(int $supplierId, int $year, array $months): ?string
    {
        $moduleStart = $this->jmhzLookup->moduleStartPeriod($supplierId);
        $firstRun = $this->jmhzLookup->firstRunPeriod($supplierId, $year);
        $firstMonth = sprintf('%04d-%02d-01', $year, (int) array_key_first($months));
        if ($moduleStart === null || $moduleStart > $firstMonth || ($firstRun !== null && $firstRun <= $firstMonth)) {
            return null;
        }
        $lastMonth = sprintf('%04d-%02d', $year, (int) array_key_last($months));

        return 'Začátek vedení mezd v MyÚčtu je nastavený na ' . substr($moduleStart, 0, 7)
            . ', ale mzdový běh za ' . substr($firstMonth, 0, 7) . ' v MyÚčtu není a hlášení za něj pochází '
            . 'z předchozího programu. Pokud měsíce do ' . $lastMonth . ' zpracoval předchozí program, posuňte '
            . 'začátek vedení mezd na první měsíc vedený v MyÚčtu. Jinak založení běhu zapíše nulové roční úhrny.';
    }

    /**
     * První období, které zpracovává MyÚčto: dřívější z aktivace mzdového
     * modulu a prvního mzdového běhu v roce.
     */
    private function boundary(int $supplierId, int $year): ?string
    {
        $candidates = array_filter([
            $this->jmhzLookup->firstRunPeriod($supplierId, $year),
            $this->jmhzLookup->moduleStartPeriod($supplierId),
        ], static fn (?string $value): bool => $value !== null);

        return $candidates === [] ? null : substr(min($candidates), 0, 8) . '01';
    }

    /**
     * První a poslední měsíc roku, ve kterém měl zaměstnanec u firmy vztah.
     *
     * @return array{0:int,1:int,2:?string}
     */
    private function span(int $supplierId, int $employeeId, int $year): array
    {
        $start = null;
        $end = null;
        $open = false;
        foreach ($this->lookup->employments($supplierId, $employeeId) as $row) {
            if (in_array($row['status'], ['archived', 'no_show'], true)) {
                continue;
            }
            $from = $row['actual_start_date'] ?? $row['start_date'];
            if (is_string($from) && ($start === null || $from < $start)) {
                $start = $from;
            }
            if ($row['end_date'] === null) {
                $open = true;
            } elseif ($end === null || $row['end_date'] > $end) {
                $end = $row['end_date'];
            }
        }
        if ($start === null) {
            return [1, 12, 'Zaměstnanec nemá v evidenci datum nástupu, počáteční stavy nejde časově zařadit.'];
        }
        $firstMonth = (int) substr($start, 0, 4) < $year ? 1 : (int) substr($start, 5, 2);
        if ((int) substr($start, 0, 4) > $year) {
            $firstMonth = 13;
        }
        $endMonth = 12;
        if (!$open && $end !== null && (int) substr($end, 0, 4) === $year) {
            $endMonth = (int) substr($end, 5, 2);
        } elseif (!$open && $end !== null && (int) substr($end, 0, 4) < $year) {
            $endMonth = 0;
        }

        return [$firstMonth, $endMonth, null];
    }

    /**
     * @param array<string,mixed> $public
     * @return array<string,mixed>
     */
    private function skip(array $public, string $reason): array
    {
        return [
            'employee_id' => $public['employee_id'],
            'employee_name' => $public['employee_name'],
            'year' => $public['year'],
            'reason' => $reason,
        ];
    }
}
