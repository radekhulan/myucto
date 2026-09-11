<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Expense;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ExpenseClassificationRuleRepository;
use MyInvoice\Repository\ExpenseKeywordCatalogRepository;
use MyInvoice\Repository\TaxConstantsRepository;
use PDO;

/**
 * DB vrstva nad pure {@see ExpenseKindClassifier} (§DM): načte pravidla tenanta a roční
 * limit §26/2 ZDP, zbytek nechá na klasifikátoru. Rozdělení je záměrné — algoritmus
 * zůstává jednotkově testovatelný bez DB, tady je jen obstarání vstupů.
 *
 * NIC NEUKLÁDÁ. Vrací návrh, který uživatel v editoru potvrdí nebo přepíše; zápis
 * `expense_kind` je akce uživatele, ne klasifikátoru (§DM „Nikdy neúčtuj automaticky,
 * když si nejsi jistý"). Proto se tu ani nepočítá hit_count — pravidlo se „trefilo"
 * teprve tehdy, když návrh někdo přijme.
 */
final class ExpenseClassificationService
{
    private const DEFAULT_ASSET_LIMIT = 80000.0;

    /** @var array<int, array{fuel?:?string, vehicle_repair?:?string}> per-request cache */
    private array $vehicleAccountsCache = [];

    /** @var array<string,string> per-request cache "supplierId|kód" => účet, na který se účtuje */
    private array $analyticCache = [];

    public function __construct(
        private readonly Connection $db,
        private readonly ExpenseClassificationRuleRepository $rules,
        private readonly TaxConstantsRepository $constants,
        private readonly ExpenseKindClassifier $classifier,
        private readonly ExpenseKeywordCatalogRepository $catalog,
    ) {}

    public function suggestForItem(
        int $supplierId,
        string $description,
        ?string $vendorName,
        ?int $vendorClientId,
        float $unitPrice,
        int $year,
    ): ?ExpenseKindSuggestion {
        return $this->withTenantAnalytic($supplierId, $this->classifier->classify(
            $description,
            $vendorName,
            $vendorClientId,
            $unitPrice,
            $this->assetLimitForYear($year),
            $this->rulesForPrice($supplierId, $unitPrice),
            $this->vehicleAccounts($supplierId),
            $this->catalog->active(),
        ));
    }

    /** @param list<array<string,mixed>> $rules */
    public function suggestFromRules(
        int $supplierId,
        string $description,
        ?string $vendorName,
        ?int $vendorClientId,
        float $unitPrice,
        int $year,
        array $rules,
    ): ?ExpenseKindSuggestion {
        $price = abs($unitPrice);
        $eligible = array_values(array_filter(
            $rules,
            static function (array $rule) use ($price): bool {
                $min = $rule['amount_min'] ?? null;
                $max = $rule['amount_max'] ?? null;
                return !($min !== null && $price < (float) $min)
                    && !($max !== null && $price > (float) $max);
            },
        ));
        return $this->withTenantAnalytic($supplierId, $this->classifier->classify(
            $description,
            $vendorName,
            $vendorClientId,
            $price,
            $this->assetLimitForYear($year),
            $eligible,
            $this->vehicleAccounts($supplierId),
            $this->catalog->active(),
            true,
        ));
    }

    /**
     * Syntetický účet → analytika, na kterou se u firmy skutečně účtuje.
     *
     * Klasifikátor zná jen účty ČÚS (511 u oprav, 548 u pojištění), osnova firmy je ale
     * často vede analyticky. Zápis na syntetiku, která analytiky má, pak v hlavní knize
     * visí vedle svých dětí (reálně: faktura za údržbu softwaru skončila na holém 511
     * vedle 511.100 a 511.900). Jedinou analytiku přesměruje už
     * PostingService::singleAnalyticMap(); tady se řeší i víc analytik: přednost má ta,
     * kterou firma používá v předkontacích, jinak první daňová analytika v pořadí osnovy.
     * Nedaňová analytika se nevybírá nikdy, tu přiřazuje až daňový příznak dokladu.
     *
     * Účet, který syntetikou není nebo analytiky nemá, se vrací beze změny.
     */
    public function analyticForExpenseAccount(int $supplierId, string $code): string
    {
        if (preg_match('/^[0-9]{3}$/', $code) !== 1) {
            return $code;
        }
        $key = $supplierId . '|' . $code;
        if (isset($this->analyticCache[$key])) {
            return $this->analyticCache[$key];
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT c.account_code
               FROM chart_of_accounts p
               JOIN chart_of_accounts c ON c.supplier_id = p.supplier_id AND c.parent_id = p.id
              WHERE p.supplier_id = ? AND p.account_code = ?
                AND c.is_active = 1
                AND c.tax_deductibility = 'deductible'
                AND c.account_code REGEXP '^[0-9]{3}[.][0-9]{1,6}$'
              ORDER BY EXISTS (
                        SELECT 1 FROM posting_rules r
                         WHERE r.supplier_id = c.supplier_id AND r.is_active = 1
                           AND (r.debit_account_code = c.account_code OR r.credit_account_code = c.account_code)
                       ) DESC,
                       c.account_code ASC
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $code]);
        $analytic = $stmt->fetchColumn();

