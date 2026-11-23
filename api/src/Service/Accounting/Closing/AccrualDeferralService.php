<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountDefinitionRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\DimensionAccountMapRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\Accrual\AccrualAgendaGuard;
use MyInvoice\Service\Accounting\Accrual\AccrualScheduleCalculator;
use MyInvoice\Service\Accounting\Dimension\DimensionDefaults;
use MyInvoice\Service\Accounting\Dimension\DimensionStamper;
use MyInvoice\Service\Accounting\DocumentPostingGroup;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;

/**
 * Časové rozlišení z řádků dokladů s obdobím od–do: náklady příštích období (381) z řádků
 * přijatých faktur a výnosy příštích období (384) z řádků vydaných faktur a členských
 * předpisů NNO.
 *
 * Jediné místo pravidla: které řádky se rozlišují (doklad zaúčtovaný v období, přesah za
 * rozvahový den, doklad bez vlastního odkladu na 381/384), z jakého účtu (účet, na který
 * řádek zaúčtoval doklad, včetně rozpadu dimenzí), v jakém kurzu a jakou částkou (poměr
 * dnů včetně obou krajů). Volá ho krok uzávěrky „Časové rozlišení" i rozpuštění v openNext
 * ({@see ClosingService}) a výkazy s průběžným časovým rozlišením mimo deník
 * ({@see \MyInvoice\Service\Accounting\Reports\InterimAccrualAdjustment}).
 */
final class AccrualDeferralService
{
    public const RULE_PREPAID_EXPENSE = 'accrual.prepaid.expense';
    public const RULE_DEFERRED_REVENUE = 'accrual.deferred.revenue';

    /** Klíč mezipaměti výpočtu s účtotvornými typy dimenzí (vedle klíčů dokladů „druh:id"). */
    private const CACHE_DRIVING_TYPES = '@driving_types';
    /** Datum zápisu odkladu či rozpuštění, které výpočet připravuje (aktivita účtů k němu). */
    public const CACHE_POSTING_DATE = '@posting_date';
    private const CACHE_ITEM_ACCOUNTS = '@item_accounts';
    /** Klíč mezipaměti se zápisy zdrojů ostatních položek (zdroj => zápis => datum). */
    private const CACHE_OTHER_ITEM_SOURCES = '@other_item_sources';

    /**
     * Zdroje zápisů, které účtuje uzávěrka a otevření dalšího roku ({@see ClosingService}).
     * Vazba „Souvisí" na takový zápis neznamená, že doklad nese odklad sám.
     */
    private const CLOSING_SOURCE_TYPES = [
        'closing', 'opening', 'deferred_revenue_accrual', 'prepaid_expense_accrual', 'small_asset_accrual',
        'fx_revaluation', 'income_tax', 'deferred_tax', 'provision', 'profit_distribution',
    ];

    /** Tolerance shody podílů rozpadu (podíl se ukládá na 10 desetinných míst). */
    private const SHARE_TOLERANCE = 0.000001;

    public function __construct(
        private readonly Connection $db,
        private readonly PostingService $posting,
        private readonly PostingRuleRepository $rules,
    ) {}

    // ── náklady příštích období (381) ─────────────────────────────────────────

