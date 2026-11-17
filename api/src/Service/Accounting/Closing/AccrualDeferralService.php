<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountDefinitionRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\DimensionAccountMapRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionDefaults;
use MyInvoice\Service\Accounting\Dimension\DimensionStamper;
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
     * dokladu v [costFrom..asOf] (náklad musí ve výkazu být, uzávěrku nezaúčtovaný doklad
     * zastaví už kontrolou) a vyřadí doklady, jejichž odklad k tomu dni zaúčtovala
     * uzávěrka ({@see deferredByClosingSql}).
     *
     * @return list<array<string,mixed>>
     */
    public function prepaidExpenseRows(int $supplierId, string $afterDate, string $costFrom, string $costTo, ?string $asOfInJournal = null): array
    {
        $deferPrefix = $this->deferralAccountPrefix($supplierId, self::RULE_PREPAID_EXPENSE);
        $journalSql = '';
        $params = [$supplierId, $afterDate, $costFrom, $costTo];
        if ($asOfInJournal !== null) {
            $journalSql = 'AND EXISTS (SELECT 1 FROM journal_entries pje
                                 WHERE pje.supplier_id = pi.supplier_id
                                   AND pje.source_type = \'purchase_invoice\' AND pje.source_id = pi.id
                                   AND pje.posted_at IS NOT NULL AND pje.reversed_by IS NULL
                                   AND pje.entry_date >= ? AND pje.entry_date <= ?)
                AND NOT ' . self::deferredByClosingSql('prepaid_expense_accrual', 'pi.supplier_id', 'pi.effective_cost_date');
            array_push($params, $costFrom, $asOfInJournal, $asOfInJournal);
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
        array_push($params, $deferPrefix, $deferPrefix);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * EP-15: řádky pro ROZPOUŠTĚNÍ odloženého nákladu do cílového období — narozdíl od
     * {@see prepaidExpenseRows} BEZ omezení na effective_cost_date, aby se v N+1
     * (openNext) našel i víceletý odklad pořízený v N (a rozpustil jen tranši N+1, ne celý
     * zbytek). `originEndsOn` = konec období, ze kterého openNext otevírá (deferováno za ním);
     * `targetEnd` = konec cílového období (rozlišení se týká jen položek začínajících do něj).
     * Bere jen položky, jejichž odklad zaúčtovala uzávěrka ({@see deferredByClosingSql}).
     *
     * @return list<array<string,mixed>>
     */
    public function prepaidExpenseReleaseRows(int $supplierId, string $originEndsOn, string $targetEnd): array
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
                AND ' . self::deferredByClosingSql('prepaid_expense_accrual', 'pi.supplier_id', 'pi.effective_cost_date') . '
                AND NOT ' . self::deferredOnDocumentSql('purchase_invoice', 'pi.supplier_id', 'pi.id') . '
              ORDER BY pi.id, pii.order_index, pii.id'
        );
        $stmt->execute([$supplierId, $originEndsOn, $targetEnd, $originEndsOn, $deferPrefix, $deferPrefix]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Účetní náklad řádku přijaté faktury v Kč, ze kterého se počítá odklad na 381: základ
     * a neodpočitatelná část daně ({@see PostingService::purchaseItemCostsCzk()}, SSOT), jinak
     * by budoucí část daně zůstala v nákladu roku pořízení.
     *
     * @param array<string,mixed> $row
     * @param array<int,array<int,float>> $costsByInvoice cache jednoho průchodu (faktura => položka => náklad)
     */
    public function prepaidExpenseCostCzk(int $supplierId, array $row, array &$costsByInvoice): float
    {
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
     * ({@see PostingService::itemAccountsByDimension()}).
     *
     * @param array<string,mixed> $row
     * @param array<int,array<int,array<string,float>>> $accountsByInvoice cache jednoho průchodu (faktura => položka => účet => podíl)
     * @return array<string,float> účet => podíl
     */
    public function prepaidExpenseAccounts(int $supplierId, array $row, array &$accountsByInvoice): array
    {
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

    // ── výnosy příštích období (384) ──────────────────────────────────────────

    /**
     * Řádky vydaných faktur s obdobím výnosu přesahujícím `afterDate`, jejichž zaúčtovaný
     * a nestornovaný zápis faktury leží v [postedFrom..postedTo]. Odklad: zápis v uzavíraném
     * období; rozpuštění: zápis kdykoli do konce období, ze kterého se otevírá, a jen v období,
     * jehož odklad zaúčtovala uzávěrka (`$release`, {@see deferredByClosingSql}). Výkaz mimo
     * deník (`$asOfInJournal`) naopak vyřadí doklady, jejichž odklad k tomu dni zaúčtovala
     * uzávěrka.
     *
     * @return list<array<string,mixed>>
     */
    public function deferredRevenueRows(int $supplierId, string $afterDate, ?string $postedFrom, string $postedTo, ?string $startsBy = null, bool $release = false, ?string $asOfInJournal = null): array
    {
        [$closingSql, $closingParams] = self::closingFilter($release, $asOfInJournal, $postedTo, 'je.entry_date');
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
                AND EXISTS (SELECT 1 FROM journal_entries je
                             WHERE je.supplier_id = i.supplier_id
                               AND je.source_type = \'invoice\' AND je.source_id = i.id
                               AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL
                               AND je.entry_date <= ?
                               AND (? IS NULL OR je.entry_date >= ?)
                               ' . $closingSql . ')
                AND NOT ' . self::deferredOnDocumentSql('invoice', 'i.supplier_id', 'i.id') . '
              ORDER BY i.id, ii.order_index, ii.id'
        );
        $params = [$supplierId, $afterDate, $startsBy, $startsBy, $postedTo, $postedFrom, $postedFrom, ...$closingParams];
        $params[] = $deferPrefix;
        $params[] = $deferPrefix;
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = $row + ['source' => 'invoice', 'membership_case_id' => null, 'journal_entry_id' => null, 'revenue_account_code' => null];
        }
        return [...$rows, ...$this->membershipDeferredRevenueRows($supplierId, $afterDate, $postedFrom, $postedTo, $startsBy, $release, $asOfInJournal)];
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
            $rows[] = $row + ['description' => 'Členský příspěvek ' . $row['varsymbol']];
        }
        return $rows;
    }

    /**
     * Podmínka na uzávěrkový odklad pro výnosové řádky: rozpuštění bere jen odklad
     * zaúčtovaný uzávěrkou (k `$postedTo`), výkaz mimo deník jen doklady, jejichž odklad
     * k `$asOfInJournal` uzávěrka ještě nezaúčtovala. Odklad v uzávěrce podmínku nemá.
     *
     * @return array{0:string,1:list<string>}
     */
    private static function closingFilter(bool $release, ?string $asOfInJournal, string $postedTo, string $dateSql): array
    {
        if ($release) {
            return ['AND ' . self::deferredByClosingSql('deferred_revenue_accrual', 'je.supplier_id', $dateSql), [$postedTo]];
        }
        if ($asOfInJournal !== null) {
            return ['AND NOT ' . self::deferredByClosingSql('deferred_revenue_accrual', 'je.supplier_id', $dateSql), [$asOfInJournal]];
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
        if ($row['source'] === 'nonprofit_membership') {
            return [round((float) $row['total_without_vat'], 2), [(string) $row['revenue_account_code'] => 1.0]];
        }
        $invoiceId = (int) $row['invoice_id'];
        if (!isset($contexts[$invoiceId])) {
            $resolved = $this->posting->issuedItemRevenueAccounts($supplierId, $invoiceId);
            $resolved['mapped'] = $this->posting->itemAccountsByDimension($supplierId, 'invoice', $invoiceId, $resolved['accounts']);
            $stmt = $this->db->pdo()->prepare(
                'SELECT DISTINCT coa.account_code
                   FROM journal_entries je
                   JOIN journal_entry_lines jel ON jel.entry_id = je.id AND jel.supplier_id = je.supplier_id
                   JOIN chart_of_accounts coa   ON coa.id = jel.account_id
                  WHERE je.supplier_id = ? AND je.source_type = \'invoice\' AND je.source_id = ?
                    AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL
                    AND coa.account_code LIKE \'6%\''
            );
            $stmt->execute([$supplierId, $invoiceId]);
            $posted = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
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
     * @param 'invoice'|'purchase_invoice'|'nonprofit_membership' $docType
     * @param int $docId id faktury, přijaté faktury, resp. id zápisu členského předpisu
     * @param array<string,float> $accounts účet => podíl položky
     * @param array<string,mixed> $cache mezipaměť zápisů jednoho výpočtu (podle dokladu)
     * @return list<array<string,mixed>> kombinace: account_code, account_share, part, dimenze, dimension_carry_lines
     */
    public function dimensionAllocations(int $supplierId, string $docType, int $docId, ?int $itemId, array $accounts, array &$cache): array
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
        return $row['source'] === 'nonprofit_membership'
            ? $this->dimensionAllocations($supplierId, 'nonprofit_membership', (int) $row['journal_entry_id'], null, $accounts, $cache)
            : $this->dimensionAllocations($supplierId, 'invoice', (int) $row['invoice_id'], (int) $row['item_id'], $accounts, $cache);
    }

    /**
     * Rozpad nákladového řádku přijaté faktury ({@see prepaidExpenseRows()},
     * {@see prepaidExpenseReleaseRows()}) na účty a dimenze zápisu faktury.
     *
     * @param array<string,mixed> $row
     * @param array<string,float> $accounts výstup {@see prepaidExpenseAccounts()}
     * @param array<string,mixed> $cache
     * @return list<array<string,mixed>>
     */
    public function prepaidExpenseAllocations(int $supplierId, array $row, array $accounts, array &$cache): array
    {
        return $this->dimensionAllocations($supplierId, 'purchase_invoice', (int) $row['purchase_invoice_id'], (int) $row['item_id'], $accounts, $cache);
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
        $invoices = $entries = [];
        foreach ($rows as $row) {
            if ($row['source'] === 'nonprofit_membership') {
                $entries[] = (int) $row['journal_entry_id'];
            } else {
                $invoices[] = (int) $row['invoice_id'];
            }
        }
        $this->preloadDimensionGroups($supplierId, 'invoice', $invoices, $cache);
        $this->preloadDimensionGroups($supplierId, 'nonprofit_membership', $entries, $cache);
    }

    /**
     * Dávkově načte kombinace dimenzí zápisů přijatých faktur nákladových řádků.
     *
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed> $cache
     */
    public function preloadPrepaidExpenseAllocations(int $supplierId, array $rows, array &$cache): void
    {
        $this->preloadDimensionGroups($supplierId, 'purchase_invoice', array_map(static fn (array $row): int => (int) $row['purchase_invoice_id'], $rows), $cache);
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
     * (`effective_cost_date`, {@see prepaidExpenseReleaseRows()}), výnosový doklad datem
     * kteréhokoli svého živého zápisu ({@see deferredRevenueRows()}).
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
            if (!is_array($doc) || !isset($doc['groups'])) {
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
     * dávkově pro všechny doklady. Uloží je do `$cache` pod klíčem dokladu (i prázdné, ať se
     * doklad bez zápisu nenačítá znovu) spolu s daty zápisů. Kombinace s nulovým obratem
     * zůstává (váha 0): je kandidátem párování položky, do poměrného dělení nevstupuje.
     *
     * @param 'invoice'|'purchase_invoice'|'nonprofit_membership' $docType
     * @param list<int> $docIds id dokladů, u členského předpisu id jeho zápisu
     * @param array<string,mixed> $cache
     */
    private function preloadDimensionGroups(int $supplierId, string $docType, array $docIds, array &$cache): void
    {
        $docIds = array_values(array_filter(array_unique($docIds), static fn (int $id): bool => !isset($cache[$docType . ':' . $id])));
        if ($docIds === []) {
            return;
        }
        $lines = [];
        // Definice účtu (rodič, daňová uznatelnost) k datu zápisu dokladu, jako přesměrování
        // na jedinou analytiku při zaúčtování ({@see PostingService::singleAnalyticRedirects()}).
        $definitions = AccountDefinitionRepository::journalJoin($supplierId);
        foreach (array_chunk($docIds, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            [$docSql, $docParams] = $docType === 'nonprofit_membership'
                ? ["je.id AS doc_id, je.entry_date FROM journal_entries je WHERE je.supplier_id = ? AND je.id IN ({$marks})", [$supplierId, ...$chunk]]
                : ["je.source_id AS doc_id, je.entry_date FROM journal_entries je WHERE je.supplier_id = ? AND je.source_type = ? AND je.source_id IN ({$marks})", [$supplierId, $docType, ...$chunk]];
            // Nejdřív živé zápisy dokladů (idx_je_supplier_source), potom jejich řádky
            // (idx_jel_supplier_entry), ať plán nezačíná u řádků deníku celé firmy.
            $stmt = $this->db->pdo()->prepare(
                "SELECT l.id, l.side, l.signed_amount, a.account_code, e.doc_id, e.entry_date,
                        CASE WHEN a.tax_deductibility = 'deductible' THEN p.account_code END AS parent_code
                   FROM (SELECT je.id AS entry_id, {$docSql}
                            AND je.posted_at IS NOT NULL
                            AND je.reversed_by IS NULL) e
                   JOIN journal_entry_lines l ON l.supplier_id = ? AND l.entry_id = e.entry_id
                   {$definitions}
                   LEFT JOIN chart_of_accounts p ON p.id = a.parent_id AND p.supplier_id = a.supplier_id
                  WHERE a.account_code LIKE '5%' OR a.account_code LIKE '6%'
                  ORDER BY l.id"
            );
            $stmt->execute([...$docParams, $supplierId]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $line) {
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
        $dates = [];
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
            $dates[$doc][(string) $line['entry_date']] = true;
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
    private static function deferredByClosingSql(string $sourceType, string $supplierSql, string $dateSql): string
    {
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
                   AND {$dateSql} <= dap.ends_on)";
    }

    /**
     * Podmínka „doklad nese odklad už sám": vlastní zápis dokladu (`$docType`, id dokladu)
     * nebo zápis k dokladu připojený vazbou (ruční interní doklad, převzatý zápis) účtuje
     * na účet časového rozlišení. Prefix účtu se váže dvakrát (`?`, `?`).
     *
     * Faktura rozúčtovaná rovnou na 381/384 má odklad v knihách od zaúčtování a rozpouští
     * ho účetní stejnou cestou. Uzávěrka by ji odložila podruhé a openNext rozpustil
     * dvakrát. Rozpad zápisu na položky deník nezná, proto se vyřazuje celý doklad.
     */
    private static function deferredOnDocumentSql(string $docType, string $supplierSql, string $docIdSql): string
    {
        return "(EXISTS (SELECT 1
                   FROM journal_entries xje
                   JOIN journal_entry_lines xl ON xl.entry_id = xje.id AND xl.supplier_id = xje.supplier_id
                   JOIN chart_of_accounts xa   ON xa.id = xl.account_id
                  WHERE xje.supplier_id = {$supplierSql}
                    AND xje.source_type = '{$docType}'
                    AND xje.source_id = {$docIdSql}
                    AND xje.posted_at IS NOT NULL
                    AND xje.reversed_by IS NULL
                    AND xa.account_code LIKE ?)
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
                    AND xa.account_code LIKE ?))";
    }

    // ── poměr dnů ─────────────────────────────────────────────────────────────

    /**
     * Část základu řádku s obdobím [from..to], která k rozvahovému dni `asOf` připadá na dny
     * za ním a odkládá se na 381/384: základ − kumulativně uznaná část k `asOf` včetně.
     * Období celé za `asOf` = celý základ, období končící do `asOf` = 0. Neplatné období
     * (konec před začátkem) se nerozlišuje.
     */
    public static function deferredAmount(float $netCzk, string $from, string $to, string $asOf): float
    {
        if (self::daysInclusive($from, $to) <= 0) {
            return 0.0;
        }
        return round($netCzk - self::cumRecognized($netCzk, $from, $to, $asOf), 2);
    }

    /**
     * EP-15: kumulativně uznaný náklad či výnos z rovnoměrného rozlišení [from..to] DO dne
     * $through včetně (na haléře). $through < from → 0; $through >= to → celý $netCzk. Kotva
     * pro konzistentní odklad (net − cumRecognized(konec N)) i víceleté rozpouštění: rozdíl
     * dvou kumulativních hodnot na hranicích období telescopuje přesně na odloženou částku,
     * takže 381/384 se rozpustí do haléře bez zbytku napříč N+1, N+2, …
     */
    public static function cumRecognized(float $netCzk, string $from, string $to, string $through): float
    {
        $from = substr($from, 0, 10);
        $to = substr($to, 0, 10);
        $through = substr($through, 0, 10);
        if ($through < $from) {
            return 0.0;
        }
        $totalDays = self::daysInclusive($from, $to);
        if ($totalDays <= 0) {
            return 0.0;
        }
        $end = $through >= $to ? $to : $through;
        $days = self::daysInclusive($from, $end);
        return round($netCzk * min(1.0, $days / $totalDays), 2);
    }

    /** Počet dnů intervalu VČETNĚ obou krajů (rozvahový den se počítá). */
    public static function daysInclusive(string $from, string $to): int
    {
        $a = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($from, 0, 10));
        $b = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($to, 0, 10));
        if ($a === false || $b === false || $b < $a) {
            return 0;
        }
        return (int) $a->diff($b)->days + 1;
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
