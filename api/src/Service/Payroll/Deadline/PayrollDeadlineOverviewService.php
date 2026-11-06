<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Deadline;

use MyInvoice\Repository\Payroll\PayrollDeadlineOverviewRepository;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementStatute;
use MyInvoice\Repository\Payroll\PayrollRegistrationChangeProposalRepository;
use MyInvoice\Repository\Payroll\PayrollSicknessCaseRepository;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPredecessorGapService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService;
use MyInvoice\Service\Payroll\Submission\PayrollAwaitingRunDutyService;
use MyInvoice\Service\Payroll\Submission\PayrollDeadlineAssessmentService;
use MyInvoice\Service\Payroll\Submission\PayrollObligationSubjectFormatter;
use MyInvoice\Service\Payroll\Submission\Registration\Change\PayrollRegistrationChangeDetectionService;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessBenefitKind;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessCaseService;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessDocumentKind;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessDocumentStatus;
use MyInvoice\Service\Payroll\Submission\Sickness\SicknessException;
use MyInvoice\Service\Payroll\TaxStatement\TaxStatementService;
use Psr\Clock\ClockInterface;

/**
 * Co je do kdy a co je po termínu — jedním voláním za celou firmu.
 *
 * ## Proč to vzniklo
 *
 * Modul uměl zákonné lhůty spočítat na den přesně a s citací paragrafu
 * ({@see PayrollLevyDeadlinePolicy}, lhůty podání, lhůty checklistu), ale
 * neexistovalo místo, které by je někomu ŘEKLO. Termín se dal najít jen tak,
 * že si člověk otevřel přehled podání za správné období, pak platby za jiné
 * období a pak kartu každého zaměstnance zvlášť. Zmeškaný termín se nikde
 * nezvýraznil — a lhůta, kterou nikdo neuvidí, není hlídaná.
 *
 * ## Co se do přehledu dostane
 *
 * Tři prameny, které mají zákonnou lhůtu a nesplněný stav:
 *
 * 1. **podání** — `payroll_obligations` + `payroll_submission_deadlines`,
 *    tedy tatáž evidence, ze které žije obrazovka podání; stav se posuzuje
 *    {@see PayrollDeadlineAssessmentService}, aby přehled a detail povinnosti
 *    neříkaly o jednom termínu dvě různé věci,
 * 2. **odvody** — nezaplacené závazky ze splatnosti podle
 *    {@see PayrollLevyDeadlinePolicy} (pojistné, zálohová a srážková daň),
 * 3. **lhůty u lidí** — nevyřízené položky nástupního a výstupního checklistu
 *    s odvozenou zákonnou lhůtou (přihláška ČSSZ, oznámení pojišťovně, ELDP),
 * 4. **roční vyúčtování daně** — nepodané DPZVD6 a DPSVD2 se lhůtou podle
 *    {@see PayrollTaxStatementDeadlinePolicy}. Modul obě vyúčtování uměl
 *    sestavit i odeslat, ale jejich lhůta žila jen v komentáři a ve větě pod
 *    panelem — tedy nikde, kde by ji někdo zmeškal včas,
 * 5. **dávky nemocenského pojištění** — evidované případy bez doloženého
 *    podání NEMPRI nebo HZUPN, s lhůtou podle
 *    {@see \MyInvoice\Service\Payroll\Submission\Sickness\SicknessDeadlinePolicy}.
 *    Vlastní pramen, protože povinnost podle § 97 zák. č. 187/2006 Sb. vzniká
 *    sociální událostí, ne založením podání: kdyby se termín odvozoval jen
 *    z `payroll_obligations`, existoval by teprve od okamžiku, kdy někdo klikl
 *    na Připravit — tedy přesně tehdy, kdy už ho hlídat netřeba.
 * 6. **roční zúčtování záloh** — zjistit do 15. 2., kdo o zúčtování žádá,
 *    provést ho do 31. 3. a přeplatek vrátit se mzdou za březen (§ 38ch ZDP,
 *    lhůty drží {@see AnnualSettlementStatute}),
 * 7. **konec platnosti povolení cizince** — povolení k pobytu nebo
 *    k zaměstnání bez nástupce u člověka s trvajícím vztahem. Bez platného
 *    povolení nesmí cizinec pracovat (§ 89 zák. č. 435/2004 Sb.) a dosud to
 *    hlásila jen jeho karta, tedy místo, kam se nikdo nedívá, dokud ho nehledá.
 * 8. **žádost o potvrzení o zdanitelných příjmech** (§ 38j odst. 3 ZDP)
 *    u trvajícího vztahu — do 10 dnů od žádosti; u skončení ji dál nese
 *    položka výstupního checklistu,
 * 9. **vyúčtování pracovní cesty** (§ 183 odst. 1 ZP) — doklady do 10
 *    pracovních dnů po skončení cesty, vyúčtování do 10 pracovních dnů od
 *    jejich předložení.
 *
 * ## Co se do něj vědomě nedostane
 *
 * Čistá mzda, srážky ze mzdy ani exekuční platby: jejich termín plyne ze
 * smlouvy nebo z rozhodnutí, ne ze zákonné lhůty, a přimíchat je by z hlídače
 * termínů udělalo výpis všech plateb.
 *
 * ## Položky checklistu bez termínu
 *
 * Nevyřízená položka bez odvozené lhůty (`due_date IS NULL`) nemá fázi
 * termínu, takže do plochého přehledu, do fází souhrnu ani do měsíčního
 * přehledu nepatří: připomínat termín, který neexistuje, je nejjistější cesta,
 * jak obsluhu naučit hlášky přeskakovat. Seskupený přehled ji ale ukáže ve
 * vlastní fázi {@see self::UNDATED_PHASE}, až za všemi lhůtami a se stejným
 * hromadným odškrtnutím. Přihlášku ČSSZ u nástupu před 1. 7. 2026 totiž
 * mzdový běh dál hlásí jako chybějící; kdyby šla odškrtnout jen na kartě
 * vztahu, znamenalo by 225 lidí po importu 225 ručních kroků. Fáze souhrnu
 * se tím nemění, počet nese zvlášť `summary.undated`.
 *
 * Cron ani e-mail tady NENÍ. Přehled je čtecí; rozeslat ho je samostatné
 * rozhodnutí s vlastními následky (komu, jak často, co s firmou bez účetní).
 */