    /**
     * Řádky přijatých faktur označené k časovému rozlišení, jejichž náklad přesahuje
     * `afterDate` (accrual_to > afterDate). Restrikce na doklady s nákladem v [costFrom..costTo]
     * (effective_cost_date, status != draft/cancelled): odkládá se jen náklad z VH.
     * Doklad, který odklad nese už sám ({@see deferredOnDocumentSql}), se neodkládá podruhé.
     *
     * `$asOfInJournal` (výkaz mimo deník) navíc vyžaduje zaúčtovaný nestornovaný zápis
     * skupiny zaúčtování dokladu v [costFrom..asOf] (náklad musí ve výkazu být, uzávěrku nezaúčtovaný doklad
     * zastaví už kontrolou) a vyřadí doklady, jejichž odklad k tomu dni zaúčtovala
     * uzávěrka ({@see deferredByClosingSql}).
     *
     * Navíc ostatní závazky s obdobím ({@see otherItemAccrualRows()}): náklad z obratu 5xx
     * jejich zápisu, filtry data a uzávěrky stejné jako u výnosu ostatních pohledávek.
     *
     * @return list<array<string,mixed>>
     */
    public function prepaidExpenseRows(int $supplierId, string $afterDate, string $costFrom, string $costTo, ?string $asOfInJournal = null): array
    {
        $deferPrefix = $this->deferralAccountPrefix($supplierId, self::RULE_PREPAID_EXPENSE);
        $journalSql = '';
        $params = [$supplierId, $afterDate, $costFrom, $costTo];
        if ($asOfInJournal !== null) {
            // Zaúčtovaný doklad pozná podle skupiny zaúčtování, stejně jako předběžná kontrola
            // uzávěrky: převzatý doklad účtují zápisy, které vlastní, ne přímý zápis.
            $journalSql = 'AND ' . DocumentPostingGroup::postedEntryExistsSql('purchase_invoice', 'pi.id', 'pi.supplier_id', 'pje',
                'AND pje.entry_date >= ? AND pje.entry_date <= ?') . '
                AND NOT ' . self::deferredByClosingSql('prepaid_expense_accrual', 'pi.supplier_id', 'pi.effective_cost_date');
            array_push($params, $costFrom, $asOfInJournal, $costFrom, $asOfInJournal, $asOfInJournal);
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT pii.id AS item_id, pii.purchase_invoice_id, pii.description,
                    pii.total_without_vat,
                    pii.accrual_from, pii.accrual_to,
                    pi.vendor_invoice_number, pi.exchange_rate, cur.code AS currency_code
               FROM purchase_invoice_items pii
               JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
               JOIN currencies cur       ON cur.id = pi.currency_id
              WHERE pi.supplier_id = ?
                AND pi.status NOT IN (\'draft\', \'cancelled\')
                AND pii.accrual_from IS NOT NULL
                AND pii.accrual_to IS NOT NULL
                AND pii.accrual_to > ?
                AND pi.effective_cost_date BETWEEN ? AND ?
                ' . $journalSql . '
                AND NOT ' . self::deferredOnDocumentSql('purchase_invoice', 'pi.supplier_id', 'pi.id') . '
              ORDER BY pi.id, pii.order_index, pii.id'
        );
        array_push($params, ...self::deferredOnDocumentParams($deferPrefix));
        $stmt->execute($params);
        return [
            ...self::purchaseInvoiceRows($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []),
            ...$this->otherItemAccrualRows('payable', $supplierId, $afterDate, $costFrom, $costTo, null, false, $asOfInJournal),
        ];
    }

    /**
     * Řádky přijatých faktur v jednotném tvaru nákladových řádků; ostatní závazky
     * ({@see otherItemAccrualRows()}) nesou navíc `other_item_id` a zdrojový zápis.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function purchaseInvoiceRows(array $rows): array
    {
        return array_map(static fn (array $row): array => $row + ['source' => 'purchase_invoice', 'other_item_id' => null, 'journal_entry_id' => null], $rows);
    }

    /**
     * Klíč dokladu nákladového řádku ({@see prepaidExpenseRows()}): víc řádků téhož dokladu
     * (položky faktury, nákladové účty ostatního závazku) je v přehledu jeden doklad.
     *
     * @param array<string,mixed> $row
     */
    public static function prepaidExpenseDocumentKey(array $row): string
    {
        return $row['source'] === 'other_item'
            ? 'other_item:' . (int) $row['other_item_id']
            : 'purchase_invoice:' . (int) $row['purchase_invoice_id'];
    }

    /**
     * EP-15: řádky pro ROZPOUŠTĚNÍ odloženého nákladu do cílového období — narozdíl od
     * {@see prepaidExpenseRows} BEZ omezení na effective_cost_date, aby se v N+1
     * (openNext) našel i víceletý odklad pořízený v N (a rozpustil jen tranši N+1, ne celý
     * zbytek). `originEndsOn` = konec období, ze kterého openNext otevírá (deferováno za ním);
     * `targetEnd` = konec cílového období (rozlišení se týká jen položek začínajících do něj).
     * Bere jen položky, jejichž odklad zaúčtovala uzávěrka ({@see deferredByClosingSql}),
     * a to v obdobích mimo `$mirroredPeriodIds`: odklad těch se rozpouští ze záznamu kroku
     * ({@see closingDeferralSnapshots()}), ne z dnešního stavu dokladů.
     *
     * @param list<int> $mirroredPeriodIds
     * @return list<array<string,mixed>>
     */
    public function prepaidExpenseReleaseRows(int $supplierId, string $originEndsOn, string $targetEnd, array $mirroredPeriodIds = []): array
    {
        $deferPrefix = $this->deferralAccountPrefix($supplierId, self::RULE_PREPAID_EXPENSE);
        $stmt = $this->db->pdo()->prepare(
            'SELECT pii.id AS item_id, pii.total_without_vat,
                    pii.accrual_from, pii.accrual_to, pii.purchase_invoice_id,
                    pi.exchange_rate, cur.code AS currency_code,
                    JSON_VALUE(cs.payload, CONCAT(\'$.prepaid_expense_accrual.item_costs."\', pii.id, \'"\')) AS closing_cost_czk
               FROM purchase_invoice_items pii
               JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
               JOIN currencies cur       ON cur.id = pi.currency_id
               LEFT JOIN accounting_periods oap
                      ON oap.supplier_id = pi.supplier_id
                     AND pi.effective_cost_date >= oap.starts_on
                     AND pi.effective_cost_date <= oap.ends_on
               LEFT JOIN accounting_closing_steps cs
                      ON cs.period_id = oap.id AND cs.step_key = \'deferrals\'
              WHERE pi.supplier_id = ?
                AND pi.status NOT IN (\'draft\', \'cancelled\')
                AND pii.accrual_from IS NOT NULL
                AND pii.accrual_to IS NOT NULL
                AND pii.accrual_to > ?
                AND pii.accrual_from <= ?
                AND ' . self::deferredByClosingSql('prepaid_expense_accrual', 'pi.supplier_id', 'pi.effective_cost_date', $mirroredPeriodIds) . '
                AND NOT ' . self::deferredOnDocumentSql('purchase_invoice', 'pi.supplier_id', 'pi.id') . '
              ORDER BY pi.id, pii.order_index, pii.id'
        );
        $stmt->execute([$supplierId, $originEndsOn, $targetEnd, $originEndsOn, ...self::deferredOnDocumentParams($deferPrefix)]);
        // Ostatní závazek: zdroje zaúčtované do konce období, ze kterého se otevírá, jen
        // v obdobích, jejichž odklad zaúčtovala uzávěrka (stejně jako výnos ostatní pohledávky).
        return [
            ...self::purchaseInvoiceRows($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []),
            ...$this->otherItemAccrualRows('payable', $supplierId, $originEndsOn, null, $originEndsOn, $targetEnd, true, null, $mirroredPeriodIds),
        ];
    }

    /**
     * Účetní náklad řádku přijaté faktury v Kč, ze kterého se počítá odklad na 381: základ
     * a neodpočitatelná část daně ({@see PostingService::purchaseItemCostsCzk()}, SSOT), jinak
     * by budoucí část daně zůstala v nákladu roku pořízení. Ostatní závazek DPH nemá, jeho
     * náklad je obrat nákladového účtu zápisu ({@see otherItemAccrualRows()}).
     *
     * @param array<string,mixed> $row
     * @param array<int,array<int,float>> $costsByInvoice cache jednoho průchodu (faktura => položka => náklad)
     */
    public function prepaidExpenseCostCzk(int $supplierId, array $row, array &$costsByInvoice): float
    {
        if ($row['source'] === 'other_item') {
            return round((float) $row['total_without_vat'], 2);
        }
        $netCzk = $this->prepaidExpenseNetCzk($row);
        $purchaseInvoiceId = (int) $row['purchase_invoice_id'];
        if (!isset($costsByInvoice[$purchaseInvoiceId])) {
            try {
                $costsByInvoice[$purchaseInvoiceId] = $this->posting->purchaseItemCostsCzk($supplierId, $purchaseInvoiceId);
            } catch (PostingException $e) {
                throw new ClosingException($e->errorCode, $e->getMessage());
            }
        }
        return $costsByInvoice[$purchaseInvoiceId][(int) $row['item_id']] ?? $netCzk;
    }

    /**
     * Částka, ze které se rozpouští odklad řádku z {@see prepaidExpenseReleaseRows}: ocenění
     * uložené uzávěrkou, která odklad zaúčtovala. Rozpouštět se musí tatáž hodnota, jinak by 381
     * nedošlo na nulu. Uzávěrka bez uloženého ocenění odložila jen základ, zůstává proto
     * na základu i v dalších letech a zaúčtovaný odklad se zpětně nemění.
     *
     * Ostatní závazek ocenění v `item_costs` nemá: rozpouští se z rozpadu kroku
     * ({@see snapshotOtherItem()}), v období bez rozpadu z obratu zdroje ke konci jeho období
     * ({@see otherItemSources()}), tedy z téže hodnoty, ze které uzávěrka odložila (řádek nese
     * obrat v Kč bez kurzu, `prepaidExpenseNetCzk()` ho vrátí beze změny).
     *
     * @param array<string,mixed> $row
     */
    public function prepaidExpenseReleaseBasisCzk(array $row): float
    {
        return isset($row['closing_cost_czk']) ? round((float) $row['closing_cost_czk'], 2) : $this->prepaidExpenseNetCzk($row);
    }

    /**
     * Základ řádku přijaté faktury v Kč kurzem dokladu (§4/12 ZoÚ): CZK → 1.0, cizí měna
     * → exchange_rate (>0 nutný).
     *
     * @param array<string,mixed> $row
     */
    private function prepaidExpenseNetCzk(array $row): float
    {
        return round((float) $row['total_without_vat'] * $this->prepaidExpenseRate($row), 2);
    }

    /**
     * Nákladové účty, ze kterých se řádek přijaté faktury odkládá.
     *
     * Nákladový účet (5xx) = účet, na který řádek zaúčtovala přijatá faktura
     * ({@see PostingService::purchaseItemExpenseAccounts()}, SSOT): účet položky > druh
     * výdaje včetně jistého automatického návrhu > produkt > kategorie > předkontace. Jinak
     * by se náklad odložil z jiného účtu, než na kterém leží. Účtotvorná dimenze mohla náklad
     * položky zaúčtovat na analytiku nebo ho rozpadem rozdělit mezi analytiky, odklad
     * i rozpuštění proto jdou na tytéž analytiky ve stejném poměru
     * ({@see PostingService::itemAccountsByDimension()}). Ostatní závazek odkládá z účtu,
     * který nese jeho zápis (jako výnos ostatní pohledávky).
     *
     * @param array<string,mixed> $row
     * @param array<int,array<int,array<string,float>>> $accountsByInvoice cache jednoho průchodu (faktura => položka => účet => podíl)
     * @return array<string,float> účet => podíl
     */
    public function prepaidExpenseAccounts(int $supplierId, array $row, array &$accountsByInvoice): array
    {
        if ($row['source'] === 'other_item') {
            return [(string) $row['expense_account_code'] => 1.0];
        }
        $purchaseInvoiceId = (int) $row['purchase_invoice_id'];
        if (!isset($accountsByInvoice[$purchaseInvoiceId])) {
            try {
                $accountsByInvoice[$purchaseInvoiceId] = $this->posting->itemAccountsByDimension(
                    $supplierId,
                    'purchase_invoice',
                    $purchaseInvoiceId,
                    $this->posting->purchaseItemExpenseAccounts($supplierId, $purchaseInvoiceId),
                );
            } catch (PostingException $e) {
                throw new ClosingException($e->errorCode, $e->getMessage());
            }
        }
        return $accountsByInvoice[$purchaseInvoiceId][(int) $row['item_id']] ?? ['518' => 1.0];
    }

    /** Kurz dokladu na CZK (§4/12 ZoÚ): CZK → 1.0, cizí měna → exchange_rate (>0 nutný). */
    private function prepaidExpenseRate(array $row): float
    {
        if ((string) ($row['currency_code'] ?? 'CZK') === 'CZK') {
            return 1.0;
        }
        $rate = $row['exchange_rate'] ?? null;
        if ($rate === null || (float) $rate <= 0) {
            throw new ClosingException(
                'missing_exchange_rate',
                'Přijatá faktura #' . (int) $row['purchase_invoice_id'] . ' v cizí měně (' . (string) $row['currency_code']
                    . ') nemá kurz — bez něj nelze časové rozlišení ocenit v Kč (§4/12 ZoÚ).',
            );
        }
        return (float) $rate;
    }

    // ── pojistka změny období na dokladu ──────────────────────────────────────

    /**
     * Smí se na dokladu změnit období časového rozlišení (accrual_from/accrual_to)? Vrací
     * důvod odmítnutí `[kód, hláška]`, nebo null.
     *
     * Odklad zaúčtovaný uzávěrkou se počítal z období na řádcích. Změna po jeho zaúčtování
     * by odklad a rozpuštění rozjela (na 381/384 by zůstal zůstatek), proto se odmítá:
     * - v uzavřeném, revidovaném či schváleném období ({@see AccountingPeriodStatus::CLOSED}),
     * - v uzavíraném období, jehož krok Časové rozlišení už zaúčtoval odklad daného druhu.
     * Posuzuje se období data dokladu a období všech živých zápisů jeho skupiny zaúčtování
     * (převzatý zápis může ležet jinde). Ostatní položka (`other_item`) skupinu zaúčtování
     * nemá: posuzují se období jejích zdrojových zápisů ({@see otherItemSourceDates()}), ze
     * kterých uzávěrka odkládá, a odklad podle uložené strany `$side` (pohledávka výnosy 384,
     * závazek náklady 381). Volá se v transakci se zamčenými obdobími firmy
     * (`$statuses` z {@see \MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard::lockPeriods()}),
     * aby souběžná uzávěrka nemohla mezi kontrolou a změnou krok zaúčtovat. Zápis odkladu se
     * čte zamykacím čtením: snímek transakce založený před čekáním na zámek období by krok
     * zaúčtovaný souběžně neviděl.
     *
     * @param 'invoice'|'purchase_invoice'|'other_item' $docType
     * @param ?string $side strana ostatní položky ('receivable'|'payable')
     * @param array<int,string> $statuses id období => stav (aktuální čtení pod zámkem)
     * @return array{0:string,1:string}|null
     */
    public function accrualChangeBlocker(int $supplierId, string $docType, int $docId, ?string $docDate, array $statuses, ?string $side = null): ?array
    {
        $periodIds = [];
        if ($docType === 'other_item' && !in_array($side, ['receivable', 'payable'], true)) {
            throw new \LogicException('Pojistka ostatní položky vyžaduje stranu.');
        }
        $dates = $docType === 'other_item' ? self::otherItemSourceDates($this->db, $supplierId, $docId, (string) $side) : [];
        if ($docDate !== null && $docDate !== '') {
            $dates[] = $docDate;
        }
        $period = $this->db->pdo()->prepare('SELECT id FROM accounting_periods WHERE supplier_id = ? AND starts_on <= ? AND ends_on >= ?');
        foreach (array_unique(array_map(static fn (string $date): string => substr($date, 0, 10), $dates)) as $date) {
            $period->execute([$supplierId, $date, $date]);
            foreach ($period->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $id) {
                $periodIds[(int) $id] = true;
            }
        }
        if ($docType !== 'other_item') {
            foreach ((new DocumentPostingGroup($this->db))->active($supplierId, $docType, $docId) as $entry) {
                if ($entry['period_id'] !== null) {
                    $periodIds[(int) $entry['period_id']] = true;
                }
            }
        }
        $sourceType = $docType === 'purchase_invoice' || $side === 'payable' ? 'prepaid_expense_accrual' : 'deferred_revenue_accrual';
        $deferral = $this->db->pdo()->prepare(
            'SELECT 1 FROM journal_entries
              WHERE supplier_id = ? AND source_type = ? AND source_id = ?
                AND posted_at IS NOT NULL AND reversed_by IS NULL LIMIT 1 LOCK IN SHARE MODE'
        );
        foreach (array_keys($periodIds) as $periodId) {
            $status = $statuses[$periodId] ?? null;
            if ($status !== null && \MyInvoice\Service\Accounting\AccountingPeriodStatus::isClosed($status)) {
                return ['period_closed', 'Doklad patří do uzavřeného účetního období, jeho časové rozlišení už je součástí uzávěrky.'];
            }
            if ($status === 'closing') {
                $deferral->execute([$supplierId, $sourceType, $sourceType === 'deferred_revenue_accrual'
                    ? ClosingSourceId::deferredRevenueAccrual($periodId)
                    : ClosingSourceId::prepaidExpenseAccrual($periodId)]);
                if ($deferral->fetchColumn() !== false) {
                    return ['accrual_deferral_posted', 'Uzávěrka období dokladu už zaúčtovala časové rozlišení. Období na řádcích změňte až po vrácení kroku Časové rozlišení, potom krok spusťte znovu.'];
                }
            }
        }
        return null;
    }

    // ── záznam zaúčtovaného odkladu ───────────────────────────────────────────

    /**
     * Rozpad odkladu po položkách, jak ho krok uzávěrky zaúčtoval: položka, doklad, období
     * od–do, částka v Kč a účty s podíly. Ukládá se do záznamu kroku (`items`) a rozpuštění
     * z něj počítá tranše, takže zrcadlí zaúčtovaný odklad i po změně dokladu (období na
     * řádcích, přeúčtování, storno v dalším roce).
     *
     * @param array<string,float> $accounts účet => podíl
     * @return array{item_id:int, doc_id:int, from:string, to:string, basis:float, accounts:array<string,float>}
     */
    public static function snapshotItem(int $itemId, int $docId, string $from, string $to, float $basis, array $accounts): array
    {
        return ['item_id' => $itemId, 'doc_id' => $docId, 'from' => $from, 'to' => $to, 'basis' => round($basis, 2), 'accounts' => $accounts];
    }

    /**
     * Rozpad odkladu řádku ostatní pohledávky ({@see otherItemDeferredRevenueRows()}) pro záznam
     * kroku: k údajům {@see snapshotItem()} navíc zdrojový zápis a jeho zápisy s daty, ze
     * kterých rozpuštění bere dimenze. Rozpuštění tak zrcadlí zaúčtovaný odklad i po pozdější
     * opravě nebo stornu položky, stejně jako u řádků faktur.
     *
     * @param array<string,mixed> $row
     * @param array<string,float> $accounts
     * @return array<string,mixed>
     */
    public static function snapshotOtherItem(array $row, string $from, string $to, float $basis, array $accounts): array
    {
        return self::snapshotItem((int) $row['other_item_id'], (int) $row['other_item_id'], $from, $to, $basis, $accounts)
            + ['source' => 'other_item', 'journal_entry_id' => (int) $row['journal_entry_id'], 'entries' => $row['source_entries']];
    }

    /**
     * Řádek rozpuštění ze záznamu kroku ({@see snapshotItem()}, {@see snapshotOtherItem()}) ve
     * tvaru řádků {@see deferredRevenueRows()}. Záznam bez `source` (řádek faktury) je faktura.
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    public static function deferredRevenueSnapshotRow(array $item): array
    {
        $row = ['item_id' => (int) $item['item_id'], 'membership_case_id' => null, 'accrual_from' => (string) $item['from'],
            'accrual_to' => (string) $item['to'], 'snapshot' => $item];
        if (($item['source'] ?? 'invoice') === 'other_item') {
            $entries = [];
            foreach ((array) ($item['entries'] ?? []) as $entryId => $date) {
                $entries[(int) $entryId] = (string) $date;
            }
            return $row + ['source' => 'other_item', 'invoice_id' => null, 'other_item_id' => (int) $item['doc_id'],
                'journal_entry_id' => (int) $item['journal_entry_id'], 'source_entries' => $entries,
                'revenue_account_code' => (string) array_key_first(self::snapshotAccounts($item))];
        }
        return $row + ['source' => 'invoice', 'invoice_id' => (int) $item['doc_id'], 'other_item_id' => null,
            'journal_entry_id' => null, 'revenue_account_code' => null];
    }

    /**
     * Řádek rozpuštění nákladů ze záznamu kroku ({@see snapshotItem()}, {@see snapshotOtherItem()})
     * ve tvaru řádků {@see prepaidExpenseReleaseRows()}. Záznam bez `source` je řádek přijaté faktury.
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    public static function prepaidExpenseSnapshotRow(array $item): array
    {
        $row = ['item_id' => (int) $item['item_id'], 'accrual_from' => (string) $item['from'],
            'accrual_to' => (string) $item['to'], 'snapshot' => $item];
        if (($item['source'] ?? 'purchase_invoice') === 'other_item') {
            $entries = [];
            foreach ((array) ($item['entries'] ?? []) as $entryId => $date) {
                $entries[(int) $entryId] = (string) $date;
            }
            return $row + ['source' => 'other_item', 'purchase_invoice_id' => null, 'other_item_id' => (int) $item['doc_id'],
                'journal_entry_id' => (int) $item['journal_entry_id'], 'source_entries' => $entries,
                'expense_account_code' => (string) array_key_first(self::snapshotAccounts($item))];
        }
        return $row + ['source' => 'purchase_invoice', 'purchase_invoice_id' => (int) $item['doc_id'],
            'other_item_id' => null, 'journal_entry_id' => null];
    }

    /**
     * Účty s podíly z rozpadu odkladu ({@see snapshotItem()}); JSON vrací číselné kódy jako čísla.
     *
     * @param array<string,mixed> $item
     * @return array<string,float>
     */
    public static function snapshotAccounts(array $item): array
    {
        $out = [];
        foreach ((array) ($item['accounts'] ?? []) as $account => $share) {
            $out[(string) $account] = (float) $share;
        }
        return $out;
    }

    /**
     * Odklady zaúčtované uzávěrkou v obdobích končících nejpozději `$originEndsOn`, jejichž
     * krok uložil rozpad po položkách ({@see snapshotItem()}): id těchto období a položky
     * k rozpuštění. Období se živým zápisem odkladu, ale bez rozpadu (uzávěrka starší verze),
     * ve výsledku není a rozpouští se dál podle dnešního stavu dokladů.
     *
     * @param 'deferred_revenue_accrual'|'prepaid_expense_accrual' $sourceType
     * @return array{periods: list<int>, items: list<array<string,mixed>>}
     */
    public function closingDeferralSnapshots(int $supplierId, string $sourceType, string $originEndsOn): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT dap.id, cs.payload
               FROM accounting_periods dap
               JOIN journal_entries dje
                 ON dje.supplier_id = dap.supplier_id
                AND dje.source_type = ?
                AND dje.source_id = dap.id
                AND dje.posted_at IS NOT NULL
                AND dje.reversed_by IS NULL
               JOIN accounting_closing_steps cs
                 ON cs.supplier_id = dap.supplier_id AND cs.period_id = dap.id AND cs.step_key = 'deferrals'
              WHERE dap.supplier_id = ? AND dap.ends_on <= ?
              ORDER BY dap.starts_on"
        );
        $stmt->execute([$sourceType, $supplierId, $originEndsOn]);
        $periods = [];
        $items = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $payload = is_string($row['payload']) ? json_decode($row['payload'], true) : null;
            $snapshot = is_array($payload) ? ($payload[$sourceType]['items'] ?? null) : null;
            if (!is_array($snapshot)) {
                continue;
            }
            $periods[] = (int) $row['id'];
            foreach ($snapshot as $item) {
                $items[] = $item + ['period_id' => (int) $row['id']];
            }
        }
        return ['periods' => $periods, 'items' => $items];
    }

    // ── výnosy příštích období (384) ──────────────────────────────────────────

    /**
     * Řádky vydaných faktur s obdobím výnosu přesahujícím `afterDate`, jejichž zaúčtovaný
     * a nestornovaný zápis skupiny zaúčtování (přímý i vlastněný zápis převzatého dokladu)
     * leží v [postedFrom..postedTo]. Odklad: zápis v uzavíraném
     * období; rozpuštění: zápis kdykoli do konce období, ze kterého se otevírá, a jen v období,
     * jehož odklad zaúčtovala uzávěrka (`$release`, {@see deferredByClosingSql}). Výkaz mimo
     * deník (`$asOfInJournal`) naopak vyřadí doklady, jejichž odklad k tomu dni zaúčtovala
     * uzávěrka. Faktury z období v `$mirroredPeriodIds` rozpuštění nevybírá: jejich odklad
     * se rozpouští ze záznamu kroku ({@see closingDeferralSnapshots()}). Členské předpisy
     * záznam nemají, vybírají se dál podle dnešního stavu.
     *
     * @param list<int> $mirroredPeriodIds
     * @return list<array<string,mixed>>
     */
    public function deferredRevenueRows(int $supplierId, string $afterDate, ?string $postedFrom, string $postedTo, ?string $startsBy = null, bool $release = false, ?string $asOfInJournal = null, array $mirroredPeriodIds = []): array
    {
        [$closingSql, $closingParams] = self::closingFilter($release, $asOfInJournal, $postedTo, 'je.entry_date', $mirroredPeriodIds);
        $deferPrefix = $this->deferralAccountPrefix($supplierId, self::RULE_DEFERRED_REVENUE);
        $stmt = $this->db->pdo()->prepare(
            'SELECT ii.id AS item_id, ii.invoice_id, ii.description, ii.total_without_vat,
                    ii.accrual_from, ii.accrual_to, i.varsymbol, cur.code AS currency_code
               FROM invoice_items ii
               JOIN invoices i     ON i.id = ii.invoice_id
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND ii.accrual_from IS NOT NULL
                AND ii.accrual_to IS NOT NULL
                AND ii.accrual_to > ?
                AND (? IS NULL OR ii.accrual_from <= ?)
                AND ' . DocumentPostingGroup::postedEntryExistsSql('invoice', 'i.id', 'i.supplier_id', 'je', '
                               AND je.entry_date <= ?
                               AND (? IS NULL OR je.entry_date >= ?)
                               ' . $closingSql) . '
                AND NOT ' . self::deferredOnDocumentSql('invoice', 'i.supplier_id', 'i.id') . '
              ORDER BY i.id, ii.order_index, ii.id'
        );
        // Zápisy dokladu určuje skupina zaúčtování (přímý zápis i zápisy převzatého dokladu),
        // stejně jako předběžná kontrola nezaúčtovaných dokladů; podmínka zápisu se váže
        // za každou větev skupiny.
        $entryParams = [$postedTo, $postedFrom, $postedFrom, ...$closingParams];
        $params = [$supplierId, $afterDate, $startsBy, $startsBy, ...$entryParams, ...$entryParams, ...self::deferredOnDocumentParams($deferPrefix)];
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = $row + ['source' => 'invoice', 'membership_case_id' => null, 'other_item_id' => null, 'journal_entry_id' => null, 'revenue_account_code' => null];
        }
        return [
            ...$rows,
            ...$this->membershipDeferredRevenueRows($supplierId, $afterDate, $postedFrom, $postedTo, $startsBy, $release, $asOfInJournal),
            ...$this->otherItemAccrualRows('receivable', $supplierId, $afterDate, $postedFrom, $postedTo, $startsBy, $release, $asOfInJournal, $mirroredPeriodIds),
        ];
    }

