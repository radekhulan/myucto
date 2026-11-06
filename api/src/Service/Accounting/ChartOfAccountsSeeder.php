<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingFrameworkRepository;
use MyInvoice\Repository\PostingRuleRepository;
use PDO;

/**
 * Naseeduje směrnou účtovou osnovu účinného rámce do
 * chart_of_accounts pro danou firmu (Epic F1). Spouští se, když firma zapne
 * podvojné účetnictví (supplier.accounting_mode = 'double_entry') — trigger
 * napojí navazující workflow (Action/Settings).
 *
 * Rámec 500 doplňuje chybějící účty bez přepsání uživatelských úprav. Rámec 504
 * inicializuje pouze prázdný rozvrh, aby nepřeváděl existující osnovu jiného rámce.
 * Vrací počet reálně vložených účtů.
 */
final class ChartOfAccountsSeeder
{
    /** Zrcadlo migrace 1112 — viz markClearingAccounts(). */
    private const CLEARING_PREFIXES = ['041', '042', '111', '131', '139', '261', '314', '324', '395'];

    public function __construct(private readonly Connection $db) {}

    /** Označí seeder účet jako zúčtovací? Stejné pravidlo jako {@see markClearingAccounts()}. */
    public static function isClearingCode(string $accountCode): bool
    {
        foreach (self::CLEARING_PREFIXES as $prefix) {
            if (str_starts_with($accountCode, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Předkontace, které seeder rámce 500 zakládá jako per-firmu override
     * (viz {@see seedAnalyticPostingRules()}): rule_key => [MD, D].
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function seededPostingRuleOverrides(): array
    {
        return [
            'invoice.material.received' => ['501.900', '321'],
            'invoice.small_asset.received' => ['501.900', '321'],
        ];
    }

    /** Předvolby analytik, které seeder rámce 500 zapisuje do nastavení účetnictví. */
    public const SEEDED_SETTINGS_CODES = [
        'fuel_account_code' => '501.100',
        'vehicle_repair_account_code' => '511.100',
    ];

    /**
     * Vybere šablonu z historie k zadanému datu, u starších volání k dnešku.
     *
     * @return int počet nově vložených účtů
     */
    public function seedForSupplier(int $supplierId, ?string $date = null): int
    {
        $pdo = $this->db->pdo();
        $framework = (new AccountingFrameworkService($this->db, new AccountingFrameworkRepository($this->db)))
            ->forDate($supplierId, $date ?? date('Y-m-d'));
        $template = $framework === AccountingFrameworkService::CZ_504
            ? ChartOfAccountsTemplate504::class
            : ChartOfAccountsTemplate::class;

        $existing = $this->existingCodes($pdo, $supplierId);
        if ($framework === AccountingFrameworkService::CZ_504 && $existing !== []) {
            return 0;
        }

        $insert = $pdo->prepare(
             'INSERT INTO chart_of_accounts
                (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active, tax_deductibility)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)'
        );

        $inserted = 0;
        // Nested-safe: pokud volající už drží transakci (např. test), neotevírej vlastní.
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            // SYNTETIKY jedním dávkovým INSERTem. Po jednom to bylo 213 round-tripů při
            // KAŽDÉM seedování — naměřeno 10,1 ms vs. 1,6 ms dávkově, a seeduje se při
            // každém založení firmy (v testech stovkykrát). Analytiky dávkovat NELZE:
            // potřebují `parent_id`, tedy id právě vloženého rodiče.
            $pending = [];
            foreach ($template::ACCOUNTS as $acc) {
                if (!isset($existing[$acc['code']]) && ($acc['parent_code'] ?? null) === null) {
                    $pending[] = $acc;
                }
            }
            if ($pending !== []) {
                $params = [];
                foreach ($pending as $acc) {
                    array_push(
                        $params,
                        $supplierId,
                        $acc['code'],
                        $acc['name'],
                        $acc['type'],
                        $acc['normal_side'], // null → saldní účet
                        1,
                        null,
                        $template::taxDeductibility($acc['code']),
                    );
                }
                $pdo->prepare(
                    'INSERT INTO chart_of_accounts
                        (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id, is_active, tax_deductibility)
                     VALUES ' . implode(', ', array_fill(0, count($pending), '(?, ?, ?, ?, ?, ?, ?, 1, ?)'))
                )->execute($params);
                $inserted += count($pending);

                // Id se dočtou zpět jedním dotazem — `lastInsertId()` u dávkového INSERTu
                // vrací jen PRVNÍ id a spoléhat na souvislou řadu by bylo křehké.
                $existing = $this->existingCodes($pdo, $supplierId);
            }

            foreach ($template::ACCOUNTS as $acc) {
                if (isset($existing[$acc['code']]) || ($acc['parent_code'] ?? null) === null) {
                    continue;
                }
                $parentCode = (string) $acc['parent_code'];
                $parentId = $existing[$parentCode] ?? null;
                if ($parentId === null) {
                    throw new \RuntimeException('Chybí rodičovský účet ' . $parentCode . ' pro analytiku ' . $acc['code'] . '.');
                }
                $insert->execute([
                    $supplierId,
                    $acc['code'],
                    $acc['name'],
                    $acc['type'],
                    $acc['normal_side'],
                    0,
                    $parentId,
                    $template::taxDeductibility($acc['code']),
                ]);
                $existing[$acc['code']] = (int) $pdo->lastInsertId();
                $inserted++;
            }
            if ($framework === AccountingFrameworkService::CZ_500) {
                $this->seedAnalyticPostingRules($pdo, $supplierId);
            }
            $this->markClearingAccounts($pdo, $supplierId);
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $inserted;
    }

    /**
     * Zúčtovací účty (`is_clearing`) — musí je dostat i firmy založené PO migraci 1112.
     *
     * Migrace `1112_chart_of_accounts_is_clearing.sql` sloupec zavedla a označila účty,
     * které v tu chvíli existovaly. Seeder ho ale nikdy neplnil, takže každá firma
     * naseedovaná od té doby měla `is_clearing = 0` na všech účtech — a invariant I20
     * ani uzávěrková kontrola `clearing_accounts_open` nad ní z principu nemohly nic
     * najít. Naměřeno: 224 účtů v testovací DB, z toho 0 označených.
     *
     * Tichá slepota kontroly je horší než její absence: hlášení je zelené, protože se
     * nemá nač ptát, ne protože je účetnictví v pořádku.
     *
     * Prefixy jsou zrcadlem migrace 1112 — když se sada mění, musí se změnit na obou
     * místech (migrace opravuje historii, seeder zakládá budoucnost).
     */
    private function markClearingAccounts(\PDO $pdo, int $supplierId): void
    {
        $like = implode(' OR ', array_map(
            static fn (string $p): string => "account_code LIKE '{$p}%'",
            self::CLEARING_PREFIXES,
        ));
        $pdo->prepare(
            "UPDATE chart_of_accounts SET is_clearing = 1
              WHERE supplier_id = ? AND is_clearing = 0 AND ({$like})"
        )->execute([$supplierId]);
    }

    /**
     * Doprovod k analytikám 501.x/511.x ze šablony: jakmile syntetika 501 dostane
     * potomky, NESMÍ se na ni dál účtovat (součet analytik by neseděl na syntetiku),
     * ale globální předkontace míří pořád na holé '501'. Založíme proto per-tenant
     * override na zbytkovou 501.900 — přesně to, co migrace 1127 udělala existujícím
     * firmám (globální pravidla ZÁMĚRNĚ nechává být, platí i pro firmy bez analytik).
     *
     * Idempotentní: override se zakládá jen když pro daný rule_key ještě není.
     */
    private function seedAnalyticPostingRules(PDO $pdo, int $supplierId): void
    {
        $hasResidual = $pdo->prepare(
            "SELECT 1 FROM chart_of_accounts WHERE supplier_id = ? AND account_code = '501.900' LIMIT 1"
        );
        $hasResidual->execute([$supplierId]);
        if ($hasResidual->fetchColumn() === false) {
            return;
        }

        $rules = [
            'invoice.material.received'    => 'Materiál — ostatní (analytika 501.900)',
            'invoice.small_asset.received' => 'Drobný majetek (analytika 501.900)',
        ];
        $insert = $pdo->prepare(
            'INSERT INTO posting_rules
                (supplier_id, rule_key, description, debit_account_code, credit_account_code, priority, is_active)
             SELECT ?, ?, ?, ?, ?, ?, 1
              WHERE NOT EXISTS (SELECT 1 FROM posting_rules WHERE supplier_id = ? AND rule_key = ?)'
        );
        $overrides = self::seededPostingRuleOverrides();
        foreach ($rules as $ruleKey => $description) {
            $insert->execute([
                $supplierId, $ruleKey, $description, $overrides[$ruleKey][0], $overrides[$ruleKey][1],
                PostingRuleRepository::OVERRIDE_PRIORITY,
                $supplierId, $ruleKey,
            ]);
        }

        // Předvolba analytik v nastavení — jen když si firma ještě nic nevybrala.
        foreach (self::SEEDED_SETTINGS_CODES as $column => $code) {
            $pdo->prepare(
                "UPDATE accounting_supplier_settings
                    SET {$column} = ?
                  WHERE supplier_id = ? AND {$column} IS NULL"
            )->execute([$code, $supplierId]);
        }
    }

    /**
     * Existuje pro suppliera už nějaký účet? (rychlá detekce pro trigger.)
     */
    public function hasChart(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM chart_of_accounts WHERE supplier_id = ? LIMIT 1'
        );
        $stmt->execute([$supplierId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * @return array<string,int> account_code => id
     */
    private function existingCodes(PDO $pdo, int $supplierId): array
    {
        $stmt = $pdo->prepare('SELECT id, account_code FROM chart_of_accounts WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(string) $row['account_code']] = (int) $row['id'];
        }
        return $map;
    }
}
