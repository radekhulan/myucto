<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PostingRuleRepository;
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
            $rows[] = $row + ['source' => 'invoice', 'membership_case_id' => null, 'revenue_account_code' => null];
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
            "SELECT o.id AS item_id, NULL AS invoice_id, cc.id AS membership_case_id,
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
