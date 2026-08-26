<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export\Instance;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Odvodí pro KAŽDOU tabulku schématu, jak ji omezit na jednu firmu — a když to
 * nejde, tabulku z exportu vyřadí.
 *
 * Proč dynamicky a ne ručním seznamem: schéma má přes 300 tabulek a roste s každou
 * migrací. Ruční výčet (vzor {@see \MyInvoice\Service\Accounting\Archive\ArchiveService})
 * se rozejde se schématem tiše — nová tabulka prostě chybí v archivu a nikdo si toho
 * roky nevšimne. Tady se schéma čte z information_schema, takže nová tabulka se
 * buď zařadí sama (má `supplier_id` nebo FK na už oscopovanou tabulku), nebo skončí
 * ve `skipped()` s důvodem, což je vidět v manifestu.
 *
 * ⚠️ DEFAULT DENY. Tabulka se exportuje JEN tehdy, když se pro ni podaří sestavit
 * WHERE vázaný na `supplier_id`. Neurčené tabulky se NEexportují. Opačná volba
 * (exportovat, co neumím zařadit) by u účetní kanceláře znamenala vydat zákazníkovi
 * data cizích firem — to není chyba v souboru, to je únik dat.
 *
 * Odvození filtru:
 *   0) denylist (systémové/globální/cross-tenant tabulky)     → skip
 *   1) `supplier` samotný                                     → `id = ?`
 *   2) tabulka má sloupec `supplier_id`                       → `supplier_id = ?`
 *   3) tabulka má jednosloupcový FK na už oscopovanou tabulku → `fk IN (SELECT pk FROM rodič WHERE …)`
 *      (iterativně do pevného bodu, takže projdou i vnuci — `projects` nemá
 *      `supplier_id` a visí přes `clients`)
 *   4) cokoli dalšího                                         → skip s důvodem
 */
final class TenantScopeResolver
{
    /** Kolikrát se zkusí dohledat rodič (hloubka FK řetězu). */
    private const MAX_PASSES = 6;

    /**
     * Tabulky, které se do exportu firmy NIKDY nedostanou.
     *
     * Tři důvody:
     *   • cross-tenant / systémové — `users`, `migrations`, `cron_*`: nepatří jedné firmě;
     *   • autentizace a tajemství — hesla, tokeny, otisky MFA: archiv opouští instalaci,
     *     tohle v něm nemá co dělat ani zašifrované;
     *   • regenerovatelné cache a globální číselníky — `ares_cache`, `countries`,
     *     kurzovní lístky ČNB/ECB: nejsou to data zákazníka a nafoukly by archiv.
     *
     * `user_suppliers` má `supplier_id`, a přesto je tu: je to mapa přístupů, tedy
     * osobní údaje uživatelů instalace, ne účetnictví firmy.
     *
     * @var list<string>
     */
    private const DENY_TABLES = [
        'migrations', 'app_meta', 'license',
        'users', 'user_suppliers', 'roles', 'role_permissions',
        'password_resets', 'login_attempts', 'login_otps',
        'mfa_recovery_codes', 'mfa_step_up_proofs',
        'api_tokens', 'api_token_ips', 'api_request_log',
        'sessions', 'activity_log_chain_head',
        'countries', 'vat_rates',
        'ares_cache', 'crpdph_cache',
        'cnb_repo_rates', 'ecb_exchange_rates', 'ecb_exchange_rate_days',
        'oss_member_state_rates',
        'ai_embeddings', 'document_embeddings',
        'epo_signing_credentials', 'epo_signing_credential_suppliers',
        'submission_channel_credentials',
        'submission_isds_mobile_credentials', 'submission_isds_auth_flows',
        'payroll_ruleset_audit',
        'payroll_submission_signing_profiles',
        'payroll_document_download_grants',
        'payroll_payment_export_download_grants',
        'payroll_submission_artifact_download_grants',
    ];

    /**
     * Prefixy systémových tabulek (cron dispatcher, heartbeat, claims…).
     *
     * @var list<string>
     */
    private const DENY_PREFIXES = ['cron_', 'payroll_data_migration_'];