final readonly class PayrollDeadlineOverviewService
{
    /**
     * Fáze termínu, jak je vidí klient. Pořadí je zároveň pořadím naléhavosti,
     * ve kterém se přehled zobrazuje.
     *
     * @var list<string>
     */
    public const PHASES = [
        'overdue',
        'due_today',
        'due_soon',
        'action_required',
        'awaiting_result',
        'open',
    ];

    /**
     * Fáze skupiny nevyřízených položek checklistu BEZ termínu. Záměrně není
     * v {@see self::PHASES}: nemá prahy ani naléhavost, nesčítá se do fází
     * souhrnu a plochý přehled ji nezná.
     */
    public const UNDATED_PHASE = 'undated';

    /**
     * Všechny fáze, které může nést skupina seskupeného přehledu (a tedy klient):
     * fáze s termínem plus sekce bez termínu.
     *
     * @var list<string>
     */
    public const GROUP_PHASES = [...self::PHASES, self::UNDATED_PHASE];

    /**
     * Odkud termín pochází. Účetní to řeší až jako druhé — primárně ji zajímá,
     * co je pozdě — ale rozhoduje to, kam vede proklik.
     *
     * @var list<string>
     */
    public const SOURCES = [
        'submission',
        'levy',
        'checklist',
        'registration_change',
        'tax_statement',
        'sickness_case',
        'annual_settlement',
        'foreign_permit',
        'taxable_income_request',
        'pension_request',
        'business_trip',
    ];

    /** Kolik dnů dopředu se termín považuje za „brzy". */
    private const DUE_SOON_DAYS = 5;

    /** Výchozí dohled dopředu — pokrývá celý příští měsíc včetně 20. dne. */
    public const DEFAULT_HORIZON_DAYS = 45;

    public const MAX_HORIZON_DAYS = 400;

    /**
     * Jak hluboko do minulosti se zmeškané termíny ještě ukazují. Bez meze by
     * dashboard firmy s historií vypsal roky staré nedodělky a to podstatné
     * by v nich zaniklo.
     */
    private const OVERDUE_LOOKBACK_DAYS = 400;

    /**
     * Prameny, kde je položkou ČLOVĚK. Ty se v seskupeném přehledu slévají do
     * jednoho řádku na druh povinnosti a jejich seznam se stránkuje. Podání,
     * odvody a vyúčtování jsou za firmu a zůstávají po jednom.
     *
     * @var list<string>
     */
    public const PERSON_SOURCES = [
        'checklist',
        'registration_change',
        'sickness_case',
        'annual_settlement',
        'foreign_permit',
        'taxable_income_request',
        'pension_request',
        'business_trip',
    ];

    /**
     * Přeplatek ze zúčtování se vrací „nejpozději při zúčtování mzdy za březen"
     * (§ 38ch odst. 5 ZDP). Mzda za březen je splatná nejpozději v dubnu
     * (§ 141 odst. 1 zákoníku práce), takže poslední den, kdy vrácení ještě
     * může proběhnout včas, je konec dubna.
     */
    private const ANNUAL_REFUND_DUE_MONTH_DAY = '04-30';

    public const GROUP_ITEMS_DEFAULT_LIMIT = 50;

    public const GROUP_ITEMS_MAX_LIMIT = 200;

    public function __construct(
        private PayrollDeadlineOverviewRepository $repository,
        private PayrollDeadlineAssessmentService $assessments,
        private PayrollRegistrationChangeProposalRepository $registrationChanges,
        private PayrollRegistrationChangeDetectionService $changeDetection,
        private PayrollTaxStatementDeadlinePolicy $taxStatementDeadlines,
        private PayrollSicknessCaseRepository $sicknessCases,
        private SicknessDeadlinePolicy $sicknessDeadlines,
        private ClockInterface $clock,
        private ?JmhzPredecessorGapService $predecessorGaps = null,
        private ?PayrollAwaitingRunDutyService $awaitingRuns = null,
    ) {}

    /**
     * @return array{
     *   as_of:string,horizon_days:int,window:array{from:string,to:string},
     *   summary:array<string,int>,
     *   items:list<array<string,mixed>>
     * }
     */
    public function overview(
        int $supplierId,
        string $environment,
        int $horizonDays = self::DEFAULT_HORIZON_DAYS,
    ): array {
        [$today, $from, $to] = $this->scope($supplierId, $environment, $horizonDays);

        $items = $this->buildItems($supplierId, $environment, $from, $to);

        return [
            'as_of' => $today->format('Y-m-d'),
            'horizon_days' => $horizonDays,
            'window' => ['from' => $from, 'to' => $to],
            'summary' => $this->summary($items),
            'items' => $items,
        ];
    }

    /**
     * Tentýž přehled, ale po skupinách místo po položkách.
     *
     * Import docházky umí jedním tahem založit nástupní checklist dvěma stům
     * lidí. Plochý seznam z toho udělal 675 skoro stejných dlaždic „Pracovní
     * smlouva · jméno · po termínu o 104 dnů" a přes ně nebylo vidět nic
     * jiného. Skupina je druh povinnosti v jedné fázi termínu: jeden řádek
     * „Pracovní smlouva — 225 osob, nejstarší po termínu o 104 dnů".
     *
     * Nic se neschová, jen se jinak zobrazí: souhrn počítá tytéž položky jako
     * {@see self::overview()} a skupina nese jejich přesný počet. Položky
     * u lidí se v odpovědi neposílají (kromě jednočlenné skupiny, která se
     * prokliká rovnou); dotahuje je stránkovaně {@see self::groupItems()}.
     * Podání a odvody jsou za firmu, je jich pár, takže jedou celé.
     *
     * @return array{
     *   as_of:string,horizon_days:int,window:array{from:string,to:string},
     *   summary:array<string,int>,
     *   groups:list<array<string,mixed>>
     * }
     */
    public function groupedOverview(
        int $supplierId,
        string $environment,
        int $horizonDays = self::DEFAULT_HORIZON_DAYS,
    ): array {
        [$today, $from, $to] = $this->scope($supplierId, $environment, $horizonDays);

        $items = $this->buildItems($supplierId, $environment, $from, $to);
        $undated = $this->undatedGroups($supplierId);

        return [
            'as_of' => $today->format('Y-m-d'),
            'horizon_days' => $horizonDays,
            'window' => ['from' => $from, 'to' => $to],
            'summary' => $this->summary($items) + [
                self::UNDATED_PHASE => array_sum(array_column($undated, 'count')),
            ],
            // Bez termínu až za všemi lhůtami; pořadí skupin s termínem se nemění.
            'groups' => [...$this->group($items), ...$undated],
        ];
    }

    /**
     * Stránka položek jedné skupiny ze {@see self::groupedOverview()}.
     *
     * Čte jen pramen dané skupiny, takže rozbalení nespouští znovu detekci
     * změn ani ostatní dotazy. Hledá se v jménu a v osobním čísle, bez
     * ohledu na velikost písmen a diakritiku.
     *
     * @return array{total:int,offset:int,limit:int,items:list<array<string,mixed>>}
     */
    public function groupItems(
        int $supplierId,
        string $environment,
        int $horizonDays,
        string $phase,
        string $source,
        string $title,
        string $query = '',
        int $offset = 0,
        int $limit = self::GROUP_ITEMS_DEFAULT_LIMIT,
    ): array {
        $offset = max(0, $offset);
        $limit = max(1, min(self::GROUP_ITEMS_MAX_LIMIT, $limit));
        $items = $this->filteredGroupItems(
            $supplierId,
            $environment,
            $horizonDays,
            $phase,
            $source,
            $title,
            $query,
        );

        return [
            'total' => count($items),
            'offset' => $offset,
            'limit' => $limit,
            'items' => array_slice($items, $offset, $limit),
        ];
    }

    /** @return list<array<string,mixed>> celá skupina, seřazená pro výpis */
    private function filteredGroupItems(
        int $supplierId,
        string $environment,
        int $horizonDays,
        string $phase,
        string $source,
        string $title,
        string $query,
    ): array {
        [, $from, $to] = $this->scope($supplierId, $environment, $horizonDays);
        $undated = $phase === self::UNDATED_PHASE;
        if (!$undated && !in_array($phase, self::PHASES, true)) {
            throw new \InvalidArgumentException('Fáze skupiny termínů není platná.');
        }
        if (!in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException('Pramen skupiny termínů není platný.');
        }
        // Termín chybět může jen položce checklistu; ostatní prameny ho mají vždy.
        if ($undated && $source !== 'checklist') {
            throw new \InvalidArgumentException('Skupina bez termínu je jen u checklistu vztahu.');
        }
        if ($title === '' || strlen($title) > 64) {
            throw new \InvalidArgumentException('Druh povinnosti ve skupině termínů chybí.');
        }

        $items = array_values(array_filter(
            $undated
                ? $this->undatedChecklistItems($supplierId, $title)
                : $this->sourceItems($supplierId, $environment, $source, $title, $from, $to),
            static fn (array $item): bool
                => $item['phase'] === $phase && $item['title'] === $title,
        ));
        $items = $this->withPersonalNumbers($supplierId, $items);
        $needle = self::fold($query);
        if ($needle !== '') {
            $items = array_values(array_filter(
                $items,
                static fn (array $item): bool
                    => str_contains(self::fold((string) $item['subject']), $needle)
                    || str_contains(self::fold((string) ($item['personal_number'] ?? '')), $needle),
            ));
        }
        usort(
            $items,
            static fn (array $a, array $b): int
                => [$a['due_on'], self::fold((string) $a['subject']), $a['reference']]
                <=> [$b['due_on'], self::fold((string) $b['subject']), $b['reference']],
        );

        return $items;
    }

    /**
     * Nevyřízené položky checklistu jedné skupiny přehledu — podklad pro
     * hromadné odškrtnutí. Stejný výběr jako to, co vidí účetní ve skupině
     * (stejné okno, stejné vyřazení doložených povinností), takže „celá
     * skupina" nikdy neodškrtne něco, co v ní nebylo.
     *
     * @return list<array{item_id:int,employment_id:int,item_key:string,subject:string}>
     *         seřazené podle id položky (kurzor dávky)
     */
    public function checklistGroupCandidates(
        int $supplierId,
        int $horizonDays,
        string $phase,
        string $itemKey,
        string $query = '',
    ): array {
        // Checklist na prostředí nezávisí; `production` je tu jen kvůli
        // společné validaci okna.
        $items = $this->filteredGroupItems(
            $supplierId,
            'production',
            $horizonDays,
            $phase,
            'checklist',
            $itemKey,
            $query,
        );
        $candidates = array_map(
            static fn (array $item): array => [
                'item_id' => (int) $item['item_id'],
                'employment_id' => (int) $item['employment_id'],
                'item_key' => (string) $item['title'],
                'subject' => (string) $item['subject'],
            ],
            $items,
        );
        usort(
            $candidates,
            static fn (array $a, array $b): int => $a['item_id'] <=> $b['item_id'],
        );

        return $candidates;
    }

    /**
     * Tytéž prameny jako {@see self::overview()}, ale nad LIBOVOLNÝM oknem
     * `[$from, $to]` místo dohledu od dneška.
     *
     * Vznikla pro měsíční přehled pro účetní ({@see \MyInvoice\Service\Payroll\Submission\PayrollMonthlyChecklistService}):
     * ten se ptá na konkrétní zvolený měsíc, ne na „co hoří teď", takže okno
     * musí zadat volající, ne horizont od dnešního dne. Souhrn (`summary`) tu
     * záměrně není — skládá si ho volající sám nad SLOUČENÝM seznamem položek
     * z více pramenů, jinak by dva souhrny (tenhle a checklistu) mohly tvrdit
     * dvě různé pravdy o tomtéž měsíci.
     *
     * @return list<array<string,mixed>>
     */
    public function itemsForWindow(
        int $supplierId,
        string $environment,
        string $from,
        string $to,
    ): array {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException(
                'Firma přehledu mzdových termínů není platná.',
            );
        }
        if (!in_array($environment, ['production', 'test'], true)) {
            throw new \InvalidArgumentException(
                'Prostředí přehledu mzdových termínů musí být production nebo test.',
            );
        }
        if ($from > $to) {
            throw new \InvalidArgumentException(
                'Počátek okna přehledu mzdových termínů nesmí být po jeho konci.',
            );
        }

        return $this->buildItems($supplierId, $environment, $from, $to);
    }

    /** @return list<array<string,mixed>> */
    private function buildItems(
        int $supplierId,
        string $environment,
        string $from,
        string $to,
    ): array {
        // Detekce se přepočítá dřív, než se přehled poskládá. Katalog lhůt
        // dosud jen PŘIPOMÍNAL a neměl vazbu na službu, která povinnost splní:
        // změnu údaje nikdo nesledoval, takže osmidenní lhůta neměla kde
        // vzniknout. Přepočet je omezený vodoznakem, takže firma s pěti sty
        // zaměstnanci zaplatí jeden dotaz, ne pět set dešifrování.
        try {
            $this->changeDetection->sweep($supplierId, $environment);
        } catch (\Throwable) {
            // Hlídač termínů musí ukázat i to, co ví, když detekce selže.
            // Prázdný dashboard je horší než dashboard bez jedné sekce.
        }

        $items = [
            ...$this->submissionItems($supplierId, $environment, $from, $to),
            ...$this->predecessorJmhzItems($supplierId, $environment, $from, $to),
            ...$this->awaitingRunItems($supplierId, $from, $to),
            ...$this->levyItems($supplierId, $from, $to),
            ...$this->checklistItems($supplierId, $from, $to),
            ...$this->registrationChangeItems($supplierId, $environment, $from, $to),
            ...$this->taxStatementItems($supplierId, $from, $to),
            ...$this->sicknessCaseItems($supplierId, $environment, $from, $to),
            ...$this->annualSettlementItems($supplierId, $from, $to),
            ...$this->foreignPermitItems($supplierId, $from, $to),
            ...$this->taxableIncomeRequestItems($supplierId, $from, $to),
            ...$this->pensionRequestItems($supplierId, $from, $to),
            ...$this->businessTripItems($supplierId, $from, $to),
        ];
        usort(
            $items,
            static fn (array $a, array $b): int
                => [$a['due_on'], $a['source'], $a['title']]
                <=> [$b['due_on'], $b['source'], $b['title']],
        );

        return $items;
    }

    /**
     * Společná validace a okno přehledu: od zmeškaných termínů do dohledu.
     *
     * @return array{\DateTimeImmutable,string,string} dnešek, od, do
     */
    private function scope(int $supplierId, string $environment, int $horizonDays): array
    {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException(
                'Firma přehledu mzdových termínů není platná.',
            );
        }
        if (!in_array($environment, ['production', 'test'], true)) {
            throw new \InvalidArgumentException(
                'Prostředí přehledu mzdových termínů musí být production nebo test.',
            );
        }
        if ($horizonDays < 1 || $horizonDays > self::MAX_HORIZON_DAYS) {
            throw new \InvalidArgumentException(
                'Dohled přehledu mzdových termínů musí být 1 až '
                . self::MAX_HORIZON_DAYS . ' dnů.',
            );
        }
        $today = $this->today();

        return [
            $today,
            $today
                ->sub(new \DateInterval('P' . self::OVERDUE_LOOKBACK_DAYS . 'D'))
                ->format('Y-m-d'),
            $today
                ->add(new \DateInterval('P' . $horizonDays . 'D'))
                ->format('Y-m-d'),
        ];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,int>
     */
    private function summary(array $items): array
    {
        $summary = ['total' => count($items)]
            + array_fill_keys(self::PHASES, 0);
        foreach ($items as $item) {
            $phase = (string) $item['phase'];
            if (array_key_exists($phase, $summary) && $phase !== 'total') {
                ++$summary[$phase];
            }
        }

        return $summary;
    }

    /**
     * Skupina = fáze termínu × pramen × druh povinnosti. Pořadí je pořadí
     * naléhavosti fáze, uvnitř ní nejstarší termín první.
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function group(array $items): array
    {
        /** @var array<string,list<array<string,mixed>>> $buckets */
        $buckets = [];
        foreach ($items as $item) {
            $key = $item['phase'] . ':' . $item['source'] . ':' . $item['title'];
            $buckets[$key][] = $item;
        }

        $groups = [];
        foreach ($buckets as $key => $members) {
            $first = $members[0];
            $perPerson = in_array($first['source'], self::PERSON_SOURCES, true);
            $dueDates = array_map(static fn (array $m): string => (string) $m['due_on'], $members);
            $days = array_map(static fn (array $m): int => (int) $m['days_to_due'], $members);
            $count = count($members);
            $groups[] = [
                'key' => $key,
                'phase' => $first['phase'],
                'source' => $first['source'],
                'title' => $first['title'],
                'per_person' => $perPerson,
                'count' => $count,
                'oldest_due_on' => min($dueDates),
                'newest_due_on' => max($dueDates),
                'min_days_to_due' => min($days),
                'max_days_to_due' => max($days),
                'is_overdue' => $first['phase'] === 'overdue',
                // Jednočlenná skupina se proklikává rovnou, takže položku
                // potřebuje; u lidí se jinak stránkuje přes groupItems().
                'items' => !$perPerson || $count === 1 ? $members : [],
            ];
        }
        $phaseRank = array_flip(self::PHASES);
        usort(
            $groups,
            static fn (array $a, array $b): int
                => [$phaseRank[$a['phase']] ?? 99, $a['oldest_due_on'], $a['source'], $a['title']]
                <=> [$phaseRank[$b['phase']] ?? 99, $b['oldest_due_on'], $b['source'], $b['title']],
        );

        return $groups;
    }

    /**
     * Položky jednoho pramene. Rozbalení skupiny tak nezaplatí detekci změn
     * ani dotazy ostatních pramenů.
     *
     * @return list<array<string,mixed>>
     */
    private function sourceItems(
        int $supplierId,
        string $environment,
        string $source,
        string $title,
        string $from,
        string $to,
    ): array {
        return match ($source) {
            'submission' => $this->submissionItems($supplierId, $environment, $from, $to),
            'levy' => $this->levyItems($supplierId, $from, $to),
            'checklist' => $this->checklistItems($supplierId, $from, $to, $title),
            'registration_change' => $this->registrationChangeItems($supplierId, $environment, $from, $to),
            'tax_statement' => $this->taxStatementItems($supplierId, $from, $to),
            'sickness_case' => $this->sicknessCaseItems($supplierId, $environment, $from, $to),
            'annual_settlement' => $this->annualSettlementItems($supplierId, $from, $to),
            'foreign_permit' => $this->foreignPermitItems($supplierId, $from, $to),
            'taxable_income_request' => $this->taxableIncomeRequestItems($supplierId, $from, $to),
            'pension_request' => $this->pensionRequestItems($supplierId, $from, $to),
            'business_trip' => $this->businessTripItems($supplierId, $from, $to),
            default => [],
        };
    }

    /**
     * Osobní číslo do výpisu lidí. Checklist ho nese z dotazu, ostatní
     * prameny u lidí se dohledají jedním dotazem za celou stránku.
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function withPersonalNumbers(int $supplierId, array $items): array
    {
        $missing = [];
        foreach ($items as $item) {
            if (!array_key_exists('personal_number', $item) && isset($item['employment_id'])) {
                $missing[] = (int) $item['employment_id'];
            }
        }
        if ($missing === []) {
            return $items;
        }
        $codes = $this->repository->employmentCodes($supplierId, $missing);

        return array_map(
            static function (array $item) use ($codes): array {
                if (!array_key_exists('personal_number', $item) && isset($item['employment_id'])) {
                    $item['personal_number'] = $codes[(int) $item['employment_id']] ?? null;
                }
                return $item;
            },
            $items,
        );
    }

    /** Malá písmena bez diakritiky — „novak" najde „Novák". */
    private static function fold(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');

        return strtr($value, [
            'á' => 'a', 'ä' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e',
            'ë' => 'e', 'í' => 'i', 'ĺ' => 'l', 'ľ' => 'l', 'ň' => 'n', 'ó' => 'o',
            'ô' => 'o', 'ö' => 'o', 'ř' => 'r', 'ŕ' => 'r', 'š' => 's', 'ť' => 't',
            'ú' => 'u', 'ů' => 'u', 'ü' => 'u', 'ý' => 'y', 'ž' => 'z',
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function submissionItems(
        int $supplierId,
        string $environment,
        string $from,
        string $to,
    ): array {
        $items = [];
        foreach ($this->repository->submissionDeadlines(
            $supplierId,
            $environment,
            $from,
            $to,
        ) as $row) {
            $assessment = $this->assessments->assess(
                (string) $row['earliest_submission_on'],
                (string) $row['due_on'],
                (string) $row['status'],
                $row['submission_status'] === null
                    ? null
                    : (string) $row['submission_status'],
            );
            // `not_open` a `fulfilled` do hlídače nepatří: první ještě nejde
            // podat, druhý je hotový. Kdyby se ukazovaly, tvořily by většinu
            // seznamu a to podstatné by v nich zaniklo.
            if (in_array(
                $assessment->phase,
                ['not_open', 'fulfilled', 'cancelled'],
                true,
            )) {
                continue;
            }
            $items[] = [
                'source' => 'submission',
                'reference' => 'payroll_obligation:' . (int) $row['obligation_id'],
                'title' => (string) $row['agenda_code'],
                // Syrový `subject_reference` (`payroll_run:8:office:4`) je
                // interní klíč, ne text pro účetní; překládá ho tentýž
                // formátovač jako přehled podání a inbox. Nerozpoznaný tvar
                // vrací null a řádek zůstane bez předmětu - to je pořád lepší
                // než ukázat interní ID.
                'subject' => PayrollObligationSubjectFormatter::humanSubject(
                    (string) $row['agenda_code'],
                    (string) $row['subject_reference'],
                ) ?? '',
                'period' => substr((string) $row['period_start'], 0, 7),
                'due_on' => (string) $row['due_on'],
                'phase' => $assessment->phase,
                'days_to_due' => $assessment->daysToDue,
                'is_overdue' => $assessment->isOverdue,
                'status' => (string) $row['status'],
                'submission_status' => $row['submission_status'],
                'ruleset_id' => (string) $row['ruleset_id'],
                'path' => '/payroll/submissions',
            ];
        }

        return $items;
    }

    /**
     * Nepodané hlášení JMHZ za převzatý měsíc ({@see JmhzPredecessorGapService}).
     * Pramen `submission`, protože jde o tutéž agendu jako podání z evidence —
     * Měsíční přehled ho tím nepřevezme podruhé (má vlastní bohatší řádek).
     *
     * @return list<array<string,mixed>>
     */
    private function predecessorJmhzItems(
        int $supplierId,
        string $environment,
        string $from,
        string $to,
    ): array {
        if ($this->predecessorGaps === null) {
            return [];
        }
        $items = [];
        foreach ($this->predecessorGaps->missing($supplierId, $environment) as $gap) {
            if ($gap['due_on'] < $from || $gap['due_on'] > $to || $gap['phase'] === 'not_open') {
                continue;
            }
            $items[] = [
                'source' => 'submission',
                'reference' => 'predecessor_jmhz:' . $gap['period'],
                'title' => JmhzSubmissionBridgeService::AGENDA_CODE,
                'subject' => 'převzatý měsíc – hlášení nebylo podáno předchozím programem',
                'period' => $gap['period'],
                'due_on' => $gap['due_on'],
                'phase' => $gap['phase'],
                'days_to_due' => $gap['days_to_due'],
                'is_overdue' => $gap['is_overdue'],
                'status' => 'open',
                'submission_status' => null,
                'ruleset_id' => '',
                'path' => '/payroll/submissions/jmhz',
            ];
        }

        return $items;
    }

    /**
     * Hlášení JMHZ za měsíc vedení mezd, který ještě nemá schválený běh
     * ({@see PayrollAwaitingRunDutyService}). Na rozdíl od podání z evidence
     * se ukazuje i před otevřením lhůty: nejdřív se musí spočítat a schválit
     * běh, a to je práce, na kterou hlídač upozorňuje.
     *
     * @return list<array<string,mixed>>
     */
    private function awaitingRunItems(int $supplierId, string $from, string $to): array
    {
        if ($this->awaitingRuns === null) {
            return [];
        }
        $items = [];
        foreach ($this->awaitingRuns->missing($supplierId) as $gap) {
            if ($gap['due_on'] < $from || $gap['due_on'] > $to) {
                continue;
            }
            $items[] = [
                'source' => 'submission',
                'reference' => 'awaiting_run:' . $gap['period'],
                'title' => PayrollAwaitingRunDutyService::agendaCode(),
                'subject' => 'čeká na schválený mzdový běh',
                'period' => $gap['period'],
                'due_on' => $gap['due_on'],
                'phase' => $gap['phase'] === 'not_open' ? 'due_soon' : $gap['phase'],
                'days_to_due' => $gap['days_to_due'],
                'is_overdue' => $gap['is_overdue'],
                'status' => 'open',
                'submission_status' => null,
                'ruleset_id' => '',
                'path' => '/payroll/runs?period=' . $gap['period'],
            ];
        }

        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function levyItems(
        int $supplierId,
        string $from,
        string $to,
    ): array {
        $items = [];
        foreach ($this->repository->levyDeadlines(
            $supplierId,
            $from,
            $to,
        ) as $row) {
            $dueOn = (string) $row['due_on'];
            $remaining = (int) $row['amount_minor'] - (int) $row['settled_minor'];
            $items[] = [
                'source' => 'levy',
                'reference' => 'payroll_liability:' . (int) $row['liability_id'],
                'title' => (string) $row['liability_kind'],
                // Název instituce z platebního účtu; `recipient_reference`
                // (`institution:health_insurer:111:account:1`) je interní klíč.
                'subject' => (string) ($row['recipient_name'] ?? ''),
                'period' => substr((string) $row['period_start'], 0, 7),
                'due_on' => $dueOn,
                'phase' => $this->phase($dueOn),
                'days_to_due' => $this->daysToDue($dueOn),
                'is_overdue' => $this->phase($dueOn) === 'overdue',
                'remaining_minor' => $remaining,
                'run_id' => (int) $row['run_id'],
                'path' => '/payroll/payments',
            ];
        }

        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function checklistItems(
        int $supplierId,
        string $from,
        string $to,
        ?string $itemKey = null,
    ): array {
        return array_map(
            fn (array $row): array => $this->checklistRowItem($row),
            $this->repository->checklistDeadlines($supplierId, $from, $to, $itemKey),
        );
    }

    /** @return list<array<string,mixed>> */
    private function undatedChecklistItems(int $supplierId, ?string $itemKey = null): array
    {
        return array_map(
            fn (array $row): array => $this->checklistRowItem($row),
            $this->repository->checklistWithoutDeadline($supplierId, $itemKey),
        );
    }

    /**
     * Skupiny nevyřízených položek bez termínu, jen s počty; lidé se dotahují
     * stránkovaně jako u ostatních skupin. Seznam se neposílá ani u jednoho
     * člověka: hromadné odškrtnutí vede přes rozbalený seznam a jednočlenná
     * skupina by se jinak vykreslila jako odkaz s termínem, který neexistuje.
     * Největší nedodělek jde první.
     *
     * @return list<array<string,mixed>>
     */
    private function undatedGroups(int $supplierId): array
    {
        $groups = [];
        foreach ($this->repository->checklistWithoutDeadlineCounts($supplierId) as $itemKey => $count) {
            $groups[] = [
                'key' => self::UNDATED_PHASE . ':checklist:' . $itemKey,
                'phase' => self::UNDATED_PHASE,
                'source' => 'checklist',
                'title' => (string) $itemKey,
                'per_person' => true,
                'count' => $count,
                'oldest_due_on' => null,
                'newest_due_on' => null,
                'min_days_to_due' => null,
                'max_days_to_due' => null,
                'is_overdue' => false,
                'items' => [],
            ];
        }
        usort(
            $groups,
            static fn (array $a, array $b): int
                => [$b['count'], $a['title']] <=> [$a['count'], $b['title']],
        );

        return $groups;
    }

    /**
     * Položka checklistu tak, jak ji vidí přehled. Bez termínu nese fázi
     * {@see self::UNDATED_PHASE} a `due_on` i `days_to_due` null, tedy nic,
     * co by se dalo splést s lhůtou.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function checklistRowItem(array $row): array
    {
        $dueOn = $row['due_date'] === null ? null : (string) $row['due_date'];
        $phase = $dueOn === null ? self::UNDATED_PHASE : $this->phase($dueOn);

        return [
            'source' => 'checklist',
            'reference' => 'payroll_checklist_item:' . (int) $row['item_id'],
            'item_id' => (int) $row['item_id'],
            'title' => (string) $row['item_key'],
            'subject' => (string) $row['full_name'],
            'personal_number' => ($row['employment_code'] ?? null) === null
                ? null
                : (string) $row['employment_code'],
            'period' => null,
            'due_on' => $dueOn,
            'phase' => $phase,
            'days_to_due' => $dueOn === null ? null : $this->daysToDue($dueOn),
            'is_overdue' => $phase === 'overdue',
            'employment_id' => (int) $row['employment_id'],
            'employee_id' => (int) $row['employee_id'],
            'checklist_phase' => (string) $row['phase'],
            'deadline_source' => $row['deadline_source'],
            'deadline_source_status' => $row['deadline_source_status'],
            // `/payroll/employees/{id}` neexistuje — ta cesta byla přepsaná
            // z názvu tabulky, ne z routeru, takže odkaz z přehledu termínů
            // vedl na prázdno. Adresa karty člověka je `/payroll/people/{id}`.
            'path' => '/payroll/people/' . (int) $row['employee_id'],
        ];
    }

    /**
     * Nesplněné registrační povinnosti z detekce změn.
     *
     * Položka nese `proposal_id`, takže z přehledu vede proklik rovnou na
     * tlačítko, které povinnost splní — na rozdíl od checklistové položky
     * `social_jmhz_change`, která je jen to-do bez vazby na podání.
     *
     * @return list<array<string,mixed>>
     */
    private function registrationChangeItems(
        int $supplierId,
        string $environment,
        string $from,
        string $to,
    ): array {
        $items = [];
        foreach ($this->registrationChanges->openDeadlines(
            $supplierId,
            $environment,
            $from,
            $to,
        ) as $row) {
            $dueOn = (string) $row['due_on'];
            $items[] = [
                'source' => 'registration_change',
                'reference' => 'payroll_registration_change_proposal:'
                    . (int) $row['proposal_id'],
                'title' => (string) $row['duty_kind'],
                'subject' => (string) $row['full_name'],
                'period' => null,
                'due_on' => $dueOn,
                'phase' => $this->phase($dueOn),
                'days_to_due' => $this->daysToDue($dueOn),
                'is_overdue' => $this->phase($dueOn) === 'overdue',
                'employment_id' => (int) $row['employment_id'],
                'employee_id' => (int) $row['employee_id'],
                'proposal_id' => (int) $row['proposal_id'],
                'action_code' => $row['action_code'] === null
                    ? null
                    : (int) $row['action_code'],
                'detected_on' => (string) $row['detected_on'],
                'deadline_source' => (string) $row['deadline_source'],
                'deadline_source_status' => 'statute_verified',
                'deadline_ruleset_id' => (string) $row['deadline_ruleset_id'],
                'path' => '/payroll/people/' . (int) $row['employee_id'],
            ];
        }

        return $items;
    }

    /**
     * Nepodaná roční vyúčtování daně s termínem v okně.
     *
     * Na rozdíl od ostatních pramenů tady NENÍ řádek v evidenci, který by se
     * dal vypsat: povinnost vzniká ze zákona tím, že firma v roce vyplácela
     * příjmy ze závislé činnosti, ne tím, že ji někdo někam zapsal. Termín se
     * proto skládá obráceně — nejdřív se spočítá, které roky mají lhůtu
     * v okně, a teprve pak se u nich ověřuje podklad a stav podání. Kdyby se
     * měla povinnost nejdřív materializovat do `payroll_obligations`, znamenalo
     * by to nový agenda kód, migraci ENUMu a generátor, který jednou za rok
     * založí dva řádky — a hlavně by termín nevznikl firmě, která si modul
     * zapne v únoru, tedy přesně té, která ho potřebuje nejvíc.
     *
     * @return list<array<string,mixed>>
     */
    private function taxStatementItems(
        int $supplierId,
        string $from,
        string $to,
    ): array {
        /** @var array<string,PayrollTaxStatementDeadlineWindow> $windows */
        $windows = [];
        $firstYear = max(
            PayrollTaxStatementDeadlinePolicy::SUPPORTED_FROM_YEAR,
            (int) substr($from, 0, 4) - 1,
        );
        $lastYear = min(
            PayrollTaxStatementDeadlinePolicy::SUPPORTED_TO_YEAR,
            PayrollTaxStatementDeadlinePolicy::LAST_STATEMENT_TAX_YEAR,
            (int) substr($to, 0, 4),
        );
        for ($year = $firstYear; $year <= $lastYear; ++$year) {
            foreach (TaxStatementService::FORMS as $formCode) {
                $window = $this->taxStatementDeadlines->forYear($formCode, $year);
                if ($window->dueOn >= $from && $window->dueOn <= $to) {
                    $windows[$formCode . ':' . $year] = $window;
                }
            }
        }
        if ($windows === []) {
            return [];
        }

        $years = array_values(array_unique(array_map(
            static fn (PayrollTaxStatementDeadlineWindow $w): int => $w->year,
            $windows,
        )));
        $basis = $this->repository->taxStatementBasisYears($supplierId, $years);
        $filed = $this->repository->filedTaxStatementYears(
            $supplierId,
            TaxStatementService::FORMS,
            $years,
        );

        $items = [];
        foreach ($windows as $window) {
            $year = $window->year;
            $yearBasis = $basis[$year] ?? null;
            if ($yearBasis === null || $yearBasis['approved_runs'] < 1) {
                continue;
            }
            // Vyúčtování srážkové daně podává jen ten, kdo v roce opravdu
            // srážel. Připomínat prázdný tiskopis firmě, která má samé HPP
            // s podepsaným prohlášením, je přesně ten druh hlášky, kterou se
            // obsluha naučí přeskakovat — a s ní i tu vedle.
            if ($window->formCode === TaxStatementService::FORM_WITHHOLDING_TAX
                && $yearBasis['withholding_minor'] === 0
            ) {
                continue;
            }
            if (in_array($year, $filed[$window->formCode] ?? [], true)) {
                continue;
            }

            $items[] = [
                'source' => 'tax_statement',
                'reference' => 'tax_statement:' . $window->formCode . ':' . $year,
                'title' => $window->formCode,
                'subject' => $window->legalReference,
                // Vyúčtování je ROČNÍ; `period` je v přehledu měsíc a klient ho
                // tak i formátuje, takže „prosinec 2025" by tvrdil něco jiného
                // než tiskopis. Rok nese `statement_year`.
                'period' => null,
                'due_on' => $window->dueOn,
                'phase' => $this->phase($window->dueOn),
                'days_to_due' => $this->daysToDue($window->dueOn),
                'is_overdue' => $this->phase($window->dueOn) === 'overdue',
                'form_code' => $window->formCode,
                'statement_year' => $year,
                'statutory_due_on' => $window->statutoryDueOn,
                'electronic_due_on' => $window->electronicDueOn,
                'extendable' => $window->extendable,
                'deadline_source' => $window->legalReference,
                'deadline_source_status' => 'statute_verified',
                'deadline_ruleset_id' => $window->rulesetId,
                // Panel vyúčtování žije na mzdovém rozcestníku, ne na vlastní
                // routě; kotva doveze účetní rovnou k němu, ne na začátek
                // dlouhé stránky.
                'path' => '/payroll#payroll-tax-statement',
            ];
        }

        return $items;
    }

    /**
     * Lhůty NEMPRI a HZUPN z evidovaných případů dávek.
     *
     * Jeden případ může nést až DVĚ nesplněné povinnosti s různými termíny:
     * oznámení o žádosti o dávku (§ 97 odst. 1 a 2) a hlášení při ukončení
     * pracovní neschopnosti (§ 97 odst. 3). Vypisují se proto zvlášť — sloučit
     * je pod jednu položku by znamenalo, že splněné oznámení schová nesplněné
     * hlášení.
     *
     * HZUPN se objeví teprve tehdy, když je znám den skončení neschopnosti;
     * dřív povinnost neexistuje a politika lhůtu odmítne spočítat. Ostatní
     * chyby výpočtu (chybějící výplatní den u vyrovnávacího příspěvku) položku
     * jen přeskočí — hlídač termínů musí ukázat i to, co ví.
     *
     * @return list<array<string,mixed>>
     */
    private function sicknessCaseItems(
        int $supplierId,
        string $environment,
        string $from,
        string $to,
    ): array {
        $items = [];
        foreach ($this->sicknessCases->openCases($supplierId, $environment) as $row) {
            $kind = SicknessBenefitKind::tryFrom((string) $row['benefit_kind']);
            if ($kind === null) {
                continue;
            }
            $incapacityFrom = (string) $row['incapacity_from'];
            $incapacityTo = $row['incapacity_to'] === null
                ? null
                : (string) $row['incapacity_to'];
            // Odpracovaná celá směna v den vzniku posouvá první den neschopnosti
            // (§ 26 odst. 3) i počátek podpůrčí doby ošetřovného (§ 40 odst. 1).
            $workedFirstDay = SicknessDeadlinePolicy::firstDayShiftDefersSupport($kind)
                && SicknessCaseService::firstDayFullyWorked($row);
            $employmentEnd = ($row['employment_end_date'] ?? null) === null
                ? null
                : (string) $row['employment_end_date'];
            // Neschopnost do 14 dnů celou kryje náhrada mzdy (§ 26 odst. 1
            // zák. č. 187/2006 Sb.) — dávka z ní neplyne, NEMPRI ani HZUPN
            // se nepodávají a hlídač by strašil lhůtou, která neexistuje.
            try {
                if (!$this->sicknessDeadlines->nempriRequired($kind, $incapacityFrom, $incapacityTo, 0, $workedFirstDay)) {
                    continue;
                }
            } catch (SicknessException) {
                continue;
            }
            // Každé podání má vlastní stav: přijaté NEMPRI neschová čekající
            // HZUPN. Vyřízené (přijaté, podané předchozím programem) se
            // nehlídá, připravené čeká na odeslání ve frontě; odmítnuté se
            // vrací do hlídače, i když k němu podání existuje.
            $documents = [
                'nempri' => [
                    'agenda' => 'NEMPRI',
                    'submitted' => $this->sicknessDocumentDone($row, SicknessDocumentKind::Nempri),
                ],
            ];
            // Druhé oznámení ke dni převedení se podává současně s prvním
            // (Všeobecné zásady NEMPRI), takže má tutéž lhůtu.
            if (SicknessCaseService::transferNoticeRequired($row)) {
                $documents['nempri_transfer'] = [
                    'agenda' => SicknessDocumentKind::NempriTransfer->shortLabel(),
                    'submitted' => $this->sicknessDocumentDone($row, SicknessDocumentKind::NempriTransfer),
                ];
            }
            // HZUPN hlásí nástup po skončení neschopnosti — jen u nemocenského
            // a ne, když zaměstnání skončilo v jejím průběhu nebo vznikla až
            // v ochranné lhůtě. Totéž pravidlo jako při přípravě hlášení.
            if ($this->sicknessDeadlines->hzupnNotRequired(
                $kind,
                $incapacityFrom,
                $incapacityTo,
                $employmentEnd,
                $workedFirstDay,
            ) === null) {
                $documents['hzupn'] = [
                    'agenda' => 'HZUPN',
                    'submitted' => $this->sicknessDocumentDone($row, SicknessDocumentKind::Hzupn),
                ];
            }
            foreach ($documents as $document => $meta) {
                if ($meta['submitted']) {
                    continue;
                }
                try {
                    $window = $document !== 'hzupn'
                        ? $this->sicknessDeadlines->forNempri(
                            $kind,
                            $incapacityFrom,
                            $incapacityTo,
                            ($row['payroll_payment_date'] ?? null) === null
                                ? null
                                : (string) $row['payroll_payment_date'],
                            (bool) ($row['lone_caregiver'] ?? false),
                            $workedFirstDay,
                            SicknessDeadlinePolicy::awaitsEventMonthIncome(
                                ['relation_type' => $row['employment_relation_type'] ?? null],
                                $row,
                            ),
                        )
                        : $this->sicknessDeadlines->forHzupn(
                            $incapacityFrom,
                            $incapacityTo,
                            ($row['returned_on'] ?? null) === null
                                ? null
                                : (string) $row['returned_on'],
                        );
                } catch (SicknessException) {
                    continue;
                }
                if ($window->dueOn < $from || $window->dueOn > $to) {
                    continue;
                }
                $items[] = [
                    'source' => 'sickness_case',
                    'reference' => 'payroll_sickness_case:' . (int) $row['case_id'],
                    'title' => $meta['agenda'],
                    'subject' => (string) $row['full_name'],
                    'period' => null,
                    'due_on' => $window->dueOn,
                    'phase' => $this->phase($window->dueOn),
                    'days_to_due' => $this->daysToDue($window->dueOn),
                    'is_overdue' => $this->phase($window->dueOn) === 'overdue',
                    'case_id' => (int) $row['case_id'],
                    'document_kind' => $document,
                    'benefit_kind' => $kind->value,
                    'employment_id' => (int) $row['employment_id'],
                    'employee_id' => (int) $row['employee_id'],
                    'status' => (string) $row['status'],
                    'document_status' => SicknessCaseService::documentStatus(
                        $row,
                        SicknessDocumentKind::from($document),
                    )->value,
                    'deadline_source' => $window->legalReference,
                    'deadline_source_status' => $window->sourceStatus,
                    'deadline_ruleset_id' => $window->rulesetId,
                    // Rovnou na záložku případů dávek, ne na začátek stránky
                    // podání s měsíčním přehledem.
                    'path' => '/payroll/submissions/sickness',
                ];
            }
        }

        return $items;
    }

    /**
     * Je podání případu za hlídačem? Vyřízené ano; připravené jen tehdy, když
     * ho ČSSZ neodmítla — odmítnuté se musí podat znovu.
     *
     * @param array<string,mixed> $row
     */
    private function sicknessDocumentDone(array $row, SicknessDocumentKind $document): bool
    {
        $status = SicknessCaseService::documentStatus($row, $document);
        if ($status->isSettled()) {
            return true;
        }

        return $status !== SicknessDocumentStatus::Rejected
            && ($row[$document->submissionColumn()] ?? null) !== null;
    }

    /**
     * Roční zúčtování záloh (§ 38ch ZDP): tři lhůty za každý rok, jehož
     * termíny padnou do okna.
     *
     * - **žádosti** do 15. 2. — kolik lidí s příjmem v roce ještě nemá
     *   rozhodnuto, zda o zúčtování žádá. Jen dokud lhůta neuplynula: po ní už
     *   zaměstnanec požádat nemůže a připomínka by jen strašila.
     * - **provedení** do 31. 3. — kdo požádal a zúčtování ještě nemá.
     * - **vrácení přeplatku** — provedené zúčtování s přeplatkem, který ještě
     *   nevyplatil žádný mzdový běh.
     *
     * @return list<array<string,mixed>>
     */
    private function annualSettlementItems(
        int $supplierId,
        string $from,
        string $to,
    ): array {
        $years = [];
        for ($year = (int) substr($from, 0, 4) - 1; $year <= (int) substr($to, 0, 4); ++$year) {
            $years[] = $year;
        }
        $requestDue = static fn (int $year): string
            => AnnualSettlementStatute::requestDeadline($year)->format('Y-m-d');
        $performDue = static fn (int $year): string
            => AnnualSettlementStatute::settlementDeadline($year)->format('Y-m-d');
        $refundDue = static fn (int $year): string
            => sprintf('%04d-%s', $year + 1, self::ANNUAL_REFUND_DUE_MONTH_DAY);
        $inWindow = static fn (string $due): bool => $due >= $from && $due <= $to;

        $items = [];
        $requestYears = array_values(array_filter(
            $years,
            fn (int $year): bool => $inWindow($requestDue($year))
                && $this->phase($requestDue($year)) !== 'overdue',
        ));
        foreach ($this->repository->annualSettlementUndecidedCounts($supplierId, $requestYears) as $year => $count) {
            if ($count < 1) {
                continue;
            }
            $items[] = $this->annualSettlementItem(
                'annual_settlement_request',
                'annual_settlement_request:' . $year,
                (string) $count,
                $year,
                $requestDue($year),
                null,
                '§ 38ch odst. 1 zákona č. 586/1992 Sb.',
            ) + ['undecided_count' => $count];
        }
        $performYears = array_values(array_filter($years, fn (int $y): bool => $inWindow($performDue($y))));
        foreach ($this->repository->annualSettlementsToPerform($supplierId, $performYears) as $row) {
            $items[] = $this->annualSettlementItem(
                'annual_settlement_perform',
                'annual_settlement_perform:' . $row['tax_year'] . ':' . $row['employee_id'],
                $row['full_name'],
                $row['tax_year'],
                $performDue($row['tax_year']),
                $row['employee_id'],
                '§ 38ch odst. 4 zákona č. 586/1992 Sb.',
            );
        }
        $refundYears = array_values(array_filter($years, fn (int $y): bool => $inWindow($refundDue($y))));
        foreach ($this->repository->annualSettlementRefundsUnpaid($supplierId, $refundYears) as $row) {
            $items[] = $this->annualSettlementItem(
                'annual_settlement_refund',
                'annual_settlement_refund:' . $row['tax_year'] . ':' . $row['employee_id'],
                $row['full_name'],
                $row['tax_year'],
                $refundDue($row['tax_year']),
                $row['employee_id'],
                '§ 38ch odst. 5 zákona č. 586/1992 Sb., § 141 odst. 1 zákoníku práce',
            ) + ['remaining_minor' => $row['payable_minor']];
        }

        return $items;
    }

    /** @return array<string,mixed> */
    private function annualSettlementItem(
        string $title,
        string $reference,
        string $subject,
        int $taxYear,
        string $dueOn,
        ?int $employeeId,
        string $legalReference,
    ): array {
        $query = 'year=' . $taxYear . ($employeeId === null ? '' : '&person=' . $employeeId);

        return [
            'source' => 'annual_settlement',
            'reference' => $reference,
            'title' => $title,
            'subject' => $subject,
            'period' => null,
            'due_on' => $dueOn,
            'phase' => $this->phase($dueOn),
            'days_to_due' => $this->daysToDue($dueOn),
            'is_overdue' => $this->phase($dueOn) === 'overdue',
            'statement_year' => $taxYear,
            'employee_id' => $employeeId,
            'deadline_source' => $legalReference,
            'deadline_source_status' => 'statute_verified',
            'path' => '/payroll/annual-settlement?' . $query,
        ];
    }

    /**
     * Konec platnosti povolení cizince bez nástupce.
     *
     * @return list<array<string,mixed>>
     */
    private function foreignPermitItems(
        int $supplierId,
        string $from,
        string $to,
    ): array {
        $items = [];
        foreach ($this->repository->foreignPermitExpiries($supplierId, $from, $to) as $row) {
            $dueOn = $row['valid_until'];
            $items[] = [
                'source' => 'foreign_permit',
                'reference' => 'payroll_foreign_permit:' . $row['permit_id'],
                'title' => 'foreign_permit_' . $row['permit_kind'],
                'subject' => $row['full_name'],
                'period' => null,
                'due_on' => $dueOn,
                'phase' => $this->phase($dueOn),
                'days_to_due' => $this->daysToDue($dueOn),
                'is_overdue' => $this->phase($dueOn) === 'overdue',
                'employee_id' => $row['employee_id'],
                'permit_id' => $row['permit_id'],
                'permit_label' => $row['permit_label'],
                'deadline_source' => '§ 89 zákona č. 435/2004 Sb.',
                'deadline_source_status' => 'statute_verified',
                'path' => '/payroll/people/' . $row['employee_id'],
            ];
        }

        return $items;
    }

    /**
     * Žádost zaměstnance o potvrzení o zdanitelných příjmech (§ 38j odst. 3
     * ZDP) mimo výstupní checklist — u trvajícího vztahu.
     *
     * @return list<array<string,mixed>>
     */
    private function taxableIncomeRequestItems(
        int $supplierId,
        string $from,
        string $to,
    ): array {
        $items = [];
        foreach ($this->repository->taxableIncomeRequestDeadlines($supplierId, $from, $to) as $row) {
            $dueOn = $row['due_on'];
            $phase = $this->phase($dueOn);
            $query = ['person' => $row['employee_id'], 'panel' => 'taxable_income_requests'];
            if ($row['employment_id'] !== null) {
                $query['employment'] = $row['employment_id'];
            }
            $items[] = [
                'source' => 'taxable_income_request',
                'reference' => 'payroll_taxable_income_request:' . $row['request_id'],
                'title' => 'taxable_income_request',
                'subject' => $row['full_name'],
                'period' => (string) $row['income_year'],
                'due_on' => $dueOn,
                'phase' => $phase,
                'days_to_due' => $this->daysToDue($dueOn),
                'is_overdue' => $phase === 'overdue',
                'employee_id' => $row['employee_id'],
                'employment_id' => $row['employment_id'],
                'request_id' => $row['request_id'],
                'deadline_source' => '§ 38j odst. 3 zákona č. 586/1992 Sb. — do 10 dnů od žádosti ze dne '
                    . (new \DateTimeImmutable($row['requested_on']))->format('j. n. Y'),
                'deadline_source_status' => 'statute_verified',
                'path' => '/payroll/people?' . http_build_query($query),
            ];
        }

        return $items;
    }

    /**
     * Výzvy a žádosti v důchodovém pojištění (evidenční list na výzvu nebo
     * po úmrtí, oprava hlášení na výzvu, potvrzení podle § 42 a § 37 odst. 2,
     * potvrzení podle znění do 31. 12. 2025). Termín počítá
     * {@see \MyInvoice\Service\Payroll\Submission\Eldp\PensionRequestDeadlinePolicy};
     * výzva navázaná na připravený evidenční list tu chybí, její termín nese
     * povinnost listu v pramenu podání.
     *
     * @return list<array<string,mixed>>
     */
    private function pensionRequestItems(
        int $supplierId,
        string $from,
        string $to,
    ): array {
        $items = [];
        foreach ($this->repository->pensionRequestDeadlines($supplierId, $from, $to) as $row) {
            $dueOn = $row['due_on'];
            $phase = $this->phase($dueOn);
            $query = ['person' => $row['employee_id'], 'panel' => 'pension_requests'];
            if ($row['employment_id'] !== null) {
                $query['employment'] = $row['employment_id'];
            }
            $items[] = [
                'source' => 'pension_request',
                'reference' => 'payroll_pension_request:' . $row['request_id'],
                'title' => 'pension_request_' . ($row['legacy_kind'] ?? $row['request_kind']),
                'subject' => $row['full_name'],
                'period' => $row['period_year'] !== null
                    ? (string) $row['period_year']
                    : ($row['period_from'] !== null ? substr($row['period_from'], 0, 7) : null),
                'due_on' => $dueOn,
                'phase' => $phase,
                'days_to_due' => $this->daysToDue($dueOn),
                'is_overdue' => $phase === 'overdue',
                'employee_id' => $row['employee_id'],
                'employment_id' => $row['employment_id'],
                'request_id' => $row['request_id'],
                'deadline_source' => $row['legal_basis'],
                'deadline_source_status' => 'statute_verified',
                'path' => '/payroll/people?' . http_build_query($query),
            ];
        }

        return $items;
    }

    /**
     * Vyúčtování pracovní cesty (§ 183 odst. 1 ZP): nejdřív lhůta zaměstnance
     * na předložení dokladů, po jejich předložení lhůta zaměstnavatele na
     * vyúčtování (schválení cesty).
     *
     * @return list<array<string,mixed>>
     */
    private function businessTripItems(
        int $supplierId,
        string $from,
        string $to,
    ): array {
        // Deset pracovních dnů je nejvýš ~16 kalendářních; měsíc rezervy stačí
        // i přes vánoční svátky.
        $arrivedFrom = (new \DateTimeImmutable($from))->modify('-40 days')->format('Y-m-d');
        $items = [];
        foreach ($this->repository->openBusinessTrips($supplierId, $arrivedFrom) as $row) {
            $submitted = $row['documents_submitted_on'];
            if ($submitted === null) {
                $arrivalLocal = (new \DateTimeImmutable($row['arrival_at_utc'], new \DateTimeZone('UTC')))
                    ->setTimezone(new \DateTimeZone($row['timezone_name']))
                    ->format('Y-m-d');
                $dueOn = BusinessTripSettlementDeadlinePolicy::documentsDueOn($arrivalLocal);
                $title = 'business_trip_documents';
                $source = BusinessTripSettlementDeadlinePolicy::SOURCE_DOCUMENTS;
            } else {
                $dueOn = BusinessTripSettlementDeadlinePolicy::settlementDueOn($submitted);
                $title = 'business_trip_settlement';
                $source = BusinessTripSettlementDeadlinePolicy::SOURCE_SETTLEMENT;
            }
            if ($dueOn < $from || $dueOn > $to) {
                continue;
            }
            $phase = $this->phase($dueOn);
            $items[] = [
                'source' => 'business_trip',
                'reference' => 'payroll_business_trip:' . $row['trip_id'],
                'title' => $title,
                'subject' => $row['full_name'],
                'period' => null,
                'due_on' => $dueOn,
                'phase' => $phase,
                'days_to_due' => $this->daysToDue($dueOn),
                'is_overdue' => $phase === 'overdue',
                'employee_id' => $row['employee_id'],
                'employment_id' => $row['employment_id'],
                'trip_id' => $row['trip_id'],
                'trip_label' => $row['destination_place'],
                'trip_period' => $row['settlement_period'],
                'deadline_source' => $source,
                'deadline_source_status' => 'statute_verified',
                'path' => '/payroll/travel?' . http_build_query([
                    'period' => $row['settlement_period'],
                    'trip' => $row['trip_id'],
                ]),
            ];
        }

        return $items;
    }

    /**
     * Fáze termínu u pramenů, které stav podání nemají.
     *
     * Prahy jsou schválně tytéž jako v {@see PayrollDeadlineAssessmentService}
     * — kdyby se rozešly, znamenalo by „brzy" na dashboardu něco jiného než
     * „brzy" u povinnosti podání a přehled by si protiřečil sám se sebou.
     */
    private function phase(string $dueOn): string
    {
        $days = $this->daysToDue($dueOn);
        if ($days < 0) {
            return 'overdue';
        }
        if ($days === 0) {
            return 'due_today';
        }

        return $days <= self::DUE_SOON_DAYS ? 'due_soon' : 'open';
    }

    private function daysToDue(string $dueOn): int
    {
        $due = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $dueOn,
            new \DateTimeZone('Europe/Prague'),
        );
        if (!$due instanceof \DateTimeImmutable
            || $due->format('Y-m-d') !== $dueOn
        ) {
            throw new \UnexpectedValueException(
                'Termín mzdové povinnosti není platné datum.',
            );
        }

        return (int) $this->today()->diff($due)->format('%r%a');
    }

    private function today(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new \DateTimeZone('Europe/Prague'))
            ->setTime(0, 0);
    }
}