    /**
     * Klíč dokladu výnosového řádku ({@see deferredRevenueRows()}): víc řádků téhož dokladu
     * (položky faktury, výnosové účty ostatní pohledávky) je v přehledu jeden doklad.
     *
     * @param array<string,mixed> $row
     */
    public static function deferredRevenueDocumentKey(array $row): string
    {
        return match ($row['source']) {
            'nonprofit_membership' => 'membership:' . (int) $row['membership_case_id'],
            'other_item' => 'other_item:' . (int) $row['other_item_id'],
            default => 'invoice:' . (int) $row['invoice_id'],
        };
    }

    /**
     * Ostatní položky strany `$side` s obdobím rozlišení (`other_items.accrual_from/to`)
     * přesahujícím `afterDate`: u pohledávky výnos (6xx, odklad na 384), u závazku náklad
     * (5xx, odklad na 381). Výsledek nese deník položky, a to na protiúčtech, které při
     * zaúčtování zvolil uživatel nebo profil druhu (i rozdělený na víc účtů). Proto se odkládá
     * podle deníku: jeden řádek na výsledkový účet zdrojového zápisu ({@see otherItemSources()})
     * s jeho čistým obratem (výnos D − MD, náklad MD − D); účet je ten zaúčtovaný (stejně jako
     * u členského předpisu). Ostatní položka DPH nemá, obrat 5xx je proto celý účetní náklad.
     * Zdroj, který (i opravou) nese s nenulovým obratem účet odkladu, se neodkládá podruhé.
     * Rozpuštění vynechá období v `$mirroredPeriodIds`: jejich odklad se rozpouští ze záznamu
     * kroku ({@see snapshotOtherItem()}), stejně jako u řádků faktur.
     *
     * Tvar řádku: výnos jako {@see deferredRevenueRows()} (`revenue_account_code`), náklad jako
     * {@see prepaidExpenseRows()} (`expense_account_code`, `vendor_invoice_number`).
     *
     * @param 'receivable'|'payable' $side
     * @param list<int> $mirroredPeriodIds
     * @return list<array<string,mixed>>
     */
    private function otherItemAccrualRows(string $side, int $supplierId, string $afterDate, ?string $postedFrom, string $postedTo, ?string $startsBy, bool $release, ?string $asOfInJournal, array $mirroredPeriodIds = []): array
    {
        $expense = $side === 'payable';
        $sources = self::otherItemSources($this->db, $supplierId, $postedTo, null, $afterDate, $side);
        if ($sources['roots'] === []) {
            return [];
        }
        [$closingSql, $closingParams] = self::closingFilter($release, $asOfInJournal, $postedTo, 'je.entry_date', $mirroredPeriodIds,
            $expense ? 'prepaid_expense_accrual' : 'deferred_revenue_accrual');
        $heads = [];
        foreach (array_chunk(array_keys($sources['roots']), 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT je.id FROM journal_entries je
                  WHERE je.supplier_id = ? AND je.id IN ({$marks})
                    AND je.entry_date <= ?
                    AND (? IS NULL OR je.entry_date >= ?)
                    {$closingSql}"
            );
            $stmt->execute([$supplierId, ...$chunk, $postedTo, $postedFrom, $postedFrom, ...$closingParams]);
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $rootId) {
                $heads[(int) $rootId] = $sources['roots'][(int) $rootId]['item_id'];
            }
        }
        if ($heads === []) {
            return [];
        }
        $items = [];
        foreach (array_chunk(array_values(array_unique($heads)), 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT oi.id, oi.accrual_from, oi.accrual_to, oi.document_no, oi.title AS description
                   FROM other_items oi
                  WHERE oi.supplier_id = ? AND oi.id IN ({$marks})
                    AND oi.deleted_at IS NULL
                    AND (? IS NULL OR oi.accrual_from <= ?)"
            );
            // Období přesahující den (`accrual_to > afterDate`, úplná dvojice) zajišťuje už kotva
            // zdrojů ({@see otherItemSources()}); obě čtení serializuje zámek období uzávěrky.
            $stmt->execute([$supplierId, ...$chunk, $startsBy, $startsBy]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $item) {
                $items[(int) $item['id']] = $item;
            }
        }
        $roots = array_filter($heads, static fn (int $itemId): bool => isset($items[$itemId]));
        if ($roots === []) {
            return [];
        }

