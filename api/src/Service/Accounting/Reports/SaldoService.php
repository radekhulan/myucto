<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\LedgerReportRepository;
use MyInvoice\Repository\SaldoRepository;
use PDO;

/**
 * Saldokonto k rozvahovému dni (audit 2026-07, nález H13 — fáze D6/1).
 *
 * Pro každý saldokontní účet (default 311/321/314/324) sestaví:
 *   • otevřené položky per partner z faktur (SaldoRepository::openItems) —
 *     nezaplacené/částečně zaplacené doklady s vazbou na svůj zápis v deníku,
 *   • KONFRONTACI Σ otevřených položek proti zůstatku účtu z hlavní knihy k asOf
 *     (LedgerReportRepository::syntheticBalances — stejná definice zůstatku jako
 *     Rozvaha, vč. anchoru po uzávěrce a bez závěrkového zápisu). Rozdíl = inventarizační hodnota:
 *     odhalí ruční zápisy, storna, zapomenuté faktury i cizoměnové neúčtované úhrady,
 *     které nejsou pokryté fakturačním zdrojem.
 *
 * Zůstatek i položky jsou v CZK (base ccy) → konfrontace je apples-to-apples.
 * Kontrola rozdílu v HALÉŘÍCH (shodně s ostatními sestavami F2).
 *
 * Post-review (adversariální audit) fixy: položky z repository přichází SIGNED
 * (bez abs()) a s bezznaménkovým `paid_ratio` — `buildAccount()` je orientuje na
 * normální stranu účtu STEJNĚ jako `gl_balance`, takže dobropis (opačná strana
 * účtu) se v netto součtu partnera správně ODEČÍTÁ (H1), a `paid_ratio` čte
 * zdroj pravdy platby dle typu dokladu, ne jen bankovní `payment_matches`, takže
 * hotovostní/ruční úhrady se do "uhrazeno" počítají (H2). Filtrace uzavřených
 * položek je `remaining === 0` (ne `<= 0`), aby se nepřehlédly otevřené záporné
 * položky (dobropisy, přeplatky).
 */
final class SaldoService
{
    /** Výchozí saldokontní účty (odběratelé/dodavatelé/poskytnuté a přijaté zálohy). */
    public const DEFAULT_ACCOUNTS = ['311', '321', '314', '324'];

    /**
     * Strop otevřených položek na JEDNU sestavu (přes všechny účty dohromady). Nad ním
     * sestava skončí 422 `too_many_rows` — stejný vzor jako `JournalExportService::MAX_ROWS`.
     *
     * Proč strop vůbec: saldokonto bylo jediná sestava bez horní hranice počtu řádků.
     * Nad ~400 tis. zaúčtovanými doklady spolklo přes 750 MB a spadlo na `memory_limit`
     * (v Dockeru s 256 MB už kolem 200 tis.) — a s ním i měsíční kontrola úplnosti,
     * která saldo volá dvakrát.
     *
     * Proč zrovna 25 000 a ne 5 000 jako u exportu deníku: nižší strop by odmítl i
     * sestavy, které dnes bez potíží projdou. 25 000 položek drží špičku hluboko pod
     * dockerovým limitem a přitom nechá projít vše, co je dnes použitelné. Nad tím už
     * sestava není čitelná ani jako pracovní podklad a účetní ji stejně musí zúžit
     * (`account`, `partner_id`, `as_of`) — což hláška říká.
     *
     * ODMÍTNOUT, ne useknout: saldokonto je inventarizační podklad (§ 29–30 ZoÚ) a
     * konfrontace se zůstatkem hlavní knihy dává smysl jen nad ÚPLNÝM seznamem.
     * Useknutý seznam by tiše vykázal nesouhlasný rozdíl jako inventarizační nález.
     */
    public const MAX_OPEN_ITEMS = 25000;

    /** Strop `per_page` pro stránkování náhledu — shodně s `AccountStatementAction`. */
    public const MAX_PER_PAGE = 200;