        return $this->analyticCache[$key] = $analytic === false ? $code : (string) $analytic;
    }

    private function withTenantAnalytic(int $supplierId, ?ExpenseKindSuggestion $s): ?ExpenseKindSuggestion
    {
        if ($s === null || $s->accountCode === null) {
            return $s;
        }
        $account = $this->analyticForExpenseAccount($supplierId, $s->accountCode);
        if ($account === $s->accountCode) {
            return $s;
        }

        return new ExpenseKindSuggestion(
            $s->kind,
            $s->confidence,
            $s->reason . ' (analytika ' . $account . ')',
            $s->source,
            $account,
            $s->nonDeductible,
            $s->ruleId,
        );
    }

    /**
     * Analytiky firmy pro PHM a servis vozidel (migrace 1127). Prázdné pole = firma
     * analytiky nevede a účet se odvodí postaru z druhu výdaje.
     *
     * @return array{fuel?:?string, vehicle_repair?:?string}
     */
    private function vehicleAccounts(int $supplierId): array
    {
        $this->vehicleAccountsCache[$supplierId] ??= (function () use ($supplierId): array {
            $stmt = $this->db->pdo()->prepare(
                'SELECT fuel_account_code, vehicle_repair_account_code
                   FROM accounting_supplier_settings WHERE supplier_id = ?'
            );
            $stmt->execute([$supplierId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row === false ? [] : [
                'fuel'           => $row['fuel_account_code'] !== null ? (string) $row['fuel_account_code'] : null,
                'vehicle_repair' => $row['vehicle_repair_account_code'] !== null
                    ? (string) $row['vehicle_repair_account_code'] : null,
            ];
        })();
        return $this->vehicleAccountsCache[$supplierId];
    }

    /**
     * Návrh pro každou položku dokladu, klíčem je id položky.
     *
     * Rok bere z data zdanitelného plnění (fallback datum vystavení) — limit DHM se
     * mění a rozhoduje rok POŘÍZENÍ, ne dnešek. Doklad se dnes klidně účtuje za loňsko.
     *
     * @return array<int,array<string,mixed>> id položky => ExpenseKindSuggestion::toArray()
     */
    public function suggestForInvoice(int $supplierId, int $purchaseInvoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pii.id, pii.description, pii.unit_price_without_vat, pii.expense_kind,
                    pi.vendor_id, pi.exchange_rate, c.company_name AS vendor_name,
                    YEAR(COALESCE(pi.tax_date, pi.issue_date)) AS acq_year
               FROM purchase_invoice_items pii
               JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
               LEFT JOIN clients c ON c.id = pi.vendor_id AND c.supplier_id = pi.supplier_id
              WHERE pii.purchase_invoice_id = ? AND pi.supplier_id = ?
              ORDER BY pii.order_index, pii.id'
        );
        $stmt->execute([$purchaseInvoiceId, $supplierId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $row) {
            // Cenu za kus přepočítáme na CZK kurzem dokladu PŘED klasifikací: práh §26/2 ZDP
            // (80 000 Kč) i rozpětí pravidel (amount_min/max) jsou v korunách. U EUR/USD dokladu
            // by porovnání cizoměnové ceny s korunovým limitem překlopilo majetek špatně
            // (3 500 EUR ≈ 85 260 Kč je dlouhodobý, ne < 80 000). CZK doklad / chybějící kurz ⇒ 1,0.
            $unitPriceCzk = (float) $row['unit_price_without_vat'] * self::fxRate($row['exchange_rate'] ?? null);
            $suggestion = $this->suggestForItem(
                $supplierId,
                (string) $row['description'],
                $row['vendor_name'] !== null ? (string) $row['vendor_name'] : null,
                $row['vendor_id'] !== null ? (int) $row['vendor_id'] : null,
                $unitPriceCzk,
                (int) $row['acq_year'],
            );
            if ($suggestion === null) {
                continue;
            }
            $out[(int) $row['id']] = $suggestion->toArray() + [
                // Co už na řádku je: bez toho UI nepozná, jestli návrh něco mění, a
                // nabízelo by „přepiš" i tam, kde se nic nemění.
                'current_expense_kind' => $row['expense_kind'] !== null ? (string) $row['expense_kind'] : null,
            ];
        }
        return $out;
    }

    /**
     * Pravidla tenanta zúžená cenovým rozpětím. Rozpětí vyhodnocuje SLUŽBA, ne pure
     * klasifikátor: ten matchuje dodavatele a text, cenu zná jen kvůli prahu §26/2 ZDP.
     * Kdyby se rozpětí neaplikovalo, sloupce amount_min/amount_max by byly mrtvé a
     * uživatel by je vyplňoval bez účinku — tichá lež v UI.
     *
     * Porovnává se cena ZA KUS bez DPH (shodně s prahem DHM): 2 ks po 50 000 je pořád
     * drobný majetek, §26/2 ZDP mluví o vstupní ceně jedné věci.
     *
     * @return list<array<string,mixed>>
     */
    private function rulesForPrice(int $supplierId, float $unitPrice): array
    {
        $price = abs($unitPrice); // dobropis má záporný řádek, věcně jde o tutéž věc
        return array_values(array_filter(
            $this->rules->activeFor($supplierId),
            static function (array $rule) use ($price): bool {
                $min = $rule['amount_min'] ?? null;
                $max = $rule['amount_max'] ?? null;
                if ($min !== null && $price < (float) $min) {
                    return false;
                }
                return !($max !== null && $price > (float) $max);
            },
        ));
    }

    public function assetLimitForYear(int $year): float
    {
        return (float) ($this->constants->forYear($year)['fixed_asset_limit'] ?? self::DEFAULT_ASSET_LIMIT);
    }

    /**
     * Kurz dokladu pro přepočet ceny za kus na CZK. CZK doklad i doklad bez vyplněného
     * kurzu (NULL / ≤ 0) ⇒ 1,0 — nominál se bere jako koruny. Cizoměnový doklad se ocení
     * uloženým kurzem, takže se práh §26/2 i rozpětí pravidel vyhodnotí v Kč.
     */
    private static function fxRate(mixed $rate): float
    {
        $r = $rate !== null ? (float) $rate : 0.0;
        return $r > 0 ? $r : 1.0;
    }
}
