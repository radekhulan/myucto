<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Product;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountDefinitionRepository;
use MyInvoice\Service\Accounting\SettlementAccounts;
use PDO;

/**
 * Výchozí účet výnosů a nákladů produktu (skladové karty) a jeho kategorie
 * (Účtování podle dimenzí, F1, migrace 1948).
 *
 * Jediné místo, které rozhoduje „produkt > kategorie": volá ho zaúčtování
 * ({@see \MyInvoice\Service\Accounting\PostingService}) i předvyplnění položky v editoru
 * dokladu, aby editor nenabídl jiný účet, než jaký by použilo zaúčtování.
 *
 * Kategorie = primární kategorie karty (`stock_item_categories.is_primary`, jinak
 * první podle pořadí). Nemá-li účet ona, dědí se od nejbližšího nadřízeného uzlu
 * (materialized path `/12/45/98/`).
 *
 * Validace účtu ({@see validateAccount()}) platí pro kartu a kategorii: účet musí být
 * v účtovém rozvrhu firmy, aktivní a výsledkový (výnos třídy 6, náklad třídy 5). Účet
 * zadaný přímo na položce vydané faktury ověřuje volnější {@see validateIssuedItemAccount()}.
 */
final class ProductPostingDefaults
{
    public const KIND_REVENUE = 'revenue';
    public const KIND_EXPENSE = 'expense';

    public function __construct(private readonly Connection $db) {}

