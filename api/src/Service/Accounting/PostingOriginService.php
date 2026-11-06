<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Accounting\Expense\ExpenseKind;
use MyInvoice\Service\Accounting\Product\ProductPostingDefaults;
use PDO;

/**
 * Čtecí model „podle JAKÉ ŠABLONY tenhle zápis vznikl" — a kde se ta šablona opraví.
 *
 * Sekce Zaúčtování dosud ukazovala jen VÝSLEDEK (dvojice účtů) a u přijaté faktury
 * navíc nákladové pravidlo. Jenže nákladové pravidlo určuje jen DRUH řádku; účty
 * z něj neplynou. Ty určuje až **předkontace** (`posting_rules`, stabilní klíč jako
 * `invoice.services.received`), a ta v UI nebyla vidět vůbec — účetní tedy viděla
 * účet 518, ale neměla jak zjistit, že ho lze změnit jedním řádkem v Nástrojích, a
 * místo toho přepisovala kontaci na každém dokladu zvlášť.
 *
 * Vrstvy se liší podle druhu dokladu (manuál § 43.2.1 a § 80.5) a tahle služba je
 * pro každý druh dohledá na JEDNOM místě, ať se popis vrstev nerozejde s tím, co
 * kód opravdu dělá:
 *
 *   - **vydaná faktura** → předkontace podle `invoices.revenue_rule_key`
 *     (prázdné = `invoice.services.issued`); tentýž fallback má
 *     {@see PostingService::buildFromInvoice()},
 *   - **přijatá faktura** → druh výdaje na řádcích ({@see ExpenseKind::ruleKey()})
 *     → předkontace, plus nákladové pravidlo, které ten druh vybralo,
 *   - **účet položky / produktu / kategorie** (F1) → `item_accounts`, přebíjí předkontaci
 *     u řádků, které ho mají ({@see ProductPostingDefaults}),
 *   - **bankovní pohyb** → pravidlo účtování / vestavěné rozpoznání / naučená
 *     kontace / spárovaná platba (předkontace `payment.*`); zdroj čte
 *     {@see AutomationProvenanceService} z `bank_posting_suggestions`.
 *
 * DVĚ VĚCI, KTERÉ SE TU NESMÍ PŘEDSTÍRAT:
 *
 * 1. **Šablona se odvozuje ze stavu dokladu, ne z toho, co proběhlo při účtování.**
 *    Nikde se neukládá „tenhle zápis vyrobila předkontace X", takže odpověď je vždy
 *    „takhle by se doklad zaúčtoval podle dnešního nastavení". Proto se u každé
 *    předkontace vrací `used` = jestli se její účty v zápisu opravdu objevily.
 *    `used = false` znamená, že kontace v deníku vznikla jinak (ruční zápis, starší
 *    nastavení) — a UI to musí říct místo toho, aby ukázalo šablonu, která se
 *    nepoužila.
 * 2. **Ruční přeúčtování šablonu přebíjí.** Po {@see DocumentRepostService::repost()}
 *    za kontací stojí účetní, ne pravidlo — a bankovní `bank_posting_suggestions`
 *    přitom pořád ukazují na zápis, který kdysi vyrobila automatika. Proto se
 *    z `activity_log` čte `accounting.reposted` a hlásí se `manual_repost`.
 */
final class PostingOriginService
{
    // Výchozí klíče se BEROU z PostingService, ne opisují: kdyby se rozešly, ukazovalo
    // by UI předkontaci, podle které se neúčtovalo. Vlastní jména tu zůstávají kvůli
    // čitelnosti volajících (a testu, který obě strany drží u sebe).
    /** Klíč předkontace pro vydanou fakturu bez vlastního klíče výnosu na hlavičce. */
    public const DEFAULT_ISSUED_KEY = PostingService::DEFAULT_ISSUED_RULE_KEY;
    /** Klíč předkontace pro přijatou fakturu bez klasifikace řádků. */
    public const DEFAULT_RECEIVED_KEY = PostingService::DEFAULT_RECEIVED_RULE_KEY;
    /** Přijatá faktura označená jako pořízení dlouhodobého majetku. */
    public const DEFAULT_ASSET_KEY = PostingService::DEFAULT_RECEIVED_ASSET_RULE_KEY;

