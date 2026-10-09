<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxAdvanceOverrideRepository;
use MyInvoice\Repository\TaxAdvanceScheduleRepository;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Report\CzechWorkingDays;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;

/**
 * Správa předpisů záloh na daň a pojistné (E9, audit 2026-07).
 *
 * Z finalizovaného přiznání vygeneruje předpisy záloh na PŘÍŠTÍ rok:
 *   - DPPO daň §38a (pololetní 30–150 tis. / čtvrtletní > 150 tis., splatné 15. den),
 *   - OSVČ (FO) nové měsíční zálohy sociální a zdravotní.
 * Předpisy pak páruje s odchozími bankovními pohyby podle variabilního symbolu
 * (VS = DIČ / VS ČSSZ / číslo pojištěnce) — reuse vzoru StatementMatcher (VS + účet
 * supplera přes AccountNumberNormalizer, žádný samostatný sloupec supplier_id na
 * bank_transactions neexistuje). Spárované úhrady se sečtou a předvyplní do ř. 85/360
 * příštího přiznání resp. přehledu pojistného.
 *
 * DPFO respektuje zvláštní krácení podle podílu příjmů §6; hospodářský
 * rok u DPPO používá kalendářní splatnosti 15. 3./6./9./12.; QR platba se nepřidává
 * (v systému neexistuje sdílený QR generátor k reuse).
 *
 * ZNÁMÁ MEZERA (adversariální review 2026-07, nález F2) — UZAVŘENO 2026-07 (párování dle
 * účtu FÚ): u druhu `tax` je očekávaný VS kmenová část DIČ (≈ IČO), který ale banka nese na
 * VŠECH platbách FÚ (DPPO, DPH, srážková daň, závislá činnost) — sám o sobě druh daně
 * NEROZLIŠÍ. Spolehlivý signál je PŘEDČÍSLÍ protiúčtu (`bank_transactions.counterparty_account`),
 * které kóduje typ daně dle číselníku berních účtů FÚ: 7704 = daň z příjmů PO (DPPO),
 * 721 = daň z příjmů FO podávajících přiznání (DPFO), 705 = DPH, 7720 = daň vybíraná srážkou,
 * 713 = daň ze závislé činnosti. `pickTransaction()` proto u daně §38a — je-li protiúčet
 * evidovaný — VYŽADUJE shodu předčíslí s očekávaným typem (DPPO/DPFO) a jinou daň i cizí
 * protistranu se stejným VS = DIČ vyloučí. Když protiúčet chybí (starší importy bez
 * counterparty_account), spadne zpět na původní ochranu úzkým oknem + tolerančním pásmem
 * částky. Doplatek daně (stejné předčíslí 7704, ale výrazně vyšší jednorázová částka poblíž
 * lhůty podání) se od zálohy odliší tolerančním pásmem částky ({@see amountMatches()}) —
 * padne jen jako NÁVRH k ověření, ne jako tichá záloha.
 */
final class TaxAdvanceScheduleService
{
    public function __construct(
        private readonly TaxAdvanceScheduleRepository $schedules,
        private readonly InsuranceSummaryService $insurance,
        private readonly Connection $db,
        private readonly TaxConstantsRepository $constants,
        private readonly TaxAdvanceOverrideRepository $overrides,
    ) {}

    /**
     * Okno párování kolem splatnosti — OSVČ měsíční zálohy (VS = VS ČSSZ / číslo
     * pojištěnce, kolize s vlastním identifikátorem je nepravděpodobná): širší okno,
     * protože platby často chodí s předstihem / mírným zpožděním.
     */
    private const DUE_WINDOW_BEFORE_DAYS = 20;
    private const DUE_WINDOW_AFTER_DAYS = 40;

    /**
     * Okno párování pro daň §38a (VS = DIČ/IČO — viz „ZNÁMÁ MEZERA" v docbloku třídy):
     * úzké okno kolem PŘESNÉHO 15. dne splatnosti, aby se snížila pravděpodobnost, že
     * se omylem spáruje nesouvisející odchozí platba nesoucí náhodou stejný VS.
     */
    private const TAX_DUE_WINDOW_BEFORE_DAYS = 5;
    private const TAX_DUE_WINDOW_AFTER_DAYS = 12;

    /**
     * Tolerance shody částky (F1): odchozí platba se považuje za „shodnou s předpisem",
     * když se od částky předpisu neliší o víc než tuto absolutní částku. Shoda částky
     * má PŘI VÝBĚRU KANDIDÁTA přednost před časovou blízkostí ke splatnosti — jinak by
     * jiná odchozí platba se STEJNÝM VS, ale JINOU částkou, blíž splatnosti „ukradla"
     * párování správné transakci a doplatek by vyšel podhodnocený/nadhodnocený.
     */
    private const AMOUNT_MATCH_TOLERANCE = 1.00;

    /**
     * Relativní tolerance shody částky pro daň §38a (audit 2026-07). Predikce zálohy je
     * jen ODHAD — počítá se z aktuálně finalizovaného přiznání, kdežto reálné zálohy byly
     * stanoveny z DŘÍVĚJŠÍ známé daňové povinnosti, takže se legitimně liší (zde +20 %:
     * predikce 150 000 vs reálná záloha 180 000). Absolutní koruna ({@see AMOUNT_MATCH_TOLERANCE})
     * by se proto NIKDY netrefila a matcher by spadl do amount-blind fallbacku, který u
     * daně (VS = DIČ nese i doplatky a DPH) spáruje cizí platbu. Místo toho: úhrada je
     * „jistá záloha" jen když se od predikce neliší o víc než toto pásmo; jinak se
     * NEspáruje (viz {@see pickTransaction()}). Pásmo je záměrně TĚSNÉ a bez násobků —
     * doplatek daně (≈ 4× čtvrtletní záloha) ani cizí platba (41 250 vs 150 000) se do něj
     * nevejdou; hraniční legitimní zálohy raději spadnou do návrhu k ručnímu potvrzení
     * (bezpečné) než k tichému nadhodnocení (nebezpečné).
     */
    private const TAX_AMOUNT_MATCH_RATIO = 0.30;