    /**
     * Účty produktů po přednosti produkt > kategorie (> nadřízená kategorie).
     *
     * @param list<int> $productIds
     * @return array<int,array{revenue:?array{code:string,source:string}, expense:?array{code:string,source:string}}>
     *         produkt => účet a odkud se vzal ('product' | 'product_category')
     */
    public function accountsFor(int $supplierId, array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));
        if ($productIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, revenue_account_code, expense_account_code
               FROM stock_items
              WHERE supplier_id = ? AND id IN ({$placeholders})"
        );
        $stmt->execute([$supplierId, ...$productIds]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($products === []) {
            return [];
        }
        $chains = $this->categoryChains($supplierId, array_map(static fn (array $r): int => (int) $r['id'], $products));
        $categoryAccounts = $this->categoryAccounts($supplierId, array_merge([], ...array_values($chains)));

        $out = [];
        foreach ($products as $row) {
            $productId = (int) $row['id'];
            $resolved = [];
            foreach ([self::KIND_REVENUE, self::KIND_EXPENSE] as $kind) {
                $own = self::code($row[$kind . '_account_code'] ?? null);
                if ($own !== null) {
                    $resolved[$kind] = ['code' => $own, 'source' => 'product'];
                    continue;
                }
                $resolved[$kind] = null;
                foreach ($chains[$productId] ?? [] as $categoryId) {
                    $code = $categoryAccounts[$categoryId][$kind] ?? null;
                    if ($code !== null) {
                        $resolved[$kind] = ['code' => $code, 'source' => 'product_category'];
                        break;
                    }
                }
            }
            $out[$productId] = $resolved;
        }
        return $out;
    }

    /**
     * Řetěz kategorií produktu od primární kategorie po kořen (nejbližší první).
     *
     * @param list<int> $productIds
     * @return array<int,list<int>> produkt => kategorie
     */
    public function categoryChains(int $supplierId, array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));
        if ($productIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT stock_item_id, path
               FROM (
                   SELECT sic.stock_item_id, sc.path,
                          ROW_NUMBER() OVER (
                              PARTITION BY sic.stock_item_id
                              ORDER BY sic.is_primary DESC, sic.display_order, sic.category_id
                          ) AS rn
                     FROM stock_item_categories sic
                     JOIN stock_categories sc ON sc.id = sic.category_id AND sc.supplier_id = sic.supplier_id
                    WHERE sic.supplier_id = ? AND sic.stock_item_id IN ({$placeholders})
               ) ranked
              WHERE rn = 1"
        );
        $stmt->execute([$supplierId, ...$productIds]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ids = array_values(array_filter(
                array_map('intval', explode('/', trim((string) $r['path'], '/'))),
                static fn (int $id): bool => $id > 0,
            ));
            $out[(int) $r['stock_item_id']] = array_reverse($ids);
        }
        return $out;
    }

    /**
     * Ověří účet karty, kategorie nebo položky. Prázdná hodnota = bez účtu (null).
     *
     * @param self::KIND_* $kind
     * @throws \InvalidArgumentException s česky formulovaným důvodem
     */
    public function validateAccount(int $supplierId, mixed $code, string $kind, ?string $date = null, bool $currentRead = false): ?string
    {
        $code = self::code(is_scalar($code) ? (string) $code : null);
        if ($code === null) {
            return null;
        }
        $row = $this->activeAccount($supplierId, $code, $date, $currentRead);
        $expectedType = $kind === self::KIND_REVENUE ? 'revenue' : 'expense';
        $expectedClass = $kind === self::KIND_REVENUE ? '6' : '5';
        if ((string) $row['account_type'] !== $expectedType || !str_starts_with($code, $expectedClass)) {
            throw new \InvalidArgumentException($kind === self::KIND_REVENUE
                ? "Účet {$code} není výnosový (třída 6)."
                : "Účet {$code} není nákladový (třída 5).");
        }
        return $code;
    }

    /**
     * Ověří účet zadaný přímo na položce vydané faktury nebo šablony opakované faktury.
     *
     * Na rozdíl od karty a kategorie (jen výnos třídy 6) smí položka nést i rozvahový
     * nebo nákladový účet: přefakturace (315, 378, snížení 518), prodej poukazů a podobné
     * případy. Vyloučené jsou jen saldokontní, daňové a peněžní účty
     * ({@see SettlementAccounts}), stejně jako u položky přijaté faktury. Evidence DPH
     * a kontrolní hlášení na účtu položky nezávisí, řídí se sazbou a členěním řádku.
     *
     * @throws \InvalidArgumentException s česky formulovaným důvodem
     */
    public function validateIssuedItemAccount(int $supplierId, mixed $code, ?string $date = null, bool $currentRead = false): ?string
    {
        $code = self::code(is_scalar($code) ? (string) $code : null);
        if ($code === null) {
            return null;
        }
        if (SettlementAccounts::isSettlement($code)) {
            throw new \InvalidArgumentException("Účet {$code} je saldokontní, daňový nebo peněžní a na položku vydané faktury nepatří.");
        }
        $this->activeAccount($supplierId, $code, $date, $currentRead);
        return $code;
    }

    /**
     * @return array{account_type:string, is_active:mixed}
     * @throws \InvalidArgumentException
     */
    private function activeAccount(int $supplierId, string $code, ?string $date, bool $currentRead): array
    {
        if (mb_strlen($code) > 10) {
            throw new \InvalidArgumentException("Účet {$code} je delší než 10 znaků.");
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT account_type, is_active FROM ' . AccountDefinitionRepository::relation($date ?? date('Y-m-d'), $supplierId, $currentRead) . ' a WHERE supplier_id = ? AND account_code = ?'
        );
        $stmt->execute([$supplierId, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \InvalidArgumentException("Účet {$code} není v účtovém rozvrhu firmy.");
        }
        if (!(bool) $row['is_active']) {
            throw new \InvalidArgumentException("Účet {$code} není v účtovém rozvrhu aktivní.");
        }
        return $row;
    }

    /**
     * Účty karty nebo kategorie z těla požadavku — jen klíče, které v těle jsou, aby
     * úprava bez nich účty nemazala.
     *
     * @param array<string,mixed> $body
     * @return array{revenue_account_code?:?string, expense_account_code?:?string}
     * @throws \InvalidArgumentException
     */
    public function accountsFromPayload(int $supplierId, array $body): array
    {
        $out = [];
        foreach (['revenue_account_code' => self::KIND_REVENUE, 'expense_account_code' => self::KIND_EXPENSE] as $field => $kind) {
            if (array_key_exists($field, $body)) {
                $out[$field] = $this->validateAccount($supplierId, $body[$field], $kind);
            }
        }
        return $out;
    }

    public static function code(?string $code): ?string
    {
        $code = trim((string) $code);
        return $code === '' ? null : $code;
    }

    /**
     * @param list<int> $categoryIds
     * @return array<int,array{revenue:?string, expense:?string}>
     */
    private function categoryAccounts(int $supplierId, array $categoryIds): array
    {
        $categoryIds = array_values(array_unique($categoryIds));
        if ($categoryIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, revenue_account_code, expense_account_code
               FROM stock_categories
              WHERE supplier_id = ? AND id IN ({$placeholders})"
        );
        $stmt->execute([$supplierId, ...$categoryIds]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = [
                'revenue' => self::code($r['revenue_account_code']),
                'expense' => self::code($r['expense_account_code']),
            ];
        }
        return $out;
    }
}