    public function __construct(
        private readonly Connection $db,
        private readonly SaldoRepository $saldo,
        private readonly LedgerReportRepository $ledger,
        private readonly AccountingPeriodRepository $periods,
    ) {}

    /**
     * @param string|null $accountFilter kód účtu (311/321/…) nebo null/'all' = default sada
     * @param int|null    $partnerId     volitelný filtr na jednoho partnera
     * @param int|null    $page          stránka SEZNAMU PARTNERŮ (1..N); null = celá sestava
     * @param int|null    $perPage       partnerů na stránku (max {@see MAX_PER_PAGE})
     * @return array<string,mixed>
     * @throws ReportException 422 too_many_rows nad {@see MAX_OPEN_ITEMS}
     */
    public function build(
        int $supplierId,
        int $periodId,
        ?string $asOf,
        ?string $accountFilter = null,
        ?int $partnerId = null,
        ?int $page = null,
        ?int $perPage = null,
    ): array {
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            throw new ReportException('period_not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }
        if ($asOf === null || $asOf === '') {
            $asOf = min((string) $period['ends_on'], date('Y-m-d'));
        }

        // Saldokonto k libovolnému datu napříč obdobími (task #3, D6/2): asOf smí
        // ležet MIMO vybrané $periodId (i v uzavřeném/approved období) — SaldoAction
        // už to nevaliduje proti hranicím vybraného období. Anchor pro
        // LedgerReportRepository::syntheticBalances() (PS okno R6) proto musí
        // odpovídat období, do kterého asOf SKUTEČNĚ spadá, ne vybranému
        // $periodId z dropdownu — jinak by se u výsledkových účtů (revenue/expense)
        // PS okno počítalo od starts_on cizího období. Rozvahové účty (311/321/…,
        // DEFAULT_ACCOUNTS) tohle přímo nepostihne (jejich okno řídí
        // LedgerReportRepository::openingAnchor() odvozený čistě z asOf), ale
        // sjednocení je bezpečnější než spoléhat na tuhle vlastnost implicitně —
        // SSOT stejný jako FinancialStatementService::buildStatement().
        $asOfPeriod = $this->periods->findForDate($supplierId, $asOf);
        $anchorStartsOn = $asOfPeriod !== null ? (string) $asOfPeriod['starts_on'] : null;

        $explicit = $accountFilter !== null && $accountFilter !== '' && $accountFilter !== 'all';
        $codes = $explicit ? [$accountFilter] : self::DEFAULT_ACCOUNTS;
        $balances = [];
        foreach ($this->ledger->syntheticBalances($supplierId, $asOf, $anchorStartsOn) as $balance) {
            $balances[(string) $balance['code']] = round((float) $balance['md'] - (float) $balance['d'], 2);
        }

        // Rozpočet otevřených položek je společný pro celou sestavu: každý účet dostane
        // jen zbytek po předchozích, takže ani sestava přes všechny účty nepřeteče strop.
        $budget = self::MAX_OPEN_ITEMS;

        $accounts = [];
        foreach ($codes as $code) {
            $block = $this->buildAccount($supplierId, $asOf, (string) $code, $partnerId, $balances, $budget);
            if ($block === null) {
                continue; // účet není v osnově firmy
            }
            $budget -= $block['open_items_count'];
            // U default sady vynech účty bez zůstatku i bez položek (nezaplevelovat 314/324);
            // u explicitně zvoleného účtu zobraz vždy.
            if (!$explicit && self::cents($block['gl_balance']) === 0 && $block['partners'] === []) {
                continue;
            }
            $accounts[] = $block;
        }

        // Stránkuje se AŽ TEĎ a jen SEZNAM PARTNERŮ k zobrazení. `gl_balance`,
        // `open_items_total`, `difference` i `matches` zůstávají za CELOU sestavu —
        // konfrontace se zůstatkem hlavní knihy je účetní tvrzení o celku, ne o
        // stránce, a číslo spočítané ze stránky by bylo nepravdivé. Export
        // (`SaldoAction::export`) stránkování nepoužívá vůbec.
        foreach ($accounts as &$block) {
            $block = self::paginatePartners($block, $page, $perPage);
        }
        unset($block);

        return [
            'as_of'   => $asOf,
            'entity'  => $this->loadEntity($supplierId),
            'period'  => [
                'id'          => (int) $period['id'],
                'fiscal_year' => (int) $period['fiscal_year'],
                'starts_on'   => (string) $period['starts_on'],
                'ends_on'     => (string) $period['ends_on'],
                'status'      => (string) $period['status'],
            ],
            // Období, do kterého asOf skutečně spadá (task #3) — liší se od 'period'
            // výše, když si účetní zvolí datum mimo vybrané období z dropdownu
            // (typicky rozvahový den uzavřeného roku při pohledu z otevřeného).
            // null = pro asOf není v accounting_periods založené žádné období.
            'as_of_period' => $asOfPeriod === null ? null : [
                'id'          => (int) $asOfPeriod['id'],
                'fiscal_year' => (int) $asOfPeriod['fiscal_year'],
                'starts_on'   => (string) $asOfPeriod['starts_on'],
                'ends_on'     => (string) $asOfPeriod['ends_on'],
                'status'      => (string) $asOfPeriod['status'],
            ],
            'accounts' => $accounts,
        ];
    }

    /**
     * @param array<string,float> $balances signed zůstatky syntetik k asOf
     * @param int                 $budget   kolik otevřených položek smí účet ještě přidat
     * @return array<string,mixed>|null null = účet v osnově neexistuje
     * @throws ReportException 422 too_many_rows
     */
    private function buildAccount(int $supplierId, string $asOf, string $code, ?int $partnerId, array $balances, int $budget): ?array
    {
        $acc = $this->saldo->resolveAccount($supplierId, $code);
        if ($acc === null) {
            return null;
        }

        // Stejná definice jako rozvaha: zůstatek k asOf bez závěrkových zápisů.
        $signed = $balances[$acc['code']] ?? 0.0;
        $normalSide = $acc['normal_side'] ?? (in_array($acc['account_type'], ['asset', 'expense'], true) ? 'debit' : 'credit');
        // Zůstatek na normální straně účtu (kladný): pohledávka MD, závazek D.
        $glBalance = $normalSide === 'debit' ? $signed : -$signed;

        // O jeden řádek víc, než smí projít — přetečení se pozná, aniž by se celý
        // (potenciálně milionový) seznam musel načíst do paměti.
        $rawItems = $this->saldo->openItems($supplierId, $acc['id'], $asOf, $acc['code'], max(1, $budget) + 1, $partnerId);

        /** @var array<int, array{partner_id:int, partner_name:string, total_remaining:float, items:list<array<string,mixed>>}> $byPartner */
        $byPartner = [];
        $openTotalCents = 0;
        $openCount = 0;
        foreach ($rawItems as $it) {
            if ($partnerId !== null && $it['partner_id'] !== $partnerId) {
                continue; // pojistka — filtr partnera už provedlo SQL
            }

            // Orientace na normální stranu účtu (post-review fix H1): stejná transformace
            // jako u gl_balance výše. Dobropis (booked_signed opačného znaménka než
            // běžná faktura na daném účtu) tak vyjde ZÁPORNÝ, ne abs()-ovaný na další
            // kladnou pohledávku — v netto součtu partnera se správně odečte.
            // paid_ratio je bezznaménkový skalár (0..1) ze zdroje pravdy platby dokladu
            // (H2 fix) — vynásobením zachová znaménko bookedNative automaticky.
            $bookedNative = $normalSide === 'debit' ? $it['booked_signed'] : -$it['booked_signed'];
            $foreignNative = $normalSide === 'debit' ? $it['foreign_signed'] : -$it['foreign_signed'];
            $paidNative = round($bookedNative * $it['paid_ratio'], 2);
            $remaining = round($bookedNative - $paidNative, 2);

            if (self::cents($remaining) === 0) {
                continue; // plně uhrazené / netto vyrovnané = uzavřená položka
            }
            if (++$openCount > $budget) {
                throw new ReportException(
                    'too_many_rows',
                    'Saldokonto má k tomuto dni víc než ' . self::MAX_OPEN_ITEMS
                        . ' otevřených položek — zúžte výběr (jeden účet, jeden partner nebo dřívější datum). '
                        . 'Useknutý seznam by konfrontaci se zůstatkem hlavní knihy vykázal jako inventarizační rozdíl.',
                    422,
                );
            }
            $daysOverdue = 0;
            if ($it['due_date'] !== '' && $asOf > $it['due_date']) {
                $daysOverdue = (int) (new \DateTimeImmutable($it['due_date']))->diff(new \DateTimeImmutable($asOf))->days;
            }
            $pid = $it['partner_id'];
            if (!isset($byPartner[$pid])) {
                $byPartner[$pid] = [
                    'partner_id'      => $pid,
                    'partner_name'    => $it['partner_name'],
                    'total_remaining' => 0.0,
                    'items'           => [],
                ];
            }
            $byPartner[$pid]['items'][] = [
                'doc_type'      => $it['doc_type'],
                'doc_id'        => $it['doc_id'],
                'doc_no'        => $it['doc_no'],
                'issue_date'    => $it['issue_date'],
                'due_date'      => $it['due_date'],
                'currency_code' => $it['currency_code'],
                'amount_foreign' => $it['currency_code'] !== 'CZK' ? $foreignNative : 0.0,
                'booked_czk'    => $bookedNative,
                'paid_czk'      => $paidNative,
                'remaining_czk' => $remaining,
                'days_overdue'  => $daysOverdue,
            ];
            $byPartner[$pid]['total_remaining'] = round($byPartner[$pid]['total_remaining'] + $remaining, 2);
            $openTotalCents += self::cents($remaining);
        }

        $partners = array_values($byPartner);
        usort($partners, static fn (array $a, array $b): int => strcmp((string) $a['partner_name'], (string) $b['partner_name']));

        $openTotal = $openTotalCents / 100;
        $difference = round($glBalance - $openTotal, 2);

        return [
            'account' => [
                'id'          => $acc['id'],
                'code'        => $acc['code'],
                'name'        => $acc['name'],
                'normal_side' => $normalSide,
            ],
            'gl_balance'       => $glBalance,
            'open_items_total' => $openTotal,
            'open_items_count' => $openCount,
            'difference'       => $difference,
            'matches'          => self::cents($difference) === 0,
            'partners'         => $partners,
        ];
    }

    /**
     * Doplní bloku účtu stránkovací hlavičku a případně ořízne `partners` na stránku.
     * `$page === null` = celá sestava na jedné stránce (výchozí chování, kvůli exportu
     * i zpětné kompatibilitě klienta).
     *
     * @param array<string,mixed> $block
     * @return array<string,mixed>
     */
    private static function paginatePartners(array $block, ?int $page, ?int $perPage): array
    {
        /** @var list<array<string,mixed>> $partners */
        $partners = $block['partners'];
        $total = count($partners);

        if ($page === null) {
            $block['partners_pagination'] = [
                'page' => 1, 'per_page' => max(1, $total), 'total' => $total, 'pages' => 1,
            ];
            return $block;
        }

        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage ?? 50));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));

        $block['partners'] = array_slice($partners, ($page - 1) * $perPage, $perPage);
        $block['partners_pagination'] = [
            'page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => $pages,
        ];

        return $block;
    }

    /**
     * @return array{name:string, ico:?string, address:string, prepared_at:string}
     */
    private function loadEntity(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT company_name, street, city, zip, ic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ReportException('supplier_not_found', 'Firma #' . $supplierId . ' neexistuje.', 404);
        }
        $address = array_filter([
            trim((string) ($row['street'] ?? '')),
            trim(trim((string) ($row['zip'] ?? '')) . ' ' . trim((string) ($row['city'] ?? ''))),
        ], static fn (string $part): bool => $part !== '');
        return [
            'name'        => (string) $row['company_name'],
            'ico'         => $row['ic'],
            'address'     => implode(', ', $address),
            'prepared_at' => date('Y-m-d H:i'),
        ];
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100.0);
    }
}