    /**
     * Předčíslí berních účtů FÚ (číselník daní), kterými banka rozlišuje typ daně na
     * odchozí platbě (protiúčet `counterparty_account`). Pro zálohy §38a nás zajímá jen
     * daň z příjmů: 7704 = právnické osoby (DPPO), 721 = fyzické osoby podávající přiznání
     * (DPFO). Ostatní (705 DPH, 7720 srážková, 713 závislá činnost) nesou stejný VS = DIČ,
     * ale na zálohu na daň z příjmů NEpatří — předčíslí je odfiltruje.
     */
    private const FU_PREFIX_DPPO = '7704';
    private const FU_PREFIX_DPFO = '721';

    /**
     * Vygeneruje předpisy záloh z finalizovaného přiznání za rok $year na období $year+1.
     * PO: daň §38a z ř. 340 (next_advances). FO: měsíční soc./zdrav. z přehledu pojistného.
     *
     * @param array<string,mixed> $computationResult výstup kalkulátoru (u PO nese next_advances)
     * @return array<string,int> počet vygenerovaných předpisů per druh
     */
    public function generateFromReturn(int $supplierId, int $year, string $type, ?int $sourceReturnId, array $computationResult): array
    {
        $periodYear = $year + 1;
        $ids = $this->supplierPaymentIdentifiers($supplierId);
        $out = ['tax' => 0, 'social' => 0, 'health' => 0];

        if ($type === 'po') {
            // #43/#42/#46: rozhodnutí FÚ (§174) má přednost před predikcí a žije nezávisle
            // na finalizaci přiznání. S rozsahem OD-DO může rok protínat víc rozhodnutí —
            // každé řídí svůj úsek, zbytek roku zůstává na predikci (§38a).
            $overrides = $this->overrides->intersectingYear($supplierId, 'po', 'tax', $periodYear);
            $rows = $this->buildTaxRows($computationResult, $periodYear, $ids['tax_vs'], $overrides);
            $this->schedules->replacePlanned($supplierId, 'po', 'tax', $periodYear, $rows, $sourceReturnId);
            $out['tax'] = count($rows);
            return $out;
        }

        $taxOverrides = $this->overrides->intersectingYear($supplierId, 'fo', 'tax', $periodYear);
        $taxRows = $this->buildTaxRows($computationResult, $periodYear, $ids['tax_vs'], $taxOverrides);
        $this->schedules->replacePlanned($supplierId, 'fo', 'tax', $periodYear, $taxRows, $sourceReturnId);
        $out['tax'] = count($taxRows);

        // FO — měsíční zálohy sociální a zdravotní z přehledu pojistného (stejný zdroj jako přehledy).
        $summary = $this->insurance->build($supplierId, $year);
        $socialMonthly = round((float) ($summary['social']['monthly_advance'] ?? 0), 2);
        $healthMonthly = round((float) ($summary['health']['monthly_advance'] ?? 0), 2);
        $effectiveMonth = $this->insuranceAdvanceEffectiveMonth($sourceReturnId);
        $previousSocial = $this->previousMonthlyAmount($supplierId, 'social', $periodYear - 1);
        $previousHealth = $this->previousMonthlyAmount($supplierId, 'health', $periodYear - 1);
        $socialMinimum = TaxFormAmount::ceilKc((float) ($summary['social']['min_base'] ?? 0) / 12 * (float) ($summary['rates']['social'] ?? 0.292));
        $healthMinimum = TaxFormAmount::ceilKc((float) ($summary['health']['min_base'] ?? 0) / 12 * (float) ($summary['rates']['health'] ?? 0.135));

        $socialRows = $this->buildMonthlyRows($periodYear, $socialMonthly, $ids['social_vs'], 'social', $effectiveMonth, $previousSocial, $socialMinimum);
        $healthRows = $this->buildMonthlyRows($periodYear, $healthMonthly, $ids['health_vs'], 'health', $effectiveMonth, $previousHealth, $healthMinimum);
        $this->schedules->replacePlanned($supplierId, 'fo', 'social', $periodYear, $socialRows, $sourceReturnId);
        $this->schedules->replacePlanned($supplierId, 'fo', 'health', $periodYear, $healthRows, $sourceReturnId);
        $out['social'] = count($socialRows);
        $out['health'] = count($healthRows);
        return $out;
    }