    /**
     * Sloupce, jejichž HODNOTA se do exportu nedává. Matchuje se case-insensitive
     * jako podřetězec názvu sloupce.
     *
     * Šifrované credentials a otisky hesel nejsou účetní záznam. Obnovený řádek je
     * dostane prázdné a účetní je zadá znovu — bezpečnější default, převzatý
     * z {@see \MyInvoice\Service\Accounting\Archive\ArchiveService}. Rozšířeno na
     * VŠECHNY tabulky, ne jen na `supplier`: credentials se mezitím rozlezly do
     * `email_profiles`, `bank_email_imap_settings`, `isds_gateway_*` a dalších.
     *
     * @var list<string>
     */
    private const SECRET_COLUMN_PATTERNS = [
        '_enc', 'password', 'secret', 'access_token', 'refresh_token',
        'api_key', 'private_key', 'token_hash', 'totp_', 'ai_pseudo_salt',
        'certificate_ciphertext', 'pfx_ciphertext', 'passphrase',
    ];

    /**
     * BLOB sloupce, které se z řádkového exportu vynechávají — obsah se do archivu
     * dostane jako SOUBOR (viz {@see InstanceExportService::exportBankStatementFiles()}).
     *
     * Důvod je paměť: `bank_statements.file_content` má u roku provozu klidně stovky
     * MB a v JSONL by se navíc base64/escapoval. Jako soubory jsou navíc pro zákazníka
     * použitelné bez naší aplikace, což je celý smysl archivu.
     *
     * @var array<string, list<string>>
     */
    private const BLOB_COLUMNS = [
        'bank_statements' => ['file_content', 'pdf_content'],
    ];

    /**
     * Ruční vazba na rodiče tam, kde v databázi FK NENÍ deklarovaný.
     *
     * Default deny je bezpečný, ale mlčí: takové tabulce prostě chybí data a nikdo
     * si toho nevšimne. `payment_order_items` je přesně ten případ — nese
     * `payment_order_id`, ale bez cizího klíče, takže by se platební příkazy
     * vyexportovaly bez položek. Rozšiřovat tenhle seznam je poslední možnost;
     * správná oprava je doplnit FK do schématu.
     *
     * @var array<string, array{0:string, 1:string, 2:string}> tabulka => [sloupec, rodič, sloupec rodiče]
     */
    private const PARENT_OVERRIDES = [
        'payment_order_items' => ['payment_order_id', 'payment_orders', 'id'],
    ];

    /** @var array<string, TenantTableScope>|null */
    private ?array $resolved = null;

    /** @var array<string, string> tabulka => důvod vynechání */
    private array $skipped = [];

    private ?int $resolvedFor = null;

    public function __construct(private readonly Connection $db) {}

    /**
     * Filtry pro všechny exportovatelné tabulky, seřazené tak, aby rodič předcházel
     * potomka (podle hloubky odvození) — obnova pak nenaráží na FK.
     *
     * @return array<string, TenantTableScope>
     */
    public function resolveAll(int $supplierId): array
    {
        if ($this->resolved !== null && $this->resolvedFor === $supplierId) {
            return $this->resolved;
        }
        $this->skipped = [];
        $columns = $this->loadColumns();
        $foreignKeys = $this->loadForeignKeys();
        $primaryKeys = $this->loadPrimaryKeys();

        /** @var array<string, TenantTableScope> $scopes */
        $scopes = [];

        // 1) + 2) přímé oscopování.
        foreach ($columns as $table => $cols) {
            if (($reason = $this->denyReason($table)) !== null) {
                $this->skipped[$table] = $reason;
                continue;
            }
            if ($table === 'supplier') {
                $scopes[$table] = $this->buildScope(
                    $table, 'id = ?', [$supplierId], $cols, $primaryKeys, 0, 'supplier.id',
                );
                continue;
            }
            if (isset($cols['supplier_id'])) {
                $scopes[$table] = $this->buildScope(
                    $table, 'supplier_id = ?', [$supplierId], $cols, $primaryKeys, 0, 'supplier_id',
                );
            }
        }

        // 3) FK řetěz do pevného bodu — `projects` přes `clients`, DPH řádky pokladny
        //    přes `cash_documents`, položky faktur přes `invoices` atd.
        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $added = 0;
            foreach ($columns as $table => $cols) {
                if (isset($scopes[$table]) || $this->denyReason($table) !== null) {
                    continue;
                }
                $link = $this->pickParentLink($table, $foreignKeys, $scopes);
                if ($link === null) {
                    continue;
                }
                [$fkColumn, $parent, $parentColumn] = $link;
                $parentScope = $scopes[$parent];
                $where = sprintf(
                    '`%s` IN (SELECT `%s` FROM `%s` WHERE %s)',
                    $fkColumn,
                    $parentColumn,
                    $parent,
                    $parentScope->where,
                );
                $scopes[$table] = $this->buildScope(
                    $table,
                    $where,
                    $parentScope->params,
                    $cols,
                    $primaryKeys,
                    $parentScope->depth + 1,
                    $fkColumn . ' → ' . $parent . '.' . $parentColumn,
                );
                $added++;
            }
            if ($added === 0) {
                break;
            }
        }