        $rule = $expense ? self::RULE_PREPAID_EXPENSE : self::RULE_DEFERRED_REVENUE;
        $nets = self::otherItemSourceNets($this->db, $supplierId, $sources['members'], $side, $this->deferralAccountPrefix($supplierId, $rule), $roots);
        $deferAccount = $this->deferralAccount($supplierId, $rule);
        $entriesOf = [];
        foreach ($sources['members'] as $entryId => $member) {
            if (isset($roots[$member['root_id']])) {
                $entriesOf[$member['root_id']][$entryId] = $member['entry_date'];
            }
        }
        $rows = [];
        foreach ($roots as $rootId => $itemId) {
            $accounts = $nets[$rootId] ?? [];
            foreach ($accounts as $code => $net) {
                // Účet odkladu s nulovým obratem (přeúčtováno na 381/384 a zpět) odklad nevyřadí;
                // jeho nulový řádek uzávěrka přeskočí. Záměrně jinak než faktura a předpis
                // (vyřadí je kterýkoli řádek účtu odkladu, {@see deferredOnDocumentSql()}): obrat položky
                // se čte po zdrojích a opravách z deníku, takže jde posoudit přesně.
                if (str_starts_with((string) $code, $deferAccount) && (int) round($net * 100) !== 0) {
                    continue 2;
                }
            }
            ksort($accounts, SORT_STRING);
            $item = $items[$itemId];
            foreach ($accounts as $code => $net) {
                $row = [
                    'item_id' => $itemId,
                    'other_item_id' => $itemId,
                    'journal_entry_id' => $rootId,
                    'accrual_from' => $item['accrual_from'],
                    'accrual_to' => $item['accrual_to'],
                    'description' => $item['description'],
                    'total_without_vat' => round($net, 2),
                    'currency_code' => 'CZK',
                    'source' => 'other_item',
                    'source_entries' => $entriesOf[$rootId] ?? [],
                ];
                $rows[] = $expense
                    ? $row + ['purchase_invoice_id' => null, 'vendor_invoice_number' => $item['document_no'], 'exchange_rate' => null, 'expense_account_code' => (string) $code]
                    : $row + ['invoice_id' => null, 'membership_case_id' => null, 'varsymbol' => $item['document_no'], 'revenue_account_code' => (string) $code];
            }
        }
        $key = static fn (array $row): array => [$row['other_item_id'], $row['journal_entry_id'], $row['revenue_account_code'] ?? $row['expense_account_code']];
        usort($rows, static fn (array $a, array $b): int => $key($a) <=> $key($b));
        return $rows;
    }

    /**
     * Čistý obrat zdrojů ostatních položek po výsledkových účtech strany (výnos 6xx jako
     * D − MD, náklad 5xx jako MD − D) a po účtu odkladu `$deferPrefix` (null = bez něj).
     * Řádky deníku po `idx_jel_supplier_entry` (IN přes id zápisů), součet do zdroje v PHP.
     *
     * @param array<int,array{root_id:int,entry_date:string}> $members zápis => zdroj
     * @param 'receivable'|'payable' $side
     * @param ?array<int,int> $roots jen tyto zdroje (zdroj => položka), jinak všechny
     * @return array<int,array<string,float>> zdroj => účet => obrat
     */
    private static function otherItemSourceNets(Connection $db, int $supplierId, array $members, string $side, ?string $deferPrefix, ?array $roots = null): array
    {
        if ($roots !== null) {
            $members = array_filter($members, static fn (array $member): bool => isset($roots[$member['root_id']]));
        }
        [$resultPrefix, $positive] = $side === 'payable' ? ['5%', 'debit'] : ['6%', 'credit'];
        $deferSql = $deferPrefix === null ? '' : ' OR a.account_code LIKE ?';
        $nets = [];
        foreach (array_chunk(array_keys($members), 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $db->pdo()->prepare(
                "SELECT l.entry_id, a.account_code,
                        SUM(CASE WHEN l.side = ? THEN l.signed_amount ELSE -l.signed_amount END) AS net
                   FROM journal_entry_lines l
                   JOIN chart_of_accounts a ON a.id = l.account_id
                  WHERE l.supplier_id = ? AND l.entry_id IN ({$marks})
                    AND (a.account_code LIKE ?{$deferSql})
                  GROUP BY l.entry_id, a.account_code"
            );
            $stmt->execute([$positive, $supplierId, ...$chunk, $resultPrefix, ...($deferPrefix === null ? [] : [$deferPrefix])]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $line) {
                $root = $members[(int) $line['entry_id']]['root_id'];
                $code = (string) $line['account_code'];
                $nets[$root][$code] = ($nets[$root][$code] ?? 0.0) + (float) $line['net'];
            }
        }
        return $nets;
    }

    /**
     * Zdrojové zápisy ostatních položek do dne `$until` a zápisy, jejichž obrat k nim patří.
     *
     * Zdroj je kořenový zápis položky (`source_id`) a dále každý opravný zápis dvojice
     * ({@see \MyInvoice\Service\Accounting\Pair\JournalPairCorrectionService}, `journal_pair_reversals`)
     * a každé storno zápisu (`reversed_by`), datované v jiném účetním období než zápis, ke
     * kterému patří (pozdějším i zpětně dřívějším, {@see sameSourceSql()}). Takový zápis je samostatný zdroj ve svém
     * roce se stejným obdobím rozlišení, stejně jako dobropis u faktury: storno vrátí zbylé
     * tranše na účty a dimenze původního zápisu, náhradní dvojice je odloží na nové. Oprava
     * či storno ve stejném období patří k původnímu zdroji jako jeho člen. Obrat zdroje je
     * součet všech jeho členů: stornovaný zápis i jeho storno se vyruší, a to i s opravnými
     * zápisy dvojic, které storno celého zápisu stornuje zvlášť. Oprava či storno po dni
     * výpočtu je zdroj s pozdějším datem, takže ho vyřadí až výběr podle data zdroje.
     *
     * Faktury a členské předpisy čtou odklad z dokladu a zápis jen pro účet, proto tento
     * výběr nepoužívají.
     *
     * @param ?int $itemId jedna položka (pojistka změny období), jinak všechny s obdobím po `$afterDate`
     *        a stranou `$side` (náhled a rozpuštění 384 jen pohledávky, 381 jen závazky)
     * @param ?'receivable'|'payable' $side
     * @return array{roots:array<int,array{item_id:int,entry_date:string}>,members:array<int,array{root_id:int,entry_date:string}>}
     */
    private static function otherItemSources(Connection $db, int $supplierId, string $until, ?int $itemId = null, ?string $afterDate = null, ?string $side = null): array
    {
        // Uzávěrka: jen položky strany s obdobím přesahujícím den (idx_other_item_accrual_to,
        // rozsah, strana jako zbytková podmínka); pojistka změny období: jedna položka, i bez období.
        if ($itemId === null && $side === null) {
            throw new \LogicException('Výběr zdrojů pro uzávěrku potřebuje stranu položek.');
        }
        $items = $itemId === null
            ? '(SELECT a.id FROM other_items a WHERE a.supplier_id = ? AND a.accrual_to > ? AND a.side = ?)'
            : '(' . $itemId . ')';
        $itemParams = $itemId === null ? [$supplierId, $afterDate ?? '0000-01-01', $side] : [];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) !== 1) {
            throw new \InvalidArgumentException('Neplatný den výpočtu ' . $until . '.');
        }
        // Den výpočtu jako literál: výraz se v rekurzi opakuje a parametry by nešly udržet.
        $u = $db->pdo()->quote($until);
        $stmt = $db->pdo()->prepare(
            "WITH RECURSIVE chain (root_id, item_id, entry_id, as_of) AS (
                 SELECT je.id, je.source_id, je.id, " . self::rootAsOfSql('je', $u) . "
                   FROM journal_entries je
                  WHERE je.supplier_id = ? AND je.source_type = 'other_item' AND je.source_id IN {$items}
                    AND je.posted_at IS NOT NULL
                 UNION
                 SELECT IF(" . self::sameSourceSql('ce', 'c.as_of', $u) . ", c.root_id, ce.id), c.item_id, ce.id,
                        IF(" . self::sameSourceSql('ce', 'c.as_of', $u) . ", c.as_of, " . self::rootAsOfSql('ce', $u) . ")
                   FROM chain c
                   JOIN journal_pair_reversals r ON r.supplier_id = ? AND r.entry_id = c.entry_id
                   JOIN journal_entries ce
                     ON ce.supplier_id = r.supplier_id AND ce.id = r.reversal_entry_id
                    AND ce.posted_at IS NOT NULL
                 UNION
                 SELECT IF(" . self::sameSourceSql('rv', 'c.as_of', $u) . ", c.root_id, rv.id), c.item_id, rv.id,
                        IF(" . self::sameSourceSql('rv', 'c.as_of', $u) . ", c.as_of, " . self::rootAsOfSql('rv', $u) . ")
                   FROM chain c
                   JOIN journal_entries e ON e.supplier_id = ? AND e.id = c.entry_id
                   JOIN journal_entries rv
                     ON rv.supplier_id = e.supplier_id AND rv.id = e.reversed_by
                    AND rv.posted_at IS NOT NULL
             )
             SELECT c.root_id, c.item_id, c.entry_id, e.entry_date, r.entry_date AS root_date
               FROM chain c
               JOIN journal_entries e ON e.supplier_id = ? AND e.id = c.entry_id
               JOIN journal_entries r ON r.supplier_id = e.supplier_id AND r.id = c.root_id"
        );
        $stmt->execute([$supplierId, ...$itemParams, $supplierId, $supplierId, $supplierId]);
        $roots = $members = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $root = (int) $row['root_id'];
            $roots[$root] = ['item_id' => (int) $row['item_id'], 'entry_date' => (string) $row['root_date']];
            $members[(int) $row['entry_id']] = ['root_id' => $root, 'entry_date' => (string) $row['entry_date']];
        }
        return ['roots' => $roots, 'members' => $members];
    }

    /**
     * Data zdrojových zápisů ostatní položky ({@see otherItemSources()}) bez ohledu na den
     * výpočtu: v jejich obdobích uzávěrka odkládá a rozpouští výnos pohledávky, resp. náklad
     * závazku (`$side` = strana, ze které se odkládalo). Pojistka změny období
     * ({@see \MyInvoice\Service\Accounting\OtherItemService}) kontroluje právě tato období.
     *
     * @param 'receivable'|'payable' $side
     * @return list<string>
     */
    public static function otherItemSourceDates(Connection $db, int $supplierId, int $itemId, string $side): array
    {
        $sources = self::otherItemSources($db, $supplierId, '9999-12-31', $itemId);
        $nets = self::otherItemSourceNets($db, $supplierId, $sources['members'], $side, null);
        // Zdroj s nulovým obratem na všech výsledkových účtech strany (zápis zrušený stornem
        // ve stejném období) uzávěrka neodkládá, jeho období pojistku nespouští.
        $live = array_filter($sources['roots'], static fn (array $root, int $rootId): bool => array_filter(
            $nets[$rootId] ?? [], static fn (float $net): bool => (int) round($net * 100) !== 0) !== [], ARRAY_FILTER_USE_BOTH);
        $dates = array_values(array_unique(array_column($live, 'entry_date')));
        sort($dates);
        return $dates;
    }

    /**
     * Patří zápis (opravný zápis dvojice, storno) ke zdroji posuzovanému ke dni `$asOfSql`?
     * Jen když leží v témže účetním období a nejpozději k tomu dni. Oprava či storno v jiném
     * období, pozdějším i zpětně datovaném, je samostatný zdroj ve svém roce.
     */
    private static function sameSourceSql(string $alias, string $asOfSql, string $untilSql): string
    {
        return "({$alias}.entry_date <= {$asOfSql} AND " . self::rootAsOfSql($alias, $untilSql) . " = {$asOfSql})";
    }

    /**
     * Den, ke kterému se posuzuje zdroj ostatní položky: konec účetního období, do kterého
     * zápis patří (z jeho stavu k tomu dni uzávěrka odložila), nejvýše však den výpočtu
     * (`$untilSql`, literál); zápis po dni výpočtu ke konci svého období. Rozpuštění druhé a další tranše tak čte totéž, z čeho se
     * odkládalo, i když položku mezitím někdo stornoval nebo přeúčtoval. Bez období zápisu
     * platí den výpočtu.
     */
    private static function rootAsOfSql(string $alias, string $untilSql): string
    {
        $end = "(SELECT MAX(ap.ends_on) FROM accounting_periods ap
                  WHERE ap.supplier_id = {$alias}.supplier_id
                    AND ap.starts_on <= {$alias}.entry_date
                    AND ap.ends_on >= {$alias}.entry_date)";
        // Zápis po dni výpočtu se posuzuje ke konci svého období: k jeho zdroji pak nepřilne
        // zpětně datovaná oprava či storno, které jsou samostatným zdrojem dřívějšího roku.
        return "IF({$alias}.entry_date <= {$untilSql}, LEAST({$untilSql}, COALESCE({$end}, {$untilSql})), COALESCE({$end}, {$alias}.entry_date))";
    }

    /**
     * Členské příspěvky NNO s obdobím přesahujícím `afterDate`. Předpis účtuje celý příspěvek
     * do výnosu (684) k účetnímu datu; období od–do nese účetní případ. Odklad i rozpuštění
     * jde stejnou cestou jako u řádků vydaných faktur, výnosový účet je ten zaúčtovaný.
     *
     * @return list<array<string,mixed>>
     */
    private function membershipDeferredRevenueRows(int $supplierId, string $afterDate, ?string $postedFrom, string $postedTo, ?string $startsBy, bool $release, ?string $asOfInJournal): array
    {
        [$closingSql, $closingParams] = self::closingFilter($release, $asOfInJournal, $postedTo, 'je.entry_date');
        $deferPrefix = $this->deferralAccountPrefix($supplierId, self::RULE_DEFERRED_REVENUE);
        $stmt = $this->db->pdo()->prepare(
            "SELECT o.id AS item_id, NULL AS invoice_id, cc.id AS membership_case_id, je.id AS journal_entry_id,
                    o.amount_cents / 100 AS total_without_vat,
                    cc.period_from AS accrual_from, cc.period_to AS accrual_to,
                    cc.title_key AS varsymbol, 'CZK' AS currency_code,
                    coa.account_code AS revenue_account_code, 'nonprofit_membership' AS source
               FROM nonprofit_membership_charge_cases cc
               JOIN nonprofit_membership_charge_operations o
                 ON o.supplier_id = cc.supplier_id AND o.case_id = cc.id AND o.operation = 'issue'
               JOIN journal_entries je
                 ON je.supplier_id = o.supplier_id AND je.id = o.journal_entry_id
               JOIN chart_of_accounts coa
                 ON coa.supplier_id = o.supplier_id AND coa.id = o.revenue_account_id
              WHERE cc.supplier_id = ?
                AND cc.period_to > ?
                AND (? IS NULL OR cc.period_from <= ?)
                AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL
                AND je.entry_date <= ?
                AND (? IS NULL OR je.entry_date >= ?)
                {$closingSql}
                AND NOT EXISTS (SELECT 1
                      FROM journal_entry_lines ml
                      JOIN chart_of_accounts ma ON ma.id = ml.account_id
                     WHERE ml.supplier_id = je.supplier_id
                       AND ml.entry_id = je.id
                       AND ma.account_code LIKE ?)
              ORDER BY cc.id, o.id"
        );
        $params = [$supplierId, $afterDate, $startsBy, $startsBy, $postedTo, $postedFrom, $postedFrom, ...$closingParams];
        $params[] = $deferPrefix;
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = $row + ['description' => 'Členský příspěvek ' . $row['varsymbol'], 'other_item_id' => null];
        }
        return $rows;
    }

    /**
     * Podmínka na uzávěrkový odklad (`$sourceType`: výnosy 384, u ostatních závazků
     * náklady 381): rozpuštění bere jen odklad
     * zaúčtovaný uzávěrkou (k `$postedTo`), výkaz mimo deník jen doklady, jejichž odklad
     * k `$asOfInJournal` uzávěrka ještě nezaúčtovala. Odklad v uzávěrce podmínku nemá.
     *
     * @return array{0:string,1:list<string>}
     */
    private static function closingFilter(bool $release, ?string $asOfInJournal, string $postedTo, string $dateSql, array $mirroredPeriodIds = [], string $sourceType = 'deferred_revenue_accrual'): array
    {
        if ($release) {
            return ['AND ' . self::deferredByClosingSql($sourceType, 'je.supplier_id', $dateSql, $mirroredPeriodIds), [$postedTo]];
        }
        if ($asOfInJournal !== null) {
            return ['AND NOT ' . self::deferredByClosingSql($sourceType, 'je.supplier_id', $dateSql), [$asOfInJournal]];
        }
        return ['', []];
    }

    /**
     * Základ řádku v Kč a výnosový účet, ze kterého se odkládá.
     *
     * Výnosový účet řádku: má-li zápis faktury jediný výnosový účet (6xx), platí pro všechny
     * řádky (zachytí i ručně zvolenou předkontaci); jinak účet řádku stejným pravidlem, jakým
     * výnos rozkládá {@see PostingService::issuedItemRevenueAccounts}. Výnos rozeslaný
     * účtotvornou dimenzí na víc analytik se odkládá z týchž analytik ve stejném poměru
     * ({@see PostingService::itemAccountsByDimension()}); má-li zápis jediný výnosový účet,
     * je to i po dimenzi ten jediný.
     *
     * @param array<string,mixed> $row
     * @param array<int,array{rate:float,accounts:array<int,string>,mapped:array<int,array<string,float>>,single:?string}> $contexts
     * @return array{0:float,1:array<string,float>} základ v Kč, účet => podíl
     */
    public function deferredRevenueItem(int $supplierId, array $row, array &$contexts): array
    {
        if ($row['revenue_account_code'] !== null) {
            // Členský předpis a ostatní pohledávka: výnosový účet je ten zaúčtovaný.
            return [round((float) $row['total_without_vat'], 2), [(string) $row['revenue_account_code'] => 1.0]];
        }
        $invoiceId = (int) $row['invoice_id'];
        if (!isset($contexts[$invoiceId])) {
            $resolved = $this->posting->issuedItemRevenueAccounts($supplierId, $invoiceId);
            $resolved['mapped'] = $this->posting->itemAccountsByDimension($supplierId, 'invoice', $invoiceId, $resolved['accounts']);
            // Výnosové účty všech zápisů skupiny zaúčtování (přímý zápis i zápisy převzatého
            // dokladu): účet, který nese jen přímý zápis, by u převzaté faktury odložil výnos
            // z cizí položky.
            $posted = [];
            $entryIds = array_map(static fn (array $entry): int => (int) $entry['id'],
                (new DocumentPostingGroup($this->db))->active($supplierId, 'invoice', $invoiceId));
            if ($entryIds !== []) {
                $marks = implode(',', array_fill(0, count($entryIds), '?'));
                $stmt = $this->db->pdo()->prepare(
                    "SELECT DISTINCT coa.account_code
                       FROM journal_entry_lines jel
                       JOIN chart_of_accounts coa ON coa.id = jel.account_id
                      WHERE jel.supplier_id = ? AND jel.entry_id IN ({$marks})
                        AND coa.account_code LIKE '6%'"
                );
                $stmt->execute([$supplierId, ...$entryIds]);
                $posted = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
            }
            $contexts[$invoiceId] = $resolved + ['single' => count($posted) === 1 ? (string) $posted[0] : null];
        }
        $context = $contexts[$invoiceId];
        // Řádek s rozvahovým nebo nákladovým účtem (přefakturace na 315/378) výnos není,
        // takže se časově nerozlišuje ani při jediném výnosovém účtu dokladu.
        $itemAccount = $context['accounts'][(int) $row['item_id']] ?? null;
        if ($itemAccount !== null && !str_starts_with($itemAccount, '6')) {
            return [0.0, []];
        }
        $accounts = $context['single'] !== null
            ? [$context['single'] => 1.0]
            : ($context['mapped'][(int) $row['item_id']] ?? ['602' => 1.0]);
        return [round((float) $row['total_without_vat'] * $context['rate'], 2), $accounts];
    }

    // ── dimenze odkladu a rozpuštění ──────────────────────────────────────────

    /**
     * Rozpad podílů účtů položky na dimenze, se kterými výnos či náklad leží v deníku.
     *
     * Odklad (MD 6xx / D 384, MD 381 / D 5xx) i rozpuštění musí ležet na stejném účtu
     * a se stejnými dimenzemi jako řádek dokladu, ze kterého částku přesouvá. Jinak se
     * výsledek po dimenzi (středisko, oddíl, činnost) nevyrovná a u účetní jednotky podle
     * vyhlášky 504/2002 Sb. zápis bez systémových os činnosti nejde vůbec zaúčtovat
     * ({@see \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy}).
     * Daňové posouzení NNO čte osy i jejich původ z řádku, proto se přebírá i původ.
     * Každá kombinace nese id zdrojových řádků (`dimension_carry_lines`): podle nich politika
     * dimenzí pozná převzatou hodnotu a přijme ji, i když ji mezitím někdo uzavřel.
     *
     * Zdrojem je SKUTEČNÝ živý zápis dokladu (stejně jako storno kurzových rozdílů
     * v openNext), ne přepočet podle dnešních pravidel: po zaúčtování se pravidla, výchozí
     * hodnoty ani hodnoty dokladu nesmějí do odkladu promítnout jinak než do výnosu.
     *
     * Na jednom účtu může zápis nést víc kombinací dimenzí (položky s různým střediskem).
     * Položka pak bere kombinace, které nesou právě dimenze, jaké jí dává zaúčtování
     * ({@see matchingGroups()}); kandidátem je i kombinace s nulovým obratem (položka
     * vyrovnaná slevou na témže středisku). Nevyhovuje-li žádná (doklad se po zaúčtování
     * změnil bez přeúčtování), dělí se v poměru, v jakém zápis kombinace na účtu nese.
     *
     * Účet z trasy, který zápis nenese, protože ho zaúčtování přesměrovalo na analytiku
     * syntetiky, se nahradí jedinou daňovou analytikou syntetiky v zápisu, jejíž kombinace
     * položce vyhoví; je-li k datu zápisu odkladu či rozpuštění ({@see CACHE_POSTING_DATE})
     * neaktivní, zůstane kód z trasy s dimenzemi položky. Jinak účet, který zápis nenese,
     * nebo nese jen s nulovým obratem a položce nic nevyhovělo, zůstává bez dimenzí
     * (dosavadní chování; u 504 pak zápis odmítne politika dimenzí srozumitelnou chybou).
     *
     * Doklad, jehož odklad zaúčtovala uzávěrka ještě bez dimenzí ({@see markLegacyDeferrals()}),
     * se rozpouští také bez dimenzí, aby rozpuštění zrcadlilo odklad. Zúžení na osy činnosti
     * (504) přichází až po spárování: páruje se nad úplnými dimenzemi řádků.
     *
     * @param 'invoice'|'purchase_invoice'|'nonprofit_membership'|'other_item' $docType
     * @param int $docId id faktury, přijaté faktury, resp. id zápisu členského předpisu či kořenového zápisu ostatní položky
     * @param array<string,float> $accounts účet => podíl položky
     * @param array<string,mixed> $cache mezipaměť zápisů jednoho výpočtu (podle dokladu)
     * @param bool $unmatched nastaví se na true, zůstal-li některý účet bez kombinace zápisu
     * @return list<array<string,mixed>> kombinace: account_code, account_share, part, dimenze, dimension_carry_lines
     */
    public function dimensionAllocations(int $supplierId, string $docType, int $docId, ?int $itemId, array $accounts, array &$cache, bool &$unmatched = false): array
    {
        $key = $docType . ':' . $docId;
        if (!isset($cache[$key])) {
            $this->preloadDimensionGroups($supplierId, $docType, [$docId], $cache);
        }
        $legacy = $cache[$key]['legacy'] ?? false;
        $groups = $legacy === true ? [] : $cache[$key]['groups'];

        $out = [];
        foreach ($accounts as $account => $share) {
            $account = (string) $account;
            $candidates = [];
            if ($itemId !== null && !isset($cache[$key]['groups'][$account])) {
                // Trasa vrací kód z pravidla (syntetiku), zápis ho ale přesměroval na analytiku.
                // Platí analytika syntetiky, na které zápis dokladu nese kombinaci vyhovující
                // položce, a to jediná: analytika jiné položky ani nejednoznačná volba se
                // nepřebírá. Rozhoduje zápis, ne osnova, takže rozpuštění zrcadlí odklad i po
                // přidání další analytiky. Analytika k datu zápisu odkladu neaktivní: zůstává
                // kód z trasy (zaúčtování ho přesměruje podle osnovy k tomu dni) s dimenzemi
                // položky.
                $found = [];
                foreach ($cache[$key]['analytics'][$account] ?? [] as $analytic) {
                    $matching = self::matchingGroups($groups[$analytic] ?? [], $this->documentItems($supplierId, $docType, $docId, $cache), $itemId, $this->drivingTypes($supplierId, $cache));
                    if ($matching !== []) {
                        $found[$analytic] = $matching;
                    }
                }
                if (count($found) === 1) {
                    $analytic = (string) array_key_first($found);
                    $candidates = $found[$analytic];
                    $account = $this->activeOnPostingDate($supplierId, $analytic, $cache) ? $analytic : $account;
                }
            }
            $all = $groups[$account] ?? [];
            if ($candidates === [] && count($all) > 1 && $itemId !== null) {
                $candidates = self::matchingGroups($all, $this->documentItems($supplierId, $docType, $docId, $cache), $itemId, $this->drivingTypes($supplierId, $cache));
            }
            if ($candidates === []) {
                // Bez párování (jediná kombinace, členský předpis) i jako záložní dělení se
                // bere jen to, co na účtu skutečně leží.
                $candidates = array_values(array_filter($all, static fn (array $group): bool => $group['weight'] > 0.0));
            }
            if ($candidates === []) {
                $unmatched = true;
                $out[] = ['account_code' => $account, 'account_share' => (float) $share, 'part' => 1.0];
                continue;
            }
            $weighted = array_values(array_filter($candidates, static fn (array $group): bool => $group['weight'] > 0.0));
            if (count($weighted) > 1) {
                // Poměrem se dělí jen mezi kombinace se znaménkem obratu účtu: sleva na jiném
                // středisku nesmí převzít díl kladného výnosu či nákladu.
                $net = array_sum(array_column($weighted, 'net'));
                $sameSign = array_values(array_filter($weighted, static fn (array $group): bool => ($group['net'] >= 0.0) === ($net >= 0.0)));
                $weighted = $sameSign !== [] ? $sameSign : $weighted;
            }
            // Vyhověly-li položce jen kombinace s nulovým obratem, poměr zápisu chybí: rovným dílem.
            $candidates = $weighted !== [] ? $weighted : $candidates;
            $weight = array_sum(array_column($weighted, 'weight'));
            foreach ($candidates as $group) {
                // Starý odklad ve 504: až spárovaná kombinace se zúží na osy činnosti.
                $assignment = is_array($legacy) ? self::onlyTypes($group['assignment'], $legacy) : $group['assignment'];
                $out[] = ['account_code' => $account, 'account_share' => (float) $share, 'part' => $weighted !== [] ? $group['weight'] / $weight : 1 / count($candidates)]
                    + $assignment
                    + ($assignment === [] ? [] : ['dimension_carry_lines' => [$group['line_ids'][0]]]);
            }
        }
        return $out;
    }

    /**
     * Je účet aktivní k datu zápisu odkladu či rozpuštění, který výpočet připravuje
     * ({@see CACHE_POSTING_DATE})? Osnova se čte jednou za datum.
     *
     * @param array<string,mixed> $cache
     */
    private function activeOnPostingDate(int $supplierId, string $code, array &$cache): bool
    {
        $postingDate = (string) ($cache[self::CACHE_POSTING_DATE] ?? date('Y-m-d'));
        $active = $cache['@active|' . $postingDate] ??= (new ChartOfAccountsRepository($this->db))->codeToIdMap($supplierId, $postingDate);
        return $active[$code]['is_active'] ?? false;
    }

    /**
     * Očekávané dimenze položek dokladu z mezipaměti výpočtu; chybějí-li, načtou se dávkou
     * pro všechny doklady téhož druhu v mezipaměti, ne po dokladech.
     *
     * @param array<string,mixed> $cache
     * @return array<int,array<string,mixed>> položka => dimenze ({@see itemDimensionLayers()})
     */
    private function documentItems(int $supplierId, string $docType, int $docId, array &$cache): array
    {
        $key = $docType . ':' . $docId;
        if (!isset($cache[$key]['items'])) {
            $pending = [];
            foreach ($cache as $k => $doc) {
                if (is_array($doc) && str_starts_with((string) $k, $docType . ':') && !isset($doc['items'])) {
                    $pending[] = (int) substr((string) $k, strlen($docType) + 1);
                }
            }
            $loaded = $this->itemDimensionLayers($supplierId, $docType, $pending);
            foreach ($pending as $id) {
                $cache[$docType . ':' . $id]['items'] = $loaded[$id] ?? [];
            }
        }
        return $cache[$key]['items'];
    }

    /**
     * Účtotvorné typy dimenzí firmy (i neaktivní: zápis mohl vzniknout, dokud typ účty řídil),
     * jednou za výpočet.
     *
     * @param array<string,mixed> $cache
     * @return array<int,true>
     */
    private function drivingTypes(int $supplierId, array &$cache): array
    {
        return $cache[self::CACHE_DRIVING_TYPES] ??= array_fill_keys((new DimensionAccountMapRepository($this->db))->drivingTypeIds($supplierId), true);
    }

    /**
     * Rozpad výnosového řádku ({@see deferredRevenueRows()}) na účty a dimenze zápisu
     * dokladu: faktura podle položky, členský předpis podle svého zápisu.
     *
     * @param array<string,mixed> $row
     * @param array<string,float> $accounts výstup {@see deferredRevenueItem()}
     * @param array<string,mixed> $cache
     * @return list<array<string,mixed>>
     */
    public function deferredRevenueAllocations(int $supplierId, array $row, array $accounts, array &$cache): array
    {
        return match ($row['source']) {
            'nonprofit_membership' => $this->dimensionAllocations($supplierId, 'nonprofit_membership', (int) $row['journal_entry_id'], null, $accounts, $cache),
            'other_item' => $this->dimensionAllocations($supplierId, 'other_item', (int) $row['journal_entry_id'], null, $accounts, $cache),
            default => $this->dimensionAllocations($supplierId, 'invoice', (int) $row['invoice_id'], (int) $row['item_id'], $accounts, $cache),
        };
    }

    /**
     * Rozpad nákladového řádku ({@see prepaidExpenseRows()}, {@see prepaidExpenseReleaseRows()})
     * na účty a dimenze zápisu: přijatá faktura podle položky, ostatní závazek podle zápisů
     * svého zdroje.
     *
     * @param array<string,mixed> $row
     * @param array<string,float> $accounts výstup {@see prepaidExpenseAccounts()}
     * @param array<string,mixed> $cache
     * @return list<array<string,mixed>>
     */
    public function prepaidExpenseAllocations(int $supplierId, array $row, array $accounts, array &$cache): array
    {
        return $row['source'] === 'other_item'
            ? $this->dimensionAllocations($supplierId, 'other_item', (int) $row['journal_entry_id'], null, $accounts, $cache)
            : $this->dimensionAllocations($supplierId, 'purchase_invoice', (int) $row['purchase_invoice_id'], (int) $row['item_id'], $accounts, $cache);
    }

    /**
     * Rozpad odkladu drobného majetku (D 501, {@see ClosingService::runSmallAssetAccrual()})
     * na dimenze zápisu dokladu, ze kterého náklad pochází: položka přijaté faktury podle
     * účtů položky, přijatá faktura bez položky a pokladní doklad podle svých nákladových
     * řádků. Z dokladu se přebírají dimenze i analytika syntetiky účtu pravidla
     * (`$creditAccount`), na které náklad v zápisu leží, poměrem podle účtů a kombinací.
     *
     * @param array{purchase_invoice_id?:?int, item_id?:?int, cash_document_id?:?int} $source
     * @param array<string,mixed> $cache mezipaměť výpočtu ({@see preloadSmallAssetAllocations()})
     * @return list<array<string,mixed>>|null kombinace jako {@see dimensionAllocations()}; null = bez dokladu
     */
    public function smallAssetAllocations(int $supplierId, array $source, string $creditAccount, array &$cache): ?array
    {
        $purchaseInvoiceId = (int) ($source['purchase_invoice_id'] ?? 0);
        $itemId = (int) ($source['item_id'] ?? 0);
        $cashId = (int) ($source['cash_document_id'] ?? 0);
        if ($purchaseInvoiceId > 0 && $itemId > 0) {
            // Účty položky se dohledávají (trasa zaúčtování faktury, několik dotazů na fakturu)
            // jen tam, kde rozhodují: nese-li zápis jediný nákladový účet s jedinou kombinací,
            // je to kombinace položky. Jiný nákladový účet na dokladu (položka přepsaná na 518,
            // služba) rozhoduje, kam položka patří. Nezaúčtovaný doklad (koncept v bázi
            // paušálu) dimenze nemá a jeho trasa by mohla selhat na kurzu, nedohledává se.
            $accounts = $this->documentExpenseAccounts($supplierId, 'purchase_invoice', $purchaseInvoiceId, $creditAccount, $cache);
            $groups = $cache['purchase_invoice:' . $purchaseInvoiceId]['groups'] ?? [];
            $analytics = $cache['purchase_invoice:' . $purchaseInvoiceId]['analytics'] ?? [];
            $expenseAccounts = array_filter(array_keys($groups), static fn (int|string $account): bool => str_starts_with((string) $account, '5'));
            $item = null;
            // Nesou-li všechny nákladové řádky dokladu stejné přiřazení (typicky firma bez dimenzí)
            // a syntetiku pravidla jediný účet, na položce nezáleží: trasa se přeskočí. Přiřazení
            // zahrnuje i původ hodnot, který odklad převezme, proto ho rozliší i stejné dimenze
            // s jiným původem. Víc analytik syntetiky pravidla (501.100 a 501.900 s jinou daňovou
            // uznatelností) rozhoduje o účtu odkladu, proto trasu vyžaduje i bez dimenzí.
            // Trasa se počítá po fakturách stejně jako u odkladu 381 ({@see prepaidExpenseAccounts()}),
            // jednou za fakturu a jen u faktur, kde rozhoduje.
            $signatures = [];
            foreach ($expenseAccounts as $account) {
                foreach ($groups[$account] as $group) {
                    $signatures[(string) json_encode($group['assignment'])] = true;
                }
            }
            $ownAccounts = array_filter($expenseAccounts, static fn (int|string $account): bool => str_starts_with((string) $account, substr($creditAccount, 0, 3)));
            if (count($signatures) > 1 || count($ownAccounts) > 1) {
                $cache[self::CACHE_ITEM_ACCOUNTS] ??= [];
                if (!isset($cache[self::CACHE_ITEM_ACCOUNTS][$purchaseInvoiceId])) {
                    try {
                        $this->prepaidExpenseAccounts($supplierId, ['source' => 'purchase_invoice', 'purchase_invoice_id' => $purchaseInvoiceId, 'item_id' => $itemId], $cache[self::CACHE_ITEM_ACCOUNTS]);
                    } catch (ClosingException) {
                        // Trasa zaúčtování dnes neprojde (kurz, mapa účtů změněná po zaúčtování):
                        // zápis dokladu platí, dělí se poměrem jeho nákladů. Výpadek platí pro celou
                        // fakturu, další položky trasu neopakují.
                        $cache[self::CACHE_ITEM_ACCOUNTS][$purchaseInvoiceId] = [];
                    }
                }
                // Jen účty, které trasa položce opravdu dala; položka mimo trasu (bez záložního
                // 518 z prepaidExpenseAccounts) i účet mimo zápis by dimenze nenašly nebo by
                // převzaly cizí náklad: zůstává poměr nákladových účtů dokladu. Kód z pravidla,
                // který zápis přesměroval na analytiku syntetiky, najde svou skupinu přes analytiky
                // zápisu stejně jako odklad 381 ({@see dimensionAllocations()}).
                $itemAccounts = $cache[self::CACHE_ITEM_ACCOUNTS][$purchaseInvoiceId][$itemId] ?? [];
                if (array_intersect_key($itemAccounts, $groups + $analytics) !== []) {
                    [$accounts, $item] = [$itemAccounts, $itemId];
                }
            }
            $unmatched = false;
            $allocations = $this->dimensionAllocations($supplierId, 'purchase_invoice', $purchaseInvoiceId, $item, $accounts, $cache, $unmatched);
            if ($item !== null && $unmatched) {
                // Účet z pravidla (syntetika), který zápis přesměroval na analytiku, nenašel kombinaci
                // položky (položka po zaúčtování změnila dimenzi): místo řádku bez dimenzí se dělí
                // poměrem nákladů dokladu, i co do účtů zápisu. Účet přepsaný na
                // položce vrací trasa už jako analytiku zápisu, sem se nedostane.
                $allocations = $this->dimensionAllocations($supplierId, 'purchase_invoice', $purchaseInvoiceId, null, $this->documentExpenseAccounts($supplierId, 'purchase_invoice', $purchaseInvoiceId, $creditAccount, $cache), $cache);
            }
        } elseif ($purchaseInvoiceId > 0 || $cashId > 0) {
            [$docType, $docId] = $purchaseInvoiceId > 0 ? ['purchase_invoice', $purchaseInvoiceId] : ['cash', $cashId];
            $allocations = $this->dimensionAllocations($supplierId, $docType, $docId, null, $this->documentExpenseAccounts($supplierId, $docType, $docId, $creditAccount, $cache), $cache);
        } else {
            return null;
        }
        // Odklad snižuje náklad tam, kde leží v zápisu dokladu: na analytice syntetiky pravidla
        // (501.100 z účtotvorné dimenze či z položky), stejně jako odklad 381. Jiná syntetika
        // (položka přepsaná na 518) i analytika k datu odkladu neaktivní zůstávají na účtu pravidla.
        // Podíl účtu ({@see spreadByAllocation()} dělí nejdřív po účtech) je součet vah jeho
        // kombinací, uvnitř účtu dělí váha kombinace.
        $synthetic = substr($creditAccount, 0, 3);
        $out = [];
        $shares = [];
        foreach ($allocations as $allocation) {
            $account = (string) $allocation['account_code'];
            if ($account !== $creditAccount && (!str_starts_with($account, $synthetic) || !$this->activeOnPostingDate($supplierId, $account, $cache))) {
                $account = $creditAccount;
            }
            $weight = (float) $allocation['account_share'] * (float) $allocation['part'];
            $shares[$account] = ($shares[$account] ?? 0.0) + $weight;
            $out[] = ['account_code' => $account, 'account_share' => 0.0, 'part' => $weight]
                + array_diff_key($allocation, ['account_code' => 1, 'account_share' => 1, 'part' => 1]);
        }
        foreach ($out as $i => $allocation) {
            $out[$i]['account_share'] = $shares[$allocation['account_code']];
        }
        return $out;
    }

    /**
     * Nákladové účty dokladu bez položky (pokladní doklad, přijatá faktura bez vazby na
     * položku) s podílem podle obratu: nejdřív účty syntetiky účtu pravidla (501), jinak
     * všechny nákladové účty dokladu. Nese-li doklad na 501 i jiný náklad (materiál pro jinou
     * činnost), dělí se odklad poměrem obou; přesné rozlišení dává jen vazba karty na položku.
     *
     * @param array<string,mixed> $cache
     * @return array<string,float> účet => podíl
     */
    private function documentExpenseAccounts(int $supplierId, string $docType, int $docId, string $creditAccount, array &$cache): array
    {
        $key = $docType . ':' . $docId;
        if (!isset($cache[$key])) {
            $this->preloadDimensionGroups($supplierId, $docType, [$docId], $cache);
        }
        $weights = [];
        foreach ($cache[$key]['groups'] ?? [] as $account => $groups) {
            if (str_starts_with((string) $account, '5')) {
                $weights[(string) $account] = array_sum(array_column($groups, 'weight'));
            }
        }
        $synthetic = substr($creditAccount, 0, 3);
        $own = array_filter($weights, static fn (string $account): bool => str_starts_with($account, $synthetic), ARRAY_FILTER_USE_KEY);
        $weights = $own !== [] ? $own : $weights;
        $sum = array_sum($weights);
        if ($weights === [] || $sum <= 0.0) {
            return $weights === [] ? [$creditAccount => 1.0] : array_map(static fn (): float => 1 / count($weights), $weights);
        }
        return array_map(static fn (float $w): float => $w / $sum, $weights);
    }

    /**
     * Dávkově načte kombinace dimenzí zápisů dokladů karet a položek drobného majetku.
     *
     * @param list<array{purchase_invoice_id?:?int, item_id?:?int, cash_document_id?:?int}> $sources
     * @param array<string,mixed> $cache
     */
    public function preloadSmallAssetAllocations(int $supplierId, array $sources, array &$cache): void
    {
        $invoices = $cash = [];
        foreach ($sources as $source) {
            if ((int) ($source['purchase_invoice_id'] ?? 0) > 0) {
                $invoices[] = (int) $source['purchase_invoice_id'];
            } elseif ((int) ($source['cash_document_id'] ?? 0) > 0) {
                $cash[] = (int) $source['cash_document_id'];
            }
        }
        $this->preloadDimensionGroups($supplierId, 'purchase_invoice', $invoices, $cache);
        $this->preloadDimensionGroups($supplierId, 'cash', $cash, $cache);
    }

    /**
     * Dávkově načte kombinace dimenzí zápisů všech dokladů výnosových řádků
     * ({@see deferredRevenueRows()}), ať výpočet uzávěrky nedělá dotazy v cyklu.
     *
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed> $cache
     */
    public function preloadDeferredRevenueAllocations(int $supplierId, array $rows, array &$cache): void
    {
        $invoices = $entries = $otherItems = [];
        foreach ($rows as $row) {
            if ($row['source'] === 'nonprofit_membership') {
                $entries[] = (int) $row['journal_entry_id'];
            } elseif ($row['source'] === 'other_item') {
                $otherItems[] = (int) $row['journal_entry_id'];
                $cache[self::CACHE_OTHER_ITEM_SOURCES][(int) $row['journal_entry_id']] = $row['source_entries'];
            } else {
                $invoices[] = (int) $row['invoice_id'];
            }
        }
        $this->preloadDimensionGroups($supplierId, 'invoice', $invoices, $cache);
        $this->preloadDimensionGroups($supplierId, 'nonprofit_membership', $entries, $cache);
        $this->preloadDimensionGroups($supplierId, 'other_item', $otherItems, $cache);
    }

    /**
     * Dávkově načte kombinace dimenzí zápisů dokladů nákladových řádků (přijaté faktury
     * a zdroje ostatních závazků).
     *
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed> $cache
     */
    public function preloadPrepaidExpenseAllocations(int $supplierId, array $rows, array &$cache): void
    {
        $invoices = $otherItems = [];
        foreach ($rows as $row) {
            if ($row['source'] === 'other_item') {
                $otherItems[] = (int) $row['journal_entry_id'];
                $cache[self::CACHE_OTHER_ITEM_SOURCES][(int) $row['journal_entry_id']] = $row['source_entries'];
            } else {
                $invoices[] = (int) $row['purchase_invoice_id'];
            }
        }
        $this->preloadDimensionGroups($supplierId, 'purchase_invoice', $invoices, $cache);
        $this->preloadDimensionGroups($supplierId, 'other_item', $otherItems, $cache);
    }

    /**
     * Rozpuštění zrcadlí odklad: doklad, jehož odklad zaúčtovala uzávěrka ve verzi bez
     * dimenzí (zápis odkladu jeho období nenese na výsledkových řádcích žádnou dimenzi),
     * se rozpustí také bez dimenzí. Jinak by výsledek po dimenzi přes oba roky nesouhlasil.
     * U účetní jednotky podle vyhlášky 504/2002 Sb. (`$keepSystemAxes`) si rozpuštění ponechá
     * jen systémové osy činnosti dokladu: bez nich by nešlo zaúčtovat, ostatní dimenze zrcadlí
     * starý odklad. Volá se po přednačtení, před výpočtem rozpuštění.
     *
     * Doklad se k období odkladu přiřazuje stejným datem, jakým ho rozpuštění vybírá
     * ({@see deferredByClosingSql()}): přijatá faktura datem nákladu
     * (`effective_cost_date`, {@see prepaidExpenseReleaseRows()}), výnosový doklad daty
     * zápisů své skupiny zaúčtování ({@see deferredRevenueRows()}). Ostatní položka
     * (pohledávka ani závazek) se jako starý odklad neoznačí: dřív období mít nemohla, její
     * odklad 381/384 tedy vznikl až s dimenzemi zápisu.
     *
     * @param 'deferred_revenue_accrual'|'prepaid_expense_accrual' $sourceType zápis odkladu
     * @param array<string,mixed> $cache
     */
    public function markLegacyDeferrals(int $supplierId, string $sourceType, string $originEndsOn, bool $keepSystemAxes, array &$cache): void
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT dap.starts_on, dap.ends_on,
                    EXISTS (SELECT 1
                              FROM journal_entry_lines l
                              JOIN chart_of_accounts a ON a.id = l.account_id
                             WHERE l.supplier_id = dje.supplier_id AND l.entry_id = dje.id
                               AND (a.account_code LIKE '5%' OR a.account_code LIKE '6%')
                               AND (EXISTS (SELECT 1 FROM journal_entry_line_dimensions d WHERE d.line_id = l.id)
                                    OR EXISTS (SELECT 1 FROM journal_entry_line_dimension_splits s WHERE s.line_id = l.id))) AS has_dimensions
               FROM accounting_periods dap
               JOIN journal_entries dje
                 ON dje.supplier_id = dap.supplier_id
                AND dje.source_type = ?
                AND dje.source_id = dap.id
                AND dje.posted_at IS NOT NULL
                AND dje.reversed_by IS NULL
              WHERE dap.supplier_id = ? AND dap.ends_on <= ?"
        );
        $stmt->execute([$sourceType, $supplierId, $originEndsOn]);
        $legacy = array_values(array_filter($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [], static fn (array $p): bool => !(bool) $p['has_dimensions']));
        if ($legacy === []) {
            return;
        }
        $mark = true;
        if ($keepSystemAxes) {
            $stmt = $this->db->pdo()->prepare('SELECT id FROM dimension_types WHERE supplier_id = ? AND system_key IS NOT NULL ORDER BY id');
            $stmt->execute([$supplierId]);
            $mark = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
        }
        $costDates = $sourceType === 'prepaid_expense_accrual' ? $this->purchaseCostDates($supplierId, $cache) : null;
        foreach ($cache as $key => &$doc) {
            if (!is_array($doc) || !isset($doc['groups']) || str_starts_with((string) $key, 'other_item:')) {
                continue;
            }
            if ($costDates !== null) {
                $dates = str_starts_with((string) $key, 'purchase_invoice:') ? array_filter([$costDates[(int) substr((string) $key, 17)] ?? null]) : [];
            } else {
                $dates = str_starts_with((string) $key, 'purchase_invoice:') ? [] : $doc['entry_dates'];
            }
            foreach ($dates as $date) {
                foreach ($legacy as $period) {
                    if ($date >= $period['starts_on'] && $date <= $period['ends_on']) {
                        $doc['legacy'] = $mark;
                    }
                }
            }
        }
        unset($doc);
    }

    /**
     * Datum nákladu přijatých faktur v mezipaměti výpočtu, dávkou.
     *
     * @param array<string,mixed> $cache
     * @return array<int,string> faktura => effective_cost_date
     */
    private function purchaseCostDates(int $supplierId, array $cache): array
    {
        $ids = [];
        foreach ($cache as $key => $doc) {
            if (is_array($doc) && isset($doc['groups']) && str_starts_with((string) $key, 'purchase_invoice:')) {
                $ids[] = (int) substr((string) $key, 17);
            }
        }
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->pdo()->prepare("SELECT id, effective_cost_date FROM purchase_invoices WHERE supplier_id = ? AND id IN ({$marks})");
            $stmt->execute([$supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
                $out[(int) $row['id']] = (string) $row['effective_cost_date'];
            }
        }
        return $out;
    }

    /**
     * Nese souhrn aspoň jeden nenulový řádek? Podle řádků (ne podle součtu) se rozhoduje,
     * zda zápis odkladu či rozpuštění vznikne: doklady s opačným znaménkem a různou dimenzí
     * dají celkem nulu, po dimenzi se ale přesunout musí.
     *
     * @param list<array<string,mixed>> $lines
     */
    public static function hasNonZeroLine(array $lines): bool
    {
        foreach ($lines as $line) {
            if ((int) round((float) $line['amount'] * 100) !== 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Souhrn řádků po účtech (náhled, záznam kroku uzávěrky).
     *
     * @param array<string,array<string,mixed>> $byLine
     * @return array<string,float>
     */
    public static function amountsByAccount(array $byLine): array
    {
        $out = [];
        foreach ($byLine as $line) {
            $account = (string) $line['account_code'];
            $out[$account] = round(($out[$account] ?? 0.0) + (float) $line['amount'], 2);
        }
        return $out;
    }

    /**
     * Klíč řádku zápisu odkladu: účet a jeho dimenze. Řádky se stejným klíčem se sčítají.
     *
     * @param array<string,mixed> $allocation
     */
    public static function allocationKey(array $allocation): string
    {
        // Odkaz původu (doklad, dávka) klíčem není: řádky stejných dimenzí a druhu původu
        // z různých dokladů se sčítají do jednoho řádku, jako dosud řádky jednoho účtu.
        $assignment = self::assignmentOf($allocation);
        foreach (['dimension_origins', 'dimension_split_origins'] as $field) {
            if (isset($assignment[$field])) {
                array_walk_recursive($assignment[$field], static function (&$value, $key): void {
                    if ($key === 'ref') {
                        $value = null;
                    }
                });
            }
        }
        return (string) $allocation['account_code'] . ($assignment === [] ? '' : '|' . json_encode($assignment));
    }

    /**
     * Sloučí původ dvou řádků se stejným klíčem: odkaz zůstane jen tam, kde je u obou stejný.
     *
     * @param array<string,mixed> $into
     * @param array<string,mixed> $line
     * @return array<string,mixed>
     */
    private static function mergeOrigins(array $into, array $line): array
    {
        foreach ($into['dimension_origins'] ?? [] as $typeId => $origin) {
            if (($line['dimension_origins'][$typeId]['ref'] ?? null) !== ($origin['ref'] ?? null)) {
                $into['dimension_origins'][$typeId]['ref'] = null;
            }
        }
        foreach ($into['dimension_split_origins'] ?? [] as $typeId => $byValue) {
            foreach ($byValue as $valueId => $origin) {
                if (($line['dimension_split_origins'][$typeId][$valueId]['ref'] ?? null) !== ($origin['ref'] ?? null)) {
                    $into['dimension_split_origins'][$typeId][$valueId]['ref'] = null;
                }
            }
        }
        return $into;
    }

    /**
     * Rozdělí částku položky na haléř nejdřív mezi účty ({@see spreadByAccount()} nad
     * původními podíly účtů, takže součty po účtech zůstávají stejné jako ve výkazech
     * a náhledu DPPO), potom částku účtu mezi jeho kombinace dimenzí (zbytek na největší podíl).
     *
     * @param list<array<string,mixed>> $allocations výstup {@see dimensionAllocations()}
     * @return array<string,array<string,mixed>> klíč řádku => řádek s `amount`
     */
    public static function spreadByAllocation(float $amount, array $allocations): array
    {
        $byAccount = [];
        foreach ($allocations as $allocation) {
            $account = (string) $allocation['account_code'];
            $byAccount[$account]['share'] ??= (float) $allocation['account_share'];
            $byAccount[$account]['parts'][] = $allocation;
        }
        $accountAmounts = self::spreadByAccount($amount, array_map(static fn (array $a): float => $a['share'], $byAccount));
        $out = [];
        foreach ($byAccount as $account => $group) {
            $parts = $group['parts'];
            $accountCents = (int) round($accountAmounts[(string) $account] * 100);
            $cents = count($parts) === 1
                ? [$accountCents]
                : DimensionStamper::distributeCents($accountCents, array_column($parts, 'part'));
            foreach ($parts as $i => $allocation) {
                $line = ['account_code' => (string) $account] + self::assignmentOf($allocation);
                $k = self::allocationKey($line);
                // Řádky se stejným klíčem nesou totéž přiřazení, důkazu stačí jeden zdrojový řádek.
                $carry = $out[$k]['dimension_carry_lines'] ?? $allocation['dimension_carry_lines'] ?? [];
                $merged = isset($out[$k]) ? self::mergeOrigins($out[$k], $line) : $line;
                $out[$k] = ['account_code' => (string) $account] + self::assignmentOf($merged)
                    + ['amount' => round(($out[$k]['amount'] ?? 0.0) + $cents[$i] / 100, 2)]
                    + ($carry === [] ? [] : ['dimension_carry_lines' => $carry]);
            }
        }
        return $out;
    }

    /**
     * Tranše rozpuštění po řádcích jako rozdíl zbytku odkladu na začátku a na konci tranše
     * (oba rozdělené {@see spreadByAllocation()}). Po účtu i po dimenzi se tak tranše přes
     * všechny roky sečtou přesně na odloženou částku, bez haléřového zbytku na dimenzi.
     *
     * @param list<array<string,mixed>> $allocations
     * @return array<string,array<string,mixed>> klíč řádku => řádek s `amount`
     */
    public static function releaseByAllocation(float $remainingBefore, float $remainingAfter, array $allocations): array
    {
        $before = self::spreadByAllocation($remainingBefore, $allocations);
        $after = self::spreadByAllocation($remainingAfter, $allocations);
        $out = [];
        foreach ($before as $k => $line) {
            $line['amount'] = round((float) $line['amount'] - (float) ($after[$k]['amount'] ?? 0.0), 2);
            $out[$k] = $line;
        }
        return $out;
    }

    /**
     * Přičte rozpad položky do souhrnu řádků zápisu (klíč => řádek s `amount`).
     *
     * @param array<string,array<string,mixed>> $byLine
     * @param array<string,array<string,mixed>> $parts
     */
    public static function addLines(array &$byLine, array $parts): void
    {
        foreach ($parts as $k => $line) {
            if (isset($byLine[$k])) {
                $byLine[$k] = self::mergeOrigins($byLine[$k], $line);
                $byLine[$k]['amount'] = round((float) $byLine[$k]['amount'] + (float) $line['amount'], 2);
                $byLine[$k]['dimension_carry_lines'] ??= $line['dimension_carry_lines'] ?? null;
                if ($byLine[$k]['dimension_carry_lines'] === null) {
                    unset($byLine[$k]['dimension_carry_lines']);
                }
            } else {
                $byLine[$k] = $line;
            }
        }
    }

    /**
     * Kombinace dimenzí výsledkových řádků (5xx, 6xx) živých zápisů dokladů po účtech,
     * dávkově pro všechny doklady. Zápisy dokladu určuje skupina zaúčtování
     * ({@see DocumentPostingGroup::activeMany()}): přímý zápis a zápisy převzatého dokladu. Uloží je
     * do `$cache` pod klíčem dokladu (i prázdné, ať se doklad bez zápisu nenačítá znovu)
     * spolu s daty zápisů. Kombinace s nulovým obratem zůstává (váha 0): je kandidátem
     * párování položky, do poměrného dělení nevstupuje.
     *
     * @param 'invoice'|'purchase_invoice'|'cash'|'nonprofit_membership'|'other_item' $docType
     * @param list<int> $docIds id dokladů, u členského předpisu id jeho zápisu
     * @param array<string,mixed> $cache
     */
    private function preloadDimensionGroups(int $supplierId, string $docType, array $docIds, array &$cache): void
    {
        $docIds = array_values(array_filter(array_unique($docIds), static fn (int $id): bool => !isset($cache[$docType . ':' . $id])));
        if ($docIds === []) {
            return;
        }
        // Zápis => doklad. Fakturu účtuje přímý zápis se zdrojem dokladu i zápisy, které
        // doklad vlastní ve skupině zaúčtování (převod z programu, který účtuje po řádcích);
        // informativní vazby „Souvisí“ zaúčtováním nejsou. Členský předpis je zápis sám.
        // Data pro starý odklad ({@see markLegacyDeferrals()}) z hlaviček všech zápisů skupiny,
        // podle kterých doklad vybírá i rozpuštění; převzatý doklad je může mít bez řádku 5xx/6xx.
        $entryDocs = [];
        $dates = [];
        $lines = [];
        if ($docType === 'other_item') {
            // Dokladem je zdrojový zápis položky (jako u členského předpisu zápis), ne položka;
            // dimenze se berou z týchž zápisů jako obrat ({@see otherItemSources()}, předané
            // v řádku jako `source_entries`). Vyžaduje přednačtení zdrojů, jinak LogicException.
            foreach (array_chunk($docIds, 500) as $chunk) {
                foreach ($this->otherItemSourceLines($supplierId, $chunk, $cache) as $line) {
                    $lines[] = $line;
                }
            }
        } elseif ($docType === 'nonprofit_membership') {
            $entryDocs = array_combine($docIds, $docIds);
        } elseif ($docType === 'cash') {
            // Pokladní doklad skupinu zaúčtování nemá ({@see DocumentPostingGroup::activeMany()}
            // zná jen faktury), účtuje ho přímý zápis se zdrojem dokladu. Koncept a stornovaný
            // zápis vyřadí dotaz na řádky níže.
            foreach (array_chunk($docIds, 500) as $chunk) {
                $marks = implode(',', array_fill(0, count($chunk), '?'));
                $stmt = $this->db->pdo()->prepare(
                    "SELECT je.id, je.source_id
                       FROM journal_entries je FORCE INDEX (idx_je_supplier_source)
                      WHERE je.supplier_id = ? AND je.source_type = 'cash' AND je.source_id IN ({$marks})"
                );
                $stmt->execute([$supplierId, ...$chunk]);
                foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $entry) {
                    $entryDocs[(int) $entry['id']] = (int) $entry['source_id'];
                }
            }
        } else {
            foreach (array_chunk($docIds, 500) as $chunk) {
                foreach ((new DocumentPostingGroup($this->db))->activeMany($supplierId, $docType, $chunk) as $docId => $entries) {
                    foreach ($entries as $entry) {
                        $entryDocs[(int) $entry['id']] = (int) $docId;
                        $dates[(int) $docId][(string) $entry['entry_date']] = true;
                    }
                }
            }
        }
        // Definice účtu (rodič, daňová uznatelnost) k datu zápisu dokladu, jako přesměrování
        // na jedinou analytiku při zaúčtování ({@see PostingService::singleAnalyticRedirects()}).
        $definitions = AccountDefinitionRepository::journalJoin($supplierId);
        foreach (array_chunk(array_keys($entryDocs), 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            // Nejdřív živé zápisy podle primárního klíče, potom jejich řádky
            // (idx_jel_supplier_entry), ať plán nezačíná u řádků deníku celé firmy.
            $stmt = $this->db->pdo()->prepare(
                "SELECT l.id, l.side, l.signed_amount, a.account_code, e.entry_id, e.entry_date,
                        CASE WHEN a.tax_deductibility = 'deductible' THEN p.account_code END AS parent_code
                   FROM (SELECT je.id AS entry_id, je.entry_date
                           FROM journal_entries je
                          WHERE je.supplier_id = ? AND je.id IN ({$marks})
                            AND je.posted_at IS NOT NULL
                            AND je.reversed_by IS NULL) e
                   JOIN journal_entry_lines l ON l.supplier_id = ? AND l.entry_id = e.entry_id
                   {$definitions}
                   LEFT JOIN chart_of_accounts p ON p.id = a.parent_id AND p.supplier_id = a.supplier_id
                  WHERE a.account_code LIKE '5%' OR a.account_code LIKE '6%'
                  ORDER BY l.id"
            );
            $stmt->execute([$supplierId, ...$chunk, $supplierId]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $line) {
                $line['doc_id'] = $entryDocs[(int) $line['entry_id']];
                $lines[] = $line;
            }
        }
        $lineIds = array_map(static fn (array $line): int => (int) $line['id'], $lines);
        $repo = new DimensionAssignmentRepository($this->db);
        $dims = $repo->lineDimensions($supplierId, $lineIds);
        $splits = $repo->lineSplits($supplierId, $lineIds);
        $origins = $repo->lineOrigins($supplierId, $lineIds);
        $splitOrigins = $repo->lineSplitOrigins($supplierId, $lineIds);

        $groups = [];
        $analytics = [];
        foreach ($lines as $line) {
            $id = (int) $line['id'];
            $assignment = array_filter([
                'dimensions' => $dims[$id] ?? [],
                'dimension_splits' => $splits[$id] ?? [],
                'dimension_origins' => $origins[$id] ?? [],
                'dimension_split_origins' => $splitOrigins[$id] ?? [],
            ], static fn (array $value): bool => $value !== []);
            $doc = (int) $line['doc_id'];
            if ($docType === 'nonprofit_membership' || $docType === 'other_item') {
                $dates[$doc][(string) $line['entry_date']] = true;
            }
            $account = (string) $line['account_code'];
            // Jen daňové analytiky syntetiky (nedaňová analytika z builderu ne), jako
            // PostingService::singleAnalyticMap. Další jeho výjimky (vyhrazené analytiky) se
            // nepromítají: vedle cíle přesměrování dají dvě analytiky a výběr se nehádá.
            if ($line['parent_code'] !== null) {
                $analytics[$doc][(string) $line['parent_code']][$account] = true;
            }
            $signature = (string) json_encode(self::assignmentOf($assignment));
            $signed = (float) $line['signed_amount'] * ((string) $line['side'] === 'debit' ? 1 : -1);
            $groups[$doc][$account][$signature]['assignment'] = $assignment;
            $groups[$doc][$account][$signature]['net'] = ($groups[$doc][$account][$signature]['net'] ?? 0.0) + $signed;
            $groups[$doc][$account][$signature]['line_ids'][] = $id;
        }
        foreach ($docIds as $docId) {
            $out = [];
            foreach ($groups[$docId] ?? [] as $account => $bySignature) {
                foreach ($bySignature as $group) {
                    $out[(string) $account][] = ['weight' => round(abs((float) $group['net']), 2), 'net' => round((float) $group['net'], 2), 'assignment' => $group['assignment'], 'line_ids' => $group['line_ids']];
                }
            }
            $cache[$docType . ':' . $docId] = [
                'groups' => $out,
                'entry_dates' => array_map('strval', array_keys($dates[$docId] ?? [])),
                'analytics' => array_map(static fn (array $codes): array => array_map('strval', array_keys($codes)), $analytics[$docId] ?? []),
            ];
        }
    }

    /**
     * Výsledkové řádky (5xx/6xx) zápisů zdrojů ostatních položek s klíčem dokladu = zdroj
     * a datem zápisu, ve tvaru řádků {@see preloadDimensionGroups()}. Řádky deníku po
     * `idx_jel_supplier_entry` (IN přes id zápisů), přiřazení ke zdroji v PHP.
     *
     * @param list<int> $rootIds
     * @param array<string,mixed> $cache
     * @return list<array<string,mixed>>
     */
    private function otherItemSourceLines(int $supplierId, array $rootIds, array $cache): array
    {
        $members = [];
        $unknown = [];
        foreach ($rootIds as $rootId) {
            $entries = $cache[self::CACHE_OTHER_ITEM_SOURCES][$rootId] ?? null;
            if ($entries === null) {
                $unknown[] = $rootId;
                continue;
            }
            foreach ($entries as $entryId => $entryDate) {
                $members[(int) $entryId] = ['root_id' => $rootId, 'entry_date' => (string) $entryDate];
            }
        }
        if ($unknown !== []) {
            // Obrat zdroje tvoří všechny jeho zápisy; dimenze jen z kořene by se s ním rozešly.
            throw new \LogicException('Dimenze ostatní položky vyžadují přednačtení zdrojů (preloadDeferredRevenueAllocations / preloadPrepaidExpenseAllocations).');
        }
        $out = [];
        foreach (array_chunk(array_keys($members), 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT l.id, l.side, l.signed_amount, a.account_code, l.entry_id
                   FROM journal_entry_lines l
                   JOIN chart_of_accounts a ON a.id = l.account_id
                  WHERE l.supplier_id = ? AND l.entry_id IN ({$marks})
                    AND (a.account_code LIKE '5%' OR a.account_code LIKE '6%')
                  ORDER BY l.id"
            );
            $stmt->execute([$supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $line) {
                $member = $members[(int) $line['entry_id']];
                unset($line['entry_id']);
                // Bez analytik syntetiky: řádek ostatní položky odkládá z účtu, který nese zápis
                // (`revenue_account_code`), ne z trasy, takže přesměrování na analytiku nehledá.
                $out[] = $line + ['doc_id' => $member['root_id'], 'entry_date' => $member['entry_date'], 'parent_code' => null];
            }
        }
        usort($out, static fn (array $x, array $y): int => (int) $x['id'] <=> (int) $y['id']);
        return $out;
    }

    /**
     * Dimenze, které zaúčtování dá výsledkovému řádku každé položky, pro víc dokladů
     * najednou: stejné vrstvy jako {@see DimensionStamper::itemDimensions()}, tedy položka
     * (hodnota či rozpad) > výchozí dimenze produktu > hlavička dokladu (hodnota či rozpad) >
     * zděděná hlavička (zakázka > odběratel či dodavatel, {@see DimensionDefaults::resolveMany()}).
     * Čte dávkou, ne po dokladech.
     *
     * @param 'invoice'|'purchase_invoice'|'nonprofit_membership' $docType
     * @param list<int> $docIds
     * @return array<int,array<int,array{dimensions?:array<int,int>, dimension_splits?:array<int,array<int,float>>}>> doklad => položka => dimenze
     */
    public function documentItemDimensions(int $supplierId, string $docType, array $docIds): array
    {
        $out = [];
        foreach ($this->itemDimensionLayers($supplierId, $docType, $docIds) as $docId => $items) {
            foreach ($items as $itemId => $item) {
                $out[$docId][$itemId] = array_intersect_key($item, ['dimensions' => 1, 'dimension_splits' => 1]);
            }
        }
        return $out;
    }

    /**
     * {@see documentItemDimensions()} a k tomu typy, jejichž hodnotu položka jen zdědila
     * z výchozích dimenzí zakázky či protistrany (`inherited`). Ty se mohly od zaúčtování
     * změnit, volnější párování ({@see matchingGroups()}) je proto vynechává.
     *
     * @param 'invoice'|'purchase_invoice'|'nonprofit_membership' $docType
     * @param list<int> $docIds
     * @return array<int,array<int,array{dimensions?:array<int,int>, dimension_splits?:array<int,array<int,float>>, inherited?:list<int>}>>
     */
    private function itemDimensionLayers(int $supplierId, string $docType, array $docIds): array
    {
        $tables = ['invoice' => ['invoice_items', 'invoice_id', 'invoices', 'client_id'], 'purchase_invoice' => ['purchase_invoice_items', 'purchase_invoice_id', 'purchase_invoices', 'vendor_id']];
        $docIds = array_values(array_unique(array_map('intval', $docIds)));
        if (!isset($tables[$docType]) || $docIds === []) {
            return [];
        }
        [$itemTable, $itemColumn, $docTable, $partyColumn] = $tables[$docType];
        $docDims = $docSplits = $rows = $parties = [];
        foreach (array_chunk($docIds, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT doc_id, item_no, dimension_type_id, dimension_value_id FROM document_dimensions
                  WHERE supplier_id = ? AND doc_type = ? AND doc_id IN ({$marks})"
            );
            $stmt->execute([$supplierId, $docType, ...$chunk]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
                $docDims[(int) $r['doc_id']][(int) $r['item_no']][(int) $r['dimension_type_id']] = (int) $r['dimension_value_id'];
            }
            $stmt = $this->db->pdo()->prepare(
                "SELECT doc_id, item_no, dimension_type_id, dimension_value_id, share FROM document_dimension_splits
                  WHERE supplier_id = ? AND doc_type = ? AND doc_id IN ({$marks})"
            );
            $stmt->execute([$supplierId, $docType, ...$chunk]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
                $docSplits[(int) $r['doc_id']][(int) $r['item_no']][(int) $r['dimension_type_id']][(int) $r['dimension_value_id']] = (float) $r['share'];
            }
            // Pořadí položky = pořadí v editoru (order_index), číslováno od 1 jako ve
            // DimensionService::saveDocument() a DimensionStamper::documentContext().
            // Týmž čtením i strany dokladu pro zděděnou hlavičku.
            $stmt = $this->db->pdo()->prepare(
                "SELECT it.id, it.{$itemColumn} AS doc_id, it.stock_item_id, d.{$partyColumn} AS party_id, d.project_id
                   FROM {$docTable} d
                   JOIN {$itemTable} it ON it.{$itemColumn} = d.id
                  WHERE d.supplier_id = ? AND d.id IN ({$marks})
                  ORDER BY it.{$itemColumn}, it.order_index, it.id"
            );
            $stmt->execute([$supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
                $rows[(int) $r['doc_id']][] = $r;
                $parties[(int) $r['doc_id']] = [
                    $r['party_id'] !== null ? (int) $r['party_id'] : null,
                    $r['project_id'] !== null ? (int) $r['project_id'] : null,
                ];
            }
        }
        $products = [];
        foreach ($rows as $docRows) {
            foreach ($docRows as $r) {
                $products[] = (int) ($r['stock_item_id'] ?? 0);
            }
        }
        $defaults = new DimensionDefaults($this->db);
        $productDims = $defaults->forProducts($supplierId, $products);
        $inheritedByDoc = $defaults->resolveMany($supplierId, $parties);

        $out = [];
        foreach ($rows as $docId => $docRows) {
            // Rozpad vstupuje jako značka „rozpad", aby se vrstvy přepisovaly stejně jako hodnota.
            $header = $docDims[$docId][0] ?? [];
            foreach (array_keys($docSplits[$docId][0] ?? []) as $typeId) {
                $header[$typeId] = 'split:0';
            }
            // Zděděná hodnota jen pro typ, který hlavička nemá hodnotou ani rozpadem.
            $inherited = array_diff_key($inheritedByDoc[$docId] ?? [], $header);
            foreach ($docRows as $i => $r) {
                $itemNo = $i + 1;
                $own = $docDims[$docId][$itemNo] ?? [];
                foreach (array_keys($docSplits[$docId][$itemNo] ?? []) as $typeId) {
                    $own[$typeId] = 'split:' . $itemNo;
                }
                foreach ($productDims[(int) ($r['stock_item_id'] ?? 0)]['header'] ?? [] as $typeId => $valueId) {
                    $own[$typeId] ??= (int) $valueId;
                }
                $line = array_replace($header + $inherited, $own);
                $item = [];
                foreach ($line as $typeId => $value) {
                    if (is_string($value)) {
                        $item['dimension_splits'][(int) $typeId] = $docSplits[$docId][(int) substr($value, 6)][$typeId];
                    } else {
                        $item['dimensions'][(int) $typeId] = (int) $value;
                    }
                }
                $inheritedTypes = array_map('intval', array_keys(array_diff_key($inherited, $own)));
                $out[$docId][(int) $r['id']] = $item + ($inheritedTypes === [] ? [] : ['inherited' => $inheritedTypes]);
            }
        }
        return $out;
    }

    /**
     * Hodnoty, které doklad dává některé své položce (hodnotou či rozpadem), po typech dimenzí.
     *
     * @param array<int,array<string,mixed>> $items položka => dimenze
     * @return array<int,array<int,true>> typ => hodnota => true
     */
    private static function documentTypes(array $items): array
    {
        $types = [];
        foreach ($items as $item) {
            foreach ($item['dimensions'] ?? [] as $typeId => $valueId) {
                $types[(int) $typeId][(int) $valueId] = true;
            }
            foreach ($item['dimension_splits'] ?? [] as $typeId => $shares) {
                foreach (array_keys($shares) as $valueId) {
                    $types[(int) $typeId][(int) $valueId] = true;
                }
            }
        }
        return $types;
    }

    /**
     * Kombinace řádků zápisu, které patří položce.
     *
     * Nejdřív přesně: kombinace nese právě dimenze, jaké položce dává zaúčtování
     * ({@see itemDimensionLayers()}, včetně zděděných výchozích hodnot a podílů rozpadu).
     * Nevyhoví-li žádná, volněji jen podle hodnot, které dává sám doklad (položka, produkt,
     * hlavička): výchozí dimenze zakázky či protistrany se mohly po zaúčtování změnit a řádek
     * nese tu tehdejší, rozpad se pak porovnává jen hodnotami. Prázdný výsledek = položka se
     * zápisem nesouhlasí a volající dělí poměrem.
     *
     * @param list<array{weight:float, net:float, assignment:array<string,mixed>, line_ids:list<int>}> $groups
     * @param array<int,array<string,mixed>> $items položka => dimenze dokladu
     * @param array<int,true> $drivingTypes účtotvorné typy dimenzí
     * @return list<array{weight:float, net:float, assignment:array<string,mixed>, line_ids:list<int>}>
     */
    private static function matchingGroups(array $groups, array $items, int $itemId, array $drivingTypes): array
    {
        $docTypes = self::documentTypes($items);
        $itemLine = $items[$itemId] ?? [];
        $matching = array_values(array_filter($groups, static fn (array $group): bool => self::groupCarries($group, $itemLine, $docTypes, $drivingTypes)));
        if ($matching !== []) {
            return $matching;
        }
        $own = array_map(static function (array $item): array {
            $drop = array_flip($item['inherited'] ?? []);
            return ['dimensions' => array_diff_key($item['dimensions'] ?? [], $drop), 'dimension_splits' => $item['dimension_splits'] ?? []];
        }, $items);
        $docTypes = self::documentTypes($own);
        $itemLine = $own[$itemId] ?? [];
        return array_values(array_filter($groups, static fn (array $group): bool => self::groupCarries($group, $itemLine, $docTypes, null)));
    }

    /**
     * Patří kombinace řádku zápisu položce? V každém typu, který doklad přiřazuje, musí
     * kombinace nést právě hodnotu položky, resp. stejný rozpad včetně podílů. Rozpadu
     * účtotvorné dimenze vyhoví i díl s jednou jeho hodnotou (rozpad se dělí na řádky analytik).
     * Chybí-li položce hodnota typu, nesmí kombinace nést hodnotu, kterou doklad dává jiné
     * položce; hodnota převzatá odjinud (pravidlo podle účtu, ruční řádek) vyhoví. Typy,
     * které doklad nepřiřazuje, se neporovnávají.
     *
     * `$drivingTypes === null` = volnější porovnání rozpadu: stačí stejné hodnoty (bez podílů)
     * nebo jedna z nich u kteréhokoli typu.
     *
     * @param array{weight:float, assignment:array<string,mixed>, line_ids:list<int>} $group
     * @param array<string,mixed> $itemLine dimenze položky
     * @param array<int,array<int,true>> $docTypes výstup {@see documentTypes()}
     * @param array<int,true>|null $drivingTypes
     */
    private static function groupCarries(array $group, array $itemLine, array $docTypes, ?array $drivingTypes): bool
    {
        foreach ($docTypes as $typeId => $explicit) {
            $groupValue = $group['assignment']['dimensions'][$typeId] ?? null;
            $groupSplit = $group['assignment']['dimension_splits'][$typeId] ?? null;
            $itemSplit = $itemLine['dimension_splits'][$typeId] ?? null;
            $itemValue = isset($itemLine['dimensions'][$typeId]) ? (int) $itemLine['dimensions'][$typeId] : null;
            if ($itemSplit !== null) {
                if ($groupSplit !== null) {
                    if (!self::sameSplit($itemSplit, $groupSplit, $drivingTypes !== null)) {
                        return false;
                    }
                } elseif ($groupValue === null || !isset($itemSplit[(int) $groupValue]) || ($drivingTypes !== null && !isset($drivingTypes[$typeId]))) {
                    return false;
                }
                continue;
            }
            if ($itemValue !== null) {
                if ($groupSplit !== null || ($groupValue === null ? null : (int) $groupValue) !== $itemValue) {
                    return false;
                }
                continue;
            }
            $carriedValues = $groupSplit !== null ? array_keys($groupSplit) : ($groupValue === null ? [] : [$groupValue]);
            foreach ($carriedValues as $valueId) {
                if (isset($explicit[(int) $valueId])) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Nesou dva rozpady stejné hodnoty, a s `$withShares` i stejné podíly (na zaokrouhlení)?
     *
     * @param array<int,float> $a hodnota => podíl
     * @param array<int,float> $b
     */
    private static function sameSplit(array $a, array $b, bool $withShares): bool
    {
        if (count($a) !== count($b) || array_diff_key($a, $b) !== []) {
            return false;
        }
        if ($withShares) {
            foreach ($a as $valueId => $share) {
                if (abs((float) $share - (float) $b[$valueId]) > self::SHARE_TOLERANCE) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Přiřazení zúžené na dané typy dimenzí (starý odklad ve 504 si ponechá jen osy činnosti).
     *
     * @param array<string,mixed> $assignment
     * @param list<int> $types
     * @return array<string,mixed>
     */
    private static function onlyTypes(array $assignment, array $types): array
    {
        $keep = array_flip($types);
        $out = [];
        foreach ($assignment as $field => $byType) {
            $kept = array_intersect_key($byType, $keep);
            if ($kept !== []) {
                $out[$field] = $kept;
            }
        }
        return $out;
    }

    /**
     * Přiřazení dimenzí řádku včetně původu. Řádky se seskupují i podle původu, aby
     * odklad nesl přesně tu stopu, kterou nese řádek dokladu.
     *
     * @param array<string,mixed> $allocation
     * @return array<string,mixed>
     */
    private static function assignmentOf(array $allocation): array
    {
        return array_intersect_key($allocation, ['dimensions' => 1, 'dimension_splits' => 1, 'dimension_origins' => 1, 'dimension_split_origins' => 1]);
    }

    // ── účet časového rozlišení ───────────────────────────────────────────────

    /**
     * Účet časového rozlišení z předkontace: MD pravidla accrual.prepaid.expense (381),
     * D pravidla accrual.deferred.revenue (384). Tenant si účet může přesměrovat.
     */
    public function deferralAccount(int $supplierId, string $ruleKey): string
    {
        $rule = $this->rules->resolve($supplierId, $ruleKey);
        return $ruleKey === self::RULE_DEFERRED_REVENUE
            ? (string) ($rule['credit_account_code'] ?? '384')
            : (string) ($rule['debit_account_code'] ?? '381');
    }

    /** LIKE prefix účtu časového rozlišení z pravidla (381 resp. 384 včetně analytik). */
    private function deferralAccountPrefix(int $supplierId, string $ruleKey): string
    {
        return addcslashes($this->deferralAccount($supplierId, $ruleKey), '%_\\') . '%';
    }

    // ── SQL podmínky odkladu ──────────────────────────────────────────────────

    /**
     * Podmínka „odklad dokladu zaúčtovala uzávěrka": den dokladu (`$dateSql`) leží v účetním
     * období končícím nejpozději k vázanému `?`, jehož krok časového rozlišení zaúčtoval
     * odkladový zápis (`$sourceType`, period_id).
     *
     * openNext rozpouští jen to, co odložila uzávěrka (`?` = konec období, ze kterého
     * otevírá). Odklad vzniklý jinak (doklad rozúčtovaný rovnou na 381/384, ruční nebo
     * převzatý zápis) rozpouští účetní stejnou cestou, jakou vznikl, takže by ho openNext
     * rozpustil podruhé. Doklad z cílového období nebyl odložen vůbec: jeho náklad či výnos
     * je v tom období celý. Výkaz mimo deník (`?` = rozvahový den) z téhož důvodu neupravuje
     * doklad, jehož odklad k tomu dni už v deníku je. Stejně čte odklad
     * {@see ClosingService::pendingDeferralRelease} v projekci pro náhled DPPO.
     */
    private static function deferredByClosingSql(string $sourceType, string $supplierSql, string $dateSql, array $skipPeriodIds = []): string
    {
        // Období, jejichž odklad se rozpouští ze záznamu kroku ({@see closingDeferralSnapshots()}),
        // se z výběru podle dnešního stavu dokladů vyřadí (id jsou celá čísla z deníku).
        $skip = $skipPeriodIds === [] ? '' : ' AND dap.id NOT IN (' . implode(',', array_map('intval', $skipPeriodIds)) . ')';
        return "EXISTS (SELECT 1
                  FROM accounting_periods dap
                  JOIN journal_entries dje
                    ON dje.supplier_id = dap.supplier_id
                   AND dje.source_type = '{$sourceType}'
                   AND dje.source_id = dap.id
                   AND dje.posted_at IS NOT NULL
                   AND dje.reversed_by IS NULL
                 WHERE dap.supplier_id = {$supplierSql}
                   AND dap.ends_on <= ?
                   AND {$dateSql} >= dap.starts_on
                   AND {$dateSql} <= dap.ends_on{$skip})";
    }

    /**
     * Podmínka „doklad nese odklad už sám": zápis skupiny zaúčtování dokladu (přímý zápis
     * i zápisy převzatého dokladu, {@see DocumentPostingGroup::postedEntryExistsSql()}) nebo
     * zápis k dokladu připojený vazbou „Souvisí" (ruční interní doklad) účtuje na účet
     * časového rozlišení. Parametry váže {@see deferredOnDocumentParams()}.
     *
     * Faktura rozúčtovaná rovnou na 381/384 má odklad v knihách od zaúčtování a rozpouští
     * ho účetní stejnou cestou. Uzávěrka by ji odložila podruhé a openNext rozpustil
     * dvakrát. Rozpad zápisu na položky deník nezná, proto se vyřazuje celý doklad.
     *
     * Vazba „Souvisí" na zápis, který zaúčtovala uzávěrka nebo otevření roku
     * ({@see CLOSING_SOURCE_TYPES}), vlastním odkladem dokladu není: účetní ji připojí kvůli
     * dohledatelnosti a odklad z uzávěrky by jinak sám sobě zablokoval rozpuštění.
     */
    private static function deferredOnDocumentSql(string $docType, string $supplierSql, string $docIdSql): string
    {
        $closingTypes = "'" . implode("', '", self::CLOSING_SOURCE_TYPES) . "'";
        return '(' . DocumentPostingGroup::postedEntryExistsSql($docType, $docIdSql, $supplierSql, 'xje', '
                    AND EXISTS (SELECT 1
                          FROM journal_entry_lines xl
                          JOIN chart_of_accounts xa ON xa.id = xl.account_id
                         WHERE xl.supplier_id = xje.supplier_id AND xl.entry_id = xje.id
                           AND xa.account_code LIKE ?)') . "
             OR EXISTS (SELECT 1
                   FROM journal_entry_document_links xdl
                   JOIN journal_entries xje    ON xje.supplier_id = xdl.supplier_id AND xje.id = xdl.entry_id
                   JOIN journal_entry_lines xl ON xl.entry_id = xje.id AND xl.supplier_id = xje.supplier_id
                   JOIN chart_of_accounts xa   ON xa.id = xl.account_id
                  WHERE xdl.supplier_id = {$supplierSql}
                    AND xdl.doc_type = '{$docType}'
                    AND xdl.doc_id = {$docIdSql}
                    AND xje.posted_at IS NOT NULL
                    AND xje.reversed_by IS NULL
                    AND xje.source_type NOT IN ({$closingTypes})
                    AND xa.account_code LIKE ?))";
    }

    /**
     * Parametry {@see deferredOnDocumentSql()}: prefix účtu časového rozlišení za obě větve
     * skupiny zaúčtování a za vazby „Souvisí".
     *
     * @return list<string>
     */
    private static function deferredOnDocumentParams(string $deferPrefix): array
    {
        return [$deferPrefix, $deferPrefix, $deferPrefix];
    }

    /**
     * Vyřadí řádky dokladů, které spravuje agenda Časové rozlišení (položka agendy ke
     * zdrojovému dokladu nebo jeho řádku). Ty rozpouští agenda svými zápisy; krok uzávěrky,
     * otevření roku ani průběžný výkaz je nesmí odložit či rozpustit podruhé. Volá se nad
     * výběrem, který volající stejně celý prochází; seznam položek agendy je malý a čte se
     * jedním dotazem ({@see AccrualAgendaGuard::managedKeys()}).
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public function withoutAgendaItems(int $supplierId, array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }
        $keys = (new AccrualAgendaGuard($this->db))->managedKeys($supplierId);
        if ($keys === []) {
            return $rows;
        }
        return array_values(array_filter($rows, static fn (array $row): bool => !AccrualAgendaGuard::excludesRow($keys, $row)));
    }

    // ── poměr dnů ─────────────────────────────────────────────────────────────
    // Výpočet žije v {@see AccrualScheduleCalculator}; tyto metody na něj jen předávají
    // (zůstávají kvůli dosavadním volajícím, vlastní výpočet tu není).

    /** {@see AccrualScheduleCalculator::deferredAmount()} */
    public static function deferredAmount(float $netCzk, string $from, string $to, string $asOf): float
    {
        return AccrualScheduleCalculator::deferredAmount($netCzk, $from, $to, $asOf);
    }

    /** {@see AccrualScheduleCalculator::cumRecognized()} */
    public static function cumRecognized(float $netCzk, string $from, string $to, string $through): float
    {
        return AccrualScheduleCalculator::cumRecognized($netCzk, $from, $to, $through);
    }

    /** {@see AccrualScheduleCalculator::daysInclusive()} */
    public static function daysInclusive(string $from, string $to): int
    {
        return AccrualScheduleCalculator::daysInclusive($from, $to);
    }

    /**
     * Rozdělí částku položky mezi účty podle podílů na haléř (zbytek na největší podíl).
     *
     * @param array<string,float> $shares
     * @return array<string,float>
     */
    public static function spreadByAccount(float $amount, array $shares): array
    {
        if (count($shares) === 1) {
            return [(string) array_key_first($shares) => $amount];
        }
        $cents = DimensionStamper::distributeCents((int) round($amount * 100), array_values($shares));
        $out = [];
        foreach (array_keys($shares) as $i => $account) {
            $out[(string) $account] = $cents[$i] / 100;
        }
        return $out;
    }
}