    /**
     * Předpisy §38a daně za kalendářní rok $periodYear — MERGE predikce (§38a) a rozhodnutí
     * FÚ (§174 DŘ) s rozsahem OD-DO (#43/#42/#46):
     *   - splatnost UVNITŘ rozsahu [effective_from, effective_to] některého rozhodnutí →
     *     výše + periodicita dle rozhodnutí ({@see buildTaxRowsFromOverride()});
     *   - splatnost MIMO všechny rozsahy → predikce z přiznání ({@see buildPredictedTaxRows()});
     *   - ROZHODNUTÍ VŽDY VYHRÁVÁ nad predikcí ve svém rozsahu (i „žádné zálohy" = FÚ zrušil
     *     zálohy pro úsek → predikce v úseku potlačena, žádný předpis nevznikne).
     *
     * PŘEKRYV rozhodnutí (obrana): rozsahy se při zápisu validují proti překryvu
     * ({@see assertNoOverlap()}), ale pro jistotu i tady NOVĚJŠÍ (pozdější effective_from)
     * VYHRÁVÁ na sdílené splatnosti — generování tak nikdy nezdvojí předpis.
     *
     * Proti výsledné (override/predikční) částce na předpisu se pak páruje
     * ({@see pickTransaction()}): reálná (snížená) záloha se napáruje jako 'exact', doplatek
     * daně / cizí platba se stejným VS = DIČ padnou mimo pásmo.
     *
     * @param array<string,mixed> $result
     * @param list<array<string,mixed>> $overrides rozhodnutí protínající rok (intersectingYear)
     * @return list<array{seq_no:int,amount:float,due_date:string,variable_symbol:?string}>
     */
    private function buildTaxRows(array $result, int $periodYear, ?string $vs, array $overrides = []): array
    {
        // Rozsahy účinnosti (pro potlačení predikce v úseku řízeném rozhodnutím). Prázdné
        // effective_from = -∞, NULL effective_to = +∞.
        $ranges = [];
        foreach ($overrides as $o) {
            $ranges[] = [
                (string) ($o['effective_from'] ?? ''),
                isset($o['effective_to']) && $o['effective_to'] !== null && $o['effective_to'] !== '' ? (string) $o['effective_to'] : null,
            ];
        }

        // Předpisy z rozhodnutí (klíč = splatnost). Při překryvu vyhrává pozdější effective_from.
        $byDue = [];
        foreach ($overrides as $o) {
            $eff = (string) ($o['effective_from'] ?? '');
            foreach ($this->buildTaxRowsFromOverride($periodYear, $vs, $o) as $r) {
                $due = (string) $r['due_date'];
                if (!isset($byDue[$due]) || $eff >= $byDue[$due]['eff']) {
                    $byDue[$due] = ['amount' => $r['amount'], 'eff' => $eff];
                }
            }
        }

        $amounts = [];
        foreach ($byDue as $due => $info) {
            $amounts[$due] = $info['amount'];
        }
        // Predikce jen tam, kde ji žádné rozhodnutí nepřebíjí (mimo všechny rozsahy).
        foreach ($this->buildPredictedTaxRows($result, $periodYear) as $r) {
            $due = (string) $r['due_date'];
            if (isset($amounts[$due]) || $this->dateCoveredByRanges($due, $ranges)) {
                continue;
            }
            $amounts[$due] = round((float) $r['amount'], 2);
        }

        ksort($amounts);
        $rows = [];
        $seq = 0;
        foreach ($amounts as $due => $amount) {
            $rows[] = [
                'seq_no' => ++$seq,
                'amount' => $amount,
                'due_date' => (string) $due,
                'variable_symbol' => $vs,
            ];
        }
        return $rows;
    }

    /**
     * Predikované předpisy §38a z přiznání (bez rozhodnutí FÚ) — regime semiannual/quarterly/
     * none z DppoReturnCalculator, splatnosti 15. den, posun o lhůtu podání (filing_deadline).
     * Vrací kandidáty vč. případného přetečení do roku Y+1 (jako dřív) — merge/párování si
     * pak úseky pořeší dle rozsahů rozhodnutí a F3 kalendářního roku.
     *
     * @param array<string,mixed> $result
     * @return list<array{due_date:string,amount:float}>
     */
    private function buildPredictedTaxRows(array $result, int $periodYear): array
    {
        $c = $this->constants->forYear($periodYear);
        $deadlines = (array) ($c['filing_deadlines'] ?? []);
        $dueDay = (int) ($deadlines['tax_advance_day'] ?? 15);
        $next = (array) ($result['next_advances'] ?? []);
        $regime = (string) ($next['regime'] ?? 'none');
        $predAmount = round((float) ($next['amount'] ?? 0), 2);

        if ($regime === 'none' || $predAmount <= 0) {
            return [];
        }

        $paperDeadline = (string) ($deadlines['dpfo_paper'] ?? '04-01');
        $filingDeadline = (string) ($next['filing_deadline']
            ?? CzechWorkingDays::deadlineFromMonthDay($periodYear, $paperDeadline));
        $months = $regime === 'semiannual'
            ? (array) ($c['advance_semiannual_months'] ?? [6, 12])
            : (array) ($c['advance_quarterly_months'] ?? [3, 6, 9, 12]);
        $candidates = [];
        foreach ([$periodYear, $periodYear + 1] as $candidateYear) {
            foreach ($months as $month) {
                $candidates[] = sprintf('%04d-%02d-%02d', $candidateYear, (int) $month, $dueDay);
            }
        }
        $count = $regime === 'semiannual' ? 2 : 4;
        $dueDates = array_slice(
            array_values(array_filter($candidates, static fn (string $date): bool => $date > $filingDeadline)),
            0,
            $count
        );
        $rows = [];
        foreach ($dueDates as $dueDate) {
            $rows[] = ['due_date' => $dueDate, 'amount' => $predAmount];
        }
        return $rows;
    }

    /**
     * Předpisy §38a řízené jedním rozhodnutím FÚ (§174 DŘ) / ručním override — zdroj pravdy
     * pro výši, periodicitu i ROZSAH zálohového období [effective_from, effective_to].
     * Emituje splatnosti kalendářního roku $periodYear UVNITŘ rozsahu:
     *   - `effective_from` = začátek období (den po lhůtě podání předchozího přiznání; u
     *     poradce 1. 7.) → žádná fantomová záloha před ním;
     *   - `effective_to` (#46) = konec období (včetně) → splatnost po něm už spadá zpět na
     *     predikci (řeší merge v {@see buildTaxRows()}); NULL = otevřený konec;
     *   - jen kalendářní rok $periodYear → záloha splatná v roce Y+1 patří do bucketu roku
     *     Y+1, ne sem (konzistentní s F3 v {@see pickTransaction()}).
     *
     * @param array<string,mixed> $override tax_advance_overrides řádek
     * @return list<array{seq_no:int,amount:float,due_date:string,variable_symbol:?string}>
     */
    private function buildTaxRowsFromOverride(int $periodYear, ?string $vs, array $override): array
    {
        $amount = round((float) ($override['amount'] ?? 0), 2);
        $periodicity = (string) ($override['periodicity'] ?? 'none');
        if ($amount <= 0 || $periodicity === 'none') {
            return [];
        }
        $c = $this->constants->forYear($periodYear);
        $deadlines = (array) ($c['filing_deadlines'] ?? []);
        $dueDay = (int) ($deadlines['tax_advance_day'] ?? 15);
        $months = match ($periodicity) {
            'semiannual' => (array) ($c['advance_semiannual_months'] ?? [6, 12]),
            'annual' => [12],
            default => (array) ($c['advance_quarterly_months'] ?? [3, 6, 9, 12]),
        };
        $effectiveFrom = (string) ($override['effective_from'] ?? '');
        $effectiveTo = isset($override['effective_to']) && $override['effective_to'] !== null ? (string) $override['effective_to'] : '';
        $rows = [];
        $seq = 0;
        foreach ($months as $month) {
            $dueDate = sprintf('%04d-%02d-%02d', $periodYear, (int) $month, $dueDay);
            if ($effectiveFrom !== '' && $dueDate < $effectiveFrom) {
                continue;
            }
            if ($effectiveTo !== '' && $dueDate > $effectiveTo) {
                continue;
            }
            $rows[] = [
                'seq_no' => ++$seq,
                'amount' => $amount,
                'due_date' => $dueDate,
                'variable_symbol' => $vs,
            ];
        }
        return $rows;
    }