    public function __construct(
        private readonly Connection $db,
        private readonly PostingRuleRepository $rules,
        private readonly JournalEntryRepository $journal,
        private readonly PurchaseInvoiceRepository $purchases,
        private readonly AutomationProvenanceService $automation,
    ) {}

    /**
     * @param 'invoice'|'purchase_invoice'|'bank' $sourceType
     *
     * @return array{source_type:string, entry_id:?int, posted:bool, origin:string,
     *               manual_repost:?array{at:string, by:?string},
     *               presets:list<array<string,mixed>>, rules:list<array<string,mixed>>,
     *               detector:?string}
     */
    public function describe(int $supplierId, string $sourceType, int $docId): array
    {
        $entry = $this->journal->findBySource($supplierId, $sourceType, $docId);
        $entryId = $entry === null || $entry['reversed_by'] !== null ? null : (int) $entry['id'];
        $entryIds = $entryId === null ? [] : [$entryId];
        if (in_array($sourceType, ['invoice', 'purchase_invoice'], true)) {
            $active = (new DocumentPostingGroup($this->db))->active($supplierId, $sourceType, $docId);
            $activeIds = array_map(static fn (array $row): int => (int) $row['id'], $active);
            // Přímý koncept tu jako dosud reprezentuje plánovanou kontaci. Skupinu
            // po stornu přímého člena dál reprezentuje její živý vlastněný člen.
            $entryId ??= $activeIds[0] ?? null;
            $entryIds = array_values(array_unique([...$entryIds, ...$activeIds]));
        }
        $usedAccounts = $this->entryAccountCodes($supplierId, $entryIds);

        $out = [
            'source_type'   => $sourceType,
            'entry_id'      => $entryId,
            'posted'        => $entryId !== null,
            'origin'        => 'unknown',
            'manual_repost' => $entryId === null ? null : $this->manualRepost($supplierId, $entryId),
            'presets'       => [],
            'rules'         => [],
            'detector'      => null,
            'item_accounts' => [],
        ];

        $out = match ($sourceType) {
            'invoice'          => $this->describeIssued($supplierId, $docId, $out, $usedAccounts),
            'purchase_invoice' => $this->describeReceived($supplierId, $docId, $out, $usedAccounts),
            'bank'             => $this->describeBank($supplierId, $out, $usedAccounts),
            default            => $out,
        };

        // Ručně přeúčtovaný zápis už žádnou šablonu nereprezentuje — účty v něm
        // vybrala účetní. Šablony se dál vracejí (dají se opravit pro příště), ale
        // původ zápisu je „ručně".
        if ($out['manual_repost'] !== null) {
            $out['origin'] = 'manual_repost';
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $out
     * @param list<string> $usedAccounts
     * @return array<string,mixed>
     */
    private function describeIssued(int $supplierId, int $docId, array $out, array $usedAccounts): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT revenue_rule_key FROM invoices WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$docId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return $out;
        }

        $key = trim((string) ($row['revenue_rule_key'] ?? ''));
        $out['presets'][] = $this->preset(
            $supplierId,
            $key !== '' ? $key : self::DEFAULT_ISSUED_KEY,
            $key !== '' ? 'revenue_key' : 'default',
            $usedAccounts,
        );
        $out['origin'] = 'preset';

        // Účet položky > produkt > kategorie (F1) má přednost před předkontací — prodej
        // majetku (641/642) ho přebíjí, takže takové řádky tu nejsou.
        $items = $this->db->pdo()->prepare(
            "SELECT revenue_account_code AS own_code, stock_item_id FROM invoice_items
              WHERE invoice_id = ? AND asset_id IS NULL AND small_asset_id IS NULL AND item_kind <> 'discount'"
        );
        $items->execute([$docId]);
        $out['item_accounts'] = $this->itemAccounts($supplierId, $items->fetchAll(PDO::FETCH_ASSOC), ProductPostingDefaults::KIND_REVENUE, $usedAccounts);
        if ($out['item_accounts'] !== []) {
            $out['origin'] = 'item_account';
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $out
     * @param list<string> $usedAccounts
     * @return array<string,mixed>
     */
    private function describeReceived(int $supplierId, int $docId, array $out, array $usedAccounts): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pi.is_fixed_asset,
                    GROUP_CONCAT(DISTINCT pii.expense_kind ORDER BY pii.expense_kind) AS kinds
               FROM purchase_invoices pi
          LEFT JOIN purchase_invoice_items pii ON pii.purchase_invoice_id = pi.id
              WHERE pi.id = ? AND pi.supplier_id = ?
              GROUP BY pi.id'
        );
        $stmt->execute([$docId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return $out;
        }

        foreach (self::receivedPresetKeys(
            explode(',', (string) ($row['kinds'] ?? '')),
            (bool) $row['is_fixed_asset'],
        ) as $key => $reason) {
            $out['presets'][] = $this->preset($supplierId, (string) $key, $reason, $usedAccounts);
        }

        // Účet položky, jinak (bez druhu výdaje a mimo pořízení majetku) účet produktu
        // > kategorie — stejné pořadí jako PostingService::purchaseExpenseWeights().
        $items = $this->db->pdo()->prepare(
            'SELECT expense_account_code AS own_code,
                    CASE WHEN expense_kind IS NULL AND is_fixed_asset = 0 AND ? = 0 THEN stock_item_id END AS stock_item_id
               FROM purchase_invoice_items WHERE purchase_invoice_id = ?'
        );
        $items->execute([(int) (bool) $row['is_fixed_asset'], $docId]);
        $out['item_accounts'] = $this->itemAccounts($supplierId, $items->fetchAll(PDO::FETCH_ASSOC), ProductPostingDefaults::KIND_EXPENSE, $usedAccounts);

        $prov = $this->purchases->expenseClassificationProvenance($supplierId, $docId);
        if ($prov['rule_id'] === null && $out['item_accounts'] !== []) {
            $out['origin'] = 'item_account';
        } elseif ($prov['rule_id'] !== null) {
            $out['rules'][] = [
                'type'      => 'expense',
                'id'        => $prov['rule_id'],
                'name'      => $prov['rule_name'],
                'exists'    => $prov['rule_exists'],
                'is_active' => $prov['rule_is_active'],
            ];
            $out['origin'] = 'expense_rule';
        } else {
            $out['origin'] = 'preset';
        }

        return $out;
    }

    /**
     * Účty, které na dokladu určila položka, produkt nebo kategorie (F1), seskupené
     * podle účtu a zdroje. Produkt a kategorie se řeší toutéž cestou jako zaúčtování
     * ({@see ProductPostingDefaults::accountsFor()}).
     *
     * @param list<array{own_code:?string, stock_item_id:mixed}> $rows
     * @param ProductPostingDefaults::KIND_* $kind
     * @param list<string> $usedAccounts
     * @return list<array{account_code:string, source:string, product_id:?int, product_name:?string, used:bool}>
     */
    private function itemAccounts(int $supplierId, array $rows, string $kind, array $usedAccounts): array
    {
        $products = (new ProductPostingDefaults($this->db))->accountsFor(
            $supplierId,
            array_map(static fn (array $r): int => (int) ($r['stock_item_id'] ?? 0), $rows),
        );
        $names = [];
        if ($products !== []) {
            $ids = array_keys($products);
            $stmt = $this->db->pdo()->prepare(
                'SELECT id, name FROM stock_items WHERE supplier_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
            );
            $stmt->execute([$supplierId, ...$ids]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $names[(int) $r['id']] = (string) $r['name'];
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $own = ProductPostingDefaults::code($r['own_code'] ?? null);
            $productId = (int) ($r['stock_item_id'] ?? 0);
            $fromProduct = $own === null ? ($products[$productId][$kind] ?? null) : null;
            if ($own === null && $fromProduct === null) {
                continue;
            }
            $code = $own ?? $fromProduct['code'];
            $source = $own !== null ? 'item' : $fromProduct['source'];
            $key = $code . '|' . $source . '|' . ($own === null ? $productId : 0);
            $out[$key] = [
                'account_code' => $code,
                'source' => $source,
                'product_id' => $own === null ? $productId : null,
                'product_name' => $own === null ? ($names[$productId] ?? null) : null,
                'used' => self::accountPresent($code, $usedAccounts),
            ];
        }
        return array_values($out);
    }

    /**
     * Které předkontace stojí za kontací PŘIJATÉ faktury — čistá funkce nad druhy
     * výdaje z řádků, oddělená schválně.
     *
     * Musí odpovídat {@see PostingService::buildFromPurchaseInvoice()}: druh výdaje
     * na řádku má přednost (rozpad podle položek přes {@see ExpenseKind::ruleKey()}),
     * a teprve když ŽÁDNÝ řádek klasifikovaný není, spadne doklad na jediný výchozí
     * klíč podle příznaku dlouhodobého majetku. Rozejít se tyhle dvě větve nesmějí —
     * jinak by UI ukázalo předkontaci, podle které se neúčtovalo. Proto je pravidlo
     * volatelné a má vlastní bránu.
     *
     * @param list<string> $expenseKinds hodnoty `purchase_invoice_items.expense_kind`
     * @return array<string,'expense_kind'|'default'> rule_key => důvod, proč zrovna ten
     */
    public static function receivedPresetKeys(array $expenseKinds, bool $isFixedAsset): array
    {
        $keys = [];
        foreach ($expenseKinds as $raw) {
            $kind = ExpenseKind::tryFromNullable(trim($raw));
            if ($kind !== null) {
                $keys[$kind->ruleKey()] = 'expense_kind';
            }
        }
        if ($keys === []) {
            $keys[$isFixedAsset ? self::DEFAULT_ASSET_KEY : self::DEFAULT_RECEIVED_KEY] = 'default';
        }

        return $keys;
    }

    /**
     * Banka nemá jedinou šablonu: kontaci určuje pravidlo účtování, vestavěné
     * rozpoznání, naučená kontace, nebo — u spárované platby — předkontace
     * `payment.*`. Který z nich to byl, ví jedině
     * {@see AutomationProvenanceService}; duplikovat jeho SQL by znamenalo dvě
     * odpovědi na tutéž otázku.
     *
     * @param array<string,mixed> $out
     * @param list<string> $usedAccounts
     * @return array<string,mixed>
     */
    private function describeBank(int $supplierId, array $out, array $usedAccounts): array
    {
        if ($out['entry_id'] === null) {
            return $out;
        }

        $prov = $this->automation->forJournalEntries($supplierId, [(int) $out['entry_id']]);
        $info = $prov[(int) $out['entry_id']] ?? null;
        if ($info === null) {
            // Žádná stopa automatiky → pohyb zaúčtoval člověk ručně. Předstírat
            // pravidlo by poslalo účetní opravovat něco, co se nepoužilo.
            $out['origin'] = 'manual';
            return $out;
        }

        $out['origin'] = (string) $info['source'];
        $out['detector'] = $info['detector'];

        if ($info['rule_id'] !== null) {
            $out['rules'][] = [
                'type'      => 'bank',
                'id'        => (int) $info['rule_id'],
                'name'      => $info['rule_name'],
                // Pravidlo se dohledává LEFT JOINem v AutomationProvenanceService:
                // bez názvu už v `bank_posting_rules` neexistuje.
                'exists'    => $info['rule_name'] !== null,
                'is_active' => null,
            ];
        }

        // Spárovaná platba účtuje 221/311 resp. 321/221 podle předkontací payment.*
        // ({@see \MyInvoice\Service\Accounting\Bank\BankPostingService::buildMatched()}),
        // takže i tady šablona existuje a dá se opravit — jen se nejmenuje
        // „bankovní pravidlo". Vrací se jen ta, jejíž účty v zápisu opravdu jsou:
        // směr pohybu určuje, která z dvojice se použila.
        if ($out['origin'] === 'matched') {
            foreach (['payment.receivable.bank', 'payment.payable.bank'] as $key) {
                $preset = $this->preset($supplierId, $key, 'payment_match', $usedAccounts);
                if ($preset['used']) {
                    $out['presets'][] = $preset;
                }
            }
        }

        return $out;
    }

    /**
     * Jedna předkontace tak, jak ji vidí {@see PostingRuleRepository::resolve()} —
     * tedy firemní override, když existuje, jinak globální seed.
     *
     * @param list<string> $usedAccounts kódy účtů, které v zápisu opravdu jsou
     * @return array{rule_key:string, description:?string, debit_account_code:?string,
     *               credit_account_code:?string, scope:?string, exists:bool,
     *               reason:string, used:bool}
     */
    private function preset(int $supplierId, string $ruleKey, string $reason, array $usedAccounts): array
    {
        $rule = $this->rules->resolve($supplierId, $ruleKey);
        $debit = $rule['debit_account_code'] ?? null;
        $credit = $rule['credit_account_code'] ?? null;

        return [
            'rule_key'            => $ruleKey,
            'description'         => $rule === null ? null : (string) $rule['description'],
            'debit_account_code'  => $debit,
            'credit_account_code' => $credit,
            'scope'               => $rule === null ? null : ($rule['supplier_id'] === null ? 'global' : 'company'),
            'exists'              => $rule !== null,
            'reason'              => $reason,
            'used'                => $rule !== null
                && self::accountPresent($debit, $usedAccounts)
                && self::accountPresent($credit, $usedAccounts),
        ];
    }

    /**
     * Je účet předkontace v zápisu? Porovnává se PREFIXEM, protože zápis nese
     * analytiku (`518.100`), zatímco předkontace drží syntetiku (`518`). Prázdná
     * strana předkontace je záměr (protiúčet doplní služba) — ta se bere jako
     * splněná, jinak by se „nepoužito" hlásilo u každého kurzového rozdílu.
     *
     * Porovnání JEN prefixem je vědomá jednosměrná chyba: `518` sedne i na `5180`,
     * kdyby si takový účet někdo založil. Opačný směr (analytika předkontace vs.
     * syntetika v zápisu) nenastává, protože předkontace drží nejvýš to, co se
     * do zápisu propíše. Falešné „použito" je snesitelné; falešné „nepoužito" by
     * účetní posílalo hledat neexistující ruční zásah.
     *
     * @param list<string> $usedAccounts
     */
    public static function accountPresent(?string $code, array $usedAccounts): bool
    {
        if ($code === null || $code === '') {
            return true;
        }
        foreach ($usedAccounts as $used) {
            if (str_starts_with($used, $code)) {
                return true;
            }
        }
        return false;
    }

    /** @param list<int> $entryIds @return list<string> */
    private function entryAccountCodes(int $supplierId, array $entryIds): array
    {
        if ($entryIds === []) return [];
        $placeholders = implode(',', array_fill(0, count($entryIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT c.account_code
               FROM journal_entry_lines jel
               JOIN chart_of_accounts c ON c.id = jel.account_id AND c.supplier_id = jel.supplier_id
              WHERE jel.supplier_id = ? AND jel.entry_id IN (' . $placeholders . ')'
        );
        $stmt->execute([$supplierId, ...$entryIds]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return ?array{at:string, by:?string} */
    private function manualRepost(int $supplierId, int $entryId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT al.created_at, u.name
               FROM activity_log al
          LEFT JOIN users u ON u.id = al.user_id
              WHERE al.supplier_id = ? AND al.action = 'accounting.reposted'
                AND al.entity_type = 'journal_entry' AND al.entity_id = ?
              ORDER BY al.id DESC
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $entryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false
            ? null
            : ['at' => (string) $row['created_at'], 'by' => $row['name'] === null ? null : (string) $row['name']];
    }
}
