<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use PDO;

/**
 * Přehled o peněžních tocích — § 18 odst. 2 ZoÚ, § 40 až § 43 vyhl. 500/2002 Sb.
 *
 * U velké a střední účetní jednotky (a u každé, která má povinný audit) je součástí
 * závěrky stejně jako rozvaha a výsledovka. Systém ho neuměl vůbec, takže balíček
 * závěrky takové firmě hlásil „hotovo" u závěrky, které dvě povinné části chyběly.
 *
 * ── Proč PŘÍMÁ klasifikace pohybů, a ne nepřímá metoda ──────────────────────
 * Nepřímá metoda staví provozní tok jako výsledek hospodaření upravený o nepeněžní
 * operace a o změny pracovního kapitálu. Každá z těch úprav je odhad nad agregáty
 * (odpisy, opravné položky, rezervy, změny pohledávek a zásob) a případný nesoulad se
 * skutečným pohybem peněz se v ní schová do zbytkové položky — výkaz pak formálně
 * „sedí", i když je špatně.
 *
 * Tady se místo toho klasifikuje KAŽDÝ pohyb na peněžních účtech podle protiúčtu
 * v témže zápisu. Výhoda je zásadní: součet toků se rovná skutečné změně stavu peněz
 * KONSTRUKČNĚ, ne dopočtem. Vyhláška přímou metodu u provozní činnosti připouští
 * (§ 43 odst. 1), takže nejde o odchylku od předpisu, ale o volbu metody.
 *
 * Zbytek, který nejde zařadit (protiúčet neodpovídá žádné skupině), se NESLUČUJE do
 * provozní činnosti — vykazuje se samostatně jako `unclassified`. Tichý přesun do
 * provozu by byl přesně ten druh „výkaz sedí, ale lže", kterému se tahle metoda vyhýbá.
 *
 * ── Zařazení podle protiúčtu ────────────────────────────────────────────────
 *   INVESTIČNÍ  0xx  dlouhodobý majetek (pořízení, prodej, zálohy na něj)
 *   FINANČNÍ    4xx  vlastní kapitál, rezervy, dlouhodobé závazky, úvěry
 *               231/232/461  krátkodobé a dlouhodobé úvěry a výpomoci
 *   PROVOZNÍ    zbytek (pohledávky, závazky, zásoby, náklady, výnosy, daně, mzdy)
 *
 * Převod mezi vlastními peněžními účty (211 ↔ 221, peníze na cestě 261) se vylučuje —
 * není to tok „ven ani dovnitř", jen přesun uvnitř skupiny peněžních prostředků.
 *
 * Read-only: nic neúčtuje.
 */
final class CashFlowStatementService
{
    /**
     * Peněžní prostředky a ekvivalenty (§ 41 vyhlášky) — pokladna, účty, ceniny
     * a peníze na cestě.
     *
     * Peníze na cestě (261) sem patří ZE STEJNÉHO důvodu jako pokladna: převod mezi
     * účtem a pokladnou přes 261 není tok ven ani dovnitř. Dřív se 261 vylučovala jen
     * jako PROTIÚČET, ale do stavu peněz se nepočítala — převod přes přelom měsíce pak
     * z výkazu zmizel na jedné straně a součet toků přestal sedět na změnu stavu.
     */
    private const CASH_PREFIXES = ['211', '213', '221', '261'];

    /**
     * Uzávěrkové a otevírací zápisy (701/702/710) NEJSOU peněžní toky.
     *
     * Uzávěrkový zápis převádí zůstatek peněžního účtu na 702, otevírací ho z 701 zase
     * nastavuje. Bez vyloučení se obojí objevilo mezi „provozní činností" v řádech
     * desítek milionů a výkaz uzavřeného roku byl nesmyslný — a stejný zápis srazil
     * konečný stav peněz na nulu, protože uzávěrka peněžní účty vynuluje.
     */
    private const BOOKKEEPING_SOURCE_TYPES = ['opening', 'closing'];

    public function __construct(
        private readonly Connection $db,
        private readonly AccountingPeriodRepository $periods,
        // Mezičlen plateb kartou může ležet pod 261 — jeho analytiky ale peníze nejsou:
        // platba kartou odešla z banky v den platby, ne až při vypořádání s dokladem.
        private readonly \MyInvoice\Service\Accounting\Card\CardClearingAccounts $cardAccounts,
    ) {}

    /** SQL podmínka „účet není analytikou mezičlenu karty" pro alias účtu (prázdná, když karty nejsou). */
    private function notCardClearing(int $supplierId, string $alias): string
    {
        $codes = $this->cardAccounts->allClearingCodes($supplierId);
        if ($codes === []) {
            return '1 = 1';
        }
        $pdo = $this->db->pdo();
        return "{$alias}.account_code NOT IN (" . implode(', ', array_map(
            static fn (string $c): string => (string) $pdo->quote($c),
            $codes,
        )) . ')';
    }