    /**
     * Leží splatnost $date uvnitř aspoň jednoho rozsahu [from, to]? Prázdné from = -∞,
     * NULL to = +∞. @param list<array{0:string,1:?string}> $ranges
     */
    private function dateCoveredByRanges(string $date, array $ranges): bool
    {
        foreach ($ranges as [$from, $to]) {
            if ($from !== '' && $date < $from) {
                continue;
            }
            if ($to !== null && $to !== '' && $date > $to) {
                continue;
            }
            return true;
        }
        return false;
    }

    /**
     * 12 měsíčních předpisů. Sociální: splatná do konce kalendářního měsíce, za který se
     * platí. Zdravotní: splatná do 8. dne NÁSLEDUJÍCÍHO měsíce.
     *
     * @return list<array{seq_no:int,amount:float,due_date:string,variable_symbol:?string}>
     */
    private function buildMonthlyRows(
        int $periodYear,
        float $monthly,
        ?string $vs,
        string $kind,
        int $effectiveMonth = 1,
        ?float $previousMonthly = null,
        float $statutoryMinimum = 0.0,
    ): array
    {
        if ($monthly <= 0) {
            return [];
        }
        $rows = [];
        $deadlines = (array) ($this->constants->forYear($periodYear)['filing_deadlines'] ?? []);
        $healthDueDay = (int) ($deadlines['health_advance_day'] ?? 8);
        for ($m = 1; $m <= 12; $m++) {
            $amount = $monthly;
            if ($m < $effectiveMonth && $previousMonthly !== null) {
                $amount = max($previousMonthly, $statutoryMinimum);
            }
            if ($kind === 'health') {
                // do 8. dne následujícího měsíce (prosinec → 8. 1. dalšího roku)
                $dueMonth = $m === 12 ? 1 : $m + 1;
                $dueYear = $m === 12 ? $periodYear + 1 : $periodYear;
                $due = sprintf('%04d-%02d-%02d', $dueYear, $dueMonth, $healthDueDay);
            } else {
                // sociální: poslední den měsíce, za který se platí
                $due = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $periodYear, $m)))
                    ->modify('last day of this month')->format('Y-m-d');
            }
            $rows[] = ['seq_no' => $m, 'amount' => $amount, 'due_date' => $due, 'variable_symbol' => $vs];
        }
        return $rows;
    }

    private function insuranceAdvanceEffectiveMonth(?int $sourceReturnId): int
    {
        if ($sourceReturnId === null) {
            return (int) (new \DateTimeImmutable('today'))->format('n');
        }
        $stmt = $this->db->pdo()->prepare('SELECT updated_at FROM income_tax_returns WHERE id = ?');
        $stmt->execute([$sourceReturnId]);
        $updatedAt = $stmt->fetchColumn();
        if (!is_string($updatedAt) || $updatedAt === '') {
            return (int) (new \DateTimeImmutable('today'))->format('n');
        }
        return (int) (new \DateTimeImmutable($updatedAt))->format('n');
    }

    private function previousMonthlyAmount(int $supplierId, string $kind, int $periodYear): ?float
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT amount FROM tax_advance_schedules
              WHERE supplier_id = ? AND taxpayer_type = 'fo' AND advance_kind = ? AND period_year = ?
              ORDER BY seq_no DESC LIMIT 1"
        );
        $stmt->execute([$supplierId, $kind, $periodYear]);
        $amount = $stmt->fetchColumn();
        return $amount === false ? null : round((float) $amount, 2);
    }

    /**
     * Spáruje odchozí bankovní platby s naplánovanými předpisy za rok $periodYear a vrátí
     * součty zaplacených záloh per druh (pro předvyplnění přiznání/přehledu). FIFO dle
     * splatnosti; platba se přiřadí předpisu se shodným VS v okně kolem splatnosti.
     *
     * `totals` = JEN jisté (exact) shody, bezpečné k automatickému předvyplnění; nejisté
     * (nesedící částka u pojistného) jsou v `totals_uncertain`. `suggested` = počet daňových
     * návrhů, které se ZÁMĚRNĚ nespárovaly (VS = DIČ sedí, ale částka neodpovídá predikci)
     * a čekají na ruční potvrzení — jejich předpis zůstává 'planned' a do součtů nevstupují.
     *
     * @return array{matched:int,suggested:int,totals:array{tax:float,social:float,health:float},totals_uncertain:array{tax:float,social:float,health:float},details:list<array<string,mixed>>}
     */
    public function matchPayments(int $supplierId, int $periodYear, string $type): array
    {
        $ids = $this->supplierPaymentIdentifiers($supplierId);
        $kinds = $type === 'po'
            ? ['tax' => $ids['tax_vs']]
            : ['tax' => $ids['tax_vs'], 'social' => $ids['social_vs'], 'health' => $ids['health_vs']];
        // Očekávané předčíslí berního účtu FÚ pro daň z příjmů (párování dle účtu FÚ):
        // DPPO 7704 (po) / DPFO 721 (fo). U pojistného se předčíslí nekontroluje (jiná
        // protistrana ČSSZ/ZP a unikátní VS).
        $expectedTaxPrefix = $type === 'po' ? self::FU_PREFIX_DPPO : self::FU_PREFIX_DPFO;

        $candidates = $this->outgoingCandidates($supplierId, $periodYear);
        $usedTxIds = array_fill_keys($this->schedules->matchedTransactionIds($supplierId), true);
        $matched = 0;
        $suggested = 0;
        $details = [];

        foreach ($kinds as $kind => $expectedVs) {
            if ($expectedVs === null || $expectedVs === '') {
                continue;
            }
            $taxPrefix = $kind === 'tax' ? $expectedTaxPrefix : null;
            $planned = $this->schedules->plannedForMatching($supplierId, $type, $kind, $periodYear);
            foreach ($planned as $schedule) {
                $pick = $this->pickTransaction($candidates, $usedTxIds, $expectedVs, (string) $schedule['due_date'], (float) $schedule['amount'], $kind, $periodYear, $taxPrefix);
                if ($pick === null) {
                    continue;
                }
                $best = $pick['tx'];
                $paid = round(abs((float) $best['amount']), 2);
                $detail = [
                    'schedule_id' => (int) $schedule['id'],
                    'kind' => $kind,
                    'due_date' => (string) $schedule['due_date'],
                    'expected_amount' => round((float) $schedule['amount'], 2),
                    'paid_amount' => $paid,
                    'paid_on' => (string) $best['posted_at'],
                    'transaction_id' => (int) $best['id'],
                    'match_confidence' => $pick['confidence'],
                    // zpětná kompatibilita s FE (F1): true = částka nesedí na předpis.
                    'amount_mismatch' => $pick['confidence'] !== 'exact',
                ];

                if ($pick['suggested']) {
                    // F1 (audit 2026-07): daň §38a s nesedící částkou — NEspárovat naslepo.
                    // Předpis zůstává 'planned', transakce se NEoznačí použitou ani nezapíše
                    // jako zaplacená; vracíme jen jako NÁVRH k ručnímu potvrzení účetní.
                    $detail['suggested'] = true;
                    $detail['needs_review'] = true;
                    $details[] = $detail;
                    $suggested++;
                    continue;
                }

                $usedTxIds[$best['id']] = true;
                $this->schedules->markPaid($supplierId, (int) $schedule['id'], $paid, (string) $best['posted_at'], (int) $best['id'], $pick['confidence']);
                $matched++;
                $detail['suggested'] = false;
                $detail['needs_review'] = $pick['confidence'] !== 'exact';
                $details[] = $detail;
            }
        }

        $totals = $this->schedules->paidTotals($supplierId, $type, $periodYear);
        return [
            'matched' => $matched,
            'suggested' => $suggested,
            'totals' => $totals['exact'],
            'totals_uncertain' => $totals['uncertain'],
            'details' => $details,
        ];
    }

    /**
     * Vybere nepoužitou odchozí transakci se shodným VS v okně kolem splatnosti.
     *
     * Shoda ČÁSTKY má přednost před časovou blízkostí (F1). Výběr:
     * (1) mezi kandidáty, jejichž částka sedí na předpis ({@see amountMatches()} — koruna
     *     u pojistného, relativní pásmo u daně §38a), vyber nejbližší datu splatnosti →
     *     `confidence='exact'`, `suggested=false` (bezpečné k automatickému započtení).
     * (2) když ŽÁDNÝ amount-konzistentní kandidát v okně není:
     *     - DAŇ (F1 + audit 2026-07): VS = DIČ nese i doplatky daně a DPH, takže naslepo
     *       nejbližší platbu NEspárovat — vrátit ji jen jako NÁVRH (`suggested=true`),
     *       předpis zůstane 'planned' a do zaplacených záloh se nic tiše nezapíše;
     *     - POJISTNÉ (VS unikátní, kolize nepravděpodobná): částečnou/odlišnou úhradu
     *       spárovat, ale označit `confidence='uncertain'` — do automatického součtu
     *       nevstoupí, účetní ji potvrdí (žádná legitimní platba nezůstane tiše nespárovaná).
     *
     * F3 (audit 2026-07): u daně §38a se kandidát omezuje na KALENDÁŘNÍ rok `periodYear`
     * — předpis roku Y nesmí konzumovat zálohu splatnou v roce Y+1 (nesla by stejný VS).
     *
     * PÁROVÁNÍ DLE ÚČTU FÚ (2026-07): je-li `$expectedTaxPrefix` zadané (druh `tax`) a
     * kandidát MÁ evidovaný protiúčet (`counterparty_account`), musí jeho PŘEDČÍSLÍ sedět
     * na očekávaný typ daně (7704 DPPO / 721 DPFO) — jinak jde o jinou daň (DPH, srážková,
     * závislá činnost) nebo cizí protistranu se stejným VS = DIČ a kandidát se VYLOUČÍ. Když
     * protiúčet chybí (starší import), předčíslí se nekontroluje a platí původní ochrany.
     *
     * @param list<array<string,mixed>> $candidates
     * @param array<int,bool> $usedTxIds
     * @param string|null $expectedTaxPrefix očekávané předčíslí účtu FÚ (jen druh `tax`)
     * @return array{tx:array<string,mixed>,confidence:string,suggested:bool}|null
     */
    private function pickTransaction(array $candidates, array $usedTxIds, string $expectedVs, string $dueDate, float $expectedAmount, string $kind, int $periodYear, ?string $expectedTaxPrefix = null): ?array
    {
        $due = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($dueDate, 0, 10));
        if ($due === false) {
            return null;
        }
        $beforeDays = $kind === 'tax' ? self::TAX_DUE_WINDOW_BEFORE_DAYS : self::DUE_WINDOW_BEFORE_DAYS;
        $afterDays = $kind === 'tax' ? self::TAX_DUE_WINDOW_AFTER_DAYS : self::DUE_WINDOW_AFTER_DAYS;
        $from = $due->modify('-' . $beforeDays . ' days');
        $to = $due->modify('+' . $afterDays . ' days');

        $bestExact = null;
        $bestExactDelta = PHP_INT_MAX;
        $bestAny = null;
        $bestAnyDelta = PHP_INT_MAX;

        foreach ($candidates as $tx) {
            if (!empty($usedTxIds[(int) $tx['id']])) {
                continue;
            }
            if ($tx['vs_key'] !== $expectedVs) {
                continue;
            }
            // Párování dle účtu FÚ: má-li platba evidovaný protiúčet, jeho předčíslí musí
            // odpovídat očekávanému typu daně z příjmů (7704/721). Jiná daň (705/7720/713)
            // nebo cizí protistrana se stejným VS = DIČ se tím vyloučí.
            if ($expectedTaxPrefix !== null) {
                $cpAccount = trim((string) ($tx['counterparty_account'] ?? ''));
                if ($cpAccount !== '' && AccountNumberNormalizer::czechAccountPrefix($cpAccount) !== $expectedTaxPrefix) {
                    continue;
                }
            }
            $posted = \DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) $tx['posted_at'], 0, 10));
            if ($posted === false || $posted < $from || $posted > $to) {
                continue;
            }
            // F3: záloha na daň roku periodYear musí padnout do TÉHOŽ kalendářního roku;
            // platba z jiného roku (typicky Q1 splatná 15. 3. roku Y+1) patří jinému období.
            if ($kind === 'tax' && (int) $posted->format('Y') !== $periodYear) {
                continue;
            }
            $delta = abs($posted->getTimestamp() - $due->getTimestamp());
            if ($delta < $bestAnyDelta) {
                $bestAnyDelta = $delta;
                $bestAny = $tx;
            }
            if ($this->amountMatches((float) $tx['amount'], $expectedAmount, $kind) && $delta < $bestExactDelta) {
                $bestExactDelta = $delta;
                $bestExact = $tx;
            }
        }

        if ($bestExact !== null) {
            return ['tx' => $bestExact, 'confidence' => 'exact', 'suggested' => false];
        }
        if ($bestAny === null) {
            return null;
        }
        if ($kind === 'tax') {
            // Nesedící částka u daně → jen návrh, NEspárovat (předpis zůstává 'planned').
            return ['tx' => $bestAny, 'confidence' => 'uncertain', 'suggested' => true];
        }
        // Pojistné: spárovat, ale jako nejistou (mimo automatický součet).
        return ['tx' => $bestAny, 'confidence' => 'uncertain', 'suggested' => false];
    }

    /**
     * Sedí částka odchozí platby na předepsanou zálohu? Pojistné: koruna na korunu
     * ({@see AMOUNT_MATCH_TOLERANCE}) — přesná záloha je známá. Daň §38a: relativní
     * pásmo ({@see TAX_AMOUNT_MATCH_RATIO}) — predikce je jen odhad z jiného přiznání.
     */
    private function amountMatches(float $txAmount, float $expectedAmount, string $kind): bool
    {
        $paid = abs($txAmount);
        if ($kind === 'tax') {
            if ($expectedAmount <= 0.0) {
                return false;
            }
            return abs($paid - $expectedAmount) <= $expectedAmount * self::TAX_AMOUNT_MATCH_RATIO;
        }
        return abs($paid - $expectedAmount) <= self::AMOUNT_MATCH_TOLERANCE;
    }

    /**
     * Odchozí bankovní pohyby (amount < 0) s VS patřící účtům supplera pro daný rok
     * (+ leden následujícího roku kvůli prosincové zdravotní záloze splatné 8. 1.).
     *
     * @return list<array<string,mixed>> každý s klíčem vs_key (kanonický VS)
     */
    private function outgoingCandidates(int $supplierId, int $periodYear): array
    {
        $accounts = $this->supplierAccounts($supplierId);
        if ($accounts === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT bt.id, bt.amount, bt.variable_symbol, bt.posted_at, bt.counterparty_account,
                    bs.account_number AS stmt_account, bs.bank_code AS stmt_bank
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.amount < 0
                AND bt.variable_symbol IS NOT NULL AND bt.variable_symbol <> ''
                AND bt.posted_at BETWEEN ? AND ?"
        );
        $stmt->execute([sprintf('%04d-01-01', $periodYear), sprintf('%04d-03-31', $periodYear + 1)]);

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (!$this->accountBelongsToSupplier($accounts, (string) ($row['stmt_account'] ?? ''), (string) ($row['stmt_bank'] ?? ''))) {
                continue;
            }
            $row['vs_key'] = VariableSymbolNormalizer::forMatching((string) $row['variable_symbol']);
            $out[] = $row;
        }
        return $out;
    }

    /**
     * @param list<array{account_number:?string,iban:?string,bank_code:?string}> $accounts
     */
    private function accountBelongsToSupplier(array $accounts, string $stmtAccount, string $stmtBank): bool
    {
        if ($stmtAccount === '') {
            return false;
        }
        foreach ($accounts as $acc) {
            $iban = $acc['iban'] !== null && $acc['iban'] !== '' ? $acc['iban'] : null;
            if ($stmtBank !== '') {
                $candBank = (string) ($acc['bank_code'] ?? '');
                if ($candBank === '' && $iban !== null) {
                    $candBank = (string) AccountNumberNormalizer::czechIbanBankCode($iban);
                }
                if ($candBank !== '' && $candBank !== $stmtBank) {
                    continue;
                }
            }
            if (AccountNumberNormalizer::matchesAny($stmtAccount, $acc['account_number'] ?? null, $iban)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<array{account_number:?string,iban:?string,bank_code:?string}>
     */
    private function supplierAccounts(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT account_number, iban, bank_code FROM currencies
              WHERE supplier_id = ? AND (account_number IS NOT NULL OR iban IS NOT NULL)'
        );
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'account_number' => $r['account_number'] === null ? null : (string) $r['account_number'],
                'iban' => $r['iban'] === null ? null : (string) $r['iban'],
                'bank_code' => $r['bank_code'] === null ? null : (string) $r['bank_code'],
            ];
        }
        return $out;
    }

    /**
     * Očekávané VS pro párování: daň = kmenová část DIČ, sociální = VS ČSSZ (vsdp),
     * zdravotní = číslo pojištěnce ZP. Kanonizováno stejně jako VS bankovní transakce.
     *
     * @return array{tax_vs:?string,social_vs:?string,health_vs:?string}
     */
    private function supplierPaymentIdentifiers(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT dic, cssz_vsdp, health_insurance_number FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [
            'tax_vs' => $this->vsKey((string) ($row['dic'] ?? '')),
            'social_vs' => $this->vsKey((string) ($row['cssz_vsdp'] ?? '')),
            'health_vs' => $this->vsKey((string) ($row['health_insurance_number'] ?? '')),
        ];
    }

    private function vsKey(string $raw): ?string
    {
        $key = VariableSymbolNormalizer::forMatching($raw);
        return $key === '' ? null : $key;
    }

    /**
     * Předpisy pro rok $periodYear (per FE), s odvozeným příznakem po splatnosti.
     *
     * @return list<array<string,mixed>>
     */
    public function listForYear(int $supplierId, int $periodYear, ?string $type = null): array
    {
        return array_map([$this, 'withOverdue'], $this->schedules->listForYear($supplierId, $periodYear, $type));
    }

    /**
     * Předpisy NAPŘÍČ ROKY pro typ (globální tabulka předpisu placení záloh, #46), se stavem
     * po splatnosti. @return list<array<string,mixed>>
     */
    public function listAllYears(int $supplierId, string $type): array
    {
        return array_map([$this, 'withOverdue'], $this->schedules->listAllForType($supplierId, $type));
    }

    /** Roky, pro které existují předpisy daného typu. @return list<int> */
    public function scheduleYears(int $supplierId, string $type): array
    {
        return $this->schedules->distinctPeriodYears($supplierId, $type);
    }

    /**
     * Nadcházející nezaplacené předpisy pro dashboard widget.
     *
     * @return list<array<string,mixed>>
     */
    public function upcoming(int $supplierId, int $limit = 12): array
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        // Nadcházející = splatné dnes a dál; k tomu i čerstvě po splatnosti (30 dní zpět).
        $from = (new \DateTimeImmutable('today'))->modify('-30 days')->format('Y-m-d');
        return array_map([$this, 'withOverdue'], $this->schedules->upcoming($supplierId, $from, $limit));
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function withOverdue(array $row): array
    {
        $row['is_overdue'] = $row['status'] === 'planned' && $row['due_date'] < (new \DateTimeImmutable('today'))->format('Y-m-d');
        return $row;
    }

    // ── Override výše záloh dle rozhodnutí FÚ (§174 DŘ, #43) ────────────────────

    /**
     * Účinný override daně §38a pro rok (nebo null). @return array<string,mixed>|null
     */
    public function activeTaxOverride(int $supplierId, string $type, int $periodYear): ?array
    {
        return $this->overrides->activeForYear($supplierId, $type, 'tax', $periodYear);
    }

    /**
     * Uloží (nahradí) override výše záloh na daň §38a pro rok. Vrací uložený řádek.
     * @return array<string,mixed>
     */
    public function saveTaxOverride(
        int $supplierId,
        string $type,
        int $periodYear,
        string $effectiveFrom,
        float $amount,
        string $periodicity,
        ?string $note,
        string $source,
    ): array {
        $periodicity = in_array($periodicity, ['quarterly', 'semiannual', 'annual', 'none'], true) ? $periodicity : 'quarterly';
        $source = $source === 'manual' ? 'manual' : 'fu_decision';
        return $this->overrides->upsert(
            $supplierId, $type, 'tax', $periodYear, $effectiveFrom, $amount, $periodicity, $note, $source
        );
    }

    /** Smaže override daně §38a pro rok. @return int počet smazaných */
    public function deleteTaxOverride(int $supplierId, string $type, int $periodYear): int
    {
        return $this->overrides->deleteGroup($supplierId, $type, 'tax', $periodYear);
    }

    // ── Rozhodnutí FÚ s rozsahem OD-DO — id-based CRUD napříč roky (#46) ────────

    /** Všechna rozhodnutí FÚ o dani §38a napříč roky. @return list<array<string,mixed>> */
    public function listTaxOverrides(int $supplierId, string $type): array
    {
        return $this->overrides->listAll($supplierId, $type, 'tax');
    }

    /** Jedno rozhodnutí FÚ dle id (scoping na supplera). @return array<string,mixed>|null */
    public function findTaxOverride(int $supplierId, int $id): ?array
    {
        return $this->overrides->find($supplierId, $id);
    }

    /**
     * Založí nové rozhodnutí FÚ (§174) s rozsahem OD-DO. Validuje rozsah i překryv s
     * ostatními rozhodnutími téhož druhu (novější/užší se řeší zamezením překryvu). Vrací
     * uložený řádek. @return array<string,mixed>
     * @throws TaxReturnException při neplatném rozsahu / překryvu
     */
    public function createTaxOverride(
        int $supplierId,
        string $type,
        string $effectiveFrom,
        ?string $effectiveTo,
        float $amount,
        string $periodicity,
        ?string $note,
        string $source,
    ): array {
        $effectiveTo = $this->normalizeTo($effectiveTo);
        $periodicity = $this->normalizePeriodicity($periodicity);
        $source = $source === 'manual' ? 'manual' : 'fu_decision';
        $this->assertValidRange($effectiveFrom, $effectiveTo);
        $this->assertNoOverlap($supplierId, $type, $effectiveFrom, $effectiveTo, null);
        $periodYear = (int) substr($effectiveFrom, 0, 4);
        return $this->overrides->insert(
            $supplierId, $type, 'tax', $periodYear, $effectiveFrom, $effectiveTo, $amount, $periodicity, $note, $source
        );
    }

    /**
     * Upraví existující rozhodnutí FÚ. Stejná validace jako {@see createTaxOverride()},
     * překryv se počítá s VYNECHÁNÍM upravovaného id. @return array<string,mixed>
     * @throws TaxReturnException při neplatném rozsahu / překryvu / neznámém id
     */
    public function updateTaxOverride(
        int $supplierId,
        int $id,
        string $effectiveFrom,
        ?string $effectiveTo,
        float $amount,
        string $periodicity,
        ?string $note,
        string $source,
    ): array {
        $existing = $this->overrides->find($supplierId, $id);
        if ($existing === null) {
            throw new TaxReturnException('override_not_found', 'Rozhodnutí FÚ nebylo nalezeno.', 404);
        }
        $type = (string) $existing['taxpayer_type'];
        $effectiveTo = $this->normalizeTo($effectiveTo);
        $periodicity = $this->normalizePeriodicity($periodicity);
        $source = $source === 'manual' ? 'manual' : 'fu_decision';
        $this->assertValidRange($effectiveFrom, $effectiveTo);
        $this->assertNoOverlap($supplierId, $type, $effectiveFrom, $effectiveTo, $id);
        $periodYear = (int) substr($effectiveFrom, 0, 4);
        $updated = $this->overrides->updateById(
            $supplierId, $id, $periodYear, $effectiveFrom, $effectiveTo, $amount, $periodicity, $note, $source
        );
        if ($updated === null) {
            throw new TaxReturnException('override_not_found', 'Rozhodnutí FÚ nebylo nalezeno.', 404);
        }
        return $updated;
    }

    /** Smaže jedno rozhodnutí FÚ dle id (scoping na supplera). @return bool */
    public function deleteTaxOverrideById(int $supplierId, int $id): bool
    {
        return $this->overrides->deleteById($supplierId, $id);
    }

    private function normalizePeriodicity(string $periodicity): string
    {
        return in_array($periodicity, ['quarterly', 'semiannual', 'annual', 'none'], true) ? $periodicity : 'quarterly';
    }

    private function normalizeTo(?string $effectiveTo): ?string
    {
        return $effectiveTo !== null && $effectiveTo !== '' ? $effectiveTo : null;
    }

    /** @throws TaxReturnException když je konec před začátkem */
    private function assertValidRange(string $effectiveFrom, ?string $effectiveTo): void
    {
        if ($effectiveFrom === '') {
            throw new TaxReturnException('invalid_range', 'Účinnost OD je povinná.', 422);
        }
        if ($effectiveTo !== null && $effectiveTo < $effectiveFrom) {
            throw new TaxReturnException('invalid_range', 'Konec účinnosti (DO) nesmí být před začátkem (OD).', 422);
        }
    }

    /**
     * Zamezí překryvu rozsahů rozhodnutí téhož druhu (dvě rozhodnutí by řídila stejnou
     * splatnost — nejednoznačná výše zálohy). Půlotevřené konce (NULL = +∞) se počítají.
     * @throws TaxReturnException při překryvu
     */
    private function assertNoOverlap(int $supplierId, string $type, string $newFrom, ?string $newTo, ?int $excludeId): void
    {
        foreach ($this->overrides->listAll($supplierId, $type, 'tax') as $o) {
            if ($excludeId !== null && (int) $o['id'] === $excludeId) {
                continue;
            }
            $from = (string) ($o['effective_from'] ?? '');
            $to = isset($o['effective_to']) && $o['effective_to'] !== null ? (string) $o['effective_to'] : null;
            // Překryv [newFrom,newTo] × [from,to] (NULL = ±∞): newFrom <= to && from <= newTo.
            $startsBeforeOtherEnds = $to === null || $newFrom <= $to;
            $otherStartsBeforeNewEnds = $newTo === null || $from <= $newTo;
            if ($startsBeforeOtherEnds && $otherStartsBeforeNewEnds) {
                throw new TaxReturnException(
                    'override_overlap',
                    sprintf('Rozsah rozhodnutí se překrývá s již existujícím rozhodnutím (%s – %s).', $from, $to ?? '…'),
                    409,
                );
            }
        }
    }

    // ── Ruční úprava / potvrzení úhrad (#43 bod 3) ─────────────────────────────

    /** Ověří, že předpis patří supplerovi (a typu/roku). @return array<string,mixed>|null */
    public function findSchedule(int $supplierId, int $id): ?array
    {
        return $this->schedules->findById($supplierId, $id);
    }

    /** Ruční úprava předepsané výše NEzaplaceného předpisu. @return bool */
    public function updatePlannedAmount(int $supplierId, int $id, float $amount): bool
    {
        return $this->schedules->updatePlannedAmount($supplierId, $id, $amount);
    }

    /**
     * Ruční potvrzení úhrady předpisu (bez bankovní transakce). Není-li částka zadaná,
     * použije předepsanou; není-li datum, použije splatnost. @return bool
     */
    public function confirmPaidManual(int $supplierId, int $id, ?float $amount, ?string $paidOn): bool
    {
        $schedule = $this->schedules->findById($supplierId, $id);
        if ($schedule === null || $schedule['status'] !== 'planned') {
            return false;
        }
        $amount = $amount !== null && $amount > 0 ? $amount : (float) $schedule['amount'];
        $paidOn = $paidOn !== null && $paidOn !== '' ? $paidOn : (string) $schedule['due_date'];
        return $this->schedules->markPaidManual($supplierId, $id, $amount, $paidOn);
    }

    /** Hromadné „vše zaplaceno" pro rok/typ (volitelně druh). @return int počet potvrzených */
    public function confirmAllPaidManual(int $supplierId, string $type, int $periodYear, ?string $kind = null): int
    {
        return $this->schedules->markAllPlannedPaidManual($supplierId, $type, $periodYear, $kind);
    }

    /** Vrátí ručně potvrzený předpis do 'planned'. @return bool */
    public function unconfirmManual(int $supplierId, int $id): bool
    {
        return $this->schedules->resetToPlanned($supplierId, $id);
    }

    /** Součty zaplacených záloh (exact/uncertain) pro předvyplnění přiznání. @return array{exact:array,uncertain:array} */
    public function paidTotals(int $supplierId, string $type, int $periodYear): array
    {
        return $this->schedules->paidTotals($supplierId, $type, $periodYear);
    }
}