        // 4) zbytek = default deny, ale viditelně (manifest ukáže, co v archivu není).
        foreach ($columns as $table => $_cols) {
            if (!isset($scopes[$table]) && !isset($this->skipped[$table])) {
                $this->skipped[$table] = 'no_tenant_scope';
            }
        }

        uasort(
            $scopes,
            static fn (TenantTableScope $a, TenantTableScope $b): int
                => [$a->depth, $a->table] <=> [$b->depth, $b->table],
        );

        $this->resolved = $scopes;
        $this->resolvedFor = $supplierId;
        return $scopes;
    }

    /**
     * Tabulky vynechané z exportu a proč. Patří do manifestu — bez toho by po roce
     * nikdo nepoznal, jestli něco chybí záměrně, nebo se ztratilo.
     *
     * @return array<string, string>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /** Je sloupec citlivý (credential / otisk hesla) → hodnota do exportu nejde? */
    public static function isSecretColumn(string $column): bool
    {
        $lower = strtolower($column);
        foreach (self::SECRET_COLUMN_PATTERNS as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }
        return false;
    }

    // ── interní ───────────────────────────────────────────────────────────────

    private function denyReason(string $table): ?string
    {
        if (in_array($table, self::DENY_TABLES, true)) {
            return 'denylist';
        }
        foreach (self::DENY_PREFIXES as $prefix) {
            if (str_starts_with($table, $prefix)) {
                return 'denylist_prefix';
            }
        }
        return null;
    }

    /**
     * @param array<string, true>                                        $cols
     * @param array<string, array{cols:list<string>, autoInc:?string}>   $primaryKeys
     * @param list<mixed>                                                $params
     */
    private function buildScope(
        string $table,
        string $where,
        array $params,
        array $cols,
        array $primaryKeys,
        int $depth,
        string $via,
    ): TenantTableScope {
        $blobs = self::BLOB_COLUMNS[$table] ?? [];
        $exported = [];
        $redacted = [];
        foreach (array_keys($cols) as $column) {
            if (in_array($column, $blobs, true)) {
                $redacted[] = $column . ' (blob → soubor)';
                continue;
            }
            if (self::isSecretColumn($column)) {
                $redacted[] = $column . ' (credential)';
                continue;
            }
            $exported[] = $column;
        }

        $pk = $primaryKeys[$table] ?? ['cols' => [], 'autoInc' => null];
        // Keyset stránkování jen u jednosloupcového číselného PK; jinak LIMIT/OFFSET.
        $keysetPk = (count($pk['cols']) === 1 && $pk['autoInc'] !== null) ? $pk['cols'][0] : null;
        $orderCols = $pk['cols'] !== [] ? $pk['cols'] : [$exported[0] ?? '1'];
        $orderBy = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $orderCols));

        return new TenantTableScope(
            table: $table,
            where: $where,
            params: $params,
            columns: $exported,
            redacted: $redacted,
            keysetPk: $keysetPk,
            orderBy: $orderBy,
            depth: $depth,
            via: $via,
        );
    }

    /**
     * Najde jednosloupcový FK na už oscopovanou tabulku. NOT NULL FK má přednost —
     * u nullable FK by `col IN (…)` vynechal řádky s NULL, což je u povinné vazby
     * chyba, ale u volitelné korektní.
     *
     * @param array<string, list<array{column:string, refTable:string, refColumn:string, nullable:bool}>> $foreignKeys
     * @param array<string, TenantTableScope> $scopes
     * @return array{0:string,1:string,2:string}|null [fkColumn, parent, parentColumn]
     */
    private function pickParentLink(string $table, array $foreignKeys, array $scopes): ?array
    {
        $override = self::PARENT_OVERRIDES[$table] ?? null;
        if ($override !== null && isset($scopes[$override[1]])) {
            return $override;
        }
        $best = null;
        foreach ($foreignKeys[$table] ?? [] as $fk) {
            if ($fk['refTable'] === $table || !isset($scopes[$fk['refTable']])) {
                continue;
            }
            $candidate = [$fk['column'], $fk['refTable'], $fk['refColumn']];
            if (!$fk['nullable']) {
                return $candidate;
            }
            $best ??= $candidate;
        }
        return $best;
    }

    /** @return array<string, array<string, true>> tabulka => sloupce */
    private function loadColumns(): array
    {
        $sql = 'SELECT c.TABLE_NAME, c.COLUMN_NAME
                  FROM information_schema.COLUMNS c
                  JOIN information_schema.TABLES t
                    ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
                 WHERE c.TABLE_SCHEMA = DATABASE()
                   -- "SYSTEM VERSIONED" tu MUSÍ být vedle "BASE TABLE": `journal_entries`
                   -- a `journal_entry_lines` jsou temporální tabulky MariaDB a filtr jen
                   -- na BASE TABLE by z archivu vynechal ÚČETNÍ DENÍK, tedy to nejcennější,
                   -- co v něm má být. Období (row_start/row_end) jsou neviditelné sloupce,
                   -- takže se sem nedostanou a běžný SELECT vrací jen aktuální verzi řádku.
                   AND t.TABLE_TYPE IN ("BASE TABLE", "SYSTEM VERSIONED")
                   -- Generované sloupce se do exportu nedávají: nejsou to data, dopočítají
                   -- se ze zdrojových sloupců, a při obnově by je INSERT odmítl.
                   AND (c.GENERATION_EXPRESSION IS NULL OR c.GENERATION_EXPRESSION = "")
                   AND (c.EXTRA IS NULL OR c.EXTRA NOT LIKE "%GENERATED%")
                 ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION';
        $out = [];
        foreach ($this->db->pdo()->query($sql)?->fetchAll(PDO::FETCH_NUM) ?: [] as [$table, $column]) {
            $out[(string) $table][(string) $column] = true;
        }
        return $out;
    }

    /**
     * @return array<string, list<array{column:string, refTable:string, refColumn:string, nullable:bool}>>
     */
    private function loadForeignKeys(): array
    {
        // Jen jednosloupcové FK (ORDINAL_POSITION = 1 a zároveň jediný sloupec vazby) —
        // složené FK by daly složený IN, který MariaDB neumí zindexovat rozumně a pro
        // odvození scope ho nepotřebujeme.
        $sql = 'SELECT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, c.IS_NULLABLE
                  FROM information_schema.KEY_COLUMN_USAGE k
                  JOIN information_schema.COLUMNS c
                    ON c.TABLE_SCHEMA = k.TABLE_SCHEMA
                   AND c.TABLE_NAME   = k.TABLE_NAME
                   AND c.COLUMN_NAME  = k.COLUMN_NAME
                 WHERE k.TABLE_SCHEMA = DATABASE()
                   AND k.REFERENCED_TABLE_NAME IS NOT NULL
                   AND k.CONSTRAINT_NAME IN (
                       SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                        WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
                        GROUP BY CONSTRAINT_NAME, TABLE_NAME
                       HAVING COUNT(*) = 1
                   )';
        $out = [];
        foreach ($this->db->pdo()->query($sql)?->fetchAll(PDO::FETCH_NUM) ?: [] as $row) {
            [$table, $column, $refTable, $refColumn, $nullable] = $row;
            $out[(string) $table][] = [
                'column' => (string) $column,
                'refTable' => (string) $refTable,
                'refColumn' => (string) $refColumn,
                'nullable' => strtoupper((string) $nullable) === 'YES',
            ];
        }
        return $out;
    }

    /** @return array<string, array{cols:list<string>, autoInc:?string}> */
    private function loadPrimaryKeys(): array
    {
        $sql = 'SELECT TABLE_NAME, COLUMN_NAME, EXTRA
                  FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_KEY = "PRI"
                 ORDER BY TABLE_NAME, ORDINAL_POSITION';
        $out = [];
        foreach ($this->db->pdo()->query($sql)?->fetchAll(PDO::FETCH_NUM) ?: [] as [$table, $column, $extra]) {
            $table = (string) $table;
            $out[$table] ??= ['cols' => [], 'autoInc' => null];
            $out[$table]['cols'][] = (string) $column;
            if (str_contains(strtolower((string) $extra), 'auto_increment')) {
                $out[$table]['autoInc'] = (string) $column;
            }
        }
        return $out;
    }
}