    /**
     * @return array{
     *   period:array{id:int, starts_on:string, ends_on:string},
     *   opening:float, closing:float, net_change:float,
     *   operating:float, investing:float, financing:float, unclassified:float,
     *   reconciles:bool,
     *   breakdown:array{operating:list<array<string,mixed>>, investing:list<array<string,mixed>>, financing:list<array<string,mixed>>, unclassified:list<array<string,mixed>>}
     * }
     */
    public function build(int $supplierId, int $periodId): array
    {
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            throw new ReportException('period_not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }
        $startsOn = (string) $period['starts_on'];
        $endsOn = (string) $period['ends_on'];

        // Počáteční stav se bere K PRVNÍMU DNI období, protože otevírací zápis má
        // `entry_date = starts_on`. Kdyby se bral „den před", firma přenášející zůstatky
        // otevíracím zápisem by měla počáteční stav nula a celý výkaz by byl posunutý.
        //
        // Z OBOU stavů se vyřazuje uzávěrkový zápis REPORTOVANÉHO období — ten peněžní
        // účty vynuluje, takže by konečný stav uzavřeného roku vyšel jako nula. Starší
        // uzávěrkové a otevírací zápisy se naopak ponechávají: jsou navzájem zrcadlové
        // a ruší se, kdežto vyřazení jen uzávěrek by minulý zůstatek započetlo dvakrát
        // (naměřeno v produkci: konečný stav vyšel přesně dvojnásobný).
        $opening = $this->cashBalance($supplierId, $startsOn, $startsOn, $endsOn, true);
        $closing = $this->cashBalance($supplierId, $endsOn, $startsOn, $endsOn, false);

        $groups = ['operating' => [], 'investing' => [], 'financing' => [], 'unclassified' => []];
        $totals = ['operating' => 0.0, 'investing' => 0.0, 'financing' => 0.0, 'unclassified' => 0.0];

        foreach ($this->cashMovements($supplierId, $startsOn, $endsOn) as $row) {
            $group = self::classify((string) $row['counter_code']);
            $amount = round((float) $row['amount'], 2);
            $totals[$group] += $amount;
            $groups[$group][] = [
                'account_code' => (string) $row['counter_code'],
                'name'         => (string) $row['counter_name'],
                'amount'       => $amount,
            ];
        }

        $netChange = round($closing - $opening, 2);
        $sum = round(array_sum($totals), 2);

        return [
            'period'       => ['id' => (int) $period['id'], 'starts_on' => $startsOn, 'ends_on' => $endsOn],
            'opening'      => $opening,
            'closing'      => $closing,
            'net_change'   => $netChange,
            'operating'    => round($totals['operating'], 2),
            'investing'    => round($totals['investing'], 2),
            'financing'    => round($totals['financing'], 2),
            'unclassified' => round($totals['unclassified'], 2),
            // Musí sedět na haléře. Kdyby ne, je chyba v datech (např. zápis, kde stojí
            // peněžní účet proti peněžnímu mimo 261) a výkaz se nesmí tvářit, že sedí.
            'reconciles'   => (int) round($sum * 100) === (int) round($netChange * 100),
            'breakdown'    => $groups,
        ];
    }

    /**
     * Pohyby peněz rozložené podle NEPENĚŽNÍCH stran zápisů, které se peněz dotýkají.
     *
     * ── Proč se nesčítá částka peněžního řádku podle protiúčtu ──────────────────
     * Původní dotaz spojoval peněžní řádek s každým protiřádkem téhož zápisu a sčítal
     * PENĚŽNÍ částku. U zápisu s jedním peněžním řádkem a N protiúčty se tak celá
     * částka započetla N× — jednou ke každému protiúčtu. V produkci z toho vyšlo číslo
     * o dva řády vedle a s obráceným znaménkem, a týž obnos svítil na několika
     * nesouvisejících účtech naráz.
     *
     * ── Proč rozklad přes protistrany sedí KONSTRUKČNĚ ──────────────────────────
     * Zápis je vyvážený, tedy Σ(MD − D) přes všechny řádky = 0. Rozdělíme-li řádky na
     * peněžní a nepeněžní, platí
     *
     *     Σ_peněžní (MD − D)  =  − Σ_nepeněžní (MD − D)  =  Σ_nepeněžní (D − MD)
     *
     * takže přiřadit každému nepeněžnímu řádku částku (D − MD) je PŘESNÝ rozklad
     * změny stavu peněz daného zápisu — bez násobení, bez zaokrouhlovacího zbytku
     * a bez potřeby cokoli dopočítávat.
     *
     * Převod uvnitř peněžních prostředků (211 ↔ 221, přes 261) vypadne sám: takový
     * zápis nemá nepeněžní řádek, takže do výkazu nepřispěje ničím. Převod s bankovním
     * poplatkem přispěje právě tím poplatkem, což je správně.
     *
     * @return list<array<string,mixed>>
     */
    private function cashMovements(int $supplierId, string $from, string $to): array
    {
        $nonCash = '(NOT (' . implode(' OR ', array_map(
            static fn (string $p): string => "a.account_code LIKE '{$p}%'",
            self::CASH_PREFIXES,
        )) . ') OR NOT (' . $this->notCardClearing($supplierId, 'a') . '))';
        $touchesCash = '(' . implode(' OR ', array_map(
            static fn (string $p): string => "ca.account_code LIKE '{$p}%'",
            self::CASH_PREFIXES,
        )) . ') AND ' . $this->notCardClearing($supplierId, 'ca');
        $bookkeeping = "'" . implode("', '", self::BOOKKEEPING_SOURCE_TYPES) . "'";

        $sql =
            "SELECT a.account_code AS counter_code,
                    MIN(a.name) AS counter_name,
                    ROUND(SUM(CASE WHEN l.side = 'credit' THEN l.amount ELSE -l.amount END), 2) AS amount
               FROM journal_entry_lines l
               JOIN journal_entries e    ON e.id = l.entry_id
               JOIN chart_of_accounts a  ON a.id = l.account_id
              WHERE l.supplier_id = ?
                AND e.posted_at IS NOT NULL
                AND e.reversed_by IS NULL
                AND e.entry_date BETWEEN ? AND ?
                AND e.source_type NOT IN ({$bookkeeping})
                AND {$nonCash}
                AND EXISTS (
                        SELECT 1
                          FROM journal_entry_lines c
                          JOIN chart_of_accounts ca ON ca.id = c.account_id
                         WHERE c.entry_id = l.entry_id
                           AND c.supplier_id = l.supplier_id
                           AND ({$touchesCash})
                    )
           GROUP BY a.account_code
             HAVING amount <> 0
              ORDER BY a.account_code";

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $from, $to]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Stav peněžních prostředků k datu (MD kladně).
     *
     * `$periodFrom`/`$periodTo` vymezují REPORTOVANÉ období, jehož uzávěrkový zápis se
     * vyřazuje — ten peněžní účty vynuluje, takže by konečný stav uzavřeného roku vyšel
     * jako nula. Starší uzávěrkové a otevírací zápisy se ponechávají: jsou zrcadlové
     * a ruší se, kdežto vyřazení všech uzávěrek by minulý zůstatek započetlo dvakrát.
     */
    private function cashBalance(int $supplierId, string $asOf, string $periodFrom, string $periodTo, bool $isPeriodStart): float
    {
        $cond = implode(' OR ', array_map(
            static fn (string $p): string => "a.account_code LIKE '{$p}%'",
            self::CASH_PREFIXES,
        ));
        $notCard = $this->notCardClearing($supplierId, 'a');

        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END), 0)
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = :supplier_id AND e.posted_at IS NOT NULL
                AND e.reversed_by IS NULL
                AND e.entry_date <= :as_of
                -- Zápis datovaný přesně na PRVNÍ den období se nesmí započítat
                -- zároveň do počátečního stavu i mezi pohyby (rozdělení VH bývá
                -- datované 1. 1.). Do počátečního stavu patří jen to, co mu
                -- předchází, plus otevírací zápis.
                AND (:is_start = 0 OR e.entry_date < :as_of_start OR e.source_type = 'opening')
                AND NOT (e.source_type = 'closing' AND e.entry_date BETWEEN :p_from AND :p_to)
                AND ({$cond})
                AND {$notCard}"
        );
        $stmt->execute([
            ':supplier_id' => $supplierId,
            ':as_of' => $asOf,
            ':as_of_start' => $asOf,
            ':is_start' => $isPeriodStart ? 1 : 0,
            ':p_from' => $periodFrom,
            ':p_to' => $periodTo,
        ]);

        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Zařazení podle protiúčtu. Účtová třída je tu dost silný signál: 0xx je dlouhodobý
     * majetek, 4xx vlastní kapitál a dlouhodobé závazky. Úvěry (231/232/461) jsou
     * výjimka — číslem patří do třídy 2 a 4, ale ekonomicky jde o financování.
     *
     * ── Krátkodobý finanční majetek (25x) je INVESTIČNÍ, ne provozní ────────────
     * Pořízení a prodej cenných papírů je podle § 42 vyhlášky činnost investiční.
     * Dokud sem 25x nepatřilo, spadl nákup podílových listů za 1 000 000 Kč mezi
     * provozní činnost a investiční činnost zůstala prázdná — výkaz pak tvrdil, že
     * firma za rok neinvestovala, přestože milion na investici skutečně odešel.
     *
     * Vlastní akcie a obchodní podíly (252) jsou výjimkou uvnitř výjimky: jde
     * o transakci s VLASTNÍM kapitálem, tedy financování.
     */
    private static function classify(string $counterCode): string
    {
        if (str_starts_with($counterCode, '252')) {
            return 'financing';
        }
        if (str_starts_with($counterCode, '0') || str_starts_with($counterCode, '25')) {
            return 'investing';
        }
        if (str_starts_with($counterCode, '231')
            || str_starts_with($counterCode, '232')
            || str_starts_with($counterCode, '461')
            || str_starts_with($counterCode, '4')
        ) {
            return 'financing';
        }
        if (preg_match('/^[1235678]/', $counterCode) === 1) {
            return 'operating';
        }

        return 'unclassified';
    }
}
